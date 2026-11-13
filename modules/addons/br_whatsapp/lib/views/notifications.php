<?php
/**
 * Notifications tab: switch whole categories on or off, and set up each automatic event
 * (on/off, Meta template name, language, which details fill the blanks, and a test send).
 * Variables: $link, $csrf, $categories, $events, $catalogue, $placeholders, $language, $e.
 */

if (!defined('WHMCS')) {
    exit;
}
?>
<div class="brwa-box">
    <h3>Categories</h3>
    <p class="brwa-hint">A message is sent only when both its category and the event itself are switched on.</p>
    <form method="post" action="<?= $e($link . '&action=notifications') ?>">
        <?= $csrf ?>
        <input type="hidden" name="do" value="categories">
        <?php foreach ($categories as $key => $category): ?>
            <label style="margin-right:18px">
                <input type="checkbox" name="categories[]" value="<?= $e($key) ?>" <?= $category['on'] ? 'checked' : '' ?>>
                <?= $e($category['label']) ?>
            </label>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-primary btn-sm">Save categories</button>
    </form>
</div>

<?php foreach ($catalogue as $catKey => $catLabel): ?>
    <div class="brwa-box">
        <h3><?= $e($catLabel) ?> <?= $categories[$catKey]['on'] ? '' : '<span class="brwa-bad">(category off)</span>' ?></h3>
        <table>
            <tr><th style="width:22%">Event</th><th>Settings</th><th style="width:24%">Send test</th></tr>
            <?php foreach ($events as $event): ?>
                <?php $m = $event['message']; if ($m->category !== $catKey) { continue; } ?>
                <tr>
                    <td>
                        <strong><?= $e($m->label) ?></strong><br>
                        <?= $event['critical'] ? '<span class="brwa-hint">Critical: sent at any hour.</span>' : '<span class="brwa-hint">Sent 08:00-18:00 client time.</span>' ?>
                    </td>
                    <td>
                        <form method="post" action="<?= $e($link . '&action=notifications') ?>">
                            <?= $csrf ?>
                            <input type="hidden" name="do" value="save">
                            <input type="hidden" name="id" value="<?= $e($m->id) ?>">
                            <input type="hidden" name="label" value="<?= $e($m->label) ?>">
                            <label><input type="checkbox" name="enabled" value="1" <?= $m->enabled ? 'checked' : '' ?>> On</label><br>
                            Template name <input type="text" name="template_name" value="<?= $e($m->template_name) ?>" size="28" maxlength="512" required pattern="[a-z0-9_]+">
                            Language <input type="text" name="language" value="<?= $e($m->language ?? '') ?>" size="6" maxlength="16" placeholder="<?= $e($language) ?>"><br>
                            Blanks in order <input type="text" name="params" value="<?= $e(implode(', ', $m->paramList)) ?>" size="60" maxlength="1000"><br>
                            <span class="brwa-hint">Allowed: <?= $e(implode(', ', $event['allowed'])) ?></span><br>
                            <button type="submit" class="btn btn-default btn-sm">Save</button>
                        </form>
                    </td>
                    <td>
                        <form method="post" action="<?= $e($link . '&action=notifications') ?>">
                            <?= $csrf ?>
                            <input type="hidden" name="do" value="test">
                            <input type="hidden" name="id" value="<?= $e($m->id) ?>">
                            <input type="text" name="test_number" placeholder="+447911123456" size="16" maxlength="20" required>
                            <button type="submit" class="btn btn-default btn-sm">Send test</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
<?php endforeach; ?>

<div class="brwa-box">
    <h3>Details that can fill blanks</h3>
    <table>
        <tr><th>Key</th><th>Meaning</th><th>Example</th></tr>
        <?php foreach ($placeholders as $key => [$label, , $sample]): ?>
            <tr><td><code><?= $e($key) ?></code></td><td><?= $e($label) ?></td><td><?= $e($sample) ?></td></tr>
        <?php endforeach; ?>
    </table>
</div>
