<?php
/**
 * Hydrotech - Penggabung Kondisi Sistem (Data Aktivitas Sistem)
 *
 * Membuat SATU catatan kesimpulan gabungan (monitoring_logs type='sistem')
 * dari tiga komponen:
 *   A. pH    : nilai terkini vs rentang pengaturan -> Normal/Rendah/Tinggi/kosong
 *   B. PPM   : idem
 *   C. Dithane: menunggu jadwal / sedang menyemprot / selesai / terlambat
 *              ("menunggu jadwal" BUKAN masalah - mengikuti ketentuan)
 *
 * Status keseluruhan:
 *   - Tidak Normal : pH DAN PPM sama-sama di luar rentang (masalah menyeluruh)
 *   - Perhatian    : salah satu di luar rentang, data tidak tersedia,
 *                    atau Dithane melewati jadwal + toleransi
 *   - Normal       : sisanya
 *
 * Dedup: catatan dibuat bila tanda tangan kondisi berubah, ATAU bila sejak
 * catatan 'sistem' terakhir telah >= 20 menit (meniru kadens log komponen).
 * Dipanggil dari saveSensorMeasurements() (dalam transaksi) dan
 * recordDithaneActivity() (dalam lock); keduanya mengunci tabel sehingga
 * antrean paralel tidak menggandakan catatan.
 */

require_once __DIR__ . '/sensors.php';
require_once __DIR__ . '/dithane.php';

const SYSTEM_ACTIVITY_CADENCE_SECONDS = 1200; // 20 menit
const DITHANE_GRACE_SECONDS = 300;            // toleransi keterlambatan (sama dgn firmware)

/** Schema guard: kolom penunjang 'sistem' sudah ada? (cek 1x per koneksi) */
function systemActivitySchemaReady(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    $q = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monitoring_logs'
                         AND COLUMN_NAME IN ('ph_value','ppm_value','dithane_state')");
    $ready = ((int)$q->fetchColumn() === 3);
    return $ready;
}

/** Klasifikasi satu komponen sensor: [status, kalimat]. */
function systemComponentSentence(?float $value, array $limits, string $label): array {
    [$min, $max] = $limits;
    $range = number_format($min, $min == (int)$min ? 0 : 1, '.', '') . '-' .
             number_format($max, $max == (int)$max ? 0 : 1, '.', '');
    if ($value === null) {
        return ['Kosong', "Data $label belum tersedia."];
    }
    $formatted = $label === 'pH'
        ? number_format($value, 2, '.', '')
        : number_format($value, 0, '.', '');
    if ($value < $min) {
        return ['Rendah', "Kondisi $label sebesar $formatted berada di bawah rentang normal ($range)."];
    }
    if ($value > $max) {
        return ['Tinggi', "Kondisi $label sebesar $formatted berada di atas rentang normal ($range)."];
    }
    return ['Normal', "Kondisi $label sebesar $formatted berada dalam rentang normal ($range)."];
}

