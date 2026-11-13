<?php
/**
 * The Binary Racks header bar shown above the module's tabs: logo, module name and version.
 * The logo is embedded in this file as a data URI (no image file), so it still shows
 * when the module folder is closed to the internet.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

if (!defined('WHMCS')) {
    exit;
}

final class Branding
{
    public const MODULE_NAME = 'Binary Racks WhatsApp Notifications';
    public const VERSION = '1.0.0';

    /** The Binary Racks logo (SVG), embedded so no image file is needed. Replace with the official logo. */
    public const LOGO_DATA_URI = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxNjAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCAxNjAgNDAiPjxyZWN0IHg9IjIiIHk9IjQiIHdpZHRoPSIyOCIgaGVpZ2h0PSIzMiIgcng9IjQiIGZpbGw9IiMwYjNkOTEiLz48cmVjdCB4PSI3IiB5PSI5IiB3aWR0aD0iMTgiIGhlaWdodD0iNSIgcng9IjEiIGZpbGw9IiNmZmYiLz48cmVjdCB4PSI3IiB5PSIxNyIgd2lkdGg9IjE4IiBoZWlnaHQ9IjUiIHJ4PSIxIiBmaWxsPSIjZmZmIi8+PHJlY3QgeD0iNyIgeT0iMjUiIHdpZHRoPSIxOCIgaGVpZ2h0PSI1IiByeD0iMSIgZmlsbD0iI2ZmZiIvPjxjaXJjbGUgY3g9IjIyIiBjeT0iMTEuNSIgcj0iMS4yIiBmaWxsPSIjMjVkMzY2Ii8+PGNpcmNsZSBjeD0iMjIiIGN5PSIxOS41IiByPSIxLjIiIGZpbGw9IiMyNWQzNjYiLz48Y2lyY2xlIGN4PSIyMiIgY3k9IjI3LjUiIHI9IjEuMiIgZmlsbD0iIzI1ZDM2NiIvPjx0ZXh0IHg9IjM4IiB5PSIxOSIgZm9udC1mYW1pbHk9IkFyaWFsLEhlbHZldGljYSxzYW5zLXNlcmlmIiBmb250LXNpemU9IjEzIiBmb250LXdlaWdodD0iNzAwIiBmaWxsPSIjMGIzZDkxIj5CSU5BUlk8L3RleHQ+PHRleHQgeD0iMzgiIHk9IjM0IiBmb250LWZhbWlseT0iQXJpYWwsSGVsdmV0aWNhLHNhbnMtc2VyaWYiIGZvbnQtc2l6ZT0iMTMiIGZvbnQtd2VpZ2h0PSI3MDAiIGZpbGw9IiMyNWE1NWEiPlJBQ0tTPC90ZXh0Pjwvc3ZnPgo=';

    /** Returns the header bar HTML. */
    public static function header(): string
    {
        return '<div class="brwa-header">'
            . '<img class="brwa-logo" src="' . Text::e(self::LOGO_DATA_URI) . '" alt="Binary Racks" width="160" height="40">'
            . '<span class="brwa-title">' . Text::e(self::MODULE_NAME) . '</span>'
            . '<span class="brwa-version">v' . Text::e(self::VERSION) . '</span>'
            . '</div>';
    }
}
