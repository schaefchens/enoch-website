"""HTTP integration tests using temporary accounts/config and no real cloud token."""
from pathlib import Path
import hashlib, hmac, http.cookiejar, json, os, re, shutil, socket, subprocess, tempfile, threading, time, urllib.request, urllib.error
from http.server import HTTPServer, BaseHTTPRequestHandler
root=Path(__file__).resolve().parents[1]
class SlowController(BaseHTTPRequestHandler):
 def do_GET(self):
  time.sleep(3);self.send_response(200);self.end_headers();self.wfile.write(b'{"status":"already-destroyed"}')
 def log_message(self,*args):pass
slow=HTTPServer(('127.0.0.1',0),SlowController)
threading.Thread(target=slow.serve_forever,daemon=True).start()
with tempfile.TemporaryDirectory(prefix='enoch-http-') as tmp:
 app=Path(tmp)
 for directory in ('app','public','config'):shutil.copytree(root/directory,app/directory)
 (app/'vendor').symlink_to(root/'vendor',target_is_directory=True)
 (app/'.env').write_text('APP_ENV=local\nSETUP_KEY='+'a'*64+'\nCRON_KEY='+'b'*64+'\nGAME_SERVER_ADMIN_KEY=dummy\n')
 (app/'config/tasks.php').write_text("<?php return ['spirit-idle'=>['title'=>'test','description'=>'Test controller','url'=>'http://127.0.0.1:%d','query'=>[], 'key_env'=>'GAME_SERVER_ADMIN_KEY','interval_env'=>'GAME_IDLE_CHECK_INTERVAL','interval'=>3600,'success_statuses'=>['already-destroyed']]];"%slow.server_port)
 sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close();base='http://127.0.0.1:'+str(port)
 server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(app/'public')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 jar=http.cookiejar.CookieJar();client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
 def request(path,body=None,headers=None):
  req=urllib.request.Request(base+path,data=body,headers=headers or {})
  try:
   r=client.open(req,timeout=10);return r.status,r.read(),dict(r.headers)
  except urllib.error.HTTPError as e:return e.code,e.read(),dict(e.headers)
 def post(action,data,token):return request('/api.php?action='+action,json.dumps(data).encode(),{'Content-Type':'application/json','X-CSRF-Token':token})
 try:
  for _ in range(100):
   try:status,body,headers=request('/');break
   except OSError:time.sleep(.05)
  assert status==200 and b'Set up your workspace' in body
  assert 'frame-ancestors' in headers['Content-Security-Policy']
  assert request('/api.php')[0]==401
  token=re.search(rb'name="csrf" value="([^"]+)"',body).group(1).decode()
  data=urllib.parse.urlencode(dict(csrf=token,key='a'*64,name='testadmin',password='a-long-test-password')).encode()
  status,body,_=request('/',data,{'Content-Type':'application/x-www-form-urlencoded'})
  assert status==200 and b'data-role="admin"' in body
  token=re.search(rb'name="csrf-token" content="([^"]+)"',body).group(1).decode()
  assert post('job',{'service':'nextcloud','operation':'stop'},'wrong')[0]==400
  assert post('user',{'name':'testviewer','password':'a-viewer-test-password','role':'viewer'},token)[0]==200
  assert post('job',{'service':'unknown','operation':'stop'},token)[0]==400
  assert post('cron-command',{},token)[0]==200
  assert post('save-credentials',{'service':'hpb','values':{'wolke_secret':'hidden-test-signaling'}},token)[0]==200
  assert b'hidden-test-signaling' in post('credentials',{'service':'hpb'},token)[1]
  assert b'hidden-test-signaling' not in request('/api.php')[1]
  status,body,_=post('scheduled-job',{'name':'Remote cleanup','url':'https://maintenance.example.org/run','method':'POST','interval_seconds':300,'enabled':True,'bearer':'hidden-managed-bearer'},token)
  assert status==200
  managed_id=json.loads(body)['id'];status,body,_=request('/api.php')
  dashboard=json.loads(body);managed=[task for task in dashboard['tasks'] if task.get('managed')][0]
  assert managed['name']=='Remote cleanup' and managed['has_bearer'] and 'hidden-managed-bearer' not in body.decode()
  managed.update({'bearer':'','interval_seconds':600,'enabled':False})
  assert post('scheduled-job',managed,token)[0]==200
  heartbeat=hmac.new(b'b'*64,b'enoch-activity:nextcloud',hashlib.sha256).hexdigest()
  assert request('/activity.php?service=nextcloud')[0]==405
  assert request('/activity.php?service=nextcloud',b'',{'Authorization':'Bearer wrong'})[0]==401
  assert request('/activity.php?service=nextcloud',b'',{'Authorization':'Bearer '+heartbeat})[0]==200
  assert request('/',headers={'Accept-Language':'de-DE,de;q=0.9,en;q=0.8'})[1].find('Das Anvertraute pflegen.'.encode())>=0
  assert request('/cron.php')[0]==401
  assert request('/cron.php',b'',{'Authorization':'Bearer wrong'})[0]==401
  started=time.monotonic();status,body,_=request('/cron.php',b'',{'Authorization':'Bearer '+'b'*64});elapsed=time.monotonic()-started
  assert status==200 and json.loads(body)['accepted'] and elapsed<1,elapsed
  # Finish the bounded background task before another request to PHP's single-threaded dev server.
  time.sleep(3.2)
  status,body,_=request('/api.php');result=json.loads(body)
  assert result['tasks'][0]['state']['status']=='ok'
  assert all(s['state']=='unknown' for s in result['services'])
  assert 'a'*64 not in body.decode() and 'b'*64 not in body.decode()
  request('/',urllib.parse.urlencode(dict(csrf=token,action='logout')).encode(),{'Content-Type':'application/x-www-form-urlencoded'})
  status,body,_=request('/');token=re.search(rb'name="csrf" value="([^"]+)"',body).group(1).decode()
  status,body,_=request('/',urllib.parse.urlencode(dict(csrf=token,name='testviewer',password='a-viewer-test-password')).encode(),{'Content-Type':'application/x-www-form-urlencoded'})
  token=re.search(rb'name="csrf-token" content="([^"]+)"',body).group(1).decode()
  assert post('job',{'service':'nextcloud','operation':'stop'},token)[0]==403
  assert post('user',{'name':'intruder','password':'a-long-test-password','role':'admin'},token)[0]==403
  assert post('cron-command',{},token)[0]==403
  assert post('scheduled-job',{'name':'Intrusion'},token)[0]==403
  assert post('credentials',{'service':'hpb'},token)[0]==403
  assert request('/api.php?action=options&service=hpb')[0]==403
  status,body,_=request('/api.php');data=json.loads(body)
  assert data['services']==[] and data['users']==[] and data['tasks']==[] and data['audit']==[]
  assert 'cron_seen' not in data
  assert 'hidden-test-signaling' not in body.decode()
  print('HTTP integration passed: setup, login, CSRF, roles, session logout, secret exclusion and cron authentication.')
  print(f'Cron acknowledged in {elapsed:.3f}s while downstream work took 3s.')
 finally:server.terminate();server.wait();slow.shutdown()
