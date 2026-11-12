# PLAN: Binary Racks WhatsApp Notifications (WHMCS addon module)

Status: **plan ready, waiting for your YES** (no code yet). Your security `.md` files will be merged in before building.

## What you asked for

1. Send WHMCS notifications to the client's registered mobile number on WhatsApp.
2. Admin config page with a **checklist of notifications** that are pre-selected (turned on/off by the admin).
3. A client custom field called **`disable_whatsapp`** so a customer can turn WhatsApp messages off themselves.
4. Admin can send messages manually, for example:
   - ~~ID verification reminder~~ (removed, decision 23)
   - Due invoice reminder
5. Automatic messages when a service is **suspended (with the reason)** and **unsuspended**.
6. Room to **add more pre-configured messages** later, when the business wants to automate other communications.
7. **Bulk marketing messages**, sent only to clients who have **marketing enabled** on their account.
8. Questions asked one at a time, with suggestions; your own security `.md` files will be uploaded and applied.

## Decisions (one per question)

| # | Question | Decision |
|---|----------|----------|
| 1 | Admin only, or Client and internet access required? | **Client and internet, very limited.** One public entry point only: a webhook that receives messages and receipts from WhatsApp's servers. It has signature checks, rate limits and strict input checks, and everything else in the module folder is denied. |
| 2 | Which WhatsApp provider? | **Meta WhatsApp Cloud API (official).** Sending goes through a separate provider file, so another provider can be added later. Webhooks are checked against Meta's signature (`X-Hub-Signature-256`, signed with the app secret). |
| 3 | Do you already have a Meta Business account and a WhatsApp Business number? | **Yes, fully set up.** The admin settings will ask for the Phone Number ID, WhatsApp Business Account ID, access token, app secret and webhook verify token. All secrets are stored with WHMCS `encrypt()` and never shown again. |
| 4 | Which phone number do messages go to? | **The client's main WHMCS phone number**, with its country code. It's converted to international format (E.164). Numbers that are invalid or missing are skipped, and the skip is logged. |
| 5 | How are notifications linked to Meta's approved message templates? | **Admin types the template name and language**, then picks the WHMCS data that fills each blank (`{{1}}`, `{{2}}` ...) from a fixed, allowed list. The name is checked for format (lowercase letters, numbers, `_`, at most 512 characters). A **"Send test"** button is suggested, so a typo shows up straight away. |
| 6 | What does `disable_whatsapp` switch off? | **Everything.** A ticked box blocks every WhatsApp message to that client (automatic, manual, bulk and marketing). WHMCS emails are not affected. It's checked again just before each message is sent. |
| 7 | Who creates the `disable_whatsapp` custom field? | **The module creates it on activation** if it doesn't exist: a tickbox that clients can edit, shown on their profile. An existing field with the same name is reused. It's never deleted on deactivate (client choices are kept). |
| 8 | Which automatic events go on the admin checklist? | **All groups:** Invoices (created, reminder due or overdue, payment received), Services (activated or welcome, suspended with reason, unsuspended, terminated), Tickets (staff reply), Domains (registered, renewed, expiring soon). Admin can **switch a whole category on or off** with one switch, and can also turn single events on or off inside a category. A message is sent only if both its category and its event are on. |
| 9 | Which "marketing enabled" setting decides who gets bulk marketing? | **WHMCS's built-in email marketing opt-in** (the client's "marketing emails" setting). A client gets bulk marketing only if that is on **and** `disable_whatsapp` is not ticked. *Risk you accepted:* that consent was given for email, not WhatsApp, so Meta may treat it as weaker consent. The WHMCS opt-in is **never changed** by this module. |
| 10 | Where does the admin send manual messages from? | **Client profile page only.** A "Send WhatsApp" button on the admin client summary page, added with an official WHMCS admin hook (no core edits). It opens a small form: choose a pre-configured message, see a preview, and send. The form uses POST with a CSRF token. |
| 11 | Which admins may send messages, run bulk marketing and change settings? | **Roles are chosen in the module settings, one list per job:** (a) send to one client, (b) send bulk marketing, (c) change settings, tokens and templates. Defaults: (a) all roles with module access, (b) and (c) Full Administrator only. Full Administrator can always reach the settings, so nobody gets locked out. Permissions are checked on the server for every action, not just by hiding buttons. |
| 12 | How are bulk marketing messages sent? | **Queued, and sent by WHMCS's own cron** in small batches. The admin sets a batch size and a daily cap to stay under Meta's limits. A campaign can be previewed (number of recipients), paused, resumed or cancelled. Consent (`disable_whatsapp` and the marketing opt-in) is checked again just before each message is sent. No new cron job is needed; it uses the WHMCS `AfterCronJob` hook. |
| 13 | How does the module know a client still needs ID verification? | **Not needed:** replaced by decision 23 (the ID reminder is removed). |
| 14 | How long are sent-message logs kept? | **90 days** by default, changeable in settings. Old entries are deleted automatically by the daily cron. The log never stores tokens or secrets. |
| 15 | What happens when a client replies on WhatsApp? | **Ignored.** The webhook only processes delivery receipts (sent, delivered, read, failed) and "STOP"-type opt-outs. Other replies are thrown away and their text is not stored. Suggestion: the templates should say "replies are not monitored, please open a ticket". |
| 16 | How flexible are new pre-configured messages? | **Manual only.** The admin can add new messages (name, Meta template name, language and blank mapping from the allowed list) in a "Manual messages" tab. They can only be sent by hand from the client profile. New automatic triggers need a module update. |
| 17 | Which language are messages sent in? | **One language for all messages**, set once in the settings (e.g. `en` or `en_GB`). The format is checked: 2 letters, optionally followed by `_` and 2 more. Each message can override it if needed. |
| 18 | Should messages wait during quiet hours (e.g. at night)? | **Daytime only: 08:00 to 18:00 in the client's own time zone.** Messages triggered outside those hours are queued and sent by the cron when the client's daytime starts. **Critical messages go immediately at any hour:** service suspension and server down notices. |
| 19 | How is the client's time zone worked out? | **From the client's country, plus state** for countries with several time zones (e.g. US, CA, AU, BR, MX, RU). Uses PHP's built-in time zone list. If unsure, falls back to the WHMCS system time zone. |
| 20 | Where do "server down" notifications come from? | **Dropped, not built.** So the only critical message that skips the daytime rule is **service suspension**. |
| 21 | Should the webhook also accept only Meta's IP addresses? | **Signature only**, as Meta recommends. No IP list to maintain. Rate limits and size limits still apply. |
| 22 | What does a "STOP" reply do? | **Stops WhatsApp only:** it ticks the client's `disable_whatsapp` field, so no more WhatsApp messages are sent. The WHMCS marketing opt-in is never touched. |
| 23 | Is an ID verification reminder needed? | **No. Removed from the module completely.** |
| 24 | Show a hint when a customer stopped WhatsApp by replying STOP? | **Yes, in the module's message log only.** A log entry: "disable_whatsapp ticked: Disabled by customer request via WhatsApp message", with the date and time. Nothing is added to the client profile or client notes. Searchable in the Log tab. |
| 25 | Can other modules send WhatsApp messages through this one? | **Yes, wanted.** _Method being proposed; waiting for approval._ |

