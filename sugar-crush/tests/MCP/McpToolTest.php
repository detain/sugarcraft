<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\McpTool;

/**
 * @see McpTool
 */
final class McpToolTest extends TestCase
{
    // =========================================================================
    // Creation Tests
    // =========================================================================

    public function testCanBeCreatedWithAllFields(): void
    {
        $name = 'get_weather';
        $description = 'Get weather for a location';
        $inputSchema = ['type' => 'object', 'properties' => ['location' => ['type' => 'string']]];
        $serverName = 'weather-api';

        $tool = new McpTool($name, $description, $inputSchema, $serverName);

        $this->assertSame($name, $tool->name);
        $this->assertSame($description, $tool->description);
        $this->assertSame($inputSchema, $tool->inputSchema);
        $this->assertSame($serverName, $tool->serverName);
    }

    public function testCanBeCreatedWithEmptyInputSchema(): void
    {
        $tool = new McpTool('empty_tool', 'A tool with no input schema', [], 'test-server');

        $this->assertSame([], $tool->inputSchema);
    }

    public function testCanBeCreatedWithComplexInputSchema(): void
    {
        $complexSchema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
                'active' => ['type' => 'boolean'],
            ],
            'required' => ['name'],
        ];

        $tool = new McpTool('complex_tool', 'Tool with complex schema', $complexSchema, 'test-server');

        $this->assertSame($complexSchema, $tool->inputSchema);
    }

    public function testReadonlyPropertiesCannotBeModified(): void
    {
        $tool = new McpTool('readonly_test', 'Testing readonly', ['key' => 'value'], 'server');

        // Properties should be publicly readable but not modifiable
        $this->assertSame('readonly_test', $tool->name);
        $this->assertSame('Testing readonly', $tool->description);
        $this->assertSame(['key' => 'value'], $tool->inputSchema);
        $this->assertSame('server', $tool->serverName);
    }

    // =========================================================================
    // fromArray Tests
    // =========================================================================

    public function testFromArrayWithAllFields(): void
    {
        $data = [
            'name' => 'list_files',
            'description' => 'List files in a directory',
            'inputSchema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]],
        ];
        $serverName = 'file-manager';

        $tool = McpTool::fromArray($data, $serverName);

        $this->assertSame('list_files', $tool->name);
        $this->assertSame('List files in a directory', $tool->description);
        $this->assertSame($data['inputSchema'], $tool->inputSchema);
        $this->assertSame($serverName, $tool->serverName);
    }

    public function testFromArrayWithMissingName(): void
    {
        $data = [
            'description' => 'Missing name field',
            'inputSchema' => [],
        ];

        $tool = McpTool::fromArray($data, 'test-server');

        $this->assertSame('', $tool->name);
    }

    public function testFromArrayWithMissingDescription(): void
    {
        $data = [
            'name' => 'test_tool',
            'inputSchema' => [],
        ];

        $tool = McpTool::fromArray($data, 'test-server');

        $this->assertSame('', $tool->description);
    }

    public function testFromArrayWithMissingInputSchema(): void
    {
        $data = [
            'name' => 'test_tool',
            'description' => 'Test description',
        ];

        $tool = McpTool::fromArray($data, 'test-server');

        $this->assertSame([], $tool->inputSchema);
    }

    public function testFromArrayWithEmptyArray(): void
    {
        $tool = McpTool::fromArray([], 'empty-server');

        $this->assertSame('', $tool->name);
        $this->assertSame('', $tool->description);
        $this->assertSame([], $tool->inputSchema);
        $this->assertSame('empty-server', $tool->serverName);
    }

    public function testFromArrayServerNameIsRequired(): void
    {
        $data = ['name' => 'tool', 'description' => 'desc', 'inputSchema' => []];

        $tool = McpTool::fromArray($data, 'explicit-server');

        $this->assertSame('explicit-server', $tool->serverName);
    }

    public function testFromArrayWithNullValues(): void
    {
        $data = [
            'name' => null,
            'description' => null,
            'inputSchema' => null,
        ];

        $tool = McpTool::fromArray($data, 'null-server');

        // null coalescing will treat these as empty/defaults
        $this->assertSame('', $tool->name);
        $this->assertSame('', $tool->description);
        $this->assertSame([], $tool->inputSchema);
    }

    public function testFromArrayWithPartialData(): void
    {
        $data = [
            'name' => 'partial_tool',
            // description and inputSchema missing
        ];

        $tool = McpTool::fromArray($data, 'partial-server');

        $this->assertSame('partial_tool', $tool->name);
        $this->assertSame('', $tool->description);
        $this->assertSame([], $tool->inputSchema);
    }

    // =========================================================================
    // Immutability Tests
    // =========================================================================

    public function testInstancesAreIndependent(): void
    {
        $data1 = ['name' => 'tool1', 'description' => 'Desc 1', 'inputSchema' => ['type' => 'object']];
        $data2 = ['name' => 'tool2', 'description' => 'Desc 2', 'inputSchema' => ['type' => 'string']];

        $tool1 = McpTool::fromArray($data1, 'server1');
        $tool2 = McpTool::fromArray($data2, 'server2');

        $this->assertNotSame($tool1, $tool2);
        $this->assertSame('tool1', $tool1->name);
        $this->assertSame('tool2', $tool2->name);
    }

    public function testInputSchemaArrayIsNotModifiedByCaller(): void
    {
        $originalSchema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];
        $tool = new McpTool('test', 'desc', $originalSchema, 'server');

        $originalSchema['properties']['name']['type'] = 'number';
        $this->assertSame('string', $tool->inputSchema['properties']['name']['type']);
    }
}
