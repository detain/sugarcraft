<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dash\Plot\Chart\Donut;

/**
 * Wireframe (outline) render mode — chart_plan.md S7: rim runes from the
 * declared tangent-bucket set, exactly N radial dividers for N segments, the
 * hub crossing rule, and a shape-only surface (no legend, no centre text).
 */
final class DonutWireframeTest extends TestCase
{
    /**
     * @var list<array{label: string, value: float}>
     */
    private const THREE_SEGMENTS = [
        ['label' => 'a', 'value' => 1],
        ['label' => 'b', 'value' => 1],
        ['label' => 'c', 'value' => 1],
    ];

    /**
     * Every rune wireframe mode may emit: blanks, the rim/divider stroke set
     * (─│╱╲ rounded corners ◜◝◟◞ and the ARC_CHARS box-corner fallback that
     * only fires when a diagonal collapses onto a cardinal axis), and the hub
     * crosses ╳/┼/●.
     */
    private const DECLARED_GLYPHS = ['─', '│', '╱', '╲', '◜', '◝', '◟', '◞', '╭', '╮', '╯', '╰', '╳', '┼', '●'];

    public function testWireframeOnlyEmitsDeclaredRuneSet(): void
    {
        $allowed = array_merge([' '], self::DECLARED_GLYPHS);

        foreach ([12, 14, 20, 21] as $size) {
            $rendered = self::stripAnsi(
                Donut::mocha(self::THREE_SEGMENTS)->withSize($size)->withRenderMode(Donut::RENDER_WIREFRAME)->render()
            );
            $unexpected = array_values(array_diff(array_keys(self::glyphCounts($rendered)), $allowed));

            $this->assertSame(
                [],
                $unexpected,
                sprintf('Size-%d wireframe must only emit the declared rim/divider/hub runes.', $size)
            );
        }
    }

    public function testWireframeRimCarriesExactlyOneOfEachQuadrantCornerRune(): void
    {
        $rendered = self::stripAnsi(
            Donut::mocha(self::THREE_SEGMENTS)->withSize(21)->withRenderMode(Donut::RENDER_WIREFRAME)->render()
        );

        foreach (['◜', '◝', '◟', '◞'] as $corner) {
            $this->assertSame(
                1,
                substr_count($rendered, $corner),
                sprintf('The size-21 rim must place exactly one %s corner rune at its %s diagonal.', $corner, $corner)
            );
        }
    }

    public function testWireframeNeverEmitsFilledBlocks(): void
    {
        $rendered = Donut::mocha(self::THREE_SEGMENTS)->withSize(21)->withRenderMode(Donut::RENDER_WIREFRAME)->render();

        $this->assertStringNotContainsString('█', $rendered, 'Wireframe is rune-only; the block fill belongs to filled mode.');
    }

    /**
     * @return array<string, array{0: list<array{label: string, value: float}>, 1: float, 2: string}>
     */
    public static function provideHubScenarios(): array
    {
        $quarters = [
            ['label' => 'a', 'value' => 1],
            ['label' => 'b', 'value' => 1],
            ['label' => 'c', 'value' => 1],
            ['label' => 'd', 'value' => 1],
        ];

        return [
            // Two non-collinear diagonals cross at the hub.
            'crossing diagonals' => [$quarters, 45.0, '╳'],
            // A horizontal and a vertical spoke cross.
            'orthogonal quarters' => [$quarters, 0.0, '┼'],
            // One diameter only: no cross of any kind.
            'single diameter' => [
                [['label' => 'a', 'value' => 1], ['label' => 'b', 'value' => 1]],
                0.0,
                '●',
            ],
        ];
    }

    /**
     * @dataProvider provideHubScenarios
     */
    public function testHubRuneFollowsTheDividerCrossingRule(array $segments, float $startAngle, string $hub): void
    {
        $size = 21;
        $lines = explode("\n", self::stripAnsi(
            Donut::mocha($segments)
                ->withSize($size)
                ->withStartAngle($startAngle)
                ->withRenderMode(Donut::RENDER_WIREFRAME)
                ->render()
        ));

        $this->assertSame(
            $hub,
            mb_str_split($lines[intdiv($size, 2)])[intdiv($size, 2)],
            sprintf('Hub with startAngle=%s must be %s.', $startAngle, $hub)
        );
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function provideSegmentCounts(): array
    {
        return ['two' => [2], 'three' => [3], 'four' => [4], 'five' => [5]];
    }

    /**
     * N segments have N boundary angles, so the hole disk must carry exactly
     * N divider runs. Each spoke opens on (and is painted with) its own
     * segment's colour, and the hole contains nothing else but the hub —
     * which sits at dist 0 and shares segments[0]'s colour — so the number
     * of distinct SGR colours inside the hole is the number of spokes.
     *
     * @dataProvider provideSegmentCounts
     */
    public function testExactlyNDividerPathsForNSegments(int $segmentCount): void
    {
        $size = 21;
        $data = [];
        for ($i = 0; $i < $segmentCount; $i++) {
            $data[] = ['label' => "s$i", 'value' => 1];
        }

        $raw = Donut::mocha($data)->withSize($size)->withRenderMode(Donut::RENDER_WIREFRAME)->render();

        // Mirrors renderWireframe()'s geometry: centre floor(size/2),
        // radius floor(size/2)-1, innerRadius floor(radius*0.5), aspect 2.0.
        $center = intdiv($size, 2);
        $innerRadius = floor((intdiv($size, 2) - 1) * 0.5);

        $spokeColors = [];
        foreach (explode("\n", $raw) as $y => $line) {
            $cells = self::tokenizeCells($line);
            foreach ($cells as $x => $token) {
                $dist = sqrt((($x - $center) ** 2) + ((($y - $center) * 2.0) ** 2));
                if ($dist > 0 && $dist < $innerRadius && $token !== ' ') {
                    $this->assertMatchesRegularExpression(
                        '/^\x1b\[38;2;\d+;\d+;\d+m[─│╱╲]\x1b\[0m$/u',
                        $token,
                        sprintf('Intruder in the hole disk at (%d,%d).', $x, $y)
                    );
                    $spokeColors[substr($token, 0, (int) strpos($token, 'm', 2) + 1)] = true;
                }
            }
        }

        $this->assertCount(
            $segmentCount,
            $spokeColors,
            sprintf('A %d-segment wireframe must carry exactly %d distinctly coloured divider runs.', $segmentCount, $segmentCount)
        );
    }

    /**
     * The wireframe surface is shape only: no segment label, no centre text
     * (the filled path never drew either — BL-1 — so there is nothing to
     * "preserve" here, and ASCII letters leaking in would prove otherwise).
     */
    public function testWireframeEmitsNoLegendOrCenterText(): void
    {
        $rendered = self::stripAnsi(
            Donut::mocha([
                ['label' => 'Revenue', 'value' => 5],
                ['label' => 'Costs', 'value' => 3],
            ])
                ->withSize(21)
                ->withCenterLabel('TOTAL')
                ->withCenterValue('42%')
                ->withShowPercentage(true)
                ->withRenderMode(Donut::RENDER_WIREFRAME)
                ->render()
        );

        $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9%]/', $rendered, 'Wireframe must not emit legend or centre text.');
        $this->assertStringNotContainsString('TOTAL', $rendered);
    }

