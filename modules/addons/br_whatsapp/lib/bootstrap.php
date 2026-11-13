<?php
/**
 * Loads every class of the Binary Racks WhatsApp module.
 * Used by br_whatsapp.php, hooks.php and webhook.php, so each of them needs one line only.
 */

declare(strict_types=1);

// Stop at once if this file is opened directly from the web (outside WHMCS).
if (!defined('WHMCS')) {
    exit;
}

// Files are listed in the order they depend on each other.
foreach ([
    'Clock', 'Text', 'Settings', 'Schema', 'Catalogue', 'Placeholders', 'PhoneNumber',
    'TimeZoneResolver', 'DaytimeWindow', 'Consent', 'Messages', 'MessageLog', 'Contacts',
    'NetworkGuard', 'MetaClient', 'RateLimiter', 'Queue', 'Campaigns', 'QueueWorker',
    'EventHandler', 'WebhookHandler', 'Permissions', 'Csrf', 'Branding', 'View', 'AdminController',
] as $brwaClassFile) {
    require_once __DIR__ . '/' . $brwaClassFile . '.php';
}
unset($brwaClassFile);
