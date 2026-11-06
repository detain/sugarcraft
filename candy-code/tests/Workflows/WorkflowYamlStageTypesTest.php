<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Workflows\Tasks;
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowTask;

/**
 * C1: the YAML tiers reach the stage shapes the engine already walks.
 * `type: verification`, `type: pipeline` and `type: parallel` parse here,
 * and every task surface takes `timeout:` / `reject:` / the `cache:` stage
 * flag — the builders and engine legs predate this file; what was missing
 * was the parse, so a model-authored plan (fromYamlString) could not spell
 * a verdict-gated stage at all.
 */
final class WorkflowYamlStageTypesTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_types_' . uniqid('', true);
        mkdir($this->tempDir . '/workflows', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($dir);
    }

    private function registry(): WorkflowRegistry
    {
        return new WorkflowRegistry($this->tempDir . '/workflows');
    }

    // ------------------------------------------------------- verification

    public function testAVerificationStageParsesBothSubMapsOntoTheDto(): void
    {
        $workflow = $this->registry()->fromYamlString(
            "name: v\n"
            . "description: verify me\n"
            . "stages:\n"
            . "  - name: check\n"
            . "    type: verification\n"
            . "    task:\n"
            . "      agent: coder\n"
            . "      prompt: write it\n"
            . "      timeout: 60\n"
            . "    verifier:\n"
            . "      agent: reviewer\n"
            . "      prompt: is it shippable?\n"
            . "      reject: '/NOT SHIPPABLE/'\n"
            . "      rejectMinOccurrences: 2\n"
            . "    capture: true\n"
            . "    cache: true\n",
            'verification-yaml',
        );

        $stage = $workflow->stages[0];
        self::assertSame('check', $stage['name']);
        self::assertSame('verification', $stage['type']);
        self::assertTrue($stage['capture'], 'capture rides every stage shape alike');
        self::assertTrue($stage['cache'], 'the C13 opt-in door is parsed here, honored on resume');

        $task = $stage['task'];
        self::assertInstanceOf(WorkflowTask::class, $task);
        self::assertSame('coder', $task->agentType);
        self::assertSame('write it', $task->prompt);
        self::assertSame(60, $task->timeout, 'per-task timeout reaches the DTO the engine already reads');

        $verifier = $stage['verifier'];
        self::assertInstanceOf(WorkflowTask::class, $verifier);
        self::assertSame('reviewer', $verifier->agentType);
        self::assertSame('/NOT SHIPPABLE/', $verifier->reject);
        self::assertSame(2, $verifier->rejectMinOccurrences);
    }

    public function testTheVerifierDefaultsItsRejectThresholdToOne(): void
    {
        $workflow = $this->registry()->fromYamlString(
            "name: v\n"
            . "description: bare\n"
            . "stages:\n"
            . "  - name: check\n"
            . "    type: verification\n"
            . "    task:\n"
            . "      prompt: do it\n"
            . "    verifier:\n"
            . "      prompt: judge it\n"
            . "      reject: '/NOPE/'\n",
            'verification-defaults',
        );

        $verifier = $workflow->stages[0]['verifier'];
        self::assertInstanceOf(WorkflowTask::class, $verifier);
        self::assertSame('coder', $verifier->agentType, 'agent: coder is the default on every task surface');
        self::assertSame(1, $verifier->rejectMinOccurrences);
        self::assertNull($verifier->timeout, 'an absent timeout stays the stage budget, not a zero');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function incompleteVerificationProvider(): array
    {
        return [
            'no verifier' => [
                "  - name: check\n"
                . "    type: verification\n"
                . "    task:\n"
                . "      prompt: do it\n",
                'verifier',
            ],
            'no task' => [
                "  - name: check\n"
                . "    type: verification\n"
                . "    verifier:\n"
                . "      prompt: judge it\n",
                'task',
            ],
            'verifier not a map' => [
                "  - name: check\n"
                . "    type: verification\n"
                . "    task:\n"
                . "      prompt: do it\n"
                . "    verifier: wise\n",
                'map',
            ],
        ];
    }

    /**
     * @dataProvider incompleteVerificationProvider
     */
    public function testAVerificationStageMissingOrMalformingASubMapIsRefused(string $stageYaml, string $needle): void
    {
        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($needle, '/') . '/');

        $this->registry()->fromYamlString(
            "name: v\ndescription: d\nstages:\n" . $stageYaml,
            'verification-refusals',
        );
    }

    // ------------------------------------------------------------- pipeline

    public function testAPipelineStageParsesNestedStepsAndKeepsTheirNames(): void
    {
        $workflow = $this->registry()->fromYamlString(
            "name: p\n"
            . "description: chain me\n"
            . "stages:\n"
            . "  - name: relay\n"
            . "    type: pipeline\n"
            . "    stages:\n"
            . "      - name: draft\n"
            . "        agent: coder\n"
            . "        prompt: draft it\n"
            . "        timeout: 30\n"
            . "      - agent: explorer\n"
            . "        prompt: polish {{prevResult}}\n",
            'pipeline-yaml',
        );

        $stage = $workflow->stages[0];
        self::assertSame('pipeline', $stage['type']);
        self::assertCount(2, $stage['stages']);

        $first = $stage['stages'][0];
        self::assertSame('draft', $first['name'], 'the step name drives the {{draft.output}} reference');
        self::assertSame('stage', $first['type']);
        $draftTask = $first['tasks'][0];
        self::assertInstanceOf(WorkflowTask::class, $draftTask);
        self::assertSame(30, $draftTask->timeout);

        $second = $stage['stages'][1];
        self::assertSame('explorer', $second['name'], 'an unnamed step falls back to its agent, like the builder does');
        self::assertInstanceOf(WorkflowTask::class, $second['tasks'][0]);
    }

    public function testAPipelineStageWithoutStepsIsRefused(): void
    {
        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessageMatches('/stages/');

        $this->registry()->fromYamlString(
            "name: p\ndescription: d\nstages:\n"
            . "  - name: relay\n"
            . "    type: pipeline\n",
            'pipeline-empty',
        );
    }

    // -------------------------------------------------------------- parallel

    public function testTheParallelTypeSpellingIsEquivalentToTheFlag(): void
    {
        $workflow = $this->registry()->fromYamlString(
            "name: q\ndescription: d\nstages:\n"
            . "  - name: fan\n"
            . "    type: parallel\n"
            . "    agents:\n"
            . "      - type: explorer\n"
            . "        prompt: one\n"
            . "      - type: reviewer\n"
            . "        prompt: two\n"
            . "        timeout: 45\n",
            'parallel-type',
        );

        $stage = $workflow->stages[0];
        self::assertSame('parallel', $stage['type']);
        self::assertCount(2, $stage['tasks']);
        self::assertSame('explorer', $stage['tasks'][0]->agentType);
        self::assertSame(45, $stage['tasks'][1]->timeout);
    }

    public function testDeclaringBothParallelSpellingsIsRefused(): void
    {
        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessageMatches('/parallel/');

        $this->registry()->fromYamlString(
            "name: q\ndescription: d\nstages:\n"
            . "  - name: fan\n"
            . "    type: parallel\n"
            . "    parallel: true\n"
            . "    agents:\n"
            . "      - prompt: one\n",
            'parallel-both',
        );
    }

    public function testAParallelTypeStageListingTasksTakesTheExistingRefusal(): void
    {
        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessageMatches('/agents/');

        $this->registry()->fromYamlString(
            "name: q\ndescription: d\nstages:\n"
            . "  - name: fan\n"
            . "    type: parallel\n"
            . "    tasks:\n"
            . "      - prompt: one\n",
            'parallel-tasks',
        );
    }

    // ---------------------------------------------------------- unknown type

    public function testAnUnknownStageTypeIsRefusedAndNamesEverySupportedSpelling(): void
    {
        try {
            $this->registry()->fromYamlString(
                "name: r\ndescription: d\nstages:\n"
                . "  - name: odd\n"
                . "    type: marmalade\n"
                . "    prompt: do it\n",
                'unknown-type',
            );
            self::fail('a stage type the engine cannot walk must not load');
        } catch (WorkflowLoadException $refusal) {
            $message = $refusal->getMessage();
            foreach (['marmalade', 'stage', 'parallel', 'pipeline', 'verification'] as $needle) {
                self::assertStringContainsString($needle, $message, "the refusal names {$needle}");
            }
        }
    }

    public function testTheStageTypeIsCaseSensitiveAndTrimlessLikeEveryOtherSpelling(): void
    {
        $this->expectException(WorkflowLoadException::class);

        $this->registry()->fromYamlString(
            "name: r\ndescription: d\nstages:\n"
            . "  - name: odd\n"
            . "    type: Verification\n"
            . "    prompt: do it\n",
            'type-case',
        );
    }

    // ------------------------------------------------- timeout arms elsewhere

    public function testTimeoutRidesTheTaskListAndTheSingleTaskTail(): void
    {
        $listed = $this->registry()->fromYamlString(
            "name: t\ndescription: d\nstages:\n"
            . "  - name: seq\n"
            . "    tasks:\n"
            . "      - agent: coder\n"
            . "        prompt: one\n"
            . "        timeout: 12\n",
            'timeout-tasks',
        );
        self::assertSame(12, $listed->stages[0]['tasks'][0]->timeout);

        $tail = $this->registry()->fromYamlString(
            "name: t2\ndescription: d\nstages:\n"
            . "  - name: solo\n"
            . "    agent: coder\n"
            . "    prompt: one\n"
            . "    timeout: '7'\n",
            'timeout-tail',
        );
        self::assertSame(7, $tail->stages[0]['tasks'][0]->timeout, 'the quoted-int law of config.timeout applies');
    }

    public function testAStageTimeoutBesideTasksIsRefusedLikeEveryOtherStageLevelTaskKey(): void
    {
        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessageMatches('/timeout/');

        $this->registry()->fromYamlString(
            "name: t\ndescription: d\nstages:\n"
            . "  - name: seq\n"
            . "    timeout: 5\n"
            . "    tasks:\n"
            . "      - prompt: one\n",
            'timeout-stage-level',
        );
    }

    public function testAZeroTaskTimeoutIsRefused(): void
    {
        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessageMatches('/at least 1/');

        $this->registry()->fromYamlString(
            "name: t\ndescription: d\nstages:\n"
            . "  - name: solo\n"
            . "    prompt: one\n"
            . "    timeout: 0\n",
            'timeout-zero',
        );
    }

    // -------------------------------------------------------------- reject leg

    public function testTheRejectSpellingReachesTheTaskDtoOnTheTail(): void
    {
        $workflow = $this->registry()->fromYamlString(
            "name: j\ndescription: d\nstages:\n"
            . "  - name: solo\n"
            . "    prompt: one\n"
            . "    reject: '/DO NOT SHIP/'\n"
            . "    rejectMinOccurrences: 3\n",
            'reject-tail',
        );

        $task = $workflow->stages[0]['tasks'][0];
        self::assertSame('/DO NOT SHIP/', $task->reject);
        self::assertSame(3, $task->rejectMinOccurrences);
    }

    public function testARejectThresholdWithoutAPatternIsRefused(): void
    {
        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessageMatches('/reject/');

        $this->registry()->fromYamlString(
            "name: j\ndescription: d\nstages:\n"
            . "  - name: solo\n"
            . "    prompt: one\n"
            . "    rejectMinOccurrences: 2\n",
            'reject-threshold-alone',
        );
    }

    // --------------------------------------------------------------- cache leg

    public function testTheCacheFlagFollowsTheCaptureLawExactly(): void
    {
        $on = $this->registry()->fromYamlString(
            "name: c\ndescription: d\nstages:\n"
            . "  - name: solo\n"
            . "    prompt: one\n"
            . "    cache: true\n",
            'cache-on',
        );
        self::assertArrayHasKey('cache', $on->stages[0]);
        self::assertTrue($on->stages[0]['cache']);

        $off = $this->registry()->fromYamlString(
            "name: c2\ndescription: d\nstages:\n"
            . "  - name: solo\n"
            . "    prompt: one\n",
            'cache-absent',
        );
        self::assertArrayNotHasKey('cache', $off->stages[0], 'absent means OFF, byte-identical to pre-C13 plans');

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessageMatches('/"cache" must be a boolean/');

        $this->registry()->fromYamlString(
            "name: c3\ndescription: d\nstages:\n"
            . "  - name: solo\n"
            . "    prompt: one\n"
            . "    cache: 'yes'\n",
            'cache-string',
        );
    }

    // ------------------------------------------------------- builder surface

    public function testTheBuilderCacheParameterMirrorsCapture(): void
    {
        $workflow = (new \SugarCraft\Code\Workflows\WorkflowBuilder())
            ->name('b')
            ->description('d')
            ->stage('one', Tasks::agent('coder')->prompt('p'), cache: true)
            ->parallel('two', [Tasks::agent('coder')->prompt('q')], capture: true, cache: true)
            ->pipeline('three', [Tasks::agent('coder')->prompt('r')], cache: true)
            ->build();

        self::assertTrue($workflow->stages[0]['cache']);
        self::assertArrayNotHasKey('capture', $workflow->stages[0]);
        self::assertTrue($workflow->stages[1]['cache']);
        self::assertTrue($workflow->stages[1]['capture']);
        self::assertTrue($workflow->stages[2]['cache']);

        $verified = (new \SugarCraft\Code\Workflows\WorkflowBuilder())
            ->name('b2')
            ->description('d')
            ->withVerification('v', Tasks::agent('coder')->prompt('p'), Tasks::agent('reviewer')->prompt('judge'), capture: true, cache: true)
            ->build();
        self::assertTrue($verified->stages[0]['capture']);
        self::assertTrue($verified->stages[0]['cache']);
    }
}
