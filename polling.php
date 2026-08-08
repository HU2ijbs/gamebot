<?php

require_once __DIR__ . '/bot.php';

Telegram::call('deleteWebhook');

echo "🤖 Bot is running via long polling... (Ctrl+C to stop)\n";

$offset = 0;
while (true) {
    $updates = Telegram::call('getUpdates', [
        'offset'  => $offset,
        'timeout' => 30,
    ]);

    if (!empty($updates['result'])) {
        foreach ($updates['result'] as $update) {
            $offset = $update['update_id'] + 1;
            try {
                handleUpdate($update);
            } catch (Throwable $e) {
                error_log('Bot error: ' . $e->getMessage());
            }
        }
    }
}
