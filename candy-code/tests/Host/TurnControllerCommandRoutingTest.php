<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Host\TurnController;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

/**
 * C-EXT-2.e: the two routing decisions a file command's frontmatter now makes.
 *
 * {@see TurnController::wrapCommandDelegation()} is THE one implementation of
 * the fenced delegation directive — TUI, `-p` and serve all hand their
 * expansion through it — so its exact shape is pinned here rather than
 * paraphrased: the directive is prose the model is told to obey, and a silent
 * rewrite of it changes what the model is asked to do.
 * {@see TurnController::commandTurnModel()} is the inline `model:` half: the
 * Task rule re-derived for one turn (plan §6 R13 — one rule, one spelling),
 * pinning what plainly can be served and falling back WITH a notice what
 * plainly cannot. Both are pure methods on the stateless controller, so this
 * file drives them directly; the submit()- and admit()-through wiring is the
 * sibling files {@see \SugarCraft\Code\Tests\Chat\CustomCommandModelOverrideTest}
 * and SessionHostCommandsTest.
 */
final class TurnControllerCommandRoutingTest extends TestCase
{
    private function spec(string $name = 'audit', ?string $model = null, bool $subtask = false, ?string $agent = null): CommandSpec
    {
        return CommandSpec::new(
            name: $name,
            description: 'x',
            category: 'project',
            template: 'body',
            model: $model,
            subtask: $subtask,
            agent: $agent,
        );
    }

    // -----------------------------------------------------------------
    // The delegation directive
    // -----------------------------------------------------------------

    public function testACommandWithNoDelegationKeyPassesTheExpansionThroughUnwrapped(): void
    {
        $turns = TurnController::new();

        self::assertSame(
            'Review src for bugs.',
            $turns->wrapCommandDelegation('Review src for bugs.', $this->spec(model: 'big')),
            'an inline model rides the one-turn pin, never the delegation fence',
        );
        self::assertSame(
            'plain body',
            $turns->wrapCommandDelegation('plain body', $this->spec()),
        );
    }

    public function testASubtaskCommandWrapsTheExpansionInFencedTaskDirective(): void
    {
        $directive = TurnController::new()->wrapCommandDelegation('Review src for bugs.', $this->spec(subtask: true));

        self::assertSame(
            'The command `/audit` declares `subtask: true`: this prompt is a task to delegate, '
            . 'not something to answer here. Invoke the Task tool with agent "coder" and the text '
            . "between the fences below as its prompt verbatim, and answer from what that run returns.\n"
            . "```\nReview src for bugs.\n```",
            $directive,
            'the whole directive shape is pinned: a drift here changes what the model is told to do',
        );
    }

    public function testAnAgentAloneStillDelegatesAndNamesItself(): void
    {
        $directive = TurnController::new()->wrapCommandDelegation('ship it', $this->spec(agent: 'sage'));

        self::assertStringStartsWith(
            'The command `/audit` declares `agent: sage`: this prompt is a task to delegate',
            $directive,
        );
        self::assertStringContainsString('Invoke the Task tool with agent "sage" and', $directive);
    }

    public function testSubtaskWithAgentSpellsBothAndModelRidesTheDirective(): void
    {
        $directive = TurnController::new()->wrapCommandDelegation(
            'deep work',
            $this->spec(model: 'big-model', subtask: true, agent: 'worker'),
        );

        self::assertStringContainsString('declares `subtask: true` and `agent: worker`', $directive);
        self::assertStringContainsString('with agent "worker", model "big-model" and', $directive);
    }

    public function testADelegatingCommandModelIsNotAlsoPinnedInline(): void
    {
        // The directive carries the asked-for model and TaskTool::runOnEngine()
        // decides it inside the run; pinning it on the delegating turn too would
        // put the session model under a fork the command meant elsewhere.
        $spec = $this->spec(model: 'big-model', subtask: true, agent: 'worker');
        $engine = new EngineBackend(new ScriptedProvider([]), 'session-model');

        self::assertNull(TurnController::new()->commandTurnModel($spec, $engine));
        self::assertNull(TurnController::new()->commandTurnModel($this->spec(model: 'big-model', agent: 'worker'), $engine));
    }

    // -----------------------------------------------------------------
    // The one-turn inline model
    // -----------------------------------------------------------------

    public function testNoModelMeansNoDecisionAtAll(): void
    {
        $engine = new EngineBackend(new ScriptedProvider([]), 'session-model');

        self::assertNull(TurnController::new()->commandTurnModel($this->spec(), $engine));
        self::assertNull(
            TurnController::new()->commandTurnModel($this->spec(model: '   '), $engine),
            'a blank model is no model',
        );
    }

    public function testANonEngineSessionAnswersNullSoTheRowStaysUnsaid(): void
    {
        self::assertNull(
            TurnController::new()->commandTurnModel($this->spec(model: 'big'), null),
            'EchoBackend and friends have no withModel(); an inert pin there is the truth, not a loss',
        );
    }

    public function testAModelEqualToTheSessionsNeedsNoPin(): void
    {
        $engine = new EngineBackend(new ScriptedProvider([]), 'session-model');

        self::assertNull(
            TurnController::new()->commandTurnModel($this->spec(model: 'session-model'), $engine),
            'the turn already runs there; cloning the backend to say so is noise',
        );
    }

