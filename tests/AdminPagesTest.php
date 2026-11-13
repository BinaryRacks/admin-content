<?php
/**
 * Tests the admin pages: every tab renders with the logo, permissions are enforced on the server,
 * forged forms (CSRF) change nothing, secrets never appear in the page, XSS is escaped,
 * the "type the token again" rule, manual sends (one-time code, daytime) and SQL injection attempts.
 */

declare(strict_types=1);

use BinaryRacks\WhatsApp\AdminController;
use BinaryRacks\WhatsApp\Csrf;
use BinaryRacks\WhatsApp\Messages;
use BinaryRacks\WhatsApp\Settings;
use WHMCS\Database\Capsule;

final class AdminPagesTest extends TestCase
{
    private const LINK = 'addonmodules.php?module=br_whatsapp';

    private function page(string $action, array $post = [], array $get = []): string
    {
        $method = $post === [] ? 'GET' : 'POST';
        return (new AdminController(self::LINK, ['action' => $action] + $get, $post, $method))->render();
    }

    private function token(): string
    {
        return Csrf::token();
    }

    /** Extracts the one-time code from a rendered form. */
    private function onceCode(string $html): string
    {
        preg_match_all('/name="brwa_once" value="([a-f0-9]+)"/', $html, $m);
        return (string) end($m[1]);
    }

    public function testEveryTabRendersWithLogoNameAndVersion(): void
    {
        foreach (['dashboard', 'notifications', 'messages', 'marketing', 'log', 'settings'] as $tab) {
            $html = $this->page($tab);
            $this->assertStringContainsString('src="data:image/svg+xml;base64,', $html, $tab);
            $this->assertStringContainsString('Binary Racks WhatsApp Notifications', $html, $tab);
            $this->assertStringContainsString('v1.0.0', $html, $tab);
            $this->assertStringNotContainsString('Something went wrong', $html, $tab);
        }
    }

    public function testPermissionsAreCheckedOnTheServer(): void
    {
        $_SESSION['adminid'] = 3;   // Support Operator, no extra roles chosen
        $this->assertStringContainsString('not allowed', $this->page('settings'));
        $this->assertStringContainsString('not allowed', $this->page('marketing'));
        $this->assertStringNotContainsString('not allowed', $this->page('dashboard'), 'send role default = everyone');

        // A forged POST to settings by a non-permitted admin must change nothing.
        $this->page('settings', ['do' => 'save', 'brwa_token' => $this->token(), 'phone_number_id' => '99999', 'waba_id' => '', 'api_version' => 'v21.0', 'language' => 'en',
            'batch_size' => '1', 'daily_cap' => '1', 'log_days' => '1', 'domain_expiry_days' => '1']);
        $this->assertSame('123456789012', Settings::get('phone_number_id'));

        Settings::set('roles_settings', '3');
        $this->assertStringNotContainsString('not allowed', $this->page('settings'));
    }

    public function testDisabledOrUnknownAdminHasNoAccess(): void
    {
        $_SESSION['adminid'] = 99;
        $this->assertStringContainsString('not allowed', $this->page('dashboard'));
        Capsule::table('tbladmins')->where('id', 1)->update(['disabled' => 1]);
        $_SESSION['adminid'] = 1;
        $this->assertStringContainsString('not allowed', $this->page('dashboard'));
    }

    public function testAttackCsrfWithoutTokenChangesNothing(): void
    {
        $html = $this->page('notifications', ['do' => 'categories', 'categories' => []]);
        $this->assertStringContainsString('Nothing was changed', $html);
        $this->assertTrue(Settings::bool('cat_invoices'));
    }

    public function testAttackCsrfWithWrongTokenChangesNothing(): void
    {
        $this->token();
        $this->page('notifications', ['do' => 'categories', 'categories' => [], 'brwa_token' => str_repeat('a', 64)]);
        $this->assertTrue(Settings::bool('cat_invoices'));
    }

