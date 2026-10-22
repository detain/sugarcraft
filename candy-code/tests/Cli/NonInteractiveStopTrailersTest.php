<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\ToolCallLoopGuard;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Usage;

/**
 * D1-fix (plan_comparison audit 2026-10-21): the one-shot `-p` path announces
 * every stop condition the engine folded onto the settled Message instead of
 * exiting 0 on an unmarked partial answer.
 *
 * Two surfaces per fact, one per audience: the `text` format carries plain
 * trailer lines after the answer, the `json` format (and a stream-json run's
 * final line) carries typed keys, and the unpriced-model caveat — a
 * bookkeeping fact with no stdout contract of its own — carries one stderr
 * line in the ERROR_LABEL family, byte-identical across formats. Exit status
 * is deliberately NOT part of this story: 0 stays 0 for a stopped-but-answered
 * run, pinned below as contract law.
 *
 * The TUI settle arm's order (output limit, step ceiling, loop guard) is the
 * trailer's order; the wording is the `cli.truncate.*` keys, not the TUI's
 * `chat.turn.*` strings, because "say continue" instructs a transcript, not a
 * pipe. {@see NonInteractiveRefusalDocumentTest} owns the refusal-channel
 * claims; nothing here re-pins them.
 */
final class NonInteractiveStopTrailersTest extends TestCase
{
    /**
     * Self-cleaning: the stream pin is class-static and must not leak into a
     * later shard even if an assertion below throws.
     */
    protected function tearDown(): void
    {
        NonInteractive::pinStreamOutputForTesting(null);
    }

    public function testACleanTurnStillEndsAtTheAnswerAlone(): void
    {
        self::assertSame(
            "the answer\n",
            self::capturedTextRun(Message::assistant('the answer')),
            'a turn that stopped nowhere grows a trailer it cannot justify',
        );
        self::assertSame(
            ['schema', 'result'],
            \array_keys(self::capturedJsonRun(Message::assistant('the answer'))[0]),
            'the clean document is not the two keys every pipeline jq depends on',
        );
    }

    public function testAStepTruncatedTurnTrailsItsHonestLineAndStillExitsZero(): void
    {
        $stdout = self::capturedTextRun(
            Message::assistant('the answer')->withStepsTruncated(true),
            $code,
        );

        self::assertSame(NonInteractive::EXIT_OK, $code, 'a stopped-but-answered run is not a failure');
        self::assertSame("the answer\n" . Lang::t('cli.truncate.steps') . "\n", $stdout);
    }

    public function testTheThreeStopConditionsTrailInOrderAndEachAloneWhenAlone(): void
    {
        $all = Message::assistant('the answer')
            ->withLengthStopped(true)
            ->withStepsTruncated(true)
            ->withLoopGuardStoppedBy('Search');
        $expected = "the answer\n"
            . Lang::t('cli.truncate.length_stopped') . "\n"
            . Lang::t('cli.truncate.steps') . "\n"
            . Lang::t('cli.truncate.loop_guard', ['tool' => 'Search', 'count' => ToolCallLoopGuard::END_TURN_AT]) . "\n";
        self::assertSame($expected, self::capturedTextRun($all), 'trailer order is the settle arm order');

        foreach ([
            'length' => [Message::assistant('the answer')->withLengthStopped(true), 'cli.truncate.length_stopped'],
            'steps' => [Message::assistant('the answer')->withStepsTruncated(true), 'cli.truncate.steps'],
        ] as $label => [$message, $key]) {
            $line = self::capturedTextRun($message);
            self::assertSame(
                "the answer\n" . Lang::t($key) . "\n",
                $line,
                "the {$label}-only trailer is not exactly its own line",
            );
        }
    }

