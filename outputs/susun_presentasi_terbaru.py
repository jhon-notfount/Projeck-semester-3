from pathlib import Path
import re

root = Path(__file__).resolve().parent
schema = (root.parent / 'database/schema.sql').read_text(encoding='utf-8')
items = {
'users': ('Akun pengguna', 'Menyimpan akun untuk masuk ke aplikasi. Akun aplikasi berbeda dengan akun MySQL yang digunakan oleh PDO atau phpMyAdmin.', [
'Identitas unik pengguna; PK dan AUTO_INCREMENT.', 'Nama akun login; wajib dan UNIQUE.', 'Hash kata sandi; bukan teks kata sandi asli.', 'Alamat email; boleh NULL.', 'Pilihan admin atau user; nilai awal admin.', 'Waktu akun dibuat; default waktu saat penambahan.', 'Waktu perubahan terakhir; diperbarui otomatis saat baris berubah.'
], 'Satu pengguna dapat menjadi pengubah terakhir beberapa settings dan profile. Jadwal merujuk ke pengguna melalui updated_by, tetapi jumlah jadwal dibatasi satu baris secara global.', 'Contoh: id 1, username admin_kelompok, role admin. Jangan menampilkan hash password saat demonstrasi.'),
'settings': ('Pengaturan dan kategori', 'Menyimpan batas normal pH/PPM serta kategori yang dirujuk monitoring dan riwayat.', [
'Identitas pengaturan; PK dan AUTO_INCREMENT.', 'Kategori ppm, ph, atau dithane; NOT NULL dan UNIQUE.', 'Objek JSON konfigurasi. Kategori dithane saat ini berisi objek kosong karena jadwal dipindahkan.', 'Waktu perubahan terakhir.', 'FK ke users.id; boleh NULL. Menunjukkan pengubah terakhir.'
], 'updated_by merujuk ke users.id. type dirujuk oleh monitoring_logs.type dan history_logs.type. JSON valid belum tentu mempunyai konfigurasi bisnis yang benar; min dan max tetap perlu diperiksa.', 'Ilustrasi PPM: {"min":"700","max":"1000"}. Ini contoh rentang normal, bukan pembatas seluruh nilai yang boleh disimpan. Default installer proyek adalah 800–1000.'),
'profile': ('Profil website', 'Menyimpan konten informasi pemilik dan tim pada halaman profil, bukan akun login pribadi.', [
'Identitas informasi profil; PK dan AUTO_INCREMENT.', 'Kelompok informasi: owner atau team.', 'Judul informasi, misalnya Nama Tim.', 'Isi informasi yang ditampilkan.', 'Urutan tampilan; default 0.', 'FK ke users.id; boleh NULL. Pengubah terakhir informasi.'
], 'profile.updated_by merujuk ke users.id. Satu pengguna dapat memperbarui beberapa baris profil. Hubungan ini tidak menyatakan bahwa pengguna adalah pemilik seluruh data tersebut.', 'Contoh: section team, label Nama Tim, value Kelompok Hydrotech, sort_order 1.'),
'ph_readings': ('Hasil pengukuran pH', 'Menyimpan nilai pH yang sudah diproses, bukan sinyal mentah ADC atau tegangan.', [
'Identitas pembacaan pH; PK dan AUTO_INCREMENT.', 'Angka pH desimal; wajib. CHECK membatasi 0 sampai 14.', 'Waktu pencatatan oleh server database; wajib, default CURRENT_TIMESTAMP.'
], 'id dirujuk oleh monitoring_logs.ph_reading_id dan notifications.ph_reading_id. Tabel ini tidak mempunyai sensor_id atau FK ke perangkat. Indeks recorded_at dan id membantu pengambilan data terbaru.', 'Contoh: id 101, ph_value 6.2500, recorded_at 2026-09-29 08:00:00. pH tidak mempunyai satuan fisik. Nilai 6.25 dan 6.2500 menyatakan angka yang sama.'),
'ppm_readings': ('Hasil pengukuran PPM', 'Menyimpan hasil pengukuran konsentrasi dalam ppm sebagai bilangan bulat sesuai kontrak data proyek.', [
'Identitas pembacaan PPM; PK dan AUTO_INCREMENT.', 'Bilangan bulat; wajib. CHECK membatasi 0 sampai 2147483647. Pecahan ditolak API.', 'Waktu pencatatan oleh server database; wajib, default CURRENT_TIMESTAMP.'
], 'id dirujuk oleh monitoring_logs.ppm_reading_id dan notifications.ppm_reading_id. Tidak ada identitas perangkat. Indeks recorded_at dan id membantu pengambilan pembacaan terbaru.', 'Contoh: id 101, ppm_value 850, recorded_at 2026-09-29 08:00:00. ID 101 di tabel ini tidak sama maknanya dengan ID 101 pada ph_readings. Nilai di luar rentang normal, misalnya 1200, tetap dapat disimpan.'),
'dithane_schedules': ('Jadwal Dithane', 'Menyimpan satu konfigurasi penyemprotan berulang yang diisi pengguna: interval, durasi, dan jam mulai.', [
'PK dengan CHECK id = 1. Tidak AUTO_INCREMENT; membatasi tabel maksimal satu baris.', 'Jarak pengulangan dalam jam; bilangan bulat positif.', 'Lama penyemprotan yang direncanakan dalam detik; bilangan bulat positif.', 'Jam mulai dalam TIME; dibatasi 00:00:00 sampai 23:59:59.', 'FK ke users.id; boleh NULL. Pengubah terakhir jadwal.', 'Waktu baris jadwal dibuat.', 'Waktu perubahan terakhir; diperbarui otomatis.'
], 'updated_by merujuk ke users.id dengan ON DELETE SET NULL. Tidak ada relasi langsung dari jadwal ke log pelaksanaan. Tabel ini belum mempunyai tanggal agenda, dosis, status selesai, atau banyak baris jadwal.', 'Contoh: id 1, interval_hours 48, duration_seconds 10, start_time 08:00:00. Artinya rencana interval 48 jam, durasi 10 detik, dimulai pukul 08.00; tabel itu sendiri tidak menyalakan pompa.'),
'monitoring_logs': ('Catatan evaluasi pemantauan', 'Menyimpan hasil evaluasi nilai atau catatan Dithane, termasuk status dan keterangan.', [
'Identitas catatan; PK dan AUTO_INCREMENT.', 'Kategori ppm, ph, atau dithane; FK wajib ke settings.type.', 'Nama hari untuk tampilan.', 'Jam tampilan berupa teks, misalnya 08.00.', 'Nilai tampilan berupa teks; dapat memuat angka atau volume seperti 0.5 l.', 'Status evaluasi atau kegiatan; berupa teks, misalnya Normal, Rendah, Tinggi.', 'Keterangan tambahan; boleh NULL.', 'Penanda soft delete: aplikasi menggunakan 0 untuk tampil dan 1 untuk dihapus.', 'Waktu catatan dibuat.', 'FK opsional ke ph_readings.id.', 'FK opsional ke ppm_readings.id.'
], 'Selain FK kategori, terdapat dua FK pembacaan. CHECK mencegah keduanya terisi bersamaan dan memastikan ID pH hanya pada type ph serta ID PPM hanya pada type ppm. Kedua ID boleh NULL untuk catatan tanpa sumber pembacaan.', 'Contoh: type ppm, value 1200, status Tinggi, ppm_reading_id 102, ph_reading_id NULL. Angka sumber berada pada ppm_readings; value di log adalah salinan untuk tampilan, bukan sumber utama perhitungan.'),
'history_logs': ('Riwayat kejadian', 'Menyimpan riwayat kegiatan atau kondisi berdasarkan kategori, tanggal, waktu, status, dan catatan.', [
'Identitas riwayat; PK dan AUTO_INCREMENT.', 'Nama hari untuk tampilan.', 'Tanggal kejadian.', 'Jam kejadian berupa teks.', 'Kategori ppm, ph, atau dithane; FK wajib ke settings.type.', 'Status kejadian yang dicatat.', 'Keterangan tambahan; boleh NULL.', 'Penanda soft delete yang dipakai aplikasi: 0 atau 1.', 'Waktu catatan dimasukkan ke database.'
], 'history_logs.type merujuk ke settings.type. Tidak ada FK langsung ke ph_readings, ppm_readings, atau dithane_schedules. Karena itu, catatan ini tidak membuktikan asal pembacaan atau versi jadwal tertentu.', 'Contoh: type dithane, log_date 2026-09-29, log_time 08.00, status Selesai. Contoh ini hanya ilustrasi baris; penyimpanan pembacaan tidak otomatis membuat riwayat atau membuktikan penyemprotan fisik.'),
'notifications': ('Notifikasi aplikasi', 'Menyimpan pesan informasi, keberhasilan, atau peringatan yang ditampilkan kepada pengguna.', [
'Identitas pesan; PK dan AUTO_INCREMENT.', 'Judul singkat notifikasi.', 'Isi pesan.', 'Jenis tampilan: warning, success, atau info; default info.', 'Tautan tujuan halaman; boleh NULL.', 'Penanda dibaca yang digunakan aplikasi: 0 belum, 1 sudah.', 'Waktu pesan dibuat.', 'FK opsional ke ph_readings.id.', 'FK opsional ke ppm_readings.id.'
], 'Sumber pembacaan dipilih melalui salah satu FK. CHECK melarang kedua ID terisi bersamaan. Notifikasi umum dapat mempunyai dua ID NULL. Tanpa user_id, pesan dan status baca masih global, belum per pengguna.', 'Contoh: label PPM perlu dipantau, message Nilai PPM di luar rentang, tone warning, ppm_reading_id 102. Satu pembacaan dapat mempunyai banyak pesan karena FK ini tidak UNIQUE.')
}
details=[]
count=0
for number,(name,(title,purpose,descs,rels,example)) in enumerate(items.items(),1):
    block=re.search(r'CREATE TABLE IF NOT EXISTS `'+name+r'` \((.*?)\n\) ENGINE',schema,re.S)[1]
    cols=re.findall(r'^\s*`(\w+)`\s+((?:ENUM\([^\n]*?\)|DECIMAL\([^)]*\)|VARCHAR\([^)]*\)|[A-Z]+(?:\([^)]*\))?))',block,re.M)
    assert len(cols)==len(descs),(name,len(cols),len(descs))
    count+=len(cols)
    text=f'## 3.{number}. {name} — {title}\n\n**Fungsi:** {purpose}\n\n| Kolom | Tipe data | Penjelasan |\n|---|---|---|\n'
    for (col,typ),desc in zip(cols,descs):
        text+=f'| {col} | {"ENUM" if typ.startswith("ENUM") else typ} | {desc} |\n'
    text+=f'\n**Relasi dan aturan:** {rels}\n\n**Contoh:** {example}\n'
    details.append(text)
