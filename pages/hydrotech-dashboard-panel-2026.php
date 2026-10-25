<?php
require_once __DIR__.'/../includes/auth_check.php';
requireAuth();
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/sensors.php';
require_once __DIR__.'/../includes/dithane.php';

$pdo = getDBConnection();

/* SETTINGS: rentang PPM/pH aktif dari tabel settings. */
$settingRows = $pdo->query("SELECT type, config_json FROM settings WHERE type IN ('ppm','ph')")->fetchAll(PDO::FETCH_KEY_PAIR);
$ppmConfig = json_decode($settingRows['ppm'] ?? 'null', true) ?: [];
$phConfig = json_decode($settingRows['ph'] ?? 'null', true) ?: [];
$ppmRange = trim(($ppmConfig['min'] ?? '800') . ' - ' . ($ppmConfig['max'] ?? '1000'));
$phRange = trim(($phConfig['min'] ?? '5,5') . ' - ' . ($phConfig['max'] ?? '6,5'));

/* DITHANE: jadwal penyemprotan aktif (tampilan rencana, bukan bukti eksekusi). */
$dithane = readDithane($pdo);
$dithaneStart = $dithane['config']['start'] ?? null;
$dithaneInterval = $dithane['config']['interval'] ?? null;
$dithaneLabel = $dithaneStart ? str_replace('.', ':', (string)$dithaneStart) : '-';
$dithaneNote = $dithaneStart && $dithaneInterval
    ? "setiap {$dithaneInterval} jam, mulai pukul " . str_replace(':', '.', (string)$dithaneStart)
    : 'belum ada jadwal aktif';

/* LATEST READINGS: pengukuran asli terakhir dari perangkat.
   latestSensorReadings() berurutan terbaru-dulu — ambil entri PERTAMA
   tiap jenis (jangan menimpa, agar tidak tertukar dengan yang tertua). */
$latestReadings = latestSensorReadings($pdo);
$phLatest = null;
$ppmLatest = null;
foreach ($latestReadings as $reading) {
    if ($reading['type'] === 'ph' && !$phLatest) $phLatest = $reading;
    if ($reading['type'] === 'ppm' && !$ppmLatest) $ppmLatest = $reading;
}
$phNow = $phLatest ? (string)$phLatest['value'] : '-';
$ppmNow = $ppmLatest ? (string)$ppmLatest['value'] : '-';

/* RELATIVE TIME: teks "x menit lalu" untuk update terakhir. */
function relativeTimeId(?string $timestamp): string {
    if (!$timestamp) return 'belum ada data';
    $ts = strtotime($timestamp);
    if ($ts === false) return '-';
    $diff = time() - $ts;
    if ($diff < 0) {
        // Timestamp di masa depan = selisih jam perangkat/server;
        // hanya selisih kecil (di bawah 1 menit) dianggap "baru saja".
        return $diff > -60 ? 'baru saja' : '-';
    }
    if ($diff < 60) return 'baru saja';
    if ($diff < 3600) return floor($diff / 60) . ' menit lalu';
    if ($diff < 86400) return floor($diff / 3600) . ' jam lalu';
    return floor($diff / 86400) . ' hari lalu';
}
$lastUpdateAt = null;
foreach ([$phLatest, $ppmLatest] as $reading) {
    if ($reading && (!$lastUpdateAt || $reading['recorded_at'] > $lastUpdateAt)) {
        $lastUpdateAt = $reading['recorded_at'];
    }
}
$lastUpdateText = $lastUpdateAt ? relativeTimeId($lastUpdateAt) : 'belum ada data';

