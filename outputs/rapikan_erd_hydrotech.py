from pathlib import Path
from html import escape
import re, math, xml.etree.ElementTree as ET
from PIL import Image, ImageDraw, ImageFont

OUT=Path(__file__).resolve().parent
source=(OUT.parent/'database/schema.sql').read_text(encoding='utf-8')
tables={}; relations=[]
for table,body in re.findall(r'CREATE TABLE IF NOT EXISTS `(\w+)` \((.*?)\) ENGINE',source,re.S):
    fields=[]
    for raw in body.splitlines():
        m=re.match(r'\s*`(\w+)`\s+(\w+(?:\([^)]*\))?)(.*)',raw)
        if m:
            fields.append(dict(name=m[1],type=m[2],nullable='NOT NULL' not in m[3] and 'PRIMARY KEY' not in m[3],pk='PRIMARY KEY' in m[3],uq='UNIQUE' in m[3],fk=False))
    for child_col,parent,parent_col in re.findall(r'FOREIGN KEY \(`(\w+)`\)\s+REFERENCES `(\w+)` \(`(\w+)`\)',body):
        f=next(f for f in fields if f['name']==child_col)
        f['fk']=True
        relations.append((parent,parent_col,table,child_col,f['nullable']))
    tables[table]=fields
assert len(tables)==8 and sum(len(f) for f in tables.values())==57 and len(relations)==7
for parent,pk,child,fk,optional in relations:
    p=next(f for f in tables[parent] if f['name']==pk)
    c=next(f for f in tables[child] if f['name']==fk)
    assert (p['pk'] or p['uq']) and p['type']==c['type']

titles={'users':'Pengguna','settings':'Pengaturan','profile':'Profil','sensors':'Sensor','sensor_readings':'Pembacaan Sensor','monitoring_logs':'Catatan Monitoring','history_logs':'Riwayat','notifications':'Notifikasi'}
ids={'users':'id_pengguna','settings':'id_pengaturan','profile':'id_profil','sensors':'id_sensor','sensor_readings':'id_pembacaan','monitoring_logs':'id_monitoring','history_logs':'id_riwayat','notifications':'id_notifikasi'}
aliases={'username':'nama_pengguna','password':'kata_sandi','email':'email','role':'peran','created_at':'dibuat_pada','updated_at':'diperbarui_pada','type':'jenis','config_json':'konfigurasi_json','updated_by':'diubah_oleh_id','section':'bagian','label':'label','value':'nilai','sort_order':'urutan','name':'nama_sensor','unit':'satuan','status':'status','location':'lokasi','sensor_id':'id_sensor','recorded_at':'direkam_pada','day_name':'nama_hari','log_time':'jam_catatan','note':'keterangan','is_deleted':'dihapus','sensor_reading_id':'id_pembacaan','log_date':'tanggal_catatan','message':'pesan','tone':'tingkat_pesan','href':'tautan','is_read':'sudah_dibaca'}
def alias(table,f):
    if f=='id': return ids[table]
    if table=='sensors' and f=='type': return 'jenis_sensor'
    if table=='sensors' and f=='status': return 'status_sensor'
    if table=='profile' and f=='value': return 'isi'
    return aliases[f]

def canvas(w,h):
    global im,d,svg,W,H
    W,H=w,h
    im=Image.new('RGB',(W,H),'white'); d=ImageDraw.Draw(im)
    svg=[f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}">','<rect width="100%" height="100%" fill="white"/>']

def font(size,bold=False): return ImageFont.truetype('C:/Windows/Fonts/arialbd.ttf' if bold else 'C:/Windows/Fonts/arial.ttf',size)
def text(x,y,value,size=25,bold=False,anchor='middle',color='#24333c'):
    d.text((x,y),value,font=font(size,bold),fill=color,anchor='mm' if anchor=='middle' else 'lm')
    svg.append(f'<text x="{x}" y="{y}" dominant-baseline="central" text-anchor="{anchor}" font-family="Arial,sans-serif" font-size="{size}" font-weight="{"bold" if bold else "normal"}" fill="{color}">{escape(value)}</text>')
def line(points,width=2,color='#44555f'):
    d.line(points,fill=color,width=width)
    svg.append(f'<polyline points="{" ".join(f"{x},{y}" for x,y in points)}" fill="none" stroke="{color}" stroke-width="{width}"/>')
