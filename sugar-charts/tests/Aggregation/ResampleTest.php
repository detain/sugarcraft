<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Aggregation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Aggregation\Resample;

final class ResampleTest extends TestCase
{
    public function testDownsampleLast(): void
    {
        // 10-second target interval
        $data = [
            ['ts' => 0,  'value' => 10.0],
            ['ts' => 5,  'value' => 15.0],  // bucket 0-10: last=15
            ['ts' => 12, 'value' => 20.0],  // bucket 10-20: last=20
            ['ts' => 25, 'value' => 30.0],  // bucket 20-30: last=30
        ];

        $result = Resample::last(10, $data);
        $this->assertCount(3, $result);
        $this->assertSame(15.0, $result[0]['value']);
        $this->assertSame(20.0, $result[1]['value']);
        $this->assertSame(30.0, $result[2]['value']);
    }

    public function testDownsampleMean(): void
    {
        $data = [
            ['ts' => 0,  'value' => 10.0],
            ['ts' => 5,  'value' => 20.0],  // bucket 0-10: mean=15
            ['ts' => 12, 'value' => 30.0],  // bucket 10-20: mean=30
        ];

        $result = Resample::mean(10, $data);
        $this->assertCount(2, $result);
        $this->assertSame(15.0, $result[0]['value']);
        $this->assertSame(30.0, $result[1]['value']);
    }

    public function testUpsampleLinear(): void
    {
        // 2 input points, upsample to 5-second intervals
        $data = [
            ['ts' => 0,  'value' => 0.0],
            ['ts' => 10, 'value' => 10.0],
        ];

        $result = Resample::linear(5, $data, 0);
        // ts=0: 0.0, ts=5: 5.0 (interpolated), ts=10: 10.0
        $this->assertCount(3, $result);
        $this->assertSame(0.0, $result[0]['value']);
        $this->assertSame(5.0, $result[1]['value']);
        $this->assertSame(10.0, $result[2]['value']);
    }

    public function testUpsampleNearest(): void
    {
        $data = [
            ['ts' => 0,  'value' => 10.0],
            ['ts' => 10, 'value' => 20.0],
        ];

        $result = Resample::nearest(5, $data, 0);
        // ts=0: 10 (nearest to 0), ts=5: 10 (nearest to 0), ts=10: 20
        $this->assertCount(3, $result);
        $this->assertSame(10.0, $result[0]['value']);
        $this->assertSame(10.0, $result[1]['value']);
        $this->assertSame(20.0, $result[2]['value']);
    }

    public function testEmptyDataReturnsEmpty(): void
    {
        $result = Resample::last(10, []);
        $this->assertSame([], $result);
    }

    public function testFluentInterface(): void
    {
        $resampler = Resample::create(10);
        $result = $resampler
            ->add(5, 15.0)
            ->add(15, 25.0)
            ->toTimeSeries();

        $this->assertIsArray($result);
    }

    public function testSinglePointReturnsAsIs(): void
    {
        $data = [['ts' => 5, 'value' => 10.0]];
        $result = Resample::linear(5, $data);
        $this->assertCount(1, $result);
        $this->assertSame(10.0, $result[0]['value']);
    }

    public function testAutoDetectDirection(): void
    {
        // Dense data (10-sec intervals) -> downsample when target is 15 sec
        $denseData = [
            ['ts' => 0,  'value' => 10.0],
            ['ts' => 10, 'value' => 20.0],
            ['ts' => 20, 'value' => 30.0],
        ];

        $result = Resample::create(15, 0)->resample($denseData);
        $this->assertNotEmpty($result);
    }
}
