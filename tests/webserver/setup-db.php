<?php
/**
 * Creates the SQLite database for the web-server security tests: WHMCS tables, the module activated
 * and configured, and one client whose number is known to the module (for the STOP test).
 * Usage: php tests/webserver/setup-db.php <database file>
 */

declare(strict_types=1);

require __DIR__ . '/../Support/bootstrap.php';

use BinaryRacks\WhatsApp\Contacts;
use BinaryRacks\WhatsApp\Settings;

@unlink($argv[1]);
touch($argv[1]);   // SQLite needs the file to exist
FakeWhmcs::reset($argv[1]);
br_whatsapp_activate();
Settings::set('phone_number_id', '123456789012');
Settings::setSecret('access_token', 'EAAtesttoken1234567890');
Settings::setSecret('app_secret', 'abcdef0123456789abcdef0123456789');
Settings::setSecret('verify_token', 'verify-token-1234567890');
// Same pretend encryption as init.php, so the web server can read these secrets.
$client = FakeWhmcs::addClient();
Contacts::remember($client, '447911123456');
echo "database ready, client $client\n";
