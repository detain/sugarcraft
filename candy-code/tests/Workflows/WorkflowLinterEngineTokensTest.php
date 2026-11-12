<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Workflows\StageCondition;
use SugarCraft\Code\Workflows\Workflow;
use SugarCraft\Code\Workflows\WorkflowLinter;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowTask;

/**
 * The linter knows the tokens the ENGINE injects, in the scopes where they
 * resolve — `{{input}}`, `{{prevResult}}`, `{{round}}`, `{{previous}}`,
 * `{{seen}}`, `{{fresh}}`, `{{path}}`, `{{item}}`, `{{index}}` — so they
 * never warn there and still warn everywhere else; plus the P1-1/P1-5
 * mirrors for hand-built plans the loader never saw.
 */
final class WorkflowLinterEngineTokensTest extends TestCase
{
    public function testEngineTokensResolveInTheirScopes(): void
    {
        $workflow = (new WorkflowRegistry(sys_get_temp_dir()))->fromYamlString(<<<'YAML'
        name: tokens
        stages:
          - name: find
            prompt: 'round {{round}} after {{previous}} skip {{seen}} fresh {{fresh}}'
            outputSchema: {type: object}
            loop:
              max: 3
              accumulate: {pointer: /f, key: /id}
              until: {dry: 1}
          - name: pair
            tasks:
              - {name: one, prompt: first}
              - {name: two, prompt: 'second {{prevResult}}'}
          - name: verify
            parallel: true
            prompt: 'check {{item}} #{{index}} {{item.claim}} {{item.output}}'
            from: {stage: find, pointer: /items, key: /id}
          - name: files
            parallel: true
            prompt: 'read {{path}}'
            from: {glob: '*.md'}
          - name: join
            prompt: 'all {{input}} and {{verify.output}} and {{files.output}}'
            input: {from: [pair], as: all}
          - name: gate
            type: verification
            task: {prompt: do}
            verifier: {prompt: 'judge {{prevResult}}'}
        YAML);

        $report = WorkflowLinter::lint($workflow);

        self::assertFalse($report->hasFindings(), $report->renderFindings());
    }

    public function testEngineTokensStillWarnOutOfScope(): void
    {
        $workflow = new Workflow('stray', 'out of scope', [
            ['name' => 'plain', 'type' => 'stage', 'tasks' => [
                new WorkflowTask('coder', '{{round}} {{previous}} {{seen}} {{item}} {{index}} {{path}} {{input}} {{prevResult}}'),
            ]],
        ]);

        $report = WorkflowLinter::lint($workflow);

        self::assertSame(8, $report->warningCount(), $report->renderFindings());
        self::assertSame(0, $report->errorCount());
    }

    public function testADryLeafWithoutAccumulateIsAnError(): void
    {
        $workflow = new Workflow('dry', 'hand-built', [
            ['name' => 'find', 'type' => 'stage', 'tasks' => [new WorkflowTask('coder', 'p')],
                'loop' => ['max' => 3, 'until' => StageCondition::parse(['dry' => 1], allowLoopLeaves: true)]],
        ]);

        $report = WorkflowLinter::lint($workflow);

        self::assertStringContainsString('until {dry: 1} counts rounds that add nothing new to loop.accumulate', $report->renderFindings());
        self::assertTrue($report->hasErrors());
    }

    public function testADryLeafThatMaxPreemptsWarns(): void
    {
        $workflow = new Workflow('dry', 'hand-built', [
            ['name' => 'find', 'type' => 'stage', 'tasks' => [new WorkflowTask('coder', 'p', outputSchema: ['type' => 'object'])],
                'loop' => ['max' => 2, 'accumulate' => ['pointer' => '/f'], 'until' => StageCondition::parse(['dry' => 2], allowLoopLeaves: true)]],
        ]);

        $report = WorkflowLinter::lint($workflow);

        self::assertSame(0, $report->errorCount(), $report->renderFindings());
        self::assertStringContainsString('max ends the loop first', $report->renderFindings());
    }

    public function testALoopOnAPipelineIsAnError(): void
    {
        $workflow = new Workflow('pipe', 'hand-built', [
            ['name' => 'chain', 'type' => 'pipeline', 'stages' => [['name' => 's', 'type' => 'stage', 'tasks' => [new WorkflowTask('coder', 'p')]]],
                'loop' => ['max' => 2, 'until' => StageCondition::parse(['stage' => 'chain', 'contains' => 'x'])]],
        ]);

        self::assertStringContainsString("loop on a 'pipeline' stage is never honoured", WorkflowLinter::lint($workflow)->renderFindings());
    }

    public function testAFanOutFromALaterStageIsAnError(): void
    {
        $workflow = new Workflow('order', 'hand-built', [
            ['name' => 'verify', 'type' => 'parallel', 'tasks' => [new WorkflowTask('coder', 'check {{item}}')],
                'from' => ['stage' => 'find', 'pointer' => '/f', 'chunk' => null]],
            ['name' => 'find', 'type' => 'stage', 'tasks' => [new WorkflowTask('coder', 'p')]],
        ]);

        self::assertStringContainsString('from.stage "find" is not a stage declared before this one', WorkflowLinter::lint($workflow)->renderFindings());
    }
}
