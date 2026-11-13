<?php
/**
 * The WhatsApp provider: sends a template message through the official Meta WhatsApp Cloud API.
 * Only connects to https://graph.facebook.com (fixed), checks the certificate, never follows redirects,
 * and always uses short timeouts. Kept separate so another provider could be added later.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

if (!defined('WHMCS')) {
    exit;
}

/** The result of one send attempt. */
final class SendResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $wamid,        // Meta's message ID, used to match delivery receipts
        public readonly bool $retryable,       // true = a temporary problem, try again later
        public readonly ?string $errorCode,
        public readonly ?string $errorText
    ) {
    }
}

/** How the module makes HTTPS requests. Tests replace it with a fake, so no real messages are sent. */
interface HttpTransport
{
    /** Sends a POST and returns ['status' => int, 'body' => string, 'error' => ?string]. */
    public function post(string $host, string $path, array $headers, string $body): array;
}

/** The real transport, using PHP's cURL with strict safety options. */
final class CurlTransport implements HttpTransport
{
    /** Sends the request to the fixed host over HTTPS only. */
    public function post(string $host, string $path, array $headers, string $body): array
    {
        $ip = NetworkGuard::resolve($host);   // refuses blocked addresses
        $ch = curl_init('https://' . $host . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,                // never follow redirects
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,      // HTTPS only
            CURLOPT_SSL_VERIFYPEER => true,                 // check Meta's certificate
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_RESOLVE        => [$host . ':443:' . $ip], // connect to the address we checked
            CURLOPT_MAXFILESIZE    => 1048576,
        ]);
        $responseBody = curl_exec($ch);
        $error = $responseBody === false ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => is_string($responseBody) ? $responseBody : '', 'error' => $error];
    }
}

final class MetaClient
{
    public const HOST = 'graph.facebook.com';

    /** Tests set a fake transport here. */
    public static ?HttpTransport $transport = null;

    /** Meta error codes that mean "too many messages right now, try later". */
    private const RETRY_CODES = ['4', '80007', '130429', '131000', '131016', '131048', '131056', '133004'];

    /**
     * Sends an approved template to a number in international format.
     * $params are the already-cleaned values for {{1}}, {{2}} ...
     */
    public function sendTemplate(string $to, string $templateName, string $language, array $params): SendResult
    {
        $phoneNumberId = Settings::get('phone_number_id');
        $apiVersion = Settings::get('api_version');
        $token = Settings::secret('access_token');

        // Check every part of the address, so nothing typed in settings can change where we connect.
        if (preg_match('/^\d{5,20}$/', $phoneNumberId) !== 1 || preg_match('/^v\d{1,2}\.\d$/', $apiVersion) !== 1 || $token === '') {
            return new SendResult(false, null, false, 'not_configured', 'Meta settings are missing or invalid.');
        }
        if (preg_match('/^[1-9]\d{7,14}$/', $to) !== 1 || preg_match(Messages::TEMPLATE_PATTERN, $templateName) !== 1
            || preg_match(Messages::LANGUAGE_PATTERN, $language) !== 1) {
            return new SendResult(false, null, false, 'invalid_message', 'Number, template or language is invalid.');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'template',
            'template'          => ['name' => $templateName, 'language' => ['code' => $language]],
        ];
        if ($params !== []) {
            $payload['template']['components'] = [[
                'type'       => 'body',
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($params)),
            ]];
        }

        try {
            $response = (self::$transport ?? new CurlTransport())->post(
                self::HOST,
                '/' . $apiVersion . '/' . $phoneNumberId . '/messages',
                ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
                (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );
        } catch (\Throwable $e) {
            return new SendResult(false, null, true, 'network', $e->getMessage());
        }

        return self::interpret($response);
    }

    /** Turns Meta's HTTP answer into a SendResult. */
    private static function interpret(array $response): SendResult
    {
        if (($response['error'] ?? null) !== null) {
            return new SendResult(false, null, true, 'network', (string) $response['error']);
        }
        $status = (int) ($response['status'] ?? 0);
        $data = json_decode((string) ($response['body'] ?? ''), true, 16);
        if (!is_array($data)) {
            $data = [];
        }

        $wamid = $data['messages'][0]['id'] ?? null;
        if ($status >= 200 && $status < 300 && is_string($wamid) && preg_match('/^[A-Za-z0-9_.:=\-]{10,191}$/', $wamid) === 1) {
            return new SendResult(true, $wamid, false, null, null);
        }

        $code = isset($data['error']['code']) ? (string) (int) $data['error']['code'] : 'http_' . $status;
        $message = is_string($data['error']['message'] ?? null) ? $data['error']['message'] : 'Unexpected answer from Meta.';
        $retryable = $status === 429 || $status >= 500 || in_array($code, self::RETRY_CODES, true);
        return new SendResult(false, null, $retryable, $code, $message);
    }
}
