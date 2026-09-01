<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plot\Chart\Donut;

final class DonutTest extends TestCase
{
    /**
     * Data used by the embedded legacy geometry oracle (see below).
     *
     * @var list<array{label: string, value: float}>
     */
    private const ORACLE_DATA = [
        ['label' => 'a', 'value' => 1],
        ['label' => 'b', 'value' => 1],
        ['label' => 'c', 'value' => 1],
    ];

    /**
     * ANSI-stripped render of `Donut::mocha(ORACLE_DATA)->withSize(21)` captured
     * from the pre-aspect code at e2d17a4c9 (md5 924a4b13d91af2e8987cd83009323511).
     * Base64-chunked so trailing row padding survives any whitespace linting.
     */
    private const LEGACY_FILLED_21 =
        'ICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICDiloggICAgICAgICAgCiAgICAgIOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKW'
        . 'iOKWiCAgICAgIAogICAgIOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiCAgICAgCiAgICDilojilojilojilojiloji'
        . 'lojilojilojilojilojilojilojiloggICAgCiAgIOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKW'
        . 'iCAgIAogIOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiCAgCiAg4paI4paI4paI4paI'
        . '4paI4paIICAgICDilojilojilojilojilojiloggIAogIOKWiOKWiOKWiOKWiOKWiCAgICAgICDilojilojilojilojiloggIAog'
        . 'IOKWiOKWiOKWiOKWiOKWiCAgICAgICDilojilojilojilojiloggIAog4paI4paI4paI4paI4paI4paIICAgICAgIOKWiOKWiOKW'
        . 'iOKWiOKWiOKWiCAKICDilojilojilojilojiloggICAgICAg4paI4paI4paI4paI4paIICAKICDilojilojilojilojiloggICAg'
        . 'ICAg4paI4paI4paI4paI4paIICAKICDilojilojilojilojilojiloggICAgIOKWiOKWiOKWiOKWiOKWiOKWiCAgCiAg4paI4paI'
        . '4paI4paI4paI4paI4paI4paI4paI4paI4paI4paI4paI4paI4paI4paI4paIICAKICAg4paI4paI4paI4paI4paI4paI4paI4paI'
        . '4paI4paI4paI4paI4paI4paI4paIICAgCiAgICDilojilojilojilojilojilojilojilojilojilojilojilojiloggICAgCiAg'
        . 'ICAg4paI4paI4paI4paI4paI4paI4paI4paI4paI4paI4paIICAgICAKICAgICAg4paI4paI4paI4paI4paI4paI4paI4paI4paI'
        . 'ICAgICAgCiAgICAgICAgICDiloggICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgIA==';

    /**
     * Same capture for the empty placeholder ring,
     * `Donut::new([])->withSize(21)` (md5 9fd29b25bdd5ca978d1b51c9cafa2152).
     */
    private const LEGACY_EMPTY_21 =
        'ICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICDilpEgICAgICAgICAgCiAgICAgIOKWkeKWkeKWkeKWkeKWkeKWkeKWkeKW'
        . 'keKWkSAgICAgIAogICAgIOKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkSAgICAgCiAgICDilpHilpHilpHilpHilpHi'
        . 'lpHilpHilpHilpHilpHilpHilpHilpEgICAgCiAgIOKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKW'
        . 'kSAgIAogIOKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkSAgCiAg4paR4paR4paR4paR'
        . '4paR4paRICAgICDilpHilpHilpHilpHilpHilpEgIAogIOKWkeKWkeKWkeKWkeKWkSAgICAgICDilpHilpHilpHilpHilpEgIAog'
        . 'IOKWkeKWkeKWkeKWkeKWkSAgICAgICDilpHilpHilpHilpHilpEgIAog4paR4paR4paR4paR4paR4paRICAgICAgIOKWkeKWkeKW'
        . 'keKWkeKWkeKWkSAKICDilpHilpHilpHilpHilpEgICAgICAg4paR4paR4paR4paR4paRICAKICDilpHilpHilpHilpHilpEgICAg'
        . 'ICAg4paR4paR4paR4paR4paRICAKICDilpHilpHilpHilpHilpHilpEgICAgIOKWkeKWkeKWkeKWkeKWkeKWkSAgCiAg4paR4paR'
        . '4paR4paR4paR4paR4paR4paR4paR4paR4paR4paR4paR4paR4paR4paR4paRICAKICAg4paR4paR4paR4paR4paR4paR4paR4paR'
        . '4paR4paR4paR4paR4paR4paR4paRICAgCiAgICDilpHilpHilpHilpHilpHilpHilpHilpHilpHilpHilpHilpHilpEgICAgCiAg'
        . 'ICAg4paR4paR4paR4paR4paR4paR4paR4paR4paR4paR4paRICAgICAKICAgICAg4paR4paR4paR4paR4paR4paR4paR4paR4paR'
        . 'ICAgICAgCiAgICAgICAgICDilpEgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgIA==';

