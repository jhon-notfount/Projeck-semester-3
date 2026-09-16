from pathlib import Path
import re
root=Path(__file__).resolve().parent.parent
def put(path,text): (root/path).write_text(text,encoding='utf-8')
def read(path): return (root/path).read_text(encoding='utf-8')

draft=read('outputs/rancangan-database-dengan-sensor.sql')
schema=draft[draft.index('CREATE TABLE `users`'):draft.index('-- Contoh data khusus')]
schema=schema.replace('CREATE TABLE `','CREATE TABLE IF NOT EXISTS `')
put('database/schema.sql','-- Hydrotech: 8 tabel, 57 atribut, 7 foreign key.\n-- Satu sensor menghasilkan banyak pembacaan.\n-- Database lama: jalankan php database/migrate_sensors.php.\n\n'+schema)

config=read('config/database.php')
for var,name,default in [('HOST','HOST','localhost'),('USER','USER','root'),('PASS','PASS',''),('NAME','NAME','monitoring_sensor')]:
    config=re.sub(rf"\$DB_{var}\s*=\s*'[^']*';",f"$DB_{var} = getenv('HYDROTECH_DB_{name}') !== false ? getenv('HYDROTECH_DB_{name}') : '{default}';",config)
put('config/database.php',config)

install=read('database/install.php')
install=install.replace('echo "=== Hydrotech Database Installer',"if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Installer hanya tersedia melalui CLI.'); }\n\necho \"=== Hydrotech Database Installer",1)
for var,name,default in [('host','HOST','localhost'),('user','USER','root'),('pass','PASS',''),('dbname','NAME','monitoring_sensor')]:
    install=re.sub(rf"\${var}\s*=\s*'[^']*';",f"${var} = getenv('HYDROTECH_DB_{name}') !== false ? getenv('HYDROTECH_DB_{name}') : '{default}';",install)
install=install.replace('$pdo->exec("USE `$dbname`");', '''$pdo->exec("USE `$dbname`");
    if ($pdo->query('SHOW TABLES')->fetchColumn() !== false) {
        throw new RuntimeException('Database sudah memiliki tabel. Gunakan php database/migrate_sensors.php untuk memperbarui tanpa mereset data.');
    }''')
install=re.sub(r'    \$pdo->exec\("DELETE FROM \w+"\);\n','',install)
install=install.replace('// ── 4. Seed Users ──','// ── 4. Seed Users ──')
install=install.replace("    $passwordHash = password_hash", "    $pdo->beginTransaction();\n    $passwordHash = password_hash",1)
install=install.replace('"INSERT INTO sensor_readings (ph_value, ppm_value, recorded_at)\n         VALUES (?, ?, DATE_SUB(NOW(), INTERVAL ? HOUR))"', '"INSERT INTO sensor_readings (sensor_id, value, recorded_at)\n         VALUES (?, ?, DATE_SUB(NOW(), INTERVAL ? HOUR))"')
install=install.replace('    $sensorData = [', '''    $pdo->exec("INSERT INTO sensors (id, name, type, unit, status, location) VALUES
        (1, 'Sensor pH (simulasi)', 'ph', 'pH', 'aktif', 'Simulasi dashboard'),
        (2, 'Sensor TDS (simulasi)', 'ppm', 'ppm', 'aktif', 'Simulasi dashboard')");
    $sensorData = [''')
install=install.replace('$stmt->execute([$row[0], $row[1], $hours]);', '$stmt->execute([1, $row[0], $hours]);\n        $stmt->execute([2, $row[1], $hours]);')
install=install.replace('Sample sensor readings seeded (8 rows).','Sensor seeded (2 devices), sample readings seeded (16 rows).')
install=install.replace('    echo "\\n=== Installation Complete!', '    $pdo->commit();\n    echo "\\n=== Installation Complete!')
install=install.replace('profile, sensor_readings\\n','profile, sensors, sensor_readings\\n')
install=install.replace('} catch (PDOException $e) {','} catch (Throwable $e) {\n    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();')
put('database/install.php',install)

