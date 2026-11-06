<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\EngineExecutor;
use SugarCraft\Code\Agents\Isolation;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Agents\WorktreeManager;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Tests\Support\RosterAgent;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\BuiltIn\Read;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Workflows\Tasks;
use SugarCraft\Code\Workflows\Workflow;
use SugarCraft\Code\Workflows\WorkflowBuilder;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;
use SugarCraft\Code\Workflows\WorkflowTask;

/**
 * G2: a stage task that says `isolation: worktree` runs in its OWN git
 * worktree, through the exact machinery a delegated Task run uses (one shared
 * WorktreeIsolation copy — same refusal strings, same kept-work note, same
 * clean-tree-vanishes law). Refuse, never degrade: a stage that cannot get a
 * tree fails with the why before the model is dialed, and the shared checkout
 * is never the fallback. Absent, a stage is byte-identical to before.
 */
final class StageWorktreeIsolationTest extends TestCase
{
    private string $tmpRoot;
    private string $repo;
    private string $trees;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/candy-code-wf-isolation-' . uniqid('', true);
        mkdir($this->tmpRoot, 0755, true);

        // A real git checkout: one tracked file, committed, so "has work" is
        // observable as a dirty tree when a run edits it.
        $this->repo = $this->tmpRoot . '/repo';
        mkdir($this->repo, 0755, true);
        shell_exec('git init -b main ' . escapeshellarg($this->repo) . ' 2>&1');
        shell_exec('git -C ' . escapeshellarg($this->repo) . ' config user.email t@t && git -C ' . escapeshellarg($this->repo) . ' config user.name t 2>&1');
        file_put_contents($this->repo . '/work.txt', "clean\n");
        shell_exec('git -C ' . escapeshellarg($this->repo) . ' add -A && git -C ' . escapeshellarg($this->repo) . " commit -q -m base 2>&1");

