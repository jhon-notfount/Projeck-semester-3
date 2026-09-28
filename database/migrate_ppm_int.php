<?php
require_once __DIR__.'/migrate_sensors.php';
require_once __DIR__.'/../includes/sensors.php';
function migratePpmInt(PDO $pdo, string $backupDirectory): array {
    $lock=measurementLock($pdo);
    try {
        $col=$pdo->query("SHOW COLUMNS FROM ppm_readings LIKE 'ppm_value'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) throw new RuntimeException('Kolom ppm_value tidak ditemukan.');
        if (strtolower($col['Type'])==='int') return ['status'=>'already_current'];
        if (!str_starts_with(strtolower($col['Type']),'decimal(')) throw new RuntimeException('Tipe PPM lama tidak dikenali.');
        $bad=$pdo->query('SELECT COUNT(*) FROM ppm_readings WHERE ppm_value<>TRUNCATE(ppm_value,0) OR ppm_value<0 OR ppm_value>2147483647')->fetchColumn();
        if ($bad) throw new RuntimeException('PPM masih memiliki pecahan atau di luar rentang INT. Tidak ada data yang diubah.');
        if (!is_dir($backupDirectory) && !mkdir($backupDirectory,0700,true)) throw new RuntimeException('Folder cadangan gagal dibuat.');
        $db=$pdo->query('SELECT DATABASE()')->fetchColumn();
        $backup=$backupDirectory.'/'.$db.'_before_ppm_int_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.sql';
        sensorBackup($pdo,$backup);
        $before=$pdo->query('SELECT id,CAST(ppm_value AS SIGNED) AS ppm_value,recorded_at FROM ppm_readings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $pdo->exec("ALTER TABLE ppm_readings DROP CHECK chk_ppm_value, MODIFY ppm_value INT NOT NULL COMMENT 'Hasil pengukuran: konsentrasi dalam ppm', ADD CONSTRAINT chk_ppm_value CHECK (ppm_value BETWEEN 0 AND 2147483647)");
        $after=$pdo->query('SELECT id,CAST(ppm_value AS SIGNED) AS ppm_value,recorded_at FROM ppm_readings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        if ($before!==$after) throw new RuntimeException('Verifikasi data gagal; tinjau backup.');
        return ['status'=>'migrated','database'=>$db,'rows'=>count($after),'backup'=>$backup];
    } finally { measurementUnlock($pdo,$lock); }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI!=='cli') {http_response_code(403);exit('CLI only');}
    try {echo json_encode(migratePpmInt(getDBConnection(),__DIR__.'/backups'),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;}
    catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
}
