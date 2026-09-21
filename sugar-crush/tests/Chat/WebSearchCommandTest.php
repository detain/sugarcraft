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
 * No test here touches the network. Several used to: they constructed a bare
 * `new WebSearchCommand()`, which builds its own {@see WebSearch} against the
 * configured SearXNG endpoint, so the flag-parsing assertions were paid for
 * with a real search and `testWebSearchCommandExecutesSuccessfully` asserted
 * exit 0 from a host that CI cannot be promised. Every test that reaches
 * {@see WebSearch::execute()} now injects a stub instead — the command's own
 * seam, which it has always had — and the ones that stop at argument
 * validation keep the real default because they never get that far.
 */
final class WebSearchCommandTest extends TestCase
{
    /**
     * A WebSearch that answers without a socket.
     *
     * {@see WebSearchCommand} only ever calls `execute()` on its tool, so a
     * plain mock is the whole seam; the tool's own HTTP path is covered in
     * {@see \SugarCraft\Crush\Tests\Tools\WebSearchToolTest}.
     */
    private function stubSearch(string $content = "Search results (1):\n  1. Result", bool $isError = false): WebSearch
    {
        $mock = $this->createMock(WebSearch::class);
        $mock->method('execute')->willReturn(new ToolResult('', $content, $isError));

        return $mock;
    }

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
        $command = new WebSearchCommand($this->stubSearch('Search results (1):' . "\n" . '  1. PHP tutorial'));
        $chat = new Chat(
            history: [],
            inputBuf: '',
            backend: new EchoBackend(),
        );

        ob_start();
        $exitCode = $command->execute($chat, ['php', 'tutorial']);
        $output = ob_get_clean();

        // A successful search should return exit code 0 and print the digest
        // the tool handed back, framed by the command's own rules.
        $this->assertSame(0, $exitCode, "Expected exit code 0 for successful search. Output: $output");
        $this->assertStringContainsString('PHP tutorial', $output);
        $this->assertStringNotContainsString("\u{2717}", $output);
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
        // The empty-query path errors before the tool is reached at all, so
        // this one needs no stub — the tool-error path itself is covered by
        // testWebSearchCommandHandlesHttpError.
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
        $command = new WebSearchCommand($this->stubSearch());
        $chat = new Chat(
            history: [],
            inputBuf: '',
            backend: new EchoBackend(),
        );

        ob_start();
        $exitCode = $command->execute($chat, ['query', '--safesearch', '2']);
        $output = ob_get_clean();

        // Parsing succeeded: the flag was consumed and the query still
        // reached the tool. A parse failure prints usage and never searches.
        $this->assertSame(0, $exitCode);
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
        $command = new WebSearchCommand($this->stubSearch());
        $chat = new Chat(
            history: [],
            inputBuf: '',
            backend: new EchoBackend(),
        );

        ob_start();
        $exitCode = $command->execute($chat, ['query', '--time-range', 'month']);
        $output = ob_get_clean();

        // Verify parsing succeeds (no usage error printed)
        $this->assertSame(0, $exitCode);
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
        // The search tool is injected because the ASSERTION BELOW is about
        // the transcript, not about connectivity: on the failure branch the
        // command's notice lands as Role::System, so an unreachable endpoint
        // used to report itself here as a wiring regression.
        $chat = new Chat(
            history: [],
            inputBuf: '/websearch test',
            backend: new EchoBackend(),
            webSearch: $this->stubSearch(),
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
        $command = new WebSearchCommand($this->stubSearch());
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
        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('Usage:', $output);

        // The query "query" should have been extracted correctly
        // If parsing failed, we'd see a usage error about empty query
    }

    // =========================================================================
    // Additional edge case tests
    // =========================================================================

    public function testWebSearchCommandWithQueryBeforeFlags(): void
    {
        $command = new WebSearchCommand($this->stubSearch());
        $chat = new Chat(
            history: [],
            inputBuf: '',
            backend: new EchoBackend(),
        );

        // Query before flags should also work
        ob_start();
        $exitCode = $command->execute($chat, ['search', '--safesearch', '0']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
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
