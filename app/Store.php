<?php
declare(strict_types=1);
namespace Enoch;
use PDO;

final class Store {
    public readonly PDO $db;
    public function __construct(public readonly string $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new \RuntimeException('Cannot create private data directory.');
        $this->db = new PDO('sqlite:' . $dir . '/enoch.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        chmod($dir . '/enoch.sqlite', 0600);
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('PRAGMA foreign_keys=ON');
        $this->db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE COLLATE NOCASE, password TEXT NOT NULL, role TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, version INTEGER NOT NULL DEFAULT 1, created INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS jobs (id TEXT PRIMARY KEY, service TEXT NOT NULL, operation TEXT NOT NULL, status TEXT NOT NULL, phase TEXT NOT NULL, phase_since INTEGER NOT NULL, created INTEGER NOT NULL, updated INTEGER NOT NULL, actor TEXT NOT NULL, message TEXT NOT NULL, data TEXT NOT NULL DEFAULT '{}');
        CREATE UNIQUE INDEX IF NOT EXISTS one_job_per_service ON jobs(service) WHERE status='active';
        CREATE TABLE IF NOT EXISTS audit (id INTEGER PRIMARY KEY, time INTEGER NOT NULL, actor TEXT NOT NULL, event TEXT NOT NULL, detail TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS kv (key TEXT PRIMARY KEY, value TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS attempts (key TEXT NOT NULL, time INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS attempts_time ON attempts(time);");
    }
    public function migrateAccess(array $services): void {
        if($this->get('access_schema',0)>=2)return;
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if($this->get('access_schema',0)<1){
                $columns=array_column($this->query('PRAGMA table_info(users)')->fetchAll(),'name');
                if(!in_array('ui_mode',$columns,true))$this->db->exec("ALTER TABLE users ADD COLUMN ui_mode TEXT NOT NULL DEFAULT 'technical'");
                $this->db->exec('CREATE TABLE IF NOT EXISTS service_permissions (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, service TEXT NOT NULL, can_start INTEGER NOT NULL DEFAULT 0, can_stop INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(user_id,service))');
                // Existing members retain exactly their previous rights on the current catalog.
                // Future accounts and future servers do not receive automatic grants.
                foreach($this->query("SELECT id,role FROM users WHERE role!='admin'")->fetchAll() as $user){
                    foreach($services as $service)$this->query('INSERT OR IGNORE INTO service_permissions(user_id,service,can_start,can_stop) VALUES(?,?,?,?)',[$user['id'],$service,(int)($user['role']==='operator'),(int)($user['role']==='operator')]);
                }
                $this->set('access_schema',1);
            }
            $columns=array_column($this->query('PRAGMA table_info(service_permissions)')->fetchAll(),'name');
            foreach(['can_advanced','can_credentials'] as $column)if(!in_array($column,$columns,true))$this->db->exec('ALTER TABLE service_permissions ADD COLUMN '.$column.' INTEGER NOT NULL DEFAULT 0');
            $this->set('access_schema',2);
            $this->db->exec('COMMIT');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    public function query(string $sql, array $args = []): \PDOStatement { $q=$this->db->prepare($sql); $q->execute($args); return $q; }
    public function get(string $key, mixed $default = null): mixed { $v=$this->query('SELECT value FROM kv WHERE key=?',[$key])->fetchColumn(); return $v===false ? $default : json_decode($v,true,512,JSON_THROW_ON_ERROR); }
    public function set(string $key, mixed $value): void { $this->query('INSERT INTO kv(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',[$key,json_encode($value,JSON_THROW_ON_ERROR)]); }
    public function audit(string $actor,string $event,string $detail): void { $this->query('INSERT INTO audit(time,actor,event,detail) VALUES(?,?,?,?)',[time(),$actor,$event,$detail]); }
    public function locked(callable $fn): mixed {
        $handle=fopen($this->dir.'/worker.lock','c');
        if (!$handle || !flock($handle,LOCK_EX|LOCK_NB)) { if($handle)fclose($handle); return null; }
        try { return $fn(); } finally { flock($handle,LOCK_UN); fclose($handle); }
    }
}
