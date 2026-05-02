<?php

declare(strict_types=1);

namespace CandyCore\Shell\Tests\Log;

use CandyCore\Shell\Command\LogCommand;
use CandyCore\Shell\Log\LogLevel;
use PHPUnit\Framework\TestCase;

final class LogLevelTest extends TestCase
{
    public function testFromStringAliases(): void
    {
        $this->assertSame(LogLevel::Debug, LogLevel::fromString('debug'));
        $this->assertSame(LogLevel::Debug, LogLevel::fromString('dbg'));
        $this->assertSame(LogLevel::Info,  LogLevel::fromString('info'));
        $this->assertSame(LogLevel::Info,  LogLevel::fromString(''));
        $this->assertSame(LogLevel::Warn,  LogLevel::fromString('warning'));
        $this->assertSame(LogLevel::Error, LogLevel::fromString('err'));
        $this->assertSame(LogLevel::Fatal, LogLevel::fromString('fatal'));
    }

    public function testFromStringRejectsUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LogLevel::fromString('catastrophic');
    }

    public function testBadgeIsUppercase(): void
    {
        $this->assertSame('WARN', LogLevel::Warn->badge());
    }

    public function testFormatProducesStyledBadgePlusMessage(): void
    {
        $line = LogCommand::format(LogLevel::Error, 'kaboom');
        $this->assertStringContainsString('ERROR', $line);
        $this->assertStringContainsString('kaboom', $line);
        $this->assertStringContainsString("\x1b[", $line); // SGR styling
    }
}
