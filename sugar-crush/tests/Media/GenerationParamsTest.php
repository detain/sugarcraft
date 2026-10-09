<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Media;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\GenerationParams;

/**
 * Plan W1.1 gates for the form-shaped parameter tree: sentinel semantics, the
 * mirrored domain refusals, strict camelCase parse (no tolerance roster here —
 * this shape never crosses a network boundary), the 52-knob roster pin that
 * keeps the MediaRequest composer honest, and immutability.
 */
final class GenerationParamsTest extends TestCase
{
    public function testFreshParamsAreEmpty(): void
    {
        $params = GenerationParams::new();

        self::assertSame([], $params->toArray());
        self::assertSame([], $params->setFields());
        self::assertFalse($params->isSet('steps'));
    }

    public function testRosterCoversTheModelledSubsetAndNothingElse(): void
    {
        // 52 = full 69-field wire surface minus prompt text and the 16
        // plumbing/payload fields (plan W1.1 composer law). If a field is added
        // to MediaRequest's spec this pin only moves deliberately.
        self::assertCount(52, GenerationParams::PROPS);
        self::assertNotContains('prompt', GenerationParams::PROPS);
        self::assertNotContains('sendImages', GenerationParams::PROPS);
        self::assertNotContains('initImages', GenerationParams::PROPS);
        self::assertContains('denoisingStrength', GenerationParams::PROPS);
        self::assertContains('maskRound', GenerationParams::PROPS);

        foreach (GenerationParams::PROPS as $prop) {
            self::assertTrue(method_exists(GenerationParams::class, 'with' . ucfirst($prop)), $prop);
            self::assertTrue(method_exists(GenerationParams::class, $prop), $prop);
            self::assertTrue(method_exists(MediaRequestProbe::requestClass(), 'with' . ucfirst($prop)), $prop);
        }
    }

    public function testSentinelDistinguishesUnsetFromExplicitNullAndZero(): void
    {
        $params = GenerationParams::new()->withSeed(0)->withRestoreFaces(null);

        self::assertSame(['seed' => 0, 'restoreFaces' => null], $params->toArray());
        self::assertTrue($params->isSet('seed'));
        self::assertTrue($params->isSet('restoreFaces'));
        self::assertNull($params->restoreFaces());
        self::assertFalse($params->isSet('width'));
    }

    public function testSetFieldsFollowsCanonicalOrderNotCallOrder(): void
    {
        $params = GenerationParams::new()->withHeight(512)->withSteps(20)->withWidth(1024);

        self::assertSame(['steps', 'width', 'height'], $params->setFields());
    }

    public function testGuardedKnobsMirrorTheRequestRefusals(): void
    {
        foreach ([
            static fn () => GenerationParams::new()->withBatchSize(0),
            static fn () => GenerationParams::new()->withNIter(-1),
            static fn () => GenerationParams::new()->withSubseedStrength(2.0),
        ] as $attempt) {
            $this->expectException(InvalidArgumentException::class);
            $attempt();
        }
    }

    public function testGuardRefusalsNameTheOffendingValue(): void
    {
        // R1 MINOR-3: the messages once interpolated the literal ".$value"
        // (single-quote concat bug); a refusal must name the real offender.
        $attempts = [
            [static fn () => GenerationParams::new()->withBatchSize(0), 'given 0'],
            [static fn () => GenerationParams::new()->withNIter(-1), 'given -1'],
            [static fn () => GenerationParams::new()->withSubseedStrength(2.0), 'given 2'],
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

    public function testUntouchedKnobsAcceptAnythingTyped(): void
    {
        // No coercion anywhere (plan W1.1): Wave-3 sliders clamp, not this layer.
        $params = GenerationParams::new()->withSteps(-5)->withCfgScale(0.0)->withInpaintingFill(42);

        self::assertSame(-5, $params->steps());
        self::assertSame(0.0, $params->cfgScale());
        self::assertSame(42, $params->inpaintingFill());
    }

    public function testRoundTripThroughArrayIsStable(): void
    {
        $params = GenerationParams::new()
            ->withSamplerName('Euler a')
            ->withScheduler('Karras')
            ->withCfgScale(7)
            ->withEnableHr(true)
            ->withTokenMergingRatioHr(0.0);
        $array = $params->toArray();

        self::assertSame($array, GenerationParams::fromArray($array)->toArray());
        self::assertSame(7.0, GenerationParams::fromArray($array)->cfgScale());
    }

    public function testUnknownKeyOnParseIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('notAModelledKnob');
        /** @var array<string, mixed> $bad */
        $bad = ['notAModelledKnob' => 1];
        GenerationParams::fromArray($bad);
    }

    public function testWrongTypeOnParseNamesTheKnob(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('width');
        /** @var array<string, mixed> $bad */
        $bad = ['width' => true];
        GenerationParams::fromArray($bad);
    }

    public function testWithersAreImmutableBuilders(): void
    {
        $a = GenerationParams::new()->withWidth(512);
        $b = $a->withWidth(1024);

        self::assertNotSame($a, $b);
        self::assertSame(512, $a->width());
        self::assertSame(1024, $b->width());
    }
}

/**
 * Small indirection so this test file does not import MediaRequest at the top
 * while still pinning roster↔request parity through the real class.
 */
final class MediaRequestProbe
{
    public static function requestClass(): string
    {
        return \SugarCraft\Crush\Media\MediaRequest::class;
    }
}
