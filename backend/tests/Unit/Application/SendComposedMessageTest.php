<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Application;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\FrozenClock;
use DaemsModule\Communications\Application\CreateMeetingFromComposer\CreateMeetingFromComposer;
use DaemsModule\Communications\Application\SendComposedMessage\Input;
use DaemsModule\Communications\Application\SendComposedMessage\SendComposedMessage;
use DaemsModule\Communications\Domain\Audience\AudienceFilter;
use DaemsModule\Communications\Domain\Audience\ResolvedRecipient;
use DaemsModule\Communications\Domain\Mail\Exception\SmtpNotConfigured;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;
use DaemsModule\Communications\Domain\Mail\MailSuppression;
use DaemsModule\Communications\Domain\Mail\SuppressionReason;
use DaemsModule\Communications\Domain\Meeting\MeetingType;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Infrastructure\Renderer\EmailHtmlRenderer;
use DaemsModule\Communications\Infrastructure\Renderer\Html2Text;
use DaemsModule\Communications\Infrastructure\Renderer\MailTemplateRegistry;
use DaemsModule\Communications\Infrastructure\Renderer\MarkdownRenderer;
use DaemsModule\Communications\Infrastructure\Renderer\VarSubstituter;
use DaemsModule\Communications\Tests\Support\InMemoryAudienceResolver;
use DaemsModule\Communications\Tests\Support\InMemoryMailOutboxRepository;
use DaemsModule\Communications\Tests\Support\InMemoryMailSuppressionRepository;
use DaemsModule\Communications\Tests\Support\InMemoryMailTemplateRepository;
use DaemsModule\Communications\Tests\Support\InMemoryMeetingRepository;
use DaemsModule\Communications\Tests\Support\InMemoryTenantCommunicationSettingsRepository;
use PHPUnit\Framework\TestCase;

