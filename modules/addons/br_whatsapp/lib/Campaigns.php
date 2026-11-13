<?php
/**
 * Bulk marketing campaigns (table mod_brwa_campaigns).
 * Recipients: active clients with the WHMCS marketing opt-in on and disable_whatsapp not ticked.
 * Starting a campaign only queues the messages; the WHMCS cron sends them in batches,
 * under the daily cap and the daytime rule. Campaigns can be paused, resumed or cancelled.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit;
}

final class Campaigns
{
    public const TABLE = 'mod_brwa_campaigns';

    /** Returns the IDs of every client who may receive marketing right now. */
    public static function recipientIds(): array
    {
        $query = Capsule::table('tblclients')
            ->where('status', 'Active')
            ->where('marketing_emails_opt_in', 1)
            ->orderBy('id');

        $fieldId = Consent::fieldId();
        if ($fieldId !== null) {
            // Leave out clients who ticked disable_whatsapp (bound values only, no text joining).
            $query->whereNotIn('id', function ($sub) use ($fieldId) {
                $sub->select('relid')->from('tblcustomfieldsvalues')
                    ->where('fieldid', $fieldId)
                    ->whereIn('value', ['on', '1', 'yes', 'true']);
            });
        }
        $ids = [];
        foreach ($query->get(['id']) as $row) {
            $ids[] = (int) $row->id;
        }
        return $ids;
    }

    /** Starts a campaign for an enabled marketing message and queues one message per recipient. */
    public static function start(string $msgKey, int $adminId): int
    {
        $message = Messages::find($msgKey);
        if ($message === null || $message->kind !== 'marketing' || !$message->enabled) {
            throw new \InvalidArgumentException('Choose an enabled marketing message.');
        }
        $recipients = self::recipientIds();
        $now = Clock::nowDb();
        $campaignId = (int) Capsule::table(self::TABLE)->insertGetId([
            'msg_key' => $msgKey, 'status' => 'running', 'total' => count($recipients),
            'sent' => 0, 'failed' => 0, 'skipped' => 0, 'created_by' => $adminId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($recipients as $clientId) {
            Queue::add($clientId, $msgKey, 'campaign', null, 'campaign:' . $campaignId . ':' . $clientId, false, $campaignId, $adminId);
        }
        if ($recipients === []) {
            self::setStatus($campaignId, 'completed');
        }
        return $campaignId;
    }

    /** Pauses a running campaign: its waiting messages are held back. */
    public static function pause(int $id): void
    {
        if (self::statusOf($id) !== 'running') {
            return;
        }
        Capsule::table(Queue::TABLE)->where('campaign_id', $id)->where('status', 'pending')->update(['status' => 'paused']);
        self::setStatus($id, 'paused');
    }

    /** Resumes a paused campaign. */
    public static function resume(int $id): void
    {
        if (self::statusOf($id) !== 'paused') {
            return;
        }
        Capsule::table(Queue::TABLE)->where('campaign_id', $id)->where('status', 'paused')->update(['status' => 'pending']);
        self::setStatus($id, 'running');
    }

    /** Cancels a campaign: messages not yet sent are dropped. */
    public static function cancel(int $id): void
    {
        if (!in_array(self::statusOf($id), ['running', 'paused'], true)) {
            return;
        }
        Capsule::table(Queue::TABLE)->where('campaign_id', $id)->whereIn('status', ['pending', 'paused'])
            ->update(['status' => 'cancelled', 'updated_at' => Clock::nowDb()]);
        self::setStatus($id, 'cancelled');
    }

    /** Adds one to the sent, failed or skipped counter, and marks the campaign completed when nothing is left. */
    public static function record(int $id, string $outcome): void
    {
        if (!in_array($outcome, ['sent', 'failed', 'skipped'], true)) {
            return;
        }
        Capsule::table(self::TABLE)->where('id', $id)->increment($outcome);
        $left = Capsule::table(Queue::TABLE)->where('campaign_id', $id)->whereIn('status', ['pending', 'sending', 'paused'])->count();
        if ($left === 0 && self::statusOf($id) === 'running') {
            self::setStatus($id, 'completed');
        }
    }

    /** Returns the latest campaigns for the Marketing tab. */
    public static function latest(int $limit = 20): array
    {
        $rows = [];
        foreach (Capsule::table(self::TABLE)->orderBy('id', 'desc')->limit($limit)->get() as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** Returns a campaign's status, or null if it doesn't exist. */
    public static function statusOf(int $id): ?string
    {
        $status = Capsule::table(self::TABLE)->where('id', $id)->value('status');
        return $status === null ? null : (string) $status;
    }

    /** Changes a campaign's status. */
    private static function setStatus(int $id, string $status): void
    {
        Capsule::table(self::TABLE)->where('id', $id)->update(['status' => $status, 'updated_at' => Clock::nowDb()]);
    }
}
