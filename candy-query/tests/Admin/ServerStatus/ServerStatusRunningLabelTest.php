<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\ServerStatus;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\AsyncCachingServerContext;
use SugarCraft\Query\Admin\CacheTtl;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Admin\ServerStatus\ServerStatusPage;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Version;
use SugarCraft\Query\Tests\Admin\FakeDatabase;

/**
 * Running/Stopped/Unreachable liveness label pins (Phase 2b C1).
 *
 * Mirrors MySQL Workbench's play-arrow header on Management :: Server
 * Status (query_dashboard.md line 45). State is derived from the cached
 * status-variables timestamp, so these pins drive it through a fake
 * context clock instead of message plumbing.
 */
final class ServerStatusRunningLabelTest extends TestCase
{
    private LivenessFakeContext $context;

    protected function setUp(): void
    {
        $this->context = new LivenessFakeContext(new FakeDatabase());
    }

    public function testFreshSampleShowsRunning(): void
    {
        $this->context->setStatusVariable('Uptime', '100');
        $this->context->setTs(microtime(true));

        $rendered = ServerStatusPage::new($this->context)->view();

        $this->assertStringContainsString('Running', $rendered);
        $this->assertStringContainsString('▶', $rendered);
        $this->assertStringNotContainsStringNormalized('Stopped', $rendered);
    }

    public function testStaleSampleShowsStopped(): void
    {
        $this->context->setStatusVariable('Uptime', '100');
        // One second past the 3 × 1 s freshness window: the fetch loop has
        // skipped its deadline, the feed is stalled.
        $this->context->setTs(microtime(true) - 3 * CacheTtl::DASHBOARD - 1.0);

        $rendered = ServerStatusPage::new($this->context)->view();

        $this->assertStringContainsString('Stopped', $rendered);
        $this->assertStringContainsString('■', $rendered);
        // Panels still render from the last good sample.
        $this->assertStringContainsString('Server Status', $rendered);
    }

    public function testSampleExactlyAtWindowBoundaryStillRuns(): void
    {
        $this->context->setStatusVariable('Uptime', '100');
        $this->context->setTs(microtime(true) - 3 * CacheTtl::DASHBOARD + 0.5);

        $rendered = ServerStatusPage::new($this->context)->view();

        $this->assertStringContainsString('Running', $rendered);
    }

    public function testNoDataAtAllShowsUnreachable(): void
    {
        $this->context->setTs(0.0);

        $rendered = ServerStatusPage::new($this->context)->view();

        $this->assertStringContainsString('Unreachable', $rendered);
        $this->assertStringContainsString('✖', $rendered);
        // The no-data body still explains itself.
        $this->assertStringContainsString('No data available yet.', $rendered);
    }

    public function testLoadingContextShowsLoadingNotUnreachable(): void
    {
        // First fetch in flight, nothing cached yet: a loading pane must not
        // read as a dead server.
        $async = new AsyncCachingServerContext($this->context, [], [], true);

        $rendered = ServerStatusPage::new($async)->view();

        $this->assertStringContainsString('Loading', $rendered);
        $this->assertStringNotContainsStringNormalized('Unreachable', $rendered);
    }

    public function testHealthyToStaleToRecoveredTransitions(): void
    {
        $this->context->setStatusVariable('Uptime', '100');
        $this->context->setTs(microtime(true));
        $page = ServerStatusPage::new($this->context);

        $this->assertStringContainsString('Running', $page->view());

        $this->context->setTs(microtime(true) - 10.0);
        $this->assertStringContainsString('Stopped', $page->view());

        // A later fetch landing fresh data heals the label without any new
        // message type — state is re-derived per render.
        $this->context->setTs(microtime(true));
        $this->assertStringContainsString('Running', $page->view());
    }

    public function testThrowingContextCountsAsUnreachable(): void
    {
        // Built healthy first, then the feed starts throwing: a sync context
        // can begin to fail mid-session when the connection dies, and the
        // render must degrade to the red label instead of crashing the pane.
        $this->context->setStatusVariable('Uptime', '100');
        $this->context->setTs(microtime(true));
        $page = ServerStatusPage::new($this->context);

        $this->context->failStatusVariables();

        $this->assertStringContainsString('Unreachable', $page->view());
    }

    /**
     * ANSI-normalized "does not contain": strips SGR sequences before the
     * needle scan, because styled words are split by color escapes.
     */
    private function assertStringNotContainsStringNormalized(string $needle, string $haystack): void
    {
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $haystack) ?? $haystack;
        $this->assertStringNotContainsString($needle, $stripped);
    }
}

/**
 * Minimal fake context with a settable status timestamp for liveness pins.
 *
 * Named distinctly from SidebarGaugeSetTest's FakeServerContext (which
 * throws from connection()) so both fakes can coexist in one run.
 */
final class LivenessFakeContext implements ServerContextInterface
{
    /** @var array<string, string> */
    private array $statusVariables = [];

    /** @var array<string, string> */
    private array $serverVariables = [];

    private float $ts = 0.0;

    private bool $failStatus = false;

    public function __construct(
        private readonly \SugarCraft\Query\Db\DatabaseInterface $connection,
    ) {}

    public function setStatusVariable(string $name, string $value): void
    {
        $this->statusVariables[$name] = $value;
    }

    /**
     * Simulate a connection that dies after the page was constructed.
     */
    public function failStatusVariables(): void
    {
        $this->failStatus = true;
    }

    public function setServerVariable(string $name, string $value): void
    {
        $this->serverVariables[$name] = $value;
    }

    public function setTs(float $ts): void
    {
        $this->ts = $ts;
    }

    public function connection(): \SugarCraft\Query\Db\DatabaseInterface
    {
        return $this->connection;
    }

    public function serverVariables(): array
    {
        return $this->serverVariables;
    }

    public function statusVariables(): array
    {
        if ($this->failStatus) {
            throw new \RuntimeException('connection lost');
        }
        return $this->statusVariables;
    }

    public function statusVariablesTs(): float
    {
        return $this->ts;
    }

    /** @return list<array<string, mixed>> */
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
        return '8.0.33';
    }

    public function password(): string
    {
        return '';
    }

    public function wasReset(): bool
    {
        return false;
    }

    public function refresh(): void
    {
    }
}
