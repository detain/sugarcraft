<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\ConfirmationPrompt;
use SugarCraft\Readline\History\InMemoryHistory;
use SugarCraft\Readline\MultiSelectPrompt;
use SugarCraft\Readline\SelectionPrompt;
use SugarCraft\Readline\Tests\Support\FlashEmptyViewPrompt;
use SugarCraft\Readline\Tests\Support\RepaintDriver;
use SugarCraft\Readline\TextPrompt;
use SugarCraft\Readline\TextareaPrompt;

/**
 * Full repaint frames are golden-pinned byte-exactly (finding 031):
 * `\r` + `CSI {previousRows}A` climb + `CSI 0J` + the prompt view + `\n`,
 * one frame per painted key. View-level tests pin what a frame contains;
 * nothing pinned the frame control arithmetic until here, so a wrapped-row
 * desync or a repaint-count regression could slide through. The list-prompt
 * frames also put the ❯/U+25CB/U+25C9 mark glyphs on record inside real terminal
 * control sequences, not just detached views.
 *
 * Every drive ends in submit/abort on purpose: an injected driver cannot be
 * distinguished from a live-empty stream, so an uncompleted sequence would
 * idle the loop forever (documented in Readline::run()).
 */
final class RepaintGoldenTest extends TestCase
{
    public function testTextPromptFrameSequenceIsByteExact(): void
    {
        $captured = RepaintDriver::capture(new TextPrompt('Name'), "a\r");
        self::assertSame(
            "\r\x1b[0J\x1b[1;36mName\x1b[0m\x1b[7m \x1b[0m\n"
            . "\r\x1b[1A\x1b[0J\x1b[1;36mName\x1b[0ma\x1b[7m \x1b[0m\n",
            $captured
        );
    }

    public function testValidatorRejectionGrowsTheFrameAndTheClimbFollows(): void
    {
        $prompt = (new TextPrompt('Name'))->withValidator(static fn (string $v): bool => $v !== '');
        $captured = RepaintDriver::capture($prompt, "\ra\r");
        self::assertSame(
            "\r\x1b[0J\x1b[1;36mName\x1b[0m\x1b[7m \x1b[0m\n"
            . "\r\x1b[1A\x1b[0J\x1b[1;36mName\x1b[0m\x1b[7m \x1b[0m\n\x1b[31mInvalid input\x1b[0m\n"
            . "\r\x1b[2A\x1b[0J\x1b[1;36mName\x1b[0ma\x1b[7m \x1b[0m\n",
            $captured,
            'the error-row frame must be 2 rows and the next climb must be 2'
        );
    }

    public function testHistorySessionFramesAreByteExact(): void
    {
        $prompt = (new TextPrompt('Name'))->withHistory(new InMemoryHistory());
        $captured = RepaintDriver::capture($prompt, "ab\r");
        self::assertSame(
            "\r\x1b[0J\x1b[1;36mName\x1b[0m\x1b[7m \x1b[0m\n"
            . "\r\x1b[1A\x1b[0J\x1b[1;36mName\x1b[0ma\x1b[7m \x1b[0m\n"
            . "\r\x1b[1A\x1b[0J\x1b[1;36mName\x1b[0mab\x1b[7m \x1b[0m\n",
            $captured
        );
    }

    public function testTextareaMultiLineFramesAreByteExact(): void
    {
        $captured = RepaintDriver::capture(new TextareaPrompt('Body'), "x\r");
        self::assertSame(
            "\r\x1b[0J\x1b[1;36mBody\x1b[0m\n\x1b[7m \x1b[0m\n"
            . "\r\x1b[2A\x1b[0J\x1b[1;36mBody\x1b[0m\nx\x1b[7m \x1b[0m\n",
            $captured
        );
    }

    public function testSelectionGlyphFramesAreByteExact(): void
    {
        $captured = RepaintDriver::capture(new SelectionPrompt('Pick', ['a', 'b']), "\x1b[B\r");
        self::assertSame(
            "\r\x1b[0J\x1b[1;36mPick\x1b[0m\n[filter] \n\x1b[7m\xe2\x9d\xaf a\x1b[0m\n  b\n"
            . "\r\x1b[4A\x1b[0J\x1b[1;36mPick\x1b[0m\n[filter] \n  a\n\x1b[7m\xe2\x9d\xaf b\x1b[0m\n",
            $captured,
            'the ❯ cursor rides real climb/clear control bytes'
        );
    }

