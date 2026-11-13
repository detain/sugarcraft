<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Db\MysqlDatabase;
use SugarCraft\Sql\Db\PostgresDatabase;

/**
 * SQL-C11 transport-level dial pins + SQL-C15 charset negotiation.
 *
 * The remote connectors must always negotiate a network bound — with or
 * without pcntl, the Admin-layer alarm is not the only defence: MySQL dials
 * with connect + read/write timeouts, PostgreSQL rides libpq's
 * connect_timeout — and every MySQL-family dial must negotiate the UTF-8
 * charset Identifier::quoteLiteral's doubling presupposes. These legs never
 * build a real socket (the suite runs the option tables through the same
 * private seams the constructors consume), so they hold on CI hosts with no
 * database at all.
 */
final class DialTimeoutOptionsTest extends TestCase
{
    /** @return array<int,mixed> */
    private function mysqlOptions(string $sslMode): array
    {
        $method = new \ReflectionMethod(MysqlDatabase::class, 'pdoOptions');

        return $method->invoke(null, $sslMode);
    }

    public function testMysqlDialCarriesTheConnectBudget(): void
    {
        $options = $this->mysqlOptions('');

        self::assertSame(
            MysqlDatabase::NETWORK_TIMEOUT_SECONDS,
            $options[\PDO::ATTR_TIMEOUT] ?? null,
            'ATTR_TIMEOUT caps the connect phase on every mysql dial',
        );
    }

    public function testMysqlDialCarriesTheNetworkReadAndWriteBudgets(): void
    {
        $options = $this->mysqlOptions('');

        $read = defined('PDO::MYSQL_ATTR_READ_TIMEOUT')
            ? \constant('PDO::MYSQL_ATTR_READ_TIMEOUT')
            : null;
        $write = defined('PDO::MYSQL_ATTR_WRITE_TIMEOUT')
            ? \constant('PDO::MYSQL_ATTR_WRITE_TIMEOUT')
            : null;

        // On any pdo_mysql build (CI included) the constants exist and the
        // attrs must be present; elsewhere their absence is the documented
        // defined()-guard path.
        if ($read !== null) {
            self::assertSame(MysqlDatabase::NETWORK_TIMEOUT_SECONDS, $options[$read], 'mid-flight read stall must die at the transport');
        }
        if ($write !== null) {
            self::assertSame(MysqlDatabase::NETWORK_TIMEOUT_SECONDS, $options[$write], 'mid-flight write stall must die at the transport');
        }

        self::assertTrue(
            $read === null || $write === null || array_key_exists($read, $options) === array_key_exists($write, $options),
            'the two network attrs arrive as a pair, never one-sided',
        );
    }

    public function testMysqlTimeoutsDoNotClobberTheSslTable(): void
    {
        $plain = $this->mysqlOptions('');
        $tls = $this->mysqlOptions('verify_identity');

        self::assertNotEmpty(
            array_diff_key($tls, $plain),
            'sslDriverOptions() still contributes its keys on top of the base set',
        );
        $this->expectException(\InvalidArgumentException::class);
        $this->mysqlOptions('bogus-mode');
    }

    public function testMysqlDialNegotiatesUtf8mb4AtConnect(): void
    {
        // The quote-safety precondition for every static builder that has no
        // live handle to call PDO::quote on (PreviewQuery's information_schema
        // lookups): the connection really runs a UTF-8-family charset.
        if (!defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            self::markTestSkipped('pdo_mysql not loaded; the defined()-guard path is taken at dial time');
        }

        $options = $this->mysqlOptions('');

        self::assertSame(
            'SET NAMES ' . MysqlDatabase::CONNECTION_CHARSET,
            $options[\constant('PDO::MYSQL_ATTR_INIT_COMMAND')] ?? null,
            'every mysql dial must send the charset init-command',
        );
    }

    public function testCharsetNegotiationSurvivesTheSslUnion(): void
    {
        if (!defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            self::markTestSkipped('pdo_mysql not loaded; the defined()-guard path is taken at dial time');
        }

        // The option set is assembled with `+` (base + ssl table); a TLS dial
        // that silently dropped the init-command would re-open the very gap
        // the negotiation closes — verified modes are the ones that matter.
        $tls = $this->mysqlOptions('verify_identity');

        self::assertSame(
            'SET NAMES ' . MysqlDatabase::CONNECTION_CHARSET,
            $tls[\constant('PDO::MYSQL_ATTR_INIT_COMMAND')] ?? null,
            'TLS dials carry the charset leg too',
        );
    }

    public function testBothMysqlDialSitesShareTheOneOptionBuilder(): void
    {
        // The reconnect leg once hand-rolled its own option array and drifted
        // (it still does if this pin goes red): connect() and reconnect() must
        // consume the same builder, which is the only way the timeouts can
        // never be lost from one of the two paths again.
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Db/MysqlDatabase.php',
        );

        self::assertSame(
            2,
            substr_count($source, 'self::pdoOptions($config->sslMode)'),
            'both dial sites (connect + reconnect) must call self::pdoOptions()',
        );
    }

    public function testPostgresDialAppendsTheLibpqConnectTimeout(): void
    {
        $method = new \ReflectionMethod(PostgresDatabase::class, 'dialDsn');
        $dsn = 'pgsql:host=h;port=5432;dbname=d';

        self::assertSame(
            $dsn . ';connect_timeout=' . PostgresDatabase::CONNECT_TIMEOUT_SECONDS,
            $method->invoke(null, $dsn),
        );
    }

    public function testPostgresDialRespectsAnExplicitConnectTimeout(): void
    {
        $method = new \ReflectionMethod(PostgresDatabase::class, 'dialDsn');
        $dsn = 'pgsql:host=h;port=5432;dbname=d;connect_timeout=5';

        self::assertSame($dsn, $method->invoke(null, $dsn), 'an operator-chosen timeout is never silently overridden');
    }

    public function testPostgresDialSiteConsumesTheTimeoutAppender(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Db/PostgresDatabase.php',
        );

        self::assertSame(
            1,
            substr_count($source, 'self::dialDsn($config->dsn)'),
            'the connect leg must hand PDO the appended DSN, never the raw one',
        );
    }

    public function testPostgresStoredDsnStaysUntouched(): void
    {
        // The timeout rides only the string handed to the constructor; the
        // identity DSN that keys pools and comparisons must not gain it.
        $config = \SugarCraft\Sql\Db\ConnectionConfig::new(
            driver: 'pgsql',
            host: 'h',
            port: 5432,
            user: 'u',
            pass: '',
            dbname: 'd',
            sslMode: '',
        );

        self::assertStringNotContainsString('connect_timeout', $config->dsn);
    }
}
