<?php
declare(strict_types=1);
namespace Enoch;

// Values are encrypted in SQLite. The independent key is a private 0600 runtime file.
final class Vault {
    public function __construct(private Config $config,private Store $store) {}
    public function read(string $service):array {
        if(!isset($this->config->services[$service]))throw new \RuntimeException('Unknown service.');
        $stored=$this->store->get('credentials:'.$service);
        if(!$stored)return [];
        // Read the original format as well as the shared SecretBox format.
        if(isset($stored['sealed']))$plain=(new SecretBox($this->config))->open($stored['sealed'],'credentials:'.$service);
        else{
            $path=$this->config->dataDir.'/credentials.key';$key=file_get_contents($path);
            $plain=openssl_decrypt(base64_decode($stored['data'],true),'aes-256-gcm',$key,OPENSSL_RAW_DATA,base64_decode($stored['iv'],true),base64_decode($stored['tag'],true),$service);
            if($plain===false)throw new \RuntimeException('Credential storage cannot be decrypted.');
        }
        return json_decode($plain,true,32,JSON_THROW_ON_ERROR);
    }
    public function save(string $service,array $values):void {
        $fields=$this->config->services[$service]['credential_fields']??throw new \RuntimeException('Unknown service.');
        if(array_diff(array_keys($values),array_keys($fields)))throw new \RuntimeException('Unknown credential field.');
        foreach($values as $value)if(!is_string($value)||strlen($value)>4096)throw new \RuntimeException('Invalid credential value.');
        $sealed=(new SecretBox($this->config))->seal(json_encode($values,JSON_THROW_ON_ERROR),'credentials:'.$service);
        $this->store->set('credentials:'.$service,['sealed'=>$sealed]);
    }
}
