<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Media;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\GenerationParams;
use SugarCraft\Crush\Media\Infotext;
use SugarCraft\Crush\Media\InfotextFields;
use SugarCraft\Crush\Media\MediaRequest;

/**
 * Plan W1.6 gates for the A1111 infotext codec: snapshot-byte goldens replay
 * verbatim, the §3.5 emit order + CONDITIONAL slots (XSet law — unset absent,
 * seed=0 present), the json quoting round-trip for values carrying `,` `:` or
 * newline, the depth-0 `, ` / first-`: ` split traps (`<lora:…>`,
 * `[tag, with comma]`, colon-bearing values), legacy-spelling normalisation,
 * garbage-tolerant parse, unknown-key preservation, and the onlyUnset law on
 * applyTo including the scheduler-owns-itself precedence for combined legacy
 * sampler names.
 */
final class InfotextTest extends TestCase
{
    public function testGoldenCanonicalFullFieldReplaysByteIdentical(): void
    {
        $text = "a cat, sitting\nNegative prompt: blurry, low-res\nSteps: 20, Sampler: DPM++ 2M, Schedule type: Karras, CFG scale: 7, Seed: 123456789, Face restoration: True, Size: 512x512, Model hash: abcd1234, Model: moviefilm_00344V5.safetensors [372b6a05], VAE hash: fedc0000, VAE: ft-mse-vae, Variation seed: 987654321, Variation seed strength: 0.2, Seed resize from: -1x-1, Denoising strength: 0.75, Conditional mask weight: 1.0, Clip skip: 2, ENSD: 31337, Token merging ratio: 0, Token merging ratio hr: 0, Init image hash: 1234abcd, RNG: CPU, Tiling: True, Eta: 0, Hires upscale: 1.5, Hires upscaler: R-ESRGAN 4x+, Style: analog style, Face restoration model: GFPGAN, Sugar-crush: dev-test, Version: v1.10.1, User: hush";
        $parsed = Infotext::parse($text);
        self::assertSame('a cat, sitting', $parsed[Infotext::PROMPT_KEY]);
        self::assertSame('blurry, low-res', $parsed[Infotext::NEGATIVE_KEY]);
        self::assertSame('20', $parsed['Steps']);
        self::assertSame('DPM++ 2M', $parsed['Sampler']);
        self::assertSame('moviefilm_00344V5.safetensors [372b6a05]', $parsed['Model']);
        self::assertSame('R-ESRGAN 4x+', $parsed['Hires upscaler']);
        self::assertSame('GFPGAN', $parsed['Face restoration model']);
        self::assertSame($text, Infotext::emit(GenerationParams::new(), $parsed));
    }

    public function testEmissionFollowsCanonicalSlotOrderFromDto(): void
    {
        $params = GenerationParams::new()
            ->withSteps(28)
            ->withSamplerName('Euler a')
            ->withScheduler('Automatic')
            ->withCfgScale(7.0)
            ->withSeed(42)
            ->withWidth(512)
            ->withHeight(768)
            ->withDenoisingStrength(0.6);
        $text = Infotext::emit($params, ['Sugar-crush' => 'dev-test'], 'p');
        $tail = substr($text, strrpos($text, "\n") + 1);
        self::assertSame(
            'Steps: 28, Sampler: Euler a, Schedule type: Automatic, CFG scale: 7, Seed: 42, Size: 512x768, Denoising strength: 0.6, Sugar-crush: dev-test',
            $tail,
        );
    }

    public function testConditionalEmissionHonoursTheXSetLawSeedZeroIsSet(): void
    {
        $zeroSeed = Infotext::emit(GenerationParams::new()->withSeed(0), [], '');
        self::assertStringContainsString('Seed: 0', $zeroSeed);
        $unset = Infotext::emit(GenerationParams::new()->withSteps(10), [], '');
        self::assertStringNotContainsString('Seed:', $unset);
        self::assertStringNotContainsString('CFG scale:', $unset);
    }

