<?php

class Admin
{
    public static function isAdmin(int $telegramId): bool
    {
        return in_array($telegramId, ADMIN_IDS, true);
    }

    public static function handle(string $text, int $chatId, int $telegramId): bool
    {
        if (!self::isAdmin($telegramId)) return false;

        $parts = preg_split('/\s+/', trim($text));
        $cmd = strtolower($parts[0]);

        switch ($cmd) {
            case '/admin':
                self::help($chatId); return true;

            case '/admin_stats':
                self::stats($chatId); return true;

            case '/admin_addgold':
                self::addResource($chatId, $parts, 'gold'); return true;

            case '/admin_addoil':
                self::addResource($chatId, $parts, 'oil'); return true;

            case '/admin_addmilitary':
                self::addResource($chatId, $parts, 'military'); return true;

            case '/admin_addtech':
                self::addResource($chatId, $parts, 'tech'); return true;

            case '/admin_ban':
                self::setBan($chatId, $parts, true); return true;

            case '/admin_unban':
                self::setBan($chatId, $parts, false); return true;

            case '/admin_broadcast':
                self::broadcast($chatId, trim(substr($text, strlen($cmd)))); return true;

            case '/admin_setdailygift':
                self::setDailyGift($chatId, $parts); return true;

            case '/admin_setchannel':
                self::setChannel($chatId, $parts); return true;

            case '/admin_event':
                self::triggerEvent($chatId, $parts); return true;

            case '/admin_find':
                self::findUser($chatId, $parts); return true;

            case '/admin_addweapon':
                self::addWeapon($chatId, $text); return true;

            case '/admin_testchannel':
                self::testChannel($chatId); return true;
        }
        return false;
    }

    private static function resolveTarget(string $input): ?array
    {
        $input = ltrim(trim($input), '@');
        if (ctype_digit($input)) {
            $stmt = db()->prepare("SELECT * FROM users WHERE telegram_id=?");
            $stmt->execute([$input]);
        } else {
            $stmt = db()->prepare("SELECT * FROM users WHERE country_name=? OR username=?");
            $stmt->execute([$input, $input]);
        }
        return $stmt->fetch() ?: null;
    }

    private static function help(int $chatId): void
    {
        $text = "🛠️ <b>پنل ادمین (فقط از طریق تلگرام)</b>\n\n"
              . "📊 <code>/admin_stats</code> — آمار کلی بازی\n"
              . "💰 <code>/admin_addgold آیدی/نام_کشور مقدار</code>\n"
              . "🛢️ <code>/admin_addoil آیدی/نام_کشور مقدار</code>\n"
              . "⚔️ <code>/admin_addmilitary آیدی/نام_کشور مقدار</code>\n"
              . "🔬 <code>/admin_addtech آیدی/نام_کشور مقدار</code>\n"
              . "🚫 <code>/admin_ban آیدی/نام_کشور</code>\n"
              . "✅ <code>/admin_unban آیدی/نام_کشور</code>\n"
              . "📢 <code>/admin_broadcast متن پیام</code> — ارسال به همه کاربران\n"
              . "🎁 <code>/admin_setdailygift طلا نفت</code> — هدیه خودکار روزانه به همه\n"
              . "📡 <code>/admin_setchannel @channel_username</code> — تنظیم کانال قفل/گزارش‌ها\n"
              . "📈 <code>/admin_event up|down درصد ساعت</code> — رویداد اقتصادی دستی (مثال: /admin_event down 15 12)\n"
              . "📈 <code>/admin_event clear</code> — پایان زودهنگام رویداد اقتصادی فعال\n"
              . "🔍 <code>/admin_find آیدی/نام_کشور</code> — مشاهده اطلاعات یک کاربر\n"
              . "🔫 <code>/admin_addweapon کلید|نام|حمله|دفاع|قیمت|shop_یا_blackmarket|نوع(اختیاری)</code>\n"
              . "📡 <code>/admin_testchannel</code> — ارسال پیام تست به کانال برای اطمینان از تنظیم درست\n";
        Telegram::sendMessage($chatId, $text);
    }

