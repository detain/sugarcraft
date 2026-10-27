<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\GeminiProvider;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Providers\VertexProvider;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-PRV-5.a: per-family thinking ceilings, both wires and the launch gate.
 *
 * Before this lane only a FLOOR bounded the `thinkingBudget` setting, while
 * the Anthropic wire shaped `max_tokens = max(ceiling, budget x 1.5)` - so a
 * budget of 50000 asked the Messages API for a 75000-token reply against the
 * family's published 64000 output ceiling (a server-side 400 on every turn),
 * an effort tier on a pre-thinking family (`claude-3-5-*`, `gemini-2.0-*`,
 * the 1-series) sent a thinking arm that family never had, and `/effort max`
 * on a small-ceiling row over-ran its family the same way the 50000 setting
 * does. The fix is one law read off {@see \SugarCraft\Code\Providers\ThinkingCapability}
 * at the last moment before each body is shaped:
 *
 *   - a family that never thinks receives NO thinking arm at all, whatever
 *     was configured or derived - the number is dropped, not resent;
 *   - a thinking family gets its budget clamped into the family's published
 *     output ceiling, with the 1024-token protocol floor standing under the
 *     clamp (and the reply triangle kept by yielding one token);
 *   - the reply ceiling itself (`max_tokens` / `maxOutputTokens`) clamps the
 *     same way, and a Gemini `-1`/`0` settings spelling passes through
 *     unclamped because a ceiling clamp can neither produce nor eat them;
 *   - the factory refuses a configured budget above the configured model's
 *     family ceiling at launch, in the floor refusal's loud shape;
 *   - an id outside both families keeps every pre-C-PRV-5 byte - the table
 *     refuses nothing it does not know.
 *
 * Wire shape only, through the same injected transports the sibling thinking
 * suites use; no live call, no credentials.
 */
final class ThinkingCeilingTest extends TestCase
{
    use HomeSandboxTrait;

    private const THINKING_ANTHROPIC = 'claude-sonnet-4-6';
    private const PRE_THINKING_ANTHROPIC = 'claude-3-5-haiku-20241022';
    private const THINKING_GEMINI = 'gemini-2.5-pro';
    private const PRE_THINKING_GEMINI = 'gemini-2.0-flash';
    private const UNKNOWN_MODEL = 'llama-3.3-70b';

    /** The family table's published ceilings, spelled here as literals so a drift in either direction reddens. */
    private const ANTHROPIC_CEILING = 64_000;
    private const GEMINI_CEILING = 65_536;

    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/thinking_ceiling_' . uniqid('', true);
        $home = $this->tmpDir . '/home';
        mkdir($home . '/.candy-code', 0o700, true);

