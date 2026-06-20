<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\ClaudeCodeInvocation;
use SugarCraft\Crush\Providers\ClaudeCodeProvider;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;

/**
 * @see ClaudeCodeInvocation
 * @see ClaudeCodeProvider
 */
final class ClaudeCodeProviderTest extends TestCase
{
    // =========================================================================
    // ClaudeCodeInvocation Constructor Tests
    // =========================================================================

    public function testClaudeCodeInvocationConstructorWithDefaults(): void
    {
        $invocation = new ClaudeCodeInvocation();

        $this->assertSame('claude', $invocation->claudePath());
        $this->assertSame('~/.claude', $invocation->configDir());
        $this->assertNull($invocation->sessionId());
    }

    public function testClaudeCodeInvocationConstructorWithCustomValues(): void
    {
        $invocation = new ClaudeCodeInvocation(
            claudePath: '/usr/local/bin/claude-code',
            configDir: '/etc/claude',
            sessionId: 'test-session-123',
        );

        $this->assertSame('/usr/local/bin/claude-code', $invocation->claudePath());
        $this->assertSame('/etc/claude', $invocation->configDir());
        $this->assertSame('test-session-123', $invocation->sessionId());
    }

    public function testClaudeCodeInvocationConstructorWithOnlySessionId(): void
    {
        $invocation = new ClaudeCodeInvocation(
            sessionId: 'my-session',
        );

        $this->assertSame('claude', $invocation->claudePath());
        $this->assertSame('~/.claude', $invocation->configDir());
        $this->assertSame('my-session', $invocation->sessionId());
    }

    // =========================================================================
    // ClaudeCodeInvocation Method Tests - claudePath()
    // =========================================================================

    public function testClaudeCodeInvocationClaudePathReturnsCorrectPath(): void
    {
        $invocation = new ClaudeCodeInvocation(claudePath: '/custom/path/claude');
        $this->assertSame('/custom/path/claude', $invocation->claudePath());
    }

    public function testClaudeCodeInvocationClaudePathWithDefault(): void
    {
        $invocation = new ClaudeCodeInvocation();
        $this->assertSame('claude', $invocation->claudePath());
    }

    // =========================================================================
    // ClaudeCodeInvocation Method Tests - configDir()
    // =========================================================================

    public function testClaudeCodeInvocationConfigDirReturnsCorrectDir(): void
    {
        $invocation = new ClaudeCodeInvocation(configDir: '/home/user/.config/claude');
        $this->assertSame('/home/user/.config/claude', $invocation->configDir());
    }

    public function testClaudeCodeInvocationConfigDirWithDefault(): void
    {
        $invocation = new ClaudeCodeInvocation();
        $this->assertSame('~/.claude', $invocation->configDir());
    }

    // =========================================================================
    // ClaudeCodeInvocation Method Tests - sessionId()
    // =========================================================================

    public function testClaudeCodeInvocationSessionIdReturnsNullWhenNotSet(): void
    {
        $invocation = new ClaudeCodeInvocation();
        $this->assertNull($invocation->sessionId());
    }

    public function testClaudeCodeInvocationSessionIdReturnsSessionIdWhenSet(): void
    {
        $invocation = new ClaudeCodeInvocation(sessionId: 'session-abc-123');
        $this->assertSame('session-abc-123', $invocation->sessionId());
    }

    public function testClaudeCodeInvocationSessionIdWithEmptyString(): void
    {
        // Empty string is not null, so it will be treated as a valid session ID
        $invocation = new ClaudeCodeInvocation(sessionId: '');
        $this->assertSame('', $invocation->sessionId());
        $this->assertNotNull($invocation->sessionId());
    }

    // =========================================================================
    // ClaudeCodeInvocation Method Tests - baseArgs()
    // =========================================================================

    public function testClaudeCodeInvocationBaseArgsReturnsCorrectBaseArgs(): void
    {
        $invocation = new ClaudeCodeInvocation();
        $args = $invocation->baseArgs();

        $this->assertIsArray($args);
        $this->assertContains('--output-format', $args);
        $this->assertContains('json', $args);
    }

