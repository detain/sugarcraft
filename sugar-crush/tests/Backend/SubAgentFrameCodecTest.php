<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Events\SubAgentActivity;

/**
 * The 'subagent' frame kind's wire contract: the child's TaskTool beats must
 * cross {@see EngineBackend::completeAsync()}'s one-way socket and arrive in
 * the parent as the same typed event the projection step consumes, and every
 * malformed shape must decode to null rather than to a half-trusted row —
 * the frame stream is an untrusted boundary (Parse, Don't Validate), so the
 * decoder is the one place that decides what a subagent frame IS.
 *
 * encodeEvent()/decodeEvent() are private static by design (the wire is not
 * public API); reflection is how the spend-cap codec got pinned before this
 * kind existed, and this file mirrors that harness rather than widening the
 * class for test reach.
 */
final class SubAgentFrameCodecTest extends TestCase
{
    private static function encodeFrame(SubAgentActivity $event): array
    {
        $method = new ReflectionMethod(EngineBackend::class, 'encodeEvent');
        $method->setAccessible(true);

        /** @var array<string, mixed> */
        return $method->invoke(null, $event);
    }

    private static function decodeFrame(array $encoded): ?SubAgentActivity
    {
        $method = new ReflectionMethod(EngineBackend::class, 'decodeEvent');
        $method->setAccessible(true);

        return $method->invoke(null, $encoded);
    }

    public function testEveryOpRoundTripsFieldForField(): void
    {
        foreach (SubAgentActivity::OPS as $op) {
            $original = new SubAgentActivity($op, 'subagent_4242_abc', 'coder', 'Audit candy-core', 7, '-> probe');

            $decoded = self::decodeFrame(self::encodeFrame($original));

            $this->assertInstanceOf(SubAgentActivity::class, $decoded, "op {$op} must decode back to an event");
            $this->assertSame($op, $decoded->op);
            $this->assertSame('subagent_4242_abc', $decoded->id, 'the id is the projection key — it may not shift');
            $this->assertSame('coder', $decoded->name);
            $this->assertSame('Audit candy-core', $decoded->task);
            $this->assertSame(7, $decoded->seq);
            $this->assertSame('-> probe', $decoded->tail);
        }
    }

    public function testTheWireShapeIsTheDocumentedThirteenKeyFrame(): void
    {
        $frame = self::encodeFrame(new SubAgentActivity(
            SubAgentActivity::OP_PROGRESS,
            'subagent_1_x',
            'reviewer',
            '',
            3,
            'thinking: check auth',
            1234,
            0.5,
            7,
            'qwen3-coder',
            900,
            [['id' => 'c1', 'label' => 'Read(path: "a")', 'state' => 'ok', 'at' => 1700000000]],
        ));

        $this->assertSame([
            'kind' => 'subagent',
            'op' => 'progress',
            'id' => 'subagent_1_x',
            'name' => 'reviewer',
            'task' => '',
            'seq' => 3,
            'tail' => 'thinking: check auth',
            'tokens' => 1234,
            'cost' => 0.5,
            'lines' => 7,
            'model' => 'qwen3-coder',
            'context' => 900,
            'calls' => [['id' => 'c1', 'label' => 'Read(path: "a")', 'state' => 'ok', 'at' => 1700000000]],
        ], $frame, 'the literal wire keys are the protocol — renaming one silently orphans the other side');
    }

    /**
     * The running totals are display-only, so a frame that lacks them (or
     * carries a nonsense figure) still decodes — the row loses its count,
     * never the beat.
     */
    public function testRunningTotalsRoundTripAndDegradeToZeroWhenAbsentOrBad(): void
    {
        $decoded = self::decodeFrame(self::encodeFrame(new SubAgentActivity(
            SubAgentActivity::OP_PROGRESS, 'subagent_1_x', 'coder', '', 2, '-> Read', 900, 0.25, 12,
        )));
        $this->assertInstanceOf(SubAgentActivity::class, $decoded);
        $this->assertSame([900, 0.25, 12], [$decoded->tokensUsed, $decoded->costUsd, $decoded->lines]);
        $this->assertSame(['', 0], [$decoded->model, $decoded->contextTokens], 'absent model/context degrade to unknown');

        $bare = ['kind' => 'subagent', 'op' => 'progress', 'id' => 'subagent_1_x', 'name' => 'coder', 'task' => '', 'seq' => 2, 'tail' => ''];

        $calls = [
            ['id' => 'c1', 'label' => 'Read(path: "a")', 'state' => 'running', 'at' => 5],
            ['id' => 'c2', 'label' => 'x', 'state' => 'exploded', 'at' => 5],
            'not a call',
        ];
        $decoded = self::decodeFrame($bare + ['calls' => $calls]);
        $this->assertInstanceOf(SubAgentActivity::class, $decoded);
        $this->assertSame([$calls[0]], $decoded->calls, 'a malformed call entry is dropped, the beat survives');
        foreach ([$bare, $bare + ['tokens' => -5, 'cost' => 'lots', 'lines' => 1.5]] as $frame) {
            $decoded = self::decodeFrame($frame);
            $this->assertInstanceOf(SubAgentActivity::class, $decoded);
            $this->assertSame([0, 0.0, 0], [$decoded->tokensUsed, $decoded->costUsd, $decoded->lines]);
        }
    }

    public function testTaskRidesEveryOpAndIsLegallyEmptyAfterStarted(): void
    {
        $frame = self::encodeFrame(new SubAgentActivity(
            SubAgentActivity::OP_FINISHED,
            'subagent_1_x',
            'coder',
            '',
            9,
            'the report',
        ));

        $this->assertArrayHasKey('task', $frame, 'a key that vanishes between ops breaks fixed-shape readers');
        $decoded = self::decodeFrame($frame);
        $this->assertInstanceOf(SubAgentActivity::class, $decoded);
        $this->assertSame('', $decoded->task);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function malformedFrames(): array
    {
        $valid = [
            'kind' => 'subagent',
            'op' => 'started',
            'id' => 'subagent_1_x',
            'name' => 'coder',
            'task' => 'go',
            'seq' => 1,
            'tail' => '',
        ];

        return [
            'op outside the allowlist' => [['op' => 'nope'] + $valid],
            'op not a string' => [['op' => 2] + $valid],
            'id empty' => [['id' => ''] + $valid],
            'name empty' => [['name' => ''] + $valid],
            'task missing' => [(function (array $f): array {
                unset($f['task']);

                return $f;
            })($valid)],
            'seq missing' => [(function (array $f): array {
                unset($f['seq']);

                return $f;
            })($valid)],
            'seq not an int' => [['seq' => '3'] + $valid],
            'tail missing' => [(function (array $f): array {
                unset($f['tail']);

                return $f;
            })($valid)],
            'tail not a string' => [['tail' => 7] + $valid],
        ];
    }

    #[DataProvider('malformedFrames')]
    public function testAMalformedSubagentFrameDecodesToNull(array $frame): void
    {
        $this->assertNull(
            self::decodeFrame($frame),
            'a frame that lies about its shape must drop, not materialise a lying mirror row',
        );
    }
}
