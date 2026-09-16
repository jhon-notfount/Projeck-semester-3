# ERD Hydrotech — Crow’s Foot

## Status dan ruang lingkup

Diagram ini adalah **rancangan relasional usulan**, bukan representasi bahwa FK sudah ada di database. Skema sumber memiliki 7 tabel, 45 atribut, dan belum memiliki FK. Rancangan mempertahankan seluruh atribut, tipe data, unique key, dan nullability yang ada, menambah 4 atribut opsional, serta mengusulkan 6 foreign key. Total: **7 tabel, 49 atribut, 6 relasi**.

Nama pada gambar diterjemahkan ke bahasa Indonesia sebagai alias dokumentasi. Nama tabel SQL ditampilkan di bawah judul tabel. SQL yang disertakan tetap memakai nama asli; tidak ada penggantian nama kolom yang diperlukan.

## Aturan notasi

- Kotak adalah tabel; setiap baris adalah satu atribut.
- PK adalah primary key, FK adalah foreign key, UQ adalah unique key.
- Dua garis tegak berarti tepat satu; lingkaran dan garis tegak berarti nol atau satu; lingkaran dan kaki gagak berarti nol atau banyak.
- Simbol pada ujung induk menyatakan berapa baris induk boleh dirujuk oleh satu baris anak. Simbol pada ujung anak menyatakan berapa anak boleh merujuk satu induk.
- Semua anak mempunyai PK `id` sendiri. Semua relasi non-identifying: FK tidak membentuk PK anak. Gambar menggunakan gaya Crow’s Foot dengan garis penghubung utuh seperti contoh; tidak memakai konvensi garis identifying/non-identifying milik IDEF1X.
- NULL “Ya” berarti atribut boleh kosong. Primary key tidak boleh NULL. Semua primary key menggunakan AUTO_INCREMENT sesuai sumber.
- Tanda `+` dan latar kuning pucat menunjukkan kolom baru. FK pada `jenis` menggunakan kolom yang sudah ada, tetapi constraint FK-nya baru diusulkan.
- Tidak ada relasi N:M yang membutuhkan tabel penghubung berdasarkan ruang lingkup saat ini.

## Daftar relasi

| Induk / kunci rujukan | Anak / FK | Induk per satu anak | Anak per satu induk | Makna |
|---|---|---|---|---|
| `users.id` | `settings.updated_by` | 0..1 | 0..N | pengubah terakhir pengaturan |
| `users.id` | `profile.updated_by` | 0..1 | 0..N | pengubah terakhir baris profil |
| `settings.type` | `monitoring_logs.type` | 1 | 0..N | pengelompokan jenis monitoring |
| `settings.type` | `history_logs.type` | 1 | 0..N | pengelompokan jenis riwayat |
| `sensor_readings.id` | `monitoring_logs.sensor_reading_id` | 0..1 | 0..N | sumber pembacaan monitoring |
| `sensor_readings.id` | `notifications.sensor_reading_id` | 0..1 | 0..N | sumber pembacaan notifikasi |

### Penjelasan kardinalitas dan integritas

- Satu pengguna dapat tercatat sebagai pengubah terakhir nol atau banyak pengaturan/baris profil. Satu baris pengaturan/profil boleh belum memiliki pengubah tercatat (data awal atau data lama), atau merujuk tepat satu pengguna. Ini bukan relasi kepemilikan dan bukan riwayat semua perubahan.
- Satu baris pengaturan mewakili satu jenis unik: ppm, ph, atau dithane. Satu jenis dapat dipakai banyak catatan monitoring/riwayat; setiap catatan wajib mempunyai satu jenis yang terdaftar. FK boleh merujuk unique key selain PK; karena itu `type` mengacu ke `settings.type`, bukan `settings.id`.
- Satu pembacaan memuat nilai pH dan PPM bersama-sama, dan boleh menjadi sumber nol atau banyak catatan monitoring/notifikasi. FK sumber boleh NULL untuk data lama, catatan manual, Dithane, dan notifikasi umum. Batas jumlah log atau notifikasi per pembacaan tidak diasumsikan.
- Penghapusan pengguna atau pembacaan secara fisik mengosongkan FK opsional melalui `ON DELETE SET NULL`. Penghapusan jenis pengaturan yang masih dipakai ditolak melalui `ON DELETE RESTRICT`. Semua pembaruan kunci memakai `ON UPDATE RESTRICT`.
- Soft delete `is_deleted` tetap berlaku pada monitoring dan riwayat; tidak menghapus referensi FK.
- Nilai `is_read` notifikasi masih global, bukan status baca per pengguna. Tabel profil masih informasi pemilik/tim, bukan profil akun individual.
- Riwayat dan monitoring tidak dibuat saling merujuk karena belum ada hubungan asal-data tersebut pada implementasi. Grafik sensor tidak otomatis mengisi kedua tabel ini hanya dengan menambah FK.
- FK ke jenis pengaturan menjamin kategori valid, bukan menyimpan konfigurasi batas pada saat pembacaan. Audit perubahan batas membutuhkan model versi konfigurasi tersendiri di pengembangan berikutnya.

