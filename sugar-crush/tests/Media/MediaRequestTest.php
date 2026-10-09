<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Media;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\GenerationParams;
use SugarCraft\Crush\Media\MediaArtifact;
use SugarCraft\Crush\Media\MediaKind;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Media\MediaResponse;

/**
 * Plan W1.1 gates for the wire-facing DTOs: sentinel semantics (unset != 0,
 * explicit null != absent), the three domain refusals, unknown-key tolerance,
 * the API_NOT_ALLOWED / NOT_SUPPORTED_V1 doors, canonical byte-stable wire
 * order, the params↔request composer bridge, and the artifact/response shapes
 * including the bytes-never-cross-wire law.
 */
final class MediaRequestTest extends TestCase
{
    public function testFreshRequestSerialisesToEmptyBody(): void
    {
        self::assertSame([], MediaRequest::new()->toArray());
        self::assertSame([], MediaRequest::new()->setFields());
    }

    public function testUnsetIsNotZeroForSeedAndSendImages(): void
    {
        $withZero = MediaRequest::new()->withSeed(0);

        // The §4.2 default is -1 (random): a never-touched request must NOT emit
        // seed at all, while an explicitly pinned seed=0 must survive intact.
        self::assertSame([], MediaRequest::new()->toArray());
        self::assertSame(['seed' => 0], $withZero->toArray());
        self::assertSame(0, $withZero->seed());
        self::assertTrue($withZero->isSet('seed'));
        self::assertFalse($withZero->isSet('send_images'));
    }

    public function testExplicitNullMeansSetWithNullNotAbsent(): void
    {
        // restore_faces None → "opts decides" is a real third state (§4.2).
        $request = MediaRequest::new()->withRestoreFaces(null);

        self::assertNull($request->restoreFaces());
        self::assertTrue($request->isSet('restore_faces'));
        self::assertSame(['restore_faces' => null], $request->toArray());
        $parsed = MediaRequest::fromArray(['restore_faces' => null]);
        self::assertTrue($parsed->isSet('restore_faces'));
        self::assertSame($request->toArray(), $parsed->toArray());
    }

    public function testWithersReturnNewInstancesAndLeaveOriginalUntouched(): void
    {
        $base = MediaRequest::new()->withSteps(20);
        $derived = $base->withSteps(50);

        self::assertNotSame($base, $derived);
        self::assertSame(20, $base->steps());
        self::assertSame(50, $derived->steps());
    }

    public function testStepsZeroIsLegalButSetNoClampingAnywhere(): void
    {
        // Wave-3 forms own coercion (plan W1.1); the DTO only REFUSES domain
        // nonsense on the three guarded fields.
        $request = MediaRequest::new()->withSteps(0)->withCfgScale(-1.0)->withInpaintingFill(7);

        self::assertSame(0, $request->steps());
        self::assertSame(-1.0, $request->cfgScale());
        self::assertSame(7, $request->inpaintingFill());
    }

    /** @return list<array{0: callable, 1: string}> */
    public static function guardRefusalProvider(): array
    {
        return [
            'batch_size zero' => [static fn () => MediaRequest::new()->withBatchSize(0), 'batch_size'],
            'batch_size negative' => [static fn () => MediaRequest::new()->withBatchSize(-3), 'batch_size'],
            'n_iter zero' => [static fn () => MediaRequest::new()->withNIter(0), 'n_iter'],
            'subseed_strength above one' => [static fn () => MediaRequest::new()->withSubseedStrength(1.5), 'subseed_strength'],
            'subseed_strength below zero' => [static fn () => MediaRequest::new()->withSubseedStrength(-0.25), 'subseed_strength'],
        ];
    }

    /** @dataProvider guardRefusalProvider */
    public function testGuardedFieldsRefuseDomainNonsense(callable $attempt, string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($field);
        $attempt();
    }

    public function testGuardRefusalsNameTheOffendingValue(): void
    {
        // R1 MINOR-3 twin pin: the request-level guards must carry the real
        // offending value, not the literal ".$value" of the old concat bug.
        $attempts = [
            [static fn () => MediaRequest::new()->withBatchSize(-3), 'given -3'],
            [static fn () => MediaRequest::new()->withSubseedStrength(1.5), 'given 1.5'],
            [static fn () => MediaRequest::new()->withSubseedStrength(-0.25), 'given -0.25'],
        ];
        foreach ($attempts as [$attempt, $fragment]) {
            try {
                $attempt();
                self::fail("expected a refusal mentioning '{$fragment}'");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($fragment, $e->getMessage());
            }
        }
    }

