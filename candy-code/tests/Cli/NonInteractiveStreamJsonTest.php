<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Events\SpendCapBreached;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Events\ToolStarted;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Message;
use SugarCraft\Code\Tools\ToolResult;

/**
 * AUT-3 end-to-end pins for `-p --output-format stream-json`.
 *
 * WHAT THIS PROVES and how: `\STDOUT` writes bypass output buffering, so the
 * run is driven with {@see NonInteractive::pinStreamOutputForTesting()} onto
 * a memory stream and the whole NDJSON body is read back as bytes. The
 * terminal json document on a SUCCESS path rides the same pinned stream;
 * on an ERROR path it rides `emitErrorDocument()`'s echo, which ob_start
 * does capture — each half is read from where it is actually written.
 */
final class NonInteractiveStreamJsonTest extends TestCase
{
    /** @return resource */
    private function pinMemoryStream()
    {
        $stream = \fopen('php://memory', 'w+b');
        $this->assertNotFalse($stream);

        return $stream;
    }

    /**
     * A backend that speaks like the engine's observer contract: prose
     * deltas, a bounded tool call, then the settled answer.
     */
    private function streamingBackend(string $answer): Backend
    {
        return new class($answer) implements Backend {
            public function __construct(private readonly string $answer)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null, ?callable $onReasoning = null, ?callable $onHeartbeat = null): Message
            {
                if ($onReasoning !== null) {
                    $onReasoning('think');
                }
                if ($onToken !== null) {
                    $onToken('Hello ');
                }
                if ($onEvent !== null) {
                    $onEvent(new ToolStarted('c1', 'Bash', ['command' => 'ls']));
                }
                if ($onEvent !== null) {
                    $onEvent(new ToolFinished('c1', 'Bash', new ToolResult('c1', 'file list', isError: false)));
                }
                if ($onToken !== null) {
                    $onToken('world');
                }

                return Message::assistant($this->answer);
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                throw new \LogicException('not used on the sync -p path');
            }
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeLines($stream): array
    {
        \rewind($stream);
        $raw = (string) \stream_get_contents($stream);
        $this->assertNotSame('', $raw);
        $this->assertStringEndsWith("\n", $raw);

        $rows = [];
        foreach (\explode("\n", \rtrim($raw, "\n")) as $line) {
            $decoded = \json_decode($line, true);
            $this->assertIsArray($decoded, 'every NDJSON line must decode on its own: ' . $line);
            $rows[] = $decoded;
        }

        return $rows;
    }

    public function testTheParserAcceptsStreamJsonAndStillRefusesUnknownFormats(): void
    {
        $args = ArgvParser::parse(['candy-code', '-p', 'hi', '--output-format', 'stream-json']);
        $this->assertNull($args->usageError);
        $this->assertSame(NonInteractive::FORMAT_STREAM_JSON, $args->outputFormat);

        $bad = ArgvParser::parse(['candy-code', '-p', 'hi', '--output-format', 'ndjson']);
        $this->assertNotNull($bad->usageError);
        $this->assertStringContainsString('stream-json', (string) $bad->usageHint, 'the remedy hint enumerates all three formats');
    }

    public function testASuccessRunStreamsEventsThenTheTerminalDocumentLast(): void
    {
        $out = $this->pinMemoryStream();
        NonInteractive::pinStreamOutputForTesting($out);
        try {
            $args = ArgvParser::parse(['candy-code', '-p', 'hi']);
            $this->assertNull($args->usageError);

            \ob_start();
            $code = NonInteractive::run($args, $this->streamingBackend('Hello world'), NonInteractive::FORMAT_STREAM_JSON);
            $printed = (string) \ob_get_clean();

            $this->assertSame(NonInteractive::EXIT_OK, $code);
            $this->assertSame('', $printed, 'under stream-json nothing reaches the plain echo path — every byte is an NDJSON line');

            $rows = $this->decodeLines($out);
            $this->assertSame(
                [
                    SessionEvent::REASONING_DELTA,
                    SessionEvent::ASSISTANT_DELTA,
                    SessionEvent::TOOL_STARTED,
                    SessionEvent::TOOL_FINISHED,
                    SessionEvent::ASSISTANT_DELTA,
                ],
                \array_slice(\array_column($rows, 'type'), 0, 5),
                'deltas and tool lifecycle arrive in emission order before the answer',
            );
            $terminal = $rows[\count($rows) - 1];
            $this->assertArrayNotHasKey('type', $terminal, 'the last line is the document, not an envelope');
            $this->assertSame(
                ['schema' => NonInteractive::SCHEMA, 'result' => 'Hello world'],
                $terminal,
                'the terminal line is exactly the json-format success document',
            );
        } finally {
            NonInteractive::pinStreamOutputForTesting(null);
            \fclose($out);
        }
    }

    public function testARefusalStillLandsOnTheDocumentAndTheStderrChannelNotJustTheStream(): void
    {
        $backend = new class implements Backend {
            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null, ?callable $onReasoning = null, ?callable $onHeartbeat = null): Message
            {
                if ($onEvent !== null) {
                    $onEvent(new SpendCapBreached(3, 1.25, 1.0));
                }

                return Message::assistant('capped');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                throw new \LogicException('not used');
            }
        };

        $out = $this->pinMemoryStream();
        NonInteractive::pinStreamOutputForTesting($out);
        try {
            $args = ArgvParser::parse(['candy-code', '-p', 'hi']);
            $this->assertNull($args->usageError);

            $code = NonInteractive::run($args, $backend, NonInteractive::FORMAT_STREAM_JSON);
            $this->assertSame(NonInteractive::EXIT_OK, $code);

            $rows = $this->decodeLines($out);
            $this->assertSame(SessionEvent::SPEND_CAP_BREACHED, $rows[0]['type']);
            $terminal = $rows[\count($rows) - 1];
            $this->assertArrayHasKey('refusals', $terminal, 'the composed observer keeps feeding the document — stream mode ADDS lines, it never moves refusal reporting');
        } finally {
            NonInteractive::pinStreamOutputForTesting(null);
            \fclose($out);
        }
    }

    public function testAFailingRunStillEndsOnATypedErrorDocument(): void
    {
        $backend = new class implements Backend {
            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null, ?callable $onReasoning = null, ?callable $onHeartbeat = null): Message
            {
                throw new \RuntimeException('provider exploded');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                throw new \LogicException('not used');
            }
        };

        $args = ArgvParser::parse(['candy-code', '-p', 'hi']);
        $this->assertNull($args->usageError);

        \ob_start();
        $code = NonInteractive::run($args, $backend, NonInteractive::FORMAT_STREAM_JSON);
        $printed = (string) \ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_FAILURE, $code);
        $decoded = \json_decode(\trim($printed), true);
        $this->assertIsArray($decoded);
        $this->assertSame('backend', $decoded['error']['type'], 'the error document prints under stream-json too — it is the terminal line');
    }

    public function testTextFormatStillPrintsTheAnswerAlone(): void
    {
        $args = ArgvParser::parse(['candy-code', '-p', 'hi']);
        $this->assertNull($args->usageError);

        \ob_start();
        $code = NonInteractive::run($args, $this->streamingBackend('Hello world'), NonInteractive::FORMAT_TEXT);
        $printed = (string) \ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $this->assertSame("Hello world\n", $printed, 'AUT-3 changed nothing for text: stdout is the answer and nothing else');
    }
}