    /**
     * Coloured cells must take exactly the filled mode's foreground wrap:
     * toFg(...), rune, Ansi::reset() — one reset per painted glyph, blanks bare.
     */
    public function testWireframeWrapsColouredRunesInForegroundSgr(): void
    {
        $raw = Donut::mocha(self::THREE_SEGMENTS)->withSize(21)->withRenderMode(Donut::RENDER_WIREFRAME)->render();
        $stripped = self::stripAnsi($raw);

        $colouredGlyphs = array_sum(array_intersect_key(
            self::glyphCounts($stripped),
            array_flip(self::DECLARED_GLYPHS)
        ));

        $this->assertGreaterThan(0, $colouredGlyphs);
        $this->assertSame(
            $colouredGlyphs,
            substr_count($raw, "\x1b[0m"),
            'Every painted wireframe rune must open and close exactly one SGR pair.'
        );
        $this->assertStringContainsString(
            Color::hex('#F38BA8')->toFg(ColorProfile::TrueColor),
            $raw,
            'Segment colours must reach the rune-level foreground, as in filled mode.'
        );
    }

    public function testWithRenderModeRejectsUnknownMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Donut::mocha(self::THREE_SEGMENTS)->withRenderMode('outline');
    }

    public function testWithRenderModeReturnsNewInstanceAndLeavesOriginalUnchanged(): void
    {
        $donut = Donut::mocha(self::THREE_SEGMENTS)->withSize(21);
        $before = $donut->render();

        $wire = $donut->withRenderMode(Donut::RENDER_WIREFRAME);

        $this->assertNotSame($donut, $wire);
        $this->assertSame($before, $donut->render(), 'Original instance must be untouched.');
        $this->assertNotSame($before, $wire->render(), 'The mode must change geometry.');
    }

    public function testRenderModeSurvivesWitherThreading(): void
    {
        $chains = [
            'withSize' => Donut::mocha(self::THREE_SEGMENTS)->withRenderMode(Donut::RENDER_WIREFRAME)->withSize(14)->render(),
            'withAspect' => Donut::mocha(self::THREE_SEGMENTS)->withRenderMode(Donut::RENDER_WIREFRAME)->withAspect(1.5)->render(),
            'withStartAngle' => Donut::mocha(self::THREE_SEGMENTS)->withRenderMode(Donut::RENDER_WIREFRAME)->withStartAngle(45.0)->render(),
            'withFillStyle' => Donut::mocha(self::THREE_SEGMENTS)->withRenderMode(Donut::RENDER_WIREFRAME)->withFillStyle(Donut::FILL_BACKGROUND)->render(),
            'center withers' => Donut::mocha(self::THREE_SEGMENTS)
                ->withRenderMode(Donut::RENDER_WIREFRAME)
                ->withCenterLabel('x')
                ->withCenterValue('1')
                ->withShowPercentage(true)
                ->render(),
        ];

        foreach ($chains as $label => $rendered) {
            $this->assertStringNotContainsString(
                '█',
                $rendered,
                sprintf('withRenderMode must survive the %s wither chain (threading lost?).', $label)
            );
            $this->assertMatchesRegularExpression('/[─│╱╲]/u', self::stripAnsi($rendered), "The $label chain must still draw the wireframe.");
        }
    }

    /**
     * Split one RAW render row into per-cell tokens: a coloured cell is its
     * complete "SGR-open, glyph, reset" run; a bare cell is its single char.
     *
     * @return list<string>
     */
    private static function tokenizeCells(string $row): array
    {
        return preg_match_all('/\x1b\[38;2;\d+;\d+;\d+m.\x1b\[0m|./su', $row, $matches) === false
            ? []
            : $matches[0];
    }

    /**
     * Per-glyph occurrence census of an ANSI-stripped render (row separators excluded).
     *
     * @return array<string, int>
     */
    private static function glyphCounts(string $stripped): array
    {
        $counts = [];
        foreach (explode("\n", $stripped) as $line) {
            foreach (mb_str_split($line) as $cell) {
                $counts[$cell] = ($counts[$cell] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Strip SGR color sequences so geometry comparisons are font-independent.
     */
    private static function stripAnsi(string $rendered): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);
    }
}
