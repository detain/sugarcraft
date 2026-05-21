<?php

declare(strict_types=1);

namespace SugarCraft\Spark\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Spark\Inspector;

final class UnderlineStylesTest extends TestCase
{
    public function testUnderlineSingle(): void
    {
        $seg = Inspector::parse("\x1b[4:1m")[0];
        $this->assertStringContainsString('underline single', $seg->describe());
    }

    public function testUnderlineDouble(): void
    {
        $seg = Inspector::parse("\x1b[4:2m")[0];
        $this->assertStringContainsString('underline double', $seg->describe());
    }

    public function testUnderlineCurly(): void
    {
        $seg = Inspector::parse("\x1b[4:3m")[0];
        $this->assertStringContainsString('underline curly', $seg->describe());
    }

    public function testUnderlineDotted(): void
    {
        $seg = Inspector::parse("\x1b[4:4m")[0];
        $this->assertStringContainsString('underline dotted', $seg->describe());
    }

    public function testUnderlineDashed(): void
    {
        $seg = Inspector::parse("\x1b[4:5m")[0];
        $this->assertStringContainsString('underline dashed', $seg->describe());
    }

    public function testUnderlineStyleUnknownSub(): void
    {
        // 4:6 is not a defined style, should fall back gracefully.
        $seg = Inspector::parse("\x1b[4:6m")[0];
        $this->assertStringContainsString('underline style 6', $seg->describe());
    }

    public function testUnderlineStyleCompoundWithBold(): void
    {
        // Compound: bold + underline double.
        $seg = Inspector::parse("\x1b[1;4:2m")[0];
        $desc = $seg->describe();
        $this->assertStringContainsString('bold', $desc);
        $this->assertStringContainsString('underline double', $desc);
    }

    public function testUnderlineStyleCompoundWithForeground(): void
    {
        // Compound: red + underline single.
        $seg = Inspector::parse("\x1b[31;4:1m")[0];
        $desc = $seg->describe();
        $this->assertStringContainsString('foreground red', $desc);
        $this->assertStringContainsString('underline single', $desc);
    }

    public function testUnderlineStyleCompoundAllThree(): void
    {
        // Compound: bold + red + underline dashed.
        $seg = Inspector::parse("\x1b[1;31;4:5m")[0];
        $desc = $seg->describe();
        $this->assertStringContainsString('bold', $desc);
        $this->assertStringContainsString('foreground red', $desc);
        $this->assertStringContainsString('underline dashed', $desc);
    }

    public function testUnderlineStyleUnknownCompoundStillParsesRest(): void
    {
        // Unknown underline sub-style 99 should not break the rest.
        $seg = Inspector::parse("\x1b[1;4:99m")[0];
        $desc = $seg->describe();
        $this->assertStringContainsString('bold', $desc);
        $this->assertStringContainsString('underline style 99', $desc);
    }
}