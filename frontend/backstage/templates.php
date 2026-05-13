<?php
/**
 * Backstage — Communications Templates list (Wave D Task D8).
 *
 * Lists the 4 strict mail kinds with edit-links to templates-edit.php.
 * Newsletter is excluded — Wave E gives newsletters their own surface
 * (`/backstage/communications/newsletters`) because they have block-level
 * content rather than string overrides.
 *
 * No data-fetch happens on this page; the per-kind row just routes the
 * operator to the edit page where the actual GET happens.
 */

declare(strict_types=1);

use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.communications.templates';
$activePage  = 'communications-templates';
$breadcrumbs = [
    ['label' => I18n::t('sidebar.group.communications') ?: 'Communications'],
    ['label' => I18n::t('backstage.title.communications.templates') ?: 'Viestipohjat'],
];

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$kinds = [
    'meeting_invitation'  => I18n::t('communications.kind.meeting_invitation'),
    'payment_reminder'    => I18n::t('communications.kind.payment_reminder'),
    'membership_approved' => I18n::t('communications.kind.membership_approved'),
    'group_message'       => I18n::t('communications.kind.group_message'),
];

ob_start();
?>
<div class="comms-templates">

<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('backstage.title.communications.templates') ?></h1>
    </div>
</div>

<table class="data-table comms-templates__list">
    <thead>
        <tr>
            <th><?= I18n::e('communications.outbox.filter.kind') ?: 'Tyyppi' ?></th>
            <th><?= I18n::e('backstage.common.actions') ?: 'Toiminnot' ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($kinds as $kind => $label): ?>
            <tr>
                <td><?= $esc($label) ?></td>
                <td>
                    <a class="btn btn--ghost btn--sm"
                       href="/backstage/communications/templates-edit?kind=<?= $esc($kind) ?>">
                        <?= I18n::e('backstage.common.edit') ?: 'Muokkaa' ?>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</div>

<link rel="stylesheet" href="/modules/communications/assets/communications.css">
<?php
$pageContent = ob_get_clean();
require DAEMS_SITE_PUBLIC . '/pages/layout.php';
