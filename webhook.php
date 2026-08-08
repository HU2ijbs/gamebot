<?php

require_once __DIR__ . '/bot.php';

$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (is_array($update)) {
    try {
        handleUpdate($update);
    } catch (Throwable $e) {
        error_log('Bot error: ' . $e->getMessage());
    }
}

http_response_code(200);
echo 'OK';
