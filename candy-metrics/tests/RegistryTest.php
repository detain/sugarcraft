<?php

declare(strict_types=1);

namespace CandyCore\Metrics\Tests;

use CandyCore\Metrics\Backend\InMemoryBackend;
use CandyCore\Metrics\Registry;
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
}
