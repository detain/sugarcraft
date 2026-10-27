<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use GuzzleHttp\Client as GuzzleClient;
use OpenAI\Contracts\ClientContract;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\BedrockProvider;
use SugarCraft\Code\Providers\ClaudeCodeInvocation;
use SugarCraft\Code\Providers\ClaudeCodeProvider;
use SugarCraft\Code\Providers\CodexInvocation;
use SugarCraft\Code\Providers\CodexProvider;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Providers\EffortGate;
use SugarCraft\Code\Providers\FallbackProvider;
use SugarCraft\Code\Providers\GeminiProvider;
use SugarCraft\Code\Providers\OpenAIProvider;
use SugarCraft\Code\Providers\SglangProvider;
use SugarCraft\Code\Providers\VertexProvider;

/**
 * C-PRV-1: the gate is the per-model truth the `/effort` surface answers
 * from. The roster below is the whole contract in both directions — every
 * pair that shapes must answer yes (the tier may ride), every pair that
 * does not must answer no (the surface refuses out loud instead of the
 * wire swallowing a set value). Model-id literals are spelled exactly as
 * the served APIs name them, no wildcards (§1.9 keeps the GlobDialect
 * corpus away from this file).
 */
final class EffortGateTest extends TestCase
{
    /**
     * Byte-for-byte the four `chat.effort.shape.*` sentences of
     * lang/en.php. A silent reword here would hide behind Lang::t();
     * pinning the literals is the drift alarm instead.
     */
    private const SHAPE_NONE = 'This provider has no effort control; the value changes nothing on the wire.';

    private const SHAPE_REQUEST = 'The tier rides the reasoning-effort field of every request this pair sends.';

    private const SHAPE_THINKING_HIGH = 'The tier spends the thinking budget (8192 tokens); a configured thinkingBudget key wins.';

    private const SHAPE_THINKING_LOW = 'The tier spends the thinking budget (1024 tokens); a configured thinkingBudget key wins.';

    private const SHAPE_THINKING_MEDIUM = 'The tier spends the thinking budget (4096 tokens); a configured thinkingBudget key wins.';

    private const SHAPE_UNSET = 'Nothing asked; the model answers at its own default.';

    public function testVerdictAnswersPerModelOnEveryShaper(): void
    {
        $roster = [
            // The C-PRV-1 bug itself: marked class, dead pair.
            'openai + gpt-4o' => [$this->gateOpenAI(), 'gpt-4o', false],
            'openai + gpt-4.1' => [$this->gateOpenAI(), 'gpt-4.1', false],
            'openai + gpt-5-chat-latest' => [$this->gateOpenAI(), 'gpt-5-chat-latest', false],
            // The reasoning arms where the field exists.
            'openai + o3' => [$this->gateOpenAI(), 'o3', true],
            'openai + o4-mini' => [$this->gateOpenAI(), 'o4-mini', true],
            'openai + gpt-5' => [$this->gateOpenAI(), 'gpt-5', true],
            'openai + gpt-5-pro' => [$this->gateOpenAI(), 'gpt-5-pro', true],
            // Anthropic-family thinking gates.
            'anthropic + claude-sonnet-4-6' => [$this->gateAnthropic(), 'claude-sonnet-4-6', true],
            'anthropic + claude-3-7-sonnet' => [$this->gateAnthropic(), 'claude-3-7-sonnet-20250620', true],
            'anthropic + claude-3-5-sonnet' => [$this->gateAnthropic(), 'claude-3-5-sonnet-20241022', false],
            'anthropic + claude-2.1' => [$this->gateAnthropic(), 'claude-2.1', false],
            // Vertex answers for both of its arms off one table.
            'vertex + claude arm' => [$this->gateVertex(), 'claude-sonnet-4-5', true],
            'vertex + gemini 2.5 arm' => [$this->gateVertex(), 'gemini-2.5-pro', true],
            'vertex + gemini 2.0 arm' => [$this->gateVertex(), 'gemini-2.0-flash', false],
            'vertex + neither family' => [$this->gateVertex(), 'mistral-large', false],
            // Direct Gemini.
            'gemini + gemini-2.5-flash' => [$this->gateGemini(), 'gemini-2.5-flash', true],
            'gemini + gemini-2.0-flash' => [$this->gateGemini(), 'gemini-2.0-flash', false],
            'gemini + gemini-1.5-pro' => [$this->gateGemini(), 'gemini-1.5-pro', false],
            // SGLang forwards the field on everything it builds.
            'sglang + any id' => [$this->gateSglang(), 'Qwen3-Next-80B', true],
            // Bedrock shapes only its Claude ids, decorated or not.
            'bedrock + bare claude id' => [$this->gateBedrock(), 'anthropic.claude-sonnet-4-6', true],
            // The generation leg inside the Claude head: pre-3-7 refused,
            // 3-7 and up shaped — same table the direct arms consult.
            'bedrock + claude-3-5-sonnet' => [$this->gateBedrock(), 'anthropic.claude-3-5-sonnet-20241022-v2:0', false],
            'bedrock + claude-3-7-sonnet' => [$this->gateBedrock(), 'anthropic.claude-3-7-sonnet-20250219-v1:0', true],
            'bedrock + dated version id' => [$this->gateBedrock(), 'anthropic.claude-sonnet-4-5-20250929-v1:0', true],
            'bedrock + geo profile id' => [$this->gateBedrock(), 'us.anthropic.claude-sonnet-4-6', true],
            'bedrock + arn' => [
                $this->gateBedrock(),
                'arn:aws:bedrock:us-east-1:123456789012:inference-profile/us.anthropic.claude-sonnet-4-5-v1:0',
                true,
            ],
            'bedrock + llama' => [$this->gateBedrock(), 'meta.llama3-1-8b-instruct-v1:0', false],
            // The CLI shapers answer for every served id.
            'codex + default id' => [$this->gateCodex(), 'gpt-5.1-codex-mini', true],
            'claude-code + default id' => [$this->gateClaudeCode(), 'claude-sonnet-4-6', true],
        ];

        foreach ($roster as $label => [$provider, $model, $expected]) {
            self::assertSame(
                $expected,
                EffortGate::verdict($provider, $model),
                $label . ' verdict',
            );
        }
    }

