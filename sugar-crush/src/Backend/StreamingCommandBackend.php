<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Message;

/**
 * Streaming-capable backend that shells out to an external command and calls
 * the `$onToken` callback once per COMPLETE line of stdout, as it arrives.
 *
 * WHAT "AS IT ARRIVES" MEANS, AND WHAT IT DOES NOT. The callback half is true.
 * The DISPLAY half, which this paragraph used to assert as "real-time
 * token-by-token display in the SugarCrush UI", is FALSE and is withdrawn.
 *
 * MEASURED on this tree, `completeAsync()` driven under a live `Loop::run()`
 * with a 50ms periodic timer standing in for the render tick, against a wrapper
 * emitting six tokens 300ms apart: SIX `$onToken` calls, at 0.006s / 0.306s /
 * 0.612s / 0.912s / 1.212s / 1.512s — so the callback really does fire per
 * token, as each token's newline lands on the pipe — and ZERO timer ticks in
 * that window. {@see complete()} reads the pipes in a SYNCHRONOUS loop and
 * {@see completeAsync()} runs the whole of it inside one `Loop::futureTick`, so
 * the ReactPHP loop is blocked for the duration of the completion, and `Chat`'s
 * `withTick` subscription — the thing that turns a `TokenDelta` into text on
 * screen — cannot run until the completion has already resolved. On the `-p`
 * one-shot path {@see \SugarCraft\Crush\Cli\NonInteractive::run()} no
 * callback is passed at all. What a caller gets today is a per-token callback
 * plus a single repaint at the end.
 *
 * Rewriting the read loop non-blocking (`Loop::addReadStream`) is an
 * architectural change to an optional tier and is recorded in the hardening
 * backlog rather than attempted here. The callback and its plumbing stay
 * exactly as they are: that is the half that already works, and an unused
 * capability in this project gets completed or documented, never deleted.
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
 *   - a partial read is buffered rather than emitted. `fgets()`/`fread()` on a
 *     non-blocking pipe hand back whatever bytes have arrived, so a token is
 *     delivered only once its terminating newline has arrived; an unterminated
 *     remainder at EOF is flushed as one last token.
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
 * separate env vars — `$SUGARCRUSH_BACKEND_CMD` selects {@see CommandBackend}
 * and `$SUGARCRUSH_BACKEND_CMD_STREAM` selects this one
 * ({@see \SugarCraft\Crush\Cli\Bootstrap::backend()}).
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
final class StreamingCommandBackend implements Backend
{
    /**
     * How long a no-progress iteration sleeps before looking at the pipes
     * again. Unconditional: an iteration that read nothing must yield, whether
     * or not the child is still running — see {@see complete()}.
     */
    private const POLL_INTERVAL_US = 5000;

    /**
     * Seconds of silence AFTER THE DIRECT CHILD HAS EXITED before its pipes
     * are abandoned and whatever was read is returned.
     *
     * NOT a completion deadline, and deliberately not derived from
     * `$idleTimeout`: this clock is armed only once `proc_get_status()` says
     * the direct child is gone, which is a different clock from "how long the
     * answer is allowed to take" — a completion may legitimately run tens of
     * minutes and nothing here caps that. What it bounds is the case where a
     * DESCENDANT inherited the stdout/stderr pipes and never closes them: the
     * child is not running, so `feof()` never becomes true, so the drain loop's
     * exit condition can never be satisfied and the read loop would spin for as
     * long as that grandchild lives. Re-armed by any byte that arrives after
     * the exit, so a descendant that is still genuinely streaming is drained
     * rather than truncated; it fires only on silence.
     */
    private const POST_EXIT_GRACE_SECONDS = 2.0;

    /**
     * Seconds a SIGTERMed child gets to exit on its own before it is sent
     * signal 9.
     *
     * `proc_close()` WAITS, so without this escalation a child that ignores
     * SIGTERM holds the expiry path open for as long as it likes. MEASURED on
     * this tree with the command string `"trap '' TERM; cat > /dev/null; sleep
     * 8"` and `$idleTimeout = 1`: 8.00s to return without the escalation,
     * 2.01s with it (the deadline, then this grace, then signal 9).
     *
     * The trap has to be in the DIRECT child for that: the same trap written
     * inside a *script file* does not reproduce it, because `proc_open`'s
     * direct child is then the `sh -c` that runs the script and `sh` does not
     * ignore the signal — it dies in ~50ms and orphans the script.
     */
    private const TERMINATE_GRACE_SECONDS = 1.0;

    /** Seconds to wait for a signal-9'd child before calling `proc_close()` anyway. */
    private const KILL_GRACE_SECONDS = 1.0;

    /**
     * @param string|list<string> $command Command + args. Pass a
     *                                     list to avoid shell
     *                                     escaping concerns.
     * @param int $idleTimeout Seconds the command may produce NO OUTPUT ON ITS
     *        PIPES before it is terminated, or 0 (the default) for no deadline.
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
     *        pipes rather than blaming the command. That case is separately
     *        bounded by {@see POST_EXIT_GRACE_SECONDS} even when this is 0.
     */
    public function __construct(
        private readonly string|array $command,
        private readonly int $idleTimeout = 0,
    ) {}

    /**
     * $onEvent is accepted and ignored: this backend streams text tokens only —
     * the external command's tool use, if any, is invisible on the wire, so
     * there is no tool lifecycle to report.
     */
    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
    {
        $payload = json_encode(
            array_map(static fn(Message $m) => $m->toWire(), $history),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if ($payload === false) {
            return Message::assistant('_[error: failed to encode history]_');
        }

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // NO `proc_open` options, for either command shape — the same as
        // {@see CommandBackend}, whose docblock makes the same escaping promise
        // in the same words and must not disagree with this one.
        //
        // `bypass_shell` is a WINDOWS-ONLY option, and it used to be passed
        // here for both shapes behind a ternary whose two arms were identical
        // (`is_array($c) ? $c : $c`), i.e. a distinction the author reached for
        // and never made. MEASURED on PHP 8.3 / Linux: inert for both shapes —
        // with it set, the string `"printf a; printf b"` still went through
        // `/bin/sh -c` and printed `ab`, and the list `["printf", "a;b"]` still
        // exec'd directly and printed `a;b`, both byte-identical to the same
        // calls without it. A LIST does not need it in the first place: passing
        // an array means PHP opens the process directly, WITHOUT going through
        // a shell, and escapes the arguments itself (PHP 7.4 UPGRADING, "the
        // process will be opened directly … PHP will take care of any
        // necessary argument escaping"). Nothing is claimed here about what
        // Windows does with a string command; neither of us can run it.
        $proc = @proc_open($this->command, $descriptor, $pipes);
        if (!is_resource($proc)) {
            return Message::assistant('_[error: failed to spawn streaming backend command]_');
        }

        // Set stdout to non-blocking so we can read as bytes arrive
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);

        $stderr = '';
        $tokens = [];
        // Bytes read from stdout that do not yet end in a newline. Held back so
        // one line is one token: a read boundary in the middle of a line used
        // to emit both halves separately AND, when the boundary landed on a
        // `\r`, delete it — `a\rb\n` came back as `ab` or as `a\rb` depending
        // on nothing but timing.
        $partial = '';
        // Re-armed by every byte either pipe produces, so the deadline below
        // measures SILENCE and never elapsed time. A wrapper that streams for
        // an hour never trips it; one that wedges without exiting does.
        $lastOutputAt = microtime(true);
        // When the direct child was first seen to be gone, or null while it
        // still runs. Arms POST_EXIT_GRACE_SECONDS, nothing else.
        $exitedAt = null;
        $expired = false;
        $abandoned = false;

        // Keep reading until process exits AND both pipes are exhausted
        $running = true;
        while (true) {
            $stdoutBytes = 0;
            while (($chunk = fread($pipes[1], 65536)) !== false && $chunk !== '') {
                $stdoutBytes += strlen($chunk);
                $partial .= $chunk;
            }

            // Emit only lines whose terminator has arrived.
            while (($newline = strpos($partial, "\n")) !== false) {
                $line = substr($partial, 0, $newline);
                $partial = substr($partial, $newline + 1);
                $token = self::tokenForLine($line);
                $tokens[] = $token;
                if ($onToken !== null) {
                    $onToken($token);
                }
            }

            // Read stderr (non-blocking)
            $stderrBytes = 0;
            while (($chunk = fread($pipes[2], 65536)) !== false && $chunk !== '') {
                $stderrBytes += strlen($chunk);
                $stderr .= $chunk;
            }

            $progressed = $stdoutBytes > 0 || $stderrBytes > 0;
            // A blank line counts as activity, as it always did — the child is
            // alive and writing, which is the only question the idle deadline
            // asks. It now also survives as a `"\n"` token.
            if ($progressed) {
                $lastOutputAt = microtime(true);
            }

            // Check if process is still running
            if ($running) {
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    $running = false;
                    $exitedAt = microtime(true);
                }
            }

            // Check if we've exhausted both pipes
            if (!$running && feof($pipes[1]) && feof($pipes[2])) {
                break;
            }

            // Opt-in only, and reported in the unit the caller configured.
            // The message this replaced said "timed out after {$iterations}
            // iterations" — a loop counter handed to someone who had
            // configured seconds.
            if ($this->idleTimeout > 0 && microtime(true) - $lastOutputAt > (float) $this->idleTimeout) {
                $expired = true;
                break;
            }

            // The child is gone but a pipe is not at EOF, which only a
            // DESCENDANT holding the inherited fd can cause. Bounded here
            // rather than left to `$idleTimeout`, whose default is 0 and which
            // Bootstrap passes nothing for: without this branch the `break`
            // above is the only exit this loop has, and it can never fire.
            if ($exitedAt !== null
                && microtime(true) - max($exitedAt, $lastOutputAt) > self::POST_EXIT_GRACE_SECONDS) {
                $abandoned = true;
                break;
            }

            // Nothing arrived this iteration, so yield — UNCONDITIONALLY. The
            // `&& $running` this guard used to carry meant that the one state
            // where the loop cannot make progress (child gone, pipe held open
            // by a descendant, `feof()` therefore false) span at 100% CPU with
            // the ReactPHP loop blocked and signals unserviced.
            if (!$progressed) {
                usleep(self::POLL_INTERVAL_US);
            }
        }

        if ($expired) {
            self::terminateAndReap($proc);
        }

        // The bytes of an unterminated last line were still paid for.
        if ($partial !== '') {
            $token = rtrim($partial, "\r");
            if ($token !== '') {
                $tokens[] = $token;
                if ($onToken !== null) {
                    $onToken($token);
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        // And REAP. The old timeout branch signalled and returned, and
        // MEASURED on PHP 8.3: the pipes are fine either way — freeing the
        // local `$pipes` array on return closes them, `/proc/self/fd` does not
        // grow across repeated expiries — but the CHILD was not waited for:
        // own-zombie count went 0 → 1 → 2 → 3 across three expiries without
        // this call on the expiry path, and did not move with it. Descriptors
        // were never the leak; the process was.
        $exit = proc_close($proc);

        $body = trim(implode('', $tokens));

        if ($expired) {
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
            );
        }

        if ($exit !== 0) {
            $tail = trim($stderr);
            $hint = $tail === '' ? '' : "\n\n```\n{$tail}\n```";
            return Message::assistant("_[error: streaming backend exited {$exit}]_{$hint}");
        }

        if ($abandoned) {
            $grace = self::POST_EXIT_GRACE_SECONDS;
            $notice = "_[notice: the command exited but something it spawned still holds its output"
                . " pipes open; stopped reading after {$grace}s of silence]_";

            return Message::assistant($body === '' ? $notice : $body . "\n\n" . $notice);
        }

        return Message::assistant($body);
    }

    /**
     * One token from one COMPLETE line of stdout, its trailing `\r` removed.
     *
     * An empty line becomes a literal newline rather than being dropped: see
     * the class docblock for why the protocol is unable to express a line break
     * otherwise. Only a TERMINATED empty line means this — an empty remainder
     * at EOF is not a blank line, it is no line at all.
     */
    private static function tokenForLine(string $line): string
    {
        $token = rtrim($line, "\r");

        return $token === '' ? "\n" : $token;
    }

    /**
     * SIGTERM the child, wait a BOUNDED moment, then signal 9 — so the expiry
     * path returns in bounded time even though `proc_close()` blocks.
     *
     * Signal 9 as an integer literal, not `SIGKILL`: that constant is defined
     * by ext-pcntl, and naming it would put an optional extension's symbol on
     * an error path that must not itself fatal. Same reason the SIGTERM above
     * is `proc_terminate()`'s own default rather than a named 15.
     *
     * This signals only the DIRECT child. A string command's direct child is
     * the shell, so its own children are ORPHANED rather than killed — the
     * grandchildren of `sh -c 'curl … | jq …'` keep running and, if they
     * inherited the pipes, keep them open. Nothing here kills a process tree;
     * what it guarantees is that this method returns.
     */
    private static function terminateAndReap($proc): void
    {
        proc_terminate($proc);

        if (self::waitForExit($proc, self::TERMINATE_GRACE_SECONDS)) {
            return;
        }

        proc_terminate($proc, 9);
        // Unchecked on purpose: after signal 9 the only way to still be running
        // is an uninterruptible kernel wait, and `proc_close()` below is then
        // the least-bad option left — there is no non-blocking reap available
        // without ext-pcntl, and leaking the handle would leak the zombie the
        // reap exists to prevent.
        self::waitForExit($proc, self::KILL_GRACE_SECONDS);
    }

    /**
     * Poll `proc_get_status()` until the child is gone or the budget runs out;
     * true if it exited. A bounded poll rather than a blocking wait, for the
     * same reason {@see \SugarCraft\Crush\Runtime::reapKilled()} uses `WNOHANG`:
     * an unflagged wait hands the caller's deadline to the child.
     */
    private static function waitForExit($proc, float $budgetSeconds): bool
    {
        $deadline = microtime(true) + $budgetSeconds;

        do {
            if (!proc_get_status($proc)['running']) {
                return true;
            }
            usleep(self::POLL_INTERVAL_US);
        } while (microtime(true) < $deadline);

        return !proc_get_status($proc)['running'];
    }

    public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        return new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($history, $onToken, $cancellation): void {
            Loop::futureTick(function () use ($history, $onToken, $resolve, $reject, $cancellation): void {
                if ($cancellation?->isCancelled() === true) {
                    $reject(new \RuntimeException('Request cancelled'));

                    return;
                }
                try {
                    $message = $this->complete($history, $onToken);
                    $resolve($message);
                } catch (\Throwable $e) {
                    $reject($e);
                }
                // No Loop::stop() here - this backend is constructed by
                // callers (see this class's own "Usage" docblock) and driven
                // by Program's own long-lived Loop::run(); stopping the
                // shared global loop after a single completion would kill
                // the whole program's render/input loop the moment the
                // first reply arrived, not just this one async call.
            });
        });
    }
}