    /**
     * The 14 declared quadrant/block-drawing runes of the smooth rim
     * (chart_plan.md S5): singles ▘▝▖▗, orthogonal halves ▀▄▌▐, diagonals
     * ▚▞, three-of-four ▛▜▙▟ — everything withSmoothRim() may emit besides
     * the full block █ and the blank space.
     */
    private const QUADRANT_RUNES = ['▘', '▝', '▖', '▗', '▀', '▄', '▌', '▐', '▚', '▞', '▛', '▜', '▙', '▟'];

    /**
     * ANSI-stripped render of `Donut::mocha(ORACLE_DATA)->withSize(21)` captured
     * from the committed post-S4 code (md5 954349b37da821c364643777e4dda425),
     * the default flag-off oracle for S5: the smooth-rim feature must not move
     * a single byte of legacy output. Base64-chunked per the S4 style so
     * trailing row padding survives whitespace linting.
     */
    private const PRE_S5_DEFAULT_FILLED_21 =
        'ICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAKICAgICAgICAg'
        . 'ICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAKICAgICAg4paI4paI4paI4paI'
        . '4paI4paI4paI4paI4paIICAgICAgCiAgICDilojilojilojilojilojilojilojilojilojilojilojilojiloggICAgCiAg4paI'
        . '4paI4paI4paI4paI4paI4paI4paI4paI4paI4paI4paI4paI4paI4paI4paI4paIICAKICDilojilojilojilojiloggICAgICAg'
        . '4paI4paI4paI4paI4paIICAKIOKWiOKWiOKWiOKWiOKWiOKWiCAgICAgICDilojilojilojilojilojiloggCiAg4paI4paI4paI'
        . '4paI4paIICAgICAgIOKWiOKWiOKWiOKWiOKWiCAgCiAg4paI4paI4paI4paI4paI4paI4paI4paI4paI4paI4paI4paI4paI4paI'
        . '4paI4paI4paIICAKICAgIOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiOKWiCAgICAKICAgICAg4paI4paI4paI'
        . '4paI4paI4paI4paI4paI4paIICAgICAgCiAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAKICAgICAg'
        . 'ICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAg'
        . 'ICAgICAg';

