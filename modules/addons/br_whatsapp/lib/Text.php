<?php
/**
 * Small text helpers used everywhere: safe HTML output, cleaning values for WhatsApp templates,
 * and removing personal data and secrets from error messages before they are logged (GDPR).
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

if (!defined('WHMCS')) {
    exit;
}

final class Text
{
    /** Escapes text for safe display in an HTML page (stops XSS). */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Cleans one value before it goes into a WhatsApp template blank.
     * Meta rejects new lines, tabs and long runs of spaces, so those are flattened.
     */
    public static function templateValue(string $value, int $maxLength = 250): string
    {
        // Make sure the text is valid UTF-8; broken bytes are dropped.
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        // Replace control characters (new lines, tabs, etc.) with a space.
        $value = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value);
        // Collapse several spaces into one.
        $value = (string) preg_replace('/ {2,}/', ' ', $value);
        $value = trim($value);
        if (mb_strlen($value) > $maxLength) {
            $value = rtrim(mb_substr($value, 0, $maxLength - 1)) . '…';
        }
        // Meta refuses empty blanks, so a dash is sent instead.
        return $value === '' ? '-' : $value;
    }

    /**
     * Removes personal data and secrets from error text before it is stored (GDPR, no leaks).
     * Phone numbers, emails, access tokens and long numbers are replaced by placeholders.
     */
    public static function scrub(string $text, int $maxLength = 255): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = (string) preg_replace('/[\p{Cc}]+/u', ' ', $text);
        // Email addresses.
        $text = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text);
        // Bearer tokens and Meta access tokens (they start with "EAA").
        $text = (string) preg_replace('/Bearer\s+\S+/i', 'Bearer [hidden]', $text);
        $text = (string) preg_replace('/\bEAA[A-Za-z0-9]{10,}\b/', '[token]', $text);
        // Any run of 6 or more digits (phone numbers, IDs), allowing spaces, dots and dashes between them.
        $text = (string) preg_replace('/\+?\d[\d .\-()]{4,}\d/', '[number]', $text);
        $text = trim((string) preg_replace('/\s{2,}/', ' ', $text));
        return mb_substr($text, 0, $maxLength);
    }

    /** Checks a short label typed by an admin: 1-100 printable characters, no control characters. */
    public static function isValidLabel(string $label): bool
    {
        $length = mb_strlen($label);
        return $length >= 1 && $length <= 100 && preg_match('/^[^\p{Cc}\p{Cf}]+$/u', $label) === 1;
    }
}
