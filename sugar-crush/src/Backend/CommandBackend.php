<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Message;

/**
 * Backend that shells out to an external command. The command
 * receives the JSON-encoded history on stdin and writes the
 * assistant reply to stdout.
 *
 * This is the recommended starting point for hooking SugarCrush
 * to a real LLM: write a small wrapper script in any language,
 * make it executable, point this backend at it. Keeps the PHP
 * core network-dep-free while still letting users plug in
 * anything that has a CLI.
 *
 * Example wrapper (Anthropic via curl + jq, in bash):
 *
 *   #!/usr/bin/env bash
 *   payload=$(jq -nc --argjson h "$(cat)" \
 *     '{model: "claude-opus-4-7", max_tokens: 4096, messages: $h}')
 *   curl -sN https://api.anthropic.com/v1/messages \
 *     -H "x-api-key: $ANTHROPIC_API_KEY" \
 *     -H "anthropic-version: 2023-06-01" \
 *     -H "content-type: application/json" \
 *     -d "$payload" \
 *     | jq -r '.content[0].text'
 *
 * `proc_open` is used so stdin/stdout are wired cleanly and the
 * process exit code is captured. A non-zero exit returns an
 * "[error: …]" assistant message rather than throwing; backend
 * failures shouldn't crash the chat shell.
 *
 * STDOUT IS RETURNED WITH EXACTLY ONE TRANSFORMATION APPLIED: `trim()`, at the
 * two ends. Everything INSIDE survives — every newline, every blank line,
 * every indent — which is what makes the wrapper above usable. What does not
 * survive is whitespace at the ends, and the claim is stated with its
 * exception because the exception is reachable: an indented FIRST line loses
 * its indentation (so a reply that opens with a four-space code block opens
 * with prose instead), a trailing newline is dropped, and a reply consisting
 * only of whitespace comes back empty. The trim stays deliberately — a
 * wrapper's `echo` adds a trailing newline nobody wants rendered, and every
 * caller of this class renders the content as markdown.
 *
 * {@see StreamingCommandBackend} deliberately does NOT do this — it treats one
 * terminated line as one token, an empty line as a literal newline, and joins
 * with the empty string — so the wrapper above is not interchangeable between
 * the two, and each has its own env var (`$SUGARCRUSH_BACKEND_CMD` here,
 * `$SUGARCRUSH_BACKEND_CMD_STREAM` there). There is also no completion
 * deadline of any kind on this path, deliberately; a completion can
 * legitimately run tens of minutes.
 *
 * {@see complete()} BLOCKS, by contract — it is what the `-p` one-shot path
 * ({@see \SugarCraft\Crush\Cli\NonInteractive::run()}) wants and it parks at 0%
 * CPU in a blocking read. {@see completeAsync()} does NOT, and used to: it
 * called `complete()` straight from its Promise executor, which the Promise
 * constructor runs IMMEDIATELY rather than deferring, so a `$SUGARCRUSH_BACKEND_CMD`
 * user's terminal froze for the whole round-trip — no spinner, no keystrokes,
 * no Escape. See that method for how the same child is now drained from the
 * event loop instead.
 */
final class CommandBackend implements Backend
{
    /**
     * How long a no-progress iteration of {@see completeAsync()}'s drain waits
     * before looking at the pipes again. Matches
     * {@see StreamingCommandBackend}'s own poll interval, so the shell-out
     * tier's two halves agree about how promptly a child's output is noticed.
     */
    private const POLL_INTERVAL_SECONDS = 0.005;

    private const DESCRIPTOR = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    /**
     * @param string|list<string> $command Command + args. Pass a
     *                                     list to avoid shell
     *                                     escaping concerns.
     */
    public function __construct(private readonly string|array $command)
    {}

    /**
     * $onEvent is accepted and ignored: the external command owns whatever tool
     * use happens on its side and reports nothing back but final text, so this
     * backend has no tool lifecycle it can honestly emit.
     */
    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
    {
        $payload = self::encodeHistory($history);
        if ($payload === null) {
            return Message::assistant('_[error: failed to encode history]_');
        }

        $spawned = $this->spawn();
        if ($spawned instanceof Message) {
            return $spawned;
        }
        [$proc, $pipes] = $spawned;

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        // `=== false` and not `?:`. `stream_get_contents()` returns
        // `string|false`, and `"0" ?: ''` is `''` in PHP — so a wrapper whose
        // ENTIRE reply is the single character `0` with no trailing newline
        // used to come back as an empty assistant message. Same for a stderr
        // tail of `"0"` on the failure path below.
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        if ($stdout === false) {
            $stdout = '';
        }
        if ($stderr === false) {
            $stderr = '';
        }
        fclose($pipes[1]);
        fclose($pipes[2]);

        return self::finish(proc_close($proc), $stdout, $stderr);
    }

