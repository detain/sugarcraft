<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Code\Cli\ParsedArgs;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * C3 (RS-AGENT-BACKLOG): the `--agent <name>` launch preset.
 *
 * The flag registers a name and NOTHING else — the judgement is the same
 * switch `/agent use <name>` and Alt+A already travel (Chat's
 * `switchPrimaryAgent`), so this file pins the three doors the flag has
 * (parse, register, apply) and refuses the shapes that would silently drop
 * the narrowing (a one-shot run has no Chat to narrow; a subcommand verb
 * runs its own flow).
 */
final class AgentLaunchPresetTest extends TestCase
{
    protected function setUp(): void
    {
        // Statics are process-wide: clear what we set, both ends.
        Bootstrap::useAgentPreset(null);
    }

    protected function tearDown(): void
    {
        Bootstrap::useAgentPreset(null);
    }

    // ------------------------------------------------------------- the parse

    public function testTheSpaceFormStoresTheNameRaw(): void
    {
        $args = ArgvParser::parse(['candy-code', '--agent', 'worker']);

        self::assertSame('worker', $args->agent, 'the parser stores, never resolves — the roster is read at launch');
        self::assertNull($args->usageError);
    }

    public function testTheEqualsFormStoresTheNameRaw(): void
    {
        $args = ArgvParser::parse(['candy-code', '--agent=worker']);

        self::assertSame('worker', $args->agent);
        self::assertNull($args->usageError);
    }

    public function testTheEqualsFormCarriesADashLeadingName(): void
    {
        $args = ArgvParser::parse(['candy-code', '--agent=-odd']);

        self::assertSame('-odd', $args->agent, 'the =-form is the escape hatch for a name that begins with "-"');
    }

    public function testTheFlagIsUntouchedByDefault(): void
    {
        $args = ArgvParser::parse(['candy-code']);

        self::assertNull($args->agent);
    }

    // ------------------------------------------------------- strict refusals

    public function testAMissingValueRefuses(): void
    {
        $args = ArgvParser::parse(['candy-code', '--agent']);

        $this->assertUsageRefusal($args, Lang::t('cli.argv.agent.missing'));
    }

    public function testAFlagShapedValueRefusesAndNamesTheOption(): void
    {
        $args = ArgvParser::parse(['candy-code', '--agent', '--model', 'x']);

        $this->assertUsageRefusal($args, Lang::t('cli.argv.agent.flag', ['option' => '--model']));
    }

    public function testAnEmptyValueRefusesBothForms(): void
    {
        $equals = ArgvParser::parse(['candy-code', '--agent=']);
        $this->assertUsageRefusal($equals, Lang::t('cli.argv.agent.empty'));

        $spaced = ArgvParser::parse(['candy-code', '--agent', '']);
        $this->assertUsageRefusal($spaced, Lang::t('cli.argv.agent.empty'));
    }

    // ------------------------------------------------------------ refusal doors

    public function testTheFlagBesideAOneShotPromptRefuses(): void
    {
        $args = ArgvParser::parse(['candy-code', '--agent', 'worker', '-p', 'audit this']);

        // The one-shot path never builds a Chat, so the narrowing would die
        // silently — the refusal is the honest shape.
        $this->assertUsageRefusal($args, Lang::t('cli.argv.agent.with_prompt'));
    }

    public function testTheFlagBesideASubcommandVerbRefuses(): void
    {
        $args = ArgvParser::parse(['candy-code', '--agent', 'worker', 'session']);

        $this->assertUsageRefusal($args, Lang::t('cli.argv.agent.with_subcommand'));
    }

    // ------------------------------------------------------- registration legs

    public function testTheDelegateWritesTheShelfTheLaunchReads(): void
    {
        Bootstrap::useAgentPreset('worker');

        self::assertSame('worker', LaunchContext::agentPresetOverride(), 'the delegate writes the one shelf the launch tail reads');
    }

    public function testTheShelfIsClearWhatYouSet(): void
    {
        Bootstrap::useAgentPreset('worker');
        Bootstrap::useAgentPreset(null);
        self::assertNull(LaunchContext::agentPresetOverride(), 'null clears — the registration law every sibling override follows');

        Bootstrap::useAgentPreset('worker');
        Bootstrap::useAgentPreset('');
        self::assertNull(LaunchContext::agentPresetOverride(), 'an empty spelling clears too, like useModel()');
    }

