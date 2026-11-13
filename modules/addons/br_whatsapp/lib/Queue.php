<?php
/**
 * The waiting list of messages (table mod_brwa_queue).
 * Each row holds only IDs (client, message key, record) and never message text,
 * so personal details are read fresh at send time and nothing sensitive sits in the queue.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit;
}

final class Queue
{
    public const TABLE = 'mod_brwa_queue';

    /** Statuses that mean "finished"; such rows are deleted after two days. */
    public const FINAL_STATUSES = ['sent', 'failed', 'skipped', 'cancelled'];

    /**
     * Adds a message to the queue. Returns the new row ID, or null if the same message
     * (same $dedupeKey) was already queued, so a notice is never sent twice.
     */
    public static function add(
        int $clientId,
        string $msgKey,
        string $source,
        ?int $relatedId,
        ?string $dedupeKey,
        bool $critical,
        ?int $campaignId = null,
        ?int $adminId = null
    ): ?int {
        if ($dedupeKey !== null && Capsule::table(self::TABLE)->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }
        $now = Clock::nowDb();
        try {
            return (int) Capsule::table(self::TABLE)->insertGetId([
                'client_id'   => $clientId,
                'msg_key'     => $msgKey,
                'source'      => $source,
                'related_id'  => $relatedId,
                'campaign_id' => $campaignId,
                'dedupe_key'  => $dedupeKey === null ? null : mb_substr($dedupeKey, 0, 191),
                'critical'    => $critical ? 1 : 0,
                'status'      => 'pending',
                'attempts'    => 0,
                'send_after'  => $now,
                'locked_at'   => null,
                'admin_id'    => $adminId,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        } catch (\Throwable $e) {
            // The unique dedupe key stopped a duplicate created at the same moment.
            if ($dedupeKey !== null && Capsule::table(self::TABLE)->where('dedupe_key', $dedupeKey)->exists()) {
                return null;
            }
            throw $e;
        }
    }

    /** Returns the IDs of messages that are due now, critical ones first. */
    public static function dueIds(int $limit): array
    {
        $ids = [];
        $rows = Capsule::table(self::TABLE)
            ->where('status', 'pending')
            ->where('send_after', '<=', Clock::nowDb())
            ->orderBy('critical', 'desc')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);
        foreach ($rows as $row) {
            $ids[] = (int) $row->id;
        }
        return $ids;
    }

    /**
     * Marks a row as "sending" so two cron runs at the same time can't both send it.
     * Returns the row if this run got it, or null if another run already did.
     */
    public static function claim(int $id): ?object
    {
        $changed = Capsule::table(self::TABLE)->where('id', $id)->where('status', 'pending')
            ->update(['status' => 'sending', 'locked_at' => Clock::nowDb(), 'updated_at' => Clock::nowDb()]);
        return $changed === 1 ? Capsule::table(self::TABLE)->where('id', $id)->first() : null;
    }

    /** Sets a row's final status. */
    public static function finish(int $id, string $status): void
    {
        Capsule::table(self::TABLE)->where('id', $id)
            ->update(['status' => $status, 'locked_at' => null, 'updated_at' => Clock::nowDb()]);
    }

    /** Puts a row back to wait until a later time (daytime rule, daily cap or a retry). */
    public static function postpone(int $id, \DateTimeImmutable $until, bool $countAttempt): void
    {
        $update = ['status' => 'pending', 'send_after' => Clock::toDb($until), 'locked_at' => null, 'updated_at' => Clock::nowDb()];
        Capsule::table(self::TABLE)->where('id', $id)->update($update);
        if ($countAttempt) {
            Capsule::table(self::TABLE)->where('id', $id)->increment('attempts');
        }
    }

    /** Frees rows stuck in "sending" for 15 minutes (for example if PHP stopped half way). */
    public static function releaseStale(): void
    {
        $cutoff = Clock::toDb(Clock::now()->modify('-15 minutes'));
        Capsule::table(self::TABLE)->where('status', 'sending')->where('locked_at', '<', $cutoff)
            ->update(['status' => 'pending', 'locked_at' => null, 'updated_at' => Clock::nowDb()]);
    }

    /** Deletes finished rows older than two days (keeps the table small; the log holds the history). */
    public static function cleanup(): void
    {
        $cutoff = Clock::toDb(Clock::now()->modify('-2 days'));
        Capsule::table(self::TABLE)->whereIn('status', self::FINAL_STATUSES)->where('updated_at', '<', $cutoff)->delete();
    }

    /** Returns how many rows are in each status, for the dashboard. */
    public static function counts(): array
    {
        $counts = [];
        foreach (Capsule::table(self::TABLE)->select('status', Capsule::raw('COUNT(*) as total'))->groupBy('status')->get() as $row) {
            $counts[(string) $row->status] = (int) $row->total;
        }
        return $counts;
    }
}