    public function testTilingRidesOnlyWhenTrueAndVariationPairRidesOnlyWithNonZeroStrength(): void
    {
        self::assertStringNotContainsString('Tiling', Infotext::emit(GenerationParams::new()->withTiling(false), [], ''));
        self::assertStringContainsString('Tiling: True', Infotext::emit(GenerationParams::new()->withTiling(true), [], ''));
        // Strength 0: upstream never emits the pair.
        $off = Infotext::emit(
            GenerationParams::new()->withSubseed(1)->withSubseedStrength(0.0),
            [],
            '',
        );
        self::assertStringNotContainsString('Variation seed', $off);
        $on = Infotext::emit(
            GenerationParams::new()->withSubseed(1)->withSubseedStrength(0.2),
            [],
            '',
        );
        self::assertStringContainsString('Variation seed: 1, Variation seed strength: 0.2', $on);
    }

    public function testEmptyNegativeLineIsOmitted(): void
    {
        $text = Infotext::emit(GenerationParams::new()->withSteps(20), ['prompt' => 'only prompt'], 'only prompt');
        self::assertStringNotContainsString('Negative prompt', $text);
        self::assertStringStartsWith("only prompt\nSteps:", $text);
    }

    public function testCjkAndEmojiPromptBodyPassesThroughRawOnThePromptLine(): void
    {
        $text = "桜の少女 🌸、超高品質、マスターピース\nNegative prompt: worst quality, low quality\nSteps: 28, Sampler: Euler a, Seed: 42, Size: 768x768, Sugar-crush: dev-test, Version: v1.9.3, User: sakura";
        $parsed = Infotext::parse($text);
        self::assertSame('桜の少女 🌸、超高品質、マスターピース', $parsed[Infotext::PROMPT_KEY]);
        self::assertSame($text, Infotext::emit(GenerationParams::new(), $parsed));
    }

    public function testNewlineInNegativeSpansBodyLinesAndQuotesOnValues(): void
    {
        $text = "portrait\nNegative prompt: blurry\nnoisy\nugly hands\nSteps: 20, Sampler: Euler a, Seed: 7, Size: 512x512, Sugar-crush: dev-test";
        $parsed = Infotext::parse($text);
        self::assertSame("blurry\nnoisy\nugly hands", $parsed[Infotext::NEGATIVE_KEY]);
        self::assertSame($text, Infotext::emit(GenerationParams::new(), $parsed));
        // A VALUE (not the prompt region) carrying a newline is json-quoted.
        $quoted = Infotext::emit(GenerationParams::new()->withSamplerName("a\nb"), [], 'p');
        self::assertStringContainsString('Sampler: "a\nb"', $quoted);
        self::assertSame("a\nb", Infotext::parse($quoted)['Sampler']);
    }

    public function testValuesWithCommaOrColonAreJsonQuotedAndSurviveParse(): void
    {
        $params = GenerationParams::new()
            ->withSteps(10)
            ->withSamplerName('lora:tag:0.8');
        $text = Infotext::emit($params, [], 'x');
        self::assertStringContainsString('Sampler: "lora:tag:0.8"', $text);
        self::assertSame('lora:tag:0.8', Infotext::parse($text)['Sampler']);
        self::assertSame($text, Infotext::emit(GenerationParams::new(), Infotext::parse($text)));
    }

    public function testLoraAngleTagsAndBracketedCommaValuesDoNotSplitPairs(): void
    {
        // Legacy unquoted spellings (bracketed comma, lora colons) parse via
        // depth-tracked splitting; re-emit normalises them to the §3.5 quoting
        // law — the documented one-way direction, same as legacy labels.
        $legacy = "p\nSteps: 9, Model: HAT [ESRGAN, 4x], Networks: <lora:mylora:0.8:0.2>, Sugar-crush: dev-test";
        $parsed = Infotext::parse($legacy);
        self::assertSame('HAT [ESRGAN, 4x]', $parsed['Model']);
        self::assertSame('<lora:mylora:0.8:0.2>', $parsed['Networks']);
        $normal = Infotext::emit(GenerationParams::new(), $parsed);
        self::assertSame($normal, Infotext::emit(GenerationParams::new(), Infotext::parse($normal)));
        self::assertStringContainsString('Model: "HAT [ESRGAN, 4x]"', $normal);
        self::assertStringContainsString('Networks: "<lora:mylora:0.8:0.2>"', $normal);
    }

    public function testPairLabelSplitsAtFirstColonSpaceAndValueKeepsColons(): void
    {
        $parsed = Infotext::parse("p\nTime model: first: second, Steps: 3");
        self::assertSame('first: second', $parsed['Time model']);
        self::assertSame('3', $parsed['Steps']);
    }