        $this->useHomeSandbox($home);
        Bootstrap::useConfigPath(null);
        Bootstrap::useProjectRootForSettings(null);
    }

    protected function tearDown(): void
    {
        Bootstrap::useProjectRootForSettings(null);
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        $this->wipeTempHome();

        parent::tearDown();
    }

    /**
     * Own-named cleanup (the sibling suites' tree-removers predate this
     * lane; a byte-shared name would trip the helper-drift census).
     */
    private function wipeTempHome(): void
    {
        if (!is_dir($this->tmpDir)) {
            return;
        }

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($rii as $node) {
            $node->isDir() ? @rmdir($node->getPathname()) : @unlink($node->getPathname());
        }

        @rmdir($this->tmpDir);
    }

    // -------------------------------------------------------------------------
    // Anthropic wire: omission, clamps, triangle, unknown-family pass-through
    // -------------------------------------------------------------------------

    public function testAPreThinkingFamilyDropsAConfiguredBudgetEntirely(): void
    {
        $body = $this->capturedAnthropicBody(
            self::PRE_THINKING_ANTHROPIC,
            4096,
            $this->ceilingRequest(self::PRE_THINKING_ANTHROPIC, temperature: 0.2, maxTokens: 300)
        );

        self::assertArrayNotHasKey('thinking', $body, 'claude-3-5-* never spoke the thinking arm; a configured number is dropped, not resent');
        self::assertSame(0.2, $body['temperature'], 'with no thinking arm the temperature passes through exactly as the off shape always did');
        self::assertSame(300, $body['max_tokens']);
    }

    public function testAPreThinkingFamilyDropsAnEffortDerivedBudgetToo(): void
    {
        $body = $this->capturedAnthropicBody(
            self::PRE_THINKING_ANTHROPIC,
            null,
            $this->ceilingRequest(self::PRE_THINKING_ANTHROPIC, reasoningEffort: 'low')
        );

        self::assertArrayNotHasKey('thinking', $body, 'the effort arm is the same number from a different door; the family gate stands at both');
        self::assertSame(4096, $body['max_tokens'], 'the untouched off-shape default stands');
    }

    public function testAThinkingFamilyTrimsAnOverlargeReplyLegWhileTheBudgetStillFits(): void
    {
        $body = $this->capturedAnthropicBody(
            self::THINKING_ANTHROPIC,
            50_000,
            $this->ceilingRequest(self::THINKING_ANTHROPIC)
        );

        self::assertSame(50_000, $body['thinking']['budget_tokens'], 'a budget under the ceiling keeps its requested size');
        self::assertSame(
            self::ANTHROPIC_CEILING,
            $body['max_tokens'],
            'the 75000-token reply the budget x 1.5 headroom used to ask for is trimmed to the family output ceiling'
        );
    }

    public function testABudgetAtTheCeilingYieldsOneTokenToKeepTheProtocolTriangle(): void
    {
        $body = $this->capturedAnthropicBody(
            self::THINKING_ANTHROPIC,
            self::ANTHROPIC_CEILING,
            $this->ceilingRequest(self::THINKING_ANTHROPIC)
        );

        self::assertSame(self::ANTHROPIC_CEILING, $body['max_tokens']);
        self::assertSame(
            self::ANTHROPIC_CEILING - 1,
            $body['thinking']['budget_tokens'],
            'budget_tokens must stay strictly below max_tokens; at an equal clamp the budget yields one token, never the shape'
        );
    }

    public function testAnExplicitReplyCeilingPastTheFamilyMaxIsTrimmedEvenWithoutThinking(): void
    {
        $body = $this->capturedAnthropicBody(
            self::THINKING_ANTHROPIC,
            null,
            $this->ceilingRequest(self::THINKING_ANTHROPIC, maxTokens: 100_000)
        );

        self::assertArrayNotHasKey('thinking', $body);
        self::assertSame(self::ANTHROPIC_CEILING, $body['max_tokens']);
    }

    public function testAnUnknownFamilyKeepsEveryPreCeilingByte(): void
    {
        $body = $this->capturedAnthropicBody(
            self::UNKNOWN_MODEL,
            70_000,
            $this->ceilingRequest(self::UNKNOWN_MODEL, maxTokens: 100_000)
        );

        self::assertSame(70_000, $body['thinking']['budget_tokens'], 'the table refuses nothing it does not know');
        self::assertSame(105_000, $body['max_tokens'], 'a proxy behind an unknown id keeps the ceiling its operator asked for');
    }

    public function testTheVertexAnthropicArmOmitsThinkingOnAPreThinkingFamily(): void
    {
        $body = $this->capturedVertexBody(
            self::PRE_THINKING_ANTHROPIC,
            null,
            $this->ceilingRequest(self::PRE_THINKING_ANTHROPIC, reasoningEffort: 'high')
        );

        self::assertArrayNotHasKey('thinking', $body, 'the shared wire gates both doors, so Vertex effort tiers stop 400ing on 3-5 rows');
        self::assertSame(4096, $body['max_tokens'], 'the off-shape default answers, not the 12288 the x 1.5 leg used to raise it to');
    }

    // -------------------------------------------------------------------------
    // Gemini wire: omission, budget clamp, settings spellings, reply clamp
    // -------------------------------------------------------------------------

    public function testAGeminiFamilyWithoutThinkingConfigOmitsTheArm(): void
    {
        $body = $this->capturedVertexBody(
            self::PRE_THINKING_GEMINI,
            8192,
            $this->ceilingRequest(self::PRE_THINKING_GEMINI)
        );

        self::assertArrayNotHasKey('thinkingConfig', $body['generationConfig'], '2.0 never had the field; the configured number is dropped whole');
    }

    public function testAPreThinkingGeminiDropsAnEffortTierAndTheDynamicSpellingToo(): void
    {
        $tierBody = $this->capturedVertexBody(
            self::PRE_THINKING_GEMINI,
            null,
            $this->ceilingRequest(self::PRE_THINKING_GEMINI, reasoningEffort: 'max')
        );
        self::assertArrayNotHasKey('thinkingConfig', $tierBody['generationConfig']);

        $dynamicBody = $this->capturedVertexBody(self::PRE_THINKING_GEMINI, -1, $this->ceilingRequest(self::PRE_THINKING_GEMINI));
        self::assertArrayNotHasKey('thinkingConfig', $dynamicBody['generationConfig'], '-1 is still a thinkingConfig value; on a family without the field the omission is the whole story');
    }

    public function testAGeminiBudgetPastTheFamilyCeilingIsClamped(): void
    {
        $body = $this->capturedGeminiNativeBody(
            self::THINKING_GEMINI,
            70_000,
            $this->ceilingRequest(self::THINKING_GEMINI)
        );

        self::assertSame(
            ['thinkingBudget' => self::GEMINI_CEILING],
            $body['generationConfig']['thinkingConfig'],
            'the native arm the factory `gemini` type builds reads the same table'
        );
    }

    /** @return array<string, array{int}> */
    public static function settingsShapes(): array
    {
        return ['dynamic' => [-1], 'off' => [0]];
    }

    #[DataProvider('settingsShapes')]
    public function testTheDynamicAndOffSpellingsPassThroughUnclampedOnAThinkingFamily(int $shape): void
    {
        $body = $this->capturedGeminiNativeBody(self::THINKING_GEMINI, $shape, $this->ceilingRequest(self::THINKING_GEMINI));

        self::assertSame(['thinkingBudget' => $shape], $body['generationConfig']['thinkingConfig'], 'a clamp can neither produce nor eat the settings side spellings');
    }

    public function testAnExplicitGeminiCeilingPastTheFamilyMaxIsTrimmed(): void
    {
        $body = $this->capturedGeminiNativeBody(
            self::THINKING_GEMINI,
            null,
            $this->ceilingRequest(self::THINKING_GEMINI, maxTokens: 70_000)
        );

        self::assertSame(self::GEMINI_CEILING, $body['generationConfig']['maxOutputTokens']);
        self::assertArrayNotHasKey('thinkingConfig', $body['generationConfig'], 'the A21(b) no-field-absent default is untouched by the clamp');
    }

    public function testAnUnknownIdOnTheGeminiWireKeepsEveryAskedByte(): void
    {
        $body = $this->capturedGeminiNativeBody(
            self::UNKNOWN_MODEL,
            90_000,
            $this->ceilingRequest(self::UNKNOWN_MODEL, maxTokens: 100_000)
        );

        self::assertSame(100_000, $body['generationConfig']['maxOutputTokens']);
        self::assertSame(['thinkingBudget' => 90_000], $body['generationConfig']['thinkingConfig']);
    }

    // -------------------------------------------------------------------------
    // Factory: the launch refusal above the family ceiling
    // -------------------------------------------------------------------------

    public function testTheFactoryRefusesAnAnthropicBudgetAboveTheFamilyCeilingAtLaunch(): void
    {
        try {
            (new ProviderFactory())->create([
                'type' => 'anthropic',
                'apiKey' => 'k',
                'model' => self::THINKING_ANTHROPIC,
                'thinkingBudget' => 65_000,
            ]);
            self::fail('a budget no arm can honour must be named at launch, not silently clamped every turn');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('thinkingBudget', $error->getMessage());
            self::assertStringContainsString('64000', $error->getMessage(), 'the refusal names the ceiling it refused against');
            self::assertStringContainsString('anthropic', $error->getMessage(), 'same provider-prefixed loud shape as the floor refusal');
        }
    }

    public function testTheFactoryRefusesAGeminiBudgetAboveTheFamilyCeilingAtLaunch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('65536');

        (new ProviderFactory())->create([
            'type' => 'gemini',
            'apiKey' => 'k',
            'model' => self::THINKING_GEMINI,
            'thinkingBudget' => 66_000,
        ]);
    }

    public function testTheFactoryRefusesAnOverlargeBudgetOnTheVertexArmToo(): void
    {
        try {
            (new ProviderFactory())->create([
                'type' => 'vertex',
                'projectId' => 'p',
                'model' => self::THINKING_GEMINI,
                'thinkingBudget' => 70_000,
            ]);
            self::fail('vertex reads the same table for the same reason');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('thinkingBudget', $error->getMessage());
            self::assertStringContainsString('65536', $error->getMessage());
        }
    }

    public function testTheFactoryStillAcceptsAWholeBudgetExactlyAtTheCeiling(): void
    {
        $provider = (new ProviderFactory())->create([
            'type' => 'anthropic',
            'apiKey' => 'k',
            'model' => self::THINKING_ANTHROPIC,
            'thinkingBudget' => self::ANTHROPIC_CEILING,
        ]);

        self::assertInstanceOf(AnthropicProvider::class, $provider, 'the ceiling itself is a legal budget - only past it is the refusal');
    }

    public function testTheFactoryRefusesNothingItDoesNotKnow(): void
    {
        $vertex = (new ProviderFactory())->create([
            'type' => 'vertex',
            'projectId' => 'p',
            'model' => self::UNKNOWN_MODEL,
            'thinkingBudget' => 100_000,
        ]);
        self::assertInstanceOf(VertexProvider::class, $vertex, 'an id the family table cannot place keeps the old pass-through on the tolerant parser');

        $anthropic = (new ProviderFactory())->create([
            'type' => 'anthropic',
            'apiKey' => 'k',
            'model' => 'made-up-proxy-1',
            'thinkingBudget' => 100_000,
        ]);
        self::assertInstanceOf(AnthropicProvider::class, $anthropic, 'and on the strict one - the table names no ceiling for it, so it names no refusal');
    }

    public function testTheTolerantParserStillStaysTolerantForGarbage(): void
    {
        $provider = (new ProviderFactory())->create([
            'type' => 'vertex',
            'projectId' => 'p',
            'model' => self::THINKING_GEMINI,
            'thinkingBudget' => 'plenty',
        ]);

        self::assertInstanceOf(VertexProvider::class, $provider, 'the launch refusal targets a whole number over the ceiling, never the un-parseable - that stays no-setting');
    }

    // -------------------------------------------------------------------------
    // Harness
    // -------------------------------------------------------------------------

    private function ceilingRequest(
        string $model,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?string $reasoningEffort = null,
    ): CompleteRequest {
        return new CompleteRequest(
            model: $model,
            messages: [new UserMessage('go')],
            temperature: $temperature,
            maxTokens: $maxTokens,
            reasoningEffort: $reasoningEffort,
        );
    }

    /** @return array<string, mixed> the body the native Messages seam was handed */
    private function capturedAnthropicBody(string $model, ?int $budget, CompleteRequest $request): array
    {
        $seen = null;
        $provider = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            $model,
            static function (string $endpoint, array $body) use (&$seen): array {
                $seen = $body;

                return ['content' => []];
            },
            null,
            [],
            true,
            null,
            $budget,
        );

        $provider->complete($request);

        self::assertIsArray($seen, 'the seam must have been reached at all');

        return $seen;
    }

    /** @return array<string, mixed> the body Vertex's predictor was handed (arm chosen by the model id) */
    private function capturedVertexBody(string $model, ?int $budget, CompleteRequest $request): array
    {
        $seen = null;
        $provider = VertexProvider::create(
            projectId: 'p',
            model: $model,
            predictor: static function (string $endpoint, string $method, array $body) use (&$seen): array {
                $seen = $body;

                return [];
            },
            thinkingBudget: $budget,
        );

        $provider->complete($request);

        self::assertIsArray($seen);

        return $seen;
    }

    /** @return array<string, mixed> the body the first-party Gemini transport seam was handed */
    private function capturedGeminiNativeBody(string $model, ?int $budget, CompleteRequest $request): array
    {
        $seen = null;
        $provider = new GeminiProvider(
            'gsk-test',
            'https://generativelanguage.googleapis.com',
            $model,
            static function (string $endpoint, array $body) use (&$seen): array {
                $seen = $body;

                return [];
            },
            null,
            [],
            $budget,
        );

        $provider->complete($request);

        self::assertIsArray($seen);

        return $seen;
    }
}
