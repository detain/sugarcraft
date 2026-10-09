<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Media;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\Crush\Media\Capability\CapabilityDiscoverer;
use SugarCraft\Crush\Media\Capability\EndpointFamily;
use SugarCraft\Crush\Media\Capability\MediaCapability;
use SugarCraft\Crush\Media\MediaKind;
use SugarCraft\Crush\Media\Sd\CallableSdTransport;
use SugarCraft\Crush\Media\Sd\NullTransport;
use SugarCraft\Crush\Media\Sd\SdTransportResult;

/**
 * Plan W1.2 gates: the discovery ladder on canned transports only — no
 * sockets, no timers (the 3.0s ceiling is the transport's contract, asserted
 * here as a constant, never exercised as wall-clock). Fail-open and
 * config-authority are the two laws this file exists to keep honest.
 */
final class CapabilityDiscovererTest extends TestCase
{
    /**
     * @param array<string, array{status:int, body:string, contentType?:string}> $answers
     */
    private static function responder(array $answers): CallableSdTransport
    {
        return new CallableSdTransport(
            static fn (string $method, string $path): SdTransportResult => self::answerResult(
                $answers[$path] ?? ['status' => 404, 'body' => 'not found', 'contentType' => 'text/plain']
            )
        );
    }

    /**
     * @param array{status:int, body:string, contentType?:string} $answer
     */
    private static function answerResult(array $answer): SdTransportResult
    {
        return SdTransportResult::new($answer['status'], $answer['body'], $answer['contentType'] ?? 'application/json');
    }

    private static function sdapiAnswers(): array
    {
        return ['/sdapi/v1/cmd-flags' => ['status' => 200, 'body' => '[]']];
    }

    /** A transport that dials and every attempt blows up (refused/timed out). */
    private static function throwing(): CallableSdTransport
    {
        return new CallableSdTransport(static function (): never {
            throw new RuntimeException('connection refused');
        });
    }

    public function testProbeIdentifiesSdapiFromCmdFlags(): void
    {
        $capability = (new CapabilityDiscoverer())->probe(self::responder(self::sdapiAnswers()));

        $this->assertInstanceOf(MediaCapability::class, $capability);
        $this->assertSame(EndpointFamily::SdApi, $capability->family());
        $this->assertSame([MediaKind::Image], $capability->kinds());
        $this->assertTrue($capability->isProbed());
        $this->assertFalse($capability->isDeclared());
        $this->assertTrue($capability->declares(MediaCapability::SUPPORT_TXT2IMG));
        $this->assertTrue($capability->declares(MediaCapability::SUPPORT_IMG2IMG));
        $this->assertTrue($capability->declares(MediaCapability::SUPPORT_INPAINT));
        $this->assertTrue($capability->declares(MediaCapability::SUPPORT_PROGRESS));
        // Honest v1 floor knowledge: upscale and video are explicitly false,
        // not merely absent (crush_media §4.6, Appendix C-1).
        $this->assertFalse($capability->declares(MediaCapability::SUPPORT_UPSCALE));
        $this->assertFalse($capability->declares(MediaCapability::SUPPORT_VIDEO));
    }

    public function testProbeIdentifiesOpenaiImagesWhenOnlyModelsRouteAnswers(): void
    {
        $capability = (new CapabilityDiscoverer())->probe(self::responder([
            '/v1/models' => ['status' => 200, 'body' => '{"data":[{"id":"sd","object":"model"}]}'],
        ]));

        $this->assertSame(EndpointFamily::OpenAiImages, $capability?->family());
        $this->assertSame([MediaKind::Image], $capability?->kinds());
        $this->assertTrue($capability?->declares(MediaCapability::SUPPORT_TXT2IMG));
        $this->assertFalse($capability?->declares(MediaCapability::SUPPORT_VIDEO));
    }

    public function testProbeIdentifiesComfyUiFromObjectInfo(): void
    {
        $capability = (new CapabilityDiscoverer())->probe(self::responder([
            '/object_info' => ['status' => 200, 'body' => '{"KSampler":{"input":{}}}'],
        ]));

        $this->assertSame(EndpointFamily::ComfyUi, $capability?->family());
        $this->assertSame([MediaKind::Image], $capability?->kinds());
        $this->assertTrue($capability?->isProbed());
    }

