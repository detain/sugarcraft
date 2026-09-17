<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Charts\Buffer\BufferHelper;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\Legend\Legend;
use SugarCraft\Charts\Picture\Sixel;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;

/**
 * Pins for the E736 Phase-2/2.5 performance refactors (round 86, lane v4).
 *
 * The refactors are behaviour-preserving — proven by a byte-identical
 * render-dump sweep over the whole touched surface — so each pin locks a
 * structural property the NEW implementation relies on: keyed-set
 * membership completeness and boundaries, Sixel memo determinism and
 * per-call scoping, and the Legend COLOR_MAP constant staying equal to
 * the Ansi helper strings it replaced.
 *
 * @coversNothing
 */
final class Phase2PerfPinsTest extends TestCase
{
    /**
     * E736/2.2: the flipped ZERO_WIDTH set must answer identically to the
     * old linear list at every boundary — members, neighbours just outside
     * the ranges — and the public width path must agree.
     */
    public function testZeroWidthSetMembersAndNeighbours(): void
    {
        $set = (new ReflectionClass(BufferHelper::class))->getConstant('ZERO_WIDTH');
        $this->assertIsArray($set);
        $this->assertCount(124, $set, 'keyed set carries every former in_array member');

        foreach ([0x200b, 0x2060, 0xfeff, 0x0300, 0x036f, 0x0483, 0x0489] as $member) {
            $this->assertTrue(isset($set[$member]), sprintf('member 0x%04x', $member));
        }
        foreach ([0x2061, 0x02ff, 0x0370, 0x048a, 0xf900] as $outside) {
            $this->assertFalse(isset($set[$outside]), sprintf('non-member 0x%04x', $outside));
        }

        $this->assertSame(0, BufferHelper::graphemeWidth("\u{034f}"));
        $this->assertSame(1, BufferHelper::graphemeWidth("\u{0370}"));
        $this->assertSame(0, BufferHelper::graphemeWidth("\u{0489}"));
        $this->assertSame(1, BufferHelper::graphemeWidth("\u{048a}"));
        $this->assertSame(1, BufferHelper::graphemeWidth("\t"));
    }

    /**
     * E736/2.1: repeated cell colours render byte-identically through the
     * style cache, and distinct colours must not collapse onto one entry.
     */
    public function testHeatmapStyleCacheIsColourDeterministic(): void
    {
        $grid = [[0.5, 0.5, 0.5], [0.5, 0.0, 0.5]];
        $this->assertSame(
            Heatmap::new($grid)->withSize(3, 2)->view(),
            Heatmap::new([[0.5, 0.5, 0.5], [0.5, 0.0, 0.5]])->withSize(3, 2)->view(),
            'equal grids render byte-identically through the cache',
        );
        $this->assertNotSame(
            Heatmap::new([[0.0, 0.0]])->withSize(2, 1)->view(),
            Heatmap::new([[0.0, 1.0]])->withSize(2, 1)->view(),
            'cache must not collapse distinct colours',
        );
        // Key-collision guard: two colours that share R+G but differ in B
        // must keep distinct cells — a cache keyed on fewer than all three
        // channels would collapse them.
        $black = Color::rgb(0, 0, 0);
        $blue = Color::rgb(0, 0, 255);
        $mixed = Heatmap::new([[0.0, 1.0]])->withSize(2, 1)->withLegend(false)->withColors($black, $blue)->view();
        $this->assertNotSame(
            Heatmap::new([[0.0, 0.0]])->withSize(2, 1)->withLegend(false)->withColors($black, $blue)->view(),
            $mixed,
            'an RB-identical/B-differing pair must not share a cache entry',
        );
    }

