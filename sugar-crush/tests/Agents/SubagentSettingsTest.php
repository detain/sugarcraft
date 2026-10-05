<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPoolConfig;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\EngineExecutor;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolLimits;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap N-P4f: the sub-agent constants are settings.
 *
 * `subagentMaxTurns` is the step cap for a run whose preset declares none.
 * `subagentMaxConcurrent` is the per-batch `Task` cap and the pool width, fed
 * by `Bootstrap::agentPoolConfig()`. `subagentMaxDepth` and
 * `subagentMaxActive` are the session `Task`'s delegation caps, handed over
 * per turn through `ToolLimits::applyTo()` → `TaskTool::withDelegationLimits()`.
 * All four are Spend, so no project file can raise them, and each default IS
 * the constant it replaced.
 */
final class SubagentSettingsTest extends TestCase
{
    use HomeSandboxTrait;

    private const KEYS = [
        EngineExecutor::MAX_TURNS_SETTINGS_KEY,
        AgentPoolConfig::MAX_CONCURRENT_SETTINGS_KEY,
        ToolLimits::SUBAGENT_MAX_DEPTH_KEY,
        ToolLimits::SUBAGENT_MAX_ACTIVE_KEY,
    ];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/sc_subagent_settings_' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->dir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeTree($this->dir);

