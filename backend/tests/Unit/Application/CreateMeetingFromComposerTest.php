<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Application;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\FrozenClock;
use DaemsModule\Communications\Application\CreateMeetingFromComposer\CreateMeetingFromComposer;
use DaemsModule\Communications\Application\CreateMeetingFromComposer\Input;
use DaemsModule\Communications\Domain\Meeting\MeetingStatus;
use DaemsModule\Communications\Domain\Meeting\MeetingType;
use DaemsModule\Communications\Tests\Support\InMemoryMeetingRepository;
use PHPUnit\Framework\TestCase;

final class CreateMeetingFromComposerTest extends TestCase
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
            email:              'a@x',
            isPlatformAdmin:    $isPlatformAdmin,
            activeTenant:       $this->tenantId(),
            roleInActiveTenant: $role,
        );
    }

    public function test_admin_creates_scheduled_meeting(): void
    {
        $repo  = new InMemoryMeetingRepository();
        $clock = FrozenClock::at('2026-05-14T09:00:00Z');
        $useCase = new CreateMeetingFromComposer($repo, $clock);

        $startsAt = new \DateTimeImmutable('2026-06-15T18:00:00Z');
        $output   = $useCase->execute(
            new Input(
                tenantId:            $this->tenantId(),
                type:                MeetingType::AnnualMeeting,
                titleByLocale:       ['fi_FI' => 'Vuosikokous 2026'],
                startsAt:            $startsAt,
                location:            'Pirkkalankatu 1, Tampere',
                remoteUrl:           'https://meet.example/abc',
                agendaItemsByLocale: ['fi_FI' => ['1. Avaus', '2. Tilinpäätös']],
                documentUrls:        ['https://example.com/agenda.pdf'],
            ),
            $this->acting(UserTenantRole::Admin),
        );

        $saved = $repo->findById($output->meetingId);
        self::assertNotNull($saved);
        self::assertSame(MeetingStatus::Scheduled, $saved->status);
        self::assertSame('Vuosikokous 2026', $saved->titleByLocale['fi_FI']);
        self::assertSame($startsAt, $saved->startsAt);
        self::assertSame('Pirkkalankatu 1, Tampere', $saved->location);
        self::assertSame($clock->now(), $saved->createdAt);
    }

    public function test_moderator_cannot_create_meeting(): void
    {
        $repo  = new InMemoryMeetingRepository();
        $clock = FrozenClock::at('2026-05-14T09:00:00Z');
        $useCase = new CreateMeetingFromComposer($repo, $clock);

        $this->expectException(ForbiddenException::class);
        $useCase->execute(
            new Input(
                tenantId:            $this->tenantId(),
                type:                MeetingType::BoardMeeting,
                titleByLocale:       ['fi_FI' => 'Hallituksen kokous'],
                startsAt:            new \DateTimeImmutable('2026-06-01T17:00:00Z'),
                location:            null,
                remoteUrl:           null,
                agendaItemsByLocale: [],
                documentUrls:        [],
            ),
            $this->acting(UserTenantRole::Moderator),
        );
    }
}
