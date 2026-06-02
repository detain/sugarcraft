<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Sampler;
use SugarCraft\Query\Admin\StatusPoller;

/**
 * Tests for Sampler.
 */
final class SamplerTest extends TestCase
{
    private FakeSamplerPoller $poller;
    private Sampler $sampler;

    protected function setUp(): void
    {
        $this->poller = new FakeSamplerPoller();
        $this->sampler = new Sampler($this->poller);
    }

    public function testFirstSampleReturnsNull(): void
    {
        $this->poller->setSnapshot(['Queries' => '100']);
        $this->poller->setTs(1.0);

        $result = $this->sampler->sample();
        $this->assertNull($result);
        $this->assertFalse($this->sampler->isFirstSample());
    }

    public function testSecondSampleComputesRates(): void
    {
        $this->poller->setSnapshot(['Queries' => '100']);
        $this->poller->setTs(1.0);
        $this->sampler->sample();

        $this->poller->setSnapshot(['Queries' => '110']);
        $this->poller->setTs(2.0);

        $rates = $this->sampler->sample();
        $this->assertNotNull($rates);
        $this->assertArrayHasKey('Queries', $rates);
        $this->assertEqualsWithDelta(10.0, $rates['Queries'], 0.001);
    }

    public function testRateIsDeltaPerSecond(): void
    {
        $this->poller->setSnapshot(['Bytes_received' => '1000']);
        $this->poller->setTs(0.0);
        $this->sampler->sample();

        $this->poller->setSnapshot(['Bytes_received' => '19000']);
        $this->poller->setTs(10.0);

        $rates = $this->sampler->sample();
        $this->assertNotNull($rates);
        $this->assertEqualsWithDelta(1800.0, $rates['Bytes_received'], 0.001);
    }

    public function testMissingKeyInSecondSnapshotReturnsZeroRate(): void
    {
        $this->poller->setSnapshot(['Queries' => '100', 'Connections' => '5']);
        $this->poller->setTs(1.0);
        $this->sampler->sample();

        $this->poller->setSnapshot(['Queries' => '110']);
        $this->poller->setTs(2.0);

        $rates = $this->sampler->sample();
        $this->assertNotNull($rates);
        $this->assertArrayNotHasKey('Connections', $rates);
    }

    public function testNegativeDeltaReturnsZeroRate(): void
    {
        $this->poller->setSnapshot(['Uptime' => '100']);
        $this->poller->setTs(1.0);
        $this->sampler->sample();

        $this->poller->setSnapshot(['Uptime' => '50']);
        $this->poller->setTs(2.0);

        $rates = $this->sampler->sample();
        $this->assertNotNull($rates);
        $this->assertEqualsWithDelta(0.0, $rates['Uptime'], 0.001);
    }

    public function testResetOnRestartDetected(): void
    {
        $this->poller->setSnapshot(['Uptime' => '100']);
        $this->poller->setTs(1.0);
        $this->sampler->sample();

        $this->poller->setWasReset(true);
        $this->poller->setSnapshot(['Uptime' => '50']);
        $this->poller->setTs(2.0);

        $result = $this->sampler->sample();
        $this->assertNull($result);
        $this->assertTrue($this->sampler->isFirstSample());
    }

    public function testResetAllClearsState(): void
    {
        $this->poller->setSnapshot(['Uptime' => '100']);
        $this->poller->setTs(1.0);
        $this->sampler->sample();

        $this->sampler->resetAll();

        $this->assertTrue($this->sampler->isFirstSample());
    }

    public function testNonNumericValueSkipped(): void
    {
        $this->poller->setSnapshot(['Version' => '8.0.33']);
        $this->poller->setTs(1.0);
        $this->sampler->sample();

        $this->poller->setSnapshot(['Version' => '8.0.36']);
        $this->poller->setTs(2.0);

        $rates = $this->sampler->sample();
        $this->assertNotNull($rates);
        $this->assertArrayNotHasKey('Version', $rates);
    }

    public function testSampleWithNullSnapshotReturnsNull(): void
    {
        $this->poller->setSnapshot(null);
        $result = $this->sampler->sample();
        $this->assertNull($result);
    }

    public function testSampleWithZeroElapsedReturnsNull(): void
    {
        $this->poller->setSnapshot(['Queries' => '100']);
        $this->poller->setTs(1.0);
        $this->sampler->sample();

        $this->poller->setSnapshot(['Queries' => '110']);
        $this->poller->setTs(1.0);

        $rates = $this->sampler->sample();
        $this->assertNull($rates);
    }
}

final class FakeSamplerPoller implements \SugarCraft\Query\Admin\StatusSnapshotProviderInterface
{
    /** @var array<string, string>|null */
    private ?array $snapshot = null;
    private float $ts = 0.0;
    private bool $wasReset = false;

    public function setSnapshot(?array $snap): void
    {
        $this->snapshot = $snap;
    }

    public function setTs(float $t): void
    {
        $this->ts = $t;
    }

    public function setWasReset(bool $val): void
    {
        $this->wasReset = $val;
    }

    public function currentSnapshot(): ?array
    {
        return $this->snapshot;
    }

    public function statusVariablesTs(): float
    {
        return $this->ts;
    }

    public function wasReset(): bool
    {
        return $this->wasReset;
    }
}