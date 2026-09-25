<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Enoch\{Activity,ActivityMonitor,Auth,Cloud,CloudRejected,Config,Engine,Host,Lifecycle,ScheduledJobs,Scheduler,Store};
$checks=0;
function check(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException('FAIL: '.$label);$checks++;echo "PASS $label\n";}
function refuses(callable $fn):bool{try{$fn();return false;}catch(Throwable){return true;}}
final class FakeCloud extends Cloud {
    public array $servers=[],$images=[],$calls=[];
    public int $nextImageId=21;
    public bool $missingInitial=false,$unsafeIP=false,$loseSnapshotResponse=false,$loseCreateResponse=false,$rejectSnapshot=false;
    public int $capacityRejects=0;
    public ?string $rejectCreateCode=null;
    public array $unavailableTypes=[];
    public function __construct(public array $cfg){parent::__construct('fake');}
    public function seed(string $state='running'):array {
        $s=['id'=>10,'name'=>$this->cfg['name'],'status'=>$state,'labels'=>[],'image'=>['id'=>$this->cfg['initial_image']], 'public_net'=>['ipv4'=>['id'=>$this->cfg['ipv4'],'ip'=>'192.0.2.1'],'ipv6'=>['id'=>$this->cfg['ipv6']]],'server_type'=>['name'=>$this->cfg['type']]];
        $this->servers=[$s];return $s;
    }
    public function image(array $labels=[]):array{return ['id'=>20,'description'=>'test-current','created'=>'2026-09-22T00:00:00Z','type'=>'snapshot','status'=>'available','created_from'=>['id'=>10],'architecture'=>'x86','disk_size'=>40,'protection'=>['delete'=>false],'labels'=>$this->cfg['labels']+$labels];}
    private function type(string $name):array {
        $specs=['cx23'=>[2,4,40,0.010472,6.5331,'CX 23','cost_optimized'],'cpx12'=>[1,2,40,0.021896,13.6731,'CPX 12','regular_purpose'],'cpx22'=>[2,4,80,0.037128,23.1931,'CPX 22','regular_purpose'],'cx53'=>[16,32,320,0.056287,35.0931,'CX 53','cost_optimized']];
        [$cores,$memory,$disk,$hourly,$monthly,$description,$category]=$specs[$name]??[2,4,40,0.01,7.00,strtoupper($name),'cost_optimized'];
        return ['name'=>$name,'description'=>$description,'category'=>$category,'cores'=>$cores,'memory'=>$memory,'architecture'=>'x86','disk'=>$disk,'storage_type'=>'local','cpu_type'=>'shared','deprecated'=>false,'locations'=>[['name'=>'fsn1','available'=>!in_array($name,$this->unavailableTypes,true),'deprecation'=>null]],'prices'=>[['location'=>'fsn1','price_hourly'=>['gross'=>(string)$hourly],'price_monthly'=>['gross'=>(string)$monthly]]]];
    }
    public function request(string $method,string $path,?array $body=null):array{
        if($method!=='GET')$this->calls[]=[$method,$path,$body];
        if($method==='GET'){
            $parsed=parse_url($path);$base=$parsed['path'];parse_str($parsed['query']??'',$q);
            if($base==='/servers')return ['servers'=>$this->servers];
            if(str_starts_with($base,'/primary_ips/'))return ['primary_ip'=>['auto_delete'=>$this->unsafeIP,'assignee_id'=>$this->servers[0]['id']??null,'location'=>['name'=>'fsn1']]];
            if($base==='/images'){
                $images=$this->images;
                if(isset($q['label_selector']))foreach(explode(',',$q['label_selector']) as $pair){[$k,$v]=explode('=',$pair,2);$images=array_values(array_filter($images,fn($i)=>($i['labels'][$k]??null)===$v));}
                return ['images'=>$images];
            }
            if(str_starts_with($base,'/images/')){if((int)basename($base)===$this->cfg['initial_image']&&!$this->missingInitial){$i=$this->image();$i['id']=$this->cfg['initial_image'];$i['description']='test-initial';$i['protection']=['delete'=>true];return ['image'=>$i];}foreach($this->images as $i)if($i['id']===(int)basename($base))return ['image'=>$i];return ['not_found'=>true];}
            if($base==='/server_types')return ['server_types'=>isset($q['name'])?[$this->type($q['name'])]:array_map($this->type(...),array_values(array_unique([...($this->cfg['types']??[$this->cfg['type']]),'cpx22','cx53'])))];
            if(str_starts_with($base,'/firewalls/'))return ['firewall'=>['id'=>1]];
        }
        if($method==='POST'&&$path==='/servers'){
            if($this->capacityRejects>0){$this->capacityRejects--;throw new CloudRejected(422,'resource_unavailable','Hetzner returned HTTP 422 (resource_unavailable).');}
            if($this->rejectCreateCode!==null)throw new CloudRejected(422,$this->rejectCreateCode,'Hetzner returned HTTP 422 ('.$this->rejectCreateCode.').');
            $s=$this->seed();$this->servers[0]['labels']=$body['labels'];
            $this->servers[0]['server_type']['name']=$body['server_type'];
            $this->servers[0]['image']=['id'=>(int)$body['image']];
            if($this->loseCreateResponse)throw new RuntimeException('Lost create response');
            return ['server'=>$this->servers[0]];
        }
        if(str_ends_with($path,'/poweron')){$this->servers[0]['status']='running';return ['action'=>['id'=>1]];}
        if(str_ends_with($path,'/shutdown')){$this->servers[0]['status']='off';return ['action'=>['id'=>2]];}
        if(str_ends_with($path,'/create_image')){
            if($this->rejectSnapshot)throw new RuntimeException('Snapshot failed');
            $i=$this->image($body['labels']);$i['labels']=$body['labels'];$i['description']=$body['description'];$i['id']=$this->nextImageId++;$i['created']=gmdate('c',time()+$i['id']);$this->images[]=$i;
            if($this->loseSnapshotResponse)throw new RuntimeException('Lost snapshot response');
            return ['image'=>$i];
        }
        if($method==='POST'&&preg_match('#^/images/([0-9]+)/actions/change_protection$#',$path,$m)){
            foreach($this->images as &$image)if((int)$image['id']===(int)$m[1])$image['protection']['delete']=(bool)($body['delete']??false);unset($image);
            return ['action'=>['id'=>3,'status'=>'success']];
        }
        if($method==='DELETE'&&str_starts_with($path,'/images/')){$this->images=array_values(array_filter($this->images,fn($i)=>$i['id']!==(int)basename($path)));return [];}
        if($method==='DELETE'){$this->servers=[];return ['action'=>['id'=>4]];}
        throw new RuntimeException('Unexpected request '.$method.' '.$path);
    }
}

