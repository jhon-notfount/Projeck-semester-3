HYDROTECH - Dashboard Monitoring Hidroponik
===========================================

Hydrotech adalah aplikasi dashboard berbasis web untuk membantu admin memantau
dan mengelola sistem hidroponik. Aplikasi ini menampilkan monitoring pH, PPM,
jadwal Dithane, data history, grafik tren, notifikasi, profile admin, serta
form pengaturan nilai batas dan jadwal penyemprotan.

Proyek ini dibuat sebagai aplikasi frontend statis. Artinya seluruh tampilan dan
interaksi berjalan langsung di browser menggunakan HTML, CSS, dan JavaScript,
tanpa backend/server khusus dan tanpa database.


1. Teknologi yang Digunakan
---------------------------

Bahasa utama:
- HTML5
  Digunakan untuk membuat struktur halaman seperti login, dashboard, profile,
  pengaturan, input data, history, dan halaman popup.

- CSS3
  Digunakan untuk mengatur tampilan, layout, warna, animasi, responsive design,
  sidebar, topbar, card, tabel, form, popup, dan komponen visual lainnya.

- JavaScript Vanilla
  Digunakan untuk membuat interaksi halaman tanpa framework tambahan, seperti
  validasi login, dropdown sidebar, jam real-time, notifikasi, filter data,
  penyimpanan pengaturan, grafik live, modal konfirmasi, hapus data, pagination,
  dan export laporan PDF.

Framework:
- Proyek ini tidak menggunakan framework frontend seperti React, Vue, Angular,
  Bootstrap, Tailwind, Laravel, CodeIgniter, atau framework lainnya.
- Semua halaman dibuat manual menggunakan HTML, CSS, dan JavaScript murni.

Database:
- Proyek ini tidak menggunakan database seperti MySQL, PostgreSQL, SQLite,
  MongoDB, Firebase, atau database server lainnya.
- Penyimpanan data sementara dilakukan menggunakan localStorage milik browser.


2. Library Eksternal
--------------------

Beberapa library eksternal digunakan melalui CDN:

- Google Fonts - Poppins
  Digunakan sebagai font utama aplikasi.
  Import berada di css/global.css.

- Font Awesome 6.5.2
  Digunakan pada halaman login untuk icon user, password, shield, dan icon mata.

- SweetAlert2
  Digunakan untuk popup/modal konfirmasi yang lebih rapi, misalnya logout,
  reset filter, hapus data, dan pesan error.

- Chart.js
  Digunakan untuk membuat grafik monitoring pH dan PPM pada dashboard dan
  halaman history.

- jsPDF
  Digunakan untuk membuat file PDF laporan history bulanan.

- jsPDF AutoTable
  Digunakan bersama jsPDF untuk membuat tabel otomatis di dalam laporan PDF.

Catatan:
- Karena beberapa library diambil dari CDN, fitur tertentu membutuhkan koneksi
  internet saat pertama kali halaman dibuka.
- Jika tidak ada internet, HTML/CSS lokal tetap bisa terbuka, tetapi icon CDN,
  popup SweetAlert2, grafik Chart.js, dan export PDF dapat tidak berjalan
  sempurna.


3. Cara Menjalankan Aplikasi
----------------------------

Karena proyek ini adalah frontend statis, aplikasi dapat dijalankan langsung
dari browser.

Langkah menjalankan:
1. Buka file index.html di browser.
2. index.html akan mengarahkan pengguna ke pages/login.html.
3. Login menggunakan akun admin.
4. Setelah login berhasil, pengguna diarahkan ke halaman dashboard.

Akun login demo:
- Username: hydrotech
- Password: Admin123

Catatan login:
- Validasi login dilakukan di sisi frontend pada file pages/login.html.
- Login ini hanya untuk simulasi/prototype, bukan sistem autentikasi backend
  yang aman untuk produksi.


4. Struktur Folder dan File
---------------------------

Struktur utama proyek:

- index.html
  Halaman awal yang melakukan redirect ke pages/login.html.

- pages/
  Berisi semua file HTML untuk halaman aplikasi.

- css/
  Berisi semua file stylesheet untuk tampilan aplikasi.

- js/
  Berisi semua file JavaScript untuk interaksi dan logika frontend.

