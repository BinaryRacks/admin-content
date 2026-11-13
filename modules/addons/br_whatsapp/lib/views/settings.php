<?php
/**
 * Settings tab: Meta connection details, secrets (typed in, never shown again), sending limits,
 * log retention, which admin roles may do each job, and "Delete data on deactivate".
 * Variables: $link, $csrf, $values, $secrets, $roles, $chosen, $webhookUrl, $e.
 */

if (!defined('WHMCS')) {
    exit;
}

/** Prints a password box for a secret: shows only whether one is saved, never the value. */
$secretField = static function (string $key, string $label, bool $saved) use ($e): void {
    ?>
    <tr><th><?= $e($label) ?></th><td>
        <input type="password" name="<?= $e($key) ?>" value="" size="50" maxlength="1024" autocomplete="new-password"
               placeholder="<?= $saved ? 'Saved (hidden). Leave empty to keep it.' : 'Not saved yet' ?>">
        <?php if ($saved): ?>
            <label class="brwa-hint"><input type="checkbox" name="clear_<?= $e($key) ?>" value="1"> Remove</label>
        <?php endif; ?>
    </td></tr>
    <?php
};
?>
<form method="post" action="<?= $e($link . '&action=settings') ?>">
    <?= $csrf ?>
    <input type="hidden" name="do" value="save">
    <div class="brwa-box">
        <h3>Meta WhatsApp Cloud API</h3>
        <table>
            <tr><th>Phone Number ID</th><td><input type="text" name="phone_number_id" value="<?= $e($values['phone_number_id']) ?>" size="24" maxlength="20" pattern="\d{5,20}"></td></tr>
            <tr><th>WhatsApp Business Account ID</th><td><input type="text" name="waba_id" value="<?= $e($values['waba_id']) ?>" size="24" maxlength="20" pattern="\d{5,20}"></td></tr>
            <?php $secretField('access_token', 'Access token (permanent system-user token)', $secrets['access_token']); ?>
            <?php $secretField('app_secret', 'App secret (checks webhook signatures)', $secrets['app_secret']); ?>
            <?php $secretField('verify_token', 'Webhook verify token (you choose it, 16+ characters)', $secrets['verify_token']); ?>
            <tr><th>Graph API version</th><td><input type="text" name="api_version" value="<?= $e($values['api_version']) ?>" size="8" maxlength="6"></td></tr>
            <tr><th>Webhook address (paste into Meta)</th><td><code><?= $e($webhookUrl) ?></code></td></tr>
        </table>
        <p class="brwa-hint">If you change the Phone Number ID or Business Account ID, the access token must be typed again.</p>
    </div>
    <div class="brwa-box">
        <h3>Sending</h3>
        <table>
            <tr><th>Language for all messages</th><td><input type="text" name="language" value="<?= $e($values['language']) ?>" size="8" maxlength="16"> <span class="brwa-hint">e.g. en or en_GB</span></td></tr>
            <tr><th>Messages per cron run</th><td><input type="number" name="batch_size" value="<?= $e($values['batch_size']) ?>" min="1" max="500"></td></tr>
            <tr><th>Marketing messages per day (cap)</th><td><input type="number" name="daily_cap" value="<?= $e($values['daily_cap']) ?>" min="1" max="1000000"></td></tr>
            <tr><th>"Domain expiring" notice, days before expiry</th><td><input type="number" name="domain_expiry_days" value="<?= $e($values['domain_expiry_days']) ?>" min="1" max="90"></td></tr>
            <tr><th>Keep log entries for (days)</th><td><input type="number" name="log_days" value="<?= $e($values['log_days']) ?>" min="1" max="3650"> <span class="brwa-hint">STOP records are kept until the client is deleted.</span></td></tr>
        </table>
    </div>
    <div class="brwa-box">
        <h3>Who may do what</h3>
        <p class="brwa-hint">Full Administrators can always do everything. "Send to one client" with nothing ticked = every role that can open this module.</p>
        <table>
            <tr><th>Role</th><th>Send to one client</th><th>Bulk marketing</th><th>Settings, messages, log &amp; GDPR</th></tr>
            <?php foreach ($roles as $id => $name): ?>
                <tr><td><?= $e($name) ?></td>
                    <?php foreach (['send', 'bulk', 'settings'] as $job): ?>
                        <td><input type="checkbox" name="roles_<?= $e($job) ?>[]" value="<?= $e($id) ?>"
                            <?= in_array($id, $chosen[$job], true) || $id === 1 ? 'checked' : '' ?> <?= $id === 1 ? 'disabled' : '' ?>></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <div class="brwa-box">
        <label><input type="checkbox" name="delete_on_deactivate" value="1" <?= $values['delete_on_deactivate'] === '1' ? 'checked' : '' ?>>
            Delete data on deactivate</label>
        <p class="brwa-hint">Off = deactivating keeps all settings, messages and logs. The client field disable_whatsapp is never deleted.</p>
    </div>
    <button type="submit" class="btn btn-primary">Save settings</button>
</form>
