<?php
/** Upgrade seven-table Hydrotech databases without reseeding or resetting data. */
require_once __DIR__ . '/../config/database.php';

function sensorSchemaStatements(): array {
    $sql = preg_replace('/--[^\r\n]*/', '', file_get_contents(__DIR__ . '/schema.sql'));
    $statements = [];
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if (preg_match('/CREATE TABLE IF NOT EXISTS `(\w+)`/', $stmt, $m)) $statements[$m[1]] = $stmt;
    }
    return $statements;
}

function sensorTableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function sensorColumnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function sensorBackup(PDO $pdo, string $file, ?array $tables = null): void {
    $tables = $tables ?? $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $handle = fopen($file, 'xb');
    if (!$handle) throw new RuntimeException('Tidak dapat membuat cadangan.');
    $write = static function (string $sql) use ($handle): void {
        if (fwrite($handle, $sql) !== strlen($sql)) throw new RuntimeException('Cadangan tidak selesai ditulis.');
    };
    try {
        $write("-- Cadangan Hydrotech. Pulihkan ke database kosong yang dipilih secara eksplisit.\nSET FOREIGN_KEY_CHECKS=0;\n");
        $pdo->beginTransaction();
        foreach ($tables as $table) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) throw new RuntimeException('Nama tabel tidak didukung untuk cadangan.');
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
            $write($create . ";\n");
            $rows = $pdo->query("SELECT * FROM `$table`");
            while ($row = $rows->fetch(PDO::FETCH_NUM)) {
                $values = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row);
                $write("INSERT INTO `$table` VALUES (" . implode(',', $values) . ");\n");
            }
        }
        $pdo->commit();
        $write("SET FOREIGN_KEY_CHECKS=1;\n");
        if (!fflush($handle)) throw new RuntimeException('Cadangan gagal disimpan.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        fclose($handle);
    }
}

