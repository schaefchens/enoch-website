<?php
declare(strict_types=1);
umask(0077);
require dirname(__DIR__).'/vendor/autoload.php';
Enoch\Budget::start();
$config=new Enoch\Config(dirname(__DIR__));
$store=new Enoch\Store($config->dataDir);
$cloud=new Enoch\Cloud($config->get('HETZNER_CLOUD_TOKEN'));
$host=new Enoch\Host($config);
$engine=new Enoch\Engine($config,$store,$cloud,$host);
$auth=new Enoch\Auth($config,$store);
