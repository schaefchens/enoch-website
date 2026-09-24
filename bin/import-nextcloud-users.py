#!/usr/bin/env python3
"""Import the private Nextcloud TSV without putting passwords in arguments or logs."""
from pathlib import Path
import argparse
import csv
import json
import re
import shlex
import subprocess

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_INPUT = ROOT / "var/imports/wolke-nextcloud-users.tsv"
SSH = [
    "ssh", "-i", str(Path("~/.ssh/hetzner_abraham").expanduser()),
    "-o", "BatchMode=yes", "-o", "ConnectTimeout=15",
    "-o", "StrictHostKeyChecking=yes", "root@wolke2.schaefchens.de",
]

REMOTE = r'''
import json, os, subprocess, sys

rows=json.load(sys.stdin)
container='nextcloud-aio-nextcloud'

def occ(*args, password=None, check=True):
    command=['docker','exec','--user','www-data']
    env=os.environ.copy()
    if password is not None:
        env['OC_PASS']=password
        command += ['--env','OC_PASS']
    command += [container,'php','occ','--no-interaction',*args]
    result=subprocess.run(command,text=True,capture_output=True,env=env,timeout=300)
    if check and result.returncode:
        raise RuntimeError('Nextcloud command failed: '+' '.join(args)+'\n'+result.stderr.strip())
    return result.stdout

existing=json.loads(occ('user:list','--output=json'))
group_members=json.loads(occ('group:list','--output=json'))
all_groups=sorted({g.strip() for row in rows for field in ('groups','group_admin_for') for g in row[field].split(';') if g.strip()})
for group in all_groups:
    if group not in group_members:
        occ('group:add',group)

created=updated=0
for row in rows:
    uid=row['username']
    if uid in existing:
        occ('user:resetpassword','--password-from-env',uid,password=row['new_password'])
        occ('user:profile',uid,'displayname',row['display_name'])
        if row['email']:
            occ('user:profile',uid,'email',row['email'])
        else:
            occ('user:profile','--delete',uid,'email',check=False)
        updated += 1
    else:
        args=['user:add','--password-from-env','--display-name='+row['display_name']]
        if row['email']:
            args.append('--email='+row['email'])
        occ(*args,uid,password=row['new_password'])
        created += 1
    memberships={g.strip() for g in row['groups'].split(';') if g.strip()}
    memberships.update(g.strip() for g in row['group_admin_for'].split(';') if g.strip())
    for group in sorted(memberships):
        occ('group:adduser',group,uid)
    occ('user:setting',uid,'files','quota',row['quota'])

# Managers are applied after all referenced accounts exist.
for row in rows:
    if row['manager']:
        occ('user:setting',row['username'],'settings','manager',row['manager'])

# Nextcloud exposes sub-admins through its service API but has no matching occ
# command. Invoke that API in the application container; no database is edited.
subadmin_code=r"""require_once "/var/www/html/lib/base.php";
$u=\OC::$server->getUserManager()->get(getenv("IMPORT_UID"));
$g=\OC::$server->getGroupManager()->get(getenv("IMPORT_GROUP"));
if (!$u || !$g) { fwrite(STDERR,"missing user or group\n"); exit(2); }
$s=\OC::$server->getGroupManager()->getSubAdmin();
if (!$s->isSubAdminOfGroup($u,$g)) { $s->createSubAdmin($u,$g); }
if (!$s->isSubAdminOfGroup($u,$g)) { fwrite(STDERR,"sub-admin verification failed\n"); exit(3); }"""
subadmins=0
for row in rows:
    for group in (g.strip() for g in row['group_admin_for'].split(';') if g.strip()):
        env=os.environ.copy();env['IMPORT_UID']=row['username'];env['IMPORT_GROUP']=group
        result=subprocess.run(['docker','exec','--user','www-data','--env','IMPORT_UID','--env','IMPORT_GROUP',container,'php','-r',subadmin_code],text=True,capture_output=True,env=env,timeout=60)
        if result.returncode:
            raise RuntimeError('Could not assign a group administrator: '+result.stderr.strip())
        subadmins += 1

final_users=json.loads(occ('user:list','--output=json'))
missing=sorted(row['username'] for row in rows if row['username'] not in final_users)
if missing:
    raise RuntimeError('User verification failed for '+', '.join(missing))
for row in rows:
    info=json.loads(occ('user:info',row['username'],'--output=json'))
    expected={g.strip() for g in row['groups'].split(';') if g.strip()}
    expected.update(g.strip() for g in row['group_admin_for'].split(';') if g.strip())
    if not expected.issubset(set(info.get('groups',[]))):
        raise RuntimeError('Group verification failed for '+row['username'])
    configured=str(info.get('quota','')).replace(' ','').lower()
    expected_quota=row['quota'].replace(' ','').lower()
    if configured != expected_quota:
        raise RuntimeError('Quota verification failed for '+row['username'])

print(json.dumps({'created':created,'updated':updated,'verified':len(rows),'groups':len(all_groups),'subadmin_assignments':subadmins}))
'''


def load_rows(path: Path):
    with path.open(newline="", encoding="utf-8") as handle:
        reader=csv.DictReader(handle,delimiter="\t")
        expected=['username','new_password','display_name','email','groups','group_admin_for','quota','manager']
        if reader.fieldnames != expected:
            raise SystemExit('Unexpected TSV columns; refusing the import.')
        rows=list(reader)
    if not rows:
        raise SystemExit('The import list is empty.')
    users={row['username'] for row in rows}
    if len(users)!=len(rows):
        raise SystemExit('Duplicate usernames are not allowed.')
    for row in rows:
        if not re.fullmatch(r'[A-Za-z0-9_@.-]+',row['username']):
            raise SystemExit('Invalid username in the import list.')
        if not row['new_password'] or not row['display_name']:
            raise SystemExit('Every row needs a password and display name.')
        if row['email'] and ('@' not in row['email'] or row['email'].lower().endswith('@der-weg-des-herrn.de')):
            raise SystemExit('Invalid or retired email address in the import list.')
        if not re.fullmatch(r'[1-9][0-9]* (?:MB|GB|TB)',row['quota']):
            raise SystemExit('Invalid quota in the import list.')
        if row['manager'] and row['manager'] not in users:
            raise SystemExit('A manager does not refer to another imported user.')
    return rows


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('path',nargs='?',type=Path,default=DEFAULT_INPUT)
    parser.add_argument('--validate-only',action='store_true')
    args=parser.parse_args()
    rows=load_rows(args.path)
    groups={g.strip() for row in rows for field in ('groups','group_admin_for') for g in row[field].split(';') if g.strip()}
    if args.validate_only:
        print(json.dumps({'users':len(rows),'groups':len(groups),'with_email':sum(bool(r['email']) for r in rows)}))
        return
    remote='python3 -c '+shlex.quote(REMOTE)
    result=subprocess.run([*SSH,remote],input=json.dumps(rows),text=True,capture_output=True,timeout=1800)
    if result.returncode:
        raise SystemExit(result.stderr.strip() or 'Remote import failed.')
    print(result.stdout.strip())


if __name__=='__main__':
    main()
