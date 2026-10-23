<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests;

use SugarCraft\Metrics\Backend\InMemoryBackend;
use SugarCraft\Metrics\Instrument\UpDownCounter;
use SugarCraft\Metrics\Registry;
use PHPUnit\Framework\TestCase;

final class UpDownCounterTest extends TestCase
{
    public function testIncrementIncreasesValue(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $counter = new UpDownCounter($r, 'queue.items', 'Number of items in queue');
        $counter->add(5.0);
        $counter->add(3.0);
        $this->assertSame(8.0, $b->upDownCounterValue('queue.items'));
    }

    public function testDecrementDecreasesValue(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $counter = new UpDownCounter($r, 'connections');
        $counter->add(10.0);
        $counter->add(-4.0);
        $this->assertSame(6.0, $b->upDownCounterValue('connections'));
    }

    public function testNegativeIncrementIsSameAsDecrement(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $counter = new UpDownCounter($r, 'items');
        $counter->add(-7.0);
        $this->assertSame(-7.0, $b->upDownCounterValue('items'));
    }

    public function testTagsArePassedThrough(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $counter = new UpDownCounter($r, 'active_users');
        $counter->add(1.0, ['region' => 'us-east']);
        $counter->add(1.0, ['region' => 'us-east']);
        $counter->add(1.0, ['region' => 'eu-west']);
        $this->assertSame(2.0, $b->upDownCounterValue('active_users', ['region' => 'us-east']));
        $this->assertSame(1.0, $b->upDownCounterValue('active_users', ['region' => 'eu-west']));
    }

    public function testNameAndHelpAreAccessible(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $counter = new UpDownCounter($r, 'http_requests', 'Incoming HTTP requests');
        $this->assertSame('http_requests', $counter->name());
        $this->assertSame('Incoming HTTP requests', $counter->help());
    }

    public function testFactoryStaticTagsLandOnEveryAdd(): void
    {
        // The defect this pin cures: Registry::newUpDownCounter() accepted
        // $tags but dropped them, so the documented README form
        // (newUpDownCounter('conns', help, ['host' => $host])) silently
        // aggregated every host into one series.
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $counter = $r->newUpDownCounter('conns', 'Active connections', ['host' => 'h1']);
        $counter->add(1.0);
        $counter->add(1.0);
        $this->assertSame(2.0, $b->upDownCounterValue('conns', ['host' => 'h1']));
    }

    public function testStaticTagsMergeWithPerCallTagsAndPerCallWins(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $counter = $r->newUpDownCounter('conns', 'Active connections', ['host' => 'h1', 'zone' => 'a']);
        $counter->add(1.0, ['zone' => 'b']);
        $this->assertSame(1.0, $b->upDownCounterValue('conns', ['host' => 'h1', 'zone' => 'b']));
    }

    public function testRegistryDefaultTagsApplyToInstrumentAdds(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b, ['env' => 'prod']);
        $counter = $r->newUpDownCounter('conns', 'Active connections', ['host' => 'h1']);
        $counter->add(2.0);
        $this->assertSame(2.0, $b->upDownCounterValue('conns', ['env' => 'prod', 'host' => 'h1']));
    }
}
