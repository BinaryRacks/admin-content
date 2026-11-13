<?php
/**
 * Binary Racks WhatsApp Notifications: the main WHMCS addon file.
 * WHMCS calls these functions to show the module in Setup > Addon Modules, to activate,
 * deactivate and upgrade it, and to draw its admin page.
 */

declare(strict_types=1);

use BinaryRacks\WhatsApp\AdminController;
use BinaryRacks\WhatsApp\Branding;
use BinaryRacks\WhatsApp\Consent;
use BinaryRacks\WhatsApp\Messages;
use BinaryRacks\WhatsApp\PhoneNumber;
use BinaryRacks\WhatsApp\Schema;
use BinaryRacks\WhatsApp\Settings;

// Stop at once if this file is opened directly from the web.
if (!defined('WHMCS')) {
    exit;
}

require_once __DIR__ . '/lib/bootstrap.php';

/** Tells WHMCS the module's name, version and author. */
function br_whatsapp_config(): array
{
    return [
        'name'        => Branding::MODULE_NAME,
        'description' => 'Sends WHMCS notifications, manual messages and bulk marketing to clients on WhatsApp (Meta Cloud API).',
        'version'     => Branding::VERSION,
        'author'      => 'Binary Racks',
        'language'    => 'english',
        'fields'      => [],   // all settings live on the module's own Settings tab
    ];
}

/** Runs when the admin clicks Activate: creates tables, built-in messages and the disable_whatsapp field. */
function br_whatsapp_activate(): array
{
    try {
        br_whatsapp_prepare();
        return ['status' => 'success', 'description' => 'Activated. Open Addons > ' . Branding::MODULE_NAME . ' > Settings to connect Meta.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Activation failed: the database tables could not be created.'];
    }
}

/** Runs on Deactivate: keeps all data unless "Delete data on deactivate" is ticked. */
function br_whatsapp_deactivate(): array
{
    try {
        if (Settings::bool('delete_on_deactivate')) {
            Schema::dropAll();
            return ['status' => 'success', 'description' => 'Deactivated and module data deleted (the disable_whatsapp field was kept).'];
        }
        return ['status' => 'success', 'description' => 'Deactivated. Your settings, messages and logs were kept.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Deactivation could not finish.'];
    }
}

/** Runs when WHMCS sees a new version was uploaded: upgrades the tables automatically. */
function br_whatsapp_upgrade(array $vars): void
{
    br_whatsapp_prepare();
}

/** Draws the module's admin page. */
function br_whatsapp_output(array $vars): void
{
    br_whatsapp_prepare();
    $controller = new AdminController(
        (string) ($vars['modulelink'] ?? 'addonmodules.php?module=br_whatsapp'),
        $_GET,
        $_POST,
        strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))
    );
    echo $controller->render();
}

/** Makes sure tables, built-in messages, the client field and the hashing key all exist. Safe to repeat. */
function br_whatsapp_prepare(): void
{
    Schema::ensure();
    Messages::seed();
    Consent::ensureField();
    if (!Settings::hasSecret('hash_key')) {
        PhoneNumber::hash('10000000');   // creates the secret hashing key
    }
}
