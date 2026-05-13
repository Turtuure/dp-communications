<?php
/**
 * Backstage — Newsletters list (Wave E Task E5).
 *
 * Lists existing newsletter drafts + sent newsletters for the active tenant
 * and exposes a "Uusi uutiskirje" button that POSTs to create + redirects to
 * the edit page.
 *
 * Data source: GET /api/backstage/communications/newsletters (proxy → platform
 * GET /api/v1/backstage/communications/newsletters).
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.communications.newsletters';
$activePage  = 'communications-newsletters';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.communications') ?: 'Communications'],
    ['label' => I18n::t('backstage.title.communications.newsletters') ?: 'Uutiskirjeet'],
];

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="comms-newsletters">

<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= $esc(I18n::t('backstage.title.communications.newsletters') ?: 'Uutiskirjeet') ?></h1>
    </div>
    <div>
        <button id="comms-newsletters-create" class="btn btn--primary">
            <?= $esc(I18n::t('communications.newsletter.new') ?: 'Uusi uutiskirje') ?>
        </button>
    </div>
</div>

<section class="comms-newsletters__list-wrap">
    <table class="data-table comms-newsletters__list" id="comms-newsletters-table">
        <thead>
            <tr>
                <th><?= $esc(I18n::t('communications.newsletter.col.name') ?: 'Nimi') ?></th>
                <th><?= $esc(I18n::t('communications.newsletter.col.status') ?: 'Tila') ?></th>
                <th><?= $esc(I18n::t('communications.newsletter.col.created') ?: 'Luotu') ?></th>
                <th><?= $esc(I18n::t('communications.newsletter.col.sent') ?: 'Lähetetty') ?></th>
                <th><?= $esc(I18n::t('backstage.common.actions') ?: 'Toiminnot') ?></th>
            </tr>
        </thead>
        <tbody>
            <tr><td colspan="5"><?= $esc(I18n::t('backstage.common.loading') ?: 'Ladataan…') ?></td></tr>
        </tbody>
    </table>
</section>

</div>

<link rel="stylesheet" href="/modules/communications/assets/newsletter-blocks.css">
<script
    src="/modules/communications/assets/newsletter-blocks.js"
    data-mode="list"
    data-edit-href="/backstage/communications/newsletters-edit"
    data-status-draft="<?= $esc(I18n::t('communications.newsletter.status.draft') ?: 'Luonnos') ?>"
    data-status-scheduled="<?= $esc(I18n::t('communications.newsletter.status.scheduled') ?: 'Ajastettu') ?>"
    data-status-sent="<?= $esc(I18n::t('communications.newsletter.status.sent') ?: 'Lähetetty') ?>"
    data-empty-text="<?= $esc(I18n::t('communications.newsletter.empty') ?: 'Ei uutiskirjeitä.') ?>"
    data-edit-label="<?= $esc(I18n::t('backstage.common.edit') ?: 'Muokkaa') ?>"
    data-delete-label="<?= $esc(I18n::t('backstage.common.delete') ?: 'Poista') ?>"
    data-delete-confirm="<?= $esc(I18n::t('communications.newsletter.delete.confirm') ?: 'Poistetaanko uutiskirje?') ?>"
    data-create-prompt="<?= $esc(I18n::t('communications.newsletter.new.prompt') ?: 'Anna uutiskirjeen sisäinen nimi') ?>"
    defer></script>
<?php
$pageContent = ob_get_clean();
require DAEMS_SITE_PUBLIC . '/pages/layout.php';