    public function testAMultiModelServerPinsTheRequestedModelForTheOneTurn(): void
    {
        // ScriptedProvider answers with its ctor model, so any id beside the
        // session's is a name a general endpoint could be pointed at.
        $engine = new EngineBackend(new ScriptedProvider([]), 'session-model');
        $decided = TurnController::new()->commandTurnModel($this->spec(model: 'other/one'), $engine);

        self::assertNotNull($decided);
        self::assertSame(['other/one', null], $decided, 'the pin carries no notice — the request simply goes out there');
    }

    public function testAModelIdWithInteriorWhitespaceFallsBackWithTheTaskRuleNotice(): void
    {
        $engine = new EngineBackend(new ScriptedProvider([]), 'session-model');
        $decided = TurnController::new()->commandTurnModel($this->spec(model: 'two words'), $engine);

        self::assertNotNull($decided);
        self::assertNull($decided[0]);
        self::assertSame(
            'The command `/audit` asked for model `two words`, which is not a model id; '
            . "this turn ran on this session's model instead.",
            $decided[1],
        );
    }

    public function testAClaudeTierNameTheSessionCannotHearFallsBack(): void
    {
        $engine = new EngineBackend(new ScriptedProvider([]), 'session-model');
        $decided = TurnController::new()->commandTurnModel($this->spec(model: 'sonnet'), $engine);

        self::assertNotNull($decided);
        self::assertNull($decided[0]);
        self::assertSame(
            'The command `/audit` asked for model `sonnet`, a Claude Code tier name no scripted model '
            . "answers to; this turn ran on this session's model instead.",
            $decided[1],
        );
    }

    public function testAClaudeTierNameTheSessionAlreadyAnswersToNeedsNoPin(): void
    {
        $engine = new EngineBackend(new ScriptedProvider([]), 'claude-sonnet-4-5');

        self::assertNull(
            TurnController::new()->commandTurnModel($this->spec(model: 'sonnet'), $engine),
            'the alias names the model already running, exactly as Task treats a request that means this session',
        );
    }

    public function testAServerReportingAnotherServedModelFallsBack(): void
    {
        $engine = EngineBackend::new($this->servedModelingProvider('this-one'), 'session-model');
        $decided = TurnController::new()->commandTurnModel($this->spec(model: 'other-one'), $engine);

        self::assertNotNull($decided);
        self::assertNull($decided[0]);
        self::assertSame(
            'The command `/audit` asked for model `other-one`, which this served-model-double server '
            . "does not serve; this turn ran on this session's model instead.",
            $decided[1],
        );

        $match = TurnController::new()->commandTurnModel($this->spec(model: 'this-one'), $engine);
        self::assertSame(['this-one', null], $match, 'the served name the command asked for pins');
    }

    public function testASingleModelServerFallsBack(): void
    {
        // Same route TaskToolPresetModelTest takes: an SglangProvider is
        // constructed, never dialled — servesOneModel() is a type fact.
        $provider = new \SugarCraft\Code\Providers\SglangProvider(
            'https://gateway.invalid',
            'one-model',
            null,
            new \GuzzleHttp\Client(['http_errors' => false]),
        );
        $engine = new EngineBackend($provider, 'one-model');
        $decided = TurnController::new()->commandTurnModel($this->spec(model: 'some-other'), $engine);

        self::assertNotNull($decided);
        self::assertNull($decided[0]);
        self::assertStringContainsString('does not serve: it runs one model', (string) $decided[1]);
    }

    // -----------------------------------------------------------------
    // helpers (names unique across the suite: DuplicatedTestHelperDrift)
    // -----------------------------------------------------------------

    /** A provider that reports one fixed served model, like a pinned gateway. */
    private function servedModelingProvider(string $served): \SugarCraft\Code\Providers\ProviderInterface
    {
        return new class ($served) implements \SugarCraft\Code\Providers\ProviderInterface, \SugarCraft\Code\Providers\ReportsServedModel {
            public function __construct(private string $served)
            {
            }

            public function name(): string
            {
                return 'served-model-double';
            }

            public function servedModel(): ?string
            {
                return $this->served;
            }

            public function noteServedModel(string $model): void
            {
            }

            public function complete(\SugarCraft\Code\Providers\CompleteRequest $request): \SugarCraft\Code\Providers\CompleteResponse
            {
                throw new \LogicException('fixture: never called');
            }

            public function completeStream(\SugarCraft\Code\Providers\CompleteRequest $request): \Generator
            {
                throw new \LogicException('fixture: never called');
            }

            public function embeddings(\SugarCraft\Code\Providers\EmbeddingsRequest $request): \SugarCraft\Code\Providers\EmbeddingsResponse
            {
                throw new \LogicException('fixture: never called');
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function supportsFunctionCalling(): bool
            {
                return false;
            }

            public function supportsDocuments(): bool
            {
                return false;
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function supportsJsonSchema(): bool
            {
                return false;
            }

            public function contextWindow(): int
            {
                return 100000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }
        };
    }
}
