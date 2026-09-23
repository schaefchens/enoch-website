<?php
declare(strict_types=1);
namespace Enoch;

final class ScheduledJobs {
    public function __construct(private Config $config,private Store $store,private ?\Closure $transport=null) {}
    public function all():array {
        $rows=$this->store->query('SELECT id,name,url,method,interval_seconds,enabled,created,updated,bearer FROM scheduled_jobs ORDER BY name COLLATE NOCASE,id')->fetchAll();
        foreach($rows as &$row){$row['id']=(int)$row['id'];$row['interval_seconds']=(int)$row['interval_seconds'];$row['enabled']=(bool)$row['enabled'];$row['has_bearer']=$row['bearer']!=='';$row['state']=$this->store->get('scheduled:'.$row['id']);unset($row['bearer']);}unset($row);
        return $rows;
    }
    public function save(array $input):int {
        $id=(int)($input['id']??0);$name=trim((string)($input['name']??''));$url=trim((string)($input['url']??''));$method=strtoupper((string)($input['method']??'POST'));
        $interval=(int)($input['interval_seconds']??0);$enabled=!empty($input['enabled']);$bearer=(string)($input['bearer']??'');
        if($name===''||strlen($name)>80)throw new \RuntimeException('Use a job name of 1–80 bytes.');
        self::validateUrl($url);if(!in_array($method,['GET','POST'],true))throw new \RuntimeException('Scheduled requests support GET or POST.');
        if($interval<60||$interval>2592000)throw new \RuntimeException('Choose an interval from 1 minute to 30 days.');
        if(strlen($bearer)>4096)throw new \RuntimeException('Bearer token is too long.');
        $now=time();$box=new SecretBox($this->config);
        if($id){
            $current=$this->store->query('SELECT bearer FROM scheduled_jobs WHERE id=?',[$id])->fetch();if(!$current)throw new \RuntimeException('Scheduled request was not found.');
            $sealed=$bearer!==''?$box->seal($bearer,'scheduled:'.$id):$current['bearer'];
            if($sealed==='')throw new \RuntimeException('Enter a bearer token.');
            $this->store->query('UPDATE scheduled_jobs SET name=?,url=?,method=?,interval_seconds=?,enabled=?,updated=?,bearer=? WHERE id=?',[$name,$url,$method,$interval,$enabled?1:0,$now,$sealed,$id]);
        }else{
            if($bearer==='')throw new \RuntimeException('Enter a bearer token.');
            $this->store->query("INSERT INTO scheduled_jobs(name,url,method,interval_seconds,enabled,created,updated,bearer) VALUES(?,?,?,?,?,?,?,'')",[$name,$url,$method,$interval,$enabled?1:0,$now,$now]);
            $id=(int)$this->store->db->lastInsertId();$sealed=$box->seal($bearer,'scheduled:'.$id);$this->store->query('UPDATE scheduled_jobs SET bearer=? WHERE id=?',[$sealed,$id]);
        }
        return $id;
    }
    public function delete(int $id):void {
        if(!$this->store->query('SELECT id FROM scheduled_jobs WHERE id=?',[$id])->fetch())throw new \RuntimeException('Scheduled request was not found.');
        $this->store->query('DELETE FROM scheduled_jobs WHERE id=?',[$id]);$this->store->query('DELETE FROM kv WHERE key=?',['scheduled:'.$id]);
    }
    public function due():?array {
        $due=[];foreach($this->store->query('SELECT * FROM scheduled_jobs WHERE enabled=1')->fetchAll() as $row){$state=$this->store->get('scheduled:'.$row['id'],[]);if(time()-(int)($state['attempted']??0)>=(int)$row['interval_seconds']){$row['_attempted']=(int)($state['attempted']??0);$due[]=$row;}}
        usort($due,fn($a,$b)=>$a['_attempted']<=>$b['_attempted']);return $due[0]??null;
    }
    public function execute(array $row):array {
        $id=(int)$row['id'];$state=['attempted'=>time(),'status'=>'running','message'=>'Request sent'];$this->store->set('scheduled:'.$id,$state);
        try{
            $token=(new SecretBox($this->config))->open($row['bearer'],'scheduled:'.$id);
            if($this->transport)$status=($this->transport)($row['method'],$row['url'],$token);
            else{
                $ch=curl_init($row['url']);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_WRITEFUNCTION=>static fn($ch,$data)=>strlen($data),CURLOPT_CUSTOMREQUEST=>$row['method'],CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>Budget::remaining(8),CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'Enoch maintenance scheduler',CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json']]);
                if($row['method']==='POST')curl_setopt($ch,CURLOPT_POSTFIELDS,'');
                if(curl_exec($ch)===false)throw new \RuntimeException('Request timed out or failed.');$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            }
            if($status<200||$status>=300)throw new \RuntimeException('Remote server returned HTTP '.$status.'.');
            $state['status']='ok';$state['message']='HTTP '.$status;$state['completed']=time();
        }catch(\Throwable $e){$state['status']='failed';$state['message']=$e->getMessage();}
        $this->store->set('scheduled:'.$id,$state);$this->store->audit('scheduler','Scheduled request · '.$row['name'],$state['message']);return ['managed'=>$id,'status'=>$state['status']];
    }
    private static function validateUrl(string $url):void {
        if(strlen($url)>2048||!filter_var($url,FILTER_VALIDATE_URL))throw new \RuntimeException('Enter a valid HTTPS URL.');
        $parts=parse_url($url);$host=strtolower((string)($parts['host']??''));
        if(($parts['scheme']??'')!=='https'||$host===''||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))throw new \RuntimeException('Scheduled requests require a plain HTTPS URL.');
        if(filter_var($host,FILTER_VALIDATE_IP)||!str_contains($host,'.')||str_ends_with($host,'.local'))throw new \RuntimeException('Use a public HTTPS hostname.');
    }
}
