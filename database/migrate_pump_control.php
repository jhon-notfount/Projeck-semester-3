<?php
/**
 * Hydrotech - Migrasi Kontrol Pompa Manual
 *
 * Menambahkan dua tabel untuk kontrol manual pompa dari dashboard:
 *  - device_commands : antrean perintah ON/OFF dari dashboard ke ESP32.
 *                      Perintah dibuat pending, diambil (delivered) perangkat
 *                      melalui api/device/commands.php, lalu ditandai done/rejected.
 *  - pump_states     : status pompa terkini yang dilaporkan perangkat
 *                      (state on/off, mode auto/manual, sisa detik override).
 *
 * Tabel lama TIDAK diubah (tanpa ALTER). Aman dijalankan berulang:
 * tabel dibuat dengan IF NOT EXISTS.
 *
 * Jalankan: php database/migrate_pump_control.php
 */

require_once __DIR__ . '/../config/database.php';

$pdo = getDBConnection();

/* Status perangkat: satu baris per pompa; perangkat meng-upsert saat poll. */
$pdo->exec("CREATE TABLE IF NOT EXISTS `pump_states` (
    `pump` ENUM('ab','ph','dithane','circ') NOT NULL PRIMARY KEY,
    `state` ENUM('on','off') NOT NULL DEFAULT 'off',
    `mode` ENUM('auto','manual') NOT NULL DEFAULT 'auto',
    `remaining_s` INT NULL COMMENT 'Sisa detik override manual (NULL = tidak berlaku)',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* Antrean perintah manual; issued_by merujuk operator dashboard. */
$pdo->exec("CREATE TABLE IF NOT EXISTS `device_commands` (
    `command_id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `pump` ENUM('ab','ph','dithane') NOT NULL,
    `action` ENUM('on','off') NOT NULL,
    `duration_s` INT NOT NULL DEFAULT 0 COMMENT 'Durasi override (detik); 0 = off tanpa durasi',
    `issued_by` INT NULL,
    `status` ENUM('pending','delivered','done','rejected') NOT NULL DEFAULT 'pending',
    `result_note` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `delivered_at` TIMESTAMP NULL DEFAULT NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    INDEX `idx_device_commands_status` (`status`, `command_id`),
    INDEX `idx_device_commands_created` (`created_at`),
    CONSTRAINT `fk_device_commands_user` FOREIGN KEY (`issued_by`)
        REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_device_commands_duration` CHECK (`duration_s` BETWEEN 0 AND 3600)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* Baris awal status pompa agar api/pump/status.php selalu punya jawaban. */
$pdo->exec("INSERT IGNORE INTO `pump_states` (`pump`) VALUES ('ab'), ('ph'), ('dithane'), ('circ')");

foreach (['pump_states', 'device_commands'] as $table) {
    $exists = $pdo->query("SHOW TABLES LIKE '{$table}'")->fetchColumn();
    echo $exists ? "OK     {$table}\n" : "GAGAL  {$table}\n";
}
echo "Migrasi kontrol pompa selesai.\n";
