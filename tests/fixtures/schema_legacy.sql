-- =====================================================
-- Hydrotech Monitoring Sensor - Database Schema
-- Database: monitoring_sensor
-- =====================================================

-- Users table for authentication
-- Struktur mendukung multi-user di masa depan (field role)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `role` ENUM('admin','user') NOT NULL DEFAULT 'admin',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Settings for PPM, pH, Dithane configurations
-- config_json stores flexible key-value config per type
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('ppm','ph','dithane') NOT NULL UNIQUE,
    `config_json` JSON NOT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Monitoring logs for per-parameter monitoring tables (halaman pengaturan)
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
    INDEX `idx_type_deleted` (`type`, `is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- History logs for global monitoring history (halaman history)
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
    INDEX `idx_type_date_deleted` (`type`, `log_date`, `is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notifications - dynamic notifications based on sensor conditions
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `label` VARCHAR(100) NOT NULL,
    `message` TEXT NOT NULL,
    `tone` ENUM('warning','success','info') NOT NULL DEFAULT 'info',
    `href` VARCHAR(255) DEFAULT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_is_read` (`is_read`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Profile information (owner and development team)
CREATE TABLE IF NOT EXISTS `profile` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `section` ENUM('owner','team') NOT NULL,
    `label` VARCHAR(100) NOT NULL,
    `value` TEXT NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sensor readings for live dashboard data storage
CREATE TABLE IF NOT EXISTS `sensor_readings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ph_value` DECIMAL(5,2) NOT NULL,
    `ppm_value` INT NOT NULL,
    `recorded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_recorded_at` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

