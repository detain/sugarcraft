<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Context\Sections\FamilyPrompt;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Providers\ModelFamily;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\ReportsServedModel;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\Tool;

/**
 * Roadmap 5.10: per-model-family base prompts. {@see ModelFamily} buckets a
 * model id; {@see FamilyPrompt} turns the bucket into a paragraph that
 * {@see Runtime::basePrompt()} folds into slot 1 at the end of
 * "# Acting vs. asking".
 *
 * The pins that matter most are the two that keep the change invisible where
 * it must be: a model outside the three families gets the pre-5.10 base byte
 * for byte, and the family is frozen per session so a served name learned
 * mid-session cannot rewrite the head of a cached prefix.
 */
final class ModelFamilyPromptTest extends TestCase
{
    /** Capitalised words in the family paragraphs that are ordinary prose. */
    private const PROSE_WORDS = ['Make', 'Match', 'Write', 'Keep', 'Leave', 'A'];

    /** @return array<string, array{string, ModelFamily}> */
    public static function ids(): array
    {
        return [
            'deployed DeepSeek-V4' => ['deepseek-ai/DeepSeek-V4-Flash-0731', ModelFamily::DeepSeekV4],
            'bare DeepSeek-V4' => ['deepseek-v4', ModelFamily::DeepSeekV4],
            'deployed Qwen3.8' => ['Qwen/Qwen3.8-Flash-Next-FP8', ModelFamily::Qwen],
            'Qwen3.8 alias' => ['Qwen3.8-Flash-Next', ModelFamily::Qwen],
            'MiniMax with org' => ['MiniMaxAI/MiniMax-M2.7', ModelFamily::MiniMax],
            'MiniMax lower-case' => ['minimax-m2', ModelFamily::MiniMax],
            'DeepSeek V3 is not V4' => ['deepseek-v3', ModelFamily::Other],
            'another Qwen generation' => ['Qwen/Qwen2.5-Coder-32B', ModelFamily::Other],
            'a frontier model' => ['claude-sonnet-4-6', ModelFamily::Other],
            'the offline echo provider' => ['echo', ModelFamily::Other],
            'an alias that names no family' => ['default', ModelFamily::Other],
        ];
    }

    #[DataProvider('ids')]
    public function testEachIdLandsInItsFamily(string $model, ModelFamily $family): void
    {
        self::assertSame($family, ModelFamily::of($model));
    }

    /**
     * One family test per family: the enum's DeepSeek-V4 and Qwen buckets are
     * exactly SglangProvider's public predicates, so the prompt and the
     * request shaping cannot disagree about a model.
     */
    #[DataProvider('ids')]
    public function testTheEnumAgreesWithTheSglangPredicates(string $model): void
    {
        self::assertSame(SglangProvider::isDeepSeekV4($model), ModelFamily::of($model) === ModelFamily::DeepSeekV4);
        self::assertSame(SglangProvider::isQwen3Next($model), ModelFamily::of($model) === ModelFamily::Qwen);
    }

    /** The SGLang served-model notice buckets with the same enum, MiniMax included. */
    public function testTheSglangMismatchBucketsAreTheEnumValues(): void
    {
        $bucket = new \ReflectionMethod(SglangProvider::class, 'modelFamily');

        foreach (self::ids() as [$model, $family]) {
            self::assertSame($family->value, $bucket->invoke(null, $model), $model);
        }
    }

    public function testTheOtherFamilyAddsNothing(): void
    {
        $other = FamilyPrompt::of(ModelFamily::Other);

        self::assertSame('', $other->render());
        self::assertFalse($other->lazy);
        self::assertFalse($other->overeager);
    }

