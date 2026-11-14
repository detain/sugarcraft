<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\TextPrompt;
use SugarCraft\Readline\TextareaPrompt;

/**
 * The Readline pump produces the symbolic names 'shift_tab' and
 * 'alt_enter' (finding 083); these pins prove a consumer exists for each:
 * Shift+Tab reverse-cycles the completion ring on TextPrompt, Alt+Enter
 * breaks a line on TextareaPrompt. Both chords are only reported by
 * terminals honoring the kitty/modifyOtherKeys push — the same reachability
 * caveat already documented for Shift+Enter.
 */
final class ChordConsumerTest extends TestCase
{
    public function testConstantSpellingsMatchTheProducerGrammar(): void
    {
        // Readline::symbolicKey names modified keys '<mod>_<lower-name>';
        // these consts are the consumer-side spelling of that contract.
        $this->assertSame('shift_tab', Key::ShiftTab);
        $this->assertSame('alt_enter', Key::AltEnter);
        $this->assertSame('shift_enter', Key::ShiftEnter);
    }

    public function testShiftTabWalksTheCompletionRingBackwards(): void
    {
        $prompt = TextPrompt::new('> ')
            ->withCompletions(['apple', 'apricot', 'avocado'])
            ->handleChar('a');

        $prompt = $prompt->handleKey(Key::Tab);   // commits first candidate
        $this->assertSame('apple', $prompt->value());
        $prompt = $prompt->handleKey(Key::Tab);   // forward cycle
        $this->assertSame('apricot', $prompt->value());
        $prompt = $prompt->handleKey(Key::Tab);   // forward cycle
        $this->assertSame('avocado', $prompt->value());
        $prompt = $prompt->handleKey(Key::ShiftTab);
        $this->assertSame('apricot', $prompt->value(), 'Shift+Tab reverses one step');
        $prompt = $prompt->handleKey(Key::ShiftTab);
        $this->assertSame('apple', $prompt->value(), 'two reverse steps land two back, not forward');
        $prompt = $prompt->handleKey(Key::ShiftTab);
        $this->assertSame('avocado', $prompt->value(), 'the reverse step wraps backwards too');
    }

    public function testShiftTabWithoutAnEngagedRingStartsCompletionLikeTab(): void
    {
        $prompt = TextPrompt::new('> ')
            ->withCompletions(['apple', 'apricot'])
            ->handleChar('a')->handleChar('p');

        $shift = $prompt->handleKey(Key::ShiftTab);
        $tab = $prompt->handleKey(Key::Tab);
        $this->assertSame($tab->value(), $shift->value(), 'the fresh arm is direction-free');
    }

    public function testAltEnterBreaksALineOnTheTextarea(): void
    {
        $prompt = TextareaPrompt::new('> ')
            ->handleChar('A')->handleKey(Key::AltEnter)->handleChar('B');

        $this->assertSame(['A', 'B'], $prompt->lines());
    }

    public function testShiftEnterStillBreaksALinesViaTheConstant(): void
    {
        $prompt = TextareaPrompt::new('> ')
            ->handleChar('A')->handleKey(Key::ShiftEnter)->handleChar('B');

        $this->assertSame(['A', 'B'], $prompt->lines());
    }

    public function testAltEnterOnTextPromptSubmitsLikeEnter(): void
    {
        // Single-line TextPrompt has no newline to break; Alt+Enter rides
        // readline's meta-newline = accept-line law (a swallowed chord
        // would be the orphan G8 names).
        $prompt = TextPrompt::new('> ')->handleChar('x')->handleKey(Key::AltEnter);
        $this->assertTrue($prompt->isSubmitted());
        $this->assertSame('x', $prompt->value());
    }
}
