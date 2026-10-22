<?php
declare(strict_types=1);
namespace Enoch;

final class ActivityMonitor {
    public function __construct(private Config $config,private Store $store,private Cloud $cloud,private Engine $engine) {}
    private function blocked(string $id,array $server):bool {
        $key='lifecycle:block:'.$id;$block=$this->store->get($key);
        if(is_array($block)&&(int)($block['server']??0)===(int)$server['id'])return true;
        if($block!==null)$this->store->set($key,null);
        $failed=$this->store->query("SELECT id,message,data,updated FROM jobs WHERE service=? AND operation='stop' AND status='failed' ORDER BY created DESC LIMIT 1",[$id])->fetch();
        if(!$failed)return false;
        $data=json_decode($failed['data'],true);
        if((int)($data['server']??0)!==(int)$server['id'])return false;
        $this->store->set($key,['server'=>(int)$server['id'],'job'=>$failed['id'],'message'=>$failed['message'],'time'=>(int)$failed['updated']]);
        $this->store->audit('activity monitor','Automatic stop suspended after a failed lifecycle operation',$id);
        return true;
    }
    public function runDue():array {
        // A fresh cloud inventory every five minutes is sufficient for 15–30 minute
        // quiet periods and leaves the other cron ticks to scheduled requests.
        $last=(int)$this->store->get('activity-monitor-seen',0);if(time()-$last<240)return ['due'=>false];
        $this->store->set('activity-monitor-seen',time());
        $enabled=array_filter($this->config->services,fn($cfg)=>(int)($cfg['idle_timeout']??0)>=300);
        if(!$enabled)return ['due'=>false];
        $servers=$this->cloud->all('servers');
        foreach($enabled as $id=>$cfg){
            $server=$this->cloud->server($cfg,$servers);if(!$server||($server['status']??'')!=='running')continue;
            if($this->store->query("SELECT 1 FROM jobs WHERE service=? AND status='active'",[$id])->fetchColumn())continue;
            if($this->blocked($id,$server))continue;
            $activity=$this->store->get('activity:'.$id,[]);$created=strtotime((string)($server['created']??''))?:0;
            $reference=max($created,(int)$this->store->get('activity:grace:'.$id,0),(int)($activity['last_seen']??0));
            if($reference===0||time()-$reference<(int)$cfg['idle_timeout'])continue;
            $save=($server['labels']['enoch-save']??'yes')!=='no';
            $options=['save_snapshot'=>$save];if(!$save)$options['acknowledge_discard']=true;
            $this->engine->enqueue($id,'stop','automatic inactivity monitor',$options);
            $this->store->audit('activity monitor','Quiet period reached; safe stop requested',$id);
            return ['service'=>$id,'queued'=>true];
        }
        return ['due'=>false];
    }
}
