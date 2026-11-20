<?php

declare(strict_types=1);

namespace SugarCraft\Code\Backend;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\ChildResult;
use SugarCraft\Async\ChildRun;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Message;
use SugarCraft\Code\Support\ProcessContainment;

/**
 * Streaming-capable backend that shells out to an external command and calls
 * the `$onToken` callback once per COMPLETE line of stdout, as it arrives.
 *
 * WHAT "AS IT ARRIVES" MEANS. Both halves — the callback AND the display — are
 * true on the {@see completeAsync()} path. `completeAsync()` starts the child with
 * sugar-async's {@see ChildRun::launch()}, which reads its pipes from stream
 * callbacks on the event loop, so the ReactPHP loop keeps turning for the whole
 * completion and `Chat`'s `withTick` subscription — the thing that turns a
 * `TokenDelta` into text on screen — runs between tokens. A per-token callback
 * and a repaint per token, not one repaint at the end.
 *
 * The child is read by ChildRun's stream handling on both entry points. The
 * blocking {@see complete()} runs the same child with {@see ChildRun::run()}, which
 * parks in the kernel until a pipe moves rather than polling, and feeds the same
 * {@see StreamingTokenSink} as the promise path, so the two cannot drift about
 * what a line means.
 *
 * On the `-p` one-shot path {@see \SugarCraft\Code\Cli\NonInteractive::run()}
 * no callback is passed at all, and `complete()` blocking is what that path
 * wants.
 *
 * THE STDOUT CONTRACT IS ONE TOKEN PER TERMINATED LINE, AND IT IS NOT {@see
 * CommandBackend}'S:
 *
 *   - a non-empty line is one token, `rtrim`ed of its trailing `\r?\n`;
 *   - AN EMPTY LINE IS A LITERAL `"\n"` — the only way this protocol can
 *     express a line break at all, and the reason it can now express any
 *     string whatsoever;
 *   - the surviving tokens are joined with the EMPTY STRING, because a stream
 *     of tokens carries its own spaces and the newline BETWEEN two tokens is a
 *     framing artefact, not text the model emitted;
 *   - a partial read is buffered rather than emitted. Reads hand back whatever
 *     bytes have arrived, so a token is delivered only once its terminating
 *     newline has arrived; an unterminated remainder at EOF is flushed as one
 *     last token.
 *
 * Measured on this tree against a wrapper printing `"Para one line one.\nPara
 * one line two.\n\nPara two.\n"`:
 *
 *   CommandBackend          "Para one line one.\nPara one line two.\n\nPara two."
 *   StreamingCommandBackend "Para one line one.Para one line two.\nPara two."
 *
 * So the two protocols are still DIFFERENT and one stdout shape does not serve
 * both: a wrapper that emits prose (the `curl … | jq -r '.content[0].text'`
 * shape {@see CommandBackend}'s own docblock recommends) loses every single
 * newline through this class, and its blank lines come back as ONE newline
 * rather than two — so a paragraph break, a list and a code fence do not
 * survive the trip. A word-per-line streaming wrapper and a prose wrapper are
 * genuinely different protocols; neither can stand in for the other, and this
 * class does not try to guess which one it was handed. That is why the two have
 * separate env vars — `$CANDY_CODE_BACKEND_CMD` selects {@see CommandBackend}
 * and `$CANDY_CODE_BACKEND_CMD_STREAM` selects this one
 * ({@see \SugarCraft\Code\Cli\Bootstrap::backend()}).
 *
 * The empty-line rule is what makes the table's second row a line break at all.
 * An empty line used to be DROPPED, which meant that for ANY command
 * whatsoever the body this class returned provably contained no `\n` and no
 * `\r`: the protocol could not express a list, a paragraph break or a code
 * fence, and the recommended Ollama-style wrapper below — `jq -r` prints an
 * EMPTY LINE for a `"\n"` content chunk — silently lost every line break the
 * model streamed. A blank line already counted as activity against the idle
 * deadline (it still does); it now also carries the byte it was reporting.
 *
 * Example wrapper (Ollama streaming):
 *
 *   #!/usr/bin/env bash
 *   payload=$(jq -nc --argjson h "$(cat)" \
 *     '{model: "llama3", stream: true, messages: $h}')
 *   curl -sN http://localhost:11434/api/chat \
 *     -d "$payload" \
 *     | jq -r '.message.content'  # streams one word per line
 *
 * Usage:
 *
 *   $chat = new Chat(
 *       backend: new StreamingCommandBackend(['./ollama-stream.sh']),
 *   );
 *   $chat->withStreaming(true);
 */
final class StreamingCommandBackend implements Backend, TracksActiveChildren
{
    use AbandonsActiveChildren;

