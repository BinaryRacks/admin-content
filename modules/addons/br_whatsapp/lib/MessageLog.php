<?php
/**
 * The message log (table mod_brwa_log), written with GDPR in mind:
 * only the client ID, a masked phone number, which message, its status and the time are stored.
 * No message text, names or reply text. Normal entries are deleted after the retention period;
 * STOP records are kept as proof of opt-out until the client is deleted or asks for erasure.
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit;
}

final class MessageLog
{
    public const TABLE = 'mod_brwa_log';
    public const STATUSES = ['sent', 'delivered', 'read', 'failed', 'skipped', 'stop'];

    /** Order of delivery receipts: a receipt may only move a message forward. */
    private const PROGRESS = ['sent' => 1, 'delivered' => 2, 'read' => 3];

    /** The fixed text written when a customer replies STOP. */
    public const STOP_TEXT = 'Customer requested STOP via WhatsApp, disable_whatsapp ticked';

    /** Adds a log entry and returns its ID. Error text is cleaned of personal data first. */
    public static function add(
        ?int $clientId,
        string $phoneMasked,
        string $msgKey,
        string $source,
        ?int $relatedId,
        string $status,
        ?string $wamid = null,
        ?string $errorCode = null,
        ?string $errorText = null,
        bool $isStop = false
    ): int {
        $now = Clock::nowDb();
        return (int) Capsule::table(self::TABLE)->insertGetId([
            'client_id'    => $clientId,
            'phone_masked' => mb_substr($phoneMasked, 0, 32),
            'msg_key'      => mb_substr($msgKey, 0, 64),
            'source'       => mb_substr($source, 0, 16),
            'related_id'   => $relatedId,
            'status'       => $status,
            'wamid'        => $wamid,
            'error_code'   => $errorCode === null ? null : mb_substr($errorCode, 0, 32),
            'error_text'   => $errorText === null ? null : Text::scrub($errorText),
            'is_stop'      => $isStop ? 1 : 0,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    /**
     * Updates a sent message when Meta reports it was delivered, read or failed.
     * Receipts can arrive out of order, so "read" is never turned back into "delivered".
     */
    public static function applyReceipt(string $wamid, string $status, ?string $errorCode, ?string $errorText): bool
    {
        $row = Capsule::table(self::TABLE)->where('wamid', $wamid)->first(['id', 'status']);
        if ($row === null) {
            return false;
        }
        if ($status !== 'failed') {
            $old = self::PROGRESS[$row->status] ?? 0;
            $new = self::PROGRESS[$status] ?? 0;
            if ($new <= $old) {
                return true;   // older news than what we already know
            }
        }
        $update = ['status' => $status, 'updated_at' => Clock::nowDb()];
        if ($status === 'failed') {
            $update['error_code'] = $errorCode === null ? null : mb_substr($errorCode, 0, 32);
            $update['error_text'] = $errorText === null ? null : Text::scrub($errorText);
        }
        Capsule::table(self::TABLE)->where('id', $row->id)->update($update);
        return true;
    }

    /** Counts marketing messages sent today (UTC), for the daily cap. */
    public static function marketingSentToday(): int
    {
        $start = Clock::now()->setTime(0, 0, 0);
        return (int) Capsule::table(self::TABLE)
            ->where('source', 'campaign')
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->where('created_at', '>=', Clock::toDb($start))
            ->count();
    }

    /**
     * Returns one page of log entries, newest first.
     * $filters: client_id (int), status (one of STATUSES), from / to (Y-m-d).
     */
    public static function search(array $filters, int $page, int $perPage = 50): array
    {
        $query = Capsule::table(self::TABLE);
        if (!empty($filters['client_id'])) {
            $query->where('client_id', (int) $filters['client_id']);
        }
        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from'] . ' 00:00:00');
        }
        if (!empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to'] . ' 23:59:59');
        }
        $total = (clone $query)->count();
        $rows = [];
        foreach ($query->orderBy('id', 'desc')->offset(max(0, $page - 1) * $perPage)->limit($perPage)->get() as $row) {
            $rows[] = $row;
        }
        return ['total' => $total, 'rows' => $rows];
    }

    /** Builds a CSV file of everything stored about one client (GDPR right of access). */
    public static function exportClientCsv(int $clientId): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['date_utc', 'message', 'source', 'record_id', 'status', 'phone_masked', 'error_code', 'error_text', 'is_stop'], ',', '"', '');
        foreach (Capsule::table(self::TABLE)->where('client_id', $clientId)->orderBy('id')->get() as $row) {
            fputcsv($out, [
                $row->created_at, $row->msg_key, $row->source, $row->related_id, $row->status,
                $row->phone_masked, $row->error_code, $row->error_text, (int) $row->is_stop,
            ], ',', '"', '');
        }
        // Waiting messages are also personal data, so they are included.
        foreach (Capsule::table(Queue::TABLE)->where('client_id', $clientId)->orderBy('id')->get() as $row) {
            fputcsv($out, [$row->created_at, $row->msg_key, $row->source, $row->related_id, 'queue:' . $row->status, '', '', '', 0], ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return self::neutraliseCsvFormulas($csv);
    }

    /**
     * Deletes everything stored about a client: log (including STOP proof), queue and phone hash.
     * Used when the client is deleted in WHMCS and by the "Delete this client's WhatsApp data" button.
     * The disable_whatsapp tick box itself is WHMCS data and stays as it is.
     */
    public static function eraseClient(int $clientId): void
    {
        Capsule::table(self::TABLE)->where('client_id', $clientId)->delete();
        Capsule::table(Queue::TABLE)->where('client_id', $clientId)->delete();
        Capsule::table(Contacts::TABLE)->where('client_id', $clientId)->delete();
    }

    /** Deletes normal entries older than the retention period. STOP proofs are kept. */
    public static function cleanup(): int
    {
        $days = Settings::int('log_days', 1, 3650);
        $cutoff = Clock::toDb(Clock::now()->modify('-' . $days . ' days'));
        return Capsule::table(self::TABLE)->where('is_stop', 0)->where('created_at', '<', $cutoff)->delete();
    }

    /** Stops spreadsheet programs from running cells that start with =, +, - or @ (CSV injection). */
    private static function neutraliseCsvFormulas(string $csv): string
    {
        return (string) preg_replace('/(^|,)("?)([=+\-@])/m', '$1$2\'$3', $csv);
    }
}
