from pathlib import Path
import xml.etree.ElementTree as ET

# Reuse drawing primitives and source-schema parsing without running the old export.
base = Path(__file__).with_name('buat_erd_crowfoot.py')
prefix = base.read_text(encoding='utf-8').split('text(W/2,65,')[0]
prefix = prefix.replace("ROOT/'database/schema.sql'", "ROOT/'tests/fixtures/schema_legacy.sql'")
exec(compile(prefix, str(base), 'exec'), globals())

def field(name,typ,nullable=False,pk=False,fk=False):
    return dict(name=name,type=typ,nullable=nullable,pk=pk,fk=fk,uq=False,new=True)

tables['sensors']=[
    field('id','INT',pk=True),
    field('name','VARCHAR(100)'),
    field('type',"ENUM('ph','ppm')"),
    field('unit',"ENUM('pH','ppm')"),
    field('status',"ENUM('aktif','nonaktif','rusak')"),
    field('location','VARCHAR(150)',True),
    field('created_at','TIMESTAMP'),
    field('updated_at','TIMESTAMP'),
]
tables['sensor_readings']=[
    field('id','INT',pk=True),
    field('sensor_id','INT',fk=True),
    field('value','DECIMAL(12,4)'),
    field('recorded_at','TIMESTAMP'),
]
meta={
 'profile':('Profil',120,230,'#ede2f4','#d7c2e3','id_profil'),
 'users':('Pengguna',1290,230,'#e4eef7','#bfd3e5','id_pengguna'),
 'settings':('Pengaturan',2460,230,'#e5f1df','#c1d9b6','id_pengaturan'),
 'sensors':('Sensor',120,920,'#def0e5','#b6d7c3','id_sensor'),
 'sensor_readings':('Pembacaan Sensor',1290,920,'#e0f1f2','#bcdfe1','id_pembacaan'),
 'monitoring_logs':('Catatan Monitoring',2460,920,'#fff1cf','#e7d7a4','id_monitoring'),
 'notifications':('Notifikasi',1290,1800,'#f4e2e9','#e1c1cd','id_notifikasi'),
 'history_logs':('Riwayat',2460,1800,'#ffebdf','#e9c6af','id_riwayat'),
}
aliases.update({'sensor_id':'id_sensor','name':'nama_sensor','unit':'satuan','location':'lokasi'})

def alias(table,name):
    if name=='id': return meta[table][5]
    if table=='sensors' and name=='type': return 'jenis_sensor'
    if table=='sensors' and name=='status': return 'status_sensor'
    if name=='value': return 'isi' if table=='profile' else 'nilai'
    return aliases[name]

relations=[
 ('R1','users','id','profile','updated_by','optional','Pengubah terakhir'),
 ('R2','users','id','settings','updated_by','optional','Pengubah terakhir'),
 ('R3','settings','type','monitoring_logs','type','one','Jenis monitoring'),
 ('R4','settings','type','history_logs','type','one','Jenis riwayat'),
 ('R5','sensors','id','sensor_readings','sensor_id','one','Menghasilkan'),
 ('R6','sensor_readings','id','monitoring_logs','sensor_reading_id','optional','Sumber monitoring'),
 ('R7','sensor_readings','id','notifications','sensor_reading_id','optional','Sumber notifikasi'),
]

def canvas(width,height):
    global W,H,im,draw,svg
    W,H=width,height
    im=Image.new('RGB',(W,H),'white')
    draw=ImageDraw.Draw(im)
    svg=[f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}">','<rect width="100%" height="100%" fill="white"/>']

def export(stem):
    svg.append('</svg>')
    (OUT/f'{stem}.svg').write_text('\n'.join(svg),encoding='utf-8')
    im.save(OUT/f'{stem}.png')
    im.save(OUT/f'{stem}.pdf','PDF',resolution=150)
    im.resize((1400,round(H*1400/W))).save(OUT/f'{stem}-pratinjau.png')
    ET.parse(OUT/f'{stem}.svg')

