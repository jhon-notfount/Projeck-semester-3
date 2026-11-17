/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19-12.3.2-MariaDB, for Linux (x86_64)
--
-- Host: localhost    Database: hydrotech
-- ------------------------------------------------------
-- Server version	12.3.2-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

--
-- Table structure for table `account_change_logs`
--

DROP TABLE IF EXISTS `account_change_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_change_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL COMMENT 'Merujuk users.user_id; NULL bila username tidak dikenal',
  `action_type` varchar(30) NOT NULL COMMENT 'username_change / password_change',
  `source` varchar(20) NOT NULL COMMENT 'login / profile',
  `old_username` varchar(100) DEFAULT NULL,
  `new_username` varchar(100) DEFAULT NULL,
  `status` varchar(20) NOT NULL COMMENT 'success / failed',
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_account_logs_user` (`user_id`),
  KEY `idx_account_logs_created` (`created_at`),
  CONSTRAINT `fk_account_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_change_logs`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `account_change_logs` WRITE;
/*!40000 ALTER TABLE `account_change_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `account_change_logs` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `dithane_schedules`
--

DROP TABLE IF EXISTS `dithane_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dithane_schedules` (
  `schedule_id` int(11) NOT NULL,
  `interval_hours` int(11) NOT NULL,
  `duration_seconds` int(11) NOT NULL,
  `start_time` time NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`schedule_id`),
  KEY `idx_dithane_updated_by` (`updated_by`),
  CONSTRAINT `fk_dithane_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_dithane_single_schedule` CHECK (`schedule_id` = 1),
  CONSTRAINT `chk_dithane_interval` CHECK (`interval_hours` > 0),
  CONSTRAINT `chk_dithane_duration` CHECK (`duration_seconds` > 0),
  CONSTRAINT `chk_dithane_start` CHECK (`start_time` between '00:00:00' and '23:59:59')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dithane_schedules`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `dithane_schedules` WRITE;
/*!40000 ALTER TABLE `dithane_schedules` DISABLE KEYS */;
INSERT INTO `dithane_schedules` VALUES
(1,48,10,'08:00:00',1,'2026-11-12 23:18:37');
/*!40000 ALTER TABLE `dithane_schedules` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `monitoring_logs`
--

DROP TABLE IF EXISTS `monitoring_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `monitoring_logs` (
  `monitoring_id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('ppm','ph','dithane') NOT NULL,
  `day_name` varchar(20) NOT NULL,
  `log_time` varchar(10) NOT NULL,
  `value` varchar(20) NOT NULL,
  `status` varchar(30) NOT NULL,
  `note` text DEFAULT NULL,
  `reading_id` int(11) DEFAULT NULL,
  `schedule_id` int(11) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`monitoring_id`),
  KEY `idx_type` (`type`),
  KEY `idx_is_deleted` (`is_deleted`),
  KEY `idx_type_deleted` (`type`,`is_deleted`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_monitoring_reading` (`reading_id`),
  KEY `idx_monitoring_schedule` (`schedule_id`),
  CONSTRAINT `fk_monitoring_reading` FOREIGN KEY (`reading_id`) REFERENCES `sensor_readings` (`reading_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_monitoring_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `dithane_schedules` (`schedule_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `monitoring_logs`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `monitoring_logs` WRITE;
/*!40000 ALTER TABLE `monitoring_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `monitoring_logs` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `label` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `tone` enum('warning','success','info') NOT NULL DEFAULT 'info',
  `href` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `monitoring_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_notifications_monitoring` (`monitoring_id`),
  KEY `idx_notifications_user` (`user_id`),
  CONSTRAINT `fk_notifications_monitoring` FOREIGN KEY (`monitoring_id`) REFERENCES `monitoring_logs` (`monitoring_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `profile`
--

DROP TABLE IF EXISTS `profile`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `profile` (
  `profile_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `section` enum('owner','team') NOT NULL,
  `label` varchar(100) NOT NULL,
  `value` text NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`profile_id`),
  UNIQUE KEY `uq_profile_section_label` (`user_id`,`section`,`label`),
  KEY `idx_profile_user` (`user_id`),
  CONSTRAINT `fk_profile_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `profile`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `profile` WRITE;
/*!40000 ALTER TABLE `profile` DISABLE KEYS */;
INSERT INTO `profile` VALUES
(1,1,'owner','Nama Pemilik','Edi Setiawan',1),
(2,1,'owner','Email','EdiSetiawan@gmail.com',2),
(3,1,'owner','WhatsApp','0856-4584-2028',3),
(4,1,'owner','Lokasi','Jl. Raung, Gumuk Kerang, Ajung, Jember',4),
(5,1,'team','Nama Team','Kelompok 1 (Hydrotech)',1),
(6,1,'team','Peran','Web Developer',2),
(7,1,'team','Kontak','0815-1533-4689',3),
(8,1,'team','Alamat','Sumbersari Jember',4);
/*!40000 ALTER TABLE `profile` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `sensor_readings`
--

DROP TABLE IF EXISTS `sensor_readings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sensor_readings` (
  `reading_id` int(11) NOT NULL AUTO_INCREMENT,
  `ph_value` decimal(6,4) DEFAULT NULL COMMENT 'Hasil pengukuran: pH tanpa satuan',
  `ppm_value` int(11) DEFAULT NULL COMMENT 'Hasil pengukuran: konsentrasi dalam ppm',
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`reading_id`),
  KEY `idx_sensor_time` (`recorded_at`,`reading_id`),
  CONSTRAINT `chk_sensor_any_value` CHECK (`ph_value` is not null or `ppm_value` is not null),
  CONSTRAINT `chk_ph_value` CHECK (`ph_value` is null or `ph_value` between 0 and 14),
  CONSTRAINT `chk_ppm_value` CHECK (`ppm_value` is null or `ppm_value` between 0 and 2147483647)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sensor_readings`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `sensor_readings` WRITE;
/*!40000 ALTER TABLE `sensor_readings` DISABLE KEYS */;
/*!40000 ALTER TABLE `sensor_readings` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `type` enum('ppm','ph') NOT NULL,
  `config_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`config_json`)),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`type`),
  KEY `idx_settings_updated_by` (`updated_by`),
  CONSTRAINT `fk_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES
('ppm','{\"min\":\"800\",\"max\":\"1000\"}','2026-11-12 23:18:37',1),
('ph','{\"min\":\"5,5\",\"max\":\"6,5\"}','2026-11-12 23:18:37',1);
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `password_changed_at` timestamp NULL DEFAULT NULL COMMENT 'Waktu ganti password terakhir - sesi lebih tua otomatis logout',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(1,'hydrotech','$2y$12$HwLs5TRDxR13p4bMViTUU.dz2HRSwN9R893uMCAwB5fs8ruK6QSzK','admin@hydrotech.com','2026-11-12 23:18:37','2026-11-12 23:18:37',NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-11-17  9:35:28
