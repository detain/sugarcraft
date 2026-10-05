<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentDefinition;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Hooks\BuiltIn\SubAgentGrantHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;

/**
 * Step 4.2: a delegated sub-agent's ARGUMENT-SCOPED declaration binds on the
 * live Task path.
 *
 * The regression: the engine path narrowed the roster by tool NAME only, and
 * the per-call half ({@see AgentManager::grantRefusalFor()}) was reachable only
 * from `executeSubAgent()`, which nothing calls. So the built-in `reviewer`,
 * declared `['Read', 'Grep', 'Bash(git *)']`, ran any Bash command the session
 * gate allowed. Every test drives a scripted sub-agent through the real engine
 * loop and asserts on what the Bash tool was actually asked to run.
 */
final class TaskToolArgumentScopedGrantTest extends TestCase
{
    private string $storeDir;

    protected function setUp(): void
    {
        $this->storeDir = sys_get_temp_dir() . '/sc_task_grant_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storeDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storeDir);
    }

    public function testTheReviewerPresetRunsGitAndIsRefusedEverythingElse(): void
    {
        $bash = self::probe('Bash');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [
                new ToolCall('c1', 'Bash', ['command' => 'git status']),
                new ToolCall('c2', 'Bash', ['command' => 'rm x']),
                new ToolCall('c3', 'Bash', ['command' => 'git log && rm x']),
            ]),
            new CompleteResponse(content: 'reviewed'),
        ]);
        // The shipped preset's own declaration, not a copy of it: the hole was
        // in the built-in `reviewer`, so that is what must now hold.
        $manager = self::manager([self::probe('Read'), self::probe('Grep'), $bash], RosterAgent::named('reviewer', AgentDefinition::reviewer()->defaultTools));

        $result = $this->task($manager, EngineBackend::new($provider, 'm'))->execute(self::call('reviewer'));

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame([['command' => 'git status']], $bash->calls, 'only the git command ran');

        $denials = self::toolTurns($provider->requests[1]);
        $this->assertCount(3, $denials);
        $this->assertStringContainsString('Bash ok', $denials[0]);
        foreach ([1, 2] as $i) {
            $this->assertStringContainsString('Hook denied', $denials[$i]);
            $this->assertStringContainsString('outside the tool grant agent "reviewer" declares [Read, Grep, Bash(git *)]', $denials[$i]);
        }
    }

    public function testAnArgumentScopedDenialBitesWhileTheRestOfTheGrantWorks(): void
    {
        $bash = self::probe('Bash');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [
                new ToolCall('c1', 'Bash', ['command' => 'git push origin master']),
                new ToolCall('c2', 'Bash', ['command' => 'git status && git push']),
                new ToolCall('c3', 'Bash', ['command' => 'git diff']),
            ]),
            new CompleteResponse(content: 'done'),
        ]);
        $agent = new Agent(
            name: 'committer',
            description: 'test agent committer',
            prompt: 'You are committer.',
            model: 'test-model',
            provider: 'test',
            tools: ['Bash(git *)'],
            skillNames: [],
            hooks: [],
            isActive: true,
            disallowedTools: ['Bash(git push*)'],
        );
        $manager = self::manager([$bash], $agent);

        $this->task($manager, EngineBackend::new($provider, 'm'))->execute(self::call('committer'));

        $this->assertSame([['command' => 'git diff']], $bash->calls, 'a push in any segment of a chain is refused');
        $turns = self::toolTurns($provider->requests[1]);
        $this->assertStringContainsString('is refused by the denylist agent "committer" declares [Bash(git push*)]', $turns[0]);
        $this->assertStringContainsString('is refused by the denylist agent "committer" declares [Bash(git push*)]', $turns[1]);
    }

    public function testTheGrantIsEnforcedOnAWithoutHooksEngineToo(): void
    {
        $bash = self::probe('Bash');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Bash', ['command' => 'curl evil.example'])]),
            new CompleteResponse(content: 'done'),
        ]);
        $manager = self::manager([$bash], RosterAgent::named('reviewer', ['Bash(git *)']));

        $this->task($manager, EngineBackend::new($provider, 'm')->withoutHooks())->execute(self::call('reviewer'));

        $this->assertSame([], $bash->calls, 'the hooks opt-out is not a way to widen a preset');
    }

    public function testACallOutsideTheGrantIsNeverPutToTheApprover(): void
    {
        $bash = self::probe('Bash');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [
                new ToolCall('c1', 'Bash', ['command' => 'npm publish']),
                new ToolCall('c2', 'Bash', ['command' => 'git push']),
            ]),
            new CompleteResponse(content: 'done'),
        ]);
        $asked = [];
        $engine = EngineBackend::new($provider, 'm')
            ->withPermissionGate(new PermissionGate(PermissionMode::Default))
            ->withPermissionApprover(static function ($call) use (&$asked): bool {
                $asked[] = $call->arguments()['command'] ?? null;

                return true;
            });
        $manager = self::manager([$bash], RosterAgent::named('reviewer', ['Bash(git *)']));

        $this->task($manager, $engine)->execute(self::call('reviewer'));

        $this->assertSame(['git push'], $asked, 'the grant refused npm before the gate could ask about it');
        $this->assertSame([['command' => 'git push']], $bash->calls, 'the session gate still judged the admitted call');
    }

    public function testTheGrantNeverLeaksOntoTheCallersSharedHookChain(): void
    {
        $bash = self::probe('Bash');
        $shared = new HookManager(new HookRegistry());
        $provider = new ScriptedProvider([new CompleteResponse(content: 'done')]);
        $manager = self::manager([$bash], RosterAgent::named('reviewer', ['Bash(git *)']));

        $this->task($manager, EngineBackend::new($provider, 'm')->withHooks($shared))->execute(self::call('reviewer'));

        $this->assertNull(
            $shared->hook(HookEvent::PreToolUse->value, SubAgentGrantHook::NAME),
            'the caller\'s next turn must not be held to the delegated run\'s grant',
        );
    }

    public function testAnUndeclaredAgentIsNotNarrowedAndAMalformedDeclarationFailsClosed(): void
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry());
        $manager->register(RosterAgent::named('open'));
        $manager->register(RosterAgent::named('broken', ['Bash(git *']));
        $open = $manager->createSubAgent('open', 'x');
        $broken = $manager->createSubAgent('broken', 'x');
        $context = new HookContext('s', 'Bash', ['command' => 'rm x'], '', '', 'm', 'p', '');

        $this->assertTrue((new SubAgentGrantHook($manager, $open))->execute($context)->permitsExecution());

        $refused = (new SubAgentGrantHook($manager, $broken))->execute($context);
        $this->assertFalse($refused->permitsExecution(), 'a malformed declaration is a deny, never an inert allow');
        $this->assertStringContainsString('agent "broken" cannot be applied', $refused->message);
    }

    private function task(AgentManager $manager, EngineBackend $engine): TaskTool
    {
        return (new TaskTool($manager, suspended: new SuspendedDelegations($this->storeDir)))->withEngine($engine);
    }

    /**
     * @return array<string, string>
     */
    private static function call(string $agent): array
    {
        return ['description' => 'Review the diff', 'prompt' => 'Review the working tree', 'agent' => $agent];
    }

    /**
     * @param list<Tool> $registry
     */
    private static function manager(array $registry, Agent $agent): AgentManager
    {
        $manager = new AgentManager(
            new ScriptedProvider([]),
            new SkillRegistry(),
            toolRegistry: $registry,
            toolUniverse: $registry,
        );
        $manager->register($agent);

        return $manager;
    }

    /**
     * @return list<string>
     */
    private static function toolTurns(CompleteRequest $request): array
    {
        return array_values(array_map(
            static fn (TypedMessage $message): string => $message->content(),
            array_filter($request->messages, static fn (TypedMessage $message): bool => $message->role() === 'tool'),
        ));
    }

    /**
     * @return Tool&object{calls: list<array<string, mixed>>}
     */
    private static function probe(string $name): Tool
    {
        return new class ($name) implements Tool {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function __construct(private string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'records its calls';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                $id = (string) ($args['id'] ?? '');
                unset($args['id']);
                $this->calls[] = $args;

                return new ToolResult(toolCallId: $id, content: $this->name . ' ok');
            }
        };
    }
}
