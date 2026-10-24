<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\AdminQueryCache;
use SugarCraft\Sql\Admin\ReactPostgresConnection;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Db\Flavor;

/**
 * TLS-threading pins for the async PostgreSQL connector.
 *
 * PgAsync carries a real `tls` parameter (unlike react/mysql's plaintext
 * URI), so the pgsql sslmode that ConnectionConfig bakes into every DSN
 * must ride through ReactPostgresConnection::dsnToParameters() — required
 * modes then fail loud against plaintext instead of silently downgrading,
 * and the app never needs the MySQL-style sync stand-down for pgsql.
 *
 * Both polarities are pinned: every accepted libpq mode lands verbatim;
 * absent/unknown modes are defensively omitted (PgAsync's own default is
 * 'prefer', matching libpq's). The App-level pin asserts a verified pgsql
 * mode keeps the admin tick armed — if the MySQL refusal gate ever leaked
 * onto pgsql, that goes red by name.
 */
final class ReactPostgresConnectionTlsTest extends TestCase
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

    /** @return array<string,mixed> */
    private function parametersFor(string $dsn): array
    {
        $connection = new ReactPostgresConnection($dsn);

        return (new \ReflectionMethod($connection, 'dsnToParameters'))
            ->invoke($connection, $dsn, '', '');
    }

    /** @return array<string,mixed> */
    private function clientParametersOf(ReactPostgresConnection $connection): array
    {
        $client = (new \ReflectionProperty(ReactPostgresConnection::class, 'client'))
            ->getValue($connection);

        return (new \ReflectionProperty($client, 'parameters'))->getValue($client);
    }

    /** @return array<string,array{string,string}> */
    public static function acceptedTlsModes(): array
    {
        $cases = [];
        foreach (['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'] as $mode) {
            $cases[$mode] = [$mode, $mode];
        }

        return $cases;
    }

    #[DataProvider('acceptedTlsModes')]
    public function testEachLibpqModeThreadsToTheTlsParameterVerbatim(string $mode, string $expected): void
    {
        $params = $this->parametersFor("pgsql:host=h;port=5432;dbname=d;sslmode={$mode}");

        $this->assertArrayHasKey('tls', $params, "sslmode={$mode} must thread into the tls parameter");
        $this->assertSame($expected, $params['tls']);
    }

    public function testModelessDsnOmitsTheTlsParameterEntirely(): void
    {
        $params = $this->parametersFor('pgsql:host=h;port=5432;dbname=d');

        $this->assertArrayNotHasKey('tls', $params, 'no sslmode in the DSN must inherit PgAsync defaults');
    }

    public function testUnknownModeIsDefensivelyOmitted(): void
    {
        $params = $this->parametersFor('pgsql:host=h;port=5432;dbname=d;sslmode=verify-planet');

        $this->assertArrayNotHasKey(
            'tls',
            $params,
            'an unrecognised sslmode must not be handed to the vendor constructor, which would throw',
        );
    }

    public function testTlsThreadingLeavesTheOtherParametersUntouched(): void
    {
        $plain = $this->parametersFor('pgsql:host=db.example;port=6543;dbname=analytics');
        $tls = $this->parametersFor('pgsql:host=db.example;port=6543;dbname=analytics;sslmode=require');

        unset($plain['tls'], $tls['tls']);
        $this->assertSame($plain, $tls, 'only the tls key may differ between encrypted and plain parameter sets');
        $this->assertSame('db.example', $tls['host']);
        $this->assertSame(6543, $tls['port']);
        $this->assertSame('analytics', $tls['database']);
    }

    public function testRealConstructionCarriesTheTlsParameterIntoTheVendorClient(): void
    {
        $encrypted = new ReactPostgresConnection('pgsql:host=h;port=5432;dbname=d;sslmode=require', 'u', 'p');
        $this->assertSame(
            'require',
            $this->clientParametersOf($encrypted)['tls'] ?? null,
            'the parameter must survive into PgAsync\Client, not just the local array',
        );

        $plain = new ReactPostgresConnection('pgsql:host=h;port=5432;dbname=d', 'u', 'p');
        $this->assertArrayNotHasKey('tls', $this->clientParametersOf($plain));
    }

    public function testVerifiedPgsqlModeKeepsTheAdminAsyncTickArmed(): void
    {
        // The MySQL refusal gate must not leak onto pgsql: the async
        // connector carries TLS natively, so a verified pgsql mode keeps
        // live refresh running (and arms no status-line stand-down).
        $a = App::start(new FakeDatabase(), Flavor::Postgres, null, 'require');
        for ($i = 0; $i < 3; $i++) {
            [$a, ] = $a->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Tab, ''));
        }

        $this->assertNull($a->ui->status, 'a verified pgsql mode must never stand the app down');
        $this->assertNotNull($a->subscriptions(), 'the admin-fetch tick stays armed: the transport honours TLS');
    }
}
