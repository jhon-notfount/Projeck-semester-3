-- Hydrotech: 9 tabel, 60 kolom, 9 foreign key.
-- Data numerik pH/PPM terpisah, jadwal Dithane berulang.
-- Database 8 tabel: jalankan php database/migrate_measurements.php.

CREATE TABLE IF NOT EXISTS `users` (
`id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `role` ENUM('admin','user') NOT NULL DEFAULT 'admin',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
`id` INT AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('ppm','ph','dithane') NOT NULL UNIQUE,
    `config_json` JSON NOT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT NULL,
    INDEX `idx_settings_updated_by` (`updated_by`),
    CONSTRAINT `fk_settings_user` FOREIGN KEY (`updated_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `profile` (
`id` INT AUTO_INCREMENT PRIMARY KEY,
    `section` ENUM('owner','team') NOT NULL,
    `label` VARCHAR(100) NOT NULL,
    `value` TEXT NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `updated_by` INT NULL,
    INDEX `idx_profile_updated_by` (`updated_by`),
    CONSTRAINT `fk_profile_user` FOREIGN KEY (`updated_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ph_readings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ph_value` DECIMAL(6,4) NOT NULL COMMENT 'Hasil pengukuran: pH tanpa satuan',
    `recorded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ph_time` (`recorded_at`, `id`),
    CONSTRAINT `chk_ph_value` CHECK (`ph_value` BETWEEN 0 AND 14)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ppm_readings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ppm_value` INT NOT NULL COMMENT 'Hasil pengukuran: konsentrasi dalam ppm',
    `recorded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ppm_time` (`recorded_at`, `id`),
    CONSTRAINT `chk_ppm_value` CHECK (`ppm_value` BETWEEN 0 AND 2147483647)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dithane_schedules` (
    `id` INT PRIMARY KEY,
    `interval_hours` INT NOT NULL,
    `duration_seconds` INT NOT NULL,
    `start_time` TIME NOT NULL,
    `updated_by` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_dithane_single_schedule` CHECK (`id` = 1),
    CONSTRAINT `chk_dithane_interval` CHECK (`interval_hours` > 0),
    CONSTRAINT `chk_dithane_duration` CHECK (`duration_seconds` > 0),
    CONSTRAINT `chk_dithane_start` CHECK (`start_time` BETWEEN '00:00:00' AND '23:59:59'),
    CONSTRAINT `fk_dithane_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `monitoring_logs` (
`id` INT AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('ppm','ph','dithane') NOT NULL,
    `day_name` VARCHAR(20) NOT NULL,
    `log_time` VARCHAR(10) NOT NULL,
    `value` VARCHAR(20) NOT NULL,
    `status` VARCHAR(30) NOT NULL,
    `note` TEXT,
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_type` (`type`),
    INDEX `idx_is_deleted` (`is_deleted`),
    INDEX `idx_type_deleted` (`type`, `is_deleted`),
    `ph_reading_id` INT NULL,
    `ppm_reading_id` INT NULL,
    INDEX `idx_monitoring_ph` (`ph_reading_id`),
    INDEX `idx_monitoring_ppm` (`ppm_reading_id`),
    CONSTRAINT `fk_monitoring_type` FOREIGN KEY (`type`)
        REFERENCES `settings` (`type`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_monitoring_ph` FOREIGN KEY (`ph_reading_id`)
        REFERENCES `ph_readings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_monitoring_ppm` FOREIGN KEY (`ppm_reading_id`)
        REFERENCES `ppm_readings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `chk_monitoring_one_reading` CHECK (
        (`ph_reading_id` IS NULL OR (`type` = 'ph' AND `ppm_reading_id` IS NULL)) AND
        (`ppm_reading_id` IS NULL OR (`type` = 'ppm' AND `ph_reading_id` IS NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `history_logs` (
`id` INT AUTO_INCREMENT PRIMARY KEY,
    `day_name` VARCHAR(20) NOT NULL,
    `log_date` DATE NOT NULL,
    `log_time` VARCHAR(10) NOT NULL,
    `type` ENUM('ppm','ph','dithane') NOT NULL,
    `status` VARCHAR(30) NOT NULL,
    `note` TEXT,
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_type` (`type`),
    INDEX `idx_log_date` (`log_date`),
    INDEX `idx_is_deleted` (`is_deleted`),
    INDEX `idx_status` (`status`),
    INDEX `idx_type_date_deleted` (`type`, `log_date`, `is_deleted`),
    CONSTRAINT `fk_history_type` FOREIGN KEY (`type`)
        REFERENCES `settings` (`type`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
`id` INT AUTO_INCREMENT PRIMARY KEY,
    `label` VARCHAR(100) NOT NULL,
    `message` TEXT NOT NULL,
    `tone` ENUM('warning','success','info') NOT NULL DEFAULT 'info',
    `href` VARCHAR(255) DEFAULT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_is_read` (`is_read`),
    INDEX `idx_created_at` (`created_at`),
    `ph_reading_id` INT NULL,
    `ppm_reading_id` INT NULL,
    INDEX `idx_notifications_ph` (`ph_reading_id`),
    INDEX `idx_notifications_ppm` (`ppm_reading_id`),
    CONSTRAINT `fk_notifications_ph` FOREIGN KEY (`ph_reading_id`)
        REFERENCES `ph_readings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_notifications_ppm` FOREIGN KEY (`ppm_reading_id`)
        REFERENCES `ppm_readings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `chk_notifications_one_reading` CHECK (`ph_reading_id` IS NULL OR `ppm_reading_id` IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

