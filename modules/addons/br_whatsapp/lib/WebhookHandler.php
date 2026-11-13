<?php
/**
 * Handles calls from Meta's servers to webhook.php (the module's only public entry point).
 * GET  = Meta's one-time "verify" handshake when you set up the webhook.
 * POST = delivery receipts (sent / delivered / read / failed) and incoming messages.
 *        Only "STOP"-type messages are acted on: disable_whatsapp is ticked and the request is logged.
 *        Every other reply is ignored and never stored.
 * Nothing is processed unless Meta's signature (X-Hub-Signature-256) is correct.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit;
}

final class WebhookHandler
{
    /** Largest request body accepted (64 KB); Meta's messages are far smaller. */
    public const MAX_BODY_BYTES = 65536;

    /** Requests allowed per caller IP per minute, and bad-signature attempts allowed per IP per minute. */
    public const RATE_LIMIT = 600;
    public const BAD_SIGNATURE_LIMIT = 10;

    /** Most items (receipts + messages) looked at from one request. */
    private const MAX_ITEMS = 200;

    /** Replies that mean "stop messaging me" (compared in upper case, without punctuation). */
    public const STOP_WORDS = ['STOP', 'STOP ALL', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'STOP PROMOTIONS', 'OPT OUT', 'OPTOUT'];

    /**
     * Handles one request and returns [HTTP status, plain-text body].
     * $headers keys are lower case. $remoteIp is only used (hashed) for the rate limit.
     */
    public static function handle(string $method, array $query, array $headers, string $rawBody, string $remoteIp): array
    {
        if (!self::moduleActive()) {
            return [404, 'Not found'];
        }
        if (!RateLimiter::allow('webhook|' . $remoteIp, self::RATE_LIMIT)) {
            return [429, 'Too many requests'];
        }
        return match ($method) {
            'GET'   => self::verify($query),
            'POST'  => self::receive($headers, $rawBody, $remoteIp),
            default => [405, 'Method not allowed'],
        };
    }

    /** Meta's setup handshake: echo the challenge only if the verify token matches. */
    private static function verify(array $query): array
    {
        // PHP turns "hub.mode" into "hub_mode" in $_GET, so both spellings are accepted.
        $mode = (string) ($query['hub_mode'] ?? $query['hub.mode'] ?? '');
        $token = (string) ($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        $challenge = (string) ($query['hub_challenge'] ?? $query['hub.challenge'] ?? '');
        $expected = Settings::secret('verify_token');

        if ($mode !== 'subscribe' || $expected === '' || !hash_equals($expected, $token)) {
            return [403, 'Forbidden'];
        }
        // Only digits are echoed back, so the reply can never carry a script.
        if (preg_match('/^\d{1,64}$/', $challenge) !== 1) {
            return [400, 'Bad request'];
        }
        return [200, $challenge];
    }

    /** Checks the signature, then processes receipts and STOP messages. */
    private static function receive(array $headers, string $rawBody, string $remoteIp): array
    {
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return [413, 'Too large'];
        }
        if (!self::signatureValid($headers['x-hub-signature-256'] ?? '', $rawBody)) {
            // Count bad signatures separately, so guessing attempts are blocked quickly.
            if (!RateLimiter::allow('webhook-badsig|' . $remoteIp, self::BAD_SIGNATURE_LIMIT)) {
                return [429, 'Too many requests'];
            }
            return [403, 'Forbidden'];
        }
        try {
            $data = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [400, 'Bad request'];
        }
        if (!is_array($data) || ($data['object'] ?? '') !== 'whatsapp_business_account' || !is_array($data['entry'] ?? null)) {
            return [200, 'OK'];   // signed but not for us: accept quietly so Meta doesn't retry
        }

        $ourPhoneId = Settings::get('phone_number_id');
        $items = 0;
        foreach ($data['entry'] as $entry) {
            foreach ((is_array($entry['changes'] ?? null) ? $entry['changes'] : []) as $change) {
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                // Ignore events for any other WhatsApp number.
                if ((string) ($value['metadata']['phone_number_id'] ?? '') !== $ourPhoneId) {
                    continue;
                }
                foreach ((is_array($value['statuses'] ?? null) ? $value['statuses'] : []) as $status) {
                    if (++$items > self::MAX_ITEMS) {
                        break 3;
                    }
                    if (is_array($status)) {
                        self::applyStatus($status);
                    }
                }
                foreach ((is_array($value['messages'] ?? null) ? $value['messages'] : []) as $message) {
                    if (++$items > self::MAX_ITEMS) {
                        break 3;
                    }
                    if (is_array($message)) {
                        self::applyMessage($message);
                    }
                }
            }
        }
        return [200, 'OK'];
    }

    /** Returns true if the header is "sha256=<hex>" and matches HMAC-SHA256 of the body with the app secret. */
    public static function signatureValid(string $header, string $rawBody): bool
    {
        $secret = Settings::secret('app_secret');
        if ($secret === '' || preg_match('/^sha256=([a-f0-9]{64})$/', $header, $m) !== 1) {
            return false;
        }
        // hash_equals compares in constant time, so the secret can't be guessed by timing.
        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $m[1]);
    }

    /** Applies one delivery receipt to the log. */
    private static function applyStatus(array $status): void
    {
        $wamid = (string) ($status['id'] ?? '');
        $state = (string) ($status['status'] ?? '');
        if (preg_match('/^[A-Za-z0-9_.:=\-]{10,191}$/', $wamid) !== 1 || !in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }
        $error = is_array($status['errors'][0] ?? null) ? $status['errors'][0] : [];
        $code = isset($error['code']) ? (string) (int) $error['code'] : null;
        $text = is_string($error['title'] ?? null) ? $error['title'] : null;
        MessageLog::applyReceipt($wamid, $state, $code, $text);
    }

    /** Handles one incoming message: STOP ticks disable_whatsapp; anything else is ignored. */
    private static function applyMessage(array $message): void
    {
        $text = match ((string) ($message['type'] ?? '')) {
            'text'        => $message['text']['body'] ?? '',
            'button'      => $message['button']['text'] ?? ($message['button']['payload'] ?? ''),
            'interactive' => $message['interactive']['button_reply']['title'] ?? '',
            default       => '',
        };
        if (!is_string($text) || !self::isStopWord($text)) {
            return;   // ordinary replies are not processed and not stored
        }
        $from = (string) ($message['from'] ?? '');
        if (preg_match('/^[1-9]\d{7,14}$/', $from) !== 1) {
            return;
        }
        $masked = PhoneNumber::mask($from);
        $clients = Contacts::clientsFor($from);
        if ($clients === []) {
            // Unknown number: noted without a client (normal retention), nothing else changes.
            MessageLog::add(null, $masked, 'stop', 'webhook', null, 'stop', null, 'no_matching_client', 'STOP received from a number with no matching client');
            return;
        }
        foreach ($clients as $clientId) {
            $ok = Consent::isWhatsAppDisabled($clientId) || Consent::disableWhatsApp($clientId);
            // STOP proof: is_stop = true keeps this entry until the client is deleted or erased.
            MessageLog::add($clientId, $masked, 'stop', 'webhook', null, 'stop', null,
                $ok ? null : 'update_failed', $ok ? MessageLog::STOP_TEXT : 'STOP received but disable_whatsapp could not be ticked', $ok);
            if ($ok) {
                // Drop anything still waiting for this client.
                Capsule::table(Queue::TABLE)->where('client_id', $clientId)->whereIn('status', ['pending', 'paused'])
                    ->update(['status' => 'cancelled', 'updated_at' => Clock::nowDb()]);
            }
        }
    }

    /** Returns true if a reply means "stop", e.g. "Stop", " STOP! ", "Unsubscribe". */
    public static function isStopWord(string $text): bool
    {
        if (strlen($text) > 64) {
            return false;
        }
        $clean = strtoupper(trim((string) preg_replace('/[^A-Za-z ]+/', ' ', $text)));
        $clean = (string) preg_replace('/\s+/', ' ', $clean);
        return in_array($clean, self::STOP_WORDS, true);
    }

    /** Returns true if the module is activated in WHMCS (so a deactivated module answers nothing). */
    private static function moduleActive(): bool
    {
        return Capsule::schema()->hasTable(Settings::TABLE)
            && Capsule::table('tbladdonmodules')->where('module', 'br_whatsapp')->where('setting', 'version')->exists();
    }
}