    /** The W0.1 skynet2 shape verbatim: video-only Wan2.2-T2V, is_image_gen false. */
    public function testProbeIdentifiesSglangVideoOnlyShapeFromW0(): void
    {
        $capability = (new CapabilityDiscoverer())->probe(self::responder([
            '/model_info' => [
                'status' => 200,
                'body' => json_encode([[
                    'model' => 'Wan2.2-T2V-A14B',
                    'task_type' => 'text-to-video',
                    'supported_task_types' => ['text-to-video'],
                    'is_image_gen' => false,
                ]], JSON_THROW_ON_ERROR),
            ],
        ]));

        $this->assertSame(EndpointFamily::SglangDiffusion, $capability?->family());
        $this->assertSame([MediaKind::Video], $capability?->kinds());
        $this->assertTrue($capability?->declares(MediaCapability::SUPPORT_VIDEO));
        $this->assertFalse($capability?->declares(MediaCapability::SUPPORT_TXT2IMG));
        // Appendix C-1 ruling: detectable, and still refused downstream.
        $this->assertFalse($capability?->family()->isImplemented());
    }

    /** Ladder order pin: sglang also serves /v1/models (W0.1) — model_info must win. */
    public function testSglangIsProbedBeforePlainOpenai(): void
    {
        $capability = (new CapabilityDiscoverer())->probe(self::responder([
            '/model_info' => [
                'status' => 200,
                'body' => json_encode([['model' => 'Wan', 'task_type' => 'text-to-image', 'is_image_gen' => true]]),
            ],
            '/v1/models' => ['status' => 200, 'body' => '{"data":[]}'],
        ]));

        $this->assertSame(EndpointFamily::SglangDiffusion, $capability?->family());
        $this->assertSame([MediaKind::Image], $capability?->kinds());
    }

    public function testProbeAnswersNullWhenNothingRecognisesTheServer(): void
    {
        $discoverer = new CapabilityDiscoverer();

        $this->assertNull($discoverer->probe(self::responder([])));
        // 2xx but un-parseable bodies must not hallucinate a dialect.
        $this->assertNull($discoverer->probe(self::responder([
            '/sdapi/v1/cmd-flags' => ['status' => 200, 'body' => '<html>proxy error</html>', 'contentType' => 'text/html'],
            '/object_info' => ['status' => 500, 'body' => '{}'],
            '/model_info' => ['status' => 200, 'body' => '[]'],
            '/v1/models' => ['status' => 404, 'body' => 'nope'],
        ])));
    }

    /** Fail-open law: a transport that throws on every dial yields absence, never a Throwable. */
    public function testProbeFailOpenOnEveryTransportThrow(): void
    {
        $this->assertNull((new CapabilityDiscoverer())->probe(self::throwing()));
    }

    /** Fail-open law, declared half: the throw storm must leave the declaration byte-intact. */
    public function testDeclaredCapabilitySurvivesAThrowingTransportUntouched(): void
    {
        $discoverer = new CapabilityDiscoverer();
        $cfg = ['mediaKinds' => ['image'], 'mediaBaseUrl' => 'http://127.0.0.1:7860'];

        $survived = $discoverer->forProvider($cfg, self::throwing());
        $plain = $discoverer->forProvider($cfg);

        $this->assertNotNull($survived);
        $this->assertSame($plain?->family(), $survived->family());
        $this->assertSame($plain?->kinds(), $survived->kinds());
        $this->assertSame($plain?->baseUrl(), $survived->baseUrl());
        $this->assertSame([], $survived->supports());
        $this->assertSame([], $survived->bounds());
        $this->assertTrue($survived->isDeclared());
        $this->assertFalse($survived->isProbed());
    }

    /** Config-authority law (§10.1-7): the probe may fill, never negate. */
    public function testProbeCanNeverNegateADeclaredSupportFlag(): void
    {
        $declared = MediaCapability::new(EndpointFamily::SdApi)
            ->markDeclared()
            ->withSupport(MediaCapability::SUPPORT_TXT2IMG, false);

        $probe = (new CapabilityDiscoverer())->probe(self::responder(self::sdapiAnswers()));
        $this->assertNotNull($probe);
        $merged = $declared->mergedFrom($probe);

        $this->assertFalse($merged->declares(MediaCapability::SUPPORT_TXT2IMG), 'a human denial must stand over a probe affirmation');
        // The keys the declaration left blank are still filled by the probe.
        $this->assertTrue($merged->declares(MediaCapability::SUPPORT_PROGRESS));
        $this->assertTrue($merged->isProbed());
        $this->assertTrue($merged->isDeclared());
        $this->assertTrue($declared->isDeclared());
        $this->assertFalse($declared->isProbed(), 'merging must not mutate the declaration');
    }

    public function testExplicitMediaFamilyWinsOverTheProbeIdentity(): void
    {
        $capability = (new CapabilityDiscoverer())->forProvider(
            ['mediaKinds' => ['image', 'video'], 'mediaFamily' => 'openai-images'],
            self::responder(self::sdapiAnswers()),
        );

        $this->assertSame(EndpointFamily::OpenAiImages, $capability?->family());
        $this->assertSame([MediaKind::Image, MediaKind::Video], $capability?->kinds());
        $this->assertTrue($capability?->isDeclared());
        $this->assertTrue($capability?->isProbed());
        // sdapi's answers rode in as fills without touching identity.
        $this->assertTrue($capability?->declares(MediaCapability::SUPPORT_IMG2IMG));
    }

    public function testForProviderDefaultsDeclaredFamilyToTheImplementedSdApi(): void
    {
        $capability = (new CapabilityDiscoverer())->forProvider(['mediaKinds' => ['video']]);

        $this->assertSame(EndpointFamily::SdApi, $capability?->family());
        $this->assertSame([MediaKind::Video], $capability?->kinds());
        $this->assertTrue($capability?->isDeclared());
        $this->assertFalse($capability?->isProbed());
    }

    public function testNothingDeclaredAndNoTransportResolvesToNull(): void
    {
        $this->assertNull((new CapabilityDiscoverer())->forProvider([]));
        $this->assertNull((new CapabilityDiscoverer())->forProvider(['baseUrl' => 'http://x:30000']));
    }

    public function testApiKeyRefFlowsFromConfigAsAReferenceName(): void
    {
        $capability = (new CapabilityDiscoverer())->forProvider([
            'mediaKinds' => ['image'],
            'mediaApiKey' => '${SD_KEY}',
            'apiKey' => '${IGNORED}',
        ]);

        $this->assertTrue($capability?->apiKeyRefIsSet());
        $this->assertSame('${SD_KEY}', $capability?->apiKeyRef(), 'mediaApiKey must shadow the provider-generic apiKey (mystage §6.3 vocabulary)');
    }

    /**
     * @return array<string, array{array<string,mixed>, string}>
     */
    public static function invalidConfigProvider(): array
    {
        return [
            'scalar mediaKinds' => [['mediaKinds' => 'image'], 'mediaKinds must be a list'],
            'unknown modality' => [['mediaKinds' => ['audio']], 'not a known modality'],
            'unknown family' => [['mediaKinds' => ['image'], 'mediaFamily' => 'nope'], 'not a known endpoint family'],
            'non-string family' => [['mediaKinds' => ['image'], 'mediaFamily' => 42], 'mediaFamily must be a string'],
            'non-string base url' => [['mediaKinds' => ['image'], 'mediaBaseUrl' => 42], 'base URL setting must be a string'],
        ];
    }