    public function testAttackGetRequestCannotChangeAnything(): void
    {
        (new AdminController(self::LINK, ['action' => 'notifications', 'do' => 'categories', 'brwa_token' => $this->token()], [], 'GET'))->render();
        $this->assertTrue(Settings::bool('cat_invoices'));
    }

    public function testSecretsAreNeverShownInThePage(): void
    {
        $html = $this->page('settings');
        $this->assertStringNotContainsString('EAAtesttoken1234567890', $html);
        $this->assertStringNotContainsString('abcdef0123456789abcdef0123456789', $html);
        $this->assertStringNotContainsString('verify-token-1234567890', $html);
        $this->assertStringContainsString('Saved (hidden)', $html);
        // And they are stored encrypted, not as plain text.
        $this->assertStringNotContainsString('EAAtesttoken', (string) Capsule::table('mod_brwa_settings')->where('setting', 'access_token')->value('value'));
    }

    private function settingsPost(array $changes): array
    {
        return $changes + ['do' => 'save', 'brwa_token' => $this->token(), 'phone_number_id' => '123456789012', 'waba_id' => '', 'api_version' => 'v21.0',
            'language' => 'en', 'batch_size' => '50', 'daily_cap' => '1000', 'log_days' => '90', 'domain_expiry_days' => '14'];
    }

    public function testChangingThePhoneNumberIdRequiresTheTokenAgain(): void
    {
        $html = $this->page('settings', $this->settingsPost(['phone_number_id' => '555555555']));
        $this->assertStringContainsString('type the access token again', $html);
        $this->assertSame('123456789012', Settings::get('phone_number_id'));

        $this->page('settings', $this->settingsPost(['phone_number_id' => '555555555', 'access_token' => 'EAAnewtoken99999999999']));
        $this->assertSame('555555555', Settings::get('phone_number_id'));
        $this->assertSame('EAAnewtoken99999999999', Settings::secret('access_token'));
    }

    public function testAttackSettingsCannotPointTheModuleElsewhere(): void
    {
        foreach (['../../evil', '123/../../x', 'http://169.254.169.254', '12345?x=1'] as $evil) {
            $this->page('settings', $this->settingsPost(['phone_number_id' => $evil, 'access_token' => 'EAAnewtoken99999999999']));
            $this->assertSame('123456789012', Settings::get('phone_number_id'), $evil);
        }
        $this->page('settings', $this->settingsPost(['api_version' => 'v21.0/../../evil']));
        $this->assertSame('v21.0', Settings::get('api_version'));
    }

    public function testInvalidNumbersAreRejected(): void
    {
        $html = $this->page('settings', $this->settingsPost(['batch_size' => '0', 'log_days' => '-5']));
        $this->assertStringContainsString('Batch size must be', $html);
        $this->assertSame('50', Settings::get('batch_size'));
    }

    public function testRolesOnlyAcceptRealRoleIds(): void
    {
        $this->page('settings', $this->settingsPost(['roles_bulk' => ['2', '999', 'x; DROP TABLE tbladmins']]));
        $this->assertSame([2], Settings::roleIds('roles_bulk'));
        $this->assertTrue(Capsule::schema()->hasTable('tbladmins'));
    }

