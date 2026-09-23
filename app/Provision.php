<?php
declare(strict_types=1);
namespace Enoch;
final class Provision {
    public static function cloudInit(Config $config,array $service,string $serviceId):string {
        $data=['hostname'=>$service['name'],'fqdn'=>$service['name'],'manage_etc_hosts'=>true,'preserve_hostname'=>false,'ssh_deletekeys'=>false];
        $data['write_files']=[];$data['runcmd']=[];
        if($service['prepare']==='aio'){
            $path=$config->dataDir.'/ssh/enoch.pub';
            if(!is_readable($path))throw new \RuntimeException('The dedicated Nextcloud public key is missing. Run the SSH setup first.');
            $key=trim(file_get_contents($path));
            if(!preg_match('/^ssh-ed25519 [A-Za-z0-9+\/=]+(?: .*)?$/',$key))throw new \RuntimeException('Invalid portal public key.');
            $script=file_get_contents($config->root.'/infrastructure/server/enoch-agent-install.sh');
            $data['write_files']=[
                ['path'=>'/root/enoch-agent-install.sh','permissions'=>'0700','content'=>$script],
                ['path'=>'/root/enoch.pub','permissions'=>'0600','content'=>$key."\n"],
            ];
            $data['runcmd'][]=['bash','/root/enoch-agent-install.sh','/root/enoch.pub'];
        }
        if((int)($service['idle_timeout']??0)>0){
            $activity=file_get_contents($config->root.'/infrastructure/server/enoch-activity-heartbeat');if($activity===false)throw new \RuntimeException('Activity heartbeat agent is missing.');
            $url=rtrim($config->get('APP_URL'),'/').'/activity.php';if(!str_starts_with($url,'https://'))throw new \RuntimeException('Activity heartbeat requires an HTTPS APP_URL.');
            $env='ENOCH_ACTIVITY_URL='.escapeshellarg($url)."\n".'ENOCH_ACTIVITY_SERVICE='.escapeshellarg($serviceId)."\n".'ENOCH_ACTIVITY_KIND='.escapeshellarg((string)$service['activity_kind'])."\n".'ENOCH_ACTIVITY_TOKEN='.escapeshellarg(Activity::token($config,$serviceId))."\n";
            array_push($data['write_files'],
                ['path'=>'/usr/local/sbin/enoch-activity-heartbeat','permissions'=>'0755','content'=>$activity],
                ['path'=>'/etc/enoch/activity.env','permissions'=>'0600','content'=>$env],
                ['path'=>'/etc/systemd/system/enoch-activity-heartbeat.service','permissions'=>'0644','content'=>"[Unit]\nDescription=Report real service use to Enoch\nAfter=network-online.target docker.service\nWants=network-online.target\n[Service]\nType=oneshot\nExecStart=/usr/local/sbin/enoch-activity-heartbeat\n"],
                ['path'=>'/etc/systemd/system/enoch-activity-heartbeat.timer','permissions'=>'0644','content'=>"[Unit]\nDescription=Check service activity every minute\n[Timer]\nOnBootSec=2min\nOnUnitActiveSec=1min\nAccuracySec=10s\nPersistent=false\n[Install]\nWantedBy=timers.target\n"]
            );
            $data['runcmd'][]=['systemctl','daemon-reload'];$data['runcmd'][]=['systemctl','enable','--now','enoch-activity-heartbeat.timer'];
        }
        // JSON is valid YAML; this avoids interpolating key/script contents into YAML syntax.
        return "#cloud-config\n".json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    }
}