    #[DataProvider('invalidConfigProvider')]
    public function testInvalidDeclaredConfigThrowsDescriptively(array $cfg, string $needle): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($needle, '/') . '/');

        (new CapabilityDiscoverer())->forProvider($cfg);
    }

    public function testEmptyMediaKindsListMeansNoDeclaration(): void
    {
        $this->assertNull((new CapabilityDiscoverer())->forProvider(['mediaKinds' => []]));
    }

    public function testDiagnoseReportsPerRouteVerdictsWithoutThrowing(): void
    {
        $discoverer = new CapabilityDiscoverer();

        $silent = $discoverer->diagnose([], null);
        $this->assertSame('none', $silent['config']);
        $this->assertSame(['not-probed', 'not-probed', 'not-probed', 'not-probed'], array_values(array_slice($silent, 1)));

        $sdapiOnly = $discoverer->diagnose(['mediaKinds' => ['image']], self::responder(self::sdapiAnswers()));
        $this->assertSame('declared', $sdapiOnly['config']);
        $this->assertSame('ok', $sdapiOnly['sdapi']);
        $this->assertSame('absent', $sdapiOnly['comfyui']);
        $this->assertSame('absent', $sdapiOnly['sglang-diffusion']);
        $this->assertSame('absent', $sdapiOnly['openai-images']);

        $broken = $discoverer->diagnose([], self::throwing());
        $this->assertSame(['error', 'error', 'error', 'error'], array_values(array_slice($broken, 1)));

        $invalid = $discoverer->diagnose(['mediaKinds' => 'image'], null);
        $this->assertStringStartsWith('invalid: ', $invalid['config']);
    }

    public function testDiscoveryVocabularyConstantsArePinned(): void
    {
        // The 3.0s ceiling is contract for W1.3's Guzzle transport to honour —
        // pinned here as a value, never exercised with a real timer.
        $this->assertSame(3.0, CapabilityDiscoverer::DISCOVERY_TIMEOUT_SECONDS);
        $this->assertSame([
            'sdapi' => '/sdapi/v1/cmd-flags',
            'comfyui' => '/object_info',
            'sglang-diffusion' => '/model_info',
            'openai-images' => '/v1/models',
        ], CapabilityDiscoverer::ROUTES);
    }

    public function testEndpointFamilyRosterAndImplementedGate(): void
    {
        $this->assertSame(
            ['sdapi', 'openai-images', 'sglang-diffusion', 'comfyui', 'custom', 'unknown'],
            array_map(static fn (EndpointFamily $f): string => $f->value, EndpointFamily::cases())
        );
        $this->assertTrue(EndpointFamily::SdApi->isImplemented());
        $this->assertFalse(EndpointFamily::SglangDiffusion->isImplemented(), 'C-1: detectable but unimplemented v1');
        foreach ([EndpointFamily::OpenAiImages, EndpointFamily::ComfyUi, EndpointFamily::Custom, EndpointFamily::Unknown] as $family) {
            $this->assertFalse($family->isImplemented());
        }
        $this->assertSame(EndpointFamily::SglangDiffusion, EndpointFamily::tryFrom('sglang-diffusion'));
    }

    public function testTransportResultPredicates(): void
    {
        $this->assertFalse(SdTransportResult::new(199, '')->is2xx());
        $this->assertTrue(SdTransportResult::new(200, '')->is2xx());
        $this->assertTrue(SdTransportResult::new(299, '')->is2xx());
        $this->assertFalse(SdTransportResult::new(300, '')->is2xx());

        $this->assertTrue(SdTransportResult::new(200, '[]', 'application/json')->isJson());
        $this->assertTrue(SdTransportResult::new(200, '[]', 'application/json; charset=utf-8')->isJson());
        $this->assertTrue(SdTransportResult::new(200, '[]', 'application/ld+json')->isJson());
        $this->assertFalse(SdTransportResult::new(200, '[]', 'text/html')->isJson());
        $this->assertFalse(SdTransportResult::new(200, '[]', '')->isJson());

        $this->assertSame([1, 2], SdTransportResult::new(200, '[1,2]', 'application/json')->decodedJson());
        $this->assertSame(['a' => 1], SdTransportResult::new(200, '{"a":1}', 'application/json')->decodedJson());
        $this->assertNull(SdTransportResult::new(200, 'garbage', 'application/json')->decodedJson());
        $this->assertNull(SdTransportResult::new(200, '"str"', 'application/json')->decodedJson(), 'scalars are not capability evidence');
        $this->assertSame([], SdTransportResult::new(200, '[]', 'text/html')->decodedJson(), 'decode is content-type agnostic by contract; isJson() is the caller-side gate');
    }

    public function testCallableTransportShorthandAndGuards(): void
    {
        $captured = [];
        $transport = new CallableSdTransport(
            static function (string $method, string $path, array $json, array $query) use (&$captured): array {
                $captured = [$method, $path, $json, $query];

                return ['status' => 200, 'body' => '{}'];
            }
        );

        $result = $transport->request('POST', '/sdapi/v1/txt2img', ['prompt' => 'p']);
        $this->assertSame(200, $result->status);
        $this->assertSame('application/json', $result->contentType, 'shorthand default');
        $this->assertSame(['POST', '/sdapi/v1/txt2img', ['prompt' => 'p'], []], $captured);

        $typed = new CallableSdTransport(
            static fn (): SdTransportResult => SdTransportResult::new(201, 'x', 'text/plain')
        );
        $this->assertSame('text/plain', $typed->request('GET', '/y')->contentType);

        $garbage = new CallableSdTransport(static fn (): string => 'nope');
        $this->expectException(InvalidArgumentException::class);
        $garbage->request('GET', '/y');
    }

    public function testNullTransportRefusesEveryDial(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NullTransport');

        (new NullTransport())->request('GET', '/sdapi/v1/cmd-flags');
    }

    public function testSupportAndBoundRostersAreClosedVocabularies(): void
    {
        $capability = MediaCapability::new(EndpointFamily::SdApi);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a modelled support flag');

        $capability->withSupport('hyperscale', true);
    }
}
