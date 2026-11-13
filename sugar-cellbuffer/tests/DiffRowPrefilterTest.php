<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Diff\DiffEncoder;
use SugarCraft\CellBuffer\Hyperlink;
use SugarCraft\CellBuffer\Position;
use SugarCraft\CellBuffer\Region;
use SugarCraft\CellBuffer\Style;

/**
 * CB-17 — the diff() unchanged-row prefilter must be OUTPUT-NEUTRAL:
 * every seeded frame pair diffs to identical op streams and encoded bytes
 * with the prefilter on and off, across all four construction doors
 * (fluent chain, fill sweeps, blits, applyDiff-fed frames).
 */
final class DiffRowPrefilterTest extends TestCase
{
    protected function tearDown(): void
    {
        Buffer::setRowPrefilterEnabled(true);
    }

    public function testRowSkipKeepsEveryOpStreamIdentical(): void
    {
        mt_srand(0xD1FF017);
        $styles = [
            null,
            new Style(fg: 0x112233),
            new Style(bg: 0x445566, attrs: Style::ATTR_BOLD),
            new Style(fgSlot: 12),
            // Slot and RGB may coexist only on DIFFERENT channels (CB-06
            // refuses the fg+fgSlot double-set in the constructor).
            new Style(fgSlot: 3, bg: 0x102030),
            new Style(ul: 0x010203, attrs: Style::ATTR_UNDERLINE),
        ];
        $links = [null, new Hyperlink('https://e.test/x', '1'), new Hyperlink('https://e.test/y')];
        $runes = ['a', '▓', '中', '🎉', '#', ' ', 'é'];

        $prev = $this->randomFrame(7, 9, $styles, $links, $runes);

        for ($frame = 0; $frame < 30; $frame++) {
            $door = $frame % 4;
            $curr = match ($door) {
                // fluent single writes (memo keeps all but touched rows)
                0 => $this->randomTouch($prev, $styles, $links, $runes),
                // fill sweeps (clip row range pruned)
                1 => $prev->fill(
                    Region::new(Position::new(0, mt_rand(0, 8)), 7, 3),
                    $runes[mt_rand(0, count($runes) - 1)] === '' ? Cell::blank() : Cell::new('▓', $styles[1], null, 2),
                ),
                // blits (wipe-all memo path)
                2 => $prev->withRegion(
                    Region::new(Position::new(1, 1), 4, 4),
                    $this->randomFrame(4, 4, $styles, $links, $runes),
                ),
                // applyDiff of a real op stream (wipe-all memo path):
                // touch->diff(prev) are the ops taking prev TO touch,
                // applied back onto prev with a random extra write.
                default => $prev->applyDiff($this->randomTouch($prev, $styles, $links, $runes)->diff($prev))->withCellAt(
                    mt_rand(0, 6), mt_rand(0, 8), Cell::new('@'),
                ),
            };

            Buffer::setRowPrefilterEnabled(true);
            $onOps = $curr->diff($prev);
            Buffer::setRowPrefilterEnabled(false);
            $offOps = $curr->diff($prev);

            self::assertSame(
                array_map(static fn (object $op): string => $op::class, $onOps),
                array_map(static fn (object $op): string => $op::class, $offOps),
                "door {$door} frame {$frame}: op classes must match",
            );
            self::assertSame(
                (new DiffEncoder())->encode($onOps),
                (new DiffEncoder())->encode($offOps),
                "door {$door} frame {$frame}: encoded bytes must match (seed 0xD1FF017)",
            );

            $prev = $curr;
        }
    }

