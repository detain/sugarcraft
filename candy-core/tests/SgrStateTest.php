<?php

declare(strict_types=1);

namespace CandyCore\Core\Tests;

use CandyCore\Core\SgrState;
use CandyCore\Core\Util\Parser;
use CandyCore\Core\Util\Token;
use PHPUnit\Framework\TestCase;

final class SgrStateTest extends TestCase
{
    public function testInitialStateEmitsNothing(): void
    {
        $this->assertSame('', SgrState::initial()->toPrefix());
    }

    public function testApplyBoldRedPrefix(): void
    {
        $s = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[1;31m") as $t) {
            $s->applyCsi($t);
        }
        $prefix = $s->toPrefix();
        $this->assertStringContainsString("\x1b[", $prefix);
        $this->assertStringContainsString('1', $prefix);
        $this->assertStringContainsString("\x1b[31m", $prefix);
    }

    public function testResetClearsState(): void
    {
        $s = SgrState::initial();
        $tokens = (new Parser())->parse("\x1b[1;31m\x1b[0m");
        foreach ($tokens as $t) {
            $s->applyCsi($t);
        }
        $this->assertTrue($s->isDefault());
    }

    public function testBackgroundColour(): void
    {
        $s = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[44m") as $t) {
            $s->applyCsi($t);
        }
        $this->assertStringContainsString("\x1b[44m", $s->toPrefix());
    }

    public function testTrueColourFg(): void
    {
        $s = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[38;2;255;128;0m") as $t) {
            $s->applyCsi($t);
        }
        $this->assertStringContainsString("\x1b[38;2;255;128;0m", $s->toPrefix());
    }

    public function testCsi256Fg(): void
    {
        $s = SgrState::initial();
        foreach ((new Parser())->parse("\x1b[38;5;42m") as $t) {
            $s->applyCsi($t);
        }
        $this->assertStringContainsString("\x1b[38;5;42m", $s->toPrefix());
    }

    public function testNonSgrTokenIgnored(): void
    {
        $s = SgrState::initial();
        $tokens = (new Parser())->parse("\x1b[5A"); // cursor up — final 'A', not 'm'
        foreach ($tokens as $t) {
            $s->applyCsi($t);
        }
        $this->assertTrue($s->isDefault());
    }
}
