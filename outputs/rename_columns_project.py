from pathlib import Path
import json,re
root=Path(__file__).resolve().parents[1]
ids=dict(users='id_pengguna',settings='id_pengaturan',profile='id_profil',ph_readings='id_pembacaan_ph',ppm_readings='id_pembacaan_ppm',dithane_schedules='id_jadwal_dithane',monitoring_logs='id_monitoring',history_logs='id_riwayat',notifications='id_notifikasi')
common=dict(username='nama_pengguna',password='kata_sandi',role='peran',created_at='dibuat_pada',updated_at='diperbarui_pada',updated_by='diubah_oleh_id',type='jenis',config_json='konfigurasi_json',section='bagian',sort_order='urutan',recorded_at='direkam_pada',ph_value='nilai_ph',ppm_value='nilai_ppm',interval_hours='interval_jam',duration_seconds='durasi_detik',start_time='jam_mulai',day_name='nama_hari',log_time='jam_catatan',log_date='tanggal_catatan',note='keterangan',is_deleted='dihapus',ph_reading_id='id_pembacaan_ph',ppm_reading_id='id_pembacaan_ppm',message='pesan',tone='tingkat_pesan',href='tautan',is_read='sudah_dibaca')
schema=(root/'database/schema.sql').read_text(encoding='utf-8')
(root/'tests/fixtures/schema_before_indonesian.sql').write_text(schema,encoding='utf-8')
mapping={}
for table,body in re.findall(r'CREATE TABLE IF NOT EXISTS `(\w+)` \((.*?)\n\) ENGINE',schema,re.S):
    cols=re.findall(r'^\s*`(\w+)`\s+',body,re.M)
    mapping[table]={c:ids[table] if c=='id' else ('isi' if table=='profile' else 'nilai') if c=='value' else common.get(c,c) for c in cols}
(root/'database/column_names.json').write_text(json.dumps(mapping,indent=2),encoding='utf-8')
def words(s,mp):
    return re.sub(r'\b[a-z_]+\b',lambda m:mp.get(m[0],m[0]),s)
def change_schema(m):
    table,body=m[1],m[2]
    # Reference targets must use the parent table's PK name, not the child's.
    targets=[]
    def shield(x):
        targets.append(f'REFERENCES `{x[1]}` (`{mapping[x[1]][x[2]]}`)')
        return f'__REF{len(targets)-1}__'
    body=re.sub(r'REFERENCES `(\w+)` \(`(\w+)`\)',shield,body)
    body=re.sub(r'`(\w+)`',lambda x:'`'+mapping[table].get(x[1],x[1])+'`',body)
    for i,t in enumerate(targets):body=body.replace(f'__REF{i}__',t)
    return f'CREATE TABLE IF NOT EXISTS `{table}` ({body}\n) ENGINE'
new_schema=re.sub(r'CREATE TABLE IF NOT EXISTS `(\w+)` \((.*?)\n\) ENGINE',change_schema,schema,flags=re.S)
new_schema=new_schema.replace('-- Database 8 tabel: jalankan php database/migrate_measurements.php.', '-- Penamaan Indonesia: php database/migrate_column_names.php.')
(root/'database/schema.sql').write_text(new_schema,encoding='utf-8')

# Keep historical migrations pointed at their original schema, not the renamed one.
p=root/'database/migrate_measurements.php'
s=p.read_text(encoding='utf-8').replace("__DIR__.'/schema.sql'", "__DIR__.'/../tests/fixtures/schema_before_indonesian.sql'")
p.write_text(s,encoding='utf-8')

def projection(table):
    return ', '.join(f'`{new}` AS `{old}`' if old!=new else f'`{new}`' for old,new in mapping[table].items())

# Rewrite only SQL literals. HTTP field names, response keys, and session names remain stable.
def sql_convert(sql,table=None):
    dynamic=re.search(r'\{\$(type|kind)\}_readings',sql)
    if dynamic:
        v=dynamic[1];mp={'id':f'id_pembacaan_{{${v}}}','recorded_at':'direkam_pada'}
        # Shield SQL aliases and string constants before changing identifiers.
        sql=sql.replace(f'{{${v}}}_value',f'__PARAM_VALUE__')
        mp['__PARAM_VALUE__']=f'nilai_{{${v}}}'
    else:
        match=re.search(r'\b(?:FROM|INTO|UPDATE)\s+`?('+ '|'.join(mapping)+r')\b',sql,re.I)
        if match:table=match[1]
        if not table:return sql
        mp=mapping[table]
    if re.match(r'^\s*SELECT\s+',sql,re.I) or sql.startswith('(SELECT'):
        before,sep,after=re.split(r'\b(FROM)\b',sql,maxsplit=1,flags=re.I)
        if before.strip()=='SELECT *':
            before='SELECT '+projection(table)+' '
        else:
            prefix='(SELECT ' if before.startswith('(SELECT') else 'SELECT '
            expressions=before[len(prefix):].strip().split(',')
            out=[]
            for e in expressions:
                e=e.strip()
                if ' AS ' in e:
                    expr,alias=e.rsplit(' AS ',1)
                    # SQL string constants such as 'ph' are not identifiers.
                    converted=expr if expr.startswith("'") else words(expr,mp)
                    out.append(converted+' AS '+alias)
                else:
                    converted=words(e,mp)
                    old=e.replace('__PARAM_VALUE__',f'{{${v}}}_value') if dynamic else e
                    out.append(converted+' AS '+old if converted!=old and re.fullmatch(r'[a-z_{}$]+',old) else converted)
            before=prefix+', '.join(out)+' '
        # ORDER BY inside each SELECT may refer to the renamed physical column.
        sql=before+sep+words(after,mp)
    else:
        sql=words(sql,mp)
    return sql

quoted=re.compile(r'"(?:\\.|[^"\\])*"|\x27(?:\\.|[^\x27\\])*\x27',re.S)
def php_block(m,filename):
    block=m[0]
    default='history_logs' if filename=='api/history/list.php' else 'monitoring_logs' if filename=='api/monitoring/list.php' else None
    def convert(q):
        literal=q[0];body=literal[1:-1]
        if not re.match(r'^\s*(?:\(?SELECT\b|INSERT\b|UPDATE\b|DELETE\b|AND\b|ORDER BY\b)',body):return literal
        return literal[0]+sql_convert(body,default)+literal[-1]
    return quoted.sub(convert,block)
for base in ['api','includes','pages']:
    for p in (root/base).rglob('*.php'):
        rel=p.relative_to(root).as_posix();s=p.read_text(encoding='utf-8')
        # Most PHP files have no closing tag; handle the final open block as well.
        s=re.sub(r'<\?php[\s\S]*?(?:\?>|\Z)',lambda m:php_block(m,rel),s)
        if rel=='includes/sensors.php':
            s=s.replace("$column = $type . '_reading_id';", "$column = 'id_pembacaan_' . $type;")
            s=s.replace('{$type}_reading_id) VALUES', 'id_pembacaan_{$type}) VALUES')
        p.write_text(s,encoding='utf-8')
p=root/'database/install.php';s=p.read_text(encoding='utf-8')
s=re.sub(r'<\?php[\s\S]*?(?:\?>|\Z)',lambda m:php_block(m,'database/install.php'),s)
s=s.replace('php database/migrate_measurements.php','php database/migrate_column_names.php')
p.write_text(s,encoding='utf-8')
print('Schema and SQL literals updated; 60 columns mapped.')
