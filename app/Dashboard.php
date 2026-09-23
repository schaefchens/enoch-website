<?php
declare(strict_types=1);
namespace Enoch;

final class Dashboard {
    public function __construct(private Config $config,private Store $store,private Access $access) {}

    public function present(array $user,array $dashboard):array {
        $technical=$user['ui_mode']==='technical'||$user['role']==='admin';
        $grants=$this->access->grants($user);
        $services=[];
        foreach($dashboard['services'] as $service){
            $id=$service['id'];if(!isset($grants[$id]))continue;
            if($technical){$service['permissions']=$grants[$id];$services[]=$service;continue;}
            $cfg=$this->config->services[$id];
            $services[]=[
                'id'=>$id,'title'=>$cfg['simple_title']??$cfg['title'],
                'description'=>$cfg['simple_description']??'A shared service for our team.',
                'url'=>$cfg['simple_url']??'https://'.$cfg['domain'].'/',
                'open_label'=>$cfg['simple_open_label']??'Open service',
                'allocated'=>in_array($service['state'],['running','off'],true),
                'save_snapshot'=>$service['save_snapshot']??true,
                'state'=>match($service['state']){'running'=>'on','off','saved'=>'off','unknown'=>'unknown',default=>'busy'},
                'permissions'=>$grants[$id],
            ];
        }
        $jobs=[];
        if($grants){
            $placeholders=implode(',',array_fill(0,count($grants),'?'));
            $jobs=$this->store->query("SELECT id,service,operation,status,phase,created,updated,actor,message,data FROM jobs WHERE service IN ($placeholders) ORDER BY (status='active') DESC,created DESC LIMIT 100",array_keys($grants))->fetchAll();
        }
        foreach($jobs as &$job){$data=json_decode($job['data'],true);$job['save_snapshot']=$data['save_snapshot']??true;unset($job['data']);}unset($job);
        if(!$technical)$jobs=array_map(fn($job)=>[
            'service'=>$job['service'],'operation'=>$job['operation'],'status'=>$job['status'],
            'created'=>$job['created'],'save_snapshot'=>$job['save_snapshot'],'message'=>self::simpleMessage($job),
        ],$jobs);
        $result=['checked'=>$dashboard['checked'],'viewer'=>array_intersect_key($user,array_flip(['id','name','role','ui_mode'])),
            'services'=>$services,'jobs'=>$jobs,'tasks'=>[],'audit'=>[],'users'=>[],'service_catalog'=>[]];
        if($technical){
            $result['cron_seen']=$this->store->get('cron_seen');
            $result['worker_seen']=$this->store->get('worker_seen');
            $audit=$this->store->query('SELECT time,actor,event,detail FROM audit ORDER BY id DESC LIMIT 300')->fetchAll();
            $result['audit']=array_slice(array_values(array_filter($audit,static function($entry)use($user,$grants){
                if($user['role']==='admin')return true;
                $service=explode(' · ',$entry['detail'],2)[0];
                return isset($grants[$service])||($entry['actor']===$user['name']&&$entry['detail']==='');
            })),0,60);
        }
        if($user['role']==='admin'){
            foreach($this->config->tasks as $id=>$task)$result['tasks'][]=[
                'id'=>$id,'title'=>$task['title'],'description'=>$task['description'],
                'interval'=>max(60,(int)$this->config->get($task['interval_env'],(string)$task['interval'])),
                'state'=>$this->store->get('task:'.$id),'configured'=>$this->config->get($task['key_env'])!=='',
            ];
            $result['users']=$this->access->users();
            foreach($this->config->services as $id=>$cfg)$result['service_catalog'][]=['id'=>$id,'title'=>$cfg['title']];
        }
        return $result;
    }

    public static function simpleMessage(array $job):string {
        if($job['status']==='failed')return 'This did not finish. Please ask a team administrator for help.';
        if($job['status']==='done')return $job['operation']==='start'?'Ready to use.':(($job['save_snapshot']??true)?'Saved and turned off.':'Turned off without saving changes.');
        return $job['operation']==='start'?'Getting ready. This can take a few minutes.':'Saving and turning off. Please wait.';
    }
}
