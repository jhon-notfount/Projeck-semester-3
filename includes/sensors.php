<?php
/** Penyimpanan satu hasil ukur per perangkat; payload pasangan lama tetap didukung. */
function sensorNumber($value, string $name): float {
    if (!is_scalar($value) || !is_numeric($value) || !is_finite((float)$value)) {
        throw new InvalidArgumentException("Nilai $name harus berupa angka valid.");
    }
    return (float)$value;
}

function resolveSensor(PDO $pdo, $id, ?string $type = null): array {
    if ($id !== null) {
        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException('ID sensor tidak valid.');
        }
        $stmt = $pdo->prepare('SELECT * FROM sensors WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $sensor = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sensor || $sensor['status'] !== 'aktif' || ($type !== null && $sensor['type'] !== $type)) {
            throw new InvalidArgumentException('Sensor tidak ditemukan, tidak aktif, atau jenisnya tidak sesuai.');
        }
        return $sensor;
    }
    $stmt = $pdo->prepare("SELECT * FROM sensors WHERE type = ? AND status = 'aktif' ORDER BY id FOR UPDATE");
    $stmt->execute([$type]);
    $sensors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($sensors) !== 1) {
        throw new InvalidArgumentException("Tentukan ID sensor $type: harus ada tepat satu sensor aktif jika ID tidak dikirim.");
    }
    return $sensors[0];
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

function sensorWarning(PDO $pdo, array $reading, array $limits, bool $includeNormal = false): ?array {
    $type = $reading['type'];
    [$min, $max] = $limits[$type];
    $value = (float)$reading['value'];
    $abnormal = $value < $min || $value > $max;
    if (!$abnormal && !$includeNormal) return null;
    $label = ($type === 'ph' ? 'pH' : 'PPM') . ($abnormal ? ' perlu dipantau' : ' normal');
    $message = "Nilai {$reading['sensor_name']} ($value) " . ($abnormal ? 'di luar' : 'dalam') . " rentang $min - $max.";
    $tone = $abnormal ? 'warning' : 'success';
    $stmt = $pdo->prepare('INSERT INTO notifications (label, message, tone, href, sensor_reading_id) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$label, $message, $tone, "pengaturan-$type.php", $reading['id']]);
    return ['label' => $label, 'message' => $message, 'tone' => $tone, 'sensor_reading_id' => (int)$reading['id']];
}

function saveSensorMeasurements(PDO $pdo, array $input): array {
    $pdo->beginTransaction();
    try {
        if (array_key_exists('sensor_id', $input) || array_key_exists('value', $input)) {
            if (!isset($input['sensor_id'], $input['value']) || isset($input['ph_value']) || isset($input['ppm_value'])) {
                throw new InvalidArgumentException('Kirim sensor_id dan value, atau pasangan ph_value dan ppm_value.');
            }
            $measurements = [[resolveSensor($pdo, $input['sensor_id']), $input['value']]];
        } else {
            if (!isset($input['ph_value'], $input['ppm_value'])) throw new InvalidArgumentException('ph_value dan ppm_value wajib dikirim bersama.');
            $measurements = [
                [resolveSensor($pdo, $input['ph_sensor_id'] ?? null, 'ph'), $input['ph_value']],
                [resolveSensor($pdo, $input['ppm_sensor_id'] ?? null, 'ppm'), $input['ppm_value']],
            ];
        }
        // Validate the entire batch before writing any readings.
        foreach ($measurements as &$measurement) {
            [$sensor, $raw] = $measurement;
            $value = sensorNumber($raw, $sensor['type']);
            if ($value < 0 || $value > 99999999.9999 || ($sensor['type'] === 'ph' && $value > 14)) {
                throw new InvalidArgumentException('pH harus 0-14 dan PPM harus 0-99999999.9999.');
            }
            $measurement[1] = round($value, 4);
        }
        unset($measurement);
        $limits = sensorLimits($pdo);
        $timestamp = $pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
        $clock = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
        $days = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
        $insert = $pdo->prepare('INSERT INTO sensor_readings (sensor_id, value, recorded_at) VALUES (?, ?, ?)');
        $log = $pdo->prepare('INSERT INTO monitoring_logs (type, day_name, log_time, value, status, note, sensor_reading_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $result = [];
        foreach ($measurements as [$sensor, $value]) {
            $insert->execute([$sensor['id'], $value, $timestamp]);
            $reading = ['id' => (int)$pdo->lastInsertId(), 'sensor_id' => (int)$sensor['id'], 'sensor_name' => $sensor['name'], 'type' => $sensor['type'], 'unit' => $sensor['unit'], 'value' => $value, 'recorded_at' => $timestamp];
            [$min, $max] = $limits[$sensor['type']];
            $status = $value < $min ? 'Rendah' : ($value > $max ? 'Tinggi' : 'Normal');
            $log->execute([$sensor['type'], $days[(int)$clock->format('w')], $clock->format('H.i'), (string)$value, $status, 'Pembacaan ' . $sensor['name'], $reading['id']]);
            sensorWarning($pdo, $reading, $limits);
            $result[] = $reading;
        }
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function latestSensorReadings(PDO $pdo): array {
    return $pdo->query("SELECT r.*, s.name AS sensor_name, s.type, s.unit
        FROM sensor_readings r JOIN sensors s ON s.id = r.sensor_id
        WHERE s.status = 'aktif' AND NOT EXISTS (
            SELECT 1 FROM sensor_readings newer WHERE newer.sensor_id = r.sensor_id
            AND (newer.recorded_at > r.recorded_at OR (newer.recorded_at = r.recorded_at AND newer.id > r.id))
        ) ORDER BY r.recorded_at DESC, r.id DESC")->fetchAll(PDO::FETCH_ASSOC);
}