// Hetzner's successful image DELETE is a 204 with no JSON body. FakeCloud
// returns the equivalent empty array in pruning tests above.
final class FakeHost extends Host {
    public string $state='ready';public array $commands=[];public bool $healthy=true;
    public function command(array $s,string $command):array{$this->commands[]=$command;return ['state'=>$command==='probe'?'installed':$this->state];}
    public function ready(array $cfg):bool{return $this->healthy;}
}
$root=dirname(__DIR__);$private=sys_get_temp_dir().'/enoch-keys-'.bin2hex(random_bytes(8));mkdir($private.'/ssh',0700,true);file_put_contents($private.'/ssh/enoch.pub','ssh-ed25519 AAAA test');putenv('ENOCH_DATA_DIR='.$private);putenv('CRON_KEY='.str_repeat('c',64));putenv('APP_URL=https://enoch.example.org');$config=new Config($root);$dirs=[];
function fixture(string $service='nextcloud'):array{
    global $config,$dirs;
    $dir=sys_get_temp_dir().'/enoch-test-'.bin2hex(random_bytes(8));$dirs[]=$dir;
    $store=new Store($dir);$cloud=new FakeCloud($config->services[$service]);$host=new FakeHost($config);$engine=new Engine($config,$store,$cloud,$host);
    return [$store,$cloud,$host,$engine];
}
function tick(Store $store,FakeCloud $cloud,FakeHost $host):array{
    global $config;$store->query('UPDATE jobs SET updated=0');
    (new Engine($config,$store,$cloud,$host))->tick();return $store->query('SELECT * FROM jobs ORDER BY created DESC LIMIT 1')->fetch();
}
function deleted(FakeCloud $c):bool{return count(array_filter($c->calls,fn($a)=>$a[0]==='DELETE'))>0;}
try{
    [$s,$c,$h,$e]=fixture();$c->seed();$e->enqueue('nextcloud','stop','alice');
    for($n=0;$n<12;$n++)$j=tick($s,$c,$h);
    check($j['status']==='done'&&deleted($c),'clean stop snapshots before deleting');
    check(array_column($c->calls,1)===['/servers/10/actions/shutdown','/servers/10/actions/create_image','/servers/10'],'cloud mutation order is shutdown, snapshot, delete');
    check(count($c->images)===1,'exactly one snapshot per operation');
    [$s,$c,$h,$e]=fixture();$c->seed();$h->state='failed';$e->enqueue('nextcloud','stop','alice');tick($s,$c,$h);$j=tick($s,$c,$h);
    check($j['status']==='failed'&&!$c->calls,'failed AIO stop does not shut down, snapshot or delete');
    [$s,$c,$h,$e]=fixture();$c->seed();$c->unsafeIP=true;$e->enqueue('nextcloud','stop','alice');$j=tick($s,$c,$h);
    check($j['status']==='failed'&&!$c->calls,'auto-delete primary IP blocks mutation');
    [$s,$c,$h,$e]=fixture();$c->seed();$c->servers[0]['public_net']['ipv4']['id']=999;$e->enqueue('nextcloud','stop','alice');$j=tick($s,$c,$h);
    check($j['status']==='failed'&&!$c->calls,'matching name with wrong IP cannot authorize changes');
    [$s,$c,$h,$e]=fixture();$c->seed('off');$c->rejectSnapshot=true;$e->enqueue('nextcloud','stop','alice');tick($s,$c,$h);tick($s,$c,$h);$s->query('UPDATE jobs SET phase_since=?',[time()-121]);$j=tick($s,$c,$h);
    check($j['status']==='failed'&&!deleted($c),'failed snapshot retains the VM');
    [$s,$c,$h,$e]=fixture();$c->seed('off');$c->loseSnapshotResponse=true;$e->enqueue('nextcloud','stop','alice');for($n=0;$n<12;$n++)$j=tick($s,$c,$h);
    check($j['status']==='done'&&count($c->images)===1,'lost snapshot response reconciles without duplicate writes');
    [$s,$c,$h,$e]=fixture();$c->seed('off');$e->enqueue('nextcloud','stop','alice');tick($s,$c,$h);tick($s,$c,$h);$c->images[0]['created_from']['id']=99;tick($s,$c,$h);
    check(!deleted($c),'snapshot from another VM never permits deletion');
    [$s,$c,$h,$e]=fixture();$c->seed('off');$e->enqueue('nextcloud','stop','alice');tick($s,$c,$h);tick($s,$c,$h);tick($s,$c,$h);$c->images=[];$j=tick($s,$c,$h);
    check($j['status']==='failed'&&!deleted($c),'snapshot is revalidated immediately before deletion');
    [$s,$c,$h,$e]=fixture();$c->seed('off');$e->enqueue('nextcloud','stop','alice');tick($s,$c,$h);tick($s,$c,$h);tick($s,$c,$h);$c->servers[0]['status']='running';tick($s,$c,$h);
    check(!deleted($c),'a restarted VM is never deleted');
    [$s,$c,$h,$e]=fixture();$c->seed();$e->enqueue('nextcloud','stop','alice');
    check(refuses(fn()=>$e->enqueue('nextcloud','start','bob')),'only one active job per service');
    check(refuses(fn()=>$e->enqueue('arbitrary','stop','bob')),'browser cannot select unmanaged resources');
    [$s,$c,$h,$e]=fixture();$c->missingInitial=true;$e->enqueue('nextcloud','start','alice');$j=tick($s,$c,$h);
    check($j['status']==='failed'&&!$c->calls,'restore without snapshot fails closed');
    [$s,$c,$h,$e]=fixture();$c->images=[$c->image()];$c->loseCreateResponse=true;$e->enqueue('nextcloud','start','alice');for($n=0;$n<5;$n++)$j=tick($s,$c,$h);
    check($j['status']==='done'&&count($c->calls)===1,'lost create response reconciles using job label and pinned IPs');
    check($c->calls[0][2]['public_net']['ipv4']===$config->services['nextcloud']['ipv4'],'restore uses the persistent addresses');
    check(str_contains($c->calls[0][2]['user_data'],'enoch-activity-heartbeat.timer')&&str_contains($c->calls[0][2]['user_data'],'activity.php')&&!str_contains($c->calls[0][2]['user_data'],str_repeat('c',64)),'restore installs the activity timer without disclosing the scheduler key');
    [$s,$c,$h,$e]=fixture();$c->images=[$c->image()];$c->capacityRejects=1;$e->enqueue('nextcloud','start','alice');for($n=0;$n<7;$n++)$j=tick($s,$c,$h);
    $creates=array_values(array_filter($c->calls,fn($call)=>$call[0]==='POST'&&$call[1]==='/servers'));
    check($j['status']==='done'&&array_column(array_column($creates,2),'server_type')===['cx23','cpx12'],'capacity rejection advances through the configured server type order');
    check(($c->servers[0]['server_type']['name']??null)==='cpx12','fallback server type is preserved on the created VM');
    [$s,$c,$h,$e]=fixture();$c->images=[$c->image()];$c->unavailableTypes=['cx23'];$e->enqueue('nextcloud','start','alice');for($n=0;$n<6;$n++)$j=tick($s,$c,$h);
    $creates=array_values(array_filter($c->calls,fn($call)=>$call[0]==='POST'&&$call[1]==='/servers'));
    check($j['status']==='done'&&array_column(array_column($creates,2),'server_type')===['cpx12'],'location availability skips a known-unavailable preferred type before creation');
    [$s,$c,$h,$e]=fixture();$c->seed('off');$c->servers[0]['server_type']['name']='cpx12';$e->enqueue('nextcloud','start','alice');for($n=0;$n<4;$n++)$j=tick($s,$c,$h);
    check($j['status']==='done'&&array_column($c->calls,1)===['/servers/10/actions/poweron'],'default start powers on an existing automatically selected fallback type');
    [$s,$c,$h,$e]=fixture();$c->images=[$c->image()];$c->rejectCreateCode='invalid_input';$e->enqueue('nextcloud','start','alice');$j=tick($s,$c,$h);
    check($j['status']==='failed'&&count(array_filter($c->calls,fn($call)=>$call[1]==='/servers'))===1,'non-capacity create rejection never tries another server type');
    [$s,$c,$h,$e]=fixture();$c->images=[$c->image()];$c->capacityRejects=2;$e->enqueue('nextcloud','start','alice');tick($s,$c,$h);$j=tick($s,$c,$h);
    check($j['status']==='failed'&&str_contains($j['message'],'CX23, CPX12'),'exhausted fallback list reports every attempted server type');
    [$s,$c]=fixture();$c->images=[$c->image()];$catalog=(new Lifecycle($c))->options($config->services['nextcloud']);$cx=array_values(array_filter($catalog['types'],fn($type)=>$type['name']==='cx23'))[0]??[];
    check($catalog['fallback_types']===['cx23','cpx12']&&($cx['price_hourly']??null)===0.010472&&($cx['price_monthly']??null)===6.5331,'server options expose ordered fallbacks and current hourly and monthly prices');
    [$s,$c,$h,$e]=fixture('hpb');$c->seed();$e->enqueue('hpb','stop','alice');for($n=0;$n<10;$n++)$j=tick($s,$c,$h);
    check($j['status']==='done'&&!$h->commands,'HPB lifecycle is independent of Nextcloud');
    [$s,$c,$h,$e]=fixture();$auth=new Auth($config,$s);$auth->addUser('alice','test-password-long','operator');
    check(refuses(fn()=>$auth->addUser('alice','test-password-long','admin')),'duplicate accounts rejected');
    check(refuses(fn()=>$auth->addUser('bob','short','admin')),'short passwords rejected');
    $row=$s->query('SELECT * FROM users')->fetch();$_SESSION=['uid'=>$row['id'],'version'=>$row['version'],'seen'=>time(),'csrf'=>'test-csrf'];
    check($auth->requireRole('operator')['name']==='alice','operator access works');
    check(refuses(fn()=>$auth->requireRole('admin')),'operator cannot administer accounts');
    check(refuses(fn()=>$auth->csrf('wrong')),'CSRF mismatch rejected');
    $s->query('UPDATE users SET version=version+1');check($auth->user()===null,'password reset or disable invalidates existing sessions');
    putenv('GAME_SERVER_ADMIN_KEY=test-secret-never-log');putenv('GAME_IDLE_CHECK_INTERVAL=60');
    $calls=0;$scheduler=new Scheduler($config,$s,function($url)use(&$calls){$calls++;return [200,['status'=>'already-destroyed']];});
    $scheduler->runDue();$scheduler->runDue();check($calls===1,'rapid cron ticks run a scheduled task only when due');
    check(!str_contains(json_encode($s->query('SELECT * FROM audit')->fetchAll()),'test-secret-never-log'),'scheduled task secret is absent from audit log');
    $s->set('task:spirit-idle',['attempted'=>0]);$scheduler=new Scheduler($config,$s,fn($url)=>[403,['status'=>'error']]);$r=$scheduler->runDue();check($r['status']==='failed','scheduler records downstream authentication failure');
    $managedCalls=[];$managed=new ScheduledJobs($config,$s,function($method,$url,$token)use(&$managedCalls){$managedCalls[]=[$method,$url,$token];return 204;});
    $managedId=$managed->save(['name'=>'Remote upkeep','url'=>'https://maintenance.example.org/run','method'=>'POST','interval_seconds'=>60,'enabled'=>true,'bearer'=>'managed-test-secret']);
    $stored=$s->query('SELECT bearer FROM scheduled_jobs WHERE id=?',[$managedId])->fetchColumn();
    check(!str_contains($stored,'managed-test-secret')&&$managed->all()[0]['has_bearer'],'managed bearer token is encrypted and never returned by the task listing');
    $row=$managed->due();$result=$managed->execute($row);check($result['status']==='ok'&&$managedCalls===[['POST','https://maintenance.example.org/run','managed-test-secret']],'managed request sends its bearer token only to the configured HTTPS endpoint');
    $managed->save(['id'=>$managedId,'name'=>'Remote upkeep','url'=>'https://maintenance.example.org/run','method'=>'GET','interval_seconds'=>120,'enabled'=>false,'bearer'=>'']);
    check($managed->all()[0]['method']==='GET'&&!$managed->all()[0]['enabled']&&$managed->due()===null,'managed request can be edited, paused and retain its encrypted token');
    check(refuses(fn()=>$managed->save(['name'=>'Unsafe','url'=>'http://127.0.0.1/private','method'=>'GET','interval_seconds'=>60,'enabled'=>true,'bearer'=>'x'])),'managed requests reject non-HTTPS and private literal targets');
    $managed->delete($managedId);check($managed->all()===[],'managed request and its execution state can be deleted');
    putenv('CRON_KEY='.str_repeat('c',64));$token=Activity::token($config,'nextcloud');Activity::record($config,$s,'nextcloud',$token);
    check(($s->get('activity:nextcloud')['last_seen']??0)>0&&$token!==Activity::token($config,'hpb')&&refuses(fn()=>Activity::record($config,$s,'nextcloud','wrong')),'activity heartbeats require a distinct service-specific token');
    [$as,$ac,$ah,$ae]=fixture();$ac->seed();$ac->servers[0]['created']=gmdate('c',time()-4000);$as->set('activity:nextcloud',['last_seen'=>time()-4000]);
    (new ActivityMonitor($config,$as,$ac,$ae))->runDue();$automatic=$as->query("SELECT * FROM jobs WHERE status='active'")->fetch();
    check($automatic&&$automatic['operation']==='stop'&&$automatic['actor']==='automatic inactivity monitor','quiet running service queues the ordinary safe stop workflow');
    check($config->services['nextcloud']['idle_timeout']===$config->services['hpb']['idle_timeout'],'Nextcloud and HPB use the same inactivity interval');
    [$s,$c,$h,$e]=fixture();$c->seed();$c->images=[$c->image()];$e->enqueue('nextcloud','stop','alice');for($n=0;$n<15;$n++)$j=tick($s,$c,$h);
    check($j['status']==='done'&&array_column($c->images,'id')===[21],'verified new snapshot replaces previous current snapshot');
    check(!array_filter($c->calls,fn($a)=>$a[0]==='DELETE'&&$a[1]==='/images/'.$c->cfg['initial_image']),'protected initial is never pruned');
    [$s,$c,$h,$e]=fixture();$c->seed();$c->images=[$c->image(['enoch-kind'=>'current'])];$e->enqueue('nextcloud','stop','alice',['checkpoint_name'=>'Before upgrade']);for($n=0;$n<16;$n++)$j=tick($s,$c,$h);
    $checkpoint=$c->images[0]??[];$paths=array_column($c->calls,1);
    check($j['status']==='done'&&($checkpoint['labels']['enoch-kind']??'')==='checkpoint'&&!empty($checkpoint['protection']['delete']),'checkpoint is protected before the VM is released');
    check(($checkpoint['labels']['enoch-parent']??null)===(string)$c->cfg['initial_image'],'checkpoint records the snapshot used to create its VM');
    $protectAt=array_search('/images/21/actions/change_protection',$paths,true);$deleteAt=array_search('/servers/10',$paths,true);
    check($protectAt!==false&&$deleteAt!==false&&$protectAt<$deleteAt,'checkpoint protection precedes VM deletion');
    check(str_contains($checkpoint['description'],'checkpoint-Before upgrade'),'checkpoint name is kept in its snapshot description');
    $e->enqueue('nextcloud','start','alice',['source'=>'checkpoint:21']);for($n=0;$n<5;$n++)$j=tick($s,$c,$h);
    $creates=array_values(array_filter($c->calls,fn($call)=>$call[0]==='POST'&&$call[1]==='/servers'));
    check($j['status']==='done'&&end($creates)[2]['image']===21&&end($creates)[2]['labels']['enoch-source']==='21','protected checkpoint can restore a VM and records its source');
    $e->enqueue('nextcloud','stop','alice');for($n=0;$n<16;$n++)$j=tick($s,$c,$h);
    $current=array_values(array_filter($c->images,fn($image)=>($image['labels']['enoch-kind']??'')==='current'))[0]??[];
    check($j['status']==='done'&&($current['labels']['enoch-parent']??null)==='21','later snapshots record the checkpoint as their parent');
    $kept=array_values(array_filter($c->images,fn($image)=>$image['id']===21))[0]??[];
    check(count($c->images)===2&&!empty($kept['protection']['delete']),'later current saves never prune protected checkpoints');
    [$s,$c,$h,$e]=fixture();$unprotected=$c->image(['enoch-kind'=>'checkpoint']);$c->images=[$unprotected];$e->enqueue('nextcloud','start','alice',['source'=>'checkpoint:20']);$j=tick($s,$c,$h);
    check($j['status']==='failed'&&!$c->calls,'an unprotected checkpoint cannot be restored');
    [$s,$c,$h,$e]=fixture();$c->seed();
    check(refuses(fn()=>$e->enqueue('nextcloud','stop','alice',['checkpoint_name'=>'']))&&refuses(fn()=>$e->enqueue('nextcloud','stop','alice',['checkpoint_name'=>str_repeat('x',49)])),'checkpoint names are validated before a job is queued');
    [$s,$c,$h,$e]=fixture();$c->seed();$c->images=[$c->image()];
    check(refuses(fn()=>$e->enqueue('nextcloud','stop','alice',['save_snapshot'=>false])),'discard requires explicit acknowledgement');
    $e->enqueue('nextcloud','stop','alice',['save_snapshot'=>false,'acknowledge_discard'=>true]);for($n=0;$n<12;$n++)$j=tick($s,$c,$h);
    check($j['status']==='done'&&array_column($c->images,'id')===[20]&&!array_filter($c->calls,fn($a)=>str_ends_with($a[1],'create_image')),'discard stop keeps previous current snapshot and creates none');
    [$s,$c,$h,$e]=fixture();$c->images=[$c->image()];$e->enqueue('nextcloud','start','alice',['type'=>'cx53']);$j=tick($s,$c,$h);
    check($j['status']==='failed'&&!$c->calls,'large-disk saving requires acknowledgement');
    [$s,$c,$h,$e]=fixture();$c->images=[$c->image()];$e->enqueue('nextcloud','start','alice',['type'=>'cx53','save_snapshot'=>false,'acknowledge_discard'=>true]);for($n=0;$n<5;$n++)$j=tick($s,$c,$h);
    check($j['status']==='done'&&$c->calls[0][2]['labels']['enoch-save']==='no','ephemeral large server stores no-save policy on VM identity');
    [$s,$c,$h,$e]=fixture();$c->seed();$c->servers[0]['labels']['enoch-save']='no';$e->enqueue('nextcloud','stop','alice');$j=tick($s,$c,$h);
    check($j['status']==='failed'&&!$c->calls,'ordinary stop cannot silently discard ephemeral session');
    [$s,$c,$h,$e]=fixture();$auth=new Auth($config,$s);$id=$auth->addUser('limited','a-test-password-long','operator','simple',['nextcloud'=>['view'=>true,'start'=>true]]);$u=$s->query('SELECT * FROM users WHERE id=?',[$id])->fetch();
    check(isset($auth->access->grants($u)['nextcloud'])&&!isset($auth->access->grants($u)['hpb']),'per-service grants do not expose unassigned services');
    check(refuses(fn()=>$auth->access->requireOperation($u,'nextcloud','stop'))&&refuses(fn()=>$auth->access->requireOperation($u,'nextcloud','credentials')),'start-only account cannot stop or reveal credentials');
    $present=(new Enoch\Dashboard($config,$s,$auth->access))->present($u,['checked'=>time(),'services'=>[['id'=>'nextcloud','state'=>'running','ip'=>'sensitive-ip','error'=>'private-error'],['id'=>'hpb','state'=>'running']]]);
    check(count($present['services'])===1&&$present['services'][0]['allocated']&&!str_contains(json_encode($present),'sensitive-ip')&&!str_contains(json_encode($present),'private-error'),'simple API uses a minimal whitelist and preserves allocation state');
    $saved=(new Enoch\Dashboard($config,$s,$auth->access))->present($u,['checked'=>time(),'services'=>[['id'=>'nextcloud','state'=>'saved']]]);
    check(!$saved['services'][0]['allocated'],'saved simple service cannot expose an inapplicable stop control');
    $vault=new Enoch\Vault($config,$s);$vault->save('nextcloud',['admin_password'=>'private-test-value']);
    check($vault->read('nextcloud')['admin_password']==='private-test-value'&&!str_contains(json_encode($s->get('credentials:nextcloud')),'private-test-value'),'credentials are encrypted at rest and decrypt correctly');
    $legacyIv=random_bytes(12);$legacyTag='';$legacyPlain=json_encode(['turn_secret'=>'preserved-legacy-value'],JSON_THROW_ON_ERROR);$legacyCipher=openssl_encrypt($legacyPlain,'aes-256-gcm',file_get_contents($private.'/credentials.key'),OPENSSL_RAW_DATA,$legacyIv,$legacyTag,'hpb');
    $s->set('credentials:hpb',['iv'=>base64_encode($legacyIv),'tag'=>base64_encode($legacyTag),'data'=>base64_encode($legacyCipher)]);
    check($vault->read('hpb')['turn_secret']==='preserved-legacy-value','existing production credential ciphertext remains readable after the shared secret-store upgrade');
    check(refuses(fn()=>$vault->save('nextcloud',['unknown'=>'value'])),'credential field allowlist enforced');
    $s->migrateAccess(array_keys($config->services));check(count($auth->access->grants($u))===1,'migration reruns do not expand grants');
    echo "\n$checks checks passed. No real cloud mutations were made.\n";
}finally{
    foreach($dirs as $dir){foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}if(is_file($private.'/credentials.key'))unlink($private.'/credentials.key');unlink($private.'/ssh/enoch.pub');rmdir($private.'/ssh');rmdir($private);
}
