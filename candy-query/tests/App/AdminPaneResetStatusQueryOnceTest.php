<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Query\Admin\AdminQueryCache;
use SugarCraft\Query\Admin\CacheTtl;
use SugarCraft\Query\Admin\ServerContext;
use SugarCraft\Query\App;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Pane;
use SugarCraft\Query\Tests\Admin\FakeDatabase;

/**
 * E718 behaviour pin: two admin-pane renders inside ONE TTL window execute
 * the synchronous SHOW GLOBAL STATUS exactly once.
 *
 * The regression this guards: App is immutable, ConnectionState carries no
 * server context, and a pane change nulls the lazily-built admin page
 * (AdminState::withPane()). The next render therefore re-runs
 * App::createContext(); when that minted a FRESH ServerContext every time,
 * the fresh instance's cold TTL cache turned every navigation back into a
 * synchronous SHOW GLOBAL STATUS on the render path — the freeze the
 * subscriptions/TTL work was supposed to end. The context now rides the
 * process-global AdminQueryCache::serverContext() memo, so the warm cache —
 * and with it the "once per window" guarantee — survives the reset.
 *
 * Drives the real update() → adminPage() → view() sequence with Down (switch
 * pane away) and '1' (switch back): both rebuild the page, which routes
 * through the inner context's statusVariablesTs() sync delegation.
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

    public function testTwoAdminRendersInsideOneTtlWindowExecuteStatusQueryOnce(): void
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
        $this->assertSame(1, $db->queryCount('SHOW GLOBAL STATUS'), 'first render primes the window');

        // Pane switch away, then back — each transition resets the page.
        [$app, ] = $app->update(new KeyMsg(KeyType::Down, ''));
        [$app, ] = $app->update(new KeyMsg(KeyType::Char, '1'));

        $pageTwo = $app->adminPage();
        $pageTwo->view();

        $this->assertNotSame($pageOne, $pageTwo, 'the pane reset must genuinely have rebuilt the page');
        $this->assertSame(
            1,
            $db->queryCount('SHOW GLOBAL STATUS'),
            'a second render inside the same TTL window must not re-issue the blocking query',
        );
    }

    public function testAfterTheWindowExpiresTheSharedContextRefreshesOnce(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '100'],
        ]);

        $app = App::start($db, Flavor::MySQL);
        [$app, ] = $app->update(new KeyMsg(KeyType::Tab, ''));
        [$app, ] = $app->update(new KeyMsg(KeyType::Tab, ''));
        [$app, ] = $app->update(new KeyMsg(KeyType::Tab, ''));

        $app->adminPage()->view();

        [$app, ] = $app->update(new KeyMsg(KeyType::Down, ''));
        [$app, ] = $app->update(new KeyMsg(KeyType::Char, '1'));
        $app->adminPage()->view();

        $this->assertSame(1, $db->queryCount('SHOW GLOBAL STATUS'));

        // Expire the memoized context's window the same way ServerContextTest
        // does — reflect back the attempt stamp instead of sleeping 3s. The
        // stamp read on the render path (statusVariablesTs) answers without
        // refetching; the refresh contract lives in statusVariables(), which
        // wasReset() and the sync-side providers drive on the Status pane.
        $context = AdminQueryCache::instance()->serverContext($db, static fn(): ServerContext => new ServerContext($db));
        (new \ReflectionProperty(ServerContext::class, 'statusVariablesTsCache'))
            ->setValue($context, microtime(true) - CacheTtl::STATUS - 0.1);

        $context->statusVariables();

        $this->assertSame(2, $db->queryCount('SHOW GLOBAL STATUS'), 'exactly one refresh in the new window');

        // And the shared instance keeps serving the warm cache: rebuilds on
        // the render path after that refresh still execute nothing new.
        [$app, ] = $app->update(new KeyMsg(KeyType::Down, ''));
        [$app, ] = $app->update(new KeyMsg(KeyType::Char, '1'));
        $app->adminPage()->view();

        $this->assertSame(2, $db->queryCount('SHOW GLOBAL STATUS'), 'post-refresh renders ride the same window');
    }
}
