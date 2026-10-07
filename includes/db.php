<?php

require_once __DIR__ . '/csrf.php';

function db_connect(): mysqli
{
    $localConfigPath = dirname(__DIR__) . '/config.local.php';
    if (file_exists($localConfigPath)) {
        $config = require $localConfigPath;
    } else {
        $environmentValue = static function (string $name, string $default): string {
            $value = getenv($name);
            return $value === false ? $default : $value;
        };
        $config = [
            'host' => $environmentValue('SRMS_DB_HOST', 'localhost'),
            'user' => $environmentValue('SRMS_DB_USER', ''),
            'password' => $environmentValue('SRMS_DB_PASSWORD', ''),
            'database' => $environmentValue('SRMS_DB_NAME', 'srms_db'),
            'port' => (int) $environmentValue('SRMS_DB_PORT', '3306'),
        ];
    }

    if (!is_array($config)) {
        throw new RuntimeException('Database configuration must return an array.');
    }

    foreach (['host', 'user', 'password', 'database'] as $key) {
        if (!array_key_exists($key, $config) || !is_string($config[$key])) {
            throw new RuntimeException("Database configuration is missing a valid '$key' value.");
        }
    }

    if ($config['user'] === '') {
        throw new RuntimeException('Set database credentials in config.local.php or SRMS_DB_USER.');
    }

    $port = $config['port'] ?? 3306;
    if (!is_int($port) || $port < 1 || $port > 65535) {
        throw new RuntimeException('Database port must be an integer between 1 and 65535.');
    }

    $connection = new mysqli(
        $config['host'],
        $config['user'],
        $config['password'],
        $config['database'],
        $port
    );
    $connection->set_charset('utf8mb4');

    return $connection;
}
