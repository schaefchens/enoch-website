<?php
declare(strict_types=1);
namespace Enoch;
final class Web {
    public static function headers():void {
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
        header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('Cache-Control: no-store');header('X-Frame-Options: DENY');
    }
    public static function json(array $data,int $status=200):never {http_response_code($status);header('Content-Type: application/json');echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);exit;}
    public static function escape(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
