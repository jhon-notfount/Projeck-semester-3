<?php
/**
 * ===================================================================
 *  PATCH OPSIONAL — api/device/report.php   (v2, sesuai spesifikasi)
 *  (file BARU — tidak menimpa backend yang ada. Salin ke
 *   <root-project>/api/device/report.php bila ingin dipakai)
 * ===================================================================
 *
 * Ketentuan spesifikasi fungsi yang dilayani endpoint ini:
 *   - Notifikasi Dithane MULAI   -> state "started"   -> notifications
 *   - Notifikasi Dithane SELESAI -> state "completed" -> notifications
 *                              + log bukti eksekusi di monitoring_logs
 *   - Anti-spam: setiap insert dide-dup per (label, message) yang
 *     berbeda per "device_seen"/menit-server, sehingga HTTP retry
 *     tidak menggandakan notifikasi ATAU log.
 *
 * Autentikasi sama seperti api/sensor/ingest.php (API key, X-API-Key).
 * TANPA perubahan skema database: ENUM monitoring_logs.type sudah
 * memiliki 'dithane'; notifications adalah tabel yang ada.
 *
 * Batas: hanya pump="dithane"; pompa dosing tidak dilaporkan di sini
 * (pemantauan dosing tetap lewat sensor_readings + notifikasi ambang
 * yang sudah ada di backend).
 * Keterbatasan kejujuran: ESP32 melaporkan SINYAL RELAY; endpoint ini
 * tidak memverifikasi aliran cairan secara fisik.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/esp.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/dithane.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        errorResponse('Method not allowed', 405);
    }
    requireEspKey([]);          // header X-API-Key / body api_key / ?api_key=

    $raw = file_get_contents('php://input');
    $input = [];
    if (str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        try { $input = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { errorResponse('JSON tidak valid.', 400); }
    } else if ($raw) {
        parse_str($raw, $input);
    }
    if (!is_array($input)) $input = [];

    $pump     = $input['pump']      ?? '';
    $state    = $input['state']     ?? '';
    $duration = $input['duration_s'] ?? 0;
    $seen     = $input['device_seen'] ?? '';

    if ($pump !== 'dithane') { errorResponse('Pompa tidak didukung.', 400); }
    if (!in_array($state, ['started', 'completed'], true)) {
        errorResponse('State tidak didukung (started/completed).', 400);
    }
    /* completed WAJIB punya durasi aktual 1..3600 s; started boleh 0. */
    if ($state === 'completed' &&
        !filter_var($duration, FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1, 'max_range' => 3600]])) {
        errorResponse('duration_s harus bulat 1..3600 untuk completed.', 400);
    }
    /* Sanitasi waktu perangkat (dipakai untuk dedup, bukan keputusan). */
    $seen = preg_replace('/[^0-9\-\s:]/', '',
            mb_substr(is_string($seen) ? trim($seen) : '', 0, 32));

    /* Waktu eksekusi berdasarkan SERVER (Asia/Jakarta), bukan jam
       perangkat — sama seperti sensor_readings yang memakai
       CURRENT_TIMESTAMP. Waktu perangkat hanya pelengkap dedup.       */
    $clock = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
    $dedupKey = ($seen !== '')
        ? 'perangkat ' . $seen
        : 'server ' . $clock->format('Y-m-d H:i') . ' (tanpa waktu perangkat)';

    $label = [
        'started'   => 'Penyemprotan Dithane dimulai',
        'completed' => 'Penyemprotan Dithane selesai',
    ][$state];
    $message = [
        'started'   => "Pompa Dithane mulai menyala ({$dedupKey}).",
        'completed' => "Penyemprotan selesai; durasi aktual "
                     . (int)$duration . " detik ({$dedupKey}).",
    ][$state];
    $tone     = $state === 'started' ? 'info' : 'success';
    $note     = $state === 'completed'
        ? "Eksekusi oleh ESP32; durasi aktual " . (int)$duration . " detik; laporan {$dedupKey}."
        : "Eksekusi oleh ESP32; laporan {$dedupKey}.";
    $valueStr = $state === 'completed'
        ? (string)(int)$duration . 's'
        : (string)(int)$duration . 's (mulai)';

    $pdo = getDBConnection();
    $lock = measurementLock($pdo);
    try {
        /* DEDUP — satu laporan per (label, message): retry tidak ganda. */
        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications WHERE label = ? AND message = ?"
        );
        $q->execute([$label, $message]);
        if ((int)$q->fetchColumn() > 0) {
            successResponse(['dedup' => true], 'Notifikasi identik sudah dikirim');
        }

        /* 1) Notifikasi web (dipakai dashboard sebagai pusat
              penerimaan notifikasi sesuai spesifikasi).                  */
        $n = $pdo->prepare(
            "INSERT INTO notifications (label, message, tone, href)
             VALUES (?, ?, ?, 'history.php')"
        );
        $n->execute([$label, $message, $tone]);

        /* 2) Log bukti eksekusi HANYA saat "completed" — riwayat
              eksekusi aktual tetap dipisah dari dithane_schedules.        */
        if ($state === 'completed') {
            $dayName = $clock->format('l');       // nama hari, style dashboard
            $logTime = $clock->format('H.i');     // format log_time dashboard
            $m = $pdo->prepare(
                "INSERT INTO monitoring_logs (type, day_name, log_time, value, status, note)
                 VALUES ('dithane', ?, ?, ?, 'Selesai', ?)"
            );
            $m->execute([
                mb_substr($dayName, 0, 20),
                $logTime,
                mb_substr($valueStr, 0, 20),
                $note,
            ]);
        }

        successResponse([
            'state'    => $state,
            'dedup'    => false,
            'notified' => true,
            /* Waktu server (WIB) saat laporan diproses — perangkat
               memakainya sebagai anchor "penyemprotan selesai terakhir"
               sehingga perhitungan interval tetap akurat meski ESP
               restart / NVS hilang.                                  */
            'last_completed_at' => $state === 'completed'
                ? $clock->format('Y-m-d H:i:s')
                : null,
        ], 'Laporan perangkat diproses');
    } finally { measurementUnlock($pdo, $lock); }
} catch (InvalidArgumentException $e) {
    errorResponse($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('Device report: ' . $e->getMessage());
    errorResponse('Gagal mencatat laporan perangkat.', 500);
}
