# Instalasi HydroTech di Windows — Cepat & Tanpa Konfigurasi

Cukup **dua program** diinstal, sisanya otomatis. Semua config default sudah
ikut git — tidak perlu membuat file apa pun.

---

## Langkah 1 — Install Laragon (dashboard + database)

1. Download & install: <https://laragon.org/download/> (pilih *Full*).
2. Buka Laragon → klik **Start All** (MySQL ikut menyala).
3. Selesai. Tidak perlu buat database/user manual — `php database\install.php`
   (langkah 3) otomatis memakai akun root default Laragon.

## Langkah 2 — Install Mosquitto (broker MQTT realtime)

1. Download installer: <https://mosquitto.org/download/> (Windows).
2. Setelah terpasang, **timpa config** dengan yang sudah disediakan proyek:
   ```
   copy "deploy\mosquitto\mosquitto-windows.conf" "C:\Program Files\mosquitto\mosquitto.conf"
   ```
   (jalankan di CMD dari folder proyek; ini broker lokal + anonymous, aman
   karena hanya melayani komputer sendiri)
3. Jalankan:
   ```
   net start mosquitto
   ```
   Kalau service belum terdaftar, jalankan dulu: `mosquitto install`.

## Langkah 3 — Siapkan database (1 perintah)

Dari folder proyek (misal `C:\laragon\www\hydrotech`):
```
php database\install.php
```
Ini otomatis membuat database + semua tabel + akun login + pengaturan awal.
Tidak perlu apa-apa lagi.

## Langkah 4 — Jalankan (2 terminal)

**Terminal 1 — dashboard:**
```
php -S localhost:8000
```

**Terminal 2 — realtime MQTT:**
```
php bin\mqtt_subscriber.php
```
Biarkan terminal 2 terbuka selama kerja (ini penerima data sensor dari broker).
Selesai — buka <http://localhost:8000>, login **hydrotech / Admin123**.

---

## Tanya-jawab cepat

| Masalah | Solusi |
|---|---|
| `php` tidak dikenali | Buka **Terminal Laragon** (Menu → Terminal), atau tambahkan PHP Laragon ke PATH |
| Tombol pompa: "Broker MQTT tidak merespons" | Mosquitto belum jalan → `net start mosquitto` |
| Data sensor tidak mengalir | Normal — data sensor datang dari ESP32 yang tertunjuk ke server tim (lihat di bawah) |
| Mau diakses dari HP/PC lain | `php -S 0.0.0.0:8000`, lalu buka IP komputermu (`ipconfig`) dari perangkat lain |

## Tentang ESP32 (pembagian tugas tim)

ESP32 tersambung ke **satu server yang jadi "otak"** — saat ini laptop Fedora
(broker + dashboard). Laptop Windows cukup untuk: pengembangan web, melihat
data, uji fitur. Kalau suatu saat demo dari Windows dan ingin ESP32 menunjuk
ke Windows: ganti `MQTT_HOST` di firmware menjadi IP Windows, isi
`MQTT_PASS` bebas (broker lokal anonymous), kompile ulang, dan jalankan
`php bin\mqtt_subscriber.php` di Windows.

## Catatan keamanan

Default lokal ini (anonymous broker, user DB root) **hanya untuk
pembangunan**. Broker produksi (laptop Fedora) memakai auth + ACL ketat
via `config/mqtt_auth.local.php` — file rahasia yang tidak ikut git.
