# Database Hydrotech - English naming

Struktur aktif: **7 tabel, tanpa foreign key**. Tabel dan kolom memakai bahasa Inggris. Primary key memakai nama spesifik agar mudah dibedakan di ERD phpMyAdmin.

| Tabel | Primary key | Fungsi |
|---|---|---|
| users | user_id | Akun login admin |
| settings | type (ENUM) | Konfigurasi rentang PPM/pH |
| sensor_readings | reading_id | Hasil pengukuran asli ESP (pH + PPM per baris) |
| dithane_schedules | schedule_id | Jadwal penyemprotan global (schedule_id = 1) |
| monitoring_logs | monitoring_id | Satu-satunya log aktivitas: dipakai dashboard, halaman pengaturan, dan halaman history |
| notifications | notification_id | Peringatan ambang batas |
| profile | profile_id | Identitas pemilik/tim (label-nilai) |

## Kolom

### users

`user_id`, `username`, `password`, `email`, `created_at`, `updated_at`.

### settings

`type` ENUM('ppm','ph') sebagai primary key, `config_json`, `updated_at`. Jadwal Dithane tidak disimpan di sini — tabelnya sendiri di dithane_schedules.

### sensor_readings

`reading_id`, `ph_value` (NULL boleh), `ppm_value` (NULL boleh), `recorded_at`. CHECK menjamin minimal satu nilai terisi, pH 0-14, PPM nonnegatif.

### dithane_schedules

`schedule_id` (CHECK = 1, satu baris global), `interval_hours`, `duration_seconds`, `start_time`, `updated_at`.

### monitoring_logs

`monitoring_id`, `type` ENUM('ppm','ph','dithane'), `day_name`, `log_time`, `value`, `status`, `note`, `reading_id` (opsional), `is_deleted`, `created_at`. Tanggal untuk halaman history diambil dari `DATE(created_at)`; halaman history menampilkan 500 log terbaru.

### notifications

`notification_id`, `label`, `message`, `tone` ENUM('warning','success','info'), `href`, `is_read`, `created_at`.

### profile

`profile_id`, `section` ENUM('owner','team'), `label`, `value`, `sort_order`.

## Relasi

Sengaja **tanpa foreign key**. Audit seluruh kode menunjukkan tidak ada query yang JOIN antar tabel; kategori memakai ENUM `type`, bukan relasi ke settings. `monitoring_logs.reading_id` hanyalah penunjung longgar (tanpa constraint) untuk ketertelusuran pengukuran, sehingga data sensor lama boleh diarsip/dihapus tanpa merusak log.

Tabel `history_logs` lama sudah digabung ke `monitoring_logs`: tabel tersebut tidak pernah terisi otomatis dan strukturnya nyaris sama, sehingga hanya satu sumber log yang dibutuhkan.

Integritas harian dijaga transaksi aplikasi (satu pengukuran = satu baris sensor_readings + log + notifikasi dalam satu COMMIT) dan CHECK constraint (`schedule_id = 1`, interval/durasi positif, minimal satu nilai sensor terisi).

## Data dan API

- ph_value DECIMAL(6,4), rentang 0-14; ppm_value INT nonnegatif, pecahan ditolak API.
- recorded_at adalah waktu pencatatan server.
- Jadwal: interval_hours, duration_seconds, start_time.
- HTTP tetap menerima ph_value/ppm_value, username/password, serta konfigurasi interval/duration/start.
- Endpoint simpan, ringkasan, pengaturan, profil, riwayat, monitoring, dan notifikasi menggunakan kolom fisik Inggris.
- Dashboard menampilkan data asli dari database (tanpa simulasi). Perangkat ESP mengirim pengukuran melalui `api/sensor/ingest.php` dengan API key dari `config/esp.php`; penggerak pompa belum terintegrasi. Jadwal tidak membuktikan pelaksanaan dan status baca notifikasi masih global.

## Instalasi dan migrasi

Database kosong: `php database/install.php`.

Skrip migrasi historis (migrate_sensors.php, migrate_measurements.php, migrate_ppm_int.php, migrate_column_names.php) hanya untuk database versi lama (7/8/9 tabel dengan kolom lama) dan tidak diperlukan untuk instalasi baru.

Database aplikasi bernama **hydrotech** (default di config/database.php). Nama lama `monitoring_sensor` sudah tidak dipakai; variabel lingkungan HYDROTECH_DB_NAME dapat mengganti nama bila diperlukan. phpMyAdmin perlu diarahkan ke server dan nama database yang sama.

## Contoh SQL

```sql
SELECT reading_id, ph_value, ppm_value, recorded_at
FROM sensor_readings ORDER BY recorded_at DESC, reading_id DESC LIMIT 5;
SELECT monitoring_id, day_name, log_time, value, status, DATE(created_at) AS log_date
FROM monitoring_logs WHERE type='ppm' AND is_deleted=0 ORDER BY created_at DESC;
```

## Pengujian dan ERD

`php tests/column_names_integration.php`, `php tests/measurement_integration.php`, dan `python tests/sensor_http.py` menggunakan database sementara. Dua test pertama menguji jalur migrasi skema lama (dengan fixture di tests/fixtures); sensor_http.py menguji alur HTTP aplikasi.

ERD di outputs/ dibuat sebelum penyederhanaan menjadi 7 tabel dan hanya relevan untuk database versi lama.
