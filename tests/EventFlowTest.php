<?php
/**
 * Tests the full journey of automatic notifications: WHMCS event -> queue -> cron -> Meta,
 * including category and event switches, consent, the daytime rule, duplicates, retries and ownership checks.
 */

declare(strict_types=1);

use WHMCS\Database\Capsule;

final class EventFlowTest extends TestCase
{
    private int $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = FakeWhmcs::addClient();
    }

    private function invoice(int $client, string $status = 'Unpaid'): int
    {
        return (int) Capsule::table('tblinvoices')->insertGetId(['userid' => $client, 'duedate' => '2026-11-20', 'total' => 10, 'status' => $status]);
    }

    public function testEnabledEventIsQueuedAndSentByTheCron(): void
    {
        $this->enable('invoice_created');
        $inv = $this->invoice($this->client);
        FakeWhmcs::runHook('InvoiceCreated', ['invoiceid' => $inv, 'status' => 'Unpaid']);
        $this->assertCount(0, $this->meta->requests, 'not sent before the cron');

        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(1, $this->meta->requests);
        $request = $this->meta->requests[0];
        $this->assertSame('graph.facebook.com', $request['host']);
        $this->assertSame('/v21.0/123456789012/messages', $request['path']);
        $this->assertSame('447911123456', $request['body']['to']);
        $this->assertSame('invoice_created', $request['body']['template']['name']);
        $this->assertSame('en', $request['body']['template']['language']['code']);
        $values = array_column($request['body']['template']['components'][0]['parameters'], 'text');
        $this->assertSame(['Alex', (string) $inv, '£10.00 GBP', '20 Nov 2026'], $values);

        $log = $this->logRows();
        $this->assertSame('sent', $log[0]->status);
        $this->assertSame('+44******3456', $log[0]->phone_masked);
        $this->assertStringStartsWith('wamid.', $log[0]->wamid);
    }

    public function testEventSwitchedOffSendsNothing(): void
    {
        FakeWhmcs::runHook('InvoiceCreated', ['invoiceid' => $this->invoice($this->client)]);
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(0, $this->meta->requests);
        $this->assertCount(0, $this->logRows());
    }

    public function testCategorySwitchedOffSendsNothing(): void
    {
        $this->enable('invoice_created');
        \BinaryRacks\WhatsApp\Settings::set('cat_invoices', '0');
        FakeWhmcs::runHook('InvoiceCreated', ['invoiceid' => $this->invoice($this->client)]);
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(0, $this->meta->requests);
    }

    public function testCategorySwitchedOffAfterQueueingIsSkippedAtSendTime(): void
    {
        $this->enable('invoice_created');
        FakeWhmcs::runHook('InvoiceCreated', ['invoiceid' => $this->invoice($this->client)]);
        \BinaryRacks\WhatsApp\Settings::set('cat_invoices', '0');
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(0, $this->meta->requests);
        $this->assertSame('category_disabled', $this->logRows()[0]->error_code);
    }

    public function testDisableWhatsAppBlocksEverything(): void
    {
        $this->enable('invoice_created');
        $this->tickDisable($this->client);
        FakeWhmcs::runHook('InvoiceCreated', ['invoiceid' => $this->invoice($this->client)]);
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(0, $this->meta->requests);
        $this->assertSame('disable_whatsapp_ticked', $this->logRows()[0]->error_code);
    }

    public function testConsentIsCheckedAgainJustBeforeSending(): void
    {
        $this->enable('invoice_created');
        FakeWhmcs::runHook('InvoiceCreated', ['invoiceid' => $this->invoice($this->client)]);
        $this->tickDisable($this->client);   // client opts out while the message waits
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(0, $this->meta->requests);
        $this->assertSame('skipped', $this->logRows()[0]->status);
    }

    public function testSameEventIsNeverSentTwice(): void
    {
        $this->enable('invoice_created');
        $inv = $this->invoice($this->client);
        FakeWhmcs::runHook('InvoiceCreated', ['invoiceid' => $inv]);
        FakeWhmcs::runHook('InvoiceCreated', ['invoiceid' => $inv]);
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(1, $this->meta->requests);
    }

    public function testDraftInvoicesAreIgnored(): void
    {
        $this->enable('invoice_created');
        FakeWhmcs::runHook('InvoiceCreated', ['invoiceid' => $this->invoice($this->client, 'Draft'), 'status' => 'Draft']);
        $this->assertSame(0, Capsule::table('mod_brwa_queue')->count());
    }

    public function testNightTimeMessageWaitsUntil8amClientTime(): void
    {
        $this->enable('invoice_created');
        $this->at('2026-11-13 22:30:00');   // 22:30 in London
        FakeWhmcs::runHook('InvoiceCreated', ['invoiceid' => $this->invoice($this->client)]);
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(0, $this->meta->requests);
        $this->assertSame('2026-11-14 08:00:00', Capsule::table('mod_brwa_queue')->value('send_after'));

        $this->at('2026-11-14 08:01:00');
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(1, $this->meta->requests);
    }

    public function testDaytimeUsesTheClientsOwnTimeZone(): void
    {
        $this->enable('invoice_created');
        $us = FakeWhmcs::addClient(['country' => 'US', 'state' => 'CA', 'phonenumber' => '+1.4155550123']);
        // 10:00 UTC is 02:00 in California, so the message must wait until 08:00 Pacific (16:00 UTC).
        FakeWhmcs::runHook('InvoiceCreated', ['invoiceid' => $this->invoice($us)]);
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(0, $this->meta->requests);
        $this->assertSame('2026-11-13 16:00:00', Capsule::table('mod_brwa_queue')->value('send_after'));
    }

    public function testSuspensionIsCriticalAndSentImmediatelyAtNightWithReason(): void
    {
        $this->enable('service_suspended');
        $this->at('2026-11-13 02:00:00');
        $service = (int) Capsule::table('tblhosting')->insertGetId(['userid' => $this->client, 'packageid' => 1, 'domain' => 'vps1.example.com', 'suspendreason' => 'Overdue on payment']);
        FakeWhmcs::runHook('AfterModuleSuspend', ['params' => ['serviceid' => $service, 'userid' => $this->client]]);
        $this->assertCount(1, $this->meta->requests, 'sent at once, without waiting for the cron');
        $values = array_column($this->meta->requests[0]['body']['template']['components'][0]['parameters'], 'text');
        $this->assertSame(['Alex', 'VPS Small', 'Overdue on payment'], $values);
    }

    public function testUnsuspensionIsNotCritical(): void
    {
        $this->enable('service_unsuspended');
        $this->at('2026-11-13 02:00:00');
        $service = (int) Capsule::table('tblhosting')->insertGetId(['userid' => $this->client, 'packageid' => 1]);
        FakeWhmcs::runHook('AfterModuleUnsuspend', ['params' => ['serviceid' => $service]]);
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(0, $this->meta->requests);
    }

    public function testInvalidPhoneIsSkippedAndLogged(): void
    {
        $this->enable('invoice_created');
        $bad = FakeWhmcs::addClient(['phonenumber' => '12ab']);
        FakeWhmcs::runHook('InvoiceCreated', ['invoiceid' => $this->invoice($bad)]);
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(0, $this->meta->requests);
        $this->assertSame('no_valid_phone', $this->logRows()[0]->error_code);
    }

    public function testTemporaryMetaErrorIsRetriedLater(): void
    {
        $this->enable('invoice_created');
        $this->meta->answers = [FakeTransport::error(503, 2, 'Service unavailable'), null];
        FakeWhmcs::runHook('InvoiceCreated', ['invoiceid' => $this->invoice($this->client)]);
        FakeWhmcs::runHook('AfterCronJob');
        $row = Capsule::table('mod_brwa_queue')->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame('2026-11-13 10:05:00', $row->send_after);
        $this->assertCount(0, $this->logRows());

        $this->at('2026-11-13 10:06:00');
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertSame('sent', $this->logRows()[0]->status);
    }

    public function testPermanentMetaErrorIsLoggedWithoutPersonalData(): void
    {
        $this->enable('invoice_created');
        $this->meta->answers = [FakeTransport::error(400, 132001, 'Template invoice_created does not exist for +447911123456 alex@example.com')];
        FakeWhmcs::runHook('InvoiceCreated', ['invoiceid' => $this->invoice($this->client)]);
        FakeWhmcs::runHook('AfterCronJob');
        $log = $this->logRows()[0];
        $this->assertSame('failed', $log->status);
        $this->assertSame('132001', $log->error_code);
        $this->assertStringNotContainsString('7911123456', $log->error_text);
        $this->assertStringNotContainsString('alex@example.com', $log->error_text);
    }

    public function testInvoiceOfAnotherClientIsNeverUsed(): void
    {
        // A queued row pointing at someone else's invoice (e.g. tampered data) must not leak it.
        $this->enable('invoice_created');
        $other = FakeWhmcs::addClient(['firstname' => 'Other']);
        $otherInvoice = $this->invoice($other);
        \BinaryRacks\WhatsApp\Queue::add($this->client, 'invoice_created', 'event', $otherInvoice, null, false);
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(0, $this->meta->requests);
        $this->assertSame('record_not_found', $this->logRows()[0]->error_code);
    }

    public function testTicketReplyAndDomainEvents(): void
    {
        $this->enable('ticket_reply');
        $this->enable('domain_renewed');
        $this->enable('domain_expiring');
        $ticket = (int) Capsule::table('tbltickets')->insertGetId(['tid' => 'ABC-123', 'userid' => $this->client, 'title' => "Line1\nLine2"]);
        FakeWhmcs::runHook('TicketAdminReply', ['ticketid' => $ticket, 'replyid' => 5]);
        $domain = (int) Capsule::table('tbldomains')->insertGetId(['userid' => $this->client, 'domain' => 'example.com', 'expirydate' => '2026-11-27']);
        FakeWhmcs::runHook('AfterRegistrarRenewal', ['params' => ['domainid' => $domain]]);
        FakeWhmcs::runHook('DailyCronJob');   // 14 days before 27 Nov
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(3, $this->meta->requests);
        $ticketValues = array_column($this->meta->requests[0]['body']['template']['components'][0]['parameters'], 'text');
        $this->assertSame('Line1 Line2', $ticketValues[2], 'new lines are flattened for Meta');
    }

    public function testGuestTicketWithoutClientIsIgnored(): void
    {
        $this->enable('ticket_reply');
        $ticket = (int) Capsule::table('tbltickets')->insertGetId(['tid' => 'X', 'userid' => 0, 'title' => 'Guest']);
        FakeWhmcs::runHook('TicketAdminReply', ['ticketid' => $ticket, 'replyid' => 1]);
        $this->assertSame(0, Capsule::table('mod_brwa_queue')->count());
    }

    public function testNothingIsSentWhenMetaIsNotConfigured(): void
    {
        $this->enable('invoice_created');
        \BinaryRacks\WhatsApp\Settings::setSecret('access_token', '');
        FakeWhmcs::runHook('InvoiceCreated', ['invoiceid' => $this->invoice($this->client)]);
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(0, $this->meta->requests);
    }

    public function testAHookErrorNeverBreaksWhmcs(): void
    {
        Capsule::schema()->drop('mod_brwa_queue');   // simulate a broken table
        $this->enable('invoice_created');
        $results = FakeWhmcs::runHook('InvoiceCreated', ['invoiceid' => $this->invoice($this->client)]);
        $this->assertSame([null], $results);
        $this->assertStringContainsString('InvoiceCreated failed', implode(' ', FakeWhmcs::$activity));
    }
}
