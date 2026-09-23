<?php
declare(strict_types=1);
namespace Enoch;

final class Auth {
    public readonly Access $access;
    public function __construct(private Config $config,private Store $store) {$this->access=new Access($config,$store);}
    public function session():void {
        if(session_status()===PHP_SESSION_ACTIVE)return;
        $local=$this->config->get('APP_ENV')==='local';
        if(!$local&&($_SERVER['HTTPS']??'')!=='on')throw new \RuntimeException('HTTPS is required.');
        ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
        session_name('enoch_session');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!$local,'httponly'=>true,'samesite'=>'Strict']);session_start();
        $_SESSION['csrf']??=bin2hex(random_bytes(32));
    }
    public function csrf(string $token):void {
        if(!hash_equals($_SESSION['csrf']??'', $token)||$token==='')throw new \RuntimeException('This form expired. Refresh and try again.');
    }
    public function user():?array {
        if(empty($_SESSION['uid'])||time()-($_SESSION['seen']??0)>3600)return null;
        $u=$this->store->query('SELECT id,name,role,ui_mode,active,version FROM users WHERE id=?',[$_SESSION['uid']])->fetch();
        if(!$u||!$u['active']||$u['version']!==($_SESSION['version']??null))return null;
        $_SESSION['seen']=time();return $u;
    }
    public function requireRole(string $role='viewer'):array {
        $u=$this->user();$ranks=['viewer'=>0,'operator'=>1,'admin'=>2];
        if(!$u||($ranks[$u['role']]??-1)<$ranks[$role])throw new PermissionDenied('You do not have permission for this action.');return $u;
    }
    public function login(string $name,string $password):bool {
        $name=mb_strtolower(trim($name));$ip=$_SERVER['REMOTE_ADDR']??'local';$now=time();
        $this->store->query('DELETE FROM attempts WHERE time<?',[$now-900]);
        foreach(['ip:'.$ip,'name:'.$name] as $key)if((int)$this->store->query('SELECT COUNT(*) FROM attempts WHERE key=?',[$key])->fetchColumn()>=10)throw new \RuntimeException('Too many attempts. Try again in 15 minutes.');
        $u=$this->store->query('SELECT * FROM users WHERE name=?',[$name])->fetch();
        $valid=password_verify($password,$u['password']??'$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if(!$u||!$valid||!$u['active']){foreach(['ip:'.$ip,'name:'.$name] as $key)$this->store->query('INSERT INTO attempts(key,time) VALUES(?,?)',[$key,$now]);return false;}
        session_regenerate_id(true);$_SESSION=['uid'=>$u['id'],'version'=>$u['version'],'seen'=>$now,'csrf'=>bin2hex(random_bytes(32))];
        $this->store->audit($u['name'],'Signed in','');return true;
    }
    public function addUser(string $name,string $password,string $role,string $mode='simple',array $permissions=[]):int {
        $name=mb_strtolower(trim($name));
        if(!preg_match('/^[a-z0-9][a-z0-9._@-]{2,79}$/',$name))throw new \RuntimeException('Use a username of 3–80 letters, numbers, dots, dashes or @.');
        if(strlen($password)<12||strlen($password)>72)throw new \RuntimeException('Use a password of 12–72 bytes.');
        [$mode,$rights]=$this->access->normalize($role,$mode,$permissions);
        $hash=password_hash($password,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_DEFAULT);
        $this->store->db->exec('SAVEPOINT add_account');
        try{
            $this->store->query('INSERT INTO users(name,password,role,ui_mode,created) VALUES(?,?,?,?,?)',[$name,$hash,$role,$mode,time()]);
            $id=(int)$this->store->db->lastInsertId();$this->access->replaceGrants($id,$rights);
            $this->store->db->exec('RELEASE add_account');return $id;
        }catch(\Throwable $e){$this->store->db->exec('ROLLBACK TO add_account');$this->store->db->exec('RELEASE add_account');if($e instanceof \PDOException&&str_contains($e->getMessage(),'UNIQUE'))throw new \RuntimeException('That username already exists.');throw $e;}
    }
    public function setup(string $key,string $name,string $password):void {
        $expected=$this->config->get('SETUP_KEY');
        if(strlen($expected)<32||!hash_equals($expected,$key))throw new \RuntimeException('Invalid setup key.');
        $this->store->db->exec('BEGIN IMMEDIATE');
        try{if($this->store->query('SELECT COUNT(*) FROM users')->fetchColumn()>0)throw new \RuntimeException('Setup is already complete.');$this->addUser($name,$password,'admin');$this->store->db->exec('COMMIT');}
        catch(\Throwable $e){$this->store->db->exec('ROLLBACK');throw $e;}
    }
}
