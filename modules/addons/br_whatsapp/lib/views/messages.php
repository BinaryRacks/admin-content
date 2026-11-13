<?php
/**
 * Manual & marketing messages tab: add, edit, switch off and delete messages.
 * Manual messages are sent by hand from the client profile; marketing messages are used by bulk campaigns.
 * Variables: $link, $csrf, $manual, $marketing, $allowedManual, $allowedMarketing, $placeholders, $language, $e.
 */

if (!defined('WHMCS')) {
    exit;
}

/** Prints the edit form for one message (or the "add new" form when $m is null). */
$form = static function (?object $m, string $kind, array $allowed) use ($link, $csrf, $language, $e): void {
    ?>
    <form method="post" action="<?= $e($link . '&action=messages') ?>" style="margin-bottom:6px">
        <?= $csrf ?>
        <input type="hidden" name="do" value="save">
        <input type="hidden" name="id" value="<?= $e($m->id ?? 0) ?>">
        <input type="hidden" name="kind" value="<?= $e($kind) ?>">
        Name <input type="text" name="label" value="<?= $e($m->label ?? '') ?>" size="28" maxlength="100" required <?= ($m->builtin ?? false) ? 'readonly' : '' ?>>
        <label><input type="checkbox" name="enabled" value="1" <?= ($m === null || $m->enabled) ? 'checked' : '' ?>> On</label><br>
        Template name <input type="text" name="template_name" value="<?= $e($m->template_name ?? '') ?>" size="28" maxlength="512" required pattern="[a-z0-9_]+">
        Language <input type="text" name="language" value="<?= $e($m->language ?? '') ?>" size="6" maxlength="16" placeholder="<?= $e($language) ?>"><br>
        Blanks in order <input type="text" name="params" value="<?= $e(implode(', ', $m->paramList ?? [])) ?>" size="60" maxlength="1000"><br>
        <span class="brwa-hint">Allowed: <?= $e(implode(', ', $allowed)) ?></span><br>
        <button type="submit" class="btn btn-<?= $m === null ? 'primary' : 'default' ?> btn-sm"><?= $m === null ? 'Add message' : 'Save' ?></button>
    </form>
    <?php
};

/** Prints the delete and test buttons for one message. */
$tools = static function (object $m) use ($link, $csrf, $e): void {
    ?>
    <form method="post" action="<?= $e($link . '&action=messages') ?>" style="display:inline">
        <?= $csrf ?>
        <input type="hidden" name="do" value="test">
        <input type="hidden" name="id" value="<?= $e($m->id) ?>">
        <input type="text" name="test_number" placeholder="+447911123456" size="16" maxlength="20" required>
        <button type="submit" class="btn btn-default btn-sm">Send test</button>
    </form>
    <?php if (!$m->builtin): ?>
        <form method="post" action="<?= $e($link . '&action=messages') ?>" style="display:inline" onsubmit="return confirm('Delete this message?');">
            <?= $csrf ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= $e($m->id) ?>">
            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
        </form>
    <?php endif;
};
?>
<?php foreach (['manual' => ['Manual messages', 'Sent by hand with the "Send WhatsApp" link on a client\'s profile. Invoice details make the sender pick an unpaid invoice.', $manual, $allowedManual],
                'marketing' => ['Marketing messages', 'Used by Bulk marketing. Only client and company details are allowed.', $marketing, $allowedMarketing]] as $kind => [$title, $hint, $list, $allowed]): ?>
    <div class="brwa-box">
        <h3><?= $e($title) ?></h3>
        <p class="brwa-hint"><?= $e($hint) ?></p>
        <table>
            <?php foreach ($list as $m): ?>
                <tr><td><?php $form($m, $kind, $allowed); $tools($m); ?></td></tr>
            <?php endforeach; ?>
            <tr><th>Add a new <?= $e($kind) ?> message</th></tr>
            <tr><td><?php $form(null, $kind, $allowed); ?></td></tr>
        </table>
    </div>
<?php endforeach; ?>