## The build plan (version 1.0.0)

### Name and folder
- Name shown in WHMCS: **Binary Racks WhatsApp Notifications**, author **Binary Racks**, version **1.0.0**
- Folder: `modules/addons/br_whatsapp/`
- Targets PHP 8.3+ and WHMCS 8.x. Uses only documented hooks, `localAPI`, Capsule, `encrypt()` and `decrypt()`.

### Files (one job per file)
```
modules/addons/br_whatsapp/
  .htaccess                 Denies all web access EXCEPT webhook.php
  br_whatsapp.php           WHMCS entry: config, activate, deactivate, upgrade, admin page router
  hooks.php                 Connects WHMCS events to the module (no logic, just hand-off)
  webhook.php               THE ONLY PUBLIC FILE: receives Meta delivery receipts and STOP
  lib/
    Branding.php            Header bar: embedded Binary Racks logo (data URI), name and version
    Schema.php              Creates and upgrades the module's tables automatically
    Settings.php            Reads and saves settings; secrets go through encrypt()
    Permissions.php         Which admin roles may: send one, send bulk, change settings
    Csrf.php                Makes and checks form tokens (every POST)
    PhoneNumber.php         Turns a WHMCS phone number into international format, or rejects it
    Consent.php             disable_whatsapp and marketing opt-in checks (asked again at send time)
    TimeZoneResolver.php    Country plus state, giving a time zone (fallback: WHMCS time zone)
    DaytimeWindow.php       08:00-18:00 rule; service suspension is exempt
    Catalogue.php           The built-in events and categories (Invoices, Services, Tickets, Domains)
    Placeholders.php        Fixed allowed list of WHMCS data that can fill template blanks
    MessageBuilder.php      Template name, language and blank values, built into one message
    MetaClient.php          The provider: sends to graph.facebook.com only (fixed host, HTTPS, timeouts)
    Queue.php               Waiting messages (daytime rule, bulk batches, retries)
    QueueWorker.php         Run by the WHMCS cron: sends due messages within batch and daily caps
    Campaigns.php           Bulk marketing: preview count, start, pause, resume, cancel
    MessageLog.php          Log of sent, delivered, read and failed messages; 90-day clean-up
    RateLimiter.php         Limits for the webhook and for admin send buttons
    WebhookHandler.php      Signature check, then receipts and STOP handling
    EventHandler.php        Turns a WHMCS event into a queued message (with a duplicate guard)
    views/                  Admin page tabs (all output escaped)
docs/
  README.md                 Install, Meta setup, nginx rules, upgrade steps, testing notes
tests/                      Automated tests (not uploaded to the server)
```
Every PHP file except `webhook.php` starts with `if (!defined('WHMCS')) { exit; }`, so opening it directly shows nothing.
This holds even on "nginx only", where `.htaccess` is ignored. `webhook.php` is the one public entry point: it starts WHMCS itself and then does the signature check before anything else.

