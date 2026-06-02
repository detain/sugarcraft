<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests;

use SugarCraft\Query\Pane;
use PHPUnit\Framework\TestCase;

final class PaneEnumTest extends TestCase
{
    public function testEnumValues(): void
    {
        $this->assertSame('tables', Pane::Tables->value);
        $this->assertSame('rows', Pane::Rows->value);
        $this->assertSame('query', Pane::Query->value);
        $this->assertSame('admin', Pane::Admin->value);
    }

    public function testNextCyclesForward(): void
    {
        $this->assertSame(Pane::Rows, Pane::Tables->next());
        $this->assertSame(Pane::Query, Pane::Rows->next());
        $this->assertSame(Pane::Admin, Pane::Query->next());
        $this->assertSame(Pane::Tables, Pane::Admin->next());
    }

    public function testFourNextsReturnToStart(): void
    {
        $this->assertSame(Pane::Tables, Pane::Tables->next()->next()->next()->next());
    }
}
