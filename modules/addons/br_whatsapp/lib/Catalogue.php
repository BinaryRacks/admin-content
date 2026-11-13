<?php
/**
 * The fixed list of built-in notifications: categories, automatic events and the ready-made
 * manual message. Used when the module is activated (to create the rows) and by the admin pages.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

if (!defined('WHMCS')) {
    exit;
}

final class Catalogue
{
    /** The four categories that can be switched on or off as a whole. */
    public const CATEGORIES = [
        'invoices' => 'Invoices',
        'services' => 'Services',
        'tickets'  => 'Tickets',
        'domains'  => 'Domains',
    ];

    /**
     * Built-in automatic events.
     * scope    = which WHMCS record the message is about (decides which blanks are allowed)
     * critical = sent at any hour (skips the 08:00-18:00 rule)
     */
    public const EVENTS = [
        'invoice_created'     => ['category' => 'invoices', 'label' => 'Invoice created',                         'scope' => 'invoice', 'critical' => false,
                                  'params' => ['client_firstname', 'invoice_id', 'invoice_total', 'invoice_duedate']],
        'invoice_reminder'    => ['category' => 'invoices', 'label' => 'Invoice payment reminder (due or overdue)', 'scope' => 'invoice', 'critical' => false,
                                  'params' => ['client_firstname', 'invoice_id', 'invoice_total', 'invoice_duedate']],
        'invoice_paid'        => ['category' => 'invoices', 'label' => 'Payment received',                        'scope' => 'invoice', 'critical' => false,
                                  'params' => ['client_firstname', 'invoice_id', 'invoice_total']],
        'service_activated'   => ['category' => 'services', 'label' => 'Service activated (welcome)',             'scope' => 'service', 'critical' => false,
                                  'params' => ['client_firstname', 'service_name', 'service_domain']],
        'service_suspended'   => ['category' => 'services', 'label' => 'Service suspended (with reason)',         'scope' => 'service', 'critical' => true,
                                  'params' => ['client_firstname', 'service_name', 'suspend_reason']],
        'service_unsuspended' => ['category' => 'services', 'label' => 'Service unsuspended',                     'scope' => 'service', 'critical' => false,
                                  'params' => ['client_firstname', 'service_name']],
        'service_terminated'  => ['category' => 'services', 'label' => 'Service terminated',                      'scope' => 'service', 'critical' => false,
                                  'params' => ['client_firstname', 'service_name']],
        'ticket_reply'        => ['category' => 'tickets',  'label' => 'Staff replied to a support ticket',       'scope' => 'ticket',  'critical' => false,
                                  'params' => ['client_firstname', 'ticket_number', 'ticket_subject']],
        'domain_registered'   => ['category' => 'domains',  'label' => 'Domain registered',                       'scope' => 'domain',  'critical' => false,
                                  'params' => ['client_firstname', 'domain_name']],
        'domain_renewed'      => ['category' => 'domains',  'label' => 'Domain renewed',                          'scope' => 'domain',  'critical' => false,
                                  'params' => ['client_firstname', 'domain_name', 'domain_expiry']],
        'domain_expiring'     => ['category' => 'domains',  'label' => 'Domain expiring soon',                    'scope' => 'domain',  'critical' => false,
                                  'params' => ['client_firstname', 'domain_name', 'domain_expiry']],
    ];

    /** The ready-made manual message (sent by hand from the client profile). */
    public const BUILTIN_MANUAL = [
        'manual_invoice_reminder' => ['label' => 'Due invoice reminder',
                                      'params' => ['client_firstname', 'invoice_id', 'invoice_total', 'invoice_duedate']],
    ];

    /** Returns the definition of a built-in event, or null if the key is not an event. */
    public static function event(string $key): ?array
    {
        return self::EVENTS[$key] ?? null;
    }

    /** Returns true if this message must be sent at any hour. */
    public static function isCritical(string $key): bool
    {
        return (self::EVENTS[$key]['critical'] ?? false) === true;
    }
}
