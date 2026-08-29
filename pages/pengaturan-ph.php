<?php
require_once '../includes/auth_check.php';
requireAuth();
require_once '../config/database.php';

$pdo = getDBConnection();

$stmtConfig = $pdo->prepare("SELECT config_json, updated_at FROM settings WHERE type = 'ph'");
$stmtConfig->execute();
$settings = $stmtConfig->fetch(PDO::FETCH_ASSOC);

$config = ['min' => 0, 'max' => 0];
$lastUpdated = 'Belum ada data';
if ($settings) {
    $decoded = json_decode($settings['config_json'], true);
    if (is_array($decoded)) {
        $config = array_merge($config, $decoded);
    }
    $lastUpdated = $settings['updated_at'];
}

$stmtLogs = $pdo->query("SELECT * FROM monitoring_logs WHERE type = 'ph' AND is_deleted = 0 ORDER BY created_at DESC");
$logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="id"><head><!-- META SETUP: pengaturan dasar dokumen, viewport, judul, dan stylesheet halaman. --><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pengaturan pH</title><link rel="stylesheet" href="../css/pengaturan-ph.css"></head><body><div class="app"><div data-sidebar-root></div><script src="../js/sidebar.js"></script><main class="main"><!-- TOPBAR: judul halaman, notifikasi, dan identitas admin. --><header class="topbar"><div class="page-title"><h1>Pengaturan pH</h1><p>Selamat datang kembali, Admin</p></div><div class="admin"><div class="bell" aria-label="Notifikasi"></div><div class="admin-card"><div class="admin-a">A</div><div><div class="admin-name">Admin</div><div class="admin-email">admin@hydrotech.com</div></div></div></div></header><div class="content"><!-- HERO SECTION: judul halaman, status sistem, dan tombol aksi utama. --><section class="hero"><div class="hero-left"><span class="hero-icon">⚙</span><div><h2>Setup Pengaturan pH</h2><p>Atur jadwal dan parameter Pengaturan pH agar sistem berjalan otomatis sesuai kebutuhan tanaman.</p></div></div><div class="hero-actions"><span>● Sistem Aktif</span><a class="edit-btn" href="input-ph.php">＋Edit data</a></div></section><!-- SUMMARY GRID: kartu ringkasan nilai pengaturan dan waktu update. --><section class="summary-grid"><div class="sum-card"><span class="si">♧</span><div><small>pH Minimum</small><b><?= htmlspecialchars($config['min'] ?? '') ?> pH</b></div></div><div class="sum-card"><span class="si">♧</span><div><small>pH Maksimum</small><b><?= htmlspecialchars($config['max'] ?? '') ?> pH</b></div></div><div class="sum-card"><span class="si">◴</span><div><small>Terakhir Pemberian pH</small><b>17.00 WIB</b></div></div><div class="sum-card"><span class="si">☰</span><div><small>Terakhir Diperbarui</small><b style="font-size:15px"><?= htmlspecialchars($lastUpdated) ?></b></div></div></section><!-- DATA TABLE: judul, filter status, tabel monitoring, dan pagination. --><h2 class="table-title">Data Monitoring Pengaturan pH</h2><p class="table-sub">Riwayat aktivitas Pengaturan pH pada sistem hidroponik.</p><div class="table-card"><div class="table-head"><span><i class="dot"></i>Tabel Data Pengaturan pH</span><div class="status-filter-wrap"><span>Status Pengaturan</span><select class="status-filter" aria-label="Filter Status Pengaturan"><option value="">Semua Status</option><option value="Normal">Normal</option><option value="Rendah">Rendah</option><option value="Tinggi">Tinggi</option></select></div></div><table><thead><tr><th>No</th><th>Hari</th><th>Jam</th><th>Pengaturan pH</th><th>Status Pengaturan pH</th><th>Keterangan</th><th>Aksi</th></tr></thead><tbody>
<?php $no = 1; foreach ($logs as $log): ?>
<tr data-row-id="<?= htmlspecialchars($log['id']) ?>">
<td><?= $no++ ?></td>
<td><?= htmlspecialchars($log['day_name'] ?? '') ?></td>
<td><?= htmlspecialchars($log['log_time'] ?? '') ?></td>
<td><span class="value-pill"><?= htmlspecialchars($log['value'] ?? '') ?></span></td>
<td>
<?php
$statusClass = 'normal';
if (strtolower($log['status'] ?? '') === 'rendah') $statusClass = 'low';
elseif (strtolower($log['status'] ?? '') === 'tinggi') $statusClass = 'high';
?>
<span class="badge <?= $statusClass ?>"><?= htmlspecialchars($log['status'] ?? '') ?></span>
</td>
<td><?= htmlspecialchars($log['note'] ?? '') ?></td>
<td class="trash"><button type="button" class="trash-btn" aria-label="Hapus data">&#128465;</button></td>
</tr>
<?php endforeach; ?>
</tbody></table><div class="showing">Menampilkan data</div><div class="pagination"><span>‹</span><span class="current">1</span><span>2</span><span>›</span></div></div></div></main></div><!-- PAGE SCRIPTS: library dialog dan logika halaman. --><script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script><script src="../js/confirm-modal.js"></script><script src="../js/settings-storage.js"></script></body></html>
