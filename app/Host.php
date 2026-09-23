<?php
declare(strict_types=1);
namespace Enoch;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

class Host {
    public function __construct(private Config $config) {}
    public function command(array $server,string $command): array {
        if(!preg_match('/^(probe|(?:prepare|status) [a-f0-9]{24})$/',$command))throw new \RuntimeException('Invalid host command.');
        $path=$this->config->dataDir.'/ssh/enoch';
        $expected=$this->config->get('NEXTCLOUD_SSH_FINGERPRINT');
        if(!is_file($path)||$expected==='')throw new \RuntimeException('Nextcloud restricted SSH access has not been configured.');
        $ssh=new SSH2($server['public_net']['ipv4']['ip'],22,Budget::remaining(6));
        $ssh->setPreferredAlgorithms(['hostkey'=>['ssh-ed25519']]);
        $hostKey=$ssh->getServerPublicHostKey();
        if(!$hostKey)throw new \RuntimeException('Cannot read Nextcloud SSH host identity.');
        $actual='SHA256:'.rtrim(base64_encode(hash('sha256',base64_decode(explode(' ',$hostKey)[1]),true)),'=');
        if(!hash_equals($expected,$actual))throw new \RuntimeException('Nextcloud SSH host identity changed. Verify it before continuing.');
        $ssh->setTimeout(Budget::remaining(6));
        if(!$ssh->login('root',PublicKeyLoader::loadPrivateKey(file_get_contents($path))))throw new \RuntimeException('Restricted Nextcloud SSH login failed.');
        $ssh->setTimeout(Budget::remaining(6)); $raw=$ssh->exec($command);
        $result=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($result)||$ssh->getExitStatus()!==0)throw new \RuntimeException('Nextcloud preparation agent failed. The VM is retained.');
        return $result;
    }
    public function ready(array $cfg): bool {
        $ch=curl_init($cfg['ready_url']);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>min(3,Budget::remaining(5)),CURLOPT_TIMEOUT=>Budget::remaining(5),CURLOPT_FOLLOWLOCATION=>false]);
        $body=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$d=is_string($body)?json_decode($body,true):null;
        if($code!==200||!is_array($d))return false;
        return $cfg['ready_kind']==='nextcloud' ? !empty($d['installed'])&&empty($d['maintenance'])&&empty($d['needsDbUpgrade']) : ($d['nextcloud-spreed-signaling']??'')==='Welcome';
    }
}
