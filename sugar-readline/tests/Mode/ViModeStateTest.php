<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\Mode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\Mode\ViMode;
use SugarCraft\Readline\TextPrompt;
use SugarCraft\Readline\ViModeState;

/**
 * Pins for the public ViModeState enum (finding 088) and for the hidden
 * prompt's register posture (finding 077): a secret buffer never lands in
 * mode state that survives prompt clones.
 */
final class ViModeStateTest extends TestCase
{
    // =========================================================================
    // Finding 088 — public enum
    // =========================================================================

    public function testEnumCasesSpellTheHistoricalStringsWithNoTextualBreak(): void
    {
        $this->assertSame(
            ['insert', 'normal', 'visual'],
            array_map(static fn (ViModeState $c): string => $c->value, ViModeState::cases()),
        );
    }

    public function testViModeReturnsEnumCasesNotStrings(): void
    {
        $prompt = TextPrompt::new('> ')->withMode(new ViMode());
        /** @var ViMode $mode */
        $mode = $this->modeOf($prompt);
        $this->assertSame(ViModeState::Insert, $mode->viMode());

        $prompt = $prompt->handleKey(Key::Escape);
        $this->assertSame(ViModeState::Normal, $this->modeOf($prompt)->viMode());

        $prompt = $prompt->insertPastedText('abc')->handleKey(Key::Escape);
        $prompt = $prompt->handleKey('V');
        $this->assertSame(ViModeState::Visual, $this->modeOf($prompt)->viMode());
    }

    // =========================================================================
    // Finding 077 — hidden prompts keep secrets out of the register
    // =========================================================================

    public function testXOnHiddenPromptLeavesRegisterEmpty(): void
    {
        $prompt = $this->hiddenNormalPrompt('secret');
        $prompt = $prompt->handleKey('x');   // deletes the 'e' under the caret

        $this->assertSame('', $this->modeOf($prompt)->register());
        $this->assertNotSame('secret', $this->modeOf($prompt)->register());
    }

    public function testYyOnHiddenPromptLeavesRegisterEmpty(): void
    {
        $prompt = $this->hiddenNormalPrompt('hunter2');
        $prompt = $prompt->handleKey('y')->handleKey('y');

        $this->assertSame('', $this->modeOf($prompt)->register());
    }

    public function testHiddenCaptureScrubbsAnEarlierPublicYank(): void
    {
        $prompt = $this->normalPrompt('public text');
        $prompt = $prompt->handleKey('y')->handleKey('y');
        $this->assertSame('public text', $this->modeOf($prompt)->register());

        // The same mode rides the hidden prompt; a hidden yy must scrub the
        // stale capture, not leave public text parked in surviving state.
        $prompt = $prompt->withHidden(true);
        $prompt = $prompt->handleKey('y')->handleKey('y');
        $this->assertSame('', $this->modeOf($prompt)->register());
    }

    public function testHiddenDeleteStillEditsButCapturesNothing(): void
    {
        $prompt = $this->hiddenNormalPrompt('abcd');
        $prompt = $prompt->handleKey('D');   // caret on 'd'

        $this->assertSame('abc', $prompt->value(), 'the edit happens; only the capture is dropped');
        $this->assertSame('', $this->modeOf($prompt)->register());
    }

    public function testVisibleCaptureIsUnaffected(): void
    {
        $prompt = $this->normalPrompt('visible');
        $prompt = $prompt->handleKey('y')->handleKey('y');
        $this->assertSame('visible', $this->modeOf($prompt)->register());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function normalPrompt(string $text): TextPrompt
    {
        $prompt = TextPrompt::new('> ')->withMode(new ViMode());
        $prompt = $prompt->handleKey(Key::Escape);
        $prompt = $prompt->insertPastedText($text);
        return $prompt->handleKey(Key::Escape);
    }

    private function hiddenNormalPrompt(string $text): TextPrompt
    {
        return $this->normalPrompt($text)->withHidden(true);
    }

    private function modeOf(TextPrompt $prompt): ViMode
    {
        $reflection = new \ReflectionClass($prompt);
        $prop = $reflection->getProperty('mode');
        $prop->setAccessible(true);
        /** @var ViMode $mode */
        $mode = $prop->getValue($prompt);
        return $mode;
    }
}
