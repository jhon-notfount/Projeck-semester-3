<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: hydrotech-dashboard-panel-2026.php");
    exit;
}
?>
<!doctype html>
<html lang="id">
  <head>
    <!-- META SETUP: pengaturan dasar dokumen, stylesheet login, dan icon library. -->
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Hydrotech Login</title>
    <link rel="stylesheet" href="../css/login.css" />
    <link
      rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  </head>
  <script>
    /* PASSWORD TOGGLE: menampilkan atau menyembunyikan isi password. */
    function togglePassword() {
      const passwordInput = document.getElementById("password");
      const eyeIcon = document.getElementById("eyeIcon");

      if (passwordInput.type === "password") {
        passwordInput.type = "text";
        eyeIcon.classList.remove("fa-eye");
        eyeIcon.classList.add("fa-eye-slash");
      } else {
        passwordInput.type = "password";
        eyeIcon.classList.remove("fa-eye-slash");
        eyeIcon.classList.add("fa-eye");
      }
    }

    /* LOGIN HANDLER: validasi akun admin lalu mengarahkan ke dashboard. */
    async function handleLogin(e) {
      e.preventDefault();
      const username = document.getElementById('username').value.trim();
      const password = document.getElementById('password').value.trim();
      if (!username || !password) {
        Swal.fire({icon:'warning',title:'Peringatan',text:'Username dan password harus diisi!'});
        return;
      }
      const btn = document.querySelector('.btn-login');
      btn.disabled = true;
      btn.textContent = 'Memproses...';
      try {
        const form = new FormData();
        form.append('username', username);
        form.append('password', password);
        const res = await fetch('../api/auth/login.php', {method:'POST',body:form});
        const data = await res.json();
        if (data.success) {
          Swal.fire({icon:'success',title:'Login Berhasil!',text:'Mengalihkan ke dashboard...',timer:1500,showConfirmButton:false});
          setTimeout(()=>{ window.location.href='hydrotech-dashboard-panel-2026.php'; },1500);
        } else {
          Swal.fire({icon:'error',title:'Login Gagal',text:data.message||'Username atau password salah!'});
          btn.disabled = false;
          btn.textContent = 'Masuk';
        }
      } catch(err) {
        Swal.fire({icon:'error',title:'Error',text:'Tidak dapat terhubung ke server'});
        btn.disabled = false;
        btn.textContent = 'Masuk';
      }
    }
  </script>
  <body class="login-page"><!-- LOGIN PAGE: halaman masuk admin dengan branding Hydrotech. -->
    <div class="login-overlay"></div>

    <!-- LOGIN LOGOS: logo institusi yang tampil di halaman login. -->
    <div class="login-logos">
      <div class="logo-box">
        <img
          src="../assets/Logo_Polije.png"
          alt="Logo Polije"
          class="logo-left" />
        <img src="../assets/Logo_TRK.png" alt="Logo TRK" class="logo-right" />
      </div>
    </div>
    <!-- LOGIN INTRO: teks pembuka dan logo utama Hydrotech. -->
    <section class="login-left">
      <div class="hydro-logo">
        <img src="../assets/Logo_hydrotech.png" alt="Logo Hydrotech" />
      </div>

      <h1 class="login-title">
        Kelola Hidroponik Anda<br />
        dengan Mudah
      </h1>

      <div class="title-line"></div>

      <p class="login-desc">
        Platform terpadu untuk monitoring dan pengelolaan<br />
        sistem hidroponik modern. Pantau nutrisi, pH, suhu, dan<br />
        kelembaban secara real-time.
      </p>
    </section>

    <!-- LOGIN FEATURES: ringkasan keunggulan sistem Hydrotech. -->
    <div class="login-features">
      <div class="feature">
        <span class="feature-icon">🌱</span>
        <p>Monitoring<br />Real-time</p>
      </div>

      <div class="feature">
        <span class="feature-icon">💧</span>
        <p>Kontrol Nutrisi<br />Mudah</p>
      </div>

      <div class="feature">
        <span class="feature-icon">📊</span>
        <p>Data Akurat<br />& Terintegrasi</p>
      </div>
    </div>

    <!-- LOGIN CARD: form username, password, pesan validasi, dan tombol masuk. -->
    <main class="login-card">
      <div class="brand">
        <span>Hydr</span>
        <img
          src="../assets/Logo_hydrotech.png"
          alt="Logo Hydrotech"
          class="globe-logo" />
        <span>tech</span>
      </div>

      <p class="sub">Masuk ke Dashboard Admin</p>

      <div class="avatar-big">
        <i class="fa-solid fa-user" aria-hidden="true"></i>
      </div>
      <form onsubmit="handleLogin(event)">
        <!-- USERNAME -->
        <label class="input-group">
          <span class="input-icon">
            <i class="fa-solid fa-user"></i>
          </span>

          <div class="input-content">
            <small>Username</small>
            <input
              type="text"
              id="username"
              placeholder="Masukkan username"
              autocomplete="username"
              required />
          </div>
        </label>

        <!-- PASSWORD -->
        <label class="input-group">
          <span class="input-icon">
            <i class="fa-solid fa-lock"></i>
          </span>

          <div class="input-content">
            <small>Password</small>
            <input
              type="password"
              id="password"
              placeholder="Masukkan password"
              autocomplete="current-password"
              required />
          </div>

          <button
            type="button"
            class="toggle-password"
            onclick="togglePassword()">
            <i class="fa-regular fa-eye" id="eyeIcon"></i>
          </button>
        </label>

        <div class="login-hint">
          <span><i class="fa-solid fa-shield-halved"></i></span>
          Akses khusus admin Hydrotech
        </div>

        <p class="login-message" id="loginMessage"></p>

        <button type="submit" class="btn-login">Masuk</button>
      </form>
    </main>
    <!-- PAGE SCRIPTS: library dialog dan fitur global dashboard. -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../js/confirm-modal.js"></script>
  </body>
</html>
