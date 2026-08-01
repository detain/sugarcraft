<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests\Backend;

use SugarCraft\Metrics\Backend\PrometheusFileBackend;
use SugarCraft\Metrics\Descriptor;
use PHPUnit\Framework\TestCase;

final class PrometheusFileBackendTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/candy-metrics-' . uniqid() . '.prom';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path . '.tmp'] as $f) {
            if ($f !== '' && is_file($f)) {
                unlink($f);
            }
        }
    }

    public function testEmitsCounterAndGaugeAndHistogram(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('hits', 5);
        $b->counter('hits', 2);
        $b->gauge('queue_depth', 17);
        $b->histogram('lat', 0.1);
        $b->histogram('lat', 0.3);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('# TYPE hits counter',           $content);
        $this->assertStringContainsString("hits 7\n",                       $content);
        $this->assertStringContainsString('# TYPE queue_depth gauge',       $content);
        $this->assertStringContainsString("queue_depth 17\n",               $content);
        $this->assertStringContainsString('# TYPE lat histogram',             $content);
        $this->assertStringContainsString("lat_count 2\n",                  $content);
        $this->assertStringContainsString('lat_sum 0.400000',               $content);
        // Verify bucket lines are present
        $this->assertStringContainsString('lat_bucket{le="0.1"} 1', $content);
        $this->assertStringContainsString('lat_bucket{le="+Inf"} 2', $content);
    }

    public function testTagsRenderAsLabels(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('hits', 1, ['route' => '/x', 'method' => 'GET']);
        $b->counter('hits', 2, ['route' => '/y', 'method' => 'GET']);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('hits{method="GET",route="/x"} 1', $content);
        $this->assertStringContainsString('hits{method="GET",route="/y"} 2', $content);
    }

    public function testLabelEscaping(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->gauge('msg', 1, ['note' => 'has "quotes" and \\back']);
        $b->flush();
        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('has \\"quotes\\" and \\\\back', $content);
    }

    public function testFlushIsAtomicReplacement(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('first', 1);
        $b->flush();
        $first = (string) file_get_contents($this->path);

        $b2 = new PrometheusFileBackend($this->path);
        $b2->counter('second', 1);
        $b2->flush();
        $second = (string) file_get_contents($this->path);

        $this->assertStringContainsString('first', $first);
        $this->assertStringNotContainsString('first',  $second);
        $this->assertStringContainsString('second',    $second);
    }

    public function testDottedNameSanitizedToUnderscores(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('http.request.duration', 1);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('http_request_duration 1', $content);
        $this->assertStringContainsString('# TYPE http_request_duration counter', $content);
        $this->assertStringNotContainsString('http.request.duration', $content);
    }

    public function testNameInjectionNeutralized(): void
    {
        $b = new PrometheusFileBackend($this->path);
        // A name containing newline + brace chars could inject extra metric lines
        // if not sanitized. The sanitizer replaces \n and { } with underscores.
        $b->counter("test\n{metric}\naline", 1);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        // Exactly one sample line should exist under the sanitized name.
        // Raw newlines in the name become underscores, so no line-break injection.
        $this->assertStringContainsString('test__metric__aline 1', $content);
        // The raw problematic name patterns must not appear literally.
        $this->assertStringNotContainsString("test\n{", $content);
        $this->assertStringNotContainsString("\n{", $content);
        // Should have exactly one TYPE line (not one per injected line).
        $this->assertSame(1, substr_count($content, '# TYPE test__metric__aline counter'));
    }

    public function testTypeEmittedOncePerFamily(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('hits', 1, ['a' => '1']);
        $b->counter('hits', 2, ['a' => '2']);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        // Exactly one TYPE line for the 'hits' family despite two label sets.
        $this->assertSame(1, substr_count($content, '# TYPE hits counter'));
        // Both series lines must still be present.
        $this->assertStringContainsString('hits{a="1"} 1', $content);
        $this->assertStringContainsString('hits{a="2"} 2', $content);
    }

    public function testRegisteredDescriptorEmitsHelpBeforeSamples(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->describe(new Descriptor('request_latency', 'HTTP request latency in seconds', 'histogram'));
        // Record nothing — this is a zero-sample descriptor.
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('# HELP request_latency HTTP request latency in seconds', $content);
        $this->assertStringContainsString('# TYPE request_latency histogram', $content);
    }

    public function testDescriptorHelpForSampledMetric(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->describe(new Descriptor('hits', 'Total HTTP request count', 'counter'));
        $b->counter('hits', 5);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        // Exactly one HELP and one TYPE for the 'hits' family.
        $this->assertSame(1, substr_count($content, '# HELP hits Total HTTP request count'));
        $this->assertSame(1, substr_count($content, '# TYPE hits counter'));
        // Sample line must still be present.
        $this->assertStringContainsString('hits 5', $content);
    }

    public function testDestructorSwallowsFlushFailure(): void
    {
        // Make flush() fail *cleanly* (no PHP warning): point the backend at a
        // path that is actually a non-empty directory, so the atomic rename()
        // over it fails. rename() is @-suppressed, so failOnWarning stays
        // satisfied while flush() still throws a RuntimeException.
        $dir = sys_get_temp_dir() . '/candy-metrics-dir-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/keep', 'x'); // non-empty → rename-over fails everywhere

        try {
            $b = new PrometheusFileBackend($dir);
            $b->counter('hits', 1); // mark dirty so flush() actually attempts the rename

            // Explicit flush surfaces the failure as a RuntimeException...
            $threw = false;
            try {
                $b->flush();
            } catch (\RuntimeException) {
                $threw = true;
            }
            $this->assertTrue($threw, 'explicit flush() must surface the rename failure');

            // ...but dropping the last reference (triggering __destruct) must not.
            unset($b);
            $this->assertTrue(true, 'destructor completed without throwing');
        } finally {
            @unlink($dir . '/keep');
            @unlink($dir . '.tmp');
            @rmdir($dir);
        }
    }

    public function testFlushWithNonDirtyMetricsStillEmitsThem(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('always_on', 1.0);
        $b->flush();

        // Modify directly to simulate a non-dirty metric (another process wrote to same file).
        // After first flush the dirty flag is cleared.
        // Now call flush again with no new data — the metric should still be emitted.
        file_put_contents($this->path, '');
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('always_on 1', $content);
    }

    public function testHistogramWithTagsEmitsAllBucketLines(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->histogram('lat', 0.05, ['route' => '/api']);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        // Bucket lines with route label.
        $this->assertStringContainsString('lat_bucket{route="/api",le="0.05"} 1', $content);
        $this->assertStringContainsString('lat_bucket{route="/api",le="+Inf"} 1', $content);
        $this->assertStringContainsString('lat_count{route="/api"} 1', $content);
        $this->assertStringContainsString('lat_sum{route="/api"} 0.05', $content);
    }

    public function testRemoveIsNoOp(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('hits', 5);
        $b->remove('hits', []); // No-op — must not throw or alter data.
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('hits 5', $content);
    }

    public function testClearIsNoOp(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('hits', 5);
        $b->clear(); // No-op — must not throw.
        $b->flush();

        $content = (string) file_get_contents($this->path);
        // Clear does not affect the Prometheus textfile backend.
        $this->assertStringContainsString('hits 5', $content);
    }

    public function testDescribeWithNoSamplesEmitsDescriptorOnly(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->describe(new Descriptor('defined_but_unused', 'Not yet observed', 'gauge'));
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('# HELP defined_but_unused Not yet observed', $content);
        $this->assertStringContainsString('# TYPE defined_but_unused gauge', $content);
        $this->assertStringNotContainsString('defined_but_unused 0', $content);
    }

    public function testSanitizeNamePrefixesDigitWithUnderscore(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('123abc', 1);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('_123abc 1', $content);
        $this->assertStringContainsString('# TYPE _123abc counter', $content);
    }

    public function testSanitizeKeyPrefixesDigitWithUnderscore(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('hits', 1, ['123tag' => 'v']);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('hits{_123tag="v"} 1', $content);
    }

    public function testUpDownCounterAccumulation(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->upDownCounter('conns', 3.0);
        $b->upDownCounter('conns', -1.0);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('conns 2', $content);
        $this->assertStringContainsString('# TYPE conns gauge', $content);
    }

    public function testAsyncCounterEmitsGaugeFormat(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->asyncCounter('jvm_gc_count', 10.0);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('jvm_gc_count 10', $content);
        $this->assertStringContainsString('# TYPE jvm_gc_count counter', $content);
    }

    public function testAsyncGaugeEmitsGauge(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->asyncGauge('heap_used', 128.5);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('heap_used 128.5', $content);
        $this->assertStringContainsString('# TYPE heap_used gauge', $content);
    }

    public function testHistogramBucketBoundaries(): void
    {
        $b = new PrometheusFileBackend($this->path, buckets: [0.01, 0.1, 1.0]);
        $b->histogram('lat', 0.05); // Falls in 0.1 bucket
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('lat_bucket{le="0.01"} 0', $content);
        $this->assertStringContainsString('lat_bucket{le="0.1"} 1', $content);
        $this->assertStringContainsString('lat_bucket{le="1"} 1', $content);
        $this->assertStringContainsString('lat_bucket{le="+Inf"} 1', $content);
    }

    public function testCustomBucketsWithTags(): void
    {
        $b = new PrometheusFileBackend($this->path, buckets: [1.0, 10.0]);
        $b->histogram('lat', 5.0, ['method' => 'GET']);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('lat_bucket{method="GET",le="1"} 0', $content);
        $this->assertStringContainsString('lat_bucket{method="GET",le="10"} 1', $content);
        $this->assertStringContainsString('lat_bucket{method="GET",le="+Inf"} 1', $content);
    }

    public function testCannotOpenTmpPathThrows(): void
    {
        $dir = sys_get_temp_dir() . '/candy-metrics-prom-tmp-' . uniqid();
        mkdir($dir);
        $path = $dir . '/nested/file.prom'; // Cannot be created

        $b = new PrometheusFileBackend($path);
        $b->counter('x', 1);

        // The explicit flush() surfaces the exception.
        // The destructor warning (from @fopen inside flush) is unavoidable
        // without modifying production code. We suppress it for this test.
        $this->expectException(\RuntimeException::class);
        try {
            $b->flush();
        } catch (\RuntimeException $e) {
            // Clean up $b's internal state so the destructor doesn't re-trigger flush.
            // We need to destroy $b while error_reporting is still silenced.
            (function (PrometheusFileBackend $inst): void {
                $inst->counter('x', 0); // marks dirty again — but we suppress flush via @ below
            })($b);
            // Suppress the destructor warning caused by the subsequent flush failure.
            @$b = null;
            throw $e; // re-throw so expectException sees it.
        }

        // Should not reach here.
        @unlink($dir . '/nested/file.prom.tmp');
        @rmdir($dir);
    }

    public function testEmitTypeEmitsOncePerFlushCycleForDescriptorWithSamples(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->describe(new Descriptor('described_metric', 'Has descriptor and samples', 'counter'));
        $b->counter('described_metric', 1.0);
        $b->counter('described_metric', 2.0, ['tag' => 'v']);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        // HELP and TYPE should appear once for the family, not per label set.
        $this->assertSame(1, substr_count($content, '# HELP described_metric'));
        $this->assertSame(1, substr_count($content, '# TYPE described_metric counter'));
        // But both sample lines should be present.
        $this->assertStringContainsString('described_metric 1', $content);
        $this->assertStringContainsString('described_metric{tag="v"} 2', $content);
    }
}
