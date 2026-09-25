<?php
declare(strict_types=1);
$path=is_file(__DIR__.'/_enoch/config/version.php')?__DIR__.'/_enoch/config/version.php':dirname(__DIR__).'/config/version.php';
$version=require $path;
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
echo json_encode(['version'=>$version],JSON_THROW_ON_ERROR);
