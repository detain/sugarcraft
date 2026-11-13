<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\ExceptionMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Sql\Admin\AdminQueryCache;
use SugarCraft\Sql\Admin\AsyncConnection;
use SugarCraft\Sql\Admin\CacheTtl;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Core\Msg\AdminDataLoadedMsg;
use SugarCraft\Sql\Core\Msg\AdminDrainCompletedMsg;
use SugarCraft\Sql\Core\Msg\AdminFetchStartedMsg;
use SugarCraft\Sql\Core\Msg\AdminTickMsg;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * Behaviour pins for the per-fire admin throttle (Fix A).
 *
 * The old code branched inside subscriptions() and the cooldown branch called
 * the non-existent Cmd::none() (fatal every tick); worse, Program::reconcile
 * Subscriptions() diffs only by id, so a declaration-time branch could never
 * change once installed. The decision now lives in update()'s AdminTickMsg arm
 * — these tests pin all three arms plus the drain-promise shape.
 */
final class AdminTickThrottleTest extends TestCase
{
    /** @var list<string> */
    private array $seenSql = [];

    protected function setUp(): void
    {
        parent::setUp();
        AdminQueryCache::reset();
        $this->seenSql = [];
    }

    protected function tearDown(): void
    {
        AdminQueryCache::reset();
        parent::tearDown();
    }

