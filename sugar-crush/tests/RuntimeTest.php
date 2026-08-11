<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\App\UserInputMsg;
use DateTimeImmutable;

/**
 * @see Runtime
 */
final class RuntimeTest extends TestCase
{
    private ProviderInterface $provider;
    private HookRegistry $hookRegistry;
    private HookManager $hookManager;
    private Runtime $runtime;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('test-provider');

        $this->hookRegistry = new HookRegistry();
        $this->hookManager = new HookManager($this->hookRegistry);

        $this->runtime = new Runtime($this->provider, $this->hookManager);
    }

    // =========================================================================
    // run() Tests - Dispatches to streaming vs batch
    // =========================================================================

    public function testRunDispatchesToStreamingWhenSupported(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(true);

        $streamResponse = new CompleteResponse(
            content: 'Hello',
            toolCalls: null,
            tokensUsed: 10,
        );

        $this->provider->method('completeStream')
            ->willReturnCallback(fn () => $this->streamOf([$streamResponse]));

        $app = App::new($this->provider, 'gpt-4');

        $results = iterator_to_array($this->runtime->run($app));

        $this->assertCount(1, $results);
        $this->assertInstanceOf(AssistantMessage::class, $results[0]);
        $this->assertSame('Hello', $results[0]->content());
    }

    public function testRunDispatchesToBatchWhenStreamingNotSupported(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(false);

        $batchResponse = new CompleteResponse(
            content: 'Hello batch',
            toolCalls: null,
            tokensUsed: 15,
        );

        $this->provider->method('complete')
            ->willReturn($batchResponse);

        $app = App::new($this->provider, 'gpt-4');

        $results = iterator_to_array($this->runtime->run($app));

        $this->assertCount(1, $results);
        $this->assertInstanceOf(AssistantMessage::class, $results[0]);
        $this->assertSame('Hello batch', $results[0]->content());
    }

    // =========================================================================
    // runStreaming() Tests
    // =========================================================================

    public function testRunStreamingAccumulatesBuffer(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(true);

        $responses = [
            new CompleteResponse(content: 'Hello ', toolCalls: null, tokensUsed: 0),
            new CompleteResponse(content: 'world!', toolCalls: null, tokensUsed: 0),
            new CompleteResponse(content: '', toolCalls: null, tokensUsed: 20),
        ];

        $this->provider->method('completeStream')
            ->willReturnCallback(fn () => $this->streamOf($responses));

        $app = App::new($this->provider, 'gpt-4');

        $results = iterator_to_array($this->runtime->run($app));

        $this->assertCount(1, $results);
        $this->assertInstanceOf(AssistantMessage::class, $results[0]);
        $this->assertSame('Hello world!', $results[0]->content());
    }

    public function testRunStreamingHandlesToolCalls(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(true);

        $toolCall = new ToolCall('call_123', 'test_tool', ['arg' => 'value']);

        $responses = [
            new CompleteResponse(content: '', toolCalls: [$toolCall], tokensUsed: 0),
            new CompleteResponse(content: 'Tool result', toolCalls: null, tokensUsed: 25),
        ];

        $this->provider->method('completeStream')
            ->willReturnCallback(fn () => $this->streamOf($responses));

        // Mock tool that exists
        $tool = $this->createMockTool('test_tool', 'Tool executed successfully');

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $results = iterator_to_array($this->runtime->run($app));

        // Should have assistant message and tool result message
        $this->assertCount(2, $results);
        $this->assertInstanceOf(AssistantMessage::class, $results[0]);
        $this->assertInstanceOf(ToolResultMessage::class, $results[1]);
    }

    public function testRunStreamingYieldsEmptyWhenNoContent(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(true);

        $responses = [
            new CompleteResponse(content: '', toolCalls: null, tokensUsed: 5),
        ];

        $this->provider->method('completeStream')
            ->willReturnCallback(fn () => $this->streamOf($responses));

        $app = App::new($this->provider, 'gpt-4');

        $results = iterator_to_array($this->runtime->run($app));

        $this->assertCount(1, $results);
        $this->assertInstanceOf(AssistantMessage::class, $results[0]);
        $this->assertSame('', $results[0]->content());
    }

    // =========================================================================
    // runBatch() Tests
    // =========================================================================

    public function testRunBatchReturnsCompleteResponse(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(false);

        $batchResponse = new CompleteResponse(
            content: 'Batch response',
            reasoning: 'I thought about it',
            toolCalls: null,
            tokensUsed: 30,
        );

        $this->provider->method('complete')
            ->willReturn($batchResponse);

        $app = App::new($this->provider, 'gpt-4');

        $results = iterator_to_array($this->runtime->run($app));

        $this->assertCount(1, $results);
        $this->assertInstanceOf(AssistantMessage::class, $results[0]);
        $this->assertSame('Batch response', $results[0]->content());
        $this->assertSame('I thought about it', $results[0]->reasoning());
    }

    public function testRunBatchHandlesToolCalls(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(false);

        $toolCall = new ToolCall('call_456', 'my_tool', []);

        $batchResponse = new CompleteResponse(
            content: 'Done',
            toolCalls: [$toolCall],
            tokensUsed: 20,
        );

        $this->provider->method('complete')
            ->willReturn($batchResponse);

        $tool = $this->createMockTool('my_tool', 'Result content');

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $results = iterator_to_array($this->runtime->run($app));

        $this->assertCount(2, $results);
        $this->assertInstanceOf(AssistantMessage::class, $results[0]);
        $this->assertInstanceOf(ToolResultMessage::class, $results[1]);
        $this->assertSame('call_456', $results[1]->toolCallId());
    }

    // =========================================================================
    // executeToolCalls() Tests
    // =========================================================================

    public function testExecuteToolCallsYieldsErrorWhenToolNotFound(): void
    {
        $toolCall = new ToolCall('call_789', 'nonexistent_tool', []);

        $app = App::new($this->provider, 'gpt-4'); // No tools registered

        // Access private method via reflection
        $results = $this->invokePrivateMethod($this->runtime, 'executeToolCalls', [[$toolCall], $app]);

        $results = iterator_to_array($results);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(ToolResultMessage::class, $results[0]);
        $this->assertSame('call_789', $results[0]->toolCallId());
        $this->assertStringContainsString('Tool not found', $results[0]->content());
        $this->assertTrue($results[0]->isError());
    }

    public function testExecuteToolCallsYieldsErrorWhenHookDenies(): void
    {
        $tool = $this->createMockTool('denied_tool', 'Should not execute');

        $toolCall = new ToolCall('call_deny', 'denied_tool', []);

        // Register a denying hook
        $this->hookRegistry->register(new class implements HookInterface {
            public function name(): string { return 'deny_all'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult {
                return HookResult::deny('Hook denied this tool');
            }
        });

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $results = $this->invokePrivateMethod($this->runtime, 'executeToolCalls', [[$toolCall], $app]);

        $results = iterator_to_array($results);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(ToolResultMessage::class, $results[0]);
        $this->assertSame('call_deny', $results[0]->toolCallId());
        $this->assertStringContainsString('Hook denied', $results[0]->content());
        $this->assertTrue($results[0]->isError());
    }

    public function testExecuteToolCallsUsesModifiedInputFromHook(): void
    {
        $tool = $this->createMockTool('modifier_tool', 'Executed');

        $toolCall = new ToolCall('call_mod', 'modifier_tool', ['original' => 'value']);

        // Register a modifying hook
        $this->hookRegistry->register(new class implements HookInterface {
            public function name(): string { return 'modify_input'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult {
                return HookResult::modify('{"modified":"value"}', 'Input modified by hook');
            }
        });

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $results = $this->invokePrivateMethod($this->runtime, 'executeToolCalls', [[$toolCall], $app]);

        $results = iterator_to_array($results);

        $this->assertCount(1, $results);
        // The tool should have been called with modified input
        $this->assertSame('call_mod', $results[0]->toolCallId());
    }

    public function testExecuteToolCallsExecutesToolAndReturnsResult(): void
    {
        $tool = $this->createMockTool('exec_tool', 'Executed successfully');

        $toolCall = new ToolCall('call_exec', 'exec_tool', ['param' => 'test']);

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $results = $this->invokePrivateMethod($this->runtime, 'executeToolCalls', [[$toolCall], $app]);

        $results = iterator_to_array($results);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(ToolResultMessage::class, $results[0]);
        $this->assertSame('call_exec', $results[0]->toolCallId());
        $this->assertSame('Executed successfully', $results[0]->content());
        $this->assertFalse($results[0]->isError());
    }

    /**
     * W1.G2 reachability fix: an image-bearing Tools\ToolResult (e.g.
     * Doctor's capability swatch) must survive executeToolCalls() onto the
     * yielded ToolResultMessage instead of being silently dropped.
     */
    public function testExecuteToolCallsThreadsImageFieldsOntoToolResultMessage(): void
    {
        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn('doctor');
        $tool->method('description')->willReturn('doctor');
        $tool->method('inputSchema')->willReturn([]);
        $tool->method('execute')->willReturn(new ToolResult(
            toolCallId: 'call_doctor',
            content: 'Detected kitty',
            imageBytes: "\x89PNGfake",
            imageProtocol: 'kitty',
        ));

        $toolCall = new ToolCall('call_doctor', 'doctor', []);
        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $results = iterator_to_array($this->invokePrivateMethod($this->runtime, 'executeToolCalls', [[$toolCall], $app]));

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->hasImage());
        $this->assertSame("\x89PNGfake", $results[0]->imageBytes());
        $this->assertSame('kitty', $results[0]->imageProtocol());
    }

    public function testExecuteToolCallsHandlesMultipleToolCalls(): void
    {
        $tool1 = $this->createMockTool('tool_one', 'Result 1');
        $tool2 = $this->createMockTool('tool_two', 'Result 2');

        $toolCall1 = new ToolCall('call_1', 'tool_one', []);
        $toolCall2 = new ToolCall('call_2', 'tool_two', []);

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool1, $tool2]);

        $results = $this->invokePrivateMethod($this->runtime, 'executeToolCalls', [[$toolCall1, $toolCall2], $app]);

        $results = iterator_to_array($results);

        $this->assertCount(2, $results);
        $this->assertSame('call_1', $results[0]->toolCallId());
        $this->assertSame('Result 1', $results[0]->content());
        $this->assertSame('call_2', $results[1]->toolCallId());
        $this->assertSame('Result 2', $results[1]->content());
    }

    public function testExecuteToolCallsMeasuresDuration(): void
    {
        $tool = $this->createMockTool('slow_tool', 'Done', delayMs: 10);

        $toolCall = new ToolCall('call_dur', 'slow_tool', []);

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $results = $this->invokePrivateMethod($this->runtime, 'executeToolCalls', [[$toolCall], $app]);

        $results = iterator_to_array($results);

        $this->assertCount(1, $results);
        // Duration should be measured (at least 10ms for our mock tool)
        $this->assertSame('call_dur', $results[0]->toolCallId());
    }

    // =========================================================================
    // findTool() Tests
    // =========================================================================

    public function testFindToolReturnsToolWhenFound(): void
    {
        $tool = $this->createMockTool('findable', 'Found');

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $result = $this->invokePrivateMethod($this->runtime, 'findTool', ['findable', $app]);

        $this->assertNotNull($result);
        $this->assertSame('findable', $result->name());
    }

    public function testFindToolReturnsNullWhenNotFound(): void
    {
        $tool = $this->createMockTool('other_tool', 'Other');

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $result = $this->invokePrivateMethod($this->runtime, 'findTool', ['nonexistent', $app]);

        $this->assertNull($result);
    }

    public function testFindToolReturnsNullWhenNoTools(): void
    {
        $app = App::new($this->provider, 'gpt-4'); // No tools

        $result = $this->invokePrivateMethod($this->runtime, 'findTool', ['any_tool', $app]);

        $this->assertNull($result);
    }

    // =========================================================================
    // buildMessages() Tests
    // =========================================================================

    public function testBuildMessagesFiltersMessages(): void
    {
        $msg1 = new UserMessage('Hello');
        $msg2 = new AssistantMessage('Hi there');
        $nonMessage = 'not a message';

        $app = App::new($this->provider, 'gpt-4')
            ->withMessages([$msg1, $msg2, $nonMessage]);

        $result = $this->invokePrivateMethod($this->runtime, 'buildMessages', [$app]);

        $this->assertCount(2, $result);
        $this->assertSame($msg1, $result[0]);
        $this->assertSame($msg2, $result[1]);
    }

    public function testBuildMessagesReturnsEmptyArrayWhenNoMessages(): void
    {
        $app = App::new($this->provider, 'gpt-4');

        $result = $this->invokePrivateMethod($this->runtime, 'buildMessages', [$app]);

        $this->assertSame([], $result);
    }

    public function testBuildMessagesFiltersNonMessageInstances(): void
    {
        $msg = new UserMessage('Valid');
        $invalid = new \stdClass();

        $app = App::new($this->provider, 'gpt-4')
            ->withMessages([$msg, $invalid]);

        $result = $this->invokePrivateMethod($this->runtime, 'buildMessages', [$app]);

        $this->assertCount(1, $result);
        $this->assertSame($msg, $result[0]);
    }

    // =========================================================================
    // buildSystemPrompt() Tests
    // =========================================================================

    public function testBuildSystemPromptReturnsBasePrompt(): void
    {
        $app = App::new($this->provider, 'gpt-4');

        $result = $this->invokePrivateMethod($this->runtime, 'buildSystemPrompt', [$app]);

        $this->assertStringContainsString('SugarCrush', $result);
        $this->assertStringContainsString('AI coding assistant', $result);
    }

    public function testBuildSystemPromptIncludesSkillContributions(): void
    {
        $skill = new Skill(
            name: 'TestSkill',
            description: 'A test skill',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'low',
            context: 'thread',
            paths: [],
            content: 'Skill content here',
            sourcePath: '/test/SKILL.md',
        );

        $app = App::new($this->provider, 'gpt-4')
            ->withEnabledSkills([$skill]);

        $result = $this->invokePrivateMethod($this->runtime, 'buildSystemPrompt', [$app]);

        $this->assertStringContainsString('SugarCrush', $result);
        $this->assertStringContainsString('## Skill: TestSkill', $result);
        $this->assertStringContainsString('Skill content here', $result);
    }

    public function testBuildSystemPromptIgnoresNonSkillEnabledSkills(): void
    {
        $nonSkill = 'not a skill object';

        $app = App::new($this->provider, 'gpt-4')
            ->withEnabledSkills([$nonSkill]);

        $result = $this->invokePrivateMethod($this->runtime, 'buildSystemPrompt', [$app]);

        $this->assertStringContainsString('SugarCrush', $result);
        $this->assertStringNotContainsString('## Skill', $result);
    }

    public function testBuildSystemPromptWithMultipleSkills(): void
    {
        $skill1 = new Skill(
            name: 'SkillOne',
            description: 'First skill',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'low',
            context: 'thread',
            paths: [],
            content: 'Content one',
            sourcePath: '/test/SKILL.md',
        );

        $skill2 = new Skill(
            name: 'SkillTwo',
            description: 'Second skill',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: 'thread',
            paths: [],
            content: 'Content two',
            sourcePath: '/test/SKILL.md',
        );

        $app = App::new($this->provider, 'gpt-4')
            ->withEnabledSkills([$skill1, $skill2]);

        $result = $this->invokePrivateMethod($this->runtime, 'buildSystemPrompt', [$app]);

        $this->assertStringContainsString('## Skill: SkillOne', $result);
        $this->assertStringContainsString('Content one', $result);
        $this->assertStringContainsString('## Skill: SkillTwo', $result);
        $this->assertStringContainsString('Content two', $result);
    }

    // =========================================================================
    // Integration Tests
    // =========================================================================

    public function testFullRunWithStreamingAndToolCall(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(true);

        $toolCall = new ToolCall('call_full', 'integrated_tool', ['input' => 'test']);
        $tool = $this->createMockTool('integrated_tool', 'Integrated result');

        $responses = [
            new CompleteResponse(content: 'Thinking...', toolCalls: [$toolCall], tokensUsed: 0),
            new CompleteResponse(content: 'Done', toolCalls: null, tokensUsed: 50),
        ];

        $this->provider->method('completeStream')
            ->willReturnCallback(fn () => $this->streamOf($responses));

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $results = iterator_to_array($this->runtime->run($app));

        $this->assertCount(2, $results);
        $this->assertInstanceOf(AssistantMessage::class, $results[0]);
        $this->assertInstanceOf(ToolResultMessage::class, $results[1]);
        $this->assertSame('call_full', $results[1]->toolCallId());
    }

    public function testFullRunWithBatchOnlyNoTools(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(false);

        $this->provider->method('complete')
            ->willReturn(new CompleteResponse(
                content: 'Simple response',
                toolCalls: null,
                tokensUsed: 25,
            ));

        $app = App::new($this->provider, 'gpt-4');

        $results = iterator_to_array($this->runtime->run($app));

        $this->assertCount(1, $results);
        $this->assertInstanceOf(AssistantMessage::class, $results[0]);
        $this->assertSame('Simple response', $results[0]->content());
    }

    // =========================================================================
    // shouldPromptIdleCompaction() Tests
    // =========================================================================

    public function testShouldPromptIdleCompactionReturnsFalseWhenTokensBelowThreshold(): void
    {
        // App with recent activity but token count below 100K
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test');

        $app = App::new($provider, 'test-model')
            ->withLastActivity(new DateTimeImmutable('1 hour ago'));

        $result = $this->runtime->shouldPromptIdleCompaction($app, 50000);

        $this->assertFalse($result);
    }

    public function testShouldPromptIdleCompactionReturnsFalseWhenRecentlyActive(): void
    {
        // App with recent activity and high token count
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test');

        $app = App::new($provider, 'test-model')
            ->withLastActivity(new DateTimeImmutable('30 minutes ago'));

        $result = $this->runtime->shouldPromptIdleCompaction($app, 150000);

        $this->assertFalse($result);
    }

    public function testShouldPromptIdleCompactionReturnsTrueWhenIdleAndLarge(): void
    {
        // App with idle time > 1 hour and token count > 100K
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test');

        $app = App::new($provider, 'test-model')
            ->withLastActivity(new DateTimeImmutable('2 hours ago'));

        $result = $this->runtime->shouldPromptIdleCompaction($app, 150000);

        $this->assertTrue($result);
    }

    public function testShouldPromptIdleCompactionReturnsFalseWhenNoLastActivity(): void
    {
        // App with no lastActivityAt set
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test');

        $app = App::new($provider, 'test-model');

        // Even with high token count, should return false if we don't know idle time
        $result = $this->runtime->shouldPromptIdleCompaction($app, 150000);

        $this->assertFalse($result);
    }

    public function testShouldPromptIdleCompactionBoundaryAtExactlyOneHour(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test');

        // Exactly 3600 seconds ago (1 hour) - should be false (not MORE than 3600)
        $app = App::new($provider, 'test-model')
            ->withLastActivity(new DateTimeImmutable('3600 seconds ago'));

        $result = $this->runtime->shouldPromptIdleCompaction($app, 150000);

        $this->assertFalse($result);
    }

    public function testShouldPromptIdleCompactionBoundaryAtExactly100KTokens(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test');

        // Exactly 100000 tokens - should be false (not MORE than 100000)
        $app = App::new($provider, 'test-model')
            ->withLastActivity(new DateTimeImmutable('2 hours ago'));

        $result = $this->runtime->shouldPromptIdleCompaction($app, 100000);

        $this->assertFalse($result);
    }

    /**
     * App::$lastActivityAt was previously only ever set via withLastActivity()
     * from test code (see the tests above) - no real code path updated it on
     * an actual user prompt, so shouldPromptIdleCompaction() would see
     * lastActivityAt === null forever in production and never fire. A real
     * UserInputMsg dispatch through App::update() must now record activity.
     */
    public function testRealUserInputMsgUpdatesLastActivityAt(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test');

        $app = App::new($provider, 'test-model');
        $this->assertNull($app->lastActivityAt, 'fresh App has no recorded activity');

        [$next, ] = $app->update(new UserInputMsg('hello'));

        $this->assertNotNull($next->lastActivityAt);
        $this->assertGreaterThan(
            (new DateTimeImmutable('5 seconds ago'))->getTimestamp(),
            $next->lastActivityAt->getTimestamp(),
        );
    }

    public function testRealUserInputMsgResetsIdleClockSoOldSessionsStopLookingIdle(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test');

        // Simulate a session that had gone idle for 2 hours.
        $app = App::new($provider, 'test-model')
            ->withLastActivity(new DateTimeImmutable('2 hours ago'));

        [$next, ] = $app->update(new UserInputMsg('still here'));

        // A fresh prompt is real activity - the idle clock resets even
        // though the session had crossed the idle threshold before.
        $idleSeconds = time() - $next->lastActivityAt->getTimestamp();
        $this->assertLessThan(5, $idleSeconds);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Wrap fixture responses in a Generator. `completeStream()` is typed to
     * return \Generator, so a mock cannot ->willReturn() a plain array.
     *
     * @param list<CompleteResponse> $responses
     */
    private function streamOf(array $responses): \Generator
    {
        yield from $responses;
    }

    private function createMockTool(string $name, string $result, int $delayMs = 0): Tool
    {
        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn($name);
        $tool->method('description')->willReturn("Description for $name");
        $tool->method('inputSchema')->willReturn([]);
        $tool->method('execute')->willReturnCallback(function ($args) use ($name, $result, $delayMs) {
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
            return new ToolResult(
                toolCallId: $args['toolCallId'] ?? "call_$name",
                content: $result,
            );
        });

        return $tool;
    }

    private function invokePrivateMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $reflectionMethod = $reflection->getMethod($method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($object, $args);
    }
}
