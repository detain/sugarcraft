<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin;

use PgAsync\Client as PgAsyncClient;
use PHPUnit\Framework\TestCase;
use React\Mysql\MysqlClient;
use React\Promise\PromiseInterface;
use SugarCraft\Sql\Admin\AsyncConnection;
use SugarCraft\Sql\Admin\ReactMysqlConnection;
use SugarCraft\Sql\Admin\ReactPostgresConnection;

/**
 * admindata-004: every AsyncConnection must answer close() by retiring its
 * server session — AdminQueryCache's drop paths call exactly this, and a
 * wrapper that ignored it would orphan sockets behind every key swap.
 *
 * The wrappers are driven with socket-less client doubles injected the same
 * way their real clients are produced (mysql: the A04 clientFactory seam;
 * pg: reflection, its pool is built in the constructor).
 */
final class AsyncConnectionCloseTest extends TestCase
{
    public function testContractDeclaresClose(): void
    {
        $this->assertTrue(
            (new \ReflectionClass(AsyncConnection::class))->hasMethod('close'),
            'the seam must carry the teardown both AdminQueryCache drop paths invoke',
        );
    }

    public function testMysqlWrapperCloseRetiresItsClient(): void
    {
        $dials = [];
        $conn = new ReactMysqlConnection(
            'mysql:host=h;dbname=d',
            'u',
            'p',
            new \React\EventLoop\StreamSelectLoop(),
            clientFactory: static function () use (&$dials): MysqlClient {
                $spy = new class extends MysqlClient {
                    public bool $closed = false;

                    public function __construct()
                    {
                        // no socket
                    }

                    public function query($sql, array $params = []): PromiseInterface
                    {
                        return \React\Promise\resolve(null);
                    }

                    public function close()
                    {
                        $this->closed = true;
                    }
                };
                $dials[] = $spy;
                return $spy;
            },
        );

        $conn->close();

        $this->assertCount(1, $dials);
        $this->assertTrue($dials[0]->closed, 'close() must reach the underlying react/mysql client');
    }

    public function testPostgresWrapperCloseRetiresItsPool(): void
    {
        $ref = new \ReflectionClass(ReactPostgresConnection::class);
        $conn = $ref->newInstanceWithoutConstructor();

        $spy = new class extends PgAsyncClient {
            public bool $closed = false;

            public function __construct()
            {
                // no parameters, no connector, no socket
            }

            public function closeNow()
            {
                $this->closed = true;
            }
        };
        $client = $ref->getProperty('client');
        $client->setAccessible(true);
        $client->setValue($conn, $spy);

        $conn->close();

        $this->assertTrue($spy->closed, 'close() must reach the PgAsync client pool');
    }
}
