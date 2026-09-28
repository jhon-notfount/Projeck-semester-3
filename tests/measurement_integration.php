<?php
require_once __DIR__.'/../database/migrate_measurements.php';
function check($value,$message) { if (!$value) throw new RuntimeException($message); }
function rejects(callable $fn) { try {$fn();} catch(Throwable $e) {return;} throw new RuntimeException('Expected rejection'); }
$p=new PDO("mysql:host=$DB_HOST;charset=utf8mb4",$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='hydrotech_split_test_'.bin2hex(random_bytes(5));
$p->exec("CREATE DATABASE `$name`"); $p->exec("USE `$name`");
try {
    $schema=preg_replace('/--[^\r\n]*/','',file_get_contents(__DIR__.'/fixtures/schema_sensors.sql'));
    foreach(array_filter(array_map('trim',explode(';',$schema))) as $sql) $p->exec($sql);
    $p->exec("INSERT INTO users (id,username,password) VALUES (1,'preserved','hash-unchanged')");
    $q=$p->prepare('INSERT INTO settings (type,config_json,updated_by) VALUES (?,?,1)');
    foreach(['ph'=>['min'=>'5,5','max'=>'6,5'],'ppm'=>['min'=>'800','max'=>'1000'],'dithane'=>['interval'=>'48','duration'=>'10','start'=>'08.00']] as $type=>$cfg) $q->execute([$type,json_encode($cfg)]);
    $p->exec("INSERT INTO profile (section,label,value,updated_by) VALUES ('owner','Nama','Tetap',1)");
    $p->exec("INSERT INTO sensors (id,name,type,unit) VALUES (1,'pH','ph','pH'),(2,'PPM','ppm','ppm')");
    $p->exec("INSERT INTO sensor_readings (id,sensor_id,value,recorded_at) VALUES (3,1,6.2512,'2026-09-15 10:00:00'),(7,2,850,'2026-09-15 10:00:00')");
    $p->exec("INSERT INTO monitoring_logs (type,day_name,log_time,value,status,sensor_reading_id) VALUES ('ph','Selasa','10.00','6.2512','Normal',3),('ppm','Selasa','10.00','850','Normal',7),('dithane','Selasa','08.00','0.5 l','Selesai',NULL)");
    $p->exec("INSERT INTO notifications (label,message,sensor_reading_id) VALUES ('ph','tetap',3),('ppm','tetap',7),('umum','tetap',NULL)");
    $p->exec("INSERT INTO history_logs (day_name,log_date,log_time,type,status) VALUES ('Selasa','2026-09-15','08.00','dithane','Selesai')");
    $p->exec("ALTER TABLE sensors MODIFY type ENUM('ph','ppm') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL");
    $result=migrateMeasurements($p,__DIR__.'/../database/backups');
    check($result['readings']===['ph'=>1,'ppm'=>1],'Counts');
    check(is_file($result['backup']),'Backup');
    check(count($p->query('SHOW TABLES')->fetchAll())===9,'Nine tables');
    check($p->query('SELECT ph_value FROM ph_readings WHERE id=3')->fetchColumn()==='6.2512','Precision preserved');
    check($p->query('SELECT recorded_at FROM ppm_readings WHERE id=7')->fetchColumn()==='2026-09-15 10:00:00','Time preserved');
    check((int)$p->query("SELECT ppm_reading_id FROM notifications WHERE label='ppm'")->fetchColumn()===7,'Notification FK mapping');
    check((int)$p->query("SELECT ph_reading_id FROM monitoring_logs WHERE type='ph'")->fetchColumn()===3,'Monitoring FK mapping');
    check($p->query('SELECT password FROM users')->fetchColumn()==='hash-unchanged','User preserved');
    check($p->query('SELECT value FROM profile')->fetchColumn()==='Tetap','Profile preserved');
    check((int)$p->query('SELECT COUNT(*) FROM history_logs')->fetchColumn()===1,'History preserved');
    check(readDithane($p)['config']===['interval'=>'48','duration'=>'10','start'=>'08.00'],'Schedule converted');
    check(migrateMeasurements($p,__DIR__.'/../database/backups')['status']==='already_current','Idempotent');
    $readings=saveSensorMeasurements($p,['ph_value'=>7.1,'ppm_value'=>1200]);
    check(count($readings)===2,'Pair saved');
    check((int)$p->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===5,'Warnings generated');
    $before=$p->query('SELECT COUNT(*) FROM ph_readings')->fetchColumn();
    foreach([['ph_value'=>6,'ppm_value'=>850.5],['ppm_value'=>2147483648],['ph_value'=>6,'ppm_value'=>-1],['ph_value'=>15],['ph_value'=>null],['ph_value'=>[]],['sensor_id'=>1,'value'=>6]] as $bad) rejects(fn()=>saveSensorMeasurements($p,$bad));
    check($p->query('SELECT COUNT(*) FROM ph_readings')->fetchColumn()===$before,'Rejected pair leaves no writes');
    rejects(fn()=>$p->exec('DELETE FROM ph_readings WHERE id=3'));
    rejects(fn()=>$p->exec('UPDATE notifications SET ph_reading_id=3,ppm_reading_id=7 WHERE id=1'));
    rejects(fn()=>$p->exec("UPDATE monitoring_logs SET type='ppm' WHERE ph_reading_id=3"));
    rejects(fn()=>$p->exec('INSERT INTO ph_readings (ph_value) VALUES (15)'));
    writeDithane($p,['interval'=>'24','duration'=>'15','start'=>'09:30'],1);
    check(readDithane($p)['config']['start']==='09.30','Schedule edit');
    rejects(fn()=>writeDithane($p,['interval'=>0,'duration'=>10,'start'=>'25:00'],1));
    check(readDithane($p)['config']['interval']==='24','Invalid schedule unchanged');
    check(count(latestSensorReadings($p))===2,'Latest per type');
    // Force second insert to fail and check that the first insert is rolled back.
    $p->exec("CREATE TRIGGER fail_ppm BEFORE INSERT ON ppm_readings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test rollback'");
    $before=$p->query('SELECT COUNT(*) FROM ph_readings')->fetchColumn();
    rejects(fn()=>saveSensorMeasurements($p,['ph_value'=>6.2,'ppm_value'=>900]));
    check($p->query('SELECT COUNT(*) FROM ph_readings')->fetchColumn()===$before,'Rollback failed');
    echo "PASS: migration, precision, timestamps, all relationships, repeat migration, payload validation, warnings, schedule, rollback.\n";
} finally { $p->exec("DROP DATABASE `$name`"); }
