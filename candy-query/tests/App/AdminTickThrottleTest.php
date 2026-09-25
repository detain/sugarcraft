<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\App;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Query\Admin\AdminQueryCache;
use SugarCraft\Query\Admin\AsyncConnection;
use SugarCraft\Query\App;
use SugarCraft\Query\Core\Msg\AdminDataLoadedMsg;
use SugarCraft\Query\Core\Msg\AdminDrainCompletedMsg;
use SugarCraft\Query\Core\Msg\AdminFetchStartedMsg;
use SugarCraft\Query\Core\Msg\AdminTickMsg;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Tests\Admin\FakeDatabase;

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
     * Admin pane. lastFetchAt is still 0.0, i.e. beyond the status TTL — the
     * "due for a full fetch" state.
     */
    private function adminApp(): App
    {
        $a = App::start(new FakeDatabase(), Flavor::MySQL);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        return $a;
    }

    /**
     * Move the throttle stamp forward: AdminFetchStartedMsg records lastFetchAt
     * while not already loading, and AdminDataLoadedMsg clears the loading flag,
     * so the pair puts the App inside the CacheTtl::STATUS cooldown window.
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
        $a = $this->adminApp();

        [$a, $cmd] = $a->update(new AdminTickMsg());

        $this->assertNotNull($cmd, 'beyond the status window the tick arms the full fetch');
        $batch = $cmd();
        $this->assertInstanceOf(BatchMsg::class, $batch);
        $this->assertCount(2, $batch->cmds, 'AdminFetchStartedMsg + promise fetch');
        $this->assertInstanceOf(AdminFetchStartedMsg::class, ($batch->cmds[0])());
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

    private function adminStateOf(App $a): \SugarCraft\Query\App\AdminState
    {
        $prop = (new \ReflectionObject($a))->getProperty('admin');
        $prop->setAccessible(true);
        /** @var \SugarCraft\Query\App\AdminState $state */
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
