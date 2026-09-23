<?php
declare(strict_types=1);
namespace Enoch;

final class Activity {
    public static function token(Config $config,string $service):string {
        if(!isset($config->services[$service]))throw new \RuntimeException('Unknown service.');
        $key=$config->get('CRON_KEY');if(strlen($key)<32)throw new \RuntimeException('Cron key is missing.');
        return hash_hmac('sha256','enoch-activity:'.$service,$key);
    }
    public static function record(Config $config,Store $store,string $service,string $given):void {
        $expected=self::token($config,$service);
        if(strlen($given)!==strlen($expected)||!hash_equals($expected,$given))throw new PermissionDenied('Unauthorized');
        $previous=$store->get('activity:'.$service,[]);
        $store->set('activity:'.$service,['last_seen'=>time(),'count'=>(int)($previous['count']??0)+1]);
    }
}
