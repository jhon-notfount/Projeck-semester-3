# 1. Ringkasan untuk presentasi

**Hydrotech menggunakan satu database aplikasi dengan 9 tabel, 60 kolom, dan 9 foreign key.** Jumlah ini sudah diperiksa pada schema.sql serta database lokal hydrotech dan monitoring_sensor pada 28 September 2026. Dokumen disiapkan untuk presentasi 29 September 2026.

Istilah yang tepat adalah sembilan tabel dalam satu database, bukan sembilan atau sepuluh database. Rancangan sepuluh tabel pernah dibahas ketika tabel identitas sensors masih dipertahankan. Setelah tabel tersebut dihapus sesuai keputusan proyek, jumlah akhirnya menjadi sembilan tabel.

| Kelompok | Tabel | Tujuan |
|---|---|---|
| Akun dan konten | users, profile | Menyimpan akun login serta informasi pemilik dan tim |
| Pengaturan | settings, dithane_schedules | Menyimpan batas pH/PPM serta jadwal Dithane |
| Pengukuran | ph_readings, ppm_readings | Menyimpan hasil pengukuran dan waktunya |
| Pencatatan | monitoring_logs, history_logs, notifications | Menyimpan evaluasi, riwayat, dan pemberitahuan |

**Fakta penting yang harus konsisten saat presentasi:**

- pH disimpan sebagai DECIMAL(6,4), sedangkan PPM sebagai INT.
- PPM adalah bilangan bulat dalam proyek ini; pH dapat memiliki pecahan.
- Tabel sensors dan sensor_readings sudah dihapus dari struktur aktif.
- Jadwal Dithane menyimpan satu konfigurasi berulang, bukan banyak agenda bertanggal.
- Grafik dashboard saat ini masih memakai simulasi JavaScript, belum pembacaan langsung ESP32.
- Kedua database lokal memiliki struktur yang sama, tetapi datanya terpisah. Aplikasi hanya menggunakan database yang ditentukan pada konfigurasi koneksi.

## Kalimat pembuka

> “Sistem Hydrotech menggunakan MySQL dengan sembilan tabel. Hasil pengukuran pH dan PPM disimpan secara terpisah, sedangkan jadwal Dithane mempunyai tabel sendiri. Hubungan antartabel dijaga menggunakan foreign key agar sumber data dapat ditelusuri dan referensinya tetap valid.”

# 2. Cara membaca ERD dan istilah dasar

ERD adalah Entity Relationship Diagram, yaitu gambaran tabel, atribut, dan hubungan data. Pada diagram fisik seperti Designer phpMyAdmin, kotak menunjukkan tabel, daftar di dalam kotak menunjukkan kolom, dan garis menghubungkan kolom yang berelasi.

| Istilah | Penjelasan sederhana | Contoh proyek |
|---|---|---|
| Database | Wadah kumpulan tabel | hydrotech |
| Tabel | Kumpulan data dengan struktur sama | ppm_readings |
| Baris | Satu catatan data | Pembacaan PPM 850 pada satu waktu |
| Kolom/atribut | Jenis informasi dalam catatan | ppm_value, recorded_at |
| Primary key (PK) | Identitas unik setiap baris | users.id |
| Foreign key (FK) | Kolom yang merujuk ke kunci tabel lain | notifications.ppm_reading_id |
| UNIQUE | Mencegah nilai yang sama muncul berulang | username, settings.type |
| NULL | Tidak ada nilai atau referensi | Notifikasi umum tanpa sumber pembacaan |
| NOT NULL | Kolom wajib mempunyai nilai | ppm_readings.ppm_value |
| AUTO_INCREMENT | ID diberikan otomatis oleh database | ph_readings.id |
| CHECK | Aturan nilai yang harus dipenuhi | ph_value antara 0 dan 14 |
| INDEX | Struktur untuk membantu pencarian | Indeks waktu pengukuran |
| Kardinalitas | Banyaknya data yang dapat terhubung | Satu pembacaan dapat dirujuk banyak notifikasi |

**1:N** berarti satu baris induk dapat memiliki banyak baris terkait. Istilah “dapat” tidak berarti data anak wajib ada. Pembacaan normal dapat tidak memiliki notifikasi sama sekali.

Foreign key yang boleh NULL membuat hubungan anak ke induknya bersifat opsional. Misalnya, sebuah notifikasi boleh tidak berhubungan dengan pembacaan tertentu. Jika ID pembacaan diisi, ID itu harus benar-benar tersedia pada tabel yang dirujuk.

## Tipe data yang digunakan

| Tipe | Kegunaan | Contoh |
|---|---|---|
| INT | Bilangan bulat | ID, PPM 850, durasi 10 detik |
| DECIMAL(6,4) | Angka dengan empat digit pecahan dan enam digit total | pH 6.2500 |
| VARCHAR(n) | Teks pendek dengan batas panjang | Nama akun dan status |
| TEXT | Teks lebih panjang | Catatan atau pesan |
| ENUM | Pilihan nilai yang telah ditentukan | admin/user; ph/ppm/dithane |
| JSON | Konfigurasi terstruktur | Batas min dan max |
| DATE | Tanggal | 2026-09-29 |
| TIME | Waktu tanpa tanggal | 08:00:00 |
| TIMESTAMP | Tanggal dan waktu pencatatan | recorded_at |
| TINYINT(1) | Bilangan kecil yang digunakan sebagai penanda 0/1 | is_read, is_deleted |

TINYINT(1) bukan jaminan bahwa database hanya menerima 0 dan 1; pada proyek ini aplikasi memakainya sebagai penanda. Akurasi alat juga tidak ditentukan oleh banyaknya angka desimal yang bisa disimpan.

# 3. Penjelasan lengkap sembilan tabel

## 3.1. users — Akun pengguna

