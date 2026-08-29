<?php
/**
 * Hydrotech Database Installer
 * 
 * Usage: php database/install.php
 * 
 * This script will:
 * 1. Create the 'monitoring_sensor' database
 * 2. Create all required tables from schema.sql
 * 3. Seed initial data with proper password hashing
 */

echo "=== Hydrotech Database Installer ===\n\n";

$host    = 'localhost';
$user    = 'root';
$pass    = '';
$dbname  = 'monitoring_sensor';
$charset = 'utf8mb4';

try {
    // ── 1. Connect to MySQL without selecting a database ──
    $pdo = new PDO("mysql:host=$host;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // ── 2. Create database ──
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` DEFAULT CHARACTER SET $charset COLLATE {$charset}_unicode_ci");
    $pdo->exec("USE `$dbname`");
    echo "[OK] Database '$dbname' created/selected.\n";

    // ── 3. Execute schema.sql ──
    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        die("[ERROR] schema.sql not found at: $schemaFile\n");
    }

    $sql = file_get_contents($schemaFile);
    // Remove SQL comments and split by semicolons
    $sql = preg_replace('/--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if (!empty($stmt)) {
            $pdo->exec($stmt);
        }
    }
    echo "[OK] All tables created successfully.\n";

    // ── 4. Seed Users ──
    $passwordHash = password_hash('Admin123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        "INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE password = VALUES(password), email = VALUES(email)"
    );
    $stmt->execute(['hydrotech', $passwordHash, 'admin@hydrotech.com', 'admin']);
    echo "[OK] Admin user created (username: hydrotech, password: Admin123).\n";

    // ── 5. Seed Settings ──
    $settingsData = [
        ['ppm',     json_encode(['min' => '800', 'max' => '1000'])],
        ['ph',      json_encode(['min' => '5,5', 'max' => '6,5'])],
        ['dithane', json_encode(['interval' => '48', 'duration' => '10', 'start' => '08.00'])],
    ];
    $stmt = $pdo->prepare(
        "INSERT INTO settings (type, config_json) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE config_json = VALUES(config_json)"
    );
    foreach ($settingsData as $s) {
        $stmt->execute($s);
    }
    echo "[OK] Settings seeded (PPM, pH, Dithane defaults).\n";

    // ── 6. Seed Monitoring Logs ──
    $pdo->exec("DELETE FROM monitoring_logs");
    $monitoringData = [
        // PPM monitoring logs (5 rows)
        ['ppm', 'Senin',  '10.00', '1000', 'Normal', 'Nilai PPM stabil, pertahankan'],
        ['ppm', 'Selasa', '11.00', '950',  'Normal', 'Nilai PPM stabil, pertahankan'],
        ['ppm', 'Rabu',   '09.30', '500',  'Rendah', 'PPM rendah, tambah nutrisi'],
        ['ppm', 'Kamis',  '14.00', '1400', 'Tinggi', 'PPM tinggi, kurangi nutrisi'],
        ['ppm', 'Jumat',  '08.00', '900',  'Normal', 'Nilai PPM stabil, pertahankan'],
        // pH monitoring logs (5 rows)
        ['ph', 'Senin',  '10.00', '6.5', 'Normal', 'pH dalam rentang optimal'],
        ['ph', 'Selasa', '11.00', '6',   'Normal', 'pH dalam rentang normal'],
        ['ph', 'Rabu',   '09.30', '3.5', 'Rendah', 'pH terlalu rendah, tambah pH UP'],
        ['ph', 'Kamis',  '14.00', '8',   'Tinggi', 'pH terlalu tinggi, tambah pH DOWN'],
        ['ph', 'Jumat',  '08.00', '6',   'Normal', 'pH dalam rentang normal'],
        // Dithane monitoring logs (5 rows)
        ['dithane', 'Senin',  '08.00', '0.5 l', 'Sudah Menyemprot', 'Penyemprotan selesai sesuai jadwal'],
        ['dithane', 'Selasa', '08.00', '0.5 l', 'Sudah Menyemprot', 'Penyemprotan selesai sesuai jadwal'],
        ['dithane', 'Rabu',   '08.00', '0.5 l', 'Sudah Menyemprot', 'Penyemprotan selesai sesuai jadwal'],
        ['dithane', 'Kamis',  '08.00', '0.5 l', 'Sudah Menyemprot', 'Penyemprotan selesai sesuai jadwal'],
        ['dithane', 'Jumat',  '08.00', '0.5 l', 'Sudah Menyemprot', 'Penyemprotan selesai sesuai jadwal'],
    ];
    $stmt = $pdo->prepare(
        "INSERT INTO monitoring_logs (type, day_name, log_time, value, status, note) VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($monitoringData as $row) {
        $stmt->execute($row);
    }
    echo "[OK] Monitoring logs seeded (15 rows: 5 PPM + 5 pH + 5 Dithane).\n";

    // ── 7. Seed History Logs ──
    $pdo->exec("DELETE FROM history_logs");
    $historyData = [
        ['Senin',   '2026-01-05', '08.00', 'ppm',     'Normal',  'Nilai PPM dalam rentang aman'],
        ['Selasa',  '2026-02-10', '09.30', 'ph',      'Normal',  'pH stabil di rentang optimal'],
        ['Rabu',    '2026-03-18', '10.00', 'ppm',     'Tinggi',  'PPM melampaui batas atas, kurangi nutrisi'],
        ['Kamis',   '2026-04-22', '11.00', 'ph',      'Rendah',  'pH di bawah batas minimum, tambah pH UP'],
        ['Jumat',   '2026-05-15', '14.00', 'dithane', 'Normal',  'Penyemprotan berjalan sesuai jadwal'],
        ['Senin',   '2026-06-02', '08.30', 'ppm',     'Normal',  'Nutrisi stabil dalam rentang optimal'],
        ['Selasa',  '2026-07-14', '09.00', 'ph',      'Tinggi',  'pH melampaui batas atas, tambah pH DOWN'],
        ['Rabu',    '2026-08-20', '10.30', 'dithane', 'Normal',  'Penyemprotan selesai tanpa kendala'],
        ['Kamis',   '2026-09-11', '13.00', 'ppm',     'Rendah',  'PPM di bawah batas minimum, tambah nutrisi'],
        ['Jumat',   '2026-10-08', '15.00', 'ph',      'Normal',  'pH dalam kondisi normal'],
    ];
    $stmt = $pdo->prepare(
        "INSERT INTO history_logs (day_name, log_date, log_time, type, status, note) VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($historyData as $row) {
        $stmt->execute($row);
    }
    echo "[OK] History logs seeded (10 rows, Jan-Oct 2026).\n";

    // ── 8. Seed Notifications ──
    $pdo->exec("DELETE FROM notifications");
    $notifData = [
        ['pH perlu dipantau',    'Nilai pH terakhir mendekati batas atas',                   'warning', 'pengaturan-ph.php'],
        ['PPM stabil',           'Nutrisi berada dalam rentang aman',                        'success', 'pengaturan-ppm.php'],
        ['Jadwal Dithane aktif', 'Penyemprotan berikutnya mengikuti jadwal otomatis',         'info',    'pengaturan-dithane.php'],
    ];
    $stmt = $pdo->prepare(
        "INSERT INTO notifications (label, message, tone, href) VALUES (?, ?, ?, ?)"
    );
    foreach ($notifData as $row) {
        $stmt->execute($row);
    }
    echo "[OK] Notifications seeded (3 rows).\n";

    // ── 9. Seed Profile ──
    $pdo->exec("DELETE FROM profile");
    $profileData = [
        ['owner', 'Nama',   'Edi Setiawan',                            1],
        ['owner', 'Email',  'EdiSetiawan@gmail.com',                   2],
        ['owner', 'WA',     '0856-4584-2028',                          3],
        ['owner', 'Lokasi', 'Jl. Raung, Gumuk Kerang, Ajung, Jember', 4],
        ['team',  'Nama Team', 'Kelompok 1 (Hydrotech)',               1],
        ['team',  'Peran',     'Web Developer',                         2],
        ['team',  'Kontak',    '0815-1533-4689',                        3],
        ['team',  'Alamat',    'Sumbersari Jember',                     4],
    ];
    $stmt = $pdo->prepare(
        "INSERT INTO profile (section, label, value, sort_order) VALUES (?, ?, ?, ?)"
    );
    foreach ($profileData as $row) {
        $stmt->execute($row);
    }
    echo "[OK] Profile data seeded (8 rows: 4 owner + 4 team).\n";

    // ── 10. Seed Sample Sensor Readings ──
    $pdo->exec("DELETE FROM sensor_readings");
    $sensorData = [
        [6.4, 845], [6.6, 858], [6.9, 874], [7.0, 862],
        [6.8, 892], [6.7, 876], [6.9, 864], [7.1, 863],
    ];
    $stmt = $pdo->prepare(
        "INSERT INTO sensor_readings (ph_value, ppm_value, recorded_at)
         VALUES (?, ?, DATE_SUB(NOW(), INTERVAL ? HOUR))"
    );
    foreach ($sensorData as $i => $row) {
        $hours = count($sensorData) - $i;
        $stmt->execute([$row[0], $row[1], $hours]);
    }
    echo "[OK] Sample sensor readings seeded (8 rows).\n";

    // ── Done ──
    echo "\n=== Installation Complete! ===\n";
    echo "Database: $dbname\n";
    echo "Tables: users, settings, monitoring_logs, history_logs, notifications, profile, sensor_readings\n";
    echo "\nTo start the server: php -S localhost:8000\n";
    echo "Login credentials: hydrotech / Admin123\n";

} catch (PDOException $e) {
    echo "\n[ERROR] Database error: " . $e->getMessage() . "\n";
    exit(1);
}

