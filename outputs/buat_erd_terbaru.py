from pathlib import Path
import re, math, json, xml.etree.ElementTree as ET

OUT=Path(__file__).resolve().parent
# Reuse the local vector/raster drawing helpers; do not generate the old diagram.
base=(OUT/'rapikan_erd_hydrotech.py').read_text(encoding='utf-8').split('# Layout follows')[0]
base=base.replace('len(tables)==8 and sum(len(f) for f in tables.values())==57 and len(relations)==7',
                  'len(tables)==9 and sum(len(f) for f in tables.values())==60 and len(relations)==9')
exec(compile(base,str(OUT/'rapikan_erd_hydrotech.py'),'exec'))

titles.update({'ph_readings':'Pembacaan pH','ppm_readings':'Pembacaan PPM','dithane_schedules':'Jadwal Dithane'})
TW=900; TH=116; ROW=44
positions={'profile':(140,260),'users':(1500,260),'dithane_schedules':(2860,260),
           'monitoring_logs':(140,1210),'settings':(1500,1050),'history_logs':(2860,1050),
           'ph_readings':(140,2110),'ppm_readings':(1500,2110),'notifications':(2860,2110)}
heights={t:TH+ROW*len(fs) for t,fs in tables.items()}
palette={'profile':('#ded4ef','#f7f3fc'),'users':('#c9e1f1','#f2f8fc'),
         'settings':('#cce5d2','#f3faf5'),'dithane_schedules':('#cce5d2','#f3faf5'),
         'monitoring_logs':('#f0dfb2','#fffaf0'),'history_logs':('#f0dfb2','#fffaf0'),
         'notifications':('#eed0dd','#fff5f9'),'ph_readings':('#bde4e7','#f1fbfc'),
         'ppm_readings':('#bde4e7','#f1fbfc')}
enum_refs={('users','peran'):'A',('settings','jenis'):'B',('monitoring_logs','jenis'):'B',('history_logs','jenis'):'B',('notifications','tingkat_pesan'):'C',('profile','bagian'):'D'}

canvas(3900,3210)
text(140,74,'HYDROTECH',48,True,anchor='start',color='#155e63')
text(140,131,'ERD DATABASE — STRUKTUR TERBARU',38,True,anchor='start')
text(140,185,'9 tabel  •  60 kolom  •  9 foreign key  |  PPM: INT  |  Notasi Crow’s Foot',27,anchor='start',color='#526670')
text(3410,180,'29 September 2026',25,anchor='start',color='#526670')

paths=[
('R1','users','profile',[(1500,450),(1040,450)],(1270,398),'Pengubah terakhir'),
('R2','users','settings',[(1950,684),(1950,1050)],(1950,859),'Pengubah terakhir'),
('R3','users','dithane_schedules',[(2400,450),(2860,450)],(2630,398),'Pengubah jadwal'),
('R4','settings','monitoring_logs',[(1500,1190),(1270,1190),(1270,1400),(1040,1400)],(1260,1130),'Kategori monitoring'),
('R5','settings','history_logs',[(2400,1230),(2860,1230)],(2630,1178),'Kategori riwayat'),
('R6','ph_readings','monitoring_logs',[(590,2110),(590,1810)],(590,1960),'Sumber pH'),
('R7','ppm_readings','monitoring_logs',[(1500,2240),(1210,2240),(1210,1650),(1040,1650)],(1220,1900),'Sumber PPM'),
('R8','ph_readings','notifications',[(590,2358),(590,2740),(3310,2740),(3310,2622)],(1950,2740),'Sumber pH untuk notifikasi'),
('R9','ppm_readings','notifications',[(2400,2240),(2860,2240)],(2630,2188),'Sumber PPM'),
]
# Validate all endpoint locations and every schema relationship before rendering.
assert {(p,c) for _,p,c,*_ in paths}=={(p,c) for p,_,c,_,_ in relations}
for rid,parent,child,points,labelpos,label in paths:
    for name,pt in [(parent,points[0]),(child,points[-1])]:
        x,y=positions[name];h=heights[name];px,py=pt
        assert ((px in [x,x+TW] and y<=py<=y+h) or (py in [y,y+h] and x<=px<=x+TW)),(name,pt,h)

