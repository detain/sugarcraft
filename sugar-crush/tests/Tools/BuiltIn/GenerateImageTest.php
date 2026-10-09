<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\Capability\EndpointFamily;
use SugarCraft\Crush\Media\Png;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Media\Sd\CallableSdTransport;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Support\MediaStore;
use SugarCraft\Crush\Support\ToolCancelRequests;
use SugarCraft\Crush\Tools\AcceptsHeartbeat;
use SugarCraft\Crush\Tools\BuiltIn\GenerateImage;
use SugarCraft\Crush\Tools\Catalog\ToolCatalog;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;
use SugarCraft\Crush\Tools\DelegatesToEngine;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\TakesToolCallId;

/**
 * W2.1 behaviour pins for the GenerateImage tool: the fake is the transport
 * (CallableSdTransport) so nothing ever dials, and the progress-loop pacing
 * seam keeps the run free of real sleeps and timers.
 */
final class GenerateImageTest extends TestCase
{
    /** The 1x1 PNG fixture every sdapi response fake wraps (ResponseTest family). */
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $mediaRoot;

    /** @var list<array{0: string, 1: string, 2: array}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->mediaRoot = sys_get_temp_dir() . '/gi-media-' . uniqid('r', true);
        self::assertDirectoryDoesNotExist($this->mediaRoot . '/nowhere');
        putenv(Client::BASE_URL_ENV);
        ToolCancelRequests::forget();
    }

    protected function tearDown(): void
    {
        ToolCancelRequests::forget();
        putenv(Client::BASE_URL_ENV);
        $this->rrmdir($this->mediaRoot);
    }

    public function testHappySingleImageSavesBytesAndBeatsOncePerPoll(): void
    {
        $beats = 0;
        $heartbeat = function () use (&$beats): void {
            $beats++;
        };
        $raw = base64_decode(self::PNG_B64, true);
        self::assertIsString($raw);
        $tool = $this->tool($this->server(
            [['state' => ['finished' => false]], ['state' => ['finished' => true]]],
            $this->envelope([self::PNG_B64], ['a lacy test prompt'], ['all_seeds' => [7], 'all_subseeds' => [8]]),
        ));

        $result = $tool->executeWithHeartbeat(['prompt' => '  a lacy test prompt  ', 'id' => 'call-1'], $heartbeat);

        self::assertFalse($result->isError(), $result->content());
        // One beat per accepted poll bracketing the single blocking POST.
        self::assertSame(2, $beats);
        $posts = array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === 'POST'));
        self::assertCount(1, $posts);
        self::assertSame('/sdapi/v1/txt2img', $posts[0][1]);
        self::assertSame('a lacy test prompt', $posts[0][2]['prompt']);

        self::assertTrue($result->hasImage());
        self::assertNotNull($result->imagePath());
        self::assertFileExists($result->imagePath());
        self::assertSame(file_get_contents($result->imagePath()), $result->imageBytes());
        // The verbatim server infotext rode the PNG's `parameters` chunk.
        $embedded = Png::readText((string) $result->imageBytes(), Png::KEYWORD_PARAMETERS);
        self::assertSame('a lacy test prompt', is_string($embedded) ? trim($embedded) : null);
        // The seed companion named the file (palette token; the off-palette
        // subseed companion never reaches the name).
        self::assertStringContainsString('-7-', basename((string) $result->imagePath()));
        self::assertSame(0o600, fileperms((string) $result->imagePath()) & 0o777);
        self::assertStringContainsString('Generated 1 image', $result->content());
        self::assertStringContainsString($this->mediaRoot, $result->content());
        self::assertSame('call-1', $result->toolCallId());
    }

    public function testBatchGridIsSavedAndSurfacedBesideSamples(): void
    {
        $tool = $this->tool($this->server(
            [['state' => ['finished' => true]]],
            $this->envelope(
                [self::PNG_B64, self::PNG_B64, self::PNG_B64],
                ['the grid row', 'first sample', 'second sample'],
                ['index_of_first_image' => 1, 'all_seeds' => [11, 22], 'all_subseeds' => [101, 102]],
            ),
        ));

        $result = $tool->execute(['prompt' => 'duotone']);

        self::assertFalse($result->isError(), $result->content());
        self::assertStringContainsString('Generated 2 images plus the batch grid', $result->content());
        self::assertStringContainsString('batch grid:', $result->content());
        $files = glob($this->mediaRoot . '/*/*.png');
        self::assertIsArray($files);
        self::assertCount(3, $files);
        // The FIRST SAMPLE headlines the result (seed 11), not the grid. The
        // needle is position-anchored after the fixed-width [date]-[time]
        // prefix: a bare '-11-' substring false-matches the zero-padded hour
        // in every batch filename during 11:00-11:59.
        $headline = array_values(array_filter(
            $files,
            static fn (string $f): bool => preg_match('/^\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}-11-\d+\.png$/', basename($f)) === 1,
        ));
        self::assertCount(1, $headline);
        self::assertSame($result->imagePath(), $headline[0]);
        self::assertCount(3, array_unique(array_map(static fn (string $f): string => basename($f), $files)));
    }

    public function testUnconfiguredHostRefusesWithoutAnyDial(): void
    {
        $tool = $this->tool($this->server([['state' => ['finished' => true]]], '{}'), base: null);

        $result = $tool->execute(['prompt' => 'anything', 'id' => 'call-x']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('no SD base URL configured', $result->content());
        self::assertSame([], $this->calls, 'an unconfigured host must never be dialed, not even for progress');
    }

    public function testDetectableButUnimplementedFamilyRefusesWithoutAnyDial(): void
    {
        self::assertFalse(EndpointFamily::SglangDiffusion->isImplemented());
        $tool = $this->tool($this->server([['state' => ['finished' => true]]], '{}'), [
            'mediaFamily' => EndpointFamily::SglangDiffusion->value,
        ]);

        $result = $tool->execute(['prompt' => 'anything']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('sglang-diffusion', $result->content());
        self::assertSame([], $this->calls);
    }

    public function testMediaKindsRosterWithoutImageRefusesWithoutAnyDial(): void
    {
        $tool = $this->tool($this->server([['state' => ['finished' => true]]], '{}'), [
            'mediaKinds' => ['video'],
        ]);

        $result = $tool->execute(['prompt' => 'anything']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('do not include image', $result->content());
        self::assertSame([], $this->calls);
    }

    public function testCancelledBeforeStartNeverDials(): void
    {
        ToolCancelRequests::listen(static fn (): array => ['call-9']);
        $tool = $this->tool($this->server(
            [['state' => ['finished' => false]], ['state' => ['finished' => true]]],
            $this->envelope([self::PNG_B64], ['x']),
        ));

        $result = $tool->execute(['prompt' => 'anything', 'id' => 'call-9']);

        self::assertTrue($result->isError());
        self::assertSame(ToolCancelRequests::CANCELLED, $result->content());
        self::assertSame([], $this->calls);
    }

    public function testSchemaRoundTripsObjectsAndRequiresPrompt(): void
    {
        $schema = $this->tool($this->server([], '{}'))->inputSchema();
        $json = json_encode($schema);
        self::assertIsString($json);
        // Fact-(b) law in its live shape: no property map may json_encode as a
        // bare [] (empty arrays are the round-trip defect this family pins).
        self::assertStringNotContainsString('"properties":[]', $json);
        self::assertStringNotContainsString('"required":[]', $json);
        self::assertStringContainsString('"required":["prompt"]', $json);
        self::assertStringContainsString('"save_to_disk":{"type":"boolean"', $json);
        self::assertSame($schema, json_decode($json, true));
    }

    public function testUnknownKnobsRideThroughToTheWire(): void
    {
        $tool = $this->tool($this->server(
            [['state' => ['finished' => true]]],
            $this->envelope([self::PNG_B64], ['x']),
        ));

        $result = $tool->execute(['prompt' => 'p', 'clip_skip' => 2, 'my_future_knob' => 'yes']);

        self::assertFalse($result->isError(), $result->content());
        $post = $this->postJson();
        self::assertSame(2, $post['clip_skip']);
        self::assertSame('yes', $post['my_future_knob']);
    }

    public function testSeedKeepsInt64ExactnessThroughTheArgsArray(): void
    {
        $tool = $this->tool($this->server(
            [['state' => ['finished' => true]]],
            $this->envelope([self::PNG_B64], ['x']),
        ));

        $result = $tool->execute(['prompt' => 'p', 'seed' => 9007199254740993]);

        self::assertFalse($result->isError(), $result->content());
        self::assertSame(9007199254740993, $this->postJson()['seed']);
    }

    public function testSaveToDiskMapsOntoTheWireKey(): void
    {
        $tool = $this->tool($this->server(
            [['state' => ['finished' => true]]],
            $this->envelope([self::PNG_B64], ['x']),
        ));

        $result = $tool->execute(['prompt' => 'p', 'save_to_disk' => true]);

        self::assertFalse($result->isError(), $result->content());
        // The alias lands on the wire key; the tool arg spelling and the
        // machinery id never ride.
        self::assertTrue($this->postJson()['save_images']);
        self::assertArrayNotHasKey('save_to_disk', $this->postJson());
        self::assertArrayNotHasKey('id', $this->postJson());
    }

    public function testServerValidationErrorBecomesAnErrorResultNotAThrow(): void
    {
        $transport = new CallableSdTransport(function (string $method, string $path) {
            $this->calls[] = [$method, $path, []];
            if ($method === 'POST') {
                return ['status' => 422, 'body' => json_encode([
                    'success' => false,
                    'error' => 'RequestValidationError',
                    'detail' => [['loc' => ['body', 'steps'], 'msg' => 'ensure this value is less than 150']],
                ])];
            }

            return ['status' => 200, 'body' => json_encode(['state' => ['finished' => false]])];
        });
        $tool = $this->tool($transport);

        $result = $tool->execute(['prompt' => 'p', 'steps' => 400]);

        self::assertTrue($result->isError());
        self::assertStringContainsString('image generation failed', $result->content());
        self::assertStringContainsString('less than 150', $result->content());
    }

    public function testApiOwnedKeyIsRefusedBeforeAnyDial(): void
    {
        $tool = $this->tool($this->server([['state' => ['finished' => true]]], '{}'));

        $result = $tool->execute(['prompt' => 'p', 'sd_model' => 'takeover.safetensors']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('refused', $result->content());
        self::assertSame([], $this->calls);
    }

    public function testBlankPromptIsRefusedBeforeAnyDial(): void
    {
        $tool = $this->tool($this->server([['state' => ['finished' => true]]], '{}'));

        $result = $tool->execute(['prompt' => '   ']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('non-empty `prompt`', $result->content());
        self::assertSame([], $this->calls);
    }

    public function testExecuteWithoutHeartbeatProducesTheSameSuccessAsTheBeatingPath(): void
    {
        $server = fn () => $this->server(
            [['state' => ['finished' => true]]],
            $this->envelope([self::PNG_B64], ['plain']),
        );

        $plain = $this->tool($server())->execute(['prompt' => 'p']);
        $beating = $this->tool($server())->executeWithHeartbeat(
            ['prompt' => 'p'],
            static function (): void {
            },
        );

        self::assertFalse($plain->isError(), $plain->content());
        self::assertFalse($beating->isError(), $beating->content());
        self::assertTrue($plain->hasImage());
        self::assertTrue($beating->hasImage());
        self::assertSame($plain->imageBytes(), $beating->imageBytes());
    }

    public function testCatalogRegistersTheToolAtPositionThirtyThreeWithAskAndTheDeclaredConcerns(): void
    {
        $entry = null;
        foreach (ToolCatalog::entries() as $candidate) {
            if ($candidate->name === GenerateImage::NAME) {
                self::assertNull($entry, 'the catalog named GenerateImage twice');
                $entry = $candidate;
            }
        }
        self::assertNotNull($entry, 'GenerateImage is not in the built-in catalog');
        self::assertSame(33, $entry->position);
        self::assertSame(ToolPermissionClass::Ask, $entry->permission);
        self::assertNotSame('', $entry->gloss);
        self::assertSame(ToolPermissionClass::Ask, ToolCatalog::permissionOf(GenerateImage::NAME));

        $class = GenerateImage::class;
        self::assertTrue(is_subclass_of($class, AcceptsHeartbeat::class));
        self::assertTrue(is_subclass_of($class, DelegatesToEngine::class));
        self::assertTrue(is_subclass_of($class, TakesToolCallId::class));
        self::assertFalse(is_subclass_of($class, ParallelSafe::class), 'single-server queue law: not parallel-safe');
        self::assertTrue(is_subclass_of($class, TruncatesOutput::class) || in_array(TruncatesOutput::class, class_uses($class), true));

        // Rules grammar: the bare tool name parses and matches itself.
        $rule = new PermissionRule(GenerateImage::NAME, PermissionAction::Ask);
        self::assertSame('GenerateImage', $rule->toolNamePattern());
    }

    public function testUnboundEngineStillGeneratesUnderTheFallbackSessionFolder(): void
    {
        $tool = $this->tool($this->server(
            [['state' => ['finished' => true]]],
            $this->envelope([self::PNG_B64], ['x']),
        ));

        $result = $tool->execute(['prompt' => 'p']);

        self::assertFalse($result->isError(), $result->content());
        self::assertStringContainsString('/main/', str_replace('\\', '/', (string) $result->imagePath()));
    }

    // ---- fixtures ----------------------------------------------------------------

    /**
     * @param array<string, mixed> $settings
     */
    private function tool(CallableSdTransport $transport, array $settings = [], ?string $base = 'http://sd.test:7860'): GenerateImage
    {
        return GenerateImage::new()
            ->withFakeDial($transport, $base, $settings)
            ->withMediaRoot($this->mediaRoot)
            ->withLoopPacing(0.01, static function (float $seconds): void {
            });
    }

    /**
     * @param list<array<string, mixed>> $progressFrames frames served in order, last repeats
     * @param string                     $generationBody raw body for POST responses
     */
    private function server(array $progressFrames, string $generationBody): CallableSdTransport
    {
        return new CallableSdTransport(function (string $method, string $path, array $json) use (&$progressFrames, $generationBody) {
            $this->calls[] = [$method, $path, $json];
            if ($method === 'POST') {
                return ['status' => 200, 'body' => $generationBody];
            }
            $frame = count($progressFrames) > 0
                ? (count($progressFrames) > 1 ? array_shift($progressFrames) : $progressFrames[0])
                : ['state' => ['finished' => true]];

            return ['status' => 200, 'body' => json_encode($frame)];
        });
    }

    /**
     * @param list<string>           $infotexts
     * @param array<string, mixed>   $extraInfo
     */
    private function envelope(array $images, array $infotexts, array $extraInfo = []): string
    {
        return json_encode([
            'images' => $images,
            'parameters' => ['prompt' => 'echo'],
            'info' => json_encode($extraInfo + ['infotexts' => array_values($infotexts)]),
        ]);
    }

    /** @return array<string, mixed> */
    private function postJson(): array
    {
        foreach ($this->calls as $call) {
            if ($call[0] === 'POST') {
                return $call[2];
            }
        }
        self::fail('no POST was recorded');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $dir . '/' . $entry;
                is_dir($path) ? $this->rrmdir($path) : @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
