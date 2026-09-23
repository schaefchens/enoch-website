import importlib.machinery
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch
from types import SimpleNamespace

path=Path(__file__).resolve().parents[1]/'local/nextcloud_create_n_delete'
loader=importlib.machinery.SourceFileLoader('lifecycle',str(path))
spec=importlib.util.spec_from_loader(loader.name,loader)
m=importlib.util.module_from_spec(spec); loader.exec_module(m)

def server(status='running'):
    return {'id':123,'name':'wolke2','status':status,'public_net':{'ipv4':{'id':m.PRIMARY_IPV4},'ipv6':{'id':m.PRIMARY_IPV6}}}

def args(**kwargs):
    defaults=dict(skip_snapshot=False,dry_run=False,keep_server=False,retain=2,base=False,base_snapshot=None)
    defaults.update(kwargs)
    return SimpleNamespace(**defaults)

class LifecycleSafety(unittest.TestCase):
    def test_refuses_matching_name_with_wrong_ips(self):
        wrong=server();wrong['public_net']['ipv4']['id']=999
        with patch.object(m,'cloud',return_value=[wrong]):
            with self.assertRaises(m.LifecycleError):m.find_server()

    def test_finds_existing_fqdn_named_vm_by_ips(self):
        current=server();current['name']='wolke2.schaefchens.de'
        with patch.object(m,'cloud',return_value=[current]):
            self.assertEqual(m.find_server()['id'],123)

    def test_auto_delete_ips_block_all_mutation(self):
        ip={'auto_delete':True,'assignee_id':123,'location':{'name':'fsn1'}}
        with patch.object(m,'find_server',return_value=server()),patch.object(m,'cloud',return_value=ip),patch.object(m,'mutate') as mutate:
            with self.assertRaises(m.LifecycleError):m.stop(args())
            mutate.assert_not_called()

    def test_failed_clean_stop_does_not_poweroff_snapshot_or_delete(self):
        with patch.object(m,'find_server',return_value=server()),patch.object(m,'check_ips'),patch.object(m,'ssh',side_effect=m.LifecycleError('failed')),patch.object(m,'mutate') as mutate:
            with self.assertRaises(m.LifecycleError):m.stop(args())
            mutate.assert_not_called()

    def test_failed_snapshot_never_deletes_vm(self):
        with patch.object(m,'find_server',return_value=server('off')),patch.object(m,'check_ips'),patch.object(m,'cloud',return_value=server('off')),patch.object(m,'mutate',side_effect=m.LifecycleError('snapshot failed')) as mutate:
            with self.assertRaises(m.LifecycleError):m.stop(args())
            self.assertEqual(mutate.call_count,1)
            self.assertEqual(mutate.call_args.args[:2],('server','create-image'))

    def test_unverified_snapshot_never_deletes_vm(self):
        with patch.object(m,'find_server',return_value=server('off')),patch.object(m,'check_ips'),patch.object(m,'cloud',return_value=server('off')),patch.object(m,'snapshots',return_value=[]),patch.object(m,'mutate') as mutate:
            with self.assertRaises(m.LifecycleError):m.stop(args())
            self.assertNotIn(('server','delete'),[call.args[:2] for call in mutate.call_args_list])

    def test_snapshot_wrong_origin_never_deletes_vm(self):
        image={'id':888,'status':'available','created_from':{'id':999}}
        def cloud(*a):return image if a[0]=='image' else server('off')
        with patch.object(m,'find_server',return_value=server('off')),patch.object(m,'check_ips'),patch.object(m,'cloud',side_effect=cloud),patch.object(m,'snapshots',return_value=[image]),patch.object(m,'mutate') as mutate:
            with self.assertRaises(m.LifecycleError):m.stop(args())
            self.assertNotIn(('server','delete'),[call.args[:2] for call in mutate.call_args_list])

    def test_skip_snapshot_requires_available_fallback(self):
        with patch.object(m,'find_server',return_value=server()),patch.object(m,'check_ips'),patch.object(m,'select_image',side_effect=m.LifecycleError('no snapshot')),patch.object(m,'mutate') as mutate,patch.object(m,'ssh') as ssh:
            with self.assertRaises(m.LifecycleError):m.stop(args(skip_snapshot=True))
            mutate.assert_not_called();ssh.assert_not_called()

    def test_dry_run_never_changes_server(self):
        with patch.object(m,'find_server',return_value=server()),patch.object(m,'check_ips'),patch.object(m,'mutate') as mutate,patch.object(m,'ssh') as ssh:
            m.stop(args(dry_run=True));mutate.assert_not_called();ssh.assert_not_called()

    def test_available_snapshot_must_fit_target_disk(self):
        image={'status':'available','type':'snapshot','architecture':'x86','disk_size':80}
        with self.assertRaises(m.LifecycleError):m.validate_image(image,{'architecture':'x86','disk':40})

if __name__=='__main__': unittest.main()
