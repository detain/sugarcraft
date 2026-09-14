<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\NonInteractive;
use SugarCraft\Crush\Cli\Subcommands;

/**
 * E701: the `sugarcrush mcp auth login` verb gate. Every arm tested here
 * refuses BEFORE the flow is constructed — which is exactly the contract
 * these pins prove: no bad invocation ever reaches socket binding, DNS, or
 * the browser leg. The success wiring (operands reaching the flow intact)
 * lives in {@see \SugarCraft\Crush\Tests\MCP\McpAuthStoreLoginTest}; this
 * file owns the door.
 *
 * Message assertions ride the JSON error document — {@see
 * NonInteractive::failUsage()} sends the SAME message string to stderr and
 * to the envelope, so the fragment is pinned once and the text-mode arms
 * only assert the exit code plus stdout silence (the runner's own stderr is
 * not capturable in-process).
 *
 * @see Subcommands::mcpAuth()
 */
final class SubcommandsMcpAuthLoginTest extends TestCase
{
    public function testMcpAuthWithoutAnActionExitsUsage(): void
    {
        [$rc, $stdout] = $this->dispatchLine(['mcp', 'auth', '--output-format', 'json']);

        self::assertSame(NonInteractive::EXIT_CONFIG, $rc);
        self::assertStringContainsString('no action given', self::errorOf($stdout));
    }

    public function testMcpAuthWithAnUnknownActionNamesItAndExitsUsage(): void
    {
        [$rc, $stdout] = $this->dispatchLine(['mcp', 'auth', 'bogus', '--output-format', 'json']);

        self::assertSame(NonInteractive::EXIT_CONFIG, $rc);
        $message = self::errorOf($stdout);
        self::assertStringContainsString('mcp auth bogus: unknown action', $message);
    }

    public function testLoginUnderJsonFormatIsRefusedAsInteractive(): void
    {
        [$rc, $stdout] = $this->dispatchLine(['mcp', 'auth', 'login', 'https://mcp.example.test/server', '--output-format', 'json']);

        self::assertSame(NonInteractive::EXIT_CONFIG, $rc);
        self::assertStringContainsString('login is interactive by design', self::errorOf($stdout));
    }

    public function testLoginWithNoServerUrlExitsUsageWithoutTouchingTheNetwork(): void
    {
        [$rc, $stdout] = $this->dispatchLine(['mcp', 'auth', 'login']);

        self::assertSame(NonInteractive::EXIT_CONFIG, $rc, 'the door closes before any flow exists');
        self::assertSame('', $stdout, 'a text-mode usage error must not write to stdout');
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function loginOperandArguments(): array
    {
        return [
            'zero timeout' => [['mcp', 'auth', 'login', 'https://mcp.example.test/server', '--', '--timeout', '0']],
            'negative timeout' => [['mcp', 'auth', 'login', 'https://mcp.example.test/server', '--', '--timeout', '-3']],
            'not a number' => [['mcp', 'auth', 'login', 'https://mcp.example.test/server', '--', '--timeout', 'soon']],
            'dangling timeout' => [['mcp', 'auth', 'login', 'https://mcp.example.test/server', '--', '--timeout']],
        ];
    }

    /**
     * @param list<string> $argv
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('loginOperandArguments')]
    public function testBadTimeoutOperandsExitUsageWithoutOpeningTheFlow(array $argv): void
    {
        [$rc, $stdout] = $this->dispatchLine($argv);

        // Text mode is deliberate: under JSON, login is refused by the
        // interactive gate BEFORE operand validation ever runs, so only here
        // does --timeout actually get judged — and post-`--`, because a bare
        // --timeout dies at the parser's unknown-options gate before any
        // verb code runs. A regression in this arm would
        // fall through into the real flow — exit 1 from a failed discovery
        // and a printed ✗ — instead of the clean exit 2 on a silent stdout.
        self::assertSame(NonInteractive::EXIT_CONFIG, $rc, 'a bad --timeout must die at the door, not in the flow');
        self::assertSame('', $stdout, 'a text-mode usage error must not write to stdout');
    }

    public function testBashCompletionNestsAuthUnderMcp(): void
    {
        ob_start();
        $rc = Subcommands::dispatch(ArgvParser::parse(['sugarcrush', 'completion', 'bash']));
        $script = (string) ob_get_clean();

        self::assertSame(0, $rc);
        // The bash dialect completes verb → action, two levels. `login` lives
        // one level deeper (action → noun) than this dialect descends for ANY
        // verb — nothing is special-cased away here, the roster row simply
        // gains the auth noun so `mcp aut<TAB>` works.
        self::assertStringContainsString('list auth', $script, 'the mcp level offers the auth verb');
    }

    // =========================================================================
    // Harness
    // =========================================================================

    /**
     * @param list<string> $args
     *
     * @return array{0:int,1:string} exit code and captured stdout
     */
    private function dispatchLine(array $args): array
    {
        ob_start();
        $rc = Subcommands::dispatch(ArgvParser::parse(['sugarcrush', ...$args]));
        $stdout = (string) ob_get_clean();

        return [$rc, $stdout];
    }

    private static function errorOf(string $stdout): string
    {
        $decoded = json_decode(trim($stdout), true);
        self::assertIsArray($decoded, "stdout was not a JSON envelope: {$stdout}");
        self::assertSame('usage', $decoded['error']['type'] ?? null, $stdout);

        return (string) ($decoded['error']['message'] ?? '');
    }
}
