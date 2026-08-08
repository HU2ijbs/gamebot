<?php

class Settings
{
    public static function get(string $key, string $default = ''): string
    {
        $stmt = db()->prepare("SELECT setting_value FROM admin_settings WHERE setting_key=?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    }

    public static function set(string $key, string $value): void
    {
        $stmt = db()->prepare("INSERT INTO admin_settings (setting_key, setting_value) VALUES (?,?)
            ON DUPLICATE KEY UPDATE setting_value=?");
        $stmt->execute([$key, $value, $value]);
    }

    public static function channelUsername(): ?string
    {
        $u = self::get('channel_username', '');
        return $u !== '' ? $u : null;
    }

    public static function channelId(): string|int|null
    {
        $id = self::get('channel_id', '');
        return $id !== '' ? $id : null;
    }

    public static function channelRef(): string|int|null
    {
        $id = self::channelId();
        if ($id) return $id;
        $u = self::channelUsername();
        if ($u) return '@' . ltrim($u, '@');
        if (defined('CHANNEL_USERNAME_FALLBACK') && CHANNEL_USERNAME_FALLBACK) {
            return '@' . ltrim(CHANNEL_USERNAME_FALLBACK, '@');
        }
        return null;
    }

    public static function joinUrl(): ?string
    {
        $invite = self::get('channel_invite_link', '');
        if ($invite !== '') return $invite;
        $u = self::channelUsername() ?: (defined('CHANNEL_USERNAME_FALLBACK') ? CHANNEL_USERNAME_FALLBACK : null);
        if ($u) return 'https://t.me/' . ltrim($u, '@');
        return null;
    }

    public static function channelLockEnabled(): bool
    {
        return self::channelRef() !== null;
    }
}
