<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\Repair\Dto;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Dto\RepairProposal;

#[CoversClass(RepairProposal::class)]
final class RepairProposalTest extends TestCase
{
    #[Test]
    public function acceptsConfidenceInRange(): void
    {
        $proposal = new RepairProposal(RepairProposal::TYPE_RELINK_BY_NAME, 'Test', 0.5, []);
        self::assertSame(0.5, $proposal->confidence);
    }

    #[Test]
    public function rejectsConfidenceBelowZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RepairProposal(RepairProposal::TYPE_RELINK_BY_NAME, 'Test', -0.1, []);
    }

    #[Test]
    public function rejectsConfidenceAboveOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RepairProposal(RepairProposal::TYPE_RELINK_BY_NAME, 'Test', 1.1, []);
    }
}
