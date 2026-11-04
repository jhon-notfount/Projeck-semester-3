<?php
/** Penyimpanan pengukuran pH/PPM asli dalam satu tabel sensor_readings. */
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
        $valid = validateSettingConfig($type, is_array($config) ? $config : []);
        $limits[$type] = [(float)$valid['min'], (float)$valid['max']];
    }
    return $limits;
}

/** Validasi bersama konfigurasi ambang ppm/ph: dipakai saat SIMPAN (update) dan BACA (limits). */
function validateSettingConfig(string $type, array $config): array {
    $ranges = ['ph' => [0.0, 14.0], 'ppm' => [0.0, 2147483647.0]];
    if (!isset($ranges[$type])) throw new InvalidArgumentException('Jenis pengaturan tidak valid.');
    foreach (['min', 'max'] as $key) {
        if (!isset($config[$key]) || $config[$key] === '' || !is_scalar($config[$key])) {
            throw new InvalidArgumentException("Nilai {$key} wajib diisi berupa angka.");
        }
        $num = str_replace(',', '.', (string)$config[$key]);
        if (!is_numeric($num) || !is_finite((float)$num)) {
            throw new InvalidArgumentException("Nilai {$key} harus berupa angka valid.");
        }
        $config[$key] = $num;
    }
    [$lo, $hi] = $ranges[$type];
    $min = (float)$config['min'];
    $max = (float)$config['max'];
    if ($min < $lo || $max < $lo || $min > $hi || $max > $hi) {
        $label = $type === 'ph' ? 'pH' : 'PPM';
        throw new InvalidArgumentException("Rentang $label harus di antara $lo dan $hi.");
    }
    if ($min > $max) {
        throw new InvalidArgumentException('Nilai minimum tidak boleh melebihi maksimum.');
    }
    return ['min' => $config['min'], 'max' => $config['max']];
}

