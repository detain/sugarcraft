<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use OpenAI\Contracts\ClientContract;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Commands\EffortDial;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Providers\EffortBudget;
use SugarCraft\Code\Providers\EffortGate;
use SugarCraft\Code\Providers\OpenAIProvider;
use SugarCraft\Code\Tests\Support\PinsEnglishLocaleTrait;

/**
 * C-WEB-2.f: the lifted dial is tested AS A FUNCTION - args, engine and a
 * recording door - so the two surfaces that now share it cannot each keep a
 * private copy of the truth. The row WORDING stays pinned terminal-side by
 * EffortCommandTest (byte-identity across the lift); what these pins police
 * is the SELECTION and the door discipline:
 *
 *  - a read (bare, usage, refusal) never calls the persist door at all -
 *    the "refuses and never persists" promise made structural, countable;
 *  - a set calls it exactly once, with the level string;
 *  - auto/clear calls it exactly once, with null - the DELETE signal;
 *  - the returned backend is null on every non-change, dialled on every
 *    change, so a host surface can never ship an effect for a refusal.
 */
final class EffortDialTest extends TestCase
{
    use PinsEnglishLocaleTrait;

    /** @param list<string|null> $seen */
    private function door(array &$seen): \Closure
    {
        $seen = [];

        return static function (?string $level) use (&$seen): string {
            $seen[] = $level;

            return 'NOTE<' . ($level ?? 'null') . '>';
        };
    }

    private function shapedEngine(): EngineBackend
    {
        return new EngineBackend(
            new AnthropicProvider('sk-test', 'https://api.anthropic.com', 'claude-sonnet-4-6'),
            'claude-sonnet-4-6',
        );
    }

    /** On the provider roster, off this model's shape: the gpt-4o refusal pair. */
    private function rosterModelEngine(string $model): EngineBackend
    {
        return new EngineBackend(
            new OpenAIProvider($this->createMock(ClientContract::class), $model),
            $model,
        );
    }

    /** Off the roster entirely: a provider class that carries no dial. */
    private function offRosterEngine(): EngineBackend
    {
        return new EngineBackend(new EchoProvider(), 'echo');
    }

    public function testASetTurnsTheOverlayAndSendsTheLevelThroughTheDoorOnce(): void
    {
        $seen = [];
        [$reply, $next] = EffortDial::decide(['high'], $this->shapedEngine(), $this->door($seen));

        $this->assertSame(['high'], $seen);
        $this->assertInstanceOf(EngineBackend::class, $next);
        $this->assertSame('high', $next->reasoningEffort());
        $this->assertStringContainsString('NOTE<high>', $reply);
        $this->assertStringContainsString('anthropic', $reply);
    }

    public function testTheTokenIsCasewiseAndTrimmedBeforeTheDoorSeesIt(): void
    {
        $seen = [];
        [, $next] = EffortDial::decide(['  HIGH '], $this->shapedEngine(), $this->door($seen));

        $this->assertSame(['high'], $seen);
        $this->assertSame('high', $next?->reasoningEffort());
    }

    public function testAutoSendsTheDeleteSignalAndClearsTheOverlay(): void
    {
        $seen = [];
        [$reply, $next] = EffortDial::decide(['auto'], $this->shapedEngine()->withReasoningEffort('high'), $this->door($seen));

        $this->assertSame([null], $seen);
        $this->assertNull($next?->reasoningEffort());
        $this->assertStringContainsString('NOTE<null>', $reply);
    }

    public function testClearIsTheSameDoorSignalAsAuto(): void
    {
        $seen = [];
        EffortDial::decide(['clear'], $this->shapedEngine(), $this->door($seen));

        $this->assertSame([null], $seen);
    }

    public function testAutoWithoutAnEngineStillHandsTheDoorTheDelete(): void
    {
        $seen = [];
        [$reply, $next] = EffortDial::decide(['auto'], null, $this->door($seen));

        $this->assertSame([null], $seen, 'the saved key must be deleted even when nothing is overlaid');
        $this->assertNull($next);
        $this->assertStringContainsString('NOTE<null>', $reply);
    }

