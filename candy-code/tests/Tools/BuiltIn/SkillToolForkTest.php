<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\Effort;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillLoader;
use SugarCraft\Code\Messages\Message as TypedMessage;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Context\DelegatedOutputFence;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Context\TurnContextBlock;
use SugarCraft\Code\Tools\BuiltIn\SkillTool;
use SugarCraft\Code\Tools\BuiltIn\TaskTool;

/**
 * C-EXT-2.c: a `context: fork` skill's body is not inlined when the Skill
 * tool carries a fork delegate — {@see SkillTool::execute()} hands the
 * composed task to {@see TaskTool::runForkedSkill()} on the turn's bound
 * Task copy, which {@see EngineBackend::turnTools()} binds each turn. The
 * pins below drive all three edges: the fork branch riding the delegation
 * engine (model, effort, roster agent), the honest degraded inline shape
 * with no delegate, and the turn binding itself.
 *
 * The harness deliberately uses REAL files: the registry row a production
 * tools() build carries may be the Stage-1 manifest shape (name,
 * description and context only), and the fork branch upgrades that row off
 * disk before routing. A hand-built in-memory row with agent/model/effort
 * already set would exercise the other leg.
 */
final class SkillToolForkTest extends TestCase
{
    /** @var list<string> scratch roots, removed leaf-first in tearDown */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $skill = $root . DIRECTORY_SEPARATOR . 'skills' . DIRECTORY_SEPARATOR . 'deep-dive';
            foreach ([
                $skill . DIRECTORY_SEPARATOR . 'references' . DIRECTORY_SEPARATOR . 'notes.md',
                $skill . DIRECTORY_SEPARATOR . 'SKILL.md',
            ] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            foreach ([
                $skill . DIRECTORY_SEPARATOR . 'references',
                $skill,
                $root . DIRECTORY_SEPARATOR . 'skills',
                $root,
            ] as $dir) {
                if (is_dir($dir)) {
                    rmdir($dir);
                }
            }
        }

