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
            $service=(string)($input['service']??'');$operation=(string)($input['operation']??'');
            $auth->access->requireOperation($user,$service,$operation);
            $options=$input['options']??[];
            if(!is_array($options))throw new RuntimeException('Invalid lifecycle options.');
            $advanced=array_diff(array_keys($options),['save_snapshot','acknowledge_discard','acknowledge_large_disk']);
            if($advanced)$auth->access->requireOperation($user,$service,'advanced');
            if(array_key_exists('save_snapshot',$options)&&$user['ui_mode']!=='technical'&&$user['role']!=='admin')$auth->access->requireOperation($user,$service,'advanced');
            $id=$engine->enqueue($service,$operation,$user['name'],$options);Web::json(['id'=>$id],202);
        }
        if(in_array($action,['credentials','save-credentials'],true)){
            $service=(string)($input['service']??'');$auth->access->requireOperation($user,$service,'credentials');
            $vault=new Enoch\Vault($config,$store);
            if($action==='save-credentials'){$auth->requireRole('admin');$vault->save($service,$input['values']??[]);$store->audit($user['name'],'Updated stored credentials',$service);Web::json(['ok'=>true]);}
            $store->audit($user['name'],'Viewed stored credentials',$service);
            Web::json(['fields'=>$config->services[$service]['credential_fields']??[],'values'=>$vault->read($service)]);
        }
        if($action==='cron-command'){
            $auth->requireRole('admin');$key=$config->get('CRON_KEY');
            if(strlen($key)<32)throw new RuntimeException('Cron key is missing.');
            $quote=static fn(string $s)=>"'".str_replace("'","'\\''",$s)."'";
            $command='/usr/bin/curl --silent --show-error --connect-timeout 2 --max-time 5 --request POST --header '.$quote('Authorization: Bearer '.$key).' '.$quote(rtrim($config->get('APP_URL'),'/').'/cron.php');
            $store->audit($user['name'],'Viewed scheduler command','');Web::json(['command'=>$command]);
        }
        if($action==='scheduled-job'){
            $auth->requireRole('admin');$jobs=new Enoch\ScheduledJobs($config,$store);$id=$jobs->save($input);
            $store->audit($user['name'],'Saved scheduled request',(string)$id);Web::json(['ok'=>true,'id'=>$id]);
        }
        if($action==='delete-scheduled-job'){
            $auth->requireRole('admin');$id=(int)($input['id']??0);(new Enoch\ScheduledJobs($config,$store))->delete($id);
            $store->audit($user['name'],'Deleted scheduled request',(string)$id);Web::json(['ok'=>true]);
        }
        if($action==='tick'){session_write_close();$engine->tick();Web::json(['ok'=>true]);}
        if($action==='pause'){$auth->requireRole('admin');$engine->abandon((string)($input['id']??''),$user['name']);Web::json(['ok'=>true]);}
        if($action==='user'){
            $auth->requireRole('admin');$auth->addUser((string)($input['name']??''),(string)($input['password']??''),(string)($input['role']??''),(string)($input['ui_mode']??'simple'),$input['permissions']??[]);$store->audit($user['name'],'Created account',(string)$input['name']);Web::json(['ok'=>true]);
        }
        if($action==='access'){
            $auth->requireRole('admin');$id=(int)($input['id']??0);
            $auth->access->updateUser($id,(string)($input['role']??''),(string)($input['ui_mode']??''),$input['permissions']??[]);
            $store->audit($user['name'],'Updated account access',(string)$id);
            if($id===$user['id'])$_SESSION['version']++;
            Web::json(['ok'=>true]);
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
    if($action==='options'){$service=(string)($_GET['service']??'');$auth->access->requireOperation($user,$service,'advanced');Web::json((new Enoch\Lifecycle($cloud))->options($config->services[$service]));}
    if($action!=='status')Web::json(['error'=>'Unknown action.'],404);
    Web::json((new Enoch\Dashboard($config,$store,$auth->access))->present($user,$engine->dashboard()));
}catch(Throwable $e){
    $message=$e instanceof PDOException?'The account database is busy. Please try again.':$e->getMessage();
    if(($user['ui_mode']??'technical')==='simple'&&$action==='tick')$message='We could not check progress. Please try again shortly.';
    Web::json(['error'=>$message],$e instanceof Enoch\PermissionDenied?403:400);
}