    public function testPromptBodyMimickingParameterLineStillAnchorsOnLastLine(): void
    {
        $text = "Steps: this is prose not a pair\nreal prompt\nSteps: 5, Sugar-crush: dev-test";
        $parsed = Infotext::parse($text);
        self::assertSame("Steps: this is prose not a pair\nreal prompt", $parsed[Infotext::PROMPT_KEY]);
        self::assertSame('5', $parsed['Steps']);
    }

    public function testGarbageTextParsesToEmptyFieldMapWithoutThrowing(): void
    {
        $parsed = Infotext::parse("!!! not infotext at all\nmore lines");
        self::assertSame('!!! not infotext at all', $parsed[Infotext::PROMPT_KEY]);
        self::assertSame('', $parsed[Infotext::NEGATIVE_KEY]);
        self::assertCount(2, $parsed);
        self::assertSame([Infotext::PROMPT_KEY => '', Infotext::NEGATIVE_KEY => ''], Infotext::parse('   '));
        // Malformed separator forms drop the pair, never the parse.
        self::assertSame('20', Infotext::parse("p\nSteps: 20, lone-token")['Steps']);
    }

    public function testLegacySpellingsNormaliseOntoCanonicalLabels(): void
    {
        $parsed = Infotext::parse("p\nVariance seed: 111, Variance seed strength: 0.5, Sampling steps: 9");
        self::assertSame('111', $parsed['Variation seed']);
        self::assertSame('0.5', $parsed['Variation seed strength']);
        self::assertSame('9', $parsed['Steps']);
        self::assertArrayNotHasKey('Variance seed', $parsed);
        self::assertArrayNotHasKey('Sampling steps', $parsed);
        // Re-emit writes canonical spellings (documented one-way direction).
        $again = Infotext::emit(GenerationParams::new(), $parsed);
        self::assertStringContainsString('Variation seed: 111, Variation seed strength: 0.5', $again);
        self::assertStringContainsString('Steps: 9', $again);
    }

    public function testCommentStrippingTogglesWithTheFlag(): void
    {
        $text = "a cat # tail comment\nSteps: 4, Sugar-crush: dev-test";
        self::assertSame('a cat', Infotext::parse($text)[Infotext::PROMPT_KEY]);
        self::assertSame('a cat # tail comment', Infotext::parse($text, stripComments: false)[Infotext::PROMPT_KEY]);
    }

    public function testSugarCrushStampSplicesBeforeVersionAndReplaysVerbatim(): void
    {
        $emitted = Infotext::emit(GenerationParams::new()->withSteps(1), ['Version' => 'v1.10.1'], 'p');
        self::assertMatchesRegularExpression('/Sugar-crush: .+, Version: v1\.10\.1$/', $emitted);
        $replay = Infotext::emit(GenerationParams::new(), Infotext::parse($emitted));
        self::assertSame($emitted, $replay);
        $own = Infotext::emit(GenerationParams::new()->withSteps(1), ['Sugar-crush' => 'custom-stamp', 'Version' => 'v1.6.0'], 'p');
        self::assertStringContainsString('Sugar-crush: custom-stamp, Version: v1.6.0', $own);
    }

