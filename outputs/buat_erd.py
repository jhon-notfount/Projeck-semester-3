from pathlib import Path
from html import escape
from PIL import Image, ImageDraw, ImageFont
import math

OUT = Path(__file__).parent
W, H = 3300, 2480
im = Image.new('RGB', (W, H), 'white')
d = ImageDraw.Draw(im)
svg = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}">', '<rect width="100%" height="100%" fill="white"/>']
font_path = 'C:/Windows/Fonts/arial.ttf'
bold_path = 'C:/Windows/Fonts/arialbd.ttf'

def text(x, y, label, size=25, bold=False, underline=False):
    f = ImageFont.truetype(bold_path if bold else font_path, size)
    d.text((x,y), label, font=f, fill='#171717', anchor='mm')
    if underline:
        width = d.textlength(label,font=f)
        d.line((x-width/2,y+size*.55,x+width/2,y+size*.55),fill='#171717',width=2)
    svg.append(f'<text x="{x}" y="{y}" dominant-baseline="central" text-anchor="middle" font-family="Arial, sans-serif" font-size="{size}" font-weight="{"bold" if bold else "normal"}" text-decoration="{"underline" if underline else "none"}">{escape(label)}</text>')

def line(points, dashed=False):
    for (x1,y1),(x2,y2) in zip(points,points[1:]):
        if dashed:
            length=math.hypot(x2-x1,y2-y1)
            for start in range(0,int(length),19):
                end=min(start+11,length)
                d.line((x1+(x2-x1)*start/length,y1+(y2-y1)*start/length,x1+(x2-x1)*end/length,y1+(y2-y1)*end/length),fill='#555555',width=3)
        else:
            d.line((x1,y1,x2,y2),fill='#333333',width=2)
    dash=' stroke-dasharray="11 8"' if dashed else ''
    svg.append(f'<polyline points="{" ".join(f"{x},{y}" for x,y in points)}" fill="none" stroke="#333" stroke-width="2"{dash}/>')

def shape(kind, box):
    x1,y1,x2,y2=box
    if kind=='ellipse':
        d.ellipse(box,fill='white',outline='#222222',width=2)
        svg.append(f'<ellipse cx="{(x1+x2)/2}" cy="{(y1+y2)/2}" rx="{(x2-x1)/2}" ry="{(y2-y1)/2}" fill="white" stroke="#222" stroke-width="2"/>')
    elif kind=='rect':
        d.rectangle(box,fill='white',outline='#222222',width=3)
        svg.append(f'<rect x="{x1}" y="{y1}" width="{x2-x1}" height="{y2-y1}" fill="white" stroke="#222" stroke-width="2"/>')
    else:
        pts=[((x1+x2)/2,y1),(x2,(y1+y2)/2),((x1+x2)/2,y2),(x1,(y1+y2)/2)]
        d.polygon(pts,fill='white',outline='#222222',width=2)
        svg.append(f'<polygon points="{" ".join(f"{x},{y}" for x,y in pts)}" fill="white" stroke="#222" stroke-width="2"/>')

def relation(x,y,label,rx=115):
    shape('diamond',(x-rx,y-65,x+rx,y+65))
    for i,t in enumerate(label.split('|')):
        text(x,y+(i-(len(label.split('|'))-1)/2)*29,t,23)

entities = [
 ('Pengguna','users',1650,365,['id_pengguna','nama_pengguna','kata_sandi','email','peran','dibuat_pada','diperbarui_pada']),
 ('Profil','profile',600,1080,['id_profil','bagian','label','isi','urutan']),
 ('Pengaturan','settings',1650,1080,['id_pengaturan','jenis','konfigurasi_json','diperbarui_pada']),
 ('Riwayat','history_logs',2700,1080,['id_riwayat','nama_hari','tanggal_catatan','jam_catatan','jenis','status','keterangan','dihapus','dibuat_pada']),
 ('Catatan Monitoring','monitoring_logs',600,1900,['id_monitoring','jenis','nama_hari','jam_catatan','nilai','status','keterangan','dihapus','dibuat_pada']),
 ('Pembacaan Sensor','sensor_readings',1650,1900,['id_pembacaan','nilai_ph','nilai_ppm','direkam_pada']),
 ('Notifikasi','notifications',2700,1900,['id_notifikasi','judul','pesan','tingkat_pesan','tautan','sudah_dibaca','dibuat_pada']),
]

text(1650,32,'ERD SISTEM MONITORING HIDROPONIK HYDROTECH',38,True)


# Hubungan fungsional yang terlihat pada kode, bukan foreign key yang sudah diterapkan.
line([(1650,409),(1650,710),(600,710),(600,1036)],True)
line([(1650,710),(2700,710),(2700,1036)],True)
line([(1650,710),(1650,1036)],True)
line([(600,710),(100,710),(100,1510),(600,1510),(600,1856)],True)
line([(1650,1124),(1650,1856)],True)
line([(1785,1900),(2565,1900)],True)
relation(600,710,'Mengelola')
relation(1650,710,'Mengatur')
relation(2700,710,'Menghapus')
relation(100,1510,'Menghapus',85)
relation(1650,1510,'Menjadi|acuan')
relation(2175,1900,'Memicu')

for name,table,x,y,attrs in entities:
    # Oval di kiri dan kanan menjaga jalur hubungan vertikal tetap bebas.
    left=attrs[::2]; right=attrs[1::2]
    positions=[]
    for side,items in [(-1,left),(1,right)]:
        offsets={2:[-140,140],3:[-210,-70,160],4:[-240,-100,100,240],5:[-280,-140,0,140,280]}[len(items)]
        for a,dy in zip(items,offsets):
            positions.append((a,x+side*285,y+dy))
    for a,ax,ay in positions:
        line([(x,y),(ax,ay)])
    for a,ax,ay in positions:
        shape('ellipse',(ax-144,ay-46,ax+144,ay+46))
        text(ax,ay,a,24,underline=a==attrs[0])
    shape('rect',(x-135,y-44,x+135,y+44))
    text(x,y,name,25,True)

line([(260,2270),(500,2270)])
text(850,2270,'Garis utuh: atribut milik entitas',25)
line([(1530,2270),(1770,2270)],True)
text(2270,2270,'Garis putus-putus: hubungan konseptual aplikasi',25)
text(1650,2330,'Atribut bergaris bawah = kunci utama (PK). Nama atribut diterjemahkan; kolom SQL tetap mengikuti skema asli.',25)
text(1650,2375,'Belum ada foreign key atau kardinalitas yang ditegakkan database. Riwayat dan monitoring belum otomatis diisi dari pembacaan sensor.',25)
text(1650,2420,'Sumber: database/schema.sql dan kode API Hydrotech. Diagram ini menjelaskan struktur serta hubungan fungsional sistem saat ini.',23)

svg.append('</svg>')
(OUT/'erd-hydrotech.svg').write_text('\n'.join(svg),encoding='utf-8')
im.save(OUT/'erd-hydrotech.png')
im.save(OUT/'erd-hydrotech.pdf','PDF',resolution=150)
im.resize((1320,992)).save(OUT/'erd-pratinjau.png')
print('ERD PNG, SVG, PDF berhasil dibuat.')
