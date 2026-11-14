<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Metrics\Backend\InMemoryBackend;
use SugarCraft\Metrics\Backend\JsonStreamBackend;
use SugarCraft\Metrics\Backend\PrometheusFileBackend;
use SugarCraft\Metrics\Backend\StatsdBackend;
use SugarCraft\Metrics\Descriptor;
use SugarCraft\Metrics\Registry;
use SugarCraft\Metrics\Util;

/**
 * Pins for the 2026-11 review remediation (lib-reviews-1102/sugar-metrics):
 * wire-forgery vectors, exposition-integrity guards, cardinality doors,
 * loud-failure modes, and the instrument/merge semantics the review asked
 * to see byte-verified.
 */
final class ReviewFixPinsTest extends TestCase
{
    // ---------------------------------------------------------------- StatsD

    public function testCommaInTagValueCannotForgeASecondPair(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b    = new StatsdBackend(existingSocket: $sock);
        $b->counter('hits', 1.0, ['env' => 'prod,evil:victim']);
        rewind($sock);
        $payload = (string) stream_get_contents($sock);
        fclose($sock);

        // Byte-exact: the comma is flattened, so the tags segment holds ONE
        // pair; `evil:victim` can never parse as a second key:value pair.
        $this->assertSame('hits:1|c|#env:prod_evil:victim', $payload);
        $this->assertSame(2, substr_count($payload, '|'));
    }

    public function testSpaceInTagValueCannotForgeASecondPair(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b    = new StatsdBackend(existingSocket: $sock);
        $b->counter('hits', 1.0, ['env' => 'prod evil:victim']);
        rewind($sock);
        $payload = (string) stream_get_contents($sock);
        fclose($sock);
        $this->assertSame('hits:1|c|#env:prod_evil:victim', $payload);
    }

    public function testNameColonIsFlattenedWhileValueColonSurvives(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b    = new StatsdBackend(existingSocket: $sock);
        $b->counter('a:b', 1.0, ['route' => '/x:y']);
        rewind($sock);
        $payload = (string) stream_get_contents($sock);
        fclose($sock);

        // Names are colon-free (metrics20-style ambiguity), tag VALUES may
        // keep colons because the pair structure is byte-guaranteed.
        $this->assertSame('a_b:1|c|#route:/x:y', $payload);
    }

    public function testUtf8MultibytePayloadIsNotShreddedByTheC1Sweep(): void
    {
        $sock = fopen('php://memory', 'w+');
        $b    = new StatsdBackend(existingSocket: $sock);
        $b->counter('hits', 1.0, ['label' => 'a😀b']);
        rewind($sock);
        $payload = (string) stream_get_contents($sock);
        fclose($sock);
        $this->assertStringContainsString('label:a😀b', $payload);
    }

