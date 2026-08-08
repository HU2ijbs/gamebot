<?php

class Telegram
{
    private static ?\CurlHandle $ch = null;

    public static function call(string $method, array $params = [])
    {
        if (self::$ch === null) {
            self::$ch = curl_init();
            curl_setopt_array(self::$ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TCP_NODELAY    => true,
                CURLOPT_ENCODING       => '', // accept gzip/deflate, smaller payloads over the wire
                CURLOPT_FORBID_REUSE   => false,
                CURLOPT_FRESH_CONNECT  => false,
            ]);
            if (defined('TELEGRAM_PROXY') && TELEGRAM_PROXY) {
                curl_setopt(self::$ch, CURLOPT_PROXY, TELEGRAM_PROXY);
            }
        }

        curl_setopt(self::$ch, CURLOPT_URL, TELEGRAM_API . $method);
        curl_setopt(self::$ch, CURLOPT_POSTFIELDS, $params);

        $result = curl_exec(self::$ch);
        if ($result === false) {
            error_log('Telegram cURL error: ' . curl_error(self::$ch));
            curl_close(self::$ch);
            self::$ch = null;
        }
        return json_decode($result, true);
    }

    public static function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null, string $parseMode = 'HTML')
    {
        $params = [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => $parseMode,
            'disable_web_page_preview' => true,
        ];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        }
        return self::call('sendMessage', $params);
    }

    public static function editMessage(int|string $chatId, int $messageId, string $text, ?array $replyMarkup = null, string $parseMode = 'HTML')
    {
        $params = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => $parseMode,
        ];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        }
        $res = self::call('editMessageText', $params);
        if (!($res['ok'] ?? false)) {
            self::sendMessage($chatId, $text, $replyMarkup, $parseMode);
        }
        return $res;
    }

    public static function answerCallbackQuery(string $callbackId, string $text = '', bool $alert = false)
    {
        return self::call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text'              => $text,
            'show_alert'        => $alert,
        ]);
    }

    public static function getChatMember(int|string $chatId, int $userId): array
    {
        return self::call('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]) ?? [];
    }

    public static function sendDice(int|string $chatId, string $emoji = '🎲'): array
    {
        return self::call('sendDice', ['chat_id' => $chatId, 'emoji' => $emoji]) ?? [];
    }

    public static function inline(array $rows): array
    {
        $keyboard = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $btn) {
                $b = ['text' => $btn[0]];
                if (isset($btn[2]) && $btn[2] === 'url') {
                    $b['url'] = $btn[1];
                } else {
                    $b['callback_data'] = $btn[1];
                }
                $line[] = $b;
            }
            $keyboard[] = $line;
        }
        return ['inline_keyboard' => $keyboard];
    }

    public static function mainMenu(): array
    {
        return self::inline([
            [['🎲 پول رایگان', 'menu|dice'], ['🌍 کشور من', 'menu|country']],
            [['🏢 شرکت‌ها', 'menu|companies'], ['🛒 بازار تسلیحات', 'menu|arms']],
            [['📦 صادرات/واردات', 'menu|trade'], ['🛢️ نفت و انرژی', 'menu|oil']],
            [['💣 حمله نظامی', 'menu|attack'], ['☢️ حمله اتمی', 'menu|nuke']],
            [['🕵️ جاسوسی', 'menu|spy'], ['📮 نظر کشورها', 'menu|opinions']],
            [['🤝 اتحاد', 'menu|alliance'], ['📈 بازار جهانی', 'menu|market']],
            [['🏴‍☠️ بازار سیاه', 'menu|blackmarket'], ['🏗️ پروژه‌های ملی', 'menu|projects']],
            [['🤝 دیپلماسی', 'menu|diplomacy'], ['🗺️ نقشه جنگی', 'menu|warmap']],
            [['📢 بیانیه رسمی', 'menu|statement'], ['🏆 رتبه‌بندی‌ها', 'menu|rankings']],
            [['⚔️ قوانین جنگ', 'menu|warlaws'], ['👥 زیرمجموعه‌گیری', 'menu|referral']],
            [['🌍 رویداد جهانی', 'menu|events'], ['🕊️ گفتگوی محرمانه', 'menu|pm']],
        ]);
    }

    public static function backButton(string $target = 'menu|main'): array
    {
        return self::inline([[['🔙 بازگشت به منو', $target]]]);
    }
}
