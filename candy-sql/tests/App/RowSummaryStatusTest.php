<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\SqliteDatabase;

/**
 * D-M25: the status line read "1 rows" — counts of one need the singular.
 */
final class RowSummaryStatusTest extends TestCase
{
    private function appWithRows(int $count): App
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE t (x INTEGER)');
        for ($i = 1; $i <= $count; $i++) {
            $pdo->exec('INSERT INTO t VALUES (' . $i . ')');
        }
        return App::builder()
            ->withDb(new SqliteDatabase($pdo))
            ->withFlavor(Flavor::Sqlite)
            ->withQueryEditor(TextArea::new()->setValue('SELECT x FROM t'))
            ->build();
    }

    public function testSingleResultReadsOneRow(): void
    {
        $app = $this->appWithRows(1);
        $run = new ReflectionMethod($app, 'runQuery');
        $run->setAccessible(true);
        /** @var App $next */
        $next = $run->invoke($app);
        $this->assertSame('1 row', $next->ui->status);
    }

    public function testManyResultsReadPlural(): void
    {
        $app = $this->appWithRows(2);
        $run = new ReflectionMethod($app, 'runQuery');
        $run->setAccessible(true);
        /** @var App $next */
        $next = $run->invoke($app);
        $this->assertSame('2 rows', $next->ui->status);
    }

    public function testZeroResultsKeepThePlural(): void
    {
        $app = $this->appWithRows(0);
        $run = new ReflectionMethod($app, 'runQuery');
        $run->setAccessible(true);
        /** @var App $next */
        $next = $run->invoke($app);
        $this->assertSame('0 rows', $next->ui->status);
    }
}
