# Database Hydrotech ? struktur terbaru

Satu database, **9 tabel, 60 kolom, 9 foreign key**. Struktur ini menggantikan rancangan 8 tabel dengan identitas perangkat.

| Tabel | Fungsi |
|---|---|
| users | Akun login |
| settings | Batas pH/PPM dan kategori log |
| profile | Informasi pemilik/tim |
| ph_readings | id, ph_value DECIMAL(6,4), recorded_at |
| ppm_readings | id, ppm_value INT, recorded_at |
| dithane_schedules | Satu jadwal berulang: interval_hours, duration_seconds, start_time, updated_by, created_at, updated_at |
| monitoring_logs | Catatan evaluasi; ph_reading_id atau ppm_reading_id opsional |
| history_logs | Riwayat berdasarkan kategori dan tanggal |
| notifications | Pesan; ph_reading_id atau ppm_reading_id opsional |

Tabel `sensors` dan `sensor_readings` tidak digunakan lagi. Sistem mengasumsikan satu sumber pengukuran untuk setiap jenis. Identitas/lokasi/status perangkat tidak dikelola. ID pembacaan hanya unik dalam tabelnya; gunakan pasangan jenis + ID untuk mengidentifikasi hasil.

## Bentuk data

- `ph_value`: angka desimal, rentang 0?14, tanpa satuan.
- `ppm_value`: bilangan bulat konsentrasi dalam ppm, 0 sampai 2147483647. Pecahan ditolak oleh API. Rentang normal tetap berasal dari settings.
- `recorded_at`: waktu pencatatan oleh server database, bukan otomatis waktu sampling ESP32.
- Empat angka desimal dipertahankan untuk pH; PPM disimpan sebagai INT.
- Jadwal Dithane berasal dari input pengguna, bukan pembacaan sensor: interval **jam**, durasi **detik**, waktu mulai **HH:MM**.

## Relasi

1. settings.updated_by ? users.id
2. profile.updated_by ? users.id
3. dithane_schedules.updated_by ? users.id
4. monitoring_logs.type ? settings.type (unik)
5. history_logs.type ? settings.type (unik)
6. monitoring_logs.ph_reading_id ? ph_readings.id
7. monitoring_logs.ppm_reading_id ? ppm_readings.id
8. notifications.ph_reading_id ? ph_readings.id
9. notifications.ppm_reading_id ? ppm_readings.id

Relasi pembacaan ke log/notifikasi berpola 1:N. Kedua kolom referensi tidak boleh terisi bersamaan. CHECK pada monitoring juga memastikan jenisnya cocok. Baris umum/lama boleh tidak memiliki referensi pembacaan. Penghapusan pembacaan yang masih dirujuk ditolak (RESTRICT). Penghapusan pengguna mengosongkan updated_by (SET NULL). Monitoring/riwayat masih menggunakan soft delete melalui is_deleted.

Jadwal memakai `id=1`, satu konfigurasi berulang sesuai UI yang ada; tidak menyimpan beberapa jadwal bertanggal atau bukti penyemprotan. FK ke pengguna tetap mengidentifikasi pengubah terakhir. Baris kategori `settings.type=dithane` dipertahankan untuk FK log lama, dengan config_json `{}`. Parameter jadwal hanya disimpan di dithane_schedules agar tidak ada dua sumber konfigurasi.

## Instalasi baru

Pastikan MySQL aktif dan PHP memiliki ekstensi pdo_mysql.

```sh
php database/install.php
php -S localhost:8000
```

Installer menolak database berisi tabel. Data awal: 8 contoh pH, 8 contoh PPM, satu jadwal berulang (48 jam, 10 detik, mulai 08.00), serta contoh data pendukung. Akun demo: hydrotech / Admin123.

Default koneksi ada di config/database.php: database monitoring_sensor. Gunakan HYDROTECH_DB_HOST, HYDROTECH_DB_USER, HYDROTECH_DB_PASS, HYDROTECH_DB_NAME untuk menimpa konfigurasi. Database hydrotech yang digunakan pada gambar juga telah dimigrasikan; dua database tetap terpisah, aplikasi hanya memakai nama yang dikonfigurasi.

## Migrasi database 8 tabel

Tutup dashboard dan hentikan aktivitas penulisan aplikasi selama pemeliharaan.

```sh
php database/migrate_measurements.php
```