**Fungsi:** Menyimpan akun untuk masuk ke aplikasi. Akun aplikasi berbeda dengan akun MySQL yang digunakan oleh PDO atau phpMyAdmin.

| Kolom | Tipe data | Penjelasan |
|---|---|---|
| id | INT | Identitas unik pengguna; PK dan AUTO_INCREMENT. |
| username | VARCHAR(50) | Nama akun login; wajib dan UNIQUE. |
| password | VARCHAR(255) | Hash kata sandi; bukan teks kata sandi asli. |
| email | VARCHAR(100) | Alamat email; boleh NULL. |
| role | ENUM | Pilihan admin atau user; nilai awal admin. |
| created_at | TIMESTAMP | Waktu akun dibuat; default waktu saat penambahan. |
| updated_at | TIMESTAMP | Waktu perubahan terakhir; diperbarui otomatis saat baris berubah. |

**Relasi dan aturan:** Satu pengguna dapat menjadi pengubah terakhir beberapa settings dan profile. Jadwal merujuk ke pengguna melalui updated_by, tetapi jumlah jadwal dibatasi satu baris secara global.

**Contoh:** Contoh: id 1, username admin_kelompok, role admin. Jangan menampilkan hash password saat demonstrasi.

## 3.2. settings — Pengaturan dan kategori

**Fungsi:** Menyimpan batas normal pH/PPM serta kategori yang dirujuk monitoring dan riwayat.

| Kolom | Tipe data | Penjelasan |
|---|---|---|
| id | INT | Identitas pengaturan; PK dan AUTO_INCREMENT. |
| type | ENUM | Kategori ppm, ph, atau dithane; NOT NULL dan UNIQUE. |
| config_json | JSON | Objek JSON konfigurasi. Kategori dithane saat ini berisi objek kosong karena jadwal dipindahkan. |
| updated_at | TIMESTAMP | Waktu perubahan terakhir. |
| updated_by | INT | FK ke users.id; boleh NULL. Menunjukkan pengubah terakhir. |

**Relasi dan aturan:** updated_by merujuk ke users.id. type dirujuk oleh monitoring_logs.type dan history_logs.type. JSON valid belum tentu mempunyai konfigurasi bisnis yang benar; min dan max tetap perlu diperiksa.

**Contoh:** Ilustrasi PPM: {"min":"700","max":"1000"}. Ini contoh rentang normal, bukan pembatas seluruh nilai yang boleh disimpan. Default installer proyek adalah 800–1000.

## 3.3. profile — Profil website

**Fungsi:** Menyimpan konten informasi pemilik dan tim pada halaman profil, bukan akun login pribadi.

| Kolom | Tipe data | Penjelasan |
|---|---|---|
| id | INT | Identitas informasi profil; PK dan AUTO_INCREMENT. |
| section | ENUM | Kelompok informasi: owner atau team. |
| label | VARCHAR(100) | Judul informasi, misalnya Nama Tim. |
| value | TEXT | Isi informasi yang ditampilkan. |
| sort_order | INT | Urutan tampilan; default 0. |
| updated_by | INT | FK ke users.id; boleh NULL. Pengubah terakhir informasi. |

**Relasi dan aturan:** profile.updated_by merujuk ke users.id. Satu pengguna dapat memperbarui beberapa baris profil. Hubungan ini tidak menyatakan bahwa pengguna adalah pemilik seluruh data tersebut.

**Contoh:** Contoh: section team, label Nama Tim, value Kelompok Hydrotech, sort_order 1.

## 3.4. ph_readings — Hasil pengukuran pH

**Fungsi:** Menyimpan nilai pH yang sudah diproses, bukan sinyal mentah ADC atau tegangan.

| Kolom | Tipe data | Penjelasan |
|---|---|---|
| id | INT | Identitas pembacaan pH; PK dan AUTO_INCREMENT. |
| ph_value | DECIMAL(6,4) | Angka pH desimal; wajib. CHECK membatasi 0 sampai 14. |
| recorded_at | TIMESTAMP | Waktu pencatatan oleh server database; wajib, default CURRENT_TIMESTAMP. |

**Relasi dan aturan:** id dirujuk oleh monitoring_logs.ph_reading_id dan notifications.ph_reading_id. Tabel ini tidak mempunyai sensor_id atau FK ke perangkat. Indeks recorded_at dan id membantu pengambilan data terbaru.

**Contoh:** Contoh: id 101, ph_value 6.2500, recorded_at 2026-09-29 08:00:00. pH tidak mempunyai satuan fisik. Nilai 6.25 dan 6.2500 menyatakan angka yang sama.

## 3.5. ppm_readings — Hasil pengukuran PPM

**Fungsi:** Menyimpan hasil pengukuran konsentrasi dalam ppm sebagai bilangan bulat sesuai kontrak data proyek.

| Kolom | Tipe data | Penjelasan |
|---|---|---|
| id | INT | Identitas pembacaan PPM; PK dan AUTO_INCREMENT. |
| ppm_value | INT | Bilangan bulat; wajib. CHECK membatasi 0 sampai 2147483647. Pecahan ditolak API. |
| recorded_at | TIMESTAMP | Waktu pencatatan oleh server database; wajib, default CURRENT_TIMESTAMP. |

**Relasi dan aturan:** id dirujuk oleh monitoring_logs.ppm_reading_id dan notifications.ppm_reading_id. Tidak ada identitas perangkat. Indeks recorded_at dan id membantu pengambilan pembacaan terbaru.

**Contoh:** Contoh: id 101, ppm_value 850, recorded_at 2026-09-29 08:00:00. ID 101 di tabel ini tidak sama maknanya dengan ID 101 pada ph_readings. Nilai di luar rentang normal, misalnya 1200, tetap dapat disimpan.

## 3.6. dithane_schedules — Jadwal Dithane

