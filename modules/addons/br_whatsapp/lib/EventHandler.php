<?php
/**
 * Turns WHMCS events (invoice created, service suspended, ticket reply ...) into queued messages.
 * hooks.php calls these methods. Each one finds the client, checks the category and event switches
 * and consent, then queues the message with a duplicate guard. Errors never reach WHMCS.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit;
}

final class EventHandler
{
    /** Invoice created (drafts are ignored until they are published). */
    public static function invoiceCreated(array $vars): void
    {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if (($vars['status'] ?? '') === 'Draft') {
            return;
        }
        self::queue('invoice_created', self::invoiceOwner($invoiceId), $invoiceId, 'invoice_created:' . $invoiceId);
    }

    /** WHMCS payment reminder (before due date, or first/second/third overdue notice). */
    public static function invoiceReminder(array $vars): void
    {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        $type = preg_replace('/[^a-z]/', '', strtolower((string) ($vars['type'] ?? 'reminder')));
        self::queue('invoice_reminder', self::invoiceOwner($invoiceId), $invoiceId,
            'invoice_reminder:' . $invoiceId . ':' . $type . ':' . Clock::now()->format('Y-m-d'));
    }

    /** Invoice paid. */
    public static function invoicePaid(array $vars): void
    {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        self::queue('invoice_paid', self::invoiceOwner($invoiceId), $invoiceId, 'invoice_paid:' . $invoiceId);
    }

    /**
     * Service events from the provisioning module hooks (create, suspend, unsuspend, terminate).
     * $key is one of: service_activated, service_suspended, service_unsuspended, service_terminated.
     */
    public static function serviceEvent(string $key, array $vars): void
    {
        $params = is_array($vars['params'] ?? null) ? $vars['params'] : [];
        $serviceId = (int) ($params['serviceid'] ?? $params['accountid'] ?? 0);
        if ($serviceId <= 0) {
            return;
        }
        $owner = Capsule::table('tblhosting')->where('id', $serviceId)->value('userid');
        // A service can be suspended or unsuspended more than once, so the guard allows one per day.
        $dedupe = $key . ':' . $serviceId . ($key === 'service_activated' || $key === 'service_terminated' ? '' : ':' . Clock::now()->format('Y-m-d'));
        self::queue($key, $owner === null ? 0 : (int) $owner, $serviceId, $dedupe);
    }

    /** A staff member replied to a ticket (guest tickets with no client account are ignored). */
    public static function ticketReply(array $vars): void
    {
        $ticketId = (int) ($vars['ticketid'] ?? 0);
        $replyId = (int) ($vars['replyid'] ?? 0);
        $owner = Capsule::table('tbltickets')->where('id', $ticketId)->value('userid');
        self::queue('ticket_reply', $owner === null ? 0 : (int) $owner, $ticketId, 'ticket_reply:' . $ticketId . ':' . $replyId);
    }

    /** Domain registered or renewed through a registrar module. */
    public static function domainEvent(string $key, array $vars): void
    {
        $params = is_array($vars['params'] ?? null) ? $vars['params'] : [];
        $domainId = (int) ($params['domainid'] ?? 0);
        if ($domainId <= 0) {
            return;
        }
        $owner = Capsule::table('tbldomains')->where('id', $domainId)->value('userid');
        $dedupe = $key . ':' . $domainId . ':' . Clock::now()->format('Y-m-d');
        self::queue($key, $owner === null ? 0 : (int) $owner, $domainId, $dedupe);
    }

    /** Daily jobs: domains expiring soon, plus clean-up of old logs, queue rows and rate counters. */
    public static function daily(): void
    {
        $days = Settings::int('domain_expiry_days', 1, 90);
        $target = Clock::now()->modify('+' . $days . ' days')->format('Y-m-d');
        foreach (Capsule::table('tbldomains')->where('status', 'Active')->where('expirydate', $target)->get(['id', 'userid', 'expirydate']) as $domain) {
            self::queue('domain_expiring', (int) $domain->userid, (int) $domain->id, 'domain_expiring:' . $domain->id . ':' . $domain->expirydate);
        }
        MessageLog::cleanup();
        Queue::cleanup();
        RateLimiter::cleanup();
    }

    /** Deletes a client's WhatsApp data when the client is deleted in WHMCS (GDPR right to erasure). */
    public static function clientDeleted(array $vars): void
    {
        $clientId = (int) ($vars['userid'] ?? $vars['clientid'] ?? 0);
        if ($clientId > 0) {
            MessageLog::eraseClient($clientId);
        }
    }

    /**
     * Common steps for every event: switches on? consent? then queue.
     * Critical messages (service suspension) are sent straight away instead of waiting for the cron.
     */
    private static function queue(string $key, int $clientId, int $relatedId, string $dedupeKey): void
    {
        if ($clientId <= 0 || $relatedId <= 0 || !Settings::isConfigured()) {
            return;
        }
        $message = Messages::find($key);
        if ($message === null || $message->kind !== 'event' || !$message->enabled || !Settings::bool('cat_' . $message->category)) {
            return;   // switched off: nothing is queued or logged
        }
        $blocked = Consent::blockReason($clientId, false);
        if ($blocked !== null) {
            MessageLog::add($clientId, '', $key, 'event', $relatedId, 'skipped', null, $blocked);
            return;
        }
        $critical = Catalogue::isCritical($key);
        $id = Queue::add($clientId, $key, 'event', $relatedId, $dedupeKey, $critical);
        if ($id !== null && $critical) {
            QueueWorker::processOne($id);
        }
    }

    /** Returns the client ID that owns an invoice, or 0. */
    private static function invoiceOwner(int $invoiceId): int
    {
        $owner = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('userid');
        return $owner === null ? 0 : (int) $owner;
    }
}
