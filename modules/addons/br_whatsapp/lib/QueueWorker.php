<?php
/**
 * Sends the queued messages. Run by the WHMCS cron (AfterCronJob hook) and, for critical
 * messages, straight away. Before each send it checks again: message still switched on,
 * client consent, a valid phone number, the 08:00-18:00 rule and the marketing daily cap.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

if (!defined('WHMCS')) {
    exit;
}

final class QueueWorker
{
    /** A message is tried at most this many times before it is marked failed. */
    public const MAX_ATTEMPTS = 5;

    /** Minutes to wait before each retry (after attempt 1, 2, 3, 4). */
    private const BACKOFF_MINUTES = [5, 15, 60, 240];

    /** Sends one batch of due messages. Returns how many rows were looked at. */
    public static function run(): int
    {
        if (!Settings::isConfigured()) {
            return 0;   // nothing can be sent until the Meta settings are saved
        }
        Queue::releaseStale();
        $ids = Queue::dueIds(Settings::int('batch_size', 1, 500));
        foreach ($ids as $id) {
            self::processOne($id);
        }
        Schema::setMeta('last_worker_run', Clock::nowDb());
        return count($ids);
    }

    /** Sends a single queued message, if it is still waiting. Never throws. */
    public static function processOne(int $id): void
    {
        $row = Queue::claim($id);
        if ($row === null) {
            return;   // already taken by another run
        }
        try {
            self::process($row);
        } catch (\Throwable $e) {
            // Unexpected problem: try again later, or give up after the last attempt.
            if ((int) $row->attempts + 1 < self::MAX_ATTEMPTS) {
                Queue::postpone((int) $row->id, self::retryTime((int) $row->attempts), true);
            } else {
                self::finishWith($row, 'failed', '', 'internal_error', $e->getMessage());
            }
        }
    }

    /** All the checks and the actual send for one claimed row. */
    private static function process(object $row): void
    {
        $clientId = (int) $row->client_id;
        $message = Messages::find((string) $row->msg_key);
        if ($message === null || !$message->enabled) {
            self::finishWith($row, 'skipped', '', 'message_disabled');
            return;
        }
        if ($message->kind === 'event' && !Settings::bool('cat_' . $message->category)) {
            self::finishWith($row, 'skipped', '', 'category_disabled');
            return;
        }

        // Consent is checked again here, in case it changed after the message was queued.
        $isMarketing = $row->source === 'campaign';
        $blocked = Consent::blockReason($clientId, $isMarketing);
        if ($blocked !== null) {
            self::finishWith($row, 'skipped', '', $blocked);
            return;
        }

        // Read the client through the official WHMCS API (gives the phone's country code).
        $details = localAPI('GetClientsDetails', ['clientid' => $clientId, 'stats' => false]);
        if (($details['result'] ?? '') !== 'success') {
            self::finishWith($row, 'skipped', '', 'client_not_found');
            return;
        }
        $client = is_array($details['client'] ?? null) ? $details['client'] : $details;
        $phone = PhoneNumber::normalise((string) ($client['phonenumber'] ?? ''), (string) ($client['phonecc'] ?? ''));
        if ($phone === null) {
            self::finishWith($row, 'skipped', '', 'no_valid_phone');
            return;
        }
        $masked = PhoneNumber::mask($phone);

        // Daytime rule: wait until 08:00 client time, unless the message is critical.
        if (!(bool) $row->critical) {
            $zone = TimeZoneResolver::forClient((string) ($client['countrycode'] ?? $client['country'] ?? ''), (string) ($client['state'] ?? ''));
            if (!DaytimeWindow::isOpen(Clock::now(), $zone)) {
                Queue::postpone((int) $row->id, DaytimeWindow::nextOpen(Clock::now(), $zone), false);
                return;
            }
        }

        // Daily cap for marketing, so the number stays inside Meta's limits.
        if ($isMarketing && MessageLog::marketingSentToday() >= Settings::int('daily_cap', 1, 1000000)) {
            Queue::postpone((int) $row->id, Clock::now()->modify('+1 day')->setTime(0, 5, 0), false);
            return;
        }

        if ($message->template_name === '') {
            self::finishWith($row, 'skipped', $masked, 'no_template');
            return;
        }
        try {
            $values = Placeholders::resolve($message->paramList, $clientId, Messages::scopeOf($message), $row->related_id === null ? null : (int) $row->related_id);
        } catch (RecordNotFound $e) {
            self::finishWith($row, 'skipped', $masked, 'record_not_found');
            return;
        }

        $result = (new MetaClient())->sendTemplate($phone, $message->template_name, Messages::languageFor($message), $values);
        if ($result->ok) {
            Contacts::remember($clientId, $phone);
            self::finishWith($row, 'sent', $masked, null, null, $result->wamid);
            return;
        }
        if ($result->retryable && (int) $row->attempts + 1 < self::MAX_ATTEMPTS) {
            Queue::postpone((int) $row->id, self::retryTime((int) $row->attempts), true);
            return;
        }
        self::finishWith($row, 'failed', $masked, $result->errorCode, $result->errorText);
    }

    /**
     * Sends a "Send test" message straight away to a number the admin typed, with sample values.
     * Doesn't touch any client. Logged without a client ID and with a masked number.
     */
    public static function sendTest(object $message, string $typedNumber): SendResult
    {
        $phone = PhoneNumber::normalise($typedNumber, '');
        if ($phone === null) {
            return new SendResult(false, null, false, 'invalid_number', 'Type the number in international format, e.g. +447911123456.');
        }
        $result = (new MetaClient())->sendTemplate($phone, $message->template_name, Messages::languageFor($message), Placeholders::samples($message->paramList));
        MessageLog::add(null, PhoneNumber::mask($phone), $message->msg_key, 'test', null, $result->ok ? 'sent' : 'failed', $result->wamid, $result->errorCode, $result->errorText);
        return $result;
    }

    /** Writes the log entry, closes the queue row and updates the campaign counters. */
    private static function finishWith(object $row, string $status, string $masked, ?string $errorCode, ?string $errorText = null, ?string $wamid = null): void
    {
        MessageLog::add((int) $row->client_id, $masked, (string) $row->msg_key, (string) $row->source,
            $row->related_id === null ? null : (int) $row->related_id, $status, $wamid, $errorCode, $errorText);
        Queue::finish((int) $row->id, $status);
        if ($row->campaign_id !== null) {
            Campaigns::record((int) $row->campaign_id, $status);
        }
    }

    /** Returns when to try again after a temporary failure. */
    private static function retryTime(int $attemptsSoFar): \DateTimeImmutable
    {
        $minutes = self::BACKOFF_MINUTES[min($attemptsSoFar, count(self::BACKOFF_MINUTES) - 1)];
        return Clock::now()->modify('+' . $minutes . ' minutes');
    }
}