        parent::tearDown();
    }

    public function testEveryDefaultIsTheConstantItReplaced(): void
    {
        self::assertSame(EngineExecutor::DEFAULT_MAX_TURNS, TaskTool::DEFAULT_MAX_TURNS, 'one default for both delegation paths');
        self::assertSame(200, EngineExecutor::DEFAULT_MAX_TURNS);
        self::assertSame(AgentPoolConfig::DEFAULT_MAX_CONCURRENT, (new AgentPoolConfig())->maxConcurrent);

        self::assertSame(EngineExecutor::DEFAULT_MAX_TURNS, SettingsSchema::byKey(EngineExecutor::MAX_TURNS_SETTINGS_KEY)?->default);
        self::assertSame(AgentPoolConfig::DEFAULT_MAX_CONCURRENT, SettingsSchema::byKey(AgentPoolConfig::MAX_CONCURRENT_SETTINGS_KEY)?->default);
        self::assertSame(TaskTool::MAX_DELEGATION_DEPTH, SettingsSchema::byKey(ToolLimits::SUBAGENT_MAX_DEPTH_KEY)?->default);
        self::assertSame(TaskTool::MAX_CONCURRENT_AGENTS, SettingsSchema::byKey(ToolLimits::SUBAGENT_MAX_ACTIVE_KEY)?->default);
    }

    public function testEveryKeyIsSpendAndUserTierOnly(): void
    {
        foreach (self::KEYS as $key) {
            $definition = SettingsSchema::byKey($key);
            self::assertNotNull($definition, $key);
            self::assertSame(RiskClass::Spend, $definition->riskClass, $key);
            self::assertTrue($definition->layered, $key);
            self::assertFalse($definition->projectSettable, $key);
            self::assertContains($key, LayeredSettings::userTierOnlyKeys());
        }
    }

    #[DataProvider('maxTurns')]
    public function testTheDefaultStepCapHonoursOnlyAValidSetting(mixed $value, int $expected): void
    {
        self::assertSame($expected, EngineExecutor::defaultMaxTurns([EngineExecutor::MAX_TURNS_SETTINGS_KEY => $value]));
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function maxTurns(): array
    {
        return [
            'set' => [40, 40],
            'one' => [1, 1],
            'integral float' => [12.0, 12],
            'zero' => [0, EngineExecutor::DEFAULT_MAX_TURNS],
            'negative' => [-5, EngineExecutor::DEFAULT_MAX_TURNS],
            'numeric string' => ['40', EngineExecutor::DEFAULT_MAX_TURNS],
            'unset' => [null, EngineExecutor::DEFAULT_MAX_TURNS],
        ];
    }

    /** A workflow-stage run with no `maxTurns` stops at the saved cap, not at 200. */
    public function testAStageRunWithNoPresetCapStopsAtTheSavedOne(): void
    {
        Bootstrap::writeUserConfig([EngineExecutor::MAX_TURNS_SETTINGS_KEY => 2]);
        self::assertSame(2, EngineExecutor::defaultMaxTurns());

        $script = [];
        for ($step = 1; $step <= 6; $step++) {
            $script[] = new CompleteResponse(content: '', toolCalls: [new ToolCall('call_' . $step, 'probe', ['step' => $step])]);
        }
        $executor = new EngineExecutor(EngineBackend::new(new ScriptedProvider($script), 'm')->withTools([self::probe()]));

        $agent = new SubAgent(id: 'stage-cap', agent: RosterAgent::named('coder'), task: 'loop');
        $result = $executor->execute($agent, new CompleteRequest(model: 'm', messages: [['role' => 'user', 'content' => 'loop']]));

        self::assertSame(AgentStatus::Failed, $result->status);
        self::assertStringContainsString('(step cap 2)', (string) $result->error?->getMessage());

        // A preset's own `maxTurns` still wins over the setting.
        $declared = new SubAgent(id: 'stage-declared', agent: RosterAgent::named('coder', maxTurns: 3), task: 'loop');
        $again = (new EngineExecutor(EngineBackend::new(new ScriptedProvider($script), 'm')->withTools([self::probe()])))
            ->execute($declared, new CompleteRequest(model: 'm', messages: [['role' => 'user', 'content' => 'loop']]));
        self::assertStringContainsString('(step cap 3)', (string) $again->error?->getMessage());
    }

    public function testThePoolConfigTakesTheFanOutSetting(): void
    {
        $config = new AgentPoolConfig(workerProvider: ['type' => 'echo']);

        $set = $config->withSettings([AgentPoolConfig::MAX_CONCURRENT_SETTINGS_KEY => 3]);
        self::assertSame(3, $set->maxConcurrent);
        self::assertSame(['type' => 'echo'], $set->workerProvider, 'every other field is kept');

        foreach ([0, 17, '3', null] as $refused) {
            self::assertSame($config, $config->withSettings([AgentPoolConfig::MAX_CONCURRENT_SETTINGS_KEY => $refused]), var_export($refused, true));
        }
    }

    /** The launch's pool — and so the engine's per-batch Task cap — reads it. */
    public function testTheLaunchPoolConfigReadsTheMergedConfig(): void
    {
        $launch = new \ReflectionMethod(Bootstrap::class, 'agentPoolConfig');

        self::assertSame(AgentPoolConfig::DEFAULT_MAX_CONCURRENT, $launch->invoke(null)->maxConcurrent);

        Bootstrap::writeUserConfig([AgentPoolConfig::MAX_CONCURRENT_SETTINGS_KEY => 2]);
        self::assertSame(2, $launch->invoke(null)->maxConcurrent);
    }

    public function testTheSessionTaskTakesTheDelegationCaps(): void
    {
        $task = new TaskTool();

        self::assertSame($task, ToolLimits::fromConfig([])->applyTo($task), 'nothing set: the Task is kept as built');

        $bound = ToolLimits::fromConfig([ToolLimits::SUBAGENT_MAX_DEPTH_KEY => 1, ToolLimits::SUBAGENT_MAX_ACTIVE_KEY => 4])->applyTo($task);
        self::assertSame(1, $this->field($bound, 'maxDelegationDepth'));
        self::assertSame(4, $this->field($bound, 'maxConcurrentAgents'));

        $depthOnly = ToolLimits::fromConfig([ToolLimits::SUBAGENT_MAX_DEPTH_KEY => 2])->applyTo($task);
        self::assertSame(2, $this->field($depthOnly, 'maxDelegationDepth'));
        self::assertSame(TaskTool::MAX_CONCURRENT_AGENTS, $this->field($depthOnly, 'maxConcurrentAgents'));

        foreach ([0, 6, 2.5] as $refused) {
            self::assertSame($task, ToolLimits::fromConfig([ToolLimits::SUBAGENT_MAX_DEPTH_KEY => $refused])->applyTo($task), var_export($refused, true));
        }
    }

    /** A nested Task carries the caps of the run that delegated it; the rebind leaves it alone. */
    public function testANestedTaskKeepsTheCapsItWasHanded(): void
    {
        $nested = (new TaskTool())->nestedFor(1, 'parent-run', static function (): void {
        }, 'scope');

        self::assertSame($nested, ToolLimits::fromConfig([ToolLimits::SUBAGENT_MAX_DEPTH_KEY => 1])->applyTo($nested));
    }

    private static function probe(): Tool
    {
        return new class () implements Tool {
            public function name(): string
            {
                return 'probe';
            }

            public function description(): string
            {
                return 'answers';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'probed');
            }
        };
    }

    private function field(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
