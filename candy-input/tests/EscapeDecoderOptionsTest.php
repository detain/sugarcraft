<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\EscapeDecoderOptions;

/**
 * Tests for EscapeDecoderOptions value object.
 */
final class EscapeDecoderOptionsTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $opts = new EscapeDecoderOptions();

        $this->assertTrue($opts->enableMouse);
        $this->assertTrue($opts->enableKitty);
        $this->assertTrue($opts->enableFocus);
        $this->assertTrue($opts->enablePaste);
    }

    public function testCustomValues(): void
    {
        $opts = new EscapeDecoderOptions(
            enableMouse: false,
            enableKitty: false,
            enableFocus: false,
            enablePaste: false,
        );

        $this->assertFalse($opts->enableMouse);
        $this->assertFalse($opts->enableKitty);
        $this->assertFalse($opts->enableFocus);
        $this->assertFalse($opts->enablePaste);
    }

    public function testPartialCustomization(): void
    {
        $opts = new EscapeDecoderOptions(enableMouse: false);

        $this->assertFalse($opts->enableMouse);
        $this->assertTrue($opts->enableKitty);
        $this->assertTrue($opts->enableFocus);
        $this->assertTrue($opts->enablePaste);
    }

    public function testMixOfEnabledAndDisabled(): void
    {
        $opts = new EscapeDecoderOptions(
            enableMouse: true,
            enableKitty: false,
            enableFocus: true,
            enablePaste: false,
        );

        $this->assertTrue($opts->enableMouse);
        $this->assertFalse($opts->enableKitty);
        $this->assertTrue($opts->enableFocus);
        $this->assertFalse($opts->enablePaste);
    }
}
