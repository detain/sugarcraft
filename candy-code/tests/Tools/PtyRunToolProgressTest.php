<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Events\ToolProgress;
use SugarCraft\Code\Tools\BuiltIn\Bash;
use SugarCraft\Code\Tools\BuiltIn\PtyRun;
use SugarCraft\Code\Tools\ToolResult;

/**
 * PtyRun on the live-tail channel (C-TOOL-3.a): a real pty run lands stripped
 * tail frames on the emitter, bounded by Bash's shared budget, and the absence
 * of either an emitter or a call id keeps the old silent path byte-untouched.
 *
 * These pins drive a real spawn (there is no fake-pty seam in this house), so
 * the polarity branch stands in for hosts whose pty mechanism is missing: a
 * refused pty must stream nothing rather than frame a run that never was.
 */
final class PtyRunToolProgressTest extends TestCase
{
    /** @param list<ToolProgress> $frames */
    private function framing(array &$frames): \Closure
    {
        return static function (ToolProgress $frame) use (&$frames): void {
            $frames[] = $frame;
        };
    }

    /** True when the run reached a real pty rather than the refusal path. */
    private static function ranOnAPty(ToolResult $result): bool
    {
        return !$result->isError() || !\str_contains($result->content(), 'no pty');
    }

    public function testARealRunFramesItsStrippedTail(): void
    {
        $frames = [];
        $result = (new PtyRun())
            ->withToolProgressEmitter($this->framing($frames))
            ->execute(['command' => 'printf "\033[32mgreen-live\033[0m done\n"', 'id' => 'c1']);

        if (!self::ranOnAPty($result)) {
            $this->assertSame([], $frames, 'a refused pty streams nothing');

            return;
        }

        $this->assertNotEmpty($frames, 'a running terminal program is not a silent tool');
        $this->assertSame('c1', $frames[0]->toolCallId);
        $this->assertSame('PtyRun', $frames[0]->toolName);
        foreach ($frames as $frame) {
            $this->assertStringNotContainsString("\x1b", $frame->tail, 'the tail is the plain text of the screen, not its SGR bytes');
        }

        $last = $frames[count($frames) - 1];
        $this->assertStringContainsString('green-live', $last->tail);
        $this->assertFalse($last->truncated);
        $this->assertSame(Bash::PROGRESS_TAIL_BYTES, $last->tailBudget);
        $previous = 0;
        foreach ($frames as $frame) {
            $this->assertGreaterThan($previous, $frame->seq, 'frames arrive with strictly rising sequence numbers');
            $previous = $frame->seq;
        }

        $this->assertStringContainsString('green-live', $result->content(), 'the finished contract is byte-untouched');
    }

    public function testABigRunTruncatesTheTailAtTheSharedBudget(): void
    {
        $frames = [];
        $result = (new PtyRun())
            ->withToolProgressEmitter($this->framing($frames))
            ->execute(['command' => 'seq 1 2000', 'id' => 'c1']);

        if (!self::ranOnAPty($result)) {
            $this->assertSame([], $frames);

            return;
        }

        $this->assertNotEmpty($frames);
        $last = $frames[count($frames) - 1];
        $this->assertTrue($last->truncated, 'bytes past the ring are marked, never silently dropped');
        $this->assertLessThanOrEqual(Bash::PROGRESS_TAIL_BYTES, strlen($last->tail));
        $this->assertStringContainsString('2000', $last->tail, 'the tail keeps the newest bytes');
    }

    public function testAnAnonymousCallStreamsNothing(): void
    {
        $frames = [];
        $result = (new PtyRun())
            ->withToolProgressEmitter($this->framing($frames))
            ->execute(['command' => 'printf "nameless\n"']);

        $this->assertSame([], $frames, 'a call with no engine id has no row to paint under');
        $this->assertNotEmpty($result->content());
    }

    public function testWithoutAnEmitterThePathIsUntouched(): void
    {
        $plain = (new PtyRun())->execute(['command' => 'printf "\033[32mgreen-live\033[0m done\n"', 'id' => 'c1']);

        $this->assertInstanceOf(ToolResult::class, $plain);
        if (self::ranOnAPty($plain)) {
            $this->assertStringContainsString("\x1b", $plain->content(), 'the transcript keeps the raw screen bytes; only the tail is stripped');
        }
    }

    public function testTheBoundCopyCarriesEveryOtherKnob(): void
    {
        $frames = [];
        $root = \sys_get_temp_dir() . '/c-tool-3a-' . \uniqid('', true);

        $original = new PtyRun($root);
        $bound = $original->withToolProgressEmitter($this->framing($frames));

        $this->assertNotSame($bound, $original, 'immutability, as every with* here promises');
        $this->assertSame($root, (new \ReflectionProperty(PtyRun::class, 'root'))->getValue($bound), 'the emitter bind must not lose the root');
        $this->assertInstanceOf(\Closure::class, (new \ReflectionProperty(PtyRun::class, 'toolProgressEmitter'))->getValue($bound));
        $this->assertNull((new \ReflectionProperty(PtyRun::class, 'toolProgressEmitter'))->getValue(new PtyRun($root)));
    }
}