        $this->roots = [];
    }

    public function testAForkedSkillRunsOnTheBoundTaskThroughTheDelegationEngine(): void
    {
        $registry = $this->manifestRegistry();
        $manager = $this->rosterManager($this->rosterAgent('coder', ['Read']));
        $provider = new ScriptedProvider([new CompleteResponse(content: 'the forked report')]);
        $engine = EngineBackend::new($provider, 'session-model');
        $task = (new TaskTool($manager))->withEngine($engine);

        $result = (new SkillTool($registry))->withForkDelegate($task)
            ->execute(['name' => 'deep-dive', 'args' => 'the parser', 'id' => 'call_9']);

        self::assertFalse($result->isError(), $result->content());
        // The Task success shape rides unchanged — fence plus the finished
        // trailer — proving the fork ran THROUGH runOnEngine, not beside it.
        self::assertTrue(
            str_starts_with($result->content(), DelegatedOutputFence::wrap('the forked report') . "\n\n[sub-agent \"coder\" finished;"),
            $result->content(),
        );
        self::assertCount(1, $provider->requests);
        self::assertSame('session-model', $provider->requests[0]->model, 'a preset that inherits its model keeps the session model when the skill declares none');
        $turns = self::turns($provider->requests[0]);
        self::assertContains(['system', 'You are coder.'], $turns, 'the fork speaks as the roster preset, not as the skill body inlined');
        $taskTurn = $turns[1][1] ?? '';
        self::assertStringContainsString('## Skill: deep-dive', $taskTurn, 'the composed heading rides the task, exactly as the inline shape spells it');
        self::assertStringContainsString('Read the whole tree before changing the parser.', $taskTurn, 'the skill body IS the forked task, arguments substituted first');
    }

    public function testTheDeclaredModelAndEffortRideTheForkedRun(): void
    {
        $registry = $this->manifestRegistry("model: skill-model\neffort: high\n");
        $manager = $this->rosterManager($this->rosterAgent('coder', ['Read']));
        $provider = new ScriptedProvider([new CompleteResponse(content: 'done')]);
        $engine = EngineBackend::new($provider, 'session-model');
        $task = (new TaskTool($manager))->withEngine($engine);

        $result = (new SkillTool($registry))->withForkDelegate($task)
            ->execute(['name' => 'deep-dive', 'id' => 'call_1']);

        self::assertFalse($result->isError(), $result->content());
        self::assertSame('skill-model', $provider->requests[0]->model, 'the skill-declared model wins over the inheriting preset');
        // The scripted provider sends no reasoning effort, so the forwarded
        // effort must be SAID, not silently dropped — and said as the
        // skill's declaration, never as the preset's (C-EXT-2.c wording).
        self::assertStringContainsString(
            'effort: high ignored (skill "deep-dive" declares it, but provider "' . $provider->name() . '"',
            $result->content(),
            $result->content(),
        );
    }

    public function testThePresetEffortOfAnEffortlessSkillStillNamesItsAgent(): void
    {
        // Polarity against the leg above: with no declared effort the
        // wording stays the byte-identical pre-C-EXT-2.c one, so every
        // existing preset-effort pin survives.
        $registry = $this->manifestRegistry();
        $manager = $this->rosterManager($this->rosterAgent('coder', ['Read'], effort: 'high'));
        $provider = new ScriptedProvider([new CompleteResponse(content: 'done')]);
        $task = (new TaskTool($manager))->withEngine(EngineBackend::new($provider, 'm'));

        $result = (new SkillTool($registry))->withForkDelegate($task)
            ->execute(['name' => 'deep-dive', 'id' => 'call_1b']);

        self::assertFalse($result->isError(), $result->content());
        self::assertStringContainsString('effort: high ignored (agent "coder" declares it', $result->content(), $result->content());
    }

    public function testTheDeclaredAgentPresetRunsTheForkWhenTheRosterKnowsIt(): void
    {
        $registry = $this->manifestRegistry("agent: reviewer\n");
        $manager = $this->rosterManager($this->rosterAgent('coder', ['Read']), $this->rosterAgent('reviewer', ['Read']));
        $provider = new ScriptedProvider([new CompleteResponse(content: 'reviewed')]);
        $task = (new TaskTool($manager))->withEngine(EngineBackend::new($provider, 'm'));

        $result = (new SkillTool($registry))->withForkDelegate($task)
            ->execute(['name' => 'deep-dive', 'id' => 'call_2']);

        self::assertFalse($result->isError(), $result->content());
        self::assertStringContainsString('[sub-agent "reviewer"', $result->content(), 'agent: names the roster preset that carries the fork');
        self::assertSame([['system', 'You are reviewer.']], array_slice(self::turns($provider->requests[0]), 0, 1));
    }

    public function testAnUnknownDeclaredAgentRefusesInsteadOfInventingARunner(): void
    {
        $registry = $this->manifestRegistry("agent: ghost-runner\n");
        $manager = $this->rosterManager($this->rosterAgent('coder', ['Read']));
        $provider = new ScriptedProvider([new CompleteResponse(content: 'never reached')]);
        $task = (new TaskTool($manager))->withEngine(EngineBackend::new($provider, 'm'));

        $result = (new SkillTool($registry))->withForkDelegate($task)
            ->execute(['name' => 'deep-dive', 'id' => 'call_3']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('agent "ghost-runner" is not in the session roster', $result->content());
        self::assertSame([], $provider->requests, 'a refused fork never reaches the engine');
    }

    public function testAForkedSkillWithoutABoundDelegateLoadsInlineWithANote(): void
    {
        $registry = $this->manifestRegistry();

        // The launch copy of the tool (never seen by turnTools()) is this shape.
        $result = (new SkillTool($registry))->execute(['name' => 'deep-dive', 'id' => 'call_4']);

        self::assertFalse($result->isError(), 'a forked skill never strands on a missing delegate');
        self::assertStringStartsWith(SkillTool::FORK_INLINE_NOTE . '## Skill: deep-dive', $result->content(), 'the note says plainly that the declared fork degraded to an inline load');
        self::assertStringContainsString('Read the whole tree before changing', $result->content());
    }

    public function testAnAssetReadOfAForkedSkillStaysInlineWhileTheBodyForks(): void
    {
        $registry = $this->manifestRegistry();
        $skillDir = $this->roots[0] . DIRECTORY_SEPARATOR . 'skills' . DIRECTORY_SEPARATOR . 'deep-dive';
        self::assertTrue(mkdir($skillDir . DIRECTORY_SEPARATOR . 'references'));
        file_put_contents($skillDir . DIRECTORY_SEPARATOR . 'references' . DIRECTORY_SEPARATOR . 'notes.md', 'reference notes');

        $manager = $this->rosterManager($this->rosterAgent('coder', ['Read']));
        $provider = new ScriptedProvider([new CompleteResponse(content: 'x')]);
        $task = (new TaskTool($manager))->withEngine(EngineBackend::new($provider, 'm'));
        $tool = (new SkillTool($registry, new SkillLoader()))->withForkDelegate($task);

        $asset = $tool->execute(['name' => 'deep-dive', 'asset' => 'references/notes.md', 'id' => 'call_5']);

        self::assertFalse($asset->isError(), $asset->content());
        self::assertStringContainsString('reference notes', $asset->content());
        self::assertSame([], $provider->requests, 'asset reads are file reads, not executions — no fork is summoned');
    }

    public function testTurnToolsBindsTheForkDelegateToEverySkillToolCopy(): void
    {
        $registry = $this->manifestRegistry();
        $manager = $this->rosterManager($this->rosterAgent('coder', ['Read']));
        $engine = EngineBackend::new(new ScriptedProvider([]), 'm')
            ->withTools([new SkillTool($registry), new TaskTool($manager)]);

        $turn = (new ReflectionMethod($engine, 'turnTools'))->invoke($engine, null, null, null);

        $boundTask = null;
        $boundSkill = null;
        foreach ($turn as $tool) {
            if ($tool instanceof TaskTool) {
                $boundTask = $tool;
            }
            if ($tool instanceof SkillTool) {
                $boundSkill = $tool;
            }
        }

        self::assertInstanceOf(TaskTool::class, $boundTask);
        self::assertInstanceOf(SkillTool::class, $boundSkill);

        $delegate = (new ReflectionProperty(SkillTool::class, 'forkDelegate'))->getValue($boundSkill);
        self::assertInstanceOf(TaskTool::class, $delegate, 'the turn pass binds a fork host');
        self::assertSame($boundTask, $delegate, 'the Skill copy delegates to the SAME bound Task copy, so engine, permission relay, emitter and spend ride one path');

        // The bound Task carries the turn's engine — the shape the fork needs.
        self::assertInstanceOf(EngineBackend::class, (new ReflectionProperty(TaskTool::class, 'engine'))->getValue($delegate));

        // The launch copy the backend holds is NOT mutated by the bind.
        $held = (new ReflectionProperty(EngineBackend::class, 'tools'))->getValue($engine);
        foreach ($held as $tool) {
            if ($tool instanceof SkillTool) {
                self::assertNull(
                    (new ReflectionProperty(SkillTool::class, 'forkDelegate'))->getValue($tool),
                    'the bind is per-turn on copies; the immutability law holds',
                );
            }
        }
    }

    public function testTheLaunchSkillToolCarriesNoForkDelegateUntilBound(): void
    {
        $registry = $this->manifestRegistry();

        self::assertNull((new ReflectionProperty(SkillTool::class, 'forkDelegate'))->getValue(new SkillTool($registry)));

        $bound = (new SkillTool($registry))->withForkDelegate(new TaskTool($this->rosterManager($this->rosterAgent('coder', []))));
        self::assertInstanceOf(TaskTool::class, (new ReflectionProperty(SkillTool::class, 'forkDelegate'))->getValue($bound));
    }

    // ------------------------------------------------------------------

    /**
     * Write a real skills/deep-dive SKILL.md and register the FULL typed
     * row off disk (what Skill::fromFile yields). The manifest leg is
     * exercised by the src upgrade condition itself, which re-reads the
     * file when a row carries no agent/model/effort — mirrored by
     * {@see testTheDeclaredModelAndEffortRideTheForkedRun()}, whose row is
     * registered from the manifest shape.
     *
     * @param string $extra extra frontmatter lines for the file
     */
    private function manifestRegistry(string $extra = ''): SkillRegistry
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ccmp-skf-' . getmypid() . '-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0o700));
        self::assertTrue(mkdir($root . DIRECTORY_SEPARATOR . 'skills'));
        self::assertTrue(mkdir($root . DIRECTORY_SEPARATOR . 'skills' . DIRECTORY_SEPARATOR . 'deep-dive'));
        $this->roots[] = $root;

        $front = "---\nname: deep-dive\ndescription: Deep parser work\ncontext: fork\n" . $extra . "---\n\n";
        $body = 'Read the whole tree before changing ' . SkillTool::ARGUMENTS_PLACEHOLDER . ".\n";
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . 'skills' . DIRECTORY_SEPARATOR . 'deep-dive' . DIRECTORY_SEPARATOR . 'SKILL.md',
            $front . $body,
        );

        $registry = new SkillRegistry();
        $registry->register(['deep-dive' => Skill::fromFile($root . DIRECTORY_SEPARATOR . 'skills' . DIRECTORY_SEPARATOR . 'deep-dive' . DIRECTORY_SEPARATOR . 'SKILL.md')]);

        return $registry;
    }

    private function rosterAgent(string $name, array $tools, ?string $model = null, ?string $effort = null): Agent
    {
        return new Agent(
            name: $name,
            description: 'the ' . $name,
            prompt: 'You are ' . $name . '.',
            model: $model ?? 'test-model',
            provider: 'test',
            tools: $tools,
            skillNames: [],
            hooks: [],
            isActive: true,
            inheritsModel: $model === null,
            effort: Effort::tryFrom((string) $effort),
        );
    }

    private function rosterManager(Agent $first, ?Agent $second = null): AgentManager
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry());
        $manager->register($first);
        if ($second !== null) {
            $manager->register($second);
        }

        return $manager;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function turns(CompleteRequest $request): array
    {
        return array_map(
            static fn (TypedMessage $message): array => [$message->role(), $message->content()],
            \SugarCraft\Code\Context\TurnContextBlock::strip($request->messages),
        );
    }
}
