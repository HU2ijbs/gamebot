<?php
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Persistent connections avoid a fresh TCP+auth handshake to MySQL on every
            // webhook request, which is one of the biggest sources of perceived "slowness".
            PDO::ATTR_PERSISTENT         => (defined('DB_PERSISTENT') && DB_PERSISTENT && php_sapi_name() !== 'cli'),
        ]);
    }
    return $pdo;
}
