<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\NonInteractive;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;

/**
 * Tests for {@see NonInteractive} — the `-p "<prompt>"` / `run "<prompt>"`
 * one-shot CLI path (crush_feat.md section 2, Recommendations 2-4).
 *
 * Covered cases:
 *   - missing/blank prompt -> exit 1, never calls the backend
 *   - a real Backend::complete() result -> echoed to stdout, exit 0
 *   - a thrown backend error -> exit 1, nothing echoed to stdout
 *   - --output-format text|json rendering
 *   - stdin-piping into history, including the 10MB cap
 */
final class NonInteractiveTest extends TestCase
{
    // -------------------------------------------------------------------------
    // run() — missing prompt (exit-code convention, Recommendation 4)
    // -------------------------------------------------------------------------

    public function testRunReturnsOneWhenPromptIsNull(): void
    {
        $args = ArgvParser::parse(['sugarcrush']);

        $this->assertNull($args->prompt);
        $this->assertSame(1, NonInteractive::run($args, new EchoBackend()));
    }

    public function testRunReturnsOneWhenPromptIsEmptyString(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '-p', '']);

        $this->assertSame(1, NonInteractive::run($args, new EchoBackend()));
    }

    public function testRunReturnsOneWhenPromptIsWhitespaceOnly(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '-p', '   ']);

        $this->assertSame(1, NonInteractive::run($args, new EchoBackend()));
    }

    public function testRunDoesNotCallBackendWhenPromptMissing(): void
    {
        $args = ArgvParser::parse(['sugarcrush']);
        $backend = new class implements Backend {
            public bool $called = false;

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->called = true;

                return Message::assistant('should not run');
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return \React\Promise\resolve($this->complete($history, $onToken));
            }
        };

        NonInteractive::run($args, $backend);

        $this->assertFalse($backend->called);
    }

    // -------------------------------------------------------------------------
    // run() — success path (exit 0, stdout gets the result)
    // -------------------------------------------------------------------------

    public function testRunEchoesBackendResultAndReturnsZero(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '-p', 'hello there']);

        \ob_start();
        $code = NonInteractive::run($args, new EchoBackend());
        $output = \ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('hello there', $output);
    }

    public function testRunJsonOutputFormatProducesValidJsonWithResultKey(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '-p', 'hi']);

        \ob_start();
        $code = NonInteractive::run($args, new EchoBackend(), NonInteractive::FORMAT_JSON);
        $output = \trim((string) \ob_get_clean());

        $this->assertSame(0, $code);
        $decoded = \json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('result', $decoded);
        $this->assertStringContainsString('hi', $decoded['result']);
    }

    /**
     * End-to-end: drives NonInteractive through the SAME call shape
     * `bin/sugarcrush` uses (`NonInteractive::run($args, null,
     * $args->outputFormat)` with the third argument coming from a real
     * ArgvParser::parse() result, not a literal `NonInteractive::FORMAT_JSON`
     * passed straight into the test) — proves `--output-format json` on a
     * real argv actually reaches {@see NonInteractive::format()}, closing the
     * gap where every other test here bypassed ArgvParser for this flag.
     */
    public function testOutputFormatFlagReachesFormatViaRealArgvParserWiring(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '-p', 'hi', '--output-format', 'json']);

        $this->assertSame('json', $args->outputFormat);

        \ob_start();
        $code = NonInteractive::run($args, new EchoBackend(), $args->outputFormat);
        $output = \trim((string) \ob_get_clean());

        $this->assertSame(0, $code);
        $decoded = \json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('result', $decoded);
        $this->assertStringContainsString('hi', $decoded['result']);
    }

    /**
     * Same as above but omits the third `NonInteractive::run()` argument
     * entirely for the *default* (text) case, matching `bin/sugarcrush`'s
     * behaviour when no `--output-format` flag is given at all.
     */
    public function testOutputFormatDefaultsToTextThroughRealArgvParserWiring(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '-p', 'hi']);

        $this->assertSame('text', $args->outputFormat);

        \ob_start();
        $code = NonInteractive::run($args, new EchoBackend(), $args->outputFormat);
        $output = \trim((string) \ob_get_clean());

        $this->assertSame(0, $code);
        $this->assertStringContainsString('hi', $output);
        $this->assertNull(\json_decode($output, true), 'text format output should not happen to be valid JSON');
    }

    // -------------------------------------------------------------------------
    // run() — a throwing backend (exit 1, nothing echoed)
    // -------------------------------------------------------------------------

    public function testRunReturnsOneWhenBackendThrows(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '-p', 'boom']);
        $backend = new class implements Backend {
            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                throw new \RuntimeException('backend unavailable');
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return \React\Promise\reject(new \RuntimeException('backend unavailable'));
            }
        };

        \ob_start();
        $code = NonInteractive::run($args, $backend);
        $output = \ob_get_clean();

        $this->assertSame(1, $code);
        $this->assertSame('', $output);
    }

    // -------------------------------------------------------------------------
    // historyFrom()
    // -------------------------------------------------------------------------

    public function testHistoryFromWithoutStdinReturnsSingleUserMessage(): void
    {
        $history = NonInteractive::historyFrom('hello', null);

        $this->assertCount(1, $history);
        $this->assertSame(Role::User, $history[0]->role);
        $this->assertSame('hello', $history[0]->content);
    }

    public function testHistoryFromWithEmptyStdinStringIgnoresIt(): void
    {
        $history = NonInteractive::historyFrom('hello', '');

        $this->assertSame('hello', $history[0]->content);
    }

    public function testHistoryFromWithStdinPrependsContextBeforePrompt(): void
    {
        $history = NonInteractive::historyFrom('explain this', 'traceback: boom at line 4');

        $this->assertCount(1, $history);
        $this->assertSame(
            "traceback: boom at line 4\n\nexplain this",
            $history[0]->content
        );
    }

    // -------------------------------------------------------------------------
    // readStdinIfPiped()
    // -------------------------------------------------------------------------

    private function memoryStreamWith(string $contents)
    {
        $stream = \fopen('php://memory', 'r+');
        \fwrite($stream, $contents);
        \rewind($stream);

        return $stream;
    }

    public function testReadStdinIfPipedReturnsDataFromStream(): void
    {
        $stream = $this->memoryStreamWith('some piped context');

        $this->assertSame('some piped context', NonInteractive::readStdinIfPiped($stream));
    }

    public function testReadStdinIfPipedReturnsNullForEmptyStream(): void
    {
        $stream = $this->memoryStreamWith('');

        $this->assertNull(NonInteractive::readStdinIfPiped($stream));
    }

    public function testReadStdinIfPipedTruncatesAtTenMegabyteCap(): void
    {
        // Claude Code's documented 10MB stdin cap — without this cap a
        // caller could pipe unbounded data straight into the prompt sent
        // to a real backend, which is exactly the failure mode
        // Recommendation 2 calls out ("cap piped input like Claude does").
        $tenMb = 10 * 1024 * 1024;
        $stream = $this->memoryStreamWith(\str_repeat('a', $tenMb + 1000));

        $result = NonInteractive::readStdinIfPiped($stream);

        $this->assertNotNull($result);
        $this->assertSame($tenMb, \strlen($result));
    }

    // -------------------------------------------------------------------------
    // format()
    // -------------------------------------------------------------------------

    public function testFormatTextReturnsRawContent(): void
    {
        $message = Message::assistant('plain text reply');

        $this->assertSame('plain text reply', NonInteractive::format($message, NonInteractive::FORMAT_TEXT));
    }

    public function testFormatJsonReturnsJsonEncodedResult(): void
    {
        $message = Message::assistant('a "quoted" reply');

        $encoded = NonInteractive::format($message, NonInteractive::FORMAT_JSON);
        $decoded = \json_decode($encoded, true);

        $this->assertIsArray($decoded);
        $this->assertSame('a "quoted" reply', $decoded['result']);
    }

    public function testFormatUnknownFormatFallsBackToText(): void
    {
        $message = Message::assistant('fallback content');

        $this->assertSame('fallback content', NonInteractive::format($message, 'yaml'));
    }

    public function testFormatConstantsHaveExpectedValues(): void
    {
        $this->assertSame('text', NonInteractive::FORMAT_TEXT);
        $this->assertSame('json', NonInteractive::FORMAT_JSON);
    }
}
