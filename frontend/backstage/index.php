<?php
/**
 * Backstage — Communications Composer (Wave D Task D7).
 *
 * Hybrid layout (Q6 F-hybrid mockup):
 *   - Top bar           — kind dropdown + locale tabs
 *   - Left form panel   — typed fields per kind (subject + intro_text + signature)
 *                         plus kind-specific selectors (meeting / invoice / application)
 *   - Audience sidebar  — membership-types + locales + joined-within filter
 *                         (visible for ad-hoc kinds, hidden for invoice / meeting forms
 *                         that derive their recipient list from the linked record)
 *   - Right preview     — HTML iframe + plain-text fallback below
 *   - Bottom actions    — "Esikatselu" (debounced auto-refresh) + "Lähetä testi
 *                         itselle" + "Lähetä jonoon"
 *
 * Data flow:
 *   1. composer.js builds a payload from the visible fields on every change.
 *   2. POST /api/backstage/communications/preview (debounced 300 ms) → render
 *      response into iframe + audience-count badge.
 *   3. "Lähetä jonoon" → POST /api/backstage/communications/send → success
 *      toast + redirect to /backstage/communications/outbox.
 *
 * Auth is enforced by `_guard.php` upstream (router.php) — this file assumes
 * a logged-in tenant admin / moderator. The backend use cases re-check role.
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.communications.compose';
$activePage  = 'communications-compose';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.communications') ?: 'Communications'],
    ['label' => I18n::t('backstage.title.communications.compose') ?: 'Lähetä viesti'],
];

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$kindOptions = [
    'meeting_invitation'  => I18n::t('communications.kind.meeting_invitation'),
    'payment_reminder'    => I18n::t('communications.kind.payment_reminder'),
    'membership_approved' => I18n::t('communications.kind.membership_approved'),
    'group_message'       => I18n::t('communications.kind.group_message'),
];

$locales = [
    'fi_FI' => 'Suomi',
    'en_GB' => 'English',
    'sw_TZ' => 'Kiswahili',
];

ob_start();
?>
<div class="comms-composer" id="comms-composer">

<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('backstage.title.communications.compose') ?></h1>
    </div>
</div>

<!-- ── Top bar: kind + locale tabs ─────────────────────────────────── -->
<div class="comms-composer__topbar">
    <label class="comms-composer__kind">
        <span><?= I18n::e('communications.composer.kind_label') ?></span>
        <select id="comms-kind" name="kind">
            <?php foreach ($kindOptions as $val => $label): ?>
                <option value="<?= $esc($val) ?>"><?= $esc($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <div class="comms-composer__locale-tabs" role="tablist" id="comms-locale-tabs">
        <?php $first = true; foreach ($locales as $code => $label): ?>
            <button type="button"
                    class="tab-button <?= $first ? 'tab-active' : '' ?>"
                    data-locale="<?= $esc($code) ?>"
                    role="tab"
                    aria-selected="<?= $first ? 'true' : 'false' ?>"><?= $esc($label) ?></button>
            <?php $first = false; endforeach; ?>
    </div>
</div>

<!-- ── Two-column body: form left, preview right ───────────────────── -->
<div class="comms-composer__body">

    <!-- Left: form panel ──────────────────────────────────────────── -->
    <form class="comms-composer__form" id="comms-composer-form" autocomplete="off">

        <!-- Kind-specific selector ─────────────────────────────────── -->
        <section class="comms-composer__kind-fields" data-kind-fields="meeting_invitation" hidden>
            <label class="comms-composer__field">
                <span><?= I18n::e('communications.kind.meeting_invitation') ?> — ID</span>
                <input type="text" id="comms-meeting-id" name="meeting_id" placeholder="UUID">
            </label>
        </section>

        <section class="comms-composer__kind-fields" data-kind-fields="payment_reminder" hidden>
            <label class="comms-composer__field">
                <span><?= I18n::e('communications.kind.payment_reminder') ?> — ID</span>
                <input type="text" id="comms-invoice-id" name="invoice_id" placeholder="UUID">
            </label>
        </section>

        <section class="comms-composer__kind-fields" data-kind-fields="membership_approved" hidden>
            <label class="comms-composer__field">
                <span><?= I18n::e('communications.kind.membership_approved') ?> — ID</span>
                <input type="text" id="comms-application-id" name="application_id" placeholder="UUID">
            </label>
        </section>

        <!-- Shared admin-editable fields ───────────────────────────── -->
        <label class="comms-composer__field">
            <span><?= I18n::e('communications.template.subject') ?></span>
            <input type="text" id="comms-subject" name="subject">
        </label>

        <label class="comms-composer__field">
            <span><?= I18n::e('communications.template.intro') ?></span>
            <textarea id="comms-intro-text" name="intro_text" rows="5"></textarea>
            <small class="comms-composer__hint">Markdown</small>
        </label>

        <!-- Group-message body — only visible for kind=group_message ── -->
        <label class="comms-composer__field" data-kind-fields="group_message" hidden>
            <span><?= I18n::e('communications.newsletter.block.paragraph') ?> (Markdown)</span>
            <textarea id="comms-body" name="body" rows="8"></textarea>
        </label>

        <label class="comms-composer__field">
            <span><?= I18n::e('communications.template.signature') ?></span>
            <input type="text" id="comms-signature" name="signature">
        </label>
    </form>

    <!-- Audience filter (sidebar-style block under the form) ─────── -->
    <aside class="comms-composer__audience" id="comms-audience" hidden>
        <h2 class="comms-composer__audience-title"><?= $esc(I18n::t('communications.composer.audience_count') ?: 'Vastaanottajat') ?></h2>

        <div class="comms-composer__audience-row">
            <span class="comms-composer__audience-label">Jäsentyypit</span>
            <div class="comms-composer__audience-chips">
                <label><input type="checkbox" name="membership_types[]" value="full"> Full</label>
                <label><input type="checkbox" name="membership_types[]" value="basic"> Basic</label>
                <label><input type="checkbox" name="membership_types[]" value="supporter"> Supporter</label>
                <label><input type="checkbox" name="membership_types[]" value="honorary"> Honorary</label>
            </div>
        </div>

        <div class="comms-composer__audience-row">
            <span class="comms-composer__audience-label">Kielet</span>
            <div class="comms-composer__audience-chips">
                <?php foreach ($locales as $code => $label): ?>
                    <label><input type="checkbox" name="locales[]" value="<?= $esc($code) ?>"> <?= $esc($label) ?></label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="comms-composer__audience-row">
            <span class="comms-composer__audience-label">Liittynyt</span>
            <div class="comms-composer__audience-chips">
                <label><input type="radio" name="joined_within" value=""> Kaikki</label>
                <label><input type="radio" name="joined_within" value="last_30_days"> 30 vrk</label>
                <label><input type="radio" name="joined_within" value="last_90_days"> 90 vrk</label>
                <label><input type="radio" name="joined_within" value="last_365_days"> 1 v</label>
            </div>
        </div>

        <p class="comms-composer__audience-count">
            <strong id="comms-audience-count">0</strong> vastaanottajaa
        </p>
    </aside>

    <!-- Right: preview panel ──────────────────────────────────────── -->
    <section class="comms-composer__preview">
        <h2 class="comms-composer__preview-title"><?= I18n::e('communications.composer.preview') ?></h2>
        <iframe id="comms-preview-html"
                class="comms-composer__preview-iframe"
                title="<?= $esc(I18n::t('communications.composer.preview') ?: 'Esikatselu') ?>"
                sandbox="allow-same-origin"></iframe>
        <details class="comms-composer__preview-text">
            <summary>plain text</summary>
            <pre id="comms-preview-text"></pre>
        </details>
    </section>

</div>

<!-- ── Bottom action bar ──────────────────────────────────────────── -->
<div class="comms-composer__actions">
    <button type="button" class="btn btn--ghost" id="comms-preview-btn"><?= I18n::e('communications.composer.preview') ?></button>
    <button type="button" class="btn btn--ghost" id="comms-test-btn"><?= I18n::e('communications.composer.test_to_self') ?></button>
    <button type="button" class="btn btn--primary" id="comms-send-btn"><?= I18n::e('communications.composer.send') ?></button>
    <span class="comms-composer__status" id="comms-composer-status"></span>
</div>

</div>

<link rel="stylesheet" href="/modules/communications/assets/communications.css">
<script
    src="/modules/communications/assets/composer.js"
    data-send-confirm="<?= $esc(I18n::t('communications.composer.send') ?: 'Lähetä jonoon') ?>?"
    data-preview-empty-text="<?= $esc(I18n::t('communications.composer.preview') ?: 'Esikatselu päivittyy automaattisesti…') ?>"
    data-audience-count-template="<?= $esc(I18n::t('communications.composer.audience_count') ?: 'Vastaanottajia: :count') ?>"
    data-empty-audience="<?= $esc(I18n::t('communications.composer.empty_audience') ?: 'Ei vastaanottajia annetuilla suodattimilla.') ?>"
    data-outbox-url="/backstage/communications/outbox"
    defer></script>
<?php
$pageContent = ob_get_clean();
require DAEMS_SITE_PUBLIC . '/pages/layout.php';
