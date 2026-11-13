<?php
/**
 * Test start-up: builds a pretend WHMCS (in-memory database, encrypt/decrypt, localAPI, add_hook)
 * so the module can be tested without a real WHMCS and without sending real WhatsApp messages.
 * Used only by PHPUnit; never uploaded to the server.
 */

declare(strict_types=1);

define('WHMCS', true);
define('BRWA_ROOT', dirname(__DIR__, 2) . '/modules/addons/br_whatsapp');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// WHMCS exposes Laravel's Capsule as WHMCS\Database\Capsule; tests do the same.
class_alias(\Illuminate\Database\Capsule\Manager::class, 'WHMCS\Database\Capsule');

require __DIR__ . '/FakeWhmcs.php';

/** Pretend WHMCS encrypt(): reversible but clearly not plain text. */
function encrypt(string $value): string
{
    return 'enc:' . base64_encode(strrev($value));
}

/** Pretend WHMCS decrypt(). */
function decrypt(string $value): string
{
    return str_starts_with($value, 'enc:') ? strrev((string) base64_decode(substr($value, 4))) : '';
}

/** Pretend WHMCS localAPI(), answered by FakeWhmcs. */
function localAPI(string $command, array $values = [], string $admin = ''): array
{
    return FakeWhmcs::api($command, $values);
}

/** Pretend WHMCS add_hook(): remembers hooks so tests can run them. */
function add_hook(string $name, int $priority, callable $callback): void
{
    FakeWhmcs::$hooks[$name][] = $callback;
}

/** Pretend WHMCS logActivity(). */
function logActivity(string $text): void
{
    FakeWhmcs::$activity[] = $text;
}

/** Pretend WHMCS generate_token(). */
function generate_token(string $type = 'form'): string
{
    return '<input type="hidden" name="token" value="whmcs-token">';
}

require BRWA_ROOT . '/br_whatsapp.php';
require BRWA_ROOT . '/hooks.php';

// These need the module's classes, so they load last.
require __DIR__ . '/FakeTransport.php';
require __DIR__ . '/TestCase.php';
