<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Message;

/**
 * Streaming-capable backend that shells out to an external command and calls
 * the `$onToken` callback once per COMPLETE line of stdout, as it arrives.
 *
 * WHAT "AS IT ARRIVES" MEANS. Both halves — the callback AND the display — are
 * now true on the {@see completeAsync()} path. They were not always; this
 * paragraph used to withdraw the display half as false, and the withdrawal was
 * correct at the time.
 *
 * MEASURED THEN, `completeAsync()` driven under a live `Loop::run()` with a
 * 50ms periodic timer standing in for the render tick, against a wrapper
 * emitting six tokens 300ms apart: six `$onToken` calls at 0.005s / 0.304s /
 * 0.608s / 0.907s / 1.210s / 1.514s — the callback really did fire per token —
 * and ZERO timer ticks in that 1.81s. `complete()` read the pipes in a
 * synchronous loop and `completeAsync()` ran the whole of it inside one
 * `Loop::futureTick`, so the ReactPHP loop was blocked for the duration and
 * `Chat`'s `withTick` subscription — the thing that turns a `TokenDelta` into
 * text on screen — could not run until the completion had already resolved.
 * What a caller got was a per-token callback plus a single repaint at the end.
 *
 * MEASURED NOW, same wrapper and same 50ms observer: the same six `$onToken`
 * calls at the same instants, and 36 timer ticks interleaved with them. The
 * read loop was not rewritten — it was HOISTED, into {@see pump()}, one
 * iteration of which is now driven either by `complete()`'s `usleep` loop or by
 * `completeAsync()`'s periodic timer. One implementation of the stdout protocol
 * below, two drivers, so the blocking and non-blocking paths cannot drift about
 * what a line means.
 *
 * On the `-p` one-shot path {@see \SugarCraft\Crush\Cli\NonInteractive::run()}
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
        $state = $this->begin($history, $onToken);
        if ($state instanceof Message) {
            return $state;
        }

        while (!$this->pump($state)) {
            // Nothing arrived this iteration, so yield — UNCONDITIONALLY. The
            // `&& $running` this guard used to carry meant that the one state
            // where the loop cannot make progress (child gone, pipe held open
            // by a descendant, `feof()` therefore false) span at 100% CPU with
            // the ReactPHP loop blocked and signals unserviced.
            if (!$state['progressed']) {
                usleep(self::POLL_INTERVAL_US);
            }
        }

        return $this->finish($state);
    }

    /**
     * Spawn the child and build the state one completion carries between two
     * reads of its pipes — or the assistant message that says why it could not
     * start.
     *
     * The state is an array and {@see pump()} takes it BY REFERENCE, rather
     * than a set of local variables in one long method, for a single reason:
     * {@see complete()} and {@see completeAsync()} must run the identical
     * protocol. Every rule in this class's docblock — an empty line is a
     * literal newline, a partial read is buffered until its terminator lands,
     * the idle deadline measures silence, the post-exit grace bounds a
     * descendant holding the pipes — lives in `pump()` exactly once, and the
     * two entry points differ only in what makes the next iteration happen.
     *
     * @param list<Message> $history
     *
     * @return array<string,mixed>|Message
     */
    private function begin(array $history, ?callable $onToken): array|Message
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
        $pipes = [];
        $proc = @proc_open($this->command, $descriptor, $pipes);
        if (!is_resource($proc)) {
            return Message::assistant('_[error: failed to spawn streaming backend command]_');
        }

        // ALL THREE non-blocking, stdin included. stdout and stderr so bytes
        // can be read as they arrive; stdin because a blocking `fwrite()` of a
        // history larger than the kernel's ~64K pipe buffer parks until the
        // child drains it, and a wrapper that reads its stdin only after it has
        // finished answering parks it for the whole completion — a deadlock in
        // `complete()` and a frozen terminal in `completeAsync()`, both before
        // the read loop this class is built around ever starts. The payload is
        // now written a slice at a time from the same iteration that drains
        // stdout, so a full pipe costs an iteration rather than the turn.
        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'proc' => $proc,
            'pipes' => $pipes,
            'onToken' => $onToken,
            // Bytes of the history not yet accepted by the child's stdin.
            'stdin' => $payload,
            'stderr' => '',
            'tokens' => [],
            // Bytes read from stdout that do not yet end in a newline. Held
            // back so one line is one token: a read boundary in the middle of a
            // line used to emit both halves separately AND, when the boundary
            // landed on a `\r`, delete it — `a\rb\n` came back as `ab` or as
            // `a\rb` depending on nothing but timing.
            'partial' => '',
            // Re-armed by every byte that moves on ANY of the three pipes, so
            // the deadline below measures SILENCE and never elapsed time. A
            // wrapper that streams for an hour never trips it; nor does one
            // still swallowing a large prompt; one that wedges without exiting
            // does. See {@see pump()} for why stdin has to count.
            'lastOutputAt' => microtime(true),
            // When the direct child was first seen to be gone, or null while it
            // still runs. Arms POST_EXIT_GRACE_SECONDS, nothing else.
            'exitedAt' => null,
            'running' => true,
            'progressed' => false,
            'expired' => false,
            'abandoned' => false,
        ];
    }

    /**
     * ONE iteration of the read loop: push whatever stdin the child will take,
     * drain both output pipes, emit every token whose terminator has arrived,
     * and decide whether this completion is over.
     *
     * Returns true when the caller must stop iterating — the child exited and
     * both pipes reached EOF, the idle deadline expired, or the post-exit grace
     * ran out on a descendant holding the pipes. Sets `$state['progressed']` so
     * a driver can tell an iteration that moved bytes — in EITHER direction —
     * from one that should yield before trying again.
     *
     * @param array<string,mixed> $state
     */
    private function pump(array &$state): bool
    {
        $pipes = $state['pipes'];

        // Offer the child more of the history. `false` from `fwrite()` is a
        // broken pipe — the child closed stdin or died — so stop offering
        // rather than re-presenting the same bytes every iteration forever.
        $stdinBytes = 0;
        if ($state['stdin'] !== '' && is_resource($pipes[0])) {
            $written = @fwrite($pipes[0], $state['stdin']);
            if ($written !== false) {
                $stdinBytes = $written;
            }
            $state['stdin'] = $written === false ? '' : substr($state['stdin'], $written);
            if ($state['stdin'] === '') {
                fclose($pipes[0]);
            }
        }

        $stdoutBytes = 0;
        while (($chunk = fread($pipes[1], 65536)) !== false && $chunk !== '') {
            $stdoutBytes += strlen($chunk);
            $state['partial'] .= $chunk;
        }

        // Emit only lines whose terminator has arrived.
        while (($newline = strpos($state['partial'], "\n")) !== false) {
            $line = substr($state['partial'], 0, $newline);
            $state['partial'] = substr($state['partial'], $newline + 1);
            $token = self::tokenForLine($line);
            $state['tokens'][] = $token;
            if ($state['onToken'] !== null) {
                ($state['onToken'])($token);
            }
        }

        $stderrBytes = 0;
        while (($chunk = fread($pipes[2], 65536)) !== false && $chunk !== '') {
            $stderrBytes += strlen($chunk);
            $state['stderr'] .= $chunk;
        }

        $state['progressed'] = $stdinBytes > 0 || $stdoutBytes > 0 || $stderrBytes > 0;
        // A blank line counts as activity, as it always did — the child is
        // alive and writing, which is the only question the idle deadline
        // asks. It now also survives as a `"\n"` token.
        //
        // BYTES THE CHILD TOOK ON STDIN COUNT TOO, and the deadline is wrong
        // without them. The blocking `fwrite()` this loop replaced returned
        // only once the WHOLE history had been handed over, and the old code
        // armed `$lastOutputAt` after it — so the deadline has always meant
        // "silence since the prompt was delivered". Arming it at spawn and
        // re-arming it on output alone would silently redefine it as "silence
        // since spawn", and a healthy child that is still reading a large
        // prompt is silent by that definition: MEASURED, a wrapper doing
        // `read -r -n 65536; sleep 0.5` over a 512 KB history with
        // `idleTimeout: 2` died at 2.01s where the blocking write finished at
        // 4.51s and answered. A child that accepts nothing AND says nothing
        // still trips it, which is the wedge the deadline is for.
        if ($state['progressed']) {
            $state['lastOutputAt'] = microtime(true);
        }

        if ($state['running']) {
            $status = proc_get_status($state['proc']);
            if (!$status['running']) {
                $state['running'] = false;
                $state['exitedAt'] = microtime(true);
            }
        }

        // Child gone AND both pipes exhausted.
        if (!$state['running'] && feof($pipes[1]) && feof($pipes[2])) {
            return true;
        }

        // Opt-in only, and reported in the unit the caller configured.
        // The message this replaced said "timed out after {$iterations}
        // iterations" — a loop counter handed to someone who had
        // configured seconds.
        if ($this->idleTimeout > 0 && microtime(true) - $state['lastOutputAt'] > (float) $this->idleTimeout) {
            $state['expired'] = true;

            return true;
        }

        // The child is gone but a pipe is not at EOF, which only a
        // DESCENDANT holding the inherited fd can cause. Bounded here
        // rather than left to `$idleTimeout`, whose default is 0 and which
        // Bootstrap passes nothing for: without this branch the `return true`
        // above is the only exit this loop has, and it can never fire.
        if ($state['exitedAt'] !== null
            && microtime(true) - max($state['exitedAt'], $state['lastOutputAt']) > self::POST_EXIT_GRACE_SECONDS) {
            $state['abandoned'] = true;

            return true;
        }

        return false;
    }

    /**
     * Reap the child and turn the accumulated tokens into the assistant
     * message. Shared by both drivers, so an expiry notice or a non-zero exit
     * reads identically whether the caller blocked or awaited a promise.
     *
     * @param array<string,mixed> $state
     */
    private function finish(array &$state): Message
    {
        if ($state['expired']) {
            self::terminateAndReap($state['proc']);
        }

        // The bytes of an unterminated last line were still paid for.
        if ($state['partial'] !== '') {
            $token = rtrim($state['partial'], "\r");
            $state['partial'] = '';
            if ($token !== '') {
                $state['tokens'][] = $token;
                if ($state['onToken'] !== null) {
                    ($state['onToken'])($token);
                }
            }
        }

        foreach ($state['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        // And REAP. The old timeout branch signalled and returned, and
        // MEASURED on PHP 8.3: the pipes are fine either way — freeing the
        // local `$pipes` array on return closes them, `/proc/self/fd` does not
        // grow across repeated expiries — but the CHILD was not waited for:
        // own-zombie count went 0 → 1 → 2 → 3 across three expiries without
        // this call on the expiry path, and did not move with it. Descriptors
        // were never the leak; the process was.
        $exit = proc_close($state['proc']);

        $body = trim(implode('', $state['tokens']));

        if ($state['expired']) {
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
            $tail = trim($state['stderr']);
            $hint = $tail === '' ? '' : "\n\n```\n{$tail}\n```";

            return Message::assistant("_[error: streaming backend exited {$exit}]_{$hint}");
        }

        if ($state['abandoned']) {
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

    /**
     * Drives the SAME {@see pump()} {@see complete()} does, from a periodic
     * timer on the event loop instead of from a `usleep` loop, so the caller's
     * render/input loop keeps running for the whole completion and every token
     * reaches the screen as it lands rather than all at once at the end.
     *
     * WHAT WAS WRONG. This method used to schedule one `Loop::futureTick()` and
     * call the fully synchronous `complete()` inside it. The `futureTick` bought
     * exactly one thing — the promise was returned before the work started —
     * and nothing else: the tick's callback then blocked the loop for the whole
     * round-trip. See the class docblock for the before/after measurement (zero
     * observer ticks in 1.81s, versus 36 across the same span now). It is the
     * same defect {@see EngineBackend::completeAsync()} was fixed for on the
     * primary backend, and it is why a `$SUGARCRUSH_BACKEND_CMD_STREAM` user's
     * spinner never animated.
     *
     * WHY A TIMER AND NOT A FORK. `EngineBackend` forks, because ITS blocking
     * work is in-process — a Guzzle round-trip and the agentic tool loop — and
     * a parent has no descriptor it could watch instead. Here it does:
     * `proc_open()` already handed us a child and three pipes, and this class's
     * read loop was already non-blocking. Forking would mean a PHP process
     * whose only job is to spawn a shell and shuttle the shell's bytes back
     * over a socket pair — a second process, a second copy of the parent's
     * heap, an ext-pcntl/ext-posix availability guard and its own zombie reap,
     * all to obtain a file descriptor we were already holding. Rejected
     * alongside it: `addReadStream()` on the two pipes. That is edge-driven and
     * would beat this on latency, but child exit, the idle deadline and the
     * post-exit grace have no readable edge, so it would need a supervising
     * timer anyway — three loop registrations to do what one does, and two
     * codepaths through the protocol instead of `pump()`.
     *
     * The poll interval is the cost, and it is the interval `complete()` was
     * already spending: a token is noticed up to 5ms after its newline lands.
     *
     * ONE BOUNDED EXCEPTION TO "NEVER BLOCKS", stated rather than glossed:
     * {@see finish()} on the EXPIRED path calls {@see terminateAndReap()},
     * which polls with `usleep` for at most
     * TERMINATE_GRACE_SECONDS + KILL_GRACE_SECONDS. That is a teardown of a
     * child that has already stopped answering, on a turn that has already
     * failed, and only when a caller opted into `$idleTimeout` at all — not the
     * completion path this method exists to unblock.
     */
    public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        $deferred = new Deferred();

        if ($cancellation?->isCancelled() === true) {
            $deferred->reject(new \RuntimeException('Request cancelled'));

            return $deferred->promise();
        }

        $state = $this->begin($history, $onToken);
        if ($state instanceof Message) {
            $deferred->resolve($state);

            return $deferred->promise();
        }

        $loop = Loop::get();
        $settled = false;
        $timer = null;

        // No Loop::stop() anywhere below - this backend is constructed by
        // callers (see this class's own "Usage" docblock) and driven by
        // Program's own long-lived Loop::run(); stopping the shared global loop
        // after a single completion would kill the whole program's
        // render/input loop the moment the first reply arrived, not just this
        // one async call.
        $timer = $loop->addPeriodicTimer(
            self::POLL_INTERVAL_US / 1000000,
            function () use (&$state, &$settled, &$timer, $loop, $deferred, $cancellation): void {
                if ($settled) {
                    return;
                }

                // Kill and reap without going through finish(): an aborted turn
                // has no message to build and the child must not outlive it.
                // Signal 9 rather than terminateAndReap()'s graceful escalation
                // because a cancel is a user abort, and `proc_close()` WAITS —
                // anything gentler hands this loop's deadline to a child that
                // has already been given up on.
                $abort = static function () use (&$state, &$settled, &$timer, $loop): void {
                    $settled = true;
                    if ($timer !== null) {
                        $loop->cancelTimer($timer);
                    }
                    @proc_terminate($state['proc'], 9);
                    foreach ($state['pipes'] as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    proc_close($state['proc']);
                };

                try {
                    // Polled rather than checked once up front: Chat's
                    // double-Escape flips this token long after this closure
                    // was built, which is the whole point of a shared mutable
                    // flag, and a check that only ran before the spawn could
                    // never see it. Same shape as
                    // {@see EngineBackend::completeAsync()}'s cancel timer.
                    if ($cancellation?->isCancelled() === true) {
                        $abort();
                        $deferred->reject(new \RuntimeException('Request cancelled'));

                        return;
                    }

                    if (!$this->pump($state)) {
                        return;
                    }

                    $settled = true;
                    $loop->cancelTimer($timer);
                    $deferred->resolve($this->finish($state));
                } catch (\Throwable $e) {
                    // Reached when a caller's own $onToken throws from inside
                    // pump(). The child is mid-answer and nobody is left to
                    // read it, so it is killed rather than orphaned.
                    if (!$settled) {
                        $abort();
                    }
                    $deferred->reject($e);
                }
            },
        );

        return $deferred->promise();
    }
}
