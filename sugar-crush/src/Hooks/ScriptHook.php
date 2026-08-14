<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks;

/**
 * A hook that executes an external script.
 *
 * EXIT-CODE CONTRACT (crush_code.md Phase 1 item 2). Until this, a script hook
 * could only ever say allow (0) or deny (anything else), which left two of
 * {@see HookResult}'s four actions — ASK and MODIFY — reachable only by
 * hand-writing a PHP {@see HookInterface} class. ASK in particular is what
 * drives the blocking permission prompt {@see \SugarCraft\Crush\Chat} and
 * {@see \SugarCraft\Crush\Renderer} already implement, so the entire prompt UI
 * was unreachable from configuration.
 *
 *   0  ALLOW   — stdout becomes the result message.
 *   1  DENY    — non-blocking deny. See the note below.
 *   2  DENY    — hard block.
 *   3  ASK     — stdout is the question put to the user.
 *   4  MODIFY  — stdout is a JSON object replacing the tool's arguments.
 *   *  DENY    — any other non-zero exit, as before.
 *
 * 0/1/2 are NOT this class's invention and were NOT free to renumber: they are
 * already the documented, tested contract of {@see HookDispatcher} and
 * {@see HookDispatchResult} (0 allow / 1 non-blocking deny / 2 hard block),
 * which is why ASK and MODIFY got 3 and 4 rather than crush_code.md's
 * suggested 2 and 3 — 2 was taken, and quietly redefining it would have turned
 * every existing "exit 2 to block" hook into a prompt.
 *
 * Exit 1 currently produces a plain DENY, identical to exit 2, rather than the
 * `[exit-1]` message prefix {@see HookDispatcher::determineExitCode()} looks
 * for. That prefix is only stripped again on the dispatcher path; the two live
 * gates ({@see \SugarCraft\Crush\Runtime::gate()} and
 * {@see \SugarCraft\Crush\Chat::gateToolCall()}) quote the message verbatim
 * into the model's tool result, so emitting it would leak a marker into the
 * transcript. Distinguishing the two is a change to those gates, not to this
 * class.
 *
 * A MODIFY whose stdout is not a JSON object is downgraded to a DENY rather
 * than to an ALLOW: a hook that meant to rewrite dangerous arguments and
 * failed must not have the ORIGINAL arguments run in its place.
 */
