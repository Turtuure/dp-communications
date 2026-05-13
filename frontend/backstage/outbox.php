<?php
/**
 * Backstage — Communications Outbox.
 *
 * Lists queued/sending/sent/failed/bounced/suppressed mail rows for the active
 * tenant. Filters by status/kind/date-range/recipient substring. Failed rows
 * surface a "Yritä uudelleen" action that POSTs to the platform API.
 *
 * Data source: GET /api/v1/backstage/communications/outbox (via /api/backstage
 * proxy). Retry: POST .../outbox/{id}/retry.
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.communications.outbox';
$activePage  = 'communications-outbox';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.communications') ?: 'Communications'],
    ['label' => I18n::t('shell.communications.outbox')],
];

$statusFilter    = isset($_GET['status'])    ? (string) $_GET['status']    : '';
$kindFilter      = isset($_GET['kind'])      ? (string) $_GET['kind']      : '';
$fromFilter      = isset($_GET['from'])      ? (string) $_GET['from']      : '';
$toFilter        = isset($_GET['to'])        ? (string) $_GET['to']        : '';
$recipientFilter = isset($_GET['recipient']) ? (string) $_GET['recipient'] : '';

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$statusOptions = [
    'queued'     => I18n::t('communications.outbox.status.queued'),
    'sending'    => I18n::t('communications.outbox.status.sending'),
    'sent'       => I18n::t('communications.outbox.status.sent'),
    'failed'     => I18n::t('communications.outbox.status.failed'),
    'bounced'    => I18n::t('communications.outbox.status.bounced'),
    'suppressed' => I18n::t('communications.outbox.status.suppressed'),
];

$kindOptions = [
    'meeting_invitation'  => I18n::t('communications.kind.meeting_invitation'),
    'payment_reminder'    => I18n::t('communications.kind.payment_reminder'),
    'membership_approved' => I18n::t('communications.kind.membership_approved'),
    'group_message'       => I18n::t('communications.kind.group_message'),
    'newsletter'          => I18n::t('communications.kind.newsletter'),
];

ob_start();
?>
<div class="comms-outbox">

<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('backstage.title.communications.outbox') ?></h1>
    </div>
    <form method="get" class="comms-outbox__filters" id="comms-outbox-filters">
        <label>
            <span><?= I18n::e('communications.outbox.filter.status') ?></span>
            <select name="status">
                <option value=""><?= $esc(I18n::t('backstage.common.all') ?: 'Kaikki') ?></option>
                <?php foreach ($statusOptions as $val => $label): ?>
                    <option value="<?= $esc($val) ?>" <?= $statusFilter === $val ? 'selected' : '' ?>><?= $esc($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span><?= I18n::e('communications.outbox.filter.kind') ?></span>
            <select name="kind">
                <option value=""><?= $esc(I18n::t('backstage.common.all') ?: 'Kaikki') ?></option>
                <?php foreach ($kindOptions as $val => $label): ?>
                    <option value="<?= $esc($val) ?>" <?= $kindFilter === $val ? 'selected' : '' ?>><?= $esc($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span><?= I18n::e('communications.outbox.filter.date_range') ?></span>
            <input type="date" name="from" value="<?= $esc($fromFilter) ?>">
            <input type="date" name="to" value="<?= $esc($toFilter) ?>">
        </label>
        <label>
            <span><?= I18n::e('communications.outbox.filter.recipient') ?></span>
            <input type="search" name="recipient" value="<?= $esc($recipientFilter) ?>" placeholder="@example.com">
        </label>
        <button type="submit" class="btn btn--ghost btn--sm">OK</button>
    </form>
</div>

<section class="comms-outbox__list-wrap">
    <table class="data-table comms-outbox__list"
           data-status="<?= $esc($statusFilter) ?>"
           data-kind="<?= $esc($kindFilter) ?>"
           data-from="<?= $esc($fromFilter) ?>"
           data-to="<?= $esc($toFilter) ?>"
           data-recipient="<?= $esc($recipientFilter) ?>">
        <thead>
            <tr>
                <th><?= I18n::e('communications.outbox.filter.recipient') ?></th>
                <th><?= I18n::e('communications.outbox.filter.kind') ?></th>
                <th><?= I18n::e('communications.outbox.filter.status') ?></th>
                <th><?= I18n::e('backstage.common.attempts') ?: 'Yritetty' ?></th>
                <th><?= I18n::e('backstage.common.time') ?: 'Aika' ?></th>
                <th><?= I18n::e('backstage.common.actions') ?: 'Toiminnot' ?></th>
            </tr>
        </thead>
        <tbody>
            <tr><td colspan="6"><?= I18n::e('backstage.common.loading') ?: 'Ladataan…' ?></td></tr>
        </tbody>
    </table>
</section>

</div>

<link rel="stylesheet" href="/modules/communications/assets/communications.css">
<script
    src="/modules/communications/assets/outbox.js"
    data-empty-text="<?= $esc(I18n::t('communications.outbox.empty') ?: 'Ei viestejä.') ?>"
    data-retry-label="<?= $esc(I18n::t('communications.outbox.action.retry') ?: 'Yritä uudelleen') ?>"
    data-retry-confirm="<?= $esc(I18n::t('communications.outbox.action.retry') ?: 'Yritä uudelleen') ?>"
    data-status-queued="<?= $esc($statusOptions['queued']) ?>"
    data-status-sending="<?= $esc($statusOptions['sending']) ?>"
    data-status-sent="<?= $esc($statusOptions['sent']) ?>"
    data-status-failed="<?= $esc($statusOptions['failed']) ?>"
    data-status-bounced="<?= $esc($statusOptions['bounced']) ?>"
    data-status-suppressed="<?= $esc($statusOptions['suppressed']) ?>"
    data-kind-meeting_invitation="<?= $esc($kindOptions['meeting_invitation']) ?>"
    data-kind-payment_reminder="<?= $esc($kindOptions['payment_reminder']) ?>"
    data-kind-membership_approved="<?= $esc($kindOptions['membership_approved']) ?>"
    data-kind-group_message="<?= $esc($kindOptions['group_message']) ?>"
    data-kind-newsletter="<?= $esc($kindOptions['newsletter']) ?>"
    defer></script>
<?php
$pageContent = ob_get_clean();
require DAEMS_SITE_PUBLIC . '/pages/layout.php';
