<?php
/**
 * Page frame for every tab: Binary Racks header bar, tab menu, notices and the tab's content.
 * Variables: $link, $tabs, $current, $notices, $body (already-escaped HTML), $header (HTML), $e (escaper).
 */

if (!defined('WHMCS')) {
    exit;
}
?>
<style>
.brwa{font-family:-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:#1d2530;max-width:1200px}
.brwa-header{display:flex;align-items:center;gap:14px;padding:10px 16px;background:#f4f7fb;border:1px solid #d9e1ec;border-radius:6px;margin-bottom:12px;flex-wrap:wrap}
.brwa-title{font-size:18px;font-weight:600}
.brwa-version{font-size:12px;color:#fff;background:#0b3d91;border-radius:10px;padding:2px 8px}
.brwa-tabs{display:flex;flex-wrap:wrap;gap:4px;border-bottom:2px solid #d9e1ec;margin-bottom:14px;padding:0;list-style:none}
.brwa-tabs a{display:block;padding:8px 14px;border-radius:6px 6px 0 0;text-decoration:none;color:#0b3d91}
.brwa-tabs a.active{background:#0b3d91;color:#fff}
.brwa-notice{padding:9px 12px;border-radius:5px;margin-bottom:8px;border:1px solid}
.brwa-notice.success{background:#eaf7ee;border-color:#9fd5ad}
.brwa-notice.danger{background:#fdecec;border-color:#f0a3a3}
.brwa-notice.info{background:#eef4fd;border-color:#a8c3ef}
.brwa table{border-collapse:collapse;width:100%;margin-bottom:14px}
.brwa th,.brwa td{border:1px solid #d9e1ec;padding:6px 8px;text-align:left;vertical-align:top}
.brwa th{background:#f4f7fb}
.brwa .brwa-box{border:1px solid #d9e1ec;border-radius:6px;padding:12px 14px;margin-bottom:14px}
.brwa input[type=text],.brwa input[type=password],.brwa input[type=number],.brwa select{max-width:100%;padding:4px 6px}
.brwa .brwa-hint{color:#5b6675;font-size:12px}
.brwa .brwa-ok{color:#1d7a3a;font-weight:600}
.brwa .brwa-bad{color:#b3261e;font-weight:600}
.brwa code{background:#f4f7fb;padding:1px 4px;border-radius:3px;word-break:break-all}
</style>
<div class="brwa">
    <?= $header ?>
    <ul class="brwa-tabs">
        <?php foreach ($tabs as $key => $label): ?>
            <li><a class="<?= $key === $current ? 'active' : '' ?>" href="<?= $e($link . '&action=' . $key) ?>"><?= $e($label) ?></a></li>
        <?php endforeach; ?>
    </ul>
    <?php foreach ($notices as [$type, $text]): ?>
        <div class="brwa-notice <?= $e($type) ?>"><?= $e($text) ?></div>
    <?php endforeach; ?>
    <?= $body ?>
</div>
