<?php
declare(strict_types=1);
namespace Enoch;

// Values are encrypted in SQLite. The independent key is a private 0600 runtime file.
final class Vault {
    public function __construct(private Config $config,private Store $store) {}
    private function key():string {
        $path=$this->config->dataDir.'/credentials.key';
        if(!is_file($path)){
            $key=random_bytes(32);$f=@fopen($path,'x');
            if($f){chmod($path,0600);fwrite($f,$key);fclose($f);}
        }
        $key=file_get_contents($path);
        if(strlen($key)!==32)throw new \RuntimeException('Credential storage key is unavailable.');
        return $key;
    }
    public function read(string $service):array {
        if(!isset($this->config->services[$service]))throw new \RuntimeException('Unknown service.');
        $stored=$this->store->get('credentials:'.$service);
        if(!$stored)return [];
        $plain=openssl_decrypt(base64_decode($stored['data'],true),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,base64_decode($stored['iv'],true),base64_decode($stored['tag'],true),$service);
        if($plain===false)throw new \RuntimeException('Credential storage cannot be decrypted.');
        return json_decode($plain,true,32,JSON_THROW_ON_ERROR);
    }
    public function save(string $service,array $values):void {
        $fields=$this->config->services[$service]['credential_fields']??throw new \RuntimeException('Unknown service.');
        if(array_diff(array_keys($values),array_keys($fields)))throw new \RuntimeException('Unknown credential field.');
        foreach($values as $value)if(!is_string($value)||strlen($value)>4096)throw new \RuntimeException('Invalid credential value.');
        $iv=random_bytes(12);$tag='';
        $cipher=openssl_encrypt(json_encode($values,JSON_THROW_ON_ERROR),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$iv,$tag,$service);
        if($cipher===false)throw new \RuntimeException('Credential storage failed.');
        $this->store->set('credentials:'.$service,['iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'data'=>base64_encode($cipher)]);
    }
}
