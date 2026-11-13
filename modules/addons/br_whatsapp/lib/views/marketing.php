<?php
/**
 * Bulk marketing tab: start a campaign with an enabled marketing message, and pause, resume or cancel campaigns.
 * Variables: $link, $csrf, $csrfOnce, $messages, $recipients, $campaigns, $dailyCap, $labels, $e.
 */

if (!defined('WHMCS')) {
    exit;
}
?>
<div class="brwa-box">
    <h3>Start a campaign</h3>
    <p><strong><?= $e($recipients) ?></strong> clients can receive marketing right now
        (active account, WHMCS marketing opt-in on, <code>disable_whatsapp</code> not ticked).</p>
    <p class="brwa-hint">Messages go out with the WHMCS cron, at most <?= $e($dailyCap) ?> a day, only 08:00-18:00 in each client's time zone.
        Consent is checked again just before each message is sent.</p>
    <?php if ($messages === []): ?>
        <p class="brwa-bad">No marketing message is switched on. Add one in "Manual &amp; marketing messages".</p>
    <?php else: ?>
        <form method="post" action="<?= $e($link . '&action=marketing') ?>" onsubmit="return confirm('Start sending this marketing message to <?= $e($recipients) ?> clients?');">
            <?= $csrfOnce ?>
            <input type="hidden" name="do" value="start">
            <select name="msg_key" required>
                <?php foreach ($messages as $m): ?>
                    <option value="<?= $e($m->msg_key) ?>"><?= $e($m->label) ?> (template <?= $e($m->template_name) ?>)</option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Start campaign</button>
        </form>
    <?php endif; ?>
</div>
<div class="brwa-box">
    <h3>Campaigns</h3>
    <table>
        <tr><th>#</th><th>Message</th><th>Status</th><th>Total</th><th>Sent</th><th>Failed</th><th>Skipped</th><th>Started (UTC)</th><th>Actions</th></tr>
        <?php foreach ($campaigns as $c): ?>
            <tr>
                <td><?= $e($c->id) ?></td>
                <td><?= $e($labels[$c->msg_key] ?? $c->msg_key) ?></td>
                <td><?= $e($c->status) ?></td>
                <td><?= $e($c->total) ?></td><td><?= $e($c->sent) ?></td><td><?= $e($c->failed) ?></td><td><?= $e($c->skipped) ?></td>
                <td><?= $e($c->created_at) ?></td>
                <td>
                    <?php foreach (['running' => ['pause', 'cancel'], 'paused' => ['resume', 'cancel']][$c->status] ?? [] as $do): ?>
                        <form method="post" action="<?= $e($link . '&action=marketing') ?>" style="display:inline" <?= $do === 'cancel' ? 'onsubmit="return confirm(\'Cancel this campaign?\');"' : '' ?>>
                            <?= $csrf ?>
                            <input type="hidden" name="do" value="<?= $e($do) ?>">
                            <input type="hidden" name="id" value="<?= $e($c->id) ?>">
                            <button type="submit" class="btn btn-default btn-sm"><?= $e(ucfirst($do)) ?></button>
                        </form>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($campaigns === []): ?><tr><td colspan="9">No campaigns yet.</td></tr><?php endif; ?>
    </table>
</div>
