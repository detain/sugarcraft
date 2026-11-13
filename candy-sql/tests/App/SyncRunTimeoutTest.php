<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Sql\Admin\Resilience\StatementTimeout;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\PreparedStatementInterface;

/**
 * SQL-C11: the synchronous remote legs (TLS-refused run/browse and the boot
 * table list) execute ON the UI loop. Before this row a stalled server froze
 * the whole app forever; now every leg runs inside a wall-clock budget and
 * the pane shows the named timeout instead of hanging.
 *
 * The budget is injected through the App's private guard seam (the repo's
 * established reflection-injection test pattern) so the cap fires in ~1 s
 * here while production runs the shared 30 s default.
 */
final class SyncRunTimeoutTest extends TestCase
{
    /** Database whose every query leg blocks for $stall seconds. */
    private static function stallingDb(float $stall): DatabaseInterface
    {
        return new class ($stall) implements DatabaseInterface {
            public function __construct(private readonly float $stall)
            {
            }

            public function query(string $sql): array|null
            {
                usleep((int) ($this->stall * 1_000_000));
                return [['id' => 1]];
            }

            public function tables(): array
            {
                return ['widgets'];
            }

            public function rows(string $table, int $limit = 100, int $offset = 0): array
            {
                return (array) $this->query('rows');
            }

            public function lastInsertId(): string|int
            {
                return '0';
            }

            public function quote(string $value): string
            {
                return "'" . $value . "'";
            }

            public function exec(string $sql): int
            {
                return 0;
            }

            public function close(): void
            {
            }

            public function serverVersion(): string
            {
                return 'MySQL version 8.0.33';
            }

            public function driverName(): string
            {
                return 'mysql';
            }

            public function ping(): bool
            {
                return true;
            }

            public function databases(): array
            {
                return ['test'];
            }

            public function prepare(string $sql): ?PreparedStatementInterface
            {
                return null;
            }

            public function dsn(): string
            {
                return 'mysql:host=h;port=3306;dbname=d';
            }

            public function username(): string
            {
                return 'u';
            }
        };
    }

    private function appWithStall(float $stall, ?int $budgetSeconds): App
    {
        $app = App::builder()
            ->withDb(self::stallingDb($stall))
            ->withFlavor(Flavor::MySQL)
            ->withQueryEditor(TextArea::new()->setValue('SELECT 1'))
            ->build();

        if ($budgetSeconds !== null) {
            // AdminState carries the injected cap (the app VOs are frozen and
            // clone-by-named-args, so transport policy rides as state there).
            $mutate = new \ReflectionMethod($app, 'mutate');
            $app = $mutate->invoke(
                $app,
                ['admin' => $app->admin->withSyncTimeoutGuard(new StatementTimeout(timeoutSeconds: $budgetSeconds))],
            );
        }

        return $app;
    }

    private function runQuery(App $app): App
    {
        $method = new \ReflectionMethod($app, 'runQuery');
        $method->setAccessible(true);

        /** @var App */
        return $method->invoke($app);
    }

    private function loadTable(App $app, string $name): App
    {
        $method = new \ReflectionMethod($app, 'loadTable');
        $method->setAccessible(true);

        /** @var App */
        return $method->invoke($app, $name);
    }

    public function testFastLegSettlesNormallyUnderTheWrap(): void
    {
        // 0.01 s stall against a 30 s production budget: the cap must be
        // transparent for anything healthy — no error, rows land.
        $app = $this->appWithStall(0.01, null);

        $out = $this->runQuery($app);

        self::assertNull($out->ui->error);
        self::assertSame([['id' => 1]], $out->browse->rows);
    }

    public function testStalledRunFoldsNamedTimeoutInsideBudget(): void
    {
        if (!function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl_alarm is not available (transport timeouts are the remaining bound there)');
        }

        $app = $this->appWithStall(5.0, 1);

        $start = microtime(true);
        $out = $this->runQuery($app);
        $elapsed = microtime(true) - $start;

        self::assertLessThan(3.0, $elapsed, 'the stall must be cut at the budget, never at the server\'s whim');
        self::assertNotNull($out->ui->error, 'a timed-out leg must name itself in the error line');
        self::assertStringContainsString('timed out after 1 s', (string) $out->ui->error);
    }

    public function testStalledBrowseFoldsNamedTimeoutInsideBudget(): void
    {
        if (!function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl_alarm is not available');
        }

        $app = $this->appWithStall(5.0, 1);

        $start = microtime(true);
        $out = $this->loadTable($app, 'widgets');
        $elapsed = microtime(true) - $start;

        self::assertLessThan(4.0, $elapsed, 'both browse legs ride the same budget');
        self::assertStringContainsString('timed out after 1 s', (string) $out->ui->error);
        self::assertFalse($out->browse->rowsLoading, 'a folded timeout must not leave the pane spinning');
    }
}
