<?php

declare(strict_types=1);

namespace SugarCraft\Animation\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\Frame;
use SugarCraft\Animation\Player;
use SugarCraft\Animation\Renderer;
use SugarCraft\Animation\TickMsg;
use SugarCraft\Animation\WatchMsg;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\TickRequest;

/**
 * E-10: the watch seam of {@see Player} — poll chain, in-place rebind, and
 * the reload hygiene rules (empty/absent reloads never touch the display;
 * policy survives a swap; position and laps reset with the file).
 *
 * No real timers and no sleeping: every scenario dispatches WatchMsg by
 * hand, exactly like the fake clock drives the E-03 schedule, so the
 * WATCH_POLL_SECONDS cadence is pinned by value, never by wall time.
 */
final class PlayerWatchTest extends TestCase
{
    private float $fakeNow = 0.0;

    /**
     * @return list<Frame>
     */
    private function frames(int $delay, int $count): array
    {
        $frames = [];
        for ($i = 0; $i < $count; $i++) {
            $frames[] = Frame::new(cells: ['xx'], delay: $delay);
        }

        return $frames;
    }

    /**
     * @param list<Frame> $frames
     */
    private function player(array $frames): Player
    {
        return new Player($frames, now: fn (): float => $this->fakeNow);
    }

    /**
     * SA-P4 (audit 011, arm -throw): a throwing probe keeps the pump alive —
     * display untouched, chain re-armed, and the next poll runs again.
     */
    public function testThrowingProbeKeepsDisplayAndChain(): void
    {
        $calls = (object) ['n' => 0];
        $probe = static function () use ($calls): ?array {
            $calls->n++;
            throw new \RuntimeException('probe exploded');
        };
        $player = new Player($this->frames(10, 3), now: fn (): float => $this->fakeNow, watch: $probe);

        [$next, $cmd] = $player->update(new WatchMsg());

        self::assertSame($player, $next, 'a throwing poll must not rebind anything');
        self::assertNotNull($cmd, 'the chain must re-arm despite the throw');
        self::assertSame(1, $calls->n);

        // The pump survives to poll again.
        [$next2, ] = $next->update(new WatchMsg());
        self::assertSame(2, $calls->n, 'the watch chain continued past the throw');
        self::assertSame($next, $next2);
    }

    /**
     * SA-P4 (audit 011, arm -loops): a non-positive loop count from a host
     * probe reads as forever — never handed raw to the throwing withLoops()
     * door, never silently rewritten to one play.
     */
    public function testNonPositiveLoopBoundFromProbeReadsAsForever(): void
    {
        $fresh = $this->frames(20, 5);
        $probe = static fn (): array => ['frames' => $fresh, 'loops' => 0];
        $player = new Player($this->frames(10, 3), now: fn (): float => $this->fakeNow, watch: $probe);

        [$next, ] = $player->update(new WatchMsg());

        self::assertNull($next->loops, 'raw 0 means forever (Netscape law)');
        self::assertCount(5, $next->frames, 'the frame swap itself still lands');
    }

    /**
     * SA-P4 (audit 018): unchanged-poll coalescing — a memoised probe that
     * re-answers with the IDENTICAL stream re-arms without a rebind: index,
     * epoch and display carry on; only real change restarts playback.
     */
    public function testMemoisedUnchangedStreamDoesNotRebind(): void
    {
        $stream = $this->frames(10, 3);
        $probe = static fn (): array => ['frames' => $stream, 'loops' => null];
        $player = new Player($stream, now: fn (): float => $this->fakeNow, watch: $probe);

        // Walk onto frame 2 so a spurious rebind (index -> 0) is visible.
        $this->fakeNow += 0.1;
        [$walked, ] = $player->update(new TickMsg());
        self::assertSame(1, $walked->index, 'fixture walked');

        [$held, $cmd] = $walked->update(new WatchMsg());

        self::assertSame($walked, $held, 'an identical re-answer must not rebind');
        self::assertSame(1, $held->index, 'position survives the unchanged poll');
        self::assertNotNull($cmd, 'the 10 ms chain still re-arms (stat keeps running)');
    }

