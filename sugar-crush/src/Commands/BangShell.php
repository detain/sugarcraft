<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\BangShellResultMsg;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Support\ToolIpcFiles;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\Tools\Concerns\CapturesProcessOutput;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;

/**
 * `!<command>` typed into the TUI's input: run a shell command yourself and put
 * its output in the transcript, where the model reads it on the next turn
 * (roadmap 5.14g).
 *
 * WHAT IT IS FOR. Showing the model something — a `git status`, a failing test
 * run, a log tail — without asking it to run the command or pasting the output
 * by hand. The command is the USER's, so it starts no turn and costs no tokens
 * until the next prompt, and nothing asks permission for it: the approval
 * prompt exists to put the AGENT's actions in front of the person at the
 * keyboard, and that person just typed this one.
 *
 * WHAT IT STILL RESPECTS ({@see refusal()}). A policy the user configured is
 * their own word about what may run, so it binds their commands too: a `Deny`
 * rule that matches the command refuses it in every mode, and in `plan` mode —
 * the mode that promises the workspace is not changed — only a command the
 * gate proves read-only runs, the same judgement it applies to the agent's
 * `Bash`. A read-only window (a session another sugarcrush has open) refuses
 * it before it gets here, like any prompt.
 *
 * HOW IT RUNS. Off the update path, in a forked child that writes its result
 * through {@see ToolIpcFiles} and leaves through {@see ForkedChild::exitNow()};
 * the parent polls from a loop timer, so the frame keeps painting and the
 * keyboard stays live while the command works. The child runs the command the
 * way the `Bash` tool does — `bash -c` behind {@see ProcessContainment::cdGuard()}
 * in the project root, captured with the containment environment, bounded to
 * {@see TIMEOUT_SECONDS} and to the tool's output cap — so `!cmd` and the
 * agent's `Bash` see the same shell. Without pcntl, or when the fork fails, it
 * runs in-process and blocks, never wrong.
 *
 * IT OCCUPIES THE TURN. `Chat` holds `inFlight` for it under its own
 * {@see CancellationToken}, so a prompt typed meanwhile waits behind it (as
 * behind any turn) and Esc Esc cancels it: the next poll kills the child and
 * every process under it through {@see ProcessContainment::killTreeAsync()}
 * and reaps it on a bounded window, both on the loop. The row comes back as a
 * {@see BangShellResultMsg} stamped with the turn generation it started
 * under, whose route arm lands the row and releases the slot.
 */
final class BangShell
{
    use CapturesProcessOutput;
    use TruncatesOutput;

    /** What opens a shell command in the input. */
    public const PREFIX = '!';

    /**
     * How long a command may run before it and its process group are killed:
     * the `Bash` tool's ceiling ({@see \SugarCraft\Crush\Tools\BuiltIn\Bash::MAX_TIMEOUT_SECONDS}),
     * because the user chose to run it and nothing else is waiting on it.
     */
    public const TIMEOUT_SECONDS = 600;

    /** How often the parent looks for the child's result. */
    private const POLL_SECONDS = 0.05;

    /** How long a cancelled command's reap may take, seconds. */
    private const REAP_BUDGET_SECONDS = 0.1;

    /** How often that reap looks, seconds. */
    private const REAP_POLL_SECONDS = 0.005;

    private function __construct()
    {
    }

    /**
     * The command in $text when it is a `!cmd` line, or null when it is not —
     * no `!` first, or nothing after it (a lone `!` is an ordinary prompt).
     */
    public static function commandOf(string $text): ?string
    {
        if (!str_starts_with($text, self::PREFIX)) {
            return null;
        }

        $command = trim(substr($text, strlen(self::PREFIX)));

        return $command === '' ? null : $command;
    }

    /**
     * Why $command may not run under $gate's policy, or null when it may.
     *
     * Read-only outside `plan`: {@see PermissionGate::ruleDecision()} consults
     * the rules and nothing else, so no strike counter moves (Auto's breaker
     * counts the AGENT's calls). In `plan` the full {@see PermissionGate::evaluate()}
     * answers, which has no side effect in that mode.
     */
    public static function refusal(string $command, ?PermissionGate $gate, string $root): ?string
    {
        if ($gate === null) {
            return null;
        }

        $call = new ToolCall('Bash', ['command' => $command]);
        if ($gate->ruleDecision($call) === PermissionDecision::Deny) {
            return 'a permission rule denies Bash for it';
        }

        if ($gate->mode() === PermissionMode::Plan
            && $gate->evaluate($call, $root === '' ? null : $root) === PermissionDecision::Deny
        ) {
            return 'plan mode runs only commands it can prove read-only';
        }

        return null;
    }

