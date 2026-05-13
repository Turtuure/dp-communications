<?php
/**
 * Backstage — Communications Template per-kind editor (Wave D Task D8).
 *
 * URL contract:
 *   /backstage/communications/templates-edit?kind=<mail-kind>
 *
 * Page shows 3 locale tabs (fi_FI / en_GB / sw_TZ) and 4 admin-editable
 * fields per locale:
 *   - subject
 *   - intro_text  (textarea, Markdown)
 *   - signature
 *   - footer
 *
 * Save button → PUT /api/backstage/communications/templates/{kind}/{locale}.
 * The page fetches each locale independently the first time its tab is
 * activated; switching tabs doesn't refetch (state is held in the JS
 * controller until the operator saves or navigates away).
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$kindRaw = isset($_GET['kind']) ? (string) $_GET['kind'] : 'meeting_invitation';
$allowedKinds = ['meeting_invitation', 'payment_reminder', 'membership_approved', 'group_message'];
if (!in_array($kindRaw, $allowedKinds, true)) {
    $kindRaw = 'meeting_invitation';
}

$pageTitle   = 'backstage.title.communications.templates';
$activePage  = 'communications-templates';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.communications') ?: 'Communications'],
    ['label' => I18n::t('backstage.title.communications.templates') ?: 'Viestipohjat', 'href' => '/backstage/communications/templates'],
    ['label' => I18n::t('communications.kind.' . $kindRaw) ?: $kindRaw],
];

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$locales = [
    'fi_FI' => 'Suomi',
    'en_GB' => 'English',
    'sw_TZ' => 'Kiswahili',
];

ob_start();
?>
<div class="comms-template-edit" data-kind="<?= $esc($kindRaw) ?>">

<div class="page-header">
    <div>
        <h1 class="page-header__title">
            <?= I18n::e('backstage.title.communications.templates') ?> — <?= $esc(I18n::t('communications.kind.' . $kindRaw) ?: $kindRaw) ?>
        </h1>
    </div>
</div>

<div class="comms-template-edit__tabs" role="tablist">
    <?php $first = true; foreach ($locales as $code => $label): ?>
        <button type="button"
                class="tab-button <?= $first ? 'tab-active' : '' ?>"
                data-locale-tab="<?= $esc($code) ?>"
                role="tab"
                aria-selected="<?= $first ? 'true' : 'false' ?>"><?= $esc($label) ?></button>
        <?php $first = false; endforeach; ?>
</div>

<form class="comms-template-edit__form" id="comms-template-form">
    <?php $first = true; foreach ($locales as $code => $_label): ?>
        <section class="tab-pane <?= $first ? 'tab-active' : '' ?>"
                 data-locale-pane="<?= $esc($code) ?>"
                 <?= $first ? '' : 'hidden' ?>>
            <label class="comms-settings__field">
                <span><?= I18n::e('communications.template.subject') ?></span>
                <input type="text" data-field="subject" data-locale="<?= $esc($code) ?>" name="subject_<?= $esc($code) ?>">
            </label>
            <label class="comms-settings__field">
                <span><?= I18n::e('communications.template.intro') ?></span>
                <textarea rows="6" data-field="intro_text" data-locale="<?= $esc($code) ?>" name="intro_text_<?= $esc($code) ?>"></textarea>
                <small class="comms-settings__hint">Markdown</small>
            </label>
            <label class="comms-settings__field">
                <span><?= I18n::e('communications.template.signature') ?></span>
                <input type="text" data-field="signature" data-locale="<?= $esc($code) ?>" name="signature_<?= $esc($code) ?>">
            </label>
            <label class="comms-settings__field">
                <span><?= I18n::e('communications.template.footer') ?></span>
                <input type="text" data-field="footer" data-locale="<?= $esc($code) ?>" name="footer_<?= $esc($code) ?>">
            </label>
        </section>
        <?php $first = false; endforeach; ?>

    <div class="comms-settings__actions">
        <button type="submit" class="btn btn--primary"><?= I18n::e('backstage.common.save') ?: 'Tallenna' ?></button>
        <span class="comms-settings__last-saved" id="comms-template-last-saved"></span>
    </div>
</form>

</div>

<link rel="stylesheet" href="/modules/communications/assets/communications.css">
<script
    src="/modules/communications/assets/templates.js"
    data-kind="<?= $esc($kindRaw) ?>"
    data-saved-label="<?= $esc(I18n::t('backstage.common.saved') ?: 'Tallennettu') ?>"
    defer></script>
<?php
$pageContent = ob_get_clean();
require DAEMS_SITE_PUBLIC . '/pages/layout.php';
