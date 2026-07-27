<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\App\AppBuilder;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Pane;
use SugarCraft\Query\ResultTable;

/**
 * Tests for AppBuilder fluent builder.
 */
final class AppBuilderTest extends TestCase
{
    private function createFakeDatabase(): \SugarCraft\Query\Db\DatabaseInterface
    {
        return new class implements \SugarCraft\Query\Db\DatabaseInterface {
            public function tables(): array { return []; }
            public function rows(string $table, int $limit = 100, int $offset = 0): array { return []; }
            public function query(string $sql): ?array { return null; }
            public function lastInsertId(): string|int { return 0; }
            public function quote(string $value): string { return "'" . $value . "'"; }
            public function exec(string $sql): int { return 0; }
            public function close(): void {}
            public function serverVersion(): string { return 'SQLite version 3.41.0'; }
            public function driverName(): string { return 'sqlite'; }
            public function ping(): bool { return true; }
            public function databases(): array { return []; }
            public function prepare(string $sql): ?\SugarCraft\Query\Db\PreparedStatementInterface { return null; }
            public function dsn(): string { return ''; }
            public function username(): string { return ''; }
        };
    }

    public function testDefaultValues(): void
    {
        $builder = new AppBuilder();

        $this->assertInstanceOf(AppBuilder::class, $builder);
    }

    public function testWithDbSetsDatabase(): void
    {
        $builder = new AppBuilder();
        $db = $this->createFakeDatabase();

        $result = $builder->withDb($db);

        $this->assertNotSame($builder, $result);
    }

    public function testWithFlavorSetsFlavor(): void
    {
        $builder = new AppBuilder();
        $db = $this->createFakeDatabase();

        $result = $builder->withFlavor(Flavor::MySQL);

        $this->assertNotSame($builder, $result);
    }

    public function testWithTablesSetsTables(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withTables(['users', 'posts']);

        $this->assertNotSame($builder, $result);
    }

    public function testWithTableCursorSetsCursor(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withTableCursor(5);

        $this->assertNotSame($builder, $result);
    }

    public function testWithSelectedTableSetsSelectedTable(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withSelectedTable('users');

        $this->assertNotSame($builder, $result);
    }

    public function testWithSelectedTableCanBeNull(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withSelectedTable(null);

        $this->assertNotSame($builder, $result);
    }

    public function testWithRowsSetsRows(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withRows([['id' => 1]]);

        $this->assertNotSame($builder, $result);
    }

    public function testWithRowCursorSetsRowCursor(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withRowCursor(10);

        $this->assertNotSame($builder, $result);
    }

    public function testWithResultTableSetsResultTable(): void
    {
        $builder = new AppBuilder();
        $table = new ResultTable([]);

        $result = $builder->withResultTable($table);

        $this->assertNotSame($builder, $result);
    }

    public function testWithPaneSetsPane(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withPane(Pane::Query);

        $this->assertNotSame($builder, $result);
    }

    public function testWithErrorSetsError(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withError('some error');

        $this->assertNotSame($builder, $result);
    }

    public function testWithErrorCanBeNull(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withError(null);

        $this->assertNotSame($builder, $result);
    }

    public function testWithStatusSetsStatus(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withStatus('Ready');

        $this->assertNotSame($builder, $result);
    }

    public function testWithStatusCanBeNull(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withStatus(null);

        $this->assertNotSame($builder, $result);
    }

    public function testWithQueryHistorySetsHistory(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withQueryHistory(['SELECT 1', 'SELECT 2']);

        $this->assertNotSame($builder, $result);
    }

    public function testWithQueryFavoritesSetsFavorites(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withQueryFavorites(['SELECT * FROM users']);

        $this->assertNotSame($builder, $result);
    }

    public function testWithHistoryDbPathSetsPath(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withHistoryDbPath('/tmp/history.db');

        $this->assertNotSame($builder, $result);
    }

    public function testWithHistoryDbPathCanBeNull(): void
    {
        $builder = new AppBuilder();

        $result = $builder->withHistoryDbPath(null);

        $this->assertNotSame($builder, $result);
    }

    public function testBuildThrowsWhenDbNotSet(): void
    {
        $builder = new AppBuilder();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('db is required');

        $builder->build();
    }

    public function testBuildReturnsAppWhenDbProvided(): void
    {
        $builder = new AppBuilder();
        $db = $this->createFakeDatabase();

        $app = $builder->withDb($db)->build();

        $this->assertInstanceOf(\SugarCraft\Query\App::class, $app);
    }

    public function testBuildWithAllOptions(): void
    {
        $builder = new AppBuilder();
        $db = $this->createFakeDatabase();
        $table = new ResultTable([]);

        $app = $builder
            ->withDb($db)
            ->withFlavor(Flavor::MySQL)
            ->withTables(['users', 'posts'])
            ->withTableCursor(1)
            ->withSelectedTable('posts')
            ->withRows([['id' => 1, 'name' => 'John']])
            ->withRowCursor(0)
            ->withResultTable($table)
            ->withPane(Pane::Query)
            ->withError(null)
            ->withStatus('Ready')
            ->withQueryHistory([])
            ->withQueryFavorites([])
            ->build();

        $this->assertInstanceOf(\SugarCraft\Query\App::class, $app);
    }

    public function testBuildWithHistoryDbPath(): void
    {
        $builder = new AppBuilder();
        $db = $this->createFakeDatabase();
        $tmpFile = sys_get_temp_dir() . '/test_history_' . uniqid() . '.db';

        try {
            $app = $builder
                ->withDb($db)
                ->withHistoryDbPath($tmpFile)
                ->build();

            $this->assertInstanceOf(\SugarCraft\Query\App::class, $app);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function testChainedWithMethodsReturnNewInstances(): void
    {
        $builder = new AppBuilder();
        $db = $this->createFakeDatabase();

        $r1 = $builder->withTables(['t1']);
        $r2 = $r1->withTables(['t2']);
        $r3 = $r2->withTables(['t3']);

        $this->assertNotSame($builder, $r1);
        $this->assertNotSame($r1, $r2);
        $this->assertNotSame($r2, $r3);
    }
}
