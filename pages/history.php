<?php
require_once '../includes/auth_check.php';
requireAuth();
require_once '../config/database.php';

$pdo = getDBConnection();

/* DATA AKTIVITAS SISTEM: baris type='sistem' = SATU kesimpulan gabungan
   pH+PPM+Dithane per kejadian. Filter dieksekusi di database lewat
   api/history/list.php; tabel diisi ulang oleh js/history.js. */
$bulanIndo = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
?>
<!doctype html>
<html lang="id">
  <head>
    <!-- META SETUP: pengaturan dasar dokumen dan stylesheet halaman history. -->
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>History</title>
    <?php echo csrfMetaTag(); ?>
    <link rel="stylesheet" href="../css/history.css" />
  </head>
  <body>
    <div class="app">
      <div data-sidebar-root></div><script src="../js/sidebar.js"></script>

      <main class="main">
        <!-- TOPBAR: judul halaman, notifikasi, dan identitas admin. -->
        <header class="topbar">
          <div class="page-title">
            <h1>History</h1>
            <p>Selamat datang kembali, Admin</p>
          </div>
          <div class="admin">
            <div class="bell" aria-label="Notifikasi"></div>
            <div class="admin-card">
              <div class="admin-a">A</div>
              <div>
                <div class="admin-name">Admin</div>
                <div class="admin-email">admin@hydrotech.com</div>
              </div>
            </div>
          </div>
        </header>

        <div class="content">
          <!-- HISTORY WRAPPER: container utama untuk tabel history dan grafik monitoring. -->
          <section class="history-card">
            <!-- HISTORY HEADER: judul ringkas halaman dan tombol ekspor PDF. -->
            <div class="history-card-head">
              <div>
                <span class="history-kicker">Riwayat Monitoring</span>
                <h2>Data Aktivitas Sistem</h2>
                <p>
                  Kesimpulan gabungan kondisi pH, PPM, dan Dithane —
                  dapat difilter berdasarkan tanggal, jam, hari, bulan,
                  tahun, dan status.
                </p>
              </div>
              <button type="button" class="history-export" data-history-export>
                Ekspor PDF
              </button>
            </div>

            <!-- TABLE PANEL: area filter, tabel data history, empty state, dan pagination. -->
            <div class="history-table-panel">
              <!-- FILTER BAR: pencarian + rentang jam + hari, bulan, tahun, status. -->
              <div class="filter-bar history-filter">
                <input
                  data-history-search
                  type="search"
                  placeholder="Cari tanggal, jam, status, atau keterangan..." />
                <span class="filter-select history-time-range" aria-label="Rentang jam">
                  <input data-history-time-from type="time" aria-label="Jam mulai" />
                  <span>–</span>
                  <input data-history-time-to type="time" aria-label="Jam selesai" />
                </span>
                <span class="filter-select">
                  <select data-history-day aria-label="Filter hari">
                    <option value="">Semua Hari</option>
                    <option value="senin">Senin</option>
                    <option value="selasa">Selasa</option>
                    <option value="rabu">Rabu</option>
                    <option value="kamis">Kamis</option>
                    <option value="jumat">Jumat</option>
                    <option value="sabtu">Sabtu</option>
                    <option value="minggu">Minggu</option>
                  </select>
                </span>
                <span class="filter-select">
                  <select data-history-month aria-label="Filter bulan">
                    <option value="">Semua Bulan</option>
                    <option value="1">Januari</option>
                    <option value="2">Februari</option>
                    <option value="3">Maret</option>
                    <option value="4">April</option>
                    <option value="5">Mei</option>
                    <option value="6">Juni</option>
                    <option value="7">Juli</option>
                    <option value="8">Agustus</option>
                    <option value="9">September</option>
                    <option value="10">Oktober</option>
                    <option value="11">November</option>
                    <option value="12">Desember</option>
                  </select>
                </span>
                <span class="filter-select">
                  <select data-history-year aria-label="Filter tahun">
                    <option value="">Semua Tahun</option>
                  </select>
                </span>
                <span class="filter-select">
                  <select data-history-status aria-label="Filter status sistem">
                    <option value="">Semua Status</option>
                    <option value="Normal">Normal</option>
                    <option value="Perhatian">Perhatian</option>
                    <option value="Tidak Normal">Tidak Normal</option>
                  </select>
                </span>
                <button type="button" data-history-reset>Reset</button>
              </div>

              <!-- HISTORY TABLE: riwayat kesimpulan kondisi keseluruhan sistem. -->
              <div class="history-table-wrap">
                <table class="history-table">
                  <thead>
                    <tr>
                      <th>No</th>
                      <th>Hari</th>
                      <th>Tanggal</th>
                      <th>Jam</th>
                      <th>Status Sistem</th>
                      <th>Keterangan</th>
                      <th>Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php /* Baris diisi dinamis oleh js/history.js (data dari database,
                               filter dieksekusi di server). */ ?>
                  </tbody>
                </table>
                <div class="history-empty" data-history-empty>
                  Data tidak ditemukan.
                </div>
              </div>

              <!-- TABLE FOOTER: informasi jumlah data yang tampil dan tombol halaman. -->
              <div class="history-footer">
                <div class="showing" data-history-showing>
                  Menampilkan 0 data
                </div>
                <div class="pagination" data-history-pagination></div>
              </div>
            </div>

            <!-- CHART PANEL: grafik tren pH dan PPM beserta kontrol periode. -->
            <section
              class="history-chart-panel"
              aria-labelledby="history-chart-title">
              <div class="history-chart-head">
                <div>
                  <span class="history-kicker">Grafik Monitoring</span>
                  <h3 id="history-chart-title">History pH dan PPM</h3>
                  <p data-history-chart-period>
                    Tren monitoring pH dan PPM selama Mei 2026, lengkap dengan
                    hari, tanggal, dan jam pengukuran.
                  </p>
                </div>
                <div class="history-chart-side">
                  <!-- CHART CONTROLS: pilihan rentang waktu dan bulan grafik. -->
                  <div class="history-chart-controls">
                    <div
                      class="history-chart-range"
                      aria-label="Filter periode grafik">
                      <button
                        type="button"
                        data-history-chart-range="7"
                        aria-pressed="false">
                        1 Minggu
                      </button>
                      <button
                        type="button"
                        data-history-chart-range="14"
                        aria-pressed="false">
                        2 Minggu
                      </button>
                      <button
                        type="button"
                        class="active"
                        data-history-chart-range="month"
                        aria-pressed="true">
                        1 Bulan
                      </button>
                    </div>
                    <label class="history-chart-filter">
                      <span>Bulan Grafik</span>
                      <select
                        data-history-chart-month
                        aria-label="Filter bulan grafik">
                        <?php $chartMonth = (int) date('n') - 1; ?>
                        <?php foreach (['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'] as $monthIndex => $monthLabel): ?>
                        <option value="<?= $monthIndex ?>"<?= $monthIndex === $chartMonth ? ' selected' : '' ?>><?= $monthLabel ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                  </div>
                  <!-- CHART STATS: ringkasan rata-rata pH dan PPM dari grafik aktif. -->
                  <div class="history-chart-stats">
                    <div class="stat-ph">
                      <div class="mini-ico icon-ph" aria-hidden="true"></div>
                      <span>Rata-rata pH</span>
                      <strong data-chart-average-ph>0</strong>
                      <small>Keasaman air</small>
                    </div>
                    <div class="stat-ppm">
                      <div class="mini-ico icon-ppm" aria-hidden="true"></div>
                      <span>Rata-rata PPM</span>
                      <strong data-chart-average-ppm>0</strong>
                      <small>Nutrisi larutan</small>
                    </div>
                  </div>
                </div>
              </div>

              <!-- CHART LEGEND: keterangan warna garis pH dan PPM. -->
              <div class="history-chart-legend" aria-label="Legenda grafik">
                <span><i class="legend-dot ph"></i>pH</span>
                <span><i class="legend-dot ppm"></i>PPM</span>
              </div>

              <!-- CHART CANVAS: tempat Chart.js menggambar grafik history. -->
              <div class="history-chart-wrap">
                <canvas
                  id="historyLineChart"
                  aria-label="Diagram garis history pH dan PPM selama satu bulan"
                  role="img"></canvas>
              </div>
            </section>
          </section>
        </div>
      </main>
    </div>

    <!-- PAGE SCRIPTS: library dialog, grafik, PDF, dan logika halaman history. -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../js/confirm-modal.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.4/dist/jspdf.plugin.autotable.min.js"></script>
    <script src="../js/history.js?v=4"></script>
  </body>
</html>
