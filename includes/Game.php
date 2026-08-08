<?php

class Game
{
    private PDO $db;
    private array $user;
    private int $chatId;
    private ?int $editId; 

    public function __construct(array $user, int $chatId, ?int $editId = null)
    {
        $this->db     = db();
        $this->user   = $user;
        $this->chatId = $chatId;
        $this->editId = $editId;
    }

    private function reply(string $text, ?array $keyboard = null): void
    {
        $kb = $keyboard ?? Telegram::backButton();
        if ($this->editId) {
            Telegram::editMessage($this->chatId, $this->editId, $text, $kb);
        } else {
            Telegram::sendMessage($this->chatId, $text, $kb);
        }
    }

    private function setState(?string $state, ?array $data = null): void
    {
        $stmt = $this->db->prepare("UPDATE users SET state=?, state_data=? WHERE id=?");
        $stmt->execute([$state, $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null, $this->user['id']]);
    }

    private function refreshUser(): void
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id=?");
        $stmt->execute([$this->user['id']]);
        $this->user = $stmt->fetch();
    }

    public static function findOrCreateUser(int $telegramId, ?string $username): array
    {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id=?");
        $stmt->execute([$telegramId]);
        $user = $stmt->fetch();

        if (!$user) {
            $stmt = $pdo->prepare("INSERT INTO users
                (telegram_id, username, gold, oil, military, economy, tech, population)
                VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $telegramId, $username,
                START_GOLD, START_OIL, START_MILITARY, START_ECONOMY, START_TECH, START_POPULATION,
            ]);
            $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id=?");
            $stmt->execute([$telegramId]);
            $user = $stmt->fetch();
        } elseif ($username && $username !== $user['username']) {
            $pdo->prepare("UPDATE users SET username=? WHERE id=?")->execute([$username, $user['id']]);
            $user['username'] = $username;
        }
        return $user;
    }

    private function fmt(int|float $n): string
    {
        return number_format($n);
    }

    public function isBanned(): bool { return (bool)($this->user['is_banned'] ?? 0); }
    public function getState(): ?string { return $this->user['state']; }
    public function getStateData(): array { return $this->user['state_data'] ? json_decode($this->user['state_data'], true) : []; }
    public function getUser(): array { return $this->user; }

    private function computePower(int $userId, ?int $military = null): array
    {
        if ($military === null) {
            $stmt = $this->db->prepare("SELECT military FROM users WHERE id=?");
            $stmt->execute([$userId]);
            $military = (int) $stmt->fetchColumn();
        }
        $stmt = $this->db->prepare("SELECT w.attack, w.defense, uw.quantity
            FROM user_weapons uw JOIN weapons w ON w.weapon_key = uw.weapon_key
            WHERE uw.user_id=? AND uw.quantity > 0 AND w.category != 'nuke'");
        $stmt->execute([$userId]);
        $atk = (int) round($military * 0.5);
        $def = (int) round($military * 0.5);
        foreach ($stmt->fetchAll() as $row) {
            $atk += $row['attack'] * $row['quantity'];
            $def += $row['defense'] * $row['quantity'];
        }
        return ['attack' => $atk, 'defense' => $def];
    }

    private function computeAirForce(int $userId): int
    {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(uw.quantity),0)
            FROM user_weapons uw JOIN weapons w ON w.weapon_key = uw.weapon_key
            WHERE uw.user_id=? AND w.unit_type='air'");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    private function arsenalSummary(int $userId): string
    {
        $stmt = $this->db->prepare("SELECT uw.quantity, w.name, w.unit_type FROM user_weapons uw
            JOIN weapons w ON w.weapon_key = uw.weapon_key
            WHERE uw.user_id=? AND uw.quantity > 0 AND w.category != 'nuke'
            ORDER BY FIELD(w.unit_type,'ground','air','naval'), w.name");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
        if (!$rows) return '— بدون تجهیزات —';
        $byType = ['ground' => [], 'air' => [], 'naval' => []];
        foreach ($rows as $r) {
            $byType[$r['unit_type']][] = "{$r['name']}: {$this->fmt($r['quantity'])}";
        }
        $labels = ['ground' => '🪖 زمینی', 'air' => '✈️ هوایی', 'naval' => '🚢 دریایی'];
        $out = [];
        foreach ($byType as $type => $lines) {
            if ($lines) $out[] = "{$labels[$type]}:\n" . implode("\n", $lines);
        }
        return implode("\n", $out);
    }

    private function destroyEquipmentFraction(int $userId, float $fraction): array
    {
        if ($fraction <= 0) return [];
        $fraction = min(1, $fraction);
        $stmt = $this->db->prepare("SELECT uw.weapon_key, uw.quantity, w.name FROM user_weapons uw
            JOIN weapons w ON w.weapon_key = uw.weapon_key
            WHERE uw.user_id=? AND uw.quantity > 0 AND w.category != 'nuke'");
        $stmt->execute([$userId]);
        $destroyed = [];
        foreach ($stmt->fetchAll() as $r) {
            $lost = (int) round($r['quantity'] * $fraction);
            if ($lost <= 0) continue;
            $this->db->prepare("UPDATE user_weapons SET quantity = GREATEST(quantity-?,0) WHERE user_id=? AND weapon_key=?")
                ->execute([$lost, $userId, $r['weapon_key']]);
            $destroyed[] = ['name' => $r['name'], 'lost' => $lost];
        }
        return $destroyed;
    }

    private function formatDestroyedList(array $destroyed): string
    {
        if (!$destroyed) return 'بدون خسارت تجهیزاتی';
        return implode('، ', array_map(fn($d) => "{$d['name']} ×{$d['lost']}", $destroyed));
    }

    private function nukeBombCount(int $userId): int
    {
        $stmt = $this->db->prepare("SELECT quantity FROM user_weapons WHERE user_id=? AND weapon_key='nuke_bomb'");
        $stmt->execute([$userId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function protectionRemainingMin(array $u): int
    {
        $elapsedSec = time() - strtotime($u['created_at']);
        $remain = NEW_PLAYER_PROTECTION_MIN * 60 - $elapsedSec;
        return $remain > 0 ? (int) ceil($remain / 60) : 0;
    }

    private function activePeaceTreaty(int $a, int $b): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM peace_treaties
            WHERE status='active' AND expires_at > NOW()
            AND ((country_a_id=? AND country_b_id=?) OR (country_a_id=? AND country_b_id=?))
            LIMIT 1");
        $stmt->execute([$a, $b, $b, $a]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function postToChannel(string $text): void
    {
        $chatRef = Settings::channelRef();
        if (!$chatRef) return;
        $keyboard = null;
        if (defined('BOT_USERNAME') && BOT_USERNAME && BOT_USERNAME !== 'YourBotUsername_bot') {
            $keyboard = Telegram::inline([
                [['🎮 ورود به ربات', 'https://t.me/' . BOT_USERNAME, 'url']],
            ]);
        }
        $res = Telegram::sendMessage($chatRef, $text, $keyboard);
        if (!($res['ok'] ?? false)) {
            error_log('postToChannel failed: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
        }
    }

    public function needsCountryName(): bool { return empty($this->user['country_name']); }

    public function askCountryName(): void
    {
        $this->setState('awaiting_country_name');
        Telegram::sendMessage($this->chatId, "🏳️ به بازی «جنگ جهانی» خوش آمدید!\n\nابتدا نام کشور خود را وارد کنید:");
    }

    public function setCountryName(string $name): void
    {
        $name = trim(mb_substr($name, 0, 32));
        if ($name === '') { Telegram::sendMessage($this->chatId, '❌ نام معتبر نیست. دوباره وارد کنید:'); return; }

        $stmt = $this->db->prepare("SELECT id FROM users WHERE LOWER(country_name) = LOWER(?) AND id != ?");
        $stmt->execute([$name, $this->user['id']]);
        if ($stmt->fetchColumn()) {
            Telegram::sendMessage($this->chatId, "❌ نام «{$name}» قبلاً توسط کشور دیگری انتخاب شده است. لطفاً نام دیگری وارد کنید:");
            return;
        }

        $stmt = $this->db->prepare("UPDATE users SET country_name=?, state=NULL WHERE id=?");
        $stmt->execute([$name, $this->user['id']]);
        $this->refreshUser();
        Telegram::sendMessage($this->chatId, "✅ کشور «{$name}» با موفقیت ثبت شد!", Telegram::mainMenu());
        $this->postToChannel("🏳️ <b>کشور تازه‌ای به بازی پیوست!</b>\n\n"
            . "کشور «<b>{$name}</b>» رسماً اعلام موجودیت کرد و وارد رقابت جهانی شد.\n\n"
            . "🎉 به {$name} خوش‌آمد بگویید 👋");
    }

    public function showMainMenu(): void
    {
        $this->reply("🎮 <b>پنل فرماندهی {$this->user['country_name']}</b>\n\nیکی از گزینه‌های زیر را انتخاب کنید 👇", Telegram::mainMenu());
    }

    public function cancel(): void
    {
        $this->setState(null);
        $this->showMainMenu();
    }

    public function myCountry(): void
    {
        $u = $this->user;
        $power = $this->computePower((int)$u['id'], (int)$u['military']);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM user_weapons WHERE user_id=? AND quantity>0");
        $stmt->execute([$u['id']]);
        $weaponTypes = $stmt->fetchColumn();

        $allianceName = '—';
        if ($u['alliance_id']) {
            $s = $this->db->prepare("SELECT name FROM alliances WHERE id=?");
            $s->execute([$u['alliance_id']]);
            $allianceName = $s->fetchColumn() ?: '—';
        }

        $air = $this->computeAirForce((int)$u['id']);
        $nukeBombs = $u['has_nuke'] ? $this->nukeBombCount((int)$u['id']) : 0;
        $protectionRemain = $this->protectionRemainingMin($u);

        $text = "🌍 <b>کشور {$u['country_name']}</b>\n\n"
              . ($protectionRemain > 0 ? "🛡️ <b>حمایت بازیکن تازه‌وارد فعال است</b> — تا {$protectionRemain} دقیقه دیگر نه می‌توانید حمله کنید و نه مورد حمله قرار می‌گیرید.\n\n" : '')
              . "💰 طلا: " . $this->fmt($u['gold']) . "\n"
              . "🛢️ نفت: " . $this->fmt($u['oil']) . "\n"
              . "⚔️ نظامی پایه: " . $this->fmt($u['military']) . "\n"
              . "📈 اقتصاد: " . $this->fmt($u['economy']) . "\n"
              . "🔬 فناوری: " . $this->fmt($u['tech']) . "\n"
              . "👥 جمعیت: " . $this->fmt($u['population']) . "\n"
              . "⭐ اعتبار: " . $this->fmt($u['reputation']) . "\n"
              . "————————————\n"
              . "🗡️ قدرت حمله مؤثر: " . $this->fmt($power['attack']) . "\n"
              . "🛡️ قدرت دفاع مؤثر: " . $this->fmt($power['defense']) . "\n"
              . "✈️ نیروی هوایی: " . $this->fmt($air) . " فروند\n"
              . "🔫 تعداد نوع تجهیزات نظامی: {$weaponTypes}\n"
              . "☢️ برنامه هسته‌ای: " . ($u['has_nuke'] ? "دارد ✅ (💣 {$nukeBombs} بمب)" : 'ندارد ❌') . "\n"
              . "🏚️ پناهگاه اتمی: " . ($u['has_bunker'] ? 'دارد ✅' : 'ندارد ❌') . "\n"
              . "🤝 اتحاد: {$allianceName}";
        $this->reply($text);
    }

    private array $companyTypes = [
        'factory'         => ['label' => '🏭 کارخانه',        'cost' => 8000,  'income' => 300,  'resource' => 'gold'],
        'farm'            => ['label' => '🌾 مزرعه',           'cost' => 6000,  'income' => 220,  'resource' => 'gold'],
        'techlab'         => ['label' => '🔬 آزمایشگاه',       'cost' => 15000, 'income' => 20,   'resource' => 'tech'],
        'oil_rig'         => ['label' => '🛢️ دکل نفتی',        'cost' => 12000, 'income' => 250,  'resource' => 'oil'],
        'bank'            => ['label' => '🏦 بانک',            'cost' => 25000, 'income' => 700,  'resource' => 'gold'],
        'mall'            => ['label' => '🏬 مرکز خرید',       'cost' => 18000, 'income' => 500,  'resource' => 'gold'],
        'refinery'        => ['label' => '⚗️ پالایشگاه',       'cost' => 20000, 'income' => 400,  'resource' => 'oil'],
        'research_center' => ['label' => '🧪 مرکز تحقیقات',    'cost' => 30000, 'income' => 45,   'resource' => 'tech'],
        'stock_exchange'  => ['label' => '📊 بورس اوراق بهادار','cost' => 40000, 'income' => 1000, 'resource' => 'gold'],
    ];

    public function companiesMenu(): void
    {
        $stmt = $this->db->prepare("SELECT * FROM companies WHERE user_id=?");
        $stmt->execute([$this->user['id']]);
        $owned = $stmt->fetchAll();

        $text = "🏢 <b>شرکت‌های شما</b>\n\n";
        if (!$owned) {
            $text .= "شما هنوز شرکتی ندارید.\n\n";
        } else {
            foreach ($owned as $c) {
                $meta = $this->companyTypes[$c['type']] ?? null;
                $text .= ($meta['label'] ?? $c['type']) . " — سطح {$c['level']} — {$this->fmt($c['income_per_hour'])}/ساعت\n";
            }
            $text .= "\n";
        }
        $text .= "📋 برای خرید شرکت جدید یکی از دکمه‌ها را بزنید:";

        $rows = [];
        foreach ($this->companyTypes as $key => $meta) {
            $rows[] = [["{$meta['label']} — {$this->fmt($meta['cost'])} طلا", "companies|buy|{$key}"]];
        }
        $rows[] = [['💰 جمع‌آوری درآمد انباشته', 'companies|collect']];
        $rows[] = [['🔙 بازگشت به منو', 'menu|main']];

        $this->reply($text, Telegram::inline($rows));
    }

    public function buyCompany(string $type): void
    {
        if (!isset($this->companyTypes[$type])) { $this->reply('❌ نوع شرکت نامعتبر است.'); return; }
        $meta = $this->companyTypes[$type];
        if ($this->user['gold'] < $meta['cost']) { $this->reply('❌ طلای کافی ندارید.', Telegram::backButton('menu|companies')); return; }

        $this->db->beginTransaction();
        $this->db->prepare("UPDATE users SET gold = gold - ? WHERE id=?")->execute([$meta['cost'], $this->user['id']]);
        $this->db->prepare("INSERT INTO companies (user_id, type, income_per_hour) VALUES (?,?,?)")
            ->execute([$this->user['id'], $type, $meta['income']]);
        $this->db->commit();
        $this->refreshUser();
        $this->reply("✅ {$meta['label']} با موفقیت خریداری شد!", Telegram::backButton('menu|companies'));
    }

    public function collectIncome(): void
    {
        $stmt = $this->db->prepare("SELECT * FROM companies WHERE user_id=?");
        $stmt->execute([$this->user['id']]);
        $companies = $stmt->fetchAll();
        if (!$companies) { $this->reply('شما شرکتی ندارید.', Telegram::backButton('menu|companies')); return; }

        $last = $this->user['last_income_collect'] ? strtotime($this->user['last_income_collect']) : strtotime($this->user['created_at']);
        $hours = max(0, min(24, (time() - $last) / 3600));

        $mult = 1.0;
        $expires = Settings::get('economy_multiplier_expires', '');
        if ($expires && strtotime($expires) > time()) {
            $mult = (float) Settings::get('economy_multiplier', '1.0');
        }

        $goldGain = 0; $oilGain = 0; $techGain = 0;
        foreach ($companies as $c) {
            $meta = $this->companyTypes[$c['type']] ?? null;
            if (!$meta) continue;
            $amount = (int) round($c['income_per_hour'] * $c['level'] * $hours * $mult);
            match ($meta['resource']) {
                'gold' => $goldGain += $amount,
                'oil'  => $oilGain  += $amount,
                'tech' => $techGain += $amount,
            };
        }
        $stmt = $this->db->prepare("UPDATE users SET gold=gold+?, oil=oil+?, tech=tech+?, last_income_collect=NOW() WHERE id=?");
        $stmt->execute([$goldGain, $oilGain, $techGain, $this->user['id']]);
        $this->refreshUser();
        $this->reply("💰 درآمد جمع‌آوری شد!\n\n+{$this->fmt($goldGain)} طلا\n+{$this->fmt($oilGain)} نفت\n+{$this->fmt($techGain)} فناوری", Telegram::backButton('menu|companies'));
    }

    private function getWeapon(string $key): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM weapons WHERE weapon_key=? AND is_active=1");
        $stmt->execute([$key]);
        return $stmt->fetch() ?: null;
    }

    private const UNIT_TYPE_LABEL = ['air' => 'هوایی ✈️', 'ground' => 'زمینی 🪖', 'naval' => 'دریایی 🚢'];

    public function armsMenu(): void
    {
        $stmt = $this->db->query("SELECT * FROM weapons WHERE category='shop' AND is_active=1 ORDER BY cost ASC");
        $weapons = $stmt->fetchAll();
        $text = "🛒 <b>بازار تسلیحات</b>\n\nهر تجهیز نظامی امتیاز حمله و دفاع جداگانه‌ای به کشور شما اضافه می‌کند.\n\n";
        $rows = [];
        foreach ($weapons as $w) {
            $typeLabel = self::UNIT_TYPE_LABEL[$w['unit_type']] ?? '';
            $text .= "{$w['name']} [{$typeLabel}] — 🗡️{$w['attack']} 🛡️{$w['defense']} — 💰{$this->fmt($w['cost'])}\n";
            $rows[] = [["{$w['name']} ({$this->fmt($w['cost'])})", "arms|buy|{$w['weapon_key']}"]];
        }
        $rows[] = [['🔙 بازگشت به منو', 'menu|main']];
        $this->reply($text, Telegram::inline($rows));
    }

    public function askWeaponQty(string $key): void
    {
        $weapon = $this->getWeapon($key);
        if (!$weapon) { $this->reply('❌ این تجهیز یافت نشد.'); return; }
        if ($weapon['category'] === 'nuke') { $this->reply('☢️ بمب اتمی از بخش «پروژه‌های ملی → خرید بمب اتمی» خریداری می‌شود.', Telegram::backButton('menu|projects')); return; }
        $this->setState('awaiting_weapon_qty', ['key' => $key]);
        Telegram::sendMessage($this->chatId, "🔢 چند عدد «{$weapon['name']}» می‌خواهید بخرید؟ (عدد را تایپ کنید)\nقیمت واحد: {$this->fmt($weapon['cost'])} طلا");
    }

    public function weaponQtyEntered(string $key, string $input): void
    {
        $qty = (int) preg_replace('/\D/', '', $input);
        $this->setState(null);
        if ($qty <= 0) { $this->reply('❌ عدد نامعتبر است.'); return; }
        $weapon = $this->getWeapon($key);
        if (!$weapon) { $this->reply('❌ این تجهیز یافت نشد.'); return; }
        $total = $weapon['cost'] * $qty;
        if ($this->user['gold'] < $total) { $this->reply('❌ طلای کافی ندارید. هزینه کل: ' . $this->fmt($total)); return; }

        $text = "🧾 <b>تایید خرید</b>\n\n{$weapon['name']} × {$qty}\n💰 هزینه کل: {$this->fmt($total)} طلا\n\nآیا مطمئن هستید؟";
        $kb = Telegram::inline([
            [['✅ تایید خرید', "arms|doconfirm|{$key}|{$qty}"], ['❌ انصراف', 'menu|main']],
        ]);
        Telegram::sendMessage($this->chatId, $text, $kb);
    }

    public function confirmWeaponPurchase(string $key, int $qty): void
    {
        $weapon = $this->getWeapon($key);
        if (!$weapon) { $this->reply('❌ این تجهیز یافت نشد.'); return; }
        if ($weapon['category'] === 'nuke') { $this->reply('❌ عملیات نامعتبر.'); return; }
        $total = $weapon['cost'] * $qty;
        if ($this->user['gold'] < $total) { $this->reply('❌ طلای کافی ندارید.'); return; }

        $this->db->beginTransaction();
        $this->db->prepare("UPDATE users SET gold=gold-? WHERE id=?")->execute([$total, $this->user['id']]);
        $this->db->prepare("INSERT INTO user_weapons (user_id, weapon_key, quantity) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE quantity = quantity + ?")->execute([$this->user['id'], $key, $qty, $qty]);
        $this->db->commit();
        $this->refreshUser();
        $this->reply("✅ خرید موفق: {$weapon['name']} × {$qty}\n💰 -{$this->fmt($total)} طلا", Telegram::backButton('menu|main'));
    }

    public function tradeMenu(): void
    {
        $text = "📦 <b>صادرات و واردات</b>\n\n"
              . "🔸 فروش نفت — هر ۱۰ واحد = ۲۵ طلا\n"
              . "🔸 خرید نفت — هر ۱۰ واحد = ۳۵ طلا\n"
              . "🔸 صادرات فناوری — هر واحد = ۴۰ طلا\n\n"
              . "یک گزینه را انتخاب کنید، سپس مقدار را تایپ کنید:";
        $this->reply($text, Telegram::inline([
            [['🔻 فروش نفت', 'trade|ask|sell_oil'], ['🔺 خرید نفت', 'trade|ask|buy_oil']],
            [['📤 صادرات فناوری', 'trade|ask|export_tech']],
            [['🔙 بازگشت به منو', 'menu|main']],
        ]));
    }

    public function askTradeAmount(string $action): void
    {
        $labels = ['sell_oil' => 'فروش نفت', 'buy_oil' => 'خرید نفت', 'export_tech' => 'صادرات فناوری'];
        if (!isset($labels[$action])) { $this->reply('❌ گزینه نامعتبر است.'); return; }
        $this->setState('awaiting_trade_amount', ['action' => $action]);
        Telegram::sendMessage($this->chatId, "🔢 مقدار مورد نظر برای «{$labels[$action]}» را تایپ کنید:");
    }

    public function tradeAmountEntered(string $action, string $input): void
    {
        $amount = (int) preg_replace('/\D/', '', $input);
        $this->setState(null);
        $this->tradeAction($action, $amount);
    }

    public function tradeAction(string $action, int $amount): void
    {
        if ($amount <= 0) { $this->reply('❌ مقدار نامعتبر است.'); return; }
        $u = $this->user;
        switch ($action) {
            case 'sell_oil':
                if ($u['oil'] < $amount) { Telegram::sendMessage($this->chatId, '❌ نفت کافی ندارید.'); return; }
                $gold = intdiv($amount, 10) * 25;
                $this->db->prepare("UPDATE users SET oil=oil-?, gold=gold+? WHERE id=?")->execute([$amount, $gold, $u['id']]);
                Telegram::sendMessage($this->chatId, "✅ {$this->fmt($amount)} نفت فروخته شد؛ {$this->fmt($gold)} طلا دریافت کردید.");
                break;
            case 'buy_oil':
                $cost = intdiv($amount, 10) * 35;
                if ($u['gold'] < $cost) { Telegram::sendMessage($this->chatId, '❌ طلای کافی ندارید.'); return; }
                $this->db->prepare("UPDATE users SET oil=oil+?, gold=gold-? WHERE id=?")->execute([$amount, $cost, $u['id']]);
                Telegram::sendMessage($this->chatId, "✅ {$this->fmt($amount)} نفت خریداری شد به قیمت {$this->fmt($cost)} طلا.");
                break;
            case 'export_tech':
                if ($u['tech'] < $amount) { Telegram::sendMessage($this->chatId, '❌ فناوری کافی ندارید.'); return; }
                $gold = $amount * 40;
                $this->db->prepare("UPDATE users SET tech=tech-?, gold=gold+? WHERE id=?")->execute([$amount, $gold, $u['id']]);
                Telegram::sendMessage($this->chatId, "✅ {$this->fmt($amount)} فناوری صادر شد؛ {$this->fmt($gold)} طلا دریافت کردید.");
                break;
            default: return;
        }
        $this->refreshUser();
    }

    public function oilMenu(): void
    {
        $stmt = $this->db->prepare("SELECT type, level FROM companies WHERE user_id=? AND type IN ('oil_rig','refinery')");
        $stmt->execute([$this->user['id']]);
        $rigs = $stmt->fetchAll();
        $rigCount = count($rigs);
        $potential = 0;
        foreach ($rigs as $r) $potential += $r['level'] * OIL_PER_RIG_LEVEL * ($r['type'] === 'refinery' ? 1.5 : 1);

        $text = "🛢️ <b>نفت و انرژی</b>\n\n"
              . "🛢️ موجودی نفت شما: {$this->fmt($this->user['oil'])}\n"
              . "⚙️ تعداد دکل/پالایشگاه: {$rigCount}\n"
              . "🎁 نفت رایگان قابل دریافت هر ۲ ساعت: ~{$this->fmt((int)$potential)}\n";

        $kb = [];
        if ($rigCount > 0) {
            $kb[] = [['🎁 دریافت نفت رایگان', 'oil|collect']];
        } else {
            $text .= "\n⚠️ برای دریافت نفت رایگان باید حداقل یک دکل نفتی داشته باشید (بخش شرکت‌ها).";
        }
        $kb[] = [['🔙 بازگشت به منو', 'menu|main']];
        $this->reply($text, Telegram::inline($kb));
    }

    public function collectFreeOil(): void
    {
        $last = $this->user['last_oil_collect'];
        if ($last && (time() - strtotime($last)) < OIL_COLLECT_COOLDOWN_MIN * 60) {
            $remaining = OIL_COLLECT_COOLDOWN_MIN * 60 - (time() - strtotime($last));
            $this->reply('⏳ نفت رایگان بعدی تا ' . ceil($remaining / 60) . ' دقیقه دیگر در دسترس است.', Telegram::backButton('menu|oil'));
            return;
        }
        $stmt = $this->db->prepare("SELECT type, level FROM companies WHERE user_id=? AND type IN ('oil_rig','refinery')");
        $stmt->execute([$this->user['id']]);
        $rigs = $stmt->fetchAll();
        if (!$rigs) { $this->reply('❌ شما دکل نفتی ندارید.', Telegram::backButton('menu|oil')); return; }

        $amount = 0;
        foreach ($rigs as $r) $amount += $r['level'] * OIL_PER_RIG_LEVEL * ($r['type'] === 'refinery' ? 1.5 : 1);
        $amount = (int) round($amount);

        $this->db->prepare("UPDATE users SET oil=oil+?, last_oil_collect=NOW() WHERE id=?")->execute([$amount, $this->user['id']]);
        $this->refreshUser();
        $this->reply("🎁 {$this->fmt($amount)} نفت رایگان دریافت کردید!", Telegram::backButton('menu|oil'));
    }

    public function askStatement(): void
    {
        $this->setState('awaiting_statement');
        Telegram::sendMessage($this->chatId, '📢 متن بیانیه رسمی خود را بنویسید (پس از تایید ادمین در کانال منتشر می‌شود):');
    }

    public function publishStatement(string $message): void
    {
        $message = trim(mb_substr($message, 0, 400));
        $this->setState(null);
        $stmt = $this->db->prepare("INSERT INTO official_statements (user_id, message, status) VALUES (?,?, 'pending')");
        $stmt->execute([$this->user['id'], $message]);
        $id = $this->db->lastInsertId();
        Telegram::sendMessage($this->chatId, '✅ بیانیه شما برای تایید ادمین ارسال شد.');

        $kb = Telegram::inline([[['✅ تایید و انتشار', "statement|approve|{$id}"], ['❌ رد', "statement|reject|{$id}"]]]);
        foreach (ADMIN_IDS as $adminId) {
            Telegram::sendMessage($adminId, "📢 بیانیه جدید از {$this->user['country_name']}:\n\n«{$message}»", $kb);
        }
    }

    public function adminRespondStatement(int $id, bool $approve): void
    {
        $stmt = $this->db->prepare("SELECT s.*, u.telegram_id, u.country_name FROM official_statements s JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.status='pending'");
        $stmt->execute([$id]);
        $st = $stmt->fetch();
        if (!$st) { $this->reply('❌ این بیانیه قبلاً پردازش شده.'); return; }

        $status = $approve ? 'approved' : 'rejected';
        $this->db->prepare("UPDATE official_statements SET status=? WHERE id=?")->execute([$status, $id]);

        if ($approve) {
            $this->postToChannel("📢 <b>بیانیه رسمی کشور {$st['country_name']}</b>\n\n"
                . "«{$st['message']}»");
            Telegram::sendMessage($st['telegram_id'], '✅ بیانیه شما تایید و در کانال منتشر شد.');
            $this->reply('✅ بیانیه منتشر شد.');
        } else {
            Telegram::sendMessage($st['telegram_id'], '❌ بیانیه شما توسط ادمین رد شد.');
            $this->reply('❌ بیانیه رد شد.');
        }
    }

    public function warLaws(): void
    {
        $text = "⚔️ <b>قوانین جنگ</b>\n\n"
              . "1️⃣ در حمله نظامی، شما تعیین می‌کنید چند درصد (۱۰٪ تا ۹۰٪) از نیروی خود را وارد نبرد کنید. برنده بر اساس قدرت حمله مؤثر مهاجم (متناسب با همان درصد) و قدرت دفاع مؤثر مدافع با کمی شانس تصادفی مشخص می‌شود.\n"
              . "2️⃣ در پیروزی، بخشی از طلا و نفت کشور مغلوب غارت می‌شود. تلفات هر دو طرف همیشه در گزارش نشان داده می‌شود.\n"
              . "3️⃣ حمله اتمی نیازمند «برنامه هسته‌ای» (پروژه‌های ملی) + حداقل " . NUKE_MIN_BOMBS_PER_ATTACK . " بمب اتمی است؛ بمب‌ها باید یکی‌یکی و با قیمت بسیار بالا خریداری شوند و در هر حمله مصرف می‌شوند.\n"
              . "4️⃣ حمله اتمی معمولاً حدود " . NO_BUNKER_DESTRUCTION_PERCENT . "٪ کشور هدف را نابود می‌کند؛ اما اگر هدف «پناهگاه اتمی» داشته باشد، تخریب به حدود " . BUNKER_DESTRUCTION_PERCENT . "٪ کاهش می‌یابد. پناهگاه اتمی را همه کاربران می‌توانند از بخش پروژه‌های ملی بخرند.\n"
              . "5️⃣ اعضای یک اتحاد نمی‌توانند به یکدیگر حمله کنند.\n"
              . "6️⃣ کشورهای دارای پیمان صلح فعال ({$this->fmtDays()} روزه) نمی‌توانند به هم حمله کنند مگر پیمان شکسته شود.\n"
              . "7️⃣ کشورهای تازه‌ثبت‌نام‌شده تا " . NEW_PLAYER_PROTECTION_MIN . " دقیقه اول تحت حمایت هستند: در این مدت نه می‌توانند حمله کنند و نه مورد حمله (نظامی یا اتمی) قرار می‌گیرند.\n"
              . "8️⃣ تمام حملات نظامی و اتمی در کانال رسمی گزارش می‌شوند.\n";
        $this->reply($text);
    }
    private function fmtDays(): string { return (string) PEACE_TREATY_DAYS; }

    private function targetList(string $action, int $page = 0, bool $excludeAlliance = false): void
    {
        $isCombat = in_array($action, ['attack', 'nuke'], true);
        if ($isCombat && ($remain = $this->protectionRemainingMin($this->user)) > 0) {
            $this->reply("🛡️ کشور شما تازه ثبت‌نام کرده و تا {$remain} دقیقه دیگر تحت حمایت ویژه بازیکنان تازه‌وارد است؛ در این مدت نه می‌توانید حمله کنید و نه مورد حمله قرار می‌گیرید.", Telegram::backButton());
            return;
        }

        $perPage = 8;
        $offset = $page * $perPage;
        $sql = "SELECT id, country_name, military FROM users WHERE id != ? AND country_name IS NOT NULL AND is_banned=0";
        $params = [$this->user['id']];
        if ($isCombat) {
            $sql .= " AND created_at <= DATE_SUB(NOW(), INTERVAL " . NEW_PLAYER_PROTECTION_MIN . " MINUTE)";
        }
        if ($excludeAlliance && $this->user['alliance_id']) {
            $sql .= " AND (alliance_id IS NULL OR alliance_id != ?)";
            $params[] = $this->user['alliance_id'];
        }
        $sql .= " ORDER BY military DESC LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $targets = $stmt->fetchAll();

        if (!$targets && $page === 0) { $this->reply('❌ در حال حاضر کشور دیگری برای این عملیات یافت نشد.', Telegram::backButton()); return; }

        $rows = [];
        foreach ($targets as $t) {
            $rows[] = [["🏳️ {$t['country_name']}", "{$action}|sel|{$t['id']}"]];
        }
        $navRow = [];
        if ($page > 0) $navRow[] = ["⬅️ قبلی", "{$action}|list|" . ($page - 1)];
        if (count($targets) === $perPage) $navRow[] = ["بعدی ➡️", "{$action}|list|" . ($page + 1)];
        if ($navRow) $rows[] = $navRow;
        $rows[] = [['🔙 بازگشت به منو', 'menu|main']];

        $this->reply('🎯 کشور هدف را انتخاب کنید:', Telegram::inline($rows));
    }

    public function attackList(int $page = 0): void { $this->targetList('attack', $page, true); }

    public function attackSelect(int $targetId): void
    {
        if (($remain = $this->protectionRemainingMin($this->user)) > 0) {
            $this->reply("🛡️ کشور شما تا {$remain} دقیقه دیگر تحت حمایت بازیکنان تازه‌وارد است و نمی‌تواند حمله کند.", Telegram::backButton()); return;
        }
        $stmt = $this->db->prepare("SELECT country_name, created_at FROM users WHERE id=?");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) { $this->reply('❌ کشور یافت نشد.'); return; }
        if ($this->protectionRemainingMin($target) > 0) {
            $this->reply('🛡️ این کشور تازه ثبت‌نام کرده و در حال حاضر تحت حمایت است؛ فعلاً نمی‌توانید به آن حمله کنید.', Telegram::backButton()); return;
        }
        $name = $target['country_name'];

        $text = "🎯 حمله به کشور <b>{$name}</b>\n\nچند درصد از کل نیروی نظامی خود را در این حمله استفاده می‌کنید؟";
        $rows = []; $line = [];
        foreach (range(ATTACK_PERCENT_MIN, ATTACK_PERCENT_MAX, ATTACK_PERCENT_STEP) as $pct) {
            $line[] = ["{$pct}٪", "attack|pct|{$targetId}|{$pct}"];
            if (count($line) === 3) { $rows[] = $line; $line = []; }
        }
        if ($line) $rows[] = $line;
        $rows[] = [['❌ انصراف', 'menu|main']];
        $this->reply($text, Telegram::inline($rows));
    }

    public function attackPercentSelect(int $targetId, int $percent): void
    {
        $percent = max(ATTACK_PERCENT_MIN, min(ATTACK_PERCENT_MAX, $percent));
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id=?");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) { $this->reply('❌ کشور یافت نشد.'); return; }

        $atkPower = $this->computePower((int)$this->user['id'], (int)$this->user['military']);
        $defPower = $this->computePower((int)$target['id'], (int)$target['military']);
        $effectiveAttack = (int) round($atkPower['attack'] * $percent / 100);

        $text = "🎯 حمله به کشور <b>{$target['country_name']}</b> با <b>{$percent}٪</b> از نیروها\n\n"
              . "🗡️ قدرت حمله مؤثر شما: {$this->fmt($effectiveAttack)}\n"
              . "🛡️ قدرت دفاع تخمینی حریف: {$this->fmt($defPower['defense'])}\n\n"
              . "🎒 <b>تجهیزات شما:</b>\n" . $this->arsenalSummary((int)$this->user['id']) . "\n\n"
              . "🎒 <b>تجهیزات حریف:</b>\n" . $this->arsenalSummary((int)$target['id']) . "\n\n"
              . "آیا مطمئن هستید؟";
        $this->reply($text, Telegram::inline([
            [['💥 تایید حمله', "attack|confirm|{$targetId}|{$percent}"], ['❌ انصراف', 'menu|main']],
        ]));
    }

    public function militaryAttack(int $targetId, int $percent = ATTACK_PERCENT_MAX): void
    {
        $percent = max(ATTACK_PERCENT_MIN, min(ATTACK_PERCENT_MAX, $percent ?: ATTACK_PERCENT_MAX));
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id=?");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) { $this->reply('❌ کشور هدف یافت نشد.'); return; }
        if ($target['id'] === $this->user['id']) { $this->reply('❌ نمی‌توانید به خودتان حمله کنید.'); return; }
        if ($this->user['alliance_id'] && $this->user['alliance_id'] === $target['alliance_id']) {
            $this->reply('❌ نمی‌توانید به هم‌پیمان خود حمله کنید.'); return;
        }
        if ($this->activePeaceTreaty((int)$this->user['id'], (int)$target['id'])) {
            $this->reply('❌ شما با این کشور پیمان صلح فعال دارید. ابتدا باید پیمان را بشکنید (بخش دیپلماسی).'); return;
        }
        if (($remain = $this->protectionRemainingMin($this->user)) > 0) {
            $this->reply("🛡️ کشور شما تا {$remain} دقیقه دیگر تحت حمایت بازیکنان تازه‌وارد است و نمی‌تواند حمله کند.", Telegram::backButton()); return;
        }
        if ($this->protectionRemainingMin($target) > 0) {
            $this->reply('🛡️ این کشور تازه ثبت‌نام کرده و تحت حمایت است؛ فعلاً نمی‌توانید به آن حمله کنید.', Telegram::backButton()); return;
        }

        $atkPower = $this->computePower((int)$this->user['id'], (int)$this->user['military']);
        $defPower = $this->computePower((int)$target['id'], (int)$target['military']);
        $committedAttack = $atkPower['attack'] * $percent / 100;

        $attackerRoll = $committedAttack * (0.85 + mt_rand(0, 30) / 100);
        $defenderRoll = $defPower['defense'] * (0.85 + mt_rand(0, 30) / 100);
        $attackerWon = $attackerRoll > $defenderRoll;

        $committedMilitary = (int) round($this->user['military'] * $percent / 100);
        $attackerLosses = (int) round($committedMilitary * mt_rand(2, 8) / 100);
        $defenderLosses = (int) round($target['military'] * mt_rand(2, 8) / 100);
        $goldStolen = 0; $oilStolen = 0;
        $myLosses = $attackerWon ? $attackerLosses : $attackerLosses * 2;

        $attackerEquipFraction = $this->user['military'] > 0 ? $myLosses / $this->user['military'] : 0;
        $defenderEquipFraction = $target['military'] > 0 ? $defenderLosses / $target['military'] : 0;

        $this->db->beginTransaction();
        if ($attackerWon) {
            $goldStolen = (int) round($target['gold'] * (mt_rand(5, 15) / 100));
            $oilStolen  = (int) round($target['oil']  * (mt_rand(5, 15) / 100));
            $this->db->prepare("UPDATE users SET gold=gold+?, oil=oil+?, military=GREATEST(military-?,0), reputation=reputation+3 WHERE id=?")
                ->execute([$goldStolen, $oilStolen, $attackerLosses, $this->user['id']]);
            $this->db->prepare("UPDATE users SET gold=GREATEST(gold-?,0), oil=GREATEST(oil-?,0), military=GREATEST(military-?,0), reputation=reputation-5 WHERE id=?")
                ->execute([$goldStolen, $oilStolen, $defenderLosses, $target['id']]);
        } else {
            $this->db->prepare("UPDATE users SET military=GREATEST(military-?,0), reputation=reputation-3 WHERE id=?")
                ->execute([$attackerLosses * 2, $this->user['id']]);
            $this->db->prepare("UPDATE users SET military=GREATEST(military-?,0), reputation=reputation+2 WHERE id=?")
                ->execute([$defenderLosses, $target['id']]);
        }
        $myDestroyed = $this->destroyEquipmentFraction((int)$this->user['id'], $attackerEquipFraction);
        $theirDestroyed = $this->destroyEquipmentFraction((int)$target['id'], $defenderEquipFraction);
        $this->db->prepare("INSERT INTO battles (attacker_id, defender_id, battle_type, attacker_won, gold_stolen, oil_stolen, attacker_losses, defender_losses, reported)
            VALUES (?,?,'military',?,?,?,?,?,1)")
            ->execute([$this->user['id'], $target['id'], $attackerWon ? 1 : 0, $goldStolen, $oilStolen, $attackerWon ? $attackerLosses : $attackerLosses * 2, $defenderLosses]);
        $this->db->commit();
        $this->refreshUser();

        $myDestroyedTxt = $this->formatDestroyedList($myDestroyed);
        $theirDestroyedTxt = $this->formatDestroyedList($theirDestroyed);
        if ($attackerWon) {
            $report = "💥 <b>پیروزی در حمله نظامی!</b> ({$percent}٪ نیرو استفاده شد)\n\nکشور {$target['country_name']} شکست خورد.\n💰 غنیمت طلا: {$this->fmt($goldStolen)}\n🛢️ غنیمت نفت: {$this->fmt($oilStolen)}\n\n"
                . "⚔️ <b>تلفات نیروی انسانی دو طرف:</b>\nتلفات شما: {$this->fmt($myLosses)}\nتلفات حریف: {$this->fmt($defenderLosses)}\n\n"
                . "🎒 <b>تجهیزات نابودشده شما:</b> {$myDestroyedTxt}\n🎒 <b>تجهیزات نابودشده حریف:</b> {$theirDestroyedTxt}";
        } else {
            $report = "💢 <b>شکست در حمله نظامی!</b> ({$percent}٪ نیرو استفاده شد)\n\nحمله شما به {$target['country_name']} دفع شد.\n\n"
                . "⚔️ <b>تلفات نیروی انسانی دو طرف:</b>\nتلفات شما: {$this->fmt($myLosses)}\nتلفات حریف: {$this->fmt($defenderLosses)}\n\n"
                . "🎒 <b>تجهیزات نابودشده شما:</b> {$myDestroyedTxt}\n🎒 <b>تجهیزات نابودشده حریف:</b> {$theirDestroyedTxt}";
        }
        $this->reply($report, Telegram::backButton());

        Telegram::sendMessage($target['telegram_id'], ($attackerWon
            ? "🚨 کشور شما توسط {$this->user['country_name']} مورد حمله قرار گرفت!\n💰 -{$this->fmt($goldStolen)} طلا\n🛢️ -{$this->fmt($oilStolen)} نفت\n⚔️ تلفات شما: {$this->fmt($defenderLosses)} | تلفات مهاجم: {$this->fmt($myLosses)}"
            : "🛡️ کشور شما توسط {$this->user['country_name']} مورد حمله قرار گرفت اما با موفقیت دفاع کردید!\n⚔️ تلفات شما: {$this->fmt($defenderLosses)} | تلفات مهاجم: {$this->fmt($myLosses)}")
            . "\n🎒 تجهیزات ازدست‌رفته شما: {$theirDestroyedTxt}\n🎒 تجهیزات ازدست‌رفته مهاجم: {$myDestroyedTxt}"
        );

        $this->postToChannel(($attackerWon ? "💥" : "🛡️") . " <b>گزارش نبرد</b>\n\n"
            . "🏳️ <b>{$this->user['country_name']}</b> با {$percent}٪ از نیروهایش به <b>{$target['country_name']}</b> حمله کرد.\n"
            . "نتیجه: " . ($attackerWon ? "پیروزی مهاجم ✅" : "دفاع موفق مدافع 🛡️") . "\n"
            . ($attackerWon ? "💰 غنیمت: {$this->fmt($goldStolen)} طلا / {$this->fmt($oilStolen)} نفت\n" : '')
            . "⚔️ تلفات: مهاجم {$this->fmt($myLosses)} | مدافع {$this->fmt($defenderLosses)}\n"
            . "🎒 تجهیزات ازدست‌رفته: مهاجم «{$myDestroyedTxt}» | مدافع «{$theirDestroyedTxt}»");
    }

    public function nukeList(int $page = 0): void { $this->targetList('nuke', $page, true); }

    public function nukeSelect(int $targetId): void
    {
        if (!$this->user['has_nuke']) { $this->reply('❌ شما هنوز برنامه هسته‌ای ندارید (بخش پروژه‌های ملی).', Telegram::backButton()); return; }
        if (($remain = $this->protectionRemainingMin($this->user)) > 0) {
            $this->reply("🛡️ کشور شما تا {$remain} دقیقه دیگر تحت حمایت بازیکنان تازه‌وارد است و نمی‌تواند حمله کند.", Telegram::backButton()); return;
        }
        $owned = $this->nukeBombCount((int)$this->user['id']);
        if ($owned < NUKE_MIN_BOMBS_PER_ATTACK) {
            $this->reply("❌ برای حمله اتمی حداقل به " . NUKE_MIN_BOMBS_PER_ATTACK . " بمب نیاز دارید.\nتعداد بمب فعلی شما: {$owned}\n\nاز «پروژه‌های ملی → خرید بمب اتمی» تهیه کنید.", Telegram::backButton('menu|projects'));
            return;
        }
        $stmt = $this->db->prepare("SELECT country_name, created_at FROM users WHERE id=?");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) { $this->reply('❌ کشور یافت نشد.'); return; }
        if ($this->protectionRemainingMin($target) > 0) {
            $this->reply('🛡️ این کشور تازه ثبت‌نام کرده و در حال حاضر تحت حمایت است؛ فعلاً نمی‌توانید به آن حمله کنید.', Telegram::backButton()); return;
        }
        $name = $target['country_name'];

        $options = array_values(array_unique(array_filter(
            [NUKE_MIN_BOMBS_PER_ATTACK, 10, 20, $owned],
            fn($n) => $n >= NUKE_MIN_BOMBS_PER_ATTACK && $n <= $owned
        )));
        sort($options);
        $rows = []; $line = [];
        foreach ($options as $n) {
            $line[] = ["💣 {$n}", "nuke|qty|{$targetId}|{$n}"];
            if (count($line) === 3) { $rows[] = $line; $line = []; }
        }
        if ($line) $rows[] = $line;
        $rows[] = [['❌ انصراف', 'menu|main']];

        $this->reply("☢️ حمله اتمی به <b>{$name}</b>\n\n💣 تعداد بمب موجود شما: {$owned}\n\nچند بمب می‌خواهید در این حمله استفاده کنید؟ (حداقل " . NUKE_MIN_BOMBS_PER_ATTACK . ")", Telegram::inline($rows));
    }

    public function nukeQtySelect(int $targetId, int $bombs): void
    {
        $owned = $this->nukeBombCount((int)$this->user['id']);
        $bombs = max(NUKE_MIN_BOMBS_PER_ATTACK, min($owned ?: NUKE_MIN_BOMBS_PER_ATTACK, $bombs));
        if ($owned < NUKE_MIN_BOMBS_PER_ATTACK) { $this->reply('❌ بمب کافی ندارید.', Telegram::backButton('menu|projects')); return; }

        $stmt = $this->db->prepare("SELECT * FROM users WHERE id=?");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) { $this->reply('❌ کشور یافت نشد.'); return; }

        $destroyPct = $target['has_bunker'] ? BUNKER_DESTRUCTION_PERCENT : NO_BUNKER_DESTRUCTION_PERCENT;

        $text = "☢️ حمله اتمی به <b>{$target['country_name']}</b> با <b>{$bombs}</b> بمب\n\n"
              . "🎒 <b>تجهیزات شما:</b>\n" . $this->arsenalSummary((int)$this->user['id']) . "\n\n"
              . "🎒 <b>تجهیزات حریف:</b>\n" . $this->arsenalSummary((int)$target['id']) . "\n\n"
              . ($target['has_bunker']
                    ? "🏚️ این کشور پناهگاه اتمی دارد؛ تخریب کاهش‌یافته خواهد بود (~{$destroyPct}٪).\n"
                    : "💥 تخریب تخمینی: ~{$destroyPct}٪ (این کشور پناهگاه اتمی ندارد)\n")
              . "\n⚠️ این عملیات {$bombs} بمب از موجودی شما مصرف می‌کند و اعتبار جهانی‌تان به‌شدت کاهش می‌یابد.\n\nآیا مطمئن هستید؟";
        $this->reply($text, Telegram::inline([
            [['☢️ تایید حمله اتمی', "nuke|confirm|{$targetId}|{$bombs}"], ['❌ انصراف', 'menu|main']],
        ]));
    }

    public function nuclearAttack(int $targetId, int $bombs = NUKE_MIN_BOMBS_PER_ATTACK): void
    {
        if (!$this->user['has_nuke']) { $this->reply('❌ شما برنامه هسته‌ای ندارید.'); return; }
        $owned = $this->nukeBombCount((int)$this->user['id']);
        if ($owned < NUKE_MIN_BOMBS_PER_ATTACK) { $this->reply('❌ بمب کافی ندارید (حداقل ' . NUKE_MIN_BOMBS_PER_ATTACK . ' عدد).', Telegram::backButton('menu|projects')); return; }
        $bombs = max(NUKE_MIN_BOMBS_PER_ATTACK, min($owned, $bombs));

        $stmt = $this->db->prepare("SELECT * FROM users WHERE id=?");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target || $target['id'] === $this->user['id']) { $this->reply('❌ هدف نامعتبر است.'); return; }
        if ($this->activePeaceTreaty((int)$this->user['id'], (int)$target['id'])) {
            $this->reply('❌ پیمان صلح فعال با این کشور دارید.'); return;
        }
        if (($remain = $this->protectionRemainingMin($this->user)) > 0) {
            $this->reply("🛡️ کشور شما تا {$remain} دقیقه دیگر تحت حمایت بازیکنان تازه‌وارد است و نمی‌تواند حمله کند.", Telegram::backButton()); return;
        }
        if ($this->protectionRemainingMin($target) > 0) {
            $this->reply('🛡️ این کشور تازه ثبت‌نام کرده و تحت حمایت است؛ فعلاً نمی‌توانید به آن حمله کنید.', Telegram::backButton()); return;
        }

        $hasBunker = (bool) $target['has_bunker'];
        $destroyPct = $hasBunker
            ? mt_rand(BUNKER_DESTRUCTION_PERCENT - 5, BUNKER_DESTRUCTION_PERCENT)
            : mt_rand(NO_BUNKER_DESTRUCTION_PERCENT - 5, NO_BUNKER_DESTRUCTION_PERCENT);
        $lossFactor   = $destroyPct / 100;
        $militaryLoss = (int) round($target['military'] * $lossFactor);
        $economyLoss  = (int) round($target['economy'] * $lossFactor);
        $goldStolen   = (int) round($target['gold'] * 0.25);
        $oilStolen    = (int) round($target['oil'] * 0.25);
        $attackerLosses = 0; 

        $this->db->beginTransaction();
        $this->db->prepare("UPDATE users SET military=GREATEST(military-?,0), economy=GREATEST(economy-?,0), gold=GREATEST(gold-?,0), oil=GREATEST(oil-?,0), reputation=reputation-10 WHERE id=?")
            ->execute([$militaryLoss, $economyLoss, $goldStolen, $oilStolen, $target['id']]);
        $this->db->prepare("UPDATE users SET gold=gold+?, oil=oil+?, reputation=reputation-15 WHERE id=?")
            ->execute([$goldStolen, $oilStolen, $this->user['id']]);
        $this->db->prepare("UPDATE user_weapons SET quantity=GREATEST(quantity-?,0) WHERE user_id=? AND weapon_key='nuke_bomb'")
            ->execute([$bombs, $this->user['id']]);
        $theirDestroyed = $this->destroyEquipmentFraction((int)$target['id'], $lossFactor);
        $this->db->prepare("INSERT INTO battles (attacker_id, defender_id, battle_type, attacker_won, gold_stolen, oil_stolen, attacker_losses, defender_losses, reported) VALUES (?,?,'nuclear',1,?,?,?,?,1)")
            ->execute([$this->user['id'], $target['id'], $goldStolen, $oilStolen, $attackerLosses, $militaryLoss]);
        $this->db->commit();
        $this->refreshUser();

        $theirDestroyedTxt = $this->formatDestroyedList($theirDestroyed);
        $bombsLeft = max(0, $owned - $bombs);
        $bunkerNote = $hasBunker ? "\n🏚️ پناهگاه اتمی حریف تخریب را کاهش داد." : '';
        $this->reply("☢️ <b>حمله اتمی با {$bombs} بمب انجام شد!</b>\n\nکشور {$target['country_name']} حدود {$destroyPct}٪ ویران شد.{$bunkerNote}\n"
            . "💰 غنیمت: {$this->fmt($goldStolen)} طلا / {$this->fmt($oilStolen)} نفت\n\n"
            . "⚔️ <b>تلفات نیروی انسانی دو طرف:</b>\nتلفات شما: {$this->fmt($attackerLosses)}\nتلفات حریف: {$this->fmt($militaryLoss)}\n\n"
            . "🎒 <b>تجهیزات نابودشده حریف:</b> {$theirDestroyedTxt}\n\n"
            . "💣 بمب باقی‌مانده شما: {$this->fmt($bombsLeft)}\n⚠️ اعتبار جهانی شما کاهش یافت.", Telegram::backButton());

        Telegram::sendMessage($target['telegram_id'], "🚨☢️ کشور شما هدف حمله اتمی {$this->user['country_name']} قرار گرفت!\n"
            . "💥 میزان تخریب: {$destroyPct}٪\n💰 -{$this->fmt($goldStolen)} طلا\n🛢️ -{$this->fmt($oilStolen)} نفت\n⚔️ تلفات نظامی شما: {$this->fmt($militaryLoss)}\n"
            . "🎒 تجهیزات ازدست‌رفته شما: {$theirDestroyedTxt}"
            . ($hasBunker ? "\n🏚️ پناهگاه اتمی شما تخریب را کاهش داد!" : ''));

        $this->postToChannel("☢️ <b>گزارش حمله اتمی</b>\n\n"
            . "🏳️ <b>{$this->user['country_name']}</b> با {$bombs} کلاهک هسته‌ای به <b>{$target['country_name']}</b> حمله کرد.\n"
            . "💥 تخریب: {$destroyPct}٪" . ($hasBunker ? ' (پناهگاه اتمی خسارت را کاهش داد)' : '') . "\n"
            . "💰 غنیمت: {$this->fmt($goldStolen)} طلا / {$this->fmt($oilStolen)} نفت\n"
            . "⚔️ تلفات مدافع: {$this->fmt($militaryLoss)} نفر\n"
            . "🎒 تجهیزات نابودشده مدافع: {$theirDestroyedTxt}");
    }

    public function spyList(int $page = 0): void { $this->targetList('spy', $page); }

    public function spySelect(int $targetId): void
    {
        $stmt = $this->db->prepare("SELECT country_name FROM users WHERE id=?");
        $stmt->execute([$targetId]);
        $name = $stmt->fetchColumn();
        if (!$name) { $this->reply('❌ کشور یافت نشد.'); return; }
        $this->reply("🕵️ جاسوسی از <b>{$name}</b>\n\nهزینه: " . $this->fmt(SPY_COST_GOLD) . " طلا\n\nآیا مطمئن هستید؟", Telegram::inline([
            [['🕵️ تایید عملیات', "spy|confirm|{$targetId}"], ['❌ انصراف', 'menu|main']],
        ]));
    }

    public function spyOn(int $targetId): void
    {
        if ($this->user['gold'] < SPY_COST_GOLD) { $this->reply('❌ طلای کافی ندارید.'); return; }
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id=?");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) { $this->reply('❌ کشور هدف یافت نشد.'); return; }

        $this->db->prepare("UPDATE users SET gold=gold-? WHERE id=?")->execute([SPY_COST_GOLD, $this->user['id']]);

        $chance = SPY_BASE_SUCCESS_PERCENT
            + $this->user['spy_level'] * SPY_EQUIPMENT_BONUS_PERCENT
            - $target['counter_spy_level'] * COUNTER_SPY_EQUIPMENT_BONUS_PERCENT;
        $chance = max(5, min(95, $chance));
        $success = mt_rand(1, 100) <= $chance;

        $this->db->prepare("INSERT INTO espionage_logs (spy_id, target_id, success) VALUES (?,?,?)")
            ->execute([$this->user['id'], $target['id'], $success ? 1 : 0]);
        $this->refreshUser();

        if ($success) {
            $power = $this->computePower((int)$target['id'], (int)$target['military']);
            $allianceName = '—';
            if ($target['alliance_id']) {
                $s = $this->db->prepare("SELECT name FROM alliances WHERE id=?");
                $s->execute([$target['alliance_id']]);
                $allianceName = $s->fetchColumn() ?: '—';
            }
            $stmt = $this->db->prepare("SELECT w.name, uw.quantity FROM user_weapons uw JOIN weapons w ON w.weapon_key=uw.weapon_key WHERE uw.user_id=? AND uw.quantity>0");
            $stmt->execute([$target['id']]);
            $weapons = $stmt->fetchAll();
            $weaponsText = $weapons ? implode("\n", array_map(fn($w) => "  • {$w['name']} × {$w['quantity']}", $weapons)) : '  (تجهیزاتی یافت نشد)';

            $text = "🕵️ <b>گزارش جاسوسی موفق از {$target['country_name']}</b>\n\n"
                  . "💰 طلا: {$this->fmt($target['gold'])}\n"
                  . "🛢️ نفت: {$this->fmt($target['oil'])}\n"
                  . "⚔️ نظامی پایه: {$this->fmt($target['military'])}\n"
                  . "🗡️ قدرت حمله مؤثر: {$this->fmt($power['attack'])}\n"
                  . "🛡️ قدرت دفاع مؤثر: {$this->fmt($power['defense'])}\n"
                  . "☢️ سلاح اتمی: " . ($target['has_nuke'] ? 'دارد' : 'ندارد') . "\n"
                  . "🤝 اتحاد: {$allianceName}\n"
                  . "🔫 تجهیزات:\n{$weaponsText}";
            $this->reply($text, Telegram::backButton());
        } else {
            $this->reply('❌ عملیات جاسوسی شکست خورد! جاسوس شما دستگیر شد.', Telegram::backButton());
            Telegram::sendMessage($target['telegram_id'], "🚨 یک جاسوس از {$this->user['country_name']} تلاش کرد از شما جاسوسی کند اما دستگیر شد!");
        }
    }

    public function spyEquipmentMenu(): void
    {
        $spyLvl = (int)$this->user['spy_level'];
        $cLvl   = (int)$this->user['counter_spy_level'];
        $spyCosts = SPY_EQUIPMENT_COSTS;
        $cCosts   = COUNTER_SPY_EQUIPMENT_COSTS;

        $text = "🎒 <b>تجهیزات جاسوسی و ضدجاسوسی</b>\n\n"
              . "🕵️ سطح تجهیزات جاسوسی شما: {$spyLvl}/3 (+" . ($spyLvl * SPY_EQUIPMENT_BONUS_PERCENT) . "% شانس موفقیت)\n"
              . "🛡️ سطح تجهیزات ضدجاسوسی شما: {$cLvl}/3 (-" . ($cLvl * COUNTER_SPY_EQUIPMENT_BONUS_PERCENT) . "% شانس موفقیت دشمن)\n";

        $rows = [];
        if ($spyLvl < 3) {
            $next = $spyLvl + 1;
            $rows[] = [["⬆️ ارتقا تجهیزات جاسوسی به سطح {$next} ({$this->fmt($spyCosts[$next])})", "spy|buyequip|spy"]];
        }
        if ($cLvl < 3) {
            $next = $cLvl + 1;
            $rows[] = [["⬆️ ارتقا ضدجاسوسی به سطح {$next} ({$this->fmt($cCosts[$next])})", "spy|buyequip|counter"]];
        }
        $rows[] = [['🔙 بازگشت به منو', 'menu|main']];
        $this->reply($text, Telegram::inline($rows));
    }

    public function buySpyEquipment(string $type): void
    {
        $field = $type === 'counter' ? 'counter_spy_level' : 'spy_level';
        $costs = $type === 'counter' ? COUNTER_SPY_EQUIPMENT_COSTS : SPY_EQUIPMENT_COSTS;
        $current = (int)$this->user[$field];
        if ($current >= 3) { $this->reply('❌ شما در حال حاضر بالاترین سطح را دارید.'); return; }
        $next = $current + 1;
        $cost = $costs[$next];
        if ($this->user['gold'] < $cost) { $this->reply('❌ طلای کافی ندارید.'); return; }

        $this->db->prepare("UPDATE users SET gold=gold-?, {$field}={$field}+1 WHERE id=?")->execute([$cost, $this->user['id']]);
        $this->refreshUser();
        $this->reply("✅ تجهیزات به سطح {$next} ارتقا یافت!", Telegram::backButton());
    }

    public function opinions(): void
    {
        $stmt = $this->db->prepare("SELECT o.score, u.country_name FROM opinions o JOIN users u ON u.id=o.target_id WHERE o.user_id=? ORDER BY o.score DESC LIMIT 15");
        $stmt->execute([$this->user['id']]);
        $rows = $stmt->fetchAll();
        if (!$rows) { $this->reply('📮 هنوز رابطه دیپلماتیک ثبت‌شده‌ای ندارید.'); return; }
        $text = "📮 <b>نظر شما نسبت به کشورهای دیگر</b>\n\n";
        foreach ($rows as $r) {
            $emoji = $r['score'] > 20 ? '😊' : ($r['score'] < -20 ? '😡' : '😐');
            $text .= "{$emoji} {$r['country_name']}: {$r['score']}\n";
        }
        $this->reply($text);
    }

    public function freeMoney(): void
    {
        $last = $this->user['last_free_money'];
        if ($last && (time() - strtotime($last)) < FREE_MONEY_COOLDOWN_MIN * 60) {
            $remaining = FREE_MONEY_COOLDOWN_MIN * 60 - (time() - strtotime($last));
            $h = floor($remaining / 3600); $m = ceil(($remaining % 3600) / 60);
            $this->reply("⏳ تاس روزانه بعدی تا {$h} ساعت و {$m} دقیقه دیگر در دسترس است.", Telegram::backButton());
            return;
        }
        $diceRes = Telegram::sendDice($this->chatId, '🎲');
        $value = (int) ($diceRes['result']['dice']['value'] ?? mt_rand(1, 6));
        $reward = $value * FREE_MONEY_PER_PIP;
        $this->db->prepare("UPDATE users SET gold=gold+?, last_free_money=NOW() WHERE id=?")->execute([$reward, $this->user['id']]);
        $this->refreshUser();
        Telegram::sendMessage($this->chatId, "🎲 تاس شما عدد <b>{$value}</b> آمد!\n\n💰 +{$this->fmt($reward)} طلا رایگان", Telegram::backButton());
    }

    public function rankings(): void
    {
        $stmt = $this->db->query("SELECT country_name, (military+economy+tech) AS power FROM users WHERE country_name IS NOT NULL ORDER BY power DESC LIMIT 10");
        $rows = $stmt->fetchAll();
        $text = "🏆 <b>برترین کشورهای جهان</b>\n\n";
        $i = 1;
        foreach ($rows as $r) { $text .= "{$i}. {$r['country_name']} — {$this->fmt($r['power'])}\n"; $i++; }
        $this->reply($text ?: 'رتبه‌بندی موجود نیست.');
    }

    public function allianceMenu(): void
    {
        if ($this->user['alliance_id']) {
            $stmt = $this->db->prepare("SELECT * FROM alliances WHERE id=?");
            $stmt->execute([$this->user['alliance_id']]);
            $alliance = $stmt->fetch();
            $isLeader = $alliance && $alliance['leader_id'] == $this->user['id'];

            $stmt = $this->db->prepare("SELECT country_name FROM users WHERE alliance_id=?");
            $stmt->execute([$this->user['alliance_id']]);
            $members = $stmt->fetchAll();

            $text = "🤝 <b>اتحاد: {$alliance['name']}</b>\n" . ($isLeader ? '👑 شما رهبر این اتحاد هستید\n' : '') . "\nاعضا:\n";
            foreach ($members as $m) $text .= "- {$m['country_name']}\n";

            $rows = [[['💬 چت اتحاد', 'alliance|chat']]];
            if ($isLeader) {
                $rows[] = [['📋 درخواست‌های عضویت', 'alliance|requests']];
                $rows[] = [['🚫 اخراج عضو', 'alliance|kicklist|0']];
            }
            $rows[] = [['🚪 خروج از اتحاد', 'alliance|leave']];
            $rows[] = [['🔙 بازگشت به منو', 'menu|main']];
            $this->reply($text, Telegram::inline($rows));
        } else {
            $text = "🤝 <b>اتحاد</b>\n\nشما عضو هیچ اتحادی نیستید.\n\n💰 هزینه ساخت اتحاد جدید: " . $this->fmt(ALLIANCE_CREATE_COST) . " طلا";
            $rows = [
                [['🏗️ ساخت اتحاد جدید', 'alliance|create']],
                [['🔍 مشاهده و درخواست عضویت', 'alliance|joinlist|0']],
                [['🔙 بازگشت به منو', 'menu|main']],
            ];
            $this->reply($text, Telegram::inline($rows));
        }
    }

    public function askAllianceName(): void
    {
        if ($this->user['gold'] < ALLIANCE_CREATE_COST) { $this->reply('❌ طلای کافی برای ساخت اتحاد ندارید (هزینه: ' . $this->fmt(ALLIANCE_CREATE_COST) . ').', Telegram::backButton()); return; }
        $this->setState('awaiting_alliance_name');
        Telegram::sendMessage($this->chatId, '🏗️ نام اتحاد خود را وارد کنید:');
    }

    public function createAlliance(string $name): void
    {
        $this->setState(null);
        $name = trim(mb_substr($name, 0, 32));
        if ($name === '') { $this->reply('❌ نام معتبر نیست.'); return; }
        if ($this->user['alliance_id']) { $this->reply('❌ شما در حال حاضر عضو یک اتحاد هستید.'); return; }
        if ($this->user['gold'] < ALLIANCE_CREATE_COST) { $this->reply('❌ طلای کافی ندارید.'); return; }

        try {
            $this->db->beginTransaction();
            $this->db->prepare("UPDATE users SET gold=gold-? WHERE id=?")->execute([ALLIANCE_CREATE_COST, $this->user['id']]);
            $this->db->prepare("INSERT INTO alliances (name, leader_id) VALUES (?,?)")->execute([$name, $this->user['id']]);
            $allianceId = $this->db->lastInsertId();
            $this->db->prepare("UPDATE users SET alliance_id=? WHERE id=?")->execute([$allianceId, $this->user['id']]);
            $this->db->commit();
            $this->refreshUser();
            Telegram::sendMessage($this->chatId, "✅ اتحاد «{$name}» ساخته شد و شما رهبر آن هستید!", Telegram::mainMenu());
        } catch (PDOException $e) {
            $this->db->rollBack();
            Telegram::sendMessage($this->chatId, '❌ این نام قبلاً استفاده شده است.');
        }
    }

    public function allianceJoinList(int $page = 0): void
    {
        $perPage = 8;
        $stmt = $this->db->prepare("SELECT id, name FROM alliances ORDER BY id DESC LIMIT {$perPage} OFFSET " . ($page * $perPage));
        $stmt->execute();
        $alliances = $stmt->fetchAll();
        if (!$alliances) { $this->reply('❌ هیچ اتحادی وجود ندارد.', Telegram::backButton()); return; }
        $rows = [];
        foreach ($alliances as $a) $rows[] = [["🤝 {$a['name']}", "alliance|joinsel|{$a['id']}"]];
        $nav = [];
        if ($page > 0) $nav[] = ['⬅️ قبلی', 'alliance|joinlist|' . ($page - 1)];
        if (count($alliances) === $perPage) $nav[] = ['بعدی ➡️', 'alliance|joinlist|' . ($page + 1)];
        if ($nav) $rows[] = $nav;
        $rows[] = [['🔙 بازگشت', 'menu|alliance']];
        $this->reply('یک اتحاد را برای درخواست عضویت انتخاب کنید:', Telegram::inline($rows));
    }

    public function allianceRequestJoin(int $allianceId): void
    {
        if ($this->user['alliance_id']) { $this->reply('❌ شما قبلاً عضو یک اتحاد هستید.'); return; }
        $stmt = $this->db->prepare("SELECT * FROM alliances WHERE id=?");
        $stmt->execute([$allianceId]);
        $alliance = $stmt->fetch();
        if (!$alliance) { $this->reply('❌ اتحاد یافت نشد.'); return; }

        $stmt = $this->db->prepare("SELECT id FROM alliance_join_requests WHERE alliance_id=? AND user_id=? AND status='pending'");
        $stmt->execute([$allianceId, $this->user['id']]);
        if ($stmt->fetch()) { $this->reply('⏳ درخواست شما قبلاً ثبت شده و در انتظار تایید رهبر است.'); return; }

        $this->db->prepare("INSERT INTO alliance_join_requests (alliance_id, user_id) VALUES (?,?)")->execute([$allianceId, $this->user['id']]);
        $reqId = $this->db->lastInsertId();
        $this->reply('✅ درخواست شما برای رهبر اتحاد ارسال شد.', Telegram::backButton());

        $stmt = $this->db->prepare("SELECT telegram_id FROM users WHERE id=?");
        $stmt->execute([$alliance['leader_id']]);
        $leaderTg = $stmt->fetchColumn();
        Telegram::sendMessage($leaderTg, "📋 کشور {$this->user['country_name']} درخواست عضویت در اتحاد «{$alliance['name']}» را دارد.", Telegram::inline([
            [['✅ پذیرش', "alliance|reqaccept|{$reqId}"], ['❌ رد', "alliance|reqreject|{$reqId}"]],
        ]));
    }

    public function allianceRequests(): void
    {
        $stmt = $this->db->prepare("SELECT ar.id, u.country_name FROM alliance_join_requests ar
            JOIN users u ON u.id=ar.user_id WHERE ar.alliance_id=? AND ar.status='pending'");
        $stmt->execute([$this->user['alliance_id']]);
        $reqs = $stmt->fetchAll();
        if (!$reqs) { $this->reply('درخواست عضویت در انتظاری وجود ندارد.', Telegram::backButton('menu|alliance')); return; }
        $rows = [];
        foreach ($reqs as $r) $rows[] = [["✅ {$r['country_name']}", "alliance|reqaccept|{$r['id']}"], ["❌", "alliance|reqreject|{$r['id']}"]];
        $rows[] = [['🔙 بازگشت', 'menu|alliance']];
        $this->reply('📋 درخواست‌های عضویت در انتظار:', Telegram::inline($rows));
    }

    public function allianceRespondRequest(int $reqId, bool $accept): void
    {
        $stmt = $this->db->prepare("SELECT ar.*, u.telegram_id, u.country_name, a.name AS alliance_name, a.leader_id
            FROM alliance_join_requests ar
            JOIN users u ON u.id=ar.user_id
            JOIN alliances a ON a.id=ar.alliance_id
            WHERE ar.id=? AND ar.status='pending'");
        $stmt->execute([$reqId]);
        $req = $stmt->fetch();
        if (!$req) { $this->reply('❌ این درخواست دیگر معتبر نیست.'); return; }
        if ($req['leader_id'] != $this->user['id']) { $this->reply('❌ فقط رهبر اتحاد می‌تواند این کار را انجام دهد.'); return; }

        $status = $accept ? 'accepted' : 'rejected';
        $this->db->prepare("UPDATE alliance_join_requests SET status=? WHERE id=?")->execute([$status, $reqId]);
        if ($accept) {
            $this->db->prepare("UPDATE users SET alliance_id=? WHERE id=?")->execute([$req['alliance_id'], $req['user_id']]);
        }
        $this->reply($accept ? '✅ کاربر به اتحاد پذیرفته شد.' : '❌ درخواست رد شد.', Telegram::backButton('menu|alliance'));
        Telegram::sendMessage($req['telegram_id'], $accept
            ? "✅ درخواست عضویت شما در اتحاد «{$req['alliance_name']}» پذیرفته شد!"
            : "❌ درخواست عضویت شما در اتحاد «{$req['alliance_name']}» رد شد.");
    }

    public function allianceKickList(int $page = 0): void
    {
        $stmt = $this->db->prepare("SELECT id, country_name FROM users WHERE alliance_id=? AND id != ?");
        $stmt->execute([$this->user['alliance_id'], $this->user['id']]);
        $members = $stmt->fetchAll();
        if (!$members) { $this->reply('عضو دیگری برای اخراج وجود ندارد.', Telegram::backButton('menu|alliance')); return; }
        $rows = [];
        foreach ($members as $m) $rows[] = [["🚫 {$m['country_name']}", "alliance|kicksel|{$m['id']}"]];
        $rows[] = [['🔙 بازگشت', 'menu|alliance']];
        $this->reply('عضوی که می‌خواهید اخراج کنید را انتخاب کنید:', Telegram::inline($rows));
    }

    public function allianceKick(int $userId): void
    {
        $stmt = $this->db->prepare("SELECT * FROM alliances WHERE id=?");
        $stmt->execute([$this->user['alliance_id']]);
        $alliance = $stmt->fetch();
        if (!$alliance || $alliance['leader_id'] != $this->user['id']) { $this->reply('❌ فقط رهبر می‌تواند اخراج کند.'); return; }
        $stmt = $this->db->prepare("SELECT telegram_id, country_name FROM users WHERE id=? AND alliance_id=?");
        $stmt->execute([$userId, $this->user['alliance_id']]);
        $member = $stmt->fetch();
        if (!$member) { $this->reply('❌ این کاربر عضو اتحاد شما نیست.'); return; }

        $this->db->prepare("UPDATE users SET alliance_id=NULL WHERE id=?")->execute([$userId]);
        $this->reply("✅ کشور {$member['country_name']} از اتحاد اخراج شد.", Telegram::backButton('menu|alliance'));
        Telegram::sendMessage($member['telegram_id'], "🚫 شما از اتحاد «{$alliance['name']}» اخراج شدید.");
    }

    public function allianceLeave(): void
    {
        if (!$this->user['alliance_id']) { $this->reply('❌ شما عضو هیچ اتحادی نیستید.'); return; }
        $stmt = $this->db->prepare("SELECT leader_id FROM alliances WHERE id=?");
        $stmt->execute([$this->user['alliance_id']]);
        $leaderId = $stmt->fetchColumn();
        if ($leaderId == $this->user['id']) { $this->reply('❌ رهبر نمی‌تواند اتحاد را ترک کند؛ ابتدا رهبری را واگذار یا اتحاد را منحل کنید.', Telegram::backButton('menu|alliance')); return; }
        $this->db->prepare("UPDATE users SET alliance_id=NULL WHERE id=?")->execute([$this->user['id']]);
        $this->refreshUser();
        $this->reply('✅ از اتحاد خارج شدید.', Telegram::backButton());
    }

    public function askAllianceChat(): void
    {
        if (!$this->user['alliance_id']) { $this->reply('❌ شما عضو هیچ اتحادی نیستید.'); return; }
        $this->setState('awaiting_alliance_chat');
        Telegram::sendMessage($this->chatId, '💬 پیام خود را برای اعضای اتحاد بنویسید:');
    }

    public function sendAllianceChat(string $message): void
    {
        $this->setState(null);
        $message = trim(mb_substr($message, 0, 500));
        $this->db->prepare("INSERT INTO alliance_messages (alliance_id, user_id, message) VALUES (?,?,?)")
            ->execute([$this->user['alliance_id'], $this->user['id'], $message]);

        $stmt = $this->db->prepare("SELECT telegram_id FROM users WHERE alliance_id=? AND id != ?");
        $stmt->execute([$this->user['alliance_id'], $this->user['id']]);
        foreach ($stmt->fetchAll() as $m) {
            Telegram::sendMessage($m['telegram_id'], "💬 <b>{$this->user['country_name']} (اتحاد)</b>:\n{$message}");
        }
        Telegram::sendMessage($this->chatId, '✅ پیام برای اعضای اتحاد ارسال شد.', Telegram::mainMenu());
    }

    public function globalMarket(): void
    {
        $stmt = $this->db->query("SELECT m.*, u.country_name FROM market_orders m JOIN users u ON u.id=m.user_id
            WHERE m.status='open' ORDER BY m.created_at DESC LIMIT 12");
        $rows = $stmt->fetchAll();
        $text = "📈 <b>بازار جهانی</b>\n\n";
        $btnRows = [];
        if (!$rows) {
            $text .= "سفارشی در بازار وجود ندارد.\n";
        } else {
            foreach ($rows as $r) {
                $text .= "#{$r['id']} 🏳️ {$r['country_name']} — {$this->fmt($r['amount'])} {$r['resource']} @ {$this->fmt($r['price_gold'])} طلا\n";
                if ($r['user_id'] != $this->user['id']) $btnRows[] = [["خرید #{$r['id']}", "market|buy|{$r['id']}"]];
            }
        }
        $text .= "\n🔹 برای ثبت سفارش فروش، منبع را انتخاب کنید:";
        $btnRows[] = [['💰 فروش طلا', 'market|sell|gold'], ['🛢️ فروش نفت', 'market|sell|oil']];
        $btnRows[] = [['⚔️ فروش نظامی', 'market|sell|military'], ['🔬 فروش فناوری', 'market|sell|tech']];
        $btnRows[] = [['🔙 بازگشت به منو', 'menu|main']];
        $this->reply($text, Telegram::inline($btnRows));
    }

    public function askMarketSellAmount(string $resource): void
    {
        $valid = ['gold', 'oil', 'military', 'tech'];
        if (!in_array($resource, $valid)) { $this->reply('❌ منبع نامعتبر است.'); return; }
        $this->setState('awaiting_market_sell', ['resource' => $resource]);
        Telegram::sendMessage($this->chatId, "🔢 مقدار و قیمت را با یک فاصله تایپ کنید:\nمثال: 100 300\n(یعنی 100 واحد به قیمت کل 300 طلا)");
    }

    public function marketSellEntered(string $resource, string $input): void
    {
        $this->setState(null);
        $parts = preg_split('/\s+/', trim($input));
        $amount = (int) ($parts[0] ?? 0);
        $price = (int) ($parts[1] ?? 0);
        $this->marketSell($resource, $amount, $price);
    }

    public function marketSell(string $resource, int $amount, int $price): void
    {
        $valid = ['gold', 'oil', 'military', 'tech'];
        if (!in_array($resource, $valid) || $amount <= 0 || $price <= 0) { Telegram::sendMessage($this->chatId, '❌ ورودی نامعتبر است. مثال درست: 100 300', Telegram::backButton('menu|market')); return; }
        if (($this->user[$resource] ?? 0) < $amount) { Telegram::sendMessage($this->chatId, '❌ موجودی کافی نیست.', Telegram::backButton('menu|market')); return; }

        $this->db->beginTransaction();
        $this->db->prepare("UPDATE users SET {$resource} = {$resource} - ? WHERE id=?")->execute([$amount, $this->user['id']]);
        $this->db->prepare("INSERT INTO market_orders (user_id, resource, amount, price_gold, order_type) VALUES (?,?,?,?, 'sell')")
            ->execute([$this->user['id'], $resource, $amount, $price]);
        $this->db->commit();
        $this->refreshUser();
        Telegram::sendMessage($this->chatId, "✅ سفارش فروش ثبت شد: {$this->fmt($amount)} {$resource} @ {$this->fmt($price)} طلا.", Telegram::backButton('menu|market'));
    }

    public function marketBuy(int $orderId): void
    {
        $stmt = $this->db->prepare("SELECT * FROM market_orders WHERE id=? AND status='open'");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) { $this->reply('❌ سفارش یافت نشد یا انجام شده.'); return; }
        if ($order['user_id'] === $this->user['id']) { $this->reply('❌ نمی‌توانید سفارش خودتان را بخرید.'); return; }
        if ($this->user['gold'] < $order['price_gold']) { $this->reply('❌ طلای کافی ندارید.'); return; }

        $resource = $order['resource'];
        $this->db->beginTransaction();
        $this->db->prepare("UPDATE users SET gold=gold-?, {$resource}={$resource}+? WHERE id=?")->execute([$order['price_gold'], $order['amount'], $this->user['id']]);
        $this->db->prepare("UPDATE users SET gold=gold+? WHERE id=?")->execute([$order['price_gold'], $order['user_id']]);
        $this->db->prepare("UPDATE market_orders SET status='filled' WHERE id=?")->execute([$orderId]);
        $this->db->commit();
        $this->refreshUser();
        $this->reply("✅ خرید موفق: {$this->fmt($order['amount'])} {$resource}", Telegram::backButton('menu|market'));
    }

    public function referralInfo(string $botUsername): void
    {
        $link = "https://t.me/{$botUsername}?start=ref{$this->user['telegram_id']}";
        $stmt = $this->db->prepare("SELECT COUNT(*) c FROM users WHERE referred_by=?");
        $stmt->execute([$this->user['id']]);
        $count = $stmt->fetch()['c'];
        $this->reply("👥 <b>زیرمجموعه‌گیری</b>\n\nبا دعوت دوستان به ازای هر نفر 1000 طلا جایزه بگیرید:\n\n🔗 {$link}\n\n👤 تعداد زیرمجموعه‌ها: {$count}");
    }

    public function applyReferral(int $referrerTelegramId): void
    {
        if ($this->user['referred_by']) return;
        $stmt = $this->db->prepare("SELECT * FROM users WHERE telegram_id=?");
        $stmt->execute([$referrerTelegramId]);
        $referrer = $stmt->fetch();
        if (!$referrer || $referrer['id'] === $this->user['id']) return;
        $this->db->prepare("UPDATE users SET referred_by=? WHERE id=?")->execute([$referrer['id'], $this->user['id']]);
        $this->db->prepare("UPDATE users SET gold=gold+1000 WHERE id=?")->execute([$referrer['id']]);
        $this->refreshUser();
        Telegram::sendMessage($referrer['telegram_id'], '🎉 یک کاربر جدید از لینک دعوت شما پیوست! 1000 طلا دریافت کردید.');
    }

    public function globalEvents(): void
    {
        $stmt = $this->db->query("SELECT * FROM global_events ORDER BY id DESC LIMIT 5");
        $rows = $stmt->fetchAll();
        $mult = (float) Settings::get('economy_multiplier', '1.0');
        $expires = Settings::get('economy_multiplier_expires', '');
        $isActive = $mult != 1.0 && $expires && strtotime($expires) > time();
        $text = "🌍 <b>رویدادهای جهانی</b>\n\n";
        if ($isActive) {
            $pct = round(($mult - 1) * 100);
            $text .= ($pct >= 0 ? "📈 در حال حاضر درآمد اقتصادی همه کشورها {$pct}٪ افزایش یافته (تا " . date('Y-m-d H:i', strtotime($expires)) . ")\n" : "📉 در حال حاضر درآمد اقتصادی همه کشورها " . abs($pct) . "٪ کاهش یافته (تا " . date('Y-m-d H:i', strtotime($expires)) . ")\n") . "\n";
        } else {
            $text .= "✅ در حال حاضر هیچ رویداد اقتصادی فعالی وجود ندارد (درآمد عادی).\n\n";
        }
        if (!$rows) { $text .= 'تاریخچه‌ای ثبت نشده.'; $this->reply($text); return; }
        $text .= "📜 <b>تاریخچه رویدادها:</b>\n";
        foreach ($rows as $r) {
            $ended = !$r['expires_at'] || strtotime($r['expires_at']) <= time();
            $status = $ended ? '⏹️ پایان‌یافته' : '🟢 فعال';
            $text .= "📌 <b>{$r['title']}</b> ({$status})\n{$r['description']}\n\n";
        }
        $this->reply($text);
    }

    public function blackMarket(): void
    {
        $stmt = $this->db->query("SELECT * FROM black_market_deals WHERE expires_at > NOW() ORDER BY id DESC LIMIT 8");
        $deals = $stmt->fetchAll();
        $stmt = $this->db->query("SELECT * FROM weapons WHERE category='blackmarket' AND is_active=1 ORDER BY cost ASC");
        $weapons = $stmt->fetchAll();

        $text = "🏴‍☠️ <b>بازار سیاه</b>\n\n⚠️ معاملات پرخطرند و احتمال دستگیری وجود دارد!\n\n";
        $rows = [];
        if ($deals) {
            $text .= "📦 <b>معاملات منابع:</b>\n";
            foreach ($deals as $d) {
                $text .= "#{$d['id']} {$d['title']} — {$this->fmt($d['amount'])} {$d['resource']} — {$this->fmt($d['price_gold'])} طلا — ریسک {$d['risk_percent']}%\n";
                $rows[] = [["خرید #{$d['id']}", "blackmarket|buydeal|{$d['id']}"]];
            }
            $text .= "\n";
        }
        if ($weapons) {
            $text .= "🔫 <b>تسلیحات ویژه (فقط بازار سیاه):</b>\n";
            foreach ($weapons as $w) {
                $text .= "{$w['name']} — 🗡️{$w['attack']} 🛡️{$w['defense']} — 💰{$this->fmt($w['cost'])}\n";
                $rows[] = [["{$w['name']} ({$this->fmt($w['cost'])})", "arms|buy|{$w['weapon_key']}"]];
            }
        }
        if (!$deals && !$weapons) $text .= 'در حال حاضر معامله‌ای موجود نیست.';
        $rows[] = [['🔙 بازگشت به منو', 'menu|main']];
        $this->reply($text, Telegram::inline($rows));
    }

    public function blackMarketBuyDeal(int $dealId): void
    {
        $stmt = $this->db->prepare("SELECT * FROM black_market_deals WHERE id=? AND expires_at > NOW()");
        $stmt->execute([$dealId]);
        $deal = $stmt->fetch();
        if (!$deal) { $this->reply('❌ این معامله دیگر در دسترس نیست.'); return; }
        if ($this->user['gold'] < $deal['price_gold']) { $this->reply('❌ طلای کافی ندارید.'); return; }

        $caught = mt_rand(1, 100) <= $deal['risk_percent'];
        if ($caught) {
            $fine = (int) round($deal['price_gold'] * 1.5);
            $this->db->prepare("UPDATE users SET gold=GREATEST(gold-?,0), reputation=reputation-5 WHERE id=?")->execute([$fine, $this->user['id']]);
            $this->refreshUser();
            $this->reply("🚨 دستگیر شدید! {$this->fmt($fine)} طلا جریمه شدید.", Telegram::backButton('menu|blackmarket'));
            return;
        }
        $resource = $deal['resource'];
        $this->db->prepare("UPDATE users SET gold=gold-?, {$resource}={$resource}+? WHERE id=?")->execute([$deal['price_gold'], $deal['amount'], $this->user['id']]);
        $this->refreshUser();
        $this->reply("✅ معامله موفق! {$this->fmt($deal['amount'])} {$resource} دریافت کردید.", Telegram::backButton('menu|blackmarket'));
    }

    private array $projectTypes = [
        'highway'         => ['label' => '🛣️ بزرگراه ملی',    'cost' => 10000,            'effect' => 'economy +30'],
        'university'      => ['label' => '🎓 دانشگاه ملی',    'cost' => 14000,            'effect' => 'tech +30'],
        'defense_shield'  => ['label' => '🛡️ سپر دفاعی',      'cost' => 18000,            'effect' => 'military +50'],
        'nuclear_program' => ['label' => '☢️ برنامه هسته‌ای',  'cost' => NUKE_GOLD_COST,   'effect' => 'has_nuke'],
        'bunker'          => ['label' => '🏚️ پناهگاه اتمی',   'cost' => BUNKER_GOLD_COST, 'effect' => 'has_bunker'],
    ];

    public function nationalProjects(): void
    {
        $stmt = $this->db->prepare("SELECT * FROM national_projects WHERE user_id=?");
        $stmt->execute([$this->user['id']]);
        $owned = $stmt->fetchAll();
        $ownedTypes = array_column($owned, 'project_type');
        $text = "🏗️ <b>پروژه‌های ملی</b>\n\n";
        if ($owned) {
            $text .= "تکمیل‌شده:\n";
            foreach ($owned as $p) $text .= ($this->projectTypes[$p['project_type']]['label'] ?? $p['project_type']) . "\n";
            $text .= "\n";
        }
        $text .= "🏚️ <b>پناهگاه اتمی:</b> هر کشوری می‌تواند بخرد. اگر مورد حمله اتمی قرار بگیرید و پناهگاه داشته باشید، به‌جای " . NO_BUNKER_DESTRUCTION_PERCENT . "٪ تخریب، فقط " . BUNKER_DESTRUCTION_PERCENT . "٪ کشورتان تخریب می‌شود.\n\n";
        $rows = [];
        foreach ($this->projectTypes as $key => $meta) {
            if (in_array($key, $ownedTypes, true)) continue; // already built, don't offer again
            $reqTech = $key === 'nuclear_program' ? ' (نیازمند فناوری ' . NUKE_TECH_REQUIREMENT . '+)' : '';
            $rows[] = [["{$meta['label']} — {$this->fmt($meta['cost'])}{$reqTech}", "projects|build|{$key}"]];
        }
        if ($this->user['has_nuke']) {
            $rows[] = [['☢️ خرید بمب اتمی', 'projects|arsenal']];
        }
        $rows[] = [['🔙 بازگشت به منو', 'menu|main']];
        $this->reply($text, Telegram::inline($rows));
    }

    public function buildProject(string $type): void
    {
        if (!isset($this->projectTypes[$type])) { $this->reply('❌ نوع پروژه نامعتبر است.'); return; }
        $meta = $this->projectTypes[$type];

        $stmt = $this->db->prepare("SELECT id FROM national_projects WHERE user_id=? AND project_type=?");
        $stmt->execute([$this->user['id'], $type]);
        if ($stmt->fetchColumn()) { $this->reply('❌ این پروژه قبلاً تکمیل شده است.', Telegram::backButton('menu|projects')); return; }

        if ($type === 'nuclear_program' && $this->user['tech'] < NUKE_TECH_REQUIREMENT) { $this->reply('❌ فناوری کافی ندارید.'); return; }
        if ($this->user['gold'] < $meta['cost']) { $this->reply('❌ طلای کافی ندارید.'); return; }

        $this->db->beginTransaction();
        $this->db->prepare("UPDATE users SET gold=gold-? WHERE id=?")->execute([$meta['cost'], $this->user['id']]);
        match ($type) {
            'highway'        => $this->db->prepare("UPDATE users SET economy=economy+30 WHERE id=?")->execute([$this->user['id']]),
            'university'     => $this->db->prepare("UPDATE users SET tech=tech+30 WHERE id=?")->execute([$this->user['id']]),
            'defense_shield' => $this->db->prepare("UPDATE users SET military=military+50 WHERE id=?")->execute([$this->user['id']]),
            'nuclear_program'=> $this->db->prepare("UPDATE users SET has_nuke=1 WHERE id=?")->execute([$this->user['id']]),
            'bunker'         => $this->db->prepare("UPDATE users SET has_bunker=1 WHERE id=?")->execute([$this->user['id']]),
        };
        $this->db->prepare("INSERT INTO national_projects (user_id, project_type) VALUES (?,?)")->execute([$this->user['id'], $type]);
        $this->db->commit();
        $this->refreshUser();
        $extra = $type === 'nuclear_program' ? "\n\nحالا می‌توانید از «پروژه‌های ملی → خرید بمب اتمی» بمب بخرید." : '';
        $this->reply("✅ پروژه {$meta['label']} تکمیل شد! ({$meta['effect']}){$extra}", Telegram::backButton('menu|projects'));
    }

    public function nukeArsenalMenu(): void
    {
        if (!$this->user['has_nuke']) { $this->reply('❌ ابتدا باید «برنامه هسته‌ای» را در بخش پروژه‌های ملی تکمیل کنید.', Telegram::backButton('menu|projects')); return; }
        $owned = $this->nukeBombCount((int)$this->user['id']);
        $cost = NUKE_BOMB_GOLD_COST;
        $text = "☢️ <b>خرید بمب اتمی</b>\n\n💣 تعداد بمب فعلی شما: {$owned}\n💰 قیمت هر بمب: {$this->fmt($cost)} طلا\n\n"
              . "⚠️ برای حمله اتمی حداقل به " . NUKE_MIN_BOMBS_PER_ATTACK . " بمب نیاز دارید.";
        $this->reply($text, Telegram::inline([
            [['➕ خرید ۱ بمب', 'projects|buynuke|1'], ['➕ خرید ۵ بمب', 'projects|buynuke|5']],
            [['🔢 مقدار دلخواه', 'projects|nukeqty']],
            [['🔙 بازگشت', 'menu|projects']],
        ]));
    }

    public function buyNukeBombs(int $qty): void
    {
        if (!$this->user['has_nuke']) { $this->reply('❌ ابتدا باید برنامه هسته‌ای را تکمیل کنید.', Telegram::backButton('menu|projects')); return; }
        if ($qty <= 0) { $this->reply('❌ مقدار نامعتبر است.'); return; }
        $cost = NUKE_BOMB_GOLD_COST * $qty;
        if ($this->user['gold'] < $cost) { $this->reply('❌ طلای کافی ندارید. هزینه کل: ' . $this->fmt($cost), Telegram::backButton('menu|projects')); return; }

        $this->db->beginTransaction();
        $this->db->prepare("UPDATE users SET gold=gold-? WHERE id=?")->execute([$cost, $this->user['id']]);
        $this->db->prepare("INSERT INTO user_weapons (user_id, weapon_key, quantity) VALUES (?, 'nuke_bomb', ?)
            ON DUPLICATE KEY UPDATE quantity = quantity + ?")->execute([$this->user['id'], $qty, $qty]);
        $this->db->commit();
        $this->refreshUser();
        $this->reply("✅ {$qty} بمب اتمی خریداری شد.\n💰 -{$this->fmt($cost)} طلا\n💣 موجودی فعلی: {$this->nukeBombCount((int)$this->user['id'])}", Telegram::backButton('menu|projects'));
    }

    public function askNukeQty(): void
    {
        if (!$this->user['has_nuke']) { $this->reply('❌ ابتدا باید برنامه هسته‌ای را تکمیل کنید.', Telegram::backButton('menu|projects')); return; }
        $this->setState('awaiting_nuke_qty');
        Telegram::sendMessage($this->chatId, "🔢 چند بمب اتمی می‌خواهید بخرید؟ (عدد را تایپ کنید)\nقیمت واحد: " . $this->fmt(NUKE_BOMB_GOLD_COST) . " طلا");
    }

    public function nukeQtyEntered(string $input): void
    {
        $qty = (int) preg_replace('/\D/', '', $input);
        $this->setState(null);
        if ($qty <= 0) { $this->reply('❌ عدد نامعتبر است.'); return; }
        $this->buyNukeBombs($qty);
    }

    public function diplomacyMenu(): void
    {
        $stmt = $this->db->prepare("SELECT pt.id, pt.expires_at, u.country_name FROM peace_treaties pt
            JOIN users u ON u.id = (CASE WHEN pt.country_a_id=? THEN pt.country_b_id ELSE pt.country_a_id END)
            WHERE pt.status='active' AND pt.expires_at > NOW() AND (pt.country_a_id=? OR pt.country_b_id=?)");
        $stmt->execute([$this->user['id'], $this->user['id'], $this->user['id']]);
        $treaties = $stmt->fetchAll();

        $text = "🤝 <b>مذاکره و دیپلماسی</b>\n\n";
        $rows = [
            [['☮️ درخواست صلح', 'diplomacy|type|peace']],
            [['🤝 درخواست اتحاد', 'diplomacy|type|alliance']],
            [['💱 درخواست تجارت', 'diplomacy|type|trade']],
        ];
        if ($treaties) {
            $text .= "📜 پیمان‌های صلح فعال:\n";
            foreach ($treaties as $t) {
                $text .= "☮️ {$t['country_name']} — تا " . date('Y-m-d', strtotime($t['expires_at'])) . "\n";
                $rows[] = [["💔 شکستن پیمان با {$t['country_name']}", "diplomacy|break|{$t['id']}"]];
            }
        }
        $rows[] = [['🔙 بازگشت به منو', 'menu|main']];
        $this->reply($text, Telegram::inline($rows));
    }

    public function diplomacyTargetList(string $type, int $page = 0): void { $this->targetList("diplomacy|{$type}", $page); }

    public function diplomacySelect(string $type, int $targetId): void
    {
        $stmt = $this->db->prepare("SELECT country_name FROM users WHERE id=?");
        $stmt->execute([$targetId]);
        $name = $stmt->fetchColumn();
        if (!$name) { $this->reply('❌ کشور یافت نشد.'); return; }
        $label = ['peace' => 'صلح', 'alliance' => 'اتحاد', 'trade' => 'تجارت'][$type] ?? $type;
        $this->reply("درخواست {$label} برای <b>{$name}</b> ارسال شود؟", Telegram::inline([
            [['✅ ارسال درخواست', "diplomacy|confirm|{$type}|{$targetId}"], ['❌ انصراف', 'menu|main']],
        ]));
    }

    public function sendDiplomacyRequest(string $type, int $targetId): void
    {
        $stmt = $this->db->prepare("SELECT telegram_id, country_name FROM users WHERE id=?");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) { $this->reply('❌ کشور یافت نشد.'); return; }

        $this->db->prepare("INSERT INTO diplomacy_requests (from_id, to_id, request_type) VALUES (?,?,?)")
            ->execute([$this->user['id'], $targetId, $type]);
        $reqId = $this->db->lastInsertId();
        $this->reply('✅ درخواست ارسال شد.', Telegram::backButton());

        $label = ['peace' => 'صلح', 'alliance' => 'اتحاد', 'trade' => 'تجارت'][$type] ?? $type;
        Telegram::sendMessage($target['telegram_id'], "🤝 درخواست {$label} از {$this->user['country_name']} دریافت کردید.", Telegram::inline([
            [['✅ پذیرش', "diplomacy|accept|{$reqId}"], ['❌ رد', "diplomacy|reject|{$reqId}"]],
        ]));
    }

    public function respondDiplomacy(int $reqId, bool $accept): void
    {
        $stmt = $this->db->prepare("SELECT * FROM diplomacy_requests WHERE id=? AND to_id=? AND status='pending'");
        $stmt->execute([$reqId, $this->user['id']]);
        $req = $stmt->fetch();
        if (!$req) { $this->reply('❌ درخواست یافت نشد یا قبلاً پاسخ داده شده.'); return; }

        $status = $accept ? 'accepted' : 'rejected';
        $this->db->prepare("UPDATE diplomacy_requests SET status=? WHERE id=?")->execute([$status, $reqId]);

        if ($accept) {
            $this->db->prepare("INSERT INTO opinions (user_id, target_id, score) VALUES (?,?,20),(?,?,20) ON DUPLICATE KEY UPDATE score = score + 20")
                ->execute([$req['from_id'], $req['to_id'], $req['to_id'], $req['from_id']]);
            if ($req['request_type'] === 'peace') {
                $this->db->prepare("INSERT INTO peace_treaties (country_a_id, country_b_id, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL " . PEACE_TREATY_DAYS . " DAY))")
                    ->execute([$req['from_id'], $req['to_id']]);
            }
        }
        $this->reply($accept ? '✅ درخواست پذیرفته شد.' : '❌ درخواست رد شد.', Telegram::backButton());

        $stmt = $this->db->prepare("SELECT telegram_id FROM users WHERE id=?");
        $stmt->execute([$req['from_id']]);
        $fromTg = $stmt->fetchColumn();
        Telegram::sendMessage($fromTg, $accept
            ? "✅ کشور {$this->user['country_name']} درخواست {$req['request_type']} شما را پذیرفت!"
            : "❌ کشور {$this->user['country_name']} درخواست {$req['request_type']} شما را رد کرد.");

        if ($accept) {
            $stmt = $this->db->prepare("SELECT country_name FROM users WHERE id=?");
            $stmt->execute([$req['from_id']]);
            $fromName = $stmt->fetchColumn();
            $label = ['peace' => '☮️ پیمان صلح', 'alliance' => '🤝 درخواست اتحاد', 'trade' => '💱 توافق تجاری'][$req['request_type']] ?? $req['request_type'];
            $labelPlain = ['peace' => 'پیمان صلح', 'alliance' => 'پیمان اتحاد', 'trade' => 'توافق تجاری'][$req['request_type']] ?? $req['request_type'];
            $this->postToChannel("{$label} <b>برقرار شد!</b>\n\n"
                . "🏳️ <b>{$fromName}</b> و <b>{$this->user['country_name']}</b> رسماً یک {$labelPlain} امضا کردند.");
        }
    }

    public function breakTreaty(int $treatyId): void
    {
        $stmt = $this->db->prepare("SELECT * FROM peace_treaties WHERE id=? AND status='active' AND (country_a_id=? OR country_b_id=?)");
        $stmt->execute([$treatyId, $this->user['id'], $this->user['id']]);
        $treaty = $stmt->fetch();
        if (!$treaty) { $this->reply('❌ پیمان یافت نشد.'); return; }
        if ($this->user['gold'] < PEACE_BREAK_GOLD_COST) { $this->reply('❌ برای شکستن پیمان به ' . $this->fmt(PEACE_BREAK_GOLD_COST) . ' طلا نیاز دارید.'); return; }

        $otherId = $treaty['country_a_id'] == $this->user['id'] ? $treaty['country_b_id'] : $treaty['country_a_id'];
        $this->db->prepare("UPDATE peace_treaties SET status='broken' WHERE id=?")->execute([$treatyId]);
        $this->db->prepare("UPDATE users SET gold=gold-?, reputation=reputation-? WHERE id=?")
            ->execute([PEACE_BREAK_GOLD_COST, PEACE_BREAK_REPUTATION_PENALTY, $this->user['id']]);
        $this->refreshUser();

        $stmt = $this->db->prepare("SELECT telegram_id, country_name FROM users WHERE id=?");
        $stmt->execute([$otherId]);
        $other = $stmt->fetch();
        $this->reply('💔 پیمان صلح شکسته شد. اکنون می‌توانید به این کشور حمله کنید.', Telegram::backButton());
        Telegram::sendMessage($other['telegram_id'], "💔 کشور {$this->user['country_name']} پیمان صلح را شکست! دیگر در امان نیستید.");
        $this->postToChannel("💔 <b>پیمان صلح شکسته شد!</b>\n\n"
            . "🏳️ <b>{$this->user['country_name']}</b> پیمان صلح خود با <b>{$other['country_name']}</b> را یک‌جانبه لغو کرد؛ از این پس دو طرف می‌توانند به هم حمله کنند.");
    }

    public function warMap(): void
    {
        $stmt = $this->db->prepare("SELECT b.*, u1.country_name AS attacker_name, u2.country_name AS defender_name
            FROM battles b JOIN users u1 ON u1.id=b.attacker_id JOIN users u2 ON u2.id=b.defender_id
            WHERE b.attacker_id=? OR b.defender_id=? ORDER BY b.id DESC LIMIT 10");
        $stmt->execute([$this->user['id'], $this->user['id']]);
        $rows = $stmt->fetchAll();
        if (!$rows) { $this->reply('🗺️ هنوز درگیری‌ای ثبت نشده.'); return; }
        $text = "🗺️ <b>نقشه جنگی {$this->user['country_name']}</b>\n\n";
        foreach ($rows as $r) {
            $icon = $r['battle_type'] === 'nuclear' ? '☢️' : '💣';
            $result = $r['attacker_won'] ? '🟢 پیروزی مهاجم' : '🔴 دفاع موفق';
            $text .= "{$icon} {$r['attacker_name']} ⚔️ {$r['defender_name']} — {$result}\n";
        }
        $this->reply($text);
    }

    public function pmList(int $page = 0): void { $this->targetList('pm', $page); }

    public function pmSelect(int $targetId): void
    {
        $stmt = $this->db->prepare("SELECT country_name FROM users WHERE id=?");
        $stmt->execute([$targetId]);
        $name = $stmt->fetchColumn();
        if (!$name) { $this->reply('❌ کشور یافت نشد.'); return; }
        $this->setState('awaiting_pm_body', ['target_id' => $targetId]);
        Telegram::sendMessage($this->chatId, "✍️ پیام محرمانه خود برای «{$name}» را بنویسید:");
    }

    public function sendPrivateMessage(int $targetId, string $message): void
    {
        $this->setState(null);
        $message = trim(mb_substr($message, 0, 500));
        $stmt = $this->db->prepare("SELECT telegram_id, country_name FROM users WHERE id=?");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) { Telegram::sendMessage($this->chatId, '❌ کشور هدف یافت نشد.'); return; }

        $this->db->prepare("INSERT INTO private_messages (from_id, to_id, message) VALUES (?,?,?)")->execute([$this->user['id'], $targetId, $message]);
        Telegram::sendMessage($this->chatId, '✅ پیام محرمانه ارسال شد.', Telegram::mainMenu());
        Telegram::sendMessage($target['telegram_id'], "🕊️ <b>پیام محرمانه از {$this->user['country_name']}</b>\n\n{$message}");
    }
}
