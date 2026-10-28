<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Events\ToolProgress;
use SugarCraft\Code\Tools\BuiltIn\Bash;

/**
 * The end-to-end ring (WEB-4): a real child writing to the real capture loop
 * must land live tail frames on the emitter, bounded by the budget, and a
 * Bash without an emitter (or without a call id) must run exactly as before -
 * the 256 KiB finished contract is untouched either way.
 */
final class BashToolProgressTest extends TestCase
{
    /** @param list<ToolProgress> $frames */
    private function emitting(array &$frames): \Closure
    {
        return static function (ToolProgress $frame) use (&$frames): void {
            $frames[] = $frame;
        };
    }

    public function testAShortRunStillFramesItsOpeningTail(): void
    {
        $frames = [];
        $bash = (new Bash())->withToolProgressEmitter($this->emitting($frames));

        $result = $bash->execute(['command' => 'printf "alpha\nbeta\n"', 'id' => 'c1']);

        $this->assertNotEmpty($frames, 'a running tool is not a silent tool');
        $this->assertSame('c1', $frames[0]->toolCallId);
        $this->assertSame('Bash', $frames[0]->toolName);
        $last = $frames[\count($frames) - 1];
        $this->assertStringContainsString('beta', $last->tail, 'the finish flush must not strand the last chunk');
        $this->assertFalse($last->truncated);
        $this->assertStringContainsString('beta', $result->content(), 'the finished contract is byte-untouched');
    }

    public function testABigRunFramesOnlyWithinTheBudget(): void
    {
        $frames = [];
        $bash = (new Bash())->withToolProgressEmitter($this->emitting($frames));
        $blob = str_repeat('streaming output line\n', 800);

        $bash->execute(['command' => 'printf "%s" ' . escapeshellarg($blob), 'id' => 'c9']);

        $this->assertNotEmpty($frames);
        $last = $frames[\count($frames) - 1];
        $this->assertLessThanOrEqual(Bash::PROGRESS_TAIL_BYTES, strlen($last->tail), 'the tail is a tail');
        $this->assertTrue($last->truncated, 'dropped bytes are announced');
        $this->assertSame(4096, $last->tailBudget);
        $seqs = array_map(static fn (ToolProgress $f): int => $f->seq, $frames);
        $this->assertSame($seqs, array_values(array_unique($seqs)), 'strictly one number per frame');
    }

    public function testNoEmitterMeansZeroFramesAndAnUntouchedRun(): void
    {
        $result = (new Bash())->execute(['command' => 'printf "quiet\n"', 'id' => 'c1']);

        $this->assertStringContainsString('quiet', $result->content());
    }

    public function testAnAnonymousCallFramesNothing(): void
    {
        $frames = [];
        $bash = (new Bash())->withToolProgressEmitter($this->emitting($frames));

        $result = $bash->execute(['command' => 'printf "still runs\n"']);

        $this->assertSame([], $frames, 'without a call id a tail belongs to no card');
        $this->assertStringContainsString('still runs', $result->content());
    }

    /**
     * C-WEB-7 (WEB-4-P1): the trailing-edge flush. A build that prints its
     * last lines just after a frame and then goes quiet must show them
     * DURING the silence, not at exit — the capture's wait beat calls
     * tick() through the same argument the liveness heartbeat rides. The
     * margins are wide by design (a 1.2 s sleep against a flush at about
     * 0.5-0.7 s); the discriminator against the pre-fix shape is the
     * timing leg: there, the 'ab' frame is the finish() at exit.
     */
    public function testAHeldTailLandsOnTheWaitBeatBeforeTheExitFlush(): void
    {
        /** @var list<array{float, ToolProgress}> $frames */
        $frames = [];
        $bash = (new Bash())->withToolProgressEmitter(static function (ToolProgress $frame) use (&$frames): void {
            $frames[] = [microtime(true), $frame];
        });

        $bash->execute(['command' => 'printf a; sleep 0.05; printf b; sleep 1.2', 'id' => 'c7']);
        $returnedAt = microtime(true);

        $this->assertCount(2, $frames, 'the opening frame and one trailing-edge flush');
        $this->assertSame('a', $frames[0][1]->tail);
        $this->assertSame('ab', $frames[1][1]->tail, 'the flush carries the held chunk');
        $this->assertGreaterThanOrEqual(0.4, $returnedAt - $frames[1][0],
            'the last lines surfaced during the silence, not at exit');
    }

    public function testARunWithoutAnEmitterStillBeatsASuppliedHeartbeat(): void
    {
        // The other half of the composition: with no ring the wait argument
        // is the plain heartbeat, byte-identical to what this call passed
        // before C-WEB-7 (item 0.4-b's idle-watchdog liveness contract).
        $beats = 0;
        $result = (new Bash())->executeWithHeartbeat(
            ['command' => 'printf x; sleep 0.5', 'id' => 'c8'],
            static function () use (&$beats): void {
                $beats++;
            },
        );

        $this->assertStringContainsString('x', $result->content());
        $this->assertGreaterThan(0, $beats, 'a silent slice still beats');
    }

    public function testARingRunKeepsBeatingTheHeartbeatAlongsideTheFlush(): void
    {
        $frames = 0;
        $beats = 0;
        $bash = (new Bash())->withToolProgressEmitter(static function (ToolProgress $frame) use (&$frames): void {
            $frames++;
        });

        $result = $bash->executeWithHeartbeat(
            ['command' => 'printf a; sleep 0.05; printf b; sleep 0.6', 'id' => 'c9'],
            static function () use (&$beats): void {
                $beats++;
            },
        );

        $this->assertGreaterThan(0, $frames, 'the ring still frames');
        $this->assertGreaterThan(0, $beats, 'and the composition still beats the heartbeat beside tick()');
        $this->assertStringContainsString('ab', $result->content());
    }
}
