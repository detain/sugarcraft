<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Backend\CommandBackend;
use SugarCraft\Code\Backend\StreamingCommandBackend;
use SugarCraft\Code\Backend\TracksActiveChildren;
use SugarCraft\Code\Message;

/**
 * CC-QA-2: command backends no longer orphan their provider trees.
 *
 * Both shell-out backends spawn through one choke point and reap through
 * their own drain loops; a quit or a hard cancel can stop the loop while the
 * child is still running. The registry + abandonActiveChildren() close that
 * gap. These pins cover the registration lifecycle, the tree walk reaching a
 * descendant, the two quit-path call sites (source order — the arms live in
 * the Chat update() family, where a full Chat harness adds nothing the
 * byte-level wiring pin cannot check), and the bin funnel's placement after
 * the keyboard release.
 */
final class CommandBackendChildAbandonTest extends TestCase
{
    /**
     * Bound for "pid is gone" polls: killTree SIGKILLs immediately, so this
     * is a scheduling margin, not a grace period.
     */
    private const DEATH_POLL_SECONDS = 2.0;

    public function testSpawningRegistersTheChildAndAbandonKillsItsTree(): void
    {
        $backend = new CommandBackend(['/bin/sleep', '31']);
        self::assertSame([], $backend->activeChildPids(), 'a fresh backend holds no children');

        // The Promise executor spawns synchronously — the child is live the
        // moment completeAsync() returns, before the loop ever ticks.
        $promise = $backend->completeAsync([Message::user('go')]);
        $pids = $backend->activeChildPids();
        self::assertCount(1, $pids, 'the spawned child is registered');
        self::assertGreaterThan(0, $pids[0]);

        self::assertSame(1, $backend->abandonActiveChildren(), 'abandon reports the one live tree');
        self::assertSame([], $backend->activeChildPids(), 'the registry drains with the kill');
        self::awaitQa2PidDeath($pids[0]);

        // Let the backend's own drain loop settle the orphaned promise so the
        // shared loop leaves no timer behind for later tests.
        $this->settleQa2Promise($promise);
    }

    public function testAbandonReachesADescendantSpawnedByTheCommand(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'qa2-descendant-');
        self::assertIsString($marker);
        // The command's own background child — the grandchild of the wrapper
        // proc_open reports — parks holding the inherited pipes. Recording
        // its pid is how this test proves the WALK (not just the direct kill)
        // reached it.
        $script = 'sleep 29 & echo $! > ' . escapeshellarg($marker) . '; wait';
        $backend = new CommandBackend(['/bin/sh', '-c', $script]);
        $promise = $backend->completeAsync([Message::user('go')]);
        $wrapperPids = $backend->activeChildPids();
        self::assertCount(1, $wrapperPids);

        $descendant = $this->awaitQa2DescendantPid($marker);

        self::assertSame(1, $backend->abandonActiveChildren());
        self::awaitQa2PidDeath($wrapperPids[0]);
        self::awaitQa2PidDeath($descendant, 'the killTree walk must reach the command child too');

