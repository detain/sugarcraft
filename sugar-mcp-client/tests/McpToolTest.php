<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\McpTool;

/**
 * Lifted from candy-code/tests/MCP/McpToolTest — the whole file travels
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
        // this tool ALIVE — array_key_exists() used to drop it. (inputSchema
        // is absent from the leniency exactly because the spec makes it
        // required — its null shape is refused, see malformedDefinitions.)
        $tool = McpTool::tryFromArray(['name' => 'write', 'description' => null, 'inputSchema' => []], 'fs');

        self::assertNotNull($tool);
        self::assertSame('', $tool->description);
    }

    /** @return list<array{string, array<string,mixed>}> */
    public static function malformedDefinitions(): array
    {
        return [
            'name is a number (the measured kill-chain)' => [['name' => 5], 'name'],
            'name carries control bytes (N3)' => [['name' => "bad\x00name"], 'name'],
            'name carries escape sequences (N3)' => [['name' => "esc\x1b[31m"], 'name'],
            // MCPSEC-02: the old `/u` gate returned false (not 0) on an
            // undecodable subject, and the `=== 1` test read that as clean.
            'name carries undecodable bytes' => [['name' => "be\xE0\x80st"], 'name'],
            'name carries a bidi format control (RLO)' => [['name' => "rtl\u{202E}tool"], 'name'],
            'name carries private-use codepoints' => [['name' => "tool\u{E000}\u{E001}"], 'name'],
            'name is a bool' => [['name' => true], 'name'],
            // Measured: name is REQUIRED, not skip-if-absent. A bare
            // `{}` used to mint a phantom tool named '' through the isset()
            // leniency that legitimately serves description/inputSchema.
            'name absent entirely' => [[], 'name'],
            'name is the empty string' => [['name' => ''], 'name'],
            'name is null' => [['name' => null], 'name'],
            'description is a number' => [['name' => 'ok', 'description' => 7, 'inputSchema' => []], 'description'],
            // PARITY-01: the spec requires inputSchema; schema-less definitions
            // are spec-forbidden, not merely sparse.
            'inputSchema absent entirely' => [['name' => 'ok'], 'inputSchema'],
            'inputSchema is explicitly null' => [['name' => 'ok', 'inputSchema' => null], 'inputSchema'],
            'inputSchema type is not object' => [['name' => 'ok', 'inputSchema' => ['type' => 'string']], 'inputSchema.type'],
            'inputSchema is a string' => [['name' => 'ok', 'inputSchema' => 'nope'], 'inputSchema'],
            'inputSchema is a number' => [['name' => 'ok', 'inputSchema' => 42], 'inputSchema'],
            'annotations is a string' => [['name' => 'ok', 'annotations' => 'readOnly', 'inputSchema' => []], 'annotations'],
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

    public function testUndecodableNameBytesNeverReachTheGateOnTheWirePath(): void
    {
        // Frame-path parity: the transport's json_decode refuses the same
        // undecodable bytes the gate now refuses, so the gate's job is the
        // definition built off-wire — the two doors together leave no path
        // for an invalid-UTF-8 name to be registered.
        self::assertNull(json_decode('{"name":"be' . "\xE0\x80" . 'st"}', true));
        self::assertNull(McpTool::tryFromArray(['name' => "be\xE0\x80st"], 'fs'));
    }

    public function testAnnotationsAreCarriedVerbatimAndDefaultToEmpty(): void
    {
        $annotations = ['readOnlyHint' => true, 'openWorldHint' => false, 'title' => 'Query'];

        $hinted = McpTool::tryFromArray(['name' => 'query', 'annotations' => $annotations, 'inputSchema' => []], 'db');
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
