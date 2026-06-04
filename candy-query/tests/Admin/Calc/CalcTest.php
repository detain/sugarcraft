<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Calc;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Calc\RatePerSecond;
use SugarCraft\Query\Admin\Calc\TupleRatePerSecond;
use SugarCraft\Query\Admin\Calc\MakeTuple;
use SugarCraft\Query\Admin\StatusSnapshot;

/**
 * Tests for calc engine components.
 */
final class CalcTest extends TestCase
{
    public function testRatePerSecondComputesRate(): void
    {
        $rate = new RatePerSecond('Queries');

        $current = ['Queries' => '110'];
        $previous = ['Queries' => '100'];
        $elapsed = 10.0;

        $result = $rate->compute($current, $previous, $elapsed);
        $this->assertEqualsWithDelta(1.0, $result, 0.001);
    }

    public function testRatePerSecondNegativeDeltaReturnsZero(): void
    {
        $rate = new RatePerSecond('Uptime');

        $current = ['Uptime' => '50'];
        $previous = ['Uptime' => '100'];
        $elapsed = 10.0;

        $result = $rate->compute($current, $previous, $elapsed);
        $this->assertSame(0.0, $result);
    }

    public function testRatePerSecondZeroElapsedReturnsZero(): void
    {
        $rate = new RatePerSecond('Queries');

        $current = ['Queries' => '110'];
        $previous = ['Queries' => '100'];
        $elapsed = 0.0;

        $result = $rate->compute($current, $previous, $elapsed);
        $this->assertSame(0.0, $result);
    }

    public function testRatePerSecondMissingKeyReturnsZero(): void
    {
        $rate = new RatePerSecond('Queries');

        $current = [];
        $previous = ['Queries' => '100'];
        $elapsed = 10.0;

        $result = $rate->compute($current, $previous, $elapsed);
        $this->assertSame(0.0, $result);
    }

    public function testRatePerSecondUpdateLast(): void
    {
        $rate = new RatePerSecond('Queries');
        $this->assertFalse($rate->isInitialized());

        $rate->updateLast(['Queries' => '100']);
        $this->assertTrue($rate->isInitialized());
        $this->assertSame(100.0, $rate->lastValue());
    }

    public function testTupleRatePerSecondComputesRates(): void
    {
        $rate = new TupleRatePerSecond('TableIO');

        $current = ['TableIO' => 'a:10,b:20'];
        $previous = ['TableIO' => 'a:5,b:15'];
        $elapsed = 10.0;

        $result = $rate->compute($current, $previous, $elapsed);
        $this->assertEqualsWithDelta(0.5, $result['a'], 0.001);
        $this->assertEqualsWithDelta(0.5, $result['b'], 0.001);
    }

    public function testTupleRatePerSecondWithCustomSeparator(): void
    {
        $rate = new TupleRatePerSecond('TableIO', ';');

        $current = ['TableIO' => 'x:100;y:200'];
        $previous = ['TableIO' => 'x:50;y:100'];
        $elapsed = 10.0;

        $result = $rate->compute($current, $previous, $elapsed);
        $this->assertEqualsWithDelta(5.0, $result['x'], 0.001);
        $this->assertEqualsWithDelta(10.0, $result['y'], 0.001);
    }

    public function testTupleRatePerSecondMissingKeyReturnsEmpty(): void
    {
        $rate = new TupleRatePerSecond('TableIO');

        $current = [];
        $previous = ['TableIO' => 'a:10'];
        $elapsed = 10.0;

        $result = $rate->compute($current, $previous, $elapsed);
        $this->assertSame([], $result);
    }

    public function testMakeTupleComputesMultipleRates(): void
    {
        $maker = (new MakeTuple(','))
            ->addRate('Queries')
            ->addTupleRate('TableIO');

        $current = [
            'Queries' => '110',
            'TableIO' => 'a:10,b:20',
        ];
        $previous = [
            'Queries' => '100',
            'TableIO' => 'a:5,b:15',
        ];
        $elapsed = 10.0;

        $result = $maker->compute($current, $previous, $elapsed);

        $this->assertEqualsWithDelta(1.0, $result['Queries'], 0.001);
        $this->assertEqualsWithDelta(0.5, $result['a'], 0.001);
        $this->assertEqualsWithDelta(0.5, $result['b'], 0.001);
    }

    public function testStatusSnapshotGet(): void
    {
        $snap = new StatusSnapshot(['Uptime' => '3600', 'Queries' => '100'], 1.0);

        $this->assertSame('3600', $snap->get('Uptime'));
        $this->assertSame('100', $snap->get('Queries'));
        $this->assertNull($snap->get('NotExist'));
    }

    public function testStatusSnapshotGetInt(): void
    {
        $snap = new StatusSnapshot(['Uptime' => '3600', 'Version' => '8.0', 'Name' => 'test'], 1.0);

        $this->assertSame(3600, $snap->getInt('Uptime'));
        $this->assertSame(8, $snap->getInt('Version'));
        $this->assertNull($snap->getInt('Name'));
        $this->assertNull($snap->getInt('NotExist'));
    }

    public function testStatusSnapshotGetFloat(): void
    {
        $snap = new StatusSnapshot(['Rate' => '3.14', 'Name' => 'test'], 1.0);

        $this->assertEqualsWithDelta(3.14, $snap->getFloat('Rate'), 0.001);
        $this->assertNull($snap->getFloat('Name'));
    }

    public function testStatusSnapshotHas(): void
    {
        $snap = new StatusSnapshot(['Uptime' => '3600'], 1.0);

        $this->assertTrue($snap->has('Uptime'));
        $this->assertFalse($snap->has('NotExist'));
    }

    public function testStatusSnapshotElapsedSince(): void
    {
        $older = new StatusSnapshot(['Uptime' => '100'], 1.0);
        $newer = new StatusSnapshot(['Uptime' => '200'], 11.0);

        $this->assertSame(10.0, $newer->elapsedSince($older));
    }

    public function testStatusSnapshotDelta(): void
    {
        $prev = new StatusSnapshot(['Queries' => '100', 'Uptime' => '1000'], 1.0);
        $curr = new StatusSnapshot(['Queries' => '150', 'Uptime' => '1100'], 11.0);

        $delta = $curr->delta($prev);

        $this->assertSame(50.0, $delta['Queries']);
        $this->assertSame(100.0, $delta['Uptime']);
    }

    public function testStatusSnapshotDeltaIgnoresNonNumeric(): void
    {
        $prev = new StatusSnapshot(['Name' => 'server'], 1.0);
        $curr = new StatusSnapshot(['Name' => 'server2'], 11.0);

        $delta = $curr->delta($prev);

        $this->assertArrayNotHasKey('Name', $delta);
    }

    public function testRatePerSecondPreservesCounterOnWrap(): void
    {
        $rate = new RatePerSecond('Counter');

        $current = ['Counter' => '10'];
        $previous = ['Counter' => '4294967290'];
        $elapsed = 1.0;

        $result = $rate->compute($current, $previous, $elapsed);
        $this->assertSame(0.0, $result);
    }
}
