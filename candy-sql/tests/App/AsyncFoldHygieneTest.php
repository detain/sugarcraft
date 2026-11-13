<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Msg\ExceptionMsg;
use SugarCraft\Sql\Admin\AdminQueryCache;
use SugarCraft\Sql\Admin\AsyncConnection;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Core\Msg\AdminDrainCompletedMsg;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * Pins for SQL-C12 (client-012/026/027): the never-reject law on the admin
 * drain arm, errored legs MARKED instead of cached empty, and the ExceptionMsg
 * fold that finally gives command-handler faults a visible tell.
 */
final class AsyncFoldHygieneTest extends TestCase
{
    private const PROCESSLIST = 'SHOW FULL PROCESSLIST';

    protected function setUp(): void
    {
        parent::setUp();
        AdminQueryCache::reset();
    }

    protected function tearDown(): void
    {
        AdminQueryCache::reset();
        parent::tearDown();
    }

    /** Drive the real (private) admin fetch in drain-only mode. */
    private function drain(App $a): mixed
    {
        $method = new \ReflectionMethod($a, 'createAdminFetchPromise');
        $outcome = null;
        $method->invoke($a, null, true)->then(static function (mixed $msg) use (&$outcome): void {
            $outcome = $msg;
        });
        return $outcome;
    }

    /** Register a fake async connection under the key the fetch derives for FakeDatabase. */
    private function seedConnection(AsyncConnection $connection): void
    {
        AdminQueryCache::instance()->connection('mysql||', static fn (): AsyncConnection => $connection);
    }

    public function testAnErroredDrainLegIsMarkedNotCachedEmpty(): void
    {
        // The old error arm did store($sql, []) — the page read that back as an
        // authoritative "No data" grid while the header still said Connected.
        // An errored leg now marks the failure and leaves the value slot a
        // true miss (lookup null → loading/last-known, never a fake empty).
        $this->seedConnection(new class implements AsyncConnection {
            public function query(string $sql, ?\SugarCraft\Async\CancellationToken $cancellation = null): PromiseInterface
            {
                return \React\Promise\reject(new \RuntimeException('ERROR 1054: Unknown column THREAD_ID'));
            }
        });
        AdminQueryCache::instance()->lookup(self::PROCESSLIST);

        $outcome = $this->drain(App::start(new FakeDatabase(), Flavor::MySQL));

        $this->assertInstanceOf(AdminDrainCompletedMsg::class, $outcome);
        $this->assertNull(
            AdminQueryCache::instance()->lookup(self::PROCESSLIST),
            'the errored leg must NOT be readable as a cached empty result',
        );
        $this->assertStringContainsString(
            '1054',
            AdminQueryCache::instance()->errorFor(self::PROCESSLIST) ?? '',
            'the named driver cause survives the drain',
        );
    }

    public function testASyncThrowingConnectionMarksTheLegAndStillResolves(): void
    {
        // Never-reject law, dispatch side: a dead pooled client throwing
        // synchronously from query() must mark the leg and keep the whole
        // drain resolving — not escape the promise factory into silence.
        $this->seedConnection(new class implements AsyncConnection {
            public function query(string $sql, ?\SugarCraft\Async\CancellationToken $cancellation = null): PromiseInterface
            {
                throw new \RuntimeException('write EPIPE');
            }
        });
        AdminQueryCache::instance()->lookup(self::PROCESSLIST);

        $outcome = $this->drain(App::start(new FakeDatabase(), Flavor::MySQL));

        $this->assertInstanceOf(AdminDrainCompletedMsg::class, $outcome);
        $this->assertSame('write EPIPE', AdminQueryCache::instance()->errorFor(self::PROCESSLIST));
    }

    public function testASuccessClearsTheErrorMark(): void
    {
        $this->seedConnection(new class implements AsyncConnection {
            public function query(string $sql, ?\SugarCraft\Async\CancellationToken $cancellation = null): PromiseInterface
            {
                return \React\Promise\reject(new \RuntimeException('first attempt died'));
            }
        });
        AdminQueryCache::instance()->lookup(self::PROCESSLIST);
        $this->drain(App::start(new FakeDatabase(), Flavor::MySQL));
        $this->assertNotNull(AdminQueryCache::instance()->errorFor(self::PROCESSLIST));

        // Same key factory is latched, so drive store() directly for the retry:
        AdminQueryCache::instance()->store(self::PROCESSLIST, [['Id' => '1']]);

        $this->assertNull(
            AdminQueryCache::instance()->errorFor(self::PROCESSLIST),
            'a later success must clear the stale error mark',
        );
    }

    public function testExceptionMsgFoldsToTheErrorLine(): void
    {
        // client-026: sugar-core routes every rejected command promise (and
        // factory throw) to ExceptionMsg. The arm used to be absent, so the
        // fold fell into the non-KeyMsg silence — now the cause is surfaced.
        $app = App::start(new FakeDatabase(), Flavor::MySQL);

        [$after, $cmd] = $app->update(new ExceptionMsg(new \RuntimeException('sink detached')));

        $this->assertNull($cmd);
        $this->assertSame(
            'Async command failed: sink detached',
            $after->ui->error,
            'the named cause reaches the error line instead of silence',
        );
    }
}
