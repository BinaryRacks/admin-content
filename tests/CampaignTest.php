<?php
/**
 * Tests bulk marketing: who receives it, the daily cap, the daytime rule, pause/resume/cancel and counters.
 */

declare(strict_types=1);

use BinaryRacks\WhatsApp\Campaigns;
use BinaryRacks\WhatsApp\Messages;
use BinaryRacks\WhatsApp\Settings;
use WHMCS\Database\Capsule;

final class CampaignTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = Messages::create('marketing', ['label' => 'Black Friday', 'template_name' => 'black_friday', 'language' => '', 'params' => 'client_firstname'], true);
    }

    public function testOnlyOptedInActiveClientsWithoutDisableAreRecipients(): void
    {
        $yes = FakeWhmcs::addClient();
        $noOptIn = FakeWhmcs::addClient(['marketing_emails_opt_in' => 0]);
        $closed = FakeWhmcs::addClient(['status' => 'Closed']);
        $disabled = FakeWhmcs::addClient();
        $this->tickDisable($disabled);
        $this->assertSame([$yes], Campaigns::recipientIds());
    }

    public function testCampaignIsQueuedThenSentByCronWithCounters(): void
    {
        FakeWhmcs::addClient();
        FakeWhmcs::addClient(['phonenumber' => '+44.7911000002']);
        $id = Campaigns::start($this->key, 1);
        $this->assertCount(0, $this->meta->requests, 'starting only queues');
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(2, $this->meta->requests);
        $c = Capsule::table('mod_brwa_campaigns')->find($id);
        $this->assertSame('completed', $c->status);
        $this->assertSame(2, (int) $c->sent);
        $this->assertSame('black_friday', $this->meta->requests[0]['body']['template']['name']);
    }

    public function testDailyCapHoldsTheRestUntilTomorrow(): void
    {
        Settings::set('daily_cap', '2');
        for ($i = 0; $i < 5; $i++) {
            FakeWhmcs::addClient(['phonenumber' => '+44.791100000' . $i]);
        }
        Campaigns::start($this->key, 1);
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(2, $this->meta->requests);
        $this->assertSame(3, Capsule::table('mod_brwa_queue')->where('status', 'pending')->where('send_after', '2026-11-14 00:05:00')->count());
    }

    public function testBatchSizeLimitsEachCronRun(): void
    {
        Settings::set('batch_size', '2');
        for ($i = 0; $i < 3; $i++) {
            FakeWhmcs::addClient(['phonenumber' => '+44.791100000' . $i]);
        }
        Campaigns::start($this->key, 1);
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(2, $this->meta->requests);
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(3, $this->meta->requests);
    }

    public function testPauseResumeAndCancel(): void
    {
        FakeWhmcs::addClient();
        $id = Campaigns::start($this->key, 1);
        Campaigns::pause($id);
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(0, $this->meta->requests, 'paused campaigns send nothing');
        Campaigns::resume($id);
        Campaigns::cancel($id);
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(0, $this->meta->requests);
        $this->assertSame('cancelled', Campaigns::statusOf($id));
    }

    public function testOptOutAfterCampaignStartIsRespected(): void
    {
        $client = FakeWhmcs::addClient();
        Campaigns::start($this->key, 1);
        Capsule::table('tblclients')->where('id', $client)->update(['marketing_emails_opt_in' => 0]);
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(0, $this->meta->requests);
        $this->assertSame('no_marketing_opt_in', $this->logRows()[0]->error_code);
    }

    public function testMarketingRespectsDaytime(): void
    {
        FakeWhmcs::addClient();
        $this->at('2026-11-13 20:00:00');
        Campaigns::start($this->key, 1);
        FakeWhmcs::runHook('AfterCronJob');
        $this->assertCount(0, $this->meta->requests);
    }

    public function testOnlyEnabledMarketingMessagesCanStart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Campaigns::start('invoice_created', 1);   // an event, not a marketing message
    }
}
