<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\TurnInterrupted;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Support\ParentProcessGuard;
use SugarCraft\Crush\Tools\DelegatesToEngine;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Usage;

/**
 * The executor that makes a pooled agent — a `/workflow` stage — a whole
 * agentic run through the session's own {@see EngineBackend}, instead of the
 * single provider call {@see ProcessExecutor}'s worker makes.
 *
 * WHY IT EXISTS. That worker advertises the agent's tools and executes none of
 * them, so any stage whose first move was a tool call ended with empty output —
 * the same defect the `Task` tool had, one dispatch path over. This executor
 * runs the stage through the engine's bounded tool loop with the SAME hook
 * chain, permission gate, approver and root as the session, narrowed to the
 * tools the stage's request carries (WorkflowEngine resolves each task's grant
 * into `$request->tools`), with the request's system prompt as a system turn
 * and the agent's `maxTurns` (default {@see DEFAULT_MAX_TURNS}) as the step cap.
 * `Task` is withheld, so a stage cannot fan out further.
 *
 * IT IS A FORKED EXECUTOR. {@see AgentWorkerPool} runs it inside the child it
 * forks per agent (its `forkedExecutor` seam), which is what keeps a
 * multi-minute stage from blocking the TUI process the workflow Fiber runs in:
 * the parent only polls and yields. Every provider chunk and tool event checks
 * {@see ParentProcessGuard}, so a child whose parent died stops rather than
 * editing the tree for nobody.
 *
 * THE ENGINE IS BOUND LATE ({@see bind()}), because the session's backend is
 * replaced on a provider switch while the pool and its executor are built once
 * at launch: {@see \SugarCraft\Crush\Chat}'s constructor re-binds the current
 * backend every time a Chat is built, the same place it links the AgentManager.
 * An unbound executor refuses every agent with the reason, never fabricates.
 */
final class EngineExecutor implements ExecutorInterface
{
    /**
     * Step cap for an agent that declares no `maxTurns`.
     *
     * 200, matching {@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool::DEFAULT_MAX_TURNS}
     * (the 2026-10-02 step-budget decision): a real stage — read, edit, run
     * the tests, fix, re-run — routinely needs more than the old 50 tool
     * steps, and running out mid-task ends the stage with no final report.
     * The cap is a runaway bound, not a budget; the stage's wall-clock
     * `timeout` is what bounds its time.
     */
    public const DEFAULT_MAX_TURNS = 200;

    /** At most one streamed append per this many seconds; a tool call flushes at once. */
    public const STREAM_FLUSH_SECONDS = 0.25;

    /** A tool line in the live stream is one line, capped here. */
    private const TOOL_LINE_MAX = 120;

    public function __construct(
        private ?EngineBackend $engine = null,
    ) {}

    public function bind(EngineBackend $engine): void
    {
        $this->engine = $engine;
    }

    public function engine(): ?EngineBackend
    {
        return $this->engine;
    }

    public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
    {
        return $this->run($agent, $request, null);
    }

    /**
     * The run, with its prose and tool calls streamed as they happen — what
     * the live-agent pane paints while a stage is still working.
     *
     * WHY A FIBER. The engine reports progress through CALLBACKS (its token and
     * tool-event sinks) and a callback cannot `yield`, while this contract —
     * the one {@see AgentWorkerPool::runStreaming()} consumes in the forked
     * child and turns into progress-file appends — is a generator. So the run
     * goes inside a `\Fiber`, and the sinks `Fiber::suspend()` each buffered
     * delta out to this generator, which yields it as a Streaming result and
     * resumes. `executeStream()` stays the ONE channel every consumer of an
     * executor already reads, rather than a second, pool-only sink seam.
     *
     * Suspending is safe under the TUI's own workflow Fiber: `Fiber::suspend()`
     * always returns to whoever resumed the INNERMOST fiber, which is this
     * generator, never the outer driver. A suspension that is not one of ours
     * (a value that is not a string) is resumed at once and yields nothing.
     *
     * WHAT IS STREAMED IS AN ACTIVITY LOG, NOT THE REPLY. The pool's progress
     * file is append-only, so text cannot be retracted when a later step
     * supersedes a step's prose; each tool call therefore gets a `▸` line and
     * the stream reads as "said this, then called that". The terminal result
     * still carries only the final answer. Deltas are coalesced to at most one
     * append per {@see STREAM_FLUSH_SECONDS} (a tool call flushes at once) so a
     * token-per-chunk provider does not cost a file write per token.
     */
    public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
    {
        $buffer = '';
        $lastFlush = microtime(true);
        $flush = static function () use (&$buffer, &$lastFlush): void {
            if ($buffer === '') {
                return;
            }
            $chunk = $buffer;
            $buffer = '';
            $lastFlush = microtime(true);
            \Fiber::suspend($chunk);
        };
        $onToken = static function (string $delta) use (&$buffer, &$lastFlush, $flush): void {
            $buffer .= $delta;
            if (microtime(true) - $lastFlush >= self::STREAM_FLUSH_SECONDS) {
                $flush();
            }
        };
        $onToolStarted = static function (ToolStarted $event) use (&$buffer, $flush): void {
            if ($buffer !== '' && !str_ends_with($buffer, "\n")) {
                $buffer .= "\n";
            }
            $buffer .= '▸ ' . self::describe($event) . "\n";
            $flush();
        };

        $fiber = new \Fiber(function () use ($agent, $request, $onToken, $onToolStarted, $flush): AgentResult {
            $result = $this->run($agent, $request, $onToken, $onToolStarted);
            $flush();

            return $result;
        });

        $suspended = $fiber->start();
        while (!$fiber->isTerminated()) {
            if (\is_string($suspended)) {
                yield new AgentResult(agentId: $agent->id, status: AgentStatus::Streaming, output: $suspended);
            }
            $suspended = $fiber->resume();
        }

        yield $fiber->getReturn();
    }

