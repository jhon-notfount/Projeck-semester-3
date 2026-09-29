<?php
require_once __DIR__.'/../database/migrate_ppm_int.php';
$p=new PDO("mysql:host=$DB_HOST;charset=utf8mb4",$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='hydrotech_ppm_int_test_'.bin2hex(random_bytes(5));
$p->exec("CREATE DATABASE `$name`");$p->exec("USE `$name`");
try {
    $sql=file_get_contents(__DIR__.'/fixtures/schema_before_indonesian.sql');
    $sql=str_replace('`ppm_value` INT','`ppm_value` DECIMAL(12,4)',$sql);
    foreach(array_filter(array_map('trim',explode(';',preg_replace('/--[^\r\n]*/','',$sql)))) as $q) $p->exec($q);
    $p->exec('INSERT INTO ppm_readings (ppm_value) VALUES (850.5)');
    $rejected=false;
    try {migratePpmInt($p,__DIR__.'/../database/backups');} catch(RuntimeException $e) {$rejected=true;}
    if (!$rejected || $p->query('SELECT ppm_value FROM ppm_readings')->fetchColumn()!=='850.5000') throw new RuntimeException('Fractional data not protected');
    $p->exec('UPDATE ppm_readings SET ppm_value=850');
    $p->exec("INSERT INTO notifications (label,message,ppm_reading_id) VALUES ('Test','Tetap',1)");
    $result=migratePpmInt($p,__DIR__.'/../database/backups');
    if ($result['rows']!==1 || !is_file($result['backup'])) throw new RuntimeException('Migration failed');
    if ((int)$p->query('SELECT ppm_reading_id FROM notifications')->fetchColumn()!==1) throw new RuntimeException('Relation changed');
    if ($p->query("SHOW COLUMNS FROM ppm_readings LIKE 'ppm_value'")->fetch(PDO::FETCH_ASSOC)['Type']!=='int') throw new RuntimeException('Expected INT');
    if (migratePpmInt($p,__DIR__.'/../database/backups')['status']!=='already_current') throw new RuntimeException('Not idempotent');
    echo "PASS: PPM INT migration, backup, fractional rejection, relation preservation, repeat execution.\n";
} finally {$p->exec("DROP DATABASE `$name`");}
