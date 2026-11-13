<?php
/**
 * Remembers which client a phone number belongs to, as a one-way hash only (table mod_brwa_contacts).
 * Updated each time a message is sent, so a STOP reply from that number can find the right client(s).
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit;
}

final class Contacts
{
    public const TABLE = 'mod_brwa_contacts';

    /** Saves (or updates) the hashed number for a client. */
    public static function remember(int $clientId, string $international): void
    {
        $row = ['phone_hash' => PhoneNumber::hash($international), 'updated_at' => Clock::nowDb()];
        if (Capsule::table(self::TABLE)->where('client_id', $clientId)->exists()) {
            Capsule::table(self::TABLE)->where('client_id', $clientId)->update($row);
        } else {
            Capsule::table(self::TABLE)->insert(['client_id' => $clientId] + $row);
        }
    }

    /** Returns the IDs of every client whose last-used number matches (several clients may share one). */
    public static function clientsFor(string $international): array
    {
        $ids = [];
        foreach (Capsule::table(self::TABLE)->where('phone_hash', PhoneNumber::hash($international))->get(['client_id']) as $row) {
            $ids[] = (int) $row->client_id;
        }
        return $ids;
    }
}
