<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Cli\Bootstrap;
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
use SugarCraft\Code\Skills\SkillOrigin;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
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
 *
 * MAJOR-6 added the second half of that sentence: a project-tier skill's
 * `allowed-tools` are withheld until the root carries the `commands` grant,
 * while its `disallowed-tools`, `model` and `effort` ride regardless. Every
 * test here therefore runs on a root that IS trusted — the grant is written
 * into the sandbox config in {@see setUp()}, so the pre-existing assertions
 * measure the legacy (honoured) behavior byte-for-byte — and the two door
 * tests at the end move the process to a second, ungranted home, because the
 * trust answer freezes per config path for the life of a process (that is the
 * design: a session cannot widen itself).
 */
final class RuntimeSkillEffectsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmpDir;
    private string $projectRoot;

    protected function setUp(): void
    {
        // Lane-cf law: the sandbox is installed before anything else touches
        // the environment, so restoreHomeSandbox() puts HOME back as found it.
        $this->tmpDir = \sys_get_temp_dir() . '/crush_rse_' . \uniqid('', true);
        $this->projectRoot = $this->tmpDir . '/project';
        @mkdir($this->projectRoot . '/.candy-code', 0700, true);
        $this->useHomeSandbox($this->tmpDir . '/home-trusted');
        $this->grantCommandsOn('home-trusted');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $dir = $this->tmpDir;
        $this->tmpDir = '';
        self::removeTree($dir);
    }

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

    // ── The MAJOR-6 trust door, measured through the production call site ──

    /**
     * THE UNTRUSTED POLARITY, end to end: the same roster, the same Skill call,
     * the same step loop — the only difference is that the operator's config
     * grants this root nothing. The chain gate must not even be swapped: with
     * the pre-approval withheld this skill contributes no rule at all, so the
     * turn runs byte-identically to a turn on which no skill ran.
     */
    public function testAnUntrustedProjectRootWithholdsThePreApprovalEntirely(): void
    {
        // A second home, not an edited one: the trust answer freezes per config
        // path for the life of the process, so widening or narrowing a grant
        // mid-session is not a thing this code can observe (by design).
        $this->useHomeSandbox($this->tmpDir . '/home-untrusted');

        $provider = $this->provider();
        $hooks = $this->hooks($gate = new PermissionGate(PermissionMode::Default));
        $runtime = new Runtime($provider, $hooks);
        $app = $this->app($provider, [$this->projectPreApprover()], [
            new UserMessage('do the thing'),
            new AssistantMessage('started', [new ToolCall('c1', 'Skill', ['name' => 'make-runner'])]),
            new ToolResultMessage('c1', 'loaded'),
        ]);

        self::assertFalse(
            Bootstrap::projectCommandShellIsTrusted($this->projectRoot),
            'the fixture must be measuring a root the config does not trust',
        );

        $runtime->run($app)->rewind();

        self::assertSame($gate, self::chainGate($hooks), 'nothing to carry must not swap the chain gate');
        self::assertFalse(self::chainGate($hooks)->hasTurnGrants(), 'the withheld allow list must reach no gate');
    }

    /**
     * THE SPLIT THE DOOR LEAVES OPEN: the same untrusted root still gets the
     * skill's refusal list, model and effort — a repository-authored skill may
     * make the session more careful, never less. {@see setUp()} arms the grant,
     * so this test moves to the ungranted home itself rather than inheriting it.
     */
    public function testAnUntrustedProjectRootStillCarriesRefusalsModelAndEffort(): void
    {
        $this->useHomeSandbox($this->tmpDir . '/home-untrusted-2');

        $captured = null;
        $provider = $this->provider();
        $hooks = $this->hooks($gate = new PermissionGate(PermissionMode::Default));
        $runtime = new Runtime($provider, $hooks);
        $skill = new Skill(
            name: 'php-wizard',
            description: 'A wizard for PHP work',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: 'Read, Grep',
            disallowedTools: 'WebFetch',
            model: 'opus-x',
            effort: 'high',
            context: 'thread',
            paths: [],
            content: 'wizard body',
            sourcePath: $this->projectRoot . '/.candy-code/skills/php-wizard/SKILL.md',
        );
        $app = $this->app($provider, [$skill], [
            new UserMessage('do the thing'),
            new AssistantMessage('started', [new ToolCall('c1', 'Skill', ['name' => 'php-wizard'])]),
            new ToolResultMessage('c1', 'loaded'),
        ]);

        $runtime->run($app, onRequest: static function (CompleteRequest $request) use (&$captured): void {
            $captured = $request;
        })->rewind();

        self::assertSame('opus-x', $captured?->model, 'the declared model rides an untrusted root');
        self::assertSame('high', $captured?->reasoningEffort, 'the declared effort rides it too');
        $chainGate = self::chainGate($hooks);
        self::assertNotSame($gate, $chainGate, 'the refusal list is not withheld, so the gate is swapped');
        self::assertSame([], $chainGate->turnAllowRules(), 'while the pre-approval is');
        self::assertCount(1, $chainGate->turnDenyRules(), 'the refusal the skill declared still binds the turn');
    }

    /**
     * A skill that pre-approves and declares nothing else: the shape that makes
     * the withheld case collapse to "no effect at all" rather than "smaller".
     */
    private function projectPreApprover(): Skill
    {
        return new Skill(
            name: 'make-runner',
            description: 'A skill a repository shipped',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: 'Bash(make)',
            disallowedTools: null,
            model: null,
            effort: null,
            context: 'thread',
            paths: [],
            content: 'run make',
            sourcePath: $this->projectRoot . '/.candy-code/skills/make-runner/SKILL.md',
        );
    }

    /**
     * Grant {@see $projectRoot} the `commands` trust in the sandbox home whose
     * leaf directory is $homeLeaf — the same list the `` !`cmd` `` forms read.
     */
    private function grantCommandsOn(string $homeLeaf): void
    {
        $configDir = $this->tmpDir . '/' . $homeLeaf . '/.candy-code';
        @mkdir($configDir, 0700, true);
        $canonical = realpath($this->projectRoot);
        file_put_contents(
            $configDir . '/config.json',
            json_encode(['trustedProjectCommands' => [$canonical === false ? $this->projectRoot : $canonical]]),
        );
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
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
            ->withRoot($this->projectRoot)
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