    public function testMultiSelectMarkToggleFramesAreByteExact(): void
    {
        $captured = RepaintDriver::capture(new MultiSelectPrompt('Pick', ['one', 'two']), " \x1b[B \r");
        self::assertSame(
            "\r\x1b[0J\x1b[1;36mPick\x1b[0m\n[multi-select] selected: 0\n[filter] \n\x1b[7m\xe2\x97\x8b one\x1b[0m\n\xe2\x97\x8b two\n"
            . "\r\x1b[5A\x1b[0J\x1b[1;36mPick\x1b[0m\n[multi-select] selected: 1\n[filter] \n\x1b[7m\xe2\x97\x89 one\x1b[0m\n\xe2\x97\x8b two\n"
            . "\r\x1b[5A\x1b[0J\x1b[1;36mPick\x1b[0m\n[multi-select] selected: 1\n[filter] \n\xe2\x97\x89 one\n\x1b[7m\xe2\x97\x8b two\x1b[0m\n"
            . "\r\x1b[5A\x1b[0J\x1b[1;36mPick\x1b[0m\n[multi-select] selected: 2\n[filter] \n\xe2\x97\x89 one\n\x1b[7m\xe2\x97\x89 two\x1b[0m\n",
            $captured
        );
    }

    public function testConfirmationBracketFramesAreByteExact(): void
    {
        $captured = RepaintDriver::capture(new ConfirmationPrompt('Ok?'), "y\r");
        self::assertSame(
            "\r\x1b[0J\x1b[1;36mOk?\x1b[0m  Yes  / \x1b[1;32m[No]\x1b[0m [y/n]\n"
            . "\r\x1b[1A\x1b[0J\x1b[1;36mOk?\x1b[0m \x1b[1;32m[Yes]\x1b[0m /  No  [y/n]\n",
            $captured
        );
    }

    public function testCompletionHintRowFrameIsByteExact(): void
    {
        $prompt = (new TextPrompt('L'))->withCompletions(['alpha', 'beta']);
        $captured = RepaintDriver::capture($prompt, "\t\r");
        self::assertSame(
            "\r\x1b[0J\x1b[1;36mL\x1b[0m\x1b[7m \x1b[0m\n"
            . "\r\x1b[1A\x1b[0J\x1b[1;36mL\x1b[0m\x1b[7m \x1b[0m\n \x1b[90malpha   beta\x1b[0m\n",
            $captured,
            'the armed-preview hint row must ride the 90 (bright black) slot'
        );
    }

    public function testAutoSuggestRowFrameIsByteExact(): void
    {
        $history = new InMemoryHistory();
        $history->push('alpha');
        $history->push('archive');
        $prompt = (new TextPrompt('L'))->withHistory($history);
        $captured = RepaintDriver::capture($prompt, "a\r");
        self::assertSame(
            "\r\x1b[0J\x1b[1;36mL\x1b[0m\x1b[7m \x1b[0m\n"
            . "\r\x1b[1A\x1b[0J\x1b[1;36mL\x1b[0ma\x1b[7m \x1b[0m\n  \x1b[2mrchive\x1b[0m\n",
            $captured
        );
    }

    public function testEmptyViewSuppressesFrameButPreservesTheClimbCount(): void
    {
        // The finding-031 asymmetry decision: a '' view paints nothing and
        // lastPaintedRows is EXPLICITLY PRESERVED (the stale frame is still
        // on screen). Zeroing it would strand that frame: the next climb
        // must still be 1 here, not absent.
        $prompt = new FlashEmptyViewPrompt(new TextPrompt('N'), 2);
        $captured = RepaintDriver::capture($prompt, "ab\r");
        self::assertSame(
            "\r\x1b[0J\x1b[1;36mN\x1b[0m\x1b[7m \x1b[0m\n"
            . "\r\x1b[1A\x1b[0J\x1b[1;36mN\x1b[0mab\x1b[7m \x1b[0m\n",
            $captured
        );
        self::assertSame(2, \substr_count($captured, "\x1b[0J"), 'the flashed frame produced no frame');
    }
}