    /**
     * Seconds of silence AFTER THE DIRECT CHILD HAS EXITED before its pipes
     * are abandoned and whatever was read is returned.
     *
     * NOT a completion deadline, and deliberately not derived from
     * `$idleTimeout`: this clock is armed only once the direct child is seen to
     * be gone, which is a different clock from "how long the answer is allowed to
     * take" — a completion may legitimately run tens of minutes and nothing here
     * caps that. What it bounds is the case where a DESCENDANT inherited the
     * stdout/stderr pipes and never closes them: the child is not running, so
     * EOF never arrives and the read would wait for as long as that grandchild
     * lives. ChildRun re-arms it on any byte that arrives after the exit, so a
     * descendant that is still genuinely streaming is drained rather than
     * truncated; it fires only on silence. Passed to {@see ChildRun::withExitGrace()}.
     */
    private const POST_EXIT_GRACE_SECONDS = 2.0;

    /**
     * @param string|list<string> $command Command + args. Pass a
     *                                     list to avoid shell
     *                                     escaping concerns.
     * @param int $idleTimeout Seconds the command may move NO BYTES ON ANY OF
     *        ITS THREE PIPES — it neither writes output nor accepts any more of
     *        the history on stdin — before it is terminated, or 0 (the default)
     *        for no deadline.
     *        Measured with `microtime(true)`, so a configured 1 means silence
     *        longer than 1.0s and not "somewhere in (1.0, 2.0]" — which is what
     *        a `time()`-based deadline meant, since a whole-second clock
     *        quantises the arming instant by up to a second.
     *
     *        An IDLE deadline, never a total one. This parameter used to be
     *        `$timeout = 120` and armed `time() + $timeout` once, so a
     *        completion that ran past two minutes was SIGTERMed mid-answer —
     *        and a completion legitimately runs tens of minutes when the model
     *        behind the wrapper is a reasoning model. {@see CommandBackend}
     *        caps nothing, and the shell-out tier's two halves must not
     *        disagree about how long an answer is allowed to take, so 0 is the
     *        default and parity with `CommandBackend` is what a caller gets
     *        without asking.
     *
     *        It is a constructor parameter rather than a constant so the
     *        no-total-cap property is TESTABLE in seconds: a command that
     *        keeps emitting for longer than `$idleTimeout` must still finish,
     *        which is exactly the assertion a 120-second constant cannot
     *        support in a test suite.
     *
     *        SILENCE ON THE PIPES, not silence from the child: a child that
     *        exits while a descendant holds the inherited pipes open is still
     *        silence by this definition, which is why the expiry message says
     *        pipes rather than blaming the command. Once the direct child has
     *        exited this budget stops applying (ChildRun's contract), so that
     *        case is bounded by {@see POST_EXIT_GRACE_SECONDS} whatever this is
     *        set to, and it reads as the abandoned-pipes notice rather than an
     *        expiry error, even when this is shorter than the grace.
     */
    public function __construct(
        private readonly string|array $command,
        private readonly int $idleTimeout = 0,
    ) {}

    /**
     * $onEvent is accepted and ignored: this backend streams text tokens only —
     * the external command's tool use, if any, is invisible on the wire, so
     * there is no tool lifecycle to report.
     *
     * Blocks until the child is done. A failing $onToken is rethrown here, after
     * the child has been stopped, as it always was.
     */
    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        $payload = CommandBackend::encodeHistory($history);
        if ($payload === null) {
            // UI-only (audit 15b-03), like every bodiless error string below:
            // the model never said it, so it must not be replayed as its words.
            return Message::assistant('_[error: failed to encode history]_')->withUiOnly()->withError();
        }

        // Only a failing $onToken ever cancels this source: a blocking call has
        // no caller token to forward.
        $stop = new CancellationSource();
        $sink = new StreamingTokenSink($onToken, $stop->cancel(...));

        // The pid is registered the moment the child starts and forgotten once
        // run() has reaped it, so a quit during the wait can still abandon it.
        $pid = null;
        try {
            $result = $this->child($payload, $sink, $stop->token(), function (int $started) use (&$pid): void {
                $pid = $started;
                $this->registerActivePid($started);
            })->run();
        } catch (\RuntimeException) {
            return self::spawnFailed();
        } finally {
            if ($pid !== null) {
                $this->forgetActivePid($pid);
            }
        }

        if (!$result->cancelled()) {
            $sink->flush();
        }
        $failure = $sink->failure();
        if ($failure !== null) {
            throw $failure;
        }

