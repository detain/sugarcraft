<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Metrics\Backend\InMemoryBackend;
use SugarCraft\Metrics\Backend\PrometheusFileBackend;
use SugarCraft\Metrics\Instrument\Counter;
use SugarCraft\Metrics\Instrument\Gauge;
use SugarCraft\Metrics\Instrument\Histogram;
use SugarCraft\Metrics\Registry;

/**
 * sugar-metrics-021: the synchronous trio (Counter/Gauge/Histogram)
 * became holdable instruments mirroring the proved UpDownCounter shape —
 * static tags captured once, per-call tags winning on conflicts, and the
 * verb-level guards (monotonic refusal, histogram negative refusal,
 * kind-switch refusal, empty-name refusal) applying verbatim because the
 * instruments replay through the ordinary Registry verbs.
 */
final class InstrumentSymmetryTest extends TestCase
{
    public function testCounterInstrumentAccumulatesAndMergesStaticTags(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $hits = $r->newCounter('hits', 'Requests served', ['route' => '/x']);
        $this->assertInstanceOf(Counter::class, $hits);
        $this->assertSame('hits', $hits->name());
        $this->assertSame('Requests served', $hits->help());

        $hits->inc();               // default step 1.0
        $hits->inc(2.5);            // explicit amount
        $hits->inc(1.0, ['route' => '/y']); // per-call tag wins on conflict
        $this->assertSame(3.5, $b->counterValue('hits', ['route' => '/x']));
        $this->assertSame(1.0, $b->counterValue('hits', ['route' => '/y']));
    }

    public function testGaugeInstrumentSetsLastWriteWinsIncludingNegatives(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $depth = $r->newGauge('queue_depth', '', ['pod' => 'p1']);
        $this->assertInstanceOf(Gauge::class, $depth);

        $depth->set(10.0);
        $depth->set(-4.0); // gauges legitimately go negative — no monotonic guard
        $this->assertSame(-4.0, $b->gaugeValue('queue_depth', ['pod' => 'p1']));
        $depth->set(7.0, ['pod' => 'p2']);
        $this->assertSame(7.0, $b->gaugeValue('queue_depth', ['pod' => 'p2']));
        $this->assertSame(-4.0, $b->gaugeValue('queue_depth', ['pod' => 'p1']));
    }

    public function testHistogramInstrumentRecordsSamplesThroughTheVerb(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $latency = $r->newHistogram('latency', 'Request latency', ['route' => '/api']);
        $this->assertInstanceOf(Histogram::class, $latency);

        $latency->observe(0.05);
        $latency->observe(0.5, ['route' => '/other']);
        $this->assertSame([0.05], $b->histogramValues('latency', ['route' => '/api']));
        $this->assertSame([0.5], $b->histogramValues('latency', ['route' => '/other']));
    }

    public function testInstrumentVerbsRideTheGuardsNotAForkOfThem(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $hits = $r->newCounter('hits', '', ['route' => '/x']);

        // First record so the backend knows this name as a COUNTER —
        // a refused increment stores nothing, so the collision arm needs
        // a real emit behind it.
        $hits->inc(1.0, ['route' => '/x']);
        $this->assertSame(1.0, $b->counterValue('hits', ['route' => '/x']));

        // Monotonic guard: negative inc refuses via Registry::counter.
        $msg = null;
        try {
            $hits->inc(-1.0, ['route' => '/x']);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
        }
        $this->assertNotNull($msg, 'inc() with a negative amount must refuse');
        $this->assertStringContainsString('counter', (string) $msg);
        $this->assertSame(1.0, $b->counterValue('hits', ['route' => '/x']), 'the refused increment must not land');

        // Kind-switch guard: on the LINE backend the same storage key under
        // a second kind refuses at the call site (the guard lives in the
        // textfile backend, not InMemory — this leg must ride PFB).
        $promPath = \sys_get_temp_dir() . '/instrument-symmetry-' . \uniqid() . '.prom';
        try {
            $pr = new Registry(PrometheusFileBackend::new($promPath));
            $pr->newCounter('hits', '', ['route' => '/x'])->inc();
            $msg = null;
            try {
                $pr->newGauge('hits')->set(1.0, ['route' => '/x']);
            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();
            }
            $this->assertNotNull($msg, 'holding the counter key as a gauge must refuse at emit');
            $this->assertStringContainsString('already emitted as a counter', (string) $msg);
        } finally {
            @\unlink($promPath);
            foreach (\glob($promPath . '.*.tmp') ?: [] as $leaked) {
                @\unlink($leaked);
            }
        }

        // The instrument route stays a pure replay: no describe()/Descriptor
        // registration is implied by holding an instrument (help is metadata
        // until the registry is asked to register it — the PARAMS park owns
        // that requiredness decision).
        $hits->inc(1.0, ['route' => '/x']);
        $this->assertSame(2.0, $b->counterValue('hits', ['route' => '/x']));
    }
}
