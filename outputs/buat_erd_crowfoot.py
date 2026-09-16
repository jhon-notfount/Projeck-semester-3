from pathlib import Path
from html import escape
import re, math
from PIL import Image, ImageDraw, ImageFont

OUT = Path(__file__).resolve().parent
ROOT = OUT.parent
W, H = 3400, 2480
im = Image.new('RGB', (W, H), 'white')
draw = ImageDraw.Draw(im)
svg = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}">', '<rect width="100%" height="100%" fill="white"/>']

def text(x,y,value,size=25,bold=False,center=False,color='#202a35'):
    font = ImageFont.truetype('C:/Windows/Fonts/arialbd.ttf' if bold else 'C:/Windows/Fonts/arial.ttf',size)
    draw.text((x,y),value,fill=color,font=font,anchor='mm' if center else 'lm')
    svg.append(f'<text x="{x}" y="{y}" dominant-baseline="central" text-anchor="{"middle" if center else "start"}" font-family="Arial,sans-serif" font-size="{size}" font-weight="{"bold" if bold else "normal"}" fill="{color}">{escape(value)}</text>')

def rect(x,y,w,h,fill,stroke='#58616b',width=2):
    draw.rectangle((x,y,x+w,y+h),fill=fill,outline=stroke,width=width)
    svg.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" fill="{fill}" stroke="{stroke}" stroke-width="{width}"/>')

def line(points,width=3,color='#485563'):
    draw.line(points,fill=color,width=width)
    svg.append(f'<polyline points="{" ".join(f"{x},{y}" for x,y in points)}" fill="none" stroke="{color}" stroke-width="{width}"/>')

def circle(x,y,r=9):
    draw.ellipse((x-r,y-r,x+r,y+r),fill='white',outline='#485563',width=3)
    svg.append(f'<circle cx="{x}" cy="{y}" r="{r}" fill="white" stroke="#485563" stroke-width="3"/>')

def endpoint(p,q,kind):
    x,y=p
    length=math.hypot(q[0]-x,q[1]-y)
    ux,uy=(q[0]-x)/length,(q[1]-y)/length
    vx,vy=-uy,ux
    def pt(t,s=0): return (x+ux*t+vx*s,y+uy*t+vy*s)
    if kind=='many':
        line([pt(0,-15),pt(28),pt(0,15)])
        circle(*pt(46))
    else:
        line([pt(16,-15),pt(16,15)])
        if kind=='optional': circle(*pt(42))
        else: line([pt(34,-15),pt(34,15)])

def relation(points,parent_kind,label,lx,ly,cardinality):
    line(points)
    endpoint(points[0],points[1],parent_kind)
    endpoint(points[-1],points[-2],'many')
    # Backplate keeps labels readable above connection lines.
    width=max(250,len(label)*14)
    rect(lx-width/2,ly-35,width,66,'white','white',1)
    text(lx,ly-16,label,23,True,True)
    text(lx,ly+15,('0..N : 0..1' if points[0][0] > points[-1][0] and points[0][1] == points[-1][1] else '1 : 0..N' if parent_kind=='one' else '0..1 : 0..N'),22,False,True)

source=(ROOT/'database/schema.sql').read_text(encoding='utf-8')
tables={}
for table,body in re.findall(r'CREATE TABLE IF NOT EXISTS `(\w+)` \((.*?)\) ENGINE',source,re.S):
    fields=[]
    for raw in body.splitlines():
        match=re.match(r'\s*`(\w+)`\s+(\w+(?:\([^)]*\))?)(.*)',raw)
        if match:
            name,typ,rest=match.groups()
            fields.append({'name':name,'type':typ,'nullable':'NOT NULL' not in rest and 'PRIMARY KEY' not in rest,'pk':'PRIMARY KEY' in rest,'uq':'UNIQUE' in rest,'new':False,'fk':False})
    tables[table]=fields

additions={'settings':'updated_by','profile':'updated_by','monitoring_logs':'sensor_reading_id','notifications':'sensor_reading_id'}
for table,name in additions.items():
    tables[table].append(dict(name=name,type='INT',nullable=True,pk=False,uq=False,new=True,fk=True))
for table in ['monitoring_logs','history_logs']:
    next(f for f in tables[table] if f['name']=='type')['fk']=True