function migrateSensors(PDO $pdo, string $backupDirectory): array {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $lockName = 'hydrotech_sensors_' . substr(hash('sha256', $db), 0, 32);
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Migrasi lain sedang berjalan.');
    $result = ['converted_rows' => 0, 'backups' => []];
    try {
        foreach (['users','settings','profile','sensor_readings','monitoring_logs','history_logs','notifications'] as $table) {
            if (!sensorTableExists($pdo, $table)) throw new RuntimeException("Tabel $table belum ada. Gunakan installer untuk database baru.");
        }
        foreach (['monitoring_logs','history_logs'] as $table) {
            $orphans = $pdo->query("SELECT COUNT(*) FROM `$table` l LEFT JOIN settings s ON s.type=l.type WHERE s.id IS NULL")->fetchColumn();
            if ($orphans) throw new RuntimeException("Ada jenis $table tanpa pengaturan. Perbaiki sebelum migrasi.");
        }
        if (sensorTableExists($pdo, 'sensor_readings_v2') || sensorTableExists($pdo, 'sensor_readings_legacy')) {
            throw new RuntimeException('Ada tabel sementara migrasi sebelumnya. Tinjau cadangan dan tabel tersebut sebelum melanjutkan.');
        }
        $legacy = sensorColumnExists($pdo, 'sensor_readings', 'ph_value');
        if ($legacy) {
            if ($pdo->query('SELECT COUNT(*) FROM sensor_readings WHERE recorded_at IS NULL')->fetchColumn()) {
                throw new RuntimeException('Ada waktu pembacaan NULL. Tentukan waktunya sebelum migrasi, tanpa mengganti diam-diam.');
            }
            $refs = $pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='sensor_readings'")->fetchColumn();
            if ($refs) throw new RuntimeException('Tabel pembacaan lama sudah dirujuk FK. Pemetaan referensi harus ditinjau terlebih dahulu.');
        } elseif (!sensorColumnExists($pdo, 'sensor_readings', 'sensor_id')) {
            throw new RuntimeException('Struktur pembacaan tidak dikenali.');
        }
        if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true)) throw new RuntimeException('Folder cadangan tidak dapat dibuat.');
        $stamp = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $backup = $backupDirectory . '/' . $db . '_' . $stamp . '.sql';
        sensorBackup($pdo, $backup);
        $result['backups'][] = $backup;
        $schema = sensorSchemaStatements();
        $pdo->exec($schema['sensors']);
        if ($legacy) {
            $sensorIds = [];
            $insertSensor = $pdo->prepare("INSERT INTO sensors (name,type,unit,status,location) VALUES (?,?,?,'aktif','Simulasi/data lama aplikasi')");
            foreach (['ph' => ['Sensor pH (simulasi/data lama)','pH'], 'ppm' => ['Sensor TDS (simulasi/data lama)','ppm']] as $type => [$name,$unit]) {
                $existing = $pdo->prepare('SELECT id FROM sensors WHERE name=? AND type=? ORDER BY id');
                $existing->execute([$name,$type]);
                $ids = $existing->fetchAll(PDO::FETCH_COLUMN);
                if (count($ids) > 1) throw new RuntimeException('Sensor migrasi ambigu.');
                if ($ids) $sensorIds[$type] = (int)$ids[0];
                else {
                    $insertSensor->execute([$name,$type,$unit]);
                    $sensorIds[$type] = (int)$pdo->lastInsertId();
                }
            }
            $pdo->exec(str_replace('`sensor_readings`', '`sensor_readings_v2`', $schema['sensor_readings']));
            // Prevent writers from changing the source while copying and swapping it.
            $pdo->exec('LOCK TABLES sensor_readings WRITE, sensor_readings_v2 WRITE, sensors READ');
            try {
                $count = (int)$pdo->query('SELECT COUNT(*) FROM sensor_readings')->fetchColumn();
                $offset = (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM sensor_readings')->fetchColumn();
                if ($offset > 1073741823) throw new RuntimeException('ID lama terlalu besar untuk pemetaan INT.');
                $copyPh = $pdo->prepare('INSERT INTO sensor_readings_v2 (id,sensor_id,value,recorded_at) SELECT id,?,ph_value,recorded_at FROM sensor_readings');
                $copyPh->execute([$sensorIds['ph']]);
                $copyPpm = $pdo->prepare('INSERT INTO sensor_readings_v2 (id,sensor_id,value,recorded_at) SELECT id+?, ?, ppm_value,recorded_at FROM sensor_readings');
                $copyPpm->execute([$offset,$sensorIds['ppm']]);
                if ((int)$pdo->query('SELECT COUNT(*) FROM sensor_readings_v2')->fetchColumn() !== $count*2) throw new RuntimeException('Jumlah hasil konversi tidak sesuai.');
                $pdo->exec('RENAME TABLE sensor_readings TO sensor_readings_legacy, sensor_readings_v2 TO sensor_readings');
            } finally {
                $pdo->exec('UNLOCK TABLES');
            }
            $verify = $pdo->prepare('SELECT COUNT(*) FROM sensor_readings_legacy old
                LEFT JOIN sensor_readings ph ON ph.id=old.id
                LEFT JOIN sensor_readings ppm ON ppm.id=old.id+?
                WHERE ph.id IS NULL OR ppm.id IS NULL OR ph.sensor_id<>? OR ppm.sensor_id<>?
                OR ph.value<>old.ph_value OR ppm.value<>old.ppm_value
                OR ph.recorded_at<>old.recorded_at OR ppm.recorded_at<>old.recorded_at');
            $verify->execute([$offset,$sensorIds['ph'],$sensorIds['ppm']]);
            if ($verify->fetchColumn()) throw new RuntimeException('Verifikasi nilai/waktu gagal; tabel lama tetap disimpan.');
            // Save the exact locked source too, including rows arriving after the first snapshot.
            $legacyBackup = $backupDirectory . '/' . $db . '_legacy_' . $stamp . '.sql';
            sensorBackup($pdo, $legacyBackup, ['sensor_readings_legacy']);
            $result['backups'][] = $legacyBackup;
            $pdo->exec('DROP TABLE sensor_readings_legacy');
            $result['converted_rows'] = $count;
        }
        foreach (['settings'=>'updated_by','profile'=>'updated_by','monitoring_logs'=>'sensor_reading_id','notifications'=>'sensor_reading_id'] as $table=>$column) {
            if (!sensorColumnExists($pdo,$table,$column)) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` INT NULL");
        }
        $fks = [
            ['settings','updated_by','users','id','fk_settings_user','SET NULL'],
            ['profile','updated_by','users','id','fk_profile_user','SET NULL'],
            ['sensor_readings','sensor_id','sensors','id','fk_reading_sensor','RESTRICT'],
            ['monitoring_logs','type','settings','type','fk_monitoring_type','RESTRICT'],
            ['monitoring_logs','sensor_reading_id','sensor_readings','id','fk_monitoring_reading','RESTRICT'],
            ['history_logs','type','settings','type','fk_history_type','RESTRICT'],
            ['notifications','sensor_reading_id','sensor_readings','id','fk_notifications_reading','RESTRICT'],
        ];
        $find = $pdo->prepare('SELECT REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL');
        foreach ($fks as [$table,$column,$parent,$key,$name,$onDelete]) {
            $find->execute([$table,$column]);
            $ref=$find->fetch(PDO::FETCH_NUM);
            if ($ref && $ref !== [$parent,$key]) throw new RuntimeException("FK $table.$column memiliki target berbeda.");
            if (!$ref) $pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` FOREIGN KEY (`$column`) REFERENCES `$parent` (`$key`) ON DELETE $onDelete ON UPDATE RESTRICT");
        }
        $result['tables'] = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $result['readings'] = (int)$pdo->query('SELECT COUNT(*) FROM sensor_readings')->fetchColumn();
        return $result;
    } finally {
        $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Migrasi hanya tersedia melalui CLI.'); }
    try {
        $result = migrateSensors(getDBConnection(), __DIR__ . '/backups');
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, 'Migrasi gagal: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
