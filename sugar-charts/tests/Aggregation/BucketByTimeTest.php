<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Aggregation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Aggregation\BucketByTime;

final class BucketByTimeTest extends TestCase
{
    public function testSumBuckets(): void
    {
        // Timestamps in seconds, 10-second intervals
        $data = [
            ['ts' => 0,  'value' => 10.0],
            ['ts' => 5,  'value' => 15.0],  // same bucket (0-10) -> sum = 25
            ['ts' => 12, 'value' => 20.0],  // next bucket (10-20) -> sum = 20
            ['ts' => 22, 'value' => 30.0],  // bucket (20-30) -> sum = 30
        ];

        $result = BucketByTime::sum(10, $data);
        $this->assertCount(3, $result);
        $this->assertSame(25.0, $result[0]['value']);
        $this->assertSame(20.0, $result[1]['value']);
        $this->assertSame(30.0, $result[2]['value']);
    }

    public function testMeanBuckets(): void
    {
        $data = [
            ['ts' => 0,  'value' => 10.0],
            ['ts' => 5,  'value' => 20.0],   // same bucket (0-10) -> mean = 15
            ['ts' => 12, 'value' => 100.0],   // next bucket (10-20) -> mean = 100
        ];

        $result = BucketByTime::mean(10, $data);
        $this->assertCount(2, $result);
        $this->assertSame(15.0, $result[0]['value']);
        $this->assertSame(100.0, $result[1]['value']);
    }

    public function testMinBuckets(): void
    {
        $data = [
            ['ts' => 0,  'value' => 10.0],
            ['ts' => 5,  'value' => 3.0],    // same bucket (0-10) -> min = 3
            ['ts' => 12, 'value' => 7.0],    // bucket (10-20) -> min = 7
        ];

        $result = BucketByTime::min(10, $data);
        $this->assertCount(2, $result);
        $this->assertSame(3.0, $result[0]['value']);
        $this->assertSame(7.0, $result[1]['value']);
    }

    public function testMaxBuckets(): void
    {
        $data = [
            ['ts' => 0,  'value' => 10.0],
            ['ts' => 5,  'value' => 30.0],   // same bucket (0-10) -> max = 30
            ['ts' => 12, 'value' => 7.0],    // bucket (10-20) -> max = 7
        ];

        $result = BucketByTime::max(10, $data);
        $this->assertCount(2, $result);
        $this->assertSame(30.0, $result[0]['value']);
        $this->assertSame(7.0, $result[1]['value']);
    }

    public function testFirstLastBuckets(): void
    {
        $data = [
            ['ts' => 0,  'value' => 10.0],
            ['ts' => 5,  'value' => 20.0],   // bucket (0-10): first=10, last=20
            ['ts' => 15, 'value' => 30.0],  // bucket (10-20): first=30, last=30
        ];

        $first = BucketByTime::first(10, $data);
        $last = BucketByTime::last(10, $data);

        $this->assertCount(2, $first);
        $this->assertSame(10.0, $first[0]['value']);
        $this->assertSame(30.0, $first[1]['value']);

        $this->assertCount(2, $last);
        $this->assertSame(20.0, $last[0]['value']);
        $this->assertSame(30.0, $last[1]['value']);
    }

    public function testEmptyDataReturnsEmptyArray(): void
    {
        $result = BucketByTime::sum(10, []);
        $this->assertSame([], $result);
    }

    public function testWithOffset(): void
    {
        // With 5-second offset, bucket boundaries shift
        $data = [
            ['ts' => 5,  'value' => 10.0],  // bucket (5-15)
            ['ts' => 12, 'value' => 20.0],  // bucket (5-15) -> sum = 30
            ['ts' => 18, 'value' => 30.0],  // bucket (15-25) -> sum = 30
        ];

        $result = BucketByTime::sum(10, $data, 5);
        $this->assertCount(2, $result);
        $this->assertSame(30.0, $result[0]['value']);
        $this->assertSame(30.0, $result[1]['value']);
    }

    public function testFluentInterface(): void
    {
        $bucketer = BucketByTime::create(10, fn(array $v) => array_sum(array_column($v, 'value')));
        $result = $bucketer
            ->add(5, 10.0)
            ->add(12, 20.0)
            ->compute();

        $this->assertCount(2, $result);
    }

    public function testRejectNonPositiveInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BucketByTime::create(0);
    }
}
