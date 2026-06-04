<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Connections;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Connections\ConnectionActions;
use SugarCraft\Query\Admin\Connections\ConnectionDetailTabs;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\PreparedStatementInterface;
use SugarCraft\Query\Db\Version;

final class ConnectionActionsTest extends TestCase
{
    private FakeDb $db;
    private TestCtx $ctx;

    protected function setUp(): void
    {
        $this->db = new FakeDb();
        $this->ctx = new TestCtx($this->db);
    }

    public function testKillRefusesBackgroundThread(): void
    {
        $actions = ConnectionActions::new($this->ctx);
        $result = $actions->kill(123, true);
        $this->assertFalse($result);
    }

    public function testKillQueryRefusesBackgroundThread(): void
    {
        $actions = ConnectionActions::new($this->ctx);
        $result = $actions->killQuery(123, true);
        $this->assertFalse($result);
    }

    public function testKillExecutesOnNonBackground(): void
    {
        $this->db->setNextExecAffected(1);
        $actions = ConnectionActions::new($this->ctx);
        $result = $actions->kill(123, false);
        $this->assertTrue($result);
    }

    public function testKillQueryExecutesOnNonBackground(): void
    {
        $this->db->setNextExecAffected(1);
        $actions = ConnectionActions::new($this->ctx);
        $result = $actions->killQuery(123, false);
        $this->assertTrue($result);
    }

    public function testKillReturnsFalseOnError(): void
    {
        $this->db->setNextExecThrows(new \PDOException('Unknown thread', 1094));
        $actions = ConnectionActions::new($this->ctx);
        $result = $actions->kill(999, false);
        $this->assertFalse($result);
    }

    public function testSetInstrumentationReturnsTrueOnSuccess(): void
    {
        $this->db->setNextExecAffected(1);
        $actions = ConnectionActions::new($this->ctx);
        $result = $actions->setInstrumentation(true, 456);
        $this->assertTrue($result);
    }

    public function testSetInstrumentationReturnsFalseOnDenied(): void
    {
        $this->db->setNextExecThrows(new \PDOException('UPDATE denied', 1142));
        $actions = ConnectionActions::new($this->ctx);
        $result = $actions->setInstrumentation(false, 456);
        $this->assertFalse($result);
    }

    public function testIsInstrumentationEnabledReturnsTrue(): void
    {
        $this->db->setQueryResult([['ENABLED' => 'YES']]);
        $actions = ConnectionActions::new($this->ctx);
        $result = $actions->isInstrumentationEnabled();
        $this->assertTrue($result);
    }

    public function testIsInstrumentationEnabledReturnsFalse(): void
    {
        $this->db->setQueryResult([['ENABLED' => 'NO']]);
        $actions = ConnectionActions::new($this->ctx);
        $result = $actions->isInstrumentationEnabled();
        $this->assertFalse($result);
    }

    public function testIsInstrumentationEnabledReturnsNullOnError(): void
    {
        $this->db->setQueryThrows(new \PDOException('denied', 1142));
        $actions = ConnectionActions::new($this->ctx);
        $result = $actions->isInstrumentationEnabled();
        $this->assertNull($result);
    }
}

final class ConnectionDetailTabsTest extends TestCase
{
    private FakeDb $db;
    private TestCtx $ctx;

    protected function setUp(): void
    {
        $this->db = new FakeDb();
        $this->ctx = new TestCtx($this->db);
    }

    public function testGetDetailsReturnsNullWhenAccessDenied(): void
    {
        $this->db->setQueryThrows(new \PDOException('denied', 1142));
        $tabs = ConnectionDetailTabs::new($this->ctx);
        $result = $tabs->getDetails(123);
        $this->assertNull($result);
    }

    public function testGetAttributesReturnsNullWhenAccessDenied(): void
    {
        $this->db->setQueryThrows(new \PDOException('denied', 1142));
        $tabs = ConnectionDetailTabs::new($this->ctx);
        $result = $tabs->getAttributes(123);
        $this->assertNull($result);
    }

