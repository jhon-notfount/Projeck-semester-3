-- Hydrotech: 7 tabel sesuai kebutuhan sistem, tanpa foreign key paksaan.
-- Satu tabel sensor_readings (pH + PPM per pengukuran), kategori log memakai
-- ENUM, monitoring_logs sebagai satu-satunya log (dashboard, pengaturan, dan
-- history membaca tabel yang sama), tanpa relasi yang dipaksa.
--
-- Catatan relasi:
-- - Tidak ada FK antar tabel: integritas dijaga transaksi aplikasi dan CHECK
--   constraint, tidak ada query yang JOIN antar tabel.
-- - monitoring_logs.reading_id merujuk pengukuran secara logis (tanpa FK);
--   data sensor boleh diarsip/dihapus tanpa merusak log.

-- Akun login admin (satu pengguna).
CREATE TABLE IF NOT EXISTS `users` (
    `user_id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `password_changed_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Waktu ganti password terakhir; sesi lebih tua otomatis logout'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Riwayat perubahan akun (username/password dari halaman login maupun profile).
-- Relasi 1:N: satu users memiliki banyak account_change_logs melalui user_id.
-- Tidak pernah menyimpan password (lama/baru) dalam bentuk apa pun.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Konfigurasi rentang PPM/pH (jadwal Dithane ada di tabel dithane_schedules).
CREATE TABLE IF NOT EXISTS `settings` (
    `type` ENUM('ppm','ph') NOT NULL PRIMARY KEY,
    `config_json` JSON NOT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hasil pengukuran asli dari perangkat ESP; satu baris per pengukuran,
-- pH dan PPM dikirim bersamaan (kolom boleh satu kosong bila perangkat
-- hanya mengirim salah satu).
CREATE TABLE IF NOT EXISTS `sensor_readings` (
    `reading_id` INT AUTO_INCREMENT PRIMARY KEY,
    `ph_value` DECIMAL(6,4) NULL COMMENT 'Hasil pengukuran: pH tanpa satuan',
    `ppm_value` INT NULL COMMENT 'Hasil pengukuran: konsentrasi dalam ppm',
    `recorded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sensor_time` (`recorded_at`, `reading_id`),
    CONSTRAINT `chk_sensor_any_value` CHECK (`ph_value` IS NOT NULL OR `ppm_value` IS NOT NULL),
    CONSTRAINT `chk_ph_value` CHECK (`ph_value` IS NULL OR `ph_value` BETWEEN 0 AND 14),
    CONSTRAINT `chk_ppm_value` CHECK (`ppm_value` IS NULL OR `ppm_value` BETWEEN 0 AND 2147483647)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jadwal penyemprotan Dithane global (selalu satu baris: schedule_id = 1).
CREATE TABLE IF NOT EXISTS `dithane_schedules` (
    `schedule_id` INT PRIMARY KEY,
    `interval_hours` INT NOT NULL,
    `duration_seconds` INT NOT NULL,
    `start_time` TIME NOT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_dithane_single_schedule` CHECK (`schedule_id` = 1),
    CONSTRAINT `chk_dithane_interval` CHECK (`interval_hours` > 0),
    CONSTRAINT `chk_dithane_duration` CHECK (`duration_seconds` > 0),
    CONSTRAINT `chk_dithane_start` CHECK (`start_time` BETWEEN '00:00:00' AND '23:59:59')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log aktivitas sistem: satu-satunya sumber log (dipakai dashboard,
-- halaman pengaturan, dan halaman history). reading_id adalah penunjung
-- longgar ke sensor_readings (tanpa FK) untuk ketertelusuran; penghapusan
-- data sensor tidak memengaruhi log.
CREATE TABLE IF NOT EXISTS `monitoring_logs` (
    `monitoring_id` INT AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('ppm','ph','dithane') NOT NULL,
    `day_name` VARCHAR(20) NOT NULL,
    `log_time` VARCHAR(10) NOT NULL,
    `value` VARCHAR(20) NOT NULL,
    `status` VARCHAR(30) NOT NULL,
    `note` TEXT,
    `reading_id` INT NULL,
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_type` (`type`),
    INDEX `idx_is_deleted` (`is_deleted`),
    INDEX `idx_type_deleted` (`type`, `is_deleted`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_monitoring_reading` (`reading_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Peringatan ambang batas hasil pengukuran.
CREATE TABLE IF NOT EXISTS `notifications` (
    `notification_id` INT AUTO_INCREMENT PRIMARY KEY,
    `label` VARCHAR(100) NOT NULL,
    `message` TEXT NOT NULL,
    `tone` ENUM('warning','success','info') NOT NULL DEFAULT 'info',
    `href` VARCHAR(255) DEFAULT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_is_read` (`is_read`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Identitas proyek (baris label/nilai per section: owner, team).
CREATE TABLE IF NOT EXISTS `profile` (
    `profile_id` INT AUTO_INCREMENT PRIMARY KEY,
    `section` ENUM('owner','team') NOT NULL,
    `label` VARCHAR(100) NOT NULL,
    `value` TEXT NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
