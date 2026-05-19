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


11. Penjelasan Teknis Setiap Fitur
----------------------------------

Bagian ini menjelaskan fitur-fitur aplikasi dibuat menggunakan apa dan bagaimana
cara kerjanya di dalam proyek.

1. Login Admin

Login admin dibuat menggunakan:
- HTML form pada pages/login.html.
- CSS pada css/login.css.
- JavaScript langsung di pages/login.html.
- Font Awesome untuk icon user, password, shield, dan show/hide password.

Cara kerja:
- Pengguna mengisi username dan password.
- Saat tombol Masuk ditekan, fungsi handleLogin(event) dijalankan.
- JavaScript mengecek apakah username sama dengan "hydrotech" dan password sama
  dengan "Admin123".
- Jika benar, pengguna diarahkan ke pages/hydrotech-dashboard-panel-2026.html.
- Jika salah, halaman menampilkan pesan error "Username atau password salah."

Catatan:
- Login ini belum memakai database.
- Login ini belum memakai session server.
- Login ini hanya validasi sederhana di sisi frontend, cocok untuk prototype.


2. Tombol Show/Hide Password

Fitur show/hide password dibuat menggunakan:
- Input password HTML.
- JavaScript function togglePassword().
- Font Awesome icon fa-eye dan fa-eye-slash.

Cara kerja:
- Jika input bertipe password, JavaScript mengubahnya menjadi text.
- Jika input bertipe text, JavaScript mengubahnya kembali menjadi password.
- Icon mata ikut berubah sesuai kondisi input.


3. Dashboard Monitoring

Dashboard dibuat menggunakan:
- HTML pada pages/hydrotech-dashboard-panel-2026.html.
- CSS pada css/hydrotech-dashboard-panel-2026.css dan css/global.css.
- JavaScript pada js/dashboard-live.js dan js/dashboard-activity.js.
- Chart.js untuk grafik pH dan PPM.

Isi dashboard:
- Ringkasan nilai PPM.
- Ringkasan nilai pH.
- Grafik live pH dan PPM.
- Aktivitas terbaru.
- Sidebar navigasi.
- Topbar admin dan notifikasi.


4. Grafik Live pH dan PPM

Grafik live dibuat menggunakan:
- Elemen canvas HTML.
- Library Chart.js.
- JavaScript pada js/dashboard-live.js.
- setInterval() untuk memperbarui data secara berkala.
- requestAnimationFrame() untuk animasi angka agar terlihat halus.

Cara kerja:
- Data awal PPM dan pH disimpan dalam array JavaScript.
- JavaScript membuat grafik line chart menggunakan Chart.js.
- Setiap 1,8 detik, JavaScript membuat nilai baru secara simulasi.
- Nilai baru dimasukkan ke array grafik.
- Chart.js menjalankan chart.update() agar grafik bergerak.
- Angka PPM dan pH pada card dashboard juga ikut diperbarui.

Catatan:
- Data grafik live masih simulasi, belum berasal dari sensor asli.
- Jika ingin memakai sensor asli, data perlu dikirim dari backend/API atau IoT
  device ke frontend.


5. Filter Aktivitas Dashboard

Filter aktivitas dibuat menggunakan:
- HTML data attribute seperti data-activity-filter dan data-activity-type.
- JavaScript pada js/dashboard-activity.js.
- CSS untuk state aktif, dropdown, dan empty state.

Cara kerja:
- Setiap tombol filter memiliki kategori.
- JavaScript membaca kategori tombol yang dipilih.
- Baris aktivitas yang cocok akan ditampilkan.
- Baris yang tidak cocok akan diberi hidden.
- Jika tidak ada data yang cocok, empty state ditampilkan.


6. Sidebar Navigasi

Sidebar dibuat menggunakan:
- HTML pada tiap halaman aplikasi.
- CSS global pada css/global.css.
- JavaScript pada js/confirm-modal.js.

Fitur sidebar:
- Menu Dashboard.
- Menu Fitur dengan dropdown.
- Menu History.
- Menu Profile.
- Tombol Logout.
- Jam dan tanggal real-time.