    private static function testChannel(int $chatId): void
    {
        $ref = Settings::channelRef();
        if (!$ref) { Telegram::sendMessage($chatId, "❌ کانالی تنظیم نشده. ابتدا /admin_setchannel @channel را بفرستید."); return; }
        $res = Telegram::sendMessage($ref, "✅ این یک پیام تست از ربات است — اگر این را در کانال می‌بینید یعنی تنظیمات درست است.");
        if ($res['ok'] ?? false) {
            Telegram::sendMessage($chatId, "✅ پیام تست با موفقیت به کانال ({$ref}) ارسال شد.");
        } else {
            $err = $res['description'] ?? 'خطای نامشخص';
            Telegram::sendMessage($chatId, "❌ ارسال پیام تست ناموفق بود.\n\nخطای تلگرام: {$err}\n\n"
                . "بررسی کنید:\n۱- ربات را در کانال «ادمین» کرده باشید (نه فقط عضو)\n۲- یوزرنیم کانال درست و بدون غلط باشد\n۳- کانال عمومی باشد یا آیدی عددی صحیح آن را با /admin_setchannel وارد کرده باشید");
        }
    }

    private static function stats(int $chatId): void
    {
        $pdo = db();
        $users = $pdo->query("SELECT COUNT(*) FROM users WHERE country_name IS NOT NULL")->fetchColumn();
        $gold = $pdo->query("SELECT SUM(gold) FROM users")->fetchColumn();
        $oil = $pdo->query("SELECT SUM(oil) FROM users")->fetchColumn();
        $alliances = $pdo->query("SELECT COUNT(*) FROM alliances")->fetchColumn();
        $battlesToday = $pdo->query("SELECT COUNT(*) FROM battles WHERE created_at >= CURDATE()")->fetchColumn();
        $banned = $pdo->query("SELECT COUNT(*) FROM users WHERE is_banned=1")->fetchColumn();
        $treaties = $pdo->query("SELECT COUNT(*) FROM peace_treaties WHERE status='active' AND expires_at > NOW()")->fetchColumn();

        $mult = Settings::get('economy_multiplier', '1.0');
        $multExp = Settings::get('economy_multiplier_expires', '');
        $eventLine = ($multExp && strtotime($multExp) > time())
            ? "📈 رویداد اقتصادی فعال: ضریب {$mult} تا " . date('Y-m-d H:i', strtotime($multExp)) . "\n"
            : "📈 رویداد اقتصادی فعال: ندارد\n";

        $text = "📊 <b>آمار کلی بازی</b>\n\n"
              . "👥 کاربران فعال: {$users}\n"
              . "🚫 مسدود شده: {$banned}\n"
              . "💰 مجموع طلای اقتصاد: " . number_format($gold ?: 0) . "\n"
              . "🛢️ مجموع نفت اقتصاد: " . number_format($oil ?: 0) . "\n"
              . "🤝 تعداد اتحادها: {$alliances}\n"
              . "☮️ پیمان‌های صلح فعال: {$treaties}\n"
              . "⚔️ جنگ‌های امروز: {$battlesToday}\n"
              . $eventLine
              . "🎁 هدیه روزانه: " . Settings::get('daily_gift_gold', '0') . " طلا / " . Settings::get('daily_gift_oil', '0') . " نفت\n"
              . "📡 کانال: " . (Settings::channelRef() ?: 'تنظیم نشده');
        Telegram::sendMessage($chatId, $text);
    }

    private static function addResource(int $chatId, array $parts, string $field): void
    {
        if (count($parts) < 3) { Telegram::sendMessage($chatId, "❌ فرمت: /admin_addgold آیدی/نام مقدار"); return; }
        $target = self::resolveTarget($parts[1]);
        $amount = (int) $parts[2];
        if (!$target) { Telegram::sendMessage($chatId, '❌ کاربر یافت نشد.'); return; }
        db()->prepare("UPDATE users SET {$field} = GREATEST({$field} + ?, 0) WHERE id=?")->execute([$amount, $target['id']]);
        Telegram::sendMessage($chatId, "✅ {$field} کشور {$target['country_name']} به میزان " . number_format($amount) . " تغییر کرد.");
        Telegram::sendMessage($target['telegram_id'], "🎁 ادمین بازی " . number_format($amount) . " واحد {$field} به کشور شما اضافه کرد!");
    }