**Fungsi:** Menyimpan satu konfigurasi penyemprotan berulang yang diisi pengguna: interval, durasi, dan jam mulai.

| Kolom | Tipe data | Penjelasan |
|---|---|---|
| id | INT | PK dengan CHECK id = 1. Tidak AUTO_INCREMENT; membatasi tabel maksimal satu baris. |
| interval_hours | INT | Jarak pengulangan dalam jam; bilangan bulat positif. |
| duration_seconds | INT | Lama penyemprotan yang direncanakan dalam detik; bilangan bulat positif. |
| start_time | TIME | Jam mulai dalam TIME; dibatasi 00:00:00 sampai 23:59:59. |
| updated_by | INT | FK ke users.id; boleh NULL. Pengubah terakhir jadwal. |
| created_at | TIMESTAMP | Waktu baris jadwal dibuat. |
| updated_at | TIMESTAMP | Waktu perubahan terakhir; diperbarui otomatis. |

**Relasi dan aturan:** updated_by merujuk ke users.id dengan ON DELETE SET NULL. Tidak ada relasi langsung dari jadwal ke log pelaksanaan. Tabel ini belum mempunyai tanggal agenda, dosis, status selesai, atau banyak baris jadwal.

**Contoh:** Contoh: id 1, interval_hours 48, duration_seconds 10, start_time 08:00:00. Artinya rencana interval 48 jam, durasi 10 detik, dimulai pukul 08.00; tabel itu sendiri tidak menyalakan pompa.

## 3.7. monitoring_logs — Catatan evaluasi pemantauan

**Fungsi:** Menyimpan hasil evaluasi nilai atau catatan Dithane, termasuk status dan keterangan.

| Kolom | Tipe data | Penjelasan |
|---|---|---|
| id | INT | Identitas catatan; PK dan AUTO_INCREMENT. |
| type | ENUM | Kategori ppm, ph, atau dithane; FK wajib ke settings.type. |
| day_name | VARCHAR(20) | Nama hari untuk tampilan. |
| log_time | VARCHAR(10) | Jam tampilan berupa teks, misalnya 08.00. |
| value | VARCHAR(20) | Nilai tampilan berupa teks; dapat memuat angka atau volume seperti 0.5 l. |
| status | VARCHAR(30) | Status evaluasi atau kegiatan; berupa teks, misalnya Normal, Rendah, Tinggi. |
| note | TEXT | Keterangan tambahan; boleh NULL. |
| is_deleted | TINYINT(1) | Penanda soft delete: aplikasi menggunakan 0 untuk tampil dan 1 untuk dihapus. |
| created_at | TIMESTAMP | Waktu catatan dibuat. |
| ph_reading_id | INT | FK opsional ke ph_readings.id. |
| ppm_reading_id | INT | FK opsional ke ppm_readings.id. |

**Relasi dan aturan:** Selain FK kategori, terdapat dua FK pembacaan. CHECK mencegah keduanya terisi bersamaan dan memastikan ID pH hanya pada type ph serta ID PPM hanya pada type ppm. Kedua ID boleh NULL untuk catatan tanpa sumber pembacaan.

**Contoh:** Contoh: type ppm, value 1200, status Tinggi, ppm_reading_id 102, ph_reading_id NULL. Angka sumber berada pada ppm_readings; value di log adalah salinan untuk tampilan, bukan sumber utama perhitungan.

## 3.8. history_logs — Riwayat kejadian

**Fungsi:** Menyimpan riwayat kegiatan atau kondisi berdasarkan kategori, tanggal, waktu, status, dan catatan.

| Kolom | Tipe data | Penjelasan |
|---|---|---|
| id | INT | Identitas riwayat; PK dan AUTO_INCREMENT. |
| day_name | VARCHAR(20) | Nama hari untuk tampilan. |
| log_date | DATE | Tanggal kejadian. |
| log_time | VARCHAR(10) | Jam kejadian berupa teks. |
| type | ENUM | Kategori ppm, ph, atau dithane; FK wajib ke settings.type. |
| status | VARCHAR(30) | Status kejadian yang dicatat. |
| note | TEXT | Keterangan tambahan; boleh NULL. |
| is_deleted | TINYINT(1) | Penanda soft delete yang dipakai aplikasi: 0 atau 1. |
| created_at | TIMESTAMP | Waktu catatan dimasukkan ke database. |

**Relasi dan aturan:** history_logs.type merujuk ke settings.type. Tidak ada FK langsung ke ph_readings, ppm_readings, atau dithane_schedules. Karena itu, catatan ini tidak membuktikan asal pembacaan atau versi jadwal tertentu.

**Contoh:** Contoh: type dithane, log_date 2026-09-29, log_time 08.00, status Selesai. Contoh ini hanya ilustrasi baris; penyimpanan pembacaan tidak otomatis membuat riwayat atau membuktikan penyemprotan fisik.

## 3.9. notifications — Notifikasi aplikasi

**Fungsi:** Menyimpan pesan informasi, keberhasilan, atau peringatan yang ditampilkan kepada pengguna.

| Kolom | Tipe data | Penjelasan |
|---|---|---|
| id | INT | Identitas pesan; PK dan AUTO_INCREMENT. |
| label | VARCHAR(100) | Judul singkat notifikasi. |
| message | TEXT | Isi pesan. |
| tone | ENUM | Jenis tampilan: warning, success, atau info; default info. |
| href | VARCHAR(255) | Tautan tujuan halaman; boleh NULL. |
| is_read | TINYINT(1) | Penanda dibaca yang digunakan aplikasi: 0 belum, 1 sudah. |
| created_at | TIMESTAMP | Waktu pesan dibuat. |
| ph_reading_id | INT | FK opsional ke ph_readings.id. |
| ppm_reading_id | INT | FK opsional ke ppm_readings.id. |

