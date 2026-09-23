<?php
declare(strict_types=1);
namespace Enoch;

final class Engine {
    public function __construct(private Config $config,private Store $store,private Cloud $cloud,private Host $host) {}
    public function enqueue(string $service,string $operation,string $actor,array $options=[]): string {
        if(!isset($this->config->services[$service])||!in_array($operation,['start','stop'],true))throw new \RuntimeException('Unknown service or operation.');
        $options=(new Lifecycle($this->cloud))->normalize($options,$operation,$this->config->services[$service]);
        $id=bin2hex(random_bytes(12));$now=time();
        try{$this->store->query("INSERT INTO jobs(id,service,operation,status,phase,phase_since,created,updated,actor,message,data) VALUES(?,?,?,'active','queued',?,?,?,?,?,?)",[$id,$service,$operation,$now,$now,$now,$actor,'Waiting to begin',json_encode(['options'=>$options],JSON_THROW_ON_ERROR)]);}
        catch(\PDOException $e){if($this->store->query("SELECT id FROM jobs WHERE service=? AND status='active'",[$service])->fetch())throw new \RuntimeException('This server already has an operation in progress.');throw $e;}
        $this->store->audit($actor,$operation.' requested',$service);return $id;
    }
    private function advance(array &$job,string $phase,string $message,array $data=[],string $status='active'): void {
        $job['data']=array_replace($job['data'],$data);$job['phase']=$phase;$job['status']=$status;$job['phase_since']=time();$job['message']=$message;
        $this->store->query('UPDATE jobs SET phase=?,status=?,phase_since=?,updated=?,message=?,data=? WHERE id=?',[$phase,$status,time(),time(),$message,json_encode($job['data'],JSON_THROW_ON_ERROR),$job['id']]);
        $this->store->audit($job['actor'],$message,$job['service'].' · '.$job['id']);
    }
    private function done(array &$j,string $message):void{$this->advance($j,'done',$message,[],'done');}
    // At most one phase per request; no sleep loops and no replay of ambiguous mutations.
    public function tick(): array {
        return $this->store->locked(function(){
            $this->store->set('worker_seen',time());
            $j=$this->store->query("SELECT * FROM jobs WHERE status='active' ORDER BY updated,id LIMIT 1")->fetch();
            if(!$j)return ['work'=>false];
            if(time()-(int)$j['updated']<2)return ['work'=>true];
            $j['data']=json_decode($j['data'],true,512,JSON_THROW_ON_ERROR);
            // updated is a scheduling timestamp; phase_since is the independent timeout.
            $this->store->query('UPDATE jobs SET updated=? WHERE id=?',[time(),$j['id']]);
            try{$this->step($j,$this->config->services[$j['service']]);}
            catch(\Throwable $e){
                // An intent is durable before every cloud write. Its next phase reconciles
                // observed state, including when PHP died before saving an API response.
                if(!$e instanceof CloudRejected&&in_array($j['phase'],['creating','powering','shutting-down','snapshotting','deleting'],true)&&time()-(int)$j['phase_since']<120){
                    $this->store->query('UPDATE jobs SET message=? WHERE id=?',['Checking the result of a cloud request before continuing.',$j['id']]);
                }else{$this->advance($j,'failed',$e->getMessage(),[],'failed');}
            }
            return ['work'=>true,'job'=>$j['id']];
        })??['busy'=>true];
    }
    private function pinned(array $j,array $cfg):?array {
        $s=$this->cloud->server($cfg);
        if($s&&isset($j['data']['server'])&&$s['id']!==$j['data']['server'])throw new \RuntimeException('The server changed during this operation. Inspect before retrying.');
        return $s;
    }
    private function step(array &$j,array $cfg):void {
        $age=time()-(int)$j['phase_since'];
        if($age>7200)throw new \RuntimeException('Operation paused too long. Inspect the retained server and snapshot, then retry.');
        $s=$this->pinned($j,$cfg);
        $life=new Lifecycle($this->cloud);
        $options=$j['data']['options']??[];
        $save=$j['data']['save_snapshot']??true;
        switch($j['phase']) {
        case 'queued':
            $this->cloud->ips($cfg,$s);
            if($j['operation']==='start') {
                if($s){
                    if(($options['source']??'current')!=='current'||($options['type']??$cfg['type'])!==($s['server_type']['name']??$cfg['type'])||($options['save_snapshot']??true)===false)throw new \RuntimeException('Stop the existing VM before selecting a different restore profile.');
                    if($s['status']==='running'){$this->advance($j,'ready','Checking service health',['server'=>$s['id']]);return;}
                    if($s['status']!=='off')throw new \RuntimeException('Server is busy. Wait for it to settle before starting.');
                    $this->advance($j,'powering','Powering on',['server'=>$s['id']]);
                    $this->cloud->request('POST','/servers/'.$s['id'].'/actions/poweron');return;
                }
                $options=$life->normalize($options,'start',$cfg);
                $image=$life->restore($cfg,$options);
                $firewall=$this->cloud->request('GET','/firewalls/'.$cfg['firewall']);
                if(!isset($firewall['firewall']))throw new \RuntimeException('Configured firewall is missing.');
                $userData=Provision::cloudInit($this->config,$cfg);
                $this->advance($j,'creating','Restoring the selected snapshot',['image'=>$image['id'],'save_snapshot'=>$options['save_snapshot']]);
                $r=$this->cloud->request('POST','/servers',[
                    'name'=>$cfg['name'],'server_type'=>$options['type'],'location'=>$cfg['location'],'image'=>$image['id'],
                    'ssh_keys'=>$cfg['ssh_keys'],'firewalls'=>[['firewall'=>$cfg['firewall']]],
                    'public_net'=>['enable_ipv4'=>true,'enable_ipv6'=>true,'ipv4'=>$cfg['ipv4'],'ipv6'=>$cfg['ipv6']],
                    'labels'=>$cfg['labels']+['enoch-job'=>$j['id'],'enoch-save'=>$options['save_snapshot']?'yes':'no'], 'start_after_create'=>true,
                    'user_data'=>$userData,
                ]);
                if(isset($r['server']['id']))$this->advance($j,'creating','Waiting for the restored VM',['server'=>$r['server']['id']]);return;
            }
            if(!$s){$this->done($j,'Already stopped; no VM is allocated');return;}
            $save=$options['save_snapshot']??(($s['labels']['enoch-save']??'yes')!=='no');
            if(!$save&&empty($options['acknowledge_discard']))throw new \RuntimeException('This server is configured to discard changes. Confirm this before stopping.');
            $data=['server'=>$s['id'],'save_snapshot'=>$save];
            if(!$save)$data['fallback']=$life->current($cfg)['id'];
            if($save){
                $default=$this->cloud->all('server_types',['name'=>$cfg['type']])[0]??[];
                if(($s['primary_disk_size']??$s['server_type']['disk']??0)>($default['disk']??0)&&empty($options['acknowledge_large_disk']))throw new \RuntimeException('Confirm that the new snapshot will require a larger server disk.');
            }
            $this->advance($j,'queued','Shutdown policy checked',$data);
            if(!in_array($s['status'],['running','off'],true))throw new \RuntimeException('Server is busy. Retry once its current action finishes.');
            if($s['status']==='off'){$this->advance($j,'snapshot','Server is off; preparing a snapshot',['server'=>$s['id']]);return;}
            if($cfg['prepare']==='aio'){$this->advance($j,'preparing','Stopping Nextcloud containers cleanly',['server'=>$s['id']]);return;}
            $this->advance($j,'shutdown','Preparing a graceful shutdown',['server'=>$s['id']]);return;
        case 'creating':
            if(!$s){if($age>120)throw new \RuntimeException('Creation was not confirmed. Check Hetzner capacity and activity before retrying.');return;}
            if(($s['labels']['enoch-job']??null)!==$j['id'])throw new \RuntimeException('A different process created this VM. No further changes made.');
            if($s['status']==='running')$this->advance($j,'ready','Checking the restored service',['server'=>$s['id']]);return;
        case 'powering':
            if(!$s)throw new \RuntimeException('Server disappeared during startup.');
            if($s['status']==='running')$this->advance($j,'ready','Checking service health');
            elseif($age>300)throw new \RuntimeException('Power-on was not confirmed. The server is retained.');return;
        case 'ready':
            if(!$s||$s['status']!=='running')throw new \RuntimeException('VM is not running.');
            if($this->host->ready($cfg)){
                if($cfg['prepare']==='aio'){
                    try{$agent=$this->host->command($s,'probe');}catch(\Throwable $e){if($age>600)throw $e;return;}
                    if(($agent['state']??'')!=='installed')throw new \RuntimeException('Nextcloud shutdown agent is missing. The VM is retained.');
                }
                $this->done($j,'Service is online');
            }
            elseif($age>1800)throw new \RuntimeException('The VM is running but its service did not become healthy. Inspect it before retrying.');return;
        case 'preparing':
            if(!$s||$s['status']!=='running')throw new \RuntimeException('Nextcloud stopped before its clean shutdown was confirmed. Inspect the server.');
            $state=$this->host->command($s,'status '.$j['id']);
            if(($state['state']??'')==='missing'){$this->host->command($s,'prepare '.$j['id']);return;}
            if(($state['state']??'')==='failed')throw new \RuntimeException('Nextcloud containers did not stop cleanly. The VM is retained.');
            if(($state['state']??'')==='ready')$this->advance($j,'shutdown','Nextcloud is stopped; preparing VM shutdown');
            elseif($age>1800)throw new \RuntimeException('Nextcloud clean shutdown timed out. The VM is retained.');return;
        case 'shutdown':
            if(!$s)throw new \RuntimeException('Server disappeared before the snapshot.');
            if($s['status']==='off'){$this->advance($j,'snapshot','VM is off; preparing a snapshot');return;}
            if($s['status']!=='running')return;
            $this->cloud->ips($cfg,$s);
            if($cfg['prepare']==='aio'&&($this->host->command($s,'status '.$j['id'])['state']??'')!=='ready')throw new \RuntimeException('Nextcloud is no longer stopped. Refusing VM shutdown.');
            $this->advance($j,'shutting-down','Waiting for graceful VM shutdown');
            $this->cloud->request('POST','/servers/'.$s['id'].'/actions/shutdown');return;
        case 'shutting-down':
            if(!$s)throw new \RuntimeException('Server disappeared before the snapshot.');
            if($s['status']==='off')$this->advance($j,'snapshot','VM is off; preparing a snapshot');
            elseif($age>1800)throw new \RuntimeException('Graceful shutdown was not confirmed. No forced power-off or deletion was attempted.');return;
        case 'snapshot':
            if(!$s||$s['status']!=='off')throw new \RuntimeException('A snapshot requires the original VM to be powered off.');
            $this->cloud->ips($cfg,$s);
            if(!$save){
                $i=$this->cloud->request('GET','/images/'.$j['data']['fallback'])['image']??[];$this->cloud->imageValid($i,$cfg);
                $this->advance($j,'delete','Discard confirmed; previous snapshot verified',['image'=>$i['id']]);return;
            }
            $this->advance($j,'snapshotting','Saving a snapshot');
            $r=$this->cloud->request('POST','/servers/'.$s['id'].'/actions/create_image',[
                'type'=>'snapshot','description'=>$cfg['name'].'-current-'.gmdate('Ymd-His'),
                'labels'=>$cfg['labels']+['enoch-job'=>$j['id'],'generation'=>gmdate('Ymd-His')],
            ]);
            if(isset($r['image']['id']))$this->advance($j,'snapshotting','Waiting for snapshot verification',['image'=>$r['image']['id']]);return;
        case 'snapshotting':
            if(!$s||$s['status']!=='off')throw new \RuntimeException('Server changed while saving its snapshot. No deletion attempted.');
            $images=$this->cloud->images($cfg,['enoch-job'=>$j['id']]);
            if(count($images)>1)throw new \RuntimeException('Multiple snapshots match this operation. Inspect before continuing.');
            if(!$images){if($age>120)throw new \RuntimeException('Snapshot creation was not confirmed. The VM is retained.');return;}
            $i=$images[0];
            if($i['status']==='creating')return;
            $this->cloud->imageValid($i,$cfg,$s['id']);
            $this->advance($j,'delete','Snapshot verified; releasing VM capacity',['image'=>$i['id']]);return;
        case 'delete':
            if(!$s||$s['status']!=='off')throw new \RuntimeException('Server is no longer powered off. No deletion attempted.');
            $this->cloud->ips($cfg,$s);
            $i=$this->cloud->request('GET','/images/'.$j['data']['image'])['image']??[];
            $this->cloud->imageValid($i,$cfg,$save?$s['id']:null);
            if($save&&($i['labels']['enoch-job']??'')!==$j['id'])throw new \RuntimeException('Snapshot does not belong to this operation.');
            $this->advance($j,'deleting','Releasing VM; keeping IPs and snapshots');
            $this->cloud->request('DELETE','/servers/'.$s['id']);return;
        case 'deleting':
            if(!$s){if($save)$this->advance($j,'pruning','VM released; retaining the initial and current snapshots');else $this->done($j,'Stopped without saving; previous snapshots retained');return;}
            if($age>300)throw new \RuntimeException('Deletion was not confirmed. The verified snapshot is retained. Inspect Hetzner before retrying.');return;
        case 'pruning':
            if(!$life->pruneOne($cfg,(int)$j['data']['image']))$this->done($j,'Stopped and saved; initial and current snapshots retained');return;
        default:throw new \RuntimeException('Unknown operation phase.');
        }
    }
    public function abandon(string $id,string $actor):void {
        $this->store->locked(function()use($id,$actor){
            $j=$this->store->query("SELECT * FROM jobs WHERE id=? AND status='active'",[$id])->fetch();
            if(!$j)throw new \RuntimeException('No active job to pause.');
            // Pause only future steps; already submitted cloud actions may still finish.
            $j['data']=json_decode($j['data'],true);$this->advance($j,'failed','Paused by '.$actor.'; check cloud state before starting another operation',[],'failed');
        });
    }
    public function dashboard():array {
        $cached=$this->store->get('dashboard');
        if($cached&&time()-$cached['checked']<20)return $cached;
        $cards=[];
        $serverError=null;
        try{$servers=$this->cloud->all('servers');}catch(\Throwable $e){$servers=[];$serverError=$e;}
        foreach($this->config->services as $id=>$cfg){
            try{if($serverError)throw $serverError;$s=$this->cloud->server($cfg,$servers);$images=$this->cloud->images($cfg);$latest=null;foreach($images as $image)if($image['status']==='available'){$latest=$image;break;}
                $cards[]=['id'=>$id,'title'=>$cfg['title'],'subtitle'=>$cfg['subtitle'],'description'=>$cfg['description'],'domain'=>$cfg['domain'],'state'=>$s['status']??'saved','type'=>$s['server_type']['name']??$cfg['type'],'location'=>$cfg['location'],'ip'=>$s['public_net']['ipv4']['ip']??null,'save_snapshot'=>($s['labels']['enoch-save']??'yes')!=='no','snapshot'=>$latest?['id'=>$latest['id'],'created'=>$latest['created'],'size'=>$latest['image_size']??null,'disk'=>$latest['disk_size']]:null,'initial_image'=>$cfg['initial_image'],'snapshots'=>count($images)+((!in_array($cfg['initial_image'],array_column($images,'id'),true))?1:0),'error'=>null];
            }catch(\Throwable $e){$cards[]=['id'=>$id,'title'=>$cfg['title'],'subtitle'=>$cfg['subtitle'],'description'=>$cfg['description'],'domain'=>$cfg['domain'],'state'=>'unknown','error'=>$e->getMessage()];}
        }
        $result=['checked'=>time(),'services'=>$cards];$this->store->set('dashboard',$result);return $result;
    }
}
