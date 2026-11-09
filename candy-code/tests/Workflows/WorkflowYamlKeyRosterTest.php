<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowRegistry;

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
            self::assertStringContainsString('agent #0', $error->getMessage());
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
            . "        prompt: r\n";

        $workflow = $this->registry()->fromYamlString($yaml);

        $types = \array_map(
            static fn (array $stage): string => (string) $stage['type'],
            $workflow->stages
        );
        self::assertSame(['stage', 'stage', 'verification', 'pipeline'], $types);

        $verifier = $workflow->stages[2]['verifier'];
        self::assertInstanceOf(\SugarCraft\Code\Workflows\WorkflowTask::class, $verifier);
        self::assertSame('/\bREJECT\b/', $verifier->reject);
        self::assertSame(2, $verifier->rejectMinOccurrences);

        self::assertSame(['a', 'b', 'c', 'd'], \array_map(
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

    public function testTheRosterIsTheSingleSource(): void
    {
        $registry = new ReflectionClass(WorkflowRegistry::class);

        self::assertSame(
            ['agent', 'name', 'prompt', 'tools', 'retries', 'outputSchema',
                'isolation', 'model', 'resource', 'lockWaitSeconds',
                'timeout', 'reject', 'rejectMinOccurrences'],
            $registry->getConstant('YAML_TASK_KEYS'),
            'the parsed task-key roster is one pinned list'
        );
        self::assertSame(
            ['name', 'parallel', 'capture', 'cache', 'type', 'agents', 'tasks',
                'task', 'verifier', 'stages', 'when'],
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
