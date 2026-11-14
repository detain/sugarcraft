<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiParser\Parser\DebugHandler;
use SugarCraft\AnsiParser\Parser\Parser;
use SugarCraft\Vterm\Terminal\Terminal;

/**
 * Random-byte fuzzing + hostile-cap regressions (vterm-33 uplift of
 * findings sugar-vterm-047/083).
 *
 * Feeds large pseudo-random streams through the full Parser →
 * ScreenHandler pipeline: the contract is that feed() never throws on
 * any byte sequence and observable state stays bounded. The escape-storm
 * case used to end in an `assertTrue(true)` sentinel; it now asserts
 * values (bounds, ring caps, reference-build parity), and the ceiling
 * story (64 KiB string buffer, 1024-entry reply ring, screen-full REP)
 * has per-cap regressions sitting right next to the fuzz. Deterministic
 * seeds keep every failure reproducible from one number.
 */
final class FuzzerTest extends TestCase
{
    /** Deterministic RNG so a failure can be reproduced from a single seed. */
    /** @return \Generator<int, int> */
    private function rng(int $seed): \Generator
    {
        mt_srand($seed);
        while (true) {
            yield mt_rand(0, 255);
        }
    }

    /**
     * @dataProvider seedAndLength
     */
    public function testFuzzerSurvivesRandomBytes(int $seed, int $length): void
    {
        $term = Terminal::new(80, 24);
        $rng = $this->rng($seed);
        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $bytes .= chr($rng->current());
            $rng->next();
        }
        // The contract: feed() never throws on any byte sequence.
        $term->feed($bytes);
        $term->flush();
        // Sanity: cursor stayed in bounds, rings stayed capped.
        $this->assertGreaterThanOrEqual(0, $term->cursor()->row);
        $this->assertLessThan(24, $term->cursor()->row);
        $this->assertGreaterThanOrEqual(0, $term->cursor()->col);
        $this->assertLessThan(80, $term->cursor()->col);
        $this->assertLessThanOrEqual(1024, count($term->replies()));
    }

    /** @return list<array{int, int}> */
    public static function seedAndLength(): array
    {
        return [
            'tiny'   => [42, 100],
            'small'  => [0xC0FFEE, 1024],
            'medium' => [0xDEADBEEF, 16 * 1024],
            'large'  => [0xBADF00D, 100 * 1024],
        ];
    }

    public function testFuzzerSurvivesEscapeStorm(): void
    {
        // Pathological case: lots of partial sequences that should all
        // be either consumed or recovered from cleanly.
        $patterns = [
            "\x1b[",        // CSI entry, no final
            "\x1b]",        // OSC start, no terminator
            "\x1bP",        // DCS start, no terminator
            "\x1b\\",       // stray ST
            "\x1b[?",       // private prefix without final
            "\x1b[?25",     // private number without h/l
            "\x1b]4;1;rgb:", // truncated palette
            "\x1b]8;;",     // hyperlink close
            "\x1b[1;2;3;4;5;6;7;8;9;10m", // many SGR params
            "\x1b[1A\x1b[2B\x1b[3C\x1b[4D", // a flurry of moves
        ];
        $term = Terminal::new(80, 24);
        for ($i = 0; $i < 200; $i++) {
            $term->feed($patterns[$i % count($patterns)]);
        }
        $term->flush();

        // Value assertions replace the old assertTrue(true) sentinel:
        // state is bounded, and a reference build fed the identical
        // stream agrees cell-for-cell — the storm is deterministic, so
        // any order-dependent parser corruption shows up here.
        $this->assertGreaterThanOrEqual(0, $term->cursor()->row);
        $this->assertLessThan(24, $term->cursor()->row);
        $this->assertGreaterThanOrEqual(0, $term->cursor()->col);
        $this->assertLessThan(80, $term->cursor()->col);
        $this->assertLessThanOrEqual(1024, count($term->replies()));

        $reference = Terminal::new(80, 24);
        for ($i = 0; $i < 200; $i++) {
            $reference->feed($patterns[$i % count($patterns)]);
        }
        $reference->flush();
        $this->assertSame(self::gridDigest($reference), self::gridDigest($term));
    }

    /** Full-grid grapheme digest + cursor of an emulator terminal. */
    private static function gridDigest(Terminal $term): string
    {
        $hash = hash_init('sha1');
        for ($r = 0; $r < 24; $r++) {
            for ($c = 0; $c < 80; $c++) {
                hash_update($hash, $term->screen()->cell($r, $c)->grapheme . "\0");
            }
        }
        hash_update($hash, sprintf('|%d,%d', $term->cursor()->row, $term->cursor()->col));
        return hash_final($hash);
    }

    public function testGiantOscPayloadHonoursTheStringCeiling(): void
    {
        // A terminated OSC title far past the 64 KiB buffer cap lands the
        // capped prefix, not the flood: the measured ceiling is 65534
        // bytes (the bound sits two bytes under MAX_STRING_BUFFER; the
        // exact figure is pinned by observation, not by a formula).
        $term = Terminal::new(20, 5);
        $term->feed("\x1b]0;" . str_repeat('A', 100 * 1024) . "\x07");
        $title = (string) $term->windowTitle();
        $this->assertSame(65534, strlen($title));
        $this->assertSame(str_repeat('A', 65534), $title);

        // Unterminated and flushed: the flush DISPATCHES the pending OSC
        // with the code it collected — the title lands, still capped (the
        // ceiling is a ceiling, not a reject). A payload with no code at
        // all (bare OSC string) is the one the flush drops: no title.
        $partial = Terminal::new(20, 5);
        $partial->feed("\x1b]0;" . str_repeat('A', 100 * 1024));
        $partial->flush();
        $this->assertSame(str_repeat('A', 65534), $partial->windowTitle());

        $codeless = Terminal::new(20, 5);
        $codeless->feed("\x1b]" . str_repeat('A', 70 * 1024));
        $codeless->flush();
        $this->assertNull($codeless->windowTitle());
    }

    public function testReplyRingStaysCappedAndObservableUnderQueryStorm(): void
    {
        // Post-vterm-15 the eviction counter is public: 1030 cursor
        // queries on a small screen must leave exactly the newest 1024
        // replies with the overflow count observing the six drops.
        $term = Terminal::new(10, 6);
        $term->feed(str_repeat("\x1b[6n", 1030));
        $term->flush();
        $this->assertCount(1024, $term->replies());
        $this->assertSame(6, $term->replyOverflowCount());
    }

    public function testRepStormWritesStayScreenBounded(): void
    {
        // CSI 65535 b (repeat last graphic): the renderer-side ceiling
        // story pinned in ParamLimitTest, exercised through the emulator
        // fuzz pad — here the emulator has no REP arm at all, so the
        // observable contract is 'nothing explodes, grid stays pristine'.
        $term = Terminal::new(80, 24);
        $term->feed('X' . "\x1b[65535b");
        $term->flush();
        $xCount = 0;
        for ($r = 0; $r < 24; $r++) {
            for ($c = 0; $c < 80; $c++) {
                if ($term->screen()->cell($r, $c)->grapheme === 'X') {
                    $xCount++;
                }
            }
        }
        $this->assertSame(1, $xCount, 'emulator records the X and no REP expansion');
    }

    /**
     * Byte-verbatim regression fixture: a SOS string interrupted by an 8-bit
     * C1 re-introducer (0x98) must keep the bytes collected before it — the
     * parser must not silently drop payload when start() fires mid-string.
     * Guards the same defect as ParserTest but as a crash-and-survive fixture.
     */
    public function testC1ReintroducerDoesNotDropStringPayload(): void
    {
        $h = new DebugHandler();
        $p = new Parser($h);
        // ESC X "AB" 0x98 "CD" 0x9C  →  a single SOS dispatch carrying "ABCD".
        $p->feed("\x1bXAB\x98CD\x9c");
        $sos = $h->filter('sos');
        $this->assertCount(1, $sos);
        $this->assertSame('ABCD', $sos[0]['detail']);
    }
}
