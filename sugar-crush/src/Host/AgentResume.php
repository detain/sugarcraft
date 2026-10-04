<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\DelegatesToEngine;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Continue a delegated run that has already FINISHED, on the user's word
 * (roadmap P-D2/P-D3, Appendix P §5.3 "cold resume"): the Agent View's
 * composer aimed at a `✓`/`✗` run, and the dashboard's `r` on one.
 *
 * WHAT IT RUNS. The session's own Task tool, with the run's resume id
 * ({@see \SugarCraft\Crush\Agents\SuspendedDelegations}, which keeps every run
 * since step 4.7-1) and the user's text as the prompt — exactly the call the
 * model makes when it follows up with `resume`. So the follow-up continues the
 * same conversation, under the same preset, grants and step cap, appends to
 * the same transcript log the Agent View is already tailing, and is
 * resumable again afterwards; the model's `resume` and the user's follow-up
 * are one conversation, not two.
 *
 * DETACHED. It belongs to no parent turn: it runs while the parent is idle as
 * well as mid-turn, and nothing it says reaches the parent model unless the
 * user brings it there. With `ext-pcntl` it runs in a forked child — the
 * process tree a turn uses, so the TUI never blocks on it — and every
 * {@see SubAgentActivity} frame the run emits comes back over a socket pair
 * to `$onActivity`, on the loop, in this process. Without it the run happens
 * in-process, in the call ({@see withoutFork()} forces that, for tests); its
 * frames still reach `$onActivity`, only after the fact.
 *
 * The child never touches SQLite (it inherits the parent's handle and must
 * not use or destroy it), leaves through {@see ForkedChild::exitNow()}, and
 * writes only its transcript log and the suspension store — the files a
 * lone Task's turn child writes.
 */
final class AgentResume
{
    /** The tool-call id prefix a follow-up run's frames carry as their `parentCallId`. */
    public const CALL_PREFIX = 'followup_';

    /** What the run is told when the user asked it to go on without saying more. */
    public const CONTINUE_TEXT = 'Continue.';

    /** How long the parent waits to reap a child that has closed its end. */
    private const REAP_SECONDS = 2.0;

    private function __construct(
        private readonly EngineBackend $engine,
        private readonly bool $fork,
    ) {
    }

    /**
     * A resumer over $engine — the session's engine, already on the session
     * the run belongs to ({@see EngineBackend::withSessionId()}), so the
     * follow-up keeps its log and mailbox under that session.
     */
    public static function new(EngineBackend $engine): self
    {
        return new self(
            $engine,
            \function_exists('pcntl_fork') && \function_exists('pcntl_waitpid') && \function_exists('stream_socket_pair'),
        );
    }

    /** The same resumer, running the follow-up in this process. */
    public function withoutFork(): self
    {
        return new self($this->engine, false);
    }

    /** Whether the engine carries a Task tool to continue a run with. */
    public function available(): bool
    {
        return $this->taskTool() !== null;
    }

    /**
     * Continue $agent's run $resumeId with $text. Resolves with the Task
     * tool's result — its report (fenced) and resume note, or why it failed —
     * and never rejects.
     *
     * @param (\Closure(SubAgentActivity): void)|null $onActivity every frame
     *        the follow-up run emits, in this process
     *
     * @return PromiseInterface<ToolResult>
     */
    public function run(string $agent, string $resumeId, string $text, string $description = '', ?\Closure $onActivity = null): PromiseInterface
    {
        $callId = self::CALL_PREFIX . bin2hex(random_bytes(6));
        $text = trim($text) === '' ? self::CONTINUE_TEXT : $text;
        $args = [
            'id' => $callId,
            'agent' => $agent,
            'prompt' => $text,
            'resume' => $resumeId,
            'description' => $description === '' ? 'follow-up' : $description,
        ];

        $task = $this->taskTool();
        if ($task === null) {
            return \React\Promise\resolve(new ToolResult($callId, 'this session has no Task tool to continue the run with', true));
        }

        if (!$this->fork) {
            return \React\Promise\resolve(self::execute($task, $this->engine, $args, $onActivity));
        }

        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            return \React\Promise\resolve(self::execute($task, $this->engine, $args, $onActivity));
        }
        [$parentEnd, $childEnd] = $pair;

