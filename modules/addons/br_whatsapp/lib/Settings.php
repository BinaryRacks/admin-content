<?php
/**
 * Reads and saves the module's settings (table mod_brwa_settings).
 * Secrets (access token, app secret, verify token, hashing key) are stored with WHMCS encrypt()
 * and are only ever decrypted in memory when needed. They are never shown or logged.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit;
}

final class Settings
{
    public const TABLE = 'mod_brwa_settings';

    /** Settings that are secret and must be encrypted. */
    public const SECRET_KEYS = ['access_token', 'app_secret', 'verify_token', 'hash_key'];

    /** Default values used until the admin saves something else. */
    public const DEFAULTS = [
        'phone_number_id'      => '',
        'waba_id'              => '',
        'api_version'          => 'v21.0',
        'language'             => 'en',
        'batch_size'           => '50',
        'daily_cap'            => '1000',
        'log_days'             => '90',
        'domain_expiry_days'   => '14',
        'roles_send'           => '',   // empty = every role that can open the module
        'roles_bulk'           => '',   // empty = Full Administrator only
        'roles_settings'       => '',   // empty = Full Administrator only
        'delete_on_deactivate' => '0',
        'cat_invoices'         => '1',
        'cat_services'         => '1',
        'cat_tickets'          => '1',
        'cat_domains'          => '1',
    ];

    /** Values already read in this request, so the database is asked only once. */
    private static ?array $cache = null;

    /** Forgets the cached values (used after saving and by tests). */
    public static function reset(): void
    {
        self::$cache = null;
    }

    /** Returns a normal (not secret) setting as text. */
    public static function get(string $key): string
    {
        if (in_array($key, self::SECRET_KEYS, true)) {
            throw new \LogicException('Use Settings::secret() for secret values.');
        }
        $all = self::load();
        return $all[$key] ?? (self::DEFAULTS[$key] ?? '');
    }

    /** Returns a whole-number setting, kept inside the allowed range. */
    public static function int(string $key, int $min, int $max): int
    {
        $value = (int) self::get($key);
        return max($min, min($max, $value));
    }

    /** Returns true if a yes/no setting is switched on. */
    public static function bool(string $key): bool
    {
        return self::get($key) === '1';
    }

    /** Returns a list of admin role IDs stored as "1,3,5". */
    public static function roleIds(string $key): array
    {
        $ids = [];
        foreach (explode(',', self::get($key)) as $part) {
            if (ctype_digit(trim($part))) {
                $ids[] = (int) trim($part);
            }
        }
        return array_values(array_unique($ids));
    }

    /** Saves a normal (not secret) setting. */
    public static function set(string $key, string $value): void
    {
        if (in_array($key, self::SECRET_KEYS, true)) {
            throw new \LogicException('Use Settings::setSecret() for secret values.');
        }
        self::write($key, $value);
    }

    /** Returns a decrypted secret, or an empty string if it was never saved. */
    public static function secret(string $key): string
    {
        $stored = self::load()[$key] ?? '';
        if ($stored === '') {
            return '';
        }
        // decrypt() is the official WHMCS function that undoes encrypt().
        return (string) decrypt($stored);
    }

    /** Returns true if a secret has been saved (without decrypting it). */
    public static function hasSecret(string $key): bool
    {
        return (self::load()[$key] ?? '') !== '';
    }

    /** Encrypts and saves a secret. An empty value removes it. */
    public static function setSecret(string $key, string $plain): void
    {
        if (!in_array($key, self::SECRET_KEYS, true)) {
            throw new \LogicException('Not a secret setting: ' . $key);
        }
        self::write($key, $plain === '' ? '' : (string) encrypt($plain));
    }

    /** Returns true when all Meta details needed to send messages are saved. */
    public static function isConfigured(): bool
    {
        return self::get('phone_number_id') !== '' && self::hasSecret('access_token');
    }

    /** Reads all settings from the database once per request. */
    private static function load(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            if (Capsule::schema()->hasTable(self::TABLE)) {
                foreach (Capsule::table(self::TABLE)->get() as $row) {
                    self::$cache[(string) $row->setting] = (string) $row->value;
                }
            }
        }
        return self::$cache;
    }

    /** Inserts or updates one setting row. */
    private static function write(string $key, string $value): void
    {
        $exists = Capsule::table(self::TABLE)->where('setting', $key)->exists();
        if ($exists) {
            Capsule::table(self::TABLE)->where('setting', $key)->update(['value' => $value]);
        } else {
            Capsule::table(self::TABLE)->insert(['setting' => $key, 'value' => $value]);
        }
        self::reset();
    }
}
