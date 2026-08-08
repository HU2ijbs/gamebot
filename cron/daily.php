<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/Telegram.php';
require_once __DIR__ . '/../includes/Settings.php';

$pdo = db();

$gold = (int) Settings::get('daily_gift_gold', '0');
$oil  = (int) Settings::get('daily_gift_oil', '0');

if ($gold <= 0 && $oil <= 0) {
    echo "No daily gift configured (use /admin_setdailygift in the bot). Skipping.\n";
    exit;
}

$users = $pdo->query("SELECT id, telegram_id FROM users WHERE country_name IS NOT NULL")->fetchAll();

$pdo->prepare("UPDATE users SET gold = gold + ?, oil = oil + ? WHERE country_name IS NOT NULL")->execute([$gold, $oil]);

$sent = 0;
foreach ($users as $u) {
    Telegram::sendMessage($u['telegram_id'], "🎁 <b>هدیه روزانه دریافت شد!</b>\n\n💰 +" . number_format($gold) . " طلا\n🛢️ +" . number_format($oil) . " نفت");
    $sent++;
    usleep(50000); 
}

echo "Gave daily gift ({$gold} gold / {$oil} oil) to {$sent} users at " . date('Y-m-d H:i:s') . "\n";
