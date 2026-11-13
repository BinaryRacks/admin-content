<?php
/**
 * Tests the GDPR rules for logs: minimal data, masked numbers, retention (STOP proof kept),
 * export (right of access), erasure (right to be forgotten) and the ClientDelete hook.
 */

declare(strict_types=1);

use BinaryRacks\WhatsApp\Contacts;
use BinaryRacks\WhatsApp\MessageLog;
use BinaryRacks\WhatsApp\Settings;
use WHMCS\Database\Capsule;

final class GdprTest extends TestCase
{
    public function testLogHoldsNoNamesNoTextAndOnlyAMaskedNumber(): void
    {
        $client = FakeWhmcs::addClient(['firstname' => 'Zebedee', 'lastname' => 'Uniquename']);
        $this->enable('invoice_created');
        $inv = (int) Capsule::table('tblinvoices')->insertGetId(['userid' => $client, 'duedate' => '2026-11-20', 'total' => 99, 'status' => 'Unpaid']);
        FakeWhmcs::runHook('InvoiceCreated', ['invoiceid' => $inv]);
        FakeWhmcs::runHook('AfterCronJob');
        $everything = json_encode([
            Capsule::table('mod_brwa_log')->get(), Capsule::table('mod_brwa_queue')->get(), Capsule::table('mod_brwa_contacts')->get(),
        ]);
        $this->assertStringNotContainsString('Zebedee', $everything);
        $this->assertStringNotContainsString('Uniquename', $everything);
        $this->assertStringNotContainsString('7911123456', $everything);
        $this->assertStringContainsString('+44******3456', $everything);
    }

    public function testRetentionDeletesOldEntriesButKeepsStopProof(): void
    {
        $client = FakeWhmcs::addClient();
        MessageLog::add($client, '', 'invoice_created', 'event', 1, 'sent');
        MessageLog::add($client, '', 'stop', 'webhook', null, 'stop', null, null, MessageLog::STOP_TEXT, true);
        $this->at('2027-03-01 10:00:00');   // more than 90 days later
        MessageLog::add($client, '', 'invoice_paid', 'event', 1, 'sent');
        FakeWhmcs::runHook('DailyCronJob');
        $statuses = array_column(array_map(fn ($r) => (array) $r, $this->logRows()), 'status');
        $this->assertSame(['stop', 'sent'], $statuses);
    }

    public function testRetentionPeriodIsASetting(): void
    {
        Settings::set('log_days', '10');
        MessageLog::add(null, '', 'x', 'test', null, 'sent');
        $this->at('2026-11-24 10:00:00');
        MessageLog::cleanup();
        $this->assertCount(0, $this->logRows());
    }

    public function testExportContainsOnlyThatClientAndBlocksSpreadsheetFormulas(): void
    {
        $a = FakeWhmcs::addClient();
        $b = FakeWhmcs::addClient();
        MessageLog::add($a, '+44******3456', 'invoice_created', 'event', 7, 'failed', null, '=HYPERLINK("http://evil")', 'x');
        MessageLog::add($b, '+44******9999', 'invoice_paid', 'event', 8, 'sent');
        $csv = MessageLog::exportClientCsv($a);
        $this->assertStringContainsString('invoice_created', $csv);
        $this->assertStringNotContainsString('invoice_paid', $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function testEraseRemovesLogStopProofQueueAndHash(): void
    {
        $client = FakeWhmcs::addClient();
        $other = FakeWhmcs::addClient();
        MessageLog::add($client, '', 'stop', 'webhook', null, 'stop', null, null, MessageLog::STOP_TEXT, true);
        MessageLog::add($other, '', 'invoice_paid', 'event', 1, 'sent');
        Contacts::remember($client, '447911123456');
        \BinaryRacks\WhatsApp\Queue::add($client, 'invoice_paid', 'event', 1, null, false);
        MessageLog::eraseClient($client);
        $this->assertSame(0, Capsule::table('mod_brwa_log')->where('client_id', $client)->count());
        $this->assertSame(0, Capsule::table('mod_brwa_queue')->where('client_id', $client)->count());
        $this->assertSame(0, Capsule::table('mod_brwa_contacts')->where('client_id', $client)->count());
        $this->assertSame(1, Capsule::table('mod_brwa_log')->count(), 'other clients are untouched');
    }

    public function testClientDeleteHookErasesData(): void
    {
        $client = FakeWhmcs::addClient();
        MessageLog::add($client, '', 'stop', 'webhook', null, 'stop', null, null, MessageLog::STOP_TEXT, true);
        FakeWhmcs::runHook('ClientDelete', ['userid' => $client]);
        $this->assertCount(0, $this->logRows());
    }

    public function testPhoneHashIsOneWay(): void
    {
        $client = FakeWhmcs::addClient();
        Contacts::remember($client, '447911123456');
        $hash = (string) Capsule::table('mod_brwa_contacts')->value('phone_hash');
        $this->assertNotSame(hash('sha256', '447911123456'), $hash, 'keyed hash, not a plain hash that could be looked up');
        $this->assertSame([$client], Contacts::clientsFor('447911123456'));
    }
}
