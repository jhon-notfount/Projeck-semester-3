<?php
/**
 * Hydrotech - Pembersihan Kontrol Pompa Manual
 *
 * Desain final: tombol pompa di dashboard = pengirim perintah langsung
 * via MQTT (api/pump/control.php -> hydrotech/cmd/esp32/pump). Database
 * TIDAK bertambah tabel maupun kolom — dua tabel sementara dari desain
 * awal (device_commands, pump_states) dihapus di sini.
 *
 * Tabel lama tidak tersentuh. Aman dijalankan berulang.
 *
 * Jalankan: php database/migrate_pump_control.php
 */

require_once __DIR__ . '/../config/database.php';

$pdo = getDBConnection();

$pdo->exec('DROP TABLE IF EXISTS `device_commands`');
$pdo->exec('DROP TABLE IF EXISTS `pump_states`');

$count = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo 'Tabel sekarang: ' . count($count) . " (harus 8)\n";
echo "Pembersihan selesai — skema kembali 8 tabel asli.\n";
