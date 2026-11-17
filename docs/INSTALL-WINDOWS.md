# Panduan Instalasi HydroTech di Windows (Laragon)

Untuk anggota tim yang memakai Windows. Dashboard + database berjalan penuh
di Windows; bagian MQTT (realtime) punya dua opsi — lihat Bagian D.

---

## A. Persiapan

| Kebutuhan | Catatan |
|---|---|
| [Laragon](https://laragon.org) | menyediakan MySQL + HeidiSQL; aktifkan MySQL saja |
| PHP 8.1+ | Laragon default sudah memilikinya (cek `php -v` di terminal Laragon) |
| Ekstensi `pdo_mysql` | aktif secara default di Laragon (`php -m` untuk memeriksa) |
| Git (opsional) | untuk clone repo |

Tidak perlu Composer dan tidak ada dependensi lain.

## B. Kode + Database

1. Salin/clone proyek ke dalam folder `www` Laragon:
   ```
   C:\laragon\www\hydrotech
   ```
2. Buka **Terminal Laragon**, pastikan MySQL aktif (tombol Start All), lalu buat
   database dan user — cara paling mudah agar `config/database.php` TIDAK perlu
   diubah, samakan dengan default proyek (user `Jhon`, password `#Jhon45`):
   ```sql
   -- jalankan di HeidiSQL / mysql.exe
   CREATE DATABASE hydrotech CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'Jhon'@'localhost' IDENTIFIED BY '#Jhon45';
   GRANT ALL PRIVILEGES ON hydrotech.* TO 'Jhon'@'localhost';
   FLUSH PRIVILEGES;
   ```
   (Alternatif tanpa membuat user: set environment variable
   `HYDROTECH_DB_USER` dan `HYDROTECH_DB_PASS` sebelum menjalankan PHP.)
3. Instal skema + data awal **dari folder proyek**:
   ```
   cd C:\laragon\www\hydrotech
   php database\install.php
   php database\migrate_pump_control.php
   ```
   `install.php` menolak dijalankan dua kali pada database yang sudah terisi —
   itu perilaku aman, bukan error.
4. Buat `config\mqtt_auth.local.php` (lihat Bagian D) — file ini di-gitignore,
   setiap orang membuat sendiri.

## C. Menjalankan Dashboard (web + database)

```
cd C:\laragon\www\hydrotech
php -S localhost:8000
```

Buka `http://localhost:8000/pages/hydrotech-dashboard-panel-2026.php`
Login: **hydrotech / Admin123**.

Semua fitur non-realtime bekerja: login, pengaturan pH/PPM/Dithane, riwayat,
notifikasi, ekspor, dsb. Data sensor lama (hasil migrasi/backup) tampil normal.

## D. Realtime MQTT — pilih salah satu

Kontrol pompa manual, sensor 2 detik, dan push konfigurasi membutuhkan
broker + worker. **Opsi 1 direkomendasikan** untuk kerja tim.

### Opsi 1 — Terhubung ke broker di laptop Fedora (tidak ada yang diinstal)
Sudah ada broker yang berjalan di laptop Fedora (service `mosquitto`).
1. Minta isi `config/mqtt_auth.local.php` dari yang punya laptop Fedora
   (JANGAN lewat repo — file itu memang tidak ikut git).
2. Ubah satu baris di `config\mqtt_auth.local.php` milikmu:
   ```php
   $MQTT_HOST = "coba-coba.local";   // bukan 127.0.0.1 — broker di Fedora
   ```
   (Windows 10/11 sudah mampu meresolusi `.local`/mDNS di jaringan yang sama.)
3. Jalankan worker di terminal terpisah:
   ```
   php bin\mqtt_subscriber.php
   ```
   Biarkan terminal terbuka selama sesi kerja. Keluar proses = data realtime
   berhenti; jalankan lagi. (Untuk permanen: daftarkan sebagai service dengan
   NSSM, atau cukup jalankan manual saat dibutuhkan.)

### Opsi 2 — Broker sendiri di Windows (jarang diperlukan)
Pasang [Mosquitto for Windows](https://mosquitto.org/download/), salin
`deploy\mosquitto\mosquitto.conf` + `deploy\mosquitto\acl` ke `C:\Program Files\mosquitto\`
(ubah `listener 1883 0.0.0.0` → `listener 1883 127.0.0.1` agar lokal saja),
buat password file:
```
mosquitto_passwd -c "C:\Program Files\mosquitto\passwd" esp32
mosquitto_passwd -b "C:\Program Files\mosquitto\passwd" phpworker <password>
net start mosquitto
```
`$MQTT_HOST` tetap `127.0.0.1`. Konsekuensinya: ESP32 di firmware tertunjuk
ke broker Fedora (`coba-coba.local`) — kalau demo dari Windows, koordinasikan
dengan tim; jangan diubah asal-asalan (firmware perlu re-kompile jika ganti host).

## E. Hal yang perlu diingat

- **ESP32 hanya menunjuk ke satu backend.** Firmware saat ini tersambung ke
  broker Fedora (mDNS `coba-coba.local`) dan kredensial MQTT di-set saat
  kompile (`MQTT_PASS` di blok konfigurasi). Kerja web di Windows tidak
  mengganggu itu, tapi data sensor baru hanya mengalir ke backend yang
  ditunjuk perangkat.
- `config/mqtt_auth.local.php` dan `config/esp_key.txt` **tidak ikut git** —
  itu disengaja (kredensial). Bagikan lewat pesan pribadi tim.
- Portal setup ESP32 (AP `hydrotech`) hanya menanyakan WiFi — tidak ada lagi
  kolom API Key.
- Kalau server PHP dijalankan dengan `php -S 0.0.0.0:8000`, dashboard bisa
  dibuka dari HP/PC lain di WiFi yang sama lewat IP Windows (`ipconfig`).

## F. Ringkasan cepat

```
1. Clone  -> C:\laragon\www\hydrotech
2. MySQL  -> buat db + user (samakan dengan config default)
3. php database\install.php
4. php database\migrate_pump_control.php
5. isi config\mqtt_auth.local.php (minta ke tim)
6. php -S localhost:8000            (dashboard)
7. php bin\mqtt_subscriber.php      (realtime; terminal terpisah)
```
