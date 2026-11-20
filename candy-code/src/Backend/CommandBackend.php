<?php

declare(strict_types=1);

namespace SugarCraft\Code\Backend;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\ChildResult;
use SugarCraft\Async\ChildRun;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Message;
use SugarCraft\Code\Support\ProcessContainment;

/**
 * Backend that shells out to an external command. The command
 * receives the JSON-encoded history on stdin and writes the
 * assistant reply to stdout.
 *
 * This is the recommended starting point for hooking CandyCode
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
 * The child runs through sugar-async's {@see ChildRun}, which owns the pipes, the
 * stdin payload and the reap. A non-zero exit returns an
 * error-marked "[error: …]" assistant message rather than throwing:
 * backend failures must not crash the chat shell, which renders the
 * message as a UI-only bubble. The mark is load-bearing for the
 * one-shot path — {@see \SugarCraft\Code\Cli\NonInteractive::run()} maps an
 * error-marked answer to its failure exit code, so a failing pipeline
 * no longer exits 0 (1021 F-5).
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
 * the two, and each has its own env var (`$CANDY_CODE_BACKEND_CMD` here,
 * `$CANDY_CODE_BACKEND_CMD_STREAM` there). There is also no completion
 * deadline of any kind on this path, deliberately; a completion can
 * legitimately run tens of minutes.
 *
 * {@see complete()} BLOCKS, by contract — it is what the `-p` one-shot path
 * ({@see \SugarCraft\Code\Cli\NonInteractive::run()}) wants. It runs the child
 * with {@see ChildRun::run()}, which on POSIX parks in the kernel until a pipe
 * moves and on Windows polls each pipe at {@see \SugarCraft\Async\WindowsPipes::POLL_MICROSECONDS},
 * because a Windows pipe has no readable stream to park on. Either way a wrapper
 * that echoes its whole history (`cat` included) cannot deadlock against a full
 * stdout pipe. {@see completeAsync()} does NOT block:
 * it starts the same child with {@see ChildRun::launch()} on the event loop, so
 * the caller's render/input loop keeps running for the whole round-trip.
 */
final class CommandBackend implements Backend, TracksActiveChildren
{
    use AbandonsActiveChildren;

    /**
     * The deadline stand-in. A completion has no deadline of its own (see the
     * class docblock), and {@see ChildRun} needs a finite number; this is the
     * same no-deadline figure WindowsPipes already uses.
     */
    private const NO_DEADLINE_SECONDS = 86400 * 365;

    /**
     * @param string|list<string> $command Command + args. A string runs through
     *                                     `/bin/sh -c` (cmd.exe on Windows); pass a list to avoid
     *                                     shell escaping concerns.
     */
    public function __construct(private readonly string|array $command)
    {}

    /**
     * $onEvent is accepted and ignored: the external command owns whatever tool
     * use happens on its side and reports nothing back but final text, so this
     * backend has no tool lifecycle it can honestly emit.
     */
    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        $payload = self::encodeHistory($history);
        if ($payload === null) {
            // UI-only (audit 15b-03), like every error string below: the
            // model never said it, so it must not be replayed as its words.
            return Message::assistant('_[error: failed to encode history]_')->withUiOnly()->withError();
        }

        // An empty command answers empty on both arms without a spawn. ChildRun
        // cannot start an empty Windows command line, so this is the one place
        // the two arms agree.
        if ($this->command === '') {
            return Message::assistant('');
        }

        // The pid is registered the moment the child starts and forgotten once
        // run() has reaped it, so a quit during the wait can still abandon it.
        $pid = null;
        $child = $this->child($payload, function (int $started) use (&$pid): void {
            $pid = $started;
            $this->registerActivePid($started);
        });
        try {
            $result = $child->run();
        } catch (\RuntimeException) {
            return self::spawnFailed();
        } finally {
            if ($pid !== null) {
                $this->forgetActivePid($pid);
            }
        }

