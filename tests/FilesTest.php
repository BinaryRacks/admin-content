<?php
/**
 * Tests direct web access to the module's files, the way an attacker would try it.
 * Uses PHP's built-in web server, which ignores .htaccess exactly like an "nginx only" server,
 * to prove every file except webhook.php shows nothing. Also checks the folder only holds
 * .php files and the .htaccess, and that every file starts with the WHMCS guard.
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;

final class FilesTest extends \PHPUnit\Framework\TestCase
{
    private static $server;
    private static int $port = 0;
    private static string $root = '';

    /** Starts a web server with a pretend WHMCS root containing only the module folder. */
    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/brwa-docroot-' . getmypid();
        @mkdir(self::$root . '/modules/addons', 0777, true);
        exec('cp -r ' . escapeshellarg(BRWA_ROOT) . ' ' . escapeshellarg(self::$root . '/modules/addons/'));
        // No init.php on purpose: if webhook.php can't start WHMCS it must fail without leaking anything.
        self::$port = random_int(20000, 40000);
        self::$server = proc_open(['php', '-d', 'display_errors=1', '-S', '127.0.0.1:' . self::$port, '-t', self::$root],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        usleep(400000);
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$server);
        exec('rm -rf ' . escapeshellarg(self::$root));
    }

    /** Fetches a path from the test server and returns [status, body]. */
    private function fetch(string $path): array
    {
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
        $body = (string) @file_get_contents('http://127.0.0.1:' . self::$port . $path, false, $ctx);
        $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;
        return [$status, $body];
    }

    public function testEveryPhpFileExceptWebhookShowsNothing(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BRWA_ROOT, FilesystemIterator::SKIP_DOTS));
        $count = 0;
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen(BRWA_ROOT) + 1);
            if ($file->getExtension() !== 'php' || $relative === 'webhook.php') {
                continue;
            }
            [, $body] = $this->fetch('/modules/addons/br_whatsapp/' . $relative);
            $this->assertSame('', $body, $relative . ' must print nothing when opened directly');
            $count++;
        }
        $this->assertGreaterThan(30, $count);
    }

    public function testAttackPathTricksShowNothing(): void
    {
        foreach ([
            '/modules/addons/br_whatsapp//lib//Settings.php',
            '/modules/addons/br_whatsapp/lib/../lib/Settings.php',
            '/modules/addons/br_whatsapp/%2e/lib/Settings.php',
            '/modules/addons/br_whatsapp/lib/%2e%2e/br_whatsapp.php',
            '/modules/addons/br_whatsapp/LIB/Settings.php',
            '/modules/addons/br_whatsapp/lib/settings.php',
            '/modules/addons/br_whatsapp/lib/Settings.php%00.txt',
            '/modules/addons/br_whatsapp/lib/views/settings.php?x=1',
        ] as $path) {
            [$status, $body] = $this->fetch($path);
            $this->assertStringNotContainsString('namespace', $body, $path);
            $this->assertStringNotContainsString('<?php', $body, $path);
            $this->assertStringNotContainsString('brwa', $body, $path);
            // A 400/404 error page from the web server may repeat the typed address; anything else must be empty.
            $this->assertTrue(in_array($status, [400, 404], true) || $body === '', $path . ' answered ' . $status . ' with content');
            $this->assertStringNotContainsString(self::$root, $body, $path . ' must not reveal server paths');
        }
    }

    public function testWebhookWithoutWhmcsFailsWithoutLeakingPaths(): void
    {
        [$status, $body] = $this->fetch('/modules/addons/br_whatsapp/webhook.php');
        $this->assertSame(500, $status);
        $this->assertStringNotContainsString('init.php', $body);
        $this->assertStringNotContainsString(self::$root, $body);
    }

    public function testFolderHoldsOnlyPhpFilesAndHtaccess(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BRWA_ROOT, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $name = $file->getFilename();
            $this->assertTrue($name === '.htaccess' || $file->getExtension() === 'php', 'unexpected public file: ' . $name);
            if ($name === '.htaccess') {
                $this->assertSame(BRWA_ROOT . '/.htaccess', $file->getPathname(), 'only one .htaccess, in the module folder');
            }
        }
    }

    public function testEveryPhpFileHasTheGuardAndAHeaderComment(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BRWA_ROOT, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $this->assertStringStartsWith("<?php\n/**", $code, $file->getFilename() . ' starts with a description');
            if ($file->getFilename() !== 'webhook.php') {
                $this->assertStringContainsString("if (!defined('WHMCS')) {\n    exit;\n}", $code, $file->getFilename());
            }
        }
    }

    public function testHtaccessDeniesAllButWebhook(): void
    {
        $rules = (string) file_get_contents(BRWA_ROOT . '/.htaccess');
        $this->assertStringContainsString('Require all denied', $rules);
        $this->assertSame(2, substr_count($rules, '<Files "webhook.php">'));
        $this->assertStringContainsString('Deny from all', $rules);
    }

    public function testNoSecretsOrExternalResourcesInTheCode(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BRWA_ROOT, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            // Embedded images (data: URIs) are random-looking text, so they are left out of the token check.
            $code = (string) preg_replace('#data:image/[a-z+]+;base64,[A-Za-z0-9+/=]+#', '', (string) file_get_contents($file->getPathname()));
            $this->assertDoesNotMatchRegularExpression('/EAA[A-Za-z0-9]{20,}/', $code, 'no Meta token in code');
            $this->assertDoesNotMatchRegularExpression('/<(script|link|img)[^>]+(src|href)="https?:/i', $code, 'no external scripts, styles or images');
            $this->assertStringNotContainsString('->raw(', $code . '', 'no raw SQL built from text');
        }
    }
}
