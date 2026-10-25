<?php
/**
 * Hydrotech - Tren Harian pH/PPM
 *
 * Mengembalikan rata-rata harian pengukuran pH dan PPM asli untuk satu bulan.
 * Digunakan grafik halaman history dan laporan PDF. Hanya tanggal yang memiliki
 * pengukuran yang dikembalikan.
 *
 * GET params: month (1-12, default bulan ini), year (default tahun ini).
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') errorResponse('Method not allowed', 405);

try {
    $month = (int)($_GET['month'] ?? date('n'));
    $year = (int)($_GET['year'] ?? date('Y'));
    if ($month < 1 || $month > 12) errorResponse('Bulan harus 1-12.', 400);
    if ($year < 2000 || $year > 2100) errorResponse('Tahun tidak valid.', 400);

    $pdo = getDBConnection();
    // Bulan/tahun sudah divalidasi sebagai integer, aman diinterpolasi langsung.
    // AVG mengabaikan baris dengan nilai NULL (perangkat kadang hanya
    // mengirim salah satu nilai).
    $rows = $pdo->query("SELECT DATE(recorded_at) AS log_date,
                                AVG(ph_value) AS avg_ph,
                                AVG(ppm_value) AS avg_ppm,
                                MAX(recorded_at) AS last_at
                           FROM sensor_readings
                          WHERE YEAR(recorded_at) = $year AND MONTH(recorded_at) = $month
                          GROUP BY DATE(recorded_at)
                          ORDER BY log_date")->fetchAll();

    $dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    $trend = [];
    foreach ($rows as $row) {
        $date = new DateTimeImmutable((string)$row['log_date']);
        $clock = $row['last_at'] ? new DateTimeImmutable((string)$row['last_at']) : null;
        $trend[] = [
            'day' => $dayNames[(int)$date->format('w')],
            'date' => $date->format('j') . ' ' . $monthNames[(int)$date->format('n') - 1] . ' ' . $date->format('Y'),
            'time' => $clock ? $clock->format('H.i') : '-',
            'ph' => $row['avg_ph'] !== null ? round((float)$row['avg_ph'], 2) : null,
            'ppm' => $row['avg_ppm'] !== null ? (int)round((float)$row['avg_ppm']) : null,
        ];
    }

    successResponse(['year' => $year, 'month' => $month, 'trend' => $trend], 'Tren harian pH/PPM');
} catch (Throwable $e) {
    error_log('History trend: ' . $e->getMessage());
    errorResponse('Tidak dapat membaca tren history.', 500);
}
