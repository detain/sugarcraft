<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\RuntimeNoticePumpMsg;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\Msg\FocusGainedMsg;

/**
 * THE MID-SESSION SEAM'S WAKE-UP IS A HOST OBLIGATION, NOT A `Chat` FEATURE
 * (E223).
 *
 * E193 gave the seam an edge-driven wake-up armed from {@see Chat::init()}.
 * `init()` is part of the `SugarCraft\Core\Model` contract, and a `Chat`
 * driven by an embedder that never runs the Cmd it hands back never arms the
 * watcher — it is back to "the notice waits for whatever `Msg` the host
 * happens to deliver next". That is no worse than before E193 and is not a
 * regression; it was simply written down nowhere, in `src/` or here.
 *
 * THE ENTRY SAYS "ONLY `Program` CALLS IT" AND THAT IS FALSE, MEASURED by
 * grepping `src/` and `bin/` for the call: there are TWO in-tree callers.
 * `SugarCraft\Core\Program::run()` is one, and
 * {@see \SugarCraft\Crush\App\App::init()} — the hosted SHELL, i.e. the
 * very "hosted-pane shape" the entry names as the gap — is the other. It
 * batches `$this->chat?->init()` with its own startup query and passes
 * `Chat::update()`'s Cmd straight through. So the in-tree hosted pane is
 * already correct, and what is actually unpinned is that it STAYS correct,
 * plus the contract for an embedder outside this tree. Both are below.
 *
 * WHY THE FIX IS A DOCUMENTED OBLIGATION AND NOT A NEW METHOD. Everything a
 * host needs is already public on the `Model` interface: `init()` hands back
 * the arming `Cmd`, and `update()` hands back the RE-arming one alongside the
 * drained transcript. Adding a `Chat::runtimeNoticeWake()` accessor would
 * expose a second door onto the same `Cmd` and give a host two ways to do it,
 * one of which skips whatever `init()` grows next. The gap was never a missing
 * capability; it was a missing sentence, which now lives on `init()`.
 *
 * THIS FILE IS THE OTHER HALF, because a doc-block alone is a "documented
 * seam" whose dormancy is still unpinned. It drives the whole host loop with
 * no `Program` anywhere: nothing armed until `init()`'s Cmd is run, a notice
 * delivered when the pump `Msg` comes back, and a re-arm handed out with it.
 */
final class HostedRuntimeNoticeWakeTest extends TestCase
{
    protected function setUp(): void
    {
        RuntimeNoticeSink::reset();
    }

    protected function tearDown(): void
    {
        // reset() disarms the read-stream watcher as well as dropping the
        // inbox, so nothing this file installs survives onto the shared loop.
        RuntimeNoticeSink::reset();
    }

    /**
     * THE DORMANCY, STATED AS A TEST: a hosted `Chat` that never runs
     * `init()`'s Cmd arms nothing, and no amount of `update()` traffic changes
     * that.
     *
     * The stray `FocusGainedMsg` is the point rather than decoration — "the
     * next Msg the host happens to deliver" is exactly the thing E193 stopped
     * the seam waiting for on the `Program` path, and this asserts it still
     * does not arm the watcher on the hosted one. Any Msg `Chat::update()`
     * does not act on would do; this one is chosen because it carries no
     * payload to get wrong.
     */
    public function testAHostThatNeverRunsInitsCmdLeavesTheWatcherUnarmed(): void
    {
        self::assertTrue(RuntimeNoticeSink::arm(), 'no cross-fork transport; this test would be vacuous');

        $chat = self::hostedOwner();
        self::assertFalse(RuntimeNoticeSink::isNotificationArmed());

        [$chat] = $chat->update(new FocusGainedMsg());
        self::assertInstanceOf(Chat::class, $chat);
        self::assertFalse(
            RuntimeNoticeSink::isNotificationArmed(),
            'something other than init() armed the wake-up, so the obligation on init() is not the whole story',
        );
    }

    /**
     * AND THE OBLIGATION DISCHARGED: run `init()`'s Cmd and the watcher is on.
     *
     * The Cmd is a `\Closure` returning a promise — the shape
     * `SugarCraft\Core\Program` invokes — so a host calls it exactly like
     * this. The loop is never run here on purpose: arming is what `init()`
     * buys, and the delivery half is the test below, which does not need a
     * loop either because the sink's transport is a socket pair this process
     * owns both ends of.
     */
    public function testRunningInitsCmdArmsTheWatcherWithNoProgramInvolved(): void
    {
        self::assertTrue(RuntimeNoticeSink::arm());

        $cmd = self::hostedOwner()->init();

        self::assertInstanceOf(\Closure::class, $cmd, 'init() handed a host nothing to arm the seam with');
        $cmd();

        self::assertTrue(RuntimeNoticeSink::isNotificationArmed());
    }

