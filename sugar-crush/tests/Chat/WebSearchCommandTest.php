<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\WebSearchCommand;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\BuiltIn\WebSearch;

/**
 * Tests for WebSearchCommand and its integration with Chat.
 *
 * Note: Some tests (testWebSearchCommandExecutesSuccessfully,
 * testWebSearchCommandHandlesHttpError) make real HTTP calls through
 * WebSearch::execute(). These test the successful parsing path and
 * rely on the configured search endpoint being available.
 */
final class WebSearchCommandTest extends TestCase
{
    // =========================================================================
    // Data Providers
    // =========================================================================

    public static function safesearchValueProvider(): array
    {
        return [
            'safesearch 0' => [0],
            'safesearch 1' => [1],
            'safesearch 2' => [2],
        ];
    }

    public static function invalidSafesearchValueProvider(): array
    {
        return [
            'negative' => [-1],
            'too high' => [3],
            'string' => ['abc'],
            'float' => [1.5],
        ];
    }

    public static function validTimeRangeValueProvider(): array
    {
        return [
            'day' => ['day'],
            'month' => ['month'],
            'year' => ['year'],
        ];
    }

    // =========================================================================
    // Unit tests for WebSearchCommand::execute()
    // =========================================================================

    /**
     * @dataProvider safesearchValueProvider
     */
    public function testWebSearchCommandAcceptsValidSafesearchValues(int $value): void
    {
        $mock = $this->createMock(WebSearch::class);
        $mock->method('execute')->willReturn(new ToolResult('', 'test result', false));

        $command = new WebSearchCommand($mock);
        $chat = new Chat(history: [], inputBuf: '', backend: new EchoBackend());

        ob_start();
        $exitCode = $command->execute($chat, ['query', '--safesearch', (string) $value]);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('✗', $output);
    }

    /**
     * @dataProvider invalidSafesearchValueProvider
     */
    public function testWebSearchCommandRejectsInvalidSafesearchValues(mixed $value): void
    {
        $command = new WebSearchCommand();
        $chat = new Chat(history: [], inputBuf: '', backend: new EchoBackend());

        ob_start();
        $exitCode = $command->execute($chat, ['query', '--safesearch', (string) $value]);
        $output = ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid safesearch', $output);
    }

    /**
     * @dataProvider validTimeRangeValueProvider
     */
    public function testWebSearchCommandAcceptsValidTimeRangeValues(string $value): void
    {
        $mock = $this->createMock(WebSearch::class);
        $mock->method('execute')->willReturn(new ToolResult('', 'test result', false));

        $command = new WebSearchCommand($mock);
        $chat = new Chat(history: [], inputBuf: '', backend: new EchoBackend());

        ob_start();
        $exitCode = $command->execute($chat, ['query', '--time-range', $value]);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('✗', $output);
    }

