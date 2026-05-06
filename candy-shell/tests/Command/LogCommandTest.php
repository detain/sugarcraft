<?php

declare(strict_types=1);

namespace CandyCore\Shell\Tests\Command;

use CandyCore\Shell\Command\LogCommand;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for {@see LogCommand::resolveTimeFormat()} — Go-style
 * time-constant aliases mapped to PHP date() format strings.
 */
final class LogCommandTest extends TestCase
{
    public function testResolveKitchen(): void
    {
        $this->assertSame('g:ia', LogCommand::resolveTimeFormat('kitchen'));
    }

    public function testResolveRfc3339(): void
    {
        $this->assertSame("Y-m-d\\TH:i:sP", LogCommand::resolveTimeFormat('rfc3339'));
    }

    public function testResolveDateOnly(): void
    {
        $this->assertSame('Y-m-d', LogCommand::resolveTimeFormat('dateonly'));
    }

    public function testResolveTimeOnly(): void
    {
        $this->assertSame('H:i:s', LogCommand::resolveTimeFormat('timeonly'));
    }

    public function testResolveDateTime(): void
    {
        $this->assertSame('Y-m-d H:i:s', LogCommand::resolveTimeFormat('datetime'));
    }

    public function testResolveAnsic(): void
    {
        $this->assertSame('D M j H:i:s Y', LogCommand::resolveTimeFormat('ansic'));
    }

    public function testCaseInsensitive(): void
    {
        $this->assertSame('g:ia', LogCommand::resolveTimeFormat('Kitchen'));
        $this->assertSame('g:ia', LogCommand::resolveTimeFormat('KITCHEN'));
    }

    public function testUnknownPassesThrough(): void
    {
        // Custom date() format strings should round-trip unchanged.
        $this->assertSame('Y-m-d', LogCommand::resolveTimeFormat('Y-m-d'));
        $this->assertSame('H:i:s', LogCommand::resolveTimeFormat('H:i:s'));
    }

    public function testFormatLineRunsResolutionForAlias(): void
    {
        // Sanity check: passing 'kitchen' through formatLine should
        // produce a time fragment matching PHP's `g:ia` pattern, not
        // the literal string 'kitchen'.
        $line = LogCommand::formatLine(\CandyCore\Shell\Log\LogLevel::Info, 'hi', '', 'kitchen', 'logfmt');
        $this->assertStringContainsString('time=', $line);
        $this->assertDoesNotMatchRegularExpression('/time=kitchen/', $line);
    }
}
