<?php
/**
 * Log & GDPR tab: search the message log, export one client's data (right of access)
 * and delete one client's data (right to erasure). Only masked numbers are ever shown.
 * Variables: $link, $csrf, $filters, $page, $total, $rows, $export, $statuses, $labels, $e.
 */

if (!defined('WHMCS')) {
    exit;
}
$pages = max(1, (int) ceil($total / 50));
$query = static fn (int $p): string => $link . '&action=log&' . http_build_query(array_filter($filters) + ['page' => $p]);
?>
<div class="brwa-box">
    <form method="get" action="addonmodules.php">
        <input type="hidden" name="module" value="br_whatsapp">
        <input type="hidden" name="action" value="log">
        Client ID <input type="number" name="client_id" value="<?= $e($filters['client_id'] ?: '') ?>" min="1" style="width:90px">
        Status <select name="status"><option value="">Any</option>
            <?php foreach ($statuses as $s): ?><option value="<?= $e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= $e($s) ?></option><?php endforeach; ?>
        </select>
        From <input type="date" name="from" value="<?= $e($filters['from']) ?>">
        To <input type="date" name="to" value="<?= $e($filters['to']) ?>">
        <button type="submit" class="btn btn-default btn-sm">Search</button>
    </form>
</div>
<table>
    <tr><th>Date (UTC)</th><th>Client</th><th>Phone</th><th>Message</th><th>Source</th><th>Status</th><th>Reason / error</th></tr>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= $e($r->created_at) ?></td>
            <td><?php if ($r->client_id): ?><a href="clientssummary.php?userid=<?= $e((int) $r->client_id) ?>">#<?= $e($r->client_id) ?></a><?php else: ?>-<?php endif; ?></td>
            <td><?= $e($r->phone_masked) ?></td>
            <td><?= $e($labels[$r->msg_key] ?? $r->msg_key) ?><?= $r->related_id ? ' (#' . $e($r->related_id) . ')' : '' ?></td>
            <td><?= $e($r->source) ?></td>
            <td><?= $e($r->status) ?><?= (int) $r->is_stop === 1 ? ' <span class="brwa-hint">(kept as opt-out proof)</span>' : '' ?></td>
            <td><?= $e(trim(($r->error_code ?? '') . ' ' . ($r->error_text ?? ''))) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="7">Nothing found.</td></tr><?php endif; ?>
</table>
<p><?= $e($total) ?> entries. Page <?= $e($page) ?> of <?= $e($pages) ?>.
    <?php if ($page > 1): ?><a href="<?= $e($query($page - 1)) ?>">Previous</a><?php endif; ?>
    <?php if ($page < $pages): ?><a href="<?= $e($query($page + 1)) ?>">Next</a><?php endif; ?>
</p>

<div class="brwa-box">
    <h3>GDPR tools</h3>
    <p class="brwa-hint">The log keeps a masked number, which message and its status, never message text. Normal entries are deleted automatically;
        STOP records are kept as proof of opt-out until the client is deleted or erased.</p>
    <form method="post" action="<?= $e($link . '&action=log') ?>" style="margin-bottom:8px">
        <?= $csrf ?>
        <input type="hidden" name="do" value="export">
        Client ID <input type="number" name="gdpr_client_id" min="1" required style="width:90px">
        <button type="submit" class="btn btn-default btn-sm">Export this client's WhatsApp data</button>
    </form>
    <?php if ($export !== null): ?>
        <p class="brwa-ok"><a href="<?= $e($export['uri']) ?>" download="whatsapp-data-client-<?= $e($export['client_id']) ?>.csv">Download CSV for client #<?= $e($export['client_id']) ?></a></p>
    <?php endif; ?>
    <form method="post" action="<?= $e($link . '&action=log') ?>" onsubmit="return confirm('Delete all WhatsApp data for this client, including STOP proof?');">
        <?= $csrf ?>
        <input type="hidden" name="do" value="erase">
        Client ID <input type="number" name="gdpr_client_id" min="1" required style="width:90px">
        Type <code>ERASE</code> <input type="text" name="confirm" size="7" maxlength="5" required>
        <button type="submit" class="btn btn-danger btn-sm">Delete this client's WhatsApp data</button>
    </form>
</div>
