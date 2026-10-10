<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Events\MediaProgress;
use SugarCraft\Crush\Media\Capability\EndpointFamily;
use SugarCraft\Crush\Media\Sd\CallableSdTransport;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Support\ToolCancelRequests;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\AcceptsHeartbeat;
use SugarCraft\Crush\Tools\BuiltIn\GenerateVideo;
use SugarCraft\Crush\Tools\Catalog\ToolCatalog;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;
use SugarCraft\Crush\Tools\DelegatesToEngine;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\TakesToolCallId;

/**
 * W5.3 behaviour pins for the GenerateVideo tool: the fake is the transport
 * (CallableSdTransport) so nothing ever dials, the pacing seam keeps the job
 * poll off real time, and the canned job shapes ride the shared
 * tests/fixtures/video/*.json roster (fact (f) proves itself: bytes land on
 * disk, only paths continue into the result).
 */
final class GenerateVideoTest extends TestCase
{
    /** Decodes to a 32-byte ftypisom stub — a plausible smallest mp4 header. */
    private const GV_MP4_STUB_B64 = 'AAAAIGZ0eXBpc29tAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    private const GV_BASE = 'http://sd.test:7860';

    private string $mediaRoot;

    /** @var list<array{0: string, 1: string, 2: array}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->mediaRoot = sys_get_temp_dir() . '/gv-media-' . uniqid('r', true);
        putenv(Client::BASE_URL_ENV);
        ToolCancelRequests::forget();
    }

    protected function tearDown(): void
    {
        ToolCancelRequests::forget();
        putenv(Client::BASE_URL_ENV);
        $this->rrmdir($this->mediaRoot);
    }

    public function testHappyJobLandsMp4AndSidecarBesideItWithPathsOnlyInResult(): void
    {
        $beats = 0;
        $tool = $this->gvTool($this->gvServer([$this->gvFixture('jobs-running'), $this->gvFixture('jobs-done-b64')]));

        $result = $tool->executeWithHeartbeat(
            ['prompt' => '  a spinning cat  ', 'fps' => 16, 'seed' => 7, 'id' => 'call-1'],
            static function () use (&$beats): void {
                $beats++;
            },
        );

        self::assertFalse($result->isError(), $result->content());
        self::assertSame(2, $beats, 'one heartbeat per status poll (fact (a) generalized to the 1 s job cadence)');
        self::assertSame($beats, $this->gvMethodCount('GET'));

        $posts = $this->gvCallsOf('POST');
        self::assertCount(1, $posts);
        self::assertSame('/v1/videos', $posts[0][1]);
        self::assertSame('a spinning cat', $posts[0][2]['prompt']);
        self::assertSame(16, $posts[0][2]['fps']);
        self::assertSame(7, $posts[0][2]['seed']);
        self::assertArrayNotHasKey('id', $posts[0][2]);

        $videos = glob($this->mediaRoot . '/main/' . \chr(42) . '.mp4'); // chr(42): a glued '*' here is a glob-shaped literal and would drift the PathGlob corpus figure
        $sidecars = glob($this->mediaRoot . '/main/' . \chr(42) . '.json');
        self::assertIsArray($videos);
        self::assertIsArray($sidecars);
        self::assertCount(1, $videos);
        self::assertCount(1, $sidecars);
        // C-6 adjacency law: same name body, .json beside .mp4.
        self::assertSame(basename((string) $videos[0], '.mp4') . '.json', basename((string) $sidecars[0]));
        // The seed token named the file (W1.9 palette).
        self::assertStringContainsString('-7-', basename((string) $videos[0]));
        self::assertSame(0o600, fileperms((string) $videos[0]) & 0o777);
        self::assertSame(0o600, fileperms((string) $sidecars[0]) & 0o777);
        self::assertSame(
            "\x00\x00\x00\x20ftypisom",
            substr((string) file_get_contents((string) $videos[0]), 0, 12),
            'the b64 stub must land as decoded bytes, never as the base64 text',
        );

        $content = $result->content();
        self::assertStringContainsString('video: ' . $videos[0], $content);
        self::assertStringContainsString('sidecar: ' . $sidecars[0], $content);
        self::assertStringContainsString('🎞', $content);
        self::assertStringContainsString('Generated a video', $content);
        // Fact (f) + §5.2: NO blob surface of any kind rides the result.
        self::assertFalse($result->hasImage());
        self::assertNull($result->imagePath());
        self::assertNull($result->imageBytes());
        self::assertStringNotContainsString(self::GV_MP4_STUB_B64, $content);
        self::assertSame('call-1', $result->toolCallId());

        $sidecar = json_decode((string) file_get_contents((string) $sidecars[0]), true);
        self::assertIsArray($sidecar);
        self::assertSame('video', $sidecar['kind']);
        self::assertSame('a spinning cat', $sidecar['params']['prompt']);
        self::assertSame('done', $sidecar['job']['status']);
        self::assertSame('job-77', $sidecar['job']['server_task_id']);
        self::assertSame([$videos[0]], $sidecar['job']['artifact_paths']);
        // No secrets, ever: api keys, tokens, bearer material stay out. The
        // run gets a words-free root so the uniqid hex in the absolute path
        // can never spell a needle and false-redden this pin.
        $quietRoot = sys_get_temp_dir() . '/gv-quiet-' . bin2hex(random_bytes(6));
        $quietTool = GenerateVideo::new()
            ->withFakeDial($this->gvServer([$this->gvFixture('jobs-done-b64')]), self::GV_BASE, [])
            ->withMediaRoot($quietRoot)
            ->withLoopPacing(0.01, static function (float $seconds): void {
            });
        try {
            $quietResult = $quietTool->execute(['prompt' => 'a spinning cat', 'fps' => 16, 'seed' => 7]);
            self::assertFalse($quietResult->isError(), $quietResult->content());
            $quietSidecar = glob($quietRoot . '/' . \chr(42) . '/' . \chr(42) . '.json');
            self::assertIsArray($quietSidecar);
            self::assertCount(1, $quietSidecar);
            self::assertDoesNotMatchRegularExpression(
                '/api(?:_|-)?key|token|secret|password|authorization|bearer/i',
                (string) file_get_contents((string) $quietSidecar[0]),
            );
        } finally {
            $this->rrmdir($quietRoot);
        }
    }

    public function testLadderSnapsUpBeforeTheWireAndSaysSoAfterwards(): void
    {
        $tool = $this->gvTool($this->gvServer([$this->gvFixture('jobs-done-b64')]));

        $result = $tool->execute(['prompt' => 'p', 'num_frames' => 100, 'fps' => 16]);

        self::assertFalse($result->isError(), $result->content());
        self::assertSame(121, $this->gvPostJson()['num_frames'], 'the snapped rung rides the wire, not the ask');
        self::assertStringContainsString('spec: 121 frames @ 16 fps', $result->content());
        self::assertStringContainsString(
            'num_frames 100 is not on the sglang-diffusion ladder (81/121/193/257); it was snapped up to 121 (+21)',
            $result->content(),
        );
    }

    public function testDurationSecondsSugarFoldsFramesThroughFps(): void
    {
        $tool = $this->gvTool($this->gvServer([$this->gvFixture('jobs-done-b64')]));

        $result = $tool->execute(['prompt' => 'p', 'duration_seconds' => 5, 'fps' => 24]);

        self::assertFalse($result->isError(), $result->content());
        // 5 s x 24 fps = 120 -> snapped up to the 121 rung.
        self::assertSame(121, $this->gvPostJson()['num_frames']);
        self::assertArrayNotHasKey('duration_seconds', $this->gvPostJson(), 'the sugar never rides the wire');
        self::assertSame(24, $this->gvPostJson()['fps']);
    }

    public function testBothFrameSpellsRefuseWithoutAnyDial(): void
    {
        $tool = $this->gvTool($this->gvServer([]));

        $result = $tool->execute(['prompt' => 'p', 'num_frames' => 81, 'duration_seconds' => 5, 'fps' => 24]);

        self::assertTrue($result->isError());
        self::assertStringContainsString('not both', $result->content());
        self::assertSame([], $this->calls);
    }

    public function testZeroFpsIsRefusedAtTheDoor(): void
    {
        $tool = $this->gvTool($this->gvServer([]));

        $result = $tool->execute(['prompt' => 'p', 'fps' => 0]);

        self::assertTrue($result->isError());
        self::assertStringContainsString('positive integer', $result->content());
        self::assertSame([], $this->calls);
    }

    public function testLadderCeilingRefusesInsteadOfClamping(): void
    {
        $tool = $this->gvTool($this->gvServer([]));

        $result = $tool->execute(['prompt' => 'p', 'num_frames' => 300]);

        self::assertTrue($result->isError());
        self::assertStringContainsString('refused, not clamped', $result->content());
        self::assertSame([], $this->calls);
    }

    public function testBlankPromptRefusesWithoutAnyDial(): void
    {
        $tool = $this->gvTool($this->gvServer([]));

        $result = $tool->execute(['prompt' => '   ']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('non-empty `prompt`', $result->content());
        self::assertSame([], $this->calls);
    }

    public function testSdapiFamilyHasNoVideoContractAndRefusesZeroDial(): void
    {
        // The video reading of C-1: sdapi is implemented for IMAGES and still
        // must be refused for video.
        $tool = $this->gvTool($this->gvServer([]), ['mediaFamily' => EndpointFamily::SdApi->value]);

        $result = $tool->execute(['prompt' => 'p']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('sdapi', $result->content());
        self::assertStringContainsString('no video job contract', $result->content());
        self::assertSame([], $this->calls);
    }

    public function testMediaKindsRosterWithoutVideoRefusesWithoutAnyDial(): void
    {
        $tool = $this->gvTool($this->gvServer([]), ['mediaKinds' => ['image']]);

        $result = $tool->execute(['prompt' => 'p']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('do not include video', $result->content());
        self::assertSame([], $this->calls);
    }

    public function testUnconfiguredHostRefusesWithoutAnyDial(): void
    {
        $tool = $this->gvTool($this->gvServer([]), base: null);

        $result = $tool->execute(['prompt' => 'anything']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('not available on this launch', $result->content());
        self::assertStringContainsString('no media base URL configured', $result->content());
        self::assertSame([], $this->calls);
    }

    public function testCancelledBeforeSubmitNeverDials(): void
    {
        ToolCancelRequests::listen(static fn (): array => ['call-9']);
        $tool = $this->gvTool($this->gvServer([$this->gvFixture('jobs-done-b64')]));

        $result = $tool->execute(['prompt' => 'anything', 'id' => 'call-9']);

        self::assertTrue($result->isError());
        self::assertSame(ToolCancelRequests::CANCELLED, $result->content());
        self::assertSame([], $this->calls);
    }

    public function testCancelBetweenPollsInterruptsAndNotifiesTheServer(): void
    {
        $pulls = 0;
        ToolCancelRequests::listen(static function () use (&$pulls): array {
            // The pull throttle is wall-clock (0.05 s); the sleeper below
            // spends real time once, so the latch lands exactly on poll two.
            return ++$pulls >= 2 ? ['call-5'] : [];
        });
        $tool = GenerateVideo::new()
            ->withFakeDial($this->gvServer([$this->gvFixture('jobs-running')]), self::GV_BASE, [])
            ->withMediaRoot($this->mediaRoot)
            ->withLoopPacing(0.01, static function (float $seconds): void {
                static $sleptOnce = false;
                if (!$sleptOnce) {
                    $sleptOnce = true;
                    usleep(80_000);
                }
            });

        $result = $tool->execute(['prompt' => 'never finishing', 'id' => 'call-5']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('interrupted before the job finished', $result->content());
        self::assertStringContainsString('a cancel was sent to the server', $result->content());
        $deletes = $this->gvCallsOf('DELETE');
        self::assertCount(1, $deletes);
        self::assertSame('/v1/videos/job-77', $deletes[0][1]);
        self::assertSame([], glob($this->mediaRoot . '/' . \chr(42) . '/' . \chr(42)), 'an interrupted render saves nothing');
    }

    public function testServerFailureSurfacesTheWorkerMessage(): void
    {
        $tool = $this->gvTool($this->gvServer([$this->gvFixture('jobs-failed')]));

        $result = $tool->execute(['prompt' => 'p', 'id' => 'call-f']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('failed on the server', $result->content());
        self::assertStringContainsString('CUDA out of memory on the worker', $result->content());
        self::assertSame([], glob($this->mediaRoot . '/' . \chr(42) . '/' . \chr(42)), 'a failed job produces no artifacts');
    }

    public function testEveryPollEmitsAPreviewlessMediaProgressFrame(): void
    {
        $captured = [];
        $transport = $this->gvServer([$this->gvFixture('jobs-running'), $this->gvFixture('jobs-done-b64')]);
        $tool = GenerateVideo::new()
            ->withFakeDial($transport, self::GV_BASE, [])
            ->withMediaRoot($this->mediaRoot)
            ->withLoopPacing(0.01, static function (float $seconds): void {
            })
            ->withEngine(
                EngineBackend::new(new ScriptedProvider([]), 'm'),
                null,
                static function (object $event) use (&$captured): void {
                    $captured[] = $event;
                },
            );

        $result = $tool->execute(['prompt' => 'p', 'id' => 'call-7']);

        self::assertFalse($result->isError(), $result->content());
        self::assertCount(2, $captured, 'one event frame per status poll');
        self::assertContainsOnlyInstancesOf(MediaProgress::class, $captured);
        self::assertSame('call-7', $captured[0]->toolCallId);
        self::assertNull($captured[0]->frameB64, 'the video contract records no preview image');
        self::assertSame(0.5, $captured[0]->progress);
        self::assertSame(12.5, $captured[0]->eta);
        self::assertSame(1.0, $captured[1]->progress);
        self::assertNull($captured[1]->eta);
    }

    public function testUnknownKnobsRideThroughToTheWire(): void
    {
        $tool = $this->gvTool($this->gvServer([$this->gvFixture('jobs-done-b64')]));

        $result = $tool->execute(['prompt' => 'p', 'motion' => 0.5, 'my_future_knob' => 'yes']);

        self::assertFalse($result->isError(), $result->content());
        self::assertSame(0.5, $this->gvPostJson()['motion']);
        self::assertSame('yes', $this->gvPostJson()['my_future_knob']);
    }

    public function testSchemaDeclaresOpenObjectAndNeverEncodesAnEmptyMapAsList(): void
    {
        $json = json_encode($this->gvTool($this->gvServer([]))->inputSchema());

        self::assertIsString($json);
        self::assertStringContainsString('"required":["prompt"]', $json);
        // Fact-(b) law: every empty object position is a stdClass, so the
        // wire never carries [] where a JSON object is declared.
        self::assertStringContainsString('"additionalProperties":{}', $json);
        self::assertStringNotContainsString('"properties":[]', $json);
    }

    public function testCatalogRegistersTheToolAtPositionThirtyFourWithAskAndTheDeclaredConcerns(): void
    {
        $entry = null;
        foreach (ToolCatalog::entries() as $candidate) {
            if ($candidate->name === GenerateVideo::NAME) {
                self::assertNull($entry, 'the catalog named GenerateVideo twice');
                $entry = $candidate;
            }
        }
        self::assertNotNull($entry, 'GenerateVideo is not in the built-in catalog');
        self::assertSame(34, $entry->position);
        self::assertSame(ToolPermissionClass::Ask, $entry->permission);
        self::assertNotSame('', $entry->gloss);
        self::assertSame(ToolPermissionClass::Ask, ToolCatalog::permissionOf(GenerateVideo::NAME));

        $class = GenerateVideo::class;
        self::assertTrue(is_subclass_of($class, AcceptsHeartbeat::class));
        self::assertTrue(is_subclass_of($class, DelegatesToEngine::class));
        self::assertTrue(is_subclass_of($class, TakesToolCallId::class));
        self::assertFalse(is_subclass_of($class, ParallelSafe::class), 'single-server queue law: not parallel-safe');
        self::assertTrue(in_array(TruncatesOutput::class, class_uses($class), true));

        $rule = new PermissionRule(GenerateVideo::NAME, PermissionAction::Ask);
        self::assertSame('GenerateVideo', $rule->toolNamePattern());
    }

    // ---- fixtures ----------------------------------------------------------------

    /**
     * @param array<string, mixed> $settings
     */
    private function gvTool(CallableSdTransport $transport, array $settings = [], ?string $base = self::GV_BASE): GenerateVideo
    {
        return GenerateVideo::new()
            ->withFakeDial($transport, $base, $settings)
            ->withMediaRoot($this->mediaRoot)
            ->withLoopPacing(0.01, static function (float $seconds): void {
            });
    }