    private static function setBan(int $chatId, array $parts, bool $ban): void
    {
        if (count($parts) < 2) { Telegram::sendMessage($chatId, '❌ فرمت: /admin_ban آیدی/نام'); return; }
        $target = self::resolveTarget($parts[1]);
        if (!$target) { Telegram::sendMessage($chatId, '❌ کاربر یافت نشد.'); return; }
        db()->prepare("UPDATE users SET is_banned=? WHERE id=?")->execute([$ban ? 1 : 0, $target['id']]);
        Telegram::sendMessage($chatId, ($ban ? '🚫 کاربر مسدود شد: ' : '✅ کاربر رفع مسدودیت شد: ') . $target['country_name']);
    }

    private static function broadcast(int $chatId, string $message): void
    {
        if ($message === '') { Telegram::sendMessage($chatId, '❌ متن پیام خالی است.'); return; }
        $stmt = db()->query("SELECT telegram_id FROM users WHERE country_name IS NOT NULL");
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $sent = 0;
        foreach ($ids as $tgId) {
            Telegram::sendMessage($tgId, "📢 <b>پیام مدیریت</b>\n\n{$message}");
            $sent++;
            usleep(50000); // ~20 msg/sec, stays under Telegram rate limits
        }
        Telegram::sendMessage($chatId, "✅ پیام برای {$sent} کاربر ارسال شد.");
    }

    private static function setDailyGift(int $chatId, array $parts): void
    {
        if (count($parts) < 3) { Telegram::sendMessage($chatId, '❌ فرمت: /admin_setdailygift طلا نفت'); return; }
        Settings::set('daily_gift_gold', (string)(int)$parts[1]);
        Settings::set('daily_gift_oil', (string)(int)$parts[2]);
        Telegram::sendMessage($chatId, "✅ هدیه روزانه خودکار تنظیم شد: {$parts[1]} طلا / {$parts[2]} نفت (هر ۲۴ ساعت توسط کرون اعمال می‌شود).");
    }

    private static function setChannel(int $chatId, array $parts): void
    {
        if (count($parts) < 2) { Telegram::sendMessage($chatId, '❌ فرمت: /admin_setchannel @channel_username یا -100xxxxxxxxxx'); return; }
        $val = $parts[1];
        if (str_starts_with($val, '@') || !ctype_digit(ltrim($val, '-'))) {
            Settings::set('channel_username', ltrim($val, '@'));
            Settings::set('channel_id', '');
        } else {
            Settings::set('channel_id', $val);
        }
        if (isset($parts[2])) Settings::set('channel_invite_link', $parts[2]);
        Telegram::sendMessage($chatId, "✅ کانال تنظیم شد: {$val}\n\n⚠️ حتماً ربات را در کانال ادمین کنید تا بتواند پیام ارسال کند و عضویت کاربران را بررسی کند.");
    }