def crow_relation(points,kind,label,lx,ly):
    line(points)
    endpoint(points[0],points[1],kind)
    endpoint(points[-1],points[-2],'many')
    width=120 if len(label)<=3 else max(250,len(label)*12)
    rect(lx-width/2,ly-34,width,66,'white','white',1)
    text(lx,ly-15,label,23,True,True)
    c='1 : 0..N' if kind=='one' else '0..1 : 0..N'
    if points[0][0]>points[-1][0] and points[0][1]==points[-1][1]: c='0..N : 0..1'
    text(lx,ly+16,c,22,False,True)

def render_table(table):
    title,x,y,fill,head,_=meta[table]
    fields=tables[table]
    height=112+48*len(fields)
    rect(x+7,y+7,820,height,'#e8eaec','#e8eaec')
    rect(x,y,820,height,fill)
    rect(x,y,820,70,head)
    text(x+410,y+25,title,29,True,True)
    text(x+410,y+53,table,20,False,True)
    for offset,label in [(17,'KUNCI'),(130,'ATRIBUT'),(490,'TIPE DATA'),(749,'NULL')]: text(x+offset,y+91,label,21,True)
    line([(x,y+112),(x+820,y+112)],1)
    for i,f in enumerate(fields):
        cy=y+136+48*i
        keys=', '.join(k for k,flag in [('PK',f['pk']),('UQ',f['uq']),('FK',f['fk'])] if flag)
        typ=f['type']
        if typ.startswith('ENUM'):
            code={'type':'E','unit':'F','status':'G'}[f['name']] if table=='sensors' else enum_refs[f['name']]
            typ=f'ENUM [{code}]'
        text(x+17,cy,keys,22,True)
        text(x+130,cy,alias(table,f['name']),25)
        text(x+490,cy,typ,24)
        text(x+755,cy,'Ya' if f['nullable'] else 'Tidak',21)
    for dx in [112,475,735]: line([(x+dx,y+70),(x+dx,y+height)],1,'#a0a9b0')

canvas(3500,2800)
text(W/2,62,'ERD HYDROTECH DENGAN ENTITAS SENSOR',46,True,True)
text(W/2,116,'Notasi Crow\u2019s Foot | 8 tabel | 57 atribut | 7 relasi FK',29,False,True)
text(W/2,162,'STRUKTUR DATABASE - setiap baris pembacaan menyimpan satu nilai dari satu sensor.',26,False,True)
crow_relation([(1290,423),(940,423)],'optional','R1 - Mengubah',1115,374)
crow_relation([(2110,423),(2460,423)],'optional','R2 - Mengubah',2285,374)
crow_relation([(2870,582),(2870,920)],'one','R3 - Jenis monitoring',2870,750)
crow_relation([(3280,430),(3390,430),(3390,1990),(3280,1990)],'one','R4',3390,1650)
crow_relation([(940,1080),(1290,1080)],'one','R5 - Menghasilkan',1115,1030)
crow_relation([(2110,1080),(2460,1080)],'optional','R6 - Sumber log',2285,1030)
crow_relation([(1700,1224),(1700,1800)],'optional','R7 - Sumber notifikasi',1700,1560)
for table in meta: render_table(table)

