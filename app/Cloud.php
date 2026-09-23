<?php
declare(strict_types=1);
namespace Enoch;

class Cloud {
    public function __construct(private string $token) {}
    public function request(string $method,string $path,?array $body=null): array {
        if ($this->token==='') throw new \RuntimeException('Hetzner token is missing.');
        $ch=curl_init('https://api.hetzner.cloud/v1'.$path);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>min(4,Budget::remaining(12)),CURLOPT_TIMEOUT=>Budget::remaining(12),CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->token,'Content-Type: application/json']]);
        if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));
        $raw=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        if($raw===false)throw new \RuntimeException('Hetzner request timed out or failed. The result must be checked before retrying.');
        // Successful DELETE requests commonly return 204 with an empty body.
        // That is a completed mutation, not malformed JSON.
        if($status===204&&$raw==='')return [];
        $data=json_decode($raw,true);
        if($status===404&&$method==='GET')return ['not_found'=>true];
        if($status<200||$status>=300){
            $message='Hetzner returned HTTP '.$status.' ('.preg_replace('/[^a-z0-9_]/i','',(string)($data['error']['code']??'request_failed')).').';
            if($status>=400&&$status<500)throw new CloudRejected($message);
            throw new \RuntimeException($message);
        }
        if(!is_array($data))throw new \RuntimeException('Hetzner returned an invalid response.');
        return $data;
    }
    public function all(string $resource,array $query=[]): array {
        $items=[];
        for($page=1;$page<=100;$page++) {
            $d=$this->request('GET','/'.$resource.'?'.http_build_query($query+['per_page'=>50,'page'=>$page]));
            if(!isset($d[$resource]))throw new \RuntimeException('Incomplete Hetzner resource list.');
            array_push($items,...$d[$resource]);
            if(empty($d['meta']['pagination']['next_page']))return $items;
        }
        throw new \RuntimeException('Too many Hetzner resource pages.');
    }
    public function server(array $cfg,?array $servers=null): ?array {
        $matches=[];
        foreach($servers??$this->all('servers') as $s) {
            $v4=($s['public_net']['ipv4']['id']??null)===$cfg['ipv4'];
            $v6=($s['public_net']['ipv6']['id']??null)===$cfg['ipv6'];
            $named=in_array($s['name'],[$cfg['name'],...$cfg['aliases']],true);
            if($v4||$v6||$named) {
                if(!$v4||!$v6)throw new \RuntimeException('Server identity mismatch. Persistent IPs do not match; no changes made.');
                $matches[]=$s;
            }
        }
        if(count($matches)>1)throw new \RuntimeException('Ambiguous server identity.');
        return $matches[0]??null;
    }
    public function ips(array $cfg,?array $server): void {
        foreach(['ipv4','ipv6'] as $type) {
            $ip=$this->request('GET','/primary_ips/'.$cfg[$type])['primary_ip']??null;
            if(!$ip||$ip['auto_delete']||($ip['assignee_id']??null)!==($server['id']??null)||($ip['location']['name']??$ip['datacenter']['location']['name']??null)!==$cfg['location'])throw new \RuntimeException('Persistent IP assignment, location or retention is unsafe.');
        }
    }
    public function images(array $cfg,array $extra=[]): array {
        $labels=$cfg['labels']+$extra; $selector=implode(',',array_map(fn($k,$v)=>$k.'='.$v,array_keys($labels),$labels));
        $all=$this->all('images',['type'=>'snapshot','label_selector'=>$selector]);
        usort($all,fn($a,$b)=>strcmp($b['created'],$a['created'])); return $all;
    }
    public function imageValid(array $image,array $cfg,?int $serverId=null): void {
        if(($image['status']??'')!=='available'||($image['type']??'')!=='snapshot')throw new \RuntimeException('Snapshot is not available. The server has been retained.');
        if($serverId===null&&($image['id']??null)===($cfg['initial_image']??-1))return;
        foreach($cfg['labels'] as $k=>$v)if(($image['labels'][$k]??null)!==$v)throw new \RuntimeException('Snapshot ownership does not match.');
        if($serverId!==null&&($image['created_from']['id']??null)!==$serverId)throw new \RuntimeException('Snapshot was not created from this server.');
    }
}
