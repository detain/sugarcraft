<?php

declare(strict_types=1);

namespace CandyCore\Query\Tests;

use CandyCore\Query\Pane;
use PHPUnit\Framework\TestCase;

final class PaneEnumTest extends TestCase
{
    public function testEnumValues(): void
    {
        $this->assertSame('tables', Pane::Tables->value);
        $this->assertSame('rows', Pane::Rows->value);
        $this->assertSame('query', Pane::Query->value);
    }

    public function testNextCyclesForward(): void
    {
        $this->assertSame(Pane::Rows, Pane::Tables->next());
        $this->assertSame(Pane::Query, Pane::Rows->next());
        $this->assertSame(Pane::Tables, Pane::Query->next());
    }

    public function testThreeNextsReturnToStart(): void
    {
        $this->assertSame(Pane::Tables, Pane::Tables->next()->next()->next());
    }
}
