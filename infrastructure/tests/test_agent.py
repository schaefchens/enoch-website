import os,re,subprocess,unittest
from pathlib import Path
from unittest.mock import patch
from types import SimpleNamespace
source=Path(__file__).resolve().parents[1].joinpath('server/enoch-agent-install.sh').read_text()
code=compile(re.search(r"<<'PY'\n(.*?)\nPY",source,re.S).group(1),'agent','exec')
class AgentTests(unittest.TestCase):
 def test_embedded_swap_helper_matches_tested_source(self):
  embedded=re.search(r"<<'SWAP_PY'\n(.*?)\nSWAP_PY",source,re.S).group(1)+'\n'
  expected=Path(__file__).resolve().parents[1].joinpath('server/prepare-snapshot.py').read_text()
  self.assertEqual(embedded,expected)
 def execute(self,command,properties,running=''):
  calls=[];printed=[]
  def run(args,**kwargs):
   calls.append(args)
   out=properties if args[0]=='systemctl' else running if args[0]=='docker' else ''
   return SimpleNamespace(stdout=out)
  with patch.dict(os.environ,SSH_ORIGINAL_COMMAND=command),patch('subprocess.run',side_effect=run),patch('builtins.print',side_effect=lambda *a,**kw:printed.append(a[0])):
   try:exec(code,{'__name__':'__main__'})
   except SystemExit as e:return e.code,calls,printed
 def test_preparation_does_not_wait_for_long_systemd_job(self):
  rc,calls,out=self.execute('prepare '+'a'*24,'LoadState=not-found\nActiveState=inactive\nResult=success')
  self.assertEqual(rc,0);self.assertIn('--no-block',calls[-1]);self.assertIn('stopping',out[-1])
 def test_running_sibling_never_counts_as_ready(self):
  rc,calls,out=self.execute('status '+'a'*24,'LoadState=loaded\nActiveState=active\nSubState=exited\nResult=success','nextcloud-aio-nextcloud')
  self.assertIn('failed',out[-1])
 def test_failed_systemd_stop_is_reported(self):
  rc,calls,out=self.execute('status '+'a'*24,'LoadState=loaded\nActiveState=failed\nResult=exit-code')
  self.assertIn('failed',out[-1]);self.assertEqual(len(calls),1)
 def test_forced_command_rejects_shell_injection(self):
  rc,calls,out=self.execute('prepare a; whoami','')
  self.assertEqual(rc,1);self.assertFalse(calls)
if __name__=='__main__':unittest.main()
