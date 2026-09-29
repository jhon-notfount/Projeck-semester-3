<?php
/** Rename physical columns without changing values, types, or relationship targets. */
require_once __DIR__ . '/migrate_sensors.php';
require_once __DIR__ . '/../includes/sensors.php';

function columnNameMap(): array {
    return json_decode(file_get_contents(__DIR__ . '/column_names.json'), true, 512, JSON_THROW_ON_ERROR);
}
function renameRelations(PDO $pdo): array {
    return $pdo->query('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL')->fetchAll(PDO::FETCH_NUM);
}
function migrateColumnNames(PDO $pdo, string $backupDirectory): array {
    $lock = measurementLock($pdo);
    try {
        $mapping = columnNameMap();
        $plans = []; $hashes = [];
        foreach ($mapping as $table => $columns) {
            $actual = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($columns as $old => $new) {
                if ($old === $new) {
                    if (!in_array($old,$actual,true)) throw new RuntimeException("Kolom $table.$old tidak ditemukan.");
                    continue;
                }
                $hasOld = in_array($old,$actual,true); $hasNew = in_array($new,$actual,true);
                if ($hasOld === $hasNew) throw new RuntimeException("Nama kolom $table.$old / $new tidak sesuai; migrasi dihentikan.");
                if ($hasOld) $plans[$table][$old] = $new;
            }
            $pk = in_array('id',$actual,true) ? 'id' : $columns['id'];
            $hashes[$table] = hash('sha256',json_encode($pdo->query("SELECT * FROM `$table` ORDER BY `$pk`")->fetchAll(PDO::FETCH_NUM)));
        }
        if (!$plans) return ['status'=>'already_current','tables'=>count($mapping)];
        if (!is_dir($backupDirectory) && !mkdir($backupDirectory,0700,true)) throw new RuntimeException('Folder cadangan gagal dibuat.');
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $backup = $backupDirectory . '/' . $db . '_before_column_names_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.sql';
        sensorBackup($pdo,$backup);
        $expected = renameRelations($pdo);
        foreach ($expected as &$r) {
            $r[1] = $mapping[$r[0]][$r[1]] ?? $r[1];
            $r[3] = $mapping[$r[2]][$r[3]] ?? $r[3];
        }
        unset($r);
        $statements = [];
        foreach ($plans as $table => $columns) {
            $q = $pdo->prepare('SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA=DATABASE() AND tc.TABLE_NAME=? AND tc.CONSTRAINT_TYPE=\'CHECK\'');
            $q->execute([$table]); $checks = $q->fetchAll(PDO::FETCH_ASSOC);
            $dropped = []; $renamed = false;
            try {
                foreach ($checks as $check) {
                    $sql = "ALTER TABLE `$table` DROP CHECK `" . $check['CONSTRAINT_NAME'] . '`';
                    $pdo->exec($sql); $statements[] = $sql . ';'; $dropped[] = $check;
                }
                $alter=[];
                foreach ($columns as $old=>$new) $alter[]="RENAME COLUMN `$old` TO `$new`";
                $sql="ALTER TABLE `$table` " . implode(', ',$alter) . ', ALGORITHM=INPLACE';
                $pdo->exec($sql); $statements[]=$sql.';'; $renamed=true;
            } finally {
                // Restore checks against whichever column names currently exist.
                foreach ($dropped as $check) {
                    $clause=stripslashes($check['CHECK_CLAUSE']);
                    if ($renamed) $clause=preg_replace_callback('/`(\w+)`/',fn($m)=>'`'.($columns[$m[1]]??$m[1]).'`',$clause);
                    $sql="ALTER TABLE `$table` ADD CONSTRAINT `".$check['CONSTRAINT_NAME'].'` CHECK ('.$clause.')';
                    $pdo->exec($sql); $statements[]=$sql.';';
                }
            }
        }
        foreach ($mapping as $table => $columns) {
            $pk = $columns['id'];
            $after = hash('sha256',json_encode($pdo->query("SELECT * FROM `$table` ORDER BY `$pk`")->fetchAll(PDO::FETCH_NUM)));
            if ($hashes[$table] !== $after) throw new RuntimeException("Verifikasi isi tabel $table gagal. Tinjau cadangan.");
        }
        $actual = renameRelations($pdo); sort($expected); sort($actual);
        if ($expected !== $actual) throw new RuntimeException('Verifikasi relasi gagal.');
        file_put_contents($backup . '.changes.sql', "-- Perubahan penamaan untuk database yang sudah dicadangkan.\n" . implode("\n\n",$statements));
        return ['status'=>'migrated','database'=>$db,'backup'=>$backup,'renamed_columns'=>array_sum(array_map('count',$plans)),'foreign_keys'=>count($actual),'data_verified'=>true];
    } finally { measurementUnlock($pdo,$lock); }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
    try { echo json_encode(migrateColumnNames(getDBConnection(),__DIR__.'/backups'),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL; }
    catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
}
