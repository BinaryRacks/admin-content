<?php
/**
 * Renders the admin page templates in lib/views/.
 * Templates get their data as plain variables and must escape everything with $e().
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

if (!defined('WHMCS')) {
    exit;
}

final class View
{
    /** The only template names allowed (stops a crafted name from loading another file). */
    private const TEMPLATES = ['layout', 'dashboard', 'notifications', 'messages', 'marketing', 'log', 'settings', 'send'];

    /** Renders a template with the given data and returns the HTML. */
    public static function render(string $name, array $data): string
    {
        if (!in_array($name, self::TEMPLATES, true)) {
            throw new \InvalidArgumentException('Unknown view.');
        }
        // $e is the escaping helper every template uses for output.
        $e = static fn (mixed $value): string => Text::e($value);
        extract($data, EXTR_SKIP);
        ob_start();
        include __DIR__ . '/views/' . $name . '.php';
        return (string) ob_get_clean();
    }
}