    /**
     * Every named family carries a lead plus both Aider reminders, as
     * separate paragraphs with no leading or trailing separator.
     */
    public function testEachNamedFamilyRendersItsLeadAndBothReminders(): void
    {
        foreach ([ModelFamily::DeepSeekV4, ModelFamily::Qwen, ModelFamily::MiniMax] as $family) {
            $prompt = FamilyPrompt::of($family);
            $text = $prompt->render();

            self::assertTrue($prompt->lazy, $family->name);
            self::assertTrue($prompt->overeager, $family->name);
            self::assertNotSame('', $prompt->lead, $family->name);
            self::assertSame($prompt->lead . "\n\n" . FamilyPrompt::LAZY . "\n\n" . FamilyPrompt::OVEREAGER, $text);
            self::assertSame($text, trim($text), 'the base prompt owns the separators');
        }

        self::assertNotSame(
            FamilyPrompt::of(ModelFamily::Qwen)->lead,
            FamilyPrompt::of(ModelFamily::DeepSeekV4)->lead,
            'the families are not one paragraph under three names',
        );
    }

    /**
     * The §4.7 register MaximsSection keeps: no emphasis markers, no
     * second-person obligation formula, no counted-line promise — Aider's
     * originals shout ("You NEVER", "COMPLETELY"), these must not. A planted
     * violation is caught by the same scan, so the clean result means
     * something.
     */
    public function testTheFamilyTextKeepsTheMaximsRegister(): void
    {
        foreach (ModelFamily::cases() as $family) {
            self::assertSame([], self::registerViolations(FamilyPrompt::of($family)->render()), $family->name);
        }

        self::assertSame(['You MUST'], self::registerViolations('You MUST ' . FamilyPrompt::LAZY));
        self::assertSame(['shouting'], self::registerViolations(FamilyPrompt::OVEREAGER . ' NEVER'));
    }

    /**
     * BaseSystemPromptTest's check, applied to the text it cannot see (its
     * base is built for `echo`, the Other family): every capitalised token
     * is ordinary prose or a tool this app really registers.
     */
    public function testTheFamilyTextNamesNoToolThisAppDoesNotShip(): void
    {
        $real = array_map(static fn (Tool $tool): string => $tool->name(), Bootstrap::tools(sys_get_temp_dir()));

        foreach (ModelFamily::cases() as $family) {
            preg_match_all('/\b[A-Z][A-Za-z]+\b/', FamilyPrompt::of($family)->render(), $matches);

            self::assertSame([], array_values(array_diff(array_unique($matches[0]), $real, self::PROSE_WORDS)), $family->name);
        }
    }

    /** The invisible-where-it-must-be pin: Other's base is the bare base. */
    public function testAModelOutsideTheFamiliesGetsThePreFamilyBaseByteForByte(): void
    {
        [$runtime, $provider] = $this->runtime();

        $bare = $this->basePrompt($runtime, null);

        self::assertSame($bare, $this->basePrompt($runtime, App::new($provider, 'claude-sonnet-4-6')));
        self::assertSame($bare, $this->basePrompt($runtime, App::new($provider, 'echo')));
        self::assertSame(1, substr_count($bare, "user can stop you.\n\n# Security\n"), 'the split point must re-join with one blank line');
    }

    /**
     * A named family's paragraph closes "# Acting vs. asking": one blank line
     * either side, ahead of "# Security", and the base still ends on its
     * end-of-base marker with the same four headings.
     */
    public function testANamedFamilyParagraphClosesTheActingSection(): void
    {
        [$runtime, $provider] = $this->runtime();

        foreach (['deepseek-ai/DeepSeek-V4-Flash-0731' => ModelFamily::DeepSeekV4, 'Qwen/Qwen3.8-Flash-Next' => ModelFamily::Qwen, 'MiniMax-M2.7' => ModelFamily::MiniMax] as $model => $family) {
            $base = $this->basePrompt($runtime, App::new($provider, $model));
            $paragraph = FamilyPrompt::of($family)->render();

            self::assertSame(1, substr_count($base, "user can stop you.\n\n" . $paragraph . "\n\n# Security\n"), $model);
            self::assertStringEndsWith('commands to follow.', $base);
            self::assertSame(1, substr_count($base, 'commands to follow.'));
            self::assertSame(4, preg_match_all('/^# /m', $base), 'the paragraph must not add a heading');
        }
    }

