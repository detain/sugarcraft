<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\ServerContext;
use SugarCraft\Query\Admin\StatusPoller;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Version;

/**
 * Tests for StatusPoller.
 */
final class StatusPollerTest extends TestCase
{
    private FakePollerContext $ctx;
    private StatusPoller $poller;

    protected function setUp(): void
    {
        $this->ctx = new FakePollerContext();
        $this->poller = new StatusPoller($this->ctx, 3.0);
    }

    public function testPollReturnsNullOnFirstCall(): void
    {
        $this->ctx->setStatusVariables(['Uptime' => '100']);
        $result = $this->poller->poll();
        $this->assertNull($result);
    }

    public function testPollReturnsDataAfterCadence(): void
    {
        $this->ctx->setStatusVariables(['Uptime' => '100']);

        $result = $this->poller->poll();
        $this->assertNull($result);

        $this->ctx->setStatusVariables(['Uptime' => '103']);
        $this->ctx->setTime(4.0);

        $result = $this->poller->poll();
        $this->assertSame(['Uptime' => '103'], $result);
    }

    public function testPollReturnsNullWhenPollInFlight(): void
    {
        $this->ctx->setStatusVariables(['Uptime' => '100']);
        $this->ctx->setPollInFlight(true);

        $result = $this->poller->poll();
        $this->assertNull($result);
    }

    public function testWasResetDelegatesToContext(): void
    {
        $this->assertFalse($this->poller->wasReset());
        $this->ctx->setWasReset(true);
        $this->assertTrue($this->poller->wasReset());
    }

    public function testCurrentSnapshotReturnsLatestData(): void
    {
        $this->ctx->setStatusVariables(['Uptime' => '100']);
        $this->poller->poll();

        $this->assertSame(['Uptime' => '100'], $this->poller->currentSnapshot());
    }

    public function testPreviousSnapshotReturnsPriorData(): void
    {
        $this->ctx->setStatusVariables(['Uptime' => '100']);
        $this->poller->poll();

        $this->ctx->setStatusVariables(['Uptime' => '103']);
        $this->ctx->setTime(4.0);
        $this->poller->poll();

        $this->assertSame(['Uptime' => '100'], $this->poller->previousSnapshot());
    }

    public function testPollGracefullyHandlesException(): void
    {
        $this->ctx->setStatusVariablesThrows();
        $result = $this->poller->poll();
        $this->assertNull($result);
        $this->assertFalse($this->ctx->isPollInFlight());
    }
}

final class FakePollerContext implements \SugarCraft\Query\Admin\ServerContextInterface
{
    /** @var array<string, string> */
    private array $statusVariables = [];
    private bool $wasReset = false;
    private bool $pollInFlight = false;
    private float $currentTime = 0.0;

    public function setStatusVariables(array $vars): void
    {
        $this->statusVariables = $vars;
    }

    public function setWasReset(bool $val): void
    {
        $this->wasReset = $val;
    }

    public function setPollInFlight(bool $val): void
    {
        $this->pollInFlight = $val;
    }

    public function setTime(float $t): void
    {
        $this->currentTime = $t;
    }

    public function setStatusVariablesThrows(): void
    {
        $this->throwOnNextStatusVariables = true;
    }

    private bool $throwOnNextStatusVariables = false;

    public function isPollInFlight(): bool
    {
        return $this->pollInFlight;
    }

    public function connection(): \SugarCraft\Query\Db\DatabaseInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function serverVariables(): array
    {
        return [];
    }

    public function statusVariables(): array
    {
        if ($this->throwOnNextStatusVariables) {
            $this->throwOnNextStatusVariables = false;
            throw new \PDOException('SHOW GLOBAL STATUS failed');
        }
        return $this->statusVariables;
    }

    public function statusVariablesTs(): float
    {
        return $this->currentTime > 0 ? $this->currentTime : microtime(true);
    }

    public function plugins(): array
    {
        return [];
    }

    public function version(): Version
    {
        return Version::parse('8.0.33');
    }

    public function flavor(): Flavor
    {
        return Flavor::MySQL;
    }

    public function versionString(): string
    {
        return 'MySQL version 8.0.33';
    }

    public function wasReset(): bool
    {
        return $this->wasReset;
    }

    public function refresh(): void
    {
    }
}