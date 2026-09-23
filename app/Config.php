<?php
declare(strict_types=1);
namespace Enoch;

final class Config {
    public readonly array $env;
    public readonly array $services;
    public readonly array $tasks;
    public readonly string $dataDir;
    public function __construct(public readonly string $root) {
        $this->env = self::readEnv($root . '/.env');
        $this->services = require $root . '/config/services.php';
        $this->tasks = require $root . '/config/tasks.php';
        $this->dataDir = $this->get('ENOCH_DATA_DIR') ?: $root . '/var';
    }
    public static function readEnv(string $path): array {
        $values = [];
        foreach (is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [] as $line) {
            if (!preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=(.*)$/', $line, $m)) continue;
            $value = trim($m[2]);
            if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], true) && substr($value, -1) === $value[0]) $value = substr($value, 1, -1);
            $values[$m[1]] = $value;
        }
        return $values;
    }
    public function get(string $name, string $default = ''): string {
        $value = getenv($name);
        return $value !== false ? $value : ($this->env[$name] ?? $default);
    }
}
