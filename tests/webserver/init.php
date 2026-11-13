<?php
/**
 * Pretend WHMCS init.php for the web-server security tests (Apache and nginx-only).
 * Placed at the test site's root so webhook.php can start "WHMCS" against a small SQLite database.
 * Never uploaded to a real server.
 */

declare(strict_types=1);

define('WHMCS', true);
require __DIR__ . '/_vendor/autoload.php';
class_alias(\Illuminate\Database\Capsule\Manager::class, 'WHMCS\Database\Capsule');

$capsule = new \Illuminate\Database\Capsule\Manager();
$capsule->addConnection(['driver' => 'sqlite', 'database' => __DIR__ . '/_data/whmcs.sqlite', 'prefix' => '']);
$capsule->setAsGlobal();

function encrypt(string $value): string { return 'enc:' . base64_encode(strrev($value)); }
function decrypt(string $value): string { return str_starts_with($value, 'enc:') ? strrev((string) base64_decode(substr($value, 4))) : ''; }
function logActivity(string $text): void {}
function localAPI(string $command, array $values = []): array
{
    // Only UpdateClient is needed by the webhook (STOP): store the custom field value.
    if ($command === 'UpdateClient') {
        $fields = unserialize(base64_decode((string) ($values['customfields'] ?? '')), ['allowed_classes' => false]);
        foreach (is_array($fields) ? $fields : [] as $fieldId => $value) {
            \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')->where('fieldid', $fieldId)->where('relid', (int) $values['clientid'])->delete();
            \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')->insert(['fieldid' => $fieldId, 'relid' => (int) $values['clientid'], 'value' => $value]);
        }
        return ['result' => 'success'];
    }
    return ['result' => 'error'];
}
