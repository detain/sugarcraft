<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Host\SessionSnapshot;
use SugarCraft\Code\Host\TranscriptProjector;
use SugarCraft\Code\Media\Png;
use SugarCraft\Code\Message;
use SugarCraft\Code\Protocol\Schema\EventSchemas;
use SugarCraft\Code\Protocol\Schema\ProtocolSchema;
use SugarCraft\Code\Support\MediaStore;
use SugarCraft\Code\ToolResult;
use SugarCraft\Code\Tools\ToolResult as EngineToolResult;
use ReflectionMethod;

/**
 * W6.1: the `tool.finished` media surface (Appendix C-7). The journal gains
 * `media` — ordered artifact refs from the session media store — and must
 * never carry bytes: identity, path, size, mime, and an optional infotext
 * digest are the whole envelope. A finish without media keeps the keys
 * ABSENT (old clients read the frame unchanged), the ref list is capped at
 * {@see TranscriptProjector::MAX_EVENT_MEDIA_REFS} with an honest
 * `mediaTruncated` flag mirroring the content-clipping ethic, and every
 * projected frame fits the protocol schema.
 */
final class TranscriptProjectorMediaTest extends TestCase
{
    /** Smallest valid 1x1 PNG — no text chunk, so no infotext to digest. */
    private const RAW_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const INFOTEXT = "a desert fox, daguerreotype\nSteps: 20, Sampler: Euler a";

    private ?string $tempRoot = null;

    protected function tearDown(): void
    {
        if ($this->tempRoot === null) {
            return;
        }
        // chr(42): a glued '*' here is a glob-shaped literal and would drift the PathGlob corpus figure
        foreach (\glob($this->tempRoot . '/.candy-code/media/' . \chr(42) . '/' . \chr(42)) ?: [] as $file) {
            @\unlink($file);
        }
        foreach (\array_reverse(\glob($this->tempRoot . '/.candy-code/media/' . \chr(42)) ?: []) as $dir) {
            @\rmdir($dir);
        }
        foreach (['.candy-code/media', '.candy-code'] as $dir) {
            @\rmdir($this->tempRoot . '/' . $dir);
        }
        @\rmdir($this->tempRoot);
        $this->tempRoot = null;
    }

    public function testAFinishWithoutMediaCarriesNoMediaKeys(): void
    {
        $data = self::event(self::finished('/only/a/path.png'))->data;
        self::assertArrayHasKey('media', $data);

        $plain = self::event(self::finished())->data;
        self::assertArrayNotHasKey('media', $plain);
        self::assertArrayNotHasKey('mediaTruncated', $plain);
    }