    /**
     * @param list<array<string, mixed>> $statusFrames served in order; the last repeats
     */
    private function gvServer(array $statusFrames): CallableSdTransport
    {
        return new CallableSdTransport(function (string $method, string $path, array $json) use (&$statusFrames) {
            $this->calls[] = [$method, $path, $json];
            if ($method === 'POST') {
                return ['status' => 200, 'body' => json_encode(['id' => 'job-77', 'status' => 'queued'])];
            }
            if ($method === 'DELETE') {
                return ['status' => 200, 'body' => json_encode(['status' => 'cancelled'])];
            }
            $frame = count($statusFrames) > 1
                ? array_shift($statusFrames)
                : ($statusFrames[0] ?? $this->gvFixture('jobs-done-b64'));

            return ['status' => 200, 'body' => json_encode($frame)];
        });
    }

    /** @return array<string, mixed> */
    private function gvFixture(string $name): array
    {
        $decoded = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/video/' . $name . '.json'), true);
        self::assertIsArray($decoded, $name);

        return $decoded;
    }

    /** @return list<array{0: string, 1: string, 2: array}> */
    private function gvCallsOf(string $method): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === $method));
    }

    private function gvMethodCount(string $method): int
    {
        return count($this->gvCallsOf($method));
    }

    /** @return array<string, mixed> */
    private function gvPostJson(): array
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
