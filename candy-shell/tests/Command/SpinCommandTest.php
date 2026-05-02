<?php

declare(strict_types=1);

namespace CandyCore\Shell\Tests\Command;

use CandyCore\Bits\Spinner\Style as SpinStyle;
use CandyCore\Shell\Command\SpinCommand;
use PHPUnit\Framework\TestCase;

final class SpinCommandTest extends TestCase
{
    public function testStyleAliases(): void
    {
        foreach (['line','dot','minidot','points','pulse','globe','meter'] as $name) {
            $this->assertInstanceOf(SpinStyle::class, SpinCommand::pickStyle($name));
        }
    }

    public function testStyleCaseInsensitive(): void
    {
        $this->assertInstanceOf(SpinStyle::class, SpinCommand::pickStyle('DOT'));
    }

    public function testUnknownStyleRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SpinCommand::pickStyle('whirl');
    }

    public function testInterruptedExitCodeIs130(): void
    {
        // Conventional SIGINT-exit code: scripts use this to detect a
        // user-cancelled spin run.
        $this->assertSame(130, SpinCommand::EXIT_INTERRUPTED);
    }
}