    public function testClaudeCodeInvocationBaseArgsWithSessionIdIncludesResume(): void
    {
        $invocation = new ClaudeCodeInvocation(sessionId: 'test-session');
        $args = $invocation->baseArgs();

        $this->assertIsArray($args);
        $this->assertContains('--resume', $args);
        $this->assertContains('test-session', $args);
    }

    public function testClaudeCodeInvocationBaseArgsWithoutSessionIdDoesNotIncludeResume(): void
    {
        $invocation = new ClaudeCodeInvocation();
        $args = $invocation->baseArgs();

        $this->assertNotContains('--resume', $args);
    }

    public function testClaudeCodeInvocationBaseArgsWithSessionIdInCorrectOrder(): void
    {
        $invocation = new ClaudeCodeInvocation(sessionId: 'my-session-id');
        $args = $invocation->baseArgs();

        // --output-format json should come first
        $this->assertSame('--output-format', $args[0]);
        $this->assertSame('json', $args[1]);
        // --resume and sessionId should follow
        $resumeIndex = array_search('--resume', $args);
        $this->assertNotFalse($resumeIndex);
        $this->assertSame('my-session-id', $args[$resumeIndex + 1]);
    }

    // =========================================================================
    // ClaudeCodeInvocation Method Tests - printModeArgs()
    // =========================================================================

    public function testClaudeCodeInvocationPrintModeArgsBuildsCorrectArgsForBasicPrompt(): void
    {
        $invocation = new ClaudeCodeInvocation();
        $args = $invocation->printModeArgs('Hello, world!');

        $this->assertIsArray($args);
        $this->assertContains('-p', $args);
        $this->assertContains('Hello, world!', $args);
        $this->assertContains('--output-format', $args);
        $this->assertContains('json', $args);
    }

    public function testClaudeCodeInvocationPrintModeArgsIncludesBareWhenOptionSet(): void
    {
        $invocation = new ClaudeCodeInvocation();
        $args = $invocation->printModeArgs('Test prompt', ['bare' => true]);

        $this->assertContains('--bare', $args);
    }

    public function testClaudeCodeInvocationPrintModeArgsDoesNotIncludeBareWhenNotSet(): void
    {
        $invocation = new ClaudeCodeInvocation();
        $args = $invocation->printModeArgs('Test prompt');

        $this->assertNotContains('--bare', $args);
    }

    public function testClaudeCodeInvocationPrintModeArgsIncludesAllowedToolsWhenOptionSet(): void
    {
        $invocation = new ClaudeCodeInvocation();
        $args = $invocation->printModeArgs('Test prompt', ['allowedTools' => 'Read,Write,Edit']);

        $this->assertContains('--allowedTools', $args);
        $this->assertContains('Read,Write,Edit', $args);
    }

    public function testClaudeCodeInvocationPrintModeArgsDoesNotIncludeAllowedToolsWhenNotSet(): void
    {
        $invocation = new ClaudeCodeInvocation();
        $args = $invocation->printModeArgs('Test prompt');

        $this->assertNotContains('--allowedTools', $args);
    }

    public function testClaudeCodeInvocationPrintModeArgsWithContinueOption(): void
    {
        $invocation = new ClaudeCodeInvocation();
        $args = $invocation->printModeArgs('Continue my work', ['continue' => true]);

        $this->assertContains('--continue', $args);
    }

    public function testClaudeCodeInvocationPrintModeArgsWithSystemPromptOption(): void
    {
        $invocation = new ClaudeCodeInvocation();
        $args = $invocation->printModeArgs('Prompt', ['systemPrompt' => 'You are helpful']);

        $this->assertContains('--system-prompt', $args);
        $this->assertContains('You are helpful', $args);
    }

    public function testClaudeCodeInvocationPrintModeArgsWithMaxBudgetUsdOption(): void
    {
        $invocation = new ClaudeCodeInvocation();
        $args = $invocation->printModeArgs('Prompt', ['maxBudgetUsd' => 5.50]);

        $this->assertContains('--max-budget-usd', $args);
        $this->assertContains('5.5', $args);
    }

