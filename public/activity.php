<?php
declare(strict_types=1);
require is_file(__DIR__.'/_enoch/app/bootstrap.php') ? __DIR__.'/_enoch/app/bootstrap.php' : dirname(__DIR__).'/app/bootstrap.php';
use Enoch\Web;
Web::headers();
if($_SERVER['REQUEST_METHOD']!=='POST')Web::json(['error'=>'Method not allowed'],405);
try{
    $service=(string)($_GET['service']??'');
    $given=preg_replace('/^Bearer\s+/i','',$_SERVER['HTTP_AUTHORIZATION']??'');
    Enoch\Activity::record($config,$store,$service,$given);
    Web::json(['accepted'=>true,'time'=>gmdate(DATE_ATOM)]);
}catch(Throwable $e){Web::json(['error'=>'Unauthorized'],401);}
