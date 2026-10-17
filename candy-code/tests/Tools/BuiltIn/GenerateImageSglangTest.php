<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Media\Capability\EndpointFamily;
use SugarCraft\Code\Media\Png;
use SugarCraft\Code\Media\Sd\CallableMultipartSdTransport;
use SugarCraft\Code\Media\Sd\CallableSdTransport;
use SugarCraft\Code\Media\Sd\Client;
use SugarCraft\Code\Support\ToolCancelRequests;
use SugarCraft\Code\Tools\BuiltIn\GenerateImage;

/**
 * W7 tool-door pins for the sglang-diffusion image dialect: the declared
 * family now walks a GENERATE path (never the sdapi body builder — every
 * dial lands on /v1/images routes and the sdapi namespace is never touched),
 * sdapi-only knobs refuse pre-dial with zero bytes on the wire, the edits
 * route fires when init images ride the draft, and a url/file_path carry
 * refuses the whole batch instead of half-saving it.
 */
final class GenerateImageSglangTest extends TestCase
{
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $mediaRoot;

    /** @var list<array{0: string, 1: string, 2: array}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->mediaRoot = sys_get_temp_dir() . '/gis-media-' . uniqid('r', true);
        putenv(Client::BASE_URL_ENV);
        ToolCancelRequests::forget();
    }

    protected function tearDown(): void
    {
        ToolCancelRequests::forget();
        putenv(Client::BASE_URL_ENV);
        $this->rrmdir($this->mediaRoot);
    }

    public function testTheDeclaredFamilyDialsTheGenerationsRouteWithContractNames(): void
    {
        $beats = 0;
        $tool = $this->tool($this->server('{"id":"cap-1","data":[{"b64_json":"' . self::PNG_B64 . '"}]}'));

        $result = $tool->executeWithHeartbeat(
            ['prompt' => 'a cat', 'cfg_scale' => 7.5, 'steps' => 30, 'width' => 512, 'height' => 512, 'seed' => 7, 'id' => 'call-1'],
            function () use (&$beats): void {
                $beats++;
            },
        );

        self::assertFalse($result->isError(), $result->content());
        self::assertSame(2, $beats, 'one beat brackets each side of the single blocking POST — no ProgressLoop on this dialect');
        self::assertCount(1, $this->calls);
        self::assertSame('POST', $this->calls[0][0]);
        self::assertSame('/v1/images/generations', $this->calls[0][1]);
        self::assertSame(7.5, $this->calls[0][2]['guidance_scale']);
        self::assertArrayNotHasKey('cfg_scale', $this->calls[0][2]);
        self::assertSame(30, $this->calls[0][2]['num_inference_steps']);
        self::assertSame('512x512', $this->calls[0][2]['size']);
        self::assertSame('b64_json', $this->calls[0][2]['response_format']);
        self::assertStringContainsString('Generated 1 image on the sglang-diffusion server', $result->content());
        self::assertTrue($result->hasImage());
        self::assertFileExists((string) $result->imagePath());
        // No server infotext exists on this wire: OUR emission rode the chunk.
        $embedded = Png::readText((string) $result->imageBytes(), Png::KEYWORD_PARAMETERS);
        self::assertIsString($embedded);
        self::assertStringContainsString('a cat', $embedded);
        self::assertStringContainsString('Steps: 30', $embedded);
    }

    public function testSdapiOnlyKnobsRefusePreDialByName(): void
    {
        $tool = $this->tool($this->server('{"id":"cap-1","data":[{"b64_json":"' . self::PNG_B64 . '"}]}'));

        $result = $tool->execute([
            'prompt' => 'x',
            'sampler_name' => 'Euler a',
            'enable_hr' => true,
            'save_images' => true,
        ]);

        self::assertTrue($result->isError());
        self::assertStringContainsString('sampler_name', $result->content());
        self::assertStringContainsString('enable_hr', $result->content());
        self::assertStringContainsString('save_images', $result->content());
        self::assertStringContainsString('none were sent', $result->content());
        self::assertSame([], $this->calls, 'the knob door closes before the client is even built');
    }

    public function testInitImagesRouteTheEditDialOverMultipartParts(): void
    {
        $tool = $this->tool($this->server('{"id":"cap-1","data":[{"b64_json":"' . self::PNG_B64 . '"}]}'));

        $result = $tool->execute([
            'prompt' => 'restyle',
            'init_images' => [self::PNG_B64],
        ]);

        self::assertFalse($result->isError(), $result->content());
        self::assertSame('/v1/images/edits', $this->calls[0][1]);
        self::assertStringNotContainsString('/sdapi/v1/', $this->calls[0][1]);
    }

    public function testACarryResponseRefusesTheWholeBatchAndSavesNothing(): void
    {
        $tool = $this->tool($this->server('{"id":"cap-1","data":[{"url":"http://elsewhere/x.png"},{"b64_json":"' . self::PNG_B64 . '"}]}'));

        $result = $tool->execute(['prompt' => 'x']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('url references instead of inline bytes', $result->content());
        self::assertStringContainsString('nothing was saved', $result->content());
        self::assertDirectoryDoesNotExist($this->mediaRoot, 'the pre-scan fires before the store mkdir');
    }

    public function testCancelledBeforeStartNeverDials(): void
    {
        ToolCancelRequests::listen(static fn (): array => ['call-9']);
        $tool = $this->tool($this->server('{"id":"cap-1","data":[{"b64_json":"' . self::PNG_B64 . '"}]}'));

        $result = $tool->execute(['prompt' => 'anything', 'id' => 'call-9']);

        self::assertTrue($result->isError());
        self::assertSame(ToolCancelRequests::CANCELLED, $result->content());
        self::assertSame([], $this->calls);
    }

    public function testTheFamilyDoorStillRefusesUnimplementedFamiliesByName(): void
    {
        // Companion to GenerateImageTest's flipped pin: sglang left the
        // refuse-set, comfyui did not.
        $tool = $this->tool($this->server('{}'), ['mediaFamily' => EndpointFamily::ComfyUi->value]);

        $result = $tool->execute(['prompt' => 'x']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('comfyui', $result->content());
        self::assertStringContainsString('sdapi and sglang-diffusion', $result->content());
        self::assertSame([], $this->calls);
    }

    // -------------------------------------------------------------- fixtures

    /**
     * @param array<string, mixed> $settings
     */
    private function tool(
        CallableSdTransport|CallableMultipartSdTransport $transport,
        array $settings = ['mediaFamily' => 'sglang-diffusion'],
        ?string $base = 'http://sg.test:30001',
    ): GenerateImage {
        return GenerateImage::new()
            ->withFakeDial($transport, $base, $settings)
            ->withMediaRoot($this->mediaRoot)
            ->withLoopPacing(0.01, static function (float $seconds): void {
            });
    }

    private function server(string $generationBody): CallableMultipartSdTransport
    {
        return new CallableMultipartSdTransport(
            function (string $method, string $path, array $json) use ($generationBody): array {
                $this->calls[] = [$method, $path, $json];

                return ['status' => 200, 'body' => $generationBody];
            },
        );
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