final class SendComposedMessageTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';

    private function tenantId(): TenantId
    {
        return TenantId::fromString(self::TENANT);
    }

    private function acting(?UserTenantRole $role, bool $isPlatformAdmin = false): ActingUser
    {
        return new ActingUser(
            id:                 UserId::generate(),
            email:              'admin@x',
            isPlatformAdmin:    $isPlatformAdmin,
            activeTenant:       $this->tenantId(),
            roleInActiveTenant: $role,
        );
    }

    private function emptyFilter(): AudienceFilter
    {
        return new AudienceFilter(
            membershipTypes:     [],
            locales:             [],
            joinedWithin:        null,
            applicationStatuses: [],
        );
    }

    /** @return array{0:SendComposedMessage, 1:InMemoryAudienceResolver, 2:InMemoryMailOutboxRepository, 3:InMemoryTenantCommunicationSettingsRepository, 4:InMemoryMeetingRepository, 5:InMemoryMailSuppressionRepository} */
    private function buildUseCase(): array
    {
        $resolver        = new InMemoryAudienceResolver();
        $templateRepo    = new InMemoryMailTemplateRepository();
        $settingsRepo    = new InMemoryTenantCommunicationSettingsRepository();
        $outboxRepo      = new InMemoryMailOutboxRepository();
        $suppressionRepo = new InMemoryMailSuppressionRepository();
        $meetingRepo     = new InMemoryMeetingRepository();
        $clock           = FrozenClock::at('2026-05-14T09:00:00Z');
        $renderer        = new EmailHtmlRenderer(
            new MailTemplateRegistry(),
            new VarSubstituter(),
            new MarkdownRenderer(),
            new Html2Text(),
        );
        $createMeeting   = new CreateMeetingFromComposer($meetingRepo, $clock);

        return [
            new SendComposedMessage(
                resolver:        $resolver,
                templateRepo:    $templateRepo,
                settingsRepo:    $settingsRepo,
                outboxRepo:      $outboxRepo,
                suppressionRepo: $suppressionRepo,
                renderer:        $renderer,
                createMeeting:   $createMeeting,
                clock:           $clock,
            ),
            $resolver,
            $outboxRepo,
            $settingsRepo,
            $meetingRepo,
            $suppressionRepo,
        ];
    }

    private function configureSmtp(InMemoryTenantCommunicationSettingsRepository $settingsRepo): void
    {
        $settingsRepo->save(new TenantCommunicationSettings(
            tenantId:               $this->tenantId(),
            smtpDsnEncrypted:       'fake-encrypted-dsn',
            mailFromAddress:        'noreply@daems.fi',
            mailDisplayName:        'Daems Society',
            mailReplyTo:            null,
            smtpTestSucceededAt:    null,
            reminderPreDueDays:     7,
            reminderPostDueDays:    [14, 30],
            lapseWarningDaysBefore: 30,
            brandLogoUrl:           null,
            brandPrimaryColor:      '#1f3a5f',
            brandFooterAddress:     'Daems ry, 33100 Tampere',
            updatedAt:              new \DateTimeImmutable('2026-05-14T09:00:00Z'),
        ));
    }

    /** @return list<ResolvedRecipient> */
    private function seedRecipients(InMemoryAudienceResolver $resolver, int $count = 2): array
    {
        $names = ['Sara', 'Mikko', 'Aino', 'Liisa'];
        $list  = [];
        for ($i = 0; $i < $count; $i++) {
            $name = $names[$i % count($names)];
            $list[] = new ResolvedRecipient(
                userId:      UserId::generate(),
                email:       strtolower($name) . $i . '@example.com',
                locale:      SupportedLocale::fromString('fi_FI'),
                firstName:   $name,
                contextVars: [],
            );
        }
        $resolver->seed($this->tenantId(), $list);
        return $list;
    }

    public function test_admin_sends_group_message_enqueues_one_row_per_recipient(): void
    {
        [$useCase, $resolver, $outboxRepo, $settingsRepo] = $this->buildUseCase();
        $this->configureSmtp($settingsRepo);
        $this->seedRecipients($resolver, 3);

        $output = $useCase->execute(
            new Input(
                tenantId:        $this->tenantId(),
                kind:            MailKind::GroupMessage,
                payload:         [
                    'subject'   => 'Tervetuloa',
                    'body_html' => '<p>Hi</p>',
                    'signature' => 'Hallitus',
                ],
                audienceFilter:  $this->emptyFilter(),
            ),
            $this->acting(UserTenantRole::Admin),
        );

        self::assertSame(3, $output->enqueuedCount);
        self::assertNull($output->meetingId);
        self::assertCount(3, $outboxRepo->byId);
        foreach ($outboxRepo->byId as $row) {
            self::assertSame(MailOutboxStatus::Queued, $row->status);
            self::assertSame(MailKind::GroupMessage, $row->kind);
            self::assertSame('Tervetuloa', $row->subject);
            self::assertSame('fi_FI', $row->locale->value());
        }
    }

    public function test_send_throws_when_smtp_not_configured(): void
    {
        [$useCase, $resolver] = $this->buildUseCase();
        // NOTE: NO configureSmtp() — repo returns the default-empty settings
        $this->seedRecipients($resolver, 1);

        $this->expectException(SmtpNotConfigured::class);
        $useCase->execute(
            new Input(
                tenantId:        $this->tenantId(),
                kind:            MailKind::GroupMessage,
                payload:         ['subject' => 'x', 'body_html' => '<p>x</p>'],
                audienceFilter:  $this->emptyFilter(),
            ),
            $this->acting(UserTenantRole::Admin),
        );
    }

    public function test_send_throws_when_audience_empty(): void
    {
        [$useCase, $_resolver, $_outboxRepo, $settingsRepo] = $this->buildUseCase();
        $this->configureSmtp($settingsRepo);
        // No recipients seeded.

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Empty audience');
        $useCase->execute(
            new Input(
                tenantId:        $this->tenantId(),
                kind:            MailKind::GroupMessage,
                payload:         ['subject' => 'x', 'body_html' => '<p>x</p>'],
                audienceFilter:  $this->emptyFilter(),
            ),
            $this->acting(UserTenantRole::Admin),
        );
    }

    public function test_member_cannot_send(): void
    {
        [$useCase, $resolver, $_outboxRepo, $settingsRepo] = $this->buildUseCase();
        $this->configureSmtp($settingsRepo);
        $this->seedRecipients($resolver, 1);

        $this->expectException(ForbiddenException::class);
        $useCase->execute(
            new Input(
                tenantId:        $this->tenantId(),
                kind:            MailKind::GroupMessage,
                payload:         ['subject' => 'x', 'body_html' => '<p>x</p>'],
                audienceFilter:  $this->emptyFilter(),
            ),
            $this->acting(UserTenantRole::Member),
        );
    }

    public function test_marketing_kind_requires_admin_not_moderator(): void
    {
        [$useCase, $resolver, $_outboxRepo, $settingsRepo] = $this->buildUseCase();
        $this->configureSmtp($settingsRepo);
        $this->seedRecipients($resolver, 1);

        $this->expectException(ForbiddenException::class);
        $useCase->execute(
            new Input(
                tenantId:        $this->tenantId(),
                kind:            MailKind::Newsletter,
                payload:         ['subject' => 'x', 'block_body' => '<p>Hi</p>', 'unsubscribe_url' => 'https://x'],
                audienceFilter:  $this->emptyFilter(),
            ),
            $this->acting(UserTenantRole::Moderator),
        );
    }

    public function test_meeting_invitation_creates_meeting_when_missing(): void
    {
        [$useCase, $resolver, $outboxRepo, $settingsRepo, $meetingRepo] = $this->buildUseCase();
        $this->configureSmtp($settingsRepo);
        $this->seedRecipients($resolver, 2);

        $output = $useCase->execute(
            new Input(
                tenantId:            $this->tenantId(),
                kind:                MailKind::MeetingInvitation,
                payload:             [
                    'subject'           => 'Vuosikokouskutsu 2026',
                    'intro_text'        => 'Tervetuloa vuosikokoukseen',
                    'meeting_title'     => 'Vuosikokous 2026',
                    'meeting_date'      => '15.6.2026 klo 18',
                    'meeting_location'  => 'Pirkkalankatu 1',
                    'meeting_remote_url' => 'https://meet.example',
                    'agenda_html'       => '<ol><li>Avaus</li></ol>',
                    'documents_list'    => '',
                    'rsvp_url'          => 'https://daems.fi/rsvp',
                    'signature'         => 'Hallitus',
                ],
                audienceFilter:      $this->emptyFilter(),
                meetingType:         MeetingType::AnnualMeeting,
                meetingStartsAt:     new \DateTimeImmutable('2026-06-15T18:00:00Z'),
                meetingLocation:     'Pirkkalankatu 1',
                meetingRemoteUrl:    'https://meet.example',
                titleByLocale:       ['fi_FI' => 'Vuosikokous 2026'],
                agendaItemsByLocale: ['fi_FI' => ['Avaus']],
                documentUrls:        [],
            ),
            $this->acting(UserTenantRole::Admin),
        );

        self::assertSame(2, $output->enqueuedCount);
        self::assertNotNull($output->meetingId);
        self::assertNotNull($meetingRepo->findById($output->meetingId));
        // Outbox rows carry the meeting id.
        foreach ($outboxRepo->byId as $row) {
            self::assertNotNull($row->payloadMeetingId);
            self::assertTrue($row->payloadMeetingId->equals($output->meetingId));
        }
    }

    public function test_suppressed_recipient_is_skipped(): void
    {
        [$useCase, $resolver, $outboxRepo, $settingsRepo, $_meetingRepo, $suppressionRepo] = $this->buildUseCase();
        $this->configureSmtp($settingsRepo);
        $recipients = $this->seedRecipients($resolver, 3);
        // Suppress the second recipient.
        $suppressionRepo->add(new MailSuppression(
            tenantId:         $this->tenantId(),
            emailAddress:     $recipients[1]->email,
            reason:           SuppressionReason::HardBounce,
            suppressedAt:     new \DateTimeImmutable('2026-04-01T00:00:00Z'),
            smtpResponseCode: '550',
            suppressedBy:     null,
        ));

        $output = $useCase->execute(
            new Input(
                tenantId:        $this->tenantId(),
                kind:            MailKind::GroupMessage,
                payload:         ['subject' => 'x', 'body_html' => '<p>x</p>'],
                audienceFilter:  $this->emptyFilter(),
            ),
            $this->acting(UserTenantRole::Admin),
        );

        self::assertSame(2, $output->enqueuedCount);
        self::assertCount(2, $outboxRepo->byId);
    }
}
