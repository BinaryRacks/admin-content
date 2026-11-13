# Binary Racks WhatsApp Notifications for WHMCS: version 1.0.0

Sends WHMCS notifications, manual messages and bulk marketing to clients on WhatsApp,
through the official **Meta WhatsApp Cloud API**.

> This `docs/` folder is for your computer only. **Do not upload it to the server.**
> Upload only the `modules/` folder.

---

## 1. Requirements
- WHMCS 8.x on PHP 8.3 or newer, with the cURL extension
- A Meta Business account with a WhatsApp Business number on the Cloud API (you already have this)
- The WHMCS cron running normally (Plesk → Websites & Domains → Scheduled Tasks, using the site's Plesk PHP, e.g.
  `/opt/plesk/php/8.3/bin/php -q /path/to/whmcs/crons/cron.php`). The module uses this cron; **no new cron job is needed.**

## 2. Install (first time)
1. Unzip `br_whatsapp-1.0.0.zip` on your computer.
2. Upload the `modules/addons/br_whatsapp/` folder into your WHMCS folder, so you get
   `<whmcs>/modules/addons/br_whatsapp/br_whatsapp.php`.
3. In WHMCS: **System Settings → Addon Modules → Binary Racks WhatsApp Notifications → Activate**.
4. Click **Configure** and, under **Access Control**, tick the admin role groups that may open the module.
   (Inside the module you then choose which of those roles may send, run bulk marketing, or change settings.)
5. Open **Addons → Binary Racks WhatsApp Notifications → Settings** and fill in:
   - **Phone Number ID** and **WhatsApp Business Account ID** (Meta Business Manager → WhatsApp → API Setup)
   - **Access token**: a *permanent system-user token* with `whatsapp_business_messaging` permission
   - **App secret** (Meta App → Settings → Basic). The module uses it to check that webhook calls really come from Meta.
   - **Webhook verify token**: any random text of 16 or more characters that you choose
6. In Meta (App → WhatsApp → Configuration → Webhook):
   - **Callback URL**: the "Webhook address" shown on the module's Settings tab
     (`https://<your-whmcs>/modules/addons/br_whatsapp/webhook.php`)
   - **Verify token**: the same text you typed in step 5
   - Subscribe to the **messages** field.
7. Activation created the client custom field **`disable_whatsapp`** (a tick box clients can change on their profile).
   Check it under System Settings → Custom Client Fields.

## 3. Set up the messages
WhatsApp only allows messages you start yourself if they use a **template approved by Meta**.
1. In Meta (WhatsApp Manager → Message templates), create one template per message, e.g. `invoice_created`
   with body text such as *"Hi {{1}}, invoice {{2}} for {{3}} is due on {{4}}. Replies to this number are not monitored, please open a support ticket."*
2. In the module's **Notifications** tab, for each event: tick **On**, type the exact **template name**, and list the
   **blanks in order** (e.g. `client_firstname, invoice_id, invoice_total, invoice_duedate`).
3. Press **Send test** with your own mobile number. A typo in the template name shows up straight away.
4. Use the **category switches** (Invoices, Services, Tickets, Domains) to turn a whole group on or off.
5. **Manual & marketing messages** tab: the "Due invoice reminder" is ready-made. Add your own manual messages
   (sent from a client's profile) or marketing messages (used by Bulk marketing).

## 4. Everyday use
- **Automatic events**: sent between **08:00 and 18:00 in the client's time zone** (worked out from country and state).
  **Service suspension** is critical and goes at any hour.
- **Send to one client**: open the client's summary page and click **Send WhatsApp**, pick a message (and an unpaid
  invoice if the message needs one), press **Preview**, then **Send WhatsApp**.
- **Bulk marketing**: goes only to active clients with the **WHMCS marketing opt-in** on and `disable_whatsapp` not ticked.
  Messages go out with the cron in batches, under the daily cap. You can pause, resume or cancel.
