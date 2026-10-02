<?php

declare(strict_types=1);

namespace SugarCraft\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mcp\RequestIdSequence;

/**
 * Process-unique JSON-RPC ids (audit B1 / AG-1), pinned through the pid seam
 * so no fork is needed: the owner keeps plain counters, every other pid gets
 * `<pid>-<nonce>-<n>`, and a reused pid never recreates an earlier id.
 */
final class RequestIdSequenceTest extends TestCase
{
    public function testTheOwnerKeepsPlainDecimalCounters(): void
    {
        $ids = new RequestIdSequence(static fn (): int => 100);

        self::assertTrue($ids->isOwner());
        self::assertSame([0, 1, 2], [$ids->next(), $ids->next(), $ids->next()]);
    }

    public function testTheFirstOwnerIdIsConfigurable(): void
    {
        $ids = new RequestIdSequence(static fn (): int => 100, firstOwnerId: 1);

        self::assertSame(1, $ids->next());
    }

    public function testAnotherPidGetsPidTaggedIdsThatNeverMatchTheOwnerSequence(): void
    {
        $pid = 100;
        $ids = new RequestIdSequence(static function () use (&$pid): int {
            return $pid;
        });
        self::assertSame(0, $ids->next());

        $pid = 200;
        self::assertFalse($ids->isOwner());
        $first = $ids->next();
        $second = $ids->next();

        self::assertIsString($first);
        self::assertMatchesRegularExpression('/^200-[0-9a-f]{12}-0$/', $first);
        self::assertMatchesRegularExpression('/^200-[0-9a-f]{12}-1$/', (string) $second);
        self::assertSame(explode('-', $first)[1], explode('-', (string) $second)[1], 'one nonce per process');
    }

    public function testAReusedPidGetsAFreshNonceSoAStaleIdIsNeverRecreated(): void
    {
        $pid = 100;
        $ids = new RequestIdSequence(static function () use (&$pid): int {
            return $pid;
        });

        $pid = 200;
        $firstLife = $ids->next();
        $pid = 300;
        $ids->next();
        $pid = 200; // the kernel handed pid 200 to a later fork
        $secondLife = $ids->next();

        self::assertNotSame($firstLife, $secondLife);
        self::assertStringStartsWith('200-', (string) $secondLife);
    }

    public function testClaimMovesOwnershipWithoutReusingIds(): void
    {
        $pid = 100;
        $ids = new RequestIdSequence(static function () use (&$pid): int {
            return $pid;
        });
        self::assertSame(0, $ids->next());

        $pid = 200;
        $ids->claim();

        self::assertSame(200, $ids->ownerPid());
        self::assertTrue($ids->isOwner());
        self::assertSame(1, $ids->next(), 'the owner counter continues, it is never reset');
    }
}