    /**
     * Drains the SAME child {@see complete()} spawns, but from the event loop
     * instead of from a blocking read, so the caller's render/input loop keeps
     * running for the whole round-trip.
     *
     * WHAT WAS WRONG. This method used to be a `React\Promise\Promise` whose
     * executor called `complete()` on line one. A Promise executor runs
     * IMMEDIATELY — that is the constructor's contract, not a deferral — so the
     * blocking `proc_open` plus blocking pipe reads happened before the promise
     * was even returned to `Program`'s `futureTick`-scheduled Cmd runner. A
     * `$SUGARCRUSH_BACKEND_CMD` user therefore got a terminal frozen for the
     * entire completion: no spinner animation, no keystrokes, no Escape-Escape
     * abort. MEASURED on this tree against a wrapper emitting six lines 300ms
     * apart, with a 50ms periodic timer standing in for the render tick: 1.81s
     * elapsed and ZERO timer ticks in that window. It is the identical defect
     * {@see EngineBackend::completeAsync()} was fixed for on the primary
     * backend.
     *
     * WHY A TIMER AND NOT A FORK. `EngineBackend` forks, because ITS blocking
     * work is in-process — a Guzzle round-trip and the agentic tool loop — and
     * there is no descriptor a parent could watch instead. Here there is:
     * `proc_open()` already handed us a child and three pipes. Forking would
     * mean a PHP process whose only job is to spawn a shell and shuttle bytes
     * back over a socket pair — a second process, a second copy of the parent's
     * heap, an ext-pcntl/ext-posix availability guard, and its own zombie-reap
     * problem, all to obtain a file descriptor we were already holding. So this
     * drains the pipes it already has from an `addPeriodicTimer` — the same
     * non-blocking-poll-from-the-loop shape
     * {@see \SugarCraft\Crush\Chat::waitForToolChildrenAsync()} uses, with
     * `proc_get_status()` where that one has `pcntl_waitpid(WNOHANG)` because
     * `proc_open()` owns this child's handle. Rejected alongside the fork:
     * `addReadStream()` on the two pipes,
     * which is edge-driven and would be a better fit if output were the only
     * thing to watch — but child exit has no readable edge, so it would need a
     * supervising timer anyway, i.e. three loop registrations to do what one
     * does.
     *
     * The poll interval is the cost: a token is noticed up to 5ms after it
     * lands. That is invisible next to a provider's own latency and it is the
     * same interval {@see StreamingCommandBackend::complete()} already spends.
     *
     * ALL THREE PIPES GO NON-BLOCKING, stdin included. `complete()`'s blocking
     * `fwrite()` of a history larger than the kernel's ~64K pipe buffer parks
     * until the child reads it, and a wrapper that reads stdin only after it
     * has finished answering parks it for the whole completion — the same
     * freeze one syscall earlier. Here the payload is written a slice at a time
     * from the same tick that drains stdout, so a full pipe costs a tick rather
     * than the turn.
     *
     * $onToken is accepted and ignored for the same reason it is in
     * `complete()`: this backend's protocol delivers one final blob of stdout,
     * so there is nothing to stream. {@see StreamingCommandBackend} is the half
     * of this tier that has tokens.
     */
    public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        $deferred = new Deferred();

        if ($cancellation?->isCancelled() === true) {
            $deferred->reject(new \RuntimeException('Request cancelled'));

            return $deferred->promise();
        }

        $payload = self::encodeHistory($history);
        if ($payload === null) {
            $deferred->resolve(Message::assistant('_[error: failed to encode history]_'));

            return $deferred->promise();
        }

        $spawned = $this->spawn();
        if ($spawned instanceof Message) {
            $deferred->resolve($spawned);

            return $deferred->promise();
        }
        [$proc, $pipes] = $spawned;

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $loop = Loop::get();
        $stdout = '';
        $stderr = '';
        $running = true;
        $settled = false;
        $timer = null;