    public function testTheHeadRefNamesTheArtifactWithoutCarryingBytes(): void
    {
        $png = self::stampedPng();
        $data = self::event(self::finished('/sessions/s1/wave-6-sample-00.png', $png))->data;

        self::assertSame([[
            'artifactId' => 'wave-6-sample-00',
            'kind' => 'image',
            'path' => '/sessions/s1/wave-6-sample-00.png',
            'bytes' => \strlen($png),
            'mime' => 'image/png',
            'infotextDigest' => \hash('sha256', self::INFOTEXT),
        ]], $data['media']);
        self::assertSame('media', \array_key_last($data));
        self::assertArrayNotHasKey('mediaTruncated', $data);
        $json = \json_encode($data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(\base64_encode($png), $json);
        self::assertLessThan(65536, \strlen($json));
    }

    public function testAPathWithoutBytesReportsZeroSizeAndNoDigest(): void
    {
        $refs = TranscriptProjector::mediaRefs(self::finished('/sessions/s1/clip.mp4'));
        self::assertNotNull($refs);
        self::assertSame([[
            'artifactId' => 'clip',
            'kind' => 'video',
            'path' => '/sessions/s1/clip.mp4',
            'bytes' => 0,
            'mime' => 'video/mp4',
        ]], $refs);
    }

    public function testPngBytesWithoutInfotextCarryNoDigest(): void
    {
        $refs = TranscriptProjector::mediaRefs(
            self::finished('/sessions/s1/plain.png', \base64_decode(self::RAW_PNG_B64, true)),
        );
        self::assertNotNull($refs);
        self::assertArrayNotHasKey('infotextDigest', $refs[0]);
    }

    public function testUnknownExtensionsFallBackToOctetStreamTypedAsImage(): void
    {
        $refs = TranscriptProjector::mediaRefs(self::finished('/sessions/s1/mystery.bin'));
        self::assertNotNull($refs);
        self::assertSame('image', $refs[0]['kind']);
        self::assertSame('application/octet-stream', $refs[0]['mime']);
    }

    public function testMultiRefsKeepOrderAndHeadBytesRidesOnlyTheHead(): void
    {
        $png = self::stampedPng();
        $truncated = null;
        $refs = TranscriptProjector::mediaRefsForPaths(
            ['/m/a.png', null, '', '/m/b.png'],
            $png,
            $truncated,
        );

        self::assertNotNull($refs);
        self::assertCount(2, $refs);
        self::assertSame('a', $refs[0]['artifactId']);
        self::assertSame('b', $refs[1]['artifactId']);
        self::assertSame(\strlen($png), $refs[0]['bytes']);
        self::assertSame(0, $refs[1]['bytes']);
        self::assertFalse($truncated);

        // A head slot without a path means the bytes describe nothing on this
        // event — they must not migrate onto surviving entries and misreport.
        $pathlessHead = TranscriptProjector::mediaRefsForPaths([null, '/m/a.png', '/m/b.png'], $png);
        self::assertNotNull($pathlessHead);
        self::assertSame(0, $pathlessHead[0]['bytes']);
        self::assertSame(0, $pathlessHead[1]['bytes']);
        self::assertArrayNotHasKey('infotextDigest', $pathlessHead[0]);
    }

    public function testTheRefListIsCappedWithAnHonestFlag(): void
    {
        $paths = [];
        for ($slot = 0; $slot < 40; ++$slot) {
            $paths[] = \sprintf('/sessions/s1/wave-6-sample-%02d.png', $slot);
        }

        $truncated = null;
        $refs = TranscriptProjector::mediaRefsForPaths($paths, null, $truncated);

        self::assertNotNull($refs);
        self::assertCount(TranscriptProjector::MAX_EVENT_MEDIA_REFS, $refs);
        self::assertTrue($truncated);
        self::assertSame('wave-6-sample-00', $refs[0]['artifactId']);
        self::assertSame('wave-6-sample-31', $refs[31]['artifactId']);
    }

    public function testTheTruncatedOutRidesTheEventWhenTheCapBites(): void
    {
        $paths = [];
        for ($slot = 0; $slot < 33; ++$slot) {
            $paths[] = \sprintf('/sessions/s1/art-%02d.png', $slot);
        }

        $event = self::eventWithPaths($paths);
        self::assertCount(32, $event->data['media']);
        self::assertTrue($event->data['mediaTruncated']);
    }

    public function testNoPathsAtAllLeavesTheFlagFalseAndTheRefsNull(): void
    {
        $truncated = true;
        $refs = TranscriptProjector::mediaRefsForPaths([null, ''], null, $truncated);
        self::assertNull($refs);
        self::assertFalse($truncated);
    }

    public function testMediaKeysRideBetweenDenialAndIdentity(): void
    {
        $data = self::event(self::finished('/s/x.png', self::stampedPng()), ['m1', 7])->data;
        self::assertSame(
            ['toolCallId', 'name', 'isError', 'durationMs', 'content', 'media', 'messageId', 'ref'],
            \array_keys($data),
        );
    }

    public function testEveryProjectedFrameFitsTheProtocolSchema(): void
    {
        $schema = EventSchemas::data(SessionEvent::TOOL_FINISHED);
        self::assertNotNull($schema);

        $cases = [
            'plain' => self::event(self::finished())->data,
            'head ref' => self::event(self::finished('/s/x.png', self::stampedPng()))->data,
            'path only' => self::event(self::finished('/s/clip.mp4'))->data,
            'capped list' => self::eventWithPaths(\array_map(
                static fn (int $slot): string => \sprintf('/sessions/s1/art-%02d.png', $slot),
                \range(0, 39),
            ))->data,
        ];

        foreach ($cases as $label => $data) {
            self::assertSame([], ProtocolSchema::errors($data, $schema), $label);
        }
    }

    public function testThirtyTwoRefsStayWellUnderTheEventByteBudget(): void
    {
        $data = self::eventWithPaths(\array_map(
            static fn (int $slot): string => \sprintf(
                '/sessions/01JW6P1SESSION/wave-6-sample-%02d.png',
                $slot,
            ),
            \range(0, 39),
        ))->data;

        $encoded = \json_encode($data, JSON_THROW_ON_ERROR);
        self::assertLessThan(65536, \strlen($encoded));
    }

    public function testArtifactIdsMatchTheStoresOwnListingAndFetchPath(): void
    {
        $this->tempRoot = \sys_get_temp_dir() . '/sc-w6p1-' . \uniqid('', true);
        $store = MediaStore::forSession('w6-p1', $this->tempRoot);
        $artifact = $store->put(self::stampedPng(), 'png');
        $path = $artifact->path();
        self::assertNotNull($path);

        $refs = TranscriptProjector::mediaRefs(self::finished($path, self::stampedPng()));
        self::assertNotNull($refs);

        $listed = $store->list();
        self::assertCount(1, $listed);
        self::assertSame($listed[0]['id'], $refs[0]['artifactId']);
        self::assertSame($path, $store->pathFor($refs[0]['artifactId']));
    }

    public function testTheBackendCodecAlreadyCarriesTheImageTriple(): void
    {
        // W6.1 step 4 (codec audit): the finished frame crossed with the
        // image triple long before media refs existed on the journal; this
        // pin records that the audit's premise holds, so refs can resolve
        // paths on the parent side of the socket.
        $png = self::stampedPng();
        $event = new ToolFinished('c1', 'GenerateImage', new EngineToolResult(
            'c1',
            'saved',
            imageBytes: $png,
            imagePath: '/sessions/s1/wave-6-sample-00.png',
            imageProtocol: 'kitty',
        ));

        $encode = new ReflectionMethod(EngineBackend::class, 'encodeEvent');
        $decode = new ReflectionMethod(EngineBackend::class, 'decodeEvent');

        $decoded = $decode->invoke(null, $encode->invoke(null, $event));
        self::assertInstanceOf(ToolFinished::class, $decoded);
        self::assertSame($png, $decoded->result->imageBytes());
        self::assertSame('/sessions/s1/wave-6-sample-00.png', $decoded->result->imagePath());
        self::assertSame('kitty', $decoded->result->imageProtocol());
    }

    public function testTheSnapshotRowCarriesTheEventRefsByteIdentical(): void
    {
        $png = self::stampedPng();
        $result = self::finished('/sessions/s1/wave-6-sample-00.png', $png);

        // W7 fold (R16(a)): same ToolResult, two consumers — the live
        // `tool.finished` frame and the reload-path snapshot row — must hold
        // the identical projection. One builder ({@see
        // TranscriptProjector::mediaRefs()}), never a second derivation.
        $eventMedia = self::event($result)->data['media'];
        $wire = SessionSnapshot::new('sess', [TranscriptProjector::resultRow($result)])->toArray();
        $rowMedia = $wire['messages'][0]['toolResults'][0]['media'];

        self::assertSame($eventMedia, $rowMedia);
        self::assertSame('wave-6-sample-00', $rowMedia[0]['artifactId']);
        self::assertStringNotContainsString(
            \base64_encode($png),
            \json_encode($rowMedia, JSON_THROW_ON_ERROR),
        );
        // A single-ref row cannot trip the cap, so the flag stays absent —
        // the same absence law the event carries.
        self::assertArrayNotHasKey('mediaTruncated', $wire['messages'][0]['toolResults'][0]);
    }

    /** @param array{0: string, 1: int}|null $identity */
    private static function event(ToolResult $result, ?array $identity = null): SessionEvent
    {
        return self::projector()->toolFinishedEvent(
            new ToolFinished('c1', 'GenerateImage', new EngineToolResult('c1', 'ok', durationMs: 12)),
            TranscriptProjector::resultRow($result),
            $identity,
            null,
            'sess',
            'turn',
        );
    }

    /** @param list<string> $paths */
    private static function eventWithPaths(array $paths): SessionEvent
    {
        $truncated = false;
        $media = TranscriptProjector::mediaRefsForPaths($paths, null, $truncated);

        return SessionEvent::new(SessionEvent::TOOL_FINISHED, array_filter([
            'toolCallId' => 'c1',
            'name' => 'GenerateImage',
            'isError' => false,
            'durationMs' => 12,
            'content' => 'ok',
            'media' => $media,
            'mediaTruncated' => $truncated ?: null,
        ], static fn (mixed $value): bool => $value !== null), 'sess', 'turn');
    }

    private static function finished(?string $imagePath = null, ?string $imageBytes = null): ToolResult
    {
        return new ToolResult(
            name: 'GenerateImage',
            result: 'ok',
            imageBytes: $imageBytes,
            imagePath: $imagePath,
            durationMs: 12,
        );
    }

    private static function stampedPng(): string
    {
        return Png::setInfotext(\base64_decode(self::RAW_PNG_B64, true), self::INFOTEXT);
    }

    private static function projector(): TranscriptProjector
    {
        return TranscriptProjector::new();
    }
}