    /**
     * The Cmd that runs $command in $root off the update path and resolves
     * with the {@see BangShellResultMsg} that lands its result row.
     *
     * With $cancellation, a cancel kills the command's whole process tree on
     * the next poll and resolves with a cancelled result; $generation is
     * stamped on the Msg so a result for a turn slot already released is
     * recognisably stale.
     */
    public static function cmd(string $command, string $root, ?CancellationToken $cancellation = null, ?int $generation = null): \Closure
    {
        return Cmd::promise(static function () use ($command, $root, $cancellation, $generation): PromiseInterface {
            $deferred = new Deferred();

            if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
                $deferred->resolve(self::resultMsg($command, self::run($command, $root), $generation));

                return $deferred->promise();
            }

            $file = ToolIpcFiles::reserve(ToolIpcFiles::CHAT_PREFIX, 'json');
            $pid = pcntl_fork();

            if ($pid === -1) {
                ToolIpcFiles::discard($file);
                $deferred->resolve(self::resultMsg($command, self::run($command, $root), $generation));

                return $deferred->promise();
            }

            if ($pid === 0) {
                ForkedChild::closeInheritedServerFds();
                $json = json_encode(self::run($command, $root), JSON_INVALID_UTF8_SUBSTITUTE);
                ToolIpcFiles::write($file, $json === false ? '' : $json);
                ForkedChild::exitNow(0);
            }

            $loop = Loop::get();
            $timer = null;
            $settled = false;
            $timer = $loop->addPeriodicTimer(
                self::POLL_SECONDS,
                static function () use ($pid, $file, $command, $loop, &$timer, &$settled, $deferred, $cancellation, $generation): void {
                    if ($settled) {
                        return;
                    }

                    if ($cancellation?->isCancelled() === true) {
                        $settled = true;
                        $loop->cancelTimer($timer);
                        ProcessContainment::killTreeAsync($pid, $loop)
                            ->then(static fn (): PromiseInterface => ProcessContainment::reapAsync(
                                [$pid],
                                self::REAP_BUDGET_SECONDS,
                                self::REAP_POLL_SECONDS,
                                $loop,
                            ))
                            ->then(static function () use ($file, $command, $deferred, $generation): void {
                                ToolIpcFiles::discard($file);
                                $deferred->resolve(new BangShellResultMsg(
                                    self::row($command, ['exitCode' => -1, 'output' => 'cancelled before it finished', 'timedOut' => false]),
                                    $generation,
                                    cancelled: true,
                                ));
                            });

                        return;
                    }

                    $status = 0;
                    if (pcntl_waitpid($pid, $status, WNOHANG) !== $pid) {
                        return;
                    }

                    $settled = true;
                    $loop->cancelTimer($timer);
                    $deferred->resolve(self::resultMsg($command, self::collect($file), $generation));
                },
            );

            return $deferred->promise();
        });
    }

    /**
     * Run $command now, in this process, and report how it ended.
     *
     * @return array{exitCode: int, output: string, timedOut: bool}
     */
    public static function run(string $command, string $root): array
    {
        $shell = new self();
        $script = ($root !== '' ? ProcessContainment::cdGuard($root) : '') . $command;
        $run = $shell->runCaptured(
            'bash -c ' . escapeshellarg($script),
            null,
            self::DEFAULT_MAX_OUTPUT_BYTES,
            (float) self::TIMEOUT_SECONDS,
        );

        return [
            'exitCode' => (int) $run['exitCode'],
            'output' => $shell->truncateMerged($shell->mergeCapturedOutput($run), self::DEFAULT_MAX_OUTPUT_BYTES, false),
            'timedOut' => ($run['timedOut'] ?? false) === true,
        ];
    }

    /**
     * The transcript row for a finished command: a user-role row, because it
     * is context the USER put in front of the model, headed so the model can
     * tell it from something the user wrote.
     *
     * @param array{exitCode: int, output: string, timedOut: bool} $result
     */
    public static function row(string $command, array $result): Message
    {
        $status = $result['timedOut']
            ? sprintf('Timed out after %d s; the command and its process group were killed.', self::TIMEOUT_SECONDS)
            : 'Exit code: ' . $result['exitCode'];
        $output = rtrim(self::printable($result['output']));

        return Message::user(sprintf(
            "[The user ran a shell command: %s]\n%s\n%s",
            self::printable($command),
            $status,
            $output === '' ? 'Output: (none)' : "Output:\n" . $output,
        ));
    }

    /**
     * Read back what the child wrote, discarding the payload either way. A
     * child that wrote nothing readable (killed, crashed) is reported as such.
     *
     * @return array{exitCode: int, output: string, timedOut: bool}
     */
    private static function collect(string $file): array
    {
        $data = is_file($file) ? file_get_contents($file) : false;
        ToolIpcFiles::discard($file);

        $decoded = ($data !== false && $data !== '') ? json_decode($data, true) : null;
        if (!\is_array($decoded) || !\is_int($decoded['exitCode'] ?? null) || !\is_string($decoded['output'] ?? null)) {
            return ['exitCode' => -1, 'output' => 'the command ended without reporting a result', 'timedOut' => false];
        }

        return [
            'exitCode' => $decoded['exitCode'],
            'output' => $decoded['output'],
            'timedOut' => ($decoded['timedOut'] ?? false) === true,
        ];
    }

    /** @param array{exitCode: int, output: string, timedOut: bool} $result */
    private static function resultMsg(string $command, array $result, ?int $generation): BangShellResultMsg
    {
        return new BangShellResultMsg(self::row($command, $result), $generation);
    }

    /**
     * $text without escape sequences or control bytes other than newline and
     * tab, and as valid UTF-8: command output is bound for a frame, and an ESC
     * in it would paint outside the transcript pane.
     */
    private static function printable(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = (string) preg_replace('/\e(?:\[[0-?]*[ -\/]*[@-~]|\][^\a\e]*(?:\a|\e\\\\)|[@-Z\\\\-_])/u', '', $text);

        return (string) preg_replace('/[^\P{C}\n\t]+/u', '', str_replace("\r\n", "\n", $text));
    }
}
