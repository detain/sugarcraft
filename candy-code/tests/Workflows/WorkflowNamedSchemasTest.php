<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Workflows\Workflow;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowLinter;
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowTask;

/**
 * P2-4: a top-level `schemas:` map of named, object-rooted JSON Schemas, and
 * `outputSchema: {$ref: <name>}` resolving against it at load on every task
 * surface — with the RESOLVED document what the stage identity hash digests.
 */
final class WorkflowNamedSchemasTest extends TestCase
{
    private const SCHEMAS = "schemas:\n"
        . "  verdict:\n"
        . "    type: object\n"
        . "    properties: {refuted: {type: boolean}, reason: {type: string}}\n"
        . "    required: [refuted, reason]\n"
        . "  findings:\n"
        . "    type: object\n"
        . "    properties: {findings: {type: array}}\n";

    private const VERDICT = [
        'type' => 'object',
        'properties' => ['refuted' => ['type' => 'boolean'], 'reason' => ['type' => 'string']],
        'required' => ['refuted', 'reason'],
    ];

    public function testARefResolvesOnEveryTaskSurface(): void
    {
        $workflow = $this->load(
            "  - name: one\n"
            . "    prompt: p\n"
            . "    outputSchema: {\$ref: verdict}\n"
            . "  - name: fan\n"
            . "    parallel: true\n"
            . "    agents:\n"
            . "      - {type: a, prompt: p, outputSchema: {\$ref: verdict}}\n"
            . "  - name: seq\n"
            . "    tasks:\n"
            . "      - {prompt: p, outputSchema: '{\"\$ref\": \"verdict\"}'}\n"
            . "  - name: check\n"
            . "    type: verification\n"
            . "    task: {prompt: p, outputSchema: {\$ref: findings}}\n"
            . "    verifier: {prompt: v, outputSchema: {\$ref: verdict}}\n"
            . "  - name: pipe\n"
            . "    type: pipeline\n"
            . "    stages:\n"
            . "      - {name: s1, prompt: p, outputSchema: {\$ref: verdict}}\n"
            . "  - name: wave\n"
            . "    parallel: true\n"
            . "    prompt: fix {{path}}\n"
            . "    outputSchema: {\$ref: verdict}\n"
            . "    from: {glob: 'src/x'}\n"
        );

        [$one, $fan, $seq, $check, $pipe, $wave] = $workflow->stages;
        self::assertSame(self::VERDICT, $one['tasks'][0]->outputSchema, 'a one-task stage');
        self::assertSame(self::VERDICT, $fan['tasks'][0]->outputSchema, 'a parallel agent');
        self::assertSame(self::VERDICT, $seq['tasks'][0]->outputSchema, 'a tasks-list entry, JSON-string spelling');
        self::assertSame('object', $check['task']->outputSchema['type'] ?? null, 'a verification task slot');
        self::assertSame(['findings' => ['type' => 'array']], $check['task']->outputSchema['properties'] ?? null);
        self::assertSame(self::VERDICT, $check['verifier']->outputSchema, 'a verifier slot');
        self::assertSame(self::VERDICT, $pipe['stages'][0]['tasks'][0]->outputSchema, 'a pipeline step');
        self::assertSame(self::VERDICT, $wave['tasks'][0]->outputSchema, 'a from: template');
    }

