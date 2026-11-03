<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Expect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Expect;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Tests\Support\PtyCapability;

/**
 * M8 pins: the send-stall budget must actually bite. A bare fwrite(2)
 * against a BLOCKING master whose queue is full sleeps inside the kernel
 * and no userspace deadline can observe it — so send() now borrows the
 * pump pattern: a temporary non-blocking window governed by a real
 * write-readiness select, with the caller's blocking mode restored in a
 * finally. Pre-fix, the flood test below parked in the kernel instead of
 * failing (which is precisely the fiction the review named).
 */
final class ExpectSendStallTest extends TestCase
{
    private ?PosixPtySystem $system = null;

    protected function setUp(): void
    {
        if (!PtyCapability::ptySyscallsUsable()) {
            $this->markTestSkipped('needs usable FFI with working openpty()/ptmx');
        }
        $this->system = new PosixPtySystem();
    }

    protected function tearDown(): void
    {
        $this->system = null;
    }

    private function reap(Child $child): void
    {
        $child->kill(Child::SIGTERM);
        $deadline = \microtime(true) + 2.0;
        $reaped = false;
        while (\microtime(true) < $deadline) {
            if ($child->exited()) {
                $reaped = true;
                break;
            }
            \usleep(20_000);
        }
        if (!$reaped) {
            $child->kill(Child::SIGKILL);
            $child->wait();
        }
    }

    public function testFloodIntoANonReadingChildFailsLoudWithinBudget(): void
    {
        $pair = $this->system->open();
        $master = $pair->master();
        // The hang shape M8 names: a blocking master nobody drains.
        \stream_set_blocking($master->stream(), true);
        $child = $pair->slave()->spawn(['/bin/sh', '-c', "stty raw -echo; exec sleep 30"]);

        try {
            $expect = Expect::on($master);
            $start = \microtime(true);

            try {
                $expect->send(\str_repeat("A", 262_144));
                self::fail('the flood should have exhausted the stall budget');
            } catch (\RuntimeException $stalled) {
                self::assertStringContainsString('no progress', $stalled->getMessage());
            }

            self::assertLessThan(
                5.0,
                \microtime(true) - $start,
                'the stall diagnosis must arrive on the budget, not when the queue frees',
            );
            self::assertTrue(
                \stream_get_meta_data($master->stream())['blocked'],
                'the caller blocking mode must come back intact after a failed send',
            );
        } finally {
            $this->reap($child);
            $master->close();
        }
    }

    public function testSmallSendOnABlockingMasterSucceedsAndRestoresMode(): void
    {
        // Discriminating twin: a handful of bytes fits the queue, the
        // select reports ready immediately, and send() must complete
        // without tripping the stall machinery. If the readiness arm
        // wrongly refused ready masters, this is the pin that reddens.
        $pair = $this->system->open();
        $master = $pair->master();
        \stream_set_blocking($master->stream(), true);
        $child = $pair->slave()->spawn(['/bin/sh', '-c', "stty raw -echo; exec sleep 30"]);

        try {
            $expect = Expect::on($master);
            $start = \microtime(true);
            $after = $expect->send('ping');

            self::assertLessThan(1.0, \microtime(true) - $start);
            self::assertSame('', $after->buffer);
            self::assertTrue(\stream_get_meta_data($master->stream())['blocked']);
        } finally {
            $this->reap($child);
            $master->close();
        }
    }

    public function testAlreadyNonBlockingMastersAreLeftUntouched(): void
    {
        // The flip must be conditional: a master the caller (or a pump)
        // already set non-blocking must not come back switched to
        // blocking on the far side of a send.
        $pair = $this->system->open();
        $master = $pair->master();
        \stream_set_blocking($master->stream(), false);
        $child = $pair->slave()->spawn(['/bin/sh', '-c', "stty raw -echo; exec sleep 30"]);

        try {
            Expect::on($master)->send('tiny');
            self::assertFalse(\stream_get_meta_data($master->stream())['blocked']);
        } finally {
            $this->reap($child);
            $master->close();
        }
    }
}
