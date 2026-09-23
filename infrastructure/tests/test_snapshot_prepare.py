import importlib.util,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
from types import SimpleNamespace
source=Path(__file__).resolve().parents[1]/'server/prepare-snapshot.py'
spec=importlib.util.spec_from_file_location('snapshot_prepare',source);mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
class SnapshotPreparation(unittest.TestCase):
 def exercise(self,memory):
  with tempfile.TemporaryDirectory() as tmp:
   swap=Path(tmp)/'swap';swap.write_bytes(b'old-memory'*100);size=swap.stat().st_size;calls=[]
   def read(path,*a,**kw):
    if str(path)=='/proc/swaps':return f'Filename Type Size Used Priority\n{swap} file 100 1 -2\n'
    if str(path)=='/proc/meminfo':return f'MemAvailable: {memory} kB\n'
    raise AssertionError(str(path))
   def run(args,**kw):calls.append(args);return SimpleNamespace(stdout='original-uuid',returncode=0)
   with patch.object(mod.os,'geteuid',return_value=0),patch.object(Path,'read_text',read),patch.object(mod.subprocess,'run',side_effect=run):
    try:mod.main();error=None
    except RuntimeError as e:error=e
   return calls,error,swap.read_bytes(),size
 def test_low_memory_refuses_before_swapoff(self):
  calls,error,data,size=self.exercise(100)
  self.assertIsNotNone(error);self.assertFalse(calls);self.assertTrue(data.startswith(b'old-memory'))
 def test_clear_preserves_capacity_uuid_and_reactivates_before_trim(self):
  calls,error,data,size=self.exercise(4000000)
  self.assertIsNone(error);self.assertEqual(data,bytes(size));self.assertEqual([c[0] for c in calls],['blkid','swapoff','mkswap','swapon','sync','fstrim']);self.assertEqual(calls[2][1:3],['-U','original-uuid'])
