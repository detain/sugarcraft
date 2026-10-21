<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Code\Support\Log;

/**
 * Pins for the `error_log()` façade (W7-C).
 *
 * THE WIRE PINS COME FIRST BECAUSE THEY ARE THE FOLD'S CONTRACT: every call
 * site moved behind this class had its output bytes pinned as they were
 * emitted before the move, so a channel-prefix regression reds here rather
 * than in whatever suite happens to capture a producer. The timestamp bracket
 * is the ini file-destination's own doing (MEASURED: a file target writes
 * `[date] <message>\n`; an unset target writes `<message>` to stderr whole)
 * and is matched through, not asserted about — it belongs to the destination,
 * which {@see \SugarCraft\Code\Diagnostics\TuiErrorLog} and the capture
 * helpers control, not to the façade.
 *
 * WHAT THIS FILE DOES NOT DO: re-census the tree. "No `error_log()` call
 * outside this file" is channel 3 of
 * {@see \SugarCraft\Code\Tests\Cli\StderrEmitterCensusTest}, which owns the
 * roster and its friction doc-block; a second scanner here would be a second
 * answer to keep in sync — rule 7's thing.
 */
final class LogTest extends TestCase
{
    use DiscardsErrorLogTrait;

    /**
     * The destination's own bracket prefix, matched through on every line.
     */
    private const TIMESTAMPED = '/^\[[^\]]*\] (.*)$/';

    public function testEachChannelWritesTheBytesTheFoldedSitesWrote(): void
    {
        $captured = self::withErrorLogDiscarded(
            static function (): void {
                // The four shapes as their call sites spelled them before the
                // fold: the E154c detach self-label, the envelope-only
                // refusal, the two-stage sub-channel, and the pre-composed
                // line the sink and the translated launch format hand over
                // already complete.
                Log::channel(Log::CHAT, 'onToken observer threw, detaching it for this turn: boom');
                Log::channel(Log::APP, 'Refused rules directory /srv/x: world-writable');
                Log::sub(Log::AGENT_WORKER_POOL, 'pcntl_fork() FAILED (11) — this agent gets no worker');
                Log::sub(Log::DSML_TOOL_CALL_PARSER, 'a "<invoke>" envelope opened at byte 3 is never closed; ');
                Log::raw('candy-code: MCP config /p could not be fully started (X: m); continuing without it.');
                Log::raw('a complete record the sink composed itself');
            },
            prefix: 'w7c_log_wire_',
        );

        self::assertSame(
            [
                'Chat: onToken observer threw, detaching it for this turn: boom',
                'candy-code: Refused rules directory /srv/x: world-writable',
                'candy-code: AgentWorkerPool: pcntl_fork() FAILED (11) — this agent gets no worker',
                'candy-code: DsmlToolCallParser: a "<invoke>" envelope opened at byte 3 is never closed; ',
                'candy-code: MCP config /p could not be fully started (X: m); continuing without it.',
                'a complete record the sink composed itself',
            ],
            self::payloads($captured),
            'a channel stopped writing the bytes its folded call site wrote',
        );
    }

    public function testTheAppEnvelopeIsTheOneTheE154LineageSpelled(): void
    {
        // `sub()` is envelope + label + ': ' — the two literals the call
        // sites used to carry inline, rebuilt from the channel constants so
        // a grep for the constant names finds every producer.
        $captured = self::withErrorLogDiscarded(
            static function (): void {
                Log::sub(Log::SGLANG_PROVIDER, 'truncation risk');
            },
            prefix: 'w7c_log_env_',
        );

        self::assertSame(
            ['candy-code: SglangProvider: truncation risk'],
            self::payloads($captured),
        );
    }

