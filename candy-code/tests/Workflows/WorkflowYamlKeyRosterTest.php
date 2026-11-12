<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowTask;

/**
 * The rider law (wftpl linter NIT-1): a workflow YAML key the parser never
 * reads must fail closed at validation, naming the offending key, instead of
 * riding in silently and quietly doing less. The rosters are consts the parse
 * arms themselves consume — one source for "what a key may be" — and the
 * guard leg below pins that the mutual-exclusion loop reads from the roster
 * rather than a second hand-copied list.
 */
final class WorkflowYamlKeyRosterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy_code_wf_roster_' . uniqid('', true);
        \mkdir($this->tempDir . '/workflows', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testAnUnknownStageKeyNamesItself(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: one\n"
            . "    agent: coder\n"
            . "    prompt: p\n"
            . "    color: teal\n";

        try {
            $this->registry()->fromYamlString($yaml);
            self::fail('an unknown stage key must not load quietly');
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString('unknown key', $error->getMessage());
            self::assertStringContainsString('"color"', $error->getMessage());
            self::assertStringContainsString("stage 'one'", $error->getMessage());
        }
    }

    public function testAnUnknownTaskEntryKeyNamesItself(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: one\n"
            . "    tasks:\n"
            . "      - agent: coder\n"
            . "        prompt: p\n"
            . "        templatize: 3\n";

        try {
            $this->registry()->fromYamlString($yaml);
            self::fail('an unknown task key must not load quietly');
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString('unknown key', $error->getMessage());
            self::assertStringContainsString('"templatize"', $error->getMessage());
        }
    }

    public function testAnUnknownVerificationSubMapKeyNamesItself(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: one\n"
            . "    type: verification\n"
            . "    task:\n"
            . "      agent: coder\n"
            . "      prompt: p\n"
            . "      colour: x\n"
            . "    verifier:\n"
            . "      agent: coder\n"
            . "      prompt: v\n";

        try {
            $this->registry()->fromYamlString($yaml);
            self::fail('an unknown verification sub-map key must not load quietly');
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString('unknown key', $error->getMessage());
            self::assertStringContainsString('"colour"', $error->getMessage());
            self::assertStringContainsString('"task"', $error->getMessage());
        }
    }

    public function testAnUnknownPipelineStepKeyNamesItself(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: one\n"
            . "    type: pipeline\n"
            . "    stages:\n"
            . "      - agent: coder\n"
            . "        prompt: p\n"
            . "        stepTimeout: 1\n";

        try {
            $this->registry()->fromYamlString($yaml);
            self::fail('an unknown pipeline step key must not load quietly');
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString('unknown key', $error->getMessage());
            self::assertStringContainsString('"stepTimeout"', $error->getMessage());
        }
    }

    public function testAParallelAgentMapRefusesTheTaskSpellingOfTheLabel(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: one\n"
            . "    parallel: true\n"
            . "    agents:\n"
            . "      - agent: coder\n"
            . "        prompt: p\n";

        try {
            $this->registry()->fromYamlString($yaml);
            self::fail('a parallel agent map spells its label "type", not "agent"');
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString('unknown key', $error->getMessage());
            self::assertStringContainsString('"agent"', $error->getMessage());
            self::assertStringContainsString('agent #1', $error->getMessage());
        }
    }

    public function testAnUnknownParallelAgentKeyNamesItself(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: one\n"
            . "    parallel: true\n"
            . "    agents:\n"
            . "      - type: coder\n"
            . "        prompt: p\n"
            . "        arf: 1\n";

        try {
            $this->registry()->fromYamlString($yaml);
            self::fail('an unknown parallel agent key must not load quietly');
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString('unknown key', $error->getMessage());
            self::assertStringContainsString('"arf"', $error->getMessage());
        }
    }

    public function testKnownKeysSurviveTheRosterOnEverySurface(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: a\n"
            . "    agent: coder\n"
            . "    prompt: p\n"
            . "    tools:\n"
            . "      - Read\n"
            . "    retries: 1\n"
            . "    isolation: none\n"
            . "    model: some/model\n"
            . "    timeout: 5\n"
            . "    capture: true\n"
            . "    cache: true\n"
            . "  - name: b\n"
            . "    tasks:\n"
            . "      - agent: coder\n"
            . "        name: t1\n"
            . "        prompt: p\n"
            . "        tools:\n"
            . "          - Read\n"
            . "        retries: 1\n"
            . "        isolation: none\n"
            . "        model: some/model\n"
            . "        resource: host\n"
            . "        lockWaitSeconds: 2\n"
            . "        timeout: 6\n"
            . "        stallTimeout: 90\n"
            . "        restarts: 1\n"
            . "  - name: c\n"
            . "    type: verification\n"
            . "    task:\n"
            . "      agent: coder\n"
            . "      prompt: p\n"
            . "    verifier:\n"
            . "      agent: coder\n"
            . "      prompt: v\n"
            . "      reject: '/\\bREJECT\\b/'\n"
            . "      rejectMinOccurrences: 2\n"
            . "  - name: d\n"
            . "    type: pipeline\n"
            . "    stages:\n"
            . "      - name: draft\n"
            . "        agent: coder\n"
            . "        prompt: p\n"
            . "        timeout: 3\n"
            . "      - agent: reviewer\n"
            . "        prompt: r\n"
            . "  - name: e\n"
            . "    uses: author-draft\n"
            . "    with:\n"
            . "      notes: \"{{a.output}}\"\n"
            . "    capture: true\n";

        $workflow = $this->registry()->fromYamlString($yaml);

        $types = \array_map(
            static fn (array $stage): string => (string) $stage['type'],
            $workflow->stages
        );
        self::assertSame(['stage', 'stage', 'verification', 'pipeline', 'stage'], $types);

        $verifier = $workflow->stages[2]['verifier'];
        self::assertInstanceOf(\SugarCraft\Code\Workflows\WorkflowTask::class, $verifier);
        self::assertSame('/\bREJECT\b/', $verifier->reject);
        self::assertSame(2, $verifier->rejectMinOccurrences);

        // C7: the stall pair survives the roster and lands typed on the DTO.
        $taskB = $workflow->stages[1]['tasks'][0];
        self::assertInstanceOf(WorkflowTask::class, $taskB);
        self::assertSame(90, $taskB->stallTimeout);
        self::assertSame(1, $taskB->restarts);

        // The C11 arm's keys survive the roster the same way the compound
        // sections do: a uses stage arrives typed 'stage', carrying its
        // subject name, its with: map, and the flags the arm shares with
        // ordinary stages.
        self::assertSame('author-draft', $workflow->stages[4]['uses']);
        self::assertSame(['notes' => '{{a.output}}'], $workflow->stages[4]['with']);
        self::assertSame(true, $workflow->stages[4]['capture']);

        self::assertSame(['a', 'b', 'c', 'd', 'e'], \array_map(
            static fn (array $stage): string => (string) $stage['name'],
            $workflow->stages
        ));
    }

    public function testTheStageLevelPlacementRefusalSurvivesTheRoster(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: one\n"
            . "    prompt: p\n"
            . "    tasks:\n"
            . "      - agent: coder\n"
            . "        prompt: q\n";

        try {
            $this->registry()->fromYamlString($yaml);
            self::fail('a task key beside "tasks" is still the placement refusal');
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString('lists "tasks"', $error->getMessage());
            self::assertStringContainsString('belongs on each task', $error->getMessage());
        }
    }

    public function testAFanOutStageLoadsItsSubMapAndTemplate(): void
    {
        // C8: the fan-out spelling loads as ONE template task plus the
        // recorded spec. The stage's own label must not leak into the
        // template — the engine names every item by its path.
        $glob = 'src/' . chr(42) . chr(42);
        $workflow = $this->registry()->fromYamlString(
            "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: waves\n"
            . "    parallel: true\n"
            . "    agent: coder\n"
            . "    prompt: fix {{path}}\n"
            . "    from:\n"
            . "      glob: {$glob}\n"
            . "      chunk: 3\n"
        );

        $stage = $workflow->stages[0];
        self::assertSame('parallel', $stage['type']);
        self::assertSame(['glob' => $glob, 'chunk' => 3], $stage['from']);
        self::assertCount(1, $stage['tasks']);
        $template = $stage['tasks'][0];
        self::assertInstanceOf(WorkflowTask::class, $template);
        self::assertNull($template->name);
        self::assertSame('fix {{path}}', $template->prompt);
        self::assertSame('coder', $template->agentType);
    }

    public function testAFanOutStageWithoutAChunkRecordsANullCap(): void
    {
        $glob = 'src/' . chr(42) . chr(42);
        $workflow = $this->registry()->fromYamlString(
            "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: waves\n"
            . "    parallel: true\n"
            . "    agent: coder\n"
            . "    prompt: fix {{path}}\n"
            . "    from:\n"
            . "      glob: {$glob}\n"
        );

        self::assertSame(['glob' => $glob, 'chunk' => null], $workflow->stages[0]['from']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedFanOutShapes(): array
    {
        $body = static fn(string $fromYaml): string => "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: waves\n"
            . "    parallel: true\n"
            . "    agent: coder\n"
            . "    prompt: fix {{path}}\n"
            . $fromYaml;

        return [
            'a scalar from' => [
                $body("    from: now\n"),
                '"from" must be a map',
            ],
            'an unknown sub-map key' => [
                $body("    from:\n      glob: x\n      root: /tmp\n"),
                'has unknown key "root"; supported keys: glob, stage, context, pointer, key, chunk, max',
            ],
            'a missing glob' => [
                $body("    from:\n      chunk: 2\n"),
                'needs exactly one source: a "glob" to expand against the environment root',
            ],
            'a blank glob' => [
                $body("    from:\n      glob: '   '\n"),
                '"glob" must not be empty',
            ],
            'a zero chunk' => [
                $body("    from:\n      glob: x\n      chunk: 0\n"),
                '"chunk" must be at least 1, got 0',
            ],
            'a half chunk' => [
                $body("    from:\n      glob: x\n      chunk: half\n"),
                '"chunk" must be a whole number, got string',
            ],
            // P0-3: `max` is a whole number from 1 to the fan-out ceiling.
            'a zero max' => [
                $body("    from:\n      glob: x\n      max: 0\n"),
                '"max" must be at least 1, got 0',
            ],
            'a textual max' => [
                $body("    from:\n      glob: x\n      max: lots\n"),
                '"max" must be a whole number, got string',
            ],
            'a max above the fan-out ceiling' => [
                $body("    from:\n      glob: x\n      max: 4097\n"),
                '"max" must be at most 4096 (the per-stage fan-out ceiling), got 4097',
            ],
        ];
    }

    public function testAFanOutMaxRidesTheSpecOnlyWhenWritten(): void
    {
        $workflow = $this->registry()->fromYamlString(
            "name: r\n"
            . "stages:\n"
            . "  - name: waves\n"
            . "    parallel: true\n"
            . "    prompt: fix {{path}}\n"
            . "    from:\n"
            . "      glob: 'src/*.php'\n"
            . "      max: \"200\"\n"
        );

        self::assertSame(['glob' => 'src/*.php', 'chunk' => null, 'max' => 200], $workflow->stages[0]['from']);
    }

    /**
     * @dataProvider refusedFanOutShapes
     */
    public function testARefusedFanOutShapeDiesAtLoad(string $yaml, string $needle): void
    {
        try {
            $this->registry()->fromYamlString($yaml);
            self::fail("the fan-out shape '{$needle}' still loaded");
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString($needle, $error->getMessage());
        }
    }

    public function testAFanOutBesideAStaticListIsRefused(): void
    {
        try {
            $this->registry()->fromYamlString(
                "name: r\n"
                . "description: d\n"
                . "stages:\n"
                . "  - name: waves\n"
                . "    parallel: true\n"
                . "    agent: coder\n"
                . "    prompt: fix {{path}}\n"
                . "    from:\n"
                . "      glob: x\n"
                . "    agents:\n"
                . "      - agent: coder\n"
            );
            self::fail('both spellings at once still loaded');
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString('one spelling at a time', $error->getMessage());
        }
    }

    public function testAFanOutOnASerialStageIsRefused(): void
    {
        try {
            $this->registry()->fromYamlString(
                "name: r\n"
                . "description: d\n"
                . "stages:\n"
                . "  - name: solo\n"
                . "    agent: coder\n"
                . "    prompt: fix it\n"
                . "    from:\n"
                . "      glob: x\n"
            );
            self::fail('a serial stage carried a fan-out');
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString('only a parallel stage can fan out', $error->getMessage());
        }
    }

    public function testAFanOutWithoutAPromptIsRefused(): void
    {
        try {
            $this->registry()->fromYamlString(
                "name: r\n"
                . "description: d\n"
                . "stages:\n"
                . "  - name: waves\n"
                . "    parallel: true\n"
                . "    agent: coder\n"
                . "    from:\n"
                . "      glob: x\n"
            );
            self::fail('a fan-out with nothing to run still loaded');
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString('needs a stage-level "prompt"', $error->getMessage());
        }
    }

    public function testTheRosterIsTheSingleSource(): void
    {
        $registry = new ReflectionClass(WorkflowRegistry::class);

        self::assertSame(
            ['agent', 'name', 'prompt', 'tools', 'retries', 'outputSchema',
                'isolation', 'model', 'resource', 'lockWaitSeconds',
                'timeout', 'reject', 'rejectMinOccurrences',
                'stallTimeout', 'restarts', 'input', 'votes', 'lenses', 'quorum'],
            $registry->getConstant('YAML_TASK_KEYS'),
            'the parsed task-key roster is one pinned list'
        );
        self::assertSame(
            ['name', 'parallel', 'capture', 'cache', 'type', 'agents', 'tasks',
                'task', 'verifier', 'stages', 'when', 'uses', 'with', 'from',
                'loop', 'minSuccess'],
            $registry->getConstant('YAML_STAGE_KEYS'),
            'the stage-shape vocabulary is one pinned list'
        );

        // The roster lives on this class; read the file reflection says it was
        // compiled from — never a hand-spelled path that could drift.
        $source = (string) \file_get_contents($registry->getFileName());
        self::assertIsString($source);
        // The mutual-exclusion loop must consume the roster, not re-list it;
        // a second hand-copied 12-key array would let a new arm silently
        // escape both refusals.
        self::assertStringContainsString('array_diff(self::YAML_TASK_KEYS', $source);
        self::assertStringNotContainsString(
            "'agent', 'prompt', 'tools', 'retries'",
            $source
        );
        // The stage type list lives on the VO alone (C1), the key rosters here.
        self::assertStringContainsString('Workflow::STAGE_TYPES', $source);
        self::assertStringNotContainsString(
            "['stage', 'parallel', 'pipeline', 'verification']",
            $source
        );
    }

    // -------------------------------------------------------------------------
    // C2: "when" joined the stage roster — grammar survives, junk refuses
    // -------------------------------------------------------------------------

    public function testWhenClausesSurviveTheRosterOnEveryLegalShape(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: a\n"
            . "    agent: coder\n"
            . "    prompt: p\n"
            . "  - name: b\n"
            . "    agent: coder\n"
            . "    prompt: q\n"
            . "    when:\n"
            . "      stage: a\n"
            . "      contains: GO\n"
            . "  - name: c\n"
            . "    agent: coder\n"
            . "    prompt: r\n"
            . "    when:\n"
            . "      any:\n"
            . "        - stage: a\n"
            . "          equals: x\n"
            . "        - all:\n"
            . "            - stage: b\n"
            . "              matches: '/^ok/'\n";

        $workflow = $this->registry()->fromYamlString($yaml);

        $this->assertArrayNotHasKey('when', $workflow->stages[0], 'no clause means no key');
        $this->assertSame(
            ['stage' => 'a', 'op' => 'contains', 'value' => 'GO'],
            $workflow->stages[1]['when'],
        );
        $this->assertSame(
            ['combinator' => 'any', 'conditions' => [
                ['stage' => 'a', 'op' => 'equals', 'value' => 'x'],
                ['combinator' => 'all', 'conditions' => [
                    ['stage' => 'b', 'op' => 'matches', 'value' => '/^ok/'],
                ]],
            ]],
            $workflow->stages[2]['when'],
            'the stored clause is the PARSED shape, not the author spelling',
        );
    }

    public function testAWhenLeafWithAMadeUpOpRefusesAtLoadNamingTheOffender(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: one\n"
            . "    agent: coder\n"
            . "    prompt: p\n"
            . "    when:\n"
            . "      stage: one\n"
            . "      gt: 3\n";

        try {
            $this->registry()->fromYamlString($yaml);
            $this->fail('the grammar must not admit a made-up op');
        } catch (WorkflowLoadException $error) {
            $this->assertStringContainsString("stage 'one'", $error->getMessage());
            $this->assertStringContainsString('where a condition belongs', $error->getMessage());
        }
    }

    public function testAnEmptyCombinatorListRefusesAtLoad(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: one\n"
            . "    agent: coder\n"
            . "    prompt: p\n"
            . "    when:\n"
            . "      all: []\n";

        try {
            $this->registry()->fromYamlString($yaml);
            $this->fail('an empty all: list is not a condition');
        } catch (WorkflowLoadException $error) {
            $this->assertStringContainsString('non-empty list', $error->getMessage());
        }
    }

    public function testNestingPastTheGrammarCeilingRefusesAtLoad(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: one\n"
            . "    agent: coder\n"
            . "    prompt: p\n"
            . "    when:\n"
            . "      any:\n"
            . "        - all:\n"
            . "            - any:\n"
            . "                - stage: one\n"
            . "                  equals: x\n";

        try {
            $this->registry()->fromYamlString($yaml);
            $this->fail('three combinator layers is past the ceiling');
        } catch (WorkflowLoadException $error) {
            $this->assertStringContainsString('nests combinators 3 deep', $error->getMessage());
        }
    }

    public function testABrokenMatchesPatternRefusesAtLoadLikeRejectDoes(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: one\n"
            . "    agent: coder\n"
            . "    prompt: p\n"
            . "    when:\n"
            . "      stage: one\n"
            . "      matches: '/unclosed('\n";

        try {
            $this->registry()->fromYamlString($yaml);
            $this->fail('a broken PCRE must not ride in to fail at every dispatch');
        } catch (WorkflowLoadException $error) {
            $this->assertStringContainsString('is not a valid PCRE', $error->getMessage());
        }
    }

    public function testANonMapWhenRefusesAtLoad(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: one\n"
            . "    agent: coder\n"
            . "    prompt: p\n"
            . "    when: ready\n";

        try {
            $this->registry()->fromYamlString($yaml);
            $this->fail('a scalar when is not a clause');
        } catch (WorkflowLoadException $error) {
            $this->assertStringContainsString('must be a map', $error->getMessage());
        }
    }

    public function testAWhenAdjacentMisspellingIsStillTheUnknownKeyRefusal(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: one\n"
            . "    agent: coder\n"
            . "    prompt: p\n"
            . "    whenx: 1\n";

        try {
            $this->registry()->fromYamlString($yaml);
            $this->fail('a near-miss key must not load quietly');
        } catch (WorkflowLoadException $error) {
            $this->assertStringContainsString('unknown key', $error->getMessage());
            $this->assertStringContainsString('"whenx"', $error->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // C4: "loop" joined the stage roster — closed pair survives, junk refuses
    // -------------------------------------------------------------------------

    public function testLoopRidesAPlainStageAndStoresTheNormalizedPair(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: a\n"
            . "    agent: coder\n"
            . "    prompt: p\n"
            . "  - name: b\n"
            . "    agent: coder\n"
            . "    prompt: 'attempt {{round}}'\n"
            . "    loop:\n"
            . "      max: 3\n"
            . "      until:\n"
            . "        stage: a\n"
            . "        contains: GO\n";

        $workflow = $this->registry()->fromYamlString($yaml);

        $this->assertArrayNotHasKey('loop', $workflow->stages[0], 'no map means no key');
        $this->assertSame(
            ['max' => 3, 'until' => ['stage' => 'a', 'op' => 'contains', 'value' => 'GO']],
            $workflow->stages[1]['loop'],
            'the stored pair is normalized once, at attach: int max and the PARSED clause',
        );
    }

    public function testAQuotedNumericMaxMeansTheSameRoundCount(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: a\n"
            . "    agent: coder\n"
            . "    prompt: p\n"
            . "  - name: b\n"
            . "    agent: coder\n"
            . "    prompt: q\n"
            . "    loop:\n"
            . "      max: \"4\"\n"
            . "      until:\n"
            . "        stage: a\n"
            . "        equals: GO\n";

        $workflow = $this->registry()->fromYamlString($yaml);

        $this->assertSame(4, $workflow->stages[1]['loop']['max']);
    }

    public function testLoopOnAParallelStageLoads(): void
    {
        // P1-5: loop: is no longer stage-only — parallel stages repeat too.
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: a\n"
            . "    agent: coder\n"
            . "    prompt: p\n"
            . "  - name: b\n"
            . "    parallel: true\n"
            . "    agents:\n"
            . "      - name: left\n"
            . "        prompt: q\n"
            . "    loop:\n"
            . "      max: 2\n"
            . "      until:\n"
            . "        stage: a\n"
            . "        contains: GO\n";

        $workflow = $this->registry()->fromYamlString($yaml);

        $this->assertSame(2, $workflow->stages[1]['loop']['max']);
    }

    public function testLoopOnAVerificationStageLoads(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: a\n"
            . "    agent: coder\n"
            . "    prompt: p\n"
            . "  - name: b\n"
            . "    type: verification\n"
            . "    task:\n"
            . "      agent: coder\n"
            . "      prompt: q\n"
            . "    verifier:\n"
            . "      agent: reviewer\n"
            . "      prompt: check\n"
            . "    loop:\n"
            . "      max: 2\n"
            . "      until:\n"
            . "        stage: a\n"
            . "        contains: GO\n";

        $workflow = $this->registry()->fromYamlString($yaml);

        $this->assertSame(2, $workflow->stages[1]['loop']['max']);
    }

    public function testLoopOnAPipelineStageRefusesAtLoad(): void
    {
        $yaml = "name: r\n"
            . "stages:\n"
            . "  - name: b\n"
            . "    type: pipeline\n"
            . "    stages:\n"
            . "      - agent: coder\n"
            . "        prompt: q\n"
            . "    loop:\n"
            . "      max: 2\n"
            . "      until:\n"
            . "        stage: b\n"
            . "        contains: GO\n";

        try {
            $this->registry()->fromYamlString($yaml);
            $this->fail('a pipeline stage does not loop');
        } catch (WorkflowLoadException $error) {
            $this->assertStringContainsString('"loop" cannot attach to a "pipeline" stage', $error->getMessage());
        }
    }

    public function testLoopBesideUsesRefusesAtLoad(): void
    {
        // C4 rider (NIT-1): a carried stage keeps the plain-stage shape, so the
        // stage-only door alone would admit the pairing. Per-round carrying has
        // semantics no lane specified — the closed-pair door refuses it at load.
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: a\n"
            . "    agent: coder\n"
            . "    prompt: p\n"
            . "  - name: b\n"
            . "    uses: author-draft\n"
            . "    loop:\n"
            . "      max: 2\n"
            . "      until:\n"
            . "        stage: a\n"
            . "        contains: GO\n";

        try {
            $this->registry()->fromYamlString($yaml);
            $this->fail('v1 refuses a loop riding a carried workflow');
        } catch (WorkflowLoadException $error) {
            $this->assertStringContainsString('"loop"', $error->getMessage());
            $this->assertStringContainsString('"uses"', $error->getMessage());
            $this->assertStringContainsString('carried workflows', $error->getMessage());
        }
    }

    public function testLoopRefusesUnknownSubKeys(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: a\n"
            . "    agent: coder\n"
            . "    prompt: p\n"
            . "  - name: b\n"
            . "    agent: coder\n"
            . "    prompt: q\n"
            . "    loop:\n"
            . "      max: 2\n"
            . "      until:\n"
            . "        stage: a\n"
            . "        contains: GO\n"
            . "      every: 5\n";

        try {
            $this->registry()->fromYamlString($yaml);
            $this->fail('the loop grammar is closed; an extra sub-key must refuse');
        } catch (WorkflowLoadException $error) {
            $this->assertStringContainsString('unknown key', $error->getMessage());
            $this->assertStringContainsString('every', $error->getMessage());
        }
    }

    public function testLoopRefusesWhenEitherHalfIsMissing(): void
    {
        $noUntil = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: b\n"
            . "    agent: coder\n"
            . "    prompt: q\n"
            . "    loop:\n"
            . "      max: 2\n";
        $noMax = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: b\n"
            . "    agent: coder\n"
            . "    prompt: q\n"
            . "    loop:\n"
            . "      until:\n"
            . "        stage: b\n"
            . "        contains: GO\n";

        foreach (['until' => $noUntil, 'max' => $noMax] as $missing => $yaml) {
            try {
                $this->registry()->fromYamlString($yaml);
                $this->fail("a loop without \"{$missing}\" must refuse, not guess");
            } catch (WorkflowLoadException $error) {
                $this->assertStringContainsString('both max and until', $error->getMessage());
            }
        }
    }

    public function testLoopRefusesJunkMaxThroughTheTimeoutParser(): void
    {
        foreach (
            ['abc' => 'whole number', '0' => 'at least 1', '-2' => 'at least 1', '1.5' => 'whole number']
            as $spelling => $needle
        ) {
            $yaml = "name: r\n"
                . "description: d\n"
                . "stages:\n"
                . "  - name: b\n"
                . "    agent: coder\n"
                . "    prompt: q\n"
                . "    loop:\n"
                . "      max: " . $spelling . "\n"
                . "      until:\n"
                . "        stage: b\n"
                . "        contains: GO\n";

            try {
                $this->registry()->fromYamlString($yaml);
                $this->fail("\"max: {$spelling}\" must refuse, not coerce");
            } catch (WorkflowLoadException $error) {
                $this->assertStringContainsString('loop.max', $error->getMessage());
                $this->assertStringContainsString($needle, $error->getMessage());
            }
        }
    }

    public function testLoopRefusesAMalformedUntilClause(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: b\n"
            . "    agent: coder\n"
            . "    prompt: q\n"
            . "    loop:\n"
            . "      max: 2\n"
            . "      until: ready\n";

        try {
            $this->registry()->fromYamlString($yaml);
            $this->fail('until rides the C2 grammar and must refuse a bare scalar');
        } catch (WorkflowLoadException $error) {
            $this->assertStringContainsString('"loop" "until"', $error->getMessage());
        }
    }

    public function testAScalarLoopRefusesAtLoad(): void
    {
        $yaml = "name: r\n"
            . "description: d\n"
            . "stages:\n"
            . "  - name: b\n"
            . "    agent: coder\n"
            . "    prompt: q\n"
            . "    loop: 3\n";

        try {
            $this->registry()->fromYamlString($yaml);
            $this->fail('loop: is a map, never a bare count');
        } catch (WorkflowLoadException $error) {
            $this->assertStringContainsString('must be a map carrying max and until', $error->getMessage());
        }
    }

    private function registry(): WorkflowRegistry
    {
        return new WorkflowRegistry($this->tempDir . '/workflows');
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        foreach ((array) \scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (\is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            \unlink($path);
        }

        \rmdir($dir);
    }
}