def rect(x,y,w,h,fill='white',stroke='#667881',width=2):
    d.rectangle((x,y,x+w,y+h),fill=fill,outline=stroke,width=width)
    svg.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" fill="{fill}" stroke="{stroke}" stroke-width="{width}"/>')
def ellipse(x,y,rx,ry):
    d.ellipse((x-rx,y-ry,x+rx,y+ry),fill='white',outline='#44555f',width=2)
    svg.append(f'<ellipse cx="{x}" cy="{y}" rx="{rx}" ry="{ry}" fill="white" stroke="#44555f" stroke-width="2"/>')
def tag(x,y,label,size=23):
    width=d.textlength(label,font=font(size))+24
    rect(x-width/2,y-19,width,38,'white','white',1)
    text(x,y,label,size)

def mark(point,nextpoint,kind):
    x,y=point; length=math.dist(point,nextpoint)
    ux=(nextpoint[0]-x)/length; uy=(nextpoint[1]-y)/length
    def at(t,s=0): return (x+ux*t-uy*s,y+uy*t+ux*s)
    if kind=='many':
        line([at(0,-15),at(29),at(0,15)],3)
        ellipse(*at(47),9,9)
    else:
        line([at(16,-15),at(16,15)],3)
        if kind=='optional': ellipse(*at(42),9,9)
        else: line([at(34,-15),at(34,15)],3)

def save(stem):
    svg.append('</svg>')
    (OUT/f'{stem}.svg').write_text('\n'.join(svg),encoding='utf-8')
    im.save(OUT/f'{stem}.png')
    im.save(OUT/f'{stem}.pdf','PDF',resolution=150)
    preview=im.resize((1400,round(H*1400/W)))
    preview.save(OUT/f'{stem}-pratinjau.jpg',quality=90)
    ET.parse(OUT/f'{stem}.svg')
    return im.copy()

# Layout follows the tree of seven FK relationships, with no crossing edges.
positions={'profile':(110,220),'users':(1260,220),'settings':(2410,220),
           'sensors':(110,990),'monitoring_logs':(1260,990),'history_logs':(2410,990),
           'sensor_readings':(1260,1800),'notifications':(2410,1800)}
palette={'profile':('#dfd1eb','#f5eff9'),'users':('#c2d9ea','#edf5fa'),'settings':('#c8dfbf','#f0f7ec'),
         'sensors':('#b9dccc','#edf7f1'),'sensor_readings':('#badde1','#edf7f8'),'monitoring_logs':('#e9d9a8','#fff9e8'),
         'history_logs':('#e8c9b5','#fcf1e9'),'notifications':('#e1bed0','#faf0f5')}
TW=800; TH=112; ROW=45
heights={t:TH+ROW*len(fs) for t,fs in tables.items()}
enum_refs={('users','role'):'A',('settings','type'):'B',('monitoring_logs','type'):'B',('history_logs','type'):'B',('notifications','tone'):'C',('profile','section'):'D',('sensors','type'):'E',('sensors','unit'):'F',('sensors','status'):'G'}

canvas(3320,2570)
text(W/2,62,'ERD SISTEM MONITORING HIDROPONIK HYDROTECH',43,True)
text(W/2,117,'VERSI TABEL / CROW\u2019S FOOT',27,True,color='#526670')
text(W/2,161,'8 tabel  •  57 kolom  •  7 foreign key  |  Nama kolom mengikuti database',24)
paths=[
 ('users','profile',[(1260,425),(910,425)],(1085,379),'Mengubah profil'),
 ('users','settings',[(2060,425),(2410,425)],(2235,379),'Mengubah pengaturan'),
 ('settings','monitoring_logs',[(2410,525),(2250,525),(2250,790),(1660,790),(1660,990)],(1940,756),'Mengelompokkan monitoring'),
 ('settings','history_logs',[(2810,557),(2810,990)],(2810,768),'Mengelompokkan riwayat'),
 ('sensors','sensor_readings',[(910,1230),(1085,1230),(1085,1960),(1260,1960)],(1085,1660),'Menghasilkan'),
 ('sensor_readings','monitoring_logs',[(1660,1800),(1660,1552)],(1660,1682),'Sumber monitoring'),
 ('sensor_readings','notifications',[(2060,1960),(2410,1960)],(2235,1915),'Sumber notifikasi'),
]
assert {(p,c) for p,c,*_ in paths}=={(p,c) for p,_,c,_,_ in relations}
for parent,child,points,labelpos,label in paths:
    optional=next(o for p,_,c,_,o in relations if p==parent and c==child)
    line(points,3)
    mark(points[0],points[1],'optional' if optional else 'one')
    mark(points[-1],points[-2],'many')
    tag(*labelpos,label,22)