**Relasi dan aturan:** Sumber pembacaan dipilih melalui salah satu FK. CHECK melarang kedua ID terisi bersamaan. Notifikasi umum dapat mempunyai dua ID NULL. Tanpa user_id, pesan dan status baca masih global, belum per pengguna.

**Contoh:** Contoh: label PPM perlu dipantau, message Nilai PPM di luar rentang, tone warning, ppm_reading_id 102. Satu pembacaan dapat mempunyai banyak pesan karena FK ini tidak UNIQUE.


# 4. Sembilan relasi dan kardinalitasnya

Pada tabel berikut, arah panah menunjukkan kolom foreign key menuju kunci rujukannya. Relasi pembacaan, pengaturan, dan profil umumnya berpola satu ke banyak. Jadwal Dithane mempunyai batas khusus karena hanya satu baris jadwal yang diizinkan.

| No. | Foreign key menuju kunci induk | Makna hubungan |
|---|---|---|
| 1 | settings.updated_by → users.id | Satu pengguna dapat tercatat sebagai pengubah terakhir beberapa pengaturan |
| 2 | profile.updated_by → users.id | Satu pengguna dapat memperbarui beberapa informasi profil |
| 3 | dithane_schedules.updated_by → users.id | Jadwal menyimpan paling banyak satu pengguna pengubah terakhir; seluruh sistem hanya memiliki maksimal satu jadwal |
| 4 | monitoring_logs.type → settings.type | Satu kategori pengaturan dapat digunakan banyak catatan monitoring |
| 5 | history_logs.type → settings.type | Satu kategori pengaturan dapat digunakan banyak catatan riwayat |
| 6 | monitoring_logs.ph_reading_id → ph_readings.id | Satu hasil pH dapat menjadi sumber banyak catatan monitoring |
| 7 | monitoring_logs.ppm_reading_id → ppm_readings.id | Satu hasil PPM dapat menjadi sumber banyak catatan monitoring |
| 8 | notifications.ph_reading_id → ph_readings.id | Satu hasil pH dapat menjadi sumber banyak notifikasi |
| 9 | notifications.ppm_reading_id → ppm_readings.id | Satu hasil PPM dapat menjadi sumber banyak notifikasi |

## Aturan referensi pembacaan

- ph_reading_id dan ppm_reading_id boleh sama-sama NULL untuk catatan umum atau data lama tanpa sumber pembacaan.
- Keduanya tidak boleh terisi bersamaan pada satu baris monitoring atau notifikasi.
- Jika monitoring mempunyai ph_reading_id, type harus ph. Jika mempunyai ppm_reading_id, type harus ppm.
- ID pembacaan yang diisi wajib tersedia di tabel tujuan. Nilai ID 5 pada ph_readings berbeda dengan ID 5 pada ppm_readings.
- Satu foreign key tidak diarahkan sekaligus ke dua tabel. Itulah alasan dua kolom referensi dipisahkan.

## Relasi wajib dan opsional

Setiap monitoring dan riwayat wajib mempunyai kategori yang tersedia dalam settings karena type tidak boleh NULL. Sebaliknya, updated_by dan kedua ID sumber pembacaan boleh NULL. Artinya, informasi pengubah atau sumber pembacaan tidak selalu tersedia, tetapi jika diisi harus valid.

Hubungan settings.type tetap sah walaupun kolom itu bukan primary key: type memiliki aturan UNIQUE. Nama kolom tidak wajib id untuk menjadi kunci yang dirujuk.

## Hal khusus pada jadwal Dithane

CHECK id = 1 dan primary key membuat dithane_schedules hanya boleh berisi satu baris. Maka pada implementasi saat ini, satu pengguna dapat terkait dengan nol atau satu jadwal aktif yang tercatat, bukan banyak jadwal. Jangan menggambarkan hubungan ini sebagai banyak jadwal tanpa menjelaskan pembatasan tersebut.

Tidak ada foreign key dari history_logs atau monitoring_logs langsung ke dithane_schedules. Catatan Dithane hanya terhubung ke kategori settings.type. Karena itu, sistem belum dapat membuktikan suatu catatan dibuat dari versi jadwal tertentu.

## Aturan penghapusan

| Aturan | Diterapkan pada | Akibat |
|---|---|---|
| ON DELETE SET NULL | users ke settings, profile, dithane_schedules | Data tetap ada; identitas pengubah terakhir dikosongkan ketika pengguna dihapus |
| ON DELETE RESTRICT | settings ke monitoring/riwayat | Kategori yang masih dirujuk tidak dapat dihapus |
| ON DELETE RESTRICT | Pembacaan ke monitoring/notifikasi | Pembacaan yang masih menjadi sumber catatan tidak dapat dihapus |
| Soft delete | monitoring_logs dan history_logs | is_deleted menjadi 1; baris tetap tersimpan |

Primary key tidak digunakan untuk diedit rutin. Relasi yang memakai RESTRICT juga membatasi perubahan kunci induk yang masih dirujuk. Foreign key menjaga integritas referensi; membuat notifikasi dan menentukan status tetap merupakan tugas kode PHP.

# 5. Bentuk data, alur penyimpanan, dan contoh kasus

## Data yang dikirim

Nilai pH dan PPM dikirim sebagai angka dalam pesan JSON. JSON adalah format pesan, sedangkan DECIMAL dan INT adalah tipe penyimpanan pada database.

```json
{
  "ph_value": 6.25,
  "ppm_value": 850
}
```

pH tidak mempunyai satuan fisik; ppm menyatakan parts per million. Pada proyek ini PPM dikirim tanpa pecahan. Angka 850.5 ditolak oleh API, sedangkan 850 diterima. Satu permintaan boleh memuat salah satu parameter atau keduanya.

Backend mencatat recorded_at menggunakan waktu server database. Kolom ini tidak otomatis menyatakan waktu ketika perangkat fisik melakukan sampling. Jika nanti membutuhkan waktu sampling asli, kontrak pengiriman perlu ditambah dan divalidasi.