    public function testAnInlineSchemaAndANestedRefPassThroughUntouched(): void
    {
        $nested = ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/definitions/a']]];
        $workflow = $this->load(
            "  - name: one\n"
            . "    prompt: p\n"
            . "    outputSchema: " . json_encode($nested) . "\n"
        );

        self::assertSame($nested, $workflow->stages[0]['tasks'][0]->outputSchema);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedShapes(): iterable
    {
        $stage = static fn (string $schema): string => "  - name: one\n    prompt: p\n    outputSchema: {$schema}\n";

        yield 'an unknown ref' => [self::SCHEMAS, $stage('{$ref: verdcit}'), "stage 'one' \"outputSchema\" \"\$ref\" names unknown schema \"verdcit\"; declared schemas: verdict, findings"];
        yield 'a ref with no schemas map' => ['', $stage('{$ref: verdict}'), 'names unknown schema "verdict"; this file declares no top-level "schemas:" map'];
        yield 'a ref beside other keys' => [self::SCHEMAS, $stage('{$ref: verdict, type: object}'), 'carries "$ref" beside other keys'];
        yield 'a non-string ref' => [self::SCHEMAS, $stage('{$ref: [verdict]}'), '"$ref" must name a schema, got array'];
        yield 'a list of schemas' => ["schemas: [a, b]\n", '', '"schemas" must be a map of name => schema, got a list'];
        yield 'a scalar schemas' => ["schemas: nope\n", '', '"schemas" must be a map of name => schema, got string'];
        yield 'a schema that is not a map' => ["schemas:\n  v: true\n", '', 'schema "v" must be a schema map, got bool'];
        yield 'a schema that is not object-rooted' => ["schemas:\n  v: {type: array}\n", '', 'schema "v" must be object-rooted ("type: object" at its root)'];
        yield 'required outside properties' => ["schemas:\n  v: {type: object, properties: {a: {type: string}}, required: [a, b]}\n", '', 'schema "v" "required" names "b", which is not one of its "properties"'];
        yield 'a bad schema name' => ["schemas:\n  '1st': {type: object}\n", '', '"schemas" key "1st" is not a usable name'];
    }

    /**
     * @dataProvider refusedShapes
     */
    public function testARefusedShapeDiesAtLoad(string $schemas, string $stages, string $needle): void
    {
        try {
            (new WorkflowRegistry(sys_get_temp_dir()))->fromYamlString("name: s\n" . $schemas . "stages:\n" . ($stages === '' ? "  - {name: one, prompt: p}\n" : $stages));
            self::fail("'{$needle}' still loaded");
        } catch (WorkflowLoadException $e) {
            self::assertStringContainsString($needle, $e->getMessage());
        }
    }

    public function testTheIdentityHashDigestsTheResolvedSchemaNotItsName(): void
    {
        $stage = "  - name: one\n    prompt: p\n    outputSchema: {\$ref: verdict}\n";
        $byRef = $this->load($stage);
        $inline = (new WorkflowRegistry(sys_get_temp_dir()))->fromYamlString(
            "name: s\nstages:\n  - name: one\n    prompt: p\n    outputSchema: " . json_encode(self::VERDICT) . "\n"
        );
        $edited = (new WorkflowRegistry(sys_get_temp_dir()))->fromYamlString(
            "name: s\nschemas:\n  verdict: {type: object, properties: {refuted: {type: boolean}}}\nstages:\n" . $stage
        );

        self::assertSame(self::hash($inline), self::hash($byRef), 'a ref and the same schema inline are one identity');
        self::assertNotSame(self::hash($byRef), self::hash($edited), 'editing the shared schema changes every stage that uses it');
    }

    public function testTheLinterFlagsAHandBuiltUnresolvedRef(): void
    {
        $plan = new Workflow('w', 'd', [
            ['name' => 'one', 'type' => 'stage', 'tasks' => [new WorkflowTask('coder', 'p', tools: ['Read'], outputSchema: ['$ref' => 'verdict'])]],
        ]);

        self::assertStringContainsString(
            'error [one] outputSchema is an unresolved {$ref: verdict}',
            WorkflowLinter::lint($plan, [], ['Read'])->renderFindings(),
        );
    }

    private function load(string $stages): Workflow
    {
        return (new WorkflowRegistry(sys_get_temp_dir()))->fromYamlString("name: s\n" . self::SCHEMAS . "stages:\n" . $stages);
    }

    private static function hash(Workflow $workflow): string
    {
        $method = new \ReflectionMethod(WorkflowEngine::class, 'stageInputHash');

        return (string) $method->invoke(null, $workflow->stages[0], 'stage');
    }
}