    public function testGuardBoundariesAreInclusive(): void
    {
        $request = MediaRequest::new()->withBatchSize(1)->withNIter(1)->withSubseedStrength(1.0);

        self::assertSame(1, $request->batchSize());
        self::assertSame(1.0, $request->subseedStrength());
        self::assertSame(0.0, MediaRequest::new()->withSubseedStrength(0.0)->subseedStrength());
    }

    public function testInt64SeedSurvivesWithoutFloatCrossing(): void
    {
        $big = PHP_INT_MAX - 1000; // realistic sdapi int64 seed
        $request = MediaRequest::new()->withSeed($big);

        self::assertSame($big, $request->seed());
        self::assertSame($big, $request->toArray()['seed']);
        $wire = json_decode((string) json_encode($request->toArray()), true);
        self::assertSame(['seed' => $big], $wire);
    }

    public function testToArrayEmitsCanonicalWireOrderRegardlessOfSetOrder(): void
    {
        $request = MediaRequest::new()
            ->withHeight(768)
            ->withPrompt('a dog')
            ->withWidth(512)
            ->withCfgScale(7.0)
            ->withMaskRound(false);

        self::assertSame(
            ['prompt', 'cfg_scale', 'width', 'height', 'mask_round'],
            array_keys($request->toArray())
        );
    }

    public function testRoundTripIsByteStableAcrossParseEmitParse(): void
    {
        $wire = [
            'prompt' => 'cat',
            'negative_prompt' => 'blurry',
            'styles' => ['none'],
            'seed' => -1,
            'subseed_strength' => 0.5,
            'sampler_name' => 'DPM++ 2M',
            'batch_size' => 2,
            'n_iter' => 3,
            'steps' => 25,
            'cfg_scale' => 7,
            'width' => 512,
            'height' => 512,
            'enable_hr' => true,
            'hr_scale' => 2.0,
            'denoising_strength' => 0.35,
            'restore_faces' => null,
            'alwayson_scripts' => ['ControlNet' => ['args' => [1, 2]]],
            'send_images' => true,
            'init_images' => ['AAA'],
            'mask' => null,
            'my_extension' => ['nested' => true],
        ];

        $first = MediaRequest::fromArray($wire);
        $emitted = $first->toArray();
        $second = MediaRequest::fromArray($emitted);

        self::assertSame($emitted, $second->toArray());
        self::assertSame(['my_extension' => ['nested' => true]], $first->unknownKeys());
        // int→float widening at the boundary (JSON cannot spell 7.0).
        self::assertSame(7.0, $first->cfgScale());
        self::assertSame(0.35, $first->denoisingStrength());
    }

    public function testUnknownWireKeysAreToleratedCollectedAndReEmitted(): void
    {
        $request = MediaRequest::fromArray(['prompt' => 'x', 'forge_thing' => 42]);

        self::assertSame(['forge_thing' => 42], $request->unknownKeys());
        self::assertSame(['prompt' => 'x', 'forge_thing' => 42], $request->toArray());
    }

    /** @return list<array{0: string}> */
    public static function apiNotAllowedProvider(): array
    {
        return array_map(static fn (string $k): array => [$k], MediaRequest::API_NOT_ALLOWED);
    }

    /** @dataProvider apiNotAllowedProvider */
    public function testApiNotAllowedKeysAreRefusedByName(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($key);
        MediaRequest::fromArray([$key => 'anything']);
    }

    public function testLatentMaskIsRefusedWithReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('latent_mask');
        MediaRequest::fromArray(['latent_mask' => 'AAAA']);
    }

    /** @return list<array{0: string, 1: mixed}> */
    public static function wrongTypeProvider(): array
    {
        return [
            'string field given int' => ['width', '512'],
            'int field given float' => ['seed', 1.5],
            'bool field given int' => ['tiling', 1],
            'array field given string' => ['styles', 'none'],
            'sampler_name given array' => ['sampler_name', ['a']],
        ];
    }

    /** @dataProvider wrongTypeProvider */
    public function testWrongWireTypeFailsFastNamingTheKey(string $key, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($key);
        MediaRequest::fromArray([$key => $value]);
    }

    public function testMediaRequestFromMapsOnlySetParamsPlusPrompt(): void
    {
        $params = GenerationParams::new()->withSteps(30)->withRestoreFaces(false);
        $request = MediaRequest::mediaRequestFrom($params, 'a fox');

        self::assertSame(
            ['prompt' => 'a fox', 'steps' => 30, 'restore_faces' => false],
            $request->toArray()
        );
        self::assertFalse($request->isSet('width'));
    }

    public function testParamsProjectionRoundTripsModelledFields(): void
    {
        $params = GenerationParams::new()
            ->withWidth(1024)
            ->withHeight(512)
            ->withHrUpscaler('ESRGAN_4x')
            ->withMaskBlur(8);
        $request = MediaRequest::mediaRequestFrom($params, 'p');

        self::assertSame($params->toArray(), $request->toParamsProjection()->toArray());
    }

    public function testRequestOnlyFieldsHaveNoParamsWithers(): void
    {
        // Plumbing/payload must not leak into the form knob tree (plan W1.1).
        foreach (['withSendImages', 'withSaveImages', 'withForceTaskId', 'withInfotext', 'withInitImages', 'withMask', 'withOverrideSettings'] as $method) {
            self::assertFalse(method_exists(GenerationParams::class, $method), $method . ' must not exist on GenerationParams');
        }
        // …and the request side does carry them.
        foreach (['withSendImages', 'withForceTaskId', 'withInitImages'] as $method) {
            self::assertTrue(method_exists(MediaRequest::class, $method), $method . ' must exist on MediaRequest');
        }
    }

    public function testMediaKindBackedValuesMatchConfigVocabulary(): void
    {
        self::assertSame('image', MediaKind::Image->value);
        self::assertSame('video', MediaKind::Video->value);
        self::assertSame(MediaKind::Video, MediaKind::from('video'));
        self::assertNull(MediaKind::tryFrom('audio'));
    }

    public function testArtifactCarriesThreeArrivalShapes(): void
    {
        $inline = MediaArtifact::new(MediaKind::Image)->withBytes('PNGDATA');
        $saved = MediaArtifact::new(MediaKind::Image)->withPath('/srv/outputs/00012.png');
        $video = MediaArtifact::new(MediaKind::Video)->withPath('/tmp/jobs/out.mp4')->withProtocolHint('halfblock');

        self::assertSame('PNGDATA', $inline->bytes());
        self::assertTrue($saved->pathIsSet());
        self::assertSame('halfblock', $video->protocolHint());
    }

    public function testArtifactBytesNeverCrossSerialize(): void
    {
        $artifact = MediaArtifact::new(MediaKind::Image)
            ->withBytes('SECRET-BLOB')
            ->withPath('/o/1.png')
            ->withTokenValue('seed', 123);

        $serialized = $artifact->toArray();
        self::assertArrayNotHasKey('bytes', $serialized);
        self::assertSame('image', $serialized['kind']);
        self::assertSame(['seed' => 123], $serialized['token_values']);

        $rebuilt = MediaArtifact::fromArray($serialized);
        self::assertNull($rebuilt->bytes());
        self::assertFalse($rebuilt->bytesIsSet());
        self::assertSame('/o/1.png', $rebuilt->path());
        self::assertSame($serialized, $rebuilt->toArray());
    }

    public function testArtifactFromArrayRejectsSmuggledBytesAndUnknownKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bytes');
        /** @var array<string, mixed> $forged */
        $forged = ['kind' => 'image', 'bytes' => 'x'];
        MediaArtifact::fromArray($forged);
    }

    public function testArtifactFromArrayRequiresKnownKind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @var array<string, mixed> $bad */
        $bad = ['kind' => 'hologram'];
        MediaArtifact::fromArray($bad);
    }

    public function testResponseAppendsArtifactsInOrderAndCarriesSentinels(): void
    {
        $response = MediaResponse::new()
            ->withArtifact(MediaArtifact::new(MediaKind::Image)->withPath('/a.png'))
            ->withArtifact(MediaArtifact::new(MediaKind::Image)->withPath('/b.png'))
            ->withJobRef('task(video-7)');

        self::assertCount(2, $response->artifacts());
        self::assertFalse($response->isEmpty());
        self::assertTrue($response->jobRefIsSet());
        self::assertFalse(MediaResponse::new()->jobRefIsSet());
        self::assertTrue(MediaResponse::new()->isEmpty());

        $wire = $response->toArray();
        self::assertSame('task(video-7)', $wire['job_ref']);
        self::assertSame('/b.png', $wire['artifacts'][1]['path']);
        $rebuilt = MediaResponse::fromArray($wire);
        self::assertSame($wire, $rebuilt->toArray());
    }

    public function testResponseFromArrayRejectsUnknownFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @var array<string, mixed> $bad */
        $bad = ['artifacts' => [], 'suprise' => 1];
        MediaResponse::fromArray($bad);
    }
}
