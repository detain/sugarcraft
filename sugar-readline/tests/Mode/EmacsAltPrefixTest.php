<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\Mode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\Mode\EmacsMode;
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

/**
 * S-13 (finding 010): an armed meta prefix must not swallow the next key.
 * Bound Alt+B/F/D consume it; anything else — Ctrl+C, Enter, arrows, plain
 * characters — is dispatched as if the Escape had never been typed.
 */
final class EmacsAltPrefixTest extends TestCase
{
    private function armed(): TextPrompt
    {
        return TextPrompt::new('> ')->withMode(new EmacsMode())->handleKey(Key::Escape);
    }

    public function testArmedCtrlCAborts(): void
    {
        $prompt = $this->armed()->handleKey(Key::CtrlC);
        $this->assertTrue($prompt->isAborted(), 'Ctrl+C after the meta prefix must reach the abort binding');
    }

    public function testArmedEnterSubmits(): void
    {
        $prompt = $this->armed()->handleKey(Key::Enter);
        $this->assertTrue($prompt->isSubmitted(), 'Enter after the meta prefix must reach the submit binding');
    }

    public function testArmedPlainCharInserts(): void
    {
        $prompt = $this->armed()->handleKey('x');
        $this->assertSame('x', $prompt->value(), 'an unbound Alt+key falls through to self-insert');
        $this->assertSame(1, $prompt->cursor());
    }

    public function testArmedArrowMoves(): void
    {
        $prompt = TextPrompt::new('> ')->withMode(new EmacsMode())
            ->handleChar('a')->handleChar('b')->handleChar('c')
            ->handleKey(Key::Escape)
            ->handleKey(Key::Left);
        $this->assertSame(2, $prompt->cursor(), 'arrows survive an armed prefix (left = one cell, not a word jump)');
    }

    public function testBoundAltLegStillConsumes(): void
    {
        $prompt = TextPrompt::new('> ')->withMode(new EmacsMode())
            ->handleChar('f')->handleChar('o')->handleChar('o')->handleChar(' ')
            ->handleChar('b')->handleChar('a')->handleChar('r')
            ->handleKey(Key::Escape)
            ->handleKey('b');
        // Alt+B consumed: word back to index 4 — a forwarded plain 'b' would
        // have inserted text instead.
        $this->assertSame(4, $prompt->cursor());
        $this->assertSame('foo bar', $prompt->value());
    }

    public function testKittyUnboundChordStaysNoOp(): void
    {
        // alt_g never armed a prefix — the chord path keeps the old no-op.
        $prompt = TextPrompt::new('> ')->withMode(new EmacsMode())->handleKey('alt_g');
        $this->assertSame('', $prompt->value());
    }

    public function testKittyBoundChordMoves(): void
    {
        $prompt = TextPrompt::new('> ')->withMode(new EmacsMode())
            ->handleChar('f')->handleChar('o')->handleChar('o')->handleChar(' ')
            ->handleChar('b')->handleChar('a')->handleChar('r')
            ->handleKey('alt_b');
        $this->assertSame(4, $prompt->cursor());
    }

    /**
     * Wire-level QA reproduction from the finding, driven event-wise (the
     * byte decoder legitimately merges a bare ESC+0x03 into one alt-chord
     * event; that merge is the classic meta encoding, not this bug). With
     * the prefix armed, a dispatchable Ctrl+C must reach the abort binding:
     * before the fix the unknown-alt no-op left isAborted() false.
     */
    public function testPumpEscThenCtrlCAbortsThroughMode(): void
    {
        $events = [
            new \SugarCraft\Input\Event\KeyEvent('Escape', \SugarCraft\Input\KeyModifier::none(), "\x1b"),
            new \SugarCraft\Input\Event\KeyEvent('c', \SugarCraft\Input\KeyModifier::ctrl(), "\x03"),
        ];
        $driver = new class ($events) implements \SugarCraft\Input\InputDriver {
            private int $reads = 0;

            public function __construct(private readonly array $events) {}

            public function read(): \SugarCraft\Input\Event|null
            {
                return $this->events[$this->reads++] ?? null;
            }
        };

        $prompt = TextPrompt::new('> ')->withMode(new EmacsMode());
        $final = (new Readline($driver))->run($prompt);

        $this->assertTrue($final->isAborted(), 'ESC + Ctrl+C through the pump aborts (finding 010 repro)');
    }

    public function testPumpPrintableAfterEscStillInserts(): void
    {
        // The pump routes printable keys to handleChar before the mode —
        // an armed prefix must not have swallowed the character either way.
        // (Submit, not abort: abort() discards the buffer by contract, so a
        // post-abort value() is legitimately empty.)
        $events = [
            new \SugarCraft\Input\Event\KeyEvent('Escape', \SugarCraft\Input\KeyModifier::none(), "\x1b"),
            new \SugarCraft\Input\Event\KeyEvent('x', \SugarCraft\Input\KeyModifier::none(), 'x'),
            new \SugarCraft\Input\Event\KeyEvent('Enter', \SugarCraft\Input\KeyModifier::none(), "\r"),
        ];
        $driver = new class ($events) implements \SugarCraft\Input\InputDriver {
            private int $reads = 0;

            public function __construct(private readonly array $events) {}

            public function read(): \SugarCraft\Input\Event|null
            {
                return $this->events[$this->reads++] ?? null;
            }
        };

        $prompt = TextPrompt::new('> ')->withMode(new EmacsMode());
        $final = (new Readline($driver))->run($prompt);

        $this->assertSame('x', $final->value());
        $this->assertTrue($final->isSubmitted());
    }
}