    /**
     * @param ?\Closure(string): void      $onToken       prose deltas, every step
     * @param ?\Closure(ToolStarted): void $onToolStarted each tool call as it starts
     */
    private function run(SubAgent $agent, CompleteRequest $request, ?\Closure $onToken, ?\Closure $onToolStarted = null): AgentResult
    {
        $startedAt = new \DateTimeImmutable();

        if ($this->engine === null) {
            return self::failed($agent, $startedAt, 'no session engine is bound to this executor, so the agent cannot run');
        }

        // E663's rule, kept on this path: a launch whose provider could not be
        // built degrades the CHAT to the offline echo engine, and a stage run
        // through it would "succeed" with its own prompt echoed back.
        if ($this->engine->provider() instanceof EchoProvider) {
            return self::failed(
                $agent,
                $startedAt,
                'No provider configured: the session engine is the offline echo fallback, so this stage '
                . 'refuses rather than report echoed text as work. Configure a provider and re-run.',
            );
        }

        $tools = array_values(array_filter(
            $request->tools ?? $this->engine->tools(),
            static fn (Tool $tool): bool => !$tool instanceof DelegatesToEngine,
        ));
        $maxTurns = max(1, $agent->agent->maxTurns ?? self::DEFAULT_MAX_TURNS);

        $guard = ParentProcessGuard::capture('workflow that dispatched it');

        // The agent's own wall-clock bound, checked cooperatively at the same
        // two progress sinks the parent guard uses (audit WF-1-rem). On the
        // forking path the pool kills this child at the same deadline anyway,
        // tree and all; this is what bounds the SYNCHRONOUS paths — a build
        // without pcntl, or a failed fork — where the pool runs this executor
        // inline and has no way to interrupt it. Cooperative, so a single
        // blocking provider call or tool runs to its own bound first; the run
        // stops at the next chunk or tool event after the deadline, before the
        // next tool starts. A non-positive timeout means no per-agent bound.
        $deadlineNs = $agent->timeout > 0 ? hrtime(true) + $agent->timeout * 1_000_000_000 : null;
        $expired = false;
        $checkDeadline = static function () use ($deadlineNs, &$expired, $agent): void {
            if ($deadlineNs !== null && hrtime(true) >= $deadlineNs) {
                $expired = true;

                throw new \RuntimeException(sprintf('the agent ran past its %d s timeout and was stopped', $agent->timeout));
            }
        };

        $onProgress = static function () use ($guard, $checkDeadline): void {
            $guard();
            $checkDeadline();
        };
        $onEvent = static function (object $event) use ($guard, $checkDeadline, $onToolStarted): void {
            $guard();
            $checkDeadline();
            if ($onToolStarted !== null && $event instanceof ToolStarted) {
                $onToolStarted($event);
            }
        };

        $messages = self::messages($agent, $request);

        try {
            $turn = $this->engine
                ->withTools($tools)
                ->withMaxSteps($maxTurns)
                ->completeTranscript(
                    $messages,
                    onEvent: $onEvent,
                    onReasoning: $onProgress,
                    onToken: $onToken,
                );
        } catch (TurnInterrupted $failure) {
            // A run that failed part-way still billed the steps it completed
            // (same bug class as audit B4): the interrupted transcript carries
            // each completed step's assistant turn with its usage.
            $usages = [];
            foreach (\array_slice($failure->transcript, \count($messages)) as $message) {
                if ($message instanceof AssistantMessage) {
                    $usages[] = $message->usage();
                }
            }

            return self::failed(
                $agent,
                $startedAt,
                $failure->getMessage(),
                Usage::sum($usages),
                $expired ? AgentStatus::TimedOut : AgentStatus::Failed,
            );
        }

        $output = trim($turn->reply->content);
        if ($output === '') {
            return self::failed($agent, $startedAt, sprintf(
                'the agent ended without a final report (step cap %d); any work it did is in the tree but was not summarised',
                $maxTurns,
            ), $turn->reply->usage);
        }

        return new AgentResult(
            agentId: $agent->id,
            status: AgentStatus::Completed,
            output: $output,
            tokensUsed: $turn->reply->usage?->totalTokens ?? 0,
            costUsd: $turn->reply->usage?->costUsd ?? 0.0,
            startedAt: $startedAt,
            completedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Nothing to do here: this executor runs INSIDE the child
     * {@see AgentWorkerPool} forks, so this process's own state is not where a
     * cancel can land. The pool's kill site is the cancel, and it reaches
     * everything this executor's run started (audit F-E2-rem): the pool kills
     * the forked child with {@see \SugarCraft\Crush\Support\ProcessContainment::killTree()}
     * (or, when the TUI's event loop is driving the run, its loop-tick twin
     * killTreeAsync() — audit R3), whose walk takes the engine's own turn
     * forks below it and every Bash command they run in its own `setsid`
     * session — a lone signal to the child would leave those running for
     * nobody.
     */
    public function cancel(string $agentId): void
    {
    }

    public function cancelAll(): void
    {
    }

    /**
     * The request's turns as typed messages, behind its system prompt. The pool
     * builds `['role' => 'user', 'content' => $task]` arrays; a request with no
     * turns at all falls back to the agent's task.
     *
     * @return list<Message>
     */
    private static function messages(SubAgent $agent, CompleteRequest $request): array
    {
        $messages = [];
        $systemPrompt = trim((string) $request->systemPrompt);
        if ($systemPrompt !== '') {
            $messages[] = new SystemMessage($systemPrompt);
        }

        foreach ($request->messages as $entry) {
            if ($entry instanceof Message) {
                $messages[] = $entry;

                continue;
            }
            if (!\is_array($entry)) {
                continue;
            }

            $content = (string) ($entry['content'] ?? '');
            $messages[] = match ((string) ($entry['role'] ?? 'user')) {
                'system' => new SystemMessage($content),
                'assistant' => new AssistantMessage($content),
                default => new UserMessage($content),
            };
        }

        if (\count($messages) === ($systemPrompt === '' ? 0 : 1)) {
            $messages[] = new UserMessage($agent->task);
        }

        return $messages;
    }

    /**
     * `Name(args…)` on one line — {@see \SugarCraft\Crush\Message::describeToolCall()},
     * the transcript's own rendering, flattened and capped for a pane tile.
     */
    private static function describe(ToolStarted $event): string
    {
        $line = \SugarCraft\Crush\Message::describeToolCall(
            new \SugarCraft\Crush\ToolCall($event->toolName, $event->arguments, $event->toolCallId),
        );
        $line = trim((string) preg_replace('/\s+/', ' ', $line));

        return mb_strlen($line) > self::TOOL_LINE_MAX ? mb_substr($line, 0, self::TOOL_LINE_MAX - 1) . '…' : $line;
    }

    /**
     * $spent is what the run billed before it failed: a failure does not
     * un-spend it, and the workflow's cost totals read it off this result.
     * $status is Failed, or TimedOut for a run its own timeout stopped.
     */
    private static function failed(
        SubAgent $agent,
        \DateTimeImmutable $startedAt,
        string $why,
        ?Usage $spent = null,
        AgentStatus $status = AgentStatus::Failed,
    ): AgentResult {
        return new AgentResult(
            agentId: $agent->id,
            status: $status,
            error: new \RuntimeException($why),
            tokensUsed: $spent?->totalTokens ?? 0,
            costUsd: $spent?->costUsd ?? 0.0,
            startedAt: $startedAt,
            completedAt: new \DateTimeImmutable(),
        );
    }
}
