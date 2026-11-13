<?php
/**
 * Connects WHMCS events to the module. WHMCS loads this file automatically while the module is active.
 * Each hook only hands the event to EventHandler / QueueWorker; any error is caught,
 * so a WhatsApp problem can never break invoicing, provisioning or the cron.
 */

declare(strict_types=1);

use BinaryRacks\WhatsApp\EventHandler;
use BinaryRacks\WhatsApp\Permissions;
use BinaryRacks\WhatsApp\QueueWorker;
use BinaryRacks\WhatsApp\Text;
use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit;
}

require_once __DIR__ . '/lib/bootstrap.php';

/** Runs $work only if the module's tables exist, and swallows any error (logged without personal data). */
function br_whatsapp_safely(string $hookName, callable $work): mixed
{
    try {
        if (!Capsule::schema()->hasTable('mod_brwa_meta')) {
            return null;
        }
        return $work();
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('Binary Racks WhatsApp: ' . $hookName . ' failed (' . get_class($e) . ')');
        }
        return null;
    }
}

// Invoices
add_hook('InvoiceCreated', 1, fn (array $vars) => br_whatsapp_safely('InvoiceCreated', fn () => EventHandler::invoiceCreated($vars)));
add_hook('InvoicePaymentReminder', 1, fn (array $vars) => br_whatsapp_safely('InvoicePaymentReminder', fn () => EventHandler::invoiceReminder($vars)));
add_hook('InvoicePaid', 1, fn (array $vars) => br_whatsapp_safely('InvoicePaid', fn () => EventHandler::invoicePaid($vars)));

// Services
add_hook('AfterModuleCreate', 1, fn (array $vars) => br_whatsapp_safely('AfterModuleCreate', fn () => EventHandler::serviceEvent('service_activated', $vars)));
add_hook('AfterModuleSuspend', 1, fn (array $vars) => br_whatsapp_safely('AfterModuleSuspend', fn () => EventHandler::serviceEvent('service_suspended', $vars)));
add_hook('AfterModuleUnsuspend', 1, fn (array $vars) => br_whatsapp_safely('AfterModuleUnsuspend', fn () => EventHandler::serviceEvent('service_unsuspended', $vars)));
add_hook('AfterModuleTerminate', 1, fn (array $vars) => br_whatsapp_safely('AfterModuleTerminate', fn () => EventHandler::serviceEvent('service_terminated', $vars)));

// Tickets
add_hook('TicketAdminReply', 1, fn (array $vars) => br_whatsapp_safely('TicketAdminReply', fn () => EventHandler::ticketReply($vars)));

// Domains
add_hook('AfterRegistrarRegistration', 1, fn (array $vars) => br_whatsapp_safely('AfterRegistrarRegistration', fn () => EventHandler::domainEvent('domain_registered', $vars)));
add_hook('AfterRegistrarRenewal', 1, fn (array $vars) => br_whatsapp_safely('AfterRegistrarRenewal', fn () => EventHandler::domainEvent('domain_renewed', $vars)));

// Cron: send the queue after every cron run; daily jobs once a day.
add_hook('AfterCronJob', 1, fn () => br_whatsapp_safely('AfterCronJob', fn () => QueueWorker::run()));
add_hook('DailyCronJob', 1, fn () => br_whatsapp_safely('DailyCronJob', fn () => EventHandler::daily()));

// GDPR: delete the client's WhatsApp data when the client is deleted.
add_hook('ClientDelete', 1, fn (array $vars) => br_whatsapp_safely('ClientDelete', fn () => EventHandler::clientDeleted($vars)));

// Adds a "Send WhatsApp" link on the admin client summary page (only for roles allowed to send).
add_hook('AdminAreaClientSummaryActionLinks', 1, function (array $vars) {
    return br_whatsapp_safely('AdminAreaClientSummaryActionLinks', function () use ($vars) {
        $clientId = (int) ($vars['userid'] ?? 0);
        if ($clientId <= 0 || !Permissions::can('send')) {
            return [];
        }
        $url = 'addonmodules.php?module=br_whatsapp&action=send&userid=' . $clientId;
        return ['<a href="' . Text::e($url) . '"><i class="fab fa-whatsapp"></i> Send WhatsApp</a>'];
    }) ?? [];
});
