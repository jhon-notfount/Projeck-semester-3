-- RANCANGAN BARU HYDROTECH DENGAN SENSOR - BELUM DIJALANKAN.
-- Untuk database rancangan kosong; bukan migrasi langsung database aktif.
-- Nama database berbeda agar tidak menggunakan monitoring_sensor milik aplikasi.
-- Aplikasi lama perlu diubah sebelum memakai model satu nilai per sensor ini.
CREATE DATABASE `monitoring_sensor_rancangan`
    DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `monitoring_sensor_rancangan`;

CREATE TABLE `users` (
`id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `role` ENUM('admin','user') NOT NULL DEFAULT 'admin',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings` (
`id` INT AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('ppm','ph','dithane') NOT NULL UNIQUE,
    `config_json` JSON NOT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT NULL,
    INDEX `idx_settings_updated_by` (`updated_by`),
    CONSTRAINT `fk_settings_user` FOREIGN KEY (`updated_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `profile` (
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

CREATE TABLE `sensors` (
`id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `type` ENUM('ph','ppm') NOT NULL,
    `unit` ENUM('pH','ppm') NOT NULL,
    `status` ENUM('aktif','nonaktif','rusak') NOT NULL DEFAULT 'aktif',
    `location` VARCHAR(150) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_sensor_type_status` (`type`, `status`),
    CONSTRAINT `chk_sensor_unit` CHECK (
        (`type` = 'ph' AND `unit` = 'pH') OR
        (`type` = 'ppm' AND `unit` = 'ppm')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sensor_readings` (
`id` INT AUTO_INCREMENT PRIMARY KEY,
    `sensor_id` INT NOT NULL,
    `value` DECIMAL(12,4) NOT NULL,
    `recorded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_recorded_at` (`recorded_at`),
    INDEX `idx_sensor_time` (`sensor_id`, `recorded_at`),
    CONSTRAINT `fk_reading_sensor` FOREIGN KEY (`sensor_id`)
        REFERENCES `sensors` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `monitoring_logs` (
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
    `sensor_reading_id` INT NULL,
    INDEX `idx_monitoring_reading` (`sensor_reading_id`),
    CONSTRAINT `fk_monitoring_type` FOREIGN KEY (`type`)
        REFERENCES `settings` (`type`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_monitoring_reading` FOREIGN KEY (`sensor_reading_id`)
        REFERENCES `sensor_readings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `history_logs` (
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

CREATE TABLE `notifications` (
`id` INT AUTO_INCREMENT PRIMARY KEY,
    `label` VARCHAR(100) NOT NULL,
    `message` TEXT NOT NULL,
    `tone` ENUM('warning','success','info') NOT NULL DEFAULT 'info',
    `href` VARCHAR(255) DEFAULT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_is_read` (`is_read`),
    INDEX `idx_created_at` (`created_at`),
    `sensor_reading_id` INT NULL,
    INDEX `idx_notifications_reading` (`sensor_reading_id`),
    CONSTRAINT `fk_notifications_reading` FOREIGN KEY (`sensor_reading_id`)
        REFERENCES `sensor_readings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contoh data khusus untuk memperjelas identitas dan hasil ukur sensor.
INSERT INTO sensors (id, name, type, unit, status, location) VALUES
    (1, 'Sensor pH Larutan', 'ph', 'pH', 'aktif', 'Bak nutrisi utama'),
    (2, 'Sensor TDS Larutan', 'ppm', 'ppm', 'aktif', 'Bak nutrisi utama');

SET @waktu_contoh = CURRENT_TIMESTAMP;
INSERT INTO sensor_readings (sensor_id, value, recorded_at) VALUES
    (1, 6.40, @waktu_contoh),
    (2, 845.00, @waktu_contoh);

INSERT INTO settings (type, config_json) VALUES
    ('ppm', '{"min":"800","max":"1000"}'),
    ('ph', '{"min":"5,5","max":"6,5"}'),
    ('dithane', '{"interval":"48","duration":"10","start":"08.00"}');