    public function testApplyToLoadsEveryDtoFieldFromParsedText(): void
    {
        $parsed = Infotext::parse("a cat, sitting\nNegative prompt: blurry\nSteps: 20, Sampler: DPM++ 2M, Schedule type: Karras, CFG scale: 7, Image CFG scale: 1.5, Seed: 0, Face restoration: True, Size: 512x512, Variation seed: 7, Variation seed strength: 0.2, Seed resize from: -1x-1, Denoising strength: 0.75, Token merging ratio: 0.5, Token merging ratio hr: 0.2, Tiling: True, Hires upscale: 1.5, Hires upscaler: R-ESRGAN 4x+, Firstpass width: 512, Style: [\"one\",\"two\"]");
        $request = Infotext::applyTo(MediaRequest::new(), $parsed);
        self::assertSame('a cat, sitting', $request->prompt());
        self::assertSame('blurry', $request->negativePrompt());
        self::assertSame(20, $request->steps());
        self::assertSame('DPM++ 2M', $request->samplerName());
        self::assertSame('Karras', $request->scheduler());
        self::assertSame(7.0, $request->cfgScale());
        self::assertSame(1.5, $request->imageCfgScale());
        self::assertSame(0, $request->seed());
        self::assertTrue($request->propIsSet('seed'), 'Seed 0 must count as SET');
        self::assertTrue($request->restoreFaces());
        self::assertSame(512, $request->width());
        self::assertSame(512, $request->height());
        self::assertSame(7, $request->subseed());
        self::assertSame(0.2, $request->subseedStrength());
        self::assertSame(-1, $request->seedResizeFromH());
        self::assertSame(-1, $request->seedResizeFromW());
        self::assertSame(0.75, $request->denoisingStrength());
        self::assertSame(0.5, $request->tokenMergingRatio());
        self::assertSame(0.2, $request->tokenMergingRatioHr());
        self::assertTrue($request->tiling());
        self::assertSame(1.5, $request->hrScale());
        self::assertSame('R-ESRGAN 4x+', $request->hrUpscaler());
        self::assertSame(512, $request->firstphaseWidth());
        self::assertSame(['one', 'two'], $request->styles());
    }

    public function testApplyToOnlyUnsetLawKeepsExplicitRequestDecisions(): void
    {
        $parsed = Infotext::parse('p' . "\n" . 'Steps: 20, Seed: 0, CFG scale: 7');
        $request = MediaRequest::new()->withSeed(5)->withSteps(35);
        $merged = Infotext::applyTo($request, $parsed);
        self::assertSame(5, $merged->seed(), 'a set seed (even non-zero) wins over pasted text');
        self::assertSame(35, $merged->steps());
        self::assertSame(7.0, $merged->cfgScale(), 'unset fields still flow in');
        // onlyUnset=false lets the pasted text win everywhere.
        $forced = Infotext::applyTo($request, $parsed, onlyUnset: false);
        self::assertSame(0, $forced->seed());
        self::assertSame(20, $forced->steps());
    }

    public function testApplyToPreservesUnmodelledFieldsAsUnknownKeys(): void
    {
        $parsed = Infotext::parse("p\nSteps: 9, Model hash: abcd1234, Model: sd_xl, Clip skip: 2, ENSD: 31337, RNG: CPU, Init image hash: 1234abcd, Conditional mask weight: 1.0, Face restoration model: GFPGAN, Hires denoising strength: 0.3, VAE hash: fedc0000, VAE: mse, Version: v1.10.1, User: hush");
        $request = Infotext::applyTo(MediaRequest::new(), $parsed);
        $unknowns = $request->unknownKeys();
        foreach ([
            'sd_model_hash', 'sd_model_name', 'clip_skip', 'eta_noise_seed_delta',
            'randn_source', 'init_images_hash', 'conditional_mask_weight',
            'face_restoration_model', 'hr_denoising_strength', 'sd_vae_hash',
            'sd_vae_name', 'version', 'user',
        ] as $key) {
            self::assertArrayHasKey($key, $unknowns, "unmodelled field {$key} must be preserved");
        }
        self::assertSame('2', $unknowns['clip_skip']);
        self::assertSame(9, $request->steps(), 'modelled siblings still route to their withers');
    }

    public function testApplyToLegacyCombinedSamplerNameSplitsUnlessScheduleTypeOwnsIt(): void
    {
        $split = Infotext::applyTo(MediaRequest::new(), Infotext::parse("p\nSampler: DPM++ 2M Karras, Steps: 20"));
        self::assertSame('DPM++ 2M', $split->samplerName());
        self::assertSame('Karras', $split->scheduler());
        $explicit = Infotext::applyTo(MediaRequest::new(), Infotext::parse('p' . "\n" . 'Sampler: DPM++ 2M Karras, Schedule type: Automatic'));
        self::assertSame('DPM++ 2M Karras', $explicit->samplerName());
        self::assertSame('Automatic', $explicit->scheduler());
    }

    public function testApplyToSkipsMalformedNumericValues(): void
    {
        $request = Infotext::applyTo(MediaRequest::new(), Infotext::parse('p' . "\n" . 'Steps: not-a-number, CFG scale: , Seed: 3'));
        self::assertNull($request->steps());
        self::assertFalse($request->propIsSet('cfgScale'));
        self::assertSame(3, $request->seed());
    }