    /**
     * Same capture for the empty placeholder ring,
     * `Donut::new([])->withSize(21)` (md5 f356949319ea0269bf4e8c801da6c74a);
     * renderEmpty() is not touched by the smooth rim at all.
     */
    private const PRE_S5_DEFAULT_EMPTY_21 =
        'ICAgICAgICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAKICAgICAgICAg'
        . 'ICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAKICAgICAg4paR4paR4paR4paR'
        . '4paR4paR4paR4paR4paRICAgICAgCiAgICDilpHilpHilpHilpHilpHilpHilpHilpHilpHilpHilpHilpHilpEgICAgCiAg4paR'
        . '4paR4paR4paR4paR4paR4paR4paR4paR4paR4paR4paR4paR4paR4paR4paR4paRICAKICDilpHilpHilpHilpHilpEgICAgICAg'
        . '4paR4paR4paR4paR4paRICAKIOKWkeKWkeKWkeKWkeKWkeKWkSAgICAgICDilpHilpHilpHilpHilpHilpEgCiAg4paR4paR4paR'
        . '4paR4paRICAgICAgIOKWkeKWkeKWkeKWkeKWkSAgCiAg4paR4paR4paR4paR4paR4paR4paR4paR4paR4paR4paR4paR4paR4paR'
        . '4paR4paR4paRICAKICAgIOKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkeKWkSAgICAKICAgICAg4paR4paR4paR'
        . '4paR4paR4paR4paR4paR4paRICAgICAgCiAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAKICAgICAg'
        . 'ICAgICAgICAgICAgICAgCiAgICAgICAgICAgICAgICAgICAgIAogICAgICAgICAgICAgICAgICAgICAKICAgICAgICAgICAgICAg'
        . 'ICAgICAg';

    public function testNewCreatesDonut(): void
    {
        $donut = Donut::new([
            ['label' => 'A', 'value' => 30],
            ['label' => 'B', 'value' => 70],
        ]);

        $this->assertNotNull($donut);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $donut = Donut::new([
            ['label' => 'A', 'value' => 30],
            ['label' => 'B', 'value' => 70],
        ]);

        $rendered = $donut->render();
        $this->assertNotSame('', $rendered);
    }

