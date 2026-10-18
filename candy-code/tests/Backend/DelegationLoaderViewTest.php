<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\RosterAgent;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\BuiltIn\Read;
use SugarCraft\Code\Tools\BuiltIn\TaskTool;
use SugarCraft\Code\Tools\Tool;

/**
 * ISS-065, the full fix: a delegated run works from a DELEGATION VIEW of the
 * session's instruction loader, not the loader itself, and the view is
 * memoised per session rather than rebuilt per dispatch.
 *
 * Before this, {@see EngineBackend::turnTools()} gave the bound engine its
 * own prompt memo but left the shared loader behind, and TaskTool handed the
 * run the session's own tool instances — every on-touch mark the sub-agent's
 * Read/Glob/Grep made landed in the PARENT's emitted set and suppressed
 * nested-document delivery for the rest of the parent's turn.
 *
 * The pins below walk the whole chain: the bound engine carries the view
 * (never the session loader), repeated dispatches share ONE view object, the
 * view starts as a snapshot of the parent and writes never cross back, the
 * roster re-point keeps loader-less tools untouched, and a loader-less engine
 * behaves exactly as it did before the fix.
 */
final class DelegationLoaderViewTest extends TestCase
{
    public function testTheBoundDelegationEngineCarriesTheViewNotTheSessionLoader(): void
    {
        $engine = $this->gapebSessionEngine();
        $session = $engine->instructionLoader();
        self::assertInstanceOf(InstructionFileLoader::class, $session);

        $bound = $this->gapebBoundEngine($engine);

        self::assertNotSame($session, $bound->instructionLoader(), 'the delegation runs on a view, never the session loader itself');
        self::assertInstanceOf(InstructionFileLoader::class, $bound->instructionLoader());
    }

    public function testRepeatedDispatchesShareOneMemoisedView(): void
    {
        $engine = $this->gapebSessionEngine();

        $first = $this->gapebBoundEngine($engine)->instructionLoader();
        $second = $this->gapebBoundEngine($engine)->instructionLoader();

        self::assertSame($first, $second, 'one view per session and loader instance — dispatches reuse it, they do not rebuild it');
    }

    public function testTheViewSnapshotsTheParentsMarksAndKeepsItsOwnWritesPrivate(): void
    {
        $engine = $this->gapebSessionEngine();
        $session = $engine->instructionLoader();
        self::assertInstanceOf(InstructionFileLoader::class, $session);
        $session->markEmitted(['/docs/issued-before-the-split.md']);

        $view = $this->gapebBoundEngine($engine)->instructionLoader();
        self::assertInstanceOf(InstructionFileLoader::class, $view);
        self::assertContains('/docs/issued-before-the-split.md', $view->emittedPaths(), 'the run sees what a shared loader would have shown it');

        $view->markEmitted(['/docs/touched-by-the-run.md']);

        self::assertNotContains('/docs/touched-by-the-run.md', $session->emittedPaths(), 'the run\'s mark never reaches the parent — the parent keeps its on-touch delivery');
        self::assertContains('/docs/touched-by-the-run.md', $view->emittedPaths());
        self::assertContains('/docs/issued-before-the-split.md', $session->emittedPaths(), 'the mark the parent earned survives');
    }

