<?php
/**
 * The daytime rule: messages are only sent between 08:00 and 18:00 in the client's own time zone.
 * Critical messages (service suspension) are exempt; the queue worker decides that.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

if (!defined('WHMCS')) {
    exit;
}

final class DaytimeWindow
{
    public const START_HOUR = 8;
    public const END_HOUR = 18;

    /** Returns true if it is daytime (08:00 to 17:59) for the client right now. */
    public static function isOpen(\DateTimeImmutable $nowUtc, \DateTimeZone $clientZone): bool
    {
        $hour = (int) $nowUtc->setTimezone($clientZone)->format('G');
        return $hour >= self::START_HOUR && $hour < self::END_HOUR;
    }

    /** Returns the next moment (in UTC) when the client's daytime starts. */
    public static function nextOpen(\DateTimeImmutable $nowUtc, \DateTimeZone $clientZone): \DateTimeImmutable
    {
        $local = $nowUtc->setTimezone($clientZone);
        $start = $local->setTime(self::START_HOUR, 0, 0);
        if ($local >= $start) {
            // Already past 08:00 today, so wait for 08:00 tomorrow.
            $start = $start->modify('+1 day')->setTime(self::START_HOUR, 0, 0);
        }
        return $start->setTimezone(new \DateTimeZone('UTC'));
    }
}
