<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\AdminQueryCache;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Core\Msg\AdminDataLoadedMsg;
use SugarCraft\Sql\Core\Msg\QueryRowsLoadedMsg;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * SQL-A07 fold pins: the async dial doors (empty username, unrecognised
 * sslmode) throw at construction — but every App seam that dials promises
 * to ALWAYS resolve, never to leak a synchronous exception into the tick
 * or the loop. A refused dial must therefore surface as the ordinary
 * error-text fold, identical in shape to any other unreachable-server
 * failure.
 *
 * The doubled FakeDatabase carries an empty username (and empty DSN), so
 * the very first cache-miss dial walks straight into the refusal; the
 * AdminQueryCache is left cold on purpose — pre-seeding it would bypass
 * the factory and prove nothing.
 */
final class AdminDialRefusalTest extends TestCase
{
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

    private function app(): App
    {
        return App::builder()
            ->withDb(new FakeDatabase())
            ->withFlavor(Flavor::MySQL)
            ->build();
    }

    /** Invoke a private promise factory without running the loop. */
    private function call(App $app, string $method, mixed ...$args): object
    {
        $ref = new \ReflectionMethod($app, $method);
        $ref->setAccessible(true);

        /** @var \React\Promise\PromiseInterface<\SugarCraft\Core\Msg> $promise */
        $promise = $ref->invoke($app, ...$args);

        $settled = null;
        $promise->then(static function (object $msg) use (&$settled): void {
            $settled = $msg;
        });

        self::assertNotNull($settled, "{$method} must resolve synchronously on the refusal path");

        return $settled;
    }

    public function testQueryDialRefusalFoldsIntoTheErrorTextNotAThrow(): void
    {
        $msg = $this->call($this->app(), 'createQueryPromise', 'SELECT 1', 7);

        self::assertInstanceOf(QueryRowsLoadedMsg::class, $msg);
        self::assertSame([], $msg->rows);
        self::assertStringContainsString('empty username', (string) $msg->error, 'the refused identity guess must be the surfaced reason');
    }

    public function testAdminTickDialRefusalFoldsIntoEmptyDataNotAThrow(): void
    {
        $msg = $this->call($this->app(), 'createAdminFetchPromise', null, false);

        self::assertInstanceOf(AdminDataLoadedMsg::class, $msg);
        self::assertSame([], $msg->statusVars, 'a refused dial must fold as empty admin data');
    }
}