    public function testGetMdlLocksFallsBackToInfoSchema(): void
    {
        // First PS query throws 1142, second (info_schema) succeeds
        $this->db->setQueryResult([
            ['lock_id' => '123:0', 'lock_type' => 'SHARED', 'lock_status' => 'GRANTED', 'lock_mode' => 'SHARED', 'thread_id' => '1', 'processlist_id' => '123'],
        ]);
        $tabs = ConnectionDetailTabs::new($this->ctx);
        $result = $tabs->getMdlLocks(123);
        $this->assertIsArray($result);
    }

    public function testGetExplainReturnsNullForEmptyQuery(): void
    {
        $this->db->setQueryResult([['id' => '123', 'info' => '']]);
        $tabs = ConnectionDetailTabs::new($this->ctx);
        $result = $tabs->getExplain(123);
        $this->assertNull($result);
    }

    public function testGetThreadStackReturnsNullOnError(): void
    {
        $this->db->setQueryThrows(new \PDOException('denied', 1142));
        $tabs = ConnectionDetailTabs::new($this->ctx);
        $result = $tabs->getThreadStack(123);
        $this->assertNull($result);
    }
}

/** @implements DatabaseInterface */
final class FakeDb implements DatabaseInterface
{
    /** @var list<array<string, mixed>> */
    public array $queryResult = [];
    private ?\PDOException $queryException = null;

    /** @param \PDOException|null */
    public ?\PDOException $nextExecException = null;
    public int $nextExecAffected = 0;

    public function setQueryResult(array $result): void
    {
        $this->queryResult = $result;
        $this->queryException = null;
    }

    public function setQueryThrows(\PDOException $e): void
    {
        $this->queryException = $e;
        $this->queryResult = [];
    }

    public function setNextExecAffected(int $n): void
    {
        $this->nextExecAffected = $n;
        $this->nextExecException = null;
    }

    public function setNextExecThrows(\PDOException $e): void
    {
        $this->nextExecException = $e;
        $this->nextExecAffected = 0;
    }

    public function tables(): array { return []; }
    public function rows(string $table, int $limit = 100): array { return []; }

    public function query(string $sql): array
    {
        if ($this->queryException !== null) {
            throw $this->queryException;
        }
        return $this->queryResult;
    }

    public function exec(string $sql): int
    {
        if ($this->nextExecException !== null) {
            $e = $this->nextExecException;
            $this->nextExecException = null;
            throw $e;
        }
        return $this->nextExecAffected;
    }

    public function lastInsertId(): string|int { return 0; }
    public function quote(string $value): string { return "'" . str_replace("'", "''", $value) . "'"; }
    public function close(): void {}
    public function serverVersion(): string { return 'MySQL 8.0.33'; }
    public function driverName(): string { return 'mysql'; }
    public function ping(): bool { return true; }
    public function databases(): array { return []; }
    public function prepare(string $sql): ?PreparedStatementInterface
    {
        return new FakeStmt($this);
    }

    public function dsn(): string { return ''; }
    public function username(): string { return ''; }
}

final class FakeStmt implements PreparedStatementInterface
{
    private bool $executed = false;

    public function __construct(private readonly FakeDb $db) {}

    public function execute(?array $params = null): bool
    {
        $this->executed = true;
        if ($this->db->nextExecException !== null) {
            $e = $this->db->nextExecException;
            $this->db->nextExecException = null;
            throw $e;
        }
        $this->db->nextExecAffected = 1;
        return true;
    }

    public function fetch(): array|false
    {
        return $this->db->queryResult[0] ?? false;
    }

    public function fetchAll(): array
    {
        return $this->db->queryResult;
    }

    public function rowCount(): int
    {
        return $this->db->nextExecAffected;
    }

    public function closeCursor(): bool
    {
        return true;
    }
}

final class TestCtx implements ServerContextInterface
{
    public function __construct(private readonly DatabaseInterface $db) {}

    public function connection(): DatabaseInterface { return $this->db; }
    public function serverVariables(): array { return []; }
    public function statusVariables(): array { return []; }
    public function statusVariablesTs(): float { return 0.0; }
    public function plugins(): array { return []; }
    public function version(): Version { return Version::new('8.0.33', Flavor::MySQL); }
    public function flavor(): Flavor { return Flavor::MySQL; }
    public function versionString(): string { return 'MySQL 8.0.33'; }
    public function password(): string { return ''; }
    public function wasReset(): bool { return false; }
    public function refresh(): void {}
}