def dashed(points,color='#536872'):
    for (x1,y1),(x2,y2) in zip(points,points[1:]):
        length=math.dist((x1,y1),(x2,y2));ux=(x2-x1)/length;uy=(y2-y1)/length
        for step in range(0,math.ceil(length),23):
            end=min(length,step+14)
            d.line([(x1+ux*step,y1+uy*step),(x1+ux*end,y1+uy*end)],fill=color,width=3)
    svg.append(f'<polyline points="{" ".join(f"{x},{y}" for x,y in points)}" fill="none" stroke="{color}" stroke-width="3" stroke-dasharray="14 9"/>')

for rid,parent,child,points,labelpos,label in paths:
    optional=next(o for p,_,c,_,o in relations if p==parent and c==child)
    dashed(points)
    mark(points[0],points[1],'optional' if optional else 'one')
    mark(points[-1],points[-2],'optional' if child=='dithane_schedules' else 'many')
    tag(*labelpos,rid+' · '+label,23)

for table,(x,y) in positions.items():
    head,fill=palette[table];h=heights[table]
    rect(x+6,y+6,TW,h,'#e9eef0','#e9eef0',1)
    rect(x,y,TW,h,fill)
    rect(x,y,TW,76,head)
    text(x+TW/2,y+26,titles[table],29,True)
    text(x+TW/2,y+56,table,23)
    for dx,label in [(16,'KUNCI'),(125,'KOLOM'),(464,'TIPE DATA'),(800,'NULL')]:
        text(x+dx,y+97,label,21,True,anchor='start')
    line([(x,y+TH),(x+TW,y+TH)],1)
    for i,f in enumerate(tables[table]):
        cy=y+TH+ROW*i+ROW/2
        keys=', '.join(k for k,flag in [('PK',f['pk']),('UQ',f['uq']),('FK',f['fk'])] if flag)
        typ=f['type'] if not f['type'].startswith('ENUM') else 'ENUM ['+enum_refs[(table,f['name'])]+']'
        for dx,value,size,bold in [(16,keys,21,True),(125,f['name'],25,False),(464,typ,24,False),(800,'Ya' if f['nullable'] else 'Tidak',22,False)]:
            text(x+dx,cy,value,size,bold,anchor='start')
    for dx in [108,445,780]: line([(x+dx,y+76),(x+dx,y+h)],1,'#bbc9cf')

# Legend placed in unused space, keeping the main relationship routes clear.
rect(140,740,900,325,'#f6f9fa','#cfdbdf',1)
text(175,782,'CARA MEMBACA',27,True,anchor='start',color='#155e63')
for i,value in enumerate(['PK = kunci utama; FK = kunci referensi; UQ = unik.',
    'NULL: Ya berarti kolom boleh kosong.',
    'Dua garis = tepat satu; lingkaran + garis = 0 atau 1.',
    'Lingkaran + kaki gagak = 0 sampai banyak (N).',
    'Garis putus-putus = relasi non-identifying.',
    'R1–R9 merujuk pada daftar relasi di halaman kedua.']):
    text(175,828+i*37,value,23,anchor='start')

rect(2860,1660,900,295,'#f6f9fa','#cfdbdf',1)
text(2895,1700,'ATURAN PENTING',27,True,anchor='start',color='#155e63')
for i,value in enumerate(['Jadwal: id_jadwal_dithane = 1; hanya satu baris.',
    'pH: 0–14; PPM: bilangan bulat tidak negatif.',
    'Pada monitoring dan notifikasi, kedua ID',
    'pembacaan tidak boleh terisi bersamaan.',
    'Keduanya boleh kosong untuk catatan umum.']):
    text(2895,1749+i*39,value,24,anchor='start')

