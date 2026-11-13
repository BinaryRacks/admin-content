<?php
/**
 * Works out a client's time zone from their country (and state, for countries with several zones).
 * WHMCS doesn't store a time zone per client, so this is the best automatic guess.
 * Falls back to the WHMCS system time zone when unsure.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

if (!defined('WHMCS')) {
    exit;
}

final class TimeZoneResolver
{
    /** States and provinces for the most common multi-zone countries. Keys are upper case. */
    private const STATES = [
        'US' => [
            'America/New_York'    => ['CT', 'DE', 'DC', 'FL', 'GA', 'IN', 'KY', 'ME', 'MD', 'MA', 'MI', 'NH', 'NJ', 'NY', 'NC', 'OH', 'PA', 'RI', 'SC', 'VT', 'VA', 'WV',
                                      'CONNECTICUT', 'DELAWARE', 'DISTRICT OF COLUMBIA', 'FLORIDA', 'GEORGIA', 'INDIANA', 'KENTUCKY', 'MAINE', 'MARYLAND', 'MASSACHUSETTS',
                                      'MICHIGAN', 'NEW HAMPSHIRE', 'NEW JERSEY', 'NEW YORK', 'NORTH CAROLINA', 'OHIO', 'PENNSYLVANIA', 'RHODE ISLAND', 'SOUTH CAROLINA',
                                      'VERMONT', 'VIRGINIA', 'WEST VIRGINIA'],
            'America/Chicago'     => ['AL', 'AR', 'IL', 'IA', 'KS', 'LA', 'MN', 'MS', 'MO', 'NE', 'ND', 'OK', 'SD', 'TN', 'TX', 'WI',
                                      'ALABAMA', 'ARKANSAS', 'ILLINOIS', 'IOWA', 'KANSAS', 'LOUISIANA', 'MINNESOTA', 'MISSISSIPPI', 'MISSOURI', 'NEBRASKA',
                                      'NORTH DAKOTA', 'OKLAHOMA', 'SOUTH DAKOTA', 'TENNESSEE', 'TEXAS', 'WISCONSIN'],
            'America/Denver'      => ['CO', 'ID', 'MT', 'NM', 'UT', 'WY', 'COLORADO', 'IDAHO', 'MONTANA', 'NEW MEXICO', 'UTAH', 'WYOMING'],
            'America/Phoenix'     => ['AZ', 'ARIZONA'],
            'America/Los_Angeles' => ['CA', 'NV', 'OR', 'WA', 'CALIFORNIA', 'NEVADA', 'OREGON', 'WASHINGTON'],
            'America/Anchorage'   => ['AK', 'ALASKA'],
            'Pacific/Honolulu'    => ['HI', 'HAWAII'],
        ],
        'CA' => [
            'America/St_Johns'  => ['NL', 'NEWFOUNDLAND AND LABRADOR'],
            'America/Halifax'   => ['NS', 'NB', 'PE', 'NOVA SCOTIA', 'NEW BRUNSWICK', 'PRINCE EDWARD ISLAND'],
            'America/Toronto'   => ['ON', 'QC', 'ONTARIO', 'QUEBEC'],
            'America/Winnipeg'  => ['MB', 'MANITOBA'],
            'America/Regina'    => ['SK', 'SASKATCHEWAN'],
            'America/Edmonton'  => ['AB', 'NT', 'NU', 'ALBERTA', 'NORTHWEST TERRITORIES', 'NUNAVUT'],
            'America/Vancouver' => ['BC', 'YT', 'BRITISH COLUMBIA', 'YUKON'],
        ],
        'AU' => [
            'Australia/Sydney'    => ['NSW', 'ACT', 'VIC', 'TAS', 'NEW SOUTH WALES', 'AUSTRALIAN CAPITAL TERRITORY', 'VICTORIA', 'TASMANIA'],
            'Australia/Brisbane'  => ['QLD', 'QUEENSLAND'],
            'Australia/Adelaide'  => ['SA', 'SOUTH AUSTRALIA'],
            'Australia/Darwin'    => ['NT', 'NORTHERN TERRITORY'],
            'Australia/Perth'     => ['WA', 'WESTERN AUSTRALIA'],
        ],
    ];

    /** For other multi-zone countries, the zone where most people live. */
    private const MAIN_ZONE = [
        'US' => 'America/New_York', 'CA' => 'America/Toronto', 'AU' => 'Australia/Sydney',
        'BR' => 'America/Sao_Paulo', 'MX' => 'America/Mexico_City', 'RU' => 'Europe/Moscow',
        'ID' => 'Asia/Jakarta', 'KZ' => 'Asia/Almaty', 'AR' => 'America/Argentina/Buenos_Aires',
        'CN' => 'Asia/Shanghai', 'ES' => 'Europe/Madrid', 'PT' => 'Europe/Lisbon', 'DE' => 'Europe/Berlin',
        'NZ' => 'Pacific/Auckland', 'CL' => 'America/Santiago', 'EC' => 'America/Guayaquil',
        'FR' => 'Europe/Paris', 'GB' => 'Europe/London', 'UA' => 'Europe/Kyiv', 'MY' => 'Asia/Kuala_Lumpur',
        'CD' => 'Africa/Kinshasa', 'MN' => 'Asia/Ulaanbaatar', 'UZ' => 'Asia/Tashkent', 'CY' => 'Asia/Nicosia',
        'DK' => 'Europe/Copenhagen', 'NL' => 'Europe/Amsterdam', 'IT' => 'Europe/Rome', 'GR' => 'Europe/Athens',
    ];

    /** Returns the client's time zone, from country code (e.g. "US") and state (e.g. "TX" or "Texas"). */
    public static function forClient(string $country, string $state): \DateTimeZone
    {
        $country = strtoupper(trim($country));
        $state = strtoupper(trim($state));

        if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
            // 1. Known state of a multi-zone country.
            foreach (self::STATES[$country] ?? [] as $zone => $states) {
                if (in_array($state, $states, true)) {
                    return new \DateTimeZone($zone);
                }
            }
            // 2. Countries with a single time zone, from PHP's built-in list.
            $zones = \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $country);
            if (count($zones) === 1) {
                return new \DateTimeZone($zones[0]);
            }
            // 3. Main zone of a multi-zone country.
            if (isset(self::MAIN_ZONE[$country])) {
                return new \DateTimeZone(self::MAIN_ZONE[$country]);
            }
        }
        // 4. Unsure: use the WHMCS system time zone.
        return new \DateTimeZone(date_default_timezone_get());
    }
}
