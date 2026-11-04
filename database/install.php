<?php
/**
 * Hydrotech Database Installer
 * 
 * Usage: php database/install.php
 * 
 * This script will:
 * 1. Create the 'hydrotech' database
 * 2. Create all required tables from schema.sql
 * 3. Seed minimal initial data (admin user, default settings, jadwal Dithane,
 *    dan data profil pemilik) tanpa data sensor/log dummy.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Installer hanya tersedia melalui CLI.'); }

echo "=== Hydrotech Database Installer ===\n\n";

$host = getenv('HYDROTECH_DB_HOST') !== false ? getenv('HYDROTECH_DB_HOST') : 'localhost';
$user = getenv('HYDROTECH_DB_USER') !== false ? getenv('HYDROTECH_DB_USER') : 'root';
$pass = getenv('HYDROTECH_DB_PASS') !== false ? getenv('HYDROTECH_DB_PASS') : '';
$dbname = getenv('HYDROTECH_DB_NAME') !== false ? getenv('HYDROTECH_DB_NAME') : 'hydrotech';
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
    if ($pdo->query('SHOW TABLES')->fetchColumn() !== false) {
        throw new RuntimeException('Database sudah memiliki tabel. Gunakan php database/migrate_column_names.php untuk memperbarui tanpa mereset data.');
    }
    echo "[OK] Database '$dbname' created/selected.\n";

    // ── 3. Execute schema.sql ──
    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        die("[ERROR] schema.sql not found at: $schemaFile\n");
    }

    $sql = file_get_contents($schemaFile);
    // Remove SQL comments, then split on top-level semicolons (keadaan aman
    // terhadap ';' di dalam string COMMENT).
    $sql = preg_replace('/--.*$/m', '', $sql);
    $statements = [];
    $buffer = '';
    $inString = false;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        if ($char === "'" && ($i === 0 || $sql[$i - 1] !== '\\')) {
            $inString = !$inString;
        }
        if ($char === ';' && !$inString) {
            if (trim($buffer) !== '') $statements[] = trim($buffer);
            $buffer = '';
            continue;
        }
        $buffer .= $char;
    }
    if (trim($buffer) !== '') $statements[] = trim($buffer);
    foreach ($statements as $stmt) {
        if (!empty($stmt)) {
            $pdo->exec($stmt);
        }
    }
    echo "[OK] All tables created successfully.\n";

    // ── 4. Seed Users ──
    $pdo->beginTransaction();
    $passwordHash = password_hash('Admin123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        "INSERT INTO users (username, password, email) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE password = VALUES(password), email = VALUES(email)"
    );
    $stmt->execute(['hydrotech', $passwordHash, 'admin@hydrotech.com']);
    $adminId = (int)$pdo->lastInsertId();
    if (!$adminId) {
        $adminId = (int)$pdo->query("SELECT user_id FROM users WHERE username = 'hydrotech'")->fetchColumn();
    }
    echo "[OK] Admin user created (username: hydrotech, password: Admin123).\n";

    // ── 5. Seed Settings (jadwal Dithane disimpan di tabel dithane_schedules) ──
    $settingsData = [
        ['ppm', json_encode(['min' => '800', 'max' => '1000'])],
        ['ph',  json_encode(['min' => '5,5', 'max' => '6,5'])],
    ];
    $stmt = $pdo->prepare(
        "INSERT INTO settings (type, config_json, updated_by) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE config_json = VALUES(config_json)"
    );
    foreach ($settingsData as $s) {
        $stmt->execute([$s[0], $s[1], $adminId]);
    }
    echo "[OK] Settings seeded (PPM, pH defaults).\n";

    // ── 6. Seed Profil Pemilik (identitas proyek; label = yang dicari pages/profile.php) ──
    $profileData = [
        ['owner', 'Nama Pemilik', 'Edi Setiawan',                            1],
        ['owner', 'Email',        'EdiSetiawan@gmail.com',                   2],
        ['owner', 'WhatsApp',     '0856-4584-2028',                          3],
        ['owner', 'Lokasi',       'Jl. Raung, Gumuk Kerang, Ajung, Jember', 4],
        ['team',  'Nama Team',    'Kelompok 1 (Hydrotech)',                  1],
        ['team',  'Peran',        'Web Developer',                           2],
        ['team',  'Kontak',       '0815-1533-4689',                          3],
        ['team',  'Alamat',       'Sumbersari Jember',                       4],
    ];
    $stmt = $pdo->prepare(
        "INSERT INTO profile (user_id, section, label, value, sort_order) VALUES (?, ?, ?, ?, ?)"
    );
    foreach ($profileData as $row) {
        $stmt->execute([$adminId, $row[0], $row[1], $row[2], $row[3]]);
    }
    echo "[OK] Profile data seeded (8 rows: 4 owner + 4 team).\n";

    // ── 7. Default jadwal Dithane ──
    $pdo->exec("INSERT INTO dithane_schedules (schedule_id,interval_hours,duration_seconds,start_time,updated_by) VALUES (1,48,10,'08:00:00',$adminId)");
    echo "[OK] Jadwal Dithane default dibuat (interval 48 jam, 10 detik, mulai 08:00).\n";

    // ── Done ──
    $pdo->commit();
    echo "\n=== Installation Complete! ===\n";
    echo "Database: $dbname\n";
    echo "Tables: users, settings, sensor_readings, dithane_schedules, monitoring_logs, notifications, profile\n";
    echo "Tabel sensor/log kosong dan siap menerima data asli dari perangkat ESP\n";
    echo "(kirim pengukuran ke api/sensor/ingest.php dengan API key dari config/esp.php).\n";
    echo "\nTo start the server: php -S localhost:8000\n";
    echo "Login credentials: hydrotech / Admin123\n";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo "\n[ERROR] Database error: " . $e->getMessage() . "\n";
    exit(1);
}

