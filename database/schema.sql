-- Hydrotech: 9 tabel, 60 kolom, 9 foreign key.
-- Data numerik pH/PPM terpisah, jadwal Dithane berulang.
-- Penamaan Indonesia: php database/migrate_column_names.php.

CREATE TABLE IF NOT EXISTS `users` (
`id_pengguna` INT AUTO_INCREMENT PRIMARY KEY,
    `nama_pengguna` VARCHAR(50) NOT NULL UNIQUE,
    `kata_sandi` VARCHAR(255) NOT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `peran` ENUM('admin','user') NOT NULL DEFAULT 'admin',
    `dibuat_pada` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `diperbarui_pada` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
`id_pengaturan` INT AUTO_INCREMENT PRIMARY KEY,
    `jenis` ENUM('ppm','ph','dithane') NOT NULL UNIQUE,
    `konfigurasi_json` JSON NOT NULL,
    `diperbarui_pada` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `diubah_oleh_id` INT NULL,
    INDEX `idx_settings_updated_by` (`diubah_oleh_id`),
    CONSTRAINT `fk_settings_user` FOREIGN KEY (`diubah_oleh_id`)
        REFERENCES `users` (`id_pengguna`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `profile` (
`id_profil` INT AUTO_INCREMENT PRIMARY KEY,
    `bagian` ENUM('owner','team') NOT NULL,
    `label` VARCHAR(100) NOT NULL,
    `isi` TEXT NOT NULL,
    `urutan` INT NOT NULL DEFAULT 0,
    `diubah_oleh_id` INT NULL,
    INDEX `idx_profile_updated_by` (`diubah_oleh_id`),
    CONSTRAINT `fk_profile_user` FOREIGN KEY (`diubah_oleh_id`)
        REFERENCES `users` (`id_pengguna`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ph_readings` (
    `id_pembacaan_ph` INT AUTO_INCREMENT PRIMARY KEY,
    `nilai_ph` DECIMAL(6,4) NOT NULL COMMENT 'Hasil pengukuran: pH tanpa satuan',
    `direkam_pada` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ph_time` (`direkam_pada`, `id_pembacaan_ph`),
    CONSTRAINT `chk_ph_value` CHECK (`nilai_ph` BETWEEN 0 AND 14)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ppm_readings` (
    `id_pembacaan_ppm` INT AUTO_INCREMENT PRIMARY KEY,
    `nilai_ppm` INT NOT NULL COMMENT 'Hasil pengukuran: konsentrasi dalam ppm',
    `direkam_pada` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ppm_time` (`direkam_pada`, `id_pembacaan_ppm`),
    CONSTRAINT `chk_ppm_value` CHECK (`nilai_ppm` BETWEEN 0 AND 2147483647)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dithane_schedules` (
    `id_jadwal_dithane` INT PRIMARY KEY,
    `interval_jam` INT NOT NULL,
    `durasi_detik` INT NOT NULL,
    `jam_mulai` TIME NOT NULL,
    `diubah_oleh_id` INT NULL,
    `dibuat_pada` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `diperbarui_pada` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_dithane_single_schedule` CHECK (`id_jadwal_dithane` = 1),
    CONSTRAINT `chk_dithane_interval` CHECK (`interval_jam` > 0),
    CONSTRAINT `chk_dithane_duration` CHECK (`durasi_detik` > 0),
    CONSTRAINT `chk_dithane_start` CHECK (`jam_mulai` BETWEEN '00:00:00' AND '23:59:59'),
    CONSTRAINT `fk_dithane_user` FOREIGN KEY (`diubah_oleh_id`) REFERENCES `users` (`id_pengguna`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `monitoring_logs` (
`id_monitoring` INT AUTO_INCREMENT PRIMARY KEY,
    `jenis` ENUM('ppm','ph','dithane') NOT NULL,
    `nama_hari` VARCHAR(20) NOT NULL,
    `jam_catatan` VARCHAR(10) NOT NULL,
    `nilai` VARCHAR(20) NOT NULL,
    `status` VARCHAR(30) NOT NULL,
    `keterangan` TEXT,
    `dihapus` TINYINT(1) NOT NULL DEFAULT 0,
    `dibuat_pada` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_type` (`jenis`),
    INDEX `idx_is_deleted` (`dihapus`),
    INDEX `idx_type_deleted` (`jenis`, `dihapus`),
    `id_pembacaan_ph` INT NULL,
    `id_pembacaan_ppm` INT NULL,
    INDEX `idx_monitoring_ph` (`id_pembacaan_ph`),
    INDEX `idx_monitoring_ppm` (`id_pembacaan_ppm`),
    CONSTRAINT `fk_monitoring_type` FOREIGN KEY (`jenis`)
        REFERENCES `settings` (`jenis`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_monitoring_ph` FOREIGN KEY (`id_pembacaan_ph`)
        REFERENCES `ph_readings` (`id_pembacaan_ph`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_monitoring_ppm` FOREIGN KEY (`id_pembacaan_ppm`)
        REFERENCES `ppm_readings` (`id_pembacaan_ppm`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `chk_monitoring_one_reading` CHECK (
        (`id_pembacaan_ph` IS NULL OR (`jenis` = 'ph' AND `id_pembacaan_ppm` IS NULL)) AND
        (`id_pembacaan_ppm` IS NULL OR (`jenis` = 'ppm' AND `id_pembacaan_ph` IS NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `history_logs` (
`id_riwayat` INT AUTO_INCREMENT PRIMARY KEY,
    `nama_hari` VARCHAR(20) NOT NULL,
    `tanggal_catatan` DATE NOT NULL,
    `jam_catatan` VARCHAR(10) NOT NULL,
    `jenis` ENUM('ppm','ph','dithane') NOT NULL,
    `status` VARCHAR(30) NOT NULL,
    `keterangan` TEXT,
    `dihapus` TINYINT(1) NOT NULL DEFAULT 0,
    `dibuat_pada` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_type` (`jenis`),
    INDEX `idx_log_date` (`tanggal_catatan`),
    INDEX `idx_is_deleted` (`dihapus`),
    INDEX `idx_status` (`status`),
    INDEX `idx_type_date_deleted` (`jenis`, `tanggal_catatan`, `dihapus`),
    CONSTRAINT `fk_history_type` FOREIGN KEY (`jenis`)
        REFERENCES `settings` (`jenis`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
`id_notifikasi` INT AUTO_INCREMENT PRIMARY KEY,
    `label` VARCHAR(100) NOT NULL,
    `pesan` TEXT NOT NULL,
    `tingkat_pesan` ENUM('warning','success','info') NOT NULL DEFAULT 'info',
    `tautan` VARCHAR(255) DEFAULT NULL,
    `sudah_dibaca` TINYINT(1) NOT NULL DEFAULT 0,
    `dibuat_pada` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_is_read` (`sudah_dibaca`),
    INDEX `idx_created_at` (`dibuat_pada`),
    `id_pembacaan_ph` INT NULL,
    `id_pembacaan_ppm` INT NULL,
    INDEX `idx_notifications_ph` (`id_pembacaan_ph`),
    INDEX `idx_notifications_ppm` (`id_pembacaan_ppm`),
    CONSTRAINT `fk_notifications_ph` FOREIGN KEY (`id_pembacaan_ph`)
        REFERENCES `ph_readings` (`id_pembacaan_ph`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_notifications_ppm` FOREIGN KEY (`id_pembacaan_ppm`)
        REFERENCES `ppm_readings` (`id_pembacaan_ppm`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `chk_notifications_one_reading` CHECK (`id_pembacaan_ph` IS NULL OR `id_pembacaan_ppm` IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

