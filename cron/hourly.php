<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/Telegram.php';
require_once __DIR__ . '/../includes/Settings.php';

$pdo = db();

$mult = 1.0;
$expires = Settings::get('economy_multiplier_expires', '');
$hasActiveEvent = $expires && strtotime($expires) > time();
if ($hasActiveEvent) {
    $mult = (float) Settings::get('economy_multiplier', '1.0');
} elseif ($expires !== '' && Settings::get('economy_multiplier', '1.0') !== '1.0') {
    Settings::set('economy_multiplier', '1.0');
    Settings::set('economy_multiplier_expires', '');
    echo "Economic event expired — multiplier reset to 1.0.\n";
}

$companies = $pdo->query("SELECT user_id, type, level, income_per_hour FROM companies")->fetchAll();
$gains = [];
$resourceMap = [
    'factory' => 'gold', 'farm' => 'gold', 'bank' => 'gold', 'mall' => 'gold', 'stock_exchange' => 'gold',
    'techlab' => 'tech', 'research_center' => 'tech',
    'oil_rig' => 'oil', 'refinery' => 'oil',
];
foreach ($companies as $c) {
    $res = $resourceMap[$c['type']] ?? 'gold';
    $amount = (int) round($c['income_per_hour'] * $c['level'] * $mult);
    $gains[$c['user_id']][$res] = ($gains[$c['user_id']][$res] ?? 0) + $amount;
}
foreach ($gains as $userId => $res) {
    $gold = $res['gold'] ?? 0; $oil = $res['oil'] ?? 0; $tech = $res['tech'] ?? 0;
    $pdo->prepare("UPDATE users SET gold=gold+?, oil=oil+?, tech=tech+?, last_income_collect=NOW() WHERE id=?")
        ->execute([$gold, $oil, $tech, $userId]);
}
echo "Paid income to " . count($gains) . " users (multiplier: {$mult}).\n";

if (!$hasActiveEvent && mt_rand(1, 100) <= 15) {
    $direction = mt_rand(0, 1) ? 'up' : 'down';
    $percent = mt_rand(10, 25);
    $hours = mt_rand(6, 18);
    $newMult = $direction === 'up' ? (1 + $percent / 100) : (1 - $percent / 100);

    Settings::set('economy_multiplier', (string) $newMult);
    Settings::set('economy_multiplier_expires', date('Y-m-d H:i:s', time() + $hours * 3600));

    $title = $direction === 'up' ? '📈 رونق اقتصادی جهانی' : '📉 رکود اقتصادی جهانی';
    $desc  = $direction === 'up'
        ? "درآمد اقتصادی همه کشورها به مدت {$hours} ساعت {$percent}٪ افزایش یافت!"
        : "درآمد اقتصادی همه کشورها به مدت {$hours} ساعت {$percent}٪ کاهش یافت!";

    $pdo->prepare("INSERT INTO global_events (title, description, effect_type, effect_value, expires_at) VALUES (?,?,?,?,?)")
        ->execute([$title, $desc, $direction, $newMult, date('Y-m-d H:i:s', time() + $hours * 3600)]);

    $channel = Settings::channelRef();
    if ($channel) Telegram::sendMessage($channel, "{$title}\n\n{$desc}");

    echo "Spawned economic event: {$title} ({$percent}% for {$hours}h)\n";
}

if (mt_rand(1, 100) <= 40) {
    $deals = [
        ['title' => '📦 محموله قاچاق طلا',      'resource' => 'gold', 'amount' => mt_rand(500, 3000)],
        ['title' => '🛢️ نفت تحریمی',            'resource' => 'oil',  'amount' => mt_rand(300, 1500)],
        ['title' => '💾 اسناد فناوری مسروقه',    'resource' => 'tech', 'amount' => mt_rand(20, 100)],
    ];
    $deal = $deals[array_rand($deals)];
    $price = (int) round($deal['amount'] * mt_rand(2, 4));
    $pdo->prepare("INSERT INTO black_market_deals (title, resource, amount, price_gold, risk_percent, expires_at) VALUES (?,?,?,?,?, DATE_ADD(NOW(), INTERVAL 6 HOUR))")
        ->execute([$deal['title'], $deal['resource'], $deal['amount'], $price, mt_rand(15, 40)]);
    echo "Spawned black market deal: {$deal['title']}\n";
}

$expiredCount = $pdo->prepare("UPDATE peace_treaties SET status='expired' WHERE status='active' AND expires_at <= NOW()");
$expiredCount->execute();
echo "Expired {$expiredCount->rowCount()} peace treaties.\n";

echo "Hourly cron run complete at " . date('Y-m-d H:i:s') . "\n";
