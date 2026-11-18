<?php
/**
 * Hydrotech - Migrasi Data Aktivitas Sistem (riwayat kesimpulan gabungan)
 *
 * Konsep baru: halaman Data Aktivitas Sistem menampilkan SATU baris per
 * kejadian yang merupakan kesimpulan kondisi pH + PPM + Dithane, disimpan
 * di monitoring_logs dengan type = 'sistem' (3 kolom penunjang baru).
 * Tanpa tabel baru; data lama tidak tersentuh. Aman dijalankan berulang.
 *
 * Jalankan: php database/migrate_system_activity.php
 */

require_once __DIR__ . '/../config/database.php';

$pdo = getDBConnection();

/* Kolom sudah ada? (idempoten) */
function columnExists(PDO $pdo, string $table, string $column): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $q->execute([$table, $column]);
    return (int)$q->fetchColumn() > 0;
}

/* 1. Perluas ENUM type dengan 'sistem' (mendukung nilai lama).           */
$pdo->exec("ALTER TABLE `monitoring_logs`
             MODIFY `type` ENUM('ppm','ph','dithane','sistem') NOT NULL COMMENT 'pH/PPM/Dithane = log komponen; sistem = kesimpulan gabungan'");
echo "OK     ENUM type + 'sistem'\n";

/* 2. Kolom penunjang baris 'sistem' (nilai masing-masing komponen).     */
foreach ([
    ['ph_value', "ADD COLUMN `ph_value` DECIMAL(6,4) NULL COMMENT 'Nilai pH pada kesimpulan' AFTER `note`"],
    ['ppm_value', "ADD COLUMN `ppm_value` INT NULL COMMENT 'Nilai PPM pada kesimpulan' AFTER `ph_value`"],
    ['dithane_state', "ADD COLUMN `dithane_state` VARCHAR(30) NULL COMMENT 'menunggu|menyemprot|selesai|terlambat|kosong' AFTER `ppm_value`"],
] as [$column, $ddl]) {
    if (columnExists($pdo, 'monitoring_logs', $column)) {
        echo "LEWATI  kolom $column (sudah ada)\n";
    } else {
        $pdo->exec("ALTER TABLE `monitoring_logs` $ddl");
        echo "OK     kolom $column\n";
    }
}

echo "Migrasi Data Aktivitas Sistem selesai.\n";