- assets/
  Berisi aset gambar seperti logo dan background.

- README.txt
  Dokumentasi proyek.

- README.md
  File README tambahan.

- make_inputs.sh
  Script bantuan untuk membuat/menyiapkan halaman input.

- make_settings.sh
  Script bantuan untuk membuat/menyiapkan halaman pengaturan.

- partial_app_start.tmp
  File sementara/partial yang berisi potongan struktur aplikasi.


5. Daftar Halaman HTML
----------------------

File HTML dipisah per halaman:

- index.html
  Redirect ke halaman login.

- pages/login.html
  Halaman login admin Hydrotech.

- pages/hydrotech-dashboard-panel-2026.html
  Halaman dashboard utama untuk monitoring ringkas, grafik live, statistik,
  aktivitas, dan navigasi utama.

- pages/profile.html
  Halaman profile admin.

- pages/pengaturan-ppm.html
  Halaman pengaturan batas minimum dan maksimum PPM.

- pages/pengaturan-ph.html
  Halaman pengaturan batas minimum dan maksimum pH.

- pages/pengaturan-dithane.html
  Halaman pengaturan interval, durasi, dan jam mulai penyemprotan Dithane.

- pages/input-ppm.html
  Form input/perubahan data pengaturan PPM.

- pages/input-ph.html
  Form input/perubahan data pengaturan pH.

- pages/input-dithane.html
  Form input/perubahan data pengaturan Dithane.

- pages/history.html
  Halaman riwayat monitoring dengan fitur pencarian, filter, pagination, grafik,
  hapus data, dan export PDF.

- pages/popup-hapus.html
  Contoh tampilan popup hapus.

- pages/popup-logout.html
  Contoh tampilan popup logout.

- pages/popup-reset.html
  Contoh tampilan popup reset.

- pages/popup-edit-pengaturan.html
  Contoh tampilan popup edit pengaturan.

- pages/popup-export-history.html
  Contoh tampilan popup export history.

- pages/popup-notifikasi.html
  Contoh tampilan popup notifikasi.

- pages/popup-berhasil.html
  Contoh tampilan popup berhasil.


6. Daftar File CSS
------------------

- css/global.css
  File CSS global yang berisi variabel warna, font, sidebar, topbar, modal,
  toast, layout umum, animasi, dan komponen yang dipakai lintas halaman.

- css/login.css
  Styling halaman login.

- css/hydrotech-dashboard-panel-2026.css
  Styling halaman dashboard utama.

- css/profile.css
  Styling halaman profile admin.

- css/history.css
  Styling halaman history, filter, tabel, grafik, dan area export.

- css/pengaturan-ppm.css
  Styling halaman pengaturan PPM.

- css/pengaturan-ph.css
  Styling halaman pengaturan pH.

- css/pengaturan-dithane.css
  Styling halaman pengaturan Dithane.

- css/input-ppm.css
  Styling form input PPM.

- css/input-ph.css
  Styling form input pH.

- css/input-dithane.css
  Styling form input Dithane.

- css/popup-berhasil.css
  Styling popup berhasil.

- css/popup-edit-pengaturan.css
  Styling popup edit pengaturan.

- css/popup-export-history.css
  Styling popup export history.

- css/popup-hapus.css
  Styling popup hapus.

- css/popup-logout.css
  Styling popup logout.

- css/popup-notifikasi.css
  Styling popup notifikasi.

- css/popup-reset.css
  Styling popup reset.


7. Daftar File JavaScript
-------------------------

- js/confirm-modal.js
  Mengatur modal konfirmasi, toast berhasil/error, dropdown fitur sidebar,
  jam real-time di sidebar, notifikasi, logout, reset filter, dan beberapa
  interaksi global.

- js/dashboard-live.js
  Mengatur grafik live pH dan PPM pada dashboard menggunakan Chart.js.
  Nilai pH dan PPM disimulasikan secara dinamis dengan interval waktu tertentu.

- js/dashboard-activity.js
  Mengatur filter aktivitas pada dashboard.

- js/settings-storage.js
  Mengatur penyimpanan pengaturan PPM, pH, dan Dithane menggunakan localStorage.
  File ini juga menangani form input, edit pengaturan, reset form, hapus baris
  tabel pada halaman pengaturan, dan toast sukses.

