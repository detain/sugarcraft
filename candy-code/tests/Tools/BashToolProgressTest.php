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
}
