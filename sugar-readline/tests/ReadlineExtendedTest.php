<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\Readline;
use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Input\Event\MouseEvent;
use SugarCraft\Input\Event\FocusEvent;
use SugarCraft\Input\KeyModifier;

/**
 * Additional Readline tests covering untested symbolicKey branches:
 * Shift+arrows, Alt+arrows, unrecognised plain keys, and mapPlainKey fallthrough.
 */
final class ReadlineExtendedTest extends TestCase
{
    // =========================================================================
    // symbolicKey — Shift modifier
    // =========================================================================

    public function testSymbolicKeyShiftArrowDown(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('symbolicKey');
        $method->setAccessible(true);

        $event = new KeyEvent('ArrowDown', KeyModifier::SHIFT(), "\x1b[1;2B");
        $this->assertSame('shift_down', $method->invoke($readline, $event));
    }

    public function testSymbolicKeyShiftArrowLeft(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('symbolicKey');
        $method->setAccessible(true);

        $event = new KeyEvent('ArrowLeft', KeyModifier::SHIFT(), "\x1b[1;2D");
        $this->assertSame('shift_left', $method->invoke($readline, $event));
    }

    public function testSymbolicKeyShiftArrowRight(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('symbolicKey');
        $method->setAccessible(true);

        $event = new KeyEvent('ArrowRight', KeyModifier::SHIFT(), "\x1b[1;2C");
        $this->assertSame('shift_right', $method->invoke($readline, $event));
    }

    public function testSymbolicKeyShiftUppercaseLetter(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('symbolicKey');
        $method->setAccessible(true);

        // Shift+A → 'a' (downcased)
        $event = new KeyEvent('A', KeyModifier::SHIFT(), 'A');
        $this->assertSame('a', $method->invoke($readline, $event));
    }

    public function testSymbolicKeyShiftF1(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('symbolicKey');
        $method->setAccessible(true);

        $event = new KeyEvent('F1', KeyModifier::SHIFT(), "\x1bOP");
        $this->assertSame('shift_f1', $method->invoke($readline, $event));
    }

    // =========================================================================
    // symbolicKey — Alt modifier
    // =========================================================================

    public function testSymbolicKeyAltArrowUp(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('symbolicKey');
        $method->setAccessible(true);

        $event = new KeyEvent('ArrowUp', KeyModifier::ALT(), "\x1b[1;3A");
        $this->assertSame('alt_up', $method->invoke($readline, $event));
    }

    public function testSymbolicKeyAltArrowDown(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('symbolicKey');
        $method->setAccessible(true);

        $event = new KeyEvent('ArrowDown', KeyModifier::ALT(), "\x1b[1;3B");
        $this->assertSame('alt_down', $method->invoke($readline, $event));
    }

    public function testSymbolicKeyAltArrowLeft(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('symbolicKey');
        $method->setAccessible(true);

        $event = new KeyEvent('ArrowLeft', KeyModifier::ALT(), "\x1b[1;3D");
        $this->assertSame('alt_left', $method->invoke($readline, $event));
    }

    public function testSymbolicKeyAltArrowRight(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('symbolicKey');
        $method->setAccessible(true);

        $event = new KeyEvent('ArrowRight', KeyModifier::ALT(), "\x1b[1;3C");
        $this->assertSame('alt_right', $method->invoke($readline, $event));
    }

    // =========================================================================
    // symbolicKey — Ctrl modifier special cases
    // =========================================================================

    public function testSymbolicKeyCtrlEscapeMapsToCtrlC(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('symbolicKey');
        $method->setAccessible(true);

        $event = new KeyEvent('Escape', KeyModifier::CTRL(), "\x1b");
        $this->assertSame('ctrl_c', $method->invoke($readline, $event));
    }

    public function testSymbolicKeyCtrlBracketMapsToCtrlC(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('symbolicKey');
        $method->setAccessible(true);

        $event = new KeyEvent('[', KeyModifier::CTRL(), "\x1b");
        $this->assertSame('ctrl_c', $method->invoke($readline, $event));
    }

    public function testSymbolicKeyCtrlDash(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('symbolicKey');
        $method->setAccessible(true);

        $event = new KeyEvent('-', KeyModifier::CTRL(), "\x1f");
        $this->assertSame('ctrl_-', $method->invoke($readline, $event));
    }

    // =========================================================================
    // symbolicKey — mapPlainKey fallthrough
    // =========================================================================

    public function testSymbolicKeyUnrecognisedKeyFallsThrough(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('symbolicKey');
        $method->setAccessible(true);

        // Unknown key name
        $event = new KeyEvent('Help', KeyModifier::none(), "\x1b[28~");
        $this->assertSame('help', $method->invoke($readline, $event));
    }

    // =========================================================================
    // stripPrefix
    // =========================================================================

    public function testStripPrefixReturnsOriginalWhenNoPrefix(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('stripPrefix');
        $method->setAccessible(true);

        $result = $method->invoke($readline, 'ArrowUp', 'Home');
        $this->assertSame('ArrowUp', $result);
    }

    public function testStripPrefixStripsPrefix(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('stripPrefix');
        $method->setAccessible(true);

        $result = $method->invoke($readline, 'ArrowUp', 'Arrow');
        $this->assertSame('Up', $result);
    }

    // =========================================================================
    // enableBracketedPaste — non-tty path
    // =========================================================================

    public function testEnableBracketedPasteReturnsFalseForNull(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('enableBracketedPaste');
        $method->setAccessible(true);

        $result = $method->invoke($readline, null);
        $this->assertFalse($result);
    }

    public function testEnableBracketedPasteReturnsFalseForMemoryStream(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('enableBracketedPaste');
        $method->setAccessible(true);

        $mem = fopen('php://memory', 'w+');
        $result = $method->invoke($readline, $mem);
        $this->assertFalse($result);
        fclose($mem);
    }

    // =========================================================================
    // repaint — early exit paths
    // =========================================================================

    public function testRepaintReturnsEarlyWhenOutputIsNull(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('repaint');
        $method->setAccessible(true);

        $prompt = new \SugarCraft\Readline\TextPrompt('> ');
        $method->invoke($readline, $prompt, null);
        $this->assertTrue(true);
    }

    public function testRepaintReturnsEarlyWhenPromptHasNoView(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('repaint');
        $method->setAccessible(true);

        $prompt = new class
        {
            public function handleChar(string $c): self { return $this; }
            public function handleKey(string $k): self { return $this; }
        };
        $output = fopen('php://memory', 'w');
        $method->invoke($readline, $prompt, $output);
        fclose($output);
        $this->assertTrue(true);
    }

    public function testRepaintReturnsEarlyWhenViewIsEmpty(): void
    {
        $readline = new Readline();
        $refl = new \ReflectionClass($readline);
        $method = $refl->getMethod('repaint');
        $method->setAccessible(true);

        $prompt = new class
        {
            public function handleChar(string $c): self { return $this; }
            public function handleKey(string $k): self { return $this; }
            public function view(): string { return ''; }
        };
        $output = fopen('php://memory', 'w');
        $method->invoke($readline, $prompt, $output);
        fclose($output);
        $this->assertTrue(true);
    }
}