    /**
     * App started against FakeDatabase (MySQL flavor) and navigated into the
     * Admin pane, with the Program's batch explode simulated: the entry batch's
     * first Msg (AdminFetchStartedMsg) is folded. Per SQL-C12/client-027 the
     * entry dispatch now stamps lastFetchAt, so this state is loading TRUE and
     * inside the dashboard window — the pile-on and dedupe pins age it where
     * they need a due tick.
     */
    private function adminApp(): App
    {
        $a = App::start(new FakeDatabase(), Flavor::MySQL);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, $entry] = $a->update(new KeyMsg(KeyType::Tab, ''));
        $batch = $entry();
        assert($batch instanceof BatchMsg);
        [$a, ] = $a->update(($batch->cmds[0])());
        return $a;
    }

    /**
     * Rewind the throttle stamp so the next tick is unambiguously beyond the
     * dashboard window (SQL-C12 made the entry dispatch stamp it).
     */
    private function agedApp(App $a): App
    {
        return new App(
            $a->connection,
            $a->browse,
            $a->query,
            $a->ui,
            $a->admin->withLastFetchAt(microtime(true) - CacheTtl::DASHBOARD * 2),
        );
    }

    /**
     * Beyond-TTL AND not loading — the settled "due for a full fetch" state:
     * AdminDataLoadedMsg clears the entry fetch's loading flag (it never
     * touches lastFetchAt) and the age rewind puts it past the window.
     */
    private function idleApp(): App
    {
        [$a, ] = $this->adminApp()->update(
            new AdminDataLoadedMsg(['Uptime' => '10'], ['max_connections' => '100'], microtime(true)),
        );
        return $this->agedApp($a);
    }

    /**
     * Move the throttle stamp forward: AdminFetchStartedMsg records lastFetchAt
     * unconditionally at dispatch (client-027), and AdminDataLoadedMsg clears
     * the loading flag, so the pair puts the App inside the CacheTtl::DASHBOARD
     * cooldown window.
     */
    private function cooledDownApp(App $a): App
    {
        [$a, ] = $a->update(new AdminDataLoadedMsg(['Uptime' => '10'], ['max_connections' => '100'], microtime(true)));
        [$a, ] = $a->update(new AdminFetchStartedMsg());
        return $a;
    }

    /** Register a fake async connection under the key the fetch derives for FakeDatabase. */
    private function seedFakeConnection(array $rows = [['Id' => '1']]): void
    {
        $test = $this;
        AdminQueryCache::instance()->connection('mysql||', function () use ($rows, $test): AsyncConnection {
            return new class ($rows, $test) implements AsyncConnection {
                /** @param list<array<string,mixed>> $rows */
                public function __construct(
                    private readonly array $rows,
                    private readonly AdminTickThrottleTest $test,
                ) {}

                public function query(string $sql, ?\SugarCraft\Async\CancellationToken $cancellation = null): PromiseInterface
                {
                    $this->test->recordSql($sql);
                    return \React\Promise\resolve($this->rows);
                }
            };
        });
    }

    public function recordSql(string $sql): void
    {
        $this->seenSql[] = $sql;
    }

    public function testTickBeyondTtlEmitsFullFetchBatch(): void
    {
        $a = $this->idleApp();

        [$a, $cmd] = $a->update(new AdminTickMsg());

        $this->assertNotNull($cmd, 'beyond the dashboard window, settled, the tick arms the full fetch');
        $batch = $cmd();
        $this->assertInstanceOf(BatchMsg::class, $batch);
        $this->assertCount(2, $batch->cmds, 'AdminFetchStartedMsg + promise fetch');
        $this->assertInstanceOf(AdminFetchStartedMsg::class, ($batch->cmds[0])());
    }

    public function testTickBeyondTtlWhileFetchInFlightDoesNotPileOn(): void
    {
        // C4/M2: aged adminApp() is beyond-TTL with the entry fetch still
        // loading. Without the guard every 1s tick would launch another full
        // batch onto the same connection — the r82 pile-up shape.
        // Empty queue ⇒ silence.
        $a = $this->agedApp($this->adminApp());

        [$a, $cmd] = $a->update(new AdminTickMsg());

        $this->assertNull($cmd, 'a due tick while a full fetch is in flight starts nothing');
        $this->assertSame([], $this->seenSql);
    }

    public function testInFlightDueTickDowngradesToDrainOnly(): void
    {
        // The guard demotes, it does not mute: pending page SQL still drains
        // while the full fetch runs (status/server queries stay skipped).
        $a = $this->agedApp($this->adminApp());
        AdminQueryCache::instance()->lookup('SHOW FULL PROCESSLIST');
        $this->seedFakeConnection([['Id' => '7', 'User' => 'root']]);

        [$a, $cmd] = $a->update(new AdminTickMsg());

        $this->assertNotNull($cmd, 'in-flight but pending — the tick still drains');
        $async = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $async);

        $outcome = null;
        $async->promise->then(static function (mixed $msg) use (&$outcome): void {
            $outcome = $msg;
        });

        $this->assertInstanceOf(AdminDrainCompletedMsg::class, $outcome);
        $this->assertSame(
            ['SHOW FULL PROCESSLIST'],
            $this->seenSql,
            'the downgraded tick never issues SHOW GLOBAL STATUS/VARIABLES',
        );
    }

    public function testTickWithinTtlWithoutPendingEmitsNothing(): void
    {
        $a = $this->cooledDownApp($this->adminApp());

        [$a, $cmd] = $a->update(new AdminTickMsg());

        $this->assertNull($cmd, 'inside the cooldown with an empty queue the tick is silent');
        $this->assertSame([], $this->seenSql);
    }

    public function testTickWithinTtlWithPendingDrainsPageQueries(): void
    {
        $a = $this->cooledDownApp($this->adminApp());
        // A page render registered this SQL through the read-through connection.
        AdminQueryCache::instance()->lookup('SHOW FULL PROCESSLIST');
        $this->seedFakeConnection([['Id' => '7', 'User' => 'root']]);

        [$a, $cmd] = $a->update(new AdminTickMsg());

        $this->assertNotNull($cmd, 'pending page SQL is drained even during the status cooldown');
        $async = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $async);

        $outcome = null;
        $async->promise->then(static function (mixed $msg) use (&$outcome): void {
            $outcome = $msg;
        });

        $this->assertInstanceOf(
            AdminDrainCompletedMsg::class,
            $outcome,
            'the drain resolves AdminDrainCompletedMsg — never AdminDataLoadedMsg, whose empty '
            . 'arrays would wipe the cached status/server vars',
        );
        $this->assertNotInstanceOf(AdminDataLoadedMsg::class, $outcome);
        $this->assertSame(['SHOW FULL PROCESSLIST'], $this->seenSql, 'status/server queries skipped in drain mode');
        $this->assertNotNull(
            AdminQueryCache::instance()->lookup('SHOW FULL PROCESSLIST'),
            'drained rows are stored back into the cache',
        );
    }

    public function testDrainOnlyPromiseResolvesNullWhenQueueEmptied(): void
    {
        $a = $this->cooledDownApp($this->adminApp());
        $this->seedFakeConnection();

        // Race guard: hasPending() was true when the tick decided, but the queue
        // is empty by the time the promise factory runs.
        $method = new \ReflectionMethod($a, 'createAdminFetchPromise');
        $outcome = null;
        $method->invoke($a, null, true)->then(static function (mixed $msg) use (&$outcome): void {
            $outcome = $msg;
        });

        $this->assertNull($outcome, 'empty drain resolves silently instead of emitting any Msg');
        $this->assertSame([], $this->seenSql);
    }

    public function testDrainCompletedForwardsReloadToThePage(): void
    {
        $a = $this->adminApp();
        // An unhandled admin key materialises the live page and stores it.
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'x'));

        $storedPage = $this->adminStateOf($a)->page;
        $this->assertNotNull($storedPage, 'page stored in state by key delegation');

        [$a2, $cmd2] = $a->update(new AdminDrainCompletedMsg());
        $this->assertNull($cmd2);

        $storedPage2 = $this->adminStateOf($a2)->page;
        $this->assertNotNull($storedPage2);
        $this->assertNotSame(
            $storedPage,
            $storedPage2,
            'AdminDrainCompletedMsg re-instantiates the page (ConnectionsPage::update returns '
            . 'a fresh clone on ReloadReportMsg, dropping the frozen processlist memo)',
        );
    }

    public function testAdminEntryStampsTheWindowOnce(): void
    {
        // SQL-C12/client-027 acceptance: one full pull per dashboard window.
        // The old entry arm set the loading flag but never stamped, so the
        // first due tick re-pulled STATUS/VARIABLES right after the entry
        // fetch had just paid for them.
        $a = App::start(new FakeDatabase(), Flavor::MySQL);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, $entry] = $a->update(new KeyMsg(KeyType::Tab, ''));

        $batch = $entry();
        $this->assertInstanceOf(
            BatchMsg::class,
            $batch,
            'Admin entry arms the same stamped batch the due tick uses',
        );
        [$a, ] = $a->update(($batch->cmds[0])());

        // The entry fetch lands (AdminDataLoadedMsg clears loading, never stamps).
        [$a, ] = $a->update(new AdminDataLoadedMsg(['Uptime' => '10'], ['max_connections' => '100'], microtime(true)));

        [$a, $cmd] = $a->update(new AdminTickMsg());
        $this->assertNull($cmd, 'the entry dispatch stamped the window — no duplicate full pull');
        $this->assertSame([], $this->seenSql);
    }

    public function testFetchStartedStampsWhileAFetchIsAlreadyInFlight(): void
    {
        // The old fold skipped the stamp whenever loading was already true.
        // client-027 makes it unconditional at dispatch: a start issued over a
        // loading model must still advance lastFetchAt.
        $a = App::start(new FakeDatabase(), Flavor::MySQL);
        $loading = new App(
            $a->connection,
            $a->browse,
            $a->query,
            $a->ui,
            $a->admin->withLoading(true),
        );
        $this->assertSame(0.0, $loading->admin->lastFetchAt);

        [$after, ] = $loading->update(new AdminFetchStartedMsg());

        $this->assertGreaterThan(0.0, $after->admin->lastFetchAt, 'stamp is unconditional at dispatch');
        $this->assertTrue($after->admin->loading, 'the in-flight flag survives the restamp');
    }

    private function adminStateOf(App $a): \SugarCraft\Sql\App\AdminState
    {
        $prop = (new \ReflectionObject($a))->getProperty('admin');
        $prop->setAccessible(true);
        /** @var \SugarCraft\Sql\App\AdminState $state */
        $state = $prop->getValue($a);
        return $state;
    }

    public function testFullFetchPathStillResolvesAdminDataLoadedMsg(): void
    {
        $a = $this->adminApp();
        $this->seedFakeConnection([['Variable_name' => 'Uptime', 'Value' => '42']]);

        $method = new \ReflectionMethod($a, 'createAdminFetchPromise');
        $outcome = null;
        $method->invoke($a, null)->then(static function (mixed $msg) use (&$outcome): void {
            $outcome = $msg;
        });

        $this->assertInstanceOf(AdminDataLoadedMsg::class, $outcome);
        $this->assertContains('SHOW GLOBAL STATUS', $this->seenSql);
        $this->assertContains('SHOW GLOBAL VARIABLES', $this->seenSql);
    }
}