    /**
     * E736/2.5: a repeated colour must quantise to exactly the same
     * palette index as the single-pixel encode (memo answers == scan
     * answers) and every stripe of an all-same-colour grid references it
     * once per colour pass — no phantom second index appears.
     */
    public function testSixelMemoKeepsQuantisationIdentical(): void
    {
        $px = Color::rgb(17, 200, 5);
        $single = Sixel::encode([[$px]], 16);
        $repeated = Sixel::encode(array_fill(0, 8, array_fill(0, 4, $px)), 16);
        $this->assertSame(
            self::firstDataColour($single),
            self::firstDataColour($repeated),
            'memo hit must equal the linear-scan answer',
        );
        $this->assertCount(
            1,
            array_unique(self::dataColours($repeated)),
            'all-same-colour grid uses one distinct palette index',
        );
        // Key-collision guard: two colours sharing only the red channel
        // must quantise independently — a memo keyed below full RGB would
        // force the second onto the first's index.
        $a = Color::rgb(17, 200, 5);
        $b = Color::rgb(17, 0, 220);
        $both = Sixel::encode([[$a, $b]], 64);
        $refs = [];
        foreach (self::dataColours($both) as $r) {
            $refs[$r] = true;
        }
        $this->assertCount(
            2,
            $refs,
            'same-red/different-green-blue pair keeps two distinct indices through the memo',
        );
        $idxA = self::firstDataColour(Sixel::encode([[$a]], 64));
        $idxB = self::firstDataColour(Sixel::encode([[$b]], 64));
        $this->assertNotSame($idxA, $idxB, 'the two colours must quantise to different indices');
        $this->assertArrayHasKey($idxA, $refs);
        $this->assertArrayHasKey($idxB, $refs);
    }

    /**
     * E736/2.5: the memo lives per encode() call — changing palette size
     * between calls must not read a stale index.
     */
    public function testSixelMemoIsScopedPerEncodeCall(): void
    {
        $px = [[Color::rgb(128, 30, 200)]];
        $a = Sixel::encode($px, 2);
        $b = Sixel::encode($px, 64);
        $c = Sixel::encode($px, 2);
        $this->assertSame($a, $c, 'same palette size → same bytes on re-encode');
        $this->assertNotSame($a, $b, 'different palette size must not read a stale memo');
    }

    /**
     * E736/2.3: COLOR_MAP equals exactly the SGR strings the former
     * Ansi::fg16() calls produced (default row is sgr(39)).
     */
    public function testLegendColorMapEqualsAnsiHelpers(): void
    {
        $map = (new ReflectionClass(Legend::class))->getConstant('COLOR_MAP');
        $this->assertIsArray($map);
        $this->assertSame(array_keys($map), ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'white', 'default']);
        foreach (['red' => 31, 'green' => 32, 'yellow' => 33, 'blue' => 34, 'magenta' => 35, 'cyan' => 36, 'white' => 37] as $name => $code) {
            $this->assertSame(Ansi::fg16($code), $map[$name], $name);
        }
        $this->assertSame(Ansi::sgr(39), $map['default']);
    }

    /**
     * E736/3.4: colorToInt is boundary-typed to Color (parse, don't
     * validate) — the reflection signature is the pin.
     */
    public function testColorToIntIsTypedToColor(): void
    {
        $param = (new ReflectionClass(BufferHelper::class))->getMethod('colorToInt')->getParameters()[0];
        $this->assertSame(Color::class, (string) $param->getType());
    }

    /**
     * Palette declaration block of a Sixel DCS is `#idx;2;r;g;b` runs
     * immediately after `ESC P q`; everything after it up to `ESC \` is
     * passes. Collect the bare `#idx` references in the pass area.
     *
     * @return list<int>
     */
    private static function dataColours(string $encoded): array
    {
        $body = substr($encoded, 3, strrpos($encoded, "\x1b\\") - 3);
        $declLen = 0;
        preg_match('/^((?:#\d+;2;\d+;\d+;\d+)+)/', $body, $m);
        $declLen = strlen($m[0] ?? '');
        preg_match_all('/#(\d+)(?!;2;)/', substr($body, $declLen), $refs);
        return array_map('intval', $refs[1]);
    }

    private static function firstDataColour(string $encoded): int
    {
        return (int) (self::dataColours($encoded)[0] ?? -1);
    }
}
