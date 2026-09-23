<?php
declare(strict_types=1);
require is_file(__DIR__.'/_enoch/app/bootstrap.php') ? __DIR__.'/_enoch/app/bootstrap.php' : dirname(__DIR__).'/app/bootstrap.php';
use Enoch\Web;
Web::headers();
try{
    $auth->session();$user=$auth->user();if(!$user)Web::json(['error'=>'Please sign in again.'],401);
    $action=$_GET['action']??'status';
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if($config->get('APP_READ_ONLY')==='1')Web::json(['error'=>'This preview is read-only. Use the live portal to make changes.'],403);
        $input=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
        $auth->csrf($_SERVER['HTTP_X_CSRF_TOKEN']??'');
        if($action==='job'){
            $auth->requireRole('operator');$id=$engine->enqueue((string)($input['service']??''),(string)($input['operation']??''),$user['name']);Web::json(['id'=>$id],202);
        }
        if($action==='tick'){session_write_close();Web::json($engine->tick());}
        if($action==='pause'){$auth->requireRole('admin');$engine->abandon((string)($input['id']??''),$user['name']);Web::json(['ok'=>true]);}
        if($action==='user'){
            $auth->requireRole('admin');$auth->addUser((string)($input['name']??''),(string)($input['password']??''),(string)($input['role']??''));$store->audit($user['name'],'Created account',(string)$input['name']);Web::json(['ok'=>true]);
        }
        if($action==='disable-user'){
            $auth->requireRole('admin');$id=(int)($input['id']??0);
            if($id===$user['id'])throw new RuntimeException('You cannot disable your own account.');
            $store->query('UPDATE users SET active=0,version=version+1 WHERE id=?',[$id]);$store->audit($user['name'],'Disabled account',(string)$id);Web::json(['ok'=>true]);
        }
        if($action==='enable-user'){
            $auth->requireRole('admin');$id=(int)($input['id']??0);
            $store->query('UPDATE users SET active=1,version=version+1 WHERE id=?',[$id]);$store->audit($user['name'],'Enabled account',(string)$id);Web::json(['ok'=>true]);
        }
        if($action==='password'){
            $row=$store->query('SELECT password FROM users WHERE id=?',[$user['id']])->fetch();
            if(!password_verify((string)($input['current']??''),$row['password']))throw new RuntimeException('Current password is incorrect.');
            $pw=(string)($input['password']??'');if(strlen($pw)<12||strlen($pw)>72)throw new RuntimeException('Use a password of 12–72 bytes.');
            $store->query('UPDATE users SET password=?,version=version+1 WHERE id=?',[password_hash($pw,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_DEFAULT),$user['id']]);$_SESSION['version']++;session_regenerate_id(true);$store->audit($user['name'],'Changed password','');Web::json(['ok'=>true]);
        }
        Web::json(['error'=>'Unknown action.'],404);
    }
    if($_SERVER['REQUEST_METHOD']!=='GET')Web::json(['error'=>'Method not allowed.'],405);
    session_write_close();
    if($action!=='status')Web::json(['error'=>'Unknown action.'],404);
    $dashboard=$engine->dashboard();
    $jobs=$store->query('SELECT id,service,operation,status,phase,created,updated,actor,message FROM jobs ORDER BY created DESC LIMIT 30')->fetchAll();
    $tasks=[];foreach($config->tasks as $id=>$t)$tasks[]=['id'=>$id,'title'=>$t['title'],'description'=>$t['description'],'interval'=>max(60,(int)$config->get($t['interval_env'],(string)$t['interval'])),'state'=>$store->get('task:'.$id),'configured'=>$config->get($t['key_env'])!==''];
    Web::json($dashboard+['jobs'=>$jobs,'tasks'=>$tasks,'cron_seen'=>$store->get('cron_seen'),'worker_seen'=>$store->get('worker_seen'),'audit'=>$store->query('SELECT time,actor,event,detail FROM audit ORDER BY id DESC LIMIT 60')->fetchAll(),'users'=>$user['role']==='admin'?$store->query('SELECT id,name,role,active FROM users ORDER BY name')->fetchAll():[]]);
}catch(Throwable $e){Web::json(['error'=>$e instanceof PDOException?'The private database is busy or unavailable. Try again.':$e->getMessage()],400);}