rect(120,1660,820,684,'#f7f9fb','#c4cdd6')
text(151,1708,'IDENTITAS DAN PEMBACAAN SENSOR',27,True)
notes=[
 'id_sensor adalah identitas perangkat.',
 'id_pembacaan adalah identitas hasil ukur.',
 '',
 'Satu sensor: nol atau banyak pembacaan.',
 'Satu pembacaan: wajib tepat satu sensor.',
 '',
 'Contoh sensor:',
 '1 | Sensor pH Larutan | ph | pH | aktif',
 '2 | Sensor TDS Larutan | ppm | ppm | aktif',
 '',
 'Contoh hasil ukur pada waktu yang sama:',
 'Pembacaan 1: sensor 1, nilai 6.40.',
 'Pembacaan 2: sensor 2, nilai 845.00.',
]
for i,s in enumerate(notes): text(151,1764+i*41,s,24)
text(120,2410,'PK = kunci utama | FK = kunci tamu | UQ = unik | NULL = boleh kosong | 1 = tepat satu | 0..1 = nol/satu | 0..N = nol/banyak',25,True)
text(120,2460,'ENUM [A] admin/user   [B] ppm/ph/dithane   [C] warning/success/info   [D] owner/team',25)
text(120,2507,'ENUM [E] ph/ppm   [F] pH/ppm   [G] aktif/nonaktif/rusak. Jenis dan satuan sensor dijaga konsisten melalui CHECK pada SQL.',25)
text(120,2560,'R4: satu jenis pengaturan mengelompokkan banyak riwayat. FK jenis merujuk settings.type (UQ).',25)
text(120,2610,'Referensi pembacaan pada monitoring/notifikasi boleh kosong untuk data manual, Dithane, atau notifikasi umum.',25)
text(120,2660,'Semua relasi non-identifying: PK anak tetap id sendiri. Status perangkat berbeda dari status hasil monitoring.',25)
text(120,2712,'Diimplementasikan pada skema proyek: sensor dan pembacaan terhubung melalui FK sensor_id.',27,True)
text(120,2760,'Nama Indonesia adalah alias dokumentasi; nama tabel SQL ditampilkan di bawah judul. Semua PK menggunakan AUTO_INCREMENT.',24)
export('erd-hydrotech-sensor-crowfoot')

# Chen version: same tables/fields/FKs, with relationship diamonds.
canvas(4450,3380)
text(W/2,65,'ERD HYDROTECH DENGAN SENSOR - NOTASI CHEN',47,True,True)
text(W/2,123,'8 entitas dan 57 atribut | Struktur database | Entitas, atribut, relasi, dan kardinalitas',27,False,True)
centers={'profile':(650,650),'users':(2150,650),'settings':(3650,650),
         'sensors':(650,1650),'sensor_readings':(2150,1650),'monitoring_logs':(3650,1650),
         'notifications':(2150,2670),'history_logs':(3650,2670)}

def oval(x,y,rx=166,ry=44):
    draw.ellipse((x-rx,y-ry,x+rx,y+ry),fill='white',outline='#333333',width=2)
    svg.append(f'<ellipse cx="{x}" cy="{y}" rx="{rx}" ry="{ry}" fill="white" stroke="#333" stroke-width="2"/>')

def diamond(x,y,label):
    pts=[(x,y-85),(x+132,y),(x,y+85),(x-132,y)]
    draw.polygon(pts,fill='white',outline='#333333',width=2)
    svg.append(f'<polygon points="{" ".join(f"{a},{b}" for a,b in pts)}" fill="white" stroke="#333" stroke-width="2"/>')
    lines=label.split('|')
    for i,s in enumerate(lines): text(x,y+(i-(len(lines)-1)/2)*29,s,23,False,True)

chen_paths=[
 ([(1980,650),(820,650)],1400,650,'Mengubah','0..N : 0..1'),
 ([(2320,650),(3480,650)],2900,650,'Mengubah','0..1 : 0..N'),
 ([(3650,704),(3650,1596)],3650,1130,'Mengelompokkan|monitoring','1 : 0..N'),
 ([(3820,650),(4310,650),(4310,2670),(3820,2670)],4310,2160,'Mengelompokkan|riwayat','1 : 0..N'),
 ([(820,1650),(1980,1650)],1400,1650,'Menghasilkan','1 : 0..N'),
 ([(2320,1650),(3480,1650)],2900,1650,'Menjadi sumber|monitoring','0..1 : 0..N'),
 ([(2150,1704),(2150,2616)],2150,2160,'Menjadi sumber|notifikasi','0..1 : 0..N'),
]
for points,x,y,label,card in chen_paths:
    line(points,2)
    diamond(x,y,label)
    # Place cardinality in a white strip under the relationship.
    rect(x-105,y+94,210,35,'white','white',1)
    text(x,y+111,card,23,True,True)

