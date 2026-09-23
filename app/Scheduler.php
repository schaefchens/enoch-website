<?php
declare(strict_types=1);
namespace Enoch;

final class Scheduler {
    public function __construct(private Config $config,private Store $store,private ?\Closure $transport=null) {}
    public function runDue():array {
        return $this->store->locked(function(){
            $managedJobs=new ScheduledJobs($this->config,$this->store);$managed=$managedJobs->due();$legacy=null;
            foreach($this->config->tasks as $id=>$task){
                $state=$this->store->get('task:'.$id,[]);$interval=max(60,(int)$this->config->get($task['interval_env'],(string)$task['interval']));
                if(time()-($state['attempted']??0)<$interval)continue;
                $candidate=['id'=>$id,'task'=>$task,'attempted'=>(int)($state['attempted']??0)];
                if($legacy===null||$candidate['attempted']<$legacy['attempted'])$legacy=$candidate;
            }
            if($managed&&(!$legacy||$managed['_attempted']<=$legacy['attempted']))return $managedJobs->execute($managed);
            if($legacy){
                $id=$legacy['id'];$task=$legacy['task'];
                $secret=$this->config->get($task['key_env']);
                $state=['attempted'=>time(),'status'=>'running','message'=>'Request sent to the game controller'];$this->store->set('task:'.$id,$state);
                try{
                    if($secret==='')throw new \RuntimeException('Controller key is missing.');
                    $url=$task['url'].'?'.http_build_query($task['query']+['key'=>$secret]);
                    if($this->transport){[$status,$data]=($this->transport)($url);}else{
                        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>Budget::remaining(8),CURLOPT_FOLLOWLOCATION=>false]);
                        $raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$data=is_string($raw)?json_decode($raw,true):null;
                    }
                    if($status!==200||!is_array($data)||!in_array($data['status']??null,$task['success_statuses'],true))throw new \RuntimeException('Controller check failed or timed out. Check its audit log; Enoch will try again when due.');
                    $state['status']='ok';$state['message']=$data['status'];$state['completed']=time();
                }catch(\Throwable $e){$state['status']='failed';$state['message']=$e->getMessage();}
                $this->store->set('task:'.$id,$state);$this->store->audit('scheduler',$task['title'].' idle check',$state['message']);return ['task'=>$id,'status'=>$state['status']];
            }
            return ['due'=>false];
        })??['busy'=>true];
    }
}