## Proses saat data masuk

1. Browser atau pengirim data mengirim permintaan ke api/sensor/save.php.
2. Backend memeriksa sesi login, format pesan, dan nilai numeriknya.
3. pH diperiksa pada rentang 0–14; PPM harus bulat, tidak negatif, dan muat dalam INT.
4. Backend mengambil batas normal pH/PPM dari settings.
5. Nilai disimpan ke ph_readings atau ppm_readings.
6. Backend membuat monitoring dengan status Rendah, Normal, atau Tinggi.
7. Jika di luar batas normal, backend membuat notifikasi warning yang merujuk ke pembacaan tersebut.
8. Transaksi disahkan jika seluruh proses berhasil; jika gagal, perubahan dalam transaksi dibatalkan.

## Contoh perbedaan batas penyimpanan dan batas normal

Misalkan pengaturan PPM adalah 700–1000. Angka ini hanya contoh; batas yang digunakan aplikasi mengikuti isi settings saat dijalankan.

| Nilai masuk | Apakah disimpan? | Hasil evaluasi |
|---|---|---|
| 850 | Ya | Normal |
| 650 | Ya | Rendah; dapat menghasilkan peringatan |
| 1200 | Ya | Tinggi; dapat menghasilkan peringatan |
| 850.5 | Tidak | Ditolak karena PPM harus bulat |
| -5 | Tidak | Ditolak karena negatif |

Batas normal tidak dijadikan batas kolom database. Jika hanya nilai normal boleh disimpan, sistem justru kehilangan bukti ketika kondisi tidak normal terjadi.

## Transaksi

COMMIT mengesahkan perubahan, sedangkan ROLLBACK membatalkan perubahan yang belum disahkan. Contoh: jika penyimpanan pH berhasil tetapi penyimpanan PPM dalam satu permintaan gagal, transaksi dibatalkan sehingga tidak menyisakan pasangan yang tersimpan sebagian.

## Hubungan dengan ESP32

Rancangan integrasi: sensor dibaca ESP32, nilai diproses, kemudian dikirim ke API PHP dan disimpan ke tabel pengukuran. Tidak diperlukan tabel identitas ESP32 selama perangkat tidak dikelola secara khusus.

**Kondisi yang sudah ada:** endpoint saat ini menggunakan sesi login aplikasi dan belum menyediakan autentikasi khusus perangkat. Grafik dashboard menghasilkan simulasi setiap sekitar 1,8 detik, lalu mengirimkannya ke API. Jangan menyebutnya sudah terhubung dengan sensor fisik.

# 6. Integrasi database dengan website

Alur umum: browser mengirim permintaan HTTP ke PHP; PHP menggunakan PDO dengan driver pdo_mysql untuk menjalankan SQL pada MySQL; hasil dikirim kembali sebagai HTML atau JSON.

| Bagian | Tugas |
|---|---|
| HTML dan CSS | Menyusun tampilan halaman |
| JavaScript | Mengirim permintaan dan memperbarui tampilan |
| Chart.js | Menggambar grafik |
| PHP | Menjalankan validasi, autentikasi, dan logika sistem |
| PDO + pdo_mysql | Menghubungkan kode PHP dengan MySQL |
| MySQL/InnoDB | Menyimpan data serta menjalankan relasi dan transaksi |

## Konfigurasi koneksi

Koneksi diatur melalui config/database.php. Parameter utamanya adalah host, nama database, pengguna, password, dan charset. Default nama database pada proyek adalah monitoring_sensor. Variabel lingkungan HYDROTECH_DB_NAME dapat menggantinya menjadi hydrotech.

Database hydrotech dan monitoring_sensor bukan dua nama untuk data yang sama. Keduanya database terpisah walaupun struktur tabelnya serupa. Saat demonstrasi, cocokkan nama database yang dipakai website dengan yang dibuka pada phpMyAdmin.

## Endpoint yang penting

| Endpoint | Fungsi |
|---|---|
| api/auth/login.php | Memeriksa akun dan membuat sesi login |
| api/sensor/save.php | Menyimpan ph_value dan/atau ppm_value |
| api/sensor/latest.php | Mengambil pembacaan terbaru; filter type=ph atau type=ppm |
| api/dashboard/summary.php | Mengambil ringkasan pembacaan, konfigurasi, dan jumlah notifikasi |
| api/settings/get.php | Membaca pengaturan; type=dithane membaca tabel jadwal |
| api/settings/update.php | Menyimpan perubahan pengaturan atau jadwal |
| api/settings/reset.php | Mengembalikan pengaturan atau jadwal ke nilai awal |
| api/notifications/generate.php | Membuat notifikasi dari hasil pengukuran terbaru |

Tabel profil, pengaturan, dan riwayat juga dibaca melalui PHP untuk membentuk HTML. Saat ini grafik dashboard diperbarui dari simulasi sebelum hasil simpan diterima. Karena itu, grafik bergerak bukan bukti bahwa data sudah tersimpan; periksa respons API atau tabel pembacaan.

# 7. Laragon, terminal, dan phpMyAdmin

MySQL adalah layanan yang menyimpan database. Laragon menyediakan lingkungan pengembangan lokal. Terminal digunakan untuk menjalankan perintah, sedangkan phpMyAdmin adalah antarmuka web untuk mengelola database.

Perubahan melalui terminal dapat terlihat pada phpMyAdmin jika keduanya mengakses server, port, dan database yang sama. Database yang dibuat manual melalui phpMyAdmin tetap dapat digunakan oleh skrip PHP dan terminal tanpa diimpor ulang.

## Langkah demonstrasi singkat