for table,(x,y) in centers.items():
    fs=tables[table]
    nodes=[]
    for side,items in [(-1,fs[::2]),(1,fs[1::2])]:
        offsets={2:[-160,160],3:[-230,-90,190],4:[-290,-130,130,290],5:[-330,-210,-90,120,280]}[len(items)]
        for f,dy in zip(items,offsets): nodes.append((f,x+side*385,y+dy))
    for f,ax,ay in nodes: line([(x,y),(ax,ay)],2)
    for f,ax,ay in nodes:
        oval(ax,ay)
        label=alias(table,f['name'])+(' (FK)' if f['fk'] else '')
        text(ax,ay,label,23,False,True)
        if f['pk']:
            font=ImageFont.truetype('C:/Windows/Fonts/arial.ttf',23)
            tw=draw.textlength(label,font=font)
            line([(ax-tw/2,ay+16),(ax+tw/2,ay+16)],2)
    rect(x-170,y-54,340,108,'white','#333333',2)
    text(x,y,meta[table][0],28,True,True)

text(2225,3110,'Kotak = entitas | Oval = atribut | Belah ketupat = relasi | Garis bawah = PK | (FK) = kunci tamu',28,True,True)
text(2225,3164,'Kardinalitas mengikuti posisi kiri ke kanan / atas ke bawah; hubungan di sisi kanan mengalir dari Pengaturan ke Riwayat.',26,False,True)
text(2225,3218,'Sensor 1 : 0..N Pembacaan Sensor. Referensi pembacaan pada monitoring/notifikasi boleh kosong.',27,False,True)
text(2225,3272,'Sesuai schema.sql proyek. Detail tipe data, NULL, UQ, dan ENUM tersedia pada versi Crow\u2019s Foot.',26,False,True)
text(2225,3326,'Dithane tetap parameter jadwal/perlakuan; tidak diasumsikan sebagai perangkat sensor.',25,False,True)
export('erd-hydrotech-sensor-chen')

# Full, separate schema for an empty design database. Do not execute it here.
bodies=dict(re.findall(r'CREATE TABLE IF NOT EXISTS `(\w+)` \((.*?)\) ENGINE',source,re.S))
extra={
 'settings':'''`updated_by` INT NULL,
    INDEX `idx_settings_updated_by` (`updated_by`),
    CONSTRAINT `fk_settings_user` FOREIGN KEY (`updated_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT''',
 'profile':'''`updated_by` INT NULL,
    INDEX `idx_profile_updated_by` (`updated_by`),
    CONSTRAINT `fk_profile_user` FOREIGN KEY (`updated_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT''',
 'monitoring_logs':'''`sensor_reading_id` INT NULL,
    INDEX `idx_monitoring_reading` (`sensor_reading_id`),
    CONSTRAINT `fk_monitoring_type` FOREIGN KEY (`type`)
        REFERENCES `settings` (`type`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_monitoring_reading` FOREIGN KEY (`sensor_reading_id`)
        REFERENCES `sensor_readings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT''',
 'history_logs':'''CONSTRAINT `fk_history_type` FOREIGN KEY (`type`)
        REFERENCES `settings` (`type`) ON DELETE RESTRICT ON UPDATE RESTRICT''',
 'notifications':'''`sensor_reading_id` INT NULL,
    INDEX `idx_notifications_reading` (`sensor_reading_id`),
    CONSTRAINT `fk_notifications_reading` FOREIGN KEY (`sensor_reading_id`)
        REFERENCES `sensor_readings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT''',
}
for table,addition in extra.items(): bodies[table]=bodies[table].strip()+',\n    '+addition+'\n'
bodies['sensors']='''
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `type` ENUM('ph','ppm') NOT NULL,
    `unit` ENUM('pH','ppm') NOT NULL,
    `status` ENUM('aktif','nonaktif','rusak') NOT NULL DEFAULT 'aktif',
    `location` VARCHAR(150) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_sensor_type_status` (`type`, `status`),
    CONSTRAINT `chk_sensor_unit` CHECK (
        (`type` = 'ph' AND `unit` = 'pH') OR
        (`type` = 'ppm' AND `unit` = 'ppm')
    )
'''
bodies['sensor_readings']='''
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sensor_id` INT NOT NULL,
    `value` DECIMAL(12,4) NOT NULL,
    `recorded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_recorded_at` (`recorded_at`),
    INDEX `idx_sensor_time` (`sensor_id`, `recorded_at`),
    CONSTRAINT `fk_reading_sensor` FOREIGN KEY (`sensor_id`)
        REFERENCES `sensors` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
'''
sql='''-- RANCANGAN BARU HYDROTECH DENGAN SENSOR - BELUM DIJALANKAN.
-- Untuk database rancangan kosong; bukan migrasi langsung database aktif.
-- Nama database berbeda agar tidak menggunakan monitoring_sensor milik aplikasi.
-- Aplikasi lama perlu diubah sebelum memakai model satu nilai per sensor ini.
CREATE DATABASE `monitoring_sensor_rancangan`
    DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `monitoring_sensor_rancangan`;

'''
order=['users','settings','profile','sensors','sensor_readings','monitoring_logs','history_logs','notifications']
for table in order:
    sql+=f'CREATE TABLE `{table}` (\n{bodies[table].strip()}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n'
