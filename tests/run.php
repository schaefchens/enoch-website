<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Enoch\{Auth,Cloud,Config,Engine,Host,Scheduler,Store};
$checks=0;
function check(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException('FAIL: '.$label);$checks++;echo "PASS $label\n";}
function refuses(callable $fn):bool{try{$fn();return false;}catch(Throwable){return true;}}
final class FakeCloud extends Cloud {
    public array $servers=[],$images=[],$calls=[];
    public bool $missingInitial=false,$unsafeIP=false,$loseSnapshotResponse=false,$loseCreateResponse=false,$rejectSnapshot=false;
    public function __construct(public array $cfg){parent::__construct('fake');}
    public function seed(string $state='running'):array {
        $s=['id'=>10,'name'=>$this->cfg['name'],'status'=>$state,'labels'=>[], 'public_net'=>['ipv4'=>['id'=>$this->cfg['ipv4'],'ip'=>'192.0.2.1'],'ipv6'=>['id'=>$this->cfg['ipv6']]],'server_type'=>['name'=>$this->cfg['type']]];
        $this->servers=[$s];return $s;
    }
    public function image(array $labels=[]):array{return ['id'=>20,'created'=>'2026-09-22T00:00:00Z','type'=>'snapshot','status'=>'available','created_from'=>['id'=>10],'architecture'=>'x86','disk_size'=>40,'labels'=>$this->cfg['labels']+$labels];}
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
            if($base==='/server_types')return ['server_types'=>[['name'=>$q['name']??$this->cfg['type'],'architecture'=>'x86','disk'=>($q['name']??'')==='cx53'?320:40]]];
            if(str_starts_with($base,'/firewalls/'))return ['firewall'=>['id'=>1]];
        }
        if($method==='POST'&&$path==='/servers'){
            $s=$this->seed();$this->servers[0]['labels']=$body['labels'];
            if($this->loseCreateResponse)throw new RuntimeException('Lost create response');
            return ['server'=>$this->servers[0]];
        }
        if(str_ends_with($path,'/poweron')){$this->servers[0]['status']='running';return ['action'=>['id'=>1]];}
        if(str_ends_with($path,'/shutdown')){$this->servers[0]['status']='off';return ['action'=>['id'=>2]];}
        if(str_ends_with($path,'/create_image')){
            if($this->rejectSnapshot)throw new RuntimeException('Snapshot failed');
            $i=$this->image($body['labels']);$i['labels']=$body['labels'];$i['created']=gmdate('c');$i['id']=21;$this->images[]=$i;
            if($this->loseSnapshotResponse)throw new RuntimeException('Lost snapshot response');
            return ['image'=>$i];
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
$root=dirname(__DIR__);$private=sys_get_temp_dir().'/enoch-keys-'.bin2hex(random_bytes(8));mkdir($private.'/ssh',0700,true);file_put_contents($private.'/ssh/enoch.pub','ssh-ed25519 AAAA test');putenv('ENOCH_DATA_DIR='.$private);$config=new Config($root);$dirs=[];
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
    [$s,$c,$h,$e]=fixture();$c->seed();$c->images=[$c->image()];$e->enqueue('nextcloud','stop','alice');for($n=0;$n<15;$n++)$j=tick($s,$c,$h);
    check($j['status']==='done'&&array_column($c->images,'id')===[21],'verified new snapshot replaces previous current snapshot');
    check(!array_filter($c->calls,fn($a)=>$a[0]==='DELETE'&&$a[1]==='/images/'.$c->cfg['initial_image']),'protected initial is never pruned');
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
    check(refuses(fn()=>$vault->save('nextcloud',['unknown'=>'value'])),'credential field allowlist enforced');
    $s->migrateAccess(array_keys($config->services));check(count($auth->access->grants($u))===1,'migration reruns do not expand grants');
    echo "\n$checks checks passed. No real cloud mutations were made.\n";
}finally{
    foreach($dirs as $dir){foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}if(is_file($private.'/credentials.key'))unlink($private.'/credentials.key');unlink($private.'/ssh/enoch.pub');rmdir($private.'/ssh');rmdir($private);
}
