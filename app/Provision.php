<?php
declare(strict_types=1);
namespace Enoch;
final class Provision {
    public static function cloudInit(Config $config,array $service):string {
        $data=['hostname'=>$service['name'],'fqdn'=>$service['name'],'manage_etc_hosts'=>true,'preserve_hostname'=>false,'ssh_deletekeys'=>false];
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
            $data['runcmd']=[['bash','/root/enoch-agent-install.sh','/root/enoch.pub']];
        }
        // JSON is valid YAML; this avoids interpolating key/script contents into YAML syntax.
        return "#cloud-config\n".json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    }
}