    /**
     * Every single-field difference must survive the prefilter ON: a row
     * whose ONLY change is one rune byte, the width, any of the seven Style
     * channels, the link url, or the link id has to still produce ops.
     * This is the digest-losslessness guard — gutting any leg of rowKey()
     * reddens its field here (mutation-proven at commit time).
     */
    public function testSingleFieldChangesSurviveThePrefilter(): void
    {
        // Every mutant replaces the SAME rune at the SAME position: only
        // the named field may move, or the digest change is not field-
        // specific and a leg-gutting mutation would survive.
        $mutants = [
            'rune' => static fn (): Cell => Cell::new('z'),
            'rune-multibyte' => static fn (): Cell => Cell::new('é'),
            'width' => static fn (): Cell => Cell::new('x', null, null, 2),
            'style-attrs' => static fn (): Cell => Cell::new('x', new Style(attrs: Style::ATTR_BOLD)),
            'style-fg' => static fn (): Cell => Cell::new('x', new Style(fg: 0x112233)),
            'style-bg' => static fn (): Cell => Cell::new('x', new Style(bg: 0x445566)),
            'style-ul' => static fn (): Cell => Cell::new('x', new Style(ul: 0x010203)),
            'style-fgSlot' => static fn (): Cell => Cell::new('x', new Style(fgSlot: 1)),
            'style-bgSlot' => static fn (): Cell => Cell::new('x', new Style(bgSlot: 2)),
            'style-ulSlot' => static fn (): Cell => Cell::new('x', new Style(ulSlot: 3)),
            'link-url' => static fn (): Cell => Cell::new('x', null, new Hyperlink('https://a.test/1')),
            'link-id' => static fn (): Cell => Cell::new('x', null, new Hyperlink('https://a.test/1', '7')),
        ];

        $prev = Buffer::new(4, 2)
            ->withCellAt(0, 0, Cell::new('x'))
            ->withCellAt(2, 1, Cell::new('x'));

        foreach ($mutants as $label => $make) {
            $curr = $prev->withCellAt(2, 1, $make());
            $ops = $curr->diff($prev);
            self::assertNotEmpty($ops, "prefilter must not skip a row differing only in {$label}");
        }
    }

    /**
     * Lossless means LOSSLESS: two different (url, id) splits whose raw text
     * contains the digest's own separator spellings must never collide.
     * The first pair is the exact forgery the pre-fix digest (literal
     * '\x1d' quoting instead of a raw 0x1d byte) would have accepted as
     * equal and skipped; the row-join pair pins raw 0x1f/0x1e bytes living
     * INSIDE a rune, which only the length prefixes can disambiguate.
     */
    public function testDigestCannotBeForgedAcrossFieldBoundaries(): void
    {
        $pairs = [
            [
                Cell::new('L', null, new Hyperlink('U\x1dV', 'W')),
                Cell::new('L', null, new Hyperlink('U', 'V\x1dW')),
            ],
            [
                Cell::new('A'),
                Cell::new("A\x1fB"),
            ],
            [
                Cell::new('A'),
                Cell::new("A\x1eB"),
            ],
        ];

        foreach ($pairs as $i => [$from, $to]) {
            $prev = Buffer::new(3, 1)->withCellAt(1, 0, $from);
            $curr = $prev->withCellAt(1, 0, $to);
            self::assertNotEmpty(
                $curr->diff($prev),
                "forgery pair {$i}: distinct cells must not digest equal",
            );
        }
    }

    public function testPrefilterKnobRoundTripsAndDefaultsOn(): void
    {
        self::assertTrue(Buffer::rowPrefilterEnabled(), 'production default is ON');
        Buffer::setRowPrefilterEnabled(false);
        self::assertFalse(Buffer::rowPrefilterEnabled());
        Buffer::setRowPrefilterEnabled(true);
        self::assertTrue(Buffer::rowPrefilterEnabled());
    }

