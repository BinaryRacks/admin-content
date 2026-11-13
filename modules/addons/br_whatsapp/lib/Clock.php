<?php
/**
 * One place that tells the module "what time is it now" (always in UTC).
 * Used by the queue, logs and daytime rule. Tests can freeze the time here.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

if (!defined('WHMCS')) {
    exit;
}

final class Clock
{
    /** When set (only by tests), the module behaves as if this is the current time. */
    public static ?\DateTimeImmutable $frozen = null;

    /** Returns the current time in UTC. */
    public static function now(): \DateTimeImmutable
    {
        return self::$frozen ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** Returns the current time as text for the database, e.g. "2026-11-13 09:30:00" (UTC). */
    public static function nowDb(): string
    {
        return self::toDb(self::now());
    }

    /** Turns a date into database text in UTC. */
    public static function toDb(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