    /**
     * A probe replaying a script: each entry is returned in order, the last
     * one repeats forever. Counts invocations.
     *
     * @param list<array{frames: list<Frame>, loops: ?int}|null> $script
     *
     * @return array{0: \Closure():?(array{frames: list<Frame>, loops: ?int}), 1: \stdClass}
     */
    private function scriptedProbe(array $script): array
    {
        $calls = (object) ['n' => 0];
        $probe = static function () use ($script, $calls): ?array {
            $result = $script[min($calls->n, count($script) - 1)];
            $calls->n++;

            return $result;
        };

        return [$probe, $calls];
    }

    private function requestOf(?\Closure $cmd): ?TickRequest
    {
        if ($cmd === null) {
            return null;
        }
        $msg = $cmd();
        $this->assertInstanceOf(TickRequest::class, $msg);

        /** @var TickRequest $msg */
        return $msg;
    }

    public function testUnArmedPlayerIgnoresWatchMsg(): void
    {
        $player = $this->player($this->frames(25, 3));
        [$same, $cmd] = $player->update(new WatchMsg());
        $this->assertSame($player, $same, 'an unwatched Player must not even run the absent-probe path');
        $this->assertNull($cmd);
    }

    public function testInitWithoutWatchKeepsTheLegacySingleArm(): void
    {
        $player = $this->player($this->frames(25, 3));
        $request = $this->requestOf($player->init());
        $this->assertNotNull($request, 'a playing Player arms its schedule on init');
        $this->assertSame(0.25, $request->seconds);
    }

    public function testInitWithWatchBatchesScheduleAndPoll(): void
    {
        [$probe, ] = $this->scriptedProbe([null]);
        $player = $this->player($this->frames(25, 3))->withWatch($probe);
        $msg = $player->init();
        $this->assertNotNull($msg);
        $batch = $msg();
        $this->assertInstanceOf(BatchMsg::class, $batch);
        $this->assertCount(2, $batch->cmds, 'init arms exactly two chains: the frame schedule and the watch poll');

        $requests = [];
        foreach ($batch->cmds as $cmd) {
            $produced = $cmd();
            $this->assertInstanceOf(TickRequest::class, $produced);
            $requests[] = $produced;
        }
        $this->assertSame(0.25, $requests[0]->seconds, 'schedule arm unchanged by the watch');
        $this->assertSame(Player::WATCH_POLL_SECONDS, $requests[1]->seconds, 'the poll rides the chicle 10 ms cadence, armed not slept');
        $this->assertInstanceOf(WatchMsg::class, ($requests[1]->produce)());
    }

    public function testInitWhilePausedWithWatchArmsOnlyThePoll(): void
    {
        [$probe, ] = $this->scriptedProbe([null]);
        $player = $this->player($this->frames(25, 3))->withWatch($probe);
        [$paused] = $player->update(new KeyMsg(KeyType::Space, ' '));
        $msg = $paused->init();
        $this->assertNotNull($msg, 'a paused watcher still polls — a new file must still land');
        $this->assertInstanceOf(TickRequest::class, $msg());
    }

    public function testNoChangePollKeepsPlayerAndReArms(): void
    {
        [$probe, $calls] = $this->scriptedProbe([null]);
        $player = $this->player($this->frames(25, 3))->withWatch($probe);

        [$same, $cmd] = $player->update(new WatchMsg());
        $this->assertSame($player, $same);
        $request = $this->requestOf($cmd);
        $this->assertNotNull($request);
        $this->assertSame(Player::WATCH_POLL_SECONDS, $request->seconds);
        $this->assertSame(1, $calls->n, 'one poll, one probe run');

        [$same2, $cmd2] = $same->update(new WatchMsg());
        $this->assertSame($player, $same2, 'the chain must be pure re-arming, never an accidental rebuild');
        $this->assertNotNull($cmd2);
        $this->assertSame(2, $calls->n);
    }

