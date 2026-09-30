<?php

/**
 * Pins for the filter text's UTF-8 integrity under backspace() and the unit
 * MAX_FILTER_LENGTH is measured in.
 *
 * Both used to work in BYTES while the rest of the class reads the filter in
 * CODEPOINTS (`highlightMatches()` splits on `mb_str_split(..., 'UTF-8')`), so
 * a single backspace over an accented character left a dangling lead byte
 * behind and the byte cap charged multibyte input up to four times.
 */

declare(strict_types=1);

namespace SugarCraft\Hermit\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Hermit\FilteredItem;
use SugarCraft\Hermit\Hermit;
use SugarCraft\Hermit\Item;

final class HermitFilterUtf8Test extends TestCase
{
    /**
     * Type each codepoint of $text in turn, the way a keyboard driver would.
     *
     * @return list<string>
     */
    private static function codepoints(string $text): array
    {
        return mb_str_split($text, 1, 'UTF-8');
    }

    /**
     * @param  list<Item> $items
     */
    private static function hermitTyping(array $items, string $text): Hermit
    {
        $h = Hermit::new($items)->show();
        foreach (self::codepoints($text) as $cp) {
            $h = $h->type($cp);
        }
        return $h;
    }

    public function testBackspaceDropsAnAccentedCodepointWhole(): void
    {
        $h = self::hermitTyping([new FilteredItem(1, 'café')], 'é');

        $this->assertSame('c3a9', bin2hex($h->filterText()), 'typing é must store both bytes');

        $h = $h->backspace();

        $this->assertSame('', $h->filterText(), 'one backspace clears one codepoint');
        $this->assertTrue(
            mb_check_encoding($h->filterText(), 'UTF-8'),
            'backspace must not leave the dangling lead byte c3 behind',
        );
    }

    public function testEveryBackspaceStepStaysValidUtf8(): void
    {
        // 'café' = 4 codepoints / 5 bytes; '日本' = 2 codepoints / 6 bytes.
        foreach (['café', '日本語', 'naïve', '👍🏽'] as $text) {
            $h = self::hermitTyping([new FilteredItem(1, $text)], $text);
            $this->assertSame($text, $h->filterText());

            $expected = self::codepoints($text);
            while ($h->filterText() !== '') {
                $h = $h->backspace();
                array_pop($expected);

                $this->assertSame(
                    mb_strlen($h->filterText(), 'UTF-8'),
                    count($expected),
                    "codepoint count must fall by one per backspace for {$text}",
                );
                $this->assertSame(
                    implode('', $expected),
                    $h->filterText(),
                    "backspace ladder for {$text} must match the codepoint prefix",
                );
                $this->assertTrue(
                    mb_check_encoding($h->filterText(), 'UTF-8'),
                    "every intermediate filter for {$text} must be valid UTF-8",
                );
            }
        }
    }

    public function testBackspaceOnAnEmojiClusterLiftsTheModifierFirst(): void
    {
        // Honest statement of granularity: '👍🏽' is ONE grapheme but TWO
        // codepoints, and backspace works in codepoints to stay in step with the
        // matcher. A user therefore needs two presses to clear a modifier
        // sequence — documented in Hermit::backspace(), asserted here so a future
        // switch to grapheme trimming is a deliberate, visible choice.
        $h = self::hermitTyping([new FilteredItem(1, '👍🏽')], '👍🏽');

        $this->assertSame(2, mb_strlen($h->filterText(), 'UTF-8'));

        $h = $h->backspace();
        $this->assertSame('👍', $h->filterText());
        $this->assertTrue(mb_check_encoding($h->filterText(), 'UTF-8'));

        $h = $h->backspace();
        $this->assertSame('', $h->filterText());
    }

    public function testBackspaceSelfHealsADanglingTailByte(): void
    {
        // A caller that feeds raw bytes (a partial read from a socket or stdin
        // chunk) can park a lone lead byte in the filter. Backspace must not
        // reproduce the corruption, and must hand back valid UTF-8.
        $h = Hermit::new([new FilteredItem(1, 'x')])->show()->type("\xc3");

        $this->assertSame('c3', bin2hex($h->filterText()));
        $this->assertFalse(mb_check_encoding($h->filterText(), 'UTF-8'), 'fixture really is malformed');

        $h = $h->backspace();

        $this->assertSame('', $h->filterText());
        $this->assertTrue(mb_check_encoding($h->filterText(), 'UTF-8'));
    }

    public function testFilteringStaysInSyncWithTheListAcrossMultibyteEdits(): void
    {
        $items = [
            new FilteredItem(1, 'café'),
            new FilteredItem(2, 'cafe'),
            new FilteredItem(3, 'caffè'),
        ];

        $h = self::hermitTyping($items, 'café');
        $this->assertSame(1, $h->itemCount(), 'exact match on café only');

        $h = $h->backspace();
        $this->assertSame('caf', $h->filterText());
        $this->assertSame(
            ['café', 'cafe', 'caffè'],
            array_map(static fn (Item $i): string => $i->value(), $h->items()),
            'dropping the accent must widen the list, not corrupt the filter',
        );
    }

    public function testMaxFilterLengthCountsCodepointsNotBytes(): void
    {
        $h = Hermit::new(['a'])->show();
        // Two bytes each: 256 codepoints is 512 bytes, which the old byte cap
        // would have refused at the 128th character.
        for ($i = 0; $i < Hermit::MAX_FILTER_LENGTH; $i++) {
            $h = $h->type('é');
        }

        $this->assertSame(Hermit::MAX_FILTER_LENGTH, mb_strlen($h->filterText(), 'UTF-8'));
        $this->assertSame(
            Hermit::MAX_FILTER_LENGTH * 2,
            strlen($h->filterText()),
            'the budget is codepoints, so the byte length is larger by design',
        );

        $this->assertSame(
            $h->filterText(),
            $h->type('x')->filterText(),
            'input past the codepoint cap must be rejected',
        );
    }

    public function testAsciiBackspaceBehaviourIsUnchanged(): void
    {
        // The pre-existing ASCII path must not shift: guards against "fixing"
        // multibyte by trimming two bytes off everything.
        $h = Hermit::new(['a', 'b'])->show()->type('ban')->backspace();

        $this->assertSame('ba', $h->filterText());
    }
}
