<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Domain\Preference;

use DaemsModule\Communications\Domain\Preference\CommunicationCategory;
use PHPUnit\Framework\TestCase;

final class CommunicationCategoryTest extends TestCase
{
    public function test_transactional_is_immutable(): void
    {
        $this->assertTrue(CommunicationCategory::Transactional->isImmutable());
        $this->assertFalse(CommunicationCategory::Operational->isImmutable());
        $this->assertFalse(CommunicationCategory::Marketing->isImmutable());
    }

    public function test_default_opt_in(): void
    {
        $this->assertTrue(CommunicationCategory::Transactional->defaultOptedIn());
        $this->assertTrue(CommunicationCategory::Operational->defaultOptedIn());
        $this->assertFalse(CommunicationCategory::Marketing->defaultOptedIn());
    }
}
