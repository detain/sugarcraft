<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use SugarCraft\Crush\Registry\Tool;
use SugarCraft\Crush\Registry\ToolSignature;
use SugarCraft\Crush\ToolRegistry;
use SugarCraft\Crush\ToolResult;
use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase
{
    public function testBuiltInToolsAreRegistered(): void
    {
        $registry = new ToolRegistry();

        $this->assertTrue($registry->has('filter'));
        $this->assertTrue($registry->has('sort'));
        $this->assertTrue($registry->has('goto'));
        $this->assertTrue($registry->has('select'));
        $this->assertTrue($registry->has('quit'));
    }

    public function testHasReturnsFalseForUnknownTool(): void
    {
        $registry = new ToolRegistry();
        $this->assertFalse($registry->has('does-not-exist'));
    }

    public function testGetReturnsNullForUnknownTool(): void
    {
        $registry = new ToolRegistry();
        $this->assertNull($registry->get('does-not-exist'));
    }

    public function testGetReturnsToolForKnownName(): void
    {
        $registry = new ToolRegistry();
        $tool = $registry->get('filter');
        $this->assertNotNull($tool);
        $this->assertSame('filter', $tool->name);
    }

    public function testAllReturnsListOfTools(): void
    {
        $registry = new ToolRegistry();
        $tools = $registry->all();
        $this->assertCount(5, $tools);
        $names = array_map(static fn(Tool $t) => $t->name, $tools);
        $this->assertContains('filter', $names);
        $this->assertContains('sort', $names);
        $this->assertContains('goto', $names);
        $this->assertContains('select', $names);
        $this->assertContains('quit', $names);
    }

    public function testExecuteFilterTool(): void
    {
        $registry = new ToolRegistry();
        $result = $registry->execute('filter', ['expression' => 'error']);
        $this->assertSame('filter', $result->name);
        $this->assertFalse($result->isError());
        $this->assertStringContainsString('error', $result->result);
    }

    public function testExecuteSortTool(): void
    {
        $registry = new ToolRegistry();

        $result = $registry->execute('sort', []);
        $this->assertSame('sort', $result->name);
        $this->assertFalse($result->isError());
        $this->assertStringContainsString('Sort applied', $result->result);

        $resultReverse = $registry->execute('sort', ['r' => true]);
        $this->assertStringContainsString('reverse', $resultReverse->result);
    }

    public function testExecuteGotoTool(): void
    {
        $registry = new ToolRegistry();
        $result = $registry->execute('goto', ['line' => '42']);
        $this->assertSame('goto', $result->name);
        $this->assertFalse($result->isError());
        $this->assertStringContainsString('42', $result->result);
    }

    public function testExecuteSelectTool(): void
    {
        $registry = new ToolRegistry();
        $result = $registry->execute('select', ['start' => '10', 'end' => '20']);
        $this->assertSame('select', $result->name);
        $this->assertFalse($result->isError());
        $this->assertStringContainsString('10', $result->result);
        $this->assertStringContainsString('20', $result->result);
    }

    public function testExecuteQuitTool(): void
    {
        $registry = new ToolRegistry();
        $result = $registry->execute('quit', []);
        $this->assertSame('quit', $result->name);
        $this->assertFalse($result->isError());
        $this->assertSame('Quit', $result->result);
    }

    public function testExecuteUnknownToolThrowsException(): void
    {
        $registry = new ToolRegistry();
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Unknown tool: unknown-cmd');
        $registry->execute('unknown-cmd');
    }

    public function testRegisterOverwritesExistingTool(): void
    {
        $registry = new ToolRegistry();

        $called = false;
        $registry->register(new Tool(
            name: 'filter',
            signature: new ToolSignature(positional: ['x']),
            execute: static function (array $args) use (&$called): ToolResult {
                $called = true;
                return ToolResult::ok('filter', 'custom filter result');
            },
        ));

        $result = $registry->execute('filter', ['x' => 'y']);
        $this->assertTrue($called);
        $this->assertSame('custom filter result', $result->result);
    }

    public function testToolSignaturePositionalAndNamedArgs(): void
    {
        $tool = new Tool(
            name: 'test',
            signature: new ToolSignature(
                positional: ['a', 'b'],
                named: ['verbose' => false],
                description: 'Test tool',
            ),
            execute: static fn() => ToolResult::ok('test', 'ok'),
        );

        $this->assertSame(['a', 'b'], $tool->signature->positional);
        $this->assertSame(['verbose' => false], $tool->signature->named);
        $this->assertSame('Test tool', $tool->signature->description);
    }

    public function testToolExecuteMethodDelegatesToHandler(): void
    {
        $capturedArgs = null;
        $tool = new Tool(
            name: 'test',
            signature: new ToolSignature(),
            execute: static function (array $args) use (&$capturedArgs): ToolResult {
                $capturedArgs = $args;
                return ToolResult::ok('test', 'executed');
            },
        );

        $result = $tool->execute(['foo' => 'bar']);
        $this->assertSame(['foo' => 'bar'], $capturedArgs);
        $this->assertSame('executed', $result->result);
    }

    public function testToolResultOkFactory(): void
    {
        $result = ToolResult::ok('my-tool', 'some output', 'call-id');
        $this->assertSame('my-tool', $result->name);
        $this->assertSame('some output', $result->result);
        $this->assertNull($result->error);
        $this->assertFalse($result->isError());
        $this->assertSame('call-id', $result->id);
    }

    public function testToolResultErrorFactory(): void
    {
        $result = ToolResult::error('my-tool', 'something went wrong', 'call-id');
        $this->assertSame('my-tool', $result->name);
        $this->assertSame('something went wrong', $result->error);
        $this->assertSame('', $result->result);
        $this->assertTrue($result->isError());
        $this->assertSame('call-id', $result->id);
    }

    /**
     * E14 regression pin: the phantom bare `SugarCraft\Crush\Tool` — once a
     * side declaration of `src/ToolRegistry.php`, one `use` away from
     * colliding with the `SugarCraft\Crush\Tools\Tool` interface every live
     * tool implements — must not be declared in this process.
     * `class_exists(..., false)` observes in-process state only, which is
     * exactly the in-scope vector: this suite drives `ToolRegistry`, so its
     * file is already loaded, and re-adding the side class there would
     * declare the name before this assertion runs. It deliberately does NOT
     * claim the name can never be declared anywhere else.
     * `autoload=true` is not used on purpose: an `*_exists()` call with
     * autoloading is a resolution probe, banned by the
     * `classifyFilePsr4Symbol()` token-gate doctrine (resolution consults
     * `declaredTypes()`, never the autoloader — probing a mis-namespaced
     * file makes Composer re-include it and the whole process dies rc 255).
     */
    public function testTheRootNamespaceDeclaresNoBareToolSymbol(): void
    {
        $this->assertFalse(class_exists('SugarCraft\\Crush\\Tool', false));
        $this->assertTrue(class_exists(Tool::class));
        $this->assertTrue(class_exists(ToolSignature::class));
    }
}
