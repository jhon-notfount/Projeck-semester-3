<?php
/**
 * Hydrotech - Rata-rata Periode 24 Jam (bagian Monitoring Nutrisi)
 *
 * Periode perhitungan rata-rata:
 *   - Waktu mulai periode disimpan PERSISTEN di tabel settings
 *     (baris type='system', config_json.period_start) - memuat ulang
 *     halaman TIDAK mereset periode.
 *   - Periode berjalan 24 jam; setelah habis, periode berikutnya
 *     dimulai (berantai: start + 24 jam) - data lama tidak dihapus,
 *     hanya tidak ikut dihitung pada periode baru.
 *   - Rata-rata = KUMULATIF seluruh pembacaan sejak awal periode:
 *     setiap titik grafik = rata-rata seluruh pembacaan dari awal
 *     periode hingga titik waktu tersebut (konsisten dengan angka
 *     rata-rata yang ditampilkan di bagian bawah dashboard).
 *
 * GET (login). Response:
 *   period_start, period_end, avg_ph, avg_ppm, progress[{t, ph, ppm}]
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') errorResponse('Method not allowed', 405);

const PERIOD_SECONDS = 86400;   // 24 jam

/** Baca/roll-forward waktu mulai periode (persisten di settings). */
function periodStart(PDO $pdo): string {
    $now = time();                                   // PHP = Asia/Jakarta (config)
    $row = $pdo->query("SELECT config_json FROM settings WHERE type = 'system'")->fetch(PDO::FETCH_ASSOC);
    $start = $now;
    if ($row) {
        $cfg = json_decode((string)$row['config_json'], true);
        $ts = strtotime((string)($cfg['period_start'] ?? ''));
        if ($ts !== false && $ts > 0) {
            $start = $ts;
            while ($start + PERIOD_SECONDS <= $now) $start += PERIOD_SECONDS;   // periode berantai
        }
    }
    $startStr = date('Y-m-d H:i:s', $start);

    if (!$row) {
        try {
            $pdo->prepare("INSERT INTO settings (type, config_json) VALUES ('system', ?)")
                ->execute([json_encode(['period_start' => $startStr])]);
        } catch (PDOException $e) {
            /* ENUM belum memuat 'system' -> perluas sekali (aman, tanpa
               menghapus data) lalu coba lagi.                           */
            $pdo->exec("ALTER TABLE `settings` MODIFY `type` ENUM('ppm','ph','system') NOT NULL");
            $pdo->prepare("INSERT INTO settings (type, config_json) VALUES ('system', ?)")
                ->execute([json_encode(['period_start' => $startStr])]);
        }
    } elseif ($startStr !== (string)($cfg['period_start'] ?? '')) {
        $pdo->prepare("UPDATE settings SET config_json = ? WHERE type = 'system'")
            ->execute([json_encode(['period_start' => $startStr])]);
    }
    return $startStr;
}

try {
    $pdo = getDBConnection();
    $start = periodStart($pdo);
    $stop = date('Y-m-d H:i:s', strtotime($start) + PERIOD_SECONDS);

    /* Rata-rata per JAM dalam periode (bucket 0..23).
       Parameter unik semua (native prepare): :begin, :stop.             */
    $q = $pdo->prepare(
        "SELECT FLOOR(TIMESTAMPDIFF(SECOND, :begin1, recorded_at)/3600) AS h,
                COUNT(ph_value) AS phn, AVG(ph_value) AS phavg,
                COUNT(ppm_value) AS ppmn, AVG(ppm_value) AS ppmavg
           FROM sensor_readings
          WHERE recorded_at >= :begin2 AND recorded_at < :stop
          GROUP BY h ORDER BY h"
    );
    $q->execute([':begin1' => $start, ':begin2' => $start, ':stop' => $stop]);
    $buckets = $q->fetchAll(PDO::FETCH_ASSOC);

    /* Rata-rata KUMULATIF per titik: rata-rata seluruh pembacaan dari
       awal periode hingga akhir bucket jam tersebut.                    */
    $phProg = []; $ppmProg = [];
    $phN = 0; $phSum = 0.0; $ppmN = 0; $ppmSum = 0.0;
    foreach ($buckets as $b) {
        $h = (int)$b['h'];
        if ((int)$b['phn'] > 0) {
            $phN += (int)$b['phn']; $phSum += (float)$b['phavg'] * (int)$b['phn'];
            $phProg[$h] = round($phSum / $phN, 2);
        }
        if ((int)$b['ppmn'] > 0) {
            $ppmN += (int)$b['ppmn']; $ppmSum += (float)$b['ppmavg'] * (int)$b['ppmn'];
            $ppmProg[$h] = (int)round($ppmSum / $ppmN);
        }
    }

    /* Nilai rata-rata kumulatif saat ini (yang ditampilkan bagian bawah). */
    $avgPh  = $phN > 0 ? round($phSum / $phN, 2) : null;
    $avgPpm = $ppmN > 0 ? (int)round($ppmSum / $ppmN) : null;

    /* Progres lengkap utk grafik: satu titik per jam sejak awal periode.  */
    $startTs = strtotime($start);
    $nowHour = (int)floor((time() - $startTs) / 3600);
    $progress = [];
    for ($h = 0; $h <= min($nowHour, 23); $h++) {
        $t = date('H.i', $startTs + ($h + 1) * 3600 - 1);
        $progress[] = [
            'h'   => $h,
            't'   => $t,
            'ph'  => isset($phProg[$h]) ? $phProg[$h] : null,
            'ppm' => isset($ppmProg[$h]) ? $ppmProg[$h] : null,
        ];
    }

    successResponse([
        'period_start' => $start,
        'period_end'   => $stop,
        'avg_ph'  => $avgPh,
        'avg_ppm' => $avgPpm,
        'progress' => $progress,
    ], 'Rata-rata periode 24 jam');
} catch (Throwable $e) {
    error_log('Dashboard average: ' . $e->getMessage());
    errorResponse('Gagal menghitung rata-rata periode.', 500);
}
