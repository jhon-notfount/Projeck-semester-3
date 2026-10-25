<?php
/** Satu jadwal berulang, sesuai formulir interval/jam mulai yang ada. */
require_once __DIR__ . '/sensors.php';
function normalizeDithane(array $config): array {
    foreach (['interval','duration'] as $key) {
        if (!isset($config[$key]) || filter_var($config[$key], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>2147483647]]) === false) {
            throw new InvalidArgumentException('Interval (jam) dan durasi (detik) harus bilangan bulat positif.');
        }
    }
    $start = $config['start'] ?? '';
    if (!is_string($start) || !preg_match('/^(?:[01][0-9]|2[0-3])[.:][0-5][0-9]$/D', $start)) {
        throw new InvalidArgumentException('Jam mulai harus HH:MM atau HH.MM, antara 00:00 dan 23:59.');
    }
    return ['interval_hours'=>(int)$config['interval'], 'duration_seconds'=>(int)$config['duration'], 'start_time'=>str_replace('.', ':', $start) . ':00'];
}
function readDithane(PDO $pdo): ?array {
    $row = $pdo->query('SELECT `schedule_id` AS `id`, `interval_hours`, `duration_seconds`, `start_time`, `updated_at` FROM dithane_schedules WHERE schedule_id=1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return ['type'=>'dithane', 'config'=>['interval'=>(string)$row['interval_hours'], 'duration'=>(string)$row['duration_seconds'], 'start'=>str_replace(':', '.', substr($row['start_time'],0,5))], 'updated_at'=>$row['updated_at']];
}
function writeDithane(PDO $pdo, array $config): void {
    $data = normalizeDithane($config);
    $lock = measurementLock($pdo);
    try {
        $q = $pdo->prepare('INSERT INTO dithane_schedules (schedule_id,interval_hours,duration_seconds,start_time) VALUES (1,?,?,?) ON DUPLICATE KEY UPDATE interval_hours=?,duration_seconds=?,start_time=?,updated_at=CURRENT_TIMESTAMP');
        $values = [$data['interval_hours'],$data['duration_seconds'],$data['start_time']];
        $q->execute(array_merge($values,$values));
    } finally { measurementUnlock($pdo, $lock); }
}
