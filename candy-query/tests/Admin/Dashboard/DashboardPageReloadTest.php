<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Dashboard;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Db\Version;
use SugarCraft\Query\Admin\AdminQueryCache;
use SugarCraft\Query\Admin\AsyncCachingServerContext;
use SugarCraft\Query\Admin\Dashboard\DashboardPage;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\Flavor;

/**
 * Fix B pins: DashboardPage used to drop ReloadReportMsg entirely, so it kept
 * its construction-time AsyncCachingServerContext snapshot forever. With a
 * frozen vars array, pollAndUpdateCells computed current == previous on every
 * poll, every rate read 0, and TimeSeriesCell dropped all <=0 samples — flat,
 * empty graphs on a live server.
 */
final class DashboardPageReloadTest extends TestCase
{
    /**
     * @param array<string, string> $status
     * @param array<string, string> $server
     */
    private function context(array $status, array $server): AsyncCachingServerContext
    {
        $inner = $this->createMock(ServerContextInterface::class);
        $inner->method('flavor')->willReturn(Flavor::MySQL);
        $inner->method('statusVariablesTs')->willReturn(microtime(true));

        return new AsyncCachingServerContext($inner, $status, $server, false);
    }

    protected function setUp(): void
    {
        AdminQueryCache::reset();
    }

    protected function tearDown(): void
    {
        AdminQueryCache::reset();
    }

    public function testReloadReplacesFrozenVarsAndProducesNonZeroRateSamples(): void
    {
        $page = new DashboardPage($this->context(
            ['Bytes_received' => '1000', 'Bytes_sent' => '500', 'Threads_connected' => '10', 'Com_select' => '100', 'Uptime' => '111'],
            ['max_connections' => '100'],
        ));

        // First render establishes the baseline snapshot.
        $page->view();
        $this->assertSame('111', $page->context->statusVariables()['Uptime'] ?? null);
        $baseline = $this->sampleTotal($page);

        $cache = AdminQueryCache::instance();
        $cache->store('status', ['Bytes_received' => '5000', 'Bytes_sent' => '2500', 'Threads_connected' => '12', 'Com_select' => '400', 'Uptime' => '999']);
        $cache->store('server', ['max_connections' => '100']);

        [$reloaded, $cmd] = $page->update(new \SugarCraft\Query\Core\Msg\ReloadReportMsg());
        $this->assertNull($cmd);
        $this->assertInstanceOf(DashboardPage::class, $reloaded);
        $this->assertSame('999', $reloaded->context->statusVariables()['Uptime'] ?? null, 'ReloadReportMsg must adopt the live cache over the frozen snapshot');

        // Second render polls immediately (lastPollAt reset): 1000 → 5000 is a
        // positive delta, so the series must GROW. Under the old frozen-snapshot
        // bug current == previous every poll, rates read 0, and the total stuck
        // at the baseline count forever.
        $reloaded->view();
        $samples = $this->sampleTotal($reloaded);
        $this->assertGreaterThan($baseline, $samples, 'changed counters across a reload must produce rate samples');

        // Third leg: another reload with further-changed counters keeps the
        // series alive (rates stay non-zero across successive arrivals).
        $cache->store('status', ['Bytes_received' => '9000', 'Bytes_sent' => '4500', 'Threads_connected' => '14', 'Com_select' => '700', 'Uptime' => '1234']);
        [$reloaded2,] = $reloaded->update(new \SugarCraft\Query\Core\Msg\ReloadReportMsg());
        $this->assertInstanceOf(DashboardPage::class, $reloaded2);
        $reloaded2->view();
        $samples2 = $this->sampleTotal($reloaded2);
        $this->assertGreaterThan($samples, $samples2, 'each reload with changed counters must keep appending samples');
        $this->assertSame('1234', $reloaded2->context->statusVariables()['Uptime'] ?? null);
    }

    public function testReloadWithoutAsyncContextIsHarmless(): void
    {
        $inner = $this->createMock(ServerContextInterface::class);
        $inner->method('flavor')->willReturn(Flavor::MySQL);
        $inner->method('statusVariables')->willReturn(['Uptime' => '7']);
        $inner->method('serverVariables')->willReturn(['max_connections' => '100']);
        $inner->method('statusVariablesTs')->willReturn(microtime(true));
        $inner->method('version')->willReturn(Version::parse('8.0.36'));

        $page = new DashboardPage($inner);
        [$same, $cmd] = $page->update(new \SugarCraft\Query\Core\Msg\ReloadReportMsg());

        $this->assertSame($page, $same, 'plain contexts pass the reload through untouched');
        $this->assertNull($cmd);
    }

    /**
     * Total samples currently held across every timeline cell — the number the
     * frozen-snapshot bug pinned at its baseline value forever.
     */
    private function sampleTotal(DashboardPage $page): int
    {
        $property = (new \ReflectionClass($page))->getProperty('timelineCells');
        $property->setAccessible(true);

        $total = 0;
        /** @var object $cell */
        foreach ((array) $property->getValue($page) as $cell) {
            $total += $cell->count();
        }

        return $total;
    }
}
