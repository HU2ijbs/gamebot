<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/Telegram.php';
require_once __DIR__ . '/includes/Settings.php';
require_once __DIR__ . '/includes/Game.php';
require_once __DIR__ . '/includes/Admin.php';

const BOT_USERNAME = 'ایدی بات';

function isChannelMember(int $telegramId, ?array $user = null, bool $forceRefresh = false): bool
{
    $ref = Settings::channelRef();
    if (!$ref) return true; 

    if (!$forceRefresh && $user && !empty($user['channel_checked_at'])) {
        $ageSec = time() - strtotime($user['channel_checked_at']);
        $wasMember = (bool) $user['channel_member'];
        if ($wasMember && $ageSec < CHANNEL_CHECK_CACHE_MIN * 60) return true;
        if (!$wasMember && $ageSec < CHANNEL_CHECK_NEGATIVE_CACHE_SEC) return false;
    }

    $res = Telegram::getChatMember($ref, $telegramId);
    $status = $res['result']['status'] ?? null;
    $ok = in_array($status, ['member', 'administrator', 'creator'], true);

    if ($user) {
        db()->prepare("UPDATE users SET channel_checked_at=NOW(), channel_member=? WHERE telegram_id=?")
            ->execute([$ok ? 1 : 0, $telegramId]);
    }
    return $ok;
}

function sendJoinPrompt(int $chatId): void
{
    $url = Settings::joinUrl();
    $rows = [];
    if ($url) $rows[] = [['📡 عضویت در کانال', $url, 'url']];
    $rows[] = [['✅ عضو شدم، بررسی مجدد', 'checkjoin']];
    Telegram::sendMessage($chatId, "⚠️ برای استفاده از ربات ابتدا باید در کانال ما عضو شوید.", Telegram::inline($rows));
}

function handleUpdate(array $update): void
{
    if (isset($update['callback_query'])) {
        handleCallback($update['callback_query']);
        return;
    }

    $message = $update['message'] ?? null;
    if (!$message || !isset($message['chat'])) return;

    $chatId     = $message['chat']['id'];
    $telegramId = $message['from']['id'];
    $username   = $message['from']['username'] ?? null;
    $text       = trim($message['text'] ?? '');

    if ($text === '') return;

    if (str_starts_with($text, '/admin')) {
        if (Admin::handle($text, $chatId, $telegramId)) return;
    }

    $isAdmin = Admin::isAdmin($telegramId);
    $user = Game::findOrCreateUser($telegramId, $username);
    if ($user['is_banned']) { Telegram::sendMessage($chatId, '🚫 حساب شما توسط مدیریت مسدود شده است.'); return; }

    if (!$isAdmin && !isChannelMember($telegramId, $user)) {
        sendJoinPrompt($chatId);
        return;
    }

    $game = new Game($user, $chatId);

    if (str_starts_with($text, '/start')) {
        $parts = explode(' ', $text, 2);
        if (isset($parts[1]) && str_starts_with($parts[1], 'ref')) {
            $refId = (int) substr($parts[1], 3);
            if ($refId > 0) $game->applyReferral($refId);
        }
        if ($game->needsCountryName()) {
            $game->askCountryName();
        } else {
            $game->showMainMenu();
        }
        return;
    }

    if ($game->needsCountryName()) {
        if ($game->getState() === 'awaiting_country_name') {
            $game->setCountryName($text);
        } else {
            $game->askCountryName();
        }
        return;
    }

    $state = $game->getState();
    if ($state) {
        $data = $game->getStateData();
        switch ($state) {
            case 'awaiting_statement':       $game->publishStatement($text); return;
            case 'awaiting_weapon_qty':      $game->weaponQtyEntered($data['key'] ?? '', $text); return;
            case 'awaiting_alliance_name':    $game->createAlliance($text); return;
            case 'awaiting_alliance_chat':    $game->sendAllianceChat($text); return;
            case 'awaiting_pm_body':          $game->sendPrivateMessage((int)($data['target_id'] ?? 0), $text); return;
            case 'awaiting_trade_amount':     $game->tradeAmountEntered($data['action'] ?? '', $text); return;
            case 'awaiting_nuke_qty':         $game->nukeQtyEntered($text); return;
            case 'awaiting_market_sell':      $game->marketSellEntered($data['resource'] ?? '', $text); return;
        }
    }

    if (str_starts_with($text, '/')) {
        Telegram::sendMessage($chatId, 'از منوی زیر یک گزینه انتخاب کنید 👇', Telegram::mainMenu());
        return;
    }

    Telegram::sendMessage($chatId, 'از منوی زیر یک گزینه انتخاب کنید 👇', Telegram::mainMenu());
}