Cara kerja dropdown:
- Tombol Fitur menjalankan function toggleDropdown().
- JavaScript menambah atau menghapus class show pada dropdown.
- Label menu Fitur berubah sesuai halaman aktif, misalnya Pengaturan PPM,
  Pengaturan pH, atau Pengaturan Dithane.


7. Jam Real-Time WIB

Jam real-time dibuat menggunakan:
- JavaScript Date.
- Intl.DateTimeFormat().
- setInterval().
- Zona waktu Asia/Jakarta.

Cara kerja:
- js/confirm-modal.js membuat elemen jam secara otomatis di sidebar.
- Tanggal diformat menggunakan locale id-ID.
- Jam diformat dengan timeZone Asia/Jakarta.
- setInterval() memperbarui jam setiap 1 detik.


8. Notifikasi

Notifikasi dibuat menggunakan:
- Tombol bell di topbar.
- JavaScript pada js/confirm-modal.js.
- localStorage untuk menyimpan status notifikasi sudah dibaca.
- CSS panel notifikasi pada css/global.css.

Cara kerja:
- Saat icon notifikasi diklik, panel notifikasi ditampilkan.
- Isi notifikasi dibuat dari array JavaScript.
- Tombol "Tandai dibaca" menyimpan status ke localStorage dengan key:
  hydrotech.notificationsRead.
- Jika sudah dibaca, tampilan notifikasi berubah menjadi state read.

Catatan:
- Notifikasi masih data statis/simulasi.
- Belum berasal dari database atau server.


9. Modal Konfirmasi

Modal konfirmasi dibuat menggunakan:
- SweetAlert2 jika library tersedia.
- Fallback modal custom jika SweetAlert2 tidak tersedia.
- JavaScript pada js/confirm-modal.js.
- CSS global untuk styling SweetAlert dan fallback modal.

Digunakan untuk:
- Logout.
- Reset filter.
- Hapus data.
- Konfirmasi aksi penting lainnya.

Cara kerja:
- Kode memanggil window.HydrotechConfirm.open({...}).
- Jika window.Swal tersedia, aplikasi memakai SweetAlert2.
- Jika SweetAlert2 tidak tersedia, aplikasi membuat modal custom dengan HTML
  dan JavaScript.
- Hasil konfirmasi dikembalikan dalam bentuk true atau false.


10. Toast Berhasil dan Pesan Error

Toast dibuat menggunakan:
- JavaScript pada js/confirm-modal.js dan js/settings-storage.js.
- CSS global pada css/global.css.
- SweetAlert2 untuk pesan error tertentu jika tersedia.

Cara kerja:
- Saat data berhasil disimpan, aplikasi memanggil HydrotechToast.success().
- JavaScript membuat elemen toast jika belum ada.
- Toast diberi class show agar muncul dengan animasi.
- Setelah beberapa detik, toast otomatis disembunyikan.


11. Pengaturan PPM

Pengaturan PPM dibuat menggunakan:
- pages/pengaturan-ppm.html.
- pages/input-ppm.html.
- css/pengaturan-ppm.css.
- css/input-ppm.css.
- js/settings-storage.js.
- localStorage.

Data yang disimpan:
- PPM minimum.
- PPM maksimum.
- Waktu update terakhir.

Key localStorage:
- hydrotech.ppmSettings

Default:
- Minimum: 800 PPM.
- Maksimum: 1000 PPM.

Cara kerja:
- Saat halaman dibuka, JavaScript membaca data dari localStorage.
- Jika belum ada data, aplikasi memakai nilai default.
- Saat admin menyimpan data baru, data disimpan sebagai JSON string di
  localStorage.
- Halaman pengaturan menampilkan data terbaru setelah disimpan.


12. Pengaturan pH

Pengaturan pH dibuat menggunakan:
- pages/pengaturan-ph.html.
- pages/input-ph.html.
- css/pengaturan-ph.css.
- css/input-ph.css.
- js/settings-storage.js.
- localStorage.

Data yang disimpan:
- pH minimum.
- pH maksimum.
- Waktu update terakhir.

Key localStorage:
- hydrotech.phSettings

Default:
- Minimum: 5,5 pH.
- Maksimum: 6,5 pH.