        // Trees land OUTSIDE the repo so an isolated run can never dirty the
        // checkout's own porcelain, and each test owns its pool directory.
        $this->trees = $this->tmpRoot . '/trees';
        putenv('CANDY_CODE_WORKTREES_DIR=' . $this->trees);
    }

    protected function tearDown(): void
    {
        putenv('CANDY_CODE_WORKTREES_DIR');
        shell_exec('rm -rf ' . escapeshellarg($this->tmpRoot));
    }

    // ------------------------------------------------------------------ EngineExecutor legs

    public function testACleanIsolatedStageRunsInItsOwnTreeAndVanishesSilently(): void
    {
        /** @var list<string> what the dial could see while the run was live */
        $seen = [];
        $treesDir = $this->trees;
        $repoDir = $this->repo;
        $provider = new ScriptedProvider([
            static function (CompleteRequest $request) use (&$seen, $treesDir, $repoDir): CompleteResponse {
                $seen['trees'] = glob($treesDir . '/*', GLOB_ONLYDIR) ?: [];
                $seen['main'] = rtrim((string) shell_exec('git -C ' . escapeshellarg($repoDir) . ' status --porcelain'));
                return new CompleteResponse(content: 'stage report');
            },
        ]);

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))
            ->execute($this->isolatedAgent('iso-clean-1'), $this->stageWire());

        self::assertSame(AgentStatus::Completed, $result->status, (string) $result->error?->getMessage());
        self::assertSame([$this->trees . '/iso-clean-1'], $seen['trees'], 'the stage dialed with its own tree live');
        self::assertSame('', $seen['main'], 'the shared checkout stayed clean while the stage ran');
        self::assertSame('stage report', $result->output, 'a tree that held no work is removed without a word');
        self::assertSame([], glob($this->trees . '/*', GLOB_ONLYDIR) ?: [], 'the clean tree vanished with its branch');
        self::assertSame(
            '',
            rtrim((string) shell_exec('git -C ' . escapeshellarg($this->repo) . ' status --porcelain')),
            'and the checkout is still untouched after the run',
        );
    }

    public function testWorkKeptInTheTreeIsDisclosedByPathAndBranch(): void
    {
        $treesDir = $this->trees;
        $repoDir = $this->repo;
        $provider = new ScriptedProvider([
            static function (CompleteRequest $request) use ($treesDir, $repoDir): CompleteResponse {
                // The stage's "work": an edit inside its own tree, never the
                // shared checkout.
                file_put_contents($treesDir . '/iso-keep-1/work.txt', "edited\n");
                self::assertSame(
                    '',
                    rtrim((string) shell_exec('git -C ' . escapeshellarg($repoDir) . ' status --porcelain')),
                    'the work landed in the tree, not the checkout',
                );

                return new CompleteResponse(content: 'stage report');
            },
        ]);

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))
            ->execute($this->isolatedAgent('iso-keep-1'), $this->stageWire());

        self::assertSame(AgentStatus::Completed, $result->status, (string) $result->error?->getMessage());
        self::assertIsString($result->output);
        self::assertStringContainsString(
            'worked in its own git worktree; its changes were kept there, not in this checkout:',
            $result->output,
            'the same kept-work note a Task result carries',
        );
        self::assertStringContainsString($this->trees . '/iso-keep-1', $result->output, 'the note names the path');
        self::assertMatchesRegularExpression('/ on branch agent-iso-keep-1-\d+/', $result->output, 'and the branch');
        self::assertTrue(is_dir($this->trees . '/iso-keep-1'), 'the tree is kept, not merged');
        self::assertSame("clean\n", file_get_contents($this->repo . '/work.txt'), 'the shared file was never touched');
    }

    public function testTwoIsolatedStagesGetTheirOwnDistinctTrees(): void
    {
        $treesDir = $this->trees;
        $seen = [];
        $recorder = static function (CompleteRequest $request) use (&$seen, $treesDir): CompleteResponse {
            $seen[] = glob($treesDir . '/*', GLOB_ONLYDIR) ?: [];

            return new CompleteResponse(content: 'ok');
        };

        $executor = new EngineExecutor(EngineBackend::new(new ScriptedProvider([$recorder, $recorder]), 'm'));
        $first = $executor->execute($this->isolatedAgent('iso-a'), $this->stageWire());
        $second = $executor->execute($this->isolatedAgent('iso-b'), $this->stageWire());

        self::assertSame(AgentStatus::Completed, $first->status);
        self::assertSame(AgentStatus::Completed, $second->status);
        self::assertSame([$treesDir . '/iso-a'], $seen[0], 'the first stage saw only its own tree');
        self::assertSame([$treesDir . '/iso-b'], $seen[1], "the second saw its own, not the first's");
    }

    public function testAStageThatCannotIsolateFailsLoudlyAndIsNeverRunInTheCheckout(): void
    {
        $plain = $this->tmpRoot . '/not-a-repo';
        mkdir($plain, 0755, true);
        $provider = new ScriptedProvider([new CompleteResponse(content: 'should never be dialed')]);

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))->execute(
            $this->isolatedAgent('iso-plain', $plain),
            $this->stageWire(),
        );

        self::assertSame(AgentStatus::Failed, $result->status, 'refusal, not degradation');
        self::assertIsString($result->error?->getMessage());
        self::assertStringContainsString('could not be created', $result->error->getMessage());
        self::assertStringContainsString('was not started', $result->error->getMessage());
        self::assertSame([], $provider->requests, 'the model was never dialed for an un-isolatable stage');
    }

    public function testStagesWithoutIsolationNeverTouchTheTreePool(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'stage report')]);
        $agent = new SubAgent(
            id: 'plain-1',
            agent: RosterAgent::named('coder')->withEnvironmentRoot($this->repo),
            task: 'do the thing',
        );

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))->execute($agent, $this->stageWire());

        self::assertSame(AgentStatus::Completed, $result->status);
        self::assertSame('stage report', $result->output, 'byte-identical to the pre-isolation stage text');
        self::assertFileDoesNotExist($this->trees, 'no isolation — no manager, no tree pool, no trace');
    }

    public function testAnInjectedManagerIsUsedInsteadOfBuildingOne(): void
    {
        $treesDir = $this->trees;
        $provider = new ScriptedProvider([
            static function (CompleteRequest $request) use ($treesDir): CompleteResponse {
                file_put_contents($treesDir . '/iso-inj-1/work.txt', "edited\n");

                return new CompleteResponse(content: 'stage report');
            },
        ]);

        $result = (new EngineExecutor(
            EngineBackend::new($provider, 'm'),
            worktreeManager: WorktreeManager::new($this->repo),
        ))->execute($this->isolatedAgent('iso-inj-1'), $this->stageWire());

        self::assertSame(AgentStatus::Completed, $result->status, (string) $result->error?->getMessage());
        self::assertStringContainsString('kept there, not in this checkout:', (string) $result->output);
        self::assertStringContainsString($this->trees . '/iso-inj-1', (string) $result->output);
    }

    public function testTheStageNoteRidesAFailureTheWayItRidesAReport(): void
    {
        $treesDir = $this->trees;
        $provider = new ScriptedProvider([
            static function (CompleteRequest $request) use ($treesDir): CompleteResponse {
                // Work exists, then the run ends without a report: the
                // failure text must still say WHERE the work is.
                file_put_contents($treesDir . '/iso-fail-1/work.txt', "edited\n");

                return new CompleteResponse(content: '   ');
            },
        ]);

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')))
            ->execute($this->isolatedAgent('iso-fail-1'), $this->stageWire());

        self::assertSame(AgentStatus::Failed, $result->status);
        self::assertStringContainsString('ended without a final report', (string) $result->error?->getMessage());
        self::assertStringContainsString('kept there, not in this checkout:', (string) $result->error?->getMessage());
        self::assertStringContainsString($this->trees . '/iso-fail-1', (string) $result->error?->getMessage());
    }

    // ------------------------------------------------------------------ YAML boundary

    public function testTheYamlIsolationKeyLandsOnTheTaskDtoInEveryForm(): void
    {
        $yaml = <<< "YAML"
        name: iso-forms
        description: every spelling of the stage isolation key
        stages:
          - name: single
            agent: coder
            prompt: one
            isolation: worktree
          - name: quiet
            agent: coder
            prompt: two
            isolation: ' none '
          - name: shouted
            agent: coder
            prompt: three
            isolation: WORKTREE
          - name: fanned
            parallel: true
            agents:
              - name: left
                prompt: four
                isolation: worktree
              - name: right
                prompt: five
          - name: listed
            tasks:
              - agent: coder
                prompt: six
                isolation: worktree
        YAML;

        $workflow = (new WorkflowRegistry($this->tmpRoot . '/workflows'))->fromYamlString($yaml, 'iso-forms');

        self::assertSame(Isolation::Worktree, $this->taskOf($workflow, 0, 0)->isolation, 'a stage carries it');
        self::assertSame(Isolation::None, $this->taskOf($workflow, 1, 0)->isolation, 'none is an explicit shared-checkout');
        self::assertSame(Isolation::Worktree, $this->taskOf($workflow, 2, 0)->isolation, 'the spelling is case-insensitive');
        self::assertSame(Isolation::Worktree, $this->taskOf($workflow, 3, 0)->isolation, 'parallel agents carry their own');
        self::assertNull($this->taskOf($workflow, 3, 1)->isolation, 'a sibling without one stays unset; the engine substitutes None at dispatch');
        self::assertSame(Isolation::Worktree, $this->taskOf($workflow, 4, 0)->isolation, 'task-list entries carry their own');
    }

    public function testAnUnknownIsolationSpellingFailsTheLoad(): void
    {
        $cases = [
            'a number is not a spelling' => ['isolation: 7', '/must be a string/'],
            'a list is not a spelling' => ["isolation:\n      - worktree", '/must be a string/'],
            'a mode that does not exist is refused, not guessed' => ['isolation: treesy', '/declares isolation: treesy; supported spellings/'],
            'empty is not none' => ['isolation: ""', '/declares isolation: /'],
        ];

        foreach ($cases as $why => [$line, $shape]) {
            $yaml = "name: closed\n"
                . "description: {$why}\n"
                . "stages:\n"
                . "  - name: work\n"
                . "    agent: coder\n"
                . "    prompt: check\n"
                . "    {$line}\n";

            $failed = false;
            try {
                (new WorkflowRegistry($this->tmpRoot . '/workflows'))->fromYamlString($yaml, 'closed');
            } catch (WorkflowLoadException $refusal) {
                $failed = true;
                self::assertMatchesRegularExpression($shape, $refusal->getMessage(), $why);
            }
            self::assertTrue($failed, $why . ' — an unparseable isolation must never load');
        }
    }

    public function testTheStageTaskCollisionGuardCoversTheIsolationKey(): void
    {
        $yaml = "name: collide\n"
            . "description: the key belongs on each task\n"
            . "stages:\n"
            . "  - name: work\n"
            . "    isolation: worktree\n"
            . "    tasks:\n"
            . "      - agent: coder\n"
            . "        prompt: check\n";

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessageMatches('/"isolation" belongs on each task/');

        (new WorkflowRegistry($this->tmpRoot . '/workflows'))->fromYamlString($yaml, 'collide');
    }

    // ------------------------------------------------------------------ through the real pool

    public function testAnIsolatedStageRunsThroughThePoolInTheSharedMachinery(): void
    {
        $treesDir = $this->trees;
        $provider = new ScriptedProvider([
            static function (CompleteRequest $request) use ($treesDir): CompleteResponse {
                foreach (glob($treesDir . '/*', GLOB_ONLYDIR) ?: [] as $tree) {
                    file_put_contents($tree . '/work.txt', "edited\n");
                }

                return new CompleteResponse(content: 'stage report');
            },
        ]);
        $registry = new WorkflowRegistry($this->tmpRoot . '/workflows');
        $registry->register(
            (new WorkflowBuilder())
                ->name('iso-e2e')
                ->stage('work', [Tasks::agent('coder')->prompt('do the thing')->isolation(Isolation::Worktree)])
                ->build(),
        );
        $engine = new WorkflowEngine(
            $registry,
            new AgentWorkerPool(1, new EngineExecutor(EngineBackend::new($provider, 'm'))),
            environmentRoot: $this->repo,
        );

        $result = $engine->run('iso-e2e');

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        $output = (string) ($result->stageResults[0]->output ?? '');
        self::assertStringContainsString('stage report', $output);
        self::assertStringContainsString('kept there, not in this checkout:', $output, 'the engine path runs the same leave() disclosure');
        self::assertStringContainsString(' on branch agent-work-', $output, 'the stage id names the branch');
        self::assertSame("clean\n", file_get_contents($this->repo . '/work.txt'), 'the pool ran the work in the tree, not the checkout');
    }

    public function testTheIsolatedStageReadsThroughTheJailRootedByItsOwnTree(): void
    {
        // MAJOR-1 (wf-g2g3-r1): the re-jail leg is load-bearing containment —
        // this dial drives a REAL tool call through the engine the isolation
        // wrapper builds, so the Read answers from the worktree, not the
        // process cwd. Remove the withWorktreeRoot call and the planted bytes
        // never reach the tool result: this pin goes red alone.
        //
        // Two hardenings the mutation itself taught: the secret is a RUNTIME
        // random token (a fixed marker leaks — the turn-context block embeds
        // the process repo's live diff, and an edited test file used to carry
        // the literal), and the assert scans ONLY the tool-role answers (the
        // turn context of a jail-rooted run names the tree too; only the Read
        // RESULT proves the tool answered from inside it).
        $treesDir = $this->trees;
        $token = bin2hex(random_bytes(8));
        $provider = new ScriptedProvider([
            static function (CompleteRequest $request) use ($treesDir, $token): CompleteResponse {
                file_put_contents($treesDir . '/iso-jail-1/tree-secret.txt', $token);

                return new CompleteResponse(
                    content: '',
                    toolCalls: [new ToolCall('call_1', 'Read', ['file_path' => 'tree-secret.txt'])],
                );
            },
            new CompleteResponse(content: 'tree read'),
        ]);

        $result = (new EngineExecutor(EngineBackend::new($provider, 'm')->withTools([new Read()])))
            ->execute($this->isolatedAgent('iso-jail-1'), $this->stageWire());

        self::assertSame(AgentStatus::Completed, $result->status, (string) $result->error?->getMessage());

        $answers = '';
        foreach ($provider->requests[1]->messages ?? [] as $message) {
            if (is_array($message) ? ($message['role'] ?? '') === 'tool' : $message->role() === 'tool') {
                $answers .= (string) (is_array($message) ? ($message['content'] ?? '') : $message->content());
            }
        }
        self::assertStringContainsString(
            $token,
            $answers,
            'the jailed Read resolved the relative path inside the stage tree — the re-jail leg carried it',
        );
        self::assertSame("clean\n", file_get_contents($this->repo . '/work.txt'), 'and the shared checkout stayed untouched');
    }

    // ------------------------------------------------------------------ helpers

    private function isolatedAgent(string $id, ?string $root = null): SubAgent
    {
        return new SubAgent(
            id: $id,
            agent: RosterAgent::named('coder')->withEnvironmentRoot($root ?? $this->repo),
            task: 'do the thing',
            timeout: 30,
            isolation: Isolation::Worktree,
        );
    }

    private function stageWire(): CompleteRequest
    {
        return new CompleteRequest(model: 'm', messages: [['role' => 'user', 'content' => 'do the thing']]);
    }

    private function taskOf(Workflow $workflow, int $stageIndex, int $taskIndex): WorkflowTask
    {
        return $workflow->stages[$stageIndex]['tasks'][$taskIndex];
    }
}
