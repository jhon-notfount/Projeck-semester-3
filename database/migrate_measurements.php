<?php
/** Migrasi 8 ke 9 tabel. Jalankan melalui CLI saat dashboard tidak digunakan. */
require_once __DIR__ . '/migrate_sensors.php';
require_once __DIR__ . '/../includes/dithane.php';

function migrateMeasurements(PDO $pdo, string $backupDirectory): array {
    $lock = measurementLock($pdo);
    try {
        if (!sensorTableExists($pdo, 'sensor_readings')) {
            foreach (['ph_readings','ppm_readings','dithane_schedules'] as $table) {
                if (!sensorTableExists($pdo,$table)) throw new RuntimeException('Struktur belum lengkap. Gunakan installer untuk database kosong.');
            }
            if (sensorTableExists($pdo,'sensors')) throw new RuntimeException('Migrasi sebelumnya belum selesai; tinjau tabel sensors dan cadangan.');
            return ['status'=>'already_current','tables'=>9];
        }
        if (!sensorColumnExists($pdo,'sensor_readings','sensor_id')) throw new RuntimeException('Database 7 tabel: jalankan migrate_sensors.php terlebih dahulu.');
        foreach (['ph_readings','ppm_readings','dithane_schedules'] as $table) {
            if (sensorTableExists($pdo,$table)) throw new RuntimeException('Tabel tujuan sudah ada. Tinjau atau pulihkan cadangan migrasi sebelumnya sebelum mencoba lagi.');
        }
        $bad = $pdo->query("SELECT COUNT(*) FROM sensor_readings r LEFT JOIN sensors s ON s.id=r.sensor_id WHERE s.id IS NULL OR s.type NOT IN ('ph','ppm') OR r.recorded_at IS NULL OR r.value < 0 OR (s.type='ph' AND r.value>14) OR (s.type='ppm' AND (r.value<>TRUNCATE(r.value,0) OR r.value>2147483647))")->fetchColumn();
        if ($bad) throw new RuntimeException('Pembacaan lama tidak valid. Migrasi dihentikan tanpa menghapus data.');
        $bad = $pdo->query('SELECT COUNT(*) FROM monitoring_logs l JOIN sensor_readings r ON r.id=l.sensor_reading_id JOIN sensors s ON s.id=r.sensor_id WHERE BINARY l.type<>BINARY s.type')->fetchColumn();
        if ($bad) throw new RuntimeException('Kategori monitoring tidak sesuai sumber pembacaan.');
        $oldSchedule = $pdo->query("SELECT * FROM settings WHERE type='dithane'")->fetch(PDO::FETCH_ASSOC);
        if (!$oldSchedule) throw new RuntimeException('Pengaturan Dithane belum tersedia.');
        $config = json_decode($oldSchedule['config_json'],true,512,JSON_THROW_ON_ERROR);
        $schedule = normalizeDithane($config);
        if (array_diff(array_keys($config), ['interval','duration','start'])) throw new RuntimeException('Ada konfigurasi tambahan Dithane; petakan kolomnya terlebih dahulu.');
        // Reject external references that would otherwise be lost during the replacement.
        $refs = $pdo->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IN ('sensors','sensor_readings')")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($refs as $ref) {
            if (!in_array([$ref['TABLE_NAME'],$ref['COLUMN_NAME']], [['sensor_readings','sensor_id'],['monitoring_logs','sensor_reading_id'],['notifications','sensor_reading_id']], true)) throw new RuntimeException('Ada relasi tambahan yang perlu dipetakan.');
        }
        if (!is_dir($backupDirectory) && !mkdir($backupDirectory,0700,true)) throw new RuntimeException('Folder backup gagal dibuat.');
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $backup = $backupDirectory . '/' . $db . '_before_split_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.sql';
        sensorBackup($pdo,$backup);
        $sql = preg_replace('/--[^\r\n]*/','',file_get_contents(__DIR__.'/schema.sql'));
        $schema = [];
        foreach (array_filter(array_map('trim',explode(';',$sql))) as $stmt) {
            if (preg_match('/CREATE TABLE IF NOT EXISTS `(\w+)`/',$stmt,$m)) $schema[$m[1]]=$stmt;
        }
        foreach (['ph_readings','ppm_readings','dithane_schedules'] as $table) $pdo->exec($schema[$table]);
        $counts = [];
        $pdo->beginTransaction();
        try {
            foreach (['ph','ppm'] as $type) {
                $pdo->exec("INSERT INTO {$type}_readings (id,{$type}_value,recorded_at) SELECT r.id,r.value,r.recorded_at FROM sensor_readings r JOIN sensors s ON s.id=r.sensor_id WHERE s.type='$type'");
                $bad = $pdo->query("SELECT COUNT(*) FROM sensor_readings r JOIN sensors s ON s.id=r.sensor_id LEFT JOIN {$type}_readings n ON n.id=r.id WHERE s.type='$type' AND (n.id IS NULL OR n.{$type}_value<>r.value OR n.recorded_at<>r.recorded_at)")->fetchColumn();
                if ($bad) throw new RuntimeException('Verifikasi pembacaan gagal.');
                $counts[$type] = (int)$pdo->query("SELECT COUNT(*) FROM {$type}_readings")->fetchColumn();
            }
            $q=$pdo->prepare('INSERT INTO dithane_schedules (id,interval_hours,duration_seconds,start_time,updated_by,created_at,updated_at) VALUES (1,?,?,?,?,COALESCE(?,CURRENT_TIMESTAMP),COALESCE(?,CURRENT_TIMESTAMP))');
            $q->execute([$schedule['interval_hours'],$schedule['duration_seconds'],$schedule['start_time'],$oldSchedule['updated_by'],$oldSchedule['updated_at'],$oldSchedule['updated_at']]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        if (array_sum($counts)!==(int)$pdo->query('SELECT COUNT(*) FROM sensor_readings')->fetchColumn()) throw new RuntimeException('Jumlah pembacaan berbeda.');
        foreach (['monitoring_logs'=>'monitoring','notifications'=>'notifications'] as $table=>$name) {
            $pdo->exec("ALTER TABLE $table ADD ph_reading_id INT NULL, ADD ppm_reading_id INT NULL");
            $pdo->exec("UPDATE $table l JOIN sensor_readings r ON r.id=l.sensor_reading_id JOIN sensors s ON s.id=r.sensor_id SET l.ph_reading_id=IF(s.type='ph',r.id,NULL), l.ppm_reading_id=IF(s.type='ppm',r.id,NULL)");
            $bad=$pdo->query("SELECT COUNT(*) FROM $table WHERE sensor_reading_id IS NOT NULL AND COALESCE(ph_reading_id,ppm_reading_id,0)<>sensor_reading_id")->fetchColumn();
            if ($bad) throw new RuntimeException('Referensi hasil migrasi tidak sesuai.');
            foreach (['ph','ppm'] as $type) {
                $pdo->exec("ALTER TABLE $table ADD INDEX idx_{$name}_{$type} ({$type}_reading_id), ADD CONSTRAINT fk_{$name}_{$type} FOREIGN KEY ({$type}_reading_id) REFERENCES {$type}_readings (id) ON DELETE RESTRICT ON UPDATE RESTRICT");
            }
            $check=$name==='monitoring' ? "(ph_reading_id IS NULL OR (type='ph' AND ppm_reading_id IS NULL)) AND (ppm_reading_id IS NULL OR (type='ppm' AND ph_reading_id IS NULL))" : 'ph_reading_id IS NULL OR ppm_reading_id IS NULL';
            $pdo->exec("ALTER TABLE $table ADD CONSTRAINT chk_{$name}_one_reading CHECK ($check)");
            $q=$pdo->prepare('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL');
            $q->execute([$table,'sensor_reading_id']);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $fk) $pdo->exec("ALTER TABLE $table DROP FOREIGN KEY `$fk`");
            $pdo->exec("ALTER TABLE $table DROP COLUMN sensor_reading_id");
        }
        // Retain the settings category for existing log FKs; schedule values live only in the new table.
        $pdo->exec("UPDATE settings SET config_json=JSON_OBJECT(),updated_at=updated_at WHERE type='dithane'");
        $pdo->exec('DROP TABLE sensor_readings');
        $pdo->exec('DROP TABLE sensors');
        return ['status'=>'migrated','backup'=>$backup,'readings'=>$counts,'tables'=>9];
    } finally { measurementUnlock($pdo,$lock); }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
    try { echo json_encode(migrateMeasurements(getDBConnection(),__DIR__.'/backups'),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL; }
    catch (Throwable $e) { fwrite(STDERR,'Migrasi gagal: '.$e->getMessage().PHP_EOL); exit(1); }
}
