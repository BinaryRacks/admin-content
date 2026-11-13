<?php
/**
 * Web-server security test: attacks the module folder on Apache (.htaccess) and nginx-only servers
 * the way an outsider would, and checks that nothing but webhook.php ever answers with content.
 * Also sends forged and genuine webhook calls through the real web server.
 * Usage: php tests/webserver/attack.php http://127.0.0.1:8081 [more base URLs...]
 * Exit code 0 = everything safe. Every attack that ever worked must stay in this list.
 */

declare(strict_types=1);

const MODULE = '/modules/addons/br_whatsapp/';
const SECRET = 'abcdef0123456789abcdef0123456789';

/** Makes one HTTP request and returns [status, body]. */
function request(string $url, string $method = 'GET', string $body = '', array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10, CURLOPT_PATH_AS_IS => true, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
    if ($body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $out = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $out];
}

/** Returns true if a response leaks anything from the module (source, data, paths, errors). */
function leaks(string $body): bool
{
    foreach (['<?php', 'namespace', 'BinaryRacks', 'brwa', 'Capsule', '/var/www', 'Fatal', 'Warning', 'Stack trace', 'mod_brwa', 'Require all', 'Binary Racks'] as $needle) {
        if (stripos($body, $needle) !== false) {
            return true;
        }
    }
    return false;
}

$moduleRoot = dirname(__DIR__, 2) . '/modules/addons/br_whatsapp';
$paths = ['.htaccess', 'lib/', 'lib/views/', ''];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleRoot, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $rel = substr($file->getPathname(), strlen($moduleRoot) + 1);
    if ($rel !== 'webhook.php') {
        $paths[] = $rel;
    }
}
// Tricks: double slashes, dot segments, encoded dots and slashes, wrong case, null byte, trailing dots/slashes, PATH_INFO.
$paths = array_merge($paths, [
    '/lib//Settings.php', 'lib/../lib/Settings.php', '%2e/lib/Settings.php', 'lib/%2e%2e/br_whatsapp.php', 'lib%2fSettings.php',
    'lib/%2E%2E/hooks.php', 'LIB/Settings.php', 'lib/settings.php', 'Br_Whatsapp.php', 'BR_WHATSAPP.PHP', 'lib/Settings.php%00.txt',
    'lib/Settings.php.', 'lib/Settings.php/', 'lib/Settings.php/x.php', 'webhook.php/../lib/Settings.php', 'webhook.php/../../br_whatsapp/hooks.php',
    '.htaccess%20', '.HTACCESS', 'lib/views/settings.php?x=1', 'lib/Settings.php::$DATA', 'lib/Settings.php~', 'lib/Settings.php.bak',
    '..%2f..%2f..%2finit.php', '%252e%252e/br_whatsapp.php', 'webhook.php%2f..%2flib%2fSettings.php',
]);

$failures = 0;
foreach (array_slice($argv, 1) as $base) {
    $checked = 0;
    foreach ($paths as $path) {
        [$status, $body] = request($base . MODULE . $path);
        $checked++;
        // Safe = an error status, or an empty page. Never module content.
        $safe = !leaks($body) && ($status >= 400 || trim($body) === '');
        if (!$safe) {
            $failures++;
            echo "LEAK  $base " . MODULE . "$path -> $status " . substr(preg_replace('/\s+/', ' ', $body), 0, 120) . "\n";
        }
    }

    // Webhook through the real server: forged calls must be refused, a genuine signed STOP accepted.
    $stop = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => [
        'metadata' => ['phone_number_id' => '123456789012'],
        'messages' => [['from' => '447911123456', 'type' => 'text', 'text' => ['body' => 'STOP']]]]]]]]]);
    $checks = [
        'unsigned POST refused'      => [request($base . MODULE . 'webhook.php', 'POST', $stop, ['Content-Type: application/json']), 403],
        'wrong signature refused'    => [request($base . MODULE . 'webhook.php', 'POST', $stop, ['X-Hub-Signature-256: sha256=' . str_repeat('0', 64)]), 403],
        'verify with wrong token'    => [request($base . MODULE . 'webhook.php?hub.mode=subscribe&hub.verify_token=x&hub.challenge=123'), 403],
        'verify challenge XSS'       => [request($base . MODULE . 'webhook.php?hub.mode=subscribe&hub.verify_token=verify-token-1234567890&hub.challenge=%3Cscript%3E'), 400],
        'verify correct'             => [request($base . MODULE . 'webhook.php?hub.mode=subscribe&hub.verify_token=verify-token-1234567890&hub.challenge=4242'), 200],
        'PUT refused'                => [request($base . MODULE . 'webhook.php', 'PUT', 'x'), 405],
        'oversized body refused'     => [request($base . MODULE . 'webhook.php', 'POST', str_repeat('a', 70000)), 413],
        'genuine signed STOP'        => [request($base . MODULE . 'webhook.php', 'POST', $stop, ['X-Hub-Signature-256: sha256=' . hash_hmac('sha256', $stop, SECRET)]), 200],
    ];
    foreach ($checks as $name => [[$status, $body], $expected]) {
        $ok = $status === $expected && !leaks($body) && ($name !== 'verify challenge XSS' || !str_contains($body, '<script'));
        if (!$ok) {
            $failures++;
        }
        echo ($ok ? 'ok    ' : 'FAIL  ') . "$base webhook: $name -> $status\n";
    }
    echo "      $base: $checked direct-access attempts checked\n";
}
echo $failures === 0 ? "ALL SAFE\n" : "$failures PROBLEM(S)\n";
exit($failures === 0 ? 0 : 1);
