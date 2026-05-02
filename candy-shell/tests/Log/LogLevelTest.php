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

    public function testOrderForMinLevelFiltering(): void
    {
        $this->assertSame(0, LogLevel::Debug->order());
        $this->assertSame(1, LogLevel::Info->order());
        $this->assertSame(2, LogLevel::Warn->order());
        $this->assertSame(3, LogLevel::Error->order());
        $this->assertSame(4, LogLevel::Fatal->order());
        $this->assertGreaterThan(LogLevel::Info->order(), LogLevel::Error->order());
    }

    public function testFormatLineLogfmt(): void
    {
        $line = LogCommand::formatLine(LogLevel::Info, 'hello world', '', '', 'logfmt');
        $this->assertStringContainsString('level=info', $line);
        $this->assertStringContainsString('msg="hello world"', $line);
    }

    public function testFormatLineJson(): void
    {
        $line = LogCommand::formatLine(LogLevel::Warn, 'oops', 'app', '', 'json');
        $decoded = json_decode($line, true);
        $this->assertSame('warn', $decoded['level']);
        $this->assertSame('oops', $decoded['message']);
        $this->assertSame('app',  $decoded['prefix']);
    }

    public function testFormatLineWithPrefix(): void
    {
        $line = LogCommand::formatLine(LogLevel::Info, 'hi', 'svc', '', 'text');
        $this->assertStringContainsString('svc', $line);
        $this->assertStringContainsString('hi', $line);
    }
}