## Pemetaan atribut lengkap

Tipe data di bawah mengikuti sumber, termasuk waktu yang masih VARCHAR dan nilai monitoring yang masih VARCHAR karena dapat memuat satuan. Tidak dilakukan perubahan tipe tanpa migrasi yang dirancang terpisah. Kolom TIMESTAMP yang tidak ditulis NOT NULL pada sumber ditandai boleh NULL.

### Pengguna (`users`)

| Atribut Indonesia | Kolom SQL asli | Tipe | Kunci | NULL | Status |
|---|---|---|---|---|---|
| id_pengguna | `id` | `INT` | PK | Tidak | Sudah ada |
| nama_pengguna | `username` | `VARCHAR(50)` | UQ | Tidak | Sudah ada |
| kata_sandi | `password` | `VARCHAR(255)` | — | Tidak | Sudah ada |
| email | `email` | `VARCHAR(100)` | — | Ya | Sudah ada |
| peran | `role` | `ENUM('admin','user')` | — | Tidak | Sudah ada |
| dibuat_pada | `created_at` | `TIMESTAMP` | — | Ya | Sudah ada |
| diperbarui_pada | `updated_at` | `TIMESTAMP` | — | Ya | Sudah ada |

### Pengaturan (`settings`)

| Atribut Indonesia | Kolom SQL asli | Tipe | Kunci | NULL | Status |
|---|---|---|---|---|---|
| id_pengaturan | `id` | `INT` | PK | Tidak | Sudah ada |
| jenis | `type` | `ENUM('ppm','ph','dithane')` | UQ | Tidak | Sudah ada |
| konfigurasi_json | `config_json` | `JSON` | — | Tidak | Sudah ada |
| diperbarui_pada | `updated_at` | `TIMESTAMP` | — | Ya | Sudah ada |
| diubah_oleh_id | `updated_by` | `INT` | FK | Ya | Kolom baru |

### Catatan Monitoring (`monitoring_logs`)

| Atribut Indonesia | Kolom SQL asli | Tipe | Kunci | NULL | Status |
|---|---|---|---|---|---|
| id_monitoring | `id` | `INT` | PK | Tidak | Sudah ada |
| jenis | `type` | `ENUM('ppm','ph','dithane')` | FK | Tidak | Kolom ada; FK usulan |
| nama_hari | `day_name` | `VARCHAR(20)` | — | Tidak | Sudah ada |
| jam_catatan | `log_time` | `VARCHAR(10)` | — | Tidak | Sudah ada |
| nilai | `value` | `VARCHAR(20)` | — | Tidak | Sudah ada |
| status | `status` | `VARCHAR(30)` | — | Tidak | Sudah ada |
| keterangan | `note` | `TEXT` | — | Ya | Sudah ada |
| dihapus | `is_deleted` | `TINYINT(1)` | — | Tidak | Sudah ada |
| dibuat_pada | `created_at` | `TIMESTAMP` | — | Ya | Sudah ada |
| id_pembacaan | `sensor_reading_id` | `INT` | FK | Ya | Kolom baru |

### Riwayat (`history_logs`)

| Atribut Indonesia | Kolom SQL asli | Tipe | Kunci | NULL | Status |
|---|---|---|---|---|---|
| id_riwayat | `id` | `INT` | PK | Tidak | Sudah ada |
| nama_hari | `day_name` | `VARCHAR(20)` | — | Tidak | Sudah ada |
| tanggal_catatan | `log_date` | `DATE` | — | Tidak | Sudah ada |
| jam_catatan | `log_time` | `VARCHAR(10)` | — | Tidak | Sudah ada |
| jenis | `type` | `ENUM('ppm','ph','dithane')` | FK | Tidak | Kolom ada; FK usulan |
| status | `status` | `VARCHAR(30)` | — | Tidak | Sudah ada |
| keterangan | `note` | `TEXT` | — | Ya | Sudah ada |
| dihapus | `is_deleted` | `TINYINT(1)` | — | Tidak | Sudah ada |
| dibuat_pada | `created_at` | `TIMESTAMP` | — | Ya | Sudah ada |