Cara kerja:
- Sama seperti pengaturan PPM.
- JavaScript membaca, menampilkan, mengubah, dan menyimpan data melalui
  localStorage.


13. Pengaturan Dithane

Pengaturan Dithane dibuat menggunakan:
- pages/pengaturan-dithane.html.
- pages/input-dithane.html.
- css/pengaturan-dithane.css.
- css/input-dithane.css.
- js/settings-storage.js.
- localStorage.

Data yang disimpan:
- Interval penyemprotan.
- Durasi penyemprotan.
- Jam mulai penyemprotan.
- Waktu update terakhir.

Key localStorage:
- hydrotech.dithaneSettings

Default:
- Interval: 48 jam.
- Durasi: 10 detik.
- Jam mulai: 08.00 WIB.

Cara kerja:
- Input jam menggunakan type time.
- Nilai jam disesuaikan agar bisa tampil sebagai format WIB.
- Data disimpan di localStorage dalam bentuk JSON string.


14. Form Input Pengaturan

Form input pengaturan dibuat menggunakan:
- pages/input-ppm.html.
- pages/input-ph.html.
- pages/input-dithane.html.
- File CSS input masing-masing.
- js/settings-storage.js.

Cara kerja:
- JavaScript mendeteksi halaman yang sedang dibuka.
- Berdasarkan nama halaman, JavaScript memilih konfigurasi PPM, pH, atau
  Dithane.
- Input form diisi otomatis dari data localStorage atau default.
- Tombol Simpan menyimpan data ke localStorage.
- Tombol Batal mengarahkan pengguna kembali ke halaman pengaturan.
- Tombol Ulang mengembalikan form ke nilai default.


15. Edit Pengaturan Melalui Modal

Edit pengaturan dibuat menggunakan:
- Tombol edit pada halaman pengaturan.
- Modal custom yang dibuat oleh js/settings-storage.js.
- localStorage untuk menyimpan perubahan.

Cara kerja:
- Saat tombol edit diklik, modal edit ditampilkan.
- Field modal diisi dengan data terbaru dari localStorage.
- Saat Simpan Perubahan ditekan, JavaScript memvalidasi agar semua field terisi.
- Data baru disimpan ke localStorage.
- Halaman pengaturan diperbarui tanpa reload penuh.
- Toast berhasil ditampilkan.


16. Hapus Baris pada Tabel Pengaturan

Fitur hapus baris dibuat menggunakan:
- Tombol trash pada tabel.
- Modal konfirmasi HydrotechConfirm.
- localStorage untuk menyimpan ID baris yang dihapus.

Key localStorage:
- hydrotech.ppmSettings.deletedRows
- hydrotech.phSettings.deletedRows
- hydrotech.dithaneSettings.deletedRows

Cara kerja:
- Saat tombol hapus diklik, modal konfirmasi muncul.
- Jika pengguna menyetujui, ID baris dimasukkan ke array deletedRows.
- Array tersebut disimpan ke localStorage.
- Baris dihapus dari tampilan.
- Saat halaman di-refresh, JavaScript membaca deletedRows dan menghapus baris
  yang ID-nya sudah tersimpan.


17. Halaman History

History dibuat menggunakan:
- pages/history.html.
- css/history.css.
- js/history.js.
- Chart.js untuk grafik history.
- jsPDF dan jsPDF AutoTable untuk export PDF.
- SweetAlert2 untuk pemilihan bulan export.
- localStorage untuk menyimpan data history yang dihapus.

Fitur history:
- Pencarian data.
- Filter tipe monitoring.
- Filter hari.
- Filter bulan.
- Filter status.
- Reset filter.
- Pagination.
- Empty state jika data tidak ditemukan.
- Hapus data history.
- Grafik tren pH dan PPM.
- Export laporan PDF.


18. Search dan Filter History

Search dan filter history dibuat menggunakan:
- Input search HTML.
- Select filter HTML.
- JavaScript pada js/history.js.
- Data attribute seperti data-history-search, data-history-type,
  data-history-day, data-history-month, dan data-history-status.