for table,(x,y) in positions.items():
    head,fill=palette[table]; h=heights[table]
    rect(x+5,y+5,TW,h,'#e8ecee','#e8ecee',1)
    rect(x,y,TW,h,fill)
    rect(x,y,TW,72,head)
    text(x+TW/2,y+26,titles[table],28,True)
    text(x+TW/2,y+54,table,21)
    for dx,label in [(16,'KUNCI'),(123,'KOLOM'),(420,'TIPE DATA'),(718,'NULL')]: text(x+dx,y+93,label,21,True,anchor='start')
    line([(x,y+TH),(x+TW,y+TH)],1)
    for i,f in enumerate(tables[table]):
        cy=y+TH+ROW*i+ROW/2
        keys=', '.join(k for k,flag in [('PK',f['pk']),('UQ',f['uq']),('FK',f['fk'])] if flag)
        typ=f['type'] if not f['type'].startswith('ENUM') else 'ENUM ['+enum_refs[(table,f['name'])]+']'
        for dx,value,size,bold in [(16,keys,21,True),(123,f['name'],24,False),(420,typ,23,False),(720,'Ya' if f['nullable'] else 'Tidak',21,False)]: text(x+dx,cy,value,size,bold,anchor='start')
    for dx in [107,405,701]: line([(x+dx,y+72),(x+dx,y+h)],1,'#b1bec5')

# Compact legend fills the unused corner without adding report paragraphs to the figure.
rect(110,1800,800,472,'#f8fafb','#cbd5db',1)
text(145,1840,'KETERANGAN',25,True,anchor='start')
legend=['PK = kunci utama','FK = kunci tamu / referensi','UQ = nilai unik','NULL: Ya = boleh kosong','Garis tegak = satu; lingkaran = nol.','Kaki gagak = banyak (N).','Sensor → Pembacaan: satu ke banyak.','Nama Indonesia ada pada judul tabel.']
for i,value in enumerate(legend): text(145,1893+i*43,value,24,anchor='start')
line([(110,2330),(3210,2330)],1,'#a7b6bf')
text(110,2371,'ENUM  A: admin / user     B: ppm / ph / dithane     C: warning / success / info     D: owner / team',24,anchor='start')
text(110,2417,'ENUM  E: ph / ppm     F: pH / ppm     G: aktif / nonaktif / rusak',24,anchor='start')
text(110,2470,'FK jenis mengacu ke settings.type (UQ). Referensi pengguna dan pembacaan pada catatan boleh kosong.',24,anchor='start')
text(110,2516,'Sumber: schema.sql dan struktur pada gambar. Semua relasi non-identifying; setiap tabel memiliki PK id sendiri.',22,anchor='start')
crow=save('erd-hydrotech-rapi-crowfoot')

# Chen with standard min/max participation at each entity end.
canvas(4480,3380)
text(W/2,64,'ERD SISTEM MONITORING HIDROPONIK HYDROTECH',47,True)
text(W/2,122,'VERSI CHEN — ENTITAS, ATRIBUT, DAN RELASI',28,True,color='#526670')
text(W/2,168,'8 entitas  •  57 atribut  •  7 relasi  |  Nama entitas dan atribut dalam bahasa Indonesia',26)
centers={'profile':(660,655),'users':(2160,655),'settings':(3660,655),
         'sensors':(660,1680),'monitoring_logs':(2160,1680),'history_logs':(3660,1680),
         'sensor_readings':(2160,2700),'notifications':(3660,2700)}

def diamond(x,y,label):
    pts=[(x,y-84),(x+142,y),(x,y+84),(x-142,y)]
    d.polygon(pts,fill='white',outline='#44555f',width=2)
    svg.append(f'<polygon points="{" ".join(f"{a},{b}" for a,b in pts)}" fill="white" stroke="#44555f" stroke-width="2"/>')
    lines=label.split('|')
    for i,s in enumerate(lines): text(x,y+(i-(len(lines)-1)/2)*30,s,23)