    public function testMochaCreatesWithColors(): void
    {
        $donut = Donut::mocha([
            ['label' => 'A', 'value' => 30],
            ['label' => 'B', 'value' => 70],
        ]);

        $rendered = $donut->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSizeReturnsSquareDimensions(): void
    {
        $donut = Donut::new([
            ['label' => 'A', 'value' => 30],
        ]);

        [$width, $height] = $donut->getInnerSize();
        $this->assertEquals($width, $height);
    }

    public function testEmptyDataRendersEmpty(): void
    {
        $donut = Donut::new([]);
        $this->assertNotSame('', $donut->render());
    }

    public function testWithSizeReturnsNewInstance(): void
    {
        $donut = Donut::new([
            ['label' => 'A', 'value' => 50],
        ]);

        $newDonut = $donut->withSize(30);
        $this->assertNotSame($donut, $newDonut);
    }

    public function testWithCenterLabelReturnsNewInstance(): void
    {
        $donut = Donut::new([
            ['label' => 'A', 'value' => 50],
        ]);

        $newDonut = $donut->withCenterLabel('Test');
        $this->assertNotSame($donut, $newDonut);
    }

    public function testWithShowPercentageReturnsNewInstance(): void
    {
        $donut = Donut::new([
            ['label' => 'A', 'value' => 50],
        ]);

        $newDonut = $donut->withShowPercentage(true);
        $this->assertNotSame($donut, $newDonut);
    }

    public function testWithStartAngleReturnsNewInstance(): void
    {
        $donut = Donut::new([
            ['label' => 'A', 'value' => 50],
        ]);

        $newDonut = $donut->withStartAngle(90.0);
        $this->assertNotSame($donut, $newDonut);
    }

    public function testAspectOneReproducesLegacyOutputByteExactly(): void
    {
        $rendered = Donut::mocha(self::ORACLE_DATA)
            ->withSize(21)
            ->withAspect(1.0)
            ->render();

        $this->assertSame(
            base64_decode(self::LEGACY_FILLED_21, true),
            self::stripAnsi($rendered),
            'withAspect(1.0) must be the legacy escape hatch: byte-identical ring (ANSI-stripped).'
        );
    }

    public function testDefaultAspectRingIsVisuallyRound(): void
    {
        [$width, $height] = self::ringExtents(
            self::stripAnsi(Donut::mocha(self::ORACLE_DATA)->withSize(21)->render()),
            '█'
        );

        // Horizontal diameter is kept in horizontal-cell units (19 of 21 cells),
        // vertical diameter is halved by the 2.0 aspect, so the ring spans twice
        // as many columns as rows and reads round on ~2:1 terminal cells.
        $this->assertSame(19, $width);
        $this->assertSame(9, $height);
        $this->assertEqualsWithDelta(2.0, $width / $height, 0.25);

        // Contrast: the legacy 1.0 aspect stayed a cell-square ellipse.
        [$legacyWidth, $legacyHeight] = self::ringExtents(
            self::stripAnsi(Donut::mocha(self::ORACLE_DATA)->withSize(21)->withAspect(1.0)->render()),
            '█'
        );
        $this->assertSame($legacyWidth, $legacyHeight);
    }

    public function testWithAspectReturnsNewInstanceAndLeavesOriginalUnchanged(): void
    {
        $donut = Donut::mocha(self::ORACLE_DATA)->withSize(21);
        $before = $donut->render();

        $corrected = $donut->withAspect(3.0);

        $this->assertNotSame($donut, $corrected);
        $this->assertSame($before, $donut->render(), 'Original instance must be untouched.');
        $this->assertNotSame($before, $corrected->render(), 'New aspect must change geometry.');
    }

    public function testRenderEmptyRingIsAlsoAspectCorrected(): void
    {
        $empty = static fn(float $ratio): string => self::stripAnsi(
            Donut::new([])->withSize(21)->withAspect($ratio)->render()
        );

        $this->assertSame(
            base64_decode(self::LEGACY_EMPTY_21, true),
            $empty(1.0),
            'renderEmpty() duplicates the ring loop; aspect 1.0 must reproduce its legacy shape byte-exactly.'
        );

        [$width, $height] = self::ringExtents($empty(2.0), '░');
        $this->assertEqualsWithDelta(
            2.0,
            $width / $height,
            0.25,
            'The empty placeholder ring must get the same aspect correction as the filled one.'
        );
        $this->assertNotSame($empty(1.0), $empty(2.0));
    }

    public function testSmoothRimOffKeepsPreS5FilledOutputByteIdentical(): void
    {
        $expected = base64_decode(self::PRE_S5_DEFAULT_FILLED_21, true);

        $default = self::stripAnsi(Donut::mocha(self::ORACLE_DATA)->withSize(21)->render());
        $explicitOff = self::stripAnsi(
            Donut::mocha(self::ORACLE_DATA)->withSize(21)->withSmoothRim(false)->render()
        );

        $this->assertSame($expected, $default, 'Flag default must stay OFF: legacy byte path.');
        $this->assertSame($expected, $explicitOff, 'withSmoothRim(false) must take the legacy byte path.');
    }

    public function testSmoothRimOffKeepsPlaceholderRingByteIdentical(): void
    {
        $expected = base64_decode(self::PRE_S5_DEFAULT_EMPTY_21, true);

        $this->assertSame($expected, self::stripAnsi(Donut::new([])->withSize(21)->render()));
        $this->assertSame(
            self::stripAnsi(Donut::new([])->withSize(21)->withSmoothRim()->render()),
            $expected,
            'renderEmpty() is the ░ placeholder ring and must ignore the smooth-rim flag.'
        );
    }

    public function testSmoothRimEmitsQuadrantRunesOnMidSizeDonut(): void
    {
        $rendered = self::stripAnsi(
            Donut::mocha(self::ORACLE_DATA)->withSize(12)->withSmoothRim()->render()
        );

        $emitted = array_values(array_intersect(
            self::QUADRANT_RUNES,
            array_keys(self::glyphCounts($rendered))
        ));

        $this->assertNotEmpty(
            $emitted,
            'A supersampled size-12 rim must emit at least one quadrant rune.'
        );
    }

    public function testSmoothRimOnlyEmitsDeclaredRuneSet(): void
    {
        $allowed = array_merge([' ', '█'], self::QUADRANT_RUNES);

        foreach ([12, 14, 20] as $size) {
            $rendered = self::stripAnsi(
                Donut::mocha(self::ORACLE_DATA)->withSize($size)->withSmoothRim()->render()
            );
            $unexpected = array_values(array_diff(array_keys(self::glyphCounts($rendered)), $allowed));

            $this->assertSame(
                [],
                $unexpected,
                sprintf('Size-%d smooth rim must only emit the declared 14-rune quadrant set, block, or space.', $size)
            );
        }
    }

    public function testSmoothRimNeverOverwritesHoleInteriorCells(): void
    {
        $size = 20;
        $rendered = self::stripAnsi(
            Donut::mocha(self::ORACLE_DATA)->withSize($size)->withSmoothRim()->render()
        );
        $lines = explode("\n", $rendered);
        $this->assertCount($size, $lines);

        // Mirrors render()'s geometry: centre floor(size/2), radius floor(size/2)-1,
        // innerRadius floor(radius*0.5), vertical leg scaled by the default 2.0 aspect.
        $center = intdiv($size, 2);
        $innerRadius = intdiv(intdiv($size, 2) - 1, 2);

        $checked = 0;
        foreach ($lines as $y => $line) {
            foreach (mb_str_split($line) as $x => $cell) {
                $dy = ($y - $center) * 2.0;
                $dist = sqrt((($x - $center) ** 2) + ($dy ** 2));
                if ($dist < $innerRadius) {
                    $checked++;
                    $this->assertSame(
                        ' ',
                        $cell,
                        sprintf('Hole-interior cell (%d,%d) dist=%.2f must stay blank.', $x, $y, $dist)
                    );
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'Guard: the size-20 hole must contain cells to check.');
    }

    public function testWithSmoothRimReturnsNewInstanceAndLeavesOriginalUnchanged(): void
    {
        $donut = Donut::mocha(self::ORACLE_DATA)->withSize(14);
        $before = $donut->render();

        $smooth = $donut->withSmoothRim();

        $this->assertNotSame($donut, $smooth);
        $this->assertSame($before, $donut->render(), 'Original instance must be untouched.');
        $this->assertNotSame($before, $smooth->render(), 'The flag must change geometry.');
    }

    public function testSmoothRimSurvivesWitherThreading(): void
    {
        $chains = [
            'withSize' => Donut::mocha(self::ORACLE_DATA)->withSmoothRim()->withSize(12)->render(),
            'withAspect' => Donut::mocha(self::ORACLE_DATA)->withSmoothRim()->withAspect(1.5)->render(),
            'withStartAngle' => Donut::mocha(self::ORACLE_DATA)->withSmoothRim()->withStartAngle(45.0)->render(),
            'center withers' => Donut::mocha(self::ORACLE_DATA)
                ->withSmoothRim()
                ->withCenterLabel('x')
                ->withCenterValue('1')
                ->withShowPercentage(true)
                ->render(),
        ];

        foreach ($chains as $label => $rendered) {
            $emitted = array_values(array_intersect(
                self::QUADRANT_RUNES,
                array_keys(self::glyphCounts(self::stripAnsi($rendered)))
            ));

            $this->assertNotEmpty(
                $emitted,
                sprintf('withSmoothRim must survive the %s wither chain (threading lost?).', $label)
            );
        }
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

    /**
     * Bounding box (in cells) of every $glyph occurrence in an ANSI-stripped render.
     *
     * @return array{0: int, 1: int} [width, height]
     */
    private static function ringExtents(string $stripped, string $glyph): array
    {
        $minX = PHP_INT_MAX;
        $maxX = -1;
        $minY = PHP_INT_MAX;
        $maxY = -1;

        foreach (explode("\n", $stripped) as $y => $line) {
            foreach (mb_str_split($line) as $x => $cell) {
                if ($cell === $glyph) {
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                    $minY = min($minY, $y);
                    $maxY = max($maxY, $y);
                }
            }
        }

        if ($maxX < $minX) {
            throw new \LogicException(sprintf('ringExtents(): glyph "%s" not found in render', $glyph));
        }

        return [$maxX - $minX + 1, $maxY - $minY + 1];
    }
}
