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

    public function testTheBuilderUsesArmStoresTheShapeTheEngineArmsOn(): void
    {
        $workflow = (new \SugarCraft\Code\Workflows\WorkflowBuilder())
            ->name('b3')
            ->description('d')
            ->uses('one', 'inner')
            ->uses('two', 'inner', ['topic' => 'x'], capture: true, cache: true)
            ->build();

        // `uses:` is an arm: the stored type stays 'stage' (the shared list
        // stays the engine's dispatch truth) and the key presence is the arm.
        self::assertSame('stage', $workflow->stages[0]['type']);
        self::assertSame('inner', $workflow->stages[0]['uses']);
        self::assertArrayNotHasKey('with', $workflow->stages[0], 'an empty with: is no key at all');
        self::assertSame(['topic' => 'x'], $workflow->stages[1]['with']);
        self::assertTrue($workflow->stages[1]['capture']);
        self::assertTrue($workflow->stages[1]['cache']);
    }

    // ------------------------------------------------------- C11 uses: / with:

    public function testAUsesStageParsesTheArmAndStringifiesItsWithValues(): void
    {
        $workflow = $this->registry()->fromYamlString(
            "name: u\ndescription: d\nstages:\n"
            . "  - name: wrap\n"
            . "    uses: inner\n"
            . "    with: {topic: deep, count: 3, flag: true}\n"
            . "    capture: true\n"
            . "    cache: true\n",
            'uses-yaml',
        );

        $stage = $workflow->stages[0];
        self::assertSame('stage', $stage['type'], 'the arm never invents a fifth stage type');
        self::assertSame('inner', $stage['uses']);
        self::assertSame(
            ['topic' => 'deep', 'count' => '3', 'flag' => '1'],
            $stage['with'],
            'scalars ride as their string spelling; the inner context is a plain map'
        );
        self::assertTrue($stage['capture']);
        self::assertTrue($stage['cache']);
        self::assertArrayNotHasKey('tasks', $stage);
        self::assertArrayNotHasKey('agent', $stage);
    }

    /**
     * @return array<array{string, string}>
     */
    public static function refusedUsesStageShapes(): array
    {
        return [
            // One body per stage: every container the arm replaces.
            'agent tail' => ["    uses: inner\n    agent: coder\n    prompt: p\n", 'cannot accompany "agent"'],
            'tasks list' => ["    uses: inner\n    tasks:\n      - agent: coder\n        prompt: p\n", 'cannot accompany "tasks"'],
            'verification slot' => ["    uses: inner\n    task:\n      prompt: p\n", 'cannot accompany "task"'],
            'parallel flag' => ["    uses: inner\n    parallel: true\n", 'cannot accompany "parallel: true"'],
            'pipeline type' => ["    uses: inner\n    type: pipeline\n", 'cannot accompany "type: pipeline"'],
            'nested steps' => ["    uses: inner\n    type: pipeline\n    stages:\n      - name: s\n        agent: coder\n        prompt: p\n", 'cannot accompany "stages"'],
            'empty name' => ["    uses: ''\n", 'must name the workflow to include'],
            'non-string name' => ["    uses: {a: b}\n", 'must name the workflow to include, got array'],
            'with scalar' => ["    uses: inner\n    with: 5\n", '"with" must be a map, got int'],
            'with list key' => ["    uses: inner\n    with: [a]\n", '"with" key is not a name'],
            'with nested map' => ["    uses: inner\n    with: {a: {b: c}}\n", 'must be a string or scalar, got array'],
            'with null' => ["    uses: inner\n    with: {a: ~}\n", 'must be a string or scalar, got null'],
        ];
    }

    /**
     * @dataProvider refusedUsesStageShapes
     */
    public function testUsesStageShapesThatBreakOneBodyOrTheArmContractAreRefused(string $stageYaml, string $needle): void
    {
        try {
            $this->registry()->fromYamlString(
                "name: u\ndescription: d\nstages:\n  - name: wrap\n" . $stageYaml,
                'uses-refusal',
            );
            self::fail('expected a WorkflowLoadException naming ' . $needle);
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString($needle, $error->getMessage());
        }
    }

    public function testUsesIsNotAStageTypeSpellingAndWithAloneDoesNotArmASubject(): void
    {
        // C11 keeps `uses:` an arm, so a `type: uses` stage is the unknown-type
        // refusal (which names every real spelling — and does not name 'uses').
        try {
            $this->registry()->fromYamlString(
                "name: u\ndescription: d\nstages:\n  - name: wrap\n    type: uses\n",
                'uses-as-type',
            );
            self::fail('expected the unknown-stage-type refusal');
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString('declares type: uses', $error->getMessage());
            self::assertStringNotContainsString("'uses'", $error->getMessage());
        }

        // `with:` is a stage key the roster now knows, but without `uses:` it
        // arms nothing: the stage still refuses for having no body of its own.
        try {
            $this->registry()->fromYamlString(
                "name: u\ndescription: d\nstages:\n  - name: wrap\n    with: {a: b}\n",
                'with-alone',
            );
            self::fail('expected the missing-body refusal');
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString('stage', strtolower($error->getMessage()));
        }
    }

    public function testWithIsAStageKeyOnlyBesideUsesAndNeverOnTheTaskTail(): void
    {
        // `with:` without `uses:` arms nothing, so the loader refuses it as a
        // dormant knob instead of parsing it into silence.
        try {
            $this->registry()->fromYamlString(
                "name: u\ndescription: d\nstages:\n  - name: wrap\n    agent: coder\n    prompt: p\n    with: {a: b}\n",
                'with-without-uses',
            );
            self::fail('expected the dormant-knob refusal');
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString('only arms a "uses" stage', $error->getMessage());
        }

        // On a task map (a `tasks:` entry) `with` is no key the task roster
        // knows — the refusal names it as unknown to the task side.
        try {
            $this->registry()->fromYamlString(
                "name: u\ndescription: d\nstages:\n  - name: wrap\n    tasks:\n      - agent: coder\n        prompt: p\n        with: {a: b}\n",
                'with-on-task',
            );
            self::fail('expected the unknown-task-key refusal');
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString('"with"', $error->getMessage());
            self::assertStringContainsString('unknown key', $error->getMessage());
        }
    }

    // ------------------------------------------------------- C9 input: fan-in joins

    public function testAPlainStageCarriesAnInputJoinOntoItsTaskDto(): void
    {
        $workflow = $this->registry()->fromYamlString(
            "name: j\ndescription: d\nstages:\n"
            . "  - name: prep\n    agent: coder\n    prompt: warm up\n"
            . "  - name: join\n    agent: coder\n    prompt: merge\n    input: {from: [prep], as: first}\n",
            'join-yaml',
        );

        self::assertSame(
            ['from' => ['prep'], 'as' => 'first'],
            $workflow->stages[1]['tasks'][0]->input,
            'the join spec is normalized onto the task the engine reads',
        );
    }

    public function testInputRidesEveryTaskSurfaceThroughTheOneArmAtParse(): void
    {
        // Tasks-list entries, verification sub-maps and pipeline steps all
        // reach applyTaskArms — one arm, one choke, proven on three surfaces.
        $workflow = $this->registry()->fromYamlString(
            "name: s\ndescription: d\nstages:\n"
            . "  - name: prep\n    agent: coder\n    prompt: warm up\n"
            . "  - name: pair\n    tasks:\n"
            . "      - agent: coder\n        prompt: one\n"
            . "        input: {from: [prep], as: file-list}\n"
            . "  - name: check\n    type: verification\n"
            . "    task: {agent: coder, prompt: do, input: {from: [prep], as: all}}\n"
            . "    verifier: {agent: reviewer, prompt: 'ok?', input: {from: [prep], as: results}}\n",
            'surfaces-yaml',
        );

        self::assertSame(
            ['from' => ['prep'], 'as' => 'file-list'],
            $workflow->stages[1]['tasks'][0]->input,
        );
        self::assertSame(['from' => ['prep'], 'as' => 'all'], $workflow->stages[2]['task']->input);
        self::assertSame(['from' => ['prep'], 'as' => 'results'], $workflow->stages[2]['verifier']->input);
    }

    /**
     * @return array<array{string, string}>
     */
    public static function refusedInputJoins(): array
    {
        return [
            // The join arms plain stages only — carried workflows are their own subject.
            'uses beside input' => [
                "  - name: prep\n    agent: coder\n    prompt: p\n"
                . "  - name: wrap\n    uses: inner\n    input: {from: [prep], as: first}\n",
                'carries "input" beside "uses"',
            ],
            // trustedJoin shape law, wrapped by the load door.
            'input scalar' => [
                "  - name: solo\n    agent: coder\n    prompt: p\n    input: first\n",
                '"input" must be a map carrying "from" and "as", got string',
            ],
            'unknown inner key' => [
                "  - name: solo\n    agent: coder\n    prompt: p\n    input: {from: [x], as: first, bogus: 1}\n",
                'has unknown key "bogus"; supported keys: from, as',
            ],
            'missing as' => [
                "  - name: solo\n    agent: coder\n    prompt: p\n    input: {from: [x]}\n",
                'needs an "as" shape',
            ],
            'missing from' => [
                "  - name: solo\n    agent: coder\n    prompt: p\n    input: {as: first}\n",
                'needs a "from" list',
            ],
            'from not a list' => [
                "  - name: solo\n    agent: coder\n    prompt: p\n    input: {from: x, as: first}\n",
                '"from" must be a list of stage names, got string',
            ],
            'from empty' => [
                "  - name: solo\n    agent: coder\n    prompt: p\n    input: {from: [], as: first}\n",
                '"from" must name at least one stage',
            ],
            'from blank entry' => [
                "  - name: solo\n    agent: coder\n    prompt: p\n    input: {from: [x, ''], as: first}\n",
                'must hold only non-empty stage-name strings',
            ],
            'from duplicate' => [
                "  - name: solo\n    agent: coder\n    prompt: p\n    input: {from: [x, x], as: first}\n",
                'names stage "x" twice',
            ],
            'as outside the vocabulary' => [
                "  - name: solo\n    agent: coder\n    prompt: p\n    input: {from: [x], as: everything}\n",
                'must be one of file-list, first, all, results',
            ],
            'as not a string' => [
                "  - name: solo\n    agent: coder\n    prompt: p\n    input: {from: [x], as: 3}\n",
                '"as" must be one of file-list, first, all, results, got int',
            ],
            // The order law: sources are visible names, so refusal is at parse.
            'forward reference' => [
                "  - name: join\n    agent: coder\n    prompt: p\n    input: {from: [later], as: first}\n"
                . "  - name: later\n    agent: coder\n    prompt: q\n",
                '"later" is not a stage declared before this one',
            ],
            'self reference' => [
                "  - name: join\n    agent: coder\n    prompt: p\n    input: {from: [join], as: first}\n",
                '"join" is not a stage declared before this one',
            ],
            'unknown name' => [
                "  - name: solo\n    agent: coder\n    prompt: p\n    input: {from: [ghost], as: first}\n",
                '"ghost" is not a stage declared before this one',
            ],
            // Placement: a tasks list owns its keys per-task.
            'input beside a tasks list' => [
                "  - name: pair\n    input: {from: [x], as: first}\n    tasks:\n      - agent: coder\n        prompt: p\n",
                '"input" belongs on each task',
            ],
        ];
    }

    /**
     * @dataProvider refusedInputJoins
     */
    public function testInputJoinsThatLieAboutShapeOrderOrPlacementAreRefused(string $stagesYaml, string $needle): void
    {
        try {
            $this->registry()->fromYamlString(
                "name: r\ndescription: d\nstages:\n" . $stagesYaml,
                'input-refusal',
            );
            self::fail('expected a WorkflowLoadException naming ' . $needle);
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString($needle, $error->getMessage());
        }
    }
}
