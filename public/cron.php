<?php
declare(strict_types=1);
// This endpoint records receipt BEFORE work. curl is never a long-running worker.
require is_file(__DIR__.'/_enoch/app/bootstrap.php') ? __DIR__.'/_enoch/app/bootstrap.php' : dirname(__DIR__).'/app/bootstrap.php';
Enoch\Web::headers();
$expected=$config->get('CRON_KEY');
$given=preg_replace('/^Bearer /','',$_SERVER['HTTP_AUTHORIZATION']??'');
if(strlen($expected)<32||!hash_equals($expected,$given))Enoch\Web::json(['error'=>'Unauthorized'],401);
if($_SERVER['REQUEST_METHOD']==='GET'){
    $tasks=[];foreach($config->tasks as $id=>$task)$tasks[$id]=$store->get('task:'.$id);
    Enoch\Web::json(['last_received'=>$store->get('cron_seen'),'last_worker'=>$store->get('worker_seen'),'tasks'=>$tasks,'jobs'=>$store->query('SELECT service,operation,status,phase,message,updated FROM jobs ORDER BY created DESC LIMIT 15')->fetchAll()]);
}
if($_SERVER['REQUEST_METHOD']!=='POST')Enoch\Web::json(['error'=>'Method not allowed'],405);
ignore_user_abort(true);
$store->set('cron_seen',time());
$body=json_encode(['accepted'=>true,'time'=>gmdate(DATE_ATOM)]);
header('Content-Type: application/json');header('Content-Length: '.strlen($body));header('Connection: close');
while(ob_get_level()>0)ob_end_clean();echo $body;
if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();else flush();
// One short unit of work only. Persisted states are continued by the next tick.
// The supplied caller has --max-time 5 as an independent hard bound.
try{
    $scheduler=new Enoch\Scheduler($config,$store);
    $result=$scheduler->runDue();
    if(isset($result['due'])&&$result['due']===false)$engine->tick();
}catch(Throwable $e){$store->audit('scheduler','Tick failed','Inspect scheduler configuration and service activity.');}