        $timer = $loop->addPeriodicTimer(
            self::POLL_INTERVAL_SECONDS,
            function () use (&$payload, &$stdout, &$stderr, &$running, &$settled, &$timer, $proc, $pipes, $loop, $deferred, $cancellation): void {
                if ($settled) {
                    return;
                }

                // Closes the pipes and reaps, so neither a descriptor nor a
                // zombie outlives the turn however it ends. $signal is 0 on the
                // success path (the child is already gone) and 9 on the abort
                // path — a cancel is a user abort, not a graceful shutdown, and
                // `proc_close()` WAITS, so anything gentler hands this loop's
                // deadline to a child that has already been given up on.
                $reap = static function (int $signal) use (&$settled, &$timer, $proc, $pipes, $loop): int {
                    $settled = true;
                    if ($timer !== null) {
                        $loop->cancelTimer($timer);
                    }
                    if ($signal !== 0) {
                        @proc_terminate($proc, $signal);
                    }
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }

                    return proc_close($proc);
                };

                try {
                    if ($payload !== '' && is_resource($pipes[0])) {
                        $written = @fwrite($pipes[0], $payload);
                        // `false` is a broken pipe — the child closed stdin or
                        // died. Nothing left to say to it; stop trying rather
                        // than re-offering the same bytes every 5ms forever.
                        $payload = $written === false ? '' : substr($payload, $written);
                        if ($payload === '') {
                            fclose($pipes[0]);
                        }
                    }

                    while (($chunk = fread($pipes[1], 65536)) !== false && $chunk !== '') {
                        $stdout .= $chunk;
                    }
                    while (($chunk = fread($pipes[2], 65536)) !== false && $chunk !== '') {
                        $stderr .= $chunk;
                    }

                    // Polled rather than checked once up front: Chat's
                    // double-Escape flips this token long after this closure was
                    // built, which is the whole point of a shared mutable flag,
                    // and a check that only ran before the spawn could never see
                    // it. Same shape as {@see EngineBackend::completeAsync()}'s
                    // cancel timer.
                    if ($cancellation?->isCancelled() === true) {
                        $reap(9);
                        $deferred->reject(new \RuntimeException('Request cancelled'));

                        return;
                    }

                    if ($running && !proc_get_status($proc)['running']) {
                        $running = false;
                    }

                    // Both conditions, not just the exit: a wrapper's last write
                    // can still be sitting in the pipe after it exits. Reading to
                    // EOF is exactly what `complete()`'s `stream_get_contents()`
                    // does, so the two paths return the same bytes. It also means
                    // a DESCENDANT that inherited the pipes and never closes them
                    // holds this open — unbounded, precisely as it is unbounded
                    // for `complete()`; the difference is that here the loop is
                    // alive and the cancel branch above can still end it.
                    if (!$running && feof($pipes[1]) && feof($pipes[2])) {
                        $exit = $reap(0);
                        $deferred->resolve(self::finish($exit, $stdout, $stderr));
                    }
                } catch (\Throwable $e) {
                    if (!$settled) {
                        $reap(9);
                    }
                    $deferred->reject($e);
                }
            },
        );

        return $deferred->promise();
    }

    /**
     * The wire history, or null when it could not be encoded. Shared so
     * {@see complete()} and {@see completeAsync()} cannot disagree about the
     * JSON flags a wrapper's `jq` sees.
     *
     * @param list<Message> $history
     */
    private static function encodeHistory(array $history): ?string
    {
        $payload = json_encode(
            array_map(static fn(Message $m) => $m->toWire(), $history),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return $payload === false ? null : $payload;
    }

    /**
     * `proc_open` the command, or the assistant message that says why it could
     * not be spawned. Shared by both entry points so neither the descriptor set
     * nor the failure wording can drift between them.
     *
     * `proc_open()` already differentiates the two command shapes natively — a
     * string goes through `/bin/sh -c`, a list is exec'd directly — so there is
     * nothing for the caller to branch on. This used to be
     * `$cmd = is_array($this->command) ? $this->command : $this->command;`,
     * both arms identical; the same dead ternary sat in
     * {@see StreamingCommandBackend}, where it was next to the option that was
     * supposed to make it mean something.
     *
     * NO OPTIONS, for either shape — identical to
     * {@see StreamingCommandBackend}, which makes the same escaping promise in
     * the same words and must not disagree with this one. The option in
     * question is `bypass_shell`, which is WINDOWS-ONLY. MEASURED on PHP 8.3 /
     * Linux it is inert for both shapes (the string `"printf a; printf b"`
     * still reaches `/bin/sh -c` with it set; the list `["printf", "a;b"]`
     * still exec's directly), and a LIST does not need it anywhere: passing an
     * array means PHP opens the process directly, WITHOUT going through a
     * shell, and escapes the arguments itself (PHP 7.4 UPGRADING — "the process
     * will be opened directly … PHP will take care of any necessary argument
     * escaping"). Nothing is claimed here about what Windows does with a STRING
     * command; that is unmeasured on this platform.
     *
     * @return array{0:resource,1:array<int,resource>}|Message
     */
    private function spawn(): array|Message
    {
        $pipes = [];
        $proc = @proc_open($this->command, self::DESCRIPTOR, $pipes);
        if (!is_resource($proc)) {
            return Message::assistant('_[error: failed to spawn backend command]_');
        }

        return [$proc, $pipes];
    }

    /**
     * Turn an exit code and the two captured streams into the assistant
     * message. Shared, so a non-zero exit reads identically whether the caller
     * awaited a promise or blocked.
     *
     * A non-zero exit returns an "[error: …]" assistant message rather than
     * throwing; backend failures shouldn't crash the chat shell.
     */
    private static function finish(int $exit, string $stdout, string $stderr): Message
    {
        if ($exit !== 0) {
            $tail = trim($stderr);
            $hint = $tail === '' ? '' : "\n\n```\n{$tail}\n```";

            return Message::assistant("_[error: backend exited {$exit}]_{$hint}");
        }

        return Message::assistant(trim($stdout));
    }
}
