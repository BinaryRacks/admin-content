<?php
/**
 * Everything about whether a client may receive WhatsApp messages:
 * - the client-editable "disable_whatsapp" tick box (blocks every WhatsApp message)
 * - the WHMCS marketing opt-in (needed for bulk marketing only; this module never changes it)
 * Checked when a message is queued AND again just before it is sent.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit;
}

final class Consent
{
    public const FIELD_NAME = 'disable_whatsapp';

    /** WHMCS shows the text after "|" to clients as the field's friendly name. */
    public const FIELD_FULL_NAME = 'disable_whatsapp|Disable WhatsApp messages';

    private static ?int $fieldId = null;

    /** Forgets the cached field ID (used by tests). */
    public static function reset(): void
    {
        self::$fieldId = null;
    }

    /** Returns the ID of the disable_whatsapp client custom field, or null if it doesn't exist. */
    public static function fieldId(): ?int
    {
        if (self::$fieldId === null) {
            $id = Capsule::table('tblcustomfields')
                ->where('type', 'client')
                ->where(function ($q) {
                    $q->where('fieldname', self::FIELD_NAME)->orWhere('fieldname', 'like', self::FIELD_NAME . '|%');
                })
                ->orderBy('id')
                ->value('id');
            self::$fieldId = $id === null ? null : (int) $id;
        }
        return self::$fieldId;
    }

    /** Creates the disable_whatsapp tick box if it doesn't exist yet (on activation). */
    public static function ensureField(): int
    {
        $existing = self::fieldId();
        if ($existing !== null) {
            return $existing;   // reuse the field that's already there
        }
        $now = Clock::nowDb();
        $id = Capsule::table('tblcustomfields')->insertGetId([
            'type'         => 'client',
            'relid'        => 0,
            'fieldname'    => self::FIELD_FULL_NAME,
            'fieldtype'    => 'tickbox',
            'description'  => 'Tick to stop all WhatsApp messages from us. You will still receive emails.',
            'fieldoptions' => '',
            'regexpr'      => '',
            'adminonly'    => '',   // empty = clients can see and change it
            'required'     => '',
            'showorder'    => 'on', // shown on the order form and profile
            'showinvoice'  => '',
            'sortorder'    => 0,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        self::$fieldId = (int) $id;
        return self::$fieldId;
    }

    /** Returns true if the client has ticked disable_whatsapp (or it was ticked by a STOP reply). */
    public static function isWhatsAppDisabled(int $clientId): bool
    {
        $fieldId = self::fieldId();
        if ($fieldId === null) {
            return false;
        }
        $value = Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', $fieldId)
            ->where('relid', $clientId)
            ->value('value');
        return in_array(strtolower(trim((string) $value)), ['on', '1', 'yes', 'true'], true);
    }

    /** Ticks disable_whatsapp for a client through the official WHMCS API. Returns true on success. */
    public static function disableWhatsApp(int $clientId): bool
    {
        $fieldId = self::fieldId() ?? self::ensureField();
        // UpdateClient expects custom fields as a base64-encoded, serialized array of fieldId => value.
        $result = localAPI('UpdateClient', [
            'clientid'     => $clientId,
            'customfields' => base64_encode(serialize([$fieldId => 'on'])),
        ]);
        return ($result['result'] ?? '') === 'success';
    }

    /** Returns true if the client's account may receive non-marketing messages (not closed). */
    public static function accountIsOpen(int $clientId): bool
    {
        $status = Capsule::table('tblclients')->where('id', $clientId)->value('status');
        return $status !== null && $status !== 'Closed';
    }

    /** Returns true if the client may receive bulk marketing: active account and WHMCS marketing opt-in on. */
    public static function marketingAllowed(int $clientId): bool
    {
        $client = Capsule::table('tblclients')->where('id', $clientId)->first(['status', 'marketing_emails_opt_in']);
        return $client !== null && $client->status === 'Active' && (int) $client->marketing_emails_opt_in === 1;
    }

    /**
     * Returns null if a message may be sent, or the reason it may not.
     * $marketing = true also requires the marketing opt-in.
     */
    public static function blockReason(int $clientId, bool $marketing): ?string
    {
        if (!self::accountIsOpen($clientId)) {
            return 'account_closed_or_missing';
        }
        if (self::isWhatsAppDisabled($clientId)) {
            return 'disable_whatsapp_ticked';
        }
        if ($marketing && !self::marketingAllowed($clientId)) {
            return 'no_marketing_opt_in';
        }
        return null;
    }
}
