<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Media\Sd;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Media\MediaRequest;
use SugarCraft\Code\Media\Sd\CallableMultipartSdTransport;
use SugarCraft\Code\Media\Sd\CallableSdTransport;
use SugarCraft\Code\Media\Sd\Client;
use SugarCraft\Code\Media\Sd\SdException;
use SugarCraft\Code\Media\Sd\SdTransportResult;

/**
 * W7 adapter pins for the sglang-diffusion IMAGE dialect (plan C-1 flip,
 * wire names verified against the W0 openapi capture, never guessed):
 * the body speaks guidance_scale — NOT cfg_scale — num_inference_steps,
 * a composed "WxH" size and n; the adapter forces response_format=b64_json
 * unless the draft stated one; sdapi-only knobs are refused BY NAME before
 * a single byte leaves the process; the edits route decodes init_images
 * into image[] file parts over a multipart transport and refuses anything
 * that cannot carry them; and the ImageResponse envelope decodes inline
 * bytes strictly, surfacing url/file_path carries as markers instead of
 * following them.
 */
final class SglangImageTest extends TestCase
{
    private const BASE = 'http://sg.test:30001';

    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var list<array{0: string, 1: string, 2: array, 3?: mixed, 4?: mixed}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->calls = [];
        putenv(Client::BASE_URL_ENV);
    }

    // ------------------------------------------------------------------ body

    public function testTheBodySpeaksTheContractVocabularyNeverTheSdapiNames(): void
    {
        $body = Client::sglangImageBody([
            'prompt' => 'a cat',
            'negative_prompt' => 'blur',
            'seed' => 7,
            'batch_size' => 2,
            'steps' => 30,
            'cfg_scale' => 7.5,
            'width' => 1024,
            'height' => 512,
        ]);

        self::assertSame([
            'prompt' => 'a cat',
            'negative_prompt' => 'blur',
            'seed' => 7,
            'n' => 2,
            'num_inference_steps' => 30,
            'guidance_scale' => 7.5,
            'size' => '1024x512',
            'response_format' => 'b64_json',
        ], $body);
        foreach (['cfg_scale', 'steps', 'batch_size', 'width', 'height'] as $sdapiName) {
            self::assertArrayNotHasKey($sdapiName, $body, $sdapiName . ' must not ride this wire');
        }
    }

    public function testSchemaShapedUnknownsRideAndAStatedResponseFormatWins(): void
    {
        $body = Client::sglangImageBody([
            'prompt' => 'p',
            'true_cfg_scale' => 2.5,
            'model' => 'Wan2.2',
            'response_format' => 'url',
        ]);

        self::assertSame(2.5, $body['true_cfg_scale'], 'Q-10: the second guidance leg rides as the schema names it');
        self::assertSame('Wan2.2', $body['model']);
        self::assertSame('url', $body['response_format'], 'the draft may override the b64 default — the carry refusal is the caller-side answer');
    }

    public function testTheDtoPathProducesTheSameBodyAsTheArrayPath(): void
    {
        // GenerateImage hands the DTO, not a raw array — the Set-sentinel
        // toArray() must translate identically (and a widened float too).
        $request = MediaRequest::fromArray([
            'prompt' => 'a cat',
            'cfg_scale' => 7,
            'steps' => 20,
            'width' => 512,
            'height' => 512,
            'seed' => 3,
        ]);

        $body = Client::sglangImageBody($request);

        self::assertSame(7.0, $body['guidance_scale']);
        self::assertSame(20, $body['num_inference_steps']);
        self::assertSame('512x512', $body['size']);
        self::assertSame(3, $body['seed']);
    }

    public function testUnsetFieldsStayUnsetSoServerDefaultsSurvive(): void
    {
        $body = Client::sglangImageBody(['prompt' => 'only the prompt']);

        self::assertSame(['prompt' => 'only the prompt', 'response_format' => 'b64_json'], $body);
    }

    // -------------------------------------------------------------- refusals

    public function testRefusalsNameEverySdapiOnlyKnobInRosterOrder(): void
    {
        $names = Client::sglangImageRefusals([
            'prompt' => 'x',
            'enable_hr' => true,
            'hr_scale' => 1.5,
            'sampler_name' => 'Euler a',
            'save_images' => true,
            'width' => 512,
        ]);

        self::assertSame(['sampler_name', 'enable_hr', 'hr_scale', 'save_images', 'width/height'], $names);
    }

    public function testSizeAlongsideBothDimensionsIsADoubleSpecification(): void
    {
        $names = Client::sglangImageRefusals(['prompt' => 'x', 'width' => 64, 'height' => 64, 'size' => '128x128']);

        self::assertSame(['size + width/height'], $names);
    }

    public function testSaveImagesFalseIsANoopThatDrops(): void
    {
        self::assertSame([], Client::sglangImageRefusals(['prompt' => 'x', 'save_images' => false]));
        self::assertArrayNotHasKey('save_images', Client::sglangImageBody(['prompt' => 'x', 'save_images' => false]));
    }

    public function testInitImagesAreARouteChoiceNotARefusal(): void
    {
        // Edits carries them as file parts; the door must stay silent.
        self::assertSame([], Client::sglangImageRefusals(['prompt' => 'x', 'init_images' => [self::PNG_B64], 'mask' => self::PNG_B64]));
    }

    public function testTheBodyBuilderThrowsLoudlyAsDefenseInDepth(): void
    {
        try {
            Client::sglangImageBody(['prompt' => 'x', 'script_name' => 'X']);
            self::fail('the builder must not silently drop a set sdapi knob');
        } catch (SdException $e) {
            self::assertStringContainsString('script_name', $e->getMessage());
            self::assertStringContainsString('no sglang-diffusion field', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- dials

    public function testTheGenerateDialPostsJsonToTheGenerationsRoute(): void
    {
        $client = $this->client($this->jsonFake());

        $client->sglangImageGenerate(['prompt' => 'a cat']);

        self::assertCount(1, $this->calls);
        self::assertSame('POST', $this->calls[0][0]);
        self::assertSame('/v1/images/generations', $this->calls[0][1], 'the sdapi registry must never be dialed for sglang');
        self::assertSame('a cat', $this->calls[0][2]['prompt']);
    }

    public function testTheEditDialDecodesInitImagesIntoFilePartsAndStripsDataUris(): void
    {
        $png = (string) base64_decode(self::PNG_B64, true);
        $client = $this->client($this->multipartFake());

        $client->sglangImageEdit(MediaRequest::fromArray([
            'prompt' => 'restyle',
            'init_images' => ['data:image/png;base64,' . self::PNG_B64],
            'mask' => self::PNG_B64,
            'width' => 64,
            'height' => 64,
        ]));

        self::assertCount(1, $this->calls);
        [$method, $path, $fields, , $files] = $this->calls[0];
        self::assertSame('POST', $method);
        self::assertSame('/v1/images/edits', $path);
        self::assertSame('restyle', $fields['prompt']);
        self::assertSame('64x64', $fields['size']);
        self::assertSame(self::PNG_B64, $fields['mask'], 'the mask rides bare b64 like every other edge in this layer');
        self::assertArrayNotHasKey('init_images', $fields, 'binaries travel as file parts, never as fields');
        self::assertSame([['name' => 'image[]', 'filename' => 'init-1.png', 'contents' => $png]], $files);
    }

    public function testTheEditDialRefusesATransportThatCannotCarryMultipart(): void
    {
        $client = Client::withBase(self::BASE, new CallableSdTransport(
            function (string $method, string $path, array $json): array {
                $this->calls[] = [$method, $path, $json];

                return ['status' => 200, 'body' => '{}'];
            },
        ));

        try {
            $client->sglangImageEdit(['prompt' => 'x', 'init_images' => [self::PNG_B64]]);
            self::fail('the edits route must refuse a JSON-only transport');
        } catch (SdException $e) {
            self::assertStringContainsString('multipart-capable', $e->getMessage());
            self::assertStringContainsString(CallableSdTransport::class, $e->getMessage());
        }

        self::assertSame([], $this->calls, 'the refusal is pre-wire');
    }

    public function testANonStrictInitImageRefusesRatherThanShippingGarbage(): void
    {
        $client = $this->client($this->multipartFake());

        $this->expectException(SdException::class);
        $this->expectExceptionMessage('init_images[0] is not strict base64');

        $client->sglangImageEdit(['prompt' => 'x', 'init_images' => ['not!!b64']]);
    }

    public function testTheContentDialGetsTheRegistryComposedRoute(): void
    {
        $client = $this->client($this->jsonFake());

        $client->sglangImageContentBytes('img 42/x');

        self::assertSame(['GET', '/v1/images/img%2042%2Fx/content'], [$this->calls[0][0], $this->calls[0][1]]);
    }

    public function testANonSuccessGenerationBecomesTheOneErrorClass(): void
    {
        $client = $this->client(new CallableSdTransport(
            static fn (): array => ['status' => 422, 'body' => '{"detail":"size too large"}'],
        ));

        try {
            $client->sglangImageGenerate(['prompt' => 'x']);
            self::fail('non-2xx must raise SdException');
        } catch (SdException $e) {
            self::assertStringContainsString('sglang-txt2img', $e->getMessage());
            self::assertStringContainsString('size too large', $e->getMessage());
        }
    }

    // --------------------------------------------------------------- decode

    public function testDecodeYieldsInlineBytesUnderTheEnvelopeId(): void
    {
        $client = $this->client($this->jsonFake());
        $result = SdTransportResult::new(200, (string) json_encode([
            'id' => 'img-9',
            'data' => [['b64_json' => self::PNG_B64], ['b64_json' => "\t" . self::PNG_B64 . "\n"]],
        ]), 'application/json');

        $decoded = $client->sglangImagesDecode($result);

        self::assertSame('img-9', $decoded['id']);
        self::assertCount(2, $decoded['items']);
        self::assertSame(base64_decode(self::PNG_B64, true), $decoded['items'][0]['bytes']);
        self::assertSame(base64_decode(self::PNG_B64, true), $decoded['items'][1]['bytes'], 'outer whitespace is padding, not corruption');
    }

    public function testDecodeSurfacesCarriesAsMarkersNotFetches(): void
    {
        $client = $this->client($this->jsonFake());
        $result = SdTransportResult::new(200, (string) json_encode([
            'id' => 'i',
            'data' => [['url' => 'http://elsewhere/x.png'], ['file_path' => '/srv/out/y.png']],
        ]), 'application/json');

        self::assertSame(
            [['url' => 'http://elsewhere/x.png'], ['file_path' => '/srv/out/y.png']],
            $client->sglangImagesDecode($result)['items'],
        );
    }

    public function testDecodeRefusesEnvelopeShapedGarbage(): void
    {
        $client = $this->client($this->jsonFake());

        foreach ([
            '{"data":[{"b64_json":"' . self::PNG_B64 . '"}]}' => 'no usable image id',
            '{"id":"i","data":[]}' => 'carries no images',
            '{"id":"i","data":[{"b64_json":"zz!!not b64"}]}' => 'not strict base64',
            '{"id":"i","data":[{}]}' => 'carries no b64_json, url or file_path',
            '{"id":"i","data":["plain"]}' => 'data[0] is not an object',
        ] as $body => $why) {
            try {
                $client->sglangImagesDecode(SdTransportResult::new(200, (string) $body, 'application/json'));
                self::fail('expected refusal: ' . $why);
            } catch (SdException $e) {
                self::assertStringContainsString($why, $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------- fixtures

    private function client(CallableSdTransport|CallableMultipartSdTransport $transport): Client
    {
        return Client::withBase(self::BASE, $transport);
    }

    private function jsonFake(): CallableSdTransport
    {
        return new CallableSdTransport(function (string $method, string $path, array $json): array {
            $this->calls[] = [$method, $path, $json];

            return ['status' => 200, 'body' => '{"id":"cap-1","data":[{"b64_json":"' . self::PNG_B64 . '"}]}'];
        });
    }

    private function multipartFake(): CallableMultipartSdTransport
    {
        return new CallableMultipartSdTransport(
            function (string $method, string $path, array $body, array $query, ?float $timeout, ?array $fields = null, ?array $files = null): array {
                $this->calls[] = [$method, $path, $body, $fields, $files];

                return ['status' => 200, 'body' => '{"id":"cap-1","data":[{"b64_json":"' . self::PNG_B64 . '"}]}'];
            },
        );
    }
}
