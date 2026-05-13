<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Mail;

use DaemsModule\Communications\Domain\Preference\CommunicationCategory;

enum MailKind: string
{
    case MeetingInvitation  = 'meeting_invitation';
    case PaymentReminder    = 'payment_reminder';
    case MembershipApproved = 'membership_approved';
    case GroupMessage       = 'group_message';
    case Newsletter         = 'newsletter';

    public function category(): CommunicationCategory
    {
        return match ($this) {
            self::MeetingInvitation,
            self::PaymentReminder,
            self::MembershipApproved => CommunicationCategory::Transactional,
            self::GroupMessage       => CommunicationCategory::Operational,
            self::Newsletter         => CommunicationCategory::Marketing,
        };
    }

    /**
     * Default template-file basename rendered for this kind (no extension).
     * The renderer can override via `$templateVariant` (e.g. `lapse_warning`
     * for a PaymentReminder outbox row used as the §4 pre-lapse warning).
     */
    public function defaultTemplateName(): string
    {
        return match ($this) {
            self::MeetingInvitation  => 'meeting_invitation',
            self::PaymentReminder    => 'payment_reminder',
            self::MembershipApproved => 'membership_approved',
            self::GroupMessage       => 'group_message',
            self::Newsletter         => 'newsletter_wrapper',
        };
    }

    /**
     * Whitelist of placeholder variable names accepted by VarSubstituter for
     * this mail kind. Unknown vars in a template raise UnknownTemplateVarException.
     *
     * @return list<string>
     */
    public function allowedVars(): array
    {
        return match ($this) {
            self::MeetingInvitation => [
                'first_name', 'subject', 'intro_text', 'intro_text_html',
                'meeting_title', 'meeting_date', 'meeting_location', 'meeting_remote_url',
                'agenda_html', 'documents_list', 'rsvp_url', 'signature',
                'brand_primary_color', 'brand_logo_url', 'brand_footer_address',
                'locale', 'tenant_name',
            ],
            self::PaymentReminder => [
                'first_name', 'subject', 'intro_text', 'intro_text_html',
                'invoice_year', 'amount', 'currency',
                'due_date', 'payment_link', 'signature',
                'predicted_lapse_date', 'outstanding_total',
                'brand_primary_color', 'brand_logo_url', 'brand_footer_address',
                'locale', 'tenant_name',
            ],
            self::MembershipApproved => [
                'first_name', 'subject', 'tenant_name', 'login_link', 'signature',
                'intro_text', 'intro_text_html',
                'brand_primary_color', 'brand_logo_url', 'brand_footer_address',
                'locale',
            ],
            self::GroupMessage => [
                'first_name', 'subject', 'body_html', 'signature', 'tenant_name',
                'brand_primary_color', 'brand_logo_url', 'brand_footer_address',
                'locale',
            ],
            self::Newsletter => [
                'first_name', 'subject', 'block_body', 'unsubscribe_url', 'tenant_name',
                'brand_primary_color', 'brand_logo_url', 'brand_footer_address',
                'locale',
            ],
        };
    }
}