assert count==60
p=root/'materi_presentasi_terbaru.md'
source=p.read_text(encoding='utf-8')
assert '<!-- TABLE_DETAILS -->' in source
p.write_text(source.replace('<!-- TABLE_DETAILS -->','\n'.join(details)),encoding='utf-8')

generator=(root/'buat_dokumen_presentasi.py').read_text(encoding='utf-8')
generator=generator.replace('bahan_presentasi_database_hydrotech.md','materi_presentasi_terbaru.md')
generator=generator.replace('Panduan_Presentasi_Database_Hydrotech.docx','Presentasi_Database_Hydrotech_Terbaru_29_September_2026.docx')
generator=generator.replace('8 TABEL   •   57 KOLOM   •   7 RELASI','9 TABEL   •   60 KOLOM   •   9 RELASI')
generator=generator.replace('22 September 2026','Presentasi: 29 September 2026 | Diperiksa: 28 September 2026')
generator=generator.replace("'Penjelasan Database\\ndan Integrasi Web'", "'Database, Relasi ERD\\ndan Integrasi Web'")
generator=generator.replace("    styles[name].paragraph_format.line_spacing = 1.12", "    styles[name].paragraph_format.line_spacing = 1.08")
generator=generator.replace("p = doc.add_paragraph(match[2], f'Heading {level}')", "p = doc.add_paragraph(match[2], f'Heading {level}')\n        if level == 2 and re.match(r'3\\.\\d+\\.', match[2]):\n            p.paragraph_format.page_break_before = not match[2].startswith('3.1.')")
generator=generator.replace("        table.style = 'Table Grid'", "        table.style = 'Table Grid'\n        table.autofit = False\n        widths = [3.9, 3.3, 9.4] if len(rows[0]) == 3 else [16.6 / len(rows[0])] * len(rows[0])\n        for col, width in zip(table.columns, widths): col.width = Cm(width)")
generator=generator.replace("            for cell, value in zip(row.cells, cells):", "            for cell, width in zip(row.cells, widths): cell.width = Cm(width)\n            for cell, value in zip(row.cells, cells):")
generator=generator.replace("'Bahan presentasi database, relasi, dashboard, dan Laragon'", "'Struktur terbaru: PPM INT, sembilan tabel, relasi, demonstrasi, dan naskah enam anggota'")
# Set the document's proofing language as Indonesian.
generator=generator.replace('doc.save(target)', "lang = OxmlElement('w:lang'); lang.set(qn('w:val'), 'id-ID')\nstyles['Normal'].element.get_or_add_rPr().append(lang)\ndoc.save(target)")
(root/'render_presentasi_terbaru.py').write_text(generator,encoding='utf-8')
exec(compile(generator,str(root/'render_presentasi_terbaru.py'),'exec'))
