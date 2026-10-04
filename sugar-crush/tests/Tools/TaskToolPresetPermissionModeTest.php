<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 4.1-2: a preset's `permissionMode:` NARROWS a delegation, never
 * widens it. The sub-agent runs under the stricter of its preset's mode and
 * the session's: a `plan` preset is denied a write a `default` session would
 * only have asked about, and a `bypass-permissions` preset under a `default`
 * session still asks — with nobody to answer here, the write is refused.
 */
final class TaskToolPresetPermissionModeTest extends TestCase
{
    private string $storeDir;

    protected function setUp(): void
    {
        $this->storeDir = sys_get_temp_dir() . '/sc_task_mode_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storeDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storeDir);
    }

    public function testAPlanPresetIsDeniedAWriteTheDefaultSessionWouldAskAbout(): void
    {
        [$write, $provider] = $this->delegateWrite(PermissionMode::Plan, PermissionMode::Default, approve: true);

        $this->assertSame([], $write->calls, 'the plan preset\'s gate denied the write before anyone was asked');
        $this->assertStringContainsString(
            "Permission mode 'plan' of agent \"reviewer\" does not allow Write",
            self::lastToolResult($provider),
        );
    }

    public function testABypassPresetUnderADefaultSessionIsNeverWidened(): void
    {
        [$write, $provider] = $this->delegateWrite(PermissionMode::BypassPermissions, PermissionMode::Default, approve: false);

        $this->assertSame([], $write->calls, 'the session still asks, and an unanswered ask refuses');
        $this->assertStringNotContainsString('of agent "reviewer"', self::lastToolResult($provider), 'a wider preset adds no gate of its own');
    }

    public function testAStricterPresetNarrowsEvenABypassSession(): void
    {
        [$write] = $this->delegateWrite(PermissionMode::Default, PermissionMode::BypassPermissions, approve: false);

        $this->assertSame([], $write->calls, 'the preset\'s default mode asks, though the session would have run it');
    }

    public function testWhatBothGatesAllowStillRuns(): void
    {
        [$write] = $this->delegateWrite(PermissionMode::Default, PermissionMode::Default, approve: true);

        $this->assertCount(1, $write->calls, 'the one question was answered yes');
    }

    public function testAReadRunsUnderAPlanPreset(): void
    {
        $read = self::probe('Read');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'Read', ['file_path' => 'notes.txt'])]),
            new CompleteResponse(content: 'read it'),
        ]);
        $engine = EngineBackend::new($provider, 'm')
            ->withTools([$read])
            ->withPermissionGate(new PermissionGate(PermissionMode::Default));

        $this->task(self::agent(PermissionMode::Plan, ['Read']), $engine);

        $this->assertCount(1, $read->calls);
    }

    public function testStrictnessOrdersEveryModeExactlyOnce(): void
    {
        $order = PermissionMode::cases();
        usort($order, static fn (PermissionMode $a, PermissionMode $b): int => $a->strictness() <=> $b->strictness());

        $this->assertSame(
            [PermissionMode::BypassPermissions, PermissionMode::Auto, PermissionMode::AcceptEdits, PermissionMode::Default, PermissionMode::Plan, PermissionMode::DontAsk],
            $order,
        );
        $this->assertCount(\count(PermissionMode::cases()), array_unique(array_map(static fn (PermissionMode $m): int => $m->strictness(), $order)), 'no two modes tie');
        $this->assertSame(PermissionMode::Plan, PermissionMode::Default->stricterOf(PermissionMode::Plan));
        $this->assertSame(PermissionMode::Plan, PermissionMode::Plan->stricterOf(PermissionMode::Plan));
        $this->assertTrue(PermissionMode::DontAsk->isStricterThan(PermissionMode::Plan));
        $this->assertFalse(PermissionMode::Auto->isStricterThan(PermissionMode::AcceptEdits));
    }

    /**
     * @return array{0: Tool&object{calls: list<array<string, mixed>>}, 1: ScriptedProvider}
     */
    private function delegateWrite(PermissionMode $preset, PermissionMode $session, bool $approve): array
    {
        $write = self::probe('Write');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'Write', ['file_path' => 'out.txt', 'content' => 'x'])]),
            new CompleteResponse(content: 'done'),
        ]);
        $engine = EngineBackend::new($provider, 'm')
            ->withTools([$write])
            ->withPermissionGate(new PermissionGate($session));
        if ($approve) {
            $engine = $engine->withPermissionApprover(static fn (): bool => true);
        }

        $this->task(self::agent($preset, ['Write']), $engine);

        return [$write, $provider];
    }

    private function task(Agent $agent, EngineBackend $engine): ToolResult
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry());
        $manager->register($agent);

        return (new TaskTool($manager, suspended: new SuspendedDelegations($this->storeDir)))
            ->withEngine($engine)
            ->execute(['description' => 'Review', 'prompt' => 'Review the change', 'agent' => 'reviewer']);
    }

    /**
     * @param list<string> $tools
     */
    private static function agent(PermissionMode $mode, array $tools): Agent
    {
        return new Agent(
            name: 'reviewer',
            description: 'reviews',
            prompt: 'You are reviewer.',
            model: 'm',
            provider: 'test',
            tools: $tools,
            skillNames: [],
            hooks: [],
            isActive: false,
            permissionMode: $mode,
            inheritsModel: true,
        );
    }

    private static function lastToolResult(ScriptedProvider $provider): string
    {
        $last = end($provider->requests);
        self::assertInstanceOf(CompleteRequest::class, $last);
        $tool = array_values(array_filter(
            $last->messages,
            static fn (TypedMessage $message): bool => $message->role() === 'tool',
        ));

        return $tool === [] ? '' : $tool[count($tool) - 1]->content();
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
                return 'probe';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                $this->calls[] = $args;

                return new ToolResult((string) ($args['id'] ?? ''), 'ok');
            }
        };
    }
}