    public function testTheCompletionTableCarriesTheFlagRow(): void
    {
        $options = (new \ReflectionClass(Subcommands::class))->getConstant('OPTIONS');
        self::assertIsArray($options);
        self::assertArrayHasKey('--agent', $options);
        self::assertSame('text', $options['--agent']['value'], 'the name is a value, not a switch');
        self::assertSame('cli.completion.option.agent', $options['--agent']['desc']);
        self::assertNotSame(
            'cli.completion.option.agent',
            Lang::t($options['--agent']['desc']),
            'the completion description resolves in every locale (parity guard proves the twelve)',
        );
    }

    public function testTheBinaryRegistersBeforeTheSessionLaunchFamily(): void
    {
        $bin = (string) \file_get_contents(\dirname(__DIR__, 2) . '/bin/candy-code');

        $at = \strpos($bin, 'Bootstrap::useAgentPreset($args->agent);');
        self::assertIsInt($at, 'the flag must reach the shelf or the launch never reads it');
        self::assertGreaterThan(
            (int) \strpos($bin, 'Bootstrap::useMaxTurns($args->maxTurns);'),
            $at,
            'registered with its value-flag siblings',
        );
        self::assertLessThan(
            (int) \strpos($bin, 'Bootstrap::useSessionLaunch('),
            $at,
            'before the session-launch family (the welder keeps that block whole)',
        );
    }

    // ------------------------------------------------------------------ apply

    public function testTheLaunchSeamTravelsTheOneSwitch(): void
    {
        $chat = $this->chat(agents: [$this->agent('worker', tools: ['keep'])], registry: [$this->launchProbeTool('keep')]);

        $narrowed = $chat->withLaunchPrimaryAgent('worker');

        self::assertSame('worker', $narrowed->primaryAgentName());
        $backend = $narrowed->backend();
        self::assertInstanceOf(EngineBackend::class, $backend);
        $last = $narrowed->history[\count($narrowed->history) - 1];
        self::assertSame(Lang::t('host.agents.use_switched', ['name' => 'worker']), $last->content,
            'the launch confirms in the transcript, the same row the typed command echoes');
    }

    public function testAnUnknownNameRefusesWithoutNarrowing(): void
    {
        $chat = $this->chat(agents: [$this->agent('worker')]);

        $next = $chat->withLaunchPrimaryAgent('ghost');

        self::assertNull($next->primaryAgentName(), 'the default agent keeps the session');
        $last = $next->history[\count($next->history) - 1];
        self::assertStringContainsString('ghost', $last->content, 'the refusal names what was refused');
    }

    public function testTheHookSitsInTheLaunchTailAfterTheNoticesAndBeforeTheLock(): void
    {
        // buildChat is too heavy to drive from a unit, so this structural pin
        // walks the source slice: the override must be read between the
        // launch-notice drain and the session lock, or the confirmation row
        // lands above the warnings it belongs under (or past the lock).
        $src = (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php');
        $start = (int) \strpos($src, '$chat = $chat->withLaunchNotices(self::launchNotices());');
        $end = (int) \strpos($src, '$chat = $chat->withSessionLocking();', $start);
        self::assertGreaterThan($start, $end);

        $slice = \substr($src, $start, $end - $start);
        // Comment lines are stripped first: a commented-out hook still
        // contains its own call text, and a pin that stays green while the
        // wiring sleeps is no pin at all (observed during the mutation round).
        $live = \implode("\n", \array_filter(
            \explode("\n", $slice),
            static fn (string $line): bool => !str_starts_with(ltrim($line), '//'),
        ));
        self::assertStringContainsString('LaunchContext::agentPresetOverride();', $live);
        self::assertStringContainsString('withLaunchPrimaryAgent($agentPreset)', $live);
        self::assertStringContainsString('if ($agentPreset !== null)', $live, 'untouched launches must byte-preserve the no-flag path');
    }

    // --------------------------------------------------------------- fixtures

    /**
     * The same cheap construction PrimaryAgentSwitchTest uses: a Chat with a
     * real AgentManager over a ScriptedProvider-backed EngineBackend.
     *
     * @param list<Agent> $agents
     * @param list<Tool> $registry
     */
    private function chat(array $agents = [], array $registry = []): Chat
    {
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
            backend: EngineBackend::new(new ScriptedProvider([]), 'session-model')->withTools($registry),
            agentManager: $manager,
        ))->withSize(120, 40);
    }

    /** @param list<string> $tools */
    private function agent(string $name, array $tools = []): Agent
    {
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
        );
    }

    private function launchProbeTool(string $name): Tool
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
                return 'agent-launch-preset probe';
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

    private function assertUsageRefusal(ParsedArgs $args, string $message): void
    {
        self::assertNotNull($args->usageError, 'the door must answer at parse time');
        self::assertStringContainsString('candy-code: ' . $message, (string) $args->usageError);
        self::assertNotNull($args->usageHint, 'every refusal carries a way out');
    }
}
