# PLAN: Binary Racks WhatsApp Notifications (WHMCS addon module)

Status: **gathering requirements** (no code yet, waiting for approval of the plan)

## What you asked for

1. Send WHMCS notifications to the client's registered mobile number on WhatsApp.
2. Admin config page with a **checklist of notifications** that are pre-selected (turned on/off by the admin).
3. A client custom field called **`disable_whatsapp`** so a customer can turn WhatsApp messages off themselves.
4. Admin can send messages manually, for example:
   - ID verification reminder
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
| 9 | Which "marketing enabled" setting decides who gets bulk marketing? | **WHMCS's built-in email marketing opt-in** (the client's "marketing emails" setting). A client gets bulk marketing only if that is on **and** `disable_whatsapp` is not ticked. *Risk you accepted:* that consent was given for email, not WhatsApp, so Meta may treat it as weaker consent. Incoming "STOP" replies (through the webhook) will untick it automatically. |
| 10 | Where does the admin send manual messages from? | **Client profile page only.** A "Send WhatsApp" button on the admin client summary page, added with an official WHMCS admin hook (no core edits). It opens a small form: choose a pre-configured message, see a preview, and send. The form uses POST with a CSRF token. |
| 11 | Which admins may send messages, run bulk marketing and change settings? | **Roles are chosen in the module settings, one list per job:** (a) send to one client, (b) send bulk marketing, (c) change settings, tokens and templates. Defaults: (a) all roles with module access, (b) and (c) Full Administrator only. Full Administrator can always reach the settings, so nobody gets locked out. Permissions are checked on the server for every action, not just by hiding buttons. |
| 12 | How are bulk marketing messages sent? | **Queued, and sent by WHMCS's own cron** in small batches. The admin sets a batch size and a daily cap to stay under Meta's limits. A campaign can be previewed (number of recipients), paused, resumed or cancelled. Consent (`disable_whatsapp` and the marketing opt-in) is checked again just before each message is sent. No new cron job is needed; it uses the WHMCS `AfterCronJob` hook. |
| 13 | How does the module know a client still needs ID verification? | _waiting for answer_ |

## Waiting for

- Your security standards `.md` files.
