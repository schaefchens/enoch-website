<?php
declare(strict_types=1);
namespace Enoch;

final class Access {
    public function __construct(private Config $config,private Store $store) {
        $store->migrateAccess(array_keys($config->services));
    }

    public function grants(array $user): array {
        if($user['role']==='admin')return array_fill_keys(array_keys($this->config->services),['view'=>true,'start'=>true,'stop'=>true,'advanced'=>true,'credentials'=>true]);
        $grants=[];
        foreach($this->store->query('SELECT service,can_start,can_stop,can_advanced,can_credentials FROM service_permissions WHERE user_id=?',[$user['id']])->fetchAll() as $row){
            if(!isset($this->config->services[$row['service']]))continue;
            $grants[$row['service']]=['view'=>true,'start'=>$user['role']==='operator'&&(bool)$row['can_start'],'stop'=>$user['role']==='operator'&&(bool)$row['can_stop'],'advanced'=>$user['role']==='operator'&&(bool)$row['can_advanced'],'credentials'=>(bool)$row['can_credentials']];
        }
        return $grants;
    }

    public function requireOperation(array $user,string $service,string $operation): void {
        if(!in_array($operation,['view','start','stop','advanced','credentials'],true)||!isset($this->config->services[$service]))throw new \RuntimeException('Unknown service or operation.');
        if(empty($this->grants($user)[$service][$operation]))throw new PermissionDenied('You do not have permission to '.$operation.' this service. Ask an administrator for access.');
    }

    public function normalize(string $role,string $mode,array $permissions): array {
        if(!in_array($role,['admin','operator','viewer'],true))throw new \RuntimeException('Choose a valid account role.');
        if(!in_array($mode,['simple','technical'],true))throw new \RuntimeException('Choose the simple or technical view.');
        $normalized=[];
        foreach($permissions as $service=>$rights){
            if(!isset($this->config->services[$service])||!is_array($rights)||array_diff(array_keys($rights),['view','start','stop','advanced','credentials']))throw new \RuntimeException('Unknown service or permission.');
            foreach($rights as $right)if(!is_bool($right))throw new \RuntimeException('Permissions must be true or false.');
            if(empty($rights['view'])){
                if(!empty($rights['start'])||!empty($rights['stop'])||!empty($rights['advanced'])||!empty($rights['credentials']))throw new \RuntimeException('Select access to a service before allowing its operations.');
                continue;
            }
            $normalized[$service]=['start'=>$role==='operator'&&!empty($rights['start']),'stop'=>$role==='operator'&&!empty($rights['stop']),'advanced'=>$role==='operator'&&!empty($rights['advanced']),'credentials'=>!empty($rights['credentials'])];
        }
        return [$role==='admin'?'technical':$mode,$normalized];
    }

    // Call inside the same transaction as the account change.
    public function replaceGrants(int $id,array $permissions):void {
        $this->store->query('DELETE FROM service_permissions WHERE user_id=?',[$id]);
        foreach($permissions as $service=>$rights)$this->store->query('INSERT INTO service_permissions(user_id,service,can_start,can_stop,can_advanced,can_credentials) VALUES(?,?,?,?,?,?)',[$id,$service,(int)$rights['start'],(int)$rights['stop'],(int)$rights['advanced'],(int)$rights['credentials']]);
    }

    public function updateUser(int $id,string $role,string $mode,array $permissions):void {
        [$mode,$rights]=$this->normalize($role,$mode,$permissions);
        $this->store->db->exec('BEGIN IMMEDIATE');
        try{
            $user=$this->store->query('SELECT id,name,role,active FROM users WHERE id=?',[$id])->fetch();
            if(!$user)throw new \RuntimeException('This account no longer exists.');
            if($user['role']==='admin'&&$user['active']&&$role!=='admin'&&(int)$this->store->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn()<=1)throw new \RuntimeException('Keep at least one active administrator.');
            $this->store->query('UPDATE users SET role=?,ui_mode=?,version=version+1 WHERE id=?',[$role,$mode,$id]);
            $this->replaceGrants($id,$rights);
            $this->store->db->exec('COMMIT');
        }catch(\Throwable $e){$this->store->db->exec('ROLLBACK');throw $e;}
    }

    public function users():array {
        $users=$this->store->query('SELECT id,name,role,ui_mode,active FROM users ORDER BY name')->fetchAll();
        foreach($users as &$user)$user['permissions']=$this->grants($user);
        return $users;
    }
}
