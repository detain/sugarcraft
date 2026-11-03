<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use PDO;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\SqliteDatabase;

/**
 * D-M3: every failure/stand-down arm of the admin fetch resolves
 * AdminDataLoadedMsg with empty arrays. SHOW GLOBAL STATUS/VARIABLES never
 * come back legitimately empty from a live server, so the fold now keeps the
 * previous leg instead of wiping a healthy dashboard on one transient
 * hiccup (stale-serving, the candy-query AsyncCachingServerContext lesson).
 */
final class AdminStaleServingTest extends TestCase
{
    /**
     * @param array<string,string> $status
     * @param array<string,string> $server
     */
    private function freshApp(): App
    {
        return App::builder()
            ->withDb(new SqliteDatabase(new PDO('sqlite::memory:')))
            ->withFlavor(Flavor::Sqlite)
            ->build();
    }

    private function fold(App $app, array $status, array $server): App
    {
        $method = new \ReflectionMethod(App::class, 'withAdminCachedData');
        $result = $method->invoke($app, $status, $server, 1_700_000_000.0);
        \assert($result instanceof App);

        return $result;
    }

    public function testAFailedFetchKeepsTheLastKnownGoodVariables(): void
    {
        $app = $this->freshApp();
        $seeded = $this->fold($app, ['Uptime' => '42'], ['version' => '8.4.0']);
        $this->assertSame(['Uptime' => '42'], $seeded->admin->cachedStatusVars);

        // A transient failure arrives as empty arrays with a fresh timestamp.
        $after = $this->fold($seeded, [], []);
        $this->assertSame(['Uptime' => '42'], $after->admin->cachedStatusVars, 'status leg must survive');
        $this->assertSame(['version' => '8.4.0'], $after->admin->cachedServerVars, 'server leg must survive');
        $this->assertFalse($after->admin->loading, 'the failed fetch still settles the spinner');
        $this->assertSame(1_700_000_000.0, $after->admin->cacheTs, 'retry cadence advances on the attempt');
    }

    public function testAPartialFailureKeepsOnlyTheFailedLegsPreviousValue(): void
    {
        $app = $this->fold($this->freshApp(), ['Uptime' => '42'], ['version' => '8.4.0']);

        // Status succeeded with new data; the server-vars leg failed empty.
        $after = $this->fold($app, ['Uptime' => '99'], []);
        $this->assertSame(['Uptime' => '99'], $after->admin->cachedStatusVars, 'fresh data wins');
        $this->assertSame(['version' => '8.4.0'], $after->admin->cachedServerVars, 'failed leg stale-serves');
    }

    public function testFirstFetchEmptyStaysEmpty(): void
    {
        $after = $this->fold($this->freshApp(), [], []);
        $this->assertSame([], $after->admin->cachedStatusVars);
        $this->assertSame([], $after->admin->cachedServerVars);
    }
}