        // An exec-free fork of this process, like a turn's: our own code, no
        // argv — outside ProcessContainment's remit, which contains command
        // children.
        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($parentEnd);
            fclose($childEnd);

            return \React\Promise\resolve(self::execute($task, $this->engine, $args, $onActivity));
        }

        if ($pid === 0) {
            fclose($parentEnd);
            ForkedChild::closeInheritedServerFds();
            self::runInChild($childEnd, $task, $this->engine, $args);
            ForkedChild::exitNow(0);
        }

        fclose($childEnd);

        return self::collect($parentEnd, $pid, $callId, $onActivity);
    }

    /**
     * The Task tool the session's engine carries, or null.
     */
    private function taskTool(): ?DelegatesToEngine
    {
        foreach ($this->engine->tools() as $tool) {
            if ($tool instanceof TaskTool) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $args
     * @param (\Closure(SubAgentActivity): void)|null $onActivity
     */
    private static function execute(DelegatesToEngine $task, EngineBackend $engine, array $args, ?\Closure $onActivity): ToolResult
    {
        try {
            $bound = $task->withEngine($engine, null, $onActivity);

            return $bound->execute($args);
        } catch (\Throwable $e) {
            return new ToolResult($args['id'], sprintf('the follow-up run failed: %s', $e->getMessage()), true);
        }
    }

    /**
     * The child's whole life: run the Task call, writing each frame and then
     * the result to $socket as one JSON line apiece.
     *
     * @param resource              $socket
     * @param array<string, string> $args
     */
    private static function runInChild($socket, DelegatesToEngine $task, EngineBackend $engine, array $args): void
    {
        $write = static function (array $line) use ($socket): void {
            $json = json_encode($line, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (\is_string($json)) {
                @fwrite($socket, $json . "\n");
            }
        };

        $result = self::execute(
            $task,
            $engine,
            $args,
            static function (SubAgentActivity $activity) use ($write): void {
                $write(['k' => 'activity', 'frame' => $activity->toArray()]);
            },
        );
        $write(['k' => 'result', 'content' => $result->content(), 'error' => $result->isError()]);
        @fclose($socket);
    }

    /**
     * The parent's half: read the child's lines on the loop, hand each frame
     * to $onActivity, and settle with the result once the child closes its
     * end.
     *
     * @param resource $socket
     * @param (\Closure(SubAgentActivity): void)|null $onActivity
     *
     * @return PromiseInterface<ToolResult>
     */
    private static function collect($socket, int $pid, string $callId, ?\Closure $onActivity): PromiseInterface
    {
        stream_set_blocking($socket, false);
        $deferred = new Deferred();
        $buffer = '';
        $result = null;

        Loop::addReadStream($socket, static function ($stream) use (&$buffer, &$result, $deferred, $pid, $callId, $onActivity): void {
            $chunk = @fread($stream, 65536);
            if (\is_string($chunk) && $chunk !== '') {
                $buffer .= $chunk;
                while (($newline = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newline);
                    $buffer = substr($buffer, $newline + 1);
                    $decoded = json_decode($line, true);
                    if (!\is_array($decoded)) {
                        continue;
                    }
                    if (($decoded['k'] ?? null) === 'activity' && \is_array($decoded['frame'] ?? null)) {
                        $activity = SubAgentActivity::fromArray($decoded['frame']);
                        if ($activity !== null && $onActivity !== null) {
                            $onActivity($activity);
                        }
                    } elseif (($decoded['k'] ?? null) === 'result') {
                        $result = new ToolResult($callId, (string) ($decoded['content'] ?? ''), ($decoded['error'] ?? true) === true);
                    }
                }

                return;
            }
            if (!feof($stream)) {
                return;
            }

            Loop::removeReadStream($stream);
            fclose($stream);
            self::reap($pid);
            $deferred->resolve($result ?? new ToolResult($callId, 'the follow-up run ended without reporting a result', true));
        });

        return $deferred->promise();
    }

    /**
     * Reap the child that just closed its end. It leaves at once after that
     * ({@see ForkedChild::exitNow()}), so the wait is short; one that does not
     * is left to the process's own exit rather than blocking the loop.
     */
    private static function reap(int $pid): void
    {
        $deadline = microtime(true) + self::REAP_SECONDS;
        do {
            $status = 0;
            if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                return;
            }
            usleep(5000);
        } while (microtime(true) < $deadline);
    }
}