/* ACTIVITIES: log monitoring asli terbaru (sensor, nutrisi, pH, Dithane). */
$activities = $pdo->query(
    "SELECT type, day_name, log_time, value, status, note, created_at
     FROM monitoring_logs WHERE is_deleted = 0
     ORDER BY created_at DESC, monitoring_id DESC LIMIT 8"
)->fetchAll(PDO::FETCH_ASSOC);
$activityCount = count($activities);
function activityIcon(string $type): string {
    return ['ppm' => 'PPM', 'ph' => 'pH', 'dithane' => 'DT'][$type] ?? strtoupper(substr($type, 0, 2));
}
?>
<!doctype html>
<html lang="id">
  <head>
    <!-- META SETUP: pengaturan dasar dokumen dan stylesheet dashboard. -->
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Dashboard Hydrotech</title>
    <?php echo csrfMetaTag(); ?>
    <link rel="stylesheet" href="../css/hydrotech-dashboard-panel-2026.css" />
  </head>
  <body>
    <div class="app">
      <div data-sidebar-root></div><script src="../js/sidebar.js"></script>
      <main class="main">
        <!-- TOPBAR: judul halaman, notifikasi, dan data admin. -->
        <header class="topbar">
          <div class="page-title">
            <h1>Dashboard</h1>
            <p>Selamat datang kembali, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></p>
          </div>
          <div class="admin">
            <div class="bell" aria-label="Notifikasi"></div>
            <div class="admin-card">
              <div class="admin-a"><?php echo strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)); ?></div>
              <div>
                <div class="admin-name"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></div>
                <div class="admin-email">admin@hydrotech.com</div>
              </div>
            </div>
          </div>
        </header>
        <div class="content">
          <!-- DASHBOARD HERO: ringkasan status sistem dan update terakhir. -->
          <section class="dashboard-hero">
            <div>
              <span class="hero-kicker">Live Hydroponic Control</span>
              <h2>Monitoring tanaman berjalan stabil</h2>
              <p>
                Nutrisi, pH, dan penyemprotan Dithane berada dalam rentang aman
                untuk siklus hari ini.
              </p>
            </div>
            <div class="hero-metrics">
              <div>
                <small>Status Sistem</small>
                <b>Aktif</b>
              </div>
              <div>
                <small>Update Terakhir</small>
                <b data-last-update><?php echo htmlspecialchars($lastUpdateText); ?></b>
              </div>
            </div>
          </section>
          <!-- STATS SUMMARY: kartu angka utama untuk PPM, pH, dan Dithane. -->
          <section class="stats">
            <div class="stat stat-ppm">
              <div class="mini-ico icon-ppm" aria-hidden="true"></div>
              <div class="pill">DISETEL</div>
              <div class="stat-title">Rentang PPM</div>
              <div class="stat-num stat-range"><?php echo htmlspecialchars($ppmRange); ?></div>
              <div class="stat-note">Dari halaman Pengaturan PPM</div>
              <div class="bar"><span style="width: 100%"></span></div>
            </div>
            <div class="stat stat-ph">
              <div class="mini-ico icon-ph" aria-hidden="true"></div>
              <div class="pill">DISETEL</div>
              <div class="stat-title">Rentang pH</div>
              <div class="stat-num stat-range"><?php echo htmlspecialchars($phRange); ?></div>
              <div class="stat-note">Dari halaman Pengaturan pH</div>
              <div class="bar"><span style="width: 100%"></span></div>
            </div>
            <div class="stat stat-dithane">
              <div class="mini-ico icon-dithane" aria-hidden="true"></div>
              <div class="pill">TERJADWAL</div>
              <div class="stat-title">Penyemprotan Dithane</div>
              <div class="stat-num"><?php echo htmlspecialchars($dithaneLabel); ?></div>
              <div class="stat-note"><?php echo htmlspecialchars($dithaneNote); ?></div>
            </div>
          </section>
          <!-- DASHBOARD GRID: area grafik nutrisi dan riwayat aktivitas terbaru. -->
          <section class="dashboard-grid">
            <!-- CHART CARD: grafik perbandingan pH dan PPM hari ini. -->
            <div class="card chart-card">
              <div class="chart-head">
                <div>
                  <div class="section-title">Monitoring Nutrisi</div>
                  <p>Perbandingan pH dan PPM hari ini</p>
                </div>
                <div class="chart-legend">
                  <span><i class="legend-ph"></i>pH</span>
                  <span><i class="legend-ppm"></i>PPM</span>
                </div>
              </div>
              <div class="chart-summary">
                <div>
                  <small>pH saat ini</small>
                  <b data-current-ph><?php echo htmlspecialchars($phNow); ?></b>
                </div>
                <div>
                  <small>PPM saat ini</small>
                  <b data-current-ppm><?php echo htmlspecialchars($ppmNow); ?></b>
                </div>
              </div>
              <div class="dashboard-chart-wrap">
                <canvas
                  class="chart"
                  id="dashboardNutrientChart"
                  aria-label="Grafik pH dan PPM"
                  role="img"></canvas>
              </div>
            </div>
            <!-- ACTIVITY CARD: daftar aktivitas sistem dengan filter kategori. -->
            <div
              class="card activity dashboard-activity-card"
              data-activity-section>
              <div class="activity-head">
                <div>
                  <div class="section-title">Riwayat Aktivitas Sistem</div>
                  <p>
                    Aktivitas terbaru dari sensor, nutrisi, pH, dan jadwal
                    penyemprotan.
                  </p>
                </div>
                <div class="activity-filter-dropdown" data-activity-dropdown>
                  <button
                    class="activity-filter-toggle"
                    type="button"
                    data-activity-toggle
                    aria-expanded="false">
                    <span data-activity-label>Semua</span>
                    <i></i>
                  </button>
                  <div class="activity-filter-menu" data-activity-menu>
                    <button
                      class="active"
                      type="button"
                      data-activity-filter="all">
                      Semua
                    </button>
                    <button type="button" data-activity-filter="ppm">
                      PPM
                    </button>
                    <button type="button" data-activity-filter="ph">pH</button>
                    <button type="button" data-activity-filter="dithane">
                      Dithane
                    </button>
                  </div>
                </div>
              </div>
              <div class="activity-list">
                <?php if (!$activityCount): ?>
                <div class="activity-row" data-activity-type="all">
                  <span class="nicon">--</span>
                  <div>
                    <b>Belum ada aktivitas</b>
                    <small>Aktivitas akan muncul saat perangkat mengirim data.</small>
                  </div>
                  <span class="status">Kosong</span>
                  <span class="ago">-</span>
                </div>
                <?php else: ?>
                <?php foreach ($activities as $activity): ?>
                <?php
                    $activityStatus = (string)($activity['status'] ?? '');
                    $statusClass = in_array(strtolower($activityStatus), ['normal', 'berhasil'], true)
                        ? '' : (strtolower($activityStatus) === 'terjadwal' ? ' wait' : ' warn');
                ?>
                <div class="activity-row" data-activity-type="<?php echo htmlspecialchars($activity['type']); ?>">
                  <span class="nicon"><?php echo htmlspecialchars(activityIcon($activity['type'])); ?></span>
                  <div>
                    <b><?php echo htmlspecialchars(ucfirst($activity['type']) . ' - ' . $activity['day_name'] . ' ' . $activity['log_time']); ?></b>
                    <small><?php echo htmlspecialchars((string)($activity['note'] ?? '')); ?></small>
                  </div>
                  <span class="status<?php echo $statusClass; ?>"><?php echo htmlspecialchars($activityStatus ?: '-'); ?></span>
                  <span class="ago"><?php echo htmlspecialchars(relativeTimeId($activity['created_at'])); ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
              </div>
              <div class="activity-empty" data-activity-empty>
                Tidak ada aktivitas pada kategori ini.
              </div>
              <div class="activity-foot">
                <span data-activity-count>Menampilkan <?php echo $activityCount; ?> aktivitas</span>
              </div>
            </div>
          </section>
        </div>
      </main>
    </div>
    <!-- PAGE SCRIPTS: dialog, filter aktivitas, Chart.js, dan pembaca data live. -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../js/confirm-modal.js"></script>
    <script src="../js/dashboard-activity.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="../js/dashboard-live.js?v=2"></script>
  </body>
</html>
