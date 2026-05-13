<?php
/**
 * Backstage — Newsletter edit page (Wave E Task E5).
 *
 * MVP block-composer: locale tabs (fi_FI / en_GB / sw_TZ), a palette of 7 block
 * kinds, a per-block typed property form, and up/down reorder controls. No
 * drag-and-drop — that lands in 1.x. Audience filter + send-queue at the bottom.
 *
 * Page is a shell; all interactive behaviour lives in newsletter-blocks.js.
 * Data source: GET /api/backstage/communications/newsletters (filters by id
 * client-side) + PATCH for autosave + POST .../send for queueing.
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.communications.newsletters';
$activePage  = 'communications-newsletters';

$newsletterId = isset($_GET['id']) ? (string) $_GET['id'] : '';

$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.communications') ?: 'Communications'],
    [
        'label' => I18n::t('backstage.title.communications.newsletters') ?: 'Uutiskirjeet',
        'href'  => '/backstage/communications/newsletters',
    ],
    ['label' => I18n::t('backstage.common.edit') ?: 'Muokkaa'],
];

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="comms-newsletter-edit" data-newsletter-id="<?= $esc($newsletterId) ?>">

<div class="page-header">
    <div>
        <h1 class="page-header__title">
            <span id="comms-newsletter-name"><?= $esc(I18n::t('backstage.common.loading') ?: 'Ladataan…') ?></span>
        </h1>
        <p class="page-header__meta" id="comms-newsletter-meta"></p>
    </div>
    <div class="comms-newsletter-edit__autosave">
        <span id="comms-autosave-indicator" class="status-badge status-badge--draft">
            <?= $esc(I18n::t('communications.newsletter.autosave.idle') ?: 'Tallennettu') ?>
        </span>
    </div>
</div>

<section class="comms-newsletter-edit__topbar">
    <label class="comms-newsletter-edit__topbar-field">
        <span><?= $esc(I18n::t('communications.newsletter.field.internal_name') ?: 'Sisäinen nimi') ?></span>
        <input type="text" id="comms-newsletter-internal-name" value="">
    </label>
    <div class="comms-newsletter-edit__locale-tabs" id="comms-locale-tabs">
        <button type="button" class="comms-locale-tab is-active" data-locale="fi_FI">fi_FI</button>
        <button type="button" class="comms-locale-tab" data-locale="en_GB">en_GB</button>
        <button type="button" class="comms-locale-tab" data-locale="sw_TZ">sw_TZ</button>
    </div>
    <label class="comms-newsletter-edit__topbar-field comms-newsletter-edit__topbar-field--subject">
        <span><?= $esc(I18n::t('communications.newsletter.field.subject') ?: 'Otsikko') ?> <span class="comms-locale-hint" id="comms-subject-locale-hint">fi_FI</span></span>
        <input type="text" id="comms-newsletter-subject" value="">
    </label>
</section>

<section class="comms-newsletter-edit__body">
    <aside class="comms-newsletter-edit__palette" aria-label="Block palette">
        <h3><?= $esc(I18n::t('communications.newsletter.palette.title') ?: 'Lohkot') ?></h3>
        <div class="comms-palette__buttons">
            <button type="button" class="comms-palette-btn" data-block-kind="heading">
                <?= $esc(I18n::t('communications.newsletter.block.heading') ?: 'Otsikko') ?>
            </button>
            <button type="button" class="comms-palette-btn" data-block-kind="paragraph">
                <?= $esc(I18n::t('communications.newsletter.block.paragraph') ?: 'Kappale') ?>
            </button>
            <button type="button" class="comms-palette-btn" data-block-kind="image">
                <?= $esc(I18n::t('communications.newsletter.block.image') ?: 'Kuva') ?>
            </button>
            <button type="button" class="comms-palette-btn" data-block-kind="button">
                <?= $esc(I18n::t('communications.newsletter.block.button') ?: 'Painike') ?>
            </button>
            <button type="button" class="comms-palette-btn" data-block-kind="divider">
                <?= $esc(I18n::t('communications.newsletter.block.divider') ?: 'Erotin') ?>
            </button>
            <button type="button" class="comms-palette-btn" data-block-kind="two_columns">
                <?= $esc(I18n::t('communications.newsletter.block.two_columns') ?: '2 saraketta') ?>
            </button>
            <button type="button" class="comms-palette-btn" data-block-kind="event_card">
                <?= $esc(I18n::t('communications.newsletter.block.event_card') ?: 'Tapahtumakortti') ?>
            </button>
        </div>
    </aside>

    <main class="comms-newsletter-edit__canvas" aria-label="Canvas">
        <h3>
            <?= $esc(I18n::t('communications.newsletter.canvas.title') ?: 'Sisältö') ?>
            <span class="comms-locale-hint" id="comms-canvas-locale-hint">fi_FI</span>
        </h3>
        <div id="comms-canvas-list" class="comms-canvas-list">
            <p class="comms-canvas-empty">
                <?= $esc(I18n::t('communications.newsletter.canvas.empty') ?: 'Aloita lisäämällä lohko vasemmalta.') ?>
            </p>
        </div>
    </main>

    <aside class="comms-newsletter-edit__properties" aria-label="Block properties">
        <h3><?= $esc(I18n::t('communications.newsletter.properties.title') ?: 'Lohkon asetukset') ?></h3>
        <div id="comms-properties-pane">
            <p class="comms-muted">
                <?= $esc(I18n::t('communications.newsletter.properties.empty') ?: 'Valitse lohko muokataksesi asetuksia.') ?>
            </p>
        </div>
    </aside>
</section>

<section class="comms-newsletter-edit__bottombar">
    <h3><?= $esc(I18n::t('communications.newsletter.audience.title') ?: 'Vastaanottajaryhmä') ?></h3>
    <div class="comms-audience-row">
        <label>
            <span><?= $esc(I18n::t('communications.newsletter.audience.membership_types') ?: 'Jäsenyystyypit (CSV)') ?></span>
            <input type="text" id="comms-audience-membership" value="" placeholder="regular,student,honorary">
        </label>
        <label>
            <span><?= $esc(I18n::t('communications.newsletter.audience.locales') ?: 'Kielet (CSV)') ?></span>
            <input type="text" id="comms-audience-locales" value="" placeholder="fi_FI,en_GB,sw_TZ">
        </label>
        <label>
            <span><?= $esc(I18n::t('communications.newsletter.audience.joined_within') ?: 'Liittynyt') ?></span>
            <select id="comms-audience-joined">
                <option value=""><?= $esc(I18n::t('backstage.common.any') ?: 'Mikä tahansa') ?></option>
                <option value="last_30_days"><?= $esc(I18n::t('communications.newsletter.audience.last_30_days') ?: 'Viim. 30 päivää') ?></option>
                <option value="last_90_days"><?= $esc(I18n::t('communications.newsletter.audience.last_90_days') ?: 'Viim. 90 päivää') ?></option>
                <option value="last_1_year"><?= $esc(I18n::t('communications.newsletter.audience.last_1_year') ?: 'Viim. vuosi') ?></option>
            </select>
        </label>
    </div>
    <div class="comms-newsletter-edit__send-actions">
        <button type="button" id="comms-newsletter-send" class="btn btn--primary">
            <?= $esc(I18n::t('communications.newsletter.action.send') ?: 'Lähetä jonoon') ?>
        </button>
        <button type="button" id="comms-newsletter-delete" class="btn btn--danger">
            <?= $esc(I18n::t('backstage.common.delete') ?: 'Poista') ?>
        </button>
    </div>
</section>

</div>

<link rel="stylesheet" href="/modules/communications/assets/newsletter-blocks.css">
<script
    src="/modules/communications/assets/newsletter-blocks.js"
    data-mode="edit"
    data-newsletter-id="<?= $esc($newsletterId) ?>"
    data-list-href="/backstage/communications/newsletters"
    data-autosave-saving="<?= $esc(I18n::t('communications.newsletter.autosave.saving') ?: 'Tallennetaan…') ?>"
    data-autosave-saved="<?= $esc(I18n::t('communications.newsletter.autosave.idle') ?: 'Tallennettu') ?>"
    data-autosave-error="<?= $esc(I18n::t('communications.newsletter.autosave.error') ?: 'Tallennusvirhe') ?>"
    data-send-confirm="<?= $esc(I18n::t('communications.newsletter.send.confirm') ?: 'Lähetetään uutiskirje. Vahvista.') ?>"
    data-delete-confirm="<?= $esc(I18n::t('communications.newsletter.delete.confirm') ?: 'Poistetaanko uutiskirje?') ?>"
    data-error-load="<?= $esc(I18n::t('communications.newsletter.error.load') ?: 'Uutiskirjeen lataus epäonnistui.') ?>"
    data-error-not-found="<?= $esc(I18n::t('communications.newsletter.error.not_found') ?: 'Uutiskirjettä ei löytynyt.') ?>"
    data-send-success="<?= $esc(I18n::t('communications.newsletter.send.success') ?: 'Uutiskirje siirretty jonoon.') ?>"
    data-send-error="<?= $esc(I18n::t('communications.newsletter.send.error') ?: 'Lähetys epäonnistui.') ?>"
    data-properties-empty="<?= $esc(I18n::t('communications.newsletter.properties.empty') ?: 'Valitse lohko muokataksesi asetuksia.') ?>"
    data-label-up="<?= $esc(I18n::t('communications.newsletter.action.move_up') ?: 'Siirrä ylös') ?>"
    data-label-down="<?= $esc(I18n::t('communications.newsletter.action.move_down') ?: 'Siirrä alas') ?>"
    data-label-remove="<?= $esc(I18n::t('communications.newsletter.action.remove') ?: 'Poista') ?>"
    defer></script>
<?php
$pageContent = ob_get_clean();
require DAEMS_SITE_PUBLIC . '/pages/layout.php';
