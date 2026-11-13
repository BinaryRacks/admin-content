<?php
/**
 * Simple rate limits ("at most N actions per minute") stored in mod_brwa_ratelimit.
 * Used by the webhook (per caller IP) and by admin send buttons (per admin).
 * Keys are hashed, so no IP address is stored in readable form (GDPR); old rows are deleted.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit;
}

final class RateLimiter
{
    public const TABLE = 'mod_brwa_ratelimit';

    /** Counts one hit and returns false if the limit for this time window has been reached. */
    public static function allow(string $key, int $limit, int $windowSeconds = 60): bool
    {
        $bucket = substr(hash('sha256', 'brwa|' . $key), 0, 64);
        $now = Clock::now()->getTimestamp();
        $window = intdiv($now, $windowSeconds) * $windowSeconds;

        $query = Capsule::table(self::TABLE)->where('bucket', $bucket)->where('window_start', $window);
        $hits = $query->value('hits');
        if ($hits === null) {
            try {
                Capsule::table(self::TABLE)->insert(['bucket' => $bucket, 'window_start' => $window, 'hits' => 1]);
                return true;
            } catch (\Throwable $e) {
                // Another request created the row at the same moment; fall through and count normally.
            }
            $hits = (int) Capsule::table(self::TABLE)->where('bucket', $bucket)->where('window_start', $window)->value('hits');
        }
        if ((int) $hits >= $limit) {
            return false;
        }
        Capsule::table(self::TABLE)->where('bucket', $bucket)->where('window_start', $window)->increment('hits');
        return true;
    }

    /** Deletes counters older than one hour. */
    public static function cleanup(): void
    {
        Capsule::table(self::TABLE)->where('window_start', '<', Clock::now()->getTimestamp() - 3600)->delete();
    }
}