chen_paths=[
 ('users','profile',[(1980,655),(840,655)],(1410,655),'Mengubah|profil'),
 ('users','settings',[(2340,655),(3480,655)],(2910,655),'Mengubah|pengaturan'),
 ('settings','monitoring_logs',[(3540,712),(3540,1165),(2160,1165),(2160,1623)],(2910,1165),'Mengelompokkan|monitoring'),
 ('settings','history_logs',[(3720,712),(3720,1623)],(3720,1165),'Mengelompokkan|riwayat'),
 ('sensors','sensor_readings',[(840,1680),(1410,1680),(1410,2700),(1980,2700)],(1410,2200),'Menghasilkan'),
 ('sensor_readings','monitoring_logs',[(2160,2643),(2160,1737)],(2160,2200),'Menjadi sumber|monitoring'),
 ('sensor_readings','notifications',[(2340,2700),(3480,2700)],(2910,2700),'Menjadi sumber|notifikasi'),
]
assert {(p,c) for p,c,*_ in chen_paths}=={(p,c) for p,_,c,_,_ in relations}
for parent,child,points,center,label in chen_paths:
    optional=next(o for p,_,c,_,o in relations if p==parent and c==child)
    line(points,2)
    diamond(*center,label)
    for p,q,value in [(points[0],points[1],'(0, N)'),(points[-1],points[-2],'(0, 1)' if optional else '(1, 1)')]:
        length=math.dist(p,q); ux=(q[0]-p[0])/length; uy=(q[1]-p[1])/length
        # Keep labels close to their entity, away from attribute ovals.
        tx=p[0]+ux*78+(95 if ux==0 else 0)
        ty=p[1]+uy*78+(-28 if uy==0 else 0)
        tag(tx,ty,value,23)

for table,(x,y) in centers.items():
    nodes=[]
    for side,fs in [(-1,tables[table][::2]),(1,tables[table][1::2])]:
        offsets={2:[-160,160],3:[-230,-95,205],4:[-290,-130,130,290],5:[-330,-210,-90,130,290]}[len(fs)]
        nodes.extend((f,x+side*390,y+dy) for f,dy in zip(fs,offsets))
    for f,ax,ay in nodes: line([(x,y),(ax,ay)],2)
    for f,ax,ay in nodes:
        ellipse(ax,ay,173,44)
        value=alias(table,f['name'])+(' (FK)' if f['fk'] else '')
        text(ax,ay,value,23)
        if f['pk']:
            tw=d.textlength(value,font=font(23))
            line([(ax-tw/2,ay+17),(ax+tw/2,ay+17)],2)
    rect(x-180,y-57,360,114,'white','#44555f',2)
    text(x,y-10,titles[table],27,True)
    text(x,y+26,table,19,color='#667881')

rect(110,2380,1040,550,'#fafbfc','#cbd5db',1)
text(155,2430,'KETERANGAN NOTASI CHEN',29,True,anchor='start')
for i,value in enumerate(['Kotak = entitas; oval = atribut.','Belah ketupat = hubungan antarentitas.','Atribut bergaris bawah = primary key.','(FK) = atribut referensi ke entitas lain.','(min, maks) di dekat entitas menyatakan','jumlah pasangan yang dapat dimilikinya.','Contoh: satu Sensor mempunyai (0, N)','pembacaan; satu Pembacaan berasal dari','(1, 1) Sensor.']):
    text(155,2490+i*44,value,25,anchor='start')
line([(110,3110),(4370,3110)],1,'#a7b6bf')
text(W/2,3160,'(0, N) = nol atau banyak  |  (0, 1) = nol atau satu  |  (1, 1) = wajib tepat satu',28,True)
text(W/2,3220,'Setiap atribut pada diagram berasal dari skema tabel; nama Indonesia merupakan padanan untuk dokumentasi.',27)
text(W/2,3280,'Detail tipe data, NULL, UNIQUE, dan nilai ENUM tersedia pada versi tabel / Crow\u2019s Foot.',26)
chen=save('erd-hydrotech-rapi-chen')
crow.save(OUT/'erd-hydrotech-rapi-dua-versi.pdf','PDF',resolution=150,save_all=True,append_images=[chen])
print('Selesai: dua versi PNG, SVG, PDF, serta PDF gabungan. Validasi: 8 tabel, 57 atribut, 7 relasi; kedua SVG valid.')
