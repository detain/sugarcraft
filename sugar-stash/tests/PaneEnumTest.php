<?php

declare(strict_types=1);

namespace CandyCore\Stash\Tests;

use CandyCore\Stash\Pane;
use PHPUnit\Framework\TestCase;

final class PaneEnumTest extends TestCase
{
    public function testEnumValues(): void
    {
        $this->assertSame('status', Pane::Status->value);
        $this->assertSame('branches', Pane::Branches->value);
        $this->assertSame('log', Pane::Log->value);
    }

    public function testNextCyclesForward(): void
    {
        $this->assertSame(Pane::Branches, Pane::Status->next());
        $this->assertSame(Pane::Log, Pane::Branches->next());
        $this->assertSame(Pane::Status, Pane::Log->next());
    }

    public function testNextIsCyclic(): void
    {
        $p = Pane::Status;
        $p = $p->next()->next()->next();
        $this->assertSame(Pane::Status, $p);
    }
}
