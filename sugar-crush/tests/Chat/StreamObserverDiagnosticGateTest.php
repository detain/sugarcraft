<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;

/**
 * The one `error_log()` in `Chat` is gated, and the gate opens.
 *
 * WHY IT IS GATED, in one line, with the argument in full at
 * {@see Chat::DEBUG_STREAM_ENV}: this write happens inside the streaming loop
 * of a turn already in flight, so the alternate screen has been up for the
 * whole session and a line on fd 2 lands on a frame the renderer believes it
 * owns. Every launch-time stderr write in this application has been routed for
 * that reason; this one fires too late for the seam those use, and its audience
 * is the embedder whose `onToken` threw rather than the person at the terminal.
 *
 * BOTH BRANCHES, and the OFF one is the reason this file exists. A gate is a
 * conditional, and a test that only ever runs it with the env var SET proves
 * the message can be produced while proving nothing at all about the default —
 * which is the behaviour every real run gets. The two tests below are
 * complements over {@see Chat::DEBUG_STREAM_ENV}, and the ON case doubles as the
 * known-positive fixture for the OFF case's assertion of an absence: the same
 * throwing sink, driven through the same turn, into the same capture file.
 * Without it, a `Chat` that had stopped catching the throw entirely would pass
 * the OFF test.
 */
final class StreamObserverDiagnosticGateTest extends TestCase
{
    private string $logFile = '';

    private string|false $previousErrorLog = false;

    private string|false $previousFlag = false;

    protected function setUp(): void
    {
        // A name no sibling lane's suite owns: three suites share /tmp and a
        // glob-delete or a collision there has voided a round's figures before.
        $this->logFile = sys_get_temp_dir() . '/sc_a46_stream_gate_' . bin2hex(random_bytes(8)) . '.log';

        // `error_log` redirected to a file rather than the output captured:
        // with no destination set, `error_log()` writes to the SAPI logger,
        // which under the CLI is fd 2 — i.e. straight past PHPUnit's capture
        // and into the suite's own output. This is the pattern
        // `DsmlToolCallParserTest` and `SglangProviderTruncationGuardTest`
        // already use.
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);

        $this->previousFlag = getenv(Chat::DEBUG_STREAM_ENV);
        putenv(Chat::DEBUG_STREAM_ENV);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);

        if ($this->previousFlag === false) {
            putenv(Chat::DEBUG_STREAM_ENV);
        } else {
            putenv(Chat::DEBUG_STREAM_ENV . '=' . $this->previousFlag);
        }

        // Exact path, never a glob: sibling lanes are running suites that own
        // their own files in this directory.
        if ($this->logFile !== '' && is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function testTheDiagnosticIsSilentByDefault(): void
    {
        $threw = $this->runTurnWithAThrowingObserver();

        self::assertTrue($threw, 'the observer was never invoked, so nothing could have been reported');
        self::assertSame(
            '',
            $this->captured(),
            'Chat put the streaming-observer diagnostic on stderr with ' . Chat::DEBUG_STREAM_ENV
                . ' unset. That write happens mid-turn with the alternate screen up, so it lands on a frame '
                . 'the renderer owns — see Chat::DEBUG_STREAM_ENV for the whole argument.',
        );
    }

    public function testTheDiagnosticComesBackWhenTheFlagIsSet(): void
    {
        putenv(Chat::DEBUG_STREAM_ENV . '=1');

        $threw = $this->runTurnWithAThrowingObserver();

        self::assertTrue($threw, 'the observer was never invoked, so nothing could have been reported');
        self::assertStringContainsString(
            'onToken observer threw',
            $this->captured(),
            'the gate no longer opens: with ' . Chat::DEBUG_STREAM_ENV . '=1 the diagnostic must reach '
                . 'stderr, or the gate has become a deletion rather than a routing decision',
        );
    }

    /**
     * `0` is off, matching every other `SUGARCRUSH_*` switch in `Chat`.
     *
     * Pinned because the natural way to write the guard — `getenv(...) !==
     * false` — reads `=0` as ON, and a user who sets it to zero to turn it off
     * would get the opposite of what they asked for.
     */
    public function testZeroReadsAsOff(): void
    {
        putenv(Chat::DEBUG_STREAM_ENV . '=0');

        self::assertTrue($this->runTurnWithAThrowingObserver());
        self::assertSame('', $this->captured());
    }

    /** Whether the throwing observer was actually reached. */
    private function runTurnWithAThrowingObserver(): bool
    {
        $reached = false;

        $chat = new Chat(
            backend: new class implements Backend {
                public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
                {
                    if ($onToken !== null) {
                        // TWO deltas, not one. The catch sets `$userSink = null`
                        // and the report is documented as firing once per turn
                        // rather than once per token; a single delta could not
                        // tell a working detach from a broken one.
                        $onToken('a');
                        $onToken('b');
                    }

                    return Message::assistant('done');
                }

                public function completeAsync(
                    array $history,
                    ?callable $onToken = null,
                    ?CancellationToken $cancellation = null,
                    ?callable $onEvent = null,
                ): PromiseInterface {
                    return new \React\Promise\Promise(
                        function (callable $resolve, callable $reject) use ($history, $onToken): void {
                            try {
                                $resolve($this->complete($history, $onToken));
                            } catch (\Throwable $e) {
                                $reject($e);
                            }
                        },
                    );
                }
            },
            inputBuf: 'hello',
            streaming: true,
            onToken: static function (string $token) use (&$reached): void {
                $reached = true;

                throw new \RuntimeException('the embedder sink is broken');
            },
        );

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        self::assertInstanceOf(\Closure::class, $cmd);
        $cmd();

        return $reached;
    }

    private function captured(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }
}
