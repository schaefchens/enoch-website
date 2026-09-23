import json
from pathlib import Path
import re
import unittest
from unittest.mock import patch
from types import SimpleNamespace

installer=(Path(__file__).resolve().parents[1]/'server/nextcloud_server_setup.sh').read_text()
source=re.search(r"cat >/usr/local/sbin/nextcloud-aio-configure-talk <<'PY'\n(.*?)\nPY",installer,re.S).group(1)
code=compile(source,'configure-talk','exec')
root='/etc/nextcloud-aio/'

class FakePath:
    files={}
    def __init__(self,path):self.name=str(path)
    def exists(self):return self.name in self.files
    def read_text(self):return self.files[self.name]
    def read_bytes(self):return self.read_text().encode()
    def write_text(self,data):self.files[self.name]=data

class TalkModeTests(unittest.TestCase):
    def setUp(self):
        self.external={'signaling_url':'https://hpb.example.test','signaling_secret':'external-test-secret',
                       'turn_server':'hpb.example.test:3478','turn_secret':'external-turn-test-secret'}
        FakePath.files={root+'talk-mode':'external',root+'external-talk.json':json.dumps(self.external)}
        self.env={'TALK_ENABLED':'','NC_DOMAIN':'cloud.example.test','TALK_PORT':'3478',
                  'TURN_DOMAIN':'','SIGNALING_SECRET':'internal-test-secret','TURN_SECRET':'internal-turn-test-secret'}
        self.imports=[]
        self.import_failure=False

    def run_command(self,args,**kwargs):
        if 'status' in args:return SimpleNamespace(returncode=0,stdout=json.dumps({'installed':True,'maintenance':False,'needsDbUpgrade':False}))
        if 'config:import' in args:
            self.imports.append(json.loads(kwargs['input']))
            return SimpleNamespace(returncode=int(self.import_failure),stdout='',stderr='')
        raise AssertionError(args)

    def output(self,args,**kwargs):
        if 'inspect' in args:return json.dumps([k+'='+v for k,v in self.env.items()]).encode()
        if 'app:list' in args:return b'{"enabled":{"spreed":"25"},"disabled":{}}'
        raise AssertionError(args)

    def execute(self):
        with patch('pathlib.Path',FakePath),patch('subprocess.run',side_effect=self.run_command),patch('subprocess.check_output',side_effect=self.output),patch('builtins.print'):
            try:exec(code,{'__name__':'__main__'})
            except SystemExit as e:
                if e.code not in (None,0):raise

    def imported(self,key):return json.loads(self.imports[-1]['apps']['spreed'][key])

    def test_external_replaces_internal_endpoints(self):
        self.execute()
        self.assertEqual(self.imported('signaling_servers')['servers'],[{'server':'https://hpb.example.test/','verify':True}])
        self.assertEqual(self.imported('turn_servers')[0]['server'],'hpb.example.test:3478')
        self.assertEqual(self.imported('signaling_servers')['secret'],'external-test-secret')

    def test_internal_replaces_external_endpoints_and_secrets(self):
        FakePath.files[root+'talk-mode']='internal';self.env['TALK_ENABLED']='yes'
        self.execute()
        self.assertEqual(self.imported('signaling_servers')['servers'],[{'server':'https://cloud.example.test/standalone-signaling/','verify':True}])
        self.assertEqual(self.imported('signaling_servers')['secret'],'internal-test-secret')
        self.assertEqual(self.imported('turn_servers')[0]['server'],'cloud.example.test:3478')
        self.assertEqual(self.imported('turn_servers')[0]['secret'],'internal-turn-test-secret')

    def test_mode_mismatch_waits_without_overwriting(self):
        self.env['TALK_ENABLED']='yes';self.execute()
        self.assertEqual(self.imports,[])
        self.assertNotIn(root+'external-talk.applied',FakePath.files)

    def test_same_configuration_is_not_reapplied(self):
        self.execute();self.execute();self.assertEqual(len(self.imports),1)

    def test_mode_switch_reapplies_even_with_marker(self):
        self.execute()
        FakePath.files[root+'talk-mode']='internal';self.env['TALK_ENABLED']='yes'
        self.execute();self.assertEqual(len(self.imports),2)
        self.assertEqual(self.imported('signaling_servers')['secret'],'internal-test-secret')

    def test_failed_import_does_not_mark_success(self):
        self.import_failure=True
        with self.assertRaises(SystemExit):self.execute()
        self.assertNotIn(root+'external-talk.applied',FakePath.files)

if __name__=='__main__':unittest.main()