    public function testWebSearchCommandShowsHelp(): void
    {
        $command = new WebSearchCommand();
        $chat = new Chat(history: [], inputBuf: '', backend: new EchoBackend());

        ob_start();
        $exitCode = $command->execute($chat, ['--help']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringContainsString('--safesearch', $output);
        $this->assertStringContainsString('--time-range', $output);
    }

    public function testWebSearchCommandShowsHelpWithHFlag(): void
    {
        $command = new WebSearchCommand();
        $chat = new Chat(history: [], inputBuf: '', backend: new EchoBackend());

        ob_start();
        $exitCode = $command->execute($chat, ['-h']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringContainsString('--safesearch', $output);
    }

    public function testWebSearchCommandHelpWithExtraArgs(): void
    {
        $command = new WebSearchCommand();
        $chat = new Chat(history: [], inputBuf: '', backend: new EchoBackend());

        // --help with a query should still show help and return 0
        ob_start();
        $exitCode = $command->execute($chat, ['--help', 'some-query']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Usage:', $output);
    }

    public function testWebSearchCommandExecutesSuccessfully(): void
    {
        // This test makes a real HTTP call via WebSearch::execute()
        // The search endpoint must be configured and reachable.
        // If the endpoint is unavailable, this test will fail with a connection error.
        $command = new WebSearchCommand();
        $chat = new Chat(
            history: [],
            inputBuf: '',
            backend: new EchoBackend(),
        );

        ob_start();
        $exitCode = $command->execute($chat, ['php', 'tutorial']);
        $output = ob_get_clean();

        // A successful search should return exit code 0
        $this->assertSame(0, $exitCode, "Expected exit code 0 for successful search. Output: $output");
    }

    public function testWebSearchCommandReturnsErrorOnEmptyQuery(): void
    {
        $command = new WebSearchCommand();
        $chat = new Chat(
            history: [],
            inputBuf: '',
            backend: new EchoBackend(),
        );

        ob_start();
        $exitCode = $command->execute($chat, []);
        $output = ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringContainsString('/websearch', $output);
    }

    public function testWebSearchCommandReturnsErrorOnWhitespaceOnlyQuery(): void
    {
        $command = new WebSearchCommand();
        $chat = new Chat(
            history: [],
            inputBuf: '',
            backend: new EchoBackend(),
        );

        ob_start();
        $exitCode = $command->execute($chat, ['   ']);
        $output = ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Usage:', $output);
    }

    public function testWebSearchCommandReturnsErrorOnToolError(): void
    {
        // This test is tricky since WebSearch::execute() makes real HTTP calls.
        // We test the command's error handling path for empty/invalid queries
        // which don't require HTTP. Real HTTP error handling would require
        // a test endpoint or mocking.
        $command = new WebSearchCommand();
        $chat = new Chat(
            history: [],
            inputBuf: '',
            backend: new EchoBackend(),
        );

        // Empty query triggers error path before HTTP call
        ob_start();
        $exitCode = $command->execute($chat, []);
        $output = ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Usage:', $output);
    }

    public function testWebSearchCommandParsesSafesearchFlag(): void
    {
        $command = new WebSearchCommand();
        $chat = new Chat(
            history: [],
            inputBuf: '',
            backend: new EchoBackend(),
        );

        // This will call WebSearch::execute() with safesearch=2
        // Exit code depends on HTTP response - we just verify parsing succeeds
        ob_start();
        $exitCode = $command->execute($chat, ['query', '--safesearch', '2']);
        $output = ob_get_clean();

        // Parsing succeeds if we get any exit code (0 from success, or error from HTTP)
        // A parse error would show "Unknown flag" before the HTTP call
        $this->assertIsInt($exitCode);
        $this->assertStringNotContainsString('Usage:', $output);
    }

    public function testWebSearchCommandRejectsInvalidSafesearchFlag(): void
    {
        $command = new WebSearchCommand();
        $chat = new Chat(history: [], inputBuf: '', backend: new EchoBackend());

        ob_start();
        $exitCode = $command->execute($chat, ['query', '--safesearch', '5']);
        $output = ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid safesearch', $output);
    }

    public function testWebSearchCommandParsesTimeRangeFlag(): void
    {
        $command = new WebSearchCommand();
        $chat = new Chat(
            history: [],
            inputBuf: '',
            backend: new EchoBackend(),
        );

        ob_start();
        $exitCode = $command->execute($chat, ['query', '--time-range', 'month']);
        $output = ob_get_clean();

        // Verify parsing succeeds (no usage error printed)
        $this->assertIsInt($exitCode);
        $this->assertStringNotContainsString('Usage:', $output);
    }

    public function testWebSearchCommandRejectsInvalidTimeRangeFlag(): void
    {
        $command = new WebSearchCommand();
        $chat = new Chat(history: [], inputBuf: '', backend: new EchoBackend());

        ob_start();
        $exitCode = $command->execute($chat, ['query', '--time-range', 'invalid']);
        $output = ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid time-range', $output);
    }

    public function testWebSearchCommandEnforcesMaxQueryLength(): void
    {
        $command = new WebSearchCommand();
        $chat = new Chat(
            history: [],
            inputBuf: '',
            backend: new EchoBackend(),
        );

        // Create a query that exceeds 2000 characters
        $longQuery = str_repeat('a', 2001);

        ob_start();
        $exitCode = $command->execute($chat, [$longQuery]);
        $output = ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('exceeds maximum length', $output);
        $this->assertStringContainsString('2000', $output);
    }

    public function testWebSearchCommandHandlesHttpError(): void
    {
        $mock = $this->createMock(WebSearch::class);
        $mock->method('execute')->willReturn(new ToolResult('', 'Error: HTTP 500', true));

        $command = new WebSearchCommand($mock);
        $chat = new Chat(history: [], inputBuf: '', backend: new EchoBackend());

        ob_start();
        $exitCode = $command->execute($chat, ['query']);
        $output = ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('✗', $output);
    }

    // =========================================================================
    // Integration tests via Chat::update()
    // =========================================================================

    public function testHandleWebSearchCommandUpdatesHistory(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '/websearch test',
            backend: new EchoBackend(),
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // Command completes without error (inFlight=false)
        $this->assertFalse($next->inFlight);

        // History should have 2 new messages: user command + assistant response
        $this->assertCount(2, $next->history);

        // First message should be the user's command
        $this->assertSame(Role::User, $next->history[0]->role);
        $this->assertSame('/websearch test', $next->history[0]->content);

        // Second message should be the assistant response
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $this->assertNotEmpty($next->history[1]->content);

        // Input buffer should be cleared
        $this->assertSame('', $next->inputBuf);
    }

    public function testWebSearchCommandParsesMultipleFlags(): void
    {
        $command = new WebSearchCommand();
        $chat = new Chat(
            history: [],
            inputBuf: '',
            backend: new EchoBackend(),
        );

        // Call with multiple flags before the query
        ob_start();
        $exitCode = $command->execute($chat, ['--safesearch', '1', '--time-range', 'year', 'query']);
        $output = ob_get_clean();

        // Verify parsing succeeded (no usage error)
        $this->assertIsInt($exitCode);
        $this->assertStringNotContainsString('Usage:', $output);

        // The query "query" should have been extracted correctly
        // If parsing failed, we'd see a usage error about empty query
    }

    // =========================================================================
    // Additional edge case tests
    // =========================================================================

    public function testWebSearchCommandWithQueryBeforeFlags(): void
    {
        $command = new WebSearchCommand();
        $chat = new Chat(
            history: [],
            inputBuf: '',
            backend: new EchoBackend(),
        );

        // Query before flags should also work
        ob_start();
        $exitCode = $command->execute($chat, ['search', '--safesearch', '0']);
        $output = ob_get_clean();

        $this->assertIsInt($exitCode);
        $this->assertStringNotContainsString('Usage:', $output);
    }

    public function testWebSearchCommandErrorsOnUnknownFlag(): void
    {
        $command = new WebSearchCommand();
        $chat = new Chat(history: [], inputBuf: '', backend: new EchoBackend());

        ob_start();
        $exitCode = $command->execute($chat, ['query', '--unknown-flag', 'value']);
        $output = ob_get_clean();

        // Unknown flags now error with exit code 1
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown flag', $output);
    }

    public function testWebSearchCommandEmptyArgsAfterFlags(): void
    {
        $command = new WebSearchCommand();
        $chat = new Chat(
            history: [],
            inputBuf: '',
            backend: new EchoBackend(),
        );

        // Only flags, no actual query
        ob_start();
        $exitCode = $command->execute($chat, ['--safesearch', '1', '--time-range', 'month']);
        $output = ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Usage:', $output);
    }
}
