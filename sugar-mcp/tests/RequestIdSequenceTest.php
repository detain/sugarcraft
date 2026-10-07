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
        $ids = new RequestIdSequence(
            static function () use (&$pid): int {
                return $pid;
            },
            ownerAlive: static fn (int $ownerPid): bool => false, // the old owner died: adoption is legal
        );
        self::assertSame(0, $ids->next());

        $pid = 200;
        self::assertTrue($ids->claim());

        self::assertSame(200, $ids->ownerPid());
        self::assertTrue($ids->isOwner());
        self::assertSame(1, $ids->next(), 'the owner counter continues, it is never reset');
    }

    /**
     * FIX #5: the fork-collision shape — parent (owner) alive, child re-claims
     * the same sequence object. The claim must be REFUSED, ownership stay put,
     * and the child keep minting pid-tagged nonce ids so it can never collide
     * with the live owner's plain counters on the shared connection.
     */
    public function testAClaimAgainstALiveOwnerIsRefusedAndTheClaimerStaysForeign(): void
    {
        $pid = 100;
        $ids = new RequestIdSequence(
            static function () use (&$pid): int {
                return $pid;
            },
            ownerAlive: static fn (int $ownerPid): bool => true, // pid 100 is running
        );
        self::assertSame(0, $ids->next());

        $pid = 200;
        self::assertFalse($ids->claim(), 'a live owner was displaced by a re-claim');
        self::assertSame(100, $ids->ownerPid(), 'the refused claim moved ownership anyway');
        self::assertFalse($ids->isOwner());

        $foreign = $ids->next();
        self::assertIsString($foreign);
        self::assertMatchesRegularExpression('/^200-[0-9a-f]{12}-0$/', $foreign);
        self::assertNotSame(1, $foreign, 'the foreign pid does not resume the owner counter it failed to take');
    }

    /**
     * A same-pid claim is the restart path (stop()/start() in the owner) and
     * must never probe liveness — the owner asking about ITSELF is not a
     * collision no matter what a stale pid table says.
     */
    public function testASamePidClaimSucceedsWithoutProbingTheOwner(): void
    {
        $probes = 0;
        $ids = new RequestIdSequence(
            static fn (): int => 100,
            ownerAlive: static function (int $ownerPid) use (&$probes): bool {
                $probes++;

                return true; // would refuse, if a same-pid claim ever asked
            },
        );

        self::assertTrue($ids->claim());
        self::assertSame(0, $probes, 'a same-pid claim probed the owner it already is');
    }
}
