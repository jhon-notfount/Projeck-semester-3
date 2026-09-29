# Database Hydrotech - penamaan Indonesia

Struktur aktif: **9 tabel, 60 kolom, 9 foreign key**. Nama tabel mengikuti ERD sebelumnya; nama ID dan kolom menggunakan bahasa Indonesia. Perubahan hanya penamaan, bukan perubahan nilai data, tipe, atau target hubungan.

## Tabel dan kolom

### users

`id_pengguna`, `nama_pengguna`, `kata_sandi`, `email`, `peran`, `dibuat_pada`, `diperbarui_pada`.

### settings

`id_pengaturan`, `jenis`, `konfigurasi_json`, `diperbarui_pada`, `diubah_oleh_id`.

### profile

`id_profil`, `bagian`, `label`, `isi`, `urutan`, `diubah_oleh_id`.

### ph_readings

`id_pembacaan_ph`, `nilai_ph`, `direkam_pada`.

### ppm_readings

`id_pembacaan_ppm`, `nilai_ppm`, `direkam_pada`.

### dithane_schedules

`id_jadwal_dithane`, `interval_jam`, `durasi_detik`, `jam_mulai`, `diubah_oleh_id`, `dibuat_pada`, `diperbarui_pada`.

### monitoring_logs

`id_monitoring`, `jenis`, `nama_hari`, `jam_catatan`, `nilai`, `status`, `keterangan`, `dihapus`, `dibuat_pada`, `id_pembacaan_ph`, `id_pembacaan_ppm`.

### history_logs

`id_riwayat`, `nama_hari`, `tanggal_catatan`, `jam_catatan`, `jenis`, `status`, `keterangan`, `dihapus`, `dibuat_pada`.

### notifications

`id_notifikasi`, `label`, `pesan`, `tingkat_pesan`, `tautan`, `sudah_dibaca`, `dibuat_pada`, `id_pembacaan_ph`, `id_pembacaan_ppm`.

## Relasi

| Foreign key | Referensi |
|---|---|
| settings.diubah_oleh_id | users.id_pengguna |
| profile.diubah_oleh_id | users.id_pengguna |
| dithane_schedules.diubah_oleh_id | users.id_pengguna |
| monitoring_logs.jenis | settings.jenis (UNIQUE) |
| history_logs.jenis | settings.jenis (UNIQUE) |
| monitoring_logs.id_pembacaan_ph | ph_readings.id_pembacaan_ph |
| monitoring_logs.id_pembacaan_ppm | ppm_readings.id_pembacaan_ppm |
| notifications.id_pembacaan_ph | ph_readings.id_pembacaan_ph |
| notifications.id_pembacaan_ppm | ppm_readings.id_pembacaan_ppm |

Pengaturan dan riwayat tetap terhubung melalui kategori jenis; penamaan tidak mengganti hubungan itu menjadi id_pengaturan. ID pengaturan adalah primary key, sedangkan jenis merupakan unique key yang dirujuk log.

Sumber pembacaan bersifat opsional (NULL). Kedua ID pembacaan tidak boleh terisi sekaligus. Jenis monitoring harus cocok dengan sumber pH/PPM. Satu pembacaan dapat dirujuk 0..N catatan. Jadwal Dithane dibatasi satu baris global dengan CHECK id_jadwal_dithane = 1; referensi pengubahnya dapat kosong.

Menghapus pengguna mengosongkan diubah_oleh_id (SET NULL). Penghapusan pembacaan atau kategori yang masih dirujuk ditolak (RESTRICT). Monitoring dan riwayat memakai dihapus untuk soft delete.

## Tipe dan satuan

- ph_readings.nilai_ph: DECIMAL(6,4), rentang 0-14, tanpa satuan fisik.
- ppm_readings.nilai_ppm: INT, bilangan bulat nonnegatif; API menolak pecahan.
- direkam_pada: waktu pencatatan server, bukan otomatis waktu sampling perangkat.
- Jadwal: interval_jam, durasi_detik, jam_mulai (TIME).
- Batas normal dari settings.konfigurasi_json; nilai di luar batas normal tetap disimpan untuk evaluasi.

