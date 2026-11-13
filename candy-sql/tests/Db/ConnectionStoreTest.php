<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Db\ConnectionFactory;
use SugarCraft\Sql\Db\ConnectionStore;

/**
 * SQL-O02a (candy-sql-once-002): the saved-connection registry. The file is
 * user data on disk, so every pin here is about the contract with it: mode
 * hygiene (0600/0700, never secrets), named refusals for every malformed
 * shape, and the env-reference password pattern held end to end.
 */
final class ConnectionStoreTest extends TestCase
{
    private string $home;
    private string $configDir;
    private string $path;

    protected function setUp(): void
    {
        $this->home = \sys_get_temp_dir() . '/cs-conn-' . uniqid('', true);
        $this->configDir = $this->home . '/.config/candy-sql';
        $this->path = $this->configDir . '/connections.json';
        \mkdir($this->configDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->configDir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->configDir);
        @\rmdir($this->home . '/.config');
        @\rmdir($this->home);
    }

    private function write(string $json): void
    {
        \file_put_contents($this->path, $json);
    }

    private function store(): ConnectionStore
    {
        return new ConnectionStore($this->path);
    }

    public function testMissingFileIsAnEmptyStore(): void
    {
        @\unlink($this->path);
        $this->assertSame([], $this->store()->all());
        $this->assertSame([], $this->store()->names());
    }

    public function testRoundTripSaveAndLoadSortedByNames(): void
    {
        $store = $this->store();
        $store->save('zeta', ['driver' => 'pgsql', 'host' => 'h2', 'port' => '5432', 'db' => 'd2']);
        $store->save('alpha', ['driver' => 'mysql', 'host' => 'h1', 'port' => '3306', 'user' => 'u1', 'db' => 'd1']);

        $this->assertSame(['alpha', 'zeta'], $store->names());
        $this->assertSame('u1', $store->all()['alpha']['user']);
        $this->assertSame('5432', $store->all()['zeta']['port']);
    }

    public function testFreshSaveLandsSixHundred(): void
    {
        $this->store()->save('mine', ['driver' => 'sqlite', 'db' => '/tmp/mine.sqlite']);

        $perms = @\fileperms($this->path);
        $this->assertIsInt($perms, 'the connections file must exist after save');
        $this->assertSame(0600, $perms & 0777, 'the file must never be group- or world-readable');
        $dirPerms = \fileperms($this->configDir);
        $this->assertSame(0, $dirPerms & 0077, 'the config dir must stay private');
    }

    public function testReadablePreExistingFileIsTightenedOnLoad(): void
    {
        $this->write('{"connections": {}}');
        \chmod($this->path, 0644);

        $this->store()->all();

        \clearstatcache(true, $this->path);
        $this->assertSame(0600, \fileperms($this->path) & 0777);
    }

    public function testTighterPreExistingFileIsNotLoosened(): void
    {
        $this->write('{"connections": {}}');
        \chmod($this->path, 0400);

        $this->store()->all();

        \clearstatcache(true, $this->path);
        $this->assertSame(0400, \fileperms($this->path) & 0777);
    }

