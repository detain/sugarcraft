<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use SugarCraft\Log\Level;
use PHPUnit\Framework\TestCase;

final class LevelTest extends TestCase
{
    public function testDebugValueIsNegativeFour(): void
    {
        $this->assertSame(-4, Level::Debug->value);
    }

    public function testInfoValueIsZero(): void
    {
        $this->assertSame(0, Level::Info->value);
    }

    public function testWarnValueIsFour(): void
    {
        $this->assertSame(4, Level::Warn->value);
    }

    public function testErrorValueIsEight(): void
    {
        $this->assertSame(8, Level::Error->value);
    }

    public function testFatalValueIsTwelve(): void
    {
        $this->assertSame(12, Level::Fatal->value);
    }

    public function testLabelReturnsFullUppercase(): void
    {
        $this->assertSame('DEBUG', Level::Debug->label());
        $this->assertSame('INFO', Level::Info->label());
        $this->assertSame('WARN', Level::Warn->label());
        $this->assertSame('ERROR', Level::Error->label());
        $this->assertSame('FATAL', Level::Fatal->label());
    }

    public function testShortLabelReturnsThreeLetterCode(): void
    {
        $this->assertSame('DBG', Level::Debug->shortLabel());
        $this->assertSame('INF', Level::Info->shortLabel());
        $this->assertSame('WRN', Level::Warn->shortLabel());
        $this->assertSame('ERR', Level::Error->shortLabel());
        $this->assertSame('FTL', Level::Fatal->shortLabel());
    }

    public function testCasesAreExhaustive(): void
    {
        $this->assertCount(5, Level::cases());
    }
}