    public function testTheQuietGateIsTheNarrowHouseLawAndSilencesOnlyTheWrites(): void
    {
        $previous = \getenv(Log::QUIET_LOGS_ENV);

        try {
            foreach (['1', 'true', 'YES'] as $asking) {
                \putenv(Log::QUIET_LOGS_ENV . '=' . $asking);
                $quiet = self::withErrorLogDiscarded(
                    static function (): void {
                        Log::channel(Log::APP, 'suppressed');
                        Log::sub(Log::CHAT, 'also suppressed');
                        Log::raw('still suppressed');
                    },
                    prefix: 'w7c_log_quiet_',
                );
                self::assertSame(
                    '',
                    $quiet,
                    "CANDY_CODE_QUIET_LOGS=\"{$asking}\" must silence every channel: it is the narrow opt-in, not a suggestion.",
                );
            }

            foreach (['0', 'off', 'false', '2', ''] as $notAsking) {
                \putenv(Log::QUIET_LOGS_ENV . '=' . $notAsking);
                $loud = self::withErrorLogDiscarded(
                    static function (): void {
                    Log::raw('flowing');
                },
                    prefix: 'w7c_log_loud_',
                );
                self::assertSame(
                    ['flowing'],
                    self::payloads($loud),
                    "CANDY_CODE_QUIET_LOGS=\"{$notAsking}\" must NOT silence the channel — unset is today's behavior and a typo is not a decision.",
                );
            }

            \putenv(Log::QUIET_LOGS_ENV);
            $default = self::withErrorLogDiscarded(
                static function (): void {
                Log::raw('default flows');
            },
                prefix: 'w7c_log_default_',
            );
            self::assertSame(
                ['default flows'],
                self::payloads($default),
                'with the variable unset the façade must write exactly what the raw calls wrote',
            );
        } finally {
            if ($previous === false) {
                \putenv(Log::QUIET_LOGS_ENV);
            } else {
                \putenv(Log::QUIET_LOGS_ENV . '=' . $previous);
            }
        }
    }

    public function testTheGateIsReadPerWriteAndNotMemoizedAtFirstSight(): void
    {
        // A memo would make a mid-process `putenv()` inert — the shape that
        // bit lane cd's interactive-availability memo. Toggling in both
        // directions inside one capture window must take effect immediately.
        $previous = \getenv(Log::QUIET_LOGS_ENV);

        try {
            \putenv(Log::QUIET_LOGS_ENV);
            $captured = self::withErrorLogDiscarded(
                static function (): void {
                    Log::raw('flows first');
                    \putenv(Log::QUIET_LOGS_ENV . '=1');
                    Log::raw('silent between');
                    \putenv(Log::QUIET_LOGS_ENV . '=0');
                    Log::raw('flows again');
                },
                prefix: 'w7c_log_memo_',
            );

            self::assertSame(
                ['flows first', 'flows again'],
                self::payloads($captured),
            );
        } finally {
            if ($previous === false) {
                \putenv(Log::QUIET_LOGS_ENV);
            } else {
                \putenv(Log::QUIET_LOGS_ENV . '=' . $previous);
            }
        }
    }

    public function testTheSinkIsTheOnlyErrorLogCallAndThePublicMethodsAllRouteThroughIt(): void
    {
        // STRUCTURAL: the fold's whole point is one destination call. The
        // census rosters the tree; this arm stops the degenerate case it
        // could not see — a method that grew its own call while the roster
        // entry for Log.php still said one.
        $file = (string) (new ReflectionMethod(Log::class, 'sink'))->getFileName();

        foreach (['sink', 'channel', 'sub', 'raw'] as $method) {
            $body = self::bodyOf($file, new ReflectionMethod(Log::class, $method));
            $expected = $method === 'sink' ? 1 : 0;
            self::assertSame(
                \substr_count($body, 'error_log('),
                $expected,
                $method === 'sink'
                    ? 'the sink must call error_log() exactly once — the ini-target mechanism (TuiErrorLog, the capture helpers) depends on it'
                    : "{$method} must reach the log only through sink(); a direct call bypasses the quiet gate",
            );
        }

        foreach (['channel', 'sub', 'raw'] as $method) {
            $body = self::bodyOf($file, new ReflectionMethod(Log::class, $method));
            self::assertStringContainsString('self::sink(', $body, "{$method} must delegate to sink()");
        }
    }

    /**
     * The written payloads, one per line, destination timestamp bracket
     * matched through (see class doc-block).
     *
     * @return list<string>
     */
    private static function payloads(string $captured): array
    {
        $lines = $captured === '' ? [] : \explode("\n", \rtrim($captured, "\n"));
        $out = [];

        foreach ($lines as $line) {
            $matched = \preg_match(self::TIMESTAMPED, $line, $parts);
            self::assertSame(1, $matched, "captured line lost the destination's bracket shape: {$line}");
            $out[] = $parts[1];
        }

        return $out;
    }

    /** The declared body of a method, by reflection line range. */
    private static function bodyOf(string $file, ReflectionMethod $method): string
    {
        $lines = \file($file);
        self::assertIsArray($lines);
        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();

        return \implode("\n", \array_slice($lines, $start - 1, $end - $start + 1));
    }
}