## Web dan kontrak API

SQL di PHP menggunakan kolom baru. SELECT memakai alias untuk menjaga nama field respons, formulir, JavaScript, dan sesi yang sudah digunakan. Nama field API tidak harus sama dengan nama kolom fisik.

Contoh JSON tetap: `{"ph_value": 6.25, "ppm_value": 850}`. Backend menyimpannya ke nilai_ph dan nilai_ppm. Form login tetap username/password, tetapi SQL membaca nama_pengguna/kata_sandi. Respons jadwal tetap interval/duration/start, sedangkan kolom database interval_jam/durasi_detik/jam_mulai.

Endpoint utama: api/sensor/save.php, latest.php, api/settings/get.php, update.php, reset.php, api/dashboard/summary.php. Semua tetap memakai sesi login; belum autentikasi perangkat ESP32.

## Instalasi dan migrasi

Database baru: `php database/install.php`. Installer membaca schema.sql terbaru dan menolak database berisi tabel.

Database sembilan tabel dengan nama lama: `php database/migrate_column_names.php`.

Untuk versi lebih lama: migrate_sensors.php (7 ke 8 tabel), migrate_measurements.php (8 ke 9), migrate_ppm_int.php bila PPM masih desimal, kemudian migrate_column_names.php. Skrip historis memakai snapshot skema lama. Jangan menjalankan migrasi lama setelah penamaan sudah diubah.

Cadangan lengkap tersimpan di database/backups. Migrasi nama memverifikasi hash seluruh isi sembilan tabel dan semua foreign key. ID, waktu, hash password, nilai pengukuran, serta tipe data dipertahankan. Jika sudah sesuai, migrasi nama tidak melakukan perubahan lagi.

Hentikan aktivitas aplikasi saat migrasi. DDL lintas tabel bukan satu transaksi; bila terputus, tinjau kondisi dan cadangan sebelum melanjutkan. Untuk pemulihan, impor backup ke database kosong yang dipilih eksplisit dan gunakan versi kode yang sesuai.

Default konfigurasi aplikasi: monitoring_sensor. HYDROTECH_DB_NAME dapat memilih hydrotech. Keduanya database terpisah, meskipun struktur sama. Pastikan server dan database di phpMyAdmin cocok dengan aplikasi.

## Demonstrasi SQL

```sql
USE hydrotech;
SHOW TABLES;
SELECT id_pembacaan_ph, nilai_ph, direkam_pada
FROM ph_readings ORDER BY direkam_pada DESC LIMIT 5;
SELECT id_pembacaan_ppm, nilai_ppm, direkam_pada
FROM ppm_readings ORDER BY direkam_pada DESC LIMIT 5;
SELECT m.id_monitoring, p.nilai_ppm, m.status
FROM monitoring_logs m
JOIN ppm_readings p ON p.id_pembacaan_ppm=m.id_pembacaan_ppm
WHERE m.dihapus=0;
```

## Pengujian

- `php tests/column_names_integration.php`: backup, seluruh data, relasi, CHECK, pengulangan migrasi, pengukuran, dan jadwal.
- `php tests/measurement_integration.php`: jalur migrasi lama, validasi, serta rollback.
- `php tests/ppm_int_integration.php`: migrasi PPM integer.
- `python tests/http_measurements.py`: instalasi baru, login, halaman, pengukuran, profil, filter, penghapusan log, notifikasi, dan pengaturan/reset.

## Batas implementasi

Dashboard masih simulasi JavaScript. Penyimpanan jadwal belum mengendalikan pompa fisik. Riwayat tidak otomatis ditulis setiap pengukuran. Status baca notifikasi global; diubah_oleh_id hanya pengubah terakhir. Tanpa tabel perangkat, sumber beberapa sensor sejenis belum dibedakan.

Gambar ERD terbaru di outputs/ERD_Hydrotech_Terbaru_9_Tabel.png memakai nama baru. Dokumen Word lama perlu disesuaikan jika masih menyebut kolom id atau nama Inggris.
