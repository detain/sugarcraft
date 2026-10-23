<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Db\MysqlDatabase;

/**
 * Pins the sslMode → PDO driver option mapping in MysqlDatabase.
 *
 * docs-review-1023 F1 (M10): the shipped comparison was case-sensitive and
 * gave 'require' VERIFY_SERVER_CERT=false — so an uppercase 'VERIFY_CA' or a
 * plain 'require' silently negotiated encryption WITHOUT certificate checking
 * (MITM-able), while README claimed the modes were fully honoured. Both halves
 * are pinned here via the private static seam.
 */
final class MysqlSslDriverOptionsTest extends TestCase
{
    /** @return array<int, mixed> */
    private static function options(string $sslMode): array
    {
        $method = new \ReflectionMethod(MysqlDatabase::class, 'sslDriverOptions');

        return $method->invoke(null, $sslMode);
    }

    public function testPlaintextModesCarryNoSslOptions(): void
    {
        foreach (['', 'disable', 'DISABLE', 'Disable'] as $mode) {
            $this->assertSame([], self::options($mode), 'mode ' . var_export($mode, true));
        }
    }

    public function testOpportunisticModesEncryptWithoutVerification(): void
    {
        foreach (['allow', 'prefer', 'preferred', 'PREFER'] as $mode) {
            $options = self::options($mode);
            $this->assertArrayHasKey(\PDO::MYSQL_ATTR_SSL_CA, $options, 'mode ' . $mode);
            $this->assertNull($options[\PDO::MYSQL_ATTR_SSL_CA], 'mode ' . $mode);
            $this->assertFalse(
                $options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT],
                'mode ' . $mode . ' must not verify, but must still encrypt',
            );
        }
    }

    public function testRequireAndVerifyModesAlwaysVerifyTheServerCertificate(): void
    {
        $modes = [
            'require',
            'required',
            'REQUIRE',
            'Require',
            'verify_ca',
            'VERIFY_CA',
            'verify-ca',
            'VERIFY-CA',
            'verify_identity',
            'verify-identity',
            'VERIFY_IDENTITY',
        ];

        foreach ($modes as $mode) {
            $options = self::options($mode);
            $this->assertTrue(
                $options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT],
                'mode ' . $mode . ' silently downgraded to unverified TLS',
            );
            $this->assertNull($options[\PDO::MYSQL_ATTR_SSL_CA], 'mode ' . $mode);
        }
    }

    public function testUnknownModeFailsLoudInsteadOfDowngrading(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown MySQL ssl-mode: tls');

        self::options('tls');
    }
}
