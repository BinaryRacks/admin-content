<?php
/**
 * Base class for all tests: gives each test a fresh pretend WHMCS with the module activated,
 * a fake Meta server, a fixed clock (Wednesday 13 Nov 2026, 10:00 UTC) and helper methods.
 */

declare(strict_types=1);

use BinaryRacks\WhatsApp\Clock;
use BinaryRacks\WhatsApp\Consent;
use BinaryRacks\WhatsApp\Messages;
use BinaryRacks\WhatsApp\MetaClient;
use BinaryRacks\WhatsApp\Settings;
use WHMCS\Database\Capsule;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected FakeTransport $meta;

    /** Fresh database, module activated and Meta configured before every test. */
    protected function setUp(): void
    {
        date_default_timezone_set('UTC');
        FakeWhmcs::reset();
        Settings::reset();
        Consent::reset();
        $_SESSION = ['adminid' => 1];
        Clock::$frozen = new DateTimeImmutable('2026-11-13 10:00:00', new DateTimeZone('UTC'));
        $this->meta = new FakeTransport();
        MetaClient::$transport = $this->meta;

        $result = br_whatsapp_activate();
        $this->assertSame('success', $result['status']);
        Settings::set('phone_number_id', '123456789012');
        Settings::setSecret('access_token', 'EAAtesttoken1234567890');
        Settings::setSecret('app_secret', 'abcdef0123456789abcdef0123456789');
        Settings::setSecret('verify_token', 'verify-token-1234567890');
    }

    protected function tearDown(): void
    {
        Clock::$frozen = null;
        MetaClient::$transport = null;
    }

    /** Switches an event message on, with its default template. */
    protected function enable(string $key): void
    {
        Capsule::table(Messages::TABLE)->where('msg_key', $key)->update(['enabled' => 1]);
    }

    /** Ticks disable_whatsapp for a client. */
    protected function tickDisable(int $clientId): void
    {
        Capsule::table('tblcustomfieldsvalues')->insert(['fieldid' => Consent::fieldId(), 'relid' => $clientId, 'value' => 'on']);
    }

    /** Returns all log rows as objects. */
    protected function logRows(): array
    {
        $rows = [];
        foreach (Capsule::table('mod_brwa_log')->orderBy('id')->get() as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** Sets the frozen clock. */
    protected function at(string $utc): void
    {
        Clock::$frozen = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }
}