Cara kerja:
- JavaScript membaca nilai search dan filter.
- Setiap baris tabel dicek apakah cocok dengan keyword dan filter.
- Baris yang cocok dimasukkan ke daftar filteredRows.
- Tabel dirender ulang sesuai hasil filter.
- Teks "Menampilkan x-y dari z data" ikut diperbarui.


19. Pagination History

Pagination dibuat menggunakan:
- JavaScript pada js/history.js.
- Variabel rowsPerPage.
- Tombol previous, nomor halaman, dan next yang dibuat otomatis.

Cara kerja:
- Data hasil filter dibagi per halaman.
- Dalam proyek ini jumlah data per halaman adalah 5 baris.
- JavaScript menghitung total halaman dari jumlah data.
- Tombol pagination dibuat menggunakan document.createElement().
- Saat halaman pagination diklik, tabel dirender ulang.


20. Grafik History

Grafik history dibuat menggunakan:
- Canvas HTML dengan id historyLineChart.
- Chart.js.
- JavaScript pada js/history.js.

Cara kerja:
- JavaScript membuat data tren bulanan pH dan PPM.
- Admin dapat memilih bulan dan rentang grafik.
- Data grafik dirender sebagai line chart.
- Grafik memiliki dua sumbu Y:
  - Sumbu pH di kiri.
  - Sumbu PPM di kanan.
- Chart.js digunakan untuk tooltip, line chart, warna garis, gradient, dan
  update grafik.

Catatan:
- Data grafik history masih simulasi.
- Data belum berasal dari database.


21. Download PDF / Export History

Fitur Download PDF dibuat menggunakan:
- jsPDF.
- jsPDF AutoTable.
- Chart.js.
- JavaScript pada js/history.js.
- SweetAlert2 untuk memilih bulan laporan.

Cara kerja export PDF:
- Pengguna menekan tombol export/download pada halaman history.
- Aplikasi menampilkan pilihan bulan laporan.
- Jika SweetAlert2 tersedia, pilihan bulan ditampilkan menggunakan modal select.
- Jika SweetAlert2 tidak tersedia, aplikasi memakai prompt bawaan browser.
- Setelah bulan dipilih, JavaScript membuat objek PDF menggunakan jsPDF.
- Data history dan data tren bulanan diproses menjadi ringkasan laporan.
- jsPDF menulis judul, ringkasan, kesimpulan, dan teks laporan.
- jsPDF AutoTable membuat tabel data agar rapi di dalam PDF.
- Chart.js membuat gambar grafik sementara menggunakan elemen canvas.
- Canvas grafik diubah menjadi gambar base64.
- Gambar grafik dimasukkan ke PDF.
- File PDF disimpan menggunakan doc.save().

Library yang berperan:
- jsPDF:
  Membuat dokumen PDF, menulis teks, membuat halaman, menambah gambar, dan
  menyimpan file PDF.

- jsPDF AutoTable:
  Membuat tabel PDF otomatis dari array data, termasuk header, isi tabel,
  ukuran kolom, style, dan pergantian halaman.

- Chart.js:
  Membuat grafik pH dan PPM yang kemudian dapat dimasukkan sebagai gambar ke
  dalam PDF.

- SweetAlert2:
  Menampilkan popup pemilihan bulan sebelum PDF dibuat.

Isi laporan PDF:
- Judul laporan.
- Waktu laporan dibuat.
- Ringkasan data bulanan.
- Statistik status monitoring.
- Rata-rata pH.
- Rata-rata PPM.
- Rekomendasi untuk pemilik.
- Tabel data history bulanan.
- Grafik tren pH dan PPM.
- Lampiran tren harian.
- Metodologi dan catatan.
- Kesimpulan pemilik.

Catatan:
- Download PDF membutuhkan library CDN jsPDF dan jsPDF AutoTable.
- Jika CDN gagal dimuat, export PDF tidak dapat berjalan sempurna.


22. Hapus Data History

Hapus data history dibuat menggunakan:
- Tombol trash pada tabel history.
- Modal konfirmasi HydrotechConfirm.
- localStorage.

Key localStorage:
- hydrotech.history.deletedRows

