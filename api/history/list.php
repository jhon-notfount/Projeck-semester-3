<?php
/**
 * Hydrotech - Data Aktivitas Sistem (riwayat kesimpulan gabungan)
 *
 * Mengembalikan baris type='sistem' (kesimpulan pH+PPM+Dithane) dengan
 * SEMUA filter dieksekusi di database (bukan hasil tampilan frontend):
 *   search   : hari/tanggal/jam/status/isi keterangan
 *   day      : Senin..Minggu (kolom day_name)
 *   month    : 1-12 (MONTH(created_at))
 *   year     : tahun (YEAR(created_at))
 *   status   : Normal | Perhatian | Tidak Normal
 *   time_from / time_to : rentang "HH.MM" terhadap log_time
 * Response: rows + daftar tahun yang tersedia (untuk filter tahun).
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { errorResponse('Method not allowed', 405); }

try {
    $pdo = getDBConnection();

    $search   = trim((string)($_GET['search'] ?? ''));
    $day      = trim((string)($_GET['day'] ?? ''));
    $month    = trim((string)($_GET['month'] ?? ''));
    $year     = trim((string)($_GET['year'] ?? ''));
    $status   = trim((string)($_GET['status'] ?? ''));
    $timeFrom = trim((string)($_GET['time_from'] ?? ''));
    $timeTo   = trim((string)($_GET['time_to'] ?? ''));

    /* Tipe data: default 'sistem' (kesimpulan gabungan); 'dithane'
       tersedia untuk ringkasan aktivitas pompa pada laporan PDF.        */
    $type = trim((string)($_GET['type'] ?? 'sistem'));
    if (!in_array($type, ['sistem', 'dithane'], true)) $type = 'sistem';

    $query = "SELECT `monitoring_id` AS `id`, `type`, `day_name`, `log_time`, `value`,
                     `status`, `note`, `ph_value`, `ppm_value`, `dithane_state`,
                     `is_deleted`, DATE(`created_at`) AS `log_date`, `created_at`
              FROM monitoring_logs
              WHERE `is_deleted` = 0 AND `type` = '" . $type . "'";
    $params = [];

    if ($search !== '') {
        /* Pencarian mencakup hari, jam, status, keterangan, dan tanggal. */
        $query .= " AND (`day_name` LIKE ? OR `log_time` LIKE ? OR `status` LIKE ?
                      OR `note` LIKE ? OR DATE_FORMAT(`created_at`, '%d %M %Y') LIKE ?
                      OR DATE_FORMAT(`created_at`, '%e %M %Y') LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    if ($day !== '') {
        /* Terima "Senin" (kapital awal) maupun "senin". */
        $query .= " AND LOWER(`day_name`) = LOWER(?)";
        $params[] = $day;
    }
    if ($month !== '' && filter_var($month, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) !== false) {
        $query .= " AND MONTH(`created_at`) = ?";
        $params[] = (int)$month;
    }
    if ($year !== '' && filter_var($year, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) !== false) {
        $query .= " AND YEAR(`created_at`) = ?";
        $params[] = (int)$year;
    }
    if ($status !== '') {
        $query .= " AND `status` = ?";
        $params[] = $status;
    }
    /* Rentang jam & menit: log_time berformat "HH.MM" sehingga perbandingan
       string cukup untuk urutan waktu. Terima "08.00" / "08:00".           */
    $normalizeTime = function (string $t): string {
        $t = str_replace(':', '.', $t);
        return preg_match('/^\d{1,2}\.\d{2}$/', $t)
            ? sprintf('%02d.%02d', ...array_map('intval', explode('.', $t)))
            : '';
    };
    $from = $normalizeTime($timeFrom);
    $to = $normalizeTime($timeTo);
    if ($from !== '') {
        $query .= " AND SUBSTRING_INDEX(`log_time`, '.', 2) >= ?";
        $params[] = $from;
    }
    if ($to !== '') {
        $query .= " AND SUBSTRING_INDEX(`log_time`, '.', 2) <= ?";
        $params[] = $to;
    }

    $query .= " ORDER BY `created_at` DESC, `monitoring_id` DESC LIMIT 2000";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $years = $pdo->prepare(
        "SELECT DISTINCT YEAR(`created_at`) AS y FROM monitoring_logs
          WHERE type = ? AND is_deleted = 0 ORDER BY y DESC"
    );
    $years->execute([$type]);

    successResponse(['rows' => $rows, 'years' => array_map('intval', $years->fetchAll(PDO::FETCH_COLUMN))], 'Data aktivitas sistem');
} catch (Exception $e) {
    error_log('History list: ' . $e->getMessage());
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
