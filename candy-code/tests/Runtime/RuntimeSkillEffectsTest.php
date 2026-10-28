<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Runtime;
use SugarCraft\Code\Context\TurnContextBlock;
use SugarCraft\Code\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tools\ToolCall;

/**
 * C-EXT-2.b (R-EXT-1): what the step loop honours.
 *
 * A skill invoked on this turn feeds its turn-scoped permissions through the
 * shared gate, and its declared model and effort ride onto the NEXT request
 * the loop sends — the history a grown step carries contains step 1's Skill
 * call, so a derivation done per run() makes the grant visible from the
 * following step onward without persistent state (forked children re-derive
 * from their copied messages). Absent a declaration the session model and
 * the preset effort stand.
 */
final class RuntimeSkillEffectsTest extends TestCase
{
    public function testASkillInvokedOnTheTurnRewritesTheNextRequestAndFeedsTheGate(): void
    {
        $captured = null;
        $provider = $this->provider();
        $hooks = $this->hooks($gate = new PermissionGate(PermissionMode::Default));
        $runtime = new Runtime($provider, $hooks);
        $app = $this->app($provider, [$this->wizard()], [
            new UserMessage('do the thing'),
            new AssistantMessage('started', [new ToolCall('c1', 'Skill', ['name' => 'php-wizard'])]),
            new ToolResultMessage('c1', 'loaded'),
        ]);

        $stream = $runtime->run($app, onRequest: static function (CompleteRequest $request) use (&$captured): void {
            $captured = $request;
        });
        $stream->rewind();

        self::assertNotNull($captured, 'the onRequest seam must receive the request the step sends');
        self::assertSame('opus-x', $captured->model, 'the skill\'s declared model must ride the next request');
        self::assertSame('high', $captured->reasoningEffort, 'the skill\'s declared effort must ride the next request');

        $chainGate = self::chainGate($hooks);
        self::assertNotSame($gate, $chainGate, 'the chain gate must be swapped to carry the turn grants');
        self::assertCount(2, $chainGate->turnAllowRules(), 'the skill\'s allow list must be on the live gate');
    }

    public function testEffectsFromTheFirstStepAreOnTheGateForTheNextStep(): void
    {
        // The step loop grows the history between steps; the Skill call from
        // step 1's row is therefore present when step 2's run() derives —
        // proven here by asserting the derivation sees a call the ORIGINAL
        // prompt predates, then a later fresh prompt clears it.
        $captured = null;
        $provider = $this->provider();
        $hooks = $this->hooks(new PermissionGate(PermissionMode::Default));
        $runtime = new Runtime($provider, $hooks);
        $app = $this->app($provider, [$this->wizard()], [
            new UserMessage('do the thing'),
            new AssistantMessage('started', [new ToolCall('c1', 'Skill', ['name' => 'php-wizard'])]),
            new ToolResultMessage('c1', 'loaded'),
            new AssistantMessage('working on it'),
        ]);

        $stream = $runtime->run($app, onRequest: static function (CompleteRequest $request) use (&$captured): void {
            $captured = $request;
        });
        $stream->rewind();

        self::assertSame('opus-x', $captured?->model, 'a grant earned at step 1 must speak at step 2');
    }

    public function testAFreshPromptClearsTheEffectsAndTheGateStaysUntouched(): void
    {
        $captured = null;
        $provider = $this->provider();
        $hooks = $this->hooks($gate = new PermissionGate(PermissionMode::Default));
        $runtime = new Runtime($provider, $hooks);
        $app = $this->app($provider, [$this->wizard()], [
            new UserMessage('do the thing'),
            new AssistantMessage('started', [new ToolCall('c1', 'Skill', ['name' => 'php-wizard'])]),
            new ToolResultMessage('c1', 'loaded'),
            new UserMessage('something else entirely'),
        ])->withReasoningEffort('low');

        $stream = $runtime->run($app, onRequest: static function (CompleteRequest $request) use (&$captured): void {
            $captured = $request;
        });
        $stream->rewind();

        self::assertSame('gpt-4', $captured?->model, 'a new real prompt ends the turn; the session model stands');
        self::assertSame('low', $captured?->reasoningEffort, 'the preset effort must not be rewritten by a stale skill');
        self::assertSame($gate, self::chainGate($hooks), 'nothing to carry must not swap the chain gate');
        self::assertFalse($gate->hasTurnGrants());
    }

