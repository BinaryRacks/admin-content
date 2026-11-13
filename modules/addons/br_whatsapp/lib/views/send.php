<?php
/**
 * "Send WhatsApp" page for one client, opened from the link on the admin client summary page.
 * Step 1: choose a manual message (and an unpaid invoice if it needs one) and preview.
 * Step 2: confirm and send (one-time code, so refreshing never sends twice).
 * Variables: $link, $csrf, $csrfOnce, $client, $messages, $invoices, $preview, $chosenKey, $chosenInvoice, $phoneMasked, $blocked, $e.
 */

if (!defined('WHMCS')) {
    exit;
}
$self = $link . '&action=send&userid=' . (int) $client->id;
?>
<div class="brwa-box">
    <h3>Send WhatsApp to <a href="clientssummary.php?userid=<?= $e((int) $client->id) ?>"><?= $e(trim($client->firstname . ' ' . $client->lastname)) ?></a>
        <?= $client->companyname !== '' ? '(' . $e($client->companyname) . ')' : '' ?></h3>
    <p>Number: <?= $phoneMasked !== null ? '<code>' . $e($phoneMasked) . '</code>' : '<span class="brwa-bad">No valid mobile number on the profile.</span>' ?></p>
    <?php if ($blocked !== null): ?>
        <p class="brwa-bad">This client can't receive WhatsApp messages (<?= $e($blocked) ?>).</p>
    <?php endif; ?>
    <p class="brwa-hint">Outside 08:00-18:00 in the client's time zone, the message waits until their morning.</p>

    <?php if ($messages === []): ?>
        <p class="brwa-bad">No manual message is switched on.</p>
    <?php else: ?>
        <form method="post" action="<?= $e($self) ?>">
            <?= $csrf ?>
            <input type="hidden" name="do" value="preview">
            Message <select name="msg_key" required>
                <?php foreach ($messages as $m): ?>
                    <option value="<?= $e($m->msg_key) ?>" <?= $m->msg_key === $chosenKey ? 'selected' : '' ?>><?= $e($m->label) ?></option>
                <?php endforeach; ?>
            </select>
            Invoice (if the message needs one) <select name="invoice_id">
                <option value="0">-</option>
                <?php foreach ($invoices as $id => $inv): ?>
                    <option value="<?= $e($id) ?>" <?= $id === $chosenInvoice ? 'selected' : '' ?>>
                        #<?= $e($inv->invoicenum !== '' ? $inv->invoicenum : $id) ?>, due <?= $e($inv->duedate) ?>, <?= $e($inv->total) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-default btn-sm">Preview</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($preview !== null): ?>
    <div class="brwa-box">
        <h3>Preview</h3>
        <p>Template <code><?= $e($preview['message']->template_name) ?></code>, language <code><?= $e($preview['language']) ?></code></p>
        <table>
            <tr><th>Blank</th><th>Detail</th><th>Value</th></tr>
            <?php foreach ($preview['message']->paramList as $i => $key): ?>
                <tr><td>{{<?= $e($i + 1) ?>}}</td><td><?= $e($key) ?></td><td><?= $e($preview['values'][$i] ?? '') ?></td></tr>
            <?php endforeach; ?>
        </table>
        <form method="post" action="<?= $e($self) ?>">
            <?= $csrfOnce ?>
            <input type="hidden" name="do" value="send">
            <input type="hidden" name="msg_key" value="<?= $e($preview['message']->msg_key) ?>">
            <input type="hidden" name="invoice_id" value="<?= $e($preview['invoice']) ?>">
            <button type="submit" class="btn btn-primary">Send WhatsApp</button>
        </form>
    </div>
<?php endif; ?>
