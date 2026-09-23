<?php
declare(strict_types=1);
namespace Enoch;

final class Lifecycle {
    public function __construct(private Cloud $cloud) {}
    private function isCheckpoint(array $image):bool {
        return ($image['labels']['enoch-kind']??'')==='checkpoint';
    }
    private function summary(array $image):array {
        $summary=array_intersect_key($image,array_flip(['id','description','created','disk_size','image_size']));
        $parent=$image['labels']['enoch-parent']??'';
        if(is_string($parent)&&ctype_digit($parent))$summary['parent_id']=(int)$parent;
        return $summary;
    }
    public function initial(array $cfg):array {
        $i=$this->cloud->request('GET','/images/'.$cfg['initial_image'])['image']??[];
        $this->cloud->imageValid($i,$cfg);
        if(empty($i['protection']['delete'])||!str_contains($i['description']??'','-initial'))throw new \RuntimeException('The initial snapshot must have an -initial name and deletion protection.');
        return $i;
    }
    public function checkpoints(array $cfg):array {
        $checkpoints=[];
        foreach($this->cloud->images($cfg) as $image){
            if(!$this->isCheckpoint($image))continue;
            $this->cloud->imageValid($image,$cfg);
            if(empty($image['protection']['delete']))throw new \RuntimeException('A checkpoint snapshot is not deletion-protected. Inspect it before continuing.');
            $checkpoints[]=$image;
        }
        return $checkpoints;
    }
    public function current(array $cfg):array {
        foreach($this->cloud->images($cfg) as $i)if($i['status']==='available'&&$i['id']!==($cfg['initial_image']??null)&&($this->isCheckpoint($i)||!str_contains($i['description']??'','-initial'))){$this->cloud->imageValid($i,$cfg);if($this->isCheckpoint($i)&&empty($i['protection']['delete']))throw new \RuntimeException('A checkpoint snapshot is not deletion-protected. Inspect it before continuing.');return $i;}
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
        return ['initial'=>$this->summary($initial),'current'=>$this->summary($current),'checkpoints'=>array_map($this->summary(...),$this->checkpoints($cfg)),'types'=>$types,'default_type'=>$cfg['type']];
    }
    public function normalize(array $options,string $operation,array $cfg):array {
        if(array_diff(array_keys($options),['source','type','save_snapshot','checkpoint_name','acknowledge_discard','acknowledge_large_disk']))throw new \RuntimeException('Unknown lifecycle option.');
        if($operation==='stop'&&(isset($options['type'])||isset($options['source'])))throw new \RuntimeException('Restore options only apply when starting.');
        if($operation==='start'&&isset($options['checkpoint_name']))throw new \RuntimeException('A checkpoint can only be created while saving and stopping.');
        foreach(['save_snapshot','acknowledge_discard','acknowledge_large_disk'] as $k)if(isset($options[$k])&&!is_bool($options[$k]))throw new \RuntimeException('Invalid lifecycle option.');
        if(isset($options['source'])&&!in_array($options['source'],['initial','current'],true)&&!preg_match('/^checkpoint:[1-9][0-9]*$/',(string)$options['source']))throw new \RuntimeException('Invalid snapshot source.');
        if(isset($options['type'])&&(!is_string($options['type'])||!preg_match('/^[a-z][a-z0-9]{1,19}$/',$options['type'])))throw new \RuntimeException('Invalid server type.');
        if(isset($options['checkpoint_name'])){
            if(!is_string($options['checkpoint_name']))throw new \RuntimeException('Invalid checkpoint name.');
            $options['checkpoint_name']=trim($options['checkpoint_name']);
            if(!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._-]{0,47}$/u',$options['checkpoint_name']))throw new \RuntimeException('Use a checkpoint name of 1–48 letters, numbers, spaces, dots, dashes or underscores.');
            if(($options['save_snapshot']??true)===false)throw new \RuntimeException('A protected checkpoint requires saving the server state.');
        }
        if(($options['save_snapshot']??true)===false&&empty($options['acknowledge_discard']))throw new \RuntimeException('Confirm that changes made during this session will be discarded.');
        return $operation==='start'?$options+['source'=>'current','type'=>$cfg['type'],'save_snapshot'=>true]:$options;
    }
    public function restore(array $cfg,array $options):array {
        if($options['source']==='initial')$i=$this->initial($cfg);
        elseif($options['source']==='current')$i=$this->current($cfg);
        else {
            $id=(int)substr($options['source'],11);$i=null;
            foreach($this->checkpoints($cfg) as $checkpoint)if((int)$checkpoint['id']===$id){$i=$checkpoint;break;}
            if(!$i)throw new \RuntimeException('The selected protected checkpoint is unavailable.');
        }
        $types=$this->cloud->all('server_types',['name'=>$options['type']]);$t=$types[0]??throw new \RuntimeException('Selected server type is unavailable.');
        if($i['architecture']!==$t['architecture']||$i['disk_size']>$t['disk'])throw new \RuntimeException('Snapshot disk is too large for this server type. Choose a larger server or the initial snapshot.');
        $base=$this->cloud->all('server_types',['name'=>$cfg['type']])[0]??throw new \RuntimeException('Default server type is unavailable.');
        if($t['disk']>$base['disk']&&$options['save_snapshot']&&empty($options['acknowledge_large_disk']))throw new \RuntimeException('Saving a larger disk replaces the current snapshot with one that cannot restore to the default smaller server. Confirm this or choose not to save changes.');
        return $i;
    }
    // One deletion per worker tick. Initial/checkpoint/protected/foreign images are never candidates.
    public function pruneOne(array $cfg,int $keep):bool {
        $this->initial($cfg);
        $current=$this->current($cfg);
        if($current['id']!==$keep)throw new \RuntimeException('The current snapshot changed; automatic cleanup stopped.');
        foreach($this->cloud->images($cfg) as $i){
            if($i['id']===$keep||$i['id']===$cfg['initial_image']||$this->isCheckpoint($i)||str_contains($i['description']??'','-initial')||!empty($i['protection']['delete']))continue;
            $this->cloud->imageValid($i,$cfg);
            if(strcmp($i['created'],$current['created'])>=0)continue;
            $this->cloud->request('DELETE','/images/'.$i['id']);return true;
        }
        return false;
    }
}