    public function testClaudeCodeInvocationPrintModeArgsWithMaxTurnsOption(): void
    {
        $invocation = new ClaudeCodeInvocation();
        $args = $invocation->printModeArgs('Prompt', ['maxTurns' => 10]);

        $this->assertContains('--max-turns', $args);
        $this->assertContains('10', $args);
    }

    public function testClaudeCodeInvocationPrintModeArgsWithPermissionModeOption(): void
    {
        $invocation = new ClaudeCodeInvocation();
        $args = $invocation->printModeArgs('Prompt', ['permissionMode' => 'bypassPermissions']);

        $this->assertContains('--permission-mode', $args);
        $this->assertContains('bypassPermissions', $args);
    }

    public function testClaudeCodeInvocationPrintModeArgsWithCustomFormat(): void
    {
        $invocation = new ClaudeCodeInvocation();
        $args = $invocation->printModeArgs('Prompt', ['format' => 'stream-json']);

        // Find the index of --output-format and check the value after it
        $formatIndex = array_search('--output-format', $args);
        $this->assertNotFalse($formatIndex);
        $this->assertSame('stream-json', $args[$formatIndex + 1]);
    }

    public function testClaudeCodeInvocationPrintModeArgsWithMultipleOptions(): void
    {
        $invocation = new ClaudeCodeInvocation();
        $args = $invocation->printModeArgs('Complex prompt', [
            'bare' => true,
            'allowedTools' => 'Read,Write',
            'systemPrompt' => 'Be concise',
            'maxTurns' => 5,
        ]);

        $this->assertContains('-p', $args);
        $this->assertContains('Complex prompt', $args);
        $this->assertContains('--bare', $args);
        $this->assertContains('--allowedTools', $args);
        $this->assertContains('Read,Write', $args);
        $this->assertContains('--system-prompt', $args);
        $this->assertContains('Be concise', $args);
        $this->assertContains('--max-turns', $args);
        $this->assertContains('5', $args);
    }

    // =========================================================================
    // ClaudeCodeProvider Method Tests - name()
    // =========================================================================

    public function testClaudeCodeProviderNameReturnsClaudeCode(): void
    {
        $provider = new ClaudeCodeProvider(new ClaudeCodeInvocation());
        $this->assertSame('claude-code', $provider->name());
    }

    // =========================================================================
    // ClaudeCodeProvider Method Tests - supportsStreaming()
    // =========================================================================

    public function testClaudeCodeProviderSupportsStreamingReturnsTrue(): void
    {
        $provider = new ClaudeCodeProvider(new ClaudeCodeInvocation());
        $this->assertTrue($provider->supportsStreaming());
    }

    // =========================================================================
    // ClaudeCodeProvider Method Tests - supportsFunctionCalling()
    // =========================================================================

    public function testClaudeCodeProviderSupportsFunctionCallingReturnsTrue(): void
    {
        $provider = new ClaudeCodeProvider(new ClaudeCodeInvocation());
        $this->assertTrue($provider->supportsFunctionCalling());
    }

    // =========================================================================
    // ClaudeCodeProvider Method Tests - supportsVision()
    // =========================================================================

    public function testClaudeCodeProviderSupportsVisionReturnsFalse(): void
    {
        $provider = new ClaudeCodeProvider(new ClaudeCodeInvocation());
        $this->assertFalse($provider->supportsVision());
    }

    // =========================================================================
    // ClaudeCodeProvider Method Tests - supportsJsonSchema()
    // =========================================================================

    public function testClaudeCodeProviderSupportsJsonSchemaReturnsTrue(): void
    {
        $provider = new ClaudeCodeProvider(new ClaudeCodeInvocation());
        $this->assertTrue($provider->supportsJsonSchema());
    }

    // =========================================================================
    // ClaudeCodeProvider Method Tests - contextWindow()
    // =========================================================================

    public function testClaudeCodeProviderContextWindowReturns200000(): void
    {
        $provider = new ClaudeCodeProvider(new ClaudeCodeInvocation());
        $this->assertSame(200_000, $provider->contextWindow());
    }

