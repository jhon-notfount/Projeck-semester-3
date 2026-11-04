<?php
/**
 * Pengujian relasi database HydroTech (database sementara, schema.sql).
 * Data yang dibuat: settings.updated_by, dithane_schedules.updated_by,
 * profile.user_id + unique, monitoring_logs.reading_id/schedule_id,
 * notifications.monitoring_id/user_id, dan integritas
 * users -> account_change_logs.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/sensors.php';
require_once __DIR__ . '/../includes/dithane.php';
require_once __DIR__ . '/../includes/device_report.php';

function check($value, $message) { if (!$value) throw new RuntimeException($message); }
function rejects(callable $fn) { try { $fn(); } catch (PDOException $e) { return; } catch (Throwable $e) { return; } throw new RuntimeException('Expected rejection'); }

$p = new PDO("mysql:host=$DB_HOST;charset=utf8mb4", $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$name = 'hydrotech_relations_test_' . bin2hex(random_bytes(5));
$p->exec("CREATE DATABASE `$name`");
$p->exec("USE `$name`");
try {
    $schema = preg_replace('/--[^\r\n]*/', '', file_get_contents(__DIR__ . '/../database/schema.sql'));
    $schema = preg_replace("/COMMENT '[^']*'/", '', $schema);
    foreach (array_filter(array_map('trim', explode(';', $schema))) as $sql) $p->exec($sql);

    $q = $p->prepare('INSERT INTO users (username, password, email) VALUES (?, ?, ?)');
    $q->execute(['adminu', 'hashadmin', 'admin@x.com']);
    $adminId = (int)$p->lastInsertId();

    /* Relasi 1: profile milik admin user; FK + unique per (user, section, label). */
    $p->prepare("INSERT INTO profile (user_id, section, label, value, sort_order) VALUES (?, 'owner', 'Nama', 'Admin', 0)")
      ->execute([$adminId]);
    rejects(fn() => $p->prepare("INSERT INTO profile (user_id, section, label, value, sort_order) VALUES (?, 'owner', 'Nama', 'Admin', 0)")->execute([$adminId]));
    rejects(fn() => $p->prepare("INSERT INTO profile (user_id, section, label, value, sort_order) VALUES (999, 'owner', 'X', 'Y', 0)")->execute());

    /* Relasi 2/3: konfigurasi pH/PPM & jadwal Dithane taut updated_by. */
    $p->prepare('INSERT INTO settings (type, config_json, updated_by) VALUES (?, ?, ?)')
      ->execute(['ph', json_encode(['min' => '5.5', 'max' => '6.5']), $adminId]);
    rejects(fn() => $p->prepare("INSERT INTO settings (type, config_json, updated_by) VALUES ('ppm', '{\"min\":800,\"max\":1000}', 999)")->execute());
    check((int)$p->query('SELECT updated_by FROM settings WHERE type = "ph"')->fetchColumn() === $adminId, 'updated_by settings tercatat');
    writeDithane($p, ['interval' => '24', 'duration' => '12', 'start' => '08.00'], $adminId);
    check((int)$p->query('SELECT updated_by FROM dithane_schedules WHERE schedule_id = 1')->fetchColumn() === $adminId, 'updated_by jadwal Dithane tercatat');

    /* Relasi 4: monitoring_logs.reading_id -> sensor_readings (reading asli). */
    $readings = saveSensorMeasurements($p, ['ph_value' => 7.1]);
    $readingId = (int)$readings[0]['id'];
    $phLogId = (int)$p->query("SELECT monitoring_id FROM monitoring_logs WHERE type = 'ph'")->fetchColumn();
    check((int)$p->query("SELECT reading_id FROM monitoring_logs WHERE monitoring_id = $phLogId")->fetchColumn() === $readingId, 'log mendisensi reading asli');
    rejects(fn() => $p->exec("INSERT INTO monitoring_logs (type, day_name, log_time, value, status, reading_id) VALUES ('ph','Senin','08.00','6','Tinggi',99999)"));
    $notiSeedId = (int)$p->query("SELECT notification_id FROM notifications ORDER BY notification_id DESC LIMIT 1")->fetchColumn();

    /* Penghapusan reading TIDAK menghapus log (SET NULL menjaga histori). */
    $p->exec("DELETE FROM sensor_readings WHERE reading_id = $readingId");
    check((int)$p->query("SELECT COUNT(*) FROM monitoring_logs WHERE monitoring_id = $phLogId")->fetchColumn() === 1, 'log tetap ada');
    check($p->query("SELECT reading_id FROM monitoring_logs WHERE monitoring_id = $phLogId")->fetchColumn() === null, 'reading_id dinormalkan');
    rejects(fn() => $p->exec("INSERT INTO monitoring_logs (type, day_name, log_time, value, status, reading_id) VALUES ('ph','Senin','08.00','6','Tinggi',99999)"));

    /* Relasi 5: notifications.monitoring_id menautkan sumber log. */
    $notiId = (int)$p->query("SELECT notification_id FROM notifications ORDER BY notification_id DESC LIMIT 1")->fetchColumn();
    $p->prepare("UPDATE notifications SET monitoring_id = ? WHERE notification_id = ?")->execute([$phLogId, $notiId]);
    check((int)$p->query("SELECT monitoring_id FROM notifications WHERE notification_id = $notiId")->fetchColumn() === $phLogId, 'notifikasi tertaut ke log');
    rejects(fn() => $p->prepare("UPDATE notifications SET monitoring_id = 99999 WHERE notification_id = ?")->execute([$notiId]));
    $p->prepare("UPDATE notifications SET monitoring_id = NULL WHERE notification_id = ?")->execute([$notiId]);

    /* Relasi 6: notifications.user_id (NULL = broadcast; hanya user valid). */
    $p->prepare("UPDATE notifications SET user_id = ? WHERE notification_id = ?")->execute([$adminId, $notiId]);
    rejects(fn() => $p->prepare("UPDATE notifications SET user_id = 999 WHERE notification_id = ?")->execute([$notiId]));

    /* Relasi 8: log penyemprotan taut jadwal pemicunya. */
    $r = recordDithaneActivity($p, 'dithane', 'started', 0, '2026-11-04 10:00:00');
    check($r['logged'] === true, 'penyalaan tercatat');
    $dithLogId = (int)$p->query("SELECT monitoring_id FROM monitoring_logs WHERE type = 'dithane' ORDER BY monitoring_id DESC LIMIT 1")->fetchColumn();
    check((int)$p->query("SELECT schedule_id FROM monitoring_logs WHERE monitoring_id = $dithLogId")->fetchColumn() === 1, 'log penyemprotan tertaut ke jadwal 1');
    $dithNotiId = (int)$p->query("SELECT notification_id FROM notifications ORDER BY notification_id DESC LIMIT 1")->fetchColumn();
    check((int)$p->query("SELECT monitoring_id FROM notifications WHERE notification_id = $dithNotiId")->fetchColumn() === $dithLogId, 'notifikasi penyalaan tertaut ke log');
    rejects(fn() => $p->exec("INSERT INTO monitoring_logs (type, day_name, log_time, value, status, schedule_id) VALUES ('dithane','Senin','08.00','-','Menyala',2)"));

    /* Relasi terjaga: users -> account_change_logs tidak diubah. */
    $ac = $p->query("SHOW CREATE TABLE account_change_logs")->fetch(PDO::FETCH_ASSOC)['Create Table'];
    check(str_contains($ac, "CONSTRAINT `fk_account_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE"), 'relasi account_change_logs tetap');
    $p->prepare("INSERT INTO account_change_logs (user_id, action_type, source, old_username, new_username, status) VALUES (?, 'password_change', 'profile', NULL, 'adminu', 'success')")->execute([$adminId]);
    check((int)$p->query('SELECT COUNT(*) FROM account_change_logs')->fetchColumn() === 1, 'pencatatan perubahan akun tetap berjalan');

    /* Daftar FK final. */
    $fks = $p->query('SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY TABLE_NAME, CONSTRAINT_NAME')->fetchAll(PDO::FETCH_ASSOC);
    echo "FK aktif:\n";
    foreach ($fks as $f) echo "  {$f['TABLE_NAME']} . {$f['CONSTRAINT_NAME']} -> {$f['REFERENCED_TABLE_NAME']}\n";
    check(count($fks) === 8, 'jumlah FK sesuai');

    echo 'PASS: relasi users-settings/dithane/profile/notifications, sensor_readings-monitoring_logs (SET NULL menjaga histori), dithane_schedules-monitoring_logs, notifications-monitoring_logs, account_change_logs tak berubah.\n';
} finally {
    $p->exec("DROP DATABASE `$name`");
}
