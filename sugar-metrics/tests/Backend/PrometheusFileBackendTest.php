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

    /** Fresh target path for tests that need more than one backend at once. */
    private function tempPromPath(): string
    {
        return sys_get_temp_dir() . '/sugar-metrics-' . uniqid() . '.prom';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path] as $f) {
            if ($f !== '' && is_file($f)) {
                unlink($f);
            }
        }
        // flush() stages into unique per-writer files `<path>.<pid>-<entropy>.tmp`;
        // sweep them (plus the pre-STAGING `.tmp.<suffix>` shape) so a leak (or
        // a test planted residue) cannot survive.
        foreach (array_merge(glob($this->path . '.*.tmp') ?: [], glob($this->path . '.tmp.*') ?: []) as $leaked) {
            if (is_file($leaked)) {
                unlink($leaked);
            }
        }
        if (is_file($this->path . '.tmp')) {
            unlink($this->path . '.tmp');
        }
    }

    public function testSameTagsInDifferentInsertionOrderAreOneSeriesNotACollision(): void
    {
        // storageKey ksorts the labels, so ['a'=>1,'b'=>2] and ['b'=>2,'a'=>1]
        // are one series. The rawFacts guard compared them with order-strict
        // !== and raised a false "sanitize to the same metric name" refusal.
        $b = new PrometheusFileBackend($this->path);
        $b->counter('x', 1.0, ['a' => '1', 'b' => '2']);
        $b->counter('x', 2.0, ['b' => '2', 'a' => '1']);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('x{a="1",b="2"} 3', $content);
        $this->assertSame(1, substr_count($content, 'x{a='));
    }

    public function testNameUsedAsBothHistogramAndCounterIsRefusedEvenUnderDifferentLabels(): void
    {
        // A histogram family `x` emits only the derived lines x_bucket/x_count/
        // x_sum, so a real counter also named x shares no line identity with
        // it — the per-line flush guard cannot see the clash, yet the file
        // would carry one base name as two kinds and node_exporter rejects
        // the whole exposition. flush() must refuse on the name alone.
        $b = new PrometheusFileBackend($this->path);
        $b->counter('job_runs', 1.0, ['kind' => 'nightly']);
        $b->histogram('job_runs', 0.5, ['kind' => 'hourly']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot carry two kinds');
        $b->flush();
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

    public function testDoubleFlushIsIdempotentAndGaugeReplacementIsLastWriteWins(): void
    {
        // Finding 039: nothing pinned that a SECOND flush publishes no duplicate
        // '# TYPE' header (a duplicate is an unparseable exposition — node_exporter
        // rejects the WHOLE file), nor that the Prometheus gauge path REPLACES
        // ($this->gauges[$key] = $value) instead of accumulating: a += regression
        // would keep 'queue_depth 17' green while the value line quietly doubles.
        // Written to hold in both flush eras: pre-MET-DIRTYGATE the second flush
        // rewrites byte-identical content, post-gate it is skipped — the published
        // file must carry exactly one header and the last gauge value either way.
        $b = new PrometheusFileBackend($this->path);
        $b->gauge('queue_depth', 4);
        $b->gauge('queue_depth', 17);
        $b->flush();
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertSame(1, substr_count($content, '# TYPE queue_depth gauge'));
        $this->assertSame(1, substr_count($content, "queue_depth 17\n"));
        $this->assertStringNotContainsString('queue_depth 4', $content);
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

    public function testIdleFlushSkipsTheRewriteButDeletedFileRepublishesState(): void
    {
        // MET-DIRTYGATE supersession (disclosed): this seat previously ran
        // testFlushWithNonDirtyMetricsStillEmitsThem, which truncated the
        // published file behind the backend's back and expected the next idle
        // flush to rewrite it — pinning the very defect finding 024 names
        // (flags maintained, never read). The class is the file's only
        // writer (MET-STAGING doctrine), so external mutation is out of the
        // contract. The honest re-materialisation signal is file DELETION
        // (node_exporter one-shot flows), which the gate still honours.
        $b = new PrometheusFileBackend($this->path);
        $b->counter('always_on', 1.0);
        $b->flush();
        $text = (string) file_get_contents($this->path);
        self::assertStringContainsString('always_on 1', $text);

        // Idle flush touches NOTHING: a distinctive future mtime survives
        // (any rebuild/rename would re-stamp it with the clock).
        $sentinel = time() + 1000;
        touch($this->path, $sentinel);
        $b->flush();
        $this->assertSame($sentinel, filemtime($this->path), 'idle flush rewrote the published file');
        $this->assertStringContainsString('always_on 1', (string) file_get_contents($this->path));

        // Consumer removes the file → the next flush republishes the full
        // state even though nothing emitted since.
        unlink($this->path);
        $b->flush();
        $this->assertStringEqualsFile($this->path, $text);
    }

    public function testIdleFlushStillHonoursDescriptorRemoveAndClearSignals(): void
    {
        // The flush gate (sugar-metrics-024) reads a general exposition-dirty
        // flag beside the per-series ones, because these three events change
        // the body without touching any series dirty bit.
        $b = new PrometheusFileBackend($this->path);
        $b->counter('keep_me', 2.0);
        $b->gauge('drop_me', 3.0);
        $b->flush();

        // (a) remove() reclaims a series and clears its per-key dirty bit —
        // the general flag must carry the event or the stale line survives.
        $b->remove('drop_me');
        $b->flush();
        $content = (string) file_get_contents($this->path);
        $this->assertStringNotContainsString('drop_me', $content);
        $this->assertStringContainsString('keep_me 2', $content);

        // (b) describe() of a never-sampled family declares HELP/TYPE with no
        // series dirt at all.
        $b->describe(new Descriptor('idle_family', 'declared while idle', 'gauge'));
        $b->flush();
        $this->assertStringContainsString('# TYPE idle_family gauge', (string) file_get_contents($this->path));

        // (c) clear() empties everything; the next flush must publish the
        // empty state rather than skip into a stale file.
        $b->clear();
        $b->flush();
        $this->assertStringNotContainsString('keep_me', (string) file_get_contents($this->path));
    }

    public function testInvalidUtf8IsRepairedToReplacementCharNotRejected(): void
    {
        // finding 031: the exposition mandates UTF-8 and one invalid byte —
        // a latin-1 username (byte E9), a truncated sequence — was enough
        // for a strict textfile consumer to reject the WHOLE file, the same
        // all-series blackout the format guards exist to prevent. The sweep
        // now repairs: flush() succeeds, every published byte is valid
        // UTF-8 with U+FFFD standing where corruption was, and healthy
        // well-formed series pass byte-identical (losslessness guard).
        $b = new PrometheusFileBackend($this->path);
        $b->gauge('ok', 1.0, ['user' => "caf\xe9"]);            // latin-1 é: invalid lead byte
        $b->gauge('cut', 2.0, ['who' => "caf\xc3"]);           // truncated 2-byte é lead
        $b->gauge('fine', 3.0, ['who' => 'naïve']);       // valid UTF-8 sibling
        $b->describe(new Descriptor('helpy', "latin-\xe9 reader", 'gauge'));
        $b->flush();

        $body = (string) file_get_contents($this->path);
        $this->assertTrue(mb_check_encoding($body, 'UTF-8'), 'published exposition carries invalid UTF-8');
        $this->assertStringContainsString('ok{user="caf' . "\u{FFFD}" . '"} 1', $body);
        $this->assertStringContainsString('cut{who="caf' . "\u{FFFD}" . '"} 2', $body);
        $this->assertStringNotContainsString("\xe9", $body);
        $this->assertStringContainsString('fine{who="naïve"} 3', $body);
        $this->assertStringContainsString('# HELP helpy latin-' . "\u{FFFD}" . ' reader', $body);
    }

    public function testFlushOfNonFiniteGaugeSpellsCanonicalTokenNotSignFlippedInf(): void
    {
        // finding 018: sprintf('%.6f', -INF) renders "INF" — a falling
        // gauge would publish a wrong-signed reading the parser accepts,
        // silently inverting the alarm. The textfile fmt() routes through
        // Util::nonFiniteToken first; pin the exact sample line.
        $b = new PrometheusFileBackend($this->path);
        $b->gauge('t', -\INF);
        $b->flush();
        $lines = explode("\n", (string) file_get_contents($this->path));
        $this->assertContains('t -Inf', $lines);
        $this->assertNotContains('t INF', $lines);

        $b2 = new PrometheusFileBackend($this->path);
        $b2->gauge('u', \INF);
        $b2->flush();
        $this->assertContains('u +Inf', explode("\n", (string) file_get_contents($this->path)));
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

    public function testRemoveReachingThroughTheFoldOntoAnotherRawsSeriesIsRefused(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('a.b', 5);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sanitize to the same metric name');
        $b->remove('a-b'); // Same folded key, DIFFERENT raw identity — key() would refuse this as a collision; remove() must too.
    }

    public function testRemoveRefusalLeavesTheTwinAliveAndHonestReclaimFreesTheFold(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('a.b', 5);

        try {
            $b->remove('a-b');
            $this->fail('expected the name-collision refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sanitize to the same metric name', $e->getMessage());
        }

        $b->remove('a.b'); // The honest reclaim.
        $b->counter('a-b', 2.0); // The collision record went with the series — the fold is free for the twin now.
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringNotContainsString('a_b 5', $content);
        $this->assertStringContainsString('a_b 2', $content);
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
        self::assertStringContainsString($path, $thrown->getMessage());
        self::assertStringContainsString('.tmp', $thrown->getMessage());
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
            self::assertStringContainsString($path, $message, "refusal for {$path} must name the target");
            self::assertStringContainsString('.tmp', $message, "refusal for {$path} must name the staging suffix");
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
     * Structural pin (MET-STAGING doctrine, sugar-metrics-028/055/057): the
     * staging name must END in `.tmp` (node_exporter's version-independent
     * ignore rule), be created `x+` (O_EXCL — a `c+` open republished planted
     * bytes verbatim), derive entropy from random_int (a clock-derived
     * `uniqid` name is predictable), carry the writer pid, and lock nothing
     * (the per-writer-unique name makes a staging flock unfalsifiable — the
     * old prose sold it as a concurrency defence that never defended).
     */
    public function testFlushStagesIntoAPerWriterUniqueTmpName(): void
    {
        $method = new \ReflectionMethod(PrometheusFileBackend::class, 'flush');
        $lines  = file((string) $method->getFileName());
        self::assertIsArray($lines);
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        self::assertStringContainsString(". '.tmp'", $body, 'the staging name must end in .tmp');
        self::assertStringContainsString("'x+'", $body, 'staging must be an exclusive create — c+ republished planted bytes');
        self::assertStringContainsString('getmypid()', $body, 'the staging name must carry the writer pid');
        self::assertStringNotContainsString('uniqid(', $body, 'the staging name must not ride the clock');
        self::assertStringNotContainsString('flock(', $body, 'rename(2) carries the publish guarantee; a staging flock is unfalsifiable here');
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
        foreach (array_merge(glob($this->path . '.*.tmp') ?: [], glob($this->path . '.tmp.*') ?: []) as $leaked) {
            self::fail('unique staging file leaked to disk: ' . $leaked);
        }
    }

    public function testEnsureSpaceWritesTheFamilyIndexInPlaceNoPerFamilyCopy(): void
    {
        // MT-8 perf shape: a new-series write used to read the WHOLE family
        // key index into a local, mutate it, and reassign it. Copy-on-write
        // separation made every new series O(series-in-family) — with the
        // default cap of 10 000 that is a multi-kilobyte hash-table copy per
        // write. ensureSpace must now address $this->familyKeys[$family]
        // directly, so adding a series does O(1) work in family size.
        $src = (string) file_get_contents(
            __DIR__ . '/../../src/Backend/PrometheusFileBackend.php'
        );
        $matched = preg_match('/private function ensureSpace.*?\n    \}\n/s', $src, $m);
        $this->assertSame(1, $matched, 'ensureSpace() vanished — perf-shape pin needs re-aiming');
        $slice = $m[0];
        $this->assertStringNotContainsString('?? []', $slice);
        $this->assertStringNotContainsString('$index[', $slice);
        $this->assertStringNotContainsString('$index =', $slice);
        $this->assertStringContainsString('$this->familyKeys[$family][$key] = true;', $slice);
    }

    /**
     * sugar-metrics-054 (MET-EMITGUARD): a name or label key that sanitizes
     * to empty must refuse at the emit chokepoint, never enter the
     * accumulator — one such line makes node_exporter reject the whole file,
     * and the whole-replace flush would republish the poison forever.
     *
     * The refusals are captured via a helper, NOT assert-thrown-in-try:
     * PHPUnit's fail() throws AssertionFailedError, which extends
     * RuntimeException and would be swallowed by a catch (\RuntimeException)
     * right below it (vacuous-catch class).
     */
    public function testEmptyNameAndLabelKeyRefuseAtEmitAndNeverPoisonTheFile(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $nameMsg = null;
        $labelMsg = null;
        try {
            $b->counter('', 5.0);
        } catch (\RuntimeException $e) {
            $nameMsg = $e->getMessage();
        }
        $this->assertNotNull($nameMsg, 'counter() with an empty name must refuse');
        $this->assertStringContainsString('metric name', (string) $nameMsg);
        $this->assertStringContainsString('sanitizes to empty', (string) $nameMsg);
        try {
            $b->gauge('ok', 1.0, ['' => 'v']);
        } catch (\RuntimeException $e) {
            $labelMsg = $e->getMessage();
        }
        $this->assertNotNull($labelMsg, 'gauge() with an empty label key must refuse');
        $this->assertStringContainsString('label key', (string) $labelMsg);
        // The refused emits left no trace: a later healthy emit plus flush
        // publishes a well-formed body containing only the healthy series.
        $b->gauge('ok', 1.0);
        $b->flush();
        $body = (string) file_get_contents($this->path);
        $this->assertStringContainsString("ok 1\n", $body);
        $this->assertStringNotContainsString('# TYPE  gauge', $body);
        $this->assertStringNotContainsString('{=', $body, 'refused series must never reach the file');
        foreach (explode("\n", trim($body)) as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $this->assertMatchesRegularExpression('/^[a-zA-Z_:][a-zA-Z0-9_:]*(\{[^{}]*\})? ([-+.eE0-9]+|NaN|\+Inf|-Inf)$/D', $line);
        }
    }

    /**
     * sugar-metrics-054 (MET-EMITGUARD): the flush-side line-shape belt is
     * belt-and-braces — unreachable while the chokepoints hold — so it is
     * pinned by calling the renderer directly with a malformed shape.
     */
    public function testMetricLineBeltRefusesMalformedLineShapes(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $m = new \ReflectionMethod(PrometheusFileBackend::class, 'metricLine');
        $m->setAccessible(true);
        // ('ok{"k"}' deliberately stays out of the set: the belt is a line
        // SHAPE check, and that body is shape-legal — label grammar is the
        // sanitizer's job upstream.)
        foreach (['bad name!', '', 'ok{'] as $badName) {
            $msg    = null;
            $threw  = false;
            try {
                $m->invoke($b, $badName, '', 1.0);
            } catch (\RuntimeException $e) {
                $threw = true;
                $msg   = $e->getMessage();
            }
            $this->assertTrue($threw, 'malformed line name must not be publishable: ' . var_export($badName, true));
            $this->assertStringContainsString('malformed exposition line', (string) $msg);
        }
        // The belt accepts the canonical shapes: finite, exponent, and the
        // non-finite tokens (NaN/Inf are valid Prometheus values).
        $this->assertStringContainsString("ok 1\n", $m->invoke($b, 'ok', '', 1.0));
        $this->assertStringContainsString("ok NaN\n", $m->invoke($b, 'ok', '', NAN));
        $this->assertStringContainsString("ok -Inf\n", $m->invoke($b, 'ok', '', -\INF));
    }

    /**
     * sugar-metrics-058 (MET-EMITGUARD): the cross-kind refusal is once, at
     * the call site — the series already owned by the first kind stays and
     * every later flush works (the old wedge made flush throw forever).
     */
    public function testCrossKindRefusalHealsNextFlushInsteadOfWedgingForever(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('dup', 1.0);
        $msg = null;
        try {
            $b->gauge('dup', 2.0);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
        }
        $this->assertNotNull($msg, 'cross-kind emit must refuse at the call site');
        $this->assertStringContainsString('already emitted as a counter', (string) $msg);
        $b->flush();
        $this->assertStringContainsString("dup 1\n", (string) file_get_contents($this->path));
    }
    /**
     * sugar-metrics-029: the publish mode is decided by this class, not the
     * process umask — 0600 by default (attacker-influenced session ids ride
     * in the labels), `fileMode:` opens it up for a reader as another user.
     */
    public function testPublishedFileIsClosedToOtherUsersByDefaultAndOpensViaFileMode(): void
    {
        $closed = $this->tempPromPath();
        $open   = $this->tempPromPath();
        $a = new PrometheusFileBackend($closed);
        $a->counter('hits', 1.0);
        $a->flush();
        clearstatcache();
        self::assertSame(0600, fileperms($closed) & 0777, 'publish must default to owner-only, umask-independent');
        $b = new PrometheusFileBackend($open, fileMode: 0644);
        $b->counter('hits', 1.0);
        $b->flush();
        clearstatcache();
        self::assertSame(0644, fileperms($open) & 0777, 'fileMode must override the default upward for a shared reader');
        unset($a, $b);
        @unlink($closed);
        @unlink($open);
    }

    /** sugar-metrics-029: a nonsense mask refuses at the door (fail fast). */
    public function testInvalidFileModeRefusedAtTheDoor(): void
    {
        foreach ([07777, -1] as $mode) {
            try {
                new PrometheusFileBackend($this->tempPromPath(), fileMode: $mode);
                self::fail("fileMode {$mode} must refuse at construction");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('fileMode', $e->getMessage());
            }
        }
    }

    /**
     * sugar-metrics-055: a crash between staging create and rename leaks a
     * `.tmp` file (possibly a COMPLETE body). Every successful publish
     * reclaims residue of its own pattern past the grace window — and only
     * that: in-window names (a live writer's flight), differently-shaped
     * neighbours, and foreign files must survive the sweep.
     */
    public function testStaleStagingResidueIsSweptAndFreshResidueIsKept(): void
    {
        $path    = $this->tempPromPath();
        $stale   = $path . '.4242-99.tmp';
        $fresh   = $path . '.1337-98.tmp';
        $alien   = $path . 'other.tmp';
        $foreign = dirname($path) . '/unrelated-' . getmypid() . '.tmp';
        foreach ([$stale, $fresh, $alien, $foreign] as $planted) {
            self::assertIsInt(file_put_contents($planted, 'planted'));
        }
        self::assertTrue(touch($stale, time() - 7200));
        self::assertTrue(touch($fresh, time()));
        $b = new PrometheusFileBackend($path);
        $b->counter('hits', 1.0);
        $b->flush();
        self::assertFileDoesNotExist($stale, 'residue past the grace window must be reclaimed by the next publish');
        self::assertFileExists($fresh, 'names inside the grace window belong to a possibly-live writer and must be left alone');
        self::assertFileExists($alien);
        self::assertFileExists($foreign);
        foreach ([$alien, $foreign] as $planted) {
            @unlink($planted);
        }
        unset($b);
        @unlink($path);
    }

    /**
     * sugar-metrics-028: 16 planted files across the staging pattern space
     * must neither wedge the writer (x+ retries with fresh entropy) nor
     * poison the exposition (the published body is this writer's bytes
     * only). The old `c+` + predictable-uniqid design failed both directions.
     */
    public function testPlantedStagingClutterNeverWedgesOrPoisonsThePublish(): void
    {
        $path = $this->tempPromPath();
        for ($i = 0; $i < 16; $i++) {
            self::assertIsInt(file_put_contents($path . '.' . getmypid() . '-' . random_int(0, 999999) . '.tmp', 'planted junk'));
        }
        $b = new PrometheusFileBackend($path);
        $b->counter('hits', 2.0);
        $b->flush();
        $contents = (string) file_get_contents($path);
        self::assertStringNotContainsString('planted', $contents);
        self::assertStringContainsString('hits 2', $contents);
        foreach (array_merge(glob($path . '.*.tmp') ?: [], glob($path . '.tmp.*') ?: []) as $leaked) {
            @unlink($leaked);
        }
        unset($b);
        @unlink($path);
    }
}