- **STOP**: if a client replies STOP (or UNSUBSCRIBE, or Meta's "Stop promotions" button), the module ticks
  their `disable_whatsapp` box and logs *"Customer requested STOP via WhatsApp, disable_whatsapp ticked"*.
  The WHMCS email marketing opt-in is never changed. All other replies are ignored and not stored.

## 5. Web server rules (only webhook.php is public)
**Apache / LiteSpeed:** the included `.htaccess` already denies everything except `webhook.php`.

**nginx only** (`.htaccess` is ignored): add this in
Plesk → Websites & Domains → Apache & nginx Settings → **Additional nginx directives**:
```nginx
# Binary Racks WhatsApp: only webhook.php is reachable; everything else in the module folder gets 404.
# "^~" makes this rule win over Plesk's own PHP handler, whatever order Plesk puts the rules in.
location ^~ /modules/addons/br_whatsapp/ {
    location = /modules/addons/br_whatsapp/webhook.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        # Your site's PHP-FPM socket (Plesk's usual path; check it under the site's PHP settings).
        fastcgi_pass unix:/var/www/vhosts/system/<your-domain>/php-fpm.sock;
    }
    return 404;
}
```
After saving, check from your computer: `https://<your-whmcs>/modules/addons/br_whatsapp/lib/Settings.php`
must show **404**, and `.../webhook.php` must show **Forbidden** (403).

Even without these rules nothing leaks: every other PHP file in the module stops immediately with an empty page
when opened outside WHMCS, and the folder holds no images, scripts or styles.

## 6. GDPR: what is stored, and why
| Data | Where | Kept for |
|------|-------|----------|
| Client ID, **masked** number (`+44******3456`), which message, status, Meta message ID, error code | message log | 90 days (setting) |
| STOP record (proof the client opted out) | message log | until the client is deleted, or erased on request |
| Client ID + which message is waiting (no text) | queue | deleted 2 days after sending |
| One-way keyed hash of the last number used | contacts | until the client is deleted |
| Hashed caller IP (rate limit only) | rate limits | 1 hour |

- **No message text, names, suspension reasons or reply text is stored.**
- Error messages are cleaned of numbers, emails and tokens before saving.
- **Right of access**: Log & GDPR tab → "Export this client's WhatsApp data" (CSV).
- **Right to erasure**: deleting a client in WHMCS deletes their WhatsApp data automatically; or use
  "Delete this client's WhatsApp data". `disable_whatsapp` stays ticked, so they still get no messages.
- Lawful basis: **contract** for service notices (invoices, suspension, tickets, domains); **consent** for marketing.
- Suggested privacy-policy wording: *"We may send service notifications and, if you have opted in to marketing,
  offers by WhatsApp. Messages are delivered by Meta Platforms (WhatsApp). You can stop WhatsApp messages at any time
  by replying STOP or by ticking 'Disable WhatsApp messages' in your account details."*

## 7. Deactivate and remove
Deactivating **keeps** all settings, messages and logs, unless **"Delete data on deactivate"** is ticked on the
Settings tab. The `disable_whatsapp` client field is never deleted, so client choices are kept.

## 8. Upgrading (future versions)
1. Read that version's notes for **files to delete**.
2. Upload the new `modules/addons/br_whatsapp/` folder over the old one.
3. Open the module once in WHMCS; its database tables upgrade automatically.

**1.0.0 is the first release, so there are no old files to delete.**

## 9. Troubleshooting
| Symptom | Check |
|---------|-------|
| Nothing is sent | Dashboard tab: Meta details saved? "Last time the cron sent the queue" recent? Event and category both On? |
| "skipped: no_valid_phone" in the log | The client's phone number needs a country code (WHMCS phone formatting). |
| "skipped: disable_whatsapp_ticked" | The client ticked the box (or replied STOP). |
| Failed with code 132001 | The template name or language doesn't match Meta exactly. |
| Webhook verify fails in Meta | The verify token must match exactly; the module must be activated. |

## 10. For developers: tests (not uploaded)
```
composer install
vendor/bin/phpunit                     # 102 tests: features, bad input and attack attempts
php tests/browser/render.php out && node tests/browser/check.js out   # real-browser check of every tab
```
Meta and WHMCS are simulated; tests never send real messages.
