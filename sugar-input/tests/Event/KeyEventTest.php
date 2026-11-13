<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Input\KeyModifier;

/**
 * Tests for KeyEvent static factory methods.
 */
final class KeyEventTest extends TestCase
{
    // --- plain() factory tests ---

    public function testPlainCreatesEventWithCharAndProvidedModifiers(): void
    {
        $modifiers = KeyModifier::none();
        $event = KeyEvent::plain('a', $modifiers);

        $this->assertSame('a', $event->key);
        $this->assertSame('a', $event->raw);
    }

    public function testPlainCreatesEventWithCtrlModifier(): void
    {
        $modifiers = KeyModifier::ctrl();
        $event = KeyEvent::plain('c', $modifiers);

        $this->assertSame('c', $event->key);
        $this->assertTrue($event->modifiers->includes(KeyModifier::CTRL));
    }

    public function testPlainUsesProvidedModifiersNotNone(): void
    {
        $modifiers = KeyModifier::altShift();
        $event = KeyEvent::plain('x', $modifiers);

        $this->assertSame('x', $event->key);
        $this->assertTrue($event->modifiers->includes(KeyModifier::ALT));
        $this->assertTrue($event->modifiers->includes(KeyModifier::SHIFT));
        $this->assertFalse($event->modifiers->includes(KeyModifier::CTRL));
    }

    public function testPlainRawMatchesKeyWhenOnlyCharProvided(): void
    {
        $event = KeyEvent::plain('Enter', KeyModifier::none());

        $this->assertSame('Enter', $event->key);
        $this->assertSame('Enter', $event->raw);
    }

    public function testPlainWithAltModifier(): void
    {
        $modifiers = KeyModifier::alt();
        $event = KeyEvent::plain('b', $modifiers);

        $this->assertSame('b', $event->key);
        $this->assertTrue($event->modifiers->includes(KeyModifier::ALT));
    }

    public function testPlainWithShiftModifier(): void
    {
        $modifiers = KeyModifier::shift();
        $event = KeyEvent::plain('A', $modifiers);

        $this->assertSame('A', $event->key);
        $this->assertTrue($event->modifiers->includes(KeyModifier::SHIFT));
    }

    public function testPlainWithCombinedModifiers(): void
    {
        $modifiers = KeyModifier::altCtrl();
        $event = KeyEvent::plain('d', $modifiers);

        $this->assertSame('d', $event->key);
        $this->assertTrue($event->modifiers->includes(KeyModifier::ALT));
        $this->assertTrue($event->modifiers->includes(KeyModifier::CTRL));
    }

    // --- esc() factory tests ---

    public function testEscCreatesEventWithKeyAndModifiers(): void
    {
        $modifiers = KeyModifier::none();
        $event = KeyEvent::esc('ArrowUp', $modifiers);

        $this->assertSame('ArrowUp', $event->key);
        $this->assertSame("\x1b", $event->raw);
    }

    public function testEscCreatesEventWithCtrlModifier(): void
    {
        $modifiers = KeyModifier::ctrl();
        $event = KeyEvent::esc('ArrowDown', $modifiers);

        $this->assertSame('ArrowDown', $event->key);
        $this->assertTrue($event->modifiers->includes(KeyModifier::CTRL));
    }

    public function testEscUsesProvidedRawWhenSpecified(): void
    {
        $modifiers = KeyModifier::alt();
        $raw = "\x1b[1;3A"; // Alt+ArrowUp in Kitty format
        $event = KeyEvent::esc('ArrowUp', $modifiers, $raw);

        $this->assertSame('ArrowUp', $event->key);
        $this->assertSame($raw, $event->raw);
    }

    public function testEscUsesDefaultRawWhenEmptyStringProvided(): void
    {
        $event = KeyEvent::esc('Escape', KeyModifier::none(), '');

        $this->assertSame('Escape', $event->key);
        $this->assertSame("\x1b", $event->raw);
    }

    public function testEscWithFunctionKey(): void
    {
        $event = KeyEvent::esc('F1', KeyModifier::none());

        $this->assertSame('F1', $event->key);
        $this->assertSame("\x1b", $event->raw);
    }

    public function testEscWithModifierAndComplexRaw(): void
    {
        $modifiers = KeyModifier::altCtrl();
        $raw = "\x1b[1;5D"; // Alt+Ctrl+ArrowLeft in Kitty format
        $event = KeyEvent::esc('ArrowLeft', $modifiers, $raw);

        $this->assertSame('ArrowLeft', $event->key);
        $this->assertTrue($event->modifiers->includes(KeyModifier::ALT));
        $this->assertTrue($event->modifiers->includes(KeyModifier::CTRL));
        $this->assertSame($raw, $event->raw);
    }

    public function testEscWithShiftModifier(): void
    {
        $modifiers = KeyModifier::shift();
        $event = KeyEvent::esc('Home', $modifiers);

        $this->assertSame('Home', $event->key);
        $this->assertTrue($event->modifiers->includes(KeyModifier::SHIFT));
    }

    public function testEscWithAltShiftModifiers(): void
    {
        $modifiers = KeyModifier::altShift();
        $raw = "\x1b[1;4A"; // Alt+Shift+ArrowUp
        $event = KeyEvent::esc('ArrowUp', $modifiers, $raw);

        $this->assertSame('ArrowUp', $event->key);
        $this->assertTrue($event->modifiers->includes(KeyModifier::ALT));
        $this->assertTrue($event->modifiers->includes(KeyModifier::SHIFT));
        $this->assertSame($raw, $event->raw);
    }

    // ─── Modifier-defaulted factory tests (Step 9) ───────────────────────────

    public function testPlainWithoutModifierDefaultsToNone(): void
    {
        $event = KeyEvent::plain('a');

        $this->assertSame('a', $event->key);
        $this->assertSame('a', $event->raw);
        $this->assertSame(KeyModifier::NONE, $event->modifiers->value());
        $this->assertInstanceOf(KeyModifier::class, $event->modifiers);
    }

    public function testEscWithoutModifierDefaultsToNone(): void
    {
        $event = KeyEvent::esc('F1');

        $this->assertSame('F1', $event->key);
        $this->assertSame("\x1b", $event->raw);
        $this->assertSame(KeyModifier::NONE, $event->modifiers->value());
        $this->assertInstanceOf(KeyModifier::class, $event->modifiers);
    }
}
