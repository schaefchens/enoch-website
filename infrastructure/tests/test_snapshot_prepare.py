import importlib.util,json,os,shutil,tempfile,unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

source=Path(__file__).resolve().parents[1]/'server/prepare-snapshot.py'
spec=importlib.util.spec_from_file_location('snapshot_prepare',source)
mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)

class SnapshotPreparation(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();root=Path(self.tmp.name)
  self.swap=root/'swap';self.config=root/'swapfiles.json';self.fstab=root/'fstab';self.proc_swaps=root/'proc-swaps';self.meminfo=root/'meminfo'
  self.fstab.write_text(f'{self.swap} none swap sw 0 0\n')
  self.patches=[patch.object(mod,'CONFIG_PATH',self.config),patch.object(mod,'FSTAB_PATH',self.fstab),patch.object(mod,'PROC_SWAPS',self.proc_swaps),patch.object(mod,'PROC_MEMINFO',self.meminfo),patch.object(mod.os,'geteuid',return_value=0)]
  for item in self.patches:item.start()
 def tearDown(self):
  for item in reversed(self.patches):item.stop()
  self.tmp.cleanup()
 def write_config(self,size=1024):
  self.config.write_text(json.dumps({'version':1,'files':[{'path':str(self.swap),'size':size,'uuid':'11111111-2222-3333-4444-555555555555','priority':-2}]}))
 def active(self,used=1,size=1024):
  self.proc_swaps.write_text(f'Filename Type Size Used Priority\n{self.swap} file {size//1024} {used} -2\n')
 def inactive(self):self.proc_swaps.write_text('Filename Type Size Used Priority\n')
 def fake_lstat(self,path):
  info=os.stat(path);return SimpleNamespace(st_mode=info.st_mode,st_uid=0)
 def test_low_memory_refuses_before_swapoff(self):
  self.swap.write_bytes(b'x'*1024);self.swap.chmod(0o600);self.write_config();self.active(used=1000);self.meminfo.write_text('MemAvailable: 1000 kB\n');calls=[]
  with patch.object(mod.os,'lstat',side_effect=self.fake_lstat),patch.object(mod.subprocess,'run',side_effect=lambda args,**kw:(calls.append(args),SimpleNamespace(stdout='',returncode=0))[1]):
   with self.assertRaises(RuntimeError):mod.main('prepare')
  self.assertFalse(calls);self.assertTrue(self.swap.exists())
 def test_prepare_removes_swap_before_trim(self):
  self.swap.write_bytes(b'x'*1024);self.swap.chmod(0o600);self.write_config();self.active();self.meminfo.write_text('MemAvailable: 4000000 kB\n');calls=[]
  def run(args,**kw):calls.append(args);return SimpleNamespace(stdout='',returncode=0)
  with patch.object(mod.os,'lstat',side_effect=self.fake_lstat),patch.object(mod.subprocess,'run',side_effect=run):mod.main('prepare')
  self.assertFalse(self.swap.exists());self.assertEqual([call[0] for call in calls],['swapoff','sync','fstrim'])
 def test_ensure_recreates_and_enables_swap(self):
  self.write_config();self.inactive();calls=[]
  def run(args,**kw):
   calls.append(args)
   if args[0]=='fallocate':self.swap.write_bytes(bytes(1024))
   return SimpleNamespace(stdout='',returncode=0)
  with patch.object(mod.os,'lstat',side_effect=self.fake_lstat),patch.object(mod.shutil,'disk_usage',return_value=shutil._ntuple_diskusage(10**10,0,10**10)),patch.object(mod.subprocess,'run',side_effect=run):mod.main('ensure')
  self.assertEqual(self.swap.stat().st_size,1024);self.assertEqual([call[0] for call in calls],['fallocate','mkswap','swapon'])
 def test_register_records_swap_and_removes_fstab_activation(self):
  self.swap.write_bytes(b'x'*1024);self.swap.chmod(0o600);self.active();self.meminfo.write_text('MemAvailable: 4000000 kB\n');calls=[]
  def run(args,**kw):calls.append(args);return SimpleNamespace(stdout='11111111-2222-3333-4444-555555555555',returncode=0)
  with patch.object(mod.os,'lstat',side_effect=self.fake_lstat),patch.object(mod.subprocess,'run',side_effect=run):mod.main('register')
  self.assertEqual(json.loads(self.config.read_text())['files'][0]['size'],1024);self.assertNotIn(' none swap ',self.fstab.read_text());self.assertEqual(calls[0][0],'blkid')

if __name__=='__main__':unittest.main()