### Notifikasi (`notifications`)

| Atribut Indonesia | Kolom SQL asli | Tipe | Kunci | NULL | Status |
|---|---|---|---|---|---|
| id_notifikasi | `id` | `INT` | PK | Tidak | Sudah ada |
| label | `label` | `VARCHAR(100)` | — | Tidak | Sudah ada |
| pesan | `message` | `TEXT` | — | Tidak | Sudah ada |
| tingkat_pesan | `tone` | `ENUM('warning','success','info')` | — | Tidak | Sudah ada |
| tautan | `href` | `VARCHAR(255)` | — | Ya | Sudah ada |
| sudah_dibaca | `is_read` | `TINYINT(1)` | — | Tidak | Sudah ada |
| dibuat_pada | `created_at` | `TIMESTAMP` | — | Ya | Sudah ada |
| id_pembacaan | `sensor_reading_id` | `INT` | FK | Ya | Kolom baru |

### Profil (`profile`)

| Atribut Indonesia | Kolom SQL asli | Tipe | Kunci | NULL | Status |
|---|---|---|---|---|---|
| id_profil | `id` | `INT` | PK | Tidak | Sudah ada |
| bagian | `section` | `ENUM('owner','team')` | — | Tidak | Sudah ada |
| label | `label` | `VARCHAR(100)` | — | Tidak | Sudah ada |
| isi | `value` | `TEXT` | — | Tidak | Sudah ada |
| urutan | `sort_order` | `INT` | — | Tidak | Sudah ada |
| diubah_oleh_id | `updated_by` | `INT` | FK | Ya | Kolom baru |

### Pembacaan Sensor (`sensor_readings`)

| Atribut Indonesia | Kolom SQL asli | Tipe | Kunci | NULL | Status |
|---|---|---|---|---|---|
| id_pembacaan | `id` | `INT` | PK | Tidak | Sudah ada |
| nilai_ph | `ph_value` | `DECIMAL(5,2)` | — | Tidak | Sudah ada |
| nilai_ppm | `ppm_value` | `INT` | — | Tidak | Sudah ada |
| direkam_pada | `recorded_at` | `TIMESTAMP` | — | Ya | Sudah ada |

## Berkas dan penerapan

- `erd-hydrotech-crowfoot.png`: gambar resolusi 3400 × 2480.
- `erd-hydrotech-crowfoot.svg`: diagram vektor yang tetap tajam ketika diperbesar.
- `erd-hydrotech-crowfoot.pdf`: versi PDF.
- `usulan-relasi-hydrotech.sql`: migrasi penambahan empat kolom dan enam FK, **belum dijalankan**.

Sebelum menerapkan SQL, tinjau database tujuan, lakukan cadangan, dan pastikan hasil dua pemeriksaan data yatim kosong. Migrasi adalah skrip satu kali dan ALTER TABLE dapat melakukan implicit commit; pemeriksaan SELECT tidak otomatis menghentikan eksekusi lanjutan. Jalankan bagian pemeriksaan terlebih dahulu. Skema asli tetap menjadi sumber untuk default dan indeks yang sudah ada.

Sesudah migrasi, API pengaturan/profil harus mengisi `updated_by` dari sesi pengguna. API sensor harus meneruskan ID pembacaan ke notifikasi dan, apabila pengisian monitoring ditambahkan, ke monitoring. Tanpa penyesuaian API, kolom baru tetap NULL. Installer lama juga perlu ditinjau karena melakukan penghapusan dan pengisian ulang data. Semua langkah penerapan ini berada di luar pembuatan diagram dan belum dikerjakan.

## Verifikasi artefak

Generator memeriksa 7 tabel, 49 atribut, dan 6 FK; setiap target FK harus PK/UQ, tipe anak dan induk harus sama, serta kardinalitas harus cocok dengan nullability FK. SVG diperiksa sebagai XML. Ini pemeriksaan struktur artefak, bukan uji migrasi pada MySQL aktif.
