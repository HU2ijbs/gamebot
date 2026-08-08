<?php
// cron-runner.php

echo "Cron runner started at " . date('Y-m-d H:i:s') . "\n";

$lastHourly = 0;
$lastDaily  = 0;

while (true) {
    $now = time();

    // هر ساعت یک‌بار
    if ($now - $lastHourly >= 3600) {
        echo "[" . date('Y-m-d H:i:s') . "] Running hourly.php ...\n";
        passthru('php cron/hourly.php');
        $lastHourly = $now;
    }

    // هر روز یک‌بار (ساعت ۰۰:۰۰ به وقت سرور)
    if (date('H:i') === '00:00' && ($now - $lastDaily > 86000)) {
        echo "[" . date('Y-m-d H:i:s') . "] Running daily.php ...\n";
        passthru('php cron/daily.php');
        $lastDaily = $now;
    }

    sleep(30); // هر ۳۰ ثانیه چک کنه
}