function measurementLock(PDO $pdo): string {
    $name = 'hydrotech_measurements_' . substr(hash('sha256', $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 24);
    $q = $pdo->prepare('SELECT GET_LOCK(?, 10)'); $q->execute([$name]);
    if ((int)$q->fetchColumn() !== 1) throw new RuntimeException('Database sedang diperbarui. Coba kembali.');
    return $name;
}
function measurementUnlock(PDO $pdo, string $name): void {
    $q = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $q->execute([$name]);
}

/* =====================================================================
 *  ATURAN PENCATATAN AKTIVITAS SISTEM (tabel Data Aktivitas Sistem)
 *  ------------------------------------------------------------------
 *  pH/PPM:
 *   - abnormal (Rendah/Tinggi): catat saat kondisi abnormal PERTAMA
 *     kali terdeteksi, lalu hanya saat kondisinya berubah
 *     (Rendah<->Tinggi, abnormal<->normal). Kondisi abnormal yang sama
 *     yang berlangsung TIDAK menghasilkan catatan berulang.
 *   - normal: catat langsung saat berubah dari abnormal menjadi normal,
 *     lalu catat berkala SETIAP 20 MENIT sejak catatan terakhir tipe
 *     tersebut. Timer 20 menit pH dan PPM berjalan INDEPENDEN.
 *  Dithane: dicatat di api/device/report.php, satu catatan SETIAP kali
 *   pompa mulai menyala (tidak ada catatan berkala saat pompa tetap
 *   menyala; tidak ada catatan saat hanya membaca sensor/jadwal).
 * ===================================================================== */

/** Keterangan aktivitas: nilai terbaca beserta batas rentang pengaturan. */
function activityNote(string $type, string $status, string $value, float $min, float $max): string {
    $label = $type === 'ph' ? 'pH' : 'PPM';
    if ($status === 'Rendah') return "$label $value di bawah rentang $min - $max";
    if ($status === 'Tinggi') return "$label $value di atas rentang $min - $max";
    return "$label $value dalam rentang $min - $max";
}

/** Putuskan apakah pembacaan (dengan status sudah dihitung) perlu dicatat.
 *  State dibaca dari catatan TERBARU tipe tersebut di monitoring_logs
 *  (kebal restart), dievaluasi dalam transaksi + lock yang sama dengan
 *  penyimpanan pengukuran agar antrean paralel tidak menggandakan data.  */
function shouldRecordActivity(PDO $pdo, string $type, string $status): bool {
    $state = $pdo->prepare(
        "SELECT status, TIMESTAMPDIFF(SECOND, created_at, CURRENT_TIMESTAMP) AS age
           FROM monitoring_logs
          WHERE type = ? AND is_deleted = 0
          ORDER BY monitoring_id DESC
          LIMIT 1"
    );
    $state->execute([$type]);
    $last = $state->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($last === null) return true;                       // deteksi pertama: catat
    $abnormal = ($status === 'Rendah' || $status === 'Tinggi');
    if ($abnormal) return $last['status'] !== $status;     // abnormal sama: jangan catat ulang
    if ($last['status'] !== 'Normal') return true;         // perubahan (abnormal -> normal): catat
    $age = $last['age'];                                   // normal rutin: berkala 20 menit
    return $age === null || (int)$age >= 1200;
}
function sensorWarning(PDO $pdo, array $reading, array $limits, bool $includeNormal = false, bool $dedup = false, ?int $monitoringId = null, ?int $userId = null): ?array {
    $type = $reading['type'];
    if (!in_array($type, ['ph','ppm'], true)) throw new InvalidArgumentException('Jenis pembacaan tidak valid.');
    [$min, $max] = $limits[$type];
    $value = (float)$reading['value'];
    $abnormal = $value < $min || $value > $max;
    if (!$abnormal && !$includeNormal) return null;
    $label = ($type === 'ph' ? 'pH' : 'PPM') . ($abnormal ? ' perlu dipantau' : ' normal');
    $message = "Nilai $type ($value) " . ($abnormal ? 'di luar' : 'dalam') . " rentang $min - $max.";
    $tone = $abnormal ? 'warning' : 'success';
    if ($dedup) {
        // Anti duplikasi: lewati bila notifikasi identik untuk kejadian yang sama sudah ada.
        $q = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE label = ? AND message = ?");
        $q->execute([$label, $message]);
        if ((int)$q->fetchColumn() > 0) return null;
    }
    $stmt = $pdo->prepare("INSERT INTO notifications (label, message, tone, href, monitoring_id, user_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$label, $message, $tone, "pengaturan-$type.php", $monitoringId, $userId]);
    return ['label'=>$label, 'message'=>$message, 'tone'=>$tone];
}

/** Simpan satu pengukuran (ph_value dan/atau ppm_value) sebagai satu baris.
 *  $operatorId berasal dari sesi operator bila penyimpanan manual melalui
 *  halaman web; ingest perangkat memakai NULL (notifikasi broadcast). */
function saveSensorMeasurements(PDO $pdo, array $input, ?int $operatorId = null): array {
    foreach (['sensor_id','ph_sensor_id','ppm_sensor_id','value'] as $old) {
        if (array_key_exists($old, $input)) throw new InvalidArgumentException('Gunakan ph_value dan/atau ppm_value tanpa ID perangkat.');
    }
    $values = [];
    foreach (['ph','ppm'] as $type) {
        if (!array_key_exists($type . '_value', $input) || $input[$type . '_value'] === null || $input[$type . '_value'] === '') continue;
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
        $insert = $pdo->prepare("INSERT INTO sensor_readings (ph_value, ppm_value, recorded_at) VALUES (?, ?, ?)");
        $insert->execute([$values['ph'] ?? null, $values['ppm'] ?? null, $timestamp]);
        $readingId = (int)$pdo->lastInsertId();
        $result = [];
        foreach ($values as $type => $value) {
            $reading = ['id'=>$readingId, 'type'=>$type, 'unit'=>$type === 'ph' ? null : 'ppm',
                $type . '_value'=>$value, 'value'=>$value, 'recorded_at'=>$timestamp];
            [$min, $max] = $limits[$type];
            $status = $value < $min ? 'Rendah' : ($value > $max ? 'Tinggi' : 'Normal');
            if (shouldRecordActivity($pdo, $type, $status)) {
                /* Timestamp kejadian sebenarnya: sekarang pembacaan masuk,
                   waktu server WAJIB sudah konsisten (session ini +07:00). */
                $note = activityNote($type, $status, (string)$value, $min, $max);
                $log = $pdo->prepare("INSERT INTO monitoring_logs (type, day_name, log_time, value, status, note, reading_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $log->execute([$type, $days[(int)$clock->format('w')], $clock->format('H.i'), (string)$value, $status, $note, $readingId]);
                $monitoringId = (int)$pdo->lastInsertId();
            } else {
                $monitoringId = null;
            }
            sensorWarning($pdo, $reading, $limits, false, false, $monitoringId, $operatorId);
            $result[] = $reading;
        }
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally { measurementUnlock($pdo, $lock); }
}

/** Pengukuran asli terakhir; satu baris bisa menghasilkan entri pH dan PPM. */
function latestSensorReadings(PDO $pdo, int $limit = 10): array {
    $limit = max(1, min(100, $limit));
    $rows = $pdo->prepare("SELECT reading_id AS id, ph_value, ppm_value, recorded_at FROM sensor_readings ORDER BY recorded_at DESC, reading_id DESC LIMIT {$limit}");
    $rows->execute();
    $result = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['ph_value'] !== null) {
            $ph = (float)$row['ph_value'];
            $result[] = ['id'=>(int)$row['id'], 'type'=>'ph', 'unit'=>null,
                'ph_value'=>$ph, 'value'=>$ph, 'recorded_at'=>$row['recorded_at']];
        }
        if ($row['ppm_value'] !== null) {
            $ppm = (int)$row['ppm_value'];
            $result[] = ['id'=>(int)$row['id'], 'type'=>'ppm', 'unit'=>'ppm',
                'ppm_value'=>$ppm, 'value'=>$ppm, 'recorded_at'=>$row['recorded_at']];
        }
    }
    return $result;
}
