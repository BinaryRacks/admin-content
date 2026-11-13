<?php
/**
 * The fixed, allowed list of WHMCS details that can fill a template's blanks ({{1}}, {{2}} ...),
 * and the code that looks those details up. Admins can only pick from this list,
 * and every record is checked to belong to the client before its details are used.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit;
}

final class Placeholders
{
    /** key => [label shown to admins, scope it belongs to, sample value for test sends] */
    public const ALLOWED = [
        'client_firstname' => ['Client first name',            'client',  'Alex'],
        'client_lastname'  => ['Client last name',             'client',  'Smith'],
        'client_fullname'  => ['Client full name',             'client',  'Alex Smith'],
        'client_company'   => ['Client company name',          'client',  'Example Ltd'],
        'company_name'     => ['Your company name (WHMCS)',    'company', 'Binary Racks'],
        'invoice_id'       => ['Invoice number',               'invoice', '1001'],
        'invoice_total'    => ['Invoice total with currency',  'invoice', '£10.00 GBP'],
        'invoice_duedate'  => ['Invoice due date',             'invoice', '20 Nov 2026'],
        'service_name'     => ['Service / product name',       'service', 'VPS Small'],
        'service_domain'   => ['Service domain or hostname',   'service', 'example.com'],
        'suspend_reason'   => ['Suspension reason',            'service', 'Overdue on payment'],
        'ticket_number'    => ['Ticket number',                'ticket',  'ABC-123456'],
        'ticket_subject'   => ['Ticket subject',               'ticket',  'Server question'],
        'domain_name'      => ['Domain name',                  'domain',  'example.com'],
        'domain_expiry'    => ['Domain expiry date',           'domain',  '01 Dec 2026'],
    ];

    /** A template can have at most this many blanks filled by the module. */
    public const MAX_PARAMS = 10;

    /**
     * Returns the placeholder keys allowed for a message.
     * Events: client + company + their own record. Manual: client + company + invoice. Marketing: client + company.
     */
    public static function allowedFor(string $kind, ?string $scope): array
    {
        $scopes = ['client', 'company'];
        if ($kind === 'event' && $scope !== null) {
            $scopes[] = $scope;
        } elseif ($kind === 'manual') {
            $scopes[] = 'invoice';
        }
        $keys = [];
        foreach (self::ALLOWED as $key => [, $placeholderScope]) {
            if (in_array($placeholderScope, $scopes, true)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /** Returns true if any of the keys needs an invoice (manual messages then ask the admin to pick one). */
    public static function needsInvoice(array $keys): bool
    {
        foreach ($keys as $key) {
            if ((self::ALLOWED[$key][1] ?? '') === 'invoice') {
                return true;
            }
        }
        return false;
    }

    /** Returns the sample values used by the "Send test" button. */
    public static function samples(array $keys): array
    {
        return array_map(fn (string $key) => self::ALLOWED[$key][2] ?? '-', $keys);
    }

    /**
     * Looks up the real values for each key, in order.
     * $scope and $relatedId say which record the message is about (e.g. invoice 1001).
     * Throws RecordNotFound if the record is missing or belongs to another client.
     */
    public static function resolve(array $keys, int $clientId, ?string $scope, ?int $relatedId): array
    {
        $client = Capsule::table('tblclients')->where('id', $clientId)->first();
        if ($client === null) {
            throw new RecordNotFound('client');
        }
        $record = null;
        if (self::scopeNeeded($keys, $scope) !== null) {
            $record = self::loadRecord((string) self::scopeNeeded($keys, $scope), $clientId, $relatedId);
        }

        $values = [];
        foreach ($keys as $key) {
            $values[] = Text::templateValue(self::valueFor($key, $client, $record));
        }
        return $values;
    }

    /** Works out which record type the keys need (invoice, service, ...), or null for client-only keys. */
    private static function scopeNeeded(array $keys, ?string $scope): ?string
    {
        foreach ($keys as $key) {
            $keyScope = self::ALLOWED[$key][1] ?? null;
            if ($keyScope !== null && !in_array($keyScope, ['client', 'company'], true)) {
                return $keyScope;
            }
        }
        return null;
    }

    /** Loads the record and checks it belongs to the client (stops data from other clients leaking). */
    private static function loadRecord(string $scope, int $clientId, ?int $relatedId): object
    {
        if ($relatedId === null || $relatedId <= 0) {
            throw new RecordNotFound($scope);
        }
        $table = match ($scope) {
            'invoice' => 'tblinvoices',
            'service' => 'tblhosting',
            'ticket'  => 'tbltickets',
            'domain'  => 'tbldomains',
            default   => throw new RecordNotFound($scope),
        };
        $row = Capsule::table($table)->where('id', $relatedId)->first();
        if ($row === null || (int) $row->userid !== $clientId) {
            throw new RecordNotFound($scope);
        }
        $row->brwa_scope = $scope;
        return $row;
    }

    /** Returns the raw text for one key. */
    private static function valueFor(string $key, object $client, ?object $record): string
    {
        return match ($key) {
            'client_firstname' => (string) $client->firstname,
            'client_lastname'  => (string) $client->lastname,
            'client_fullname'  => trim($client->firstname . ' ' . $client->lastname),
            'client_company'   => (string) $client->companyname,
            'company_name'     => (string) Capsule::table('tblconfiguration')->where('setting', 'CompanyName')->value('value'),
            // Use the custom invoice number if WHMCS has one, otherwise the invoice ID.
            'invoice_id'       => ((string) ($record->invoicenum ?? '')) !== '' ? (string) $record->invoicenum : (string) ($record->id ?? ''),
            'invoice_total'    => self::money((float) ($record->total ?? 0), (int) ($client->currency ?? 0)),
            'invoice_duedate'  => self::date((string) ($record->duedate ?? '')),
            'service_name'     => (string) Capsule::table('tblproducts')->where('id', (int) ($record->packageid ?? 0))->value('name'),
            'service_domain'   => (string) ($record->domain ?? ''),
            'suspend_reason'   => (string) ($record->suspendreason ?? ''),
            'ticket_number'    => (string) ($record->tid ?? ''),
            'ticket_subject'   => (string) ($record->title ?? ''),
            'domain_name'      => (string) ($record->domain ?? ''),
            'domain_expiry'    => self::date((string) ($record->expirydate ?? '')),
            default            => '',
        };
    }

    /** Formats an amount with the client's currency, e.g. "£10.00 GBP". */
    private static function money(float $amount, int $currencyId): string
    {
        $currency = Capsule::table('tblcurrencies')->where('id', $currencyId)->first()
            ?? Capsule::table('tblcurrencies')->where('default', 1)->first();
        $number = number_format($amount, 2, '.', ',');
        if ($currency === null) {
            return $number;
        }
        return trim($currency->prefix . $number . $currency->suffix);
    }

    /** Formats a database date ("2026-11-20") as "20 Nov 2026"; empty or zero dates become "". */
    private static function date(string $value): string
    {
        if ($value === '' || str_starts_with($value, '0000')) {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        return $date === false ? '' : $date->format('d M Y');
    }
}

/** Thrown when a message's record (invoice, service ...) is missing or belongs to another client. */
final class RecordNotFound extends \RuntimeException
{
}