line([(140,2865),(3760,2865)],2,'#b7c6cc')
text(140,2920,'PILIHAN ENUM',27,True,anchor='start',color='#155e63')
text(140,2970,'A: admin / user     B: ppm / ph / dithane     C: warning / success / info     D: owner / team',26,anchor='start')
text(140,3030,'Kategori Dithane tetap ada pada settings untuk relasi log; parameter jadwal disimpan di dithane_schedules.',25,anchor='start')
text(140,3080,'Relasi jadwal menunjukkan batas aktual 0..1 karena hanya satu baris diizinkan di seluruh tabel.',25,anchor='start')
text(140,3140,'Sumber: database/schema.sql. Nama kolom dan tipe data mengikuti implementasi terbaru.',24,anchor='start',color='#526670')
diagram=save('ERD_Hydrotech_Terbaru_9_Tabel')

# A separate reference sheet makes the exact column mapping easy to present.
canvas(3900,3210)
text(140,90,'HYDROTECH — PENJELASAN RELASI ERD',43,True,anchor='start',color='#155e63')
text(140,153,'Pendamping diagram · seluruh foreign key dan aturan kardinalitas',28,anchor='start')
y=240
for rid,parent,child,*_ in paths:
    rel=next(r for r in relations if r[0]==parent and r[2]==child)
    _,pk,_,fk,optional=rel
    rect(140,y,3620,190,'#f3f8f9','#d2dfe3',1)
    text(180,y+46,f'{rid}   {child}.{fk}  →  {parent}.{pk}',34,True,anchor='start')
    cardinality='Satu induk dapat dirujuk 0..N baris anak.' if child!='dithane_schedules' else 'Maksimal satu jadwal di seluruh sistem; satu pengguna dapat dirujuk 0..1 jadwal.'
    text(180,y+99,cardinality,28,anchor='start')
    text(180,y+145,('Satu baris anak boleh tanpa induk (FK NULL), atau merujuk tepat satu induk.' if optional else 'Setiap baris anak wajib merujuk tepat satu induk (FK NOT NULL).'),27,anchor='start')
    y+=220
text(140,2300,'ATURAN REFERENSI DAN PENGHAPUSAN',31,True,anchor='start',color='#155e63')
notes=[
'R1, R2, R3: pengguna dihapus → diubah_oleh_id menjadi NULL (ON DELETE SET NULL).',
'R4–R9: data induk yang masih dirujuk tidak dapat dihapus (ON DELETE RESTRICT).',
'settings.jenis adalah UNIQUE, sehingga sah menjadi tujuan foreign key walaupun bukan primary key.',
'monitoring_logs: id_pembacaan_ph hanya untuk jenis ph; id_pembacaan_ppm hanya untuk jenis ppm.',
'Monitoring dan notifikasi tidak boleh merujuk pH dan PPM sekaligus dalam satu baris.',
'history_logs tidak mempunyai FK langsung ke pembacaan maupun jadwal Dithane.',
'diubah_oleh_id hanya menunjukkan pengubah terakhir; bukan daftar seluruh perubahan.',
'Garis ERD menjelaskan hubungan penyimpanan data, bukan bukti bahwa perangkat ESP32 sudah terhubung.'
]
for i,value in enumerate(notes):text(140,2370+i*70,value,28,anchor='start')
text(140,3045,'Disusun berdasarkan skema proyek pada 29 September 2026.',26,anchor='start',color='#526670')
reference=save('ERD_Hydrotech_Terbaru_Relasi')
diagram.save(OUT/'ERD_Hydrotech_Terbaru_Lengkap.pdf','PDF',resolution=170,save_all=True,append_images=[reference])
(OUT/'ERD_Hydrotech_Terbaru_Metadata.json').write_text(json.dumps({'tables':len(tables),'columns':sum(map(len,tables.values())),'foreign_keys':len(relations),'relationships':relations},indent=2),encoding='utf-8')
print('Selesai: PNG, SVG, dan PDF 2 halaman; 9 tabel / 60 kolom / 9 relasi.')
