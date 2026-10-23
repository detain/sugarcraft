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
        $this->path = sys_get_temp_dir() . '/sugar-metrics-' . uniqid() . '.prom';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path] as $f) {
            if ($f !== '' && is_file($f)) {
                unlink($f);
            }
        }
        // flush() stages into unique per-writer files `<path>.tmp.<pid>-...`;
        // sweep them so a leak (or a test planted residue) cannot survive.
        foreach (glob($this->path . '.tmp.*') ?: [] as $leaked) {
            if (is_file($leaked)) {
                unlink($leaked);
            }
        }
        if (is_file($this->path . '.tmp')) {
            unlink($this->path . '.tmp');
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
        $dir = sys_get_temp_dir() . '/sugar-metrics-dir-' . uniqid();
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

    public function testRemoveDropsTheAccumulatedSeries(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('hits', 5);
        $b->remove('hits', []); // Real reclaim now — next flush omits the series.
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringNotContainsString('hits 5', $content);
    }

    public function testRemoveTargetsOnlyTheNamedSeries(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('hits', 5, ['route' => '/a']);
        $b->counter('hits', 7, ['route' => '/b']);
        $b->remove('hits', ['route' => '/a']);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringNotContainsString('route="/a"', $content);
        $this->assertStringContainsString('hits{route="/b"} 7', $content);
    }

    public function testClearForgetsEverySeries(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('hits', 5);
        $b->gauge('depth', 2);
        $b->clear();
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringNotContainsString('hits 5', $content);
        $this->assertStringNotContainsString('depth 2', $content);
    }

    public function testFamilyCapEvictsOldestSeriesFifo(): void
    {
        // maxSeriesPerFamily=2: creating a third distinct series in the
        // same family must drop the oldest so memory stays bounded.
        $b = new PrometheusFileBackend($this->path, null, 2);
        $b->counter('hits', 1, ['route' => '/a']);
        $b->counter('hits', 2, ['route' => '/b']);
        $b->counter('hits', 3, ['route' => '/c']); // evicts /a
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringNotContainsString('route="/a"', $content);
        $this->assertStringContainsString('hits{route="/b"} 2', $content);
        $this->assertStringContainsString('hits{route="/c"} 3', $content);
    }

    public function testZeroOrNegativeFamilyCapIsRefused(): void
    {
        foreach ([0, -1] as $bad) {
            $threw = false;
            try {
                new PrometheusFileBackend($this->path, null, $bad);
            } catch (\InvalidArgumentException) {
                $threw = true;
            }
            $this->assertTrue($threw, "maxSeriesPerFamily={$bad} must be refused at construction");
        }
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
        $dir = sys_get_temp_dir() . '/sugar-metrics-prom-tmp-' . uniqid();
        mkdir($dir);
        $path = $dir . '/nested/file.prom'; // parent dir cannot be created

        $b = new PrometheusFileBackend($path);
        $b->counter('x', 1);

        // flush() refuses at the parent probe before fopen() can warn, so
        // neither the explicit flush below nor the
        // destructor's implicit second flush emits a PHP diagnostic — the old
        // `@$b = null` suppression dance around the destructor is gone.
        $thrown = null;
        try {
            $b->flush();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        unset($b);

        self::assertNotNull($thrown, 'explicit flush() must surface the refusal');
        self::assertStringContainsString($path . '.tmp', $thrown->getMessage());
        @rmdir($dir);
    }

    /**
     * Both uncreatable-tmp shapes — parent dir missing AND parent is a
     * regular file — must refuse through the RuntimeException contract WITHOUT
     * raising any PHP diagnostic. The file-as-parent shape (ENOTDIR) is the
     * hermetic always-fails mechanism: it fails for every uid including root,
     * unlike permission bits. A test-local error handler records anything the
     * door might emit; failOnWarning alone cannot pin the same property when a
     * stray `@` could hide it, so this test stands on its own.
     */
    public function testCannotOpenTmpPathRefusesWithoutWarning(): void
    {
        $dir = sys_get_temp_dir() . '/sugar-metrics-tmpdoor-' . uniqid();
        mkdir($dir);
        $blocker = $dir . '/blocker';
        file_put_contents($blocker, '');
        $missingParent = $dir . '/nested/metrics.prom';
        $fileParent = $blocker . '/metrics.prom';

        $raised = [];
        set_error_handler(static function (int $severity, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true; // consume the diagnostic: the refusal must stay silent
        });

        $refusalMessages = [];
        try {
            foreach ([$missingParent, $fileParent] as $path) {
                $b = new PrometheusFileBackend($path);
                $b->counter('x', 1);
                try {
                    $b->flush();
                } catch (\RuntimeException $e) {
                    $refusalMessages[$path] = $e->getMessage();
                }
                unset($b); // destructor's second flush() must also stay silent
            }
        } finally {
            restore_error_handler();
        }

        self::assertSame([$missingParent, $fileParent], array_keys($refusalMessages), 'both uncreatable shapes must refuse');
        foreach ($refusalMessages as $path => $message) {
            self::assertStringStartsWith('Prometheus textfile: cannot open ', $message, "refusal for {$path} must use the documented door message");
            self::assertStringContainsString($path . '.tmp', $message, "refusal for {$path} must name the tmp path");
        }
        self::assertSame([], $raised, 'the refusal door must not raise a PHP diagnostic');

        @unlink($blocker);
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

    /**
     * Structural pin: flush() must stage into a per-writer UNIQUE temp file.
     * A fixed shared name (`<path>.tmp`) lets one writer's rename steal the
     * other's staging file, so the loser dies on a spurious
     * rename_failed(ENOENT) — this pin reddens deterministically if the unique
     * suffix is ever dropped, without relying on process interleavings.
     */
    public function testFlushStagesIntoAPerWriterUniqueTmpName(): void
    {
        $method = new \ReflectionMethod(PrometheusFileBackend::class, 'flush');
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        self::assertStringContainsString('.tmp.', $body, 'flush() must stage into a .tmp.<suffix> file');
        self::assertStringContainsString('uniqid(', $body, 'the staging name must be unique per flush');
        self::assertStringNotContainsString("\$this->path . '.tmp';", $body, 'a fixed shared staging name re-introduces the rename race');
    }

    /**
     * End-to-end probe: two processes flushing the same path concurrently
     * must both succeed (zero child failure, zero parent throws), publish a
     * complete last-writer-wins snapshot, and leave no staging residue.
     */
    public function testConcurrentFlushesFromTwoProcessesPublishCleanly(): void
    {
        $childScript = __DIR__ . '/_fixtures/concurrent-flush-child.php';
        self::assertFileExists($childScript);

        $proc = proc_open(
            [PHP_BINARY, $childScript, $this->path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($proc);

        $parent = new PrometheusFileBackend($this->path);
        for ($i = 0; $i < 30; $i++) {
            $parent->counter('parent_tally', 1.0, ['writer' => 'parent']);
            $parent->flush(); // must never throw on the other process's staging traffic
            usleep(2000);
        }

        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($proc), 'child flush loop failed: ' . $stderr);

        $content = (string) file_get_contents($this->path);
        self::assertNotSame('', $content);
        foreach (explode("\n", $content) as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            self::assertMatchesRegularExpression(
                '/^[a-zA-Z_:][a-zA-Z0-9_:]*(\{[^{}]*\})? [-+.eE0-9]+$/',
                $line,
                'published body must be a complete well-formed snapshot, not an interleaved write'
            );
        }
        self::assertNotSame(
            str_contains($content, 'parent_tally'),
            str_contains($content, 'child_tally'),
            'the final file must be one writer\'s complete snapshot (last-writer-wins), never a mix'
        );

        unset($parent);
        foreach (glob($this->path . '.tmp.*') ?: [] as $leaked) {
            self::fail('unique staging file leaked to disk: ' . $leaked);
        }
    }
}