    public function testARefusalOnARosterProviderWithAnUnshapedModelNeverTouchesTheDoor(): void
    {
        $seen = [];
        [$reply, $next] = EffortDial::decide(['high'], $this->rosterModelEngine('gpt-4o'), $this->door($seen));

        $this->assertSame([], $seen, 'the banned failure is a saved key no wire reads');
        $this->assertNull($next);
        $this->assertSame(Lang::t('chat.effort.unsupported_model', ['provider' => 'openai', 'model' => 'gpt-4o']), $reply);
    }

    public function testARefusalOffTheRosterNamesTheProviderAndNeverTouchesTheDoor(): void
    {
        $seen = [];
        [$reply, $next] = EffortDial::decide(['high'], $this->offRosterEngine(), $this->door($seen));

        $this->assertSame([], $seen);
        $this->assertNull($next);
        $this->assertSame(Lang::t('chat.effort.unsupported', ['provider' => 'echo']), $reply);
    }

    public function testAnInvalidLevelOnAnOffRosterProviderIsUsageFirstNotAFalseRefusal(): void
    {
        $seen = [];
        [$reply, $next] = EffortDial::decide(['ultramax'], $this->offRosterEngine(), $this->door($seen));

        $this->assertSame([], $seen);
        $this->assertNull($next);
        $this->assertSame(
            Lang::t('chat.effort.usage', ['levels' => implode('|', EffortBudget::levels())]),
            $reply,
        );
    }

    public function testAnUnknownTokenIsUsageNotAPersist(): void
    {
        $seen = [];
        [$reply, $next] = EffortDial::decide(['mostly'], $this->shapedEngine(), $this->door($seen));

        $this->assertSame([], $seen);
        $this->assertNull($next);
        $this->assertSame(
            Lang::t('chat.effort.usage', ['levels' => implode('|', EffortBudget::levels())]),
            $reply,
        );
    }

    public function testTwoWordsAreUsageWithoutAnyEngineQuestion(): void
    {
        $seen = [];
        [$reply, $next] = EffortDial::decide(['very', 'high'], $this->offRosterEngine(), $this->door($seen));

        $this->assertSame([], $seen);
        $this->assertNull($next);
        $this->assertStringContainsString(implode('|', EffortBudget::levels()), $reply);
    }

    public function testBareReadsTheLiveOverlayAndShapeWithoutAskingTheDoor(): void
    {
        $seen = [];
        $engine = $this->shapedEngine()->withReasoningEffort('medium');
        [$reply, $next] = EffortDial::decide([], $engine, $this->door($seen));

        $this->assertSame([], $seen);
        $this->assertNull($next, 'a read changes nothing, so no surface may ship an effect');
        $provider = $engine->provider();
        $this->assertSame(Lang::t('chat.effort.status', [
            'level' => 'medium',
            'provider' => 'anthropic',
            'shape' => EffortGate::shape($provider, 'medium', 'claude-sonnet-4-6'),
        ]), $reply);
    }

    public function testBareWithoutAnOverlaySaysUnset(): void
    {
        $seen = [];
        [$reply, $next] = EffortDial::decide([], $this->shapedEngine(), $this->door($seen));

        $this->assertSame([], $seen);
        $this->assertNull($next);
        $this->assertStringContainsString('unset', $reply);
        $this->assertStringContainsString('anthropic', $reply);
    }

    public function testBareWithoutAnyEngineIsToldPlainlyAndNothingPersists(): void
    {
        $seen = [];
        [$reply, $next] = EffortDial::decide([], null, $this->door($seen));

        $this->assertSame([], $seen);
        $this->assertNull($next);
        $this->assertSame(Lang::t('chat.effort.status_no_backend'), $reply);
    }
}