    public function testClaudeCodeProviderContextWindowWithDifferentModels(): void
    {
        // Test with Sonnet 4-6
        $provider1 = new ClaudeCodeProvider(new ClaudeCodeInvocation(), 'claude-sonnet-4-6');
        $this->assertSame(200_000, $provider1->contextWindow());

        // Test with Opus 4-6
        $provider2 = new ClaudeCodeProvider(new ClaudeCodeInvocation(), 'claude-opus-4-6');
        $this->assertSame(200_000, $provider2->contextWindow());

        // Test with Sonnet 4-7
        $provider3 = new ClaudeCodeProvider(new ClaudeCodeInvocation(), 'claude-sonnet-4-7');
        $this->assertSame(200_000, $provider3->contextWindow());

        // Test with Opus 4-7
        $provider4 = new ClaudeCodeProvider(new ClaudeCodeInvocation(), 'claude-opus-4-7');
        $this->assertSame(200_000, $provider4->contextWindow());

        // Test with Haiku 4-7
        $provider5 = new ClaudeCodeProvider(new ClaudeCodeInvocation(), 'claude-haiku-4-7');
        $this->assertSame(200_000, $provider5->contextWindow());

        // Test with unknown model (should default to 200000)
        $provider6 = new ClaudeCodeProvider(new ClaudeCodeInvocation(), 'unknown-model');
        $this->assertSame(200_000, $provider6->contextWindow());
    }

    // =========================================================================
    // ClaudeCodeProvider Method Tests - costPer1kTokens()
    // =========================================================================

    public function testClaudeCodeProviderCostPer1kTokensReturnsZero(): void
    {
        $provider = new ClaudeCodeProvider(new ClaudeCodeInvocation());

        $this->assertSame(0.0, $provider->costPer1kTokens('claude-sonnet-4-6', 'input'));
        $this->assertSame(0.0, $provider->costPer1kTokens('claude-opus-4-6', 'output'));
        $this->assertSame(0.0, $provider->costPer1kTokens('any-model', 'input'));
        $this->assertSame(0.0, $provider->costPer1kTokens('any-model', 'output'));
    }

    // =========================================================================
    // ClaudeCodeProvider Method Tests - embeddings()
    // =========================================================================

    public function testClaudeCodeProviderEmbeddingsReturnsEmptyEmbeddingsResponse(): void
    {
        $provider = new ClaudeCodeProvider(new ClaudeCodeInvocation());
        $request = new EmbeddingsRequest(
            model: 'claude-sonnet-4-6',
            input: 'Test input',
        );

        $response = $provider->embeddings($request);

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);
        $this->assertIsArray($response->embeddings);
        $this->assertCount(0, $response->embeddings);
    }

    public function testClaudeCodeProviderEmbeddingsReturnsEmptyArrayRegardlessOfInput(): void
    {
        $provider = new ClaudeCodeProvider(new ClaudeCodeInvocation());

        $request1 = new EmbeddingsRequest(
            model: 'claude-sonnet-4-6',
            input: ['First text', 'Second text'],
        );
        $response1 = $provider->embeddings($request1);
        $this->assertSame([], $response1->embeddings);

        $request2 = new EmbeddingsRequest(
            model: 'claude-sonnet-4-6',
            input: str_repeat('Long text ', 100),
        );
        $response2 = $provider->embeddings($request2);
        $this->assertSame([], $response2->embeddings);
    }

    // =========================================================================
    // ClaudeCodeProvider Integration Tests
    // =========================================================================

    public function testClaudeCodeProviderWithCustomInvocation(): void
    {
        $invocation = new ClaudeCodeInvocation(
            claudePath: '/bin/claude-code',
            configDir: '/tmp/claude',
            sessionId: 'custom-session',
        );
        $provider = new ClaudeCodeProvider($invocation, 'claude-opus-4-6');

        $this->assertSame('claude-code', $provider->name());
        $this->assertTrue($provider->supportsStreaming());
        $this->assertTrue($provider->supportsFunctionCalling());
        $this->assertFalse($provider->supportsVision());
        $this->assertTrue($provider->supportsJsonSchema());
        $this->assertSame(200_000, $provider->contextWindow());
        $this->assertSame(0.0, $provider->costPer1kTokens('any-model', 'input'));
    }
}
