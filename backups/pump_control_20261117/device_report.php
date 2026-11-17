<?php
/**
 * Pencatatan AKTIVITAS POMPA DITHANE (tabel Data Aktivitas Sistem).
 *
 * Aturan:
 *  - Satu catatan SETIAP kali pompa Dithane MULAI MENYALA (state "started").
 *    Catatan berisi waktu mulai menyala (waktu server saat laporan masuk,
 *    Asia/Jakarta — berbarengan dengan aksi relay) dan status aktivitas.
 *  - TIDAK ada catatan berkala selama pompa menyala, dan TIDAK ada catatan
 *    saat sistem hanya membaca sensor / mengecek jadwal.
 *  - Siklus berikutnya (pompa menyala kembali) menghasilkan catatan baru.
 *  - Laporan "completed" hanya membuat notifikasi (tanpa catatan kedua,
 *    agar riwayat tetap satu baris per penyalaan).
 *  - Dedup: laporan "started" yang diterima berulang < 60 detik (HTTP
 *    burst/retry yang jarang) diabaikan, karena awal siklus sah tidak
 *    mungkin berjarak < 60 detik (interval minimum 1 jam).
 */

require_once __DIR__ . '/sensors.php';

function recordDithaneActivity(PDO $pdo, string $pump, string $state, int $durationS, string $deviceSeen): array {
    if ($pump !== 'dithane') {
        throw new InvalidArgumentException('Pompa tidak didukung.');
    }
    if (!in_array($state, ['started', 'completed'], true)) {
        throw new InvalidArgumentException('State tidak didukung (started/completed).');
    }
    if ($state === 'completed' &&
        filter_var($durationS, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3600]]) === false) {
        throw new InvalidArgumentException('duration_s harus bulat 1..3600 untuk completed.');
    }
    $clock = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
    $dedupKey = $deviceSeen !== ''
        ? 'perangkat ' . $deviceSeen
        : 'server ' . $clock->format('Y-m-d H:i') . ' (tanpa waktu perangkat)';
    $days = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];

    $lock = measurementLock($pdo);
    try {
        if ($state === 'started') {
            /* Anti duplikasi laporan ulang dalam < 60 detik.              */
            $q = $pdo->prepare(
                "SELECT COUNT(*) FROM monitoring_logs
                  WHERE type = 'dithane' AND is_deleted = 0
                    AND created_at >= CURRENT_TIMESTAMP - INTERVAL 60 SECOND"
            );
            $q->execute();
            if ((int)$q->fetchColumn() > 0) {
                return ['state' => 'started', 'dedup' => true, 'logged' => false, 'notified' => false, 'last_completed_at' => null];
            }

            /* 1) Catatan aktivitas: satu baris per pompa MULAI menyala,
                  schedule_id menautkan penyalaan ke jadwal pemicunya.      */
            $m = $pdo->prepare(
                "INSERT INTO monitoring_logs (type, day_name, log_time, value, status, note, schedule_id)
                 VALUES ('dithane', ?, ?, ?, 'Menyala', ?, 1)"
            );
            $m->execute([
                $days[(int)$clock->format('w')],
                $clock->format('H.i'),
                '-',
                "Pompa Dithane mulai menyala ({$dedupKey}).",
            ]);
            $monitoringId = (int)$pdo->lastInsertId();

            /* 2) Notifikasi web (fitur notifikasi tetap berjalan).         */
            sensorWarningDithane($pdo, 'Penyemprotan Dithane dimulai',
                "Pompa Dithane mulai menyala ({$dedupKey}).", 'info', $monitoringId);
            return ['state' => 'started', 'dedup' => false, 'logged' => true, 'notified' => true, 'last_completed_at' => null];
        }

        /* state = completed: notifikasi saja, TANPA catatan baru agar
           tabel aktivitas tidak berisi berulang untuk satu penyalaan.  */
        sensorWarningDithane($pdo, 'Penyemprotan Dithane selesai',
            "Penyemprotan selesai; durasi aktual {$durationS} detik ({$dedupKey}).", 'success', null);
        return ['state' => 'completed', 'dedup' => false, 'logged' => false, 'notified' => true,
                'last_completed_at' => $clock->format('Y-m-d H:i:s')];
    } finally { measurementUnlock($pdo, $lock); }
}

/** Notifikasi aktivitas pompa (dedup pesan identik, pola sensorWarning);
 *  monitoring_id menautkan notifikasi ke catatan penyalaan sumbernya.    */
function sensorWarningDithane(PDO $pdo, string $label, string $message, string $tone, ?int $monitoringId = null): void {
    $q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE label = ? AND message = ?");
    $q->execute([$label, $message]);
    if ((int)$q->fetchColumn() > 0) return;
    $n = $pdo->prepare("INSERT INTO notifications (label, message, tone, href, monitoring_id, user_id) VALUES (?, ?, ?, 'history.php', ?, NULL)");
    $n->execute([$label, $message, $tone, $monitoringId]);
}