Cara kerja:
- Setiap baris history memiliki data id.
- Saat baris dihapus, id baris disimpan ke localStorage.
- Baris langsung dihapus dari tampilan.
- Saat halaman dibuka lagi, JavaScript membaca daftar id yang sudah dihapus dan
  menghilangkan baris tersebut dari tabel.


23. Reset Filter

Reset filter dibuat menggunakan:
- Tombol reset.
- JavaScript pada js/history.js dan js/confirm-modal.js.
- Modal konfirmasi HydrotechConfirm.

Cara kerja:
- Saat tombol reset ditekan, aplikasi meminta konfirmasi.
- Jika disetujui, nilai input search dan semua select filter dikosongkan.
- Halaman history kembali menampilkan data dari awal.


24. Profile Admin

Profile admin dibuat menggunakan:
- pages/profile.html.
- css/profile.css.
- Struktur HTML statis.
- confirm-modal.js untuk komponen global seperti sidebar, logout, jam, dan
  notifikasi.

Catatan:
- Data profile masih statis.
- Belum tersambung ke database user.


25. Halaman Popup

Halaman popup dibuat menggunakan:
- File HTML popup di folder pages.
- File CSS popup masing-masing.
- SweetAlert2 pada beberapa popup.

Fungsi:
- Menjadi contoh desain popup untuk aksi berhasil, hapus, logout, reset,
  edit pengaturan, export history, dan notifikasi.

Catatan:
- Sebagian popup sudah digantikan secara dinamis oleh SweetAlert2 atau modal
  custom melalui JavaScript.
- File popup tetap berguna sebagai contoh tampilan atau referensi desain.


26. Responsive Design

Responsive design dibuat menggunakan:
- CSS media query.
- Layout flexbox.
- Grid CSS.
- Ukuran elemen yang menyesuaikan viewport.
- Aturan khusus pada css/global.css dan CSS halaman masing-masing.

Tujuan:
- Tampilan tetap rapi pada laptop, tablet, dan layar yang lebih kecil.
- Sidebar, tabel, grafik, form, dan card tetap bisa digunakan dengan nyaman.


27. Penyimpanan localStorage

Penyimpanan localStorage dibuat menggunakan:
- localStorage.getItem().
- localStorage.setItem().
- JSON.parse().
- JSON.stringify().

Cara kerja:
- Data object JavaScript diubah menjadi string JSON menggunakan JSON.stringify().
- String tersebut disimpan ke localStorage.
- Saat dibaca kembali, string JSON diubah menjadi object menggunakan
  JSON.parse().
- Jika data belum ada atau rusak, aplikasi memakai nilai default.

Contoh pola penyimpanan:
- Simpan data:
  localStorage.setItem("hydrotech.ppmSettings", JSON.stringify(data));

- Baca data:
  JSON.parse(localStorage.getItem("hydrotech.ppmSettings") || "{}");


28. Data Statis dan Data Dinamis

Data statis:
- Struktur halaman.
- Tabel awal history.
- Profile admin.
- Beberapa konten aktivitas.
- Beberapa isi notifikasi.

Data dinamis:
- Nilai grafik live dashboard.
- Nilai pengaturan PPM, pH, dan Dithane.
- Status baris yang dihapus.
- Status notifikasi dibaca.
- Hasil filter dan pagination.
- Laporan PDF yang dibuat berdasarkan pilihan bulan.


29. Keamanan Aplikasi

Karena aplikasi ini masih frontend statis:
- Username dan password masih terlihat di source code.
- Tidak ada session login yang aman.
- Tidak ada proteksi halaman dari server.
- Data localStorage bisa dilihat dan diubah lewat DevTools browser.
- Belum cocok untuk data sensitif.

Untuk versi produksi, fitur keamanan perlu ditingkatkan dengan:
- Backend authentication.
- Password hashing.
- Token/session.
- Middleware proteksi halaman.
- Database user.
- Validasi input di server.


12. Alur Penggunaan Aplikasi
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


13. Catatan Data Simulasi
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


14. Batasan Aplikasi
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


15. Rekomendasi Pengembangan Selanjutnya
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


16. Ringkasan Teknis
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
