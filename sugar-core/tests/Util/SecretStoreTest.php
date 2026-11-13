<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\SecretStore;

final class SecretStoreTest extends TestCase
{
    protected function setUp(): void
    {
        SecretStore::resetForTesting();
        SecretStore::armOsForTesting('Linux');
    }

    protected function tearDown(): void
    {
        SecretStore::resetForTesting();
    }

    public function testArgvCarriesOnlyServiceAndEntryPerFamily(): void
    {
        self::assertSame(
            ['secret-tool', 'lookup', 'service', 'svc', 'account', 'ent'],
            SecretStore::argvFor('Linux', 'svc', 'ent'),
        );
        self::assertSame(
            ['security', 'find-generic-password', '-s', 'svc', '-a', 'ent', '-w'],
            SecretStore::argvFor('Darwin', 'svc', 'ent'),
        );
        self::assertNull(SecretStore::argvFor('Windows', 'svc', 'ent'));
    }

    public function testSupportedFollowsTheOsFamily(): void
    {
        self::assertTrue(SecretStore::supported());
        SecretStore::armOsForTesting('Darwin');
        self::assertTrue(SecretStore::supported());
        SecretStore::armOsForTesting('Windows');
        self::assertFalse(SecretStore::supported());
        self::assertSame('Windows', SecretStore::osFamily());
    }

    public function testHitIsReturnedAndMemoised(): void
    {
        $spawns = 0;
        SecretStore::armSpawnSeamForTesting(function (array $argv) use (&$spawns): array {
            $spawns++;
            self::assertSame('ent', $argv[5]);

            return ['status' => 'hit', 'stdout' => 'sekrit', 'exitCode' => 0];
        });
        self::assertSame('sekrit', SecretStore::lookup('svc', 'ent'));
        self::assertSame('sekrit', SecretStore::lookup('svc', 'ent'));
        self::assertSame(1, $spawns);
        self::assertSame(['secret' => 'sekrit', 'status' => 'hit'], SecretStore::lookupDetailed('svc', 'ent'));
    }

    public function testMissIsMemoisedToo(): void
    {
        $spawns = 0;
        SecretStore::armSpawnSeamForTesting(function () use (&$spawns): array {
            $spawns++;

            return ['status' => 'miss', 'stdout' => '', 'exitCode' => 1];
        });
        self::assertNull(SecretStore::lookup('svc', 'ent'));
        self::assertNull(SecretStore::lookup('svc', 'ent'));
        self::assertSame(1, $spawns);
        self::assertSame('miss', SecretStore::lookupDetailed('svc', 'ent')['status']);
    }

    public function testEntriesAreKeyedByServiceAndEntry(): void
    {
        SecretStore::armSpawnSeamForTesting(
            static fn (array $argv): array => ['status' => 'hit', 'stdout' => $argv[3] . '/' . $argv[5], 'exitCode' => 0],
        );
        self::assertSame('a/x', SecretStore::lookup('a', 'x'));
        self::assertSame('b/x', SecretStore::lookup('b', 'x'));
    }

    public function testHitWithEmptyStdoutIsAMiss(): void
    {
        SecretStore::armSpawnSeamForTesting(static fn (): array => ['status' => 'hit', 'stdout' => '', 'exitCode' => 0]);
        self::assertSame('miss', SecretStore::lookupDetailed('s', 'e')['status']);
    }

    /** @return iterable<string,array{string,string}> */
    public static function refusedNames(): iterable
    {
        yield 'empty entry' => ['svc', ''];
        yield 'empty service' => ['', 'e'];
        yield 'leading dash' => ['svc', '-u'];
        yield 'control byte' => ['svc', "a\0b"];
        yield 'newline' => ['svc', "a\nb"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedNames')]
    public function testMisreadableNamesAreRefusedWithoutSpawning(string $service, string $entry): void
    {
        SecretStore::armSpawnSeamForTesting(function (): array {
            self::fail('must not spawn');
        });
        self::assertNull(SecretStore::lookup($service, $entry));
        self::assertSame('refused', SecretStore::lookupDetailed($service, $entry)['status']);
    }

    public function testUnsupportedFamilyReportsNoBackend(): void
    {
        SecretStore::armOsForTesting('Windows');
        SecretStore::armSpawnSeamForTesting(function (): array {
            self::fail('must not spawn');
        });
        self::assertNull(SecretStore::lookup('s', 'e'));
        self::assertSame('no_backend', SecretStore::lookupDetailed('s', 'e')['status']);
    }

    public function testSeamStatusesPassThrough(): void
    {
        foreach (['missing', 'timeout'] as $status) {
            SecretStore::resetForTesting();
            SecretStore::armOsForTesting('Linux');
            SecretStore::armSpawnSeamForTesting(static fn (): array => ['status' => $status, 'stdout' => '', 'exitCode' => 1]);
            self::assertSame($status, SecretStore::lookupDetailed('s', 'e')['status']);
        }
    }

    public function testRealSpawnWithMissingBinaryIsMissingNotAWarning(): void
    {
        $oldPath = getenv('PATH');
        putenv('PATH=/nonexistent-dir');
        try {
            self::assertSame('missing', SecretStore::lookupDetailed('s', 'e')['status']);
        } finally {
            putenv('PATH=' . $oldPath);
        }
    }

    public function testRealSpawnReadsStdoutAndTimesOut(): void
    {
        $dir = sys_get_temp_dir() . '/ss-bin-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $bin = $dir . '/secret-tool';
        $oldPath = getenv('PATH');
        try {
            file_put_contents($bin, "#!/bin/sh\nprintf 'tok-%s\\n' \"\$5\"\n");
            chmod($bin, 0755);
            putenv('PATH=' . $dir . ':/usr/bin:/bin');
            self::assertSame('tok-ent', SecretStore::lookup('svc', 'ent'));

            SecretStore::resetForTesting();
            SecretStore::armOsForTesting('Linux');
            SecretStore::armTimeoutForTesting(0.3);
            file_put_contents($bin, "#!/bin/sh\nexec sleep 30\n");
            self::assertSame('timeout', SecretStore::lookupDetailed('svc', 'ent')['status']);

            SecretStore::resetForTesting();
            SecretStore::armOsForTesting('Linux');
            file_put_contents($bin, "#!/bin/sh\nexit 1\n");
            self::assertSame('miss', SecretStore::lookupDetailed('svc', 'ent')['status']);
        } finally {
            putenv('PATH=' . $oldPath);
            @unlink($bin);
            @rmdir($dir);
        }
    }
}