    public function testReloadRebindsResetsPositionAndReDerivesLoops(): void
    {
        $second = $this->frames(50, 5);
        [$probe, ] = $this->scriptedProbe([
            null,
            ['frames' => $second, 'loops' => 2],
        ]);
        $player = $this->player($this->frames(25, 3))
            ->withLoops(7)
            ->withFixedFps(4.0)
            ->withMaxSeconds(30.0)
            ->withWatch($probe);

        // One poll at index 0 (no change), then the change lands.
        [$player, $cmd] = $player->update(new WatchMsg());
        $this->assertNotNull($cmd);

        // Step by hand so the swap demonstrably resets the index too.
        [$player, ] = $player->update(new KeyMsg(KeyType::Right, ''));
        $this->assertSame(1, $player->index);

        [$next, $cmd] = $player->update(new WatchMsg());
        $request = $this->requestOf($cmd);
        $this->assertNotNull($request, 'the chain survives the reload for the next change');
        $this->assertSame(Player::WATCH_POLL_SECONDS, $request->seconds);

        $this->assertNotSame($player, $next);
        $this->assertCount(5, $next->frames, 'the new stream is in place');
        $this->assertSame(0, $next->index, 'position restarts with the file');
        $this->assertSame(2, $next->loops, 'the loop bound re-derives from the reload, it is not carried policy');
        $this->assertSame(4.0, $next->fixedFps, 'cadence survives the swap');
        $this->assertSame(30.0, $next->maxSeconds, 'duration bound survives the swap');

        // The epoch re-anchored with the null anchor: the first tick after
        // the reload anchors the new stream by the E-03 next-arrival law
        // (fixedFps 4 -> 0.25 s period), and playback walks the NEW frames.
        $this->fakeNow += 0.25;
        [$after, ] = $next->update(new TickMsg());
        $this->assertSame(1, $after->index, 'the reloaded stream plays');
        $this->assertCount(5, $after->frames);
    }

    public function testEmptyReloadNeverTouchesTheDisplay(): void
    {
        // chicle's failed-load guard: a GIF caught mid-rewrite decodes to
        // nothing; the watcher must keep the last good stream playing, not
        // flash "(no frames)" and not clobber the loop bound.
        [$probe, ] = $this->scriptedProbe([['frames' => [], 'loops' => 5]]);
        $player = $this->player($this->frames(25, 3))->withWatch($probe);
        [$same, $cmd] = $player->update(new WatchMsg());
        $this->assertSame($player, $same);
        $this->assertSame(3, count($same->frames));
        $this->assertNull($same->loops, 'loops only change when a stream actually lands');
        $request = $this->requestOf($cmd);
        $this->assertNotNull($request);
        $this->assertSame(Player::WATCH_POLL_SECONDS, $request->seconds, 'a bad file keeps being watched — the write may still be landing');
    }

    public function testWatchSurvivesReloadAndWithFramesKeepsLoopsWithoutProbe(): void
    {
        $probeCount = (object) ['n' => 0];
        $probe = static function () use ($probeCount): ?array {
            $probeCount->n++;

            return null;
        };
        $player = $this->player($this->frames(25, 3))->withLoops(4)->withWatch($probe);

        $publicSwap = $player->withFrames($this->frames(75, 2));
        $this->assertSame(4, $publicSwap->loops, 'a bare withFrames() keeps the loop bound — only a watch reload re-derives it from bytes');
        $this->assertCount(2, $publicSwap->frames);
        $this->assertSame(0, $publicSwap->index);

        [$after, $cmd] = $player->update(new WatchMsg());
        $this->assertNotNull($cmd, 'the seam rides the mutate copy — reloads never lose the watcher');
        $this->assertSame(1, $probeCount->n);
    }

    public function testPausedWatcherStillSwapsFrames(): void
    {
        [$probe, ] = $this->scriptedProbe([['frames' => $this->frames(50, 6), 'loops' => null]]);
        $player = $this->player($this->frames(25, 3))->withWatch($probe);
        [$paused, ] = $player->update(new KeyMsg(KeyType::Space, ' '));
        [$next, $cmd] = $paused->update(new WatchMsg());
        $this->assertTrue($next->paused, 'a reload must not silently resume a hand-paused player');
        $this->assertCount(6, $next->frames);
        $this->assertNull($next->loops);
        $this->assertNotNull($cmd);
    }

    public function testPresetSurvivesTheSwap(): void
    {
        [$probe, ] = $this->scriptedProbe([['frames' => $this->frames(25, 2), 'loops' => null]]);
        $player = $this->player($this->frames(25, 3))
            ->withWatch($probe)
            ->update(new KeyMsg(KeyType::Char, 'd'))[0];
        $this->assertSame(Renderer::PRESET_DENSITY, $player->preset);
        [$next, ] = $player->update(new WatchMsg());
        $this->assertSame(Renderer::PRESET_DENSITY, $next->preset, 'display choice is session policy, not file state');
    }
}