    public function testJsonModeCarriesTheTypedStopKeysAndNothingForAStoppedNowhereTurn(): void
    {
        [$document] = self::capturedJsonRun(
            Message::assistant('the answer')
                ->withLengthStopped(true)
                ->withLoopGuardStoppedBy('Search'),
        );

        self::assertSame('the answer', $document['result']);
        self::assertTrue($document['length_stopped']);
        self::assertSame('Search', $document['loop_guard_stopped_by']);
        self::assertArrayNotHasKey('steps_truncated', $document, 'a false flag must stay absent, not asserted');

        [$clean] = self::capturedJsonRun(Message::assistant('the answer'));
        foreach (['steps_truncated', 'length_stopped', 'loop_guard_stopped_by'] as $key) {
            self::assertArrayNotHasKey($key, $clean);
        }
    }

    public function testTheTerminalLineOfAStreamJsonRunCarriesTheSameKeys(): void
    {
        $stream = \fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        NonInteractive::pinStreamOutputForTesting($stream);

        $code = NonInteractive::run(
            ArgvParser::parse(['candy-code', '-p', 'go']),
            self::answering(Message::assistant('the answer')->withStepsTruncated(true)),
            NonInteractive::FORMAT_STREAM_JSON,
        );

        self::assertSame(NonInteractive::EXIT_OK, $code);
        \rewind($stream);
        $lines = \explode("\n", \rtrim((string) \stream_get_contents($stream), "\n"));
        $last = \end($lines);
        $document = \json_decode((string) $last, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $document['schema']);
        self::assertTrue($document['steps_truncated'], "a stream-json reader's final line lost the stop fact: {$last}");
    }

    public function testTheTextTrailerStripsControlBytesFromAForgedToolName(): void
    {
        $message = Message::assistant('the answer')->withLoopGuardStoppedBy("\x1b[31mEvil\x07");
        $stdout = self::capturedTextRun($message);

        self::assertStringNotContainsString("\x1b", $stdout, 'the trailer smuggled an escape onto the terminal');
        self::assertStringNotContainsString("\x07", $stdout);
        self::assertStringContainsString('Evil', $stdout, 'sanitising must still name the tool that repeated');
    }

    public function testTheLoopGuardToolNameRidesTheJsonDocumentEscapedAndVerbatim(): void
    {
        $evil = "\x1b[31mEvil\x07";
        [$document, $raw] = self::capturedJsonRun(Message::assistant('the answer')->withLoopGuardStoppedBy($evil), $code);

        self::assertSame(NonInteractive::EXIT_OK, $code);
        self::assertStringContainsString('\u001b', $raw, 'json_encode is the escape leg of this contract');
        self::assertSame($evil, $document['loop_guard_stopped_by'], 'escaping must round-trip the name, not mangle it');
    }