    public function testPlaintextPassIsRefusedByName(): void
    {
        $this->write('{"connections": {"bad": {"driver": "mysql", "db": "x", "pass": "hunter2"}}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Connection 'bad' stores a plaintext 'pass'");
        $this->store()->all();
    }

    public function testUnknownFieldIsRefusedInsteadOfDropped(): void
    {
        $this->write('{"connections": {"s": {"driver": "mysql", "db": "x", "hostname": "typo"}}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("unknown setting 'hostname'");
        $this->store()->all();
    }

    public function testCorruptJsonIsANamedRefusal(): void
    {
        $this->write('{"connections": {oops');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');
        $this->store()->all();
    }

    public function testMissingConnectionsKeyIsRefused(): void
    {
        $this->write('{"servers": {}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be a JSON object with a "connections" map');
        $this->store()->all();
    }

    public function testUnsupportedDriverIsRefused(): void
    {
        $this->write('{"connections": {"m": {"driver": "oracle", "db": "x"}}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("unsupported driver 'oracle'");
        $this->store()->all();
    }

    public function testNonNumericPortIsRefused(): void
    {
        $this->write('{"connections": {"m": {"driver": "mysql", "db": "x", "port": "330a"}}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("'port' of connection 'm' must be a number");
        $this->store()->all();
    }

    public function testInvalidNameIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid connection name');
        $this->store()->save('bad name!', ['driver' => 'sqlite', 'db' => '/tmp/x']);
    }

    public function testUnknownConnectNameListsSavedNames(): void
    {
        $this->write('{"connections": {"dev": {"driver": "mysql", "db": "x"}, "prod": {"driver": "mysql", "db": "y"}}}');

        try {
            $this->store()->argv('ghost');
            $this->fail('expected a named refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Unknown connection 'ghost'", $e->getMessage());
            $this->assertStringContainsString('dev, prod', $e->getMessage());
        }
    }

    public function testArgvExpandsEveryField(): void
    {
        $this->write(json_encode(['connections' => ['p' => [
            'driver' => 'mysql', 'host' => 'db.example.com', 'port' => '3307',
            'user' => 'app', 'db' => 'shop', 'ssl-mode' => 'require',
        ]]]) . "\n");

        $this->assertSame([
            '--driver=mysql',
            '--host=db.example.com',
            '--port=3307',
            '--user=app',
            '--db=shop',
            '--ssl-mode=require',
        ], $this->store()->argv('p'));
    }

    public function testPassEnvIsResolvedAtDialTimeAndNeverStored(): void
    {
        $var = 'CANDY_SQL_TEST_SECRET_' . strtoupper(substr(uniqid(), -6));
        $this->write(json_encode(['connections' => ['p' => [
            'driver' => 'mysql', 'host' => 'h', 'db' => 'd', 'pass-env' => $var,
        ]]]) . "\n");

        \putenv($var . '=sekrit-value');
        try {
            $argv = $this->store()->argv('p');
            $this->assertContains('--pass=sekrit-value', $argv);

            // The secret travels only in process memory: the file itself
            // still has no trace of it.
            $raw = (string) \file_get_contents($this->path);
            $this->assertStringNotContainsString('sekrit-value', $raw);
        } finally {
            \putenv($var);
        }
    }

    public function testMissingPassEnvRefusesWithTheVariableName(): void
    {
        $var = 'CANDY_SQL_TEST_ABSENT_' . strtoupper(substr(uniqid(), -6));
        $this->write(json_encode(['connections' => ['p' => [
            'driver' => 'mysql', 'host' => 'h', 'db' => 'd', 'pass-env' => $var,
        ]]]) . "\n");
        \putenv($var);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('export it before dialing');
        $this->store()->argv('p');
    }

    public function testSavedSqliteEntryDialsThroughTheFactory(): void
    {
        $file = $this->home . '/saved.sqlite';
        $pdo = new \PDO('sqlite:' . $file);
        $pdo->exec('CREATE TABLE t (v TEXT)');
        $pdo->exec("INSERT INTO t VALUES ('row-1')");
        unset($pdo);

        $this->store()->save('mine', ['driver' => 'sqlite', 'db' => $file]);
        $database = ConnectionFactory::fromArgv($this->store()->argv('mine'));

        $this->assertInstanceOf(\SugarCraft\Sql\Db\SqliteDatabase::class, $database);
        $rows = $database->query("SELECT v FROM t");
        $this->assertSame([['v' => 'row-1']], $rows);
    }

    public function testDefaultPathHonoursXdgConfigHome(): void
    {
        $xdg = getenv('XDG_CONFIG_HOME');
        $home = getenv('HOME');
        \putenv('XDG_CONFIG_HOME=' . $this->home . '/xdg');
        try {
            $this->assertSame($this->home . '/xdg/candy-sql/connections.json', ConnectionStore::defaultPath());
        } finally {
            $xdg === false ? \putenv('XDG_CONFIG_HOME') : \putenv('XDG_CONFIG_HOME=' . $xdg);
            \putenv('HOME=' . (is_string($home) ? $home : ''));
        }
    }

    public function testDefaultPathFallsBackToHome(): void
    {
        $xdg = getenv('XDG_CONFIG_HOME');
        \putenv('XDG_CONFIG_HOME');
        try {
            $path = ConnectionStore::defaultPath();
            if ($path !== null) {
                $this->assertStringEndsWith('/.config/candy-sql/connections.json', $path);
            }
        } finally {
            $xdg === false ? \putenv('XDG_CONFIG_HOME') : \putenv('XDG_CONFIG_HOME=' . $xdg);
        }
    }

    public function testPassEnvMustLookLikeAnEnvironmentVariable(): void
    {
        $this->write('{"connections": {"m": {"driver": "mysql", "db": "x", "pass-env": "bad var"}}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be an environment variable name');
        $this->store()->all();
    }
}
