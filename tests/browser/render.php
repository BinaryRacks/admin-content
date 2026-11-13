<?php
/**
 * Browser check, step 1: renders every admin tab with sample data into HTML files,
 * wrapped in a plain page like WHMCS's admin area, so a real browser can open them.
 * Usage: php tests/browser/render.php <output folder>
 */

declare(strict_types=1);

require __DIR__ . '/../Support/bootstrap.php';

use BinaryRacks\WhatsApp\AdminController;
use BinaryRacks\WhatsApp\Campaigns;
use BinaryRacks\WhatsApp\Clock;
use BinaryRacks\WhatsApp\Messages;
use BinaryRacks\WhatsApp\MessageLog;
use BinaryRacks\WhatsApp\Settings;
use WHMCS\Database\Capsule;

$out = $argv[1] ?? __DIR__ . '/out';
@mkdir($out, 0777, true);

// Sample data: an activated, configured module with one client, an invoice, a campaign and some log rows.
date_default_timezone_set('UTC');
FakeWhmcs::reset();
$_SESSION = ['adminid' => 1];
Clock::$frozen = new DateTimeImmutable('2026-11-13 10:00:00', new DateTimeZone('UTC'));
br_whatsapp_activate();
Settings::set('phone_number_id', '123456789012');
Settings::setSecret('access_token', 'EAAtesttoken1234567890');
$client = FakeWhmcs::addClient(['firstname' => 'Alex <b>Test</b>']);
Capsule::table('tblinvoices')->insert(['userid' => $client, 'duedate' => '2026-11-20', 'total' => 25, 'status' => 'Unpaid']);
Messages::create('marketing', ['label' => 'Black Friday', 'template_name' => 'black_friday', 'language' => '', 'params' => 'client_firstname'], true);
MessageLog::add($client, '+44******3456', 'invoice_created', 'event', 1, 'delivered', 'wamid.ABCDEFGHIJK');
MessageLog::add($client, '+44******3456', 'stop', 'webhook', null, 'stop', null, null, MessageLog::STOP_TEXT, true);
Campaigns::start(Messages::allOfKind('marketing')[0]->msg_key, 1);

$pages = ['dashboard' => [], 'notifications' => [], 'messages' => [], 'marketing' => [], 'log' => [], 'settings' => [], 'send' => ['userid' => (string) $client]];
foreach ($pages as $action => $extra) {
    $html = (new AdminController('addonmodules.php?module=br_whatsapp', ['action' => $action] + $extra, [], 'GET'))->render();
    file_put_contents("$out/$action.html", "<!doctype html><html><head><meta charset=\"utf-8\"><title>$action</title></head>"
        . "<body style=\"background:#fff;margin:16px\">$html</body></html>");
}
echo "rendered " . count($pages) . " pages to $out\n";
