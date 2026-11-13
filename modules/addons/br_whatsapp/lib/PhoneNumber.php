<?php
/**
 * Turns the phone number saved in WHMCS into the international format WhatsApp needs
 * (digits only, with country code, e.g. "447911123456"), or rejects it.
 * Also makes the masked form for logs and the one-way hash used to match STOP replies.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

if (!defined('WHMCS')) {
    exit;
}

final class PhoneNumber
{
    /**
     * Returns the number in international digits-only format, or null if it is not usable.
     * $phone is the WHMCS phone field (e.g. "+44.7911123456" or "07911 123456"),
     * $countryCallingCode is the client's calling code from WHMCS (e.g. "44").
     */
    public static function normalise(string $phone, string $countryCallingCode): ?string
    {
        $phone = trim($phone);
        if ($phone === '' || strlen($phone) > 40) {
            return null;
        }
        // Allow only digits, spaces and common separators; anything else (letters, "ext") is rejected.
        if (preg_match('/^[+0-9 ().\-\/]+$/', $phone) !== 1) {
            return null;
        }
        $hasPlus = str_starts_with($phone, '+');
        $digits = (string) preg_replace('/\D/', '', $phone);

        if ($hasPlus) {
            $international = $digits;                 // already has the country code
        } elseif (str_starts_with($digits, '00')) {
            $international = substr($digits, 2);      // "0044..." international dialling prefix
        } else {
            $cc = (string) preg_replace('/\D/', '', $countryCallingCode);
            if ($cc === '' || strlen($cc) > 3) {
                return null;
            }
            // Drop the national "0" trunk prefix (07911 -> 7911), except Italy, which keeps it.
            if ($cc !== '39' && str_starts_with($digits, '0')) {
                $digits = substr($digits, 1);
            }
            $international = $cc . $digits;
        }

        // E.164 numbers are 8 to 15 digits and never start with 0.
        if (preg_match('/^[1-9]\d{7,14}$/', $international) !== 1) {
            return null;
        }
        return $international;
    }

    /** Hides most of a number for logs, e.g. "447911123456" becomes "+44******3456". */
    public static function mask(string $international): string
    {
        $digits = (string) preg_replace('/\D/', '', $international);
        if (strlen($digits) < 8) {
            return '';
        }
        return '+' . substr($digits, 0, 2) . str_repeat('*', strlen($digits) - 6) . substr($digits, -4);
    }

    /**
     * Returns a one-way hash of the number (HMAC-SHA256 with a secret key made at install).
     * The real number can't be worked out from it, but the same number always gives the same hash.
     */
    public static function hash(string $international): string
    {
        $key = Settings::secret('hash_key');
        if ($key === '') {
            // Make the key the first time it is needed.
            $key = bin2hex(random_bytes(32));
            Settings::setSecret('hash_key', $key);
        }
        return hash_hmac('sha256', (string) preg_replace('/\D/', '', $international), $key);
    }
}