    public function testVerdictRefusesUnmarkedProvidersAndNull(): void
    {
        self::assertFalse(EffortGate::verdict(new EchoProvider(), 'anything'));
        self::assertFalse(EffortGate::verdict(null, 'o3'));
    }

    public function testVerdictReadsAFallbackRosterThroughItsPrimary(): void
    {
        // The tier rides whichever arm answers, but the claim a shaper
        // makes is its own; the primary's predicate is the gate's answer.
        $fallback = FallbackProvider::new(
            $this->gateOpenAI(),
            'o3',
            ['gpt-4o' => fn () => $this->gateAnthropic()],
        );

        self::assertTrue(EffortGate::verdict($fallback, 'o3'));
        self::assertFalse(EffortGate::verdict($fallback, 'gpt-4o'));
    }

    public function testShapeSpellsEveryArmWithThePinnedSentence(): void
    {
        // unset on a shaping pair: honest nothing-asked.
        self::assertSame(self::SHAPE_UNSET, EffortGate::shape($this->gateOpenAI(), null, 'o3'));
        // yes-verdict non-thinking wire: the request-field sentence.
        self::assertSame(self::SHAPE_REQUEST, EffortGate::shape($this->gateOpenAI(), 'high', 'o3'));
        // the headline fix: a MARKED class on a dead pair says none, not unset.
        self::assertSame(self::SHAPE_NONE, EffortGate::shape($this->gateOpenAI(), 'high', 'gpt-4o'));
        // the four thinking spenders, token figures per rung.
        self::assertSame(self::SHAPE_THINKING_HIGH, EffortGate::shape($this->gateAnthropic(), 'high', 'claude-sonnet-4-6'));
        self::assertSame(self::SHAPE_THINKING_LOW, EffortGate::shape($this->gateVertex(), 'low', 'gemini-2.5-pro'));
        self::assertSame(self::SHAPE_THINKING_MEDIUM, EffortGate::shape($this->gateGemini(), 'medium', 'gemini-2.5-flash'));
        // C-PRV-1: Bedrock joins the thinking arm — it spends the same table.
        self::assertSame(self::SHAPE_THINKING_LOW, EffortGate::shape($this->gateBedrock(), 'low', 'anthropic.claude-sonnet-4-6'));
        // ... and still answers none on its llama ids.
        self::assertSame(self::SHAPE_NONE, EffortGate::shape($this->gateBedrock(), 'high', 'meta.llama3-1-8b-instruct-v1:0'));
        // the CLI shapers ride the request-field sentence.
        self::assertSame(self::SHAPE_REQUEST, EffortGate::shape($this->gateSglang(), 'max', 'Qwen3-Next-80B'));
        self::assertSame(self::SHAPE_REQUEST, EffortGate::shape($this->gateCodex(), 'high', 'gpt-5.1-codex-mini'));
        self::assertSame(self::SHAPE_REQUEST, EffortGate::shape($this->gateClaudeCode(), 'xhigh', 'claude-sonnet-4-6'));
        // an unmarked provider refuses whatever the level.
        self::assertSame(self::SHAPE_NONE, EffortGate::shape(new EchoProvider(), 'high', 'anything'));
        // shape walks the same primary-unwrap as verdict.
        $fallback = FallbackProvider::new(
            $this->gateOpenAI(),
            'o3',
            ['gpt-4o' => fn () => $this->gateAnthropic()],
        );
        self::assertSame(self::SHAPE_REQUEST, EffortGate::shape($fallback, 'high', 'o3'));
        self::assertSame(self::SHAPE_NONE, EffortGate::shape($fallback, 'high', 'gpt-4o'));
    }

    private function gateOpenAI(): OpenAIProvider
    {
        return new OpenAIProvider($this->createMock(ClientContract::class), 'gpt-4o');
    }

    private function gateAnthropic(): AnthropicProvider
    {
        return new AnthropicProvider('sk-test');
    }

    private function gateVertex(): VertexProvider
    {
        return new VertexProvider('test-project', 'us-east5', 'claude-sonnet-4-5');
    }

    private function gateGemini(): GeminiProvider
    {
        return new GeminiProvider('test-key');
    }

    private function gateSglang(): SglangProvider
    {
        return new SglangProvider('https://sglang.invalid', 'Qwen3-Next-80B', null, new GuzzleClient());
    }

    private function gateBedrock(): BedrockProvider
    {
        return new BedrockProvider($this->createMock(BedrockRuntimeClient::class));
    }

    private function gateCodex(): CodexProvider
    {
        return new CodexProvider(new CodexInvocation());
    }

    private function gateClaudeCode(): ClaudeCodeProvider
    {
        return new ClaudeCodeProvider(new ClaudeCodeInvocation());
    }
}