aliases={
 'username':'nama_pengguna','password':'kata_sandi','email':'email','role':'peran',
 'created_at':'dibuat_pada','updated_at':'diperbarui_pada','type':'jenis',
 'config_json':'konfigurasi_json','day_name':'nama_hari','log_time':'jam_catatan',
 'status':'status','note':'keterangan','is_deleted':'dihapus','log_date':'tanggal_catatan',
 'label':'label','message':'pesan','tone':'tingkat_pesan','href':'tautan','is_read':'sudah_dibaca',
 'section':'bagian','sort_order':'urutan','ph_value':'nilai_ph','ppm_value':'nilai_ppm',
 'recorded_at':'direkam_pada','updated_by':'diubah_oleh_id','sensor_reading_id':'id_pembacaan',
}
meta={
 'profile':('Profil',120,230,'#ede2f4','#d7c2e3','id_profil'),
 'users':('Pengguna',1290,230,'#e4eef7','#bfd3e5','id_pengguna'),
 'settings':('Pengaturan',2460,230,'#e5f1df','#c1d9b6','id_pengaturan'),
 'sensor_readings':('Pembacaan Sensor',120,1010,'#e0f1f2','#bcdfe1','id_pembacaan'),
 'monitoring_logs':('Catatan Monitoring',1290,1010,'#fff1cf','#e7d7a4','id_monitoring'),
 'history_logs':('Riwayat',2460,1010,'#ffebdf','#e9c6af','id_riwayat'),
 'notifications':('Notifikasi',120,1620,'#f4e2e9','#e1c1cd','id_notifikasi'),
}
enum_refs={'role':'A','type':'B','tone':'C','section':'D'}
TBW=820
HEADER=112
ROW=48

def alias(table,field):
    if field=='id': return meta[table][5]
    if field=='value': return 'isi' if table=='profile' else 'nilai'
    return aliases[field]

def table_box(table):
    title,x,y,fill,head,_=meta[table]
    fields=tables[table]
    height=HEADER+ROW*len(fields)
    rect(x+7,y+7,TBW,height,'#e8eaec','#e8eaec')
    rect(x,y,TBW,height,fill)
    rect(x,y,TBW,70,head)
    text(x+TBW/2,y+25,title,29,True,True)
    text(x+TBW/2,y+53,table,20,False,True,'#485563')
    for offset,label in [(17,'KUNCI'),(130,'ATRIBUT'),(490,'TIPE DATA'),(749,'NULL')]:
        text(x+offset,y+91,label,21,True)
    line([(x,y+112),(x+TBW,y+112)],1)
    for i,f in enumerate(fields):
        cy=y+HEADER+ROW*i+ROW/2
        if f['new']: rect(x+1,cy-23,TBW-2,47,'#fff9e5','#fff9e5',1)
        keys=[]
        if f['pk']: keys.append('PK')
        if f['uq']: keys.append('UQ')
        if f['fk']: keys.append('FK')
        name=alias(table,f['name'])+(' +' if f['new'] else '')
        typ=f['type'] if not f['type'].startswith('ENUM') else 'ENUM ['+enum_refs[f['name']]+']'
        text(x+17,cy,', '.join(keys),22,True)
        text(x+130,cy,name,25)
        text(x+490,cy,typ,24)
        text(x+755,cy,'Ya' if f['nullable'] else 'Tidak',21)
    for dx in [112,475,735]: line([(x+dx,y+70),(x+dx,y+height)],1,'#a0a9b0')
    return height

text(W/2,65,'ERD HYDROTECH — NOTASI CROW’S FOOT',45,True,True)
text(W/2,119,'Rancangan relasional usulan berdasarkan 7 tabel yang ada • Atribut lengkap • PK, FK, UNIQUE, tipe data, dan NULL',27,False,True)
text(W/2,163,'Nama atribut pada gambar menggunakan bahasa Indonesia; nama SQL asli tercantum dalam dokumen pendamping.',24,False,True,'#55616d')

# Parent at start, referencing child at end. Cardinality follows FK nullability.
relation([(1290,423),(940,423)],'optional','Diubah oleh',1115,376,'Pengguna 0..1 : Profil 0..N')
relation([(2110,423),(2460,423)],'optional','Diubah oleh',2285,376,'Pengguna 0..1 : Pengaturan 0..N')
relation([(2460,535),(2290,535),(2290,825),(1700,825),(1700,1010)],'one','Mengelompokkan monitoring',1940,779,'Pengaturan 1 : Monitoring 0..N')
relation([(2870,582),(2870,1010)],'one','Mengelompokkan riwayat',2870,796,'Pengaturan 1 : Riwayat 0..N')
relation([(940,1170),(1290,1170)],'optional','Sumber pembacaan',1115,1118,'Sensor 0..1 : Monitoring 0..N')
relation([(530,1314),(530,1620)],'optional','Sumber notifikasi',530,1465,'Sensor 0..1 : Notifikasi 0..N')
for table in meta: table_box(table)

