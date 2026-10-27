<?php
/**
 * Hydrotech - Migrasi Fitur Pengelolaan Akun
 *
 * Menambahkan:
 *   1. Tabel account_change_logs (riwayat perubahan username/password, FK ke users).
 *   2. Kolom users.password_changed_at (penanda invalidasi sesi lama).
 *
 * Aman dijalankan berulang (idempoten) dan tidak menghapus/mengubah data lama.
 *
 * Usage: php -d extension=pdo_mysql database/migrate_account_logs.php
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
    require_once __DIR__ . '/../config/database.php';
    try {
        $results = migrateAccountLogs(getDBConnection());
        echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

function migrateAccountLogs(PDO $pdo): array {
    $results = [];

    // 1. Tabel riwayat perubahan akun (relasi FK ke users.user_id).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `account_change_logs` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NULL COMMENT 'Merujuk users.user_id; NULL bila username tidak dikenal',
            `action_type` VARCHAR(30) NOT NULL COMMENT 'username_change / password_change',
            `source` VARCHAR(20) NOT NULL COMMENT 'login / profile',
            `old_username` VARCHAR(100) NULL,
            `new_username` VARCHAR(100) NULL,
            `status` VARCHAR(20) NOT NULL COMMENT 'success / failed',
            `ip_address` VARCHAR(45) NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_account_logs_user` (`user_id`),
            INDEX `idx_account_logs_created` (`created_at`),
            CONSTRAINT `fk_account_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $results[] = 'Tabel account_change_logs siap (dibuat bila belum ada).';

    // 2. Kolom penanda waktu ganti password terakhir (untuk logout sesi lama).
    $column = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'password_changed_at'")->fetch(PDO::FETCH_ASSOC);
    if (!$column) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `password_changed_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Waktu ganti password terakhir; sesi lebih tua otomatis logout'");
        $results[] = 'Kolom users.password_changed_at ditambahkan.';
    } else {
        $results[] = 'Kolom users.password_changed_at sudah ada.';
    }

    return $results;
}