    /**
     * THE STEADY STATE: the pump `Msg` drains into the transcript AND hands
     * back a re-arm, so a host that keeps running what `update()` returns
     * keeps the seam awake.
     *
     * Without the re-arm assertion this test would pass on a one-shot wake-up
     * that delivers the first notice of a session and sleeps through every
     * one after it — which is the failure a host would find hardest to
     * attribute.
     */
    public function testThePumpMsgDeliversTheNoticeAndHandsBackARearm(): void
    {
        self::assertTrue(RuntimeNoticeSink::arm());
        self::assertTrue(RuntimeNoticeSink::record('a parser declined to fire'));

        [$after, $rearm] = self::hostedOwner()->update(new RuntimeNoticePumpMsg());

        self::assertInstanceOf(Chat::class, $after);
        $rendered = array_map(static fn (object $m): string => (string) $m->content, $after->history);
        self::assertContains('a parser declined to fire', $rendered);

        self::assertInstanceOf(\Closure::class, $rearm, 'the seam went to sleep after one notice');
    }

    /**
     * THE NEGATIVE THE THREE ABOVE NEED (rule 15): with no transport open,
     * `init()` hands back NOTHING and that is correct rather than broken.
     *
     * `null` here is the sink on its in-process array backend, which only this
     * process can write to and only synchronously — see
     * {@see Chat::init()}. Without this row, an `init()` that returned null
     * unconditionally would satisfy the dormancy test above and be caught only
     * by the arming one, which is a single assertion away from the same hole.
     */
    public function testWithNoTransportOpenInitHandsAHostNothingToArm(): void
    {
        self::assertFalse(RuntimeNoticeSink::hasTransport());

        self::assertNull(self::hostedOwner()->init());
    }

    /**
     * THE IN-TREE HOST DISCHARGES PART ONE, AND MUST KEEP DOING SO.
     *
     * {@see \SugarCraft\Crush\App\App::init()} batches the hosted `Chat`'s
     * startup Cmd with its own. `SugarCraft\Core\Cmd::batch()` drops nulls, so
     * deleting `$this->chat?->init()` from that batch leaves a shell that still
     * starts, still paints, and silently never arms the seam — no test anywhere
     * asserted otherwise before this one.
     *
     * AND THE FAN-OUT IS THE HOST'S JOB TOO, which is a second obligation and
     * was NOT obvious: `Cmd::batch()` does not run its children. It returns a
     * Cmd yielding a `SugarCraft\Core\BatchMsg` that CARRIES them, and
     * `Program::runCmd()` is what explodes it. A host embedding `App` rather
     * than `Chat` therefore has to walk that sentinel or the arming Cmd is
     * constructed and never invoked. This test walks it exactly as a host
     * must, which is why the walk is here rather than hidden in a helper.
     */
    public function testTheHostedShellForwardsTheChatsStartupCmd(): void
    {
        self::assertTrue(RuntimeNoticeSink::arm());

        $app = App::new($this->createMock(ProviderInterface::class), 'gpt-4')
            ->withChat(self::hostedOwner());

        $cmd = $app->init();
        self::assertInstanceOf(\Closure::class, $cmd);

        $batch = $cmd();
        self::assertInstanceOf(BatchMsg::class, $batch, 'App::init() stopped batching; the walk below is '
            . 'checking something other than what a host would do');
        foreach ($batch->cmds as $child) {
            $child();
        }

        self::assertTrue(
            RuntimeNoticeSink::isNotificationArmed(),
            "App::init() no longer runs the hosted Chat's startup Cmd, so a shell-hosted session is back to "
            . 'waiting for the next keystroke to notice a mid-session notice',
        );
    }

    /**
     * A `Chat` appointed as this process's drain owner, built directly — no
     * `Bootstrap`, no `Program`, which is the hosted shape this file is about.
     *
     * `drainsRuntimeNotices` defaults to false and `drain()` is destructive,
     * so the appointment has to be explicit here exactly as it is in
     * {@see \SugarCraft\Crush\Cli\Bootstrap::chat()}.
     */
    private static function hostedOwner(): Chat
    {
        return new Chat(drainsRuntimeNotices: true);
    }
}
