<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\ReconnectException;

/**
 * ReconnectException extends RuntimeException, not PDOException
 * (D-M10, 2026-11-03). MysqlDatabase::query() throws it when the
 * reconnect manager gives up; the two in-app catches widened to
 * `PDOException | ReconnectException` so the failure lands in the
 * status line instead of a raw fatal over the alt screen.
 */
final class ReconnectExceptionFoldTest extends TestCase
{
    private function appOn(DatabaseInterface $db): App
    {
        return App::builder()
            ->withDb($db)
            ->withFlavor(Flavor::MySQL)
            ->withQueryEditor(TextArea::new()->setValue('SELECT 1'))
            ->build();
    }

    private function doubleThatDiesOnQuery(): DatabaseInterface
    {
        return new class implements DatabaseInterface {
            public function tables(): array { return []; }

            /** @return list<array<string,mixed>> */
            public function rows(string $table, int $limit = 100, int $offset = 0): array { return []; }

            public function query(string $sql): array|null
            {
                throw new ReconnectException(
                    'Failed to reconnect after connection error: gone',
                    new \PDOException('SQLSTATE[HY000] [2006] MySQL server has gone away'),
                );
            }

            public function lastInsertId(): string|int { return 0; }

            public function quote(string $value): string { return "'" . $value . "'"; }

            public function exec(string $sql): int { return 0; }

            public function close(): void {}

            public function serverVersion(): string { return 'MySQL version unknown'; }

            public function driverName(): string { return 'mysql'; }

            public function ping(): bool { return false; }

            public function databases(): array { return []; }

            public function prepare(string $sql): ?\SugarCraft\Sql\Db\PreparedStatementInterface { return null; }

            public function dsn(): string { return 'mysql://x@y/db'; }

            public function username(): string { return 'x'; }
        };
    }

    public function testRunQueryFoldsAReconnectRefusalIntoTheErrorLine(): void
    {
        $app = $this->appOn($this->doubleThatDiesOnQuery());
        $runQuery = new \ReflectionMethod($app, 'runQuery');
        $runQuery->setAccessible(true);

        $mutated = $runQuery->invoke($app); // used to escape as a raw RuntimeException

        $this->assertNotNull($mutated->ui->error);
        $this->assertStringContainsString('Failed to reconnect', (string) $mutated->ui->error);
    }

    public function testLoadTableFoldsAReconnectRefusalToo(): void
    {
        $app = $this->appOn($this->doubleThatDiesOnQuery());
        $loadTable = new \ReflectionMethod($app, 'loadTable');
        $loadTable->setAccessible(true);

        $mutated = $loadTable->invoke($app, 'anything');

        $this->assertNotNull($mutated->ui->error);
        $this->assertStringContainsString('Failed to reconnect', (string) $mutated->ui->error);
        $this->assertFalse($mutated->browse->rowsLoading, 'loading flag must clear on the error fold');
    }
}
