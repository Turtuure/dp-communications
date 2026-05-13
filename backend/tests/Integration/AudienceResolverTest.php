<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Integration\Audience;

use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;
use DaemsModule\Communications\Domain\Audience\AudienceFilter;
use DaemsModule\Communications\Domain\Audience\JoinedWithinPeriod;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;
use DaemsModule\Communications\Infrastructure\Audience\SqlAudienceResolver;

final class AudienceResolverTest extends MigrationTestCase
{
    private SqlAudienceResolver $resolver;

    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        // 098 = communications module schema slot (mail_outbox + user_communication_preferences …)
        $this->runMigrationsUpTo(98);

        $this->resolver = new SqlAudienceResolver(new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]));

        $this->tenantId = $this->resolveDaemsTenantId();

        // Purge any opt-in rows the migration may have seeded for users
        // that may exist from earlier shared schema. We control the data
        // we care about by inserting fresh users in each test.
    }

    private function resolveDaemsTenantId(): TenantId
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM tenants WHERE slug = ?');
        $stmt->execute(['daems']);
        $id = $stmt->fetchColumn();
        if (!is_string($id)) {
            throw new \RuntimeException('daems tenant missing after migrations');
        }
        return TenantId::fromString($id);
    }

    /**
     * Insert a user + user_tenant row and (optionally) seed
     * communication-preference rows. Returns user_id.
     */
    private function seedMember(
        string $idTail,
        string $email,
        string $name,
        string $membershipType,
        \DateTimeImmutable $joinedAt,
        bool $optInOperational = true,
        bool $optInMarketing   = false,
    ): string {
        $id = '01958000-0000-7000-8000-0000000000' . $idTail;

        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth,
                                membership_type, membership_status, is_platform_admin, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $name,
            $email,
            'x',
            '1990-01-01',
            $membershipType,
            'active',
            0,
            $joinedAt->format('Y-m-d H:i:s'),
        ]);

        $this->pdo()->prepare(
            'INSERT INTO user_tenants (user_id, tenant_id, role, joined_at)
             VALUES (?, ?, ?, ?)'
        )->execute([
            $id,
            $this->tenantId->value(),
            'member',
            $joinedAt->format('Y-m-d H:i:s'),
        ]);

        // Replace any default-seeded preference rows for this (user, tenant).
        $this->pdo()->prepare(
            'DELETE FROM user_communication_preferences WHERE user_id = ? AND tenant_id = ?'
        )->execute([$id, $this->tenantId->value()]);

        $this->pdo()->prepare(
            'INSERT INTO user_communication_preferences (user_id, tenant_id, category, opted_in)
             VALUES (?, ?, ?, ?), (?, ?, ?, ?), (?, ?, ?, ?)'
        )->execute([
            $id, $this->tenantId->value(), 'transactional', 1,
            $id, $this->tenantId->value(), 'operational',   $optInOperational ? 1 : 0,
            $id, $this->tenantId->value(), 'marketing',     $optInMarketing   ? 1 : 0,
        ]);

        return $id;
    }

    public function testFilterByMembershipTypes(): void
    {
        $this->seedMember('a1', 'full1@example.com',  'Full One',   'FULL',       new \DateTimeImmutable('-60 days'));
        $this->seedMember('a2', 'basic1@example.com', 'Basic One',  'BASIC',      new \DateTimeImmutable('-60 days'));
        $this->seedMember('a3', 'supp1@example.com',  'Supp One',   'SUPPORTING', new \DateTimeImmutable('-60 days'));
        $this->seedMember('a4', 'hon1@example.com',   'Hon One',    'HONORARY',   new \DateTimeImmutable('-60 days'));

        $filter = new AudienceFilter(
            membershipTypes:     ['FULL', 'BASIC'],
            locales:             [],
            joinedWithin:        null,
            applicationStatuses: [],
        );

        $result = $this->resolver->resolve($this->tenantId, $filter, CommunicationCategory::Transactional);

        $emails = array_map(static fn ($r): string => $r->email, $result);
        sort($emails);
        $this->assertCount(2, $result);
        $this->assertSame(['basic1@example.com', 'full1@example.com'], $emails);
    }

    public function testFilterByLocales(): void
    {
        // tenants.default_locale on 'daems' = fi_FI. Add an extra tenant in en_GB.
        $this->pdo()->prepare(
            "INSERT INTO tenants (id, slug, name, default_locale, supported_locales)
             VALUES (?, ?, ?, 'en_GB', 'fi_FI,en_GB')"
        )->execute(['01958000-0000-7000-8000-0000000000c0', 'lcl-test', 'Locale Test']);

        // Seed three members on the daems (fi_FI) tenant.
        $this->seedMember('b1', 'fi1@example.com', 'Fi One', 'FULL', new \DateTimeImmutable('-10 days'));
        $this->seedMember('b2', 'fi2@example.com', 'Fi Two', 'FULL', new \DateTimeImmutable('-10 days'));

        // Seed one member on the en_GB tenant by adding a user_tenants row to
        // the new tenant for an existing user (we'll reuse fi1 by simulating
        // them being a member of the en tenant; but in fact we want a separate
        // *user* on the en_GB tenant). Insert a 3rd user there.
        $enUserId = '01958000-0000-7000-8000-0000000000b3';
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth,
                                membership_type, membership_status, is_platform_admin)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $enUserId, 'En One', 'en1@example.com', 'x', '1990-01-01', 'FULL', 'active', 0,
        ]);
        $this->pdo()->prepare(
            "INSERT INTO user_tenants (user_id, tenant_id, role, joined_at)
             VALUES (?, '01958000-0000-7000-8000-0000000000c0', 'member', NOW())"
        )->execute([$enUserId]);

        // Filter fi_FI: must return the two daems-tenant members and nothing else.
        $filter = new AudienceFilter(
            membershipTypes:     [],
            locales:             ['fi_FI'],
            joinedWithin:        null,
            applicationStatuses: [],
        );
        $result = $this->resolver->resolve($this->tenantId, $filter, CommunicationCategory::Transactional);
        $emails = array_map(static fn ($r): string => $r->email, $result);
        sort($emails);
        $this->assertSame(['fi1@example.com', 'fi2@example.com'], $emails);

        // Filter en_GB against the daems tenant: must return zero (daems is fi_FI).
        $filterEn = new AudienceFilter(
            membershipTypes:     [],
            locales:             ['en_GB'],
            joinedWithin:        null,
            applicationStatuses: [],
        );
        $resultEn = $this->resolver->resolve($this->tenantId, $filterEn, CommunicationCategory::Transactional);
        $this->assertSame([], $resultEn);
    }

    public function testJoinedWithinLast30Days(): void
    {
        $this->seedMember('d1', 'recent@example.com', 'Recent One', 'FULL', new \DateTimeImmutable('-10 days'));
        $this->seedMember('d2', 'mid@example.com',    'Mid One',    'FULL', new \DateTimeImmutable('-50 days'));
        $this->seedMember('d3', 'old@example.com',    'Old One',    'FULL', new \DateTimeImmutable('-100 days'));

        $filter = new AudienceFilter(
            membershipTypes:     [],
            locales:             [],
            joinedWithin:        JoinedWithinPeriod::Last30Days,
            applicationStatuses: [],
        );
        $result = $this->resolver->resolve($this->tenantId, $filter, CommunicationCategory::Transactional);

        $this->assertCount(1, $result);
        $this->assertSame('recent@example.com', $result[0]->email);

        // Sanity: Last90Days returns the -10 + -50 ones, not the -100.
        $filter90 = new AudienceFilter(
            membershipTypes:     [],
            locales:             [],
            joinedWithin:        JoinedWithinPeriod::Last90Days,
            applicationStatuses: [],
        );
        $result90 = $this->resolver->resolve($this->tenantId, $filter90, CommunicationCategory::Transactional);
        $emails = array_map(static fn ($r): string => $r->email, $result90);
        sort($emails);
        $this->assertSame(['mid@example.com', 'recent@example.com'], $emails);
    }

    public function testMarketingSkipsOptedOutUsers(): void
    {
        // Three members: two opted into marketing, one opted out.
        $this->seedMember('e1', 'mkt1@example.com', 'Mkt One',   'FULL', new \DateTimeImmutable('-10 days'),
            optInMarketing: true);
        $this->seedMember('e2', 'mkt2@example.com', 'Mkt Two',   'FULL', new \DateTimeImmutable('-10 days'),
            optInMarketing: true);
        $this->seedMember('e3', 'no@example.com',   'No Mkt',    'FULL', new \DateTimeImmutable('-10 days'),
            optInMarketing: false);

        $filter = new AudienceFilter(
            membershipTypes:     [],
            locales:             [],
            joinedWithin:        null,
            applicationStatuses: [],
        );
        $result = $this->resolver->resolve($this->tenantId, $filter, CommunicationCategory::Marketing);

        $emails = array_map(static fn ($r): string => $r->email, $result);
        sort($emails);
        $this->assertCount(2, $result);
        $this->assertSame(['mkt1@example.com', 'mkt2@example.com'], $emails);

        // And Transactional ignores the opt-in entirely — all 3 should come back.
        $resultTrans = $this->resolver->resolve($this->tenantId, $filter, CommunicationCategory::Transactional);
        $this->assertCount(3, $resultTrans);
    }

    public function testApplicationStatusFilter(): void
    {
        $userPendingId  = $this->seedMember('f1', 'pending@example.com', 'Pending One',  'BASIC', new \DateTimeImmutable('-10 days'));
        $userApprovedId = $this->seedMember('f2', 'approved@example.com', 'Approved One', 'BASIC', new \DateTimeImmutable('-10 days'));
        $userRejectedId = $this->seedMember('f3', 'rejected@example.com', 'Rejected One', 'BASIC', new \DateTimeImmutable('-10 days'));

        // Seed matching member_applications rows (joined on email).
        $ins = $this->pdo()->prepare(
            'INSERT INTO member_applications
                (id, name, email, date_of_birth, motivation, status, tenant_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            '01958000-0000-7000-8000-0000000000f1', 'Pending One',  'pending@example.com',  '1990-01-01', 'why', 'pending',  $this->tenantId->value(), '2026-01-01 12:00:00',
        ]);
        $ins->execute([
            '01958000-0000-7000-8000-0000000000f2', 'Approved One', 'approved@example.com', '1990-01-01', 'why', 'approved', $this->tenantId->value(), '2026-01-01 12:00:00',
        ]);
        $ins->execute([
            '01958000-0000-7000-8000-0000000000f3', 'Rejected One', 'rejected@example.com', '1990-01-01', 'why', 'rejected', $this->tenantId->value(), '2026-01-01 12:00:00',
        ]);
        // Suppress unused-variable warnings: IDs prove no DB constraint flares.
        $this->assertNotEmpty($userPendingId);
        $this->assertNotEmpty($userApprovedId);
        $this->assertNotEmpty($userRejectedId);

        $filter = new AudienceFilter(
            membershipTypes:     [],
            locales:             [],
            joinedWithin:        null,
            applicationStatuses: ['pending', 'approved'],
        );
        $result = $this->resolver->resolve($this->tenantId, $filter, CommunicationCategory::Transactional);

        $emails = array_map(static fn ($r): string => $r->email, $result);
        sort($emails);
        $this->assertCount(2, $result);
        $this->assertSame(['approved@example.com', 'pending@example.com'], $emails);
    }
}
