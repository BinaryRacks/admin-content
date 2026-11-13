# Changelog: Binary Racks WhatsApp Notifications

## 1.0.0 (first release)
- Automatic WhatsApp notifications for invoices, services (suspension with reason is critical), tickets and domains,
  with category switches and per-event switches.
- Manual messages from the client summary page ("Send WhatsApp"), including the ready-made due invoice reminder.
- Bulk marketing campaigns to clients with the WHMCS marketing opt-in, sent by the WHMCS cron with a daily cap.
- Daytime rule: 08:00-18:00 in each client's time zone (from country and state).
- Client tick box `disable_whatsapp` blocks every WhatsApp message; a STOP reply ticks it and is logged as proof.
- Public webhook (Meta signature checked) for delivery receipts and STOP.
- GDPR: masked numbers, no message text, 90-day retention, export and erasure tools.

**Upgrade steps:** first release, no old files to delete.
