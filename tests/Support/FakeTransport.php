<?php
/**
 * A pretend Meta server for tests: records every request and answers with whatever the test chose.
 * No real network connection is ever made.
 */

declare(strict_types=1);

use BinaryRacks\WhatsApp\HttpTransport;

final class FakeTransport implements HttpTransport
{
    /** Every request made: ['host', 'path', 'headers', 'body' (decoded JSON)]. */
    public array $requests = [];

    /** Answers to give, in order; the last one repeats. */
    public array $answers = [];

    private int $counter = 0;

    /** Records the request and returns the next planned answer (default: success). */
    public function post(string $host, string $path, array $headers, string $body): array
    {
        $this->requests[] = ['host' => $host, 'path' => $path, 'headers' => $headers, 'body' => json_decode($body, true)];
        $this->counter++;
        $answer = $this->answers[min(count($this->answers), $this->counter) - 1] ?? null;
        return $answer ?? ['status' => 200, 'body' => json_encode(['messages' => [['id' => 'wamid.TEST' . $this->counter . 'ABCDEFG']]]), 'error' => null];
    }

    /** Plans a Meta error answer. */
    public static function error(int $status, int $code, string $message): array
    {
        return ['status' => $status, 'body' => json_encode(['error' => ['code' => $code, 'message' => $message]]), 'error' => null];
    }
}
