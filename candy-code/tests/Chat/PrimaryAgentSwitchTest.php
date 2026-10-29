<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Role;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * C-AGT-4.b: the session's PRIMARY AGENT switch, wired end to end.
 *
 * `/agent use <preset>` and `Alt+A` both land on Chat's own apply path, and
 * this file pins that path from each door that actually opens in production:
 * the typed command (through the public `runCommand`), the chord (through
 * `update(KeyMsg)`), the mid-turn refusal (the mode-switch shape, restated
 * here because a primary-agent swap rebuilds the launch backend while a turn
 * is reading it), the hand-over on a session switch (the record belongs to
 * the session that picked it), the cycle's presenting rule (a preset that
 * declares `mode: subagent` is never offered, because presenting is a claim
 * the preset frontmatter — not the loaded Agent — owns), the echo-backend
 * refusal, and the status badge the switch paints.
 *
 * The narrowing itself is C-AGT-4.a's contract and lives in
 * {@see \SugarCraft\Code\Tests\Agents\PrimaryAgentTest}; here the pins are
 * that the switch APPLIES and UNDOES it through the roster's real objects
 * and says so in the operator's language.
 */
final class PrimaryAgentSwitchTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-primary-agent-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
        mkdir($this->sandbox . '/project', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    // ------------------------------------------------------ the typed command

    public function testTheUseVerbNarrowsTheBackendAndNamesThePrimaryAgent(): void
    {
        $chat = $this->chat(registry: [$this->probeTool('keep'), $this->probeTool('drop')], agents: [
            $this->agent('worker', tools: ['keep']),
        ]);

        [$next] = $chat->runCommand('/agent use worker');

        self::assertSame('worker', $next->primaryAgentName());
        $backend = $next->backend();
        self::assertInstanceOf(EngineBackend::class, $backend);
        self::assertSame(['keep'], \array_map(static fn (Tool $t): string => $t->name(), $backend->tools()),
            'the preset narrows the roster down to what it declares');
    }

    public function testUseDefaultRevertsTheRecordedSliceByValue(): void
    {
        $chat = $this->chat(registry: [$this->probeTool('keep'), $this->probeTool('drop')], agents: [
            $this->agent('worker', tools: ['keep']),
        ]);

        [$narrowed] = $chat->runCommand('/agent use worker');
        [$back] = $narrowed->runCommand('/agent use default');

        self::assertNull($back->primaryAgentName(), 'default is a REAL choice, not a no-op spelling');
        $backend = $back->backend();
        self::assertInstanceOf(EngineBackend::class, $backend);
        self::assertSame(['keep', 'drop'], \array_map(static fn (Tool $t): string => $t->name(), $backend->tools()),
            'revert restores the launch roster, in launch order');
    }

    public function testUseOfAnUnknownRosterNameRefusesWithoutTouchingAnything(): void
    {
        $chat = $this->chat(agents: [$this->agent('worker')]);

        [$next] = $chat->runCommand('/agent use ghost');

        self::assertNull($next->primaryAgentName());
        self::assertTrue($chat->backend() instanceof EngineBackend);
        $last = $next->history[\count($next->history) - 1];
        self::assertSame(Role::System, $last->role, 'a refusal lands as a notice row, not as a reply');
        self::assertStringContainsString('ghost', $last->content, 'the refusal names what was refused');
    }

    public function testASecondUseReplacesThePreviousPickWholesale(): void
    {
        $chat = $this->chat(registry: [$this->probeTool('keep'), $this->probeTool('drop')], agents: [
            $this->agent('worker', tools: ['keep']),
            $this->agent('sweeper', tools: ['drop']),
        ]);

        [$first] = $chat->runCommand('/agent use worker');
        [$second] = $first->runCommand('/agent use sweeper');

        self::assertSame('sweeper', $second->primaryAgentName());
        $backend = $second->backend();
        self::assertInstanceOf(EngineBackend::class, $backend);
        self::assertSame(['drop'], \array_map(static fn (Tool $t): string => $t->name(), $backend->tools()),
            'the second apply narrows the LAUNCH roster, not the first preset\'s slice');
    }

    public function testTheEchoSessionSaysTheSwitchIsUnavailable(): void
    {
        $chat = $this->chat(backend: new EchoBackend(), agents: [$this->agent('worker')]);

        [$next] = $chat->runCommand('/agent use worker');

        self::assertNull($next->primaryAgentName());
        self::assertSame(
            Lang::t('host.agents.use_unavailable'),
            $next->history[\count($next->history) - 1]->content,
        );
    }

    // --------------------------------------------------------- mid-turn door

    public function testTheChordRefusesMidTurnWithoutTakingARecord(): void
    {
        $chat = $this->chat(inFlight: true, agents: [$this->agent('alpha')]);

        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'a', alt: true));

        self::assertNull($next->primaryAgentName(), 'no record is taken while a turn is reading the launch backend');
        $this->assertNotice($next, Lang::t('host.agents.use_turn_running'),
            'the door says the between-turns law out loud');
        self::assertTrue($next->inFlight, 'the chord never cancels or waits — it refuses');
    }

    // ------------------------------------------------------------- Alt+A

    public function testTheChordWalksTheRosterAndReturnsToTheDefault(): void
    {
        $chat = $this->chat(agents: [$this->agent('bravo'), $this->agent('alpha')]);
        $press = new KeyMsg(KeyType::Char, 'a', alt: true);

        [$one] = $chat->update($press);
        self::assertSame('alpha', $one->primaryAgentName(), 'the offer walks by name, not by registration order');
        $this->assertNotice($one, Lang::t('host.agents.use_switched', ['name' => 'alpha']));

        [$two] = $one->update($press);
        self::assertSame('bravo', $two->primaryAgentName());

        [$back] = $two->update($press);
        self::assertNull($back->primaryAgentName(), 'the cycle returns to the plain default');
        $this->assertNotice($back, Lang::t('host.agents.use_default'));
    }

    public function testTheChordSkipsPresetsThatDeclareModeSubagent(): void
    {
        $ghost = $this->agent('ghost', sourcePath: $this->presetFile('ghost', 'subagent'));
        $seen = $this->agent('seen', sourcePath: $this->presetFile('seen', 'primary'));
        $chat = $this->chat(agents: [$ghost, $seen, $this->agent('builtinless')]);

        $press = new KeyMsg(KeyType::Char, 'a', alt: true);
        [$one] = $chat->update($press);
        [$two] = $one->update($press);
        [$three] = $two->update($press);

        self::assertSame('builtinless', $one->primaryAgentName(), 'a definition without a preset file still presents');
        self::assertSame('seen', $two->primaryAgentName());
        self::assertNull($three->primaryAgentName(), 'three presses walk default→builtinless→seen→default — ghost was never offered');
    }

    public function testTheChordStandsQuietWhenTheRosterIsEmpty(): void
    {
        $chat = $this->chat();

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Char, 'a', alt: true));

        self::assertNull($cmd);
        self::assertNull($next->primaryAgentName());
        self::assertCount(\count($chat->history), $next->history, 'nothing to offer, nothing said');
    }

    // ------------------------------------------------------------ hand-over

    public function testASessionHandOverRevertsTheNarrowedBackend(): void
    {
        $chat = $this->chat(registry: [$this->probeTool('keep'), $this->probeTool('drop')], agents: [
            $this->agent('worker', tools: ['keep']),
        ]);
        [$narrowed] = $chat->runCommand('/agent use worker');

        $mutate = new \ReflectionMethod(Chat::class, 'mutate');
        // The permissionGrants key exists (and is not null) so the hand-over
        // clause short-circuits past the transcript store this fixture has none of.
        $next = $mutate->invoke($narrowed, ['currentSessionId' => 'other-session', 'permissionGrants' => []]);

        self::assertNull($next->primaryAgentName(), 'the pick belongs to the session that picked it');
        $backend = $next->backend();
        self::assertInstanceOf(EngineBackend::class, $backend);
        self::assertSame(['keep', 'drop'], \array_map(static fn (Tool $t): string => $t->name(), $backend->tools()),
            'the next session starts on the launch roster, not the narrowed one');
    }

    // ------------------------------------------------------------- the badge

    public function testTheStatusBadgeNamesThePrimaryAgentOnlyAfterTheSwitch(): void
    {
        $badge = new \ReflectionMethod(\SugarCraft\Code\Renderer::class, 'agentBadge');

        $chat = $this->chat(agents: [$this->agent('worker')]);
        self::assertSame('', $badge->invoke(null, $chat, 120), 'a plain session renders byte-unchanged');

        [$narrowed] = $chat->runCommand('/agent use worker');
        self::assertStringContainsString('worker', $badge->invoke(null, $narrowed, 120));
        self::assertStringContainsString(
            Lang::t('tui.status.agent', ['name' => 'worker']),
            $badge->invoke(null, $narrowed, 120),
        );
    }

    // ------------------------------------------------------------- fixtures

    /**
     * @param list<Tool> $registry
     * @param list<Agent> $agents
     */
    private function chat(
        ?Backend $backend = null,
        array $registry = [],
        array $agents = [],
        bool $inFlight = false,
    ): Chat {
        $manager = new AgentManager(
            new ScriptedProvider([]),
            new SkillRegistry(),
            toolRegistry: $registry,
            toolUniverse: $registry,
        );
        foreach ($agents as $agent) {
            $manager->register($agent);
        }

        return (new Chat(
            // The launch roster is the registry itself, so a revert has a
            // non-empty slice to restore — the same shape a real launch has.
            backend: $backend ?? EngineBackend::new(new ScriptedProvider([]), 'session-model')->withTools($registry),
            agentManager: $manager,
            inFlight: $inFlight,
        ))->withSize(120, 40);
    }

    /**
     * @param list<string> $tools
     */
    private function agent(
        string $name,
        array $tools = [],
        ?string $sourcePath = null,
        PermissionMode $permissionMode = PermissionMode::Default,
    ): Agent {
        return new Agent(
            name: $name,
            description: "the {$name} preset",
            prompt: "You are {$name}.",
            model: 'inherit',
            provider: 'anthropic',
            tools: $tools,
            skillNames: [],
            hooks: [],
            isActive: true,
            permissionMode: $permissionMode,
            sourcePath: $sourcePath,
        );
    }

    /** A preset file on disk declaring one presenting mode, for the cycle filter. */
    private function presetFile(string $name, string $mode): string
    {
        $path = $this->sandbox . '/project/' . $name . '.md';
        \file_put_contents($path, "---\nname: {$name}\nmode: {$mode}\n---\nBody.\n");

        return $path;
    }

    private function assertNotice(Chat $chat, string $text, string $message = 'the switch speaks through one notice row'): void
    {
        $last = $chat->history[\count($chat->history) - 1];
        self::assertSame($text, $last->content, $message);
    }

    private function probeTool(string $name): Tool
    {
        return new class ($name) implements Tool {
            public function __construct(private string $toolName)
            {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'primary-agent-switch probe';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'ok');
            }
        };
    }
}
