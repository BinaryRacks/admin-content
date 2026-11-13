<?php
/**
 * The module's admin page (Addons > Binary Racks WhatsApp Notifications).
 * Picks the tab, checks the admin's permission for it, handles form posts
 * (every change: POST + CSRF token + role check) and renders the views.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit;
}

final class AdminController
{
    /** Tab name => [label, job needed]. */
    private const TABS = [
        'dashboard'     => ['Dashboard', 'send'],
        'notifications' => ['Notifications', 'settings'],
        'messages'      => ['Manual & marketing messages', 'settings'],
        'marketing'     => ['Bulk marketing', 'bulk'],
        'log'           => ['Log & GDPR', 'settings'],
        'settings'      => ['Settings', 'settings'],
    ];

    /** Manual sends allowed per admin per minute. */
    private const SEND_LIMIT_PER_MINUTE = 20;

    /** Messages shown at the top of the page: [type (success|danger|info), text]. */
    private array $notices = [];

    public function __construct(
        private readonly string $moduleLink,
        private readonly array $get,
        private readonly array $post,
        private readonly string $method
    ) {
    }

    /** Builds the whole admin page and returns its HTML. */
    public function render(): string
    {
        $action = (string) ($this->get['action'] ?? 'dashboard');
        $tab = $action === 'send' ? 'send' : (array_key_exists($action, self::TABS) ? $action : 'dashboard');
        $job = $tab === 'send' ? 'send' : self::TABS[$tab][1];

        if (!Permissions::can($job)) {
            $this->notice('danger', 'Your admin role is not allowed to use this part of the module.');
            return $this->layout($tab, '');
        }

        // Every POST must carry the CSRF token before anything is changed.
        if ($this->method === 'POST') {
            if (!Csrf::check($this->post, $this->method)) {
                $this->notice('danger', 'The form has expired or was not sent from this page. Nothing was changed. Please try again.');
            } else {
                $this->handlePost($tab);
            }
        }

        $body = match ($tab) {
            'dashboard'     => $this->dashboard(),
            'notifications' => $this->notifications(),
            'messages'      => $this->messages(),
            'marketing'     => $this->marketing(),
            'log'           => $this->log(),
            'settings'      => $this->settings(),
            'send'          => $this->sendPage(),
        };
        return $this->layout($tab, $body);
    }

    /** Sends a form post to the right handler for the tab. */
    private function handlePost(string $tab): void
    {
        $do = (string) ($this->post['do'] ?? '');
        try {
            match ($tab . ':' . $do) {
                'settings:save'             => $this->saveSettings(),
                'notifications:categories'  => $this->saveCategories(),
                'notifications:save'        => $this->saveMessage('event'),
                'notifications:test',
                'messages:test'             => $this->testSend(),
                'messages:save'             => $this->saveMessage(null),
                'messages:delete'           => $this->deleteMessage(),
                'marketing:start'           => $this->startCampaign(),
                'marketing:pause',
                'marketing:resume',
                'marketing:cancel'          => $this->campaignAction($do),
                'log:export'                => null,   // handled in log() so the file can be offered
                'log:erase'                 => $this->eraseClient(),
                'send:preview'              => null,   // handled in sendPage()
                'send:send'                 => null,   // handled in sendPage()
                default                     => $this->notice('danger', 'Unknown action.'),
            };
        } catch (\Throwable $e) {
            // Never show internal details; just say it failed.
            $this->notice('danger', 'Something went wrong; nothing was changed.');
        }
    }

    // ---------------------------------------------------------------- Dashboard

    /** Status overview: configuration, queue and the last 24 hours. */
    private function dashboard(): string
    {
        $since = Clock::toDb(Clock::now()->modify('-24 hours'));
        $last24 = [];
        foreach (Capsule::table(MessageLog::TABLE)->where('created_at', '>=', $since)
            ->select('status', Capsule::raw('COUNT(*) as total'))->groupBy('status')->get() as $row) {
            $last24[(string) $row->status] = (int) $row->total;
        }
        return View::render('dashboard', [
            'configured'    => Settings::isConfigured(),
            'hasAppSecret'  => Settings::hasSecret('app_secret'),
            'hasVerify'     => Settings::hasSecret('verify_token'),
            'fieldId'       => Consent::fieldId(),
            'webhookUrl'    => $this->webhookUrl(),
            'queue'         => Queue::counts(),
            'last24'        => $last24,
            'lastRun'       => Schema::getMeta('last_worker_run'),
        ]);
    }

    // ---------------------------------------------------------------- Notifications

    /** Category switches and the event checklist. */
    private function notifications(): string
    {
        $categories = [];
        foreach (Catalogue::CATEGORIES as $key => $label) {
            $categories[$key] = ['label' => $label, 'on' => Settings::bool('cat_' . $key)];
        }
        $events = [];
        foreach (Messages::allOfKind('event') as $message) {
            $scope = Catalogue::event($message->msg_key)['scope'] ?? null;
            $events[] = ['message' => $message, 'allowed' => Placeholders::allowedFor('event', $scope),
                         'critical' => Catalogue::isCritical($message->msg_key)];
        }
        return View::render('notifications', [
            'link' => $this->moduleLink, 'csrf' => Csrf::fields(), 'categories' => $categories, 'events' => $events,
            'catalogue' => Catalogue::CATEGORIES, 'placeholders' => Placeholders::ALLOWED, 'language' => Settings::get('language'),
        ]);
    }

    /** Saves the four category switches. */
    private function saveCategories(): void
    {
        $chosen = is_array($this->post['categories'] ?? null) ? $this->post['categories'] : [];
        foreach (array_keys(Catalogue::CATEGORIES) as $key) {
            Settings::set('cat_' . $key, in_array($key, $chosen, true) ? '1' : '0');
        }
        $this->notice('success', 'Category switches saved.');
    }

    // ---------------------------------------------------------------- Messages

    /** List and edit forms for manual and marketing messages. */
    private function messages(): string
    {
        return View::render('messages', [
            'link' => $this->moduleLink, 'csrf' => Csrf::fields(),
            'manual' => Messages::allOfKind('manual'), 'marketing' => Messages::allOfKind('marketing'),
            'allowedManual' => Placeholders::allowedFor('manual', null),
            'allowedMarketing' => Placeholders::allowedFor('marketing', null),
            'placeholders' => Placeholders::ALLOWED, 'language' => Settings::get('language'),
        ]);
    }

    /**
     * Saves an event, manual or marketing message (or adds a new one).
     * $onlyKind = 'event' when posted from the Notifications tab.
     */
    private function saveMessage(?string $onlyKind): void
    {
        $id = (int) ($this->post['id'] ?? 0);
        $input = [
            'label'         => trim((string) ($this->post['label'] ?? '')),
            'template_name' => trim((string) ($this->post['template_name'] ?? '')),
            'language'      => trim((string) ($this->post['language'] ?? '')),
            'params'        => (string) ($this->post['params'] ?? ''),
        ];
        $enabled = ($this->post['enabled'] ?? '') === '1';

        if ($id === 0) {
            // New message: only manual or marketing can be added.
            $kind = (string) ($this->post['kind'] ?? '');
            if ($onlyKind !== null || !in_array($kind, ['manual', 'marketing'], true)) {
                $this->notice('danger', 'Choose manual or marketing.');
                return;
            }
            $errors = Messages::validate($input, $kind, null);
            if ($errors !== []) {
                $this->notices(...$errors);
                return;
            }
            Messages::create($kind, $input, $enabled);
            $this->notice('success', 'Message added.');
            return;
        }

        $message = Messages::findById($id);
        if ($message === null || ($onlyKind !== null && $message->kind !== $onlyKind) || ($onlyKind === null && $message->kind === 'event')) {
            $this->notice('danger', 'Message not found.');
            return;
        }
        if ($message->builtin) {
            $input['label'] = $message->label;   // built-in names stay fixed
        }
        $scope = $message->kind === 'event' ? (Catalogue::event($message->msg_key)['scope'] ?? null) : null;
        $errors = Messages::validate($input, $message->kind, $scope);
        if ($errors !== []) {
            $this->notices(...$errors);
            return;
        }
        Messages::update($id, $input, $enabled);
        $this->notice('success', 'Saved: ' . $input['label']);
    }

    /** Deletes a manual or marketing message the admin added. */
    private function deleteMessage(): void
    {
        $deleted = Messages::delete((int) ($this->post['id'] ?? 0));
        $this->notice($deleted ? 'success' : 'danger', $deleted ? 'Message deleted.' : 'Built-in messages can only be switched off.');
    }

    /** "Send test" button: sends sample values to a number the admin types. */
    private function testSend(): void
    {
        if (!RateLimiter::allow('test|' . Permissions::currentAdminId(), self::SEND_LIMIT_PER_MINUTE)) {
            $this->notice('danger', 'Too many test sends. Please wait a minute.');
            return;
        }
        $message = Messages::findById((int) ($this->post['id'] ?? 0));
        if ($message === null) {
            $this->notice('danger', 'Message not found.');
            return;
        }
        if (!Settings::isConfigured()) {
            $this->notice('danger', 'Save the Meta Phone Number ID and access token in Settings first.');
            return;
        }
        $result = QueueWorker::sendTest($message, (string) ($this->post['test_number'] ?? ''));
        if ($result->ok) {
            $this->notice('success', 'Test sent to Meta. Check the phone, and the Log tab for delivery.');
        } else {
            $this->notice('danger', 'Test failed (' . ($result->errorCode ?? 'error') . '): ' . Text::scrub((string) $result->errorText));
        }
    }

    // ---------------------------------------------------------------- Marketing

    /** Bulk marketing: recipients count, start form and campaign list. */
    private function marketing(): string
    {
        return View::render('marketing', [
            'link' => $this->moduleLink, 'csrf' => Csrf::fields(), 'csrfOnce' => Csrf::fields(true),
            'messages' => Messages::allOfKind('marketing', true), 'recipients' => count(Campaigns::recipientIds()),
            'campaigns' => Campaigns::latest(), 'dailyCap' => Settings::int('daily_cap', 1, 1000000),
            'labels' => $this->messageLabels(),
        ]);
    }

    /** Starts a campaign (one-time code stops a double start on refresh). */
    private function startCampaign(): void
    {
        if (!Csrf::consumeOnce($this->post)) {
            $this->notice('danger', 'This form was already used. Nothing was started twice.');
            return;
        }
        if (!Settings::isConfigured()) {
            $this->notice('danger', 'Save the Meta settings first.');
            return;
        }
        try {
            $id = Campaigns::start((string) ($this->post['msg_key'] ?? ''), Permissions::currentAdminId());
            $this->notice('success', 'Campaign #' . $id . ' started. Messages are sent by the WHMCS cron in batches.');
        } catch (\InvalidArgumentException $e) {
            $this->notice('danger', $e->getMessage());
        }
    }

    /** Pause, resume or cancel a campaign. */
    private function campaignAction(string $do): void
    {
        $id = (int) ($this->post['id'] ?? 0);
        match ($do) {
            'pause'  => Campaigns::pause($id),
            'resume' => Campaigns::resume($id),
            'cancel' => Campaigns::cancel($id),
        };
        $this->notice('success', 'Campaign #' . $id . ': ' . $do . ' done.');
    }

    // ---------------------------------------------------------------- Log & GDPR

    /** The log table with filters, plus the GDPR export and erase tools. */
    private function log(): string
    {
        $filters = [
            'client_id' => ctype_digit((string) ($this->get['client_id'] ?? '')) ? (int) $this->get['client_id'] : 0,
            'status'    => in_array($this->get['status'] ?? '', MessageLog::STATUSES, true) ? (string) $this->get['status'] : '',
            'from'      => self::validDate((string) ($this->get['from'] ?? '')),
            'to'        => self::validDate((string) ($this->get['to'] ?? '')),
        ];
        $page = max(1, min(100000, (int) ($this->get['page'] ?? 1)));
        $result = MessageLog::search($filters, $page);

        // GDPR export: offered as a download link built in the page (no extra public file).
        $export = null;
        if ($this->method === 'POST' && ($this->post['do'] ?? '') === 'export' && Csrf::check($this->post, $this->method)) {
            $clientId = (int) ($this->post['gdpr_client_id'] ?? 0);
            if ($clientId > 0) {
                $export = ['client_id' => $clientId,
                           'uri' => 'data:text/csv;charset=utf-8;base64,' . base64_encode("\xEF\xBB\xBF" . MessageLog::exportClientCsv($clientId))];
            }
        }
        return View::render('log', [
            'link' => $this->moduleLink, 'csrf' => Csrf::fields(), 'filters' => $filters, 'page' => $page,
            'total' => $result['total'], 'rows' => $result['rows'], 'export' => $export, 'statuses' => MessageLog::STATUSES,
            'labels' => $this->messageLabels(),
        ]);
    }

    /** Deletes everything this module stores about a client (erasure request). */
    private function eraseClient(): void
    {
        $clientId = (int) ($this->post['gdpr_client_id'] ?? 0);
        if ($clientId <= 0 || ($this->post['confirm'] ?? '') !== 'ERASE') {
            $this->notice('danger', 'Type ERASE to confirm.');
            return;
        }
        MessageLog::eraseClient($clientId);
        // Recorded in the WHMCS activity log without any personal data.
        if (function_exists('logActivity')) {
            logActivity('Binary Racks WhatsApp: erased WhatsApp data for client ID ' . $clientId);
        }
        $this->notice('success', 'All WhatsApp data stored by this module for client #' . $clientId . ' was deleted.');
    }

    // ---------------------------------------------------------------- Settings

    /** The settings form. Secrets are never put back into the page. */
    private function settings(): string
    {
        $values = [];
        foreach (array_keys(Settings::DEFAULTS) as $key) {
            $values[$key] = Settings::get($key);
        }
        return View::render('settings', [
            'link' => $this->moduleLink, 'csrf' => Csrf::fields(), 'values' => $values,
            'secrets' => ['access_token' => Settings::hasSecret('access_token'), 'app_secret' => Settings::hasSecret('app_secret'),
                          'verify_token' => Settings::hasSecret('verify_token')],
            'roles' => Permissions::allRoles(), 'webhookUrl' => $this->webhookUrl(),
            'chosen' => ['send' => Settings::roleIds('roles_send'), 'bulk' => Settings::roleIds('roles_bulk'), 'settings' => Settings::roleIds('roles_settings')],
        ]);
    }

    /** Checks and saves the settings form. Nothing is saved if any value is wrong. */
    private function saveSettings(): void
    {
        $p = $this->post;
        $errors = [];
        $phoneId = trim((string) ($p['phone_number_id'] ?? ''));
        $wabaId = trim((string) ($p['waba_id'] ?? ''));
        foreach (['Phone Number ID' => $phoneId, 'WhatsApp Business Account ID' => $wabaId] as $label => $value) {
            if ($value !== '' && preg_match('/^\d{5,20}$/', $value) !== 1) {
                $errors[] = $label . ' must be 5 to 20 digits.';
            }
        }
        $apiVersion = trim((string) ($p['api_version'] ?? ''));
        if (preg_match('/^v\d{1,2}\.\d$/', $apiVersion) !== 1) {
            $errors[] = 'Graph API version must look like v21.0.';
        }
        $language = trim((string) ($p['language'] ?? ''));
        if (preg_match(Messages::LANGUAGE_PATTERN, $language) !== 1) {
            $errors[] = 'Language must look like "en" or "en_GB".';
        }
        $numbers = ['batch_size' => [1, 500], 'daily_cap' => [1, 1000000], 'log_days' => [1, 3650], 'domain_expiry_days' => [1, 90]];
        foreach ($numbers as $key => [$min, $max]) {
            $value = trim((string) ($p[$key] ?? ''));
            if (!ctype_digit($value) || (int) $value < $min || (int) $value > $max) {
                $errors[] = ucfirst(str_replace('_', ' ', $key)) . " must be a whole number from $min to $max.";
            }
        }

        // Secrets: an empty box keeps the saved value. Strict formats stop pasted junk.
        $token = trim((string) ($p['access_token'] ?? ''));
        $appSecret = trim((string) ($p['app_secret'] ?? ''));
        $verify = trim((string) ($p['verify_token'] ?? ''));
        if ($token !== '' && preg_match('/^[A-Za-z0-9_\-.|]{20,1024}$/', $token) !== 1) {
            $errors[] = 'The access token does not look right.';
        }
        if ($appSecret !== '' && preg_match('/^[A-Za-z0-9]{16,128}$/', $appSecret) !== 1) {
            $errors[] = 'The app secret does not look right.';
        }
        if ($verify !== '' && preg_match('/^[\x21-\x7E]{16,128}$/', $verify) !== 1) {
            $errors[] = 'The verify token must be 16 to 128 characters with no spaces.';
        }

        // Your rule: if the Phone Number ID or Business Account ID changes, the access token must be typed again.
        $idsChanged = $phoneId !== Settings::get('phone_number_id') || $wabaId !== Settings::get('waba_id');
        if ($idsChanged && Settings::hasSecret('access_token') && $token === '') {
            $errors[] = 'You changed the Phone Number ID or Business Account ID, so please type the access token again.';
        }

        // Roles: keep only IDs of roles that really exist.
        $existingRoles = array_keys(Permissions::allRoles());
        $roles = [];
        foreach (Permissions::JOBS as $job) {
            $picked = is_array($p['roles_' . $job] ?? null) ? $p['roles_' . $job] : [];
            $roles[$job] = array_values(array_intersect(array_map('intval', array_filter($picked, 'is_scalar')), $existingRoles));
        }

        if ($errors !== []) {
            $this->notices(...$errors);
            return;
        }

        foreach (['phone_number_id' => $phoneId, 'waba_id' => $wabaId, 'api_version' => $apiVersion, 'language' => $language] as $key => $value) {
            Settings::set($key, $value);
        }
        foreach (array_keys($numbers) as $key) {
            Settings::set($key, (string) (int) $p[$key]);
        }
        foreach ($roles as $job => $ids) {
            Settings::set('roles_' . $job, implode(',', $ids));
        }
        Settings::set('delete_on_deactivate', ($p['delete_on_deactivate'] ?? '') === '1' ? '1' : '0');
        if ($idsChanged && $token === '') {
            Settings::setSecret('access_token', '');   // never keep a token for a different number
        }
        foreach (['access_token' => $token, 'app_secret' => $appSecret, 'verify_token' => $verify] as $key => $value) {
            if ($value !== '') {
                Settings::setSecret($key, $value);
            }
            if (($p['clear_' . $key] ?? '') === '1') {
                Settings::setSecret($key, '');
            }
        }
        $this->notice('success', 'Settings saved.');
    }

    // ---------------------------------------------------------------- Manual send (from the client profile)

    /** The "Send WhatsApp" page for one client, opened from the client summary page. */
    private function sendPage(): string
    {
        $clientId = ctype_digit((string) ($this->get['userid'] ?? '')) ? (int) $this->get['userid'] : 0;
        $client = $clientId > 0 ? Capsule::table('tblclients')->where('id', $clientId)->first(['id', 'firstname', 'lastname', 'companyname']) : null;
        if ($client === null) {
            $this->notice('danger', 'Client not found.');
            return '';
        }

        $messages = Messages::allOfKind('manual', true);
        $invoices = [];
        foreach (Capsule::table('tblinvoices')->where('userid', $clientId)->whereIn('status', ['Unpaid', 'Overdue'])
            ->orderBy('duedate')->limit(100)->get(['id', 'invoicenum', 'duedate', 'total']) as $row) {
            $invoices[(int) $row->id] = $row;
        }

        $chosenKey = (string) ($this->post['msg_key'] ?? '');
        $chosenInvoice = (int) ($this->post['invoice_id'] ?? 0);
        $preview = null;
        $do = $this->method === 'POST' && Csrf::check($this->post, $this->method) ? (string) ($this->post['do'] ?? '') : '';

        if ($do === 'preview' || $do === 'send') {
            $message = null;
            foreach ($messages as $candidate) {
                if ($candidate->msg_key === $chosenKey) {
                    $message = $candidate;
                }
            }
            $needsInvoice = $message !== null && Placeholders::needsInvoice($message->paramList);
            $blocked = Consent::blockReason($clientId, false);
            if ($message === null) {
                $this->notice('danger', 'Choose a message.');
            } elseif ($needsInvoice && !isset($invoices[$chosenInvoice])) {
                $this->notice('danger', 'Choose one of this client\'s unpaid invoices.');
            } elseif ($blocked !== null) {
                $this->notice('danger', 'This client cannot receive WhatsApp messages (' . $blocked . ').');
            } elseif ($do === 'preview') {
                try {
                    $values = Placeholders::resolve($message->paramList, $clientId, $needsInvoice ? 'invoice' : null, $needsInvoice ? $chosenInvoice : null);
                    $preview = ['message' => $message, 'values' => $values, 'language' => Messages::languageFor($message), 'invoice' => $needsInvoice ? $chosenInvoice : 0];
                } catch (RecordNotFound $e) {
                    $this->notice('danger', 'The invoice could not be found for this client.');
                }
            } else {
                $this->sendManual($clientId, $message, $needsInvoice ? $chosenInvoice : null);
            }
        }

        $details = localAPI('GetClientsDetails', ['clientid' => $clientId, 'stats' => false]);
        $info = is_array($details['client'] ?? null) ? $details['client'] : $details;
        $phone = PhoneNumber::normalise((string) ($info['phonenumber'] ?? ''), (string) ($info['phonecc'] ?? ''));

        return View::render('send', [
            'link' => $this->moduleLink, 'csrf' => Csrf::fields(), 'csrfOnce' => Csrf::fields(true), 'client' => $client,
            'messages' => $messages, 'invoices' => $invoices, 'preview' => $preview, 'chosenKey' => $chosenKey,
            'chosenInvoice' => $chosenInvoice, 'phoneMasked' => $phone === null ? null : PhoneNumber::mask($phone),
            'blocked' => Consent::blockReason($clientId, false),
        ]);
    }

    /** Queues a manual message and tries to send it now (the daytime rule may hold it until morning). */
    private function sendManual(int $clientId, object $message, ?int $invoiceId): void
    {
        if (!Csrf::consumeOnce($this->post)) {
            $this->notice('danger', 'This form was already used, so the message was not sent again.');
            return;
        }
        $adminId = Permissions::currentAdminId();
        if (!RateLimiter::allow('send|' . $adminId, self::SEND_LIMIT_PER_MINUTE)) {
            $this->notice('danger', 'Too many messages in one minute. Please wait.');
            return;
        }
        if (!Settings::isConfigured()) {
            $this->notice('danger', 'Save the Meta settings first.');
            return;
        }
        $id = Queue::add($clientId, $message->msg_key, 'manual', $invoiceId, null, false, null, $adminId);
        if ($id === null) {
            $this->notice('danger', 'The message could not be queued.');
            return;
        }
        QueueWorker::processOne($id);
        $row = Capsule::table(Queue::TABLE)->where('id', $id)->first(['status', 'send_after']);
        match ((string) ($row->status ?? '')) {
            'sent'    => $this->notice('success', 'Message sent to Meta. Delivery shows in the Log tab.'),
            'pending' => $this->notice('info', 'It is outside 08:00-18:00 for this client, so the message will be sent at ' . $row->send_after . ' UTC.'),
            default   => $this->notice('danger', 'The message was not sent (' . (string) Capsule::table(MessageLog::TABLE)
                ->where('client_id', $clientId)->orderBy('id', 'desc')->value('error_code') . '). See the Log tab.'),
        };
    }

    // ---------------------------------------------------------------- Helpers

    /** Wraps a tab's HTML in the header bar, the tab menu and the notices. */
    private function layout(string $current, string $body): string
    {
        $tabs = [];
        foreach (self::TABS as $key => [$label, $job]) {
            if (Permissions::can($job)) {
                $tabs[$key] = $label;
            }
        }
        return View::render('layout', ['link' => $this->moduleLink, 'tabs' => $tabs, 'current' => $current,
            'notices' => $this->notices, 'body' => $body, 'header' => Branding::header()]);
    }

    /** Returns the public webhook address to paste into Meta. */
    private function webhookUrl(): string
    {
        $base = (string) Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value');
        return rtrim($base, '/') . '/modules/addons/br_whatsapp/webhook.php';
    }

    /** Returns [msg_key => label] for showing names in tables. */
    private function messageLabels(): array
    {
        $labels = ['stop' => 'STOP reply'];
        foreach (Capsule::table(Messages::TABLE)->get(['msg_key', 'label']) as $row) {
            $labels[(string) $row->msg_key] = (string) $row->label;
        }
        return $labels;
    }

    /** Returns the date if it is a real Y-m-d date, otherwise an empty string. */
    private static function validDate(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }

    /** Adds a notice shown at the top of the page. */
    private function notice(string $type, string $text): void
    {
        $this->notices[] = [$type, $text];
    }

    /** Adds several error notices. */
    private function notices(string ...$texts): void
    {
        foreach ($texts as $text) {
            $this->notice('danger', $text);
        }
    }
}
