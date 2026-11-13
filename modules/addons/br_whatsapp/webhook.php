<?php
/**
 * PUBLIC ENTRY POINT: the address Meta's servers call with delivery receipts and STOP replies.
 * This is the only file in the module that may be opened from the internet.
 * It starts WHMCS, then WebhookHandler checks the rate limit, size and Meta's signature
 * before anything is read. It answers in plain text only and never shows errors.
 */

declare(strict_types=1);

// Never show PHP errors to the caller (they could reveal paths).
ini_set('display_errors', '0');

// Start WHMCS (database, encryption and localAPI). WHMCS defines the WHMCS constant here.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/lib/bootstrap.php';

use BinaryRacks\WhatsApp\WebhookHandler;

try {
    // Read at most one byte more than allowed, so an oversized body is detected without loading it all.
    $rawBody = (string) file_get_contents('php://input', false, null, 0, WebhookHandler::MAX_BODY_BYTES + 1);
    $headers = [];
    if (isset($_SERVER['HTTP_X_HUB_SIGNATURE_256'])) {
        $headers['x-hub-signature-256'] = (string) $_SERVER['HTTP_X_HUB_SIGNATURE_256'];
    }
    [$status, $body] = WebhookHandler::handle(
        strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
        $_GET,
        $headers,
        $rawBody,
        (string) ($_SERVER['REMOTE_ADDR'] ?? '')
    );
} catch (\Throwable $e) {
    [$status, $body] = [500, 'Error'];
}

http_response_code($status);
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
echo $body;
