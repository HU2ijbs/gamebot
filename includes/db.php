<?php
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $port = defined('DB_PORT') ? DB_PORT : '3306';
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => (defined('DB_PERSISTENT') && DB_PERSISTENT && php_sapi_name() !== 'cli'),
        ]);
    }
    return $pdo;
}