- js/history.js
  Mengatur halaman history, termasuk pencarian, filter, pagination, hapus data,
  grafik tren pH/PPM, pemilihan bulan, dan export laporan history ke PDF.


8. Aset Gambar
--------------

Folder assets berisi:

- assets/Background.png
  Gambar background halaman login.

- assets/Logo_hydrotech.png
  Logo Hydrotech.

- assets/Logo_Polije.png
  Logo Polije.

- assets/Logo_TRK.png
  Logo TRK.


9. Penyimpanan Data Tanpa Database
----------------------------------

Karena proyek ini tidak memakai database, data disimpan di browser menggunakan
localStorage.

localStorage adalah fitur bawaan browser untuk menyimpan data sederhana dalam
bentuk key-value. Data tetap tersimpan walaupun halaman di-refresh atau browser
ditutup, selama cache/storage browser tidak dihapus.

Data yang disimpan:

- Pengaturan PPM
  Key: hydrotech.ppmSettings
  Isi data: nilai minimum PPM, maksimum PPM, dan waktu update terakhir.
  Default: min 800, max 1000.

- Pengaturan pH
  Key: hydrotech.phSettings
  Isi data: nilai minimum pH, maksimum pH, dan waktu update terakhir.
  Default: min 5,5, max 6,5.

- Pengaturan Dithane
  Key: hydrotech.dithaneSettings
  Isi data: interval penyemprotan, durasi penyemprotan, jam mulai, dan waktu
  update terakhir.
  Default: interval 48 jam, durasi 10 detik, mulai 08.00 WIB.

- Data baris yang dihapus pada tabel pengaturan
  Key: hydrotech.ppmSettings.deletedRows
  Key: hydrotech.phSettings.deletedRows
  Key: hydrotech.dithaneSettings.deletedRows
  Digunakan agar baris yang dihapus tidak muncul kembali setelah refresh.

- Data baris history yang dihapus
  Key: hydrotech.history.deletedRows
  Digunakan agar data history yang dihapus tetap hilang setelah refresh.

- Status notifikasi dibaca
  Key: hydrotech.notificationsRead
  Digunakan untuk menyimpan status apakah notifikasi sudah ditandai dibaca.

Kelebihan localStorage:
- Tidak membutuhkan server.
- Tidak membutuhkan instalasi database.
- Cocok untuk prototype, demo, dan aplikasi statis sederhana.
- Data tetap ada setelah refresh halaman.

Kekurangan localStorage:
- Data hanya tersimpan di browser/perangkat yang sama.
- Data tidak otomatis tersinkron ke perangkat lain.
- Data dapat hilang jika pengguna menghapus cache/storage browser.
- Tidak cocok untuk data sensitif atau sistem produksi yang membutuhkan
  keamanan tinggi.
- Tidak ada autentikasi server dan tidak ada validasi backend.


10. Fitur Utama Aplikasi
------------------------

- Login admin
  Halaman login memiliki validasi username dan password sederhana di frontend.

- Dashboard monitoring
  Menampilkan ringkasan kondisi sistem hidroponik, nilai PPM, pH, grafik live,
  aktivitas terbaru, dan akses cepat ke fitur lain.

- Grafik live pH dan PPM
  Dashboard menampilkan grafik yang bergerak secara periodik menggunakan data
  simulasi JavaScript dan Chart.js.

- Pengaturan PPM
  Admin dapat mengatur nilai PPM minimum dan maksimum.

- Pengaturan pH
  Admin dapat mengatur nilai pH minimum dan maksimum.

- Pengaturan Dithane
  Admin dapat mengatur interval penyemprotan, durasi penyemprotan, dan jam mulai.

- Form input pengaturan
  Setiap parameter memiliki halaman input untuk mengubah nilai pengaturan.

- Edit pengaturan melalui modal
  Pada halaman pengaturan, data dapat diedit melalui modal tanpa pindah halaman.

- Toast berhasil
  Setelah data berhasil disimpan, aplikasi menampilkan pesan berhasil.

- Modal konfirmasi
  Digunakan untuk logout, reset, dan hapus data agar pengguna tidak melakukan
  aksi penting secara tidak sengaja.

