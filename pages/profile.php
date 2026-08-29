<?php
require_once __DIR__.'/../includes/auth_check.php';
requireAuth();
require_once __DIR__.'/../config/database.php';

$pdo = getDBConnection();
$stmt = $pdo->query("SELECT * FROM profile ORDER BY section, sort_order");
$profileRows = $stmt->fetchAll();
$owner = array_filter($profileRows, fn($r) => $r['section'] === 'owner');
$team = array_filter($profileRows, fn($r) => $r['section'] === 'team');

// Helper to get value by label
function getProfileVal($rows, $label) {
    foreach ($rows as $r) {
        if ($r['label'] === $label) return htmlspecialchars($r['value']);
    }
    return '';
}
?>
<!doctype html>
<html lang="id">
  <head>
    <!-- META SETUP: pengaturan dasar dokumen dan stylesheet halaman profile. -->
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Profile</title>
    <link rel="stylesheet" href="../css/profile.css" />
    <style>
      .edit-btn {
        background-color: #0d6efd;
        color: #fff;
        border: none;
        padding: 8px 16px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        margin-top: 10px;
      }
      .edit-btn:hover { background-color: #0b5ed7; }
      .editable-input {
        width: 100%;
        padding: 4px 8px;
        border: 1px solid #ccc;
        border-radius: 4px;
        display: none;
      }
      .edit-mode .editable-input { display: inline-block; }
      .edit-mode .editable-text { display: none; }

      /* Fix overlapping issues */
      .profile-hero {
        height: auto !important;
        max-height: none !important;
      }
      .profile-wrap {
        height: auto !important;
        min-height: calc(100vh - 64px);
        overflow: visible !important;
      }
      .main:has(.profile-wrap) {
        height: auto !important;
        min-height: 100vh !important;
        overflow: visible !important;
      }
    </style>
  </head>
  <body>
    <div class="app">
      <div data-sidebar-root></div><script src="../js/sidebar.js"></script>

      <main class="main">
        <!-- TOPBAR: judul halaman, notifikasi, dan identitas admin. -->
        <header class="topbar">
          <div class="page-title">
            <h1>Profile</h1>
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

        <div class="profile-wrap">
          <!-- PROFILE HERO: pengenalan singkat sistem Hydrotech. -->
          <section class="profile-hero">
            <div class="profile-hero-copy">
              <span class="profile-kicker">SMART HYDROCARE</span>
              <h2>Dashboard IoT Hydrotech</h2>
              <p>
                Profil sistem monitoring hidroponik untuk memantau nutrisi, pH,
                penyemprotan, dan informasi pengelola dalam satu dashboard
                admin.
              </p>
              <button class="edit-btn" onclick="toggleEditMode()" id="editBtn">Edit Profile</button>
            </div>
            <div class="profile-hero-panel">
              <img src="../assets/Logo_hydrotech.png" alt="Logo Hydrotech" />
              <div>
                <strong>Hydrotech</strong>
                <span>IoT Monitoring System</span>
              </div>
            </div>
          </section>

          <!-- PROFILE CARDS: informasi pemilik dan tim pengembang. -->
          <section class="profile-cards" id="profileForm">
            <article class="info-card owner-card">
              <div class="profile-card-head">
                <div class="round-user">A</div>
                <div>
                  <span>Informasi Pengelola</span>
                  <h3>Data Pemilik</h3>
                </div>
              </div>
              <div class="info-line">
                <span class="li">N</span>
                <div>
                  <small>Nama Pemilik</small>
                  <b class="editable-text"><?php echo getProfileVal($owner, 'Nama Pemilik') ?: 'Edi Setiawan'; ?></b>
                  <input type="text" class="editable-input" name="owner_name" value="<?php echo getProfileVal($owner, 'Nama Pemilik') ?: 'Edi Setiawan'; ?>">
                </div>
              </div>
              <div class="info-line">
                <span class="li">@</span>
                <div>
                  <small>Email</small>
                  <b class="editable-text"><?php echo getProfileVal($owner, 'Email') ?: 'EdiSetiawan@gmail.com'; ?></b>
                  <input type="text" class="editable-input" name="owner_email" value="<?php echo getProfileVal($owner, 'Email') ?: 'EdiSetiawan@gmail.com'; ?>">
                </div>
              </div>
              <div class="info-line">
                <span class="li">WA</span>
                <div>
                  <small>WhatsApp</small>
                  <b class="editable-text"><?php echo getProfileVal($owner, 'WhatsApp') ?: '0856-4584-2028'; ?></b>
                  <input type="text" class="editable-input" name="owner_wa" value="<?php echo getProfileVal($owner, 'WhatsApp') ?: '0856-4584-2028'; ?>">
                </div>
              </div>
              <div class="info-line">
                <span class="li">L</span>
                <div>
                  <small>Lokasi</small>
                  <b class="editable-text"><?php echo getProfileVal($owner, 'Lokasi') ?: 'Jl. Raung, Gumuk Kerang, Ajung, Jember'; ?></b>
                  <input type="text" class="editable-input" name="owner_loc" value="<?php echo getProfileVal($owner, 'Lokasi') ?: 'Jl. Raung, Gumuk Kerang, Ajung, Jember'; ?>">
                </div>
              </div>
            </article>

            <article class="info-card team-card">
              <div class="profile-card-head">
                <div class="round-code">&lt;/&gt;</div>
                <div>
                  <span>Informasi Sistem</span>
                  <h3>Tim Pengembang</h3>
                </div>
              </div>
              <div class="info-line">
                <span class="li">T</span>
                <div>
                  <small>Nama Team</small>
                  <b class="editable-text"><?php echo getProfileVal($team, 'Nama Team') ?: 'Kelompok 1 (Hydrotech)'; ?></b>
                  <input type="text" class="editable-input" name="team_name" value="<?php echo getProfileVal($team, 'Nama Team') ?: 'Kelompok 1 (Hydrotech)'; ?>">
                </div>
              </div>
              <div class="info-line">
                <span class="li">R</span>
                <div>
                  <small>Peran</small>
                  <b class="editable-text"><?php echo getProfileVal($team, 'Peran') ?: 'Web Developer'; ?></b>
                  <input type="text" class="editable-input" name="team_role" value="<?php echo getProfileVal($team, 'Peran') ?: 'Web Developer'; ?>">
                </div>
              </div>
              <div class="info-line">
                <span class="li">K</span>
                <div>
                  <small>Kontak</small>
                  <b class="editable-text"><?php echo getProfileVal($team, 'Kontak') ?: '0815-1533-4689'; ?></b>
                  <input type="text" class="editable-input" name="team_contact" value="<?php echo getProfileVal($team, 'Kontak') ?: '0815-1533-4689'; ?>">
                </div>
              </div>
              <div class="info-line">
                <span class="li">A</span>
                <div>
                  <small>Alamat</small>
                  <b class="editable-text"><?php echo getProfileVal($team, 'Alamat') ?: 'Sumber sari Jember'; ?></b>
                  <input type="text" class="editable-input" name="team_address" value="<?php echo getProfileVal($team, 'Alamat') ?: 'Sumber sari Jember'; ?>">
                </div>
              </div>
            </article>
          </section>

          <!-- PROFILE SUMMARY: pesan penutup dan tombol kembali ke dashboard. -->
          <section class="profile-summary">
            <div>
              <span class="profile-kicker">Pesan Penutup</span>
              <h2>Terima kasih telah menggunakan Hydrotech</h2>
              <p>
                Semoga dashboard ini membantu proses monitoring dan pengelolaan
                sistem hidroponik menjadi lebih mudah, rapi, dan efisien.
              </p>
            </div>
            <a class="profile-action" href="hydrotech-dashboard-panel-2026.php"
              >Kembali ke Dashboard</a
            >
          </section>
        </div>
      </main>
    </div>

    <!-- PAGE SCRIPTS: library dialog dan fitur global dashboard. -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../js/confirm-modal.js"></script>
    <script>
      let isEditMode = false;
      async function toggleEditMode() {
        const form = document.getElementById('profileForm');
        const btn = document.getElementById('editBtn');
        
        if (!isEditMode) {
          form.classList.add('edit-mode');
          btn.textContent = 'Simpan Perubahan';
          isEditMode = true;
        } else {
          btn.textContent = 'Menyimpan...';
          btn.disabled = true;

          // Collect owner data
          const ownerData = [
            { label: 'Nama Pemilik', value: form.querySelector('[name="owner_name"]').value },
            { label: 'Email', value: form.querySelector('[name="owner_email"]').value },
            { label: 'WhatsApp', value: form.querySelector('[name="owner_wa"]').value },
            { label: 'Lokasi', value: form.querySelector('[name="owner_loc"]').value },
          ];

          // Collect team data
          const teamData = [
            { label: 'Nama Team', value: form.querySelector('[name="team_name"]').value },
            { label: 'Peran', value: form.querySelector('[name="team_role"]').value },
            { label: 'Kontak', value: form.querySelector('[name="team_contact"]').value },
            { label: 'Alamat', value: form.querySelector('[name="team_address"]').value },
          ];

          try {
            // Save owner section
            const ownerForm = new FormData();
            ownerForm.append('section', 'owner');
            ownerForm.append('data', JSON.stringify(ownerData));
            const ownerRes = await fetch('../api/profile/update.php', { method: 'POST', body: ownerForm });
            const ownerResult = await ownerRes.json();

            // Save team section
            const teamForm = new FormData();
            teamForm.append('section', 'team');
            teamForm.append('data', JSON.stringify(teamData));
            const teamRes = await fetch('../api/profile/update.php', { method: 'POST', body: teamForm });
            const teamResult = await teamRes.json();

            if (ownerResult.success && teamResult.success) {
              Swal.fire({icon: 'success', title: 'Berhasil', text: 'Profil berhasil diperbarui', timer: 1500, showConfirmButton: false});
              setTimeout(() => location.reload(), 1500);
            } else {
              Swal.fire({icon: 'error', title: 'Gagal', text: ownerResult.message || teamResult.message || 'Gagal memperbarui profil'});
              btn.textContent = 'Simpan Perubahan';
              btn.disabled = false;
            }
          } catch(err) {
            Swal.fire({icon: 'error', title: 'Error', text: 'Terjadi kesalahan jaringan'});
            btn.textContent = 'Simpan Perubahan';
            btn.disabled = false;
          }
        }
      }
    </script>
  </body>
</html>
