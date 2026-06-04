<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Connections;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Connections\ProcesslistProvider;
use SugarCraft\Query\Admin\Connections\ProcesslistResult;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\PreparedStatementInterface;
use SugarCraft\Query\Db\Version;
use SugarCraft\Query\Tests\Admin\FakeDatabase;

final class ProcesslistProviderTest extends TestCase
{
    private FakeDatabase $db;
    private TestServerContext $context;

    protected function setUp(): void
    {
        $this->db = new FakeDatabase();
        $this->context = new TestServerContext($this->db);
    }

    public function testFetchAllReturnsEmptyWhenNoRows(): void
    {
        $this->db->setQueryResult([]);
        $provider = ProcesslistProvider::new($this->context);
        $result = $provider->fetchAll();
        $this->assertSame([], $result);
    }

    public function testFetchAllViaShowProcesslistWhenPSDisabled(): void
    {
        $this->db->setQueryResult([
            ['Id' => '1', 'User' => 'root', 'Host' => 'localhost', 'db' => 'test', 'Command' => 'Query', 'Time' => 0, 'State' => 'executing', 'Info' => 'SELECT 1'],
            ['Id' => '2', 'User' => 'root', 'Host' => 'localhost', 'db' => '', 'Command' => 'Sleep', 'Time' => 100, 'State' => '', 'Info' => null],
        ]);

        $provider = ProcesslistProvider::new($this->context);
        $result = $provider->fetchAll();

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]->processId);
        $this->assertSame('root', $result[0]->user);
        $this->assertSame('localhost', $result[0]->host);
        $this->assertSame('test', $result[0]->database);
        $this->assertSame('Query', $result[0]->command);
        $this->assertSame(0, $result[0]->time);
        $this->assertSame('executing', $result[0]->state);
        $this->assertSame('SELECT 1', $result[0]->info);
        $this->assertFalse($result[0]->isPS);
    }

    public function testFetchAllFallsBackWhenPSDenied(): void
    {
        $db = new SequentialFakeDatabase();

        // First query: PS check returns 1 (enabled)
        $db->addQueryResult([['ps' => 1]]);
        // Second query: PS table throws 1142 (denied)
        $db->setQueryThrowsOn(new \PDOException('SELECT privilege denied', 1142), 'performance_schema.threads');
        // Third query: Fallback to SHOW FULL PROCESSLIST succeeds
        $db->addQueryResult([
            ['Id' => '5', 'User' => 'root', 'Host' => 'localhost', 'db' => 'test', 'Command' => 'Query', 'Time' => 1, 'State' => '', 'Info' => 'SELECT 2'],
        ]);

        $context = new TestServerContext($db);
        $provider = ProcesslistProvider::new($context);
        $result = $provider->fetchAll();

        $this->assertCount(1, $result);
        $this->assertSame(5, $result[0]->processId);
        $this->assertFalse($result[0]->isPS);
    }

    public function testFetchAllReturnsEmptyOnConnectionError(): void
    {
        $db = new SequentialFakeDatabase();

        // First query: PS check succeeds
        $db->addQueryResult([['ps' => 1]]);
        // Second query: Connection error 2013 on PS table
        $db->setQueryThrowsOn(new \PDOException('Lost connection', 2013), 'performance_schema.threads');

        $context = new TestServerContext($db);
        $provider = ProcesslistProvider::new($context);
        $result = $provider->fetchAll();

        // Connection errors degrade to empty list
        $this->assertSame([], $result);
    }

    public function testRefreshClearsPSCache(): void
    {
        $this->db->setQueryResult([['ps' => 0]]);
        $provider = ProcesslistProvider::new($this->context);
        $provider->fetchAll();

        $refreshed = $provider->refresh();
        $this->assertNotSame($provider, $refreshed);
    }

    public function testTruncatesLongInfo(): void
    {
        $longQuery = str_repeat('a', 600);
        $this->db->setQueryResult([
            ['Id' => '1', 'User' => 'root', 'Host' => 'localhost', 'db' => 'test', 'Command' => 'Query', 'Time' => 0, 'State' => '', 'Info' => $longQuery],
        ]);

        $provider = ProcesslistProvider::new($this->context);
        $result = $provider->fetchAll();

        $this->assertSame(512, \strlen($result[0]->info));
        $this->assertTrue($result[0]->infoTruncated);
    }

    public function testInfoTruncatedIsFalseForShortInfo(): void
    {
        $this->db->setQueryResult([
            ['Id' => '1', 'User' => 'root', 'Host' => 'localhost', 'db' => 'test', 'Command' => 'Query', 'Time' => 0, 'State' => '', 'Info' => 'SELECT 1'],
        ]);

        $provider = ProcesslistProvider::new($this->context);
        $result = $provider->fetchAll();

        $this->assertSame('SELECT 1', $result[0]->info);
        $this->assertFalse($result[0]->infoTruncated);
    }

    public function testIsBackgroundReturnsTrueForEmptyUser(): void
    {
        $this->db->setQueryResult([
            ['Id' => '1', 'User' => '', 'Host' => '', 'db' => '', 'Command' => 'Daemon', 'Time' => 0, 'State' => '', 'Info' => null],
        ]);

        $provider = ProcesslistProvider::new($this->context);
        $result = $provider->fetchAll();

        $this->assertTrue($result[0]->isBackground());
    }
}