- History monitoring
  Menampilkan tabel riwayat monitoring lengkap dengan pencarian, filter jenis,
  filter hari, filter bulan, filter status, pagination, dan empty state.

- Hapus data history
  Data history dapat dihapus dari tampilan dan status hapus disimpan di
  localStorage.

- Grafik history
  Halaman history menampilkan grafik tren pH dan PPM berdasarkan periode yang
  dipilih.

- Export PDF
  History bulanan dapat diexport menjadi file PDF menggunakan jsPDF dan
  jsPDF AutoTable.

- Notifikasi
  Icon notifikasi menampilkan panel daftar notifikasi sistem hidroponik.

- Jam real-time WIB
  Sidebar menampilkan tanggal dan jam real-time berdasarkan zona waktu
  Asia/Jakarta.

- Responsive design
  Tampilan dibuat agar tetap dapat digunakan pada ukuran layar berbeda.


11. Alur Penggunaan Aplikasi
----------------------------

1. Pengguna membuka index.html.
2. Sistem mengarahkan pengguna ke halaman login.
3. Pengguna login menggunakan akun admin demo.
4. Jika username dan password benar, pengguna masuk ke dashboard.
5. Pengguna dapat membuka menu Fitur untuk mengatur PPM, pH, dan Dithane.
6. Pengguna dapat menyimpan pengaturan baru melalui form input atau modal edit.
7. Data pengaturan disimpan ke localStorage.
8. Pengguna dapat membuka halaman History untuk melihat data monitoring.
9. Pengguna dapat memfilter, mencari, menghapus, atau export history ke PDF.
10. Pengguna dapat logout melalui tombol logout di sidebar.


12. Catatan Data Simulasi
-------------------------

Beberapa data pada aplikasi masih berupa data statis/simulasi karena proyek ini
belum terhubung ke sensor, backend, API, atau database.

Contoh data simulasi:
- Nilai pH dan PPM pada dashboard live.
- Data aktivitas terbaru.
- Data history monitoring.
- Tren bulanan pH dan PPM.
- Notifikasi sistem.
- Profile admin.

Data simulasi ini digunakan agar tampilan dan alur aplikasi dapat diuji tanpa
perangkat IoT atau server.


13. Batasan Aplikasi
--------------------

- Belum memiliki backend.
- Belum memiliki database server.
- Belum terhubung langsung dengan sensor hidroponik.
- Login belum menggunakan autentikasi server.
- Data masih bersifat lokal di browser.
- Export PDF bergantung pada library CDN.
- Grafik bergantung pada Chart.js dari CDN.
- File HTML sebagian dibuat terpisah per halaman, sehingga belum menggunakan
  routing seperti aplikasi SPA.


14. Rekomendasi Pengembangan Selanjutnya
----------------------------------------

Jika aplikasi ingin dikembangkan menjadi sistem produksi, beberapa hal yang
dapat ditambahkan adalah:

- Backend/API untuk menghubungkan frontend dengan database.
- Database seperti MySQL, PostgreSQL, MongoDB, Firebase, atau SQLite.
- Sistem login dengan autentikasi yang aman.
- Role user, misalnya admin dan operator.
- Integrasi sensor IoT untuk membaca pH, PPM, suhu, kelembaban, dan pompa.
- Penyimpanan history otomatis dari sensor.
- Export laporan berdasarkan data asli dari database.
- Validasi input di backend.
- Proteksi halaman agar tidak bisa dibuka tanpa login.
- Deployment ke hosting atau server.
- Mode offline/online yang lebih rapi.
- Backup dan restore data.


15. Ringkasan Teknis
--------------------

Nama proyek:
- Hydrotech

Jenis aplikasi:
- Dashboard monitoring hidroponik berbasis web statis.

Bahasa:
- HTML5
- CSS3
- JavaScript Vanilla

Framework:
- Tidak menggunakan framework.

Database:
- Tidak menggunakan database.

Penyimpanan data:
- localStorage browser.

Library:
- Google Fonts
- Font Awesome
- SweetAlert2
- Chart.js
- jsPDF
- jsPDF AutoTable

Target pengguna:
- Admin/pengelola sistem hidroponik.

Status proyek:
- Prototype frontend/dashboard statis dengan data simulasi dan penyimpanan lokal.