        return self::finish($result->exitCode ?? -1, $result->stdout, $result->stderr);
    }

    /**
     * Starts the same child {@see complete()} runs, on the event loop instead of
     * blocking, so the caller's render/input loop keeps running for the whole
     * round-trip. The history goes to stdin through the loop's write stream, a
     * slice at a time, so a full pipe costs a loop tick rather than the turn.
     *
     * A cancelled token kills the child through ChildRun's kill ladder (TERM, a
     * grace, then KILL) and rejects with 'Request cancelled'. A token already
     * cancelled spawns nothing.
     *
     * $onToken is accepted and ignored: this backend's protocol delivers one final
     * blob of stdout, so there is nothing to stream. {@see StreamingCommandBackend}
     * is the half of this tier that has tokens.
     */
    public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        $deferred = new Deferred();

        if ($cancellation?->isCancelled() === true) {
            $deferred->reject(new \RuntimeException('Request cancelled'));

            return $deferred->promise();
        }

        $payload = self::encodeHistory($history);
        if ($payload === null) {
            $deferred->resolve(Message::assistant('_[error: failed to encode history]_')->withUiOnly()->withError());

            return $deferred->promise();
        }

        if ($this->command === '') {
            $deferred->resolve(Message::assistant(''));

            return $deferred->promise();
        }

        $pid = null;
        $child = $this->child($payload, function (int $started) use (&$pid): void {
            $pid = $started;
            $this->registerActivePid($started);
        })->withCancellation($cancellation?->asyncToken());

        // The promise rejects only when the child cannot be started, and resolves
        // once it is done, cancelled or not; the registry entry goes at settle.
        $child->launch(Loop::get())->then(
            function (ChildResult $result) use ($deferred, &$pid): void {
                if ($pid !== null) {
                    $this->forgetActivePid($pid);
                }
                if ($result->cancelled()) {
                    $deferred->reject(new \RuntimeException('Request cancelled'));

                    return;
                }
                $deferred->resolve(self::finish($result->exitCode ?? -1, $result->stdout, $result->stderr));
            },
            function (\Throwable $e) use ($deferred): void {
                $deferred->resolve(self::spawnFailed());
            },
        );

        return $deferred->promise();
    }

    /**
     * The one ChildRun both entry points run: the history on stdin, no byte cap
     * (the reply is kept whole, as before), the no-deadline stand-in, and no exit
     * grace, so a descendant holding the pipes is part of the command's budget.
     *
     * @param \Closure(int): void $onSpawn Told the child's pid right after it starts
     */
    private function child(string $payload, \Closure $onSpawn): ChildRun
    {
        return self::childRun($this->command, $payload)
            ->withExitGrace(null)
            ->withOnSpawn($onSpawn);
    }

    /**
     * The spawn both shell-out tiers share: the command's argv (a string on Windows is its raw line) behind the
     * containment wrapper, the history on stdin, no byte cap and the no-deadline
     * stand-in. Each caller picks its own exit grace, idle budget and hooks, so
     * {@see StreamingCommandBackend} starts its child exactly as this class does.
     *
     * @internal public only so StreamingCommandBackend shares it.
     */
    public static function childRun(string|array $command, string $payload): ChildRun
    {
        return self::childRunForSpec(
            ProcessContainment::spawnSpec(self::shellSpec($command, PHP_OS_FAMILY === 'Windows')),
            $payload,
        );
    }

    /**
     * The string-versus-argv choice {@see childRun()} makes, split out so a test
     * can pin it on every platform: a string spec must stay the raw line
     * (ChildRun::commandLine), and only a list may be exec'd as an argv.
     *
     * @param string|list<string> $spec
     * @internal public only so the spec-to-ChildRun choice is testable on every platform
     */
    public static function childRunForSpec(string|array $spec, string $payload): ChildRun
    {
        $run = is_string($spec)
            ? ChildRun::commandLine($spec)
            : ChildRun::new(array_values((array) $spec));

        return $run
            ->withEnv(ProcessContainment::env())
            ->withStdin($payload)
            ->withMaxBytes(PHP_INT_MAX)
            ->withTimeout(self::NO_DEADLINE_SECONDS);
    }

    /**
     * What ChildRun is started with for $command. On POSIX a string is `/bin/sh -c`
     * over an argv, and a list is exec'd as given. On Windows a string stays the
     * raw line: PHP escapes an argv element by element there, and cmd.exe does not
     * read that escaping, so a string command goes to proc_open() as one string.
     * ChildRun::commandLine() carries that string.
     *
     * This is a deliberate Windows change for CommandBackend. Its master spawn ran
     * `COMSPEC /d /s /c <line>` as an argv; this moves it to the raw line, the form
     * StreamingCommandBackend already starts its child with. The Windows quoting of
     * a string command therefore changed here, and only here.
     *
     * @return string|list<string>
     * @internal public only so the spawn-spec test reads it on every platform
     */
    public static function shellSpec(string|array $command, bool $windows): string|array
    {
        if (is_array($command)) {
            return $command;
        }
        if ($windows) {
            return $command;
        }

        return ['/bin/sh', '-c', $command];
    }

    /** The message for a command that could not be started. */
    private static function spawnFailed(): Message
    {
        return Message::assistant('_[error: failed to spawn backend command]_')->withUiOnly()->withError();
    }

    /**
     * The wire history, or null when it could not be encoded. Shared by
     * {@see complete()}, {@see completeAsync()} AND
     * {@see StreamingCommandBackend}, so no two command entry points can
     * disagree about the JSON flags a wrapper's `jq` sees.
     *
     * `JSON_INVALID_UTF8_SUBSTITUTE` (audit 15a A22): these rows are Chat's own
     * history - a pasted Latin-1 snippet, a file read verbatim - and neither
     * the tool-result scrub in Runtime nor the loader scrub
     * ({@see \SugarCraft\Code\Context\Utf8Scrub}) ever touches them. One
     * invalid byte made the encode return false, and since the row is replayed
     * in every later request, EVERY later turn on a command backend answered
     * `failed to encode history`. Each invalid sequence becomes U+FFFD - the
     * same substitute Runtime's tool-result repair picks, for the same reason:
     * it is the one character that says "a byte was here that was not text",
     * where `?` is indistinguishable from a real question mark.
     *
     * UI-only rows are left out (audit 15b-03) - the same filter Chat applies
     * before it dispatches, repeated here so a direct caller of either command
     * backend gets a clean wire too. One place, so the two backends cannot
     * disagree about it either.
     *
     * @internal public only so StreamingCommandBackend shares it.
     *
     * @param list<Message> $history
     */
    public static function encodeHistory(array $history): ?string
    {
        $payload = json_encode(
            array_map(static fn(Message $m) => $m->toWire(), Message::agentVisible($history)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $payload === false ? null : $payload;
    }

    /**
     * Turn an exit code and the two captured streams into the assistant
     * message. Shared, so a non-zero exit reads identically whether the caller
     * awaited a promise or blocked.
     *
     * A non-zero exit returns an error-marked "[error: …]" message rather than
     * throwing — the chat shell must survive showing it, and {@see
     * \SugarCraft\Code\Cli\NonInteractive::run()} carries the same failure to
     * headless callers through the exit code, not through an exception (1021 F-5).
     */
    private static function finish(int $exit, string $stdout, string $stderr): Message
    {
        if ($exit !== 0) {
            $tail = trim($stderr);
            $hint = $tail === '' ? '' : "\n\n```\n{$tail}\n```";

            return Message::assistant("_[error: backend exited {$exit}]_{$hint}")->withUiOnly()->withError();
        }

        return Message::assistant(trim($stdout));
    }
}
