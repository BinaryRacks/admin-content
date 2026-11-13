<?php
/**
 * Dashboard tab: is the module set up, is the cron running, and what happened in the last 24 hours.
 * Variables: $configured, $hasAppSecret, $hasVerify, $fieldId, $webhookUrl, $queue, $last24, $lastRun, $e.
 */

if (!defined('WHMCS')) {
    exit;
}
$yes = '<span class="brwa-ok">Yes</span>';
$no = '<span class="brwa-bad">No</span>';
?>
<div class="brwa-box">
    <h3>Set-up check</h3>
    <table>
        <tr><th>Meta Phone Number ID and access token saved</th><td><?= $configured ? $yes : $no ?></td></tr>
        <tr><th>App secret saved (needed to trust the webhook)</th><td><?= $hasAppSecret ? $yes : $no ?></td></tr>
        <tr><th>Webhook verify token saved</th><td><?= $hasVerify ? $yes : $no ?></td></tr>
        <tr><th>Client field <code>disable_whatsapp</code> exists</th><td><?= $fieldId !== null ? $yes . ' (field #' . $e($fieldId) . ')' : $no ?></td></tr>
        <tr><th>Webhook address for Meta</th><td><code><?= $e($webhookUrl) ?></code></td></tr>
        <tr><th>Last time the WHMCS cron sent the queue (UTC)</th><td><?= $lastRun !== '' ? $e($lastRun) : $no ?></td></tr>
    </table>
</div>
<div class="brwa-box">
    <h3>Queue now</h3>
    <table>
        <tr><th>Status</th><th>Messages</th></tr>
        <?php foreach (['pending' => 'Waiting', 'sending' => 'Sending', 'paused' => 'Paused (campaign)'] as $key => $label): ?>
            <tr><td><?= $e($label) ?></td><td><?= $e($queue[$key] ?? 0) ?></td></tr>
        <?php endforeach; ?>
    </table>
    <h3>Last 24 hours</h3>
    <table>
        <tr><th>Status</th><th>Messages</th></tr>
        <?php foreach (['sent', 'delivered', 'read', 'failed', 'skipped', 'stop'] as $key): ?>
            <tr><td><?= $e(ucfirst($key)) ?></td><td><?= $e($last24[$key] ?? 0) ?></td></tr>
        <?php endforeach; ?>
    </table>
</div>
