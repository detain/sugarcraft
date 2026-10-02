<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers\ToolCallParser;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\ToolCallParser\ToolParameterTypes;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Audit 15a A9: the per-request type map a text-scanning parser types its
 * recovered values by. "Unknown" must never be reported as a partial type
 * list, because the parser's safe fallback depends on telling them apart.
 */
final class ToolParameterTypesTest extends TestCase
{
    /**
     * @param array<string, mixed> $schema
     */
    private static function tool(string $name, array $schema): Tool
    {
        return new class ($name, $schema) implements Tool {
            /** @param array<string, mixed> $schema */
            public function __construct(private string $toolName, private array $schema) {}

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'stub';
            }

            public function inputSchema(): array
            {
                return $this->schema;
            }

            public function execute(array $args): ToolResult
            {
                throw new \LogicException('never executed');
            }
        };
    }

    public function testFromToolsReadsStringAndListTypes(): void
    {
        $types = ToolParameterTypes::fromTools([self::tool('Write', [
            'type' => 'object',
            'properties' => [
                'content' => ['type' => 'string'],
                'meta' => ['type' => ['object', 'null']],
                'overwrite' => ['type' => 'boolean'],
            ],
        ])]);

        $this->assertSame(['string'], $types->declared('Write', 'content'));
        $this->assertSame(['object', 'null'], $types->declared('Write', 'meta'));
        $this->assertSame(['boolean'], $types->declared('Write', 'overwrite'));
        $this->assertFalse($types->isEmpty());
    }

    public function testFromToolsUnionsAFullyTypedAnyOfOrOneOf(): void
    {
        $types = ToolParameterTypes::fromTools([self::tool('t', [
            'properties' => [
                'a' => ['anyOf' => [['type' => 'integer'], ['type' => 'null']]],
                'b' => ['oneOf' => [['type' => ['array', 'null']], ['type' => 'object']]],
            ],
        ])]);

        $this->assertSame(['integer', 'null'], $types->declared('t', 'a'));
        $this->assertSame(['array', 'null', 'object'], $types->declared('t', 'b'));
    }

    public function testAnythingNotFullyTypedIsUnknownRatherThanPartial(): void
    {
        $types = ToolParameterTypes::fromTools([self::tool('t', [
            'properties' => [
                'untyped' => ['description' => 'no type at all'],
                'halfTyped' => ['anyOf' => [['type' => 'integer'], ['enum' => ['a', 'b']]]],
                'badType' => ['type' => 42],
                'badList' => ['type' => ['string', 7]],
                'emptyList' => ['type' => []],
            ],
        ])]);

        foreach (['untyped', 'halfTyped', 'badType', 'badList', 'emptyList', 'absent'] as $parameter) {
            $this->assertNull($types->declared('t', $parameter), $parameter);
        }
        $this->assertNull($types->declared('unknownTool', 'untyped'));
        $this->assertTrue($types->isEmpty());
    }

    public function testFromToolsSkipsNonToolEntriesAndSchemasWithoutProperties(): void
    {
        $types = ToolParameterTypes::fromTools([
            'not a tool',
            ['name' => 'array-shaped'],
            self::tool('NoProps', ['type' => 'object']),
            self::tool('Read', ['properties' => ['limit' => ['type' => 'integer']]]),
        ]);

        $this->assertSame(['integer'], $types->declared('Read', 'limit'));
        $this->assertNull($types->declared('NoProps', 'anything'));
        $this->assertTrue(ToolParameterTypes::fromTools(null)->isEmpty());
        $this->assertTrue(ToolParameterTypes::fromTools([])->isEmpty());
    }

    public function testNewKeepsWellFormedEntriesAndDropsMalformedOnes(): void
    {
        $types = ToolParameterTypes::new([
            'Write' => ['content' => ['string'], 'bad' => 'string', 'empty' => []],
        ]);

        $this->assertSame(['string'], $types->declared('Write', 'content'));
        $this->assertNull($types->declared('Write', 'bad'));
        $this->assertNull($types->declared('Write', 'empty'));
        $this->assertTrue(ToolParameterTypes::new()->isEmpty());
    }
}