    public function testASkillDeclaringOnlyTheModelLeavesThePresetEffortAlone(): void
    {
        $captured = null;
        $provider = $this->provider();
        $hooks = $this->hooks(new PermissionGate(PermissionMode::Default));
        $runtime = new Runtime($provider, $hooks);
        $modelOnly = new Skill(
            name: 'model-only',
            description: 'declares a model alone',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: 'sonnet-y',
            effort: null,
            context: 'thread',
            paths: [],
            content: 'body',
            sourcePath: '/s/model-only/SKILL.md',
        );
        $app = $this->app($provider, [$modelOnly], [
            new UserMessage('do the thing'),
            new AssistantMessage('started', [new ToolCall('c1', 'Skill', ['name' => 'model-only'])]),
            new ToolResultMessage('c1', 'loaded'),
        ])->withReasoningEffort('low');

        $stream = $runtime->run($app, onRequest: static function (CompleteRequest $request) use (&$captured): void {
            $captured = $request;
        });
        $stream->rewind();

        self::assertSame('sonnet-y', $captured?->model, 'the declared model rides the request');
        self::assertSame('low', $captured?->reasoningEffort, 'an undeclared effort must not overwrite the preset');
    }

    public function testTheReinjectedRosterAloneCountsForTheGateSwap(): void
    {
        $provider = $this->provider();
        $hooks = $this->hooks(new PermissionGate(PermissionMode::Default));
        $runtime = new Runtime($provider, $hooks);
        $turnContext = TurnContextBlock::new()->withInvokedSkills(['php-wizard'])->message();
        $app = $this->app($provider, [$this->wizard()], [
            new UserMessage('do the thing'),
            new AssistantMessage('started', [new ToolCall('c1', 'Skill', ['name' => 'php-wizard'])]),
            new ToolResultMessage('c1', 'loaded'),
            $turnContext,
        ]);

        $stream = $runtime->run($app);
        $stream->rewind();

        self::assertCount(2, self::chainGate($hooks)->turnAllowRules(), 'the gate must carry the skill allow list');
    }

    private function wizard(): Skill
    {
        return new Skill(
            name: 'php-wizard',
            description: 'A wizard for PHP work',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: 'Read, Grep',
            disallowedTools: null,
            model: 'opus-x',
            effort: 'high',
            context: 'thread',
            paths: [],
            content: 'wizard body',
            sourcePath: '/s/php-wizard/SKILL.md',
        );
    }

    private function provider(): ProviderInterface
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test-provider');
        $provider->method('supportsStreaming')->willReturn(false);
        $provider->method('complete')->willReturn(new CompleteResponse('all done'));

        return $provider;
    }

    /**
     * @param array<int, Skill> $skills
     * @param array<int, object> $messages
     */
    private function app(ProviderInterface $provider, array $skills, array $messages): App
    {
        $registry = new SkillRegistry();
        $registry->register($skills);

        return App::new($provider, 'gpt-4')
            ->withTools([])
            ->withMessages($messages)
            ->withAvailableSkills($registry);
    }

    private function hooks(PermissionGate $gate): HookManager
    {
        $hooks = new HookManager(new HookRegistry());
        $hooks->register(new PermissionGateHook($gate));

        return $hooks;
    }

    private static function chainGate(HookManager $hooks): PermissionGate
    {
        $hook = $hooks->hook(HookEvent::PreToolUse->value, PermissionGateHook::NAME);
        self::assertInstanceOf(PermissionGateHook::class, $hook);

        return $hook->gate();
    }
}