    public function testTheRosterIsRepointedAtTheViewAndLoaderlessToolsStayUntouched(): void
    {
        $engine = $this->gapebSessionEngine();
        $session = $engine->instructionLoader();
        $bound = $this->gapebBoundEngine($engine);
        $view = $bound->instructionLoader();
        self::assertInstanceOf(InstructionFileLoader::class, $session);
        self::assertInstanceOf(InstructionFileLoader::class, $view);

        $read = new Read(instructionLoader: $session);
        $plain = $this->gapebPlainTool();

        $repoint = new \ReflectionMethod(TaskTool::class, 'repointedAtDelegationLoader');
        /** @var list<Tool> $repointed */
        $repointed = $repoint->invoke(null, [$read, $plain], $bound);

        self::assertNotSame($read, $repointed[0], 'the loader-carrying tool is copied');
        self::assertSame($view, (new \ReflectionProperty(Read::class, 'instructionLoader'))->getValue($repointed[0]), 'and its copy answers to the view');
        self::assertSame($plain, $repointed[1], 'a loader-less tool is returned as it was');
        self::assertSame($session, (new \ReflectionProperty(Read::class, 'instructionLoader'))->getValue($read), 'the session\'s own instance keeps the session loader');

        /** @var list<Tool> $untouched */
        $untouched = $repoint->invoke(null, [$read, $plain], EngineBackend::new(new ScriptedProvider([]), 'm'));
        self::assertSame([$read, $plain], $untouched, 'an engine without a loader leaves the roster by itself');
    }

    public function testAnEngineWithoutALoaderBindsNoView(): void
    {
        $engine = EngineBackend::new(new ScriptedProvider([]), 'm')
            ->withSessionId('S1')
            ->withTools([new Read(), new TaskTool($this->gapebRoster())]);

        $bound = $this->gapebBoundEngine($engine);

        self::assertNull($bound->instructionLoader(), 'no session loader, no view — standalone behaviour is byte-identical to before the fix');
    }

    public function testTheWiringIsPinnedInBothFiles(): void
    {
        // Source pins (house pattern): the bound-engine mutate carries the
        // view key in both arms, and the delegation roster passes through the
        // re-point helper exactly once.
        $engine = file_get_contents(__DIR__ . '/../../src/Backend/EngineBackend.php');
        self::assertNotFalse($engine);
        self::assertSame(2, substr_count($engine, "'instructionLoader' => \$delegationLoader"), 'both $bound arms bind the view');
        self::assertSame(1, substr_count($engine, '$delegationLoader = $this->instructionDelegationView();'));

        $task = file_get_contents(__DIR__ . '/../../src/Tools/BuiltIn/TaskTool.php');
        self::assertNotFalse($task);
        self::assertSame(1, substr_count($task, '$tools = self::repointedAtDelegationLoader($tools, $engine);'), 'the roster is re-pointed on exactly one path');
    }

    // =========================================================================

    private function gapebSessionEngine(): EngineBackend
    {
        return EngineBackend::new(new ScriptedProvider([]), 'm')
            ->withSessionId('S1')
            ->withInstructionLoader(new InstructionFileLoader('/tmp'))
            ->withTools([new Read(), new TaskTool($this->gapebRoster())]);
    }

    /**
     * @return list<Tool>
     */
    private function gapebTurnTools(EngineBackend $engine): array
    {
        $turnTools = new \ReflectionMethod($engine, 'turnTools');

        /** @var list<Tool> $tools */
        return $turnTools->invoke($engine, null, null, null);
    }

    private function gapebBoundEngine(EngineBackend $engine): EngineBackend
    {
        $task = null;
        foreach ($this->gapebTurnTools($engine) as $tool) {
            if ($tool instanceof TaskTool) {
                $task = $tool;
            }
        }
        self::assertInstanceOf(TaskTool::class, $task);

        $bound = (new \ReflectionProperty(TaskTool::class, 'engine'))->getValue($task);
        self::assertInstanceOf(EngineBackend::class, $bound);

        return $bound;
    }

    private function gapebRoster(): AgentManager
    {
        $tools = [new Read()];
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry(), toolRegistry: $tools, toolUniverse: $tools);
        $manager->register(RosterAgent::named('coder', ['Read'], maxTurns: 2));

        return $manager;
    }

    private function gapebPlainTool(): Tool
    {
        return new class implements Tool {
            public function name(): string
            {
                return 'gapeb_plain';
            }

            public function description(): string
            {
                return 'carries no instruction loader';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): \SugarCraft\Code\Tools\ToolResult
            {
                return new \SugarCraft\Code\Tools\ToolResult(toolCallId: '', content: 'plain');
            }
        };
    }
}
