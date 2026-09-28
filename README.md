# Hydrotech

Dashboard monitoring hidroponik berbasis PHP, MySQL, dan JavaScript.

Database memiliki **9 tabel**, dengan hasil pH, hasil PPM, dan jadwal Dithane pada tabel terpisah. Identitas perangkat sensor tidak disimpan.

Lihat [panduan database, instalasi, migrasi, dan pengujian](database/README.md).

- Instalasi baru: `php database/install.php`
- Database lama 8 tabel: `php database/migrate_measurements.php`
- Menjalankan aplikasi: `php -S localhost:8000`

PHP harus mengaktifkan ekstensi `pdo_mysql`. Dashboard saat ini masih menghasilkan data simulasi.

`README.txt` dan diagram tujuh tabel terdahulu merupakan dokumentasi versi lama.
