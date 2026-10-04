<?php

declare(strict_types=1);

namespace SugarCraft\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mcp\McpTool;

/**
 * Lifted from sugar-crush/tests/MCP/McpToolTest — the whole file travels
 * without product dependencies. The type-gate rows are the load-bearing part:
 * they pin the kill-chain fix (a wrong-typed wire field must degrade to
 * "skipped", never reach the constructor as a TypeError).
 */
final class McpToolTest extends TestCase
{
    public function testConstructorCarriesAllFourFields(): void
    {
        $tool = new McpTool('read', 'Reads files', ['type' => 'object'], 'fs');

        self::assertSame('read', $tool->name);
        self::assertSame('Reads files', $tool->description);
        self::assertSame(['type' => 'object'], $tool->inputSchema);
        self::assertSame('fs', $tool->serverName);
    }

    public function testFromArrayDefaultsAbsentAndNullFields(): void
    {
        $fromEmpty = McpTool::fromArray([], 'fs');
        self::assertSame('', $fromEmpty->name);
        self::assertSame('', $fromEmpty->description);
        self::assertSame([], $fromEmpty->inputSchema);

        $fromNulls = McpTool::fromArray(
            ['name' => 'write', 'description' => null, 'inputSchema' => null],
            'fs',
        );
        self::assertSame('write', $fromNulls->name);
        self::assertSame('', $fromNulls->description);
        self::assertSame([], $fromNulls->inputSchema);

        $partial = McpTool::fromArray(['name' => 'only'], 'fs');
        self::assertSame('only', $partial->name);
        self::assertSame('', $partial->description);
    }

    public function testFromArrayBuildsComplexSchemas(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string'], 'nested' => ['type' => 'array']],
            'required' => ['path'],
        ];

        self::assertSame($schema, McpTool::fromArray(['name' => 'x', 'inputSchema' => $schema], 'fs')->inputSchema);
    }

    public function testTryFromArrayAcceptsWellTypedDefinitions(): void
    {
        $tool = McpTool::tryFromArray(['name' => 'ping', 'description' => 'p', 'inputSchema' => []], 'svc');

        self::assertNotNull($tool);
        self::assertSame('ping', $tool->name);
        self::assertSame('svc', $tool->serverName);
    }

    public function testTryFromArrayAcceptsTheExplicitNullFieldShapes(): void
    {
        // The {"name":"write","description":null} case: isset() semantics keep
        // this tool ALIVE — array_key_exists() used to drop it.
        $tool = McpTool::tryFromArray(['name' => 'write', 'description' => null], 'fs');

        self::assertNotNull($tool);
        self::assertSame('', $tool->description);
    }

    /** @return list<array{string, array<string,mixed>}> */
    public static function malformedDefinitions(): array
    {
        return [
            'name is a number (the measured kill-chain)' => [['name' => 5], 'name'],
            'name is a bool' => [['name' => true], 'name'],
            // Review probe P8: name is REQUIRED, not skip-if-absent. A bare
            // `{}` used to mint a phantom tool named '' through the isset()
            // leniency that legitimately serves description/inputSchema.
            'name absent entirely' => [[], 'name'],
            'name is the empty string' => [['name' => ''], 'name'],
            'name is null' => [['name' => null], 'name'],
            'description is a number' => [['name' => 'ok', 'description' => 7], 'description'],
            'inputSchema is a string' => [['name' => 'ok', 'inputSchema' => 'nope'], 'inputSchema'],
            'inputSchema is a number' => [['name' => 'ok', 'inputSchema' => 42], 'inputSchema'],
            'annotations is a string' => [['name' => 'ok', 'annotations' => 'readOnly'], 'annotations'],
        ];
    }

    /** @dataProvider malformedDefinitions */
    public function testTryFromArrayRefusesWrongTypedFieldsWithoutReachingTheConstructor(
        array $definition,
        string $offendingField,
    ): void {
        self::assertNull(
            McpTool::tryFromArray($definition, 'fs'),
            "field {$offendingField} of the wrong type must skip the definition",
        );
    }

    public function testAnnotationsAreCarriedVerbatimAndDefaultToEmpty(): void
    {
        $annotations = ['readOnlyHint' => true, 'openWorldHint' => false, 'title' => 'Query'];

        $hinted = McpTool::tryFromArray(['name' => 'query', 'annotations' => $annotations], 'db');
        self::assertNotNull($hinted);
        self::assertSame($annotations, $hinted->annotations);

        self::assertSame([], McpTool::fromArray(['name' => 'plain'], 'db')->annotations);
        self::assertSame([], McpTool::fromArray(['name' => 'nulled', 'annotations' => null], 'db')->annotations);
        self::assertSame([], (new McpTool('n', 'd', [], 's'))->annotations);
    }

    public function testValueObjectsAreIndependentInstances(): void
    {
        $a = McpTool::fromArray(['name' => 'a', 'inputSchema' => ['k' => 1]], 'fs');
        $b = McpTool::fromArray(['name' => 'b', 'inputSchema' => ['k' => 2]], 'fs');

        self::assertSame(['k' => 1], $a->inputSchema);
        self::assertSame(['k' => 2], $b->inputSchema);
    }
}
