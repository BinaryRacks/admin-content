<?php
/**
 * Tests the public webhook: Meta's verify handshake, signature checks, size and rate limits,
 * delivery receipts, and STOP replies (tick disable_whatsapp + log). Includes forged-request attacks.
 */

declare(strict_types=1);

use BinaryRacks\WhatsApp\Consent;
use BinaryRacks\WhatsApp\Contacts;
use BinaryRacks\WhatsApp\MessageLog;
use BinaryRacks\WhatsApp\Queue;
use BinaryRacks\WhatsApp\WebhookHandler;
use WHMCS\Database\Capsule;

final class WebhookTest extends TestCase
{
    private const SECRET = 'abcdef0123456789abcdef0123456789';
    private int $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = FakeWhmcs::addClient();
        Contacts::remember($this->client, '447911123456');   // as if we messaged this client before
    }

    /** Sends a POST signed like Meta does. */
    private function post(array $payload, ?string $signature = null, string $ip = '203.0.113.5'): array
    {
        $body = json_encode($payload);
        $signature ??= 'sha256=' . hash_hmac('sha256', $body, self::SECRET);
        return WebhookHandler::handle('POST', [], ['x-hub-signature-256' => $signature], $body, $ip);
    }

    /** Wraps a value block the way Meta does. */
    private function payload(array $value, string $phoneId = '123456789012'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => [[
            'field' => 'messages', 'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => $phoneId]] + $value,
        ]]]]];
    }

    private function stopMessage(string $text, string $from = '447911123456'): array
    {
        return $this->payload(['messages' => [['from' => $from, 'id' => 'wamid.IN1234567890', 'type' => 'text', 'text' => ['body' => $text]]]]);
    }

    // ---- Verify handshake

    public function testVerifyHandshakeEchoesChallengeWithRightToken(): void
    {
        $r = WebhookHandler::handle('GET', ['hub_mode' => 'subscribe', 'hub_verify_token' => 'verify-token-1234567890', 'hub_challenge' => '1158201444'], [], '', '1.2.3.4');
        $this->assertSame([200, '1158201444'], $r);
    }

    public function testVerifyHandshakeRejectsWrongToken(): void
    {
        $r = WebhookHandler::handle('GET', ['hub_mode' => 'subscribe', 'hub_verify_token' => 'guess', 'hub_challenge' => '1'], [], '', '1.2.3.4');
        $this->assertSame(403, $r[0]);
    }

    public function testAttackChallengeWithScriptIsNotEchoed(): void
    {
        $r = WebhookHandler::handle('GET', ['hub_mode' => 'subscribe', 'hub_verify_token' => 'verify-token-1234567890', 'hub_challenge' => '<script>alert(1)</script>'], [], '', '1.2.3.4');
        $this->assertSame(400, $r[0]);
        $this->assertStringNotContainsString('<script>', $r[1]);
    }

    public function testVerifyRefusedWhenNoTokenSaved(): void
    {
        \BinaryRacks\WhatsApp\Settings::setSecret('verify_token', '');
        $r = WebhookHandler::handle('GET', ['hub_mode' => 'subscribe', 'hub_verify_token' => '', 'hub_challenge' => '1'], [], '', '1.2.3.4');
        $this->assertSame(403, $r[0]);
    }

    // ---- Signature and request checks (forged requests)

    public function testAttackUnsignedRequestIsRejectedAndNothingChanges(): void
    {
        $r = WebhookHandler::handle('POST', [], [], json_encode($this->stopMessage('STOP')), '203.0.113.9');
        $this->assertSame(403, $r[0]);
        $this->assertFalse(Consent::isWhatsAppDisabled($this->client));
    }

    public function testAttackWrongSignatureIsRejected(): void
    {
        $r = $this->post($this->stopMessage('STOP'), 'sha256=' . str_repeat('0', 64));
        $this->assertSame(403, $r[0]);
        $this->assertFalse(Consent::isWhatsAppDisabled($this->client));
    }

    public function testAttackSignatureMadeWithAnotherSecretIsRejected(): void
    {
        $body = json_encode($this->stopMessage('STOP'));
        $r = WebhookHandler::handle('POST', [], ['x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $body, 'wrong-secret')], $body, '1.1.1.1');
        $this->assertSame(403, $r[0]);
    }

    public function testAttackBodyChangedAfterSigningIsRejected(): void
    {
        $body = json_encode($this->stopMessage('hello'));
        $sig = 'sha256=' . hash_hmac('sha256', $body, self::SECRET);
        $tampered = str_replace('hello', 'STOP', $body);
        $r = WebhookHandler::handle('POST', [], ['x-hub-signature-256' => $sig], $tampered, '1.1.1.1');
        $this->assertSame(403, $r[0]);
        $this->assertFalse(Consent::isWhatsAppDisabled($this->client));
    }

    public function testAttackNoAppSecretMeansNothingIsTrusted(): void
    {
        \BinaryRacks\WhatsApp\Settings::setSecret('app_secret', '');
        $this->assertSame(403, $this->post($this->stopMessage('STOP'))[0]);
    }

    public function testAttackOversizedBodyIsRejected(): void
    {
        $body = str_repeat('a', WebhookHandler::MAX_BODY_BYTES + 1);
        $r = WebhookHandler::handle('POST', [], ['x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $body, self::SECRET)], $body, '1.1.1.1');
        $this->assertSame(413, $r[0]);
    }

    public function testAttackMalformedJsonIsRejected(): void
    {
        $body = '{"object": "whatsapp_business_account", "entry": [';
        $r = WebhookHandler::handle('POST', [], ['x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $body, self::SECRET)], $body, '1.1.1.1');
        $this->assertSame(400, $r[0]);
    }

    public function testAttackDeeplyNestedJsonIsRejected(): void
    {
        $body = str_repeat('[', 100) . str_repeat(']', 100);
        $r = WebhookHandler::handle('POST', [], ['x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $body, self::SECRET)], $body, '1.1.1.1');
        $this->assertSame(400, $r[0]);
    }

    public function testOtherHttpMethodsAreRefused(): void
    {
        $this->assertSame(405, WebhookHandler::handle('PUT', [], [], '', '1.1.1.1')[0]);
        $this->assertSame(405, WebhookHandler::handle('DELETE', [], [], '', '1.1.1.1')[0]);
    }

    public function testAttackFloodIsRateLimited(): void
    {
        for ($i = 0; $i < WebhookHandler::RATE_LIMIT; $i++) {
            WebhookHandler::handle('PUT', [], [], '', '198.51.100.7');
        }
        $this->assertSame(429, WebhookHandler::handle('GET', [], [], '', '198.51.100.7')[0]);
        $this->assertNotSame(429, WebhookHandler::handle('PUT', [], [], '', '198.51.100.8')[0], 'other IPs are not affected');
    }

    public function testAttackSignatureGuessingIsBlockedQuickly(): void
    {
        for ($i = 0; $i < WebhookHandler::BAD_SIGNATURE_LIMIT; $i++) {
            $this->assertSame(403, $this->post(['x' => $i], 'sha256=' . str_repeat('1', 64), '192.0.2.50')[0]);
        }
        $this->assertSame(429, $this->post(['x' => 'last'], 'sha256=' . str_repeat('1', 64), '192.0.2.50')[0]);
    }

    public function testIpAddressesAreNotStoredReadably(): void
    {
        WebhookHandler::handle('PUT', [], [], '', '198.51.100.77');
        foreach (Capsule::table('mod_brwa_ratelimit')->get() as $row) {
            $this->assertStringNotContainsString('198.51.100.77', $row->bucket);
        }
    }

    public function testDeactivatedModuleAnswersNotFound(): void
    {
        Capsule::table('tbladdonmodules')->delete();
        $this->assertSame(404, $this->post($this->stopMessage('STOP'))[0]);
        $this->assertFalse(Consent::isWhatsAppDisabled($this->client));
    }

    // ---- Delivery receipts

    public function testReceiptsMoveForwardOnly(): void
    {
        MessageLog::add($this->client, '+44******3456', 'invoice_created', 'event', 1, 'sent', 'wamid.ABCDEFGHIJ');
        $receipt = fn (string $s) => $this->post($this->payload(['statuses' => [['id' => 'wamid.ABCDEFGHIJ', 'status' => $s, 'recipient_id' => '447911123456']]]));
        $receipt('read');
        $receipt('delivered');   // arrives late; must not undo "read"
        $this->assertSame('read', $this->logRows()[0]->status);
    }

    public function testFailedReceiptStoresCleanedError(): void
    {
        MessageLog::add($this->client, '+44******3456', 'invoice_created', 'event', 1, 'sent', 'wamid.ABCDEFGHIJ');
        $this->post($this->payload(['statuses' => [['id' => 'wamid.ABCDEFGHIJ', 'status' => 'failed',
            'errors' => [['code' => 131026, 'title' => 'Message undeliverable to 447911123456']]]]]));
        $row = $this->logRows()[0];
        $this->assertSame('failed', $row->status);
        $this->assertSame('131026', $row->error_code);
        $this->assertStringNotContainsString('7911123456', $row->error_text);
    }

    public function testEventsForAnotherPhoneNumberIdAreIgnored(): void
    {
        $r = $this->post($this->payload(['messages' => [['from' => '447911123456', 'type' => 'text', 'text' => ['body' => 'STOP']]]], '999999999'));
        $this->assertSame(200, $r[0]);
        $this->assertFalse(Consent::isWhatsAppDisabled($this->client));
    }

    // ---- STOP replies

    public function testStopTicksDisableWhatsAppAndIsLoggedAsProof(): void
    {
        $this->assertSame([200, 'OK'], $this->post($this->stopMessage('Stop')));
        $this->assertTrue(Consent::isWhatsAppDisabled($this->client));
        $log = $this->logRows()[0];
        $this->assertSame('stop', $log->status);
        $this->assertSame(1, (int) $log->is_stop);
        $this->assertSame(MessageLog::STOP_TEXT, $log->error_text);
        $this->assertSame('+44******3456', $log->phone_masked);
        // The WHMCS email marketing opt-in is never touched.
        $this->assertSame(1, (int) Capsule::table('tblclients')->where('id', $this->client)->value('marketing_emails_opt_in'));
        $this->assertSame('UpdateClient', end(FakeWhmcs::$apiCalls)[0], 'changed through localAPI');
    }

    public function testStopVariantsAndMetaOptOutButton(): void
    {
        foreach (['STOP', ' stop! ', 'Unsubscribe', 'Stop promotions'] as $word) {
            $this->assertTrue(WebhookHandler::isStopWord($word), $word);
        }
        foreach (['please stop by tomorrow', 'hello', 'stopp', ''] as $word) {
            $this->assertFalse(WebhookHandler::isStopWord($word), $word);
        }
        $button = $this->payload(['messages' => [['from' => '447911123456', 'type' => 'button', 'button' => ['text' => 'Stop promotions', 'payload' => 'Stop promotions']]]]);
        $this->post($button);
        $this->assertTrue(Consent::isWhatsAppDisabled($this->client));
    }

    public function testStopCancelsMessagesStillWaiting(): void
    {
        $this->enable('invoice_created');
        Queue::add($this->client, 'invoice_created', 'event', 1, 'x1', false);
        $this->post($this->stopMessage('STOP'));
        $this->assertSame('cancelled', Capsule::table('mod_brwa_queue')->value('status'));
    }

    public function testStopFromUnknownNumberChangesNoClient(): void
    {
        $this->post($this->stopMessage('STOP', '447000000000'));
        $this->assertFalse(Consent::isWhatsAppDisabled($this->client));
        $log = $this->logRows()[0];
        $this->assertNull($log->client_id);
        $this->assertSame(0, (int) $log->is_stop, 'not kept forever: no client to link it to');
    }

    public function testOrdinaryRepliesAreIgnoredAndNotStored(): void
    {
        $this->post($this->stopMessage('Hi, my server is down, my password is hunter2'));
        $this->assertCount(0, $this->logRows());
        $this->assertFalse(Consent::isWhatsAppDisabled($this->client));
    }

    public function testAttackScriptInMessageIsIgnored(): void
    {
        $this->post($this->stopMessage('<script>alert(1)</script>'));
        $this->assertCount(0, $this->logRows());
    }

    public function testAttackForgedSenderNumberFormatIsIgnored(): void
    {
        $this->post($this->stopMessage('STOP', "447911123456' OR '1'='1"));
        $this->assertFalse(Consent::isWhatsAppDisabled($this->client));
        $this->assertCount(0, $this->logRows());
    }
}