sql+='''-- Contoh data khusus untuk memperjelas identitas dan hasil ukur sensor.
INSERT INTO sensors (id, name, type, unit, status, location) VALUES
    (1, 'Sensor pH Larutan', 'ph', 'pH', 'aktif', 'Bak nutrisi utama'),
    (2, 'Sensor TDS Larutan', 'ppm', 'ppm', 'aktif', 'Bak nutrisi utama');

SET @waktu_contoh = CURRENT_TIMESTAMP;
INSERT INTO sensor_readings (sensor_id, value, recorded_at) VALUES
    (1, 6.40, @waktu_contoh),
    (2, 845.00, @waktu_contoh);

INSERT INTO settings (type, config_json) VALUES
    ('ppm', '{"min":"800","max":"1000"}'),
    ('ph', '{"min":"5,5","max":"6,5"}'),
    ('dithane', '{"interval":"48","duration":"10","start":"08.00"}');
'''
(OUT/'rancangan-database-dengan-sensor.sql').write_text(sql,encoding='utf-8')

doc='''# ERD Hydrotech dengan Sensor

## Status

Rancangan pengembangan: **8 tabel, 57 atribut, 7 FK**. Diagram dan SQL belum diterapkan ke database aktif maupun aplikasi. Berkas schema.sql asli tidak diubah. Diagram ini menggantikan rancangan tujuh tabel untuk kebutuhan laporan yang menyertakan identitas perangkat sensor.

## Perubahan utama

1. Tabel `sensors` menambahkan `id`, `name`, `type`, `unit`, `status`, `location`, `created_at`, dan `updated_at`.
2. `sensor_readings` berubah menjadi `id`, `sensor_id`, `value`, dan `recorded_at`. Satu baris mewakili satu hasil ukur dari satu sensor.
3. Kolom `ph_value` dan `ppm_value` pada model lama diganti dengan `value` dalam rancangan ini. Dua hasil dari pH dan TDS pada waktu yang sama disimpan sebagai dua baris, masing-masing dengan identitas sensor asal.
4. Empat FK opsional pada rancangan sebelumnya dipertahankan: `settings.updated_by`, `profile.updated_by`, `monitoring_logs.sensor_reading_id`, dan `notifications.sensor_reading_id`.
5. `monitoring_logs.type` dan `history_logs.type` merujuk `settings.type`, yang memiliki UNIQUE key. Riwayat masih berupa catatan per jenis, belum memiliki referensi pembacaan langsung.
6. Dithane merupakan pengaturan jadwal/perlakuan, bukan sensor fisik pada rancangan ini. Tidak ditambahkan perangkat yang belum ada kebutuhannya.

## Aturan sensor

- `id_sensor` mengidentifikasi perangkat; `id_pembacaan` mengidentifikasi catatan hasil ukur. Keduanya tidak sama.
- `jenis_sensor` menunjukkan parameter yang diukur: `ph` atau `ppm`. Nilai ppm dapat berasal dari sensor TDS; rancangan tidak menetapkan model atau merek perangkat tertentu.
- Pasangan jenis-satuan `ph/pH` dan `ppm/ppm` diperiksa oleh CHECK pada SQL. Status perangkat hanya `aktif`, `nonaktif`, atau `rusak`.
- Status perangkat berbeda dengan status pengukuran seperti Normal, Rendah, atau Tinggi.
- Satu sensor boleh belum mempunyai pembacaan. Setiap pembacaan wajib mempunyai satu sensor terdaftar.
- Sensor yang sudah dirujuk pembacaan tidak boleh dihapus secara fisik (RESTRICT); ubah status menjadi nonaktif jika tidak digunakan.
- Pembacaan yang dirujuk monitoring atau notifikasi tidak boleh dihapus secara fisik (RESTRICT), agar sumber data tetap tersedia. FK opsional tetap memungkinkan NULL untuk log manual dan notifikasi umum.
- `recorded_at` pada pembacaan dibuat NOT NULL dalam rancangan baru. Nilai menggunakan DECIMAL(12,4); kesesuaian rentang nilai dan status aktif sensor tetap perlu divalidasi API.
- API harus memastikan jenis catatan monitoring sesuai dengan jenis sensor pembacaan yang dirujuk. FK pada ID saja tidak memeriksa kesamaan jenis antar tabel.
- Hubungan jenis ke pengaturan tidak menyimpan salinan batas historis; audit versi konfigurasi berada di luar rancangan ini.

## Daftar relasi

| Kode | Induk / kunci | Anak / FK | Induk per anak | Anak per induk |
|---|---|---|---|---|
'''
for code,parent,pk,child,fk,kind,label in relations:
    doc+=f'| {code} | `{parent}.{pk}` | `{child}.{fk}` | {"1" if kind=="one" else "0..1"} | 0..N |\n'