    private static function triggerEvent(int $chatId, array $parts): void
    {
        if (($parts[1] ?? '') === 'clear') {
            Settings::set('economy_multiplier', '1.0');
            Settings::set('economy_multiplier_expires', '');
            Telegram::sendMessage($chatId, '✅ رویداد اقتصادی فعال (در صورت وجود) پاک شد؛ درآمد به حالت عادی برگشت.');
            return;
        }
        if (count($parts) < 4 || !in_array($parts[1], ['up', 'down'])) {
            Telegram::sendMessage($chatId, '❌ فرمت: /admin_event up|down درصد ساعت   (مثال: /admin_event down 15 12)\nبرای پایان دادن زودهنگام به رویداد فعال: /admin_event clear');
            return;
        }
        $direction = $parts[1];
        $percent = max(1, min(90, (int)$parts[2]));
        $hours = max(1, min(168, (int)$parts[3]));
        $mult = $direction === 'up' ? (1 + $percent / 100) : (1 - $percent / 100);

        Settings::set('economy_multiplier', (string)$mult);
        Settings::set('economy_multiplier_expires', date('Y-m-d H:i:s', time() + $hours * 3600));

        $title = $direction === 'up' ? "📈 رونق اقتصادی جهانی" : "📉 رکود اقتصادی جهانی";
        $desc = $direction === 'up'
            ? "درآمد اقتصادی همه کشورها به مدت {$hours} ساعت {$percent}٪ افزایش یافت!"
            : "درآمد اقتصادی همه کشورها به مدت {$hours} ساعت {$percent}٪ کاهش یافت!";
        db()->prepare("INSERT INTO global_events (title, description, effect_type, effect_value, expires_at) VALUES (?,?,?,?,?)")
            ->execute([$title, $desc, $direction, $mult, date('Y-m-d H:i:s', time() + $hours * 3600)]);

        $channel = Settings::channelRef();
        if ($channel) Telegram::sendMessage($channel, "{$title}\n\n{$desc}");

        Telegram::sendMessage($chatId, "✅ رویداد اعمال شد: {$title} ({$percent}٪ برای {$hours} ساعت).");
    }

    private static function findUser(int $chatId, array $parts): void
    {
        if (count($parts) < 2) { Telegram::sendMessage($chatId, '❌ فرمت: /admin_find آیدی/نام'); return; }
        $u = self::resolveTarget($parts[1]);
        if (!$u) { Telegram::sendMessage($chatId, '❌ کاربر یافت نشد.'); return; }
        $text = "🔍 <b>{$u['country_name']}</b> (ID: {$u['telegram_id']})\n\n"
              . "💰 طلا: " . number_format($u['gold']) . "\n"
              . "🛢️ نفت: " . number_format($u['oil']) . "\n"
              . "⚔️ نظامی: " . number_format($u['military']) . "\n"
              . "📈 اقتصاد: " . number_format($u['economy']) . "\n"
              . "🔬 فناوری: " . number_format($u['tech']) . "\n"
              . "☢️ سلاح اتمی: " . ($u['has_nuke'] ? 'دارد' : 'ندارد') . "\n"
              . "🚫 مسدود: " . ($u['is_banned'] ? 'بله' : 'خیر') . "\n"
              . "🤝 اتحاد: " . ($u['alliance_id'] ?: '—');
        Telegram::sendMessage($chatId, $text);
    }

    private static function addWeapon(int $chatId, string $text): void
    {
        $rest = trim(substr($text, strlen('/admin_addweapon')));
        $fields = array_map('trim', explode('|', $rest));
        if (count($fields) < 6) {
            Telegram::sendMessage($chatId, "❌ فرمت:\n/admin_addweapon کلید|نام|حمله|دفاع|قیمت|shop یا blackmarket|نوع(ground/air/naval, اختیاری)\n\nمثال:\n/admin_addweapon drone|🛸 پهپاد شناسایی|60|20|2000|shop|air");
            return;
        }
        [$key, $name, $atk, $def, $cost, $category] = $fields;
        $category = in_array($category, ['shop', 'blackmarket', 'nuke']) ? $category : 'shop';
        $unitType = $fields[6] ?? 'ground';
        $unitType = in_array($unitType, ['ground', 'air', 'naval', 'nuclear']) ? $unitType : 'ground';
        try {
            db()->prepare("INSERT INTO weapons (weapon_key, name, category, unit_type, attack, defense, cost) VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE name=?, category=?, unit_type=?, attack=?, defense=?, cost=?, is_active=1")
                ->execute([$key, $name, $category, $unitType, (int)$atk, (int)$def, (int)$cost, $name, $category, $unitType, (int)$atk, (int)$def, (int)$cost]);
            Telegram::sendMessage($chatId, "✅ تجهیز «{$name}» ثبت/به‌روزرسانی شد.");
        } catch (Throwable $e) {
            Telegram::sendMessage($chatId, '❌ خطا: ' . $e->getMessage());
        }
    }
}
