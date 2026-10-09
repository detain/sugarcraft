<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Events\MediaProgress;
use SugarCraft\Crush\Media\Sd\CallableSdTransport;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Support\ToolCancelRequests;
use SugarCraft\Crush\Tools\BuiltIn\GenerateImage;

/**
 * W2.4's feed pin: when the progress loop hands the tool a frame, the tool
 * emits it as a MediaProgress on the event emitter withEngine bound - the
 * exact call id, the server's own numbers riding the newest poll. When the
 * server sends no current_image the pipeline stays silent (degrade honestly,
 * never a fabricated frame), and a cancelled call never reaches the loop at
 * all, so cancellation outranks preview as it outranks the POST.
 */
final class GenerateImagePreviewEmitTest extends TestCase
{
    /** A 1x1 PNG, same fixture shape the sdapi response fakes wrap. */
    private const PIXEL_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $previewRoot;

    /** @var list<array{0: string, 1: string, 2: array}> */
    private array $previewCalls = [];

    protected function setUp(): void
    {
        $this->previewRoot = sys_get_temp_dir() . '/gip-media-' . uniqid('r', true);
        putenv(Client::BASE_URL_ENV);
        ToolCancelRequests::forget();
    }

    protected function tearDown(): void
    {
        ToolCancelRequests::forget();
        putenv(Client::BASE_URL_ENV);
        $this->purgeTree($this->previewRoot);
    }

    public function testFramesFromTheServerEmitProgressEventsForTheCallId(): void
    {
        /** @var list<MediaProgress> $events */
        $events = [];
        $tool = $this->previewTool(
            static function (object $event) use (&$events): void {
                if ($event instanceof MediaProgress) {
                    $events[] = $event;
                }
            },
            $this->previewServer([
                ['progress' => 0.3, 'eta_relative' => 5.0, 'current_image' => 'Zmlyc3Q=', 'state' => ['finished' => false]],
                ['progress' => 1.0, 'eta_relative' => 0.0, 'current_image' => 'c2Vjb25k', 'state' => ['finished' => true]],
            ]),
        );

        $result = $tool->executeWithHeartbeat(['prompt' => 'emitter probe', 'id' => 'call-4'], static function (): void {
        });

        self::assertFalse($result->isError(), $result->content());
        self::assertCount(2, $events, 'one event per poll that carried a frame');
        self::assertSame('call-4', $events[0]->toolCallId);
        self::assertSame('Zmlyc3Q=', $events[0]->frameB64);
        self::assertSame(0.3, $events[0]->progress);
        self::assertSame(5.0, $events[0]->eta);
        self::assertSame('c2Vjb25k', $events[1]->frameB64, 'the newest frame wins to the emitter');
        self::assertSame(1.0, $events[1]->progress);
    }

    public function testAProgressRouteWithoutCurrentImageEmitsNothing(): void
    {
        $events = 0;
        $tool = $this->previewTool(
            static function (object $event) use (&$events): void {
                if ($event instanceof MediaProgress) {
                    $events++;
                }
            },
            $this->previewServer([
                ['progress' => 0.5, 'state' => ['finished' => false]],
                ['state' => ['finished' => true]],
            ]),
        );

        $result = $tool->executeWithHeartbeat(['prompt' => 'silent probe', 'id' => 'call-5'], static function (): void {
        });

        self::assertFalse($result->isError(), $result->content());
        self::assertSame(0, $events, 'no preview, no fabricated frame');
    }

    public function testAToolWithoutAnEmitterBehavesExactlyAsBefore(): void
    {
        $tool = $this->previewTool(
            null,
            $this->previewServer([
                ['progress' => 0.5, 'current_image' => 'Zg==', 'state' => ['finished' => true]],
            ]),
        );

        $result = $tool->executeWithHeartbeat(['prompt' => 'plain probe', 'id' => 'call-6'], static function (): void {
        });

        self::assertFalse($result->isError(), $result->content());
        self::assertTrue($result->hasImage());
    }

    public function testACancelledCallNeverEmitsAPreview(): void
    {
        $events = 0;
        $tool = $this->previewTool(
            static function (object $event) use (&$events): void {
                if ($event instanceof MediaProgress) {
                    $events++;
                }
            },
            $this->previewServer([
                ['progress' => 0.2, 'current_image' => 'Zg==', 'state' => ['finished' => false]],
            ]),
        );
        ToolCancelRequests::listen(static fn (): array => ['call-9']);

        $result = $tool->executeWithHeartbeat(['prompt' => 'cancelled probe', 'id' => 'call-9'], static function (): void {
        });

        self::assertTrue($result->isError());
        self::assertStringContainsString('cancel', strtolower($result->content()));
        self::assertSame(0, $events, 'the pre-check returns before the loop, so nothing is ever emitted');
        $posts = array_filter($this->previewCalls, static fn (array $c): bool => $c[0] === 'POST');
        self::assertCount(0, $posts, 'and the render is never dialed either');
    }

    /**
     * The tool carrying an event emitter: production binds it through
     * withEngine(); here the emitter is seeded at construction (the ctor's
     * eighth slot) because a bare tool() has no EngineBackend to bind.
     *
     * @param (callable(object):void)|null $emitter
     * @param list<array<string, mixed>>   $progressFrames
     */
    private function previewTool(?callable $emitter, CallableSdTransport $transport): GenerateImage
    {
        $bound = $emitter === null ? null : \Closure::fromCallable($emitter);
        // The ctor is private by house law; the emitter slot (8th arg) has no
        // public seam because production binds it through withEngine(). Step
        // into the class scope to seed it directly, then ride the real
        // withers - which is itself the pin that copies carry the emitter.
        $spawn = \Closure::bind(
            static fn (?\Closure $e): GenerateImage => new GenerateImage(null, null, null, null, null, null, null, $e),
            null,
            GenerateImage::class,
        );
        $tool = $bound === null ? GenerateImage::new() : $spawn($bound);

        return $tool
            ->withFakeDial($transport, 'http://sd.test:7860', [])
            ->withMediaRoot($this->previewRoot)
            ->withLoopPacing(0.01, static function (float $seconds): void {
            });
    }

    /** @param list<array<string, mixed>> $progressFrames frames served in order, last repeats */
    private function previewServer(array $progressFrames): CallableSdTransport
    {
        return new CallableSdTransport(function (string $method, string $path, array $json) use (&$progressFrames): array {
            $this->previewCalls[] = [$method, $path, $json];
            if ($method === 'POST') {
                return ['status' => 200, 'body' => json_encode([
                    'images' => [self::PIXEL_PNG_B64],
                    'parameters' => ['prompt' => 'echo'],
                    'info' => json_encode(['infotexts' => ['a quiet prompt']]),
                ])];
            }
            $frame = count($progressFrames) > 0
                ? (count($progressFrames) > 1 ? array_shift($progressFrames) : $progressFrames[0])
                : ['state' => ['finished' => true]];

            return ['status' => 200, 'body' => json_encode($frame)];
        });
    }

    private function purgeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $dir . '/' . $entry;
                is_dir($path) ? $this->purgeTree($path) : @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