    public function testRoundTripPropertyAcrossAdversarialPunctuation(): void
    {
        foreach (self::roundTripCorpus() as $name => $text) {
            $parsed = Infotext::parse($text);
            self::assertSame($text, Infotext::emit(GenerationParams::new(), $parsed), "round-trip broke for {$name}");
        }
    }

    public function testParseThenEmitFromDtoMatchesGoldenSemantics(): void
    {
        $text = "p\nSteps: 20, CFG scale: 7, Seed: 42, Size: 512x512, Sugar-crush: dev-test";
        $parsed = Infotext::parse($text);
        $request = Infotext::applyTo(MediaRequest::new(), $parsed);
        // The parsed map also carries the stamp/value extras, so replay of the
        // DTO+extras merge must land back on the golden byte-for-byte.
        self::assertSame($text, Infotext::emit($request, $parsed));
    }

    /**
     * The committed golden battery — every entry must replay byte-identical.
     *
     * @return array<string, string>
     */
    public static function roundTripCorpus(): array
    {
        return [
            'cjk-emoji' => "桜の少女 🌸、超高品質\nNegative prompt: worst quality, low quality\nSteps: 28, Sampler: Euler a, Seed: 42, Size: 768x768, Sugar-crush: dev-test, Version: v1.9.3",
            'hires-trio' => "p\nSteps: 20, Seed: 1, Size: 512x512, Hires upscale: 1.5, Hires upscaler: R-ESRGAN 4x+, Hires denoising strength: 0.3, Sugar-crush: dev-test",
            'variation-extras' => "p\nSeed: 1, Variation seed: 111, Variation seed strength: 0.5, Seed resize from: 0x0, Sugar-crush: dev-test",
            'bracket-trap' => "p\nSteps: 9, Model: \"HAT [ESRGAN, 4x]\", Networks: \"<lora:mylora:0.8:0.2>\", Sugar-crush: dev-test",
            'json-quoted-colon' => "x\nSteps: 10, Model: \"a:b, c\", Sugar-crush: dev-test",
            'no-prompt' => "Steps: 15, Sampler: DPM++ 2M, Sugar-crush: dev-test",
            'multiline-negative' => "portrait\nNegative prompt: blurry\nnoisy\nSteps: 20, Sugar-crush: dev-test",
            'empty-seed-zero-size' => "p\nSeed: 0, Size: 0x0, Sugar-crush: dev-test",
            'user-version-bookends' => "p\nSteps: 1, Sugar-crush: dev-test, Version: v1.10.1, User: hush",
            'legacy-face-model' => "p\nFace restoration: CodeFormer, Face restoration model: CodeFormer, Sugar-crush: dev-test",
            'multi-style' => "p\nSteps: 12, Style: \"[\\\"sci-fi\\\", \\\"noir\\\"]\", Sugar-crush: dev-test",
            'tiling-rng-enbd' => "p\nSteps: 30, Clip skip: 2, ENSD: 31337, RNG: CPU, Tiling: True, Sugar-crush: dev-test",
        ];
    }

    public function testFieldsTableCoversEveryPlanLabel(): void
    {
        foreach ([
            'Steps', 'Sampler', 'Schedule type', 'CFG scale', 'Image CFG scale', 'Seed',
            'Face restoration', 'Size', 'Model hash', 'Model', 'VAE hash', 'VAE',
            'Variation seed', 'Variation seed strength', 'Seed resize from',
            'Denoising strength', 'Conditional mask weight', 'Clip skip', 'ENSD',
            'Token merging ratio', 'Token merging ratio hr', 'Init image hash', 'RNG',
            'Tiling', 'Hires upscale', 'Hires upscaler', 'Hires steps',
            'Hires denoising strength', 'Style', 'Version', 'User', 'Sugar-crush',
        ] as $label) {
            self::assertArrayHasKey($label, InfotextFields::FIELDS, "missing label {$label}");
        }
        self::assertSame('Steps', InfotextFields::canonicalLabel('Sampling steps'));
        self::assertSame('Variation seed', InfotextFields::canonicalLabel('Variance seed'));
        self::assertSame('cfg_scale', InfotextFields::fieldFor('CFG scale'));
        self::assertNull(InfotextFields::fieldFor('Made up label'));
    }
}
