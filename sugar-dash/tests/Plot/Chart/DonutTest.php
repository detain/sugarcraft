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
