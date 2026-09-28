# 1. Gambaran umum sistem

**Hydrotech adalah aplikasi web untuk memantau kondisi hidroponik, terutama pH, PPM, serta pengaturan jadwal Dithane.**

Teknologi yang digunakan:

| Komponen | Teknologi | Fungsi |
|---|---|---|
| Tampilan web | HTML dan CSS | Menyusun dan mempercantik halaman |
| Interaksi web | JavaScript | Mengatur formulir, grafik, dan permintaan data |
| Grafik | Chart.js | Menampilkan grafik pH dan PPM |
| Backend | PHP | Menjalankan logika aplikasi dan mengakses database |
| Database | MySQL | Menyimpan data secara permanen |
| Koneksi database | PDO dengan driver pdo_mysql | Menghubungkan PHP dengan MySQL |
| Lingkungan pengembangan | Laragon | Menyediakan lingkungan untuk menjalankan layanan pengembangan lokal |
| Administrasi database | phpMyAdmin | Memeriksa tabel, menjalankan SQL, serta impor dan ekspor data |

Berdasarkan konfigurasi proyek, nama database default adalah **monitoring_sensor**, dengan **8 tabel, 57 kolom, dan 7 foreign key**.

Pada gambar database yang digunakan dalam diskusi, nama databasenya tertulis **hydrotech**. Jika database tersebut digunakan saat presentasi, konfigurasi aplikasi harus menunjuk ke nama yang sama. Nama aplikasi dan nama database tidak harus identik, tetapi nama database pada konfigurasi harus cocok dengan database yang benar-benar dituju.

## Kalimat pembuka presentasi

> “Hydrotech menggunakan database relasional MySQL untuk menyimpan akun pengguna, pengaturan, identitas sensor, hasil pembacaan, catatan monitoring, riwayat, notifikasi, serta profil. Aplikasi web mengakses database melalui backend PHP menggunakan PDO.”

# 2. Bagaimana database terhubung ke dashboard?

## A. Alur hubungan antarkomponen

Alur komunikasi aplikasi secara umum:

```text
Browser pengguna
      |
      | Permintaan HTTP
      v
Backend PHP
      |
      | PDO + driver pdo_mysql
      v
Server MySQL
      |
      | Hasil query
      v
Backend PHP
      |
      | HTML atau JSON
      v
Browser menampilkan data
```

Ada dua jalur komunikasi:

1. **Browser ke PHP**, menggunakan HTTP.
2. **PHP ke MySQL**, menggunakan koneksi database melalui PDO.