        return $this->finish($result, $sink);
    }

    /**
     * Starts the same child {@see complete()} runs, on the event loop instead of
     * blocking, so the caller's render/input loop keeps running for the whole
     * completion and every token reaches the screen as it lands.
     *
     * A cancelled token kills the child through ChildRun's kill ladder, reaps it,
     * and rejects with 'Request cancelled'. A token already cancelled spawns
     * nothing. A throwing $onToken also stops the child, and the promise rejects
     * with that exception. The cancel is SIGKILL at once, not the TERM grace: a
     * user abort must not wait on a child that traps TERM.
     */
    public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        $deferred = new Deferred();

        if ($cancellation?->isCancelled() === true) {
            $deferred->reject(new \RuntimeException('Request cancelled'));

            return $deferred->promise();
        }

        $payload = CommandBackend::encodeHistory($history);
        if ($payload === null) {
            $deferred->resolve(Message::assistant('_[error: failed to encode history]_')->withUiOnly()->withError());

            return $deferred->promise();
        }

        // ONE source for everything that stops the child: the caller's token
        // (forwarded through its listener, detached at settle) and a failing
        // $onToken. ChildRun observes only one token, so the two are merged here.
        $stop = new CancellationSource();
        $pid = null;
        $detach = $cancellation?->onCancel(static function () use (&$pid, $stop): void {
            // SIGKILL at once, not the TERM grace: a user abort must not wait on a
            // child that traps TERM. ChildRun's ladder then finds the child gone and
            // only reaps it.
            if ($pid !== null) {
                ProcessContainment::terminatePid($pid, 9);
            }
            $stop->cancel();
        });
        $sink = new StreamingTokenSink($onToken, $stop->cancel(...));

        $child = $this->child($payload, $sink, $stop->token(), function (int $started) use (&$pid): void {
            $pid = $started;
            $this->registerActivePid($started);
        });

        // The promise rejects only when the child cannot be started, and resolves
        // once it is done, cancelled or not; the registry entry goes at settle.
        $child->launch(Loop::get())->then(
            function (ChildResult $result) use ($deferred, $sink, $detach, &$pid): void {
                if ($detach !== null) {
                    $detach();
                }
                if ($pid !== null) {
                    $this->forgetActivePid($pid);
                }
                if (!$result->cancelled()) {
                    $sink->flush();
                }
                $failure = $sink->failure();
                if ($failure !== null) {
                    $deferred->reject($failure);

                    return;
                }
                if ($result->cancelled()) {
                    $deferred->reject(new \RuntimeException('Request cancelled'));

                    return;
                }
                $deferred->resolve($this->finish($result, $sink));
            },
            function (\Throwable $e) use ($deferred, $detach): void {
                if ($detach !== null) {
                    $detach();
                }
                $deferred->resolve(self::spawnFailed());
            },
        );

        return $deferred->promise();
    }

    /**
     * The one ChildRun both entry points run: the shared spawn of
     * {@see CommandBackend::childRun()}, with this backend's exit grace, its
     * optional idle budget, the stdout feed into $sink, and the cancellation.
     *
     * @param \Closure(int): void $onSpawn Told the child's pid right after it starts
     */
    private function child(string $payload, StreamingTokenSink $sink, \SugarCraft\Async\CancellationToken $stop, \Closure $onSpawn): ChildRun
    {
        return CommandBackend::childRun($this->command, $payload)
            ->withExitGrace(self::POST_EXIT_GRACE_SECONDS)
            ->withIdleTimeout($this->idleTimeout > 0 ? (float) $this->idleTimeout : null)
            ->withOnChunk(static function (int|string $slot, string $chunk) use ($sink): void {
                // Slot 1 is stdout, the protocol. Stderr is kept whole on the result.
                if ($slot === 1) {
                    $sink->offer($chunk);
                }
            })
            ->withCancellation($stop)
            ->withOnSpawn($onSpawn);
    }

    /**
     * Turn the child's result and the tokens it streamed into the assistant
     * message. Shared by both entry points, so an expiry notice or a non-zero exit
     * reads identically whether the caller blocked or awaited a promise.
     */
    private function finish(ChildResult $result, StreamingTokenSink $sink): Message
    {
        $body = $sink->body();

        if ($result->timedOut()) {
            // The tokens already went to `$onToken`, so the user has SEEN
            // them; replacing them with an error message would delete an
            // answer they watched arrive and paid for.
            if ($body !== '') {
                return Message::assistant(
                    $body
                    . "\n\n_[notice: no output on the command's pipes for more than {$this->idleTimeout}s;"
                    . ' the command was terminated and the text above is what it had produced]_',
                );
            }

            // NOT "the command produced no output": the silent party may be a
            // descendant holding the pipes open after the command itself
            // exited, and the command exiting does not end that silence.
            return Message::assistant(
                "_[error: no output on the streaming backend's pipes for more than {$this->idleTimeout}s]_",
            )->withUiOnly()->withError();
        }

        $exit = $result->exitCode ?? -1;
        if ($exit !== 0) {
            $tail = trim($result->stderr);
            $hint = $tail === '' ? '' : "\n\n```\n{$tail}\n```";

            return Message::assistant("_[error: streaming backend exited {$exit}]_{$hint}")->withUiOnly()->withError();
        }

        if ($result->abandonedPipes()) {
            $grace = self::POST_EXIT_GRACE_SECONDS;
            $notice = "_[notice: the command exited but something it spawned still holds its output"
                . " pipes open; stopped reading after {$grace}s of silence]_";

            // With a body the row is the model's partial answer plus a notice,
            // which stays agent-visible; the notice alone is the app's.
            return $body === ''
                ? Message::assistant($notice)->withUiOnly()
                : Message::assistant($body . "\n\n" . $notice);
        }

        return Message::assistant($body);
    }

    /** The message for a streaming command that could not be started. */
    private static function spawnFailed(): Message
    {
        return Message::assistant('_[error: failed to spawn streaming backend command]_')->withUiOnly()->withError();
    }
}
