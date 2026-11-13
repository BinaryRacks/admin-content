<?php
/**
 * Stores the pre-configured messages (table mod_brwa_messages): automatic events,
 * manual messages and marketing messages. Each one names a Meta-approved template,
 * its language and which WHMCS details fill its blanks. All admin input is checked here.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit;
}

final class Messages
{
    public const TABLE = 'mod_brwa_messages';
    public const KINDS = ['event', 'manual', 'marketing'];

    /** Meta template names: lower-case letters, digits and underscores, up to 512 characters. */
    public const TEMPLATE_PATTERN = '/^[a-z0-9_]{1,512}$/';

    /** Language codes such as "en", "en_GB" or "pt_BR". */
    public const LANGUAGE_PATTERN = '/^[a-z]{2,3}(_[A-Z]{2})?$/';

    /** Creates the built-in rows (events and the due invoice reminder) if they are missing. */
    public static function seed(): void
    {
        $now = Clock::nowDb();
        foreach (Catalogue::EVENTS as $key => $event) {
            if (!Capsule::table(self::TABLE)->where('msg_key', $key)->exists()) {
                Capsule::table(self::TABLE)->insert([
                    'msg_key' => $key, 'kind' => 'event', 'category' => $event['category'], 'label' => $event['label'],
                    'template_name' => $key, 'language' => null, 'params' => json_encode($event['params']),
                    'enabled' => 0, 'builtin' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        foreach (Catalogue::BUILTIN_MANUAL as $key => $message) {
            if (!Capsule::table(self::TABLE)->where('msg_key', $key)->exists()) {
                Capsule::table(self::TABLE)->insert([
                    'msg_key' => $key, 'kind' => 'manual', 'category' => null, 'label' => $message['label'],
                    'template_name' => 'invoice_reminder', 'language' => null, 'params' => json_encode($message['params']),
                    'enabled' => 1, 'builtin' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    /** Returns one message by key, or null. */
    public static function find(string $key): ?object
    {
        $row = Capsule::table(self::TABLE)->where('msg_key', $key)->first();
        return $row === null ? null : self::decorate($row);
    }

    /** Returns one message by its numeric ID, or null. */
    public static function findById(int $id): ?object
    {
        $row = Capsule::table(self::TABLE)->where('id', $id)->first();
        return $row === null ? null : self::decorate($row);
    }

    /** Returns all messages of a kind (event, manual or marketing), in a stable order. */
    public static function allOfKind(string $kind, bool $enabledOnly = false): array
    {
        $query = Capsule::table(self::TABLE)->where('kind', $kind)->orderBy('id');
        if ($enabledOnly) {
            $query->where('enabled', 1);
        }
        $rows = [];
        foreach ($query->get() as $row) {
            $rows[] = self::decorate($row);
        }
        return $rows;
    }

    /** Returns the language a message is sent in: its own override, or the module's language. */
    public static function languageFor(object $message): string
    {
        $own = (string) ($message->language ?? '');
        return $own !== '' ? $own : Settings::get('language');
    }

    /** Returns the record type a message is about (invoice, service ...), or null. */
    public static function scopeOf(object $message): ?string
    {
        if ($message->kind === 'event') {
            return Catalogue::event($message->msg_key)['scope'] ?? null;
        }
        return $message->kind === 'manual' && Placeholders::needsInvoice($message->paramList) ? 'invoice' : null;
    }

    /**
     * Checks admin input for a message and returns a list of problems (empty list = valid).
     * $input keys: label, template_name, language, params (comma-separated text).
     */
    public static function validate(array $input, string $kind, ?string $scope): array
    {
        $errors = [];
        if (!Text::isValidLabel((string) ($input['label'] ?? ''))) {
            $errors[] = 'Name must be 1 to 100 characters.';
        }
        if (preg_match(self::TEMPLATE_PATTERN, (string) ($input['template_name'] ?? '')) !== 1) {
            $errors[] = 'Template name may only use lower-case letters, numbers and _ (as in Meta).';
        }
        $language = (string) ($input['language'] ?? '');
        if ($language !== '' && preg_match(self::LANGUAGE_PATTERN, $language) !== 1) {
            $errors[] = 'Language must look like "en" or "en_GB".';
        }
        $params = self::parseParams((string) ($input['params'] ?? ''));
        if ($params === null) {
            $errors[] = 'Blanks must be a comma-separated list of allowed details.';
        } else {
            $allowed = Placeholders::allowedFor($kind, $scope);
            foreach ($params as $param) {
                if (!in_array($param, $allowed, true)) {
                    $errors[] = 'This detail is not allowed for this message: ' . $param;
                }
            }
            if (count($params) > Placeholders::MAX_PARAMS) {
                $errors[] = 'At most ' . Placeholders::MAX_PARAMS . ' blanks are allowed.';
            }
        }
        return $errors;
    }

    /** Turns "client_firstname, invoice_id" into a list; returns null if the text has odd characters. */
    public static function parseParams(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (strlen($text) > 1000 || preg_match('/^[a-z_ ,]+$/', $text) !== 1) {
            return null;
        }
        $parts = array_filter(array_map('trim', explode(',', $text)), fn ($p) => $p !== '');
        return array_values($parts);
    }

    /** Updates an existing message after validate() said it is fine. Built-in fields stay fixed. */
    public static function update(int $id, array $input, bool $enabled): void
    {
        Capsule::table(self::TABLE)->where('id', $id)->update([
            'label'         => (string) $input['label'],
            'template_name' => (string) $input['template_name'],
            'language'      => ((string) ($input['language'] ?? '')) === '' ? null : (string) $input['language'],
            'params'        => json_encode(self::parseParams((string) $input['params']) ?? []),
            'enabled'       => $enabled ? 1 : 0,
            'updated_at'    => Clock::nowDb(),
        ]);
    }

    /** Switches a message on or off. */
    public static function setEnabled(int $id, bool $enabled): void
    {
        Capsule::table(self::TABLE)->where('id', $id)->update(['enabled' => $enabled ? 1 : 0, 'updated_at' => Clock::nowDb()]);
    }

    /** Adds a new manual or marketing message and returns its key. */
    public static function create(string $kind, array $input, bool $enabled): string
    {
        if (!in_array($kind, ['manual', 'marketing'], true)) {
            throw new \InvalidArgumentException('Only manual and marketing messages can be added.');
        }
        $now = Clock::nowDb();
        // A random key, so it can never clash with or imitate a built-in key.
        $key = $kind . '_' . bin2hex(random_bytes(6));
        Capsule::table(self::TABLE)->insert([
            'msg_key' => $key, 'kind' => $kind, 'category' => null, 'label' => (string) $input['label'],
            'template_name' => (string) $input['template_name'],
            'language' => ((string) ($input['language'] ?? '')) === '' ? null : (string) $input['language'],
            'params' => json_encode(self::parseParams((string) $input['params']) ?? []),
            'enabled' => $enabled ? 1 : 0, 'builtin' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $key;
    }

    /** Deletes a message the admin added. Built-in messages can only be switched off. */
    public static function delete(int $id): bool
    {
        return Capsule::table(self::TABLE)->where('id', $id)->where('builtin', 0)->delete() > 0;
    }

    /** Adds the decoded list of blanks to a database row. */
    private static function decorate(object $row): object
    {
        $params = json_decode((string) $row->params, true);
        $row->paramList = is_array($params) ? array_values(array_filter($params, 'is_string')) : [];
        $row->enabled = (int) $row->enabled === 1;
        $row->builtin = (int) $row->builtin === 1;
        return $row;
    }
}
