<?php
/* Backup dump sederhana ke file opsional (database/backups). */
require 'config/database.php';
$pdo = getDBConnection();
if (!is_dir(__DIR__ . '/backups')) mkdir(__DIR__ . '/backups', 0700, true);
$target = __DIR__ . '/backups/' . $DB_NAME . '_before_relations_' . date('Ymd_His') . '.sql';
$out = "-- Backup sebelum migrate_relations.php\n";
foreach (['users','account_change_logs','settings','sensor_readings','dithane_schedules','monitoring_logs','notifications','profile'] as $table) {
    $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC)['Create Table'];
    $out .= "\nDROP TABLE IF EXISTS `$table`;\n$create;\n";
    foreach ($pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols = implode(',', array_map(fn($c) => '`' . $c . '`', array_keys($row)));
        $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row));
        $out .= "INSERT INTO `$table` ($cols) VALUES ($vals);\n";
    }
}
file_put_contents($target, $out);
echo "backup: $target\n";
