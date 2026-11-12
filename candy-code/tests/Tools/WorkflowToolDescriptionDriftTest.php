<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\BuiltIn\WorkflowTool;
use SugarCraft\Code\Workflows\Workflow;
use SugarCraft\Code\Workflows\WorkflowGrammarGlossary;
use SugarCraft\Code\Workflows\WorkflowRegistry;

/**
 * wf-impl P0-1: the `Workflow` tool's description is the model's ONLY view of
 * the grammar, so it is generated from the loader's own rosters and pinned
 * here. A key the loader learns without a gloss — or a gloss for a key the
 * loader refuses — goes red, in the style of ReadmeRosterDriftTest.
 */
final class WorkflowToolDescriptionDriftTest extends TestCase
{
    public function testEveryStageTypeHasAGlossAndNoGlossNamesAnUnknownType(): void
    {
        $this->assertEqualsCanonicalizing(Workflow::STAGE_TYPES, array_keys(WorkflowGrammarGlossary::STAGE_TYPES));
    }

    public function testEveryTaskKeyHasAGlossAndNoGlossNamesAnUnknownKey(): void
    {
        $this->assertEqualsCanonicalizing(WorkflowRegistry::YAML_TASK_KEYS, array_keys(WorkflowGrammarGlossary::TASK_KEYS));
    }

    public function testEveryStageKeyHasAGlossAndNoGlossNamesAnUnknownKey(): void
    {
        $this->assertEqualsCanonicalizing(self::registryStageKeys(), array_keys(WorkflowGrammarGlossary::STAGE_KEYS));
    }

    public function testNoGlossIsBlank(): void
    {
        foreach ([WorkflowGrammarGlossary::STAGE_TYPES, WorkflowGrammarGlossary::STAGE_KEYS, WorkflowGrammarGlossary::TASK_KEYS, WorkflowGrammarGlossary::INTERPOLATION] as $roster) {
            foreach ($roster as $key => $gloss) {
                $this->assertNotSame('', trim($gloss), "gloss for {$key}");
            }
        }
    }

    public function testTheDescriptionCarriesEveryTypeAndKeyWithItsGloss(): void
    {
        $description = (new WorkflowTool())->description();

        foreach (Workflow::STAGE_TYPES as $type) {
            $this->assertStringContainsString("- `{$type}`: " . WorkflowGrammarGlossary::STAGE_TYPES[$type], $description);
        }
        foreach (self::registryStageKeys() as $key) {
            $this->assertStringContainsString("- `{$key}`: " . WorkflowGrammarGlossary::STAGE_KEYS[$key], $description);
        }
        foreach (WorkflowRegistry::YAML_TASK_KEYS as $key) {
            $this->assertStringContainsString("- `{$key}`: " . WorkflowGrammarGlossary::TASK_KEYS[$key], $description);
        }
        foreach (array_keys(WorkflowGrammarGlossary::INTERPOLATION) as $form) {
            $this->assertStringContainsString("`{$form}`", $description);
        }
    }

    public function testATaskKeyWithoutAGlossStillReachesTheModelBare(): void
    {
        // The generator walks the LOADER's roster, so a new key is never
        // invisible to the model even before its gloss lands.
        $grammar = WorkflowGrammarGlossary::grammar(['name', 'brandNewKey']);

        $this->assertStringContainsString("- `brandNewKey`\n", $grammar);
    }

    public function testTheDescriptionEndsByPointingAtTheSkill(): void
    {
        $this->assertStringEndsWith(
            'Load the `workflow-authoring` skill before authoring a non-trivial plan.',
            (new WorkflowTool())->description(),
        );
    }

    public function testThePlanSchemaDescriptionNamesEveryStageShape(): void
    {
        $plan = (new WorkflowTool())->inputSchema()['properties']['plan']['description'];

        foreach (['tasks:', 'parallel: true', 'agents:', 'from:', 'type: pipeline', 'type: verification', 'task:', 'verifier:', 'uses:', 'when', 'loop', 'capture'] as $shape) {
            $this->assertStringContainsString($shape, $plan);
        }
    }

    public function testDryRunIsInTheInputSchema(): void
    {
        $schema = (new WorkflowTool())->inputSchema();

        $this->assertSame('boolean', $schema['properties']['dryRun']['type']);
        $this->assertSame(['plan'], $schema['required']);
    }

    /** @return list<string> the registry's private stage-key roster */
    private static function registryStageKeys(): array
    {
        $value = (new \ReflectionClassConstant(WorkflowRegistry::class, 'YAML_STAGE_KEYS'))->getValue();
        self::assertIsArray($value);

        return $value;
    }
}