common="""<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/sensors.php';
requireAuth();
"""
put('api/sensor/save.php',common+"""
if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);
try {
    $readings = saveSensorMeasurements(getDBConnection(), $_POST);
    successResponse(['readings' => $readings, 'reading_ids' => array_column($readings, 'id')], 'Data sensor tersimpan');
} catch (InvalidArgumentException $e) {
    errorResponse($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('Sensor save: ' . $e->getMessage());
    errorResponse('Data sensor gagal disimpan.', 500);
}
""")
put('api/sensor/latest.php',common+"""
if ($_SERVER['REQUEST_METHOD'] !== 'GET') errorResponse('Method not allowed', 405);
try {
    $pdo = getDBConnection();
    $where = [];
    $params = [];
    if (isset($_GET['sensor_id'])) {
        $id = filter_var($_GET['sensor_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) errorResponse('ID sensor tidak valid.', 400);
        $where[] = 'r.sensor_id = ?';
        $params[] = $id;
    }
    if (isset($_GET['type'])) {
        if (!in_array($_GET['type'], ['ph','ppm'], true)) errorResponse('Jenis sensor tidak valid.', 400);
        $where[] = 's.type = ?';
        $params[] = $_GET['type'];
    }
    $query = 'SELECT r.*, s.name AS sensor_name, s.type, s.unit, s.status AS sensor_status FROM sensor_readings r JOIN sensors s ON s.id = r.sensor_id';
    if ($where) $query .= ' WHERE ' . implode(' AND ', $where);
    $query .= ' ORDER BY r.recorded_at DESC, r.id DESC LIMIT 10';
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    successResponse($stmt->fetchAll());
} catch (Throwable $e) {
    errorResponse('Tidak dapat membaca hasil sensor.', 500);
}
""")
summary=read('api/dashboard/summary.php')
summary=summary.replace('requireAuth();',"require_once __DIR__ . '/../../includes/sensors.php';\nrequireAuth();",1)
summary=summary.replace('    $stmtReading = $pdo->query("SELECT * FROM sensor_readings ORDER BY recorded_at DESC LIMIT 1");\n    $latestReading = $stmtReading->fetch(PDO::FETCH_ASSOC);', '''    $latestReadings = latestSensorReadings($pdo);
    // Compatibility fields: newest available reading per parameter, with its own timestamp.
    $latestReading = null;
    foreach ($latestReadings as $reading) {
        if ($latestReading === null) $latestReading = [];
        $key = $reading['type'] . '_value';
        if (!array_key_exists($key, $latestReading)) {
            $latestReading[$key] = (float)$reading['value'];
            $latestReading[$reading['type'] . '_sensor_id'] = (int)$reading['sensor_id'];
            $latestReading[$reading['type'] . '_recorded_at'] = $reading['recorded_at'];
        }
    }''')
summary=summary.replace("'latest_reading' => $latestReading,", "'latest_reading' => $latestReading,\n        'latest_readings' => $latestReadings,")
put('api/dashboard/summary.php',summary)
put('api/notifications/generate.php',common+"""
if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);
try {
    $pdo = getDBConnection();
    $pdo->beginTransaction();
    $limits = sensorLimits($pdo);
    $generated = [];
    foreach (latestSensorReadings($pdo) as $reading) {
        $generated[] = sensorWarning($pdo, $reading, $limits, true);
    }
    $pdo->commit();
    successResponse($generated, 'Notifikasi sensor diperbarui');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    errorResponse('Tidak dapat membuat notifikasi sensor.', 500);
}
""")
for path in ['api/settings/update.php','api/settings/reset.php']:
    s=read(path).replace('SET config_json = ?, updated_at', 'SET config_json = ?, updated_by = ?, updated_at')
    s=s.replace('[$configJson, $type]', "[$configJson, $_SESSION['user_id'], $type]").replace('[$defaultConfig, $type]', "[$defaultConfig, $_SESSION['user_id'], $type]")
    put(path,s)
s=read('api/profile/update.php').replace('(section, label, value, sort_order) VALUES (?, ?, ?, ?)', '(section, label, value, sort_order, updated_by) VALUES (?, ?, ?, ?, ?)')
s=s.replace('[$section, $label, $value, $index]', "[$section, $label, $value, $index, $_SESSION['user_id']]")
put('api/profile/update.php',s)

s=read('js/dashboard-live.js')
s=s.replace('  await applySettingRanges();','''  await applySettingRanges();

  // Dashboard remains a simulator. Resolve explicit device IDs before saving.
  let simulationSensors = null;
  try {
    const response = await fetch('../api/sensor/list.php');
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'Daftar sensor gagal dimuat');
    const ph = result.data.filter(sensor => sensor.type === 'ph' && sensor.status === 'aktif');
    const ppm = result.data.filter(sensor => sensor.type === 'ppm' && sensor.status === 'aktif');
    if (ph.length !== 1 || ppm.length !== 1) throw new Error('Simulasi memerlukan satu sensor pH dan satu sensor PPM aktif.');
    simulationSensors = { ph: ph[0].id, ppm: ppm[0].id };
  } catch (error) {
    console.error(error);
  }
  let savingReading = false;''')
old='''    const formData = new FormData();
    formData.append("ph_value", latestPh);
    formData.append("ppm_value", latestPpm);
    fetch("../api/sensor/save.php", {
      method: "POST",
      body: formData
    }).catch(e => {
      console.error("Gagal menyimpan data sensor:", e);
    });'''
new='''    if (!simulationSensors) {
      if (updateText) updateText.textContent = "simulasi tidak tersimpan";
      return;
    }
    if (savingReading) return;
    savingReading = true;
    try {
      const formData = new FormData();
      formData.append("ph_sensor_id", simulationSensors.ph);
      formData.append("ppm_sensor_id", simulationSensors.ppm);
      formData.append("ph_value", latestPh);
      formData.append("ppm_value", latestPpm);
      const response = await fetch("../api/sensor/save.php", { method: "POST", body: formData });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || "Penyimpanan gagal");
      if (updateText) updateText.textContent = "simulasi tersimpan";
    } catch (error) {
      if (updateText) updateText.textContent = "gagal menyimpan simulasi";
      console.error("Gagal menyimpan data sensor:", error);
    } finally {
      savingReading = false;
    }'''
assert old in s
put('js/dashboard-live.js',s.replace(old,new))
ignore=root/'.gitignore'
existing=ignore.read_text(encoding='utf-8') if ignore.exists() else ''
if '/database/backups/' not in existing: put('.gitignore',existing+'\n/database/backups/\n')
print('Skema, installer, API, dan dashboard diperbarui.')
