<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Events;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Events\ToolProgress;

/**
 * The 'tool_progress' frame kind's wire contract (WEB-4), pinned the way
 * MediaProgressFrameCodecTest pins 'media_progress': encodeEvent()/
 * decodeEvent() are private static by design, so reflection is the harness,
 * and the fork socket is an untrusted boundary - every out-of-shape frame
 * must decode to null, never to a half-trusted tail.
 */
final class ToolProgressFrameCodecTest extends TestCase
{
    private static function encodeTailFrame(ToolProgress $event): array
    {
        $method = new ReflectionMethod(EngineBackend::class, 'encodeEvent');
        $method->setAccessible(true);

        /** @var array<string, mixed> */
        return $method->invoke(null, $event);
    }

    private static function decodeTailFrame(array $encoded): ?ToolProgress
    {
        $method = new ReflectionMethod(EngineBackend::class, 'decodeEvent');
        $method->setAccessible(true);

        return $method->invoke(null, $encoded);
    }

    public function testATailFrameRoundTripsFieldForField(): void
    {
        $original = new ToolProgress('call-7', 'Bash', 'gcc -c a.c', 3, true, 4096);

        $decoded = self::decodeTailFrame(self::encodeTailFrame($original));

        $this->assertInstanceOf(ToolProgress::class, $decoded);
        $this->assertSame('call-7', $decoded->toolCallId, 'the call key may not shift');
        $this->assertSame('Bash', $decoded->toolName);
        $this->assertSame('gcc -c a.c', $decoded->tail);
        $this->assertSame(3, $decoded->seq);
        $this->assertTrue($decoded->truncated);
        $this->assertSame(4096, $decoded->tailBudget);
    }

    public function testTheWireShapeIsThePlainArrayTheCodecPromises(): void
    {
        $encoded = self::encodeTailFrame(new ToolProgress('c', 'Bash', 't', 1, false, 512));

        $this->assertSame('tool_progress', $encoded['kind']);
        $this->assertSame('c', $encoded['id']);
        $this->assertSame('Bash', $encoded['name']);
        $this->assertSame('t', $encoded['tail']);
        $this->assertSame(1, $encoded['seq']);
        $this->assertFalse($encoded['truncated']);
        $this->assertSame(512, $encoded['budget']);
    }

    public function testMalformedTailFramesDecodeToNull(): void
    {
        $good = ['kind' => 'tool_progress', 'id' => 'c', 'name' => 'Bash', 'tail' => 'x', 'seq' => 1, 'truncated' => false, 'budget' => 0];

        $this->assertNull(self::decodeTailFrame(['kind' => 'tool_progress']), 'no id at all');
        $this->assertNull(self::decodeTailFrame(['kind' => 'tool_progress', 'id' => '']), 'the empty call id is not a key');
        $this->assertNull(self::decodeTailFrame(['kind' => 'tool_progress', 'id' => 7]), 'a numeric id is not a string');
        $this->assertNull(self::decodeTailFrame(['kind' => 'tool_progress', 'id' => 'c', 'tail' => 'x', 'seq' => 1, 'truncated' => false, 'budget' => 0]), 'a frame without a tool name is not ours');
        $this->assertNull(self::decodeTailFrame(['kind' => 'tool_progress', 'id' => 'c', 'name' => 'Bash', 'seq' => 1, 'truncated' => false, 'budget' => 0]), 'a missing tail is not an empty tail');
        $this->assertNull(self::decodeTailFrame(['kind' => 'tool_progress', 'id' => 'c', 'name' => 'Bash', 'tail' => 5, 'seq' => 1, 'truncated' => false, 'budget' => 0]), 'byte-count tails belong to no frame');
        $this->assertNull(self::decodeTailFrame(['kind' => 'tool_progress', 'id' => 'c', 'name' => 'Bash', 'tail' => 'x', 'truncated' => false, 'budget' => 0]), 'a missing seq was never written by a ring');
        $this->assertNull(self::decodeTailFrame(['kind' => 'tool_progress', 'id' => 'c', 'name' => 'Bash', 'tail' => 'x', 'seq' => 0, 'truncated' => false, 'budget' => 0]), 'a zero seq is below the first frame');
        $this->assertNull(self::decodeTailFrame(array_merge($good, ['seq' => '1'])), 'a prose seq is not a number');
        $this->assertNull(self::decodeTailFrame(array_merge($good, ['truncated' => 'yes'])), 'a prose flag is not a bool');
        $this->assertNull(self::decodeTailFrame(array_merge($good, ['budget' => -1])), 'a negative budget cannot exist');
    }

    public function testAnUnknownKindIsStillRefusedAfterTheNewArm(): void
    {
        // Regression: adding the tool_progress arm must not let a frame of an
        // unrecognized kind slide past the guards into some other event.
        $this->assertNull(self::decodeTailFrame([
            'kind' => 'tool_progress_v2',
            'id' => 'call-1',
            'name' => 'Bash',
            'tail' => 'x',
            'seq' => 1,
            'truncated' => false,
            'budget' => 0,
        ]));
    }

    public function testTheFrameSurvivesTheSocketSerializationBoundary(): void
    {
        // The child writes serialize($frame) and the parent reads it back with
        // allowed_classes => false - the event object must be rebuilt purely
        // from the plain-array payload on the other side.
        $encoded = self::encodeTailFrame(new ToolProgress('c', 'Bash', "out\r\nnext", 2, true, 4096));
        $wire = serialize($encoded);
        /** @var array<string, mixed> $readBack */
        $readBack = unserialize($wire, ['allowed_classes' => false]);

        $decoded = self::decodeTailFrame($readBack);
        $this->assertInstanceOf(ToolProgress::class, $decoded);
        $this->assertSame("out\r\nnext", $decoded->tail);
        $this->assertSame(2, $decoded->seq);
        $this->assertTrue($decoded->truncated);
    }
}