    public function testTheUnpricedModelLineLandsOnStderrWithTheAnswerStillCleanOnStdout(): void
    {
        $expression = "\\SugarCraft\\Code\\Message::assistant('the answer')->withUsage(\\SugarCraft\\Code\\Usage::new(unpricedModel: 'mystery/model'))";

        [$stdout, $stderr, $code] = self::runOneShotChildWithSplitChannels($expression, 'text');
        self::assertSame(NonInteractive::EXIT_OK, $code);
        self::assertSame("the answer\n", $stdout, 'the unpriced notice bled into the piped answer');
        $expected = Lang::t('chat.turn.unpriced_model', ['model' => 'mystery/model']);
        self::assertStringContainsString('candy-code: ' . $expected . "\n", $stderr);

        // The same notice on the json run: stdout stays exactly one document
        // (stderr bytes are format-independent by this path's discipline).
        [$jsonStdout, $jsonStderr, $jsonCode] = self::runOneShotChildWithSplitChannels($expression, 'json');
        self::assertSame(NonInteractive::EXIT_OK, $jsonCode);
        self::assertIsArray(\json_decode(\trim($jsonStdout), true, 512, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('candy-code: ' . $expected . "\n", $jsonStderr);

        // KNOWN-POSITIVE PAIRING (rule 15): the identical harness, one field
        // away — "stdout empty of notices" above is an absence this instrument
        // could have detected.
        [$quietStdout, $quietStderr, $quietCode] = self::runOneShotChildWithSplitChannels(
            "\\SugarCraft\\Code\\Message::assistant('the answer')->withUsage(\\SugarCraft\\Code\\Usage::new(totalTokens: 5))",
            'text',
        );
        self::assertSame(NonInteractive::EXIT_OK, $quietCode);
        self::assertSame("the answer\n", $quietStdout);
        self::assertSame('', $quietStderr, 'a priced model grew an unpriced notice anyway');
    }

    /**
     * Drive one -p run in-process and capture what lands on stdout.
     *
     * @param-out int $code
     */
    private static function capturedTextRun(Message $message, ?int &$code = null): string
    {
        \ob_start();
        $code = NonInteractive::run(
            ArgvParser::parse(['candy-code', '-p', 'go']),
            self::answering($message),
            NonInteractive::FORMAT_TEXT,
        );

        return (string) \ob_get_clean();
    }

    /**
     * The same run under `--output-format json`, decoded — with the RAW bytes
     * returned beside it because two of these tests pin escape behaviour at
     * the byte level, where a decoded array hides exactly what it proves.
     *
     * @param-out int $code
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private static function capturedJsonRun(Message $message, ?int &$code = null): array
    {
        \ob_start();
        $code = NonInteractive::run(
            ArgvParser::parse(['candy-code', '-p', 'go']),
            self::answering($message),
            NonInteractive::FORMAT_JSON,
        );
        $raw = (string) \ob_get_clean();

        return [\json_decode(\trim($raw), true, 512, JSON_THROW_ON_ERROR), $raw];
    }

    /**
     * A backend that returns exactly the Message the test built — the stop
     * flags are planted by the caller through the with* carriers, because the
     * point of these tests is what the RENDERER does with a folded Message,
     * not whether the fold computes one (EngineBackend's own suite pins that).
     */
    private static function answering(Message $message): Backend
    {
        return new class($message) implements Backend {
            public function __construct(private readonly Message $answer)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return $this->answer;
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return \React\Promise\resolve($this->complete($history, $onToken, $onEvent));
            }
        };
    }

    /**
     * Run one -p invocation in a child process with stdout and stderr as two
     * separate pipes, so a notice written to the wrong channel cannot be
     * missed by a merged-stream assertion. $messageExpression is PHP source
     * evaluated inside the child to build the answer Message.
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private static function runOneShotChildWithSplitChannels(string $messageExpression, string $format): array
    {
        $autoloadLiteral = \var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $formatLiteral = \var_export($format, true);

        $script = <<<CHILD
            <?php
            declare(strict_types=1);
            require {$autoloadLiteral};
            use SugarCraft\\Code\\Backend;
            use SugarCraft\\Code\\Backend\\CancellationToken;
            use SugarCraft\\Code\\Cli\\ArgvParser;
            use SugarCraft\\Code\\Cli\\NonInteractive;

            \$backend = new class implements Backend {
                public function complete(array \$history, ?callable \$onToken = null, ?callable \$onEvent = null): \\SugarCraft\\Code\\Message
                {
                    return {$messageExpression};
                }

                public function completeAsync(array \$history, ?callable \$onToken = null, ?CancellationToken \$cancellation = null, ?callable \$onEvent = null): \\React\\Promise\\PromiseInterface
                {
                    // Every backslash in this heredoc is doubled: the heredoc
                    // INTERPOLATES, so a single `\\r` in `Promise\\resolve` is a
                    // carriage return in the emitted file and the child dies on
                    // `unexpected identifier "esolve"`.
                    return \\React\\Promise\\resolve(\$this->complete(\$history, \$onToken, \$onEvent));
                }
            };

            exit(NonInteractive::run(ArgvParser::parse(['candy-code', '-p', 'go']), \$backend, {$formatLiteral}));
            CHILD;

        $path = \sys_get_temp_dir() . '/sc_d1_stops_child_' . \getmypid() . '_' . \uniqid('seq', true) . '.php';
        \file_put_contents($path, $script);

        try {
            $process = \proc_open(
                [\PHP_BINARY, $path],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process, 'could not spawn the child process');

            $stdout = (string) \stream_get_contents($pipes[1]);
            $stderr = (string) \stream_get_contents($pipes[2]);
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            $code = \proc_close($process);
        } finally {
            @\unlink($path);
        }

        return [$stdout, $stderr, $code];
    }
}