final readonly class ScriptHook implements HookInterface
{
    /** Action proceeds; stdout is the message. */
    public const EXIT_ALLOW = 0;

    /**
     * Non-blocking deny in {@see HookDispatcher}'s vocabulary. Blocks like
     * {@see self::EXIT_BLOCK} on the live gates — see the class docblock.
     */
    public const EXIT_DENY = 1;

    /** Hard block. */
    public const EXIT_BLOCK = 2;

    /** Defer to the user; stdout is the prompt text. */
    public const EXIT_ASK = 3;

    /** Rewrite the tool arguments; stdout is a JSON object. */
    public const EXIT_MODIFY = 4;

    /**
     * How many CONSECUTIVE `stream_select()` failures {@see drain()} rides out
     * before it gives up on a pipe.
     *
     * A signal is the expected cause and it is not an error, so the budget has
     * to be generous; a genuinely broken descriptor fails instantly and
     * forever, so it has to be finite. Any successful select resets it, which
     * means 128 is 128 signals with no readable byte in between rather than
     * 128 signals per hook.
     */
    private const DRAIN_SELECT_RETRIES = 128;

    public function __construct(
        private string $name,
        private HookEvent $event,
        private string $matcher,
        private string $command,
        private string $description,
    ) {}

    /**
     * Create a ScriptHook from a config array.
     *
     * `name` is honoured when {@see HookConfig} supplied one, falling back to
     * the command as before. {@see HookRegistry} keys its hooks by name, so
     * without a way to name them two config entries running the same command
     * on the same event silently collapsed into one.
     */
    public static function fromConfig(array $config): self
    {
        $eventString = $config['event'] ?? 'PreToolUse';
        $event = HookEvent::tryFrom($eventString) ?? HookEvent::PreToolUse;

        $name = $config['name'] ?? null;

        return new self(
            name: is_string($name) && $name !== '' ? $name : ($config['command'] ?? uniqid('hook_')),
            event: $event,
            matcher: $config['matcher'] ?? '.*',
            command: $config['command'] ?? '',
            description: $config['description'] ?? '',
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function event(): HookEvent
    {
        return $this->event;
    }

    public function matcher(): string
    {
        return $this->matcher;
    }

    /**
     * Run the script and turn its exit code into a verdict — see the class
     * docblock for the full exit-code table.
     *
     * A guard that could not RUN has approved nothing, so both failure modes
     * below fail CLOSED. `proc_open()` refuses to start a process whose `cwd`
     * does not exist, and {@see HookContext::$projectRoot} can legitimately
     * carry an unusable value — a mistyped `--root` threaded through
     * {@see \SugarCraft\Crush\Runtime}, or a caller with no root of its own
     * to give. Before this, that turned a DENYING hook into an allow: the
     * one direction a security gate must never fail in.
     */
    public function execute(HookContext $context): HookResult
    {
        // Inherit the process directory rather than refuse to run: the hook's
        // VERDICT is what matters, and running it one directory over is a far
        // smaller loss than not running it at all.
        $cwd = is_dir($context->projectRoot) ? $context->projectRoot : null;

        $env = [
            'CRUSH_SESSION_ID' => $context->sessionId,
            'CRUSH_TOOL_NAME' => $context->toolName,
            'CRUSH_TOOL_INPUT' => $context->toolInput,
            'CRUSH_TOOL_OUTPUT' => $context->toolOutput,
            'CRUSH_MODEL' => $context->model,
            'CRUSH_PROVIDER' => $context->provider,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $this->command,
            $descriptors,
            $pipes,
            $cwd,
            $env
        );

        if (!is_resource($process)) {
            return HookResult::deny("Hook {$this->name} could not be executed");
        }

        fclose($pipes[0]);

        [$output, $errors] = $this->drain($pipes[1], $pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $output = trim($output);
        $errors = trim($errors);

        return match ($exitCode) {
            self::EXIT_ALLOW => HookResult::allow($output),
            self::EXIT_ASK => HookResult::ask($output !== '' ? $output : $this->defaultQuestion()),
            self::EXIT_MODIFY => $this->modifyOrDeny($output),
            default => HookResult::deny($errors ?: "Hook exited with code $exitCode"),
        };
    }

    /**
     * Read both pipes to EOF without letting either one wedge the other.
     *
     * `stream_get_contents($stdout)` followed by `stream_get_contents($stderr)`
     * deadlocks the moment a hook writes more than one pipe buffer (64 KiB on
     * Linux) to stderr: the parent is blocked reading stdout, the child is
     * blocked writing a full stderr pipe nobody is draining, and neither ever
     * moves. That hangs the whole CLI on a hook that did nothing worse than be
     * chatty about why it denied something — the exact case a security gate is
     * most likely to be verbose in.
     *
     * `stream_select()` over both, reading only what is ready, has no such
     * ordering. The pipes are switched to non-blocking so a select that wakes
     * on a partial buffer cannot block inside the `fread()` that follows it.
     *
     * A `false` from the select is RETRIED, not treated as end-of-output.
     * PHP's `stream_select()` is not installed with `SA_RESTART`, so any
     * signal delivered while it is parked returns false with EINTR — and
     * {@see \SugarCraft\Core\Program} turns on `pcntl_async_signals()` and
     * installs SIGWINCH/SIGINT handlers for the whole TUI, which makes a
     * terminal RESIZE during a hook a routine occurrence. Breaking on that
     * abandoned everything the hook had not written yet: a truncated deny
     * reason, a half an `exit 3` question, and — worst — a partial `exit 4`
     * rewrite, which is invalid JSON and so becomes a DENY of a call the hook
     * meant to permit with different arguments. The verdict itself always
     * survived (it comes from `proc_close()`), but the words did not.
     *
     * @param resource $stdout
     * @param resource $stderr
     * @return array{0: string, 1: string}
     */
    private function drain($stdout, $stderr): array
    {
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        $buffers = ['', ''];
        $open = [0 => $stdout, 1 => $stderr];
        $failures = 0;

        while ($open !== []) {
            $read = array_values($open);
            $write = null;
            $except = null;

            // A null timeout blocks until one of the pipes is readable, which
            // includes the readable-at-EOF a closing child produces — so a
            // hook that runs for a minute costs no polling, and one that exits
            // immediately is not waited on.
            if (@stream_select($read, $write, $except, null) === false) {
                if (++$failures > self::DRAIN_SELECT_RETRIES) {
                    // Not a signal, then: something is wrong with the
                    // descriptors themselves and retrying forever would wedge
                    // the CLI harder than the deadlock this method replaced.
                    // Sweep whatever is already buffered before letting go —
                    // the pipes are non-blocking, so this cannot park.
                    foreach ($open as $slot => $pipe) {
                        $rest = @stream_get_contents($pipe);
                        if (is_string($rest)) {
                            $buffers[$slot] .= $rest;
                        }
                    }

                    break;
                }

                continue;
            }

            $failures = 0;

            foreach ($open as $slot => $pipe) {
                if (!in_array($pipe, $read, true)) {
                    continue;
                }

                $chunk = fread($pipe, 8192);
                if ($chunk === false || $chunk === '') {
                    // feof() is the EOF test, not the empty read: a
                    // non-blocking pipe legitimately returns '' when the child
                    // has written nothing YET.
                    if (feof($pipe)) {
                        unset($open[$slot]);
                    }

                    continue;
                }

                $buffers[$slot] .= $chunk;
            }
        }

        return $buffers;
    }

    /**
     * Turn an EXIT_MODIFY script's stdout into a MODIFY, or refuse.
     *
     * Fails to DENY, never to ALLOW: a rewrite hook that produced garbage was
     * trying to change the arguments, and running the untouched originals in
     * its place is the one outcome it definitely did not ask for. A JSON list
     * or scalar is rejected for the same reason a syntax error is — the two
     * consumers of `modifiedInput` ({@see \SugarCraft\Crush\Runtime::gate()}
     * and {@see \SugarCraft\Crush\Chat::gateToolCall()}) both expect a
     * name => value argument map and silently fall back to the originals
     * otherwise, which would make a failed rewrite look like a successful one.
     *
     * The check is on the JSON TEXT, not on `array_is_list()` of the decoded
     * value, because PHP's decoder throws away exactly the distinction being
     * tested: `[]` and `{}` both decode to `[]`, and `{"0":"a","1":"b"}`
     * decodes to something `array_is_list()` calls a list. Reading the opening
     * brace gets all four right — `{}` is an explicit "run this tool with no
     * arguments", which is a rewrite the hook deliberately asked for, while
     * `[]` is the positional array the consumers cannot use.
     *
     * The `ltrim()` below is redundant against today's only caller, which has
     * already `trim()`ed the output — kept so the brace test is a property of
     * THIS method rather than of what happens to be upstream of it, since a
     * caller that ever forgets the trim would otherwise turn every rewrite
     * whose JSON is indented into a deny.
     */
    private function modifyOrDeny(string $output): HookResult
    {
        $decoded = json_decode($output, true);

        if (!is_array($decoded) || !str_starts_with(ltrim($output), '{')) {
            return HookResult::deny(
                "Hook {$this->name} asked to rewrite the tool input but did not print a JSON object",
            );
        }

        return HookResult::modify($output);
    }

    /**
     * The question asked when an EXIT_ASK script printed nothing. A prompt
     * with an empty body is unanswerable, and falling back to allow/deny would
     * make silence mean something the hook never said.
     */
    private function defaultQuestion(): string
    {
        return $this->description !== ''
            ? $this->description
            : "Hook {$this->name} requires your approval";
    }
}
