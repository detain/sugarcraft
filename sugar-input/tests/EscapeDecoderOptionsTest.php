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

    public function testOptionsAreImmutable(): void
    {
        // m1: a decoder captures its options at construction; a mid-session
        // property write would silently diverge the live decoder from the
        // object the host still holds. The flags are readonly — changing a
        // protocol mix means passing a new instance to a new decoder.
        $opts = new EscapeDecoderOptions();

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');

        /** @phpstan-ignore-next-line intentional readonly-violation probe */
        $opts->enableMouse = false;
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

    public function testEachWithDerivesANewInstanceAndFlipsOnlyItsOwnField(): void
    {
        $derivations = [
            'withMouse' => 'enableMouse',
            'withKitty' => 'enableKitty',
            'withFocus' => 'enableFocus',
            'withPaste' => 'enablePaste',
            'withTrailingEscapeDeferral' => 'deferTrailingEscape',
        ];

        // Start from the all-disabled mix so every flip is toward true, and
        // no field can be confused with its default.
        $base = new EscapeDecoderOptions(
            enableMouse: false,
            enableKitty: false,
            enableFocus: false,
            enablePaste: false,
        );

        foreach ($derivations as $method => $property) {
            $derived = $base->{$method}();

            $this->assertNotSame($base, $derived, $method . ' must return a new instance');
            $this->assertTrue($derived->{$property}, $method . ' sets ' . $property);

            foreach ($derivations as $otherMethod => $otherProperty) {
                if ($otherProperty === $property) {
                    continue;
                }
                $this->assertFalse($derived->{$otherProperty}, $method . ' leaves ' . $otherProperty . ' alone');
            }

            $this->assertFalse($base->{$property}, $method . ' must not mutate the original');
        }
    }

    public function testWithTakesAnExplicitFalseToDisableAgain(): void
    {
        $opts = (new EscapeDecoderOptions())->withKitty(false);

        $this->assertFalse($opts->enableKitty);
        $this->assertTrue($opts->enableMouse);
    }

    public function testChainedDerivationsCompose(): void
    {
        $opts = (new EscapeDecoderOptions())
            ->withMouse(false)
            ->withTrailingEscapeDeferral();

        $this->assertFalse($opts->enableMouse);
        $this->assertTrue($opts->deferTrailingEscape);
        $this->assertTrue($opts->enableKitty);
        $this->assertTrue($opts->enableFocus);
        $this->assertTrue($opts->enablePaste);
    }
}
