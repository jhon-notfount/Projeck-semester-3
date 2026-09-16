"""Fresh installer and authenticated HTTP checks in an isolated temporary database."""
from pathlib import Path
import http.cookiejar, json, os, shutil, socket, subprocess, time, urllib.request, urllib.error, urllib.parse, uuid

root=Path(__file__).resolve().parent.parent
php=shutil.which('php')
ext=Path(php).parent/'ext'
command=[php,'-d',f'extension_dir={ext}','-d','extension=pdo_mysql']
name='hydrotech_sensor_http_'+uuid.uuid4().hex[:10]
env={**os.environ,'HYDROTECH_DB_NAME':name}
flags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0

def run(args,**kwargs):
    return subprocess.run(command+args,cwd=root,env=env,creationflags=flags,capture_output=True,text=True,**kwargs)

def db(code):
    script="<?php require 'config/database.php'; $pdo=getDBConnection(); "+code
    result=run([],input=script)
    if result.returncode: raise RuntimeError(result.stdout+result.stderr)
    return result.stdout

server=None
try:
    install=run(['database/install.php'])
    assert install.returncode==0,install.stdout+install.stderr
    repeat=run(['database/install.php'])
    assert repeat.returncode!=0,'Installer must refuse to reset an existing database'
    assert json.loads(db("echo json_encode([$pdo->query('SELECT COUNT(*) FROM sensors')->fetchColumn(),$pdo->query('SELECT COUNT(*) FROM sensor_readings')->fetchColumn()]);"))==[2,16]
    with socket.socket() as probe:
        probe.bind(('127.0.0.1',0))
        port=probe.getsockname()[1]
    base=f'http://127.0.0.1:{port}/'
    server=subprocess.Popen(command+['-S',f'127.0.0.1:{port}','-t',str(root)],cwd=root,env=env,creationflags=flags,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(path,data=None):
        payload=None if data is None else urllib.parse.urlencode(data).encode()
        try:
            with opener.open(base+path,payload,timeout=10) as response: return response.status,json.load(response)
        except urllib.error.HTTPError as error: return error.code,json.load(error)
    for _ in range(50):
        try:
            status,_=request('api/sensor/list.php')
            break
        except (ConnectionError,urllib.error.URLError): time.sleep(.1)
    else: raise RuntimeError('Test server did not start')
    assert status==401
    status,result=request('api/auth/login.php',{'username':'hydrotech','password':'Admin123'})
    assert status==200 and result['success'],result
    status,result=request('api/sensor/list.php')
    assert status==200 and len(result['data'])==2
    status,result=request('api/sensor/save.php',{'ph_sensor_id':1,'ppm_sensor_id':2,'ph_value':6.2,'ppm_value':950})
    assert status==200 and len(result['data']['reading_ids'])==2,result
    status,result=request('api/sensor/latest.php?sensor_id=1')
    assert status==200 and float(result['data'][0]['value'])==6.2 and result['data'][0]['type']=='ph'
    status,_=request('api/sensor/save.php',{'ph_value':15,'ppm_value':950})
    assert status==400
    status,result=request('api/sensor/save.php',{'sensor_id':2,'value':1200})
    assert status==200 and len(result['data']['readings'])==1
    status,result=request('api/dashboard/summary.php')
    assert status==200 and len(result['data']['latest_readings'])==2
    assert result['data']['latest_reading']['ph_value']==6.2 and result['data']['latest_reading']['ppm_value']==1200
    status,result=request('api/notifications/generate.php',{})
    assert status==200 and len(result['data'])==2 and all(x['sensor_reading_id'] for x in result['data'])
    status,result=request('api/settings/update.php',{'type':'ph','config':json.dumps({'min':'5.5','max':'6.7'})})
    assert status==200 and result['success']
    status,result=request('api/settings/reset.php',{'type':'ph'})
    assert status==200 and result['success']
    status,result=request('api/profile/update.php',{'section':'owner','data':json.dumps([{'label':'Nama','value':'Uji HTTP'}])})
    assert status==200 and result['success']
    audit=json.loads(db("echo json_encode([$pdo->query(\"SELECT updated_by FROM settings WHERE type='ph'\")->fetchColumn(),$pdo->query(\"SELECT updated_by FROM profile WHERE section='owner' LIMIT 1\")->fetchColumn()]);"))
    assert audit==[1,1],audit
    print('PASS: fresh installer, repeat-install protection, login, sensor list, paired/single saves, validation, filtered readings, summary, linked notifications, settings/profile audit.')
finally:
    if server:
        server.terminate()
        server.wait(timeout=10)
    assert name.startswith('hydrotech_sensor_http_') and name.replace('_','').isalnum()
    result=run([],input="<?php require 'config/database.php'; $p=new PDO('mysql:host='.$DB_HOST,$DB_USER,$DB_PASS); $p->exec('DROP DATABASE IF EXISTS `"+name+"`');")
    if result.returncode: print('Test database cleanup failed:',result.stderr)
