<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests;

use SugarCraft\Metrics\Backend\PrometheusFileBackend;
use PHPUnit\Framework\TestCase;

final class HistogramBucketsTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/candy-metrics-histogram-' . uniqid() . '.prom';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path . '.tmp'] as $f) {
            if ($f !== '' && is_file($f)) {
                unlink($f);
            }
        }
    }

    public function testEmitsHistogramTypeWithBucketLines(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->histogram('lat', 0.1);
        $b->histogram('lat', 0.3);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('# TYPE lat histogram', $content);
        $this->assertStringContainsString('lat_count 2', $content);
        $this->assertStringContainsString('lat_sum 0.400000', $content);
    }

    public function testBucketBoundariesAndInfBucket(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->histogram('lat', 0.05);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        // Value 0.05 is <= 0.05, 0.1, etc. so those buckets get 1
        $this->assertStringContainsString('lat_bucket{le="0.05"} 1', $content);
        $this->assertStringContainsString('lat_bucket{le="0.1"} 1', $content);
        $this->assertStringContainsString('lat_bucket{le="+Inf"} 1', $content);
        // Buckets above 0.05 should be 0
        $this->assertStringContainsString('lat_bucket{le="0.005"} 0', $content);
        $this->assertStringContainsString('lat_bucket{le="0.025"} 0', $content);
    }

    public function testMultipleSamplesAccumulateInBuckets(): void
    {
        $b = new PrometheusFileBackend($this->path);
        // Values: 0.001, 0.01, 0.1, 1.0, 10.0
        foreach ([0.001, 0.01, 0.1, 1.0, 10.0] as $v) {
            $b->histogram('lat', $v);
        }
        $b->flush();

        $content = (string) file_get_contents($this->path);
        // +Inf should equal total count
        $this->assertStringContainsString('lat_bucket{le="+Inf"} 5', $content);
        $this->assertStringContainsString('lat_count 5', $content);
        // le=0.005 should have 1 sample (0.001 is below 0.005, only 0.01 and above pass)
        // Wait - 0.001 <= 0.005 so it counts
        $this->assertStringContainsString('lat_bucket{le="0.005"} 1', $content);
        // le=0.01 should have 2 samples (0.001 and 0.01)
        $this->assertStringContainsString('lat_bucket{le="0.01"} 2', $content);
        // le=0.1 should have 3 samples (0.001, 0.01, 0.1)
        $this->assertStringContainsString('lat_bucket{le="0.1"} 3', $content);
        // le=1.0 should have 4 samples
        $this->assertStringContainsString('lat_bucket{le="1"} 4', $content);
        // le=10.0 should have 5 samples
        $this->assertStringContainsString('lat_bucket{le="10"} 5', $content);
    }

    public function testBucketLinesAreInSortedOrder(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->histogram('lat', 0.5);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $lines = explode("\n", trim($content));
        $bucketLines = array_filter($lines, fn($l) => str_contains($l, '_bucket{le='));
        $this->assertNotEmpty($bucketLines);

        // Extract le values and verify they're in ascending order
        $leValues = [];
        foreach ($bucketLines as $line) {
            if (preg_match('/le="([^"]+)"/', $line, $m)) {
                $leValues[] = $m[1];
            }
        }

        // Note: fmt() formats 1.0 as "1", 2.5 as "2.5", etc.
        $expected = ['0.005', '0.01', '0.025', '0.05', '0.1', '0.25', '0.5', '1', '2.5', '5', '10', '25', '50', '100', '+Inf'];
        $this->assertSame($expected, $leValues);
    }

    public function testHistogramWithTagsEmitsBucketLabels(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->histogram('request_latency', 0.05, ['route' => '/api']);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('# TYPE request_latency histogram', $content);
        $this->assertStringContainsString('request_latency_bucket{route="/api",le="0.05"} 1', $content);
    }
}