### How it works
1. **Automatic events.** `hooks.php` listens to official WHMCS hooks: InvoiceCreated, InvoicePaymentReminder, InvoicePaid,
   AfterModuleCreate, AfterModuleSuspend (with reason), AfterModuleUnsuspend, AfterModuleTerminate, TicketAdminReply,
   AfterRegistrarRegistration, AfterRegistrarRenewal, and DailyCronJob (domains expiring soon).
   Each event is checked in this order: category on, then event on, then client consent, then a valid phone number. It is then queued.
   A duplicate guard (event + record ID) stops the same notice being sent twice.
2. **Daytime rule.** A queued message is sent only between 08:00 and 18:00 in the client's local time.
   Service suspension skips the rule and goes immediately.
3. **Sending.** The WHMCS cron (`AfterCronJob` hook) runs `QueueWorker`. It sends to Meta's Graph API,
   saves Meta's message ID and logs the result. Temporary failures are retried a few times with increasing waits.
4. **Manual send.** A "Send WhatsApp" link on the admin client summary page (`AdminAreaClientSummaryActionLinks` hook)
   opens the module's send form for that client. You pick a manual message (e.g. invoice reminder),
   see a preview, then send (POST + CSRF + role check).
5. **Bulk marketing.** Pick a marketing message, see "X clients will receive this", then start.
   Recipients are clients with the WHMCS marketing opt-in on and `disable_whatsapp` not ticked. Messages are queued and sent in batches, under the daily cap and the daytime rule.
