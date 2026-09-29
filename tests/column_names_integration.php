<?php
require_once __DIR__.'/../database/migrate_column_names.php';
require_once __DIR__.'/../includes/dithane.php';
function ensure($ok,$message) {if (!$ok) throw new RuntimeException($message);}
$p=new PDO("mysql:host=$DB_HOST;charset=utf8mb4",$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='hydrotech_names_test_'.bin2hex(random_bytes(5));
$p->exec("CREATE DATABASE `$name`");$p->exec("USE `$name`");
try {
    $sql=preg_replace('/--[^\r\n]*/','',file_get_contents(__DIR__.'/fixtures/schema_before_indonesian.sql'));
    foreach(array_filter(array_map('trim',explode(';',$sql))) as $q) $p->exec($q);
    $p->exec("INSERT INTO users (id,username,password) VALUES (1,'tetap','hash-tetap')");
    $q=$p->prepare('INSERT INTO settings (type,config_json,updated_by) VALUES (?,?,1)');
    foreach(['ph'=>['min'=>5.5,'max'=>6.5],'ppm'=>['min'=>800,'max'=>1000],'dithane'=>(object)[]] as $type=>$config) $q->execute([$type,json_encode($config)]);
    $p->exec("INSERT INTO profile (section,label,value,updated_by) VALUES ('owner','Nama','Tetap',1)");
    $p->exec("INSERT INTO ph_readings (id,ph_value,recorded_at) VALUES (10,6.2512,'2026-09-28 08:00:00')");
    $p->exec("INSERT INTO ppm_readings (id,ppm_value,recorded_at) VALUES (20,850,'2026-09-28 08:00:00')");
    $p->exec("INSERT INTO dithane_schedules (id,interval_hours,duration_seconds,start_time,updated_by) VALUES (1,48,10,'08:00:00',1)");
    $p->exec("INSERT INTO monitoring_logs (type,day_name,log_time,value,status,ph_reading_id) VALUES ('ph','Senin','08.00','6.2512','Normal',10)");
    $p->exec("INSERT INTO notifications (label,message,ppm_reading_id) VALUES ('PPM','Tetap',20)");
    $p->exec("INSERT INTO history_logs (type,day_name,log_date,log_time,status) VALUES ('ppm','Senin','2026-09-28','08.00','Normal')");
    $r=migrateColumnNames($p,__DIR__.'/../database/backups');
    ensure($r['data_verified'] && $r['foreign_keys']===9,'Migration verification');
    ensure(is_file($r['backup']),'Backup missing');
    ensure($p->query('SELECT kata_sandi FROM users WHERE id_pengguna=1')->fetchColumn()==='hash-tetap','Account changed');
    ensure($p->query('SELECT nilai_ph FROM ph_readings WHERE id_pembacaan_ph=10')->fetchColumn()==='6.2512','Precision changed');
    ensure((int)$p->query('SELECT id_pembacaan_ppm FROM notifications')->fetchColumn()===20,'FK mapping changed');
    ensure(migrateColumnNames($p,__DIR__.'/../database/backups')['status']==='already_current','Repeat migration');
    $readings=saveSensorMeasurements($p,['ph_value'=>7.2,'ppm_value'=>1200]);
    ensure(count($readings)===2,'Measurement save');
    ensure((int)$p->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===3,'Warning creation');
    writeDithane($p,['interval'=>24,'duration'=>15,'start'=>'09:30'],1);
    ensure(readDithane($p)['config']['start']==='09.30','Schedule editing');
    ensure(count(latestSensorReadings($p))===2,'Latest readings');
    foreach (['INSERT INTO ph_readings (nilai_ph) VALUES (15)', 'UPDATE notifications SET id_pembacaan_ph=10,id_pembacaan_ppm=20 WHERE id_notifikasi=1', 'DELETE FROM ppm_readings WHERE id_pembacaan_ppm=20'] as $invalid) {
        $rejected=false; try {$p->exec($invalid);}catch(PDOException $e){$rejected=true;} ensure($rejected,'Constraint not enforced');
    }
    echo "PASS: rename all columns, preserve every row, FK/CHECK enforcement, backup, idempotence, measurement and schedule services.\n";
} finally {$p->exec("DROP DATABASE `$name`");}