1. Pastikan MySQL pada Laragon berjalan.
2. Jalankan aplikasi PHP sesuai konfigurasi proyek, kemudian login.
3. Buka phpMyAdmin dan pilih database yang benar.
4. Tampilkan sembilan tabel, kemudian struktur ph_readings dan ppm_readings.
5. Tampilkan Designer untuk menjelaskan foreign key yang sudah ada.
6. Buka tabel dithane_schedules untuk menunjukkan interval, durasi, dan jam mulai.
7. Gunakan query pada bab berikut untuk membuktikan data dan relasinya.

## Perintah terminal

Masuk ke MySQL dengan akun yang digunakan pada lingkungan lokal:

```text
mysql -u root -p
```

Masukkan password akun MySQL. Jika akun memang tanpa password, tekan Enter. Password MySQL berbeda dari password login website. Setelah prompt mysql> muncul:

```sql
USE hydrotech;
SHOW TABLES;
```

Untuk menjalankan server pengembangan PHP, buka terminal di direktori proyek:

```text
php -S localhost:8000
```

Perintah tersebut tidak menjalankan MySQL. MySQL tetap perlu aktif secara terpisah. Jika ingin aplikasi memakai hydrotech dari sesi PowerShell yang sama:

```powershell
$env:HYDROTECH_DB_NAME = "hydrotech"
php -S localhost:8000
```

PHP yang dipakai harus memiliki ekstensi pdo_mysql. Tidak perlu menjalankan installer atau migrasi ulang untuk presentasi jika struktur terbaru sudah tersedia.

> “Terminal dan phpMyAdmin sama-sama mengakses MySQL. phpMyAdmin tidak perlu terus dibuka agar website bekerja; yang harus berjalan adalah server aplikasi dan layanan MySQL.”

# 8. Query untuk demonstrasi di phpMyAdmin

Semua query di bab ini hanya membaca data. Pilih database yang sama dengan konfigurasi aplikasi. Contoh di bawah memakai hydrotech.

## A. Memastikan database dan tabel

```sql
USE hydrotech;
SELECT DATABASE() AS database_aktif;
SHOW TABLES;
DESCRIBE ph_readings;
DESCRIBE ppm_readings;
DESCRIBE dithane_schedules;
```

## B. Menampilkan pengukuran dan jadwal

```sql
SELECT id, ph_value, recorded_at
FROM ph_readings
ORDER BY recorded_at DESC, id DESC
LIMIT 5;

SELECT id, ppm_value, recorded_at
FROM ppm_readings
ORDER BY recorded_at DESC, id DESC
LIMIT 5;

SELECT id, interval_hours, duration_seconds, start_time
FROM dithane_schedules;
```

## C. Membuktikan hubungan monitoring dengan pembacaan

```sql
SELECT m.id, m.type, p.ppm_value, m.status,
       p.recorded_at
FROM monitoring_logs AS m
JOIN ppm_readings AS p ON p.id = m.ppm_reading_id
WHERE m.is_deleted = 0
ORDER BY m.id DESC
LIMIT 10;
```

JOIN menggabungkan data berdasarkan hubungan kolom. Jika hasilnya kosong, mungkin belum ada monitoring PPM yang mempunyai referensi pembacaan; baris contoh lama dapat memiliki foreign key NULL. Hasil kosong tidak otomatis berarti relasinya rusak.

## D. Menampilkan sembilan foreign key

```sql
SELECT TABLE_NAME AS tabel_anak,
       COLUMN_NAME AS foreign_key,
       REFERENCED_TABLE_NAME AS tabel_induk,
       REFERENCED_COLUMN_NAME AS kunci_induk
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, COLUMN_NAME;
```

## E. Menelusuri pembuat perubahan jadwal

```sql
SELECT d.interval_hours, d.duration_seconds,
       d.start_time, u.username AS pengubah_terakhir
FROM dithane_schedules AS d
LEFT JOIN users AS u ON u.id = d.updated_by;
```

LEFT JOIN mempertahankan tampilan jadwal meskipun updated_by kosong. Jangan menampilkan kolom password saat demonstrasi.

# 9. Integritas, keamanan, dan batas implementasi

## Integritas data

Primary key mencegah identitas ganda dalam tabel. UNIQUE mencegah username dan kategori pengaturan duplikat. Foreign key mencegah referensi ke data yang tidak tersedia. CHECK membatasi nilai, jenis sumber monitoring, dan jumlah konfigurasi jadwal. Transaksi menyatukan penyimpanan pembacaan, monitoring, dan notifikasi.

Indeks waktu membantu pencarian pembacaan terbaru. Indeks type dan is_deleted membantu menyaring catatan. Indeks mempercepat sebagian pencarian tetapi juga menambah ruang dan pekerjaan saat penulisan; indeks bukan pengganti primary key atau foreign key.

## Keamanan dasar

Kata sandi pengguna disimpan sebagai hash dan diverifikasi saat login. Sesi digunakan untuk mengenali pengguna yang masuk. Prepared statement memisahkan nilai input dari struktur SQL. Input pengukuran divalidasi sebelum ditulis.

Kolom role sudah tersedia, tetapi requireAuth memeriksa keberadaan sesi, bukan seluruh aturan izin per peran. Keberadaan role tidak cukup untuk menyatakan pembatasan admin/user sudah lengkap. Penggunaan di luar lingkungan lokal memerlukan pengaturan akun database, HTTPS, serta pembatasan akses yang sesuai.

## Batas yang perlu disampaikan dengan jujur