doc+='''
Referensi pengubah terakhir boleh kosong untuk data awal. Relasi pengguna ini bukan kepemilikan dan bukan riwayat perubahan. Penghapusan pengguna memakai SET NULL. Kunci jenis pengaturan memakai RESTRICT. Semua ON UPDATE menggunakan RESTRICT.

## Notasi

- Crow's Foot: PK, FK, UQ, tipe, dan NULL ditampilkan. Lingkaran berarti nol, garis tegak berarti satu, kaki gagak berarti banyak. Relasi non-identifying karena FK bukan bagian PK anak. Garis penghubung utuh mengikuti gaya contoh, tidak mengkodekan identifying/non-identifying.
- Chen: kotak adalah entitas, oval adalah atribut, belah ketupat adalah relasi, atribut bergaris bawah adalah PK. Label kardinalitas dibaca kiri ke kanan atau atas ke bawah; relasi luar di kanan dari Pengaturan ke Riwayat. UQ dan detail tipe disajikan pada Crow's Foot.
- Nama Indonesia pada diagram merupakan alias dokumentasi. SQL tetap memakai nama tabel/kolom bahasa Inggris untuk konsistensi dengan basis proyek.

## Pemetaan seluruh atribut

'''
for table in order:
    doc+=f'### {meta[table][0]} (`{table}`)\n\n| Nama diagram | Kolom SQL | Tipe | Kunci | NULL |\n|---|---|---|---|---|\n'
    for f in tables[table]:
        keys=', '.join(k for k,flag in [('PK',f['pk']),('UQ',f['uq']),('FK',f['fk'])] if flag) or '-'
        doc+=f'| {alias(table,f["name"])} | `{f["name"]}` | `{f["type"]}` | {keys} | {"Ya" if f["nullable"] else "Tidak"} |\n'
    doc+='\n'
