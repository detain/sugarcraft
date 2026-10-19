<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\BedrockProvider;
use SugarCraft\Code\Providers\ClaudeCodeProvider;
use SugarCraft\Code\Providers\CustomProvider;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Providers\EffortBudget;
use SugarCraft\Code\Providers\GeminiProvider;
use SugarCraft\Code\Providers\OpenAIProvider;
use SugarCraft\Code\Providers\ShapesReasoningEffort;
use SugarCraft\Code\Providers\SglangProvider;
use SugarCraft\Code\Providers\VertexProvider;

/**
 * PRV-2 (§6.5): the effort tier is one named dial, and this class owns the
 * single table every Anthropic-family wire spends it through. The token
 * counts are the contract - a tier that silently changed price would be a
 * spend change wearing a UX mask - so the table is pinned digit-for-digit,
 * and the cheapest rung is pinned against the protocol floor it must clear
 * ({@see AnthropicProvider} sends it as `thinking.budget_tokens`).
 */
final class EffortBudgetTest extends TestCase
{
    public function testTheTierTableIsTheWholeContract(): void
    {
        self::assertSame(1024, EffortBudget::thinkingTokens('low'));
        self::assertSame(4096, EffortBudget::thinkingTokens('medium'));
        self::assertSame(8192, EffortBudget::thinkingTokens('high'));
        self::assertSame(16384, EffortBudget::thinkingTokens('xhigh'));
        self::assertSame(32768, EffortBudget::thinkingTokens('max'));
    }

    public function testTheTableIsCasewiseAndTrimTolerantLikeTheResolverItFeeds(): void
    {
        self::assertSame(8192, EffortBudget::thinkingTokens('  HIGH  '));
    }

    public function testAnythingTheEffortEnumCannotNameAnswersNull(): void
    {
        // Null (no dial), unknown spellings, and floats (the SGLang dial is a
        // continuous number, not one of these rungs) all mean "no tier here"
        // - never a guess, which is what keeps unset == pre-PRV-2 byte.
        self::assertNull(EffortBudget::thinkingTokens(null));
        self::assertNull(EffortBudget::thinkingTokens('turbo'));
        self::assertNull(EffortBudget::thinkingTokens('none'));
        self::assertNull(EffortBudget::thinkingTokens('minimal'));
        self::assertNull(EffortBudget::thinkingTokens(0.5));
    }

    public function testLevelsAreTheRungsInSpellingOrder(): void
    {
        self::assertSame(['low', 'medium', 'high', 'xhigh', 'max'], EffortBudget::levels());
    }

    public function testTheCheapestRungIsTheProtocolFloorItself(): void
    {
        // low == the Anthropic Messages API minimum budget (read through the
        // provider that consumes the wire trait - trait constants are not
        // addressable directly): if the floor ever moves, the tier table has
        // to move with it, and this pin is where a drift becomes a red test
        // instead of a 400 at the API.
        self::assertSame(AnthropicProvider::MIN_THINKING_BUDGET, EffortBudget::thinkingTokens('low'));
    }

    public function testTheShapesRosterIsExactlyTheFiveDialTurners(): void
    {
        // /effort refuses loudly everywhere else; this roster IS the refusal
        // set's complement, so an accidental `implements` (or a rename that
        // drops one) flips a refusal into silence or vice versa. Pinned
        // against the classes, not a narrated list.
        $shapers = [];
        foreach ($this->providerClasses() as $class) {
            if (is_subclass_of($class, ShapesReasoningEffort::class)) {
                $shapers[] = $class;
            }
        }

        self::assertSame([
            OpenAIProvider::class,
            SglangProvider::class,
            AnthropicProvider::class,
            VertexProvider::class,
            GeminiProvider::class,
        ], $shapers);
    }

    public function testTheRefusalSetCarriesTheMarkerNotAtAll(): void
    {
        // Bedrock speaks Converse (no thinking wire today), Custom and
        // ClaudeCode forward no budget knob, Echo draws nothing: a /effort
        // grant here would be the banned set-but-ignored failure.
        foreach ([BedrockProvider::class, CustomProvider::class, ClaudeCodeProvider::class, EchoProvider::class] as $class) {
            self::assertNotContains(ShapesReasoningEffort::class, class_implements($class) ?: [], $class.' must stay a loud refusal');
        }
    }

    /** @return list<class-string> */
    private function providerClasses(): array
    {
        return [
            OpenAIProvider::class,
            SglangProvider::class,
            AnthropicProvider::class,
            VertexProvider::class,
            GeminiProvider::class,
            BedrockProvider::class,
            CustomProvider::class,
            ClaudeCodeProvider::class,
            EchoProvider::class,
        ];
    }
}