        $this->settleQa2Promise($promise);
    }

    public function testCleanBlockingCompletionLeavesNothingRegistered(): void
    {
        $backend = new CommandBackend(['/bin/printf', 'hi']);
        $message = $backend->complete([Message::user('go')]);
        self::assertStringContainsString('hi', $message->content);
        self::assertSame([], $backend->activeChildPids(), 'the reap sink forgets the child it reaped');
    }

    public function testCleanStreamingCompletionLeavesNothingRegistered(): void
    {
        $backend = new StreamingCommandBackend(['/bin/printf', 'hi']);
        $message = $backend->complete([Message::user('go')]);
        self::assertStringContainsString('hi', $message->content);
        self::assertSame([], $backend->activeChildPids(), 'finish() is the streaming forget sink');
    }

    public function testStreamingBackendRegistersAndAbandonsAsync(): void
    {
        $backend = new StreamingCommandBackend(['/bin/sleep', '33']);
        $promise = $backend->completeAsync([Message::user('go')]);
        $pids = $backend->activeChildPids();
        self::assertCount(1, $pids, 'begin() registers the streaming child');

        // Async abandon: the loop is still live here, exactly as on the
        // Ctrl+C / double-Escape paths that pass async=true. SIGSTOP lands
        // synchronously; the /proc walk finishes on loop ticks, so the loop
        // must run before the death is owed.
        self::assertSame(1, $backend->abandonActiveChildren(true));
        $this->settleQa2Promise($promise);
        self::awaitQa2PidDeath($pids[0]);
        self::assertSame([], $backend->activeChildPids(), 'the async walk drains the registry at once');
    }

    public function testBothCommandBackendsCarryTheContract(): void
    {
        // Chat dispatches on the interface; an API-path backend (EngineBackend,
        // the summariser) must not accidentally claim it, so the interface
        // list itself is the pin — the guard in Chat can only ever see these.
        self::assertInstanceOf(TracksActiveChildren::class, new CommandBackend(['/bin/true']));
        self::assertInstanceOf(TracksActiveChildren::class, new StreamingCommandBackend(['/bin/true']));
    }

    public function testQuitFunnelsAbandonAfterTheKeyboardRelease(): void
    {
        $bin = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/candy-code');
        $funnel = substr_count($bin, 'abandonActiveBackendChildren');
        self::assertSame(1, $funnel, 'the exit funnel carries exactly one safety-net call');

        // Order law: after the keyboard release (the terminal must be sane
        // before anything else writes), before the detached SessionEnd
        // funnel (the announce rides the dying process's own channel).
        $popAt = strpos($bin, 'fwrite(STDOUT, Ansi::popKittyKeyboard());');
        $abandonAt = strpos($bin, '$finalChat->abandonActiveBackendChildren();');
        $sessionEndAt = strpos($bin, 'NonInteractive::fireSessionEndDetached(');
        self::assertIsInt($popAt);
        self::assertIsInt($abandonAt);
        self::assertIsInt($sessionEndAt);
        self::assertGreaterThan($popAt, $abandonAt, 'the release is written first');
        self::assertLessThan($sessionEndAt, $abandonAt, 'the kill lands before the detached hooks fire');
    }

    public function testInterruptArmsAbandonBeforeQuitAndCancel(): void
    {
        $keys = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Chat/Handlers/HandlesKeys.php');

        // Ctrl+C: abandon, then Cmd::quit() — and quit still returns once.
        self::assertSame(1, substr_count($keys, 'return [$this, Cmd::quit()];'), 'the quit arm is untouched in shape');
        $quitAt = strpos($keys, 'return [$this, Cmd::quit()];');
        $armAt = strpos($keys, '$this->abandonActiveBackendChildren(true);');
        self::assertIsInt($quitAt);
        self::assertIsInt($armAt);
        self::assertLessThan($quitAt, $armAt, 'Ctrl+C abandons before quitting');
        self::assertSame(2, substr_count($keys, 'abandonActiveBackendChildren(true)'), 'both interrupt arms call it');

        // Double-Escape: the token cancel still comes first (backends that
        // poll it should reap their own child); abandon follows.
        $cancelAt = strpos($keys, '$this->inFlightCancellation?->cancel();');
        self::assertIsInt($cancelAt);
        $escapes = preg_split('/\$this->inFlightCancellation\?->cancel\(\);/', $keys);
        self::assertIsArray($escapes);
        $parts = count($escapes);
        $afterCancel = 0;
        for ($i = 1; $i < $parts; $i++) {
            if (str_contains(substr((string) $escapes[$i], 0, 600), 'abandonActiveBackendChildren')) {
                $afterCancel++;
            }
        }
        self::assertGreaterThanOrEqual(1, $afterCancel, 'an abandon follows the hard cancel');
    }

    /**
     * Poll until the pid stops answering signals. A killed-unreaped child
     * stays a zombie while a parent holds it, and kill(0) answers zombies —
     * the backends' own reapers do not run here, so "gone" means "it has
     * left the process table entirely" only when no parent waits; on this
     * box the direct child is reaped by our proc_close in the drain loop,
     * and the descendant reparents to init. Either way the poll is bounded.
     */
    private function awaitQa2PidDeath(int $pid, string $what = 'the registered child'): void
    {
        $deadline = microtime(true) + self::DEATH_POLL_SECONDS;
        while (microtime(true) < $deadline) {
            $stat = @file_get_contents('/proc/' . $pid . '/stat');
            if ($stat === false) {
                return; // fully reaped
            }
            // An abandoned kill leaves a zombie until the backend's own
            // drain reaps it: field 3 after the parenthesised comm is the
            // state, and Z means dead. kill(pid, 0) lies for zombies.
            $after = substr(strrchr($stat, ')'), 2);
            $state = explode(' ', $after)[0] ?? '';
            if ($state === 'Z') {
                return;
            }
            usleep(20000);
        }
        self::fail($what . ' pid ' . $pid . ' was still a live process after ' . self::DEATH_POLL_SECONDS . 's');
    }

    private function awaitQa2DescendantPid(string $marker): int
    {
        $deadline = microtime(true) + self::DEATH_POLL_SECONDS;
        while (microtime(true) < $deadline) {
            $pid = (int) trim((string) file_get_contents($marker));
            if ($pid > 0) {
                return $pid;
            }
            usleep(20000);
        }
        self::fail('the command never recorded its background child pid');
    }

    /**
     * Run the shared loop only long enough for the backend's own drain to
     * notice its child died and settle — keeping no timer armed after the
     * test, which would poison every loop-touching suite beside this one.
     */
    private function settleQa2Promise(\React\Promise\PromiseInterface $promise): void
    {
        $done = false;
        $promise->then(
            static function () use (&$done): void { $done = true; },
            static function () use (&$done): void { $done = true; },
        );
        $loop = Loop::get();
        $loop->addTimer(0.4, static function () use ($loop): void { $loop->stop(); });
        $loop->run();
        self::assertTrue($done, 'the abandoned turn settles once its drain loop ticks');
    }
}