doc+='''## Dampak pada aplikasi jika rancangan diterapkan

- `api/sensor/save.php` perlu menerima `sensor_id` dan `value`, memeriksa perangkat, memilih batas sesuai jenis, lalu menyimpan satu hasil ukur. Pengiriman pH dan PPM bersama dapat dipertahankan sebagai antarmuka batch dengan dua baris hasil, bukan satu baris gabungan.
- `api/sensor/latest.php` dan `api/dashboard/summary.php` perlu mengambil hasil terbaru per sensor/jenis. SELECT satu baris terbaru secara global tidak lagi menghasilkan pH dan PPM sekaligus. Jika ada banyak perangkat dengan jenis sama, tentukan perangkat yang ingin ditampilkan.
- `api/notifications/generate.php` perlu mengambil jenis dari tabel sensors dan menggunakan hasil terbaru perangkat yang dipilih. Simpan ID pembacaan yang memicu notifikasi.
- `js/dashboard-live.js` dan kode pembaca respons sensor perlu menyesuaikan payload serta tampilan. Saat ini nilai dashboard masih simulasi; penambahan tabel Sensor tidak membuat integrasi IoT secara otomatis.
- Saat menambahkan monitoring dari sensor, simpan referensi pembacaan yang sesuai. Pembuatan riwayat tetap membutuhkan logika tersendiri.
- API profil/pengaturan perlu mengisi updated_by dari sesi. Status baca notifikasi tetap global seperti proyek semula.
- Installer dan data awal harus disesuaikan dengan urutan FK. Untuk data lama, satu baris gabungan pH/PPM dapat dipecah menjadi dua pembacaan hanya setelah identitas perangkat asal ditentukan. Jangan menganggap seluruh data lama berasal dari satu perangkat tanpa konfirmasi.

## Berkas

- `erd-hydrotech-sensor-crowfoot.png/.svg/.pdf`: versi tabel.
- `erd-hydrotech-sensor-chen.png/.svg/.pdf`: versi kotak-oval-belah ketupat.
- `rancangan-database-dengan-sensor.sql`: skema lengkap untuk database rancangan kosong `monitoring_sensor_rancangan`, ditambah contoh dua sensor, dua pembacaan, dan pengaturan awal. Bukan migrasi database aktif dan bukan seluruh seed data proyek.

## Validasi

Generator memeriksa jumlah tabel/atribut, kesamaan tipe FK-induk, keunikan target FK, dan kesesuaian NULL-kardinalitas. Kolom pada SQL hasil dibandingkan dengan model diagram. SVG divalidasi sebagai XML. SQL belum diuji dengan menjalankannya di server MySQL.
'''
(OUT/'penjelasan-erd-dengan-sensor.md').write_text(doc,encoding='utf-8')

assert len(tables)==8
assert sum(len(fs) for fs in tables.values())==57
assert sum(f['fk'] for fs in tables.values() for f in fs)==7
for code,parent,pk,child,fk,kind,label in relations:
    pf=next(f for f in tables[parent] if f['name']==pk)
    cf=next(f for f in tables[child] if f['name']==fk)
    assert pf['pk'] or pf['uq']
    assert pf['type']==cf['type']
    assert cf['nullable']==(kind=='optional')
for table,body in bodies.items():
    parsed=[]
    for raw in body.splitlines():
        m=re.match(r'\s*`(\w+)`\s+(\w+(?:\([^)]*\))?)(.*)',raw)
        if m: parsed.append((m[1],m[2], 'NOT NULL' not in m[3] and 'PRIMARY KEY' not in m[3]))
    expected=[(f['name'],f['type'],f['nullable']) for f in tables[table]]
    assert parsed==expected,(table,parsed,expected)
assert sql.count('FOREIGN KEY')==7
print('Berhasil: ERD Crow\u2019s Foot + Chen (PNG/SVG/PDF), SQL rancangan, dan penjelasan.')
print('Validasi lulus: 8 tabel, 57 atribut, 7 FK; kolom/tipe/NULL SQL sesuai diagram; kedua SVG valid.')

# The ERD is now implemented; retain the generated attribute dictionary with current documentation.
attribute_doc = doc.split('## Pemetaan seluruh atribut')[1].split('## Dampak pada aplikasi')[0]
current_doc = "# ERD Hydrotech dengan Sensor - versi implementasi\n\nSkema proyek dan database lokal telah disesuaikan: **8 tabel, 57 atribut, dan 7 foreign key**. Sensor berbeda dari catatan pembacaannya.\n\nDokumentasi operasional, migrasi, API, serta batasan simulasi terdapat pada [database/README.md](../database/README.md). Contoh SQL rancangan tetap tersedia untuk referensi, tetapi sumber skema aplikasi adalah [database/schema.sql](../database/schema.sql).\n\n## Pemetaan seluruh atribut" + attribute_doc
(OUT/'penjelasan-erd-dengan-sensor.md').write_text(current_doc, encoding='utf-8')
