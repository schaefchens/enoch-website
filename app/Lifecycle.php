<?php
declare(strict_types=1);
namespace Enoch;

final class Lifecycle {
    public function __construct(private Cloud $cloud) {}
    public function initial(array $cfg):array {
        $i=$this->cloud->request('GET','/images/'.$cfg['initial_image'])['image']??[];
        $this->cloud->imageValid($i,$cfg);
        if(empty($i['protection']['delete'])||!str_contains($i['description']??'','-initial'))throw new \RuntimeException('The initial snapshot must have an -initial name and deletion protection.');
        return $i;
    }
    public function current(array $cfg):array {
        foreach($this->cloud->images($cfg) as $i)if($i['status']==='available'&&$i['id']!==($cfg['initial_image']??null)&&!str_contains($i['description']??'','-initial')){$this->cloud->imageValid($i,$cfg);return $i;}
        return $this->initial($cfg);
    }
    public function options(array $cfg):array {
        $initial=$this->initial($cfg);$current=$this->current($cfg);$types=[];
        foreach($this->cloud->all('server_types') as $t){
            if($t['architecture']!==$current['architecture']||!empty($t['deprecated'])||($t['storage_type']??'local')!=='local')continue;
            $location=null;foreach($t['locations']??[] as $l)if($l['name']===$cfg['location'])$location=$l;
            if(!$location)continue;
            $types[]=['name'=>$t['name'],'cores'=>$t['cores'],'memory'=>$t['memory'],'disk'=>$t['disk'],'available'=>$location['available']??null];
        }
        usort($types,fn($a,$b)=>($a['memory']<=>$b['memory'])?:strcmp($a['name'],$b['name']));
        $summary=fn($i)=>array_intersect_key($i,array_flip(['id','description','created','disk_size','image_size']));
        return ['initial'=>$summary($initial),'current'=>$summary($current),'types'=>$types,'default_type'=>$cfg['type']];
    }
    public function normalize(array $options,string $operation,array $cfg):array {
        if(array_diff(array_keys($options),['source','type','save_snapshot','acknowledge_discard','acknowledge_large_disk']))throw new \RuntimeException('Unknown lifecycle option.');
        if($operation==='stop'&&(isset($options['type'])||isset($options['source'])))throw new \RuntimeException('Restore options only apply when starting.');
        foreach(['save_snapshot','acknowledge_discard','acknowledge_large_disk'] as $k)if(isset($options[$k])&&!is_bool($options[$k]))throw new \RuntimeException('Invalid lifecycle option.');
        if(isset($options['source'])&&!in_array($options['source'],['initial','current'],true))throw new \RuntimeException('Invalid snapshot source.');
        if(isset($options['type'])&&(!is_string($options['type'])||!preg_match('/^[a-z][a-z0-9]{1,19}$/',$options['type'])))throw new \RuntimeException('Invalid server type.');
        if(($options['save_snapshot']??true)===false&&empty($options['acknowledge_discard']))throw new \RuntimeException('Confirm that changes made during this session will be discarded.');
        return $operation==='start'?$options+['source'=>'current','type'=>$cfg['type'],'save_snapshot'=>true]:$options;
    }
    public function restore(array $cfg,array $options):array {
        $i=$options['source']==='initial'?$this->initial($cfg):$this->current($cfg);
        $types=$this->cloud->all('server_types',['name'=>$options['type']]);$t=$types[0]??throw new \RuntimeException('Selected server type is unavailable.');
        if($i['architecture']!==$t['architecture']||$i['disk_size']>$t['disk'])throw new \RuntimeException('Snapshot disk is too large for this server type. Choose a larger server or the initial snapshot.');
        $base=$this->cloud->all('server_types',['name'=>$cfg['type']])[0]??throw new \RuntimeException('Default server type is unavailable.');
        if($t['disk']>$base['disk']&&$options['save_snapshot']&&empty($options['acknowledge_large_disk']))throw new \RuntimeException('Saving a larger disk replaces the current snapshot with one that cannot restore to the default smaller server. Confirm this or choose not to save changes.');
        return $i;
    }
    // One deletion per worker tick. Initial/protected/foreign images are never candidates.
    public function pruneOne(array $cfg,int $keep):bool {
        $this->initial($cfg);
        $current=$this->current($cfg);
        if($current['id']!==$keep)throw new \RuntimeException('The current snapshot changed; automatic cleanup stopped.');
        foreach($this->cloud->images($cfg) as $i){
            if($i['id']===$keep||$i['id']===$cfg['initial_image']||str_contains($i['description']??'','-initial')||!empty($i['protection']['delete']))continue;
            $this->cloud->imageValid($i,$cfg);
            if(strcmp($i['created'],$current['created'])>=0)continue;
            $this->cloud->request('DELETE','/images/'.$i['id']);return true;
        }
        return false;
    }
}
