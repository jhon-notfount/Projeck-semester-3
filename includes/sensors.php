<?php
/** Penyimpanan nilai pH/PPM dalam tabel terpisah, tanpa identitas perangkat. */
function sensorNumber($value, string $name): float {
    if (!is_scalar($value) || !is_numeric($value) || !is_finite((float)$value)) {
        throw new InvalidArgumentException("Nilai $name harus berupa angka valid.");
    }
    return (float)$value;
}

function sensorLimits(PDO $pdo): array {
    $rows = $pdo->query("SELECT type, config_json FROM settings WHERE type IN ('ph','ppm')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $limits = [];
    foreach (['ph' => [5.5, 6.5], 'ppm' => [800, 1000]] as $type => $defaults) {
        $config = isset($rows[$type]) ? json_decode($rows[$type], true) : ['min' => $defaults[0], 'max' => $defaults[1]];
        if (!is_array($config) || !isset($config['min'], $config['max']) || !is_scalar($config['min']) || !is_scalar($config['max'])) {
            throw new RuntimeException("Konfigurasi batas $type tidak valid.");
        }
        $min = sensorNumber(str_replace(',', '.', (string)$config['min']), "$type minimum");
        $max = sensorNumber(str_replace(',', '.', (string)$config['max']), "$type maksimum");
        if ($min > $max) throw new RuntimeException("Batas minimum $type melebihi maksimum.");
        $limits[$type] = [$min, $max];
    }
    return $limits;
}

function measurementLock(PDO $pdo): string {
    $name = 'hydrotech_measurements_' . substr(hash('sha256', $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 24);
    $q = $pdo->prepare('SELECT GET_LOCK(?, 10)'); $q->execute([$name]);
    if ((int)$q->fetchColumn() !== 1) throw new RuntimeException('Database sedang diperbarui. Coba kembali.');
    return $name;
}
function measurementUnlock(PDO $pdo, string $name): void {
    $q = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$name]);
}
function sensorWarning(PDO $pdo, array $reading, array $limits, bool $includeNormal = false): ?array {
    $type = $reading['type'];
    if (!in_array($type, ['ph','ppm'], true)) throw new InvalidArgumentException('Jenis pembacaan tidak valid.');
    [$min, $max] = $limits[$type];
    $value = (float)$reading['value'];
    $abnormal = $value < $min || $value > $max;
    if (!$abnormal && !$includeNormal) return null;
    $label = ($type === 'ph' ? 'pH' : 'PPM') . ($abnormal ? ' perlu dipantau' : ' normal');
    $message = "Nilai $type ($value) " . ($abnormal ? 'di luar' : 'dalam') . " rentang $min - $max.";
    $tone = $abnormal ? 'warning' : 'success';
    $column = $type . '_reading_id';
    $stmt = $pdo->prepare("INSERT INTO notifications (label, message, tone, href, $column) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$label, $message, $tone, "pengaturan-$type.php", $reading['id']]);
    return ['label'=>$label, 'message'=>$message, 'tone'=>$tone, $column=>(int)$reading['id']];
}

function saveSensorMeasurements(PDO $pdo, array $input): array {
    foreach (['sensor_id','ph_sensor_id','ppm_sensor_id','value'] as $old) {
        if (array_key_exists($old, $input)) throw new InvalidArgumentException('Gunakan ph_value dan/atau ppm_value tanpa ID perangkat.');
    }
    $values = [];
    foreach (['ph','ppm'] as $type) {
        if (!array_key_exists($type . '_value', $input)) continue;
        $value = sensorNumber($input[$type . '_value'], $type);
        if ($value < 0 || $value > ($type === 'ph' ? 14 : 2147483647)) {
            throw new InvalidArgumentException('pH harus 0-14 dan PPM harus 0-2147483647.');
        }
        if ($type === 'ppm' && floor($value) !== $value) throw new InvalidArgumentException('PPM harus berupa bilangan bulat tanpa pecahan.');
        $values[$type] = $type === 'ppm' ? (int)$value : round($value, 4);
    }
    if (!$values) throw new InvalidArgumentException('Kirim ph_value dan/atau ppm_value berupa angka.');
    $lock = measurementLock($pdo);
    try {
        $pdo->beginTransaction();
        $limits = sensorLimits($pdo);
        $timestamp = $pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
        $clock = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
        $days = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
        $result = [];
        foreach ($values as $type => $value) {
            $insert = $pdo->prepare("INSERT INTO {$type}_readings ({$type}_value, recorded_at) VALUES (?, ?)");
            $insert->execute([$value, $timestamp]);
            $reading = ['id'=>(int)$pdo->lastInsertId(), 'type'=>$type, 'unit'=>$type === 'ph' ? null : 'ppm',
                $type . '_value'=>$value, 'value'=>$value, 'recorded_at'=>$timestamp];
            [$min, $max] = $limits[$type];
            $status = $value < $min ? 'Rendah' : ($value > $max ? 'Tinggi' : 'Normal');
            $log = $pdo->prepare("INSERT INTO monitoring_logs (type, day_name, log_time, value, status, note, {$type}_reading_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $log->execute([$type, $days[(int)$clock->format('w')], $clock->format('H.i'), (string)$value, $status, 'Hasil pengukuran ' . $type, $reading['id']]);
            sensorWarning($pdo, $reading, $limits);
            $result[] = $reading;
        }
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally { measurementUnlock($pdo, $lock); }
}

function latestSensorReadings(PDO $pdo): array {
    $result = [];
    foreach (['ph','ppm'] as $type) {
        $r = $pdo->query("SELECT id, {$type}_value, recorded_at FROM {$type}_readings ORDER BY recorded_at DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $r['type'] = $type; $r['unit'] = $type === 'ph' ? null : 'ppm';
            $r[$type . '_value'] = $type === 'ppm' ? (int)$r[$type . '_value'] : (float)$r[$type . '_value'];
            $r['value'] = $r[$type . '_value']; $result[] = $r;
        }
    }
    return $result;
}