# Documentation panel uses otherwise empty space below the two log tables.
rect(1290,1700,1990,416,'#f7f9fb','#c4cdd6',2)
text(1320,1741,'CARA MEMBACA RELASI',28,True)
text(1320,1792,'1 = tepat satu    ·    0..1 = boleh kosong, maksimal satu    ·    0..N = nol atau banyak',26)
text(1320,1840,'FK jenis mengacu ke settings.type (UQ), bukan ke settings.id.',26)
text(1320,1888,'FK diubah_oleh_id mencatat pengubah terakhir; bukan pemilik atau hak akses.',26)
text(1320,1936,'FK id_pembacaan boleh NULL untuk catatan manual, Dithane, atau notifikasi umum.',26)
text(1320,1984,'Semua relasi non-identifying: FK tidak menjadi bagian dari primary key anak.',26)
text(1320,2032,'Riwayat dan monitoring tetap terpisah; tidak dibuat relasi langsung tanpa kolom referensi.',26)
text(1320,2080,'Relasi jenis hanya mengelompokkan parameter, bukan menyimpan versi batas historis.',26)

text(120,2181,'PK = kunci utama     FK = kunci tamu     UQ = nilai unik     + = kolom baru yang diusulkan     NULL = apakah nilai boleh kosong',25,True)
text(120,2230,'ENUM [A] peran: admin / user     ·     ENUM [B] jenis: ppm / ph / dithane',25)
text(120,2278,'ENUM [C] tingkat_pesan: warning / success / info     ·     ENUM [D] bagian: owner / team',25)
text(120,2335,'PENTING: Database asli belum memiliki FK. Keenam FK pada gambar adalah usulan dan belum diterapkan.',28,True)
text(120,2383,'Pengisian log dari sensor dan pencatatan pengubah terakhir membutuhkan penyesuaian API. Empat kolom baru bersifat opsional.',25)
text(120,2431,'Sumber: database/schema.sql • Nilai ENUM, tipe data, dan nullability mengikuti skema sumber. SQL relasi dan pemetaan nama disertakan.',24)

svg.append('</svg>')
(OUT/'erd-hydrotech-crowfoot.svg').write_text('\n'.join(svg),encoding='utf-8')
im.save(OUT/'erd-hydrotech-crowfoot.png')
im.save(OUT/'erd-hydrotech-crowfoot.pdf','PDF',resolution=150)
im.resize((1700,1240)).save(OUT/'erd-crowfoot-pratinjau.png')

relations=[
 ('users','id','settings','updated_by','0..1','pengubah terakhir pengaturan'),
 ('users','id','profile','updated_by','0..1','pengubah terakhir baris profil'),
 ('settings','type','monitoring_logs','type','1','pengelompokan jenis monitoring'),
 ('settings','type','history_logs','type','1','pengelompokan jenis riwayat'),
 ('sensor_readings','id','monitoring_logs','sensor_reading_id','0..1','sumber pembacaan monitoring'),
 ('sensor_readings','id','notifications','sensor_reading_id','0..1','sumber pembacaan notifikasi'),
]
assert len(tables)==7
assert sum(map(len,tables.values()))==49
assert sum(f['fk'] for fields in tables.values() for f in fields)==6
for parent,pk,child,fk,card,meaning in relations:
    pf=next(f for f in tables[parent] if f['name']==pk)
    cf=next(f for f in tables[child] if f['name']==fk)
    assert pf['pk'] or pf['uq']
    assert cf['type']==pf['type']
    assert cf['nullable']==(card=='0..1')

