<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Sql\Admin\AdminQueryCache;
use SugarCraft\Sql\Admin\AsyncCachingServerContext;
use SugarCraft\Sql\Admin\CacheTtl;
use SugarCraft\Sql\Admin\ServerContext;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Pane;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * SQL-A03 behaviour pin: the admin render path executes ZERO synchronous
 * SHOW GLOBAL STATUS — ever.
 *
 * The lineage: E718 stopped the per-navigation re-fetch by sharing one
 * ServerContext per database handle (the AdminQueryCache memo), but it still
 * allowed exactly ONE blocking remote scan per TTL window, because the inner
 * stamp getters (statusVariablesTs()/wasReset()) fetched on a cold cache and
 * the async wrapper delegated into them. On a remote server that is a
 * 200 ms+ UI freeze every few seconds. A03 makes the stamp getters serve
 * stored state (0.0 when nothing has arrived) and seeds that state from the
 * async pipeline's real payload arrivals instead — so the render path never
 * issues the query at all, and the memo's job is now to be the stable seed
 * target for the restart latch and Uptime baseline.
 *
 * Drives the real update() → adminPage() → view() sequence with Down (switch
 * pane away) and '1' (switch back) plus a simulated TTL-window rollover: all
 * of it must leave the FakeDatabase's status-query count at zero.
 */
final class AdminPaneResetStatusQueryOnceTest extends TestCase
{
    protected function setUp(): void
    {
        AdminQueryCache::reset();
    }

    protected function tearDown(): void
    {
        AdminQueryCache::reset();
    }

    public function testAdminRendersNeverIssueTheSyncStatusQuery(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '100'],
            ['Variable_name' => 'Threads_connected', 'Value' => '1'],
        ]);

        $app = App::start($db, Flavor::MySQL);
        [$app, ] = $app->update(new KeyMsg(KeyType::Tab, ''));
        [$app, ] = $app->update(new KeyMsg(KeyType::Tab, ''));
        [$app, ] = $app->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(Pane::Admin, $app->ui->pane);

        $pageOne = $app->adminPage();
        $pageOne->view();
        $this->assertSame(0, $db->queryCount('SHOW GLOBAL STATUS'), 'render must not prime a sync fetch');

        // Pane switch away, then back — each transition resets the page and
        // rebuilds the wrapper around the memoized inner context.
        [$app, ] = $app->update(new KeyMsg(KeyType::Down, ''));
        [$app, ] = $app->update(new KeyMsg(KeyType::Char, '1'));

        $pageTwo = $app->adminPage();
        $pageTwo->view();

        $this->assertNotSame($pageOne, $pageTwo, 'the pane reset must genuinely have rebuilt the page');

        // Expire the memoized context's window the way ServerContextTest does
        // and render again: even across window rollovers the render path stays
        // silent (this used to be one blocking query per window).
        $context = AdminQueryCache::instance()->serverContext($db, static fn(): ServerContext => new ServerContext($db));
        (new \ReflectionProperty(ServerContext::class, 'statusVariablesTsCache'))
            ->setValue($context, microtime(true) - CacheTtl::STATUS - 0.1);

        [$app, ] = $app->update(new KeyMsg(KeyType::Down, ''));
        [$app, ] = $app->update(new KeyMsg(KeyType::Char, '1'));
        $app->adminPage()->view();

        $this->assertSame(0, $db->queryCount('SHOW GLOBAL STATUS'), 'zero sync status queries across 3 render windows');
    }

    public function testStampGettersServeStoredStateWithoutFetching(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult([['Variable_name' => 'Uptime', 'Value' => '100']]);
        $context = new ServerContext($db);

        $this->assertSame(0.0, $context->statusVariablesTs(), 'cold stamp is 0.0, not a fetch');
        $this->assertFalse($context->wasReset(), 'cold latch is false, not a fetch');
        $this->assertNull($context->lastUptime());
        $this->assertSame(0, $db->queryCount('SHOW GLOBAL STATUS'));
    }

    public function testAdoptionSeedsLatchAndStampWithoutAnySyncFetch(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult([['Variable_name' => 'Uptime', 'Value' => '999']]);
        $inner = new ServerContext($db);
        $cache = new AdminQueryCache();

        $ctx = new AsyncCachingServerContext($inner, cache: $cache);

        // First arrival through the async pipeline: seed must reach the inner.
        $cache->store('status', ['Uptime' => '100']);
        $ctx->refreshFromLiveCache();
        $this->assertSame(100, $inner->lastUptime(), 'adoption seeds the Uptime baseline');

        // A restart arrives: Uptime falls. wasReset() delegates into the
        // (non-fetching, seeded) inner — true, and zero queries.
        $cache->store('status', ['Uptime' => '40']);
        $ctx->refreshFromLiveCache();
        $this->assertTrue($inner->wasReset(), 'adopted Uptime decrease flips the latch');

        // The next healthy arrival clears it (edge-triggered, A02 rule).
        $cache->store('status', ['Uptime' => '45']);
        $ctx->refreshFromLiveCache();
        $this->assertFalse($inner->wasReset());

        // The stamp tracks the payload's TRUE arrival, never a laundered now.
        $fetched = $cache->storedAt('status');
        $this->assertIsFloat($fetched);
        $this->assertSame($fetched, $ctx->statusVariablesTs());
        $this->assertSame($fetched, $inner->statusVariablesTs());

        $this->assertSame(0, $db->queryCount('SHOW GLOBAL STATUS'), 'the whole adoption chain issued no sync query');
    }

    public function testAdoptedSnapshotStaysInsideTheInnerTtlWindow(): void
    {
        // A freshly seeded inner answers statusVariables() from the adopted
        // rows — so any legitimate sync consumer rides the same window
        // instead of re-issuing SHOW GLOBAL STATUS.
        $db = new FakeDatabase();
        $db->setQueryResult([['Variable_name' => 'Uptime', 'Value' => '777']]);
        $inner = new ServerContext($db);
        $inner->seedStatusSnapshot(['Uptime' => '100', 'Threads_connected' => '3'], microtime(true));

        $this->assertSame(['Uptime' => '100', 'Threads_connected' => '3'], $inner->statusVariables());
        $this->assertSame(0, $db->queryCount('SHOW GLOBAL STATUS'));
    }
}
