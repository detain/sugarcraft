<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Top;

final class TopTest extends TestCase
{
    public function testInstantiatesWithDefaultTick(): void
    {
        $top = Top::new();

        $this->assertSame(Top::DEFAULT_TICK_MS, $top->tickMs);
        $this->assertSame(2000, Top::DEFAULT_TICK_MS, 'btop update_ms default');
    }
}