/** Kondisi Dithane berdasarkan jadwal + catatan terakhir: [kalimat, kelas]. */
function systemDithaneCondition(PDO $pdo): array {
    $schedule = readDithane($pdo);
    $durationS = $schedule ? (int)$schedule['config']['duration'] : 0;
    $intervalH = $schedule ? (int)$schedule['config']['interval'] : 0;
    $startLabel = $schedule ? str_replace('.', ':', (string)$schedule['config']['start']) : '';

    $last = $pdo->query(
        "SELECT status, created_at,
                TIMESTAMPDIFF(SECOND, created_at, CURRENT_TIMESTAMP) AS age
           FROM monitoring_logs
          WHERE type = 'dithane' AND is_deleted = 0
          ORDER BY monitoring_id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($last === null) {
        return ["Pompa Dithane belum pernah menyemprot - menunggu jadwal pertama (mulai pukul $startLabel WIB).", 'menunggu'];
    }

    /* Satu catatan Dithane = satu kali MULAI menyala; waktu selesai = mulai + durasi. */
    $startedAt = strtotime((string)$last['created_at']);
    $doneAt = $startedAt + $durationS;
    $now = strtotime((string)$pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn());

    if (($last['status'] ?? '') === 'Menyala') {
        if ($now - $startedAt <= $durationS + 60) {
            return ['Pompa Dithane sedang menyemprot (mulai pukul ' . date('H.i', $startedAt) . ' WIB).', 'menyemprot'];
        }
        return ['Pompa Dithane telah menyelesaikan penyemprotan (selesai pukul ' . date('H.i', $doneAt) . ' WIB).', 'selesai'];
    }
    if ($now < $doneAt + 3600) {
        return ['Pompa Dithane telah menyelesaikan penyemprotan pada ' . date('H.i', $doneAt) . ' WIB sesuai jadwal.', 'selesai'];
    }
    if ($now > $doneAt + $intervalH * 3600 + DITHANE_GRACE_SECONDS) {
        $lateMinutes = (int)round(($now - ($doneAt + $intervalH * 3600)) / 60);
        return ["Pompa Dithane terlambat dari jadwal penyemprotan (melewati pukul " . date('H.i', $doneAt + $intervalH * 3600) . " WIB, terlambat $lateMinutes menit).", 'terlambat'];
    }
    return ['Pompa Dithane menunggu jadwal penyemprotan berikutnya.', 'menunggu'];
}

/** Rekam kesimpulan gabungan (dedup: perubahan kondisi / kadens 20 menit). */
function recordSystemActivity(PDO $pdo, ?int $readingId, ?float $phValue, ?float $ppmValue): void {
    /* Skema belum dimigrasi (db lama/mid-migration): lewati tanpa error. */
    if (!systemActivitySchemaReady($pdo)) return;
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $limits = sensorLimits($pdo);

        /* Nilai yang tidak diterima dipanggilan: ambil pengukuran terkini. */
        if ($phValue === null || $ppmValue === null) {
            $latest = $pdo->query(
                "SELECT ph_value, ppm_value FROM sensor_readings
                  ORDER BY recorded_at DESC, reading_id DESC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($phValue === null && isset($latest['ph_value'])) $phValue = (float)$latest['ph_value'];
            if ($ppmValue === null && isset($latest['ppm_value'])) $ppmValue = (float)$latest['ppm_value'];
        }

        [$phStatus, $phSentence] = systemComponentSentence($phValue, $limits['ph'], 'pH');
        [$ppmStatus, $ppmSentence] = systemComponentSentence($ppmValue, $limits['ppm'], 'PPM');
        [$dithSentence, $dithState] = systemDithaneCondition($pdo);

        /* Status keseluruhan - aturan konsisten dgn kontrol pH/PPM/Dithane. */
        $phBad = in_array($phStatus, ['Rendah', 'Tinggi'], true);
        $ppmBad = in_array($ppmStatus, ['Rendah', 'Tinggi'], true);
        if ($phBad && $ppmBad) {
            $overall = 'Tidak Normal';
        } elseif ($phBad || $ppmBad || $phStatus === 'Kosong' || $ppmStatus === 'Kosong' || $dithState === 'terlambat') {
            $overall = 'Perhatian';
        } else {
            $overall = 'Normal';
        }

        /* Dedup: tanda tangan (status pH, status PPM, kondisi Dithane)
           sama & belum 20 menit -> jangan catat ulang.                    */
        $last = $pdo->query(
            "SELECT status, ph_value, ppm_value, dithane_state, created_at,
                    TIMESTAMPDIFF(SECOND, created_at, CURRENT_TIMESTAMP) AS age
               FROM monitoring_logs
              WHERE type = 'sistem' AND is_deleted = 0
              ORDER BY monitoring_id DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($last !== null) {
            /* Klasifikasi terakhir diturunkan dari nilai tersimpan vs batas
               aktif (andal untuk semua kombinasi status keseluruhan).       */
            $classify = function ($value, array $lim): string {
                if ($value === null) return 'Kosong';
                if ($value < $lim[0]) return 'Rendah';
                if ($value > $lim[1]) return 'Tinggi';
                return 'Normal';
            };
            $lastSignature = $classify($last['ph_value'] === null ? null : (float)$last['ph_value'], $limits['ph'])
                . '|' . $classify($last['ppm_value'] === null ? null : (float)$last['ppm_value'], $limits['ppm'])
                . '|' . ($last['dithane_state'] ?? 'menunggu');
            $newSignature = $phStatus . '|' . $ppmStatus . '|' . $dithState;
            if ($lastSignature === $newSignature && (int)$last['age'] < SYSTEM_ACTIVITY_CADENCE_SECONDS) {
                if ($ownTransaction) $pdo->commit();
                return;
            }
        }

        $clock = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
        $days = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
        $stmt = $pdo->prepare(
            "INSERT INTO monitoring_logs
                (type, day_name, log_time, value, status, note, ph_value, ppm_value, dithane_state, reading_id)
             VALUES ('sistem', ?, ?, '-', ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $days[(int)$clock->format('w')],
            $clock->format('H.i'),
            $overall,
            trim($phSentence . ' ' . $ppmSentence . ' ' . $dithSentence),
            $phValue,
            $ppmValue !== null ? (int)round($ppmValue) : null,
            $dithState,
            $readingId,
        ]);
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