    /**
     * The family follows the SERVED model when the provider reports one, so
     * SGLang's default-id discovery picks the text for what is really
     * answering.
     */
    public function testTheServedModelDecidesTheFamily(): void
    {
        [$runtime] = $this->runtime();
        $provider = $this->servingProvider('deepseek-ai/DeepSeek-V4-Flash-0731');

        $base = $this->basePrompt($runtime, App::new($provider, SglangProvider::DEFAULT_MODEL));

        self::assertStringContainsString(FamilyPrompt::of(ModelFamily::DeepSeekV4)->lead, $base);
        self::assertStringNotContainsString(FamilyPrompt::of(ModelFamily::Qwen)->lead, $base);
    }

    /**
     * Frozen per session: a served name learned after the first build does
     * not move slot 1 (it would rewrite the head of the cached prefix), and a
     * `/model` switch — a different configured id — re-resolves.
     */
    public function testTheFamilyIsFrozenPerSessionAndModel(): void
    {
        [$runtime] = $this->runtime();
        $served = null;
        $provider = $this->createMockForIntersectionOfInterfaces([ProviderInterface::class, ReportsServedModel::class]);
        $provider->method('servedModel')->willReturnCallback(static function () use (&$served): ?string {
            return $served;
        });

        $app = App::new($provider, SglangProvider::DEFAULT_MODEL)->withSessionId('s-family');
        $first = $this->basePrompt($runtime, $app);
        self::assertStringContainsString(FamilyPrompt::of(ModelFamily::Qwen)->lead, $first);

        $served = 'deepseek-ai/DeepSeek-V4-Flash-0731';
        self::assertSame($first, $this->basePrompt($runtime, $app), 'a served name learned mid-session must not move slot 1');

        $switched = $this->basePrompt($runtime, $app->withModel('MiniMax-M2.7'));
        self::assertStringContainsString(FamilyPrompt::of(ModelFamily::MiniMax)->lead, $switched);
    }

    /** Wiring: slot 1 of the production section list carries the paragraph. */
    public function testTheProductionSlotOneCarriesTheFamilyParagraph(): void
    {
        [$runtime, $provider] = $this->runtime();

        $sections = new \ReflectionMethod($runtime, 'systemPromptSections');
        $list = $sections->invoke($runtime, App::new($provider, 'Qwen/Qwen3.8-Flash-Next'));

        self::assertStringStartsWith('You are SugarCrush', $list[0]->render());
        self::assertStringContainsString(FamilyPrompt::of(ModelFamily::Qwen)->render(), $list[0]->render());
    }

    /** @return array{0: Runtime, 1: EchoProvider} */
    private function runtime(): array
    {
        $provider = new EchoProvider();

        return [new Runtime($provider, new HookManager(new HookRegistry())), $provider];
    }

    private function basePrompt(Runtime $runtime, ?App $app): string
    {
        return (string) (new \ReflectionMethod($runtime, 'basePrompt'))->invoke($runtime, $app);
    }

    private function servingProvider(string $served): ProviderInterface
    {
        $provider = $this->createMockForIntersectionOfInterfaces([ProviderInterface::class, ReportsServedModel::class]);
        $provider->method('servedModel')->willReturn($served);

        return $provider;
    }

    /** @return list<string> */
    private static function registerViolations(string $text): array
    {
        $found = [];

        foreach (['IMPORTANT:', 'CRITICAL:', 'You MUST'] as $needle) {
            if (str_contains($text, $needle)) {
                $found[] = $needle;
            }
        }
        if (preg_match('/\b\d+\s+lines\b/', $text) === 1) {
            $found[] = 'N lines';
        }
        if (preg_match('/\b(?:NEVER|ALWAYS|COMPLETELY)\b/', $text) === 1) {
            $found[] = 'shouting';
        }

        return $found;
    }
}
