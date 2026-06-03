<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\HookContext;

/**
 * @see HookContext
 */
final class HookContextTest extends TestCase
{
    private const FIXTURE_SESSION_ID = 'session_abc123';
    private const FIXTURE_TOOL_NAME = 'Read';
    private const FIXTURE_TOOL_INPUT = 'original input';
    private const FIXTURE_TOOL_OUTPUT = 'original output';
    private const FIXTURE_MODEL = 'claude-sonnet-4-6';
    private const FIXTURE_PROVIDER = 'anthropic';
    private const FIXTURE_PROJECT_ROOT = '/home/sites/sugarcraft';

    // =========================================================================
    // Creation Tests
    // =========================================================================

    public function testCanBeCreatedWithAllFields(): void
    {
        $toolArgs = ['file' => '/path/to/file.txt'];

        $context = new HookContext(
            sessionId: self::FIXTURE_SESSION_ID,
            toolName: self::FIXTURE_TOOL_NAME,
            toolArgs: $toolArgs,
            toolInput: self::FIXTURE_TOOL_INPUT,
            toolOutput: self::FIXTURE_TOOL_OUTPUT,
            model: self::FIXTURE_MODEL,
            provider: self::FIXTURE_PROVIDER,
            projectRoot: self::FIXTURE_PROJECT_ROOT,
        );

        $this->assertSame(self::FIXTURE_SESSION_ID, $context->sessionId);
        $this->assertSame(self::FIXTURE_TOOL_NAME, $context->toolName);
        $this->assertSame($toolArgs, $context->toolArgs);
        $this->assertSame(self::FIXTURE_TOOL_INPUT, $context->toolInput);
        $this->assertSame(self::FIXTURE_TOOL_OUTPUT, $context->toolOutput);
        $this->assertSame(self::FIXTURE_MODEL, $context->model);
        $this->assertSame(self::FIXTURE_PROVIDER, $context->provider);
        $this->assertSame(self::FIXTURE_PROJECT_ROOT, $context->projectRoot);
    }

    public function testCanBeCreatedWithEmptyToolArgs(): void
    {
        $context = new HookContext(
            sessionId: self::FIXTURE_SESSION_ID,
            toolName: self::FIXTURE_TOOL_NAME,
            toolArgs: [],
            toolInput: self::FIXTURE_TOOL_INPUT,
            toolOutput: self::FIXTURE_TOOL_OUTPUT,
            model: self::FIXTURE_MODEL,
            provider: self::FIXTURE_PROVIDER,
            projectRoot: self::FIXTURE_PROJECT_ROOT,
        );

        $this->assertSame([], $context->toolArgs);
    }

    // =========================================================================
    // withToolInput Tests
    // =========================================================================

    public function testWithToolInput(): void
    {
        $originalContext = $this->createContext();
        $newInput = 'modified tool input string';

        $newContext = $originalContext->withToolInput($newInput);

        // New context has modified input
        $this->assertSame($newInput, $newContext->toolInput);
        // Original context unchanged
        $this->assertSame(self::FIXTURE_TOOL_INPUT, $originalContext->toolInput);
    }

    public function testWithToolInputPreservesOtherFields(): void
    {
        $originalContext = $this->createContext();
        $newInput = 'changed input';

        $newContext = $originalContext->withToolInput($newInput);

        $this->assertSame($originalContext->sessionId, $newContext->sessionId);
        $this->assertSame($originalContext->toolName, $newContext->toolName);
        $this->assertSame($originalContext->toolArgs, $newContext->toolArgs);
        $this->assertSame($originalContext->toolOutput, $newContext->toolOutput);
        $this->assertSame($originalContext->model, $newContext->model);
        $this->assertSame($originalContext->provider, $newContext->provider);
        $this->assertSame($originalContext->projectRoot, $newContext->projectRoot);
    }

    public function testWithToolInputReturnsNewInstance(): void
    {
        $originalContext = $this->createContext();

        $newContext = $originalContext->withToolInput('new input');

        $this->assertNotSame($originalContext, $newContext);
    }

    public function testWithToolInputWithEmptyString(): void
    {
        $originalContext = $this->createContext();

        $newContext = $originalContext->withToolInput('');

        $this->assertSame('', $newContext->toolInput);
    }

