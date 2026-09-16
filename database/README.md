# Database Hydrotech

Database `monitoring_sensor` memiliki **8 tabel, 57 kolom, dan 7 foreign key**, sesuai ERD dengan sensor.

| Tabel | Isi |
|---|---|
| `users` | Akun pengguna |
| `settings` | Konfigurasi PPM, pH, Dithane; pengguna yang terakhir mengubah |
| `profile` | Informasi pemilik/tim; pengguna yang terakhir mengubah |
| `sensors` | Identitas, jenis, satuan, status, lokasi perangkat |
| `sensor_readings` | Satu nilai dan waktu pembacaan dari satu sensor |
| `monitoring_logs` | Catatan per parameter, dengan sumber pembacaan opsional |
| `history_logs` | Riwayat per jenis pengaturan |
| `notifications` | Notifikasi dengan sumber pembacaan opsional |

`sensors.id` adalah identitas perangkat. `sensor_readings.id` adalah identitas catatan pengukuran. Kolom `sensor_readings.sensor_id` wajib mengacu ke perangkat yang ada. Satu sensor dapat memiliki nol atau banyak pembacaan. Hasil pH dan PPM disimpan pada dua baris terpisah.

## Instalasi baru

PHP harus mempunyai driver `pdo_mysql`; server MySQL harus aktif.

```sh
php database/install.php
php -S localhost:8000
```

Installer hanya berjalan melalui CLI dan menolak database yang sudah memiliki tabel. Installer membuat dua sensor simulasi, 16 contoh pembacaan, serta data awal tabel lain. Akun demo: `hydrotech` / `Admin123`.

## Memperbarui database tujuh tabel

```sh
php database/migrate_sensors.php
```

Migrasi membuat cadangan SQL di `database/backups/`, lalu memecah setiap pembacaan lama menjadi hasil pH dan PPM dengan nilai dan waktu yang sama seperti asalnya. ID lama dipertahankan untuk pH; ID PPM menggunakan offset ID maksimum lama. Data akun, profil, pengaturan, notifikasi, monitoring, dan riwayat lama tidak direset.

Karena skema lama tidak mencatat identitas perangkat, hasil lama dikaitkan dengan dua perangkat berlabel **simulasi/data lama**, bukan dianggap berasal dari perangkat fisik tertentu. Migrasi memverifikasi setiap nilai dan waktu, mencadangkan sumber lama yang dikunci, kemudian menghapus tabel sementara. Database utama tetap memiliki delapan tabel. Migrasi dapat dijalankan ulang tanpa menggandakan pembacaan; setiap eksekusi membuat cadangan baru.

Hentikan aktivitas penulisan aplikasi saat pemeliharaan. Migrasi mengunci tabel pembacaan ketika konversi, tetapi perubahan struktur MySQL tidak dapat dibatalkan sebagai satu transaksi besar. Jika ditemukan tabel sementara dari proses yang terputus, migrasi berhenti agar tabel/cadangan dapat ditinjau, tanpa menghapusnya otomatis. Pembacaan lama dengan waktu NULL atau FK yang sudah mengarah ke struktur gabungan memerlukan peninjauan sebelum migrasi.

Cadangan tidak dimasukkan ke Git. Untuk pemulihan, pilih database tujuan kosong secara eksplisit sebelum mengimpor file; file cadangan tidak memilih database tujuan secara otomatis.

## Konfigurasi

Default pada `config/database.php`: host `localhost`, pengguna `root`, password kosong, database `monitoring_sensor`. Pengaturan dapat ditimpa melalui `HYDROTECH_DB_HOST`, `HYDROTECH_DB_USER`, `HYDROTECH_DB_PASS`, dan `HYDROTECH_DB_NAME`.

Jika PHP CLI melaporkan `could not find driver`, aktifkan ekstensi `pdo_mysql` pada PHP yang dipakai. Contoh menjalankan dengan ekstensi sementara:

```sh
php -d extension_dir="LOKASI_PHP/ext" -d extension=pdo_mysql database/migrate_sensors.php
```

## API sensor

Semua endpoint memerlukan sesi login.

- `GET api/sensor/list.php`: daftar perangkat beserta statusnya.
- `POST api/sensor/save.php`: `sensor_id` dan `value`, untuk satu hasil ukur.
- Endpoint simpan juga menerima pasangan `ph_value` dan `ppm_value`, dengan `ph_sensor_id` dan `ppm_sensor_id`. Tanpa ID, harus ada tepat satu perangkat aktif untuk masing-masing jenis; pemilihan perangkat ambigu ditolak.
- `GET api/sensor/latest.php`: 10 pembacaan terbaru dengan informasi perangkat. Filter opsional `sensor_id` atau `type=ph/ppm`.
- `GET api/dashboard/summary.php`: `latest_readings` berisi hasil terbaru per perangkat aktif. Field kompatibilitas `latest_reading` merangkum hasil terbaru per jenis, masing-masing dengan waktu dan ID perangkat tersendiri.
- `POST api/notifications/generate.php`: membuat notifikasi dari hasil terbaru masing-masing perangkat aktif.

Contoh body satu hasil:

```text
sensor_id=1&value=6.4
```

Contoh body pasangan:

```text
ph_sensor_id=1&ph_value=6.4&ppm_sensor_id=2&ppm_value=845
```

Respons simpan memuat `readings` dan `reading_ids`. Penyimpanan pembacaan, catatan monitoring, dan notifikasi peringatan dilakukan dalam satu transaksi. Seluruh pasangan dibatalkan jika satu nilai/perangkat tidak valid atau penyimpanan gagal. pH dibatasi 0–14; PPM tidak negatif dan harus muat pada DECIMAL(12,4). Perangkat nonaktif/rusak ditolak.

Dashboard tetap **simulasi**, bukan koneksi perangkat IoT fisik. Grafik mengirim nilai simulasi dengan ID perangkat dan memeriksa hasil penyimpanan. Tabel sensor tidak dengan sendirinya menghubungkan perangkat keras. Riwayat global lama tetap terpisah; penyimpanan sensor mengisi `monitoring_logs`, bukan otomatis `history_logs`.

## Relasi dan perilaku penghapusan

- Pengguna → pengaturan/profil: `updated_by` opsional, `ON DELETE SET NULL`.
- Pengaturan → monitoring/riwayat: FK `type` ke `settings.type` yang unik, `ON DELETE RESTRICT`.
- Sensor → pembacaan: FK wajib, `ON DELETE RESTRICT`.
- Pembacaan → monitoring/notifikasi: FK opsional, `ON DELETE RESTRICT`.

Semua kunci menggunakan `ON UPDATE RESTRICT`. Soft delete monitoring/riwayat tetap bekerja melalui `is_deleted`. Informasi pengubah terakhir bukan riwayat audit semua perubahan; status baca notifikasi masih global. Hubungan ke pengaturan menyimpan kategori, bukan versi batas historis.

## Pengujian

```sh
php tests/sensor_integration.php
python tests/sensor_http.py
```

Uji integrasi membutuhkan driver PDO MySQL dan akses membuat database sementara. Uji HTTP membutuhkan Python serta PHP pada PATH. Keduanya membuat database uji terpisah dan menghapus database uji tersebut setelah selesai; database aplikasi tidak digunakan untuk mutasi uji.

ERD terbaru tersedia pada `outputs/erd-hydrotech-sensor-crowfoot.*` dan `outputs/erd-hydrotech-sensor-chen.*`. Gambar/dokumen tujuh tabel terdahulu adalah versi lama.
