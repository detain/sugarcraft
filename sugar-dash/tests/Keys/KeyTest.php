<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Keys;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Keys\Key;

/**
 * Tests for the Key value object.
 */
final class KeyTest extends TestCase
{
    public function testBasicKeyCreation(): void
    {
        $key = new Key('a');
        $this->assertSame('a', $key->key);
        $this->assertFalse($key->ctrl);
        $this->assertFalse($key->alt);
        $this->assertFalse($key->shift);
    }

    public function testKeyWithModifiers(): void
    {
        $key = new Key('c', ctrl: true, alt: true, shift: true);
        $this->assertSame('c', $key->key);
        $this->assertTrue($key->ctrl);
        $this->assertTrue($key->alt);
        $this->assertTrue($key->shift);
    }

    public function testWithCtrl(): void
    {
        $key = new Key('c');
        $withCtrl = $key->withCtrl();

        $this->assertSame('c', $withCtrl->key);
        $this->assertTrue($withCtrl->ctrl);
        $this->assertFalse($withCtrl->alt);
        $this->assertFalse($withCtrl->shift);
        // Original unchanged
        $this->assertFalse($key->ctrl);
    }

    public function testWithAlt(): void
    {
        $key = new Key('e');
        $withAlt = $key->withAlt();

        $this->assertSame('e', $withAlt->key);
        $this->assertFalse($withAlt->ctrl);
        $this->assertTrue($withAlt->alt);
        $this->assertFalse($withAlt->shift);
    }

    public function testWithShift(): void
    {
        $key = new Key('x');
        $withShift = $key->withShift();

        $this->assertSame('x', $withShift->key);
        $this->assertFalse($withShift->ctrl);
        $this->assertFalse($withShift->alt);
        $this->assertTrue($withShift->shift);
    }

    public function testToStringPlainKey(): void
    {
        $key = new Key('a');
        $this->assertSame('A', $key->toString());
    }

    public function testToStringCtrl(): void
    {
        $key = new Key('c', ctrl: true);
        $this->assertSame('Ctrl+C', $key->toString());
    }

    public function testToStringCtrlAlt(): void
    {
        $key = new Key('d', ctrl: true, alt: true);
        $this->assertSame('Ctrl+Alt+D', $key->toString());
    }

    public function testToStringCtrlAltShift(): void
    {
        $key = new Key('e', ctrl: true, alt: true, shift: true);
        $this->assertSame('Ctrl+Alt+Shift+E', $key->toString());
    }

    public function testMatchesExact(): void
    {
        $key = new Key('x', ctrl: true, alt: false, shift: false);
        $this->assertTrue($key->matches('x', ctrl: true, alt: false, shift: false));
        $this->assertFalse($key->matches('x', ctrl: false, alt: false, shift: false));
        $this->assertFalse($key->matches('y', ctrl: true, alt: false, shift: false));
    }

    public function testMatchesAllModifiers(): void
    {
        $key = new Key('z', ctrl: true, alt: true, shift: true);
        $this->assertTrue($key->matches('z', ctrl: true, alt: true, shift: true));
        $this->assertFalse($key->matches('z', ctrl: true, alt: true, shift: false));
        $this->assertFalse($key->matches('z', ctrl: true, alt: false, shift: true));
    }
}
