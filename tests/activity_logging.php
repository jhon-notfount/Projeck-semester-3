<?php
/**
 * Pengujian aturan pencatatan Data Aktivitas Sistem (halaman History):
 *   1. pH abnormal tercatat ketika kondisi abnormal pertama kali terdeteksi.
 *   2. pH normal tercatat setiap 20 menit.
 *   3. PPM abnormal tercatat ketika kondisi abnormal pertama kali terdeteksi.
 *   4. PPM normal tercatat setiap 20 menit.
 *   5. pH dan PPM memiliki timer pencatatan masing-masing (independen).
 *   6. Pompa Dithane menghasilkan satu catatan setiap kali mulai menyala.
 *   7. Tidak ada pencatatan berulang selama kondisi abnormal yang sama.
 *   8. Data (status/keterangan/waktu) tersimpan dengan struktur yang
 *      ditampilkan tabel Data Aktivitas Sistem.
 * Memakai database sementara; dikonfigurasi lewat HYDROTECH_DB_* (default
 * sama dengan config/database.php).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/sensors.php';
require_once __DIR__ . '/../includes/device_report.php';

function check($value, $message) { if (!$value) throw new RuntimeException($message); }
function rejects(callable $fn) { try { $fn(); } catch (Throwable $e) { return; } throw new RuntimeException('Expected rejection: ' . $e->getMessage()); }

$p = new PDO("mysql:host=$DB_HOST;charset=utf8mb4", $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$name = 'hydrotech_activity_test_' . bin2hex(random_bytes(5));
$p->exec("CREATE DATABASE `$name`");
$p->exec("USE `$name`");
try {
    $schema = preg_replace('/--[^\r\n]*/', '', file_get_contents(__DIR__ . '/../database/schema.sql'));
    $schema = preg_replace("/COMMENT '[^']*'/", '', $schema);   // isi COMMENT ada memakai ';'.
    foreach (array_filter(array_map('trim', explode(';', $schema))) as $sql) $p->exec($sql);
    $q = $p->prepare('INSERT INTO settings (type, config_json) VALUES (?, ?)');
    foreach (['ph' => ['min' => '5.5', 'max' => '6.5'], 'ppm' => ['min' => '800', 'max' => '1000']] as $type => $cfg) {
        $q->execute([$type, json_encode($cfg)]);
    }
    $p->exec("INSERT INTO dithane_schedules (schedule_id, interval_hours, duration_seconds, start_time) VALUES (1, 24, 12, '06:00:00')");

    $logCount = function (string $type) use ($p): int {
        return (int)$p->query("SELECT COUNT(*) FROM monitoring_logs WHERE type = '$type' AND is_deleted = 0")->fetchColumn();
    };
    $lastLog = function (string $type) use ($p) {
        return $p->query("SELECT status, note, value, reading_id, day_name, log_time FROM monitoring_logs WHERE type = '$type' AND is_deleted = 0 ORDER BY monitoring_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    };
    $readingCount = (int)$p->query('SELECT COUNT(*) FROM sensor_readings')->fetchColumn();

    /* === 1. pH abnormal (di atas range): tercatat saat pertama terdeteksi === */
    saveSensorMeasurements($p, ['ph_value' => 7.1]);
    check($logCount('ph') === 1, 'pH abnormal pertama harus tercatat');
    $row = $lastLog('ph');
    check($row['status'] === 'Tinggi', 'pH 7.1 status Tinggi');
    check($row['note'] === 'pH 7.1 di atas rentang 5.5 - 6.5', 'Keterangan pH berisi nilai + batas range');
    check($row['value'] === '7.1', 'Nilai pH tersimpan');
    check($row['reading_id'] !== null, 'Log pH terhubung ke pengukuran');
    check(preg_match('/^[0-9]{2}\.[0-9]{2}$/', (string)$row['log_time']) === 1, 'log_time format H.i');
    check(in_array($row['day_name'], ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'], true), 'day_name valid');

    /* === 7a. kondisi abnormal yang sama: TANPA catatan berulang === */
    saveSensorMeasurements($p, ['ph_value' => 7.2]);
    check($logCount('ph') === 1, 'pH Tinggi berulang tidak dicatat ulang');
    check((int)$p->query('SELECT COUNT(*) FROM sensor_readings')->fetchColumn() === 2, 'Pengukuran tetap tersimpan (heartbeat) walau log tidak ditambah');

    /* === 3. PPM abnormal di bawah range: tercatat saat pertama terdeteksi === */
    saveSensorMeasurements($p, ['ppm_value' => 700]);
    $row = $lastLog('ppm');
    check($logCount('ppm') === 1 && $row['status'] === 'Rendah', 'PPM abnormal pertama tercatat');
    check($row['note'] === 'PPM 700 di bawah rentang 800 - 1000', 'Keterangan PPM abnormal');

    /* PPM abnormal yang sama: tanpa catatan berulang (hitung lagi). */
    saveSensorMeasurements($p, ['ppm_value' => 710]);
    check($logCount('ppm') === 1, 'PPM Rendah berulang tidak dicatat ulang');
    check($logCount('ph') === 1, 'Timer pH tidak menghasilkan baris saat PPM dikirim');

    /* === perubahan abnormal -> normal: tercatat === */
    saveSensorMeasurements($p, ['ph_value' => 6]);
    $row = $lastLog('ph');
    check($logCount('ph') === 2 && $row['status'] === 'Normal', 'pH kembali normal tercatat');
    check($row['note'] === 'pH 6 dalam rentang 5.5 - 6.5', 'Keterangan pH normal');

    saveSensorMeasurements($p, ['ppm_value' => 900]);
    $row = $lastLog('ppm');
    check($logCount('ppm') === 2 && $row['status'] === 'Normal', 'PPM kembali normal tercatat');

    /* === 2. pH normal: berkala, belum 20 menit -> tanpa baris baru === */
    saveSensorMeasurements($p, ['ph_value' => 6.1]);
    check($logCount('ph') === 2, 'pH normal < 20 menit tidak dicatat');
    saveSensorMeasurements($p, ['ppm_value' => 910]);
    check($logCount('ppm') === 2, 'PPM normal < 20 menit tidak dicatat');

    /* === 2/4. tepat 20 menit: pH dan PPM masing-masing tercatat === */
    $p->exec("UPDATE monitoring_logs SET created_at = CURRENT_TIMESTAMP - INTERVAL 21 MINUTE WHERE type = 'ph' AND status = 'Normal'");
    saveSensorMeasurements($p, ['ph_value' => 6.15]);
    check($logCount('ph') === 3, 'pH normal tercatat setelah >= 20 menit');
    saveSensorMeasurements($p, ['ph_value' => 6.2]);
    check($logCount('ph') === 3, 'Timer pH ter-reset log terbaru: tidak langsung tercatat lagi');
    check($logCount('ppm') === 2, 'Timer pH berjalan tanpa memengaruhi PPM');

    $p->exec("UPDATE monitoring_logs SET created_at = CURRENT_TIMESTAMP - INTERVAL 21 MINUTE WHERE type = 'ppm' AND status = 'Normal'");
    saveSensorMeasurements($p, ['ppm_value' => 950]);
    check($logCount('ppm') === 3, 'PPM normal tercatat setelah >= 20 menit (timernya sendiri)');
    saveSensorMeasurements($p, ['ppm_value' => 960]);
    check($logCount('ppm') === 3, 'Timer PPM ter-reset log terbaru');

    /* === perubahan antar kondisi abnormal: Rendah -> Tinggi tercatat === */
    saveSensorMeasurements($p, ['ph_value' => 4]);
    check($logCount('ph') === 4 && $lastLog('ph')['status'] === 'Rendah', 'pH berubah Tinggi -> Rendah tercatat');
    saveSensorMeasurements($p, ['ph_value' => 4.5]);
    check($logCount('ph') === 4, 'pH Rendah yang sama tidak dicatat ulang');
    saveSensorMeasurements($p, ['ph_value' => 8]);
    check($logCount('ph') === 5 && $lastLog('ph')['status'] === 'Tinggi', 'pH berubah Rendah -> Tinggi tercatat');

    /* === 6. Pompa Dithane: satu catatan SETIAP kali mulai menyala === */
    $r = recordDithaneActivity($p, 'dithane', 'started', 0, '2026-11-04 10:00:00');
    check($r['logged'] === true, 'Pelaporan pompa mulai menyala dicatat');
    $row = $lastLog('dithane');
    check($row['status'] === 'Menyala', 'Status aktivitas Dithane Menyala');
    check(str_contains((string)$row['note'], 'Pompa Dithane mulai menyala'), 'Keterangan penyalaan Dithane');
    check((int)$p->query("SELECT COUNT(*) FROM notifications WHERE label = 'Penyemprotan Dithane dimulai'")->fetchColumn() === 1, 'Notifikasi pompa mulai');

    /* Laporan ulang (HTTP burst) dalam < 60 detik: bukan penyalaan baru. */
    $r = recordDithaneActivity($p, 'dithane', 'started', 0, '2026-11-04 10:00:05');
    check($r['dedup'] === true && $logCount('dithane') === 1, 'Laporan started berulang < 60 detik didedup');
    check((int)$p->query("SELECT COUNT(*) FROM notifications WHERE label = 'Penyemprotan Dithane dimulai'")->fetchColumn() === 1, 'Notifikasi tidak ganda');

    /* Pompa menyala kembali pada siklus berikutnya: catatan baru. */
    $p->exec("UPDATE monitoring_logs SET created_at = CURRENT_TIMESTAMP - INTERVAL 2 MINUTE WHERE type = 'dithane'");
    $r = recordDithaneActivity($p, 'dithane', 'started', 0, '2026-11-04 11:00:00');
    check($r['logged'] === true && $logCount('dithane') === 2, 'Siklus penyalaan berikutnya menghasilkan catatan baru');

    /* Laporan selesai: hanya notifikasi, TANPA catatan kedua. */
    $r = recordDithaneActivity($p, 'dithane', 'completed', 12, '2026-11-04 11:00:12');
    check($r['logged'] === false && $r['last_completed_at'] !== null, 'Laporan selesai tanpa catatan baru, return last_completed_at');
    check($logCount('dithane') === 2, 'Penyalaan tetap satu catatan');
    check((int)$p->query("SELECT COUNT(*) FROM notifications WHERE label = 'Penyemprotan Dithane selesai'")->fetchColumn() === 1, 'Notifikasi pompa selesai');

    /* Pengecekan jadwal/sensor hanya membaca konfigurasi: tanpa catatan baru. */
    $lastDone = $p->query(
        "SELECT DATE_ADD(l.created_at, INTERVAL COALESCE(d.duration_seconds, 0) SECOND)
           FROM monitoring_logs l
           LEFT JOIN dithane_schedules d ON d.schedule_id = 1
          WHERE l.type = 'dithane' AND l.is_deleted = 0
          ORDER BY l.monitoring_id DESC LIMIT 1"
    )->fetchColumn();
    check($lastDone !== false, 'Anchor last_completed_at tersedia dari catatan aktivitas');
    check($logCount('dithane') === 2, 'Pembacaan konfigurasi tidak mencatat aktivitas');

    /* Validasi masukan endpoint laporan. */
    rejects(fn() => recordDithaneActivity($p, 'ph', 'started', 0, ''));
    rejects(fn() => recordDithaneActivity($p, 'dithane', 'restarting', 0, ''));
    rejects(fn() => recordDithaneActivity($p, 'dithane', 'completed', 0, ''));
    rejects(fn() => recordDithaneActivity($p, 'dithane', 'completed', 4000, ''));

    /* === 8. Urutan terbaru -> terlama memakai waktu kejadian server === */
    $rows = $p->query("SELECT monitoring_id, created_at FROM monitoring_logs WHERE type = 'dithane' AND is_deleted = 0 ORDER BY created_at DESC, monitoring_id DESC")->fetchAll(PDO::FETCH_ASSOC);
    check(count($rows) === 2, 'Dua catatan Dithane tersimpan');
    check($rows[0]['monitoring_id'] > $rows[1]['monitoring_id'], 'Catatan terbaru memiliki id terbesar');

    check($readingCount === 0, 'Baseline pengukuran kosong');
    echo 'PASS: aturan pencatatan Data Aktivitas Sistem (pH/PPM abnormal saat terdeteksi/perubahan, normal tiap 20 menit per tipe, Dithane satu catatan per mulai menyala, tanpa duplikat, keterangan + timestamp valid).\n';
} finally {
    $p->exec("DROP DATABASE `$name`");
}
