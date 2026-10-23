<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\History\HistoryRecorder;
use SugarCraft\Sql\Admin\History\SqliteHistoryStore;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * Pins the docs-review-1023 M3 wiring: App::start() accepts a
 * HistoryRecorder and threads it into AdminState, which is the exact call
 * shape bin/candy-sql uses for its default-on query history.
 */
final class HistoryRecorderThreadingTest extends TestCase
{
    private string $historyPath;

    protected function setUp(): void
    {
        $this->historyPath = \sys_get_temp_dir() . '/candy_sql_thread_' . \uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        foreach ([$this->historyPath, $this->historyPath . '-wal', $this->historyPath . '-shm'] as $f) {
            if (\is_file($f)) {
                @\unlink($f);
            }
        }
    }

    public function testStartThreadsTheRecorderIntoAdminState(): void
    {
        $store = new SqliteHistoryStore($this->historyPath);
        $recorder = new HistoryRecorder($store);
        $app = App::start(new FakeDatabase(), Flavor::MySQL, $recorder);

        $this->assertSame($recorder, $app->admin->historyRecorder);
        $store->close();
    }

    public function testStartWithoutRecorderLeavesHistoryDisabled(): void
    {
        $app = App::start(new FakeDatabase(), Flavor::MySQL);

        $this->assertNull($app->admin->historyRecorder);
    }
}
