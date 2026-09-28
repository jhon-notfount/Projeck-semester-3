"""Uji integrasi HTTP pada database sementara; tidak mengubah data aplikasi."""
from pathlib import Path
import os, subprocess, shutil, socket, time, json, urllib.request, urllib.parse, urllib.error, http.cookiejar, secrets
root=Path(__file__).resolve().parents[1]
php=shutil.which('php')
ext=Path(php).parent/'ext'
cmd=[php,'-d',f'extension_dir={ext}','-d','extension=pdo_mysql']
name='hydrotech_http_test_'+secrets.token_hex(5)
env=dict(os.environ,HYDROTECH_DB_NAME=name)
server=None
log=(root/'outputs/http_measurements_test.log').open('w')
try:
    subprocess.run(cmd+['database/install.php'],cwd=root,env=env,check=True,stdout=log,stderr=log)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
    server=subprocess.Popen(cmd+['-S',f'127.0.0.1:{port}'],cwd=root,env=env,stdout=log,stderr=log,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
    base=f'http://127.0.0.1:{port}/'
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def req(path,data=None,is_json=False,expected=200):
        headers={}
        if data is not None:
            if is_json:
                data=json.dumps(data).encode(); headers['Content-Type']='application/json'
            else: data=urllib.parse.urlencode(data).encode()
        request=urllib.request.Request(base+path,data=data,headers=headers)
        try: response=opener.open(request,timeout=10)
        except urllib.error.HTTPError as e: response=e
        text=response.read().decode()
        assert response.code==expected,(path,response.code,text)
        return json.loads(text) if path.startswith('api/') else text
    for _ in range(50):
        try:
            urllib.request.urlopen(base+'pages/login.php',timeout=1);break
        except (OSError,urllib.error.URLError):time.sleep(.1)
    req('api/sensor/latest.php',expected=401)
    assert req('api/auth/login.php',{'username':'hydrotech','password':'Admin123'})['success']
    for page in ['hydrotech-dashboard-panel-2026.php','pengaturan-ph.php','pengaturan-ppm.php','pengaturan-dithane.php','history.php','profile.php']:
        text=req('pages/'+page);assert 'Fatal error' not in text and 'Warning:' not in text,page
    result=req('api/sensor/save.php',{'ph_value':6.2512,'ppm_value':850},True)
    assert result['success'] and len(result['data']['readings'])==2
    for kind,value in [('ph',6.2512),('ppm',850)]:
        data=req('api/sensor/latest.php?type='+kind)['data']
        assert float(data[0]['value'])==value,(kind,data[0])
    assert req('api/sensor/latest.php')['success']
    req('api/sensor/save.php',{'ph_value':6,'ppm_value':-1},True,400)
    req('api/sensor/save.php',{'ppm_value':850.5},True,400)
    req('api/sensor/save.php',{'ph_value':15},True,400)
    req('api/sensor/latest.php?sensor_id=1',expected=400)
    assert req('api/settings/update.php',{'type':'dithane','config':json.dumps({'interval':'24','duration':'15','start':'09:30'})})['success']
    assert req('api/settings/get.php?type=dithane')['data']['config']['start']=='09.30'
    req('api/settings/update.php',{'type':'dithane','config':json.dumps({'interval':'0','duration':'15','start':'25:00'})},expected=400)
    assert '09.30' in req('pages/pengaturan-dithane.php')
    summary=req('api/dashboard/summary.php')['data']
    assert summary['settings']['dithane']['interval']=='24'
    assert summary['latest_reading']['ph_value']==6.2512
    assert req('api/settings/reset.php',{'type':'dithane'})['success']
    assert req('api/settings/get.php?type=dithane')['data']['config']['interval']=='48'
    assert req('api/notifications/generate.php',{})['success']
    print('PASS HTTP: fresh install, login, six pages, JSON save, latest, validation, schedule edit/reset, summary, notifications.')
finally:
    if server:
        server.terminate();server.wait(timeout=10)
    cleanup='<?php require "config/database.php"; $p=getDBConnection(); $n=$p->query("SELECT DATABASE()")->fetchColumn(); if (!preg_match("/^hydrotech_http_test_[a-f0-9]{10}$/D",$n)) exit(1); $p->exec("DROP DATABASE `$n`");'
    subprocess.run(cmd,cwd=root,env=env,input=cleanup,text=True,check=True,stdout=log,stderr=log)
    log.close()
