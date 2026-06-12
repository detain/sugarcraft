<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\ReactMysqlConnection;

/**
 * Guards the PDO-DSN → react/mysql-URI translation.
 *
 * react/mysql's Factory runs the URI through parse_url() and connects to
 * $parts['host'], requiring a `mysql://` scheme. A scheme-less URI mis-parses
 * (the username becomes the "scheme", host is empty) and every connect is
 * "refused" — so this test pins the scheme and the host/port/credential
 * round-trip. dsnToUri() is exercised in isolation (no socket/loop).
 */
final class ReactMysqlConnectionTest extends TestCase
{
    /** Build the react/mysql URI dsnToUri() would hand the driver. */
    private function uriFor(string $dsn, string $user, string $pass): string
    {
        $ref = new \ReflectionClass(ReactMysqlConnection::class);
        $conn = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('dsnToUri');
        $method->setAccessible(true);

        return $method->invoke($conn, $dsn, $user, $pass);
    }

    public function testUriCarriesTheRequiredMysqlScheme(): void
    {
        $uri = $this->uriFor('mysql:host=db.example.com;port=3306;dbname=shop', 'appuser', 'secret');

        $this->assertStringStartsWith('mysql://', $uri);
    }

    public function testUriParsesToTheRemoteHostNotLocalhost(): void
    {
        $uri = $this->uriFor('mysql:host=db.example.com;port=3307;dbname=shop', 'appuser', 'secret');
        $parts = parse_url($uri);

        $this->assertSame('mysql', $parts['scheme'] ?? null);
        $this->assertSame('db.example.com', $parts['host'] ?? null, 'host must survive — not default to localhost');
        $this->assertSame(3307, $parts['port'] ?? null);
    }

    public function testCredentialsAndDbnameRoundTripThroughUrlEncoding(): void
    {
        // react/mysql rawurldecode()s user/pass/path, so special chars must be encoded.
        $uri = $this->uriFor('mysql:host=h;port=3306;dbname=my db', 'user@corp', 'p@ss:w/rd');
        $parts = parse_url($uri);

        $this->assertSame('user@corp', rawurldecode($parts['user'] ?? ''));
        $this->assertSame('p@ss:w/rd', rawurldecode($parts['pass'] ?? ''));
        $this->assertSame('my db', rawurldecode(ltrim($parts['path'] ?? '', '/')));
    }
}