PDO adalah antarmuka akses database di PHP. Driver **pdo_mysql** memberikan kemampuan PDO untuk berkomunikasi dengan MySQL. Rujukan: [Dokumentasi resmi PDO MySQL](https://www.php.net/pdo-mysql).

## B. File penghubung database

Koneksi diatur pada file **config/database.php**. Berikut bentuk sederhana konfigurasi default proyek:

```php
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'monitoring_sensor';
$DB_CHARSET = 'utf8mb4';
```

Pada kode sebenarnya, nilai tersebut juga bisa diganti melalui **environment variable**, yaitu pengaturan lingkungan tanpa harus mengedit nilai default di file.

| Pengaturan | Penjelasan |
|---|---|
| localhost | Server database berada pada komputer yang menjalankan backend |
| root | Akun MySQL untuk mengakses database lokal |
| Password | Kata sandi akun MySQL |
| monitoring_sensor | Database yang akan digunakan |
| utf8mb4 | Pengodean karakter untuk menyimpan teks Unicode |

Koneksi dibuat menggunakan pola berikut:

```php
$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
$pdo = new PDO($dsn, $DB_USER, $DB_PASS);
```

Dalam proyek, koneksi dibungkus dalam fungsi **getDBConnection()**. Fungsi ini menggunakan variabel **static**, sehingga pemanggilan berulang **dalam satu proses permintaan PHP** menggunakan objek koneksi yang sama.

Ini bukan berarti seluruh pengguna aplikasi berbagi satu koneksi global selamanya.

## C. Syarat supaya koneksi berhasil

1. Server MySQL aktif.
2. PHP memiliki driver pdo_mysql.
3. Host dan akun MySQL benar.
4. Database yang dituju tersedia.
5. Struktur tabel sesuai dengan query aplikasi.

Contohnya, jika database bernama hydrotech, tetapi konfigurasi menunjuk ke monitoring_sensor, aplikasi akan mengakses database yang berbeda atau gagal jika database tersebut tidak ada.

> “Dashboard terhubung ke database melalui backend PHP. Backend menggunakan PDO dan driver pdo_mysql dengan konfigurasi host, pengguna, password, serta nama database.”

# 3. Bagaimana integrasi database dengan web dilakukan?

Proyek menggunakan **dua cara pengambilan data**.

## A. PHP mengambil data sebelum halaman ditampilkan

Contohnya terdapat pada halaman profil, halaman pengaturan pH/PPM/Dithane, dan tabel halaman riwayat.

```text
Pengguna membuka halaman
          v
PHP memeriksa sesi login
          v
PHP menjalankan SELECT
          v
MySQL mengembalikan data
          v
PHP menyusun HTML berisi data
          v
Browser menampilkan halaman
```

Contoh pada halaman riwayat:

```php
$pdo = getDBConnection();

$stmt = $pdo->query(
    "SELECT *
     FROM history_logs
     WHERE is_deleted = 0
     ORDER BY log_date DESC, log_time DESC"
);

$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

Hasilnya ditampilkan sebagai baris tabel menggunakan perulangan PHP. Cara ini disebut **server-side rendering**: data dimasukkan ke HTML di sisi server sebelum dikirim ke browser.

## B. JavaScript mengambil atau mengirim data melalui API

**API** adalah antarmuka yang menyediakan jalur komunikasi antara bagian tampilan dan backend.

| Endpoint | Fungsi |
|---|---|
| api/auth/login.php | Memeriksa login |
| api/settings/get.php | Membaca pengaturan |
| api/settings/update.php | Mengubah pengaturan |
| api/sensor/list.php | Membaca daftar sensor |
| api/sensor/save.php | Menyimpan hasil pembacaan |
| api/sensor/latest.php | Membaca hasil pengukuran terbaru |
| api/dashboard/summary.php | Menyediakan ringkasan data |
| api/notifications/list.php | Membaca notifikasi |
| api/history/delete.php | Menandai riwayat sebagai dihapus |

Contoh permintaan dari JavaScript:

```javascript
fetch("../api/settings/get.php?type=ph")
  .then(response => response.json())
  .then(result => {
    console.log(result.data);
  });
```

```text
JavaScript menjalankan fetch()
             v
Endpoint PHP menerima permintaan
             v
PHP memeriksa login dan input
             v
PHP menjalankan query database
             v
PHP mengembalikan JSON
             v
JavaScript memperbarui tampilan
```

**JSON** adalah format pertukaran data. Contohnya:

```json
{
  "success": true,
  "data": {
    "type": "ph",
    "config": {
      "min": "5,5",
      "max": "6,5"
    }
  }
}
```

Perlu dibedakan:

- Permintaan dari formulir proyek banyak menggunakan **FormData**.
- Respons API menggunakan **JSON**.

Jadi, tidak semua data yang dikirim dari browser berbentuk JSON.

# 4. Bagaimana dashboard bekerja saat ini?

Bagian ini penting agar penjelasan saat presentasi sesuai dengan implementasi.

## A. Pengaturan dibaca dari database

- Dashboard mengambil batas pH dan PPM melalui **api/settings/get.php**.
- Daftar sensor dibaca melalui **api/sensor/list.php**.
- Notifikasi dibaca melalui **api/notifications/list.php**.

Bagian-bagian tersebut sudah menggunakan data dari database.

## B. Grafik dashboard masih menggunakan simulasi

Pada **js/dashboard-live.js**, nilai pH dan PPM masih dihasilkan dengan perhitungan angka acak. Fungsi pembaruan dijadwalkan setiap sekitar **1,8 detik**:

```javascript
window.setInterval(render, 1800);
```

```text
JavaScript menghasilkan nilai simulasi
                  v
Chart.js memperbarui grafik
                  v
JavaScript mengirim nilai dan ID sensor
                  v
api/sensor/save.php
                  v
PHP memvalidasi dan menyimpan ke MySQL
```

**Grafik bergerak bukan karena sedang membaca perangkat fisik.** Nilai simulasi ditampilkan, kemudian dikirim untuk disimpan.

API pembacaan terbaru sudah tersedia, tetapi kode grafik dashboard saat ini belum memakai API tersebut sebagai sumber utama seluruh titik grafiknya.

## C. Grafik riwayat berbeda dari tabel riwayat

- **Tabel riwayat** diambil dari history_logs.
- **Grafik tren riwayat** masih dibuat dari perhitungan JavaScript.
- Bagian grafik pada laporan PDF juga menggunakan data tren tersebut.

Karena itu, jangan menyatakan bahwa seluruh grafik dan statistik laporan sudah merupakan agregasi pengukuran nyata dari database.

> “Integrasi database dan backend sudah berjalan. Saat ini sumber nilai pada grafik masih simulasi untuk menguji alur monitoring. Nilai tersebut disimpan ke database dengan identitas sensor, sedangkan integrasi perangkat fisik merupakan tahap berikutnya.”

# 5. Penjelasan delapan tabel

## A. users — akun pengguna

Tabel ini menyimpan akun untuk masuk ke aplikasi.

| Kolom | Fungsi |
|---|---|
| id | Identitas unik pengguna |
| username | Nama akun untuk login |
| password | Hash password |
| email | Alamat email |
| role | Jenis peran, yaitu admin atau user |
| created_at | Waktu pembuatan akun |
| updated_at | Waktu pembaruan akun |

Username memiliki aturan **UNIQUE**, sehingga dua akun tidak boleh memakai username yang sama. Password disimpan sebagai **hash**, bukan teks password asli.

## B. settings — konfigurasi sistem

Tabel ini menyimpan tiga jenis pengaturan: **ppm, ph, dan dithane**.

| Kolom | Fungsi |
|---|---|
| id | Identitas pengaturan |
| type | Jenis pengaturan |
| config_json | Isi konfigurasi |
| updated_at | Waktu perubahan terakhir |
| updated_by | Pengguna yang terakhir mengubah |

Contoh pengaturan pH:

```json
{"min": "5,5", "max": "6,5"}
```

Contoh pengaturan Dithane:

```json
{"interval": "48", "duration": "10", "start": "08.00"}
```

**Mengapa menggunakan JSON?** Karena atribut konfigurasi berbeda. pH dan PPM membutuhkan nilai minimum serta maksimum, sedangkan Dithane membutuhkan interval, durasi, dan waktu mulai.

JSON memberikan fleksibilitas. Konsekuensinya, struktur dan makna konfigurasi perlu divalidasi oleh aplikasi; valid secara format JSON belum tentu valid sebagai pengaturan.

Updated_by hanya mencatat **pengubah terakhir**, bukan seluruh riwayat perubahan.

## C. profile — profil pemilik dan tim

Tabel ini menyimpan informasi yang ditampilkan pada halaman profil.

| Kolom | Fungsi |
|---|---|
| id | Identitas baris profil |
| section | Bagian owner atau team |
| label | Nama informasi, misalnya Nama atau Email |
| value | Isi informasi |
| sort_order | Urutan tampilan |
| updated_by | Pengguna yang terakhir mengubah |

Tabel ini merupakan **informasi pemilik dan tim proyek**, bukan profil pribadi setiap akun pengguna.

## D. sensors — identitas perangkat

Tabel ini menyimpan identitas sensor.

| Kolom | Fungsi |
|---|---|
| id | Identitas sensor |
| name | Nama sensor |
| type | Parameter yang diukur: pH atau PPM |
| unit | Penanda satuan/skala hasil |
| status | Aktif, nonaktif, atau rusak |
| location | Lokasi sensor |
| created_at | Waktu pencatatan sensor |
| updated_at | Waktu pembaruan sensor |

Contoh identitas:

```text
ID: 1
Nama: Sensor pH
Jenis: ph
Unit: pH
Status: aktif
Lokasi: Bak nutrisi
```

Secara ilmiah, pH tidak memiliki satuan fisik; nilai pH pada kolom unit dipakai sebagai **label skala pengukuran**.

**Status sensor berbeda dari status pembacaan.** Status sensor adalah aktif, nonaktif, atau rusak. Status pembacaan adalah Normal, Rendah, atau Tinggi.

## E. sensor_readings — hasil pembacaan

Tabel ini menyimpan hasil pengukuran dari sensor.

| Kolom | Fungsi |
|---|---|
| id | Identitas catatan pembacaan |
| sensor_id | Sensor yang menghasilkan pembacaan |
| value | Nilai hasil pengukuran |
| recorded_at | Waktu pembacaan dicatat |

Contoh:

| id | sensor_id | value | Makna |
|---|---|---|---|
| 101 | 1 | 6.4000 | Hasil sensor pH |
| 102 | 2 | 845.0000 | Hasil sensor TDS/PPM |

**Sensor_id dan id pembacaan berbeda.** Satu perangkat bisa memiliki ribuan pembacaan, tetapi identitas perangkatnya tetap sama.

## F. monitoring_logs — hasil evaluasi monitoring

Tabel ini menyimpan catatan pembacaan beserta statusnya.

| Kolom | Fungsi |
|---|---|
| id | Identitas catatan monitoring |
| type | Jenis parameter |
| day_name | Nama hari |
| log_time | Jam catatan |
| value | Nilai atau teks hasil monitoring |
| status | Hasil evaluasi, misalnya Normal, Rendah, Tinggi |
| note | Keterangan |
| is_deleted | Penanda penghapusan dari tampilan |
| created_at | Waktu pembuatan catatan |
| sensor_reading_id | Referensi pembacaan, jika ada |

Contoh:

```text
Jenis: ph
Nilai: 7.1
Status: Tinggi
Keterangan: Pembacaan Sensor pH
Sumber pembacaan: ID 101
```

Perbedaannya: sensor_readings menyimpan **nilai pengukuran**, sedangkan monitoring_logs menyimpan **catatan evaluasi nilai tersebut**.

Sensor_reading_id boleh kosong untuk catatan manual atau catatan Dithane yang tidak berasal dari pembacaan sensor.

## G. history_logs — riwayat umum

Tabel ini menyimpan riwayat berdasarkan tanggal dan jenis parameter.

| Kolom | Fungsi |
|---|---|
| id | Identitas riwayat |
| day_name | Nama hari |
| log_date | Tanggal kejadian |
| log_time | Jam kejadian |
| type | Jenis parameter |
| status | Status kejadian |
| note | Keterangan atau ringkasan |
| is_deleted | Penanda penghapusan dari tampilan |
| created_at | Waktu pembuatan baris |

Monitoring memiliki kolom nilai dan referensi pembacaan. Riwayat umum memiliki tanggal serta ringkasan kejadian, tetapi belum memiliki referensi langsung ke pembacaan.

**Pada implementasi sekarang, penyimpanan sensor belum otomatis mengisi history_logs.** Tabel ini masih dikelola terpisah.

## H. notifications — notifikasi

Tabel ini menyimpan pesan pemberitahuan.

| Kolom | Fungsi |
|---|---|
| id | Identitas notifikasi |
| label | Judul pesan |
| message | Isi pesan |
| tone | Warning, success, atau info |
| href | Tujuan tautan |
| is_read | Status sudah dibaca |
| created_at | Waktu dibuat |
| sensor_reading_id | Sumber pembacaan, jika ada |

Notifikasi umum, seperti informasi jadwal, boleh tidak memiliki referensi pembacaan. Status baca saat ini masih **global**, belum dipisahkan untuk masing-masing pengguna.

# 6. Penjelasan relasi antar tabel

## A. Daftar tujuh foreign key

| No. | Kolom FK | Mengacu ke | Makna |
|---|---|---|---|
| 1 | settings.updated_by | users.id | Pengubah terakhir pengaturan |
| 2 | profile.updated_by | users.id | Pengubah terakhir profil |
| 3 | sensor_readings.sensor_id | sensors.id | Asal perangkat pembacaan |
| 4 | monitoring_logs.type | settings.type | Jenis pengaturan monitoring |
| 5 | monitoring_logs.sensor_reading_id | sensor_readings.id | Sumber pembacaan monitoring |
| 6 | history_logs.type | settings.type | Jenis pengaturan riwayat |
| 7 | notifications.sensor_reading_id | sensor_readings.id | Sumber pembacaan notifikasi |

## B. Kardinalitas satu-ke-banyak

Contoh paling mudah: **satu Sensor dapat menghasilkan banyak Pembacaan Sensor**.

- Satu sensor dapat memiliki **nol atau banyak** pembacaan.
- Satu pembacaan wajib berasal dari **tepat satu** sensor.

Untuk hubungan pengguna dan pengaturan:

- Satu pengguna dapat tercatat sebagai pengubah terakhir banyak pengaturan.
- Satu pengaturan boleh belum memiliki pengubah tercatat, atau memiliki satu pengubah terakhir.

Karena itu, ERD perlu menunjukkan bukan hanya 1:N, tetapi juga apakah hubungan **wajib atau opsional**.

## C. Mengapa FK boleh mengacu ke settings.type?

Foreign key tidak selalu harus mengacu ke kolom bernama id. Dalam proyek ini, **settings.type memiliki aturan UNIQUE**. Satu nilai ph, ppm, atau dithane mengidentifikasi tepat satu baris pengaturan.

> “Kolom type digunakan sebagai kunci rujukan karena nilainya unik. Jadi setiap catatan monitoring memiliki jenis pengaturan yang terdaftar.”

Hubungan tersebut menjamin kategorinya valid. Hubungan itu **tidak menyimpan versi batas pengaturan pada masa lalu**.

# 7. Istilah database yang perlu dipahami

| Istilah | Penjelasan dalam proyek |
|---|---|
| Database | Wadah kumpulan tabel |
| Tabel | Kelompok data dengan struktur tertentu |
| Kolom/atribut | Jenis informasi, misalnya nama sensor |
| Baris/record | Satu data lengkap dalam tabel |
| Primary key / PK | Identitas unik setiap baris |
| Foreign key / FK | Kolom yang merujuk data pada tabel lain |
| UNIQUE | Mencegah nilai kunci yang sama muncul berulang |
| AUTO_INCREMENT | Membuat ID angka otomatis |
| NOT NULL | Kolom wajib memiliki nilai |
| NULL | Nilai belum tersedia atau tidak berlaku; berbeda dari angka nol |
| DEFAULT | Nilai yang dipakai jika kolom tidak diisi |
| INDEX | Struktur untuk membantu pencarian data |
| CHECK | Aturan untuk memeriksa kondisi nilai |
| JOIN | Menggabungkan data dari tabel yang berhubungan |

## A. Contoh penggunaan CHECK

Pada tabel sensor, jenis dan label satuan harus sesuai:

```sql
CHECK (
    (type = 'ph' AND unit = 'pH')
    OR
    (type = 'ppm' AND unit = 'ppm')
)
```

Kombinasi jenis ph dan satuan ppm ditolak.

## B. Mengapa memakai DECIMAL(12,4)?

Artinya maksimum **12 digit total**, dengan **empat digit di belakang koma** dan **delapan digit sebelum koma**. Contohnya: 6.4000 dan 845.0000.

Tipe ini menyimpan angka desimal dengan presisi yang ditentukan. Namun, **presisi penyimpanan bukan berarti sensor fisiknya pasti seakurat empat angka di belakang koma**.

## C. Mengapa sebagian nilai monitoring masih VARCHAR?

Karena kolom monitoring_logs.value juga menampung catatan seperti **0.5 l**. Untuk perhitungan numerik, sumber yang lebih tepat adalah sensor_readings.value, yang menggunakan DECIMAL.

## D. Mengapa menggunakan indeks?

Contohnya:

```sql
INDEX idx_sensor_time (sensor_id, recorded_at)
```

Indeks ini membantu query yang mencari pembacaan suatu sensor berdasarkan waktu. Indeks memiliki biaya tambahan pada penyimpanan dan operasi tulis, sehingga dipasang sesuai kebutuhan pencarian.

# 8. Contoh alur lengkap satu pengukuran

Misalnya sensor pH menghasilkan nilai **7,1**, sedangkan batas pengaturan adalah **5,5–6,5**.

## Langkah 1 — kirim data

Frontend mengirim sensor_id=1 dan value=7.1 ke **api/sensor/save.php**.

## Langkah 2 — periksa login

Backend memastikan pengguna memiliki sesi login.

## Langkah 3 — validasi sensor dan nilai

Backend memeriksa:

- ID sensor tersedia.
- Sensor berstatus aktif.
- Nilai berupa angka yang valid.
- pH berada dalam rentang valid 0–14.
- Jenis sensor sesuai dengan parameter yang dikirim.

**Rentang valid input berbeda dari rentang normal tanaman.** Nilai pH 7,1 masih valid sebagai angka pH, tetapi berada di atas batas normal yang dikonfigurasi.

## Langkah 4 — baca batas pengaturan

Backend membaca settings untuk jenis ph.

## Langkah 5 — simpan pembacaan

Data masuk ke sensor_readings.

## Langkah 6 — buat catatan monitoring

Karena 7,1 lebih besar daripada 6,5, statusnya menjadi **Tinggi**. Catatan masuk ke monitoring_logs, dengan FK ke pembacaan yang baru disimpan.

## Langkah 7 — buat notifikasi

Backend membuat notifikasi peringatan yang juga merujuk pembacaan tersebut.

## Langkah 8 — selesaikan transaksi

Jika semua berhasil, backend menjalankan **commit()** dan mengembalikan respons sukses. Jika salah satu operasi gagal, perubahan dalam transaksi dibatalkan melalui **rollBack()**.

> “Satu proses penyimpanan pengukuran dapat menghasilkan pembacaan, catatan monitoring, dan notifikasi. Ketiganya disimpan dalam transaksi agar tidak terjadi penyimpanan sebagian.”

# 9. CRUD, transaksi, dan aturan penghapusan

## A. CRUD

CRUD merupakan empat operasi dasar:

| Operasi | SQL | Contoh proyek |
|---|---|---|
| Create | INSERT | Menambah pembacaan |
| Read | SELECT | Menampilkan riwayat |
| Update | UPDATE | Mengubah batas pH |
| Delete | DELETE atau soft delete | Menghapus catatan dari tampilan |

## B. Soft delete

Pada monitoring dan riwayat, tombol hapus mengubah:

```sql
UPDATE history_logs
SET is_deleted = 1
WHERE id = ?;
```

Data masih tersimpan, tetapi tidak ditampilkan oleh query yang menggunakan:

```sql
WHERE is_deleted = 0
```

Keuntungannya, penghapusan dari tampilan tidak langsung menghilangkan catatan secara fisik. Namun, aplikasi saat ini belum otomatis menyediakan fitur pemulihan melalui antarmuka.

## C. ON DELETE SET NULL

Digunakan pada referensi pengguna di settings dan profile. Jika akun pengguna dihapus secara fisik, informasi pengaturan atau profil tetap ada, tetapi updated_by menjadi NULL.

## D. ON DELETE RESTRICT

Digunakan pada hubungan yang sumber datanya perlu dipertahankan. Contoh: sensor yang masih memiliki pembacaan tidak dapat dihapus secara fisik. Jika perangkat tidak dipakai lagi, statusnya dapat diubah menjadi nonaktif.

## E. Engine InnoDB

Semua tabel menggunakan **InnoDB**, yang menyediakan dukungan transaksi dan foreign key. FK menjaga hubungan antarbaris, sedangkan transaksi menjaga agar beberapa operasi penyimpanan berhasil atau dibatalkan sebagai satu kesatuan.

# 10. Bagaimana login dan keamanan dasarnya bekerja?

```text
Pengguna mengisi username dan password
                  v
api/auth/login.php mencari akun di users
                  v
password_verify() memeriksa password
                  v
PHP membuat informasi sesi login
                  v
Pengguna dapat membuka halaman terlindungi
```

Proyek menggunakan:

- **password_hash()** untuk membuat hash password.
- **password_verify()** untuk memeriksa password.
- **session_regenerate_id(true)** setelah login.
- **requireAuth()** untuk memeriksa sesi.
- **Prepared statement** untuk query dengan input pengguna.

Contoh prepared statement:

```php
$stmt = $pdo->prepare(
    "SELECT * FROM users WHERE username = ? LIMIT 1"
);

$stmt->execute([$username]);
```

Input diberikan sebagai parameter, sehingga tidak langsung digabungkan sebagai bagian perintah SQL.

**Hash password tidak dibuka kembali menjadi password asli.** Password yang dimasukkan diperiksa kecocokannya terhadap hash.

Bedakan dua akun berikut:

- **root**: akun MySQL untuk koneksi database.
- **hydrotech**: akun aplikasi yang tersimpan pada tabel users.

Keduanya memiliki fungsi yang berbeda.

# 11. Terminal Laragon dan phpMyAdmin

## A. Fungsinya berbeda

| Komponen | Fungsi |
|---|---|
| MySQL | Menyimpan dan mengolah data |
| PHP | Menjalankan backend aplikasi |
| Terminal Laragon | Tempat menjalankan perintah seperti installer dan server PHP |
| phpMyAdmin | Antarmuka administrasi database melalui browser |
| Browser dashboard | Tempat pengguna memakai aplikasi |

phpMyAdmin digunakan untuk mengelola server MySQL/MariaDB, termasuk tabel dan impor data. Rujukan: [Dokumentasi resmi phpMyAdmin](https://docs.phpmyadmin.net/en/master/intro.html).

**phpMyAdmin bukan tempat menjalankan aplikasi dashboard Hydrotech.**

## B. Mengapa terminal praktis untuk proyek ini?

### 1. Menjalankan installer PHP

```sh
php database/install.php
```

Installer membuat database dan tabel, mengisi data awal, membuat hash password, serta membuat data sensor simulasi.

File installer adalah **program PHP**, sehingga dijalankan menggunakan PHP CLI. Tab SQL phpMyAdmin digunakan untuk menjalankan SQL, bukan program PHP tersebut.

### 2. Menjalankan migrasi

```sh
php database/migrate_sensors.php
```

Migrasi melakukan pencadangan, konversi data lama, dan pemeriksaan hasil. Pekerjaan tersebut lebih mudah diulang secara konsisten melalui satu script.

### 3. Menjalankan server pengembangan

```sh
php -S localhost:8000
```

Perintah ini menjalankan **server web bawaan PHP**, sehingga aplikasi dapat dibuka pada **http://localhost:8000**.

Server bawaan PHP ditujukan untuk pengembangan dan pengujian, bukan server produksi. Rujukan: [Dokumentasi server bawaan PHP](https://www.php.net/commandline.webserver).

### 4. Memakai lingkungan alat Laragon

Terminal Laragon menyediakan lingkungan alat pengembangan tersendiri, sehingga pemilihan PHP dan alat lain lebih terarah dibanding mengandalkan konfigurasi PATH sistem yang mungkin berbeda. Rujukan: [Dokumentasi terminal Laragon](https://laragon.org/docs/terminal).

## C. Apakah phpMyAdmin masih berguna?

Ya, untuk melihat isi tabel, memeriksa FK, menjalankan query SQL, memeriksa apakah data baru masuk, mengimpor atau mengekspor SQL, dan menampilkan diagram relasi.

Web tetap dapat mengakses database meskipun halaman phpMyAdmin ditutup, selama server MySQL dan server web masih berjalan.

## D. Apakah terminal dan phpMyAdmin memakai database berbeda?

Tidak harus. Keduanya dapat mengakses database yang sama apabila terhubung ke server MySQL, port, dan nama database yang sama.

> “Saya menggunakan terminal Laragon untuk menjalankan installer, migrasi, dan server PHP. phpMyAdmin tetap digunakan untuk melihat serta mengelola database. Penghubung aplikasi dengan MySQL adalah PDO, sehingga aplikasi tidak bergantung pada halaman phpMyAdmin.”

# 12. Urutan menjalankan proyek saat demonstrasi

Karena database proyek sudah dibuat dan dimigrasikan, alur demonstrasi cukup:

1. Aktifkan layanan MySQL melalui Laragon.
2. Buka terminal pada folder proyek.
3. Jalankan perintah di bawah.
4. Buka http://localhost:8000.
5. Login.
6. Tampilkan dashboard dan halaman pengaturan.
7. Buka phpMyAdmin untuk memperlihatkan data yang tersimpan.

```sh
php -S localhost:8000
```

**Installer dan migrasi tidak perlu dijalankan setiap kali membuka aplikasi.**

| Perintah | Kapan digunakan |
|---|---|
| php database/install.php | Instalasi pada database baru |
| php database/migrate_sensors.php | Memperbarui database versi lama |
| php -S localhost:8000 | Menjalankan web untuk pengembangan |

Perintah php -S tidak otomatis menyalakan server MySQL.

# 13. Pertanyaan yang mungkin diajukan dosen

## 1. Mengapa menggunakan database relasional?

> “Karena data sistem memiliki hubungan yang jelas, seperti sensor dengan hasil pembacaan dan pengguna dengan perubahan pengaturan. Database relasional memungkinkan hubungan tersebut dijaga menggunakan foreign key.”

## 2. Mengapa memilih MySQL?

> “MySQL sesuai dengan kebutuhan penyimpanan berbentuk tabel, mendukung transaksi dan foreign key melalui InnoDB, serta dapat diakses oleh PHP menggunakan PDO.”

## 3. Mengapa tabel sensor dipisahkan dari pembacaan?

> “Identitas sensor relatif tetap, sedangkan pembacaan bertambah terus. Pemisahan menghindari pengulangan nama, lokasi, serta status perangkat pada setiap hasil pengukuran.”

## 4. Apa bedanya ID sensor dan ID pembacaan?

> “ID sensor menunjukkan perangkatnya. ID pembacaan menunjukkan satu catatan pengukurannya. Satu sensor dapat menghasilkan banyak ID pembacaan.”

## 5. Mengapa pH dan PPM disimpan pada baris terpisah?

> “Karena masing-masing hasil memiliki sensor asal. Struktur ini juga memungkinkan beberapa perangkat dari jenis yang sama memiliki riwayat pembacaan masing-masing.”

## 6. Apakah sistem sudah terhubung dengan sensor fisik?

> “Belum. Saat ini dashboard menggunakan data simulasi. Identitas sensor, penyimpanan, validasi, monitoring, serta notifikasinya sudah disiapkan, tetapi komunikasi perangkat fisik masih perlu dikembangkan.”

## 7. Apakah sistem sudah real-time?

> “Tampilan simulasi diperbarui berkala sekitar 1,8 detik. Namun, belum menggunakan pembacaan perangkat fisik atau mekanisme push seperti WebSocket. Jadi saya menyebutnya pembaruan berkala pada simulasi.”

## 8. Apa yang terjadi jika browser ditutup?

> “Data yang sudah tersimpan tetap berada di MySQL. Namun, karena generator simulasi berjalan di JavaScript browser, simulasi dari halaman tersebut berhenti ketika halaman ditutup.”

## 9. Apakah FK otomatis mengambil data atau membuat notifikasi?

> “Tidak. FK menjaga keabsahan hubungan data. Pengambilan data dilakukan dengan query, sedangkan pembuatan notifikasi dilakukan oleh logika PHP.”

## 10. Apakah semua riwayat otomatis berasal dari sensor?

> “Belum. Penyimpanan sensor saat ini membuat pembacaan, monitoring, dan notifikasi peringatan. Tabel history_logs masih terpisah dan belum otomatis diisi oleh proses tersebut.”

## 11. Apakah database sudah sepenuhnya dinormalisasi?

> “Struktur sudah memisahkan beberapa entitas utama, terutama sensor dan pembacaan. Namun, saya tidak menyatakan seluruh skema sudah terbukti 3NF. Masih ada bagian yang dapat dirapikan, seperti nama hari yang dapat dihitung dari tanggal, konfigurasi JSON, dan penyimpanan nilai pada log.”

## 12. Apakah status Normal atau Tinggi dihitung oleh database?

> “Saat ini status dihitung oleh PHP dengan membandingkan nilai pengukuran terhadap batas pada settings. Database menyimpan hasilnya.”

## 13. Mengapa menghapus data hanya mengubah is_deleted?

> “Itu merupakan soft delete. Data disembunyikan dari tampilan tanpa langsung menghapus barisnya, sehingga catatan masih tersedia.”

## 14. Bagaimana jika sebagian proses penyimpanan gagal?

> “Proses penyimpanan sensor memakai transaksi. Jika penyimpanan monitoring atau notifikasi gagal, pembacaan dalam transaksi yang sama juga dibatalkan.”

## 15. Apakah peran admin dan user sudah membatasi semua aksi?

> “Kolom role sudah tersedia, tetapi pemeriksaan akses pada endpoint saat ini terutama memastikan pengguna sudah login. Pembatasan tindakan yang lebih rinci berdasarkan role masih perlu dikembangkan.”

## 16. Apakah data tiap pengguna dipisahkan?

> “Belum. Pengaturan, monitoring, dan notifikasi masih bersifat global. Updated_by mencatat pengubah terakhir, bukan pemilik seluruh data.”

## 17. Apakah jadwal Dithane sudah mengendalikan penyemprotan?

> “Saat ini sistem menyimpan konfigurasi jadwal dan menampilkan informasinya. Integrasi aktuator atau perangkat penyemprot otomatis belum dapat dianggap sudah berjalan hanya karena jadwal tersimpan.”

## 18. Apakah sistem siap dipublikasikan?

> “Sistem saat ini ditujukan untuk pengembangan lokal. Untuk produksi masih diperlukan pengaturan server, akun database dengan hak terbatas, HTTPS, pembatasan akses berdasarkan peran, perlindungan permintaan perubahan data, serta pengelolaan error yang lebih ketat.”

## 19. Bagaimana cara membuktikan bahwa data benar-benar tersimpan?

Tampilkan query berikut pada alat pengelola database:

```sql
SELECT
    r.id AS id_pembacaan,
    s.id AS id_sensor,
    s.name AS nama_sensor,
    s.type AS jenis_sensor,
    r.value AS nilai,
    s.unit AS satuan,
    r.recorded_at AS waktu
FROM sensor_readings AS r
JOIN sensors AS s
    ON s.id = r.sensor_id
ORDER BY r.recorded_at DESC, r.id DESC
LIMIT 10;
```

> “Query ini menggabungkan hasil pengukuran dengan identitas perangkat melalui JOIN. Data terbaru terlihat lengkap dengan nama sensor, nilai, dan waktu penyimpanannya.”

## 20. Mengapa grafik tetap bergerak saat penyimpanan gagal?

> “Karena grafik saat ini diperbarui dari simulasi di browser sebelum penyimpanan selesai. Aplikasi memberikan status apakah simulasi berhasil disimpan. Jadi grafik bergerak belum menjadi bukti bahwa data sudah masuk ke database.”

# 14. Contoh narasi penutup presentasi

> “Database Hydrotech terdiri dari delapan tabel dengan tujuh hubungan foreign key. Pemisahan tabel dilakukan berdasarkan fungsi data, seperti akun, pengaturan, identitas sensor, pembacaan, monitoring, riwayat, notifikasi, dan profil.

> Aplikasi terhubung ke MySQL melalui backend PHP menggunakan PDO. Data ditampilkan melalui halaman PHP maupun API yang diakses JavaScript.

> Setiap hasil pengukuran memiliki referensi sensor asal. Backend memvalidasi nilai, membandingkannya dengan batas pengaturan, lalu menyimpan pembacaan, monitoring, dan notifikasi dalam satu transaksi.

> Terminal Laragon digunakan untuk menjalankan script dan server pengembangan, sedangkan phpMyAdmin digunakan untuk administrasi database.

> Pada tahap sekarang, sumber grafik masih simulasi. Pengembangan berikutnya adalah menghubungkan sensor fisik, menjadikan grafik sepenuhnya berbasis pembacaan database, serta melengkapi integrasi riwayat dan keamanan akses.”

**Poin utama yang perlu dikuasai: siapa yang menghasilkan data, siapa yang memprosesnya, di mana data disimpan, bagaimana relasinya dijaga, dan bagian mana yang masih simulasi.**

# Referensi

- Sumber implementasi: config/database.php, database/schema.sql, includes/sensors.php, includes/auth_check.php, API pada folder api/, halaman PHP pada folder pages/, js/dashboard-live.js, js/history.js, js/settings-storage.js, dan js/confirm-modal.js.
- [PHP: MySQL PDO Driver](https://www.php.net/pdo-mysql).
- [PHP: Built-in Web Server](https://www.php.net/commandline.webserver).
- [phpMyAdmin: Introduction](https://docs.phpmyadmin.net/en/master/intro.html).
- [Laragon: Terminal](https://laragon.org/docs/terminal).

Materi disusun berdasarkan pemeriksaan kode proyek pada 22 September 2026. Keterangan fitur dan batasan mengikuti kondisi implementasi pada saat pemeriksaan.