/**
 * Minimal ServerContextInterface implementation for testing.
 */
final class TestServerContext implements ServerContextInterface
{
    public function __construct(
        private readonly DatabaseInterface $connection,
    ) {}

    public function connection(): DatabaseInterface
    {
        return $this->connection;
    }

    public function serverVariables(): array
    {
        return [];
    }

    public function statusVariables(): array
    {
        return [];
    }

    public function statusVariablesTs(): float
    {
        return 0.0;
    }

    public function plugins(): array
    {
        return [];
    }

    public function version(): Version
    {
        return Version::new('8.0.33', Flavor::MySQL);
    }

    public function flavor(): Flavor
    {
        return Flavor::MySQL;
    }

    public function versionString(): string
    {
        return 'MySQL 8.0.33';
    }

    public function password(): string { return ''; }

    public function wasReset(): bool
    {
        return false;
    }

    public function refresh(): void
    {
    }
}

/**
 * FakeDatabase that supports sequential query results via a queue
 * and can throw on specific SQL patterns.
 */
final class SequentialFakeDatabase implements DatabaseInterface
{
    /** @var list<list<array<string, mixed>>> */
    private array $resultQueue = [];

    private ?\PDOException $throwingException = null;
    private ?string $throwingOnPattern = null;

    public function addQueryResult(array $result): void
    {
        $this->resultQueue[] = $result;
    }

    /**
     * Set an exception to throw when a query matches $pattern.
     *
     * @param \PDOException $e Exception to throw
     * @param string $pattern SQL pattern to match (e.g., 'performance_schema.threads')
     */
    public function setQueryThrowsOn(\PDOException $e, string $pattern): void
    {
        $this->throwingException = $e;
        $this->throwingOnPattern = $pattern;
    }

    public function tables(): array
    {
        return [];
    }

    public function rows(string $table, int $limit = 100): array
    {
        return [];
    }

    public function query(string $sql): array|null
    {
        if ($this->throwingException !== null
            && $this->throwingOnPattern !== null
            && str_contains($sql, $this->throwingOnPattern)
        ) {
            $e = $this->throwingException;
            $this->throwingException = null;
            $this->throwingOnPattern = null;
            throw $e;
        }

        if ($this->resultQueue !== []) {
            return \array_shift($this->resultQueue);
        }

        return [];
    }

    public function lastInsertId(): string|int
    {
        return 0;
    }

    public function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
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
        return [];
    }

    public function prepare(string $sql): ?PreparedStatementInterface
    {
        return null;
    }

    public function dsn(): string { return ''; }
    public function username(): string { return ''; }
}
