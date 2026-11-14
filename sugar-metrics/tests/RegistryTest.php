<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests;

use SugarCraft\Metrics\Backend\InMemoryBackend;
use SugarCraft\Metrics\Backend\PrometheusFileBackend;
use SugarCraft\Metrics\Descriptor;
use SugarCraft\Metrics\Instrument\AsyncCounter;
use SugarCraft\Metrics\Instrument\AsyncGauge;
use SugarCraft\Metrics\Instrument\UpDownCounter;
use SugarCraft\Metrics\Registry;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    public function testCounterAccumulates(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $r->counter('hits');
        $r->counter('hits');
        $r->counter('hits', 3.5);
        $this->assertSame(5.5, $b->counterValue('hits'));
    }

    public function testGaugeReplaces(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $r->gauge('queue.depth', 4);
        $r->gauge('queue.depth', 7);
        $r->gauge('queue.depth', 2);
        $this->assertSame(2.0, $b->gaugeValue('queue.depth'));
    }

    public function testHistogramAppendsAllSamples(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        foreach ([0.1, 0.2, 0.4] as $v) {
            $r->histogram('latency', $v);
        }
        $this->assertSame([0.1, 0.2, 0.4], $b->histogramValues('latency'));
    }

    public function testTimeRecordsElapsedAsHistogram(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $stop = $r->time('handler');
        usleep(2000);
        $elapsed = $stop();
        $samples = $b->histogramValues('handler');
        $this->assertCount(1, $samples);
        $this->assertGreaterThan(0.001, $samples[0]);
        $this->assertSame($samples[0], $elapsed);
    }

    public function testWithTagsStampsEveryEmit(): void
    {
        $b = new InMemoryBackend();
        $r = (new Registry($b))->withTags(['user' => 'alice', 'env' => 'prod']);
        $r->counter('hits');
        $this->assertSame(1.0, $b->counterValue('hits', ['user' => 'alice', 'env' => 'prod']));
        $this->assertSame(0.0, $b->counterValue('hits'));
    }

    public function testCallSiteTagsMergeOverDefaults(): void
    {
        $b = new InMemoryBackend();
        $r = (new Registry($b))->withTags(['env' => 'prod']);
        $r->counter('hits', 1.0, ['route' => '/x']);
        $this->assertSame(1.0, $b->counterValue('hits', ['env' => 'prod', 'route' => '/x']));
    }

    public function testTagsKeyedByNameAndTagsTuple(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $r->counter('hits', 1.0, ['user' => 'alice']);
        $r->counter('hits', 1.0, ['user' => 'bob']);
        $r->counter('hits', 1.0, ['user' => 'alice']);
        $this->assertSame(2.0, $b->counterValue('hits', ['user' => 'alice']));
        $this->assertSame(1.0, $b->counterValue('hits', ['user' => 'bob']));
    }

    public function testRegisterIsIdempotent(): void
    {
        $r = new Registry(new InMemoryBackend());
        $d1 = new Descriptor('conns', 'Active connections', 'gauge');
        $same = new Descriptor('conns', 'Active connections', 'gauge'); // identical re-register

        $r->register($d1);
        // An identical descriptor (same name, type and help) is a no-op.
        $r->register($same);

        // Register a different descriptor to confirm it was stored (not overwritten).
        $r->register(new Descriptor('requests', 'Total requests', 'counter'));

        $ref = new \ReflectionClass(Registry::class);
        $prop = $ref->getProperty('descriptors');
        $prop->setAccessible(true);
        $descriptors = $prop->getValue($r);

        $this->assertArrayHasKey('conns', $descriptors);
        $this->assertSame('Active connections', $descriptors['conns']->help);
        $this->assertArrayHasKey('requests', $descriptors);
    }

    public function testRegisterConflictThrows(): void
    {
        // n7: a re-registration that CHANGES type or help used to be a
        // silent first-wins no-op — the second call site believed its
        // metadata shipped. Now it fails loud.
        $r = new Registry(new InMemoryBackend());
        $r->register(new Descriptor('conns', 'Active connections', 'gauge'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already registered');
        $r->register(new Descriptor('conns', 'Different help text', 'gauge'));
    }

    public function testInstrumentFactoriesReturnInstruments(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);

        // newUpDownCounter returns an UpDownCounter whose add() records via the backend.
        $counter = $r->newUpDownCounter('conns', 'Active connections');
        $this->assertInstanceOf(UpDownCounter::class, $counter);
        $counter->add(1);
        $counter->add(-1);
        $this->assertSame(0.0, $b->upDownCounterValue('conns'));

        // newAsyncCounter returns an AsyncCounter that observes via the callback.
        $asyncC = $r->newAsyncCounter('jvm_gc', 'JVM GC count', fn() => 5.0);
        $this->assertInstanceOf(AsyncCounter::class, $asyncC);
        $asyncC->observe();
        $this->assertSame(5.0, $b->asyncCounterValue('jvm_gc'));

        // newAsyncGauge returns an AsyncGauge that observes via the callback.
        $asyncG = $r->newAsyncGauge('heap_used', 'Heap memory used', fn() => 128.5);
        $this->assertInstanceOf(AsyncGauge::class, $asyncG);
        $asyncG->observe();
        $this->assertSame(128.5, $b->asyncGaugeValue('heap_used'));
    }

    public function testBackendAccessorReturnsBackend(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $this->assertSame($b, $r->backend());
    }

    public function testWithTagsPropagatesCardinalityLimit(): void
    {
        $b = new InMemoryBackend();
        $parent = new Registry($b, [], 1);  // cardinality limit of 1
        $child = $parent->withTags(['x' => '1']);

        // Child inherits the same cardinality limit.
        $child->counter('m', 1.0, ['y' => 'a']);
        $child->counter('m', 1.0, ['y' => 'b']); // exceeds limit of 1

        // Only one unique label combo should survive (FIFO eviction).
        $this->assertLessThanOrEqual(1, $child->cardinality('m'));
    }

    public function testDeleteLabelValuesRemovesSeriesAndDecreasesCardinality(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b, [], 1000);
        $r->counter('hits', 1.0, ['route' => '/a']);
        $r->counter('hits', 1.0, ['route' => '/b']);
        $this->assertSame(2, $r->cardinality('hits'));

        // deleteLabelValues only evicts from the cardinality cache, not from the backend.
        $r->deleteLabelValues('hits', ['route' => '/a']);
        $this->assertSame(1, $r->cardinality('hits'));
        // The backend value is NOT removed by deleteLabelValues (only by remove()).
        $this->assertSame(1.0, $b->counterValue('hits', ['route' => '/a']));
        $this->assertSame(1.0, $b->counterValue('hits', ['route' => '/b']));
    }

    public function testDeleteLabelValuesOnUnknownSeriesIsHarmless(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $r->deleteLabelValues('never_seen', ['x' => 'y']); // no-op, must not throw.
        $this->assertSame(0, $r->cardinality('never_seen'));
    }

    public function testRemoveForwardsToBackendAndCache(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b, [], 1000);
        $r->counter('hits', 1.0, ['route' => '/x']);
        $r->counter('hits', 2.0, ['route' => '/y']);
        $this->assertSame(2, $r->cardinality('hits'));

        $r->remove('hits', ['route' => '/x']);
        $this->assertSame(1, $r->cardinality('hits'));
        $this->assertSame(0.0, $b->counterValue('hits', ['route' => '/x']));
        $this->assertSame(2.0, $b->counterValue('hits', ['route' => '/y']));
    }

    public function testDeleteLabelValuesWithDefaultTagsHitsTheMergedTrackerKey(): void
    {
        // The tracker keys by the MERGED label set (defaults plus call
        // tags), so deleteLabelValues must merge the same way before
        // computing its key — with a bare-tag lookup the reclaim missed
        // every series on a registry that carries default tags.
        $b = new InMemoryBackend();
        $r = new Registry($b, ['env' => 'prod'], 1000);
        $r->counter('hits', 1.0, ['route' => '/a']);
        $r->counter('hits', 1.0, ['route' => '/b']);
        $this->assertSame(2, $r->cardinality('hits'));

        $r->deleteLabelValues('hits', ['route' => '/a']);
        $this->assertSame(1, $r->cardinality('hits'));

        // Same for the no-call-tags leg: the entry lives under the
        // defaults key, not the empty key.
        $r->counter('plain', 1.0);
        $this->assertSame(1, $r->cardinality('plain'));
        $r->deleteLabelValues('plain');
        $this->assertSame(0, $r->cardinality('plain'));
    }

    public function testRemoveWithDefaultTagsReclaimsTheMergedBackendSeries(): void
    {
        // remove() forwards the merged label set to the backend — the
        // series was stored under the merged key, so a bare-tag forward
        // missed it and the stale value kept being emitted.
        $b = new InMemoryBackend();
        $r = new Registry($b, ['env' => 'prod'], 1000);
        $r->counter('hits', 1.0, ['route' => '/x']);
        $r->counter('hits', 2.0, ['route' => '/y']);

        $r->remove('hits', ['route' => '/x']);
        $this->assertSame(1, $r->cardinality('hits'));
        $this->assertSame(0.0, $b->counterValue('hits', ['env' => 'prod', 'route' => '/x']));
        $this->assertSame(2.0, $b->counterValue('hits', ['env' => 'prod', 'route' => '/y']));
    }

    public function testCardinalityReturnsZeroForUnknownMetric(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $this->assertSame(0, $r->cardinality('unknown_metric'));
    }

    public function testRegistryRemoveFromNonExistentIsHarmless(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $r->remove('never_existed', ['x' => 'y']); // must not throw.
        $this->assertTrue(true);
    }

    public function testLookalikeTagSetsCountAsDistinctSeries(): void
    {
        // Cardinality tracking must not collapse the forged-pair tag set
        // into the real two-tag set (the Util::tagKey escape collision).
        $b = new InMemoryBackend();
        $r = new Registry($b, [], 1000);
        $r->counter('m', 1.0, ['x' => 'a|y=b']);
        $r->counter('m', 1.0, ['x' => 'a', 'y' => 'b']);
        $this->assertSame(2, $r->cardinality('m'));
    }

    public function testTimeSamplesOnTheMonotonicClockNeverWallTime(): void
    {
        // time() must pair hrtime(), not two microtime(true) reads: a
        // system clock step-back between them yields a negative elapsed,
        // which histogram() refuses — thrown from inside the caller's
        // finally arm, masking the original exception. Pin the shape at
        // the source. This is the JUSTIFIED no-seam exception accepted by
        // sugar-metrics-038/MET-SEAMS: the clock source is not injectable
        // through any public surface, so no behavior probe can observe it;
        // every other prose-pin named by that finding was replaced by a
        // seam instead of kept.
        $src = (string) file_get_contents(__DIR__ . '/../src/Registry.php');
        $matched = preg_match('/public function time\(.*?\n    \}\n/s', $src, $m);
        $this->assertSame(1, $matched, 'time() vanished — clock-shape pin needs re-aiming');
        $this->assertStringContainsString('hrtime(', $m[0]);
        // No microtime CALL may survive in the pair (the prose may name it
        // as the hazard); assert the invocation shape, not the bare word.
        $this->assertSame(0, preg_match('/microtime\s*\(/', $m[0]));

        // Behavioral floor: an honest reading is a non-negative float.
        $r = new Registry(new InMemoryBackend());
        $stop = $r->time('elapsed');
        $this->assertGreaterThanOrEqual(0.0, $stop());
    }

    /**
     * sugar-metrics-003 (with -037 note): a withTags() child inherits the
     * parent's descriptor ledger (snapshot, value-copied), so a clashing
     * re-registration on the child throws instead of silently
     * last-writer-winning the shared backend's TYPE/HELP. The DM-OPS-02
     * half: TYPE/HELP is backend-global — the child's emissions carry the
     * parent's metadata without any pointless per-child re-registration.
     */
    public function testWithTagsChildInheritsLedgerAndRefusesClashingReRegistration(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'metrics-wt');
        $this->assertIsString($path);
        $b = new PrometheusFileBackend($path);
        $r = Registry::new($b);
        $r->register(new Descriptor('m', 'parent help', 'gauge', ['route']));
        $child = $r->withTags(['user' => 'bob']);
        $conflict = null;
        try {
            $child->register(new Descriptor('m', 'clashing help', 'gauge', ['route']));
        } catch (\RuntimeException $e) {
            $conflict = $e;
        }
        // sugar-metrics-020 sweep: the old fail()-inside-try form had a
        // needle ('m') that occurs in its own fail message, so a swallowed
        // throw could still pass vacuously. Capture-flag with a contentful
        // needle that the guard's real message must carry.
        self::assertNotNull($conflict, 'a clashing re-registration on a withTags() child must throw');
        $this->assertStringContainsString('"m" is already registered', $conflict->getMessage());
        // A kind switch is refused by the same inherited table.
        $switch = null;
        try {
            $child->register(new Descriptor('m', 'parent help', 'counter', ['route']));
        } catch (\RuntimeException $e) {
            $switch = $e;
        }
        self::assertNotNull($switch, 'a kind-switch re-registration on a child must throw');
        $this->assertStringContainsString('cannot re-register as counter', $switch->getMessage());
        // Identical re-registration stays an idempotent no-op.
        $child->register(new Descriptor('m', 'parent help', 'gauge', ['route']));
        // Emissions ride the parent's metadata: the child never registered,
        // yet its exposition carries the TYPE/HELP lines.
        $child->gauge('m', 3, ['route' => '/x']);
        $b->flush();
        $body = (string) file_get_contents($path);
        $this->assertStringContainsString('# TYPE m gauge', $body);
        $this->assertStringContainsString('# HELP m parent help', $body);
        @unlink($path);
    }

    /**
     * sugar-metrics-003: inheritance is one-way and snapshot-at-creation —
     * a child registration never writes through to the parent ledger, a
     * parent registration after the split never back-fills an existing
     * child, and a fresh child takes the parent's current whole ledger.
     */
    public function testWithTagsDescriptorInheritanceIsOneWaySnapshot(): void
    {
        $b = new InMemoryBackend();
        $r = Registry::new($b);
        $child = $r->withTags(['user' => 'bob']);
        $child->register(new Descriptor('z', 'child help', 'gauge'));
        // The parent ledger saw no write-through: this is not a conflict there.
        $r->register(new Descriptor('z', 'parent help', 'gauge'));
        $r->register(new Descriptor('late', 'after the split', 'gauge'));
        // The earlier child never sees it (no retroactive back-fill).
        $child->register(new Descriptor('late', 'after the split', 'gauge'));
        // A fresh child inherits the whole current ledger.
        $fresh = $r->withTags([]);
        try {
            $fresh->register(new Descriptor('late', 'clashing', 'gauge'));
            $this->fail('a fresh child must inherit the parent ledger at split time');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('late', $e->getMessage());
        }
    }
}