    public function testAttackXssInClientNameIsEscapedOnSendPage(): void
    {
        $client = FakeWhmcs::addClient(['firstname' => '<script>alert(1)</script>', 'companyname' => '"><img src=x onerror=alert(2)>']);
        $html = $this->page('send', [], ['userid' => (string) $client]);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testAttackXssInMessageNameIsEscaped(): void
    {
        $this->page('messages', ['do' => 'save', 'brwa_token' => $this->token(), 'id' => '0', 'kind' => 'manual',
            'label' => '<svg onload=alert(1)>', 'template_name' => 'promo', 'language' => '', 'params' => '', 'enabled' => '1']);
        $html = $this->page('messages');
        $this->assertStringNotContainsString('<svg onload', $html);
        $this->assertStringContainsString('&lt;svg onload=alert(1)&gt;', $html);
    }

    public function testBadTemplateNamesAndDisallowedBlanksAreRejected(): void
    {
        $html = $this->page('messages', ['do' => 'save', 'brwa_token' => $this->token(), 'id' => '0', 'kind' => 'marketing',
            'label' => 'Promo', 'template_name' => 'Bad Name!', 'language' => 'english', 'params' => 'invoice_total', 'enabled' => '1']);
        $this->assertStringContainsString('Template name may only use', $html);
        $this->assertStringContainsString('Language must look like', $html);
        $this->assertStringContainsString('not allowed for this message: invoice_total', $html);
        $this->assertSame([], Messages::allOfKind('marketing'));
    }

    public function testBuiltInMessagesCannotBeDeletedOrRenamed(): void
    {
        $builtin = Messages::find('manual_invoice_reminder');
        $html = $this->page('messages', ['do' => 'delete', 'brwa_token' => $this->token(), 'id' => (string) $builtin->id]);
        $this->assertStringContainsString('can only be switched off', $html);
        $this->assertNotNull(Messages::find('manual_invoice_reminder'));
    }

    public function testAttackLogFiltersCannotInjectSql(): void
    {
        $html = $this->page('log', [], ['client_id' => "1 OR 1=1", 'status' => "sent' OR '1'='1", 'from' => "2026-01-01' --"]);
        $this->assertStringContainsString('Nothing found', $html);
        $this->assertTrue(Capsule::schema()->hasTable('mod_brwa_log'));
    }

    public function testManualSendWithPreviewOneTimeCodeAndInvoiceOwnership(): void
    {
        $client = FakeWhmcs::addClient();
        $inv = (int) Capsule::table('tblinvoices')->insertGetId(['userid' => $client, 'duedate' => '2026-11-20', 'total' => 25, 'status' => 'Unpaid']);
        $other = FakeWhmcs::addClient();
        $otherInv = (int) Capsule::table('tblinvoices')->insertGetId(['userid' => $other, 'duedate' => '2026-11-20', 'total' => 1, 'status' => 'Unpaid']);
        $get = ['userid' => (string) $client];

        // Someone else's invoice is refused.
        $html = $this->page('send', ['do' => 'preview', 'brwa_token' => $this->token(), 'msg_key' => 'manual_invoice_reminder', 'invoice_id' => (string) $otherInv], $get);
        $this->assertStringContainsString('unpaid invoices', $html);

        $html = $this->page('send', ['do' => 'preview', 'brwa_token' => $this->token(), 'msg_key' => 'manual_invoice_reminder', 'invoice_id' => (string) $inv], $get);
        $this->assertStringContainsString('£25.00 GBP', $html);
        $code = $this->onceCode($html);

        $send = ['do' => 'send', 'brwa_token' => $this->token(), 'brwa_once' => $code, 'msg_key' => 'manual_invoice_reminder', 'invoice_id' => (string) $inv];
        $this->assertStringContainsString('Message sent to Meta', $this->page('send', $send, $get));
        // Refresh / resubmit: the same code can't send twice.
        $this->assertStringContainsString('already used', $this->page('send', $send, $get));
        $this->assertCount(1, $this->meta->requests);
        $this->assertSame('invoice_reminder', $this->meta->requests[0]['body']['template']['name']);
    }

    public function testManualSendAtNightWaitsForDaytime(): void
    {
        $client = FakeWhmcs::addClient();
        $inv = (int) Capsule::table('tblinvoices')->insertGetId(['userid' => $client, 'duedate' => '2026-11-20', 'total' => 25, 'status' => 'Unpaid']);
        $this->at('2026-11-13 21:00:00');
        $html = $this->page('send', ['do' => 'preview', 'brwa_token' => $this->token(), 'msg_key' => 'manual_invoice_reminder', 'invoice_id' => (string) $inv], ['userid' => (string) $client]);
        $html = $this->page('send', ['do' => 'send', 'brwa_token' => $this->token(), 'brwa_once' => $this->onceCode($html), 'msg_key' => 'manual_invoice_reminder', 'invoice_id' => (string) $inv], ['userid' => (string) $client]);
        $this->assertStringContainsString('2026-11-14 08:00:00 UTC', $html);
        $this->assertCount(0, $this->meta->requests);
    }

    public function testManualSendRefusedWhenClientDisabledWhatsApp(): void
    {
        $client = FakeWhmcs::addClient();
        $this->tickDisable($client);
        $html = $this->page('send', [], ['userid' => (string) $client]);
        $this->assertStringContainsString("can't receive WhatsApp", $html);
    }

    public function testCampaignStartNeedsOneTimeCodeAndBulkRole(): void
    {
        FakeWhmcs::addClient();
        $key = Messages::create('marketing', ['label' => 'Promo', 'template_name' => 'promo', 'language' => '', 'params' => ''], true);
        $html = $this->page('marketing');
        $post = ['do' => 'start', 'brwa_token' => $this->token(), 'brwa_once' => $this->onceCode($html), 'msg_key' => $key];
        $this->assertStringContainsString('started', $this->page('marketing', $post));
        $this->assertStringContainsString('already used', $this->page('marketing', $post));
        $this->assertSame(1, Capsule::table('mod_brwa_campaigns')->count());

        $_SESSION['adminid'] = 2;   // Sales Operator without the bulk role
        $this->page('marketing', ['do' => 'start', 'brwa_token' => $this->token(), 'brwa_once' => 'x', 'msg_key' => $key]);
        $this->assertSame(1, Capsule::table('mod_brwa_campaigns')->count());
    }

    public function testGdprExportAndErase(): void
    {
        $client = FakeWhmcs::addClient();
        \BinaryRacks\WhatsApp\MessageLog::add($client, '+44******3456', 'invoice_paid', 'event', 1, 'sent');
        $html = $this->page('log', ['do' => 'export', 'brwa_token' => $this->token(), 'gdpr_client_id' => (string) $client]);
        $this->assertStringContainsString('data:text/csv;charset=utf-8;base64,', $html);

        $html = $this->page('log', ['do' => 'erase', 'brwa_token' => $this->token(), 'gdpr_client_id' => (string) $client, 'confirm' => 'nope']);
        $this->assertStringContainsString('Type ERASE', $html);
        $this->page('log', ['do' => 'erase', 'brwa_token' => $this->token(), 'gdpr_client_id' => (string) $client, 'confirm' => 'ERASE']);
        $this->assertCount(0, $this->logRows());
        $this->assertStringNotContainsString('Alex', implode(' ', FakeWhmcs::$activity), 'activity log has no personal data');
    }

    public function testTestSendUsesSampleValues(): void
    {
        $event = Messages::find('invoice_created');
        $html = $this->page('notifications', ['do' => 'test', 'brwa_token' => $this->token(), 'id' => (string) $event->id, 'test_number' => '+44 7911 123456']);
        $this->assertStringContainsString('Test sent', $html);
        $values = array_column($this->meta->requests[0]['body']['template']['components'][0]['parameters'], 'text');
        $this->assertSame(['Alex', '1001', '£10.00 GBP', '20 Nov 2026'], $values);
    }

    public function testSummaryLinkOnlyForAdminsAllowedToSend(): void
    {
        $links = FakeWhmcs::runHook('AdminAreaClientSummaryActionLinks', ['userid' => 5])[0];
        $this->assertStringContainsString('action=send&amp;userid=5', $links[0]);
        Settings::set('roles_send', '2');
        $_SESSION['adminid'] = 3;
        $this->assertSame([], FakeWhmcs::runHook('AdminAreaClientSummaryActionLinks', ['userid' => 5])[0]);
    }
}
