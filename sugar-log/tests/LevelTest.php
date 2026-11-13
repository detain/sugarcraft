<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use SugarCraft\Log\Level;
use Psr\Log\LogLevel;
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

    public function testFromPsrStringMapsEveryLevelConstant(): void
    {
        // [API-01] The bridge's 8-way routing is now public enum surface;
        // the same inputs, the same coarse targets.
        $this->assertSame(Level::Fatal, Level::fromPsrString(LogLevel::EMERGENCY));
        $this->assertSame(Level::Fatal, Level::fromPsrString(LogLevel::ALERT));
        $this->assertSame(Level::Fatal, Level::fromPsrString(LogLevel::CRITICAL));
        $this->assertSame(Level::Error, Level::fromPsrString(LogLevel::ERROR));
        $this->assertSame(Level::Warn, Level::fromPsrString(LogLevel::WARNING));
        $this->assertSame(Level::Info, Level::fromPsrString(LogLevel::NOTICE));
        $this->assertSame(Level::Info, Level::fromPsrString(LogLevel::INFO));
        $this->assertSame(Level::Debug, Level::fromPsrString(LogLevel::DEBUG));
    }

    public function testFromPsrStringReturnsNullForUnknownStrings(): void
    {
        $this->assertNull(Level::fromPsrString('nope'));
        $this->assertNull(Level::fromPsrString(''));
        $this->assertNull(Level::fromPsrString('emergency '));
    }

    public function testToPsrStringRoundTripsEveryLevel(): void
    {
        // toPsrString is the canonical inverse: each emitted string must map
        // straight back to the level it came from.
        foreach (Level::cases() as $level) {
            $this->assertSame($level, Level::fromPsrString($level->toPsrString()));
        }
    }
}