- Grafik dashboard masih simulasi; autentikasi dan koneksi ESP32 fisik belum diterapkan.
- Jadwal Dithane adalah konfigurasi. Tidak ada bukti bahwa relay, pompa, atau penyemprotan otomatis sudah berjalan.
- Satu jadwal berulang disimpan; tabel ini belum mencatat dosis, tanggal pelaksanaan individual, atau status penyelesaian.
- history_logs tidak otomatis diisi pada setiap penyimpanan pembacaan.
- Grafik riwayat pada versi proyek yang diperiksa masih memakai data JavaScript; tabel riwayat membaca database. Jangan menyamakan keduanya.
- Notifikasi dan status bacanya bersifat global karena tidak mempunyai user_id penerima.
- updated_by hanya menunjukkan pengubah terakhir, bukan seluruh riwayat perubahan.
- Referensi type ke settings tidak menyimpan versi batas lama. Evaluasi historis yang dapat diaudit memerlukan penyimpanan versi atau salinan batas saat pengukuran.
- Tanpa tabel identitas perangkat, dua sensor pH di lokasi berbeda belum dapat dibedakan. Kebutuhan itu memerlukan tambahan penanda atau model perangkat.

# 10. Pertanyaan dosen dan jawaban singkat

## 1. Mengapa disebut sembilan tabel, bukan sepuluh database?

> “Satu database berisi sembilan tabel. Rancangan sepuluh tabel sebelumnya masih menghitung tabel identitas sensor. Setelah tabel itu dihapus, struktur aktif menjadi sembilan tabel.”

## 2. Mengapa hasil pH dan PPM dipisahkan?

> “Pemisahan mengikuti kebutuhan rancangan proyek supaya jenis nilai dan tipe datanya jelas: pH desimal, PPM bilangan bulat. Keduanya punya tabel pengukuran masing-masing.”

## 3. Apakah satu tabel gabungan sebelumnya salah?

> “Tidak otomatis salah. Satu tabel pembacaan dengan identitas jenis juga dapat digunakan. Proyek ini memilih tabel terpisah sesuai kebutuhan dan arahan perancangan.”

## 4. Mengapa tabel sensors dihapus?

> “Lingkup sistem hanya satu sumber pH dan satu sumber PPM. Identitas perangkat belum dikelola, sehingga jenis sumber sudah diketahui dari tabelnya.”

## 5. Mengapa PPM menggunakan INT?

> “Sistem mengirim PPM tanpa pecahan, seperti 850. INT sesuai dengan kontrak data tersebut. Ini keputusan ketelitian pada proyek, bukan pernyataan bahwa semua pengukuran ppm selalu bulat.”

## 6. Mengapa pH menggunakan DECIMAL?

> “pH membutuhkan pecahan, misalnya 6,25. DECIMAL menyimpan angka dengan jumlah digit pecahan tertentu. Empat digit dipertahankan pada penyimpanan, tetapi akurasi fisik bergantung pada alat dan kalibrasi.”

## 7. Jika batas PPM 700–1000, mengapa 1200 boleh tersimpan?

> “700–1000 adalah contoh rentang normal, bukan batas penyimpanan. Nilai 1200 perlu disimpan supaya kondisi tinggi tercatat dan dapat menghasilkan peringatan.”

## 8. Apa bentuk data yang dikirim?

> “Nilainya berupa angka pH dan PPM yang sudah diproses. Pesan dikirim dalam format JSON dengan nama ph_value dan ppm_value; database menyimpannya sebagai DECIMAL dan INT.”

## 9. Apakah ID pembacaan pH dan PPM boleh sama?

> “Boleh, karena primary key unik dalam tabel masing-masing. ID 1 pada ph_readings berbeda dari ID 1 pada ppm_readings. Relasi menggunakan kolom foreign key yang terpisah.”

## 10. Apakah foreign key otomatis membuat notifikasi?

> “Tidak. PHP memeriksa nilai dan membuat notifikasi. Foreign key memastikan notifikasi itu merujuk ke pembacaan yang valid.”

## 11. Mengapa ada dua foreign key pada monitoring?

> “Sumbernya dapat berupa pH atau PPM. Karena sumber berada pada dua tabel, referensinya dipisahkan. Aturan CHECK mencegah kedua ID terisi sekaligus.”

## 12. Mengapa settings.type masih memiliki kategori dithane?

> “Kategori itu masih dirujuk oleh monitoring dan riwayat. Parameter jadwalnya sudah dipindahkan ke dithane_schedules, sehingga config_json untuk kategori Dithane tidak lagi menyimpan jadwal.”

## 13. Apakah Dithane mempunyai banyak jadwal?

> “Implementasi sekarang hanya satu konfigurasi berulang, dibatasi id = 1. Banyak agenda bertanggal atau catatan pelaksanaan belum dibuat.”

## 14. Apa bedanya pembacaan, monitoring, dan riwayat?

> “Pembacaan menyimpan angka sumber. Monitoring menyimpan catatan evaluasi beserta status. Riwayat menyimpan kejadian berdasarkan kategori dan tanggal, dan tidak otomatis menjadi salinan semua pembacaan.”

## 15. Mengapa menggunakan transaksi?

> “Supaya pembacaan, monitoring, dan notifikasi tersimpan sebagai satu kesatuan. Jika satu tahap gagal, perubahan yang belum disahkan dibatalkan.”

## 16. Mengapa data tidak langsung dihapus?

> “Soft delete menyembunyikan catatan melalui is_deleted tanpa langsung menghilangkan barisnya. Sementara itu, pembacaan yang masih dirujuk dilindungi oleh foreign key.”

## 17. Apakah tabel jadwal berarti penyemprotan sudah otomatis?

> “Belum. Tabel menyimpan konfigurasi waktu. Pengendalian pompa dan bukti pelaksanaan membutuhkan proses serta integrasi perangkat tambahan.”

## 18. Mengapa perubahan terminal terlihat di phpMyAdmin?

> “Keduanya mengakses MySQL yang sama jika server, port, dan nama database cocok. phpMyAdmin hanyalah antarmuka pengelolaan.”

## 19. Apakah website berhenti jika phpMyAdmin ditutup?

> “Tidak. Website tetap berjalan selama server PHP dan MySQL aktif. Backend mengakses MySQL melalui PDO.”

