<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\WorkflowCommand;
use SugarCraft\Code\Workflows\WorkflowEngineInterface;
use SugarCraft\Code\Workflows\WorkflowResult;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * C5 (wf-wfa): `/workflow lint <name|file>` through the real command entry.
 *
 * File-path linting builds its registry from nothing but the plan file, so
 * these pins never touch HOME; the by-name leg only proves the cannot-load
 * shape (exit-code 2's slash surface) with a name no tier can own.
 */
final class WorkflowLintCommandTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        $this->dirs = [];
    }

    public function testACleanPlanLintsWithoutFindingsAndWithoutDispatch(): void
    {
        $result = $this->lint($this->writeWfaPlan(<<<'YAML'
            name: wfa-clean
            description: two linked stages
            stages:
              - name: gather
                agent: researcher
                prompt: gather notes
              - name: write
                agent: coder
                prompt: use {{gather.results}} and {{gather.output}}
            YAML));

        $reply = $result->rows[1]->content;
        self::assertStringContainsString('Verdict: clean — no findings', $reply);
        self::assertStringContainsString('gather | stage', $reply, 'the plan table lists the stages before the verdict');
        self::assertFalse($result->rows[1]->error, 'a clean lint is an ordinary reply');
    }

    public function testTheLintFramingLocalisesWhileTheReportContentStaysMachineSide(): void
    {
        $plan = $this->writeWfaPlan(<<<'YAML'
            name: wfa-de
            description: one stage
            stages:
              - name: only
                agent: coder
                prompt: do it
            YAML);

        $reply = \SugarCraft\Code\Lang::inLocale('de', fn (): string => (string) $this->lint($plan)->rows[1]->content);

        self::assertStringContainsString('Lint des Workflows `wfa-de` (keine Agenten gestartet):', $reply);
        self::assertStringContainsString('Urteil: clean — no findings', $reply, 'the verdictLine content stays English machine-side');
        self::assertStringNotContainsString('Verdict:', $reply);
    }

    public function testADanglingResultsRefFailsTheLintWithAnErrorFlag(): void
    {
        $result = $this->lint($this->writeWfaPlan(<<<'YAML'
            name: wfa-holes
            description: dangling reference
            stages:
              - name: sole
                agent: coder
                prompt: reads {{ghost.results}}
            YAML));

        $reply = $result->rows[1]->content;
        self::assertStringContainsString('names no producer', $reply);
        self::assertStringContainsString('1 error(s), 0 warning(s)', $reply);
        self::assertTrue($result->rows[1]->error, 'errors flip the row — the exit-1 shape of the slash surface');
    }

    public function testABareUnknownRefIsAWarningAndStaysUnflagged(): void
    {
        $result = $this->lint($this->writeWfaPlan(<<<'YAML'
            name: wfa-bare
            description: maybe a run-context seed
            stages:
              - name: sole
                agent: coder
                prompt: topic {{subject}}
            YAML));

        $reply = $result->rows[1]->content;
        self::assertStringContainsString('0 error(s), 1 warning(s)', $reply);
        self::assertFalse($result->rows[1]->error, 'only errors fail the lint; the run may still seed the name');
    }

    public function testAMissingFileIsCannotLoad(): void
    {
        $result = $this->lint('/no/such/wfa-plan.yml');

        self::assertStringContainsString('cannot-load', $result->rows[1]->content);
        self::assertTrue($result->rows[1]->error);
    }

    public function testAMalformedPlanIsCannotLoadNotAPartialLint(): void
    {
        $result = $this->lint($this->writeWfaPlan(<<<'YAML'
            name: wfa-broken
            description: stages written as a map
            stages:
              first:
                agent: coder
            YAML));

        self::assertStringContainsString('cannot-load', $result->rows[1]->content);
        self::assertTrue($result->rows[1]->error, 'a plan the engine would refuse to run never reaches the static pass');
    }

    public function testAPhpPathIsRefusedBecauseItWouldExecuteAtLoad(): void
    {
        $result = $this->lint('/tmp/wfa-should-never-load.php');

        self::assertStringContainsString('executes at load', $result->rows[1]->content);
        self::assertTrue($result->rows[1]->error);
    }

    public function testAnAbsentNameIsCannotLoadThroughTheTiers(): void
    {
        $result = $this->lint('wf-wfa-no-such-workflow-anywhere');

        self::assertStringContainsString('cannot-load', $result->rows[1]->content);
        self::assertTrue($result->rows[1]->error);
    }

    public function testAnEmptyTargetAnswersTheLintUsageRow(): void
    {
        $result = $this->lint('');

        self::assertStringContainsString('Usage: /workflow lint <name|file>', $result->rows[1]->content);
    }

    private function lint(string $target): \SugarCraft\Code\Host\Commands\CommandResult
    {
        $engine = new class () implements WorkflowEngineInterface {
            public function run(string $workflowPath, array $context = [], ?CancellationToken $cancellation = null): WorkflowResult
            {
                throw new \LogicException('lint never dispatches a run');
            }

            public function pause(string $workflowId): void
            {
                throw new \LogicException('lint never pauses');
            }

            public function resume(string $workflowId, ?CancellationToken $cancellation = null): WorkflowResult
            {
                throw new \LogicException('lint never resumes');
            }

            public function status(string $workflowId): WorkflowStatus
            {
                throw new \LogicException('lint never polls status');
            }

            public function listWorkflows(): array
            {
                throw new \LogicException('lint never lists');
            }
        };

        return (new WorkflowCommand())->run(
            CommandContext::new(workflowEngine: $engine, root: sys_get_temp_dir()),
            '/workflow lint' . ($target === '' ? '' : ' ' . $target),
        );
    }

    private function writeWfaPlan(string $yaml): string
    {
        $dir = sys_get_temp_dir() . '/wf-wfa-lint-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $this->dirs[] = $dir;
        $path = $dir . '/plan.yml';
        file_put_contents($path, $yaml . "\n");

        return $path;
    }
}
