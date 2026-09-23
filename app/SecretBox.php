<?php
declare(strict_types=1);
namespace Enoch;

// Small authenticated-encryption wrapper shared by credentials and scheduled requests.
final class SecretBox {
    public function __construct(private Config $config) {}
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
    public function seal(string $plain,string $context):string {
        $iv=random_bytes(12);$tag='';
        $cipher=openssl_encrypt($plain,'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$iv,$tag,$context);
        if($cipher===false)throw new \RuntimeException('Secret storage failed.');
        return base64_encode(json_encode(['iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'data'=>base64_encode($cipher)],JSON_THROW_ON_ERROR));
    }
    public function open(string $sealed,string $context):string {
        $data=json_decode(base64_decode($sealed,true),true,8,JSON_THROW_ON_ERROR);
        $plain=openssl_decrypt(base64_decode($data['data'],true),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,base64_decode($data['iv'],true),base64_decode($data['tag'],true),$context);
        if($plain===false)throw new \RuntimeException('Secret storage cannot be decrypted.');
        return $plain;
    }
}
