<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Calc;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Calc\CacheHitRate;
use SugarCraft\Query\Admin\Calc\InnoDBBufferPoolUsageBytes;
use SugarCraft\Query\Admin\Calc\RatePerSecond;
use SugarCraft\Query\Admin\Calc\StatusVar;
use SugarCraft\Query\Admin\Calc\TableOpenCacheHitRate;
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

    public function testTupleRatePerSecondUpdateLastAndIsInitialized(): void
    {
        $rate = new TupleRatePerSecond('TableIO');

        $this->assertFalse($rate->isInitialized());

        $rate->updateLast(['TableIO' => 'a:10,b:20']);

        $this->assertTrue($rate->isInitialized());
    }

    public function testTupleRatePerSecondLastTuples(): void
    {
        $rate = new TupleRatePerSecond('TableIO');

        $rate->updateLast(['TableIO' => 'a:10,b:20']);

        $last = $rate->lastTuples();
        $this->assertEqualsWithDelta(10.0, $last['a'], 0.001);
        $this->assertEqualsWithDelta(20.0, $last['b'], 0.001);
    }

    public function testTupleRatePerSecondUpdateLastWithMissingKey(): void
    {
        $rate = new TupleRatePerSecond('TableIO');

        $rate->updateLast(['OtherKey' => 'a:10']);

        $this->assertFalse($rate->isInitialized());
        $this->assertSame([], $rate->lastTuples());
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

    public function testMakeTupleUpdateLast(): void
    {
        $maker = (new MakeTuple(','))
            ->addRate('Queries')
            ->addTupleRate('TableIO');

        $snapshot = [
            'Queries' => '100',
            'TableIO' => 'a:5,b:15',
        ];

        $maker->updateLast($snapshot);

        // Now compute with a later snapshot to verify updateLast worked
        $current = [
            'Queries' => '110',
            'TableIO' => 'a:10,b:20',
        ];
        $elapsed = 10.0;

        $result = $maker->compute($current, $snapshot, $elapsed);

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

    // ===== StatusVar tests =====

    public function testStatusVarComputeReturnsValue(): void
    {
        $var = new StatusVar('Uptime');

        $current = ['Uptime' => '3600'];
        $result = $var->compute($current, [], 0.0);

        $this->assertSame('3600', $result);
    }

    public function testStatusVarComputeReturnsEmptyStringWhenMissing(): void
    {
        $var = new StatusVar('NonExistent');

        $current = ['Uptime' => '3600'];
        $result = $var->compute($current, [], 0.0);

        $this->assertSame('', $result);
    }

    public function testStatusVarComputeInt(): void
    {
        $var = new StatusVar('Connections');

        $current = ['Connections' => '42'];
        $result = $var->computeInt($current, [], 0.0);

        $this->assertSame(42, $result);
    }

    public function testStatusVarComputeIntReturnsZeroWhenMissing(): void
    {
        $var = new StatusVar('NonExistent');

        $current = ['Connections' => '42'];
        $result = $var->computeInt($current, [], 0.0);

        $this->assertSame(0, $result);
    }

    public function testStatusVarComputeFloat(): void
    {
        $var = new StatusVar('Rate');

        $current = ['Rate' => '3.14'];
        $result = $var->computeFloat($current, [], 0.0);

        $this->assertEqualsWithDelta(3.14, $result, 0.001);
    }

    public function testStatusVarComputeFloatReturnsZeroWhenMissing(): void
    {
        $var = new StatusVar('NonExistent');

        $current = ['Rate' => '3.14'];
        $result = $var->computeFloat($current, [], 0.0);

        $this->assertSame(0.0, $result);
    }

    public function testStatusVarExistsReturnsTrue(): void
    {
        $var = new StatusVar('Uptime');

        $current = ['Uptime' => '3600'];
        $result = $var->exists($current);

        $this->assertTrue($result);
    }

    public function testStatusVarExistsReturnsFalse(): void
    {
        $var = new StatusVar('NonExistent');

        $current = ['Uptime' => '3600'];
        $result = $var->exists($current);

        $this->assertFalse($result);
    }

    // ===== CacheHitRate tests =====

    public function testCacheHitRateComputesPercentage(): void
    {
        $rate = new CacheHitRate('blks_hit', 'blks_read');

        $current = ['blks_hit' => '800', 'blks_read' => '200'];
        $result = $rate->compute($current, [], 0.0);

        $this->assertEqualsWithDelta(80.0, $result, 0.001);
    }

    public function testCacheHitRateZeroTotalReturnsZero(): void
    {
        $rate = new CacheHitRate('blks_hit', 'blks_read');

        $current = [];
        $result = $rate->compute($current, [], 0.0);

        $this->assertSame(0.0, $result);
    }

    public function testCacheHitRateAllHitsReturns100(): void
    {
        $rate = new CacheHitRate('blks_hit', 'blks_read');

        $current = ['blks_hit' => '1000', 'blks_read' => '0'];
        $result = $rate->compute($current, [], 0.0);

        $this->assertSame(100.0, $result);
    }

    public function testCacheHitRateAllMissesReturnsZero(): void
    {
        $rate = new CacheHitRate('blks_hit', 'blks_read');

        $current = ['blks_hit' => '0', 'blks_read' => '1000'];
        $result = $rate->compute($current, [], 0.0);

        $this->assertSame(0.0, $result);
    }

    // ===== TableOpenCacheHitRate tests =====

    public function testTableOpenCacheHitRateComputesPercentage(): void
    {
        $rate = new TableOpenCacheHitRate();

        $current = ['Table_open_cache_hits' => '80', 'Table_open_cache_misses' => '20'];
        $result = $rate->compute($current, [], 0.0);

        $this->assertEqualsWithDelta(80.0, $result, 0.001);
    }

    public function testTableOpenCacheHitRateZeroTotalReturnsZero(): void
    {
        $rate = new TableOpenCacheHitRate();

        $current = [];
        $result = $rate->compute($current, [], 0.0);

        $this->assertSame(0.0, $result);
    }

    public function testTableOpenCacheHitRateWithCustomKeys(): void
    {
        $rate = new TableOpenCacheHitRate('hits', 'misses');

        $current = ['hits' => '75', 'misses' => '25'];
        $result = $rate->compute($current, [], 0.0);

        $this->assertEqualsWithDelta(75.0, $result, 0.001);
    }

    // ===== InnoDBBufferPoolUsageBytes tests =====

    public function testInnoDBBufferPoolUsageBytesComputesPercentage(): void
    {
        $pool = new \SugarCraft\Query\Admin\Calc\InnoDBBufferPoolUsageBytes();

        // Innodb_buffer_pool_bytes_data = 8192 * 100 = 819200 bytes used
        // Innodb_page_size = 8192 bytes per page
        // Innodb_buffer_pool_pages_total = 100 pages total
        // Usage = (819200 / 8192) / 100 * 100 = 100 / 100 * 100 = 100%
        $current = [
            'Innodb_buffer_pool_bytes_data' => '819200',
            'Innodb_page_size' => '8192',
            'Innodb_buffer_pool_pages_total' => '100',
        ];
        $result = $pool->compute($current, [], 0.0);

        $this->assertEqualsWithDelta(100.0, $result, 0.001);
    }

    public function testInnoDBBufferPoolUsageBytesZeroPageSizeReturnsZero(): void
    {
        $pool = new \SugarCraft\Query\Admin\Calc\InnoDBBufferPoolUsageBytes();

        $current = [
            'Innodb_buffer_pool_bytes_data' => '819200',
            'Innodb_page_size' => '0',
            'Innodb_buffer_pool_pages_total' => '100',
        ];
        $result = $pool->compute($current, [], 0.0);

        $this->assertSame(0.0, $result);
    }

    public function testInnoDBBufferPoolUsageBytesZeroPagesTotalReturnsZero(): void
    {
        $pool = new \SugarCraft\Query\Admin\Calc\InnoDBBufferPoolUsageBytes();

        $current = [
            'Innodb_buffer_pool_bytes_data' => '819200',
            'Innodb_page_size' => '8192',
            'Innodb_buffer_pool_pages_total' => '0',
        ];
        $result = $pool->compute($current, [], 0.0);

        $this->assertSame(0.0, $result);
    }

    public function testInnoDBBufferPoolUsageBytesPartialUsage(): void
    {
        $pool = new \SugarCraft\Query\Admin\Calc\InnoDBBufferPoolUsageBytes();

        // 50% usage
        $current = [
            'Innodb_buffer_pool_bytes_data' => '409600',
            'Innodb_page_size' => '8192',
            'Innodb_buffer_pool_pages_total' => '100',
        ];
        $result = $pool->compute($current, [], 0.0);

        $this->assertEqualsWithDelta(50.0, $result, 0.001);
    }

    public function testInnoDBBufferPoolUsageBytesWithCustomKeys(): void
    {
        $pool = new \SugarCraft\Query\Admin\Calc\InnoDBBufferPoolUsageBytes('bytes_data', 'page_size', 'pages_total');

        $current = [
            'bytes_data' => '409600',
            'page_size' => '4096',
            'pages_total' => '100',
        ];
        $result = $pool->compute($current, [], 0.0);

        // 409600 / 4096 = 100 pages used out of 100 total = 100%
        $this->assertEqualsWithDelta(100.0, $result, 0.001);
    }
}
