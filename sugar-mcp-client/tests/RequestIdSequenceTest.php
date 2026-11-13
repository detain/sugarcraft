<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\RequestIdSequence;

/**
 * Process-unique JSON-RPC ids (the fork-corruption audit), pinned through the pid seam
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
        // Pin flipped 2026-11-04 (fix wave, review N9 / tui n2): the counter
        // is never RESET, but a foreign succession now resumes FAR ahead —
        // the dead owner's plain-id window can never alias a stale reply
        // still sitting in the pipe.
        self::assertGreaterThanOrEqual(1_000_001, $ids->next(), 'the counter continues ahead of the dead owner window');
    }

    /**
     * The fork guard: the fork-collision shape — parent (owner) alive, child re-claims
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

    /**
     * The fork-claim safety spine driven through its DEFAULT probe: every
     * cross-pid test above injects the liveness answer, so the real kernel
     * contract — signal-0 with the ESRCH-alone polarity, /proc fallback —
     * was never executed. (The fail-open arm, true when neither posix nor
     * /proc can speak, is structurally untestable in-process: no suite
     * strips posix from its own runtime. The death-phase pin below exercises
     * the posix-kill or /proc arm, whichever the platform has.)
     */
    public function testTheDefaultLivenessProbeDrivesRealCrossPidClaims(): void
    {
        if (!function_exists('posix_kill') && !is_dir('/proc/self')) {
            self::markTestSkipped('the default probe has no real arm to exercise without posix or /proc');
        }

        $proc = proc_open([PHP_BINARY, '-r', 'usleep(400_000);'], [], $pipes);
        self::assertIsResource($proc);
        $childPid = (int) proc_get_status($proc)['pid'];

        $pid = $childPid; // the child "opened" the connection
        $ids = new RequestIdSequence(static function () use (&$pid): int {
            return $pid;
        });
        self::assertSame(0, $ids->next());

        $pid = (int) getmypid(); // now WE ask for it, while the owner lives
        self::assertFalse($ids->claim(), 'the default probe let a live child be displaced');
        self::assertSame($childPid, $ids->ownerPid(), 'the refused claim moved ownership anyway');

        // proc_close REAPS: a zombie would keep answering signal-0, and the
        // death below must be a real ESRCH, not a half-dead pid table entry.
        proc_close($proc);

        self::assertTrue($ids->claim(), 'the default probe never granted the dead child succession');
        self::assertSame((int) getmypid(), $ids->ownerPid());
        self::assertGreaterThanOrEqual(
            1_000_001,
            $ids->next(),
            'succession from a dead owner must resume far ahead of its plain-id window',
        );
    }
}