sql='''-- USULAN MIGRASI ERD HYDROTECH; BELUM DIJALANKAN.
-- Menggunakan nama tabel dan kolom ASLI agar sesuai basis kode PHP.
-- Tidak mengubah schema.sql atau database secara otomatis.
-- Jalankan hanya setelah meninjau database tujuan, membuat cadangan,
-- serta memastikan semua jenis monitoring/riwayat tersedia pada settings.
-- Skrip satu kali, bukan idempoten. DDL MySQL dapat melakukan implicit commit.
USE `monitoring_sensor`;

-- Pemeriksaan awal: kedua hasil harus kosong sebelum menjalankan ALTER.
SELECT DISTINCT m.type AS jenis_tanpa_pengaturan
FROM monitoring_logs m LEFT JOIN settings s ON s.type = m.type
WHERE s.id IS NULL;
SELECT DISTINCT h.type AS jenis_tanpa_pengaturan
FROM history_logs h LEFT JOIN settings s ON s.type = h.type
WHERE s.id IS NULL;

-- Empat atribut tambahan bersifat opsional untuk mempertahankan data lama.
ALTER TABLE settings
    ADD COLUMN updated_by INT NULL,
    ADD INDEX idx_settings_updated_by (updated_by),
    ADD CONSTRAINT fk_settings_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE RESTRICT;

ALTER TABLE profile
    ADD COLUMN updated_by INT NULL,
    ADD INDEX idx_profile_updated_by (updated_by),
    ADD CONSTRAINT fk_profile_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE RESTRICT;

ALTER TABLE monitoring_logs
    ADD COLUMN sensor_reading_id INT NULL,
    ADD INDEX idx_monitoring_sensor_reading (sensor_reading_id),
    ADD CONSTRAINT fk_monitoring_setting_type FOREIGN KEY (type)
        REFERENCES settings (type) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT fk_monitoring_sensor_reading FOREIGN KEY (sensor_reading_id)
        REFERENCES sensor_readings (id) ON DELETE SET NULL ON UPDATE RESTRICT;

ALTER TABLE history_logs
    ADD CONSTRAINT fk_history_setting_type FOREIGN KEY (type)
        REFERENCES settings (type) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE notifications
    ADD COLUMN sensor_reading_id INT NULL,
    ADD INDEX idx_notifications_sensor_reading (sensor_reading_id),
    ADD CONSTRAINT fk_notifications_sensor_reading FOREIGN KEY (sensor_reading_id)
        REFERENCES sensor_readings (id) ON DELETE SET NULL ON UPDATE RESTRICT;

-- Tidak ada FK monitoring_logs -> history_logs karena skema dan alur saat ini
-- menyimpan dua kelompok catatan terpisah. Hubungan tersebut tidak diasumsikan.
-- FK type sah karena settings.type sudah UNIQUE dan ENUM-nya identik.
-- Semua FK non-identifying; primary key setiap tabel tetap id.
'''
(OUT/'usulan-relasi-hydrotech.sql').write_text(sql,encoding='utf-8')

doc='''# ERD Hydrotech — Crow’s Foot

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
'''
for parent,pk,child,fk,card,meaning in relations:
    doc+=f'| `{parent}.{pk}` | `{child}.{fk}` | {card} | 0..N | {meaning} |\n'
doc+='''
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

'''
for table,fields in tables.items():
    doc+=f'### {meta[table][0]} (`{table}`)\n\n| Atribut Indonesia | Kolom SQL asli | Tipe | Kunci | NULL | Status |\n|---|---|---|---|---|---|\n'
    for f in fields:
        keys=', '.join(k for k,flag in [('PK',f['pk']),('UQ',f['uq']),('FK',f['fk'])] if flag) or '—'
        doc+=f'| {alias(table,f["name"])} | `{f["name"]}` | `{f["type"]}` | {keys} | {"Ya" if f["nullable"] else "Tidak"} | {"Kolom baru" if f["new"] else "Kolom ada; FK usulan" if f["fk"] else "Sudah ada"} |\n'
    doc+='\n'
doc+='''## Berkas dan penerapan

- `erd-hydrotech-crowfoot.png`: gambar resolusi 3400 × 2480.
- `erd-hydrotech-crowfoot.svg`: diagram vektor yang tetap tajam ketika diperbesar.
- `erd-hydrotech-crowfoot.pdf`: versi PDF.
- `usulan-relasi-hydrotech.sql`: migrasi penambahan empat kolom dan enam FK, **belum dijalankan**.

Sebelum menerapkan SQL, tinjau database tujuan, lakukan cadangan, dan pastikan hasil dua pemeriksaan data yatim kosong. Migrasi adalah skrip satu kali dan ALTER TABLE dapat melakukan implicit commit; pemeriksaan SELECT tidak otomatis menghentikan eksekusi lanjutan. Jalankan bagian pemeriksaan terlebih dahulu. Skema asli tetap menjadi sumber untuk default dan indeks yang sudah ada.

Sesudah migrasi, API pengaturan/profil harus mengisi `updated_by` dari sesi pengguna. API sensor harus meneruskan ID pembacaan ke notifikasi dan, apabila pengisian monitoring ditambahkan, ke monitoring. Tanpa penyesuaian API, kolom baru tetap NULL. Installer lama juga perlu ditinjau karena melakukan penghapusan dan pengisian ulang data. Semua langkah penerapan ini berada di luar pembuatan diagram dan belum dikerjakan.

## Verifikasi artefak

Generator memeriksa 7 tabel, 49 atribut, dan 6 FK; setiap target FK harus PK/UQ, tipe anak dan induk harus sama, serta kardinalitas harus cocok dengan nullability FK. SVG diperiksa sebagai XML. Ini pemeriksaan struktur artefak, bukan uji migrasi pada MySQL aktif.
'''
(OUT/'penjelasan-erd-hydrotech-crowfoot.md').write_text(doc,encoding='utf-8')
import xml.etree.ElementTree as ET
ET.parse(OUT/'erd-hydrotech-crowfoot.svg')
print('Selesai: PNG, SVG, PDF, SQL usulan, dan penjelasan. Terverifikasi: 7 tabel, 49 atribut, 6 FK; SVG XML valid.')
