<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use SugarCraft\Core\Util\Color;
use SugarCraft\Log\Styles;
use SugarCraft\Style\Style;
use PHPUnit\Framework\TestCase;

final class StylesTest extends TestCase
{
    public function testPadLevelTextPadsShortLabels(): void
    {
        $this->assertSame('DBG  ', Styles::padLevelText('DBG'));
        $this->assertSame('INF  ', Styles::padLevelText('INF'));
        $this->assertSame('WRN  ', Styles::padLevelText('WRN'));
    }

    public function testPadLevelTextLeavesLongLabelsUnchanged(): void
    {
        $this->assertSame('DEBUG', Styles::padLevelText('DEBUG'));
        $this->assertSame('ERROR', Styles::padLevelText('ERROR'));
        $this->assertSame('FATAL', Styles::padLevelText('FATAL'));
    }

    public function testKeyStyleReturnsStyleForKnownField(): void
    {
        $styles = Styles::default();

        $this->assertInstanceOf(Style::class, $styles->keyStyle('time'));
        $this->assertInstanceOf(Style::class, $styles->keyStyle('level'));
        $this->assertInstanceOf(Style::class, $styles->keyStyle('prefix'));
        $this->assertInstanceOf(Style::class, $styles->keyStyle('caller'));
        $this->assertInstanceOf(Style::class, $styles->keyStyle('message'));
        $this->assertInstanceOf(Style::class, $styles->keyStyle('key'));
        $this->assertInstanceOf(Style::class, $styles->keyStyle('value'));
    }

    public function testKeyStyleReturnsDefaultStyleForUnknownField(): void
    {
        $styles = Styles::default();
        $this->assertInstanceOf(Style::class, $styles->keyStyle('unknown'));
    }

    public function testKeysMapIsPopulated(): void
    {
        $styles = Styles::default();
        $this->assertArrayHasKey('time', $styles->keys);
        $this->assertArrayHasKey('level', $styles->keys);
        $this->assertArrayHasKey('prefix', $styles->keys);
        $this->assertArrayHasKey('caller', $styles->keys);
        $this->assertArrayHasKey('message', $styles->keys);
        $this->assertArrayHasKey('key', $styles->keys);
        $this->assertArrayHasKey('value', $styles->keys);
    }

    public function testDefaultReturnsNewInstance(): void
    {
        $a = Styles::default();
        $b = Styles::default();
        $this->assertInstanceOf(Styles::class, $a);
        $this->assertInstanceOf(Styles::class, $b);
    }

    public function testDefaultIsOneSharedProcessWideInstance(): void
    {
        // M6 truth: default() is a memoized singleton — Logger::styles() and
        // every TextFormatter built without an explicit Styles object share
        // this one MUTABLE object. Style mutations made through it are global
        // for the process; the README documents the per-logger escape hatch.
        $this->assertSame(Styles::default(), Styles::default());
    }

    public function testFreshInstanceIsolatesPerLoggerStyling(): void
    {
        $mine = new Styles();
        $this->assertNotSame($mine, Styles::default());

        $mine->keys['time'] = Style::new()->foreground(Color::ansi(6));

        // The shared default keeps its identity (raw) time style untouched.
        $this->assertSame('x', Styles::default()->keys['time']->render('x'));
        $this->assertNotSame('x', $mine->keys['time']->render('x'));
    }

    public function testEnvelopeKeyDefaultsAreIdentityStyles(): void
    {
        // Regression pin for the dead-knob fix: the per-field defaults must be
        // byte-identity (raw output) so wiring them live changes nothing for
        // consumers that never configured them.
        foreach (['time', 'level', 'prefix', 'caller', 'message', 'value'] as $field) {
            $this->assertSame('x', Styles::default()->keys[$field]->render('x'), "keys[{$field}] must default to identity");
        }
        // Context pairs ARE styled by default (whole-pair gray) — that knob
        // was live before M7 and must stay so.
        $this->assertNotSame('x', Styles::default()->keys['key']->render('x'));
    }

    public function testPadLevelTextDefaultsOff(): void
    {
        $this->assertFalse((new Styles())->padLevelText);
        $this->assertFalse(Styles::default()->padLevelText);
    }
}
