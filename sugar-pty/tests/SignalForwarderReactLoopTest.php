<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Pty;
use SugarCraft\Pty\SignalForwarder;
use SugarCraft\Pty\Tests\Support\SharedLoopResidue;

/**
 * Confirms `SignalForwarder::attachSigwinch` does not double-handle the
 * signal when invoked from inside a ReactPHP event loop.
 *
 * The hazard this bounds: ReactPHP's loop installs its own signal
 * handling on some backends (ext-event, ext-libev, ext-uv), and naive
 * `pcntl_signal` calls can be silently overwritten or fire twice
 * depending on signal-dispatch ordering. This test pins the actual
 * combined behavior.
 *
 * Mirrors the watchdog convention from PtyPoolReactLoopTest — the test
 * is hard-bounded by the loop's own timer cap so CI cannot hang.

 */
final class SignalForwarderReactLoopTest extends TestCase
{
    use \SugarCraft\Pty\Tests\Support\RequiresPtyCapability;

    private function requirePcntlAndReact(): void
    {
        if (!SignalForwarder::pcntlReady()) {
            $this->markTestSkipped('ext-pcntl is required for SignalForwarder.');
        }
        if (!\defined('SIGWINCH')) {
            $this->markTestSkipped('SIGWINCH is not defined on this host.');
        }
        if (!\class_exists(\React\EventLoop\Loop::class)) {
            $this->markTestSkipped('react/event-loop is not installed.');
        }
    }

    protected function tearDown(): void
    {
        if (\defined('SIGWINCH')) {
            SignalForwarder::reset(SIGWINCH);
        }

        // Same obligation PtyPoolReactLoopTest carries: this is one of the
        // two files that drives the SHARED Loop:: facade, so anything still
        // armed here hijacks the next Loop::run() in the suite — a leaked
        // one-shot ends it for the wrong reason, a leaked periodic or
        // signal watcher never lets it end at all (the $timeout=null
        // stream_select leg, the E490 candidate). See SharedLoopResidue.
        $residue = SharedLoopResidue::census();

        $this->assertSame(
            ['timers' => 0, 'readStreams' => 0, 'writeStreams' => 0, 'signals' => 0, 'futureTicks' => 0],
            $residue,
            'this test left something armed on the SHARED loop: ' . SharedLoopResidue::describe()
                . ' — see the ledger in SharedLoopResidue and PtyPoolReactLoopTest.',
        );
    }

    public function testAttachSigwinchFiresExactlyOnceInsideReactLoop(): void
    {
        $this->requirePtySyscalls();
        $this->requirePcntlAndReact();

        $loop = \React\EventLoop\Loop::get();
        $pty = Pty::open();
        $invocations = 0;

        try {
            // async:true so dispatch follows the same path the loop would
            // see in a long-lived program. If the loop's own signal layer
            // double-installed, we'd see >1 here.
            $ok = SignalForwarder::attachSigwinch(
                $pty,
                function () use (&$invocations): array {
                    $invocations++;
                    return ['cols' => 120, 'rows' => 40];
                },
                async: true,
            );
            $this->assertTrue($ok);

            // Send SIGWINCH to ourselves once.
            \posix_kill(\posix_getpid(), SIGWINCH);

            // Run the loop briefly so async signal dispatch + any
            // queued futureTick callbacks get a chance to fire. The
            // exact timer is the budget; nothing in the test schedules
            // its own work past this.
            $loop->addTimer(0.05, function () use ($loop): void {
                $loop->stop();
            });
            $loop->run();

            $this->assertSame(
                1,
                $invocations,
                'attachSigwinch handler fired ' . $invocations . ' times — expected exactly 1. '
                . 'ReactPHP loop may have installed a competing pcntl_signal layer.',
            );
        } finally {
            $pty->close();
        }
    }
}
