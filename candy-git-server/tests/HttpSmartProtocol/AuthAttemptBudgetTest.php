<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\HttpSmartProtocol\AuthAttemptBudget;

/**
 * @covers \SugarCraft\GitServer\HttpSmartProtocol\AuthAttemptBudget
 */
final class AuthAttemptBudgetTest extends TestCase
{
    private string $dir;
    private float $now = 1000.0;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/auth-budget-test-' . \uniqid();
        \mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
        parent::tearDown();
    }

    private function budget(int $capacity = 3, int $refillSeconds = 60): AuthAttemptBudget
    {
        return new AuthAttemptBudget(
            $this->dir . '/auth-attempts.json',
            $capacity,
            $refillSeconds,
            fn (): float => $this->now,
        );
    }

    private function stateFile(): string
    {
        return $this->dir . '/auth-attempts.json';
    }

    public function testChargeAllowsBurstThenDeniesWithRetryAfter(): void
    {
        $budget = $this->budget(capacity: 3);

        self::assertNull($budget->charge('10.0.0.1', 'alice'));
        self::assertNull($budget->charge('10.0.0.1', 'alice'));
        self::assertNull($budget->charge('10.0.0.1', 'alice'));
        // Empty: one token costs refillSeconds/capacity = 20 s.
        self::assertSame(20, $budget->charge('10.0.0.1', 'alice'));
    }

    public function testRefillReturnsOneTokenPerSlice(): void
    {
        $budget = $this->budget(capacity: 3, refillSeconds: 60);
        $budget->charge('p', 'u');
        $budget->charge('p', 'u');
        $budget->charge('p', 'u');
        self::assertSame(20, $budget->charge('p', 'u'));

        $this->now += 20.0; // exactly one token back
        self::assertNull($budget->charge('p', 'u'));
        self::assertSame(20, $budget->charge('p', 'u'));
    }

    public function testFullWindowRestoresCapacity(): void
    {
        $budget = $this->budget(capacity: 3);
        $budget->charge('p', 'u');
        $budget->charge('p', 'u');
        $budget->charge('p', 'u');
        self::assertSame(20, $budget->charge('p', 'u'));

        $this->now += 60.0;
        self::assertNull($budget->charge('p', 'u'));
        self::assertNull($budget->charge('p', 'u'));
        self::assertNull($budget->charge('p', 'u'));
    }

    public function testRefundReturnsTheTokenAndLeavesNoStaleRecord(): void
    {
        $budget = $this->budget(capacity: 2);
        self::assertNull($budget->charge('p', 'u'));
        $budget->refund('p', 'u');

        // Full again: the refund deleted the restored buckets (absence
        // IS full), so the ledger itself is empty...
        $state = (array) \json_decode((string) \file_get_contents($this->stateFile()), true);
        self::assertCount(0, $state);
        // ...and the whole burst is available once more.
        self::assertNull($budget->charge('p', 'u'));
        self::assertNull($budget->charge('p', 'u'));
    }

    public function testPeerDimensionSpraysAcrossUsernamesButSharedBurst(): void
    {
        $budget = $this->budget(capacity: 2);
        // One peer, three different usernames: the PEER bucket is shared,
        // so rotation buys nothing beyond the burst.
        self::assertNull($budget->charge('9.9.9.9', 'alice'));
        self::assertNull($budget->charge('9.9.9.9', 'bob'));
        $denied = $budget->charge('9.9.9.9', 'carol');
        self::assertNotNull($denied, 'username rotation must not outrun the peer bucket');
    }

    public function testUsernameDimensionIsSharedAcrossPeers(): void
    {
        $budget = $this->budget(capacity: 2);
        self::assertNull($budget->charge('1.1.1.1', 'victim'));
        self::assertNull($budget->charge('2.2.2.2', 'victim'));
        // Third attacker, same username: the username bucket is empty even
        // though this peer has never been seen.
        self::assertNotNull($budget->charge('3.3.3.3', 'victim'));
    }

    public function testCorruptStateFailsOpenAsFreshBuckets(): void
    {
        \file_put_contents($this->stateFile(), '{torn json');
        $budget = $this->budget();
        self::assertNull($budget->charge('p', 'u'), 'an unreadable ledger must allow, never lock out');
    }

    public function testUnwritableStateFailsOpenAndRefundSurvives(): void
    {
        // Parent is a regular FILE: mkdir/fopen can never succeed here.
        $blocked = $this->dir . '/blocked';
        \file_put_contents($blocked, 'not-a-dir');
        $budget = new AuthAttemptBudget($blocked . '/attempts.json', 3, 60, fn (): float => $this->now);

        self::assertNull($budget->charge('p', 'u'));
        $budget->refund('p', 'u'); // must not throw
    }

    public function testClockRegressionIsTreatedAsFull(): void
    {
        $budget = $this->budget(capacity: 2);
        $budget->charge('p', 'u');
        $budget->charge('p', 'u');
        self::assertSame(30, $budget->charge('p', 'u'));

        $this->now -= 500.0; // NTP step backwards
        self::assertNull($budget->charge('p', 'u'), 'a jumped-back clock must not punish the client');
    }

    public function testRefilledBucketsArePrunedFromTheLedger(): void
    {
        $budget = $this->budget(capacity: 1, refillSeconds: 10);
        self::assertNull($budget->charge('old-peer', 'old-user'));

        $this->now += 10.0; // old bucket is full again
        self::assertNull($budget->charge('new-peer', 'new-user')); // write prunes

        $state = (array) \json_decode((string) \file_get_contents($this->stateFile()), true);
        foreach ($state as $key => $_) {
            if (\is_string($key) && \str_starts_with($key, 'peer:old-peer')) {
                self::fail('refilled-to-full bucket must be deleted, got ' . $key);
            }
        }
    }

    public function testUsernameKeyIsHashedAgainstHeaderBloat(): void
    {
        $budget = $this->budget(capacity: 1);
        $huge = \str_repeat('u', 100000);
        $budget->charge('p', $huge);
        $state = (array) \json_decode((string) \file_get_contents($this->stateFile()), true);
        foreach (\array_keys($state) as $key) {
            self::assertLessThan(200, \strlen((string) $key), 'a wire-supplied username must not land raw in the ledger');
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            @\unlink($dir . '/' . $item);
        }
        @\rmdir($dir);
    }
}
