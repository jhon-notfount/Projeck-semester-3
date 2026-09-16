<?php
require_once __DIR__ . '/../database/migrate_sensors.php';
require_once __DIR__ . '/../includes/sensors.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejected(callable $fn, string $message): void {
    try { $fn(); } catch (Throwable $e) { return; }
    throw new RuntimeException($message);
}
function seedSettings(PDO $pdo): void {
    $stmt=$pdo->prepare('INSERT INTO settings (type,config_json) VALUES (?,?)');
    foreach (['ph'=>['min'=>'5,5','max'=>'6,5'],'ppm'=>['min'=>'800','max'=>'1000'],'dithane'=>['interval'=>'48','duration'=>'10','start'=>'08.00']] as $type=>$config) $stmt->execute([$type,json_encode($config)]);
}

$server=new PDO("mysql:host=$DB_HOST;charset=utf8mb4",$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$name='hydrotech_sensor_test_'.bin2hex(random_bytes(5));
check((bool)preg_match('/^hydrotech_sensor_test_[a-f0-9]{10}$/',$name),'Unsafe test database name');
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $pdo=new PDO("mysql:host=$DB_HOST;dbname=$name;charset=utf8mb4",$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $legacy=preg_replace('/--[^\r\n]*/','',file_get_contents(__DIR__.'/fixtures/schema_legacy.sql'));
    foreach(array_filter(array_map('trim',explode(';',$legacy))) as $sql) $pdo->exec($sql);
    $pdo->exec("INSERT INTO users (username,password) VALUES ('preserved','existing-hash')");
    seedSettings($pdo);
    $pdo->exec("INSERT INTO sensor_readings (id,ph_value,ppm_value,recorded_at) VALUES (3,6.25,900,'2026-09-15 10:00:00'),(7,7.10,1200,'2026-09-15 10:00:00')");
    $pdo->exec("INSERT INTO monitoring_logs (type,day_name,log_time,value,status) VALUES ('ph','Selasa','10.00','6.25','Normal')");
    $pdo->exec("INSERT INTO history_logs (type,day_name,log_date,log_time,status) VALUES ('ph','Selasa','2026-09-15','10.00','Normal')");
    $pdo->exec("INSERT INTO profile (section,label,value) VALUES ('owner','Nama','Tetap')");
    $pdo->exec("INSERT INTO notifications (label,message) VALUES ('Lama','Tetap')");
    $result=migrateSensors($pdo,__DIR__.'/../database/backups');
    check($result['converted_rows']===2 && $result['readings']===4,'Old readings were not split exactly');
    check(count($result['tables'])===8,'Expected eight tables');
    check($pdo->query('SELECT password FROM users LIMIT 1')->fetchColumn()==='existing-hash','Migration reset account');
    check($pdo->query('SELECT value FROM profile LIMIT 1')->fetchColumn()==='Tetap','Migration reset profile');
    check((int)$pdo->query('SELECT COUNT(*) FROM history_logs')->fetchColumn()===1,'Migration reset history');
    check((float)$pdo->query('SELECT value FROM sensor_readings WHERE id=14')->fetchColumn()===1200.0,'Sparse ID PPM mapping failed');
    check($pdo->query('SELECT recorded_at FROM sensor_readings WHERE id=7')->fetchColumn()==='2026-09-15 10:00:00','Timestamp changed');
    check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()")->fetchColumn()===7,'Missing foreign keys');
    foreach($result['backups'] as $backup) check(is_file($backup) && filesize($backup)>100,'Missing backup');
    $again=migrateSensors($pdo,__DIR__.'/../database/backups');
    check($again['converted_rows']===0 && $again['readings']===4,'Repeated migration duplicated data');
    $sensors=$pdo->query('SELECT type,id FROM sensors')->fetchAll(PDO::FETCH_KEY_PAIR);
    $rows=saveSensorMeasurements($pdo,['ph_value'=>'6.2','ppm_value'=>'950']);
    check(count($rows)===2 && $rows[0]['recorded_at']===$rows[1]['recorded_at'],'Paired readings failed');
    check((int)$pdo->query('SELECT COUNT(*) FROM monitoring_logs WHERE sensor_reading_id IS NOT NULL')->fetchColumn()===2,'Linked monitoring missing');
    $before=(int)$pdo->query('SELECT COUNT(*) FROM sensor_readings')->fetchColumn();
    rejected(fn()=>saveSensorMeasurements($pdo,['ph_value'=>6,'ppm_value'=>'invalid']),'Invalid batch accepted');
    rejected(fn()=>saveSensorMeasurements($pdo,['sensor_id'=>$sensors['ph'],'value'=>15]),'Invalid pH accepted');
    rejected(fn()=>saveSensorMeasurements($pdo,['sensor_id'=>$sensors['ph'],'value'=>['bad']]),'Array accepted');
    rejected(fn()=>saveSensorMeasurements($pdo,['ph_sensor_id'=>$sensors['ppm'],'ph_value'=>6,'ppm_value'=>900]),'Wrong type accepted');
    check((int)$pdo->query('SELECT COUNT(*) FROM sensor_readings')->fetchColumn()===$before,'Rejected input left partial writes');
    $pdo->exec("UPDATE sensors SET status='rusak' WHERE type='ph'");
    rejected(fn()=>saveSensorMeasurements($pdo,['sensor_id'=>$sensors['ph'],'value'=>6]),'Broken sensor accepted');
    $pdo->exec("UPDATE sensors SET status='aktif' WHERE type='ph'");
    $pdo->exec("INSERT INTO sensors (name,type,unit) VALUES ('Extra','ph','pH')");
    rejected(fn()=>saveSensorMeasurements($pdo,['ph_value'=>6,'ppm_value'=>900]),'Ambiguous sensor chosen automatically');
    $alert=saveSensorMeasurements($pdo,['sensor_id'=>$sensors['ph'],'value'=>4.2])[0];
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM notifications WHERE sensor_reading_id=? AND tone=\'warning\'');
    $stmt->execute([$alert['id']]);
    check((int)$stmt->fetchColumn()===1,'Warning is not linked to reading');
    rejected(fn()=>$pdo->exec('DELETE FROM sensors WHERE id='.(int)$sensors['ph']),'Referenced device could be deleted');
    rejected(fn()=>$pdo->exec("INSERT INTO sensors (name,type,unit) VALUES ('Bad','ph','ppm')"),'Inconsistent unit accepted');
    $latest=latestSensorReadings($pdo);
    check(count($latest)===2,'Expected latest reading per device with data');
    // Verify all-or-nothing behavior when a later database write fails.
    $pdo->exec("DELETE FROM monitoring_logs WHERE type='ppm'");
    $pdo->exec("DELETE FROM settings WHERE type='ppm'");
    $before=(int)$pdo->query('SELECT COUNT(*) FROM sensor_readings')->fetchColumn();
    rejected(fn()=>saveSensorMeasurements($pdo,['ph_sensor_id'=>$sensors['ph'],'ppm_sensor_id'=>$sensors['ppm'],'ph_value'=>6,'ppm_value'=>900]),'Missing setting FK should fail');
    check((int)$pdo->query('SELECT COUNT(*) FROM sensor_readings')->fetchColumn()===$before,'Database failure left partial batch');
    echo "PASS: migration, backups, repeat migration, data preservation, FK, batch rollback, input validation, inactive/ambiguous sensors, linked monitoring/alerts, latest readings.\n";
} finally {
    $server->exec("DROP DATABASE `$name`");
}
