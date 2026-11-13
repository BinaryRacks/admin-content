<?php
/**
 * Protection against forged form submissions (CSRF).
 * Every form that changes something carries a secret token from the admin's session,
 * and send-type forms also carry a one-time code so refreshing the page can't send twice.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

if (!defined('WHMCS')) {
    exit;
}

final class Csrf
{
    private const SESSION_KEY = 'brwa_csrf';
    private const ONCE_KEY = 'brwa_once';

    /** Returns this session's token, creating it the first time. */
    public static function token(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY]) || strlen($_SESSION[self::SESSION_KEY]) !== 64) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /** Returns the hidden form fields: our token, plus WHMCS's own token so WHMCS accepts the POST too. */
    public static function fields(bool $oneTime = false): string
    {
        $html = '<input type="hidden" name="brwa_token" value="' . Text::e(self::token()) . '">';
        if (function_exists('generate_token')) {
            $html .= generate_token('form');
        }
        if ($oneTime) {
            $code = bin2hex(random_bytes(16));
            $_SESSION[self::ONCE_KEY][$code] = time();
            // Keep only the 50 newest codes.
            $_SESSION[self::ONCE_KEY] = array_slice($_SESSION[self::ONCE_KEY], -50, null, true);
            $html .= '<input type="hidden" name="brwa_once" value="' . Text::e($code) . '">';
        }
        return $html;
    }

    /** Returns true if the request is a POST carrying this session's token. */
    public static function check(array $post, string $method): bool
    {
        if ($method !== 'POST') {
            return false;
        }
        $sent = $post['brwa_token'] ?? '';
        return is_string($sent) && isset($_SESSION[self::SESSION_KEY]) && hash_equals((string) $_SESSION[self::SESSION_KEY], $sent);
    }

    /** Uses up a one-time code. Returns false if it is missing, unknown or already used. */
    public static function consumeOnce(array $post): bool
    {
        $code = $post['brwa_once'] ?? '';
        if (!is_string($code) || !isset($_SESSION[self::ONCE_KEY][$code])) {
            return false;
        }
        unset($_SESSION[self::ONCE_KEY][$code]);
        return true;
    }
}