## 20. Apakah sudah memenuhi normalisasi sempurna?

> “Data sudah dipisahkan menurut fungsi, tetapi kami tidak mengklaim seluruh desain terbukti 3NF. Nama hari yang dapat diturunkan dari tanggal, nilai salinan monitoring, dan konfigurasi JSON masih merupakan pilihan desain yang perlu dijelaskan.”

# 11. Naskah ringkas untuk enam anggota

Durasi sasaran sekitar 8–10 menit. Gunakan ERD terbaru dari phpMyAdmin sebagai pendamping. Bab sebelumnya menjadi bahan untuk menjawab pertanyaan, sehingga tidak semua detail kolom perlu dibaca saat presentasi.

## Anggota 1 — Pembukaan dan konsep

> “Assalamualaikum, kami akan menjelaskan database Hydrotech. Sistem ini menggunakan satu database dengan sembilan tabel. ERD menunjukkan tabel, kolom, dan hubungan datanya. Primary key adalah identitas unik, sedangkan foreign key menghubungkan data antartabel. Struktur terbaru memisahkan hasil pH, PPM, dan jadwal Dithane agar fungsi penyimpanannya jelas.”

## Anggota 2 — Akun, profil, dan pengaturan

> “Tabel users menyimpan akun login, profile menyimpan informasi pemilik dan tim, sedangkan settings menyimpan batas pH dan PPM serta kategori log. Kolom updated_by pada profil dan pengaturan merujuk ke users.id untuk menunjukkan pengubah terakhir. Satu pengguna dapat memperbarui beberapa baris informasi. Kata sandi akun disimpan dalam bentuk hash.”

## Anggota 3 — Pengukuran pH dan PPM

> “Tabel ph_readings menyimpan id, ph_value, dan recorded_at. pH menggunakan DECIMAL karena mempunyai pecahan. Tabel ppm_readings menyimpan id, ppm_value, dan recorded_at, tetapi nilainya menggunakan INT karena sistem mengirim PPM sebagai bilangan bulat. ID kedua tabel boleh sama karena ruang identitasnya berbeda. Tabel perangkat tidak digunakan karena identitas sensor belum dikelola.”

## Anggota 4 — Jadwal dan pencatatan

> “Dithane mempunyai tabel dithane_schedules untuk interval dalam jam, durasi dalam detik, dan jam mulai. Saat ini hanya ada satu konfigurasi jadwal berulang. Monitoring menyimpan evaluasi nilai dan status, history_logs menyimpan riwayat berdasarkan tanggal, sedangkan notifications menyimpan pesan. Jadwal yang tersimpan belum berarti penyemprotan fisik sudah dijalankan.”

## Anggota 5 — Relasi ERD dan integritas

> “Ada sembilan foreign key. Pengaturan, profil, dan jadwal merujuk ke pengguna. Monitoring dan riwayat merujuk ke kategori settings. Monitoring serta notifikasi memiliki referensi terpisah ke pembacaan pH dan PPM. Kedua referensi pembacaan tidak boleh diisi bersamaan. Satu pembacaan dapat mempunyai banyak catatan terkait, sedangkan jadwal dibatasi satu baris untuk seluruh sistem. Foreign key menjaga referensi; transaksi menjaga agar penyimpanan tidak terjadi sebagian.”

## Anggota 6 — Integrasi web dan penutup

> “Browser mengirim permintaan ke PHP, kemudian PHP memakai PDO untuk mengakses MySQL. Terminal Laragon dan phpMyAdmin dapat melihat database yang sama. Pada versi sekarang, grafik masih menghasilkan data simulasi yang dikirim sebagai JSON untuk disimpan. Integrasi ESP32 fisik menjadi pengembangan berikutnya. Kesimpulannya, pembagian tabel dan relasi membuat data pengukuran, pengaturan, serta catatan dapat dikelola dengan jelas. Terima kasih.”

# 12. Ringkasan akhir dan sumber proyek

## Lima hal yang perlu diingat

- Jumlah aktif: **9 tabel, 60 kolom, dan 9 foreign key**.
- pH: **DECIMAL(6,4)**; PPM: **INT**; contoh pesan: ph_value 6.25 dan ppm_value 850.
- Sumber monitoring/notifikasi dibedakan melalui **ph_reading_id** dan **ppm_reading_id**.
- Jadwal Dithane: **satu konfigurasi berulang**, bukan bukti penyemprotan.
- Database terhubung ke web melalui **PHP + PDO + MySQL**; grafik saat ini masih simulasi.

## Sumber penyusunan

Materi ini disusun dari pemeriksaan lokal pada 28 September 2026, bukan dari asumsi gambar ERD lama. Contoh nilai dan tanggal dalam dokumen adalah ilustrasi, kecuali keterangan struktur yang diperiksa langsung.

| Sumber | Bagian yang diperiksa |
|---|---|
| database/schema.sql | Sembilan tabel, tipe data, indeks, CHECK, dan foreign key |
| Database lokal hydrotech dan monitoring_sensor | Jumlah tabel/kolom, relasi, dan ppm_value INT |
| config/database.php | Konfigurasi koneksi PDO |
| includes/sensors.php | Validasi, transaksi, monitoring, dan notifikasi |
| includes/dithane.php | Validasi dan penyimpanan jadwal |
| includes/auth_check.php | Pemeriksaan sesi login |
| api/sensor/save.php | Penerimaan JSON atau form |
| api/dashboard/summary.php | Pengambilan ringkasan dan konfigurasi |
| js/dashboard-live.js | Simulasi dan pengiriman data setiap sekitar 1,8 detik |
| database/README.md | Catatan migrasi dan batas implementasi |

**Gunakan dokumen ini untuk menggantikan materi lama yang masih menjelaskan delapan tabel atau PPM bertipe desimal.**
