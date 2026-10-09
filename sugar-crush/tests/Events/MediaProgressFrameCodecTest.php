<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Events;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Events\MediaProgress;

/**
 * The 'media_progress' frame kind's wire contract (W2.4), pinned the way
 * SubAgentFrameCodecTest pins 'subagent': encodeEvent()/decodeEvent() are
 * private static by design, so reflection is the harness, and the socket is
 * an untrusted boundary - every out-of-shape frame must decode to null,
 * never to a half-trusted row.
 */
final class MediaProgressFrameCodecTest extends TestCase
{
    private static function encodeProgressEvent(MediaProgress $event): array
    {
        $method = new ReflectionMethod(EngineBackend::class, 'encodeEvent');
        $method->setAccessible(true);

        /** @var array<string, mixed> */
        return $method->invoke(null, $event);
    }

    private static function decodeProgressEvent(array $encoded): ?MediaProgress
    {
        $method = new ReflectionMethod(EngineBackend::class, 'decodeEvent');
        $method->setAccessible(true);

        return $method->invoke(null, $encoded);
    }

    public function testAProgressFrameRoundTripsFieldForField(): void
    {
        $original = new MediaProgress('call-7', 'aW1hZ2U=', 0.42, 8.5);

        $decoded = self::decodeProgressEvent(self::encodeProgressEvent($original));

        $this->assertInstanceOf(MediaProgress::class, $decoded);
        $this->assertSame('call-7', $decoded->toolCallId, 'the slot key may not shift');
        $this->assertSame('aW1hZ2U=', $decoded->frameB64);
        $this->assertSame(0.42, $decoded->progress);
        $this->assertSame(8.5, $decoded->eta);
    }

    public function testANullFrameAndNullReadoutsRoundTrip(): void
    {
        $decoded = self::decodeProgressEvent(self::encodeProgressEvent(new MediaProgress('call-7', null, null, null)));

        $this->assertInstanceOf(MediaProgress::class, $decoded);
        $this->assertNull($decoded->frameB64);
        $this->assertNull($decoded->progress);
        $this->assertNull($decoded->eta);
    }

    public function testTheWireShapeIsThePlainArrayTheCodecPromises(): void
    {
        $encoded = self::encodeProgressEvent(new MediaProgress('call-7', 'Zg==', 0.5, 3.0));

        $this->assertSame('media_progress', $encoded['kind']);
        $this->assertSame('call-7', $encoded['id']);
        $this->assertSame('Zg==', $encoded['frame']);
        $this->assertSame(0.5, $encoded['progress']);
        $this->assertSame(3.0, $encoded['eta']);
    }

    public function testAnIntegerEtaDecodesToTheSameFloatReading(): void
    {
        // serialize survives ints fine; a server that reported whole seconds
        // must not make the frame undecodable.
        $decoded = self::decodeProgressEvent([
            'kind' => 'media_progress',
            'id' => 'call-7',
            'frame' => null,
            'progress' => 1,
            'eta' => 4,
        ]);

        $this->assertInstanceOf(MediaProgress::class, $decoded);
        $this->assertSame(1.0, $decoded->progress);
        $this->assertSame(4.0, $decoded->eta);
    }

    public function testMalformedProgressFramesDecodeToNull(): void
    {
        $this->assertNull(self::decodeProgressEvent(['kind' => 'media_progress']), 'no id at all');
        $this->assertNull(self::decodeProgressEvent(['kind' => 'media_progress', 'id' => '']), 'the empty call id is not a slot key');
        $this->assertNull(self::decodeProgressEvent(['kind' => 'media_progress', 'id' => 7]), 'a numeric id is not a string');
        $this->assertNull(self::decodeProgressEvent(['kind' => 'media_progress', 'id' => 'c', 'frame' => 5]), 'frame bytes must be a string or null');
        $this->assertNull(self::decodeProgressEvent(['kind' => 'media_progress', 'id' => 'c', 'progress' => 'fast']), 'a prose progress is not a number');
        $this->assertNull(self::decodeProgressEvent(['kind' => 'media_progress', 'id' => 'c', 'eta' => []]), 'a list eta is not a number');
    }

    public function testAnUnknownKindIsStillRefusedAfterTheNewArm(): void
    {
        // Regression: adding the media_progress arm must not let a frame of
        // an unrecognized kind slide past the guards into some other event -
        // with an id AND name present it still decodes to null.
        $this->assertNull(self::decodeProgressEvent([
            'kind' => 'media_preview_v2',
            'id' => 'call-1',
            'name' => 'GenerateImage',
        ]));
    }

    public function testTheFrameSurvivesTheSocketSerializationBoundary(): void
    {
        // The child writes serialize($frame) and the parent reads it back with
        // allowed_classes => false - the event object must be rebuilt purely
        // from the plain-array payload on the other side.
        $encoded = self::encodeProgressEvent(new MediaProgress('call-7', 'aW1hZ2U=', 0.25, 12.0));
        $wire = serialize($encoded);
        /** @var array<string, mixed> $readBack */
        $readBack = unserialize($wire, ['allowed_classes' => false]);

        $decoded = self::decodeProgressEvent($readBack);
        $this->assertInstanceOf(MediaProgress::class, $decoded);
        $this->assertSame('aW1hZ2U=', $decoded->frameB64);
        $this->assertSame(0.25, $decoded->progress);
        $this->assertSame(12.0, $decoded->eta);
    }
}
