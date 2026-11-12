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
| 3 | Do you already have a Meta Business account and a WhatsApp Business number? | _waiting for answer_ |

## Waiting for

- Your security standards `.md` files.