Migrasi mencadangkan semua tabel ke database/backups, menyalin pembacaan sesuai jenisnya, mempertahankan ID/nilai/waktu, memetakan FK monitoring/notifikasi, dan memindahkan konfigurasi Dithane. Tabel lama baru dihapus setelah verifikasi. Identitas perangkat lama tetap tersedia dalam cadangan. Akun, profil, dan riwayat tidak direset. Pemanggilan ulang pada struktur lengkap tidak menggandakan data.

Untuk struktur lebih lama (7 tabel), jalankan migrate_sensors.php terlebih dahulu, lalu migrate_measurements.php. Migrator lama membaca snapshot skema lama dari tests/fixtures/schema_sensors.sql.

MySQL DDL tidak dibatalkan sebagai satu transaksi besar. Bila migrasi terputus dan tabel tujuan sudah ada, skrip berhenti untuk ditinjau; jangan menghapus sumber secara manual. Cadangan dapat diimpor ke database kosong yang dipilih secara eksplisit, kemudian periksa data dan arahkan konfigurasi aplikasi ke database pemulihan. Untuk menjalankan versi aplikasi lama, gunakan kode lama dengan database cadangan yang sesuai.

## API

Semua endpoint tetap memerlukan sesi login. Integrasi autentikasi perangkat ESP32 belum diterapkan.

- POST api/sensor/save.php: JSON atau form berisi ph_value dan/atau ppm_value.
- GET api/sensor/latest.php: 10 hasil terbaru; filter opsional type=ph atau type=ppm.
- GET api/sensor/list.php: metadata dua parameter pengukuran, bukan daftar perangkat.
- GET api/dashboard/summary.php: pembacaan terakhir setiap jenis, waktu, ID pembacaan, dan konfigurasi termasuk jadwal dari tabel baru.
- POST api/notifications/generate.php: notifikasi berdasarkan hasil terbaru.
- GET api/settings/get.php?type=dithane: membaca tabel jadwal.
- POST api/settings/update.php dan reset.php: menyimpan/reset tabel jadwal jika type=dithane.

Contoh JSON pengukuran:

```json
{"ph_value": 6.25, "ppm_value": 850}
```

Tidak lagi menerima sensor_id, ph_sensor_id, ppm_sensor_id, atau value generik. Setiap hasil respons memuat type dan id; ID dari tabel berbeda bisa sama. Nilai kosong, bukan angka, atau di luar batas ditolak. Pembacaan, monitoring, dan notifikasi disimpan dalam satu transaksi; kegagalan salah satu membatalkan seluruh pasangan.

API jadwal tetap menerima form `type=dithane` dan `config` berupa JSON:

```json
{"interval":"48","duration":"10","start":"08.00"}
```

Interval dan durasi wajib bilangan bulat positif, waktu mulai 00:00?23:59. Nama kontrak API dipertahankan agar UI tetap bekerja; kolom database menggunakan nama dengan satuan eksplisit.

## Batasan

Dashboard masih menghasilkan nilai simulasi JavaScript, kemudian mengirim JSON ke backend. Belum ada koneksi ESP32 fisik, penggerak penyemprotan otomatis, atau catatan pelaksanaan jadwal. Penyimpanan pembacaan mengisi monitoring, bukan otomatis history_logs. Status baca notifikasi masih global. ERD dan dokumen presentasi lama dengan 8 tabel merupakan versi sebelum perubahan ini.

## Pengujian

```sh
php tests/measurement_integration.php
python tests/http_measurements.py
```

Pengujian memakai database sementara dan menghapus hanya database uji tersebut. Mencakup migrasi dengan collation berbeda, nilai/waktu/ID dan FK, backup, pemanggilan ulang, validasi, rollback, login, halaman web, JSON, jadwal edit/reset, dan notifikasi. PHP harus dapat memuat pdo_mysql.

## Perubahan PPM ke INT

Jalankan `php database/migrate_ppm_int.php` untuk database sembilan tabel yang PPM-nya masih DECIMAL. Skrip membuat backup lengkap, memeriksa nilai bulat dan rentang INT, lalu mengubah tipe tanpa mengubah ID, waktu, atau relasi. Jika ada pecahan, migrasi berhenti tanpa membulatkan diam-diam. Hentikan penulisan aplikasi selama migrasi.