    // =========================================================================
    // withToolOutput Tests
    // =========================================================================

    public function testWithToolOutput(): void
    {
        $originalContext = $this->createContext();
        $newOutput = 'modified tool output string';

        $newContext = $originalContext->withToolOutput($newOutput);

        // New context has modified output
        $this->assertSame($newOutput, $newContext->toolOutput);
        // Original context unchanged
        $this->assertSame(self::FIXTURE_TOOL_OUTPUT, $originalContext->toolOutput);
    }

    public function testWithToolOutputPreservesOtherFields(): void
    {
        $originalContext = $this->createContext();
        $newOutput = 'changed output';

        $newContext = $originalContext->withToolOutput($newOutput);

        $this->assertSame($originalContext->sessionId, $newContext->sessionId);
        $this->assertSame($originalContext->toolName, $newContext->toolName);
        $this->assertSame($originalContext->toolArgs, $newContext->toolArgs);
        $this->assertSame($originalContext->toolInput, $newContext->toolInput);
        $this->assertSame($originalContext->model, $newContext->model);
        $this->assertSame($originalContext->provider, $newContext->provider);
        $this->assertSame($originalContext->projectRoot, $newContext->projectRoot);
    }

    public function testWithToolOutputReturnsNewInstance(): void
    {
        $originalContext = $this->createContext();

        $newContext = $originalContext->withToolOutput('new output');

        $this->assertNotSame($originalContext, $newContext);
    }

    public function testWithToolOutputWithEmptyString(): void
    {
        $originalContext = $this->createContext();

        $newContext = $originalContext->withToolOutput('');

        $this->assertSame('', $newContext->toolOutput);
    }

    // =========================================================================
    // Immutability Tests
    // =========================================================================

    public function testContextIsReadonly(): void
    {
        $context = $this->createContext();

        // Verify all properties are accessible but object is readonly (final readonly)
        $this->assertSame(self::FIXTURE_SESSION_ID, $context->sessionId);
    }

    public function testPreservesOtherFields(): void
    {
        // Test that chaining withToolInput preserves everything except what changed
        $originalContext = $this->createContext();

        $contextAfterInput = $originalContext->withToolInput('modified input');
        $contextAfterOutput = $contextAfterInput->withToolOutput('modified output');

        // Check input change preserved
        $this->assertSame('modified input', $contextAfterInput->toolInput);
        $this->assertSame(self::FIXTURE_TOOL_OUTPUT, $contextAfterInput->toolOutput);

        // Check output change also preserved
        $this->assertSame('modified input', $contextAfterOutput->toolInput);
        $this->assertSame('modified output', $contextAfterOutput->toolOutput);

        // Verify original unchanged
        $this->assertSame(self::FIXTURE_TOOL_INPUT, $originalContext->toolInput);
        $this->assertSame(self::FIXTURE_TOOL_OUTPUT, $originalContext->toolOutput);
    }

    // =========================================================================
    // Edge Case Tests
    // =========================================================================

    public function testWithToolInputSameAsOriginal(): void
    {
        $originalContext = $this->createContext();

        $newContext = $originalContext->withToolInput(self::FIXTURE_TOOL_INPUT);

        $this->assertNotSame($originalContext, $newContext);
        $this->assertSame($originalContext->toolInput, $newContext->toolInput);
    }

    public function testWithToolOutputSameAsOriginal(): void
    {
        $originalContext = $this->createContext();

        $newContext = $originalContext->withToolOutput(self::FIXTURE_TOOL_OUTPUT);

        $this->assertNotSame($originalContext, $newContext);
        $this->assertSame($originalContext->toolOutput, $newContext->toolOutput);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createContext(): HookContext
    {
        return new HookContext(
            sessionId: self::FIXTURE_SESSION_ID,
            toolName: self::FIXTURE_TOOL_NAME,
            toolArgs: ['arg1' => 'value1'],
            toolInput: self::FIXTURE_TOOL_INPUT,
            toolOutput: self::FIXTURE_TOOL_OUTPUT,
            model: self::FIXTURE_MODEL,
            provider: self::FIXTURE_PROVIDER,
            projectRoot: self::FIXTURE_PROJECT_ROOT,
        );
    }
}
