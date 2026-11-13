<?php
/**
 * Small, focused tests: phone numbers, text cleaning, time zones, the daytime window,
 * the outgoing-connection guard (SSRF) and the Meta request format.
 */

declare(strict_types=1);

use BinaryRacks\WhatsApp\DaytimeWindow;
use BinaryRacks\WhatsApp\MetaClient;
use BinaryRacks\WhatsApp\NetworkGuard;
use BinaryRacks\WhatsApp\PhoneNumber;
use BinaryRacks\WhatsApp\Settings;
use BinaryRacks\WhatsApp\Text;
use BinaryRacks\WhatsApp\TimeZoneResolver;

final class UnitTest extends TestCase
{
    public function testPhoneNumbersAreNormalised(): void
    {
        $this->assertSame('447911123456', PhoneNumber::normalise('+44.7911123456', '44'));
        $this->assertSame('447911123456', PhoneNumber::normalise('07911 123456', '44'));
        $this->assertSame('447911123456', PhoneNumber::normalise('0044 7911 123456', ''));
        $this->assertSame('14155550123', PhoneNumber::normalise('(415) 555-0123', '1'));
        $this->assertSame('390612345678', PhoneNumber::normalise('06 1234 5678', '39'), 'Italy keeps its leading 0');
    }

    public function testBadPhoneNumbersAreRejected(): void
    {
        foreach (['', 'abc', '12', '+0123456789', '07911 123456 ext 5', str_repeat('1', 50), "+44 7911<script>"] as $bad) {
            $this->assertNull(PhoneNumber::normalise($bad, '44'), $bad);
        }
        $this->assertNull(PhoneNumber::normalise('07911 123456', ''), 'no country code known');
    }

    public function testPhoneMask(): void
    {
        $this->assertSame('+44******3456', PhoneNumber::mask('447911123456'));
        $this->assertSame('', PhoneNumber::mask('12'));
    }

    public function testTextHelpers(): void
    {
        $this->assertSame('&lt;b onmouseover=&quot;x&quot;&gt;&apos;', Text::e('<b onmouseover="x">\''));
        $this->assertSame('a b c', Text::templateValue("a\n\tb    c"));
        $this->assertSame('-', Text::templateValue("  \n "));
        $this->assertSame(250, mb_strlen(Text::templateValue(str_repeat('x', 900))));
        $scrubbed = Text::scrub('to +44 7911 123456 / bob@example.com Bearer EAAabc123 EAAxxxxxxxxxxxxxxxx');
        $this->assertStringNotContainsString('7911', $scrubbed);
        $this->assertStringNotContainsString('bob@', $scrubbed);
        $this->assertStringNotContainsString('EAAabc123', $scrubbed);
        $this->assertStringNotContainsString('EAAxxxx', $scrubbed);
    }

    public function testTimeZones(): void
    {
        $this->assertSame('Europe/London', TimeZoneResolver::forClient('GB', '')->getName());
        $this->assertSame('America/Chicago', TimeZoneResolver::forClient('US', 'Texas')->getName());
        $this->assertSame('America/Los_Angeles', TimeZoneResolver::forClient('us', 'ca')->getName());
        $this->assertSame('Australia/Perth', TimeZoneResolver::forClient('AU', 'WA')->getName());
        $this->assertSame('America/New_York', TimeZoneResolver::forClient('US', 'Unknown')->getName());
        $this->assertSame('Asia/Jakarta', TimeZoneResolver::forClient('ID', '')->getName());
        $this->assertSame('UTC', TimeZoneResolver::forClient('../../etc', '')->getName(), 'junk falls back to WHMCS zone');
    }

    public function testDaytimeWindowEdges(): void
    {
        $london = new DateTimeZone('Europe/London');
        $utc = fn (string $t) => new DateTimeImmutable($t, new DateTimeZone('UTC'));
        $this->assertFalse(DaytimeWindow::isOpen($utc('2026-11-13 07:59:59'), $london));
        $this->assertTrue(DaytimeWindow::isOpen($utc('2026-11-13 08:00:00'), $london));
        $this->assertTrue(DaytimeWindow::isOpen($utc('2026-11-13 17:59:59'), $london));
        $this->assertFalse(DaytimeWindow::isOpen($utc('2026-11-13 18:00:00'), $london));
        $this->assertSame('2026-11-13 08:00:00', DaytimeWindow::nextOpen($utc('2026-11-13 03:00:00'), $london)->format('Y-m-d H:i:s'));
        // Summer time: 08:00 in London in July is 07:00 UTC.
        $this->assertSame('2026-07-02 07:00:00', DaytimeWindow::nextOpen($utc('2026-07-01 20:00:00'), $london)->format('Y-m-d H:i:s'));
    }

    public function testAttackSsrfBlockedAddresses(): void
    {
        foreach (['127.0.0.1', '10.0.0.5', '172.16.0.1', '192.168.1.1', '169.254.169.254', '0.0.0.0', '100.64.0.1', '::1', 'fe80::1', 'fd00::1'] as $ip) {
            $this->assertFalse(NetworkGuard::isPublicIp($ip), $ip);
        }
        $this->assertTrue(NetworkGuard::isPublicIp('157.240.1.1'));
        $this->expectException(RuntimeException::class);
        NetworkGuard::resolve('evil.example.com');
    }

    public function testAttackTamperedSettingsNeverChangeTheAddress(): void
    {
        Settings::set('phone_number_id', '1/../../me?x=');
        $result = (new MetaClient())->sendTemplate('447911123456', 'hello', 'en', []);
        $this->assertFalse($result->ok);
        $this->assertSame('not_configured', $result->errorCode);
        $this->assertCount(0, $this->meta->requests);
    }

    public function testMetaRequestFormatAndTokenOnlyInHeader(): void
    {
        $result = (new MetaClient())->sendTemplate('447911123456', 'hello_world', 'en_GB', ['Alex']);
        $this->assertTrue($result->ok);
        $req = $this->meta->requests[0];
        $this->assertContains('Authorization: Bearer EAAtesttoken1234567890', $req['headers']);
        $this->assertSame(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => '447911123456', 'type' => 'template',
            'template' => ['name' => 'hello_world', 'language' => ['code' => 'en_GB'],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Alex']]]]]], $req['body']);
    }

    public function testMetaRateLimitErrorIsRetryable(): void
    {
        $this->meta->answers = [FakeTransport::error(400, 131056, 'Pair rate limit')];
        $this->assertTrue((new MetaClient())->sendTemplate('447911123456', 'hello', 'en', [])->retryable);
        $this->meta->answers = [['status' => 0, 'body' => '', 'error' => 'Connection timed out']];
        $this->assertTrue((new MetaClient())->sendTemplate('447911123456', 'hello', 'en', [])->retryable);
    }
}
