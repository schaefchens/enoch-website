#!/usr/bin/env python3
"""SFTP deployment. Keeps live secrets and state on updates; no remote shell needed."""
from pathlib import Path
import argparse, json, os, shutil, sqlite3, subprocess, tempfile, urllib.request, urllib.error
ROOT=Path(__file__).resolve().parents[1]
def env_values(path):
 result={}
 for line in path.read_text().splitlines():
  if '=' not in line or line.lstrip().startswith('#'):continue
  k,v=line.split('=',1);v=v.strip()
  if len(v)>1 and v[0] in '\"\'' and v[-1]==v[0]:v=v[1:-1]
  result[k.strip()]=v
 return result
def quote(path):return '"'+str(path).replace('\\','\\\\').replace('"','\\"')+'"'
def main():
 args=argparse.ArgumentParser();args.add_argument('--initial',action='store_true');args=args.parse_args()
 values=env_values(ROOT/'.env');base=values.get('APP_URL','https://enoch.schaefchens.de').rstrip('/')
 if not base.startswith('https://'):raise RuntimeError('Deployment requires HTTPS.')
 for binary in ('sshpass','sftp'):
  if not shutil.which(binary):raise RuntimeError('Install '+binary+' locally first.')
 with tempfile.TemporaryDirectory(prefix='enoch-deploy-') as temp:
  stage=Path(temp);child_env=os.environ.copy();child_env['SSHPASS']=values['SFTP_PASSWD']
  def sftp(commands,check=True):
   batch=stage/'batch';batch.write_text('\n'.join(commands)+'\n');batch.chmod(0o600)
   r=subprocess.run(['sshpass','-e','sftp','-o','StrictHostKeyChecking=yes','-o','PubkeyAuthentication=no','-o','PreferredAuthentications=password','-o','BatchMode=no','-o','ConnectTimeout=10','-o','ServerAliveInterval=15','-b',str(batch),values['SFTP_SERVER']],env=child_env,capture_output=True,text=True,timeout=300)
   if check and r.returncode:raise RuntimeError('SFTP failed: '+r.stderr[-1000:])
   return r
  def get(path):
   try:
    with urllib.request.urlopen(base+path,timeout=15) as response:return response.status,response.read()
   except urllib.error.HTTPError as e:return e.code,e.read()
  if args.initial:
   for file in ('/_enoch/.env','/_enoch/var/enoch.sqlite'):
    if sftp(['ls '+file],check=False).returncode==0:raise RuntimeError('Initial deployment refuses to overwrite '+file)
  marker=stage/'access-check.txt';marker.write_text('Enoch private access probe')
  sftp(['-mkdir /_enoch','chmod 755 /_enoch','put '+quote(ROOT/'.htaccess')+' /_enoch/.htaccess','put '+quote(marker)+' /_enoch/access-check.txt','chmod 644 /_enoch/access-check.txt'])
  if get('/_enoch/access-check.txt')[0]!=403:raise RuntimeError('Private directory is not denied over HTTPS. No credentials uploaded.')
  print('Private directory is denied over HTTPS.',flush=True)
  probe=stage/'enoch-requirements-check.php'
  probe.write_text('<?php header("Content-Type: application/json"); echo json_encode(["php"=>PHP_VERSION_ID,"extensions"=>array_map("extension_loaded",["curl","pdo_sqlite","mbstring","openssl"])]);')
  try:
   sftp(['put '+quote(probe)+' /enoch-requirements-check.php'])
   status,body=get('/enoch-requirements-check.php');info=json.loads(body)
   if status!=200 or info['php']<80300 or not all(info['extensions']):raise RuntimeError('Hosting PHP 8.3+, curl, pdo_sqlite, mbstring and openssl are required.')
   print('Hosting PHP requirements passed: '+str(info['php']),flush=True)
  finally:sftp(['-rm /enoch-requirements-check.php','-rm /_enoch/access-check.txt'])
  private=stage/'_enoch';private.mkdir()
  for directory in ('app','config','vendor','infrastructure'):
   shutil.copytree(ROOT/directory,private/directory,ignore=shutil.ignore_patterns('__pycache__','*.pyc'))
  shutil.copy2(ROOT/'composer.json',private/'composer.json');shutil.copy2(ROOT/'composer.lock',private/'composer.lock')
  # Every protected directory keeps the inherited Apache denial.
  shutil.copy2(ROOT/'.htaccess',private/'.htaccess')
  if args.initial:
   runtime={k:v for k,v in values.items() if k.startswith(('HETZNER_','NEXTCLOUD_','GAME_')) or k in ('APP_URL','SETUP_KEY','CRON_KEY')}
   runtime['APP_ENV']='production';(private/'.env').write_text(''.join(k+'='+v+'\n' for k,v in runtime.items()));(private/'.env').chmod(0o600)
   (private/'var').mkdir(mode=0o700)
   shutil.copytree(ROOT/'var/ssh',private/'var/ssh');(private/'var/ssh/enoch').chmod(0o600)
   source=sqlite3.connect(ROOT/'var/enoch.sqlite');dest=sqlite3.connect(private/'var/enoch.sqlite');source.backup(dest);dest.close();source.close();(private/'var/enoch.sqlite').chmod(0o600)
  commands=['put -r '+quote(private)+' /']
  # Public entry points go last so the private app exists when they become reachable.
  for item in sorted((ROOT/'public').iterdir()):
   if item.is_dir():commands.append('-mkdir /'+item.name)
   commands.append(('put -r ' if item.is_dir() else 'put ')+quote(item)+' /')
  sftp(commands)
  for path in ('/_enoch/.env','/_enoch/var/enoch.sqlite','/_enoch/vendor/autoload.php'):
   if get(path)[0]!=403:raise RuntimeError('A private file is not denied: '+path)
  status,body=get('/')
  if status!=200 or b'Enoch' not in body:raise RuntimeError('Deployment uploaded, but the homepage check failed.')
  print('Deployment verified at '+base+'/',flush=True)
if __name__=='__main__':
 try:main()
 except Exception as exc:raise SystemExit(str(exc))