6. **Webhook.** Meta posts delivery receipts, which update the log. A "STOP"-type reply (STOP, UNSUBSCRIBE, Meta's opt-out button)
   **ticks that client's `disable_whatsapp` field** through `localAPI UpdateClient`, which stops all WhatsApp messages to them. The WHMCS marketing opt-in is never touched. Other replies are ignored and not stored.
7. **Settings tab.** Meta Phone Number ID, Business Account ID, access token, app secret, verify token (secrets are typed in, never shown again),
   language, batch size, daily cap, log retention, roles for each job, and "Delete data on deactivate" (off by default).
8. **Manual messages tab.** Add, edit, disable and delete manual messages (the due invoice reminder comes pre-made).
9. **Notifications tab.** Category switches, event checklist, template name, language and blank mapping for each event, and a "Send test" button that sends to a number you type.
10. **Log tab.** Search by client, status and date.

### Database tables (created and upgraded automatically)
`mod_brwa_settings`, `mod_brwa_messages` (event and manual messages), `mod_brwa_queue`, `mod_brwa_log`,
`mod_brwa_campaigns`, `mod_brwa_ratelimit`, `mod_brwa_meta` (schema version).
Deactivating keeps all of these unless "Delete data on deactivate" is ticked. The `disable_whatsapp` field is never deleted.

### Security risks and how each is handled
| Risk | Protection |
|------|-----------|
| Fake webhook calls (someone pretending to be Meta) | HMAC-SHA256 signature check with the app secret, using a constant-time compare. Unsigned or wrongly signed requests get 403 and are not processed. |
| Flooding the webhook | Body size limit (64 KB), JSON depth limit, rate limit for each IP address, POST and GET only. |
| Webhook verify handshake abuse | `hub.verify_token` compared in constant time; `hub.challenge` must be digits only before it is echoed back. |
| Direct access to other module files | `.htaccess` deny-all; every PHP file in lib/ and views/ exits outside WHMCS; nginx rules in the README. |
| Stolen or leaked secrets | `encrypt()` at rest, never shown on screen, never logged. If the Phone Number ID or Business Account ID changes, the access token must be typed again. |
| SSRF (module tricked into calling other hosts) | The Meta host is fixed in code (`graph.facebook.com`) and can't be changed in settings. HTTPS with certificate checks, connect and total timeouts, no redirects. |
| XSS (script in names, reasons or template fields) | All output escaped. Template names and languages checked against strict patterns. |
| CSRF (forged admin forms) | Every change uses POST plus a token that is checked. |
| SQL injection | Capsule query builder with bound values only. |
| An admin doing more than allowed | Role check on the server for every action, not just hidden buttons. |
| Messaging a client who opted out | Consent is checked when queued AND again just before sending. |
| Wrong client or number | Phone numbers turned into international format and checked. STOP matches clients by their normalised number. |
| **Accepted risk:** marketing uses the email opt-in (decision 9), which was given for email rather than WhatsApp. | STOP ticks `disable_whatsapp` only; the email opt-in is never changed by the module. |

### Testing
- PHPUnit tests with a simulated WHMCS (an in-memory database plus stub `localAPI`, `encrypt` and hooks) and a **simulated Meta API**.
  No real messages are sent.
- Covered: every event, both category and event switches, consent, the daytime rule and time zones, the queue and caps, campaigns, the webhook
  (good and bad signatures, oversized, malformed, replayed), STOP, phone parsing, and permission and CSRF failures.
- Attack tests: XSS, CSRF, SQL injection, SSRF, secret leaks, direct file access (`../`, `//`, `%2e`, wrong case) on Apache and nginx-only test configs.
- Browser check with Playwright: admin tabs render with the logo, tables and no console errors.
- **Security and penetration test:** I'll ask you when the first working version is built, as your rules require.

### Delivery
One zip: `br_whatsapp-1.0.0.zip` containing `modules/` (to upload) and `docs/` (to keep). Plus the upgrade steps (none for 1.0.0, since it's a first install).

## Waiting for

- Your **YES** to this plan (or changes).
- Your security standards `.md` files, which I'll merge into this plan before building.