    public function testSerializedFramesRecomputeDigestsAndStillDiffIdentically(): void
    {
        // The memo must never ride the wire: __unserialize restores an
        // empty memo, so digests are recomputed from the restored grid —
        // and the recomputed stream is identical to the pristine one.
        $a = Buffer::new(5, 3)->withCellAt(1, 1, Cell::new('中', new Style(bg: 0x010203), null, 2));
        $b = $a->withCellAt(0, 0, Cell::new('x'));
        $restored = (new \ReflectionClass(Buffer::class))->newInstanceWithoutConstructor();
        $restored->__unserialize($b->__serialize());

        $direct = $b->diff($a);
        $viaWire = $restored->diff($a);

        self::assertSame(
            (new DiffEncoder())->encode($direct),
            (new DiffEncoder())->encode($viaWire),
        );
    }

    public function testWarmSteadyStateDiffBeatsTheColdWalk(): void
    {
        // 200x60 frame with a one-cell cursor blink per frame: the memo
        // makes untouched rows O(1) string compares. Direction-pinned with
        // margin (wall-clock ratios move with the machine; the ORDER and
        // the size of the win do not).
        $w = 200;
        $h = 60;
        $base = Buffer::new($w, $h);
        $cell = Cell::new('#');
        for ($row = 0; $row < $h; $row++) {
            for ($col = 0; $col < $w; $col++) {
                $base = $base->withCellAt($col, $row, ($col + $row) % 3 === 0 ? $cell : Cell::blank());
            }
        }

        $framesOn = 0.0;
        $framesOff = 0.0;
        foreach ([true, false] as $on) {
            Buffer::setRowPrefilterEnabled($on);
            $prev = $base;
            for ($f = 0; $f < 20; $f++) {
                $curr = $prev->withCellAt(($f * 7) % $w, $f % $h, $f % 2 === 0 ? $cell : Cell::new('.'));
                $t0 = hrtime(true);
                $curr->diff($prev);
                $elapsed = (hrtime(true) - $t0) / 1e6;
                if ($on) {
                    $framesOn += $elapsed;
                } else {
                    $framesOff += $elapsed;
                }
                $prev = $curr;
            }
        }
        Buffer::setRowPrefilterEnabled(true);

        self::assertLessThan(
            $framesOff / 2,
            $framesOn,
            sprintf('warm prefiltered diff (%0.1fms/20 frames) must beat half the cold walk (%0.1fms)', $framesOn, $framesOff),
        );

        // Disclosed measurement, per-row budget: 200x60 baseline ~17.3ms
        // per frame target <8ms — see commit body for this run's figures.
        self::assertLessThan(15.0, $framesOn / 20, 'absolute ceiling for the 20-frame warm diff average (ms)');
    }

    /**
     * @param list<Style|null> $styles
     * @param list<Hyperlink|null> $links
     * @param list<string> $runes
     */
    private function randomFrame(int $w, int $h, array $styles, array $links, array $runes): Buffer
    {
        $b = Buffer::new($w, $h);
        for ($i = 0; $i < intdiv($w * $h, 3); $i++) {
            $b = $b->withCellAt(
                mt_rand(0, $w - 1),
                mt_rand(0, $h - 1),
                Cell::new(
                    $runes[mt_rand(0, count($runes) - 1)],
                    $styles[mt_rand(0, count($styles) - 1)],
                    $links[mt_rand(0, count($links) - 1)],
                    mt_rand(0, 1) === 1 ? 2 : 1,
                ),
            );
        }

        return $b;
    }

    /**
     * @param list<Style|null> $styles
     * @param list<Hyperlink|null> $links
     * @param list<string> $runes
     */
    private function randomTouch(Buffer $b, array $styles, array $links, array $runes): Buffer
    {
        $out = $b;
        for ($i = 0; $i < 5; $i++) {
            $out = $out->withCellAt(
                mt_rand(0, $b->width() - 1),
                mt_rand(0, $b->height() - 1),
                Cell::new(
                    $runes[mt_rand(0, count($runes) - 1)],
                    $styles[mt_rand(0, count($styles) - 1)],
                    $links[mt_rand(0, count($links) - 1)],
                    mt_rand(0, 1) === 1 ? 2 : 1,
                ),
            );
        }

        return $out;
    }
}
