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
    private function location(array $type,string $name):?array {
        if(!array_key_exists('locations',$type))return ['name'=>$name,'available'=>true,'deprecation'=>null];
        foreach($type['locations'] as $location)if(($location['name']??null)===$name)return $location;
        return null;
    }
    private function retired(?array $location):bool {
        $after=$location['deprecation']['unavailable_after']??null;
        return is_string($after)&&strtotime($after)!==false&&strtotime($after)<=time();
    }
    public function options(array $cfg):array {
        $initial=$this->initial($cfg);$current=$this->current($cfg);$types=[];
        foreach($this->cloud->all('server_types') as $t){
            if(($t['architecture']??'')!==$current['architecture']||!empty($t['deprecated'])||($t['storage_type']??'local')!=='local')continue;
            $location=$this->location($t,$cfg['location']);if(!$location||$this->retired($location))continue;
            $price=null;foreach($t['prices']??[] as $candidate)if(($candidate['location']??null)===$cfg['location']){$price=$candidate;break;}
            $types[]=['name'=>$t['name'],'description'=>$t['description']??strtoupper($t['name']),'category'=>$t['category']??null,'cores'=>$t['cores'],'memory'=>$t['memory'],'disk'=>$t['disk'],'cpu_type'=>$t['cpu_type']??null,'architecture'=>$t['architecture'],'storage_type'=>$t['storage_type']??'local','available'=>$location['available']??null,'price_hourly'=>isset($price['price_hourly']['gross'])?(float)$price['price_hourly']['gross']:null,'price_monthly'=>isset($price['price_monthly']['gross'])?(float)$price['price_monthly']['gross']:null];
        }
        usort($types,fn($a,$b)=>($a['memory']<=>$b['memory'])?:strcmp($a['name'],$b['name']));
        return ['initial'=>$this->summary($initial),'current'=>$this->summary($current),'checkpoints'=>array_map($this->summary(...),$this->checkpoints($cfg)),'types'=>$types,'default_type'=>$cfg['type'],'fallback_types'=>array_values(array_unique($cfg['types']??[$cfg['type']])),'location'=>$cfg['location'],'currency'=>'EUR'];
    }
    public function normalize(array $options,string $operation,array $cfg):array {
        if(array_diff(array_keys($options),['source','type','save_snapshot','checkpoint_name','acknowledge_discard','acknowledge_large_disk']))throw new \RuntimeException('Unknown lifecycle option.');
        $explicitType=array_key_exists('type',$options);
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
        return $operation==='start'?$options+['source'=>'current','type'=>$cfg['type'],'save_snapshot'=>true,'_type_explicit'=>$explicitType]:$options;
    }
    private function restoreImage(array $cfg,array $options):array {
        if($options['source']==='initial')$i=$this->initial($cfg);
        elseif($options['source']==='current')$i=$this->current($cfg);
        else {
            $id=(int)substr($options['source'],11);$i=null;
            foreach($this->checkpoints($cfg) as $checkpoint)if((int)$checkpoint['id']===$id){$i=$checkpoint;break;}
            if(!$i)throw new \RuntimeException('The selected protected checkpoint is unavailable.');
        }
        return $i;
    }
    private function validateType(array $type,array $image,array $base,array $cfg,array $options):void {
        $location=$this->location($type,$cfg['location']);
        if(!$location||$this->retired($location)||!empty($type['deprecated'])||($type['storage_type']??'local')!=='local')throw new \RuntimeException('Selected server type is unavailable.');
        if($image['architecture']!==($type['architecture']??'')||$image['disk_size']>($type['disk']??0))throw new \RuntimeException('Snapshot disk is too large for this server type. Choose a larger server or the initial snapshot.');
        if(($type['disk']??0)>($base['disk']??0)&&$options['save_snapshot']&&empty($options['acknowledge_large_disk']))throw new \RuntimeException('Saving a larger disk replaces the current snapshot with one that cannot restore to the default smaller server. Confirm this or choose not to save changes.');
    }
    public function restorePlan(array $cfg,array $options):array {
        $image=$this->restoreImage($cfg,$options);$catalog=[];
        foreach($this->cloud->all('server_types') as $type)$catalog[$type['name']]=$type;
        $base=$catalog[$cfg['type']]??throw new \RuntimeException('Default server type is unavailable.');
        $selected=$catalog[$options['type']]??null;
        if(!empty($options['_type_explicit'])){
            if(!$selected)throw new \RuntimeException('Selected server type is unavailable.');
            $this->validateType($selected,$image,$base,$cfg,$options);
        }
        $names=array_values(array_unique([$options['type'],...($cfg['types']??[$cfg['type']])]));$types=[];
        foreach($names as $name){
            $type=$catalog[$name]??null;if(!$type)continue;
            try{$this->validateType($type,$image,$base,$cfg,$options);}catch(\RuntimeException){continue;}
            $location=$this->location($type,$cfg['location']);if(($location['available']??null)===false)continue;
            $types[]=$name;
        }
        if(!$types)throw new \RuntimeException('No configured compatible server type is currently available in '.strtoupper($cfg['location']).'.');
        return ['image'=>$image,'types'=>$types];
    }
    public function restore(array $cfg,array $options):array {return $this->restorePlan($cfg,$options)['image'];}
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
