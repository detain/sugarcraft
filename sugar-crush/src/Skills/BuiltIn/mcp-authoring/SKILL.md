---
name: mcp-authoring
description: Scaffolds a new MCP tool inside a SugarCraft lib that wants to expose itself over the protocol. Use when a lib maintainer says 'add MCP tool', 'expose over protocol', 'register MCP handler', or creates files under a lib's `src/MCP/` directory. Generates the tool schema, McpServer wiring, and a smoke test.
license: MIT
metadata:
  author: sugar-crush-team
  version: "1.0.0"
  phase: P7
  step: P7.S17
---

# MCP Authoring

Scaffolds a new MCP tool exposed by a SugarCraft library over the Model Context Protocol.

## When to Use

- A lib maintainer says "add MCP tool", "expose over protocol", or "register MCP handler"
- Files are created under `<slug>/src/MCP/`
- A lib wants to contribute tools to the sugar-crush agent runtime

## Step-by-Step

### 1. Identify the Lib and Tool Name

From the lib slug and the operation the maintainer described, derive:

```
Lib: candy-query    Tool: query    FQN: SugarCraft\Query\MCP\QueryMcpServer
Lib: sugar-charts  Tool: chart    FQN: SugarCraft\Charts\MCP\ChartMcpServer
```

The tool name should be a lowercase singular noun matching the lib's purpose.

### 2. Create the Tool Schema

Create `<slug>/src/MCP/<Tool>McpTool.php`:

```php
<?php
declare(strict_types=1);

namespace SugarCraft\<Lib>\MCP;

use SugarCraft\Core\MCP\McpTool;

/**
 * Mirrors <upstream>/<repo>.<Method>
 */
final class <Tool>McpTool extends McpTool
{
    public function name(): string
    {
        return '<tool>';
    }

    public function description(): string
    {
        return '<One sentence describing what this tool does.>';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                '<param>' => [
                    'type' => 'string',
                    'description' => '<description>',
                ],
            ],
            'required' => ['<param>'],
        ];
    }

    protected function doExecute(array $params): mixed
    {
        // TODO: implement
    }
}
```

### 3. Create the McpServer Wiring

Create `<slug>/src/MCP/<Tool>McpServer.php`:

```php
<?php
declare(strict_types=1);

namespace SugarCraft\<Lib>\MCP;

use SugarCraft\Core\MCP\McpServer;

/**
 * McpServer extension for <Tool> capability.
 * Mirrors charmbracelet/<repo> protocol surface.
 */
final class <Tool>McpServer extends McpServer
{
    protected function registerTools(): void
    {
        $this->registerTool(new <Tool>McpTool());
    }
}
```

### 4. Register in the Root McpServer

Add to `src/MCP/McpServer.php` in the main sugar-crush codebase:

```php
// In registerServers() or the servers list:
<Tool>McpServer::class => 'sugarcraft-<slug>',
```

### 5. Create a Smoke Test

Create `<slug>/tests/MCP/<Tool>McpToolTest.php`:

```php
<?php
declare(strict_types=1);

namespace SugarCraft\<Lib>\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\<Lib>\MCP\<Tool>McpTool;

final class <Tool>McpToolTest extends TestCase
{
    public function testNameIsString(): void
    {
        $tool = new <Tool>McpTool();
        $this->assertIsString($tool->name());
    }

    public function testInputSchemaHasType(): void
    {
        $tool = new <Tool>McpTool();
        $schema = $tool->inputSchema();
        $this->assertSame('object', $schema['type']);
    }
}
```

## Convention Notes

- One tool per class, one class per file
- Tool names are lowercase `snake_case` strings — they become the MCP protocol method name
- `inputSchema()` must return a valid JSON Schema object for the `inputSchema` field
- `doExecute()` is protected — the public `execute(array $params)` entry point is inherited
- All tools live in `<lib>/src/MCP/` — not in the root `src/MCP/` of sugar-crush itself

## Validation

After scaffolding, run:

```bash
cd <slug> && vendor/bin/phpunit tests/MCP/<Tool>McpToolTest.php
```

Confirm the tool is registered:

```php
$server = new <Tool>McpServer();
$this->assertContains('<tool>', $server->listToolNames());
```
