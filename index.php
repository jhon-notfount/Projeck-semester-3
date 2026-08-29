<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: pages/hydrotech-dashboard-panel-2026.php");
    exit;
} else {
    header("Location: pages/login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
  <head>
    <!-- META SETUP: pengaturan karakter dan redirect otomatis ke halaman login. -->
    <meta charset="UTF-8" />
    <meta http-equiv="refresh" content="0; url=pages/login.php" />
    <title>Hydrotech</title>
  </head>
  <body>
    <!-- FALLBACK LINK: tautan manual jika redirect otomatis belum berjalan. -->
    <a href="pages/login.php">Buka Hydrotech</a>
  </body>
</html>
