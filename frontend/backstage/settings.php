<?php
/**
 * Backstage — Communications Settings (Wave C8).
 *
 * Tabbed editor for the per-tenant `tenant_communication_settings` row:
 *   1. SMTP    — DSN + from / display / reply-to + "Lähetä testi" button
 *   2. Cron    — reminder pre-due / post-due lists / lapse warning window
 *   3. Brand   — logo URL / primary color / footer address
 *   4. Suppression — placeholder list (Wave G writes to it; UI ready)
 *
 * Data source: GET /api/backstage/communications/settings.
 * Update:      PUT /api/backstage/communications/settings.
 * SMTP test:   POST /api/backstage/communications/settings/smtp-test.
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.communications.settings';
$activePage  = 'communications-settings';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.communications') ?: 'Communications'],
    ['label' => I18n::t('backstage.title.communications.settings')],
];

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="comms-settings" id="comms-settings">

<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('backstage.title.communications.settings') ?></h1>
    </div>
</div>

<div class="comms-settings__tabs" role="tablist">
    <button type="button" class="tab-button tab-active" data-tab="smtp" role="tab" aria-selected="true">SMTP</button>
    <button type="button" class="tab-button" data-tab="cron" role="tab" aria-selected="false">Cron</button>
    <button type="button" class="tab-button" data-tab="brand" role="tab" aria-selected="false">Brand</button>
    <button type="button" class="tab-button" data-tab="suppression" role="tab" aria-selected="false">Suppression</button>
</div>

<form class="comms-settings__form" id="comms-settings-form">

    <!-- ── SMTP tab ─────────────────────────────────────────────── -->
    <section class="tab-pane tab-active" data-tab-pane="smtp">
        <label class="comms-settings__field">
            <span><?= I18n::e('communications.settings.smtp.dsn') ?></span>
            <input type="password"
                   name="smtp_dsn"
                   id="comms-smtp-dsn"
                   autocomplete="off"
                   placeholder="smtps://user:pass@smtp.example.com:465">
            <small class="comms-settings__hint" id="comms-dsn-state"></small>
        </label>
        <label class="comms-settings__field">
            <span><?= I18n::e('communications.settings.smtp.from') ?></span>
            <input type="email" name="mail_from_address" id="comms-mail-from" placeholder="noreply@example.com">
        </label>
        <label class="comms-settings__field">
            <span><?= I18n::e('communications.settings.smtp.display_name') ?></span>
            <input type="text" name="mail_display_name" id="comms-mail-display">
        </label>
        <label class="comms-settings__field">
            <span><?= I18n::e('communications.settings.smtp.reply_to') ?></span>
            <input type="email" name="mail_reply_to" id="comms-mail-reply-to" placeholder="reply@example.com">
        </label>

        <div class="comms-settings__smtp-test">
            <button type="button" class="btn btn--ghost" id="comms-smtp-test-btn"><?= I18n::e('communications.settings.smtp.test') ?></button>
            <span class="comms-settings__smtp-test-status" id="comms-smtp-test-status"></span>
        </div>
    </section>

    <!-- ── Cron tab ─────────────────────────────────────────────── -->
    <section class="tab-pane" data-tab-pane="cron" hidden>
        <label class="comms-settings__field">
            <span><?= I18n::e('communications.settings.cron.pre_due') ?></span>
            <input type="number" min="0" max="365" name="reminder_pre_due_days" id="comms-cron-pre">
        </label>
        <label class="comms-settings__field">
            <span><?= I18n::e('communications.settings.cron.post_due') ?></span>
            <input type="text" name="reminder_post_due_days" id="comms-cron-post" placeholder="14,30">
            <small class="comms-settings__hint"><?= $esc(I18n::t('backstage.common.csv_ints_hint') ?: 'Vrk-luvut pilkulla erotettuna, esim. 14,30') ?></small>
        </label>
        <label class="comms-settings__field">
            <span><?= I18n::e('communications.settings.cron.lapse') ?></span>
            <input type="number" min="0" max="365" name="lapse_warning_days_before" id="comms-cron-lapse">
        </label>
    </section>

    <!-- ── Brand tab ────────────────────────────────────────────── -->
    <section class="tab-pane" data-tab-pane="brand" hidden>
        <label class="comms-settings__field">
            <span><?= I18n::e('communications.settings.brand.logo') ?></span>
            <input type="url" name="brand_logo_url" id="comms-brand-logo" placeholder="https://example.com/logo.png">
        </label>
        <label class="comms-settings__field">
            <span><?= I18n::e('communications.settings.brand.color') ?></span>
            <input type="text" name="brand_primary_color" id="comms-brand-color" pattern="^#[0-9A-Fa-f]{6}$" placeholder="#0066cc">
        </label>
        <label class="comms-settings__field">
            <span><?= I18n::e('communications.settings.brand.footer') ?></span>
            <textarea name="brand_footer_address" id="comms-brand-footer" rows="3"></textarea>
        </label>
    </section>

    <!-- ── Suppression tab ──────────────────────────────────────── -->
    <section class="tab-pane" data-tab-pane="suppression" hidden>
        <p class="comms-settings__hint"><?= $esc(I18n::t('communications.suppression.empty') ?: 'Suppression-lista on tyhjä.') ?></p>
        <p class="comms-settings__hint comms-settings__placeholder">Wave G adds remove/manual-block buttons here.</p>
        <table class="data-table comms-settings__suppression-table">
            <thead>
                <tr>
                    <th><?= I18n::e('communications.outbox.filter.recipient') ?></th>
                    <th><?= I18n::e('backstage.common.reason') ?: 'Syy' ?></th>
                    <th><?= I18n::e('backstage.common.time') ?: 'Aika' ?></th>
                    <th><?= I18n::e('backstage.common.actions') ?: 'Toiminnot' ?></th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="4"><?= $esc(I18n::t('communications.suppression.empty') ?: 'Suppression-lista on tyhjä.') ?></td></tr>
            </tbody>
        </table>
    </section>

    <div class="comms-settings__actions">
        <button type="submit" class="btn btn--primary"><?= I18n::e('backstage.common.save') ?: 'Tallenna' ?></button>
        <span class="comms-settings__last-saved" id="comms-settings-last-saved"></span>
    </div>
</form>

</div>

<link rel="stylesheet" href="/modules/communications/assets/communications.css">
<script
    src="/modules/communications/assets/settings.js"
    data-saved-label="<?= $esc(I18n::t('backstage.common.saved') ?: 'Tallennettu') ?>"
    data-test-success-template="<?= $esc(I18n::t('communications.settings.smtp.test_success') ?: 'SMTP-testi onnistui :time') ?>"
    data-test-running-label="<?= $esc(I18n::t('backstage.common.loading') ?: 'Ladataan…') ?>"
    data-dsn-configured-label="<?= $esc(I18n::t('backstage.common.configured') ?: 'Konfiguroitu — jätä tyhjäksi pitääksesi nykyinen') ?>"
    data-dsn-empty-label="<?= $esc(I18n::t('backstage.common.not_configured') ?: 'Ei konfiguroitu') ?>"
    defer></script>
<?php
$pageContent = ob_get_clean();
require DAEMS_SITE_PUBLIC . '/pages/layout.php';