function handleCallback(array $callback): void
{
    $callbackId = $callback['id'];
    $chatId     = $callback['message']['chat']['id'] ?? null;
    $msgId      = $callback['message']['message_id'] ?? null;
    $telegramId = $callback['from']['id'];
    $username   = $callback['from']['username'] ?? null;
    $data       = $callback['data'] ?? '';

    if (!$chatId) { Telegram::answerCallbackQuery($callbackId); return; }

    Telegram::answerCallbackQuery($callbackId);

    $isAdmin = Admin::isAdmin($telegramId);
    $user = Game::findOrCreateUser($telegramId, $username);
    if ($user['is_banned']) { Telegram::sendMessage($chatId, '🚫 حساب شما مسدود شده است.'); return; }

    if ($data === 'checkjoin') {
        if ($isAdmin || isChannelMember($telegramId, $user, true)) {
            Telegram::editMessage($chatId, $msgId, '✅ عضویت شما تایید شد! دستور /start را بفرستید.');
        } else {
            Telegram::editMessage($chatId, $msgId, '❌ هنوز عضو کانال نشده‌اید.');
        }
        return;
    }

    if (!$isAdmin && !isChannelMember($telegramId, $user)) {
        sendJoinPrompt($chatId);
        return;
    }
    if (empty($user['country_name'])) { Telegram::sendMessage($chatId, 'ابتدا /start را بفرستید و نام کشور خود را وارد کنید.'); return; }

    if (str_starts_with($data, 'statement|') && !$isAdmin) return;

    $game = new Game($user, $chatId, $msgId);
    $parts = explode('|', $data);
    $domain = $parts[0] ?? '';

    switch ($domain) {
        case 'menu':
            switch ($parts[1] ?? '') {
                case 'main':       $game->showMainMenu(); break;
                case 'dice':       $game->freeMoney(); break;
                case 'country':    $game->myCountry(); break;
                case 'companies':  $game->companiesMenu(); break;
                case 'arms':       $game->armsMenu(); break;
                case 'trade':      $game->tradeMenu(); break;
                case 'oil':        $game->oilMenu(); break;
                case 'attack':     $game->attackList(0); break;
                case 'nuke':       $game->nukeList(0); break;
                case 'spy':
                    Telegram::editMessage($chatId, $msgId, "🕵️ <b>بخش جاسوسی</b>\n\nیک گزینه را انتخاب کنید:", Telegram::inline([
                        [['🎯 شروع عملیات جاسوسی', 'spy|list|0']],
                        [['🎒 تجهیزات جاسوسی/ضدجاسوسی', 'spy|equip']],
                        [['🔙 بازگشت به منو', 'menu|main']],
                    ]));
                    break;
                case 'opinions':   $game->opinions(); break;
                case 'alliance':   $game->allianceMenu(); break;
                case 'market':     $game->globalMarket(); break;
                case 'blackmarket':$game->blackMarket(); break;
                case 'projects':   $game->nationalProjects(); break;
                case 'diplomacy':  $game->diplomacyMenu(); break;
                case 'warmap':     $game->warMap(); break;
                case 'statement':  $game->askStatement(); break;
                case 'rankings':   $game->rankings(); break;
                case 'warlaws':    $game->warLaws(); break;
                case 'referral':   $game->referralInfo(BOT_USERNAME); break;
                case 'events':     $game->globalEvents(); break;
                case 'pm':         $game->pmList(0); break;
            }
            break;

        case 'companies':
            if (($parts[1] ?? '') === 'buy') $game->buyCompany($parts[2] ?? '');
            if (($parts[1] ?? '') === 'collect') $game->collectIncome();
            break;

        case 'arms':
            if (($parts[1] ?? '') === 'buy') $game->askWeaponQty($parts[2] ?? '');
            if (($parts[1] ?? '') === 'doconfirm') $game->confirmWeaponPurchase($parts[2] ?? '', (int)($parts[3] ?? 0));
            break;

        case 'oil':
            if (($parts[1] ?? '') === 'collect') $game->collectFreeOil();
            break;

        case 'attack':
            match ($parts[1] ?? '') {
                'list'    => $game->attackList((int)($parts[2] ?? 0)),
                'sel'     => $game->attackSelect((int)($parts[2] ?? 0)),
                'pct'     => $game->attackPercentSelect((int)($parts[2] ?? 0), (int)($parts[3] ?? ATTACK_PERCENT_MAX)),
                'confirm' => $game->militaryAttack((int)($parts[2] ?? 0), (int)($parts[3] ?? ATTACK_PERCENT_MAX)),
                default   => null,
            };
            break;

        case 'nuke':
            match ($parts[1] ?? '') {
                'list'    => $game->nukeList((int)($parts[2] ?? 0)),
                'sel'     => $game->nukeSelect((int)($parts[2] ?? 0)),
                'qty'     => $game->nukeQtySelect((int)($parts[2] ?? 0), (int)($parts[3] ?? NUKE_MIN_BOMBS_PER_ATTACK)),
                'confirm' => $game->nuclearAttack((int)($parts[2] ?? 0), (int)($parts[3] ?? NUKE_MIN_BOMBS_PER_ATTACK)),
                default   => null,
            };
            break;

        case 'spy':
            match ($parts[1] ?? '') {
                'list'     => $game->spyList((int)($parts[2] ?? 0)),
                'sel'      => $game->spySelect((int)($parts[2] ?? 0)),
                'confirm'  => $game->spyOn((int)($parts[2] ?? 0)),
                'equip'    => $game->spyEquipmentMenu(),
                'buyequip' => $game->buySpyEquipment($parts[2] ?? ''),
                default    => null,
            };
            break;

        case 'alliance':
            switch ($parts[1] ?? '') {
                case 'create':    $game->askAllianceName(); break;
                case 'joinlist':  $game->allianceJoinList((int)($parts[2] ?? 0)); break;
                case 'joinsel':   $game->allianceRequestJoin((int)($parts[2] ?? 0)); break;
                case 'requests':  $game->allianceRequests(); break;
                case 'reqaccept': $game->allianceRespondRequest((int)($parts[2] ?? 0), true); break;
                case 'reqreject': $game->allianceRespondRequest((int)($parts[2] ?? 0), false); break;
                case 'kicklist':  $game->allianceKickList((int)($parts[2] ?? 0)); break;
                case 'kicksel':   $game->allianceKick((int)($parts[2] ?? 0)); break;
                case 'leave':     $game->allianceLeave(); break;
                case 'chat':      $game->askAllianceChat(); break;
            }
            break;

        case 'market':
            if (($parts[1] ?? '') === 'buy') $game->marketBuy((int)($parts[2] ?? 0));
            if (($parts[1] ?? '') === 'sell') $game->askMarketSellAmount($parts[2] ?? '');
            break;

        case 'trade':
            if (($parts[1] ?? '') === 'ask') $game->askTradeAmount($parts[2] ?? '');
            break;

        case 'blackmarket':
            if (($parts[1] ?? '') === 'buydeal') $game->blackMarketBuyDeal((int)($parts[2] ?? 0));
            break;

        case 'projects':
            switch ($parts[1] ?? '') {
                case 'build':   $game->buildProject($parts[2] ?? ''); break;
                case 'arsenal': $game->nukeArsenalMenu(); break;
                case 'buynuke': $game->buyNukeBombs((int)($parts[2] ?? 0)); break;
                case 'nukeqty': $game->askNukeQty(); break;
            }
            break;

        case 'diplomacy':
            $p1 = $parts[1] ?? '';
            if ($p1 === 'type') {
                $game->diplomacyTargetList($parts[2] ?? 'peace', 0);
            } elseif ($p1 === 'accept') {
                $game->respondDiplomacy((int)($parts[2] ?? 0), true);
            } elseif ($p1 === 'reject') {
                $game->respondDiplomacy((int)($parts[2] ?? 0), false);
            } elseif ($p1 === 'break') {
                $game->breakTreaty((int)($parts[2] ?? 0));
            } elseif ($p1 === 'confirm') {
                $game->sendDiplomacyRequest($parts[2] ?? 'peace', (int)($parts[3] ?? 0));
            } elseif (in_array($p1, ['peace', 'alliance', 'trade'], true)) {
                $sub = $parts[2] ?? '';
                if ($sub === 'sel') $game->diplomacySelect($p1, (int)($parts[3] ?? 0));
                if ($sub === 'list') $game->diplomacyTargetList($p1, (int)($parts[3] ?? 0));
            }
            break;

        case 'pm':
            if (($parts[1] ?? '') === 'sel') $game->pmSelect((int)($parts[2] ?? 0));
            if (($parts[1] ?? '') === 'list') $game->pmList((int)($parts[2] ?? 0));
            break;

        case 'statement':
            if (($parts[1] ?? '') === 'approve') $game->adminRespondStatement((int)($parts[2] ?? 0), true);
            if (($parts[1] ?? '') === 'reject') $game->adminRespondStatement((int)($parts[2] ?? 0), false);
            break;
    }
}
