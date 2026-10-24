<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Db;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Db\MysqlDatabase;

/**
 * Pins for MysqlDatabase::requiresVerifiedTls() — the security reading of
 * the ssl-mode table, consulted by the App TLS gate that refuses the
 * plaintext-capable ReactPHP transport. The family-consistency leg is the
 * point: the predicate and sslDriverOptions() must never disagree about
 * which modes DEMAND verification, or the gate guards the wrong door.
 */
final class MysqlSslPredicateTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function modeTable(): array
    {
        return [
            'empty' => ['', false],
            'disable' => ['disable', false],
            'DISABLE upper' => ['DISABLE', false],
            'allow' => ['allow', false],
            'prefer' => ['prefer', false],
            'Preferred mixed case' => ['Preferred', false],
            'require' => ['require', true],
            'required' => ['required', true],
            'verify-ca hyphen' => ['verify-ca', true],
            'verify_ca underscore' => ['verify_ca', true],
            'VERIFY_IDENTITY upper' => ['VERIFY_IDENTITY', true],
        ];
    }

    #[DataProvider('modeTable')]
    public function testPredicateMatchesTheModeTable(string $mode, bool $expected): void
    {
        $this->assertSame(
            $expected,
            MysqlDatabase::requiresVerifiedTls($mode),
            sprintf('ssl-mode %s must be %s', $mode === '' ? '(empty)' : "'$mode'", $expected ? 'TLS-demanding' : 'async-capable'),
        );
    }

    public function testUnknownModeThrowsLoudLikeTheDriverOptionBuilder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown MySQL ssl-mode: rogue');

        MysqlDatabase::requiresVerifiedTls('rogue');
    }

    /**
     * One table, two readers: for every spelling the driver options mark
     * verify-cert on, the predicate must report a TLS demand — and off for
     * every plaintext/opportunistic spelling (options absent or false).
     */
    #[DataProvider('modeTable')]
    public function testPredicateAndDriverOptionsReadOneTable(string $mode, bool $expected): void
    {
        $options = self::sslDriverOptions($mode);
        $verifyFlag = (bool) ($options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] ?? false);

        $this->assertSame(
            $verifyFlag,
            MysqlDatabase::requiresVerifiedTls($mode),
            "sslDriverOptions() and requiresVerifiedTls() diverged for '$mode'",
        );
    }

    /** @return array<int, mixed> */
    private static function sslDriverOptions(string $mode): array
    {
        $method = new \ReflectionMethod(MysqlDatabase::class, 'sslDriverOptions');

        return $method->invoke(null, $mode);
    }
}
