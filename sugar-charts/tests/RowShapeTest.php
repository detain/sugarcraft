<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Aggregation\BucketByTime;
use SugarCraft\Charts\Aggregation\Resample;
use SugarCraft\Charts\Legend\Legend;
use SugarCraft\Charts\Scatter\Scatter;

/**
 * Row-shape doors (sugar-charts-028 / CH-38): malformed association rows
 * must surface as one domain InvalidArgumentException naming the position
 * and the observed type - never as a PHP Warning (failOnWarning suite) or
 * a TypeError leaking out of a private maths pass.
 */
final class RowShapeTest extends TestCase
{
    /** @var list<array{ts:int,value:float}> */
    private const CANON = [['ts' => 10, 'value' => 1.0], ['ts' => 20, 'value' => 2.0], ['ts' => 30, 'value' => 3.0]];

    public function testResampleAcceptsCanonicalAssocRows(): void
    {
        $out = Resample::new(10)->resample(self::CANON);
        $this->assertNotSame([], $out);
    }

    public function testResampleAcceptsTuplesIdenticallyToAssoc(): void
    {
        $tuples = array_map(static fn (array $r): array => [$r['ts'], $r['value']], self::CANON);
        $this->assertSame(
            Resample::new(10)->resample(self::CANON),
            Resample::new(10)->resample($tuples),
            'tuple rows must canonicalize to the assoc form before the maths paths read them'
        );
    }

    public function testResampleRejectsWrongKeyRowsNamingPosition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("row #1 has unsupported shape (array)");
        Resample::new(10)->resample([['ts' => 10, 'value' => 1.0], ['time' => 20, 'val' => 2.0]]);
    }

    public function testResampleRejectsScalarRows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported shape (int)');
        Resample::new(10)->resample([10, 20]);
    }

    public function testResampleValueFiniteErrorStillDomainNotWarning(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Resample::new(10)->resample([['ts' => 10, 'value' => NAN]]);
    }

    public function testAddManyStoredRowsFeedComputeThroughTheDoor(): void
    {
        $r = Resample::new(10)->addMany([[10, 1.0], [20, 2.0]]);
        $this->assertNotSame([], $r->toTimeSeries());
    }

    public function testBucketByTimeTuplesMatchAssocBuckets(): void
    {
        $bucketer = BucketByTime::new(10);
        $tuples = array_map(static fn (array $r): array => [$r['ts'], $r['value']], self::CANON);
        $this->assertSame($bucketer->bucket(self::CANON), $bucketer->bucket($tuples));
    }

    public function testBucketByTimeRejectsMissingTsRow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('row #0 has unsupported shape (array)');
        BucketByTime::new(10)->bucket([['value' => 1.0]]);
    }

    public function testBucketByTimeRejectsNonNumericTsRow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('row #0 has unsupported shape (array)');
        BucketByTime::new(10)->bucket([['ts' => 'last tuesday', 'value' => 1.0]]);
    }

    public function testScatterWrongKeyRowsThrowDomainDoor(): void
    {
        // CH-12 single door: assoc 'x'/'y' rows are not tuple points and must
        // never reach the renderer as junk.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scatter');
        Scatter::new([['x' => 1, 'y' => 2]]);
    }

    public function testScatterWithPointsDoorAlsoRejects(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Scatter::new()->withPoints([['a' => 1, 'b' => 2]]);
    }

    public function testLegendRejectsItemWithoutColorAtNew(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('item #0 has unsupported shape (array)');
        Legend::new([['label' => 'Series A']]);
    }

    public function testLegendRejectsNonStringLabelAtWithItems(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('item #1 has unsupported shape (int)');
        Legend::new()->withItems([['label' => 'ok', 'color' => 'red'], 7]);
    }

    public function testLegendValidRowsRenderUnchanged(): void
    {
        // the door must be transparent for the documented shape
        $view = Legend::new([['label' => 'A', 'color' => 'red'], ['label' => 'B', 'color' => 'blue']])->view();
        $this->assertStringContainsString('A', $view);
        $this->assertStringContainsString('B', $view);
    }
}
