# Hydrotech

Dashboard monitoring hidroponik berbasis PHP, MySQL, dan JavaScript.

Database memiliki **7 tabel tanpa foreign key**: hasil pengukuran pH + PPM digabung pada tabel `sensor_readings` (satu baris per pengukuran), konfigurasi pada `settings`, dan `monitoring_logs` sebagai satu-satunya log aktivitas (dipakai dashboard, halaman pengaturan, dan halaman history).

Lihat [panduan database, instalasi, migrasi, dan pengujian](database/README.md).

- Instalasi baru: `php database/install.php`
- Database lama 8 tabel: `php database/migrate_measurements.php`, lalu `php database/migrate_column_names.php`
- Menjalankan aplikasi: `php -S localhost:8000`

PHP harus mengaktifkan ekstensi `pdo_mysql`. Dashboard menampilkan data sensor asli dari database tanpa data simulasi: perangkat ESP mengirim pengukuran pH/PPM ke `api/sensor/ingest.php` menggunakan API key dari `config/esp.php` (header `X-API-Key`).

`README.txt` dan diagram tujuh tabel terdahulu merupakan dokumentasi versi lama.

Penamaan ID/kolom kini menggunakan bahasa Inggris dengan ID spesifik; lihat [dokumentasi database](database/README.md). Database sembilan tabel dengan nama lama diperbarui menggunakan `php database/migrate_column_names.php`.