    public function testLoudModeRefusesAWriteToAReadonlySocket(): void
    {
        $sock = fopen('php://memory', 'r'); // writable=false: fwrite returns false
        $b    = new StatsdBackend(existingSocket: $sock, failSilently: false);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('write to resource#');
            $b->counter('hits', 1.0);
        } finally {
            fclose($sock);
        }
    }

    // ------------------------------------------------------------ textfile

    private function tempPromPath(): string
    {
        return sys_get_temp_dir() . '/sm-' . uniqid('', true) . '.prom';
    }

    public function testUnsortedBucketsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('strictly increasing');
        new PrometheusFileBackend($this->tempPromPath(), [2.0, 1.0]);
    }

    public function testDuplicateBucketsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PrometheusFileBackend($this->tempPromPath(), [1.0, 1.0]);
    }

    public function testNonFiniteBucketsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PrometheusFileBackend($this->tempPromPath(), [0.5, \INF]);
    }

    public function testSeriesPerFamilyBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PrometheusFileBackend($this->tempPromPath(), null, 0);
    }

    public function testCallerSuppliedLeLabelIsRefused(): void
    {
        $b = new PrometheusFileBackend($this->tempPromPath());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('caller-supplied "le" label');
        $b->histogram('lat', 0.1, ['le' => '1']);
    }

    public function testSanitizingFoldsThatWouldCollideAreRefused(): void
    {
        $b = new PrometheusFileBackend($this->tempPromPath());
        $b->counter('a.b', 1.0);
        // 'a-b' folds to the same wire name 'a_b' — silently sharing the
        // accumulator (and then remove() wiping both) was the old defect.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sanitize to the same metric name');
        $b->counter('a-b', 1.0);
    }

    public function testFoldCollisionOnTagKeysIsRefused(): void
    {
        $b = new PrometheusFileBackend($this->tempPromPath());
        $b->counter('hits', 1.0, ['a-b' => '1']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('same metric name');
        $b->counter('hits', 1.0, ['a_b' => '1']);
    }

    public function testEvictedSeriesFreesItsCollisionSlot(): void
    {
        $b = new PrometheusFileBackend($this->tempPromPath(), [1.0], 2);
        $b->counter('hits', 1.0, ['route' => 'a.b']); // folds to a_b
        $b->counter('hits', 1.0, ['route' => 'z']);
        $b->counter('hits', 1.0, ['route' => 'y']);   // FIFO-evicts the a_b series
        // Re-using the folded name must be legal once the old series is gone.
        $b->counter('hits', 1.0, ['route' => 'a-b']);
        $this->assertTrue(true, 'no collision throw after eviction');
    }

    public function testSameNameAcrossKindsFailsLoudAtFlush(): void
    {
        $path = $this->tempPromPath();
        $b    = new PrometheusFileBackend($path, [1.0]);
        $b->counter('hits', 1.0);
        $b->gauge('hits', 2.0); // same sample identity as the counter line
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('duplicate series');
            $b->flush();
        } finally {
            @unlink($path);
        }
    }

    public function testHistogramDerivedNameCollideFailsLoudAtFlush(): void
    {
        $path = $this->tempPromPath();
        $b    = new PrometheusFileBackend($path);
        $b->counter('lat_count', 1.0);
        $b->histogram('lat', 0.5); // derives a `lat_count` line for the same labels
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('duplicate series');
            $b->flush();
        } finally {
            @unlink($path);
        }
    }

    public function testControlBytesInLabelValuesAreSweptOrEscaped(): void
    {
        $path = $this->tempPromPath();
        $b    = new PrometheusFileBackend($path);
        $b->counter('hits', 1.0, ['k' => "v\x00x\ny"]);
        $b->flush();
        $contents = (string) file_get_contents($path);
        @unlink($path);

        // Spec escaping turns the newline into the two-character sequence
        // \n; the NUL byte has no spec escape so the control sweep replaces
        // it with an underscore. Raw control bytes may never appear.
        $this->assertStringNotContainsString("\x00", $contents);
        $this->assertStringContainsString('k="v_x\\ny"', $contents);
    }

    public function testSuccessfulFlushLeavesNoStagingResidue(): void
    {
        $path = $this->tempPromPath();
        $b    = new PrometheusFileBackend($path);
        $b->counter('hits', 1.0);
        $b->flush();
        $leaked  = glob($path . '.tmp.*');
        $this->assertSame([], $leaked === false ? [] : $leaked, 'staging files must not survive a flush');
        $this->assertStringContainsString('# TYPE hits counter', (string) file_get_contents($path));
        @unlink($path);
    }

    // -------------------------------------------------------------- Registry

    public function testCardinalityLimitBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cardinalityLimit must be >= 1');
        new Registry(new InMemoryBackend(), [], 0);
    }

    public function testCardinalityLimitAccessorEchoesTheBudget(): void
    {
        $r = new Registry(new InMemoryBackend(), [], 7);
        $this->assertSame(7, $r->cardinalityLimit());
    }

    public function testFlushForwardsToTheBackend(): void
    {
        $spy = new class implements \SugarCraft\Metrics\Backend {
            public bool $flushed = false;
            public function counter(string $name, float $value, array $tags = []): void {}
            public function gauge(string $name, float $value, array $tags = []): void {}
            public function histogram(string $name, float $value, array $tags = []): void {}
            public function upDownCounter(string $name, float $amount, array $tags = []): void {}
            public function asyncCounter(string $name, float $value, array $tags = []): void {}
            public function asyncGauge(string $name, float $value, array $tags = []): void {}
            public function describe(Descriptor $descriptor): void {}
            public function flush(): void { $this->flushed = true; }
            public function remove(string $name, array $tags = []): void {}
            public function clear(): void {}
        };
        (new Registry($spy))->flush();
        $this->assertTrue($spy->flushed);
    }

    public function testNegativeValuesOnMonotonicKindsAreRefused(): void
    {
        $r = new Registry(new InMemoryBackend());
        try {
            $r->counter('hits', -1.0);
            $this->fail('counter must refuse negative values');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refused negative value', $e->getMessage());
        }
        try {
            $r->histogram('lat', -0.5);
            $this->fail('histogram must refuse negative observations');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refused negative value', $e->getMessage());
        }
    }

    public function testNegativeGaugeAndAsyncSamplesStillPassThrough(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $r->gauge('temp', -5.0);
        $r->asyncCounter('offset_total', -2.0);
        $r->upDownCounter('conns', -3.0);
        $this->assertSame(-5.0, $b->gaugeValue('temp'));
        $this->assertSame(-2.0, $b->asyncCounterValue('offset_total'));
        $this->assertSame(-3.0, $b->upDownCounterValue('conns'));
    }

    public function testTimerSamplesExactlyOnceEvenWhenStoppedTwice(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $stop = $r->time('work');
        $stop();
        $stop();
        $this->assertCount(1, $b->histogramValues('work'), 'a double stop() must not double-record');
    }

    public function testRegisterDoorRefusesNamesThatBypassedTheDescriptor(): void
    {
        // MET-REGCONFLICT (001, Registry leg): instrument verbs skip
        // Descriptor entirely, and unserialize forges a DTO past its
        // constructor — the register() door re-validates so a hyphenated
        // (fold-prone) name can never enter the descriptor ledger.
        $forged = unserialize('O:29:"SugarCraft\\Metrics\\Descriptor":4:{s:4:"name";s:3:"a-b";s:4:"help";s:4:"help";s:4:"type";s:7:"counter";s:9:"labelKeys";a:0:{}}');
        self::assertInstanceOf(Descriptor::class, $forged);
        $r = new Registry(new InMemoryBackend());
        try {
            $r->register($forged);
            $this->fail('register() accepted a name the charset refuses');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('a-b', $e->getMessage());
        }
    }

    public function testReRegisterWithDifferentLabelKeysThrowsNotSilentlyPins(): void
    {
        // MET-REGCONFLICT (002): the docblock promised a throw for every
        // SECOND, DIFFERENT descriptor; labelKeys were ignored, so a
        // changed label set silently kept the stale first pin.
        $r = new Registry(new InMemoryBackend());
        $r->register(new Descriptor('m', 'help', 'counter', ['route']));
        $conflict = null;
        try {
            $r->register(new Descriptor('m', 'help', 'counter', ['method', 'code']));
        } catch (\RuntimeException $e) {
            $conflict = $e;
        }
        self::assertNotNull($conflict, 'different labelKeys must conflict, not overwrite silently');
        self::assertStringContainsString('already registered', $conflict->getMessage());
        // Control: the identical declaration (name, type, help, ordered
        // labelKeys) stays an idempotent no-op.
        $r->register(new Descriptor('m', 'help', 'counter', ['route']));
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------ JSON lines

    public function testEncodeFailureThrowsInLoudModeAndDropsInSilentMode(): void
    {
        $hostile = "\xFF\xFE invalid utf-8";
        $loud    = fopen('php://memory', 'w+');
        $lb      = new JsonStreamBackend($loud);
        try {
            $this->expectException(\RuntimeException::class);
            $lb->counter($hostile, 1.0);
        } finally {
            fclose($loud);
        }

        $quiet = fopen('php://memory', 'w+');
        $sb    = new JsonStreamBackend($quiet, throwOnError: false);
        $sb->counter($hostile, 1.0); // must not throw, must not write a corrupt record
        rewind($quiet);
        $this->assertSame('', (string) stream_get_contents($quiet));
        fclose($quiet);
    }

    public function testNonFiniteGaugeValuesRenderAsSpecTokens(): void
    {
        $stream = fopen('php://memory', 'w+');
        $b      = new JsonStreamBackend($stream);
        $b->gauge('t', \NAN);
        $b->gauge('p', \INF);
        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));
        fclose($stream);
        $this->assertSame('NaN', json_decode($lines[0], true)['value']);
        $this->assertSame('+Inf', json_decode($lines[1], true)['value']);
    }

    // -------------------------------------------------------------- InMemory

    public function testInMemoryDoorsRefuseSillyBounds(): void
    {
        try {
            new InMemoryBackend(0);
            $this->fail('maxSamplesPerKey must be >= 1');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('maxSamplesPerKey', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        new InMemoryBackend(10, -1);
    }

    public function testInMemoryTotalSeriesCapEvictsOldest(): void
    {
        $b = new InMemoryBackend(4096, 2);
        $b->counter('hits', 1.0, ['route' => 'a']);
        $b->counter('hits', 1.0, ['route' => 'b']);
        $b->counter('hits', 1.0, ['route' => 'c']); // evicts route=a (FIFO)
        $this->assertSame(2, $b->seriesCount());
        $this->assertSame(0.0, $b->counterValue('hits', ['route' => 'a']));
        $this->assertSame(1.0, $b->counterValue('hits', ['route' => 'c']));
    }

    // ------------------------------------------------------------ instruments

    public function testAsyncInstrumentPerCallTagsMergeOverStaticTags(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $g = $r->newAsyncGauge('queue_depth', 'depth', fn(): float => 42.0, ['pool' => 'main']);
        $g->observe();
        $g->observe(['pool' => 'other', 'job' => 'nightly']);
        $this->assertSame(42.0, $b->asyncGaugeValue('queue_depth', ['pool' => 'main']));
        $this->assertSame(
            42.0,
            $b->asyncGaugeValue('queue_depth', ['pool' => 'other', 'job' => 'nightly']),
            'per-call tags win over static ones',
        );
    }

    // ------------------------------------------------------------------ Util

    public function testTagKeyEscapesNulSoTwoTagSetsNeverAlias(): void
    {
        $withNul = Util::tagKey(['k' => "a\x00b"]);
        $literal = Util::tagKey(['k' => 'a\\0b']);
        $this->assertStringContainsString('\\0', $withNul);
        $this->assertNotSame($withNul, $literal, 'NUL must escape distinctly from the literal backslash-0');
    }

    // ------------------------------------------------------------ Descriptor

    public function testDescriptorNameRefusesTrailingNewlineUnderDAnchor(): void
    {
        // MET-NAMEGATE (053): a bare $ matches before a trailing newline;
        // "foo\n" would then ride the exposition line and fork a second one.
        $this->expectException(\InvalidArgumentException::class);
        new Descriptor("foo\n", 'help', 'counter');
    }

    public function testDescriptorLabelKeysEnforcePrometheusLabelCharset(): void
    {
        // MET-NAMEGATE (006): colons belong to metric names, not label
        // names; a bad key must throw naming the offending key displaySafe'd.
        foreach (['1foo', 'a-b', 'user name', 'a:b', "k\n"] as $bad) {
            try {
                new Descriptor('foo', 'help', 'counter', [$bad]);
                $this->fail('label key ' . Util::displaySafe($bad) . ' should have been refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString(Util::displaySafe($bad), $e->getMessage());
            }
        }
        // Controls: legal label names — including the histogram-reserved
        // 'le', whose refusal stays an emit-side concern — stay accepted.
        $d = new Descriptor('foo', 'help', 'counter', ['le', 'method', '_x9']);
        $this->assertSame(['le', 'method', '_x9'], $d->labelKeys);
    }
}
