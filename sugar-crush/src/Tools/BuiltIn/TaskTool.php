<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\TurnInterrupted;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Tools\DelegatesToEngine;
use SugarCraft\Crush\Tools\ExemptFromParallelDeadline;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * The model-callable Task tool: delegate one bounded task to a sub-agent from
 * the session's agent roster and return the sub-agent's final text (crush_code.md P8.13).
 *
 * This is the seam the whole sub-agent machinery was built toward but never had:
 * {@see AgentManager::createSubAgent()} and {@see AgentManager::executeAll()}
 * existed, `Chat::executeAgents()` reached them only through slash-command
 * plumbing, and `src/Renderer.php`'s header recorded the absence in terms —
 * "nothing in src/ or bin/ calls createSubAgent()/executeSubAgent() directly,
 * because there is no Task/Agent tool". The name deliberately mirrors the
 * upstream delegation tool rather than the unrelated `SugarCraft\Crush\Agents\Task`
 * value object, which this class neither reads nor extends; the `Tool` suffix
 * on the class follows the `LspTool`/`SkillTool` precedent, the bare wire name
 * `Task` follows what the model already knows from upstream.
 *
 * THE CEILING IS INHERITED, NOT REIMPLEMENTED. Dispatch goes through
 * {@see AgentManager::executeAll()}, which resolves each batch member's grants
 * against the session registry (E644) and hands the worker pool a per-agent
 * resolver — the same governed path `Chat::executeAgents()` drives. A Task call
 * can never widen what the named agent declared, and a batch that fails grant
 * resolution settles its members with the reason and fails loud here rather
 * than forking anything.
 *
 * FAIL-CLOSED IN FOUR PLACES, because a delegation tool that fabricates is
 * worse than no delegation tool at all:
 *  - unbound (no session {@see AgentManager} wired in) → refusal naming the
 *    missing wiring; the corpus builds this instance standalone, and the one
 *    consumer that calls `execute()` on every corpus tool must get an error
 *    result, never a spawn and never a throw;
 *  - no worker provider spec (a pool bound without one falls through to the
 *    manager's default pool, whose refusing worker reports FAILED naming the
 *    absence) → the refusal text comes back as this result's error;
 *  - anything the pool reports as Failed/Stopped/TimedOut → loud, with the
 *    status and the child's own message, never a plausible-looking summary.
 *  - unknown agent → the roster is named in full, so the model can correct
 *    itself on the next turn instead of hallucinating one.
 *
 * COARSE BY DECISION: the tool surfaces as one ordinary ToolStarted/ToolFinished
 * pair; the sub-agent's own tool events and streamed partials stay inside the
 * delegated run (they feed only its liveness heartbeat). Upstream's
 * Task tool behaves the same way from the parent model's point of view — one
 * call, one final report — and the live-progress surfaces (pane, status strip)
 * keep their own feed through Chat/WorkflowEngine, which this path does not
 * duplicate.
 *
 * THE SUB-AGENT IS A WHOLE AGENTIC RUN, NOT ONE COMPLETION. The pool path
 * below is a single provider call in a `php -r` worker that advertises the
 * grant and executes nothing, so a sub-agent whose first move was a tool call
 * — which, for any real task, is every sub-agent — came back as "completed
 * without any output text" in 2-13s. Measured in a live session: ten audit
 * delegations in a row, all refused that way. When the running
 * {@see EngineBackend} is bound ({@see DelegatesToEngine}, which it does for
 * itself every turn), the sub-agent instead runs through that engine's own
 * bounded tool loop: the SAME provider, hook chain (ProtectFilesHook and the
 * trusted project hooks), permission gate, approver, spend cap and root as
 * the turn that called it, with the tool list narrowed to the preset's grant
 * ({@see AgentManager::grantedToolsFor()}), its prompt and skills as a system
 * turn ({@see AgentManager::systemPromptFor()}), and `maxTurns` as the step
 * cap. `Task` itself is withheld from the sub-agent, so delegation is one
 * level deep. The pool path stays as the unbound fallback.
 *
 * THE PROVIDER AND MODEL ARE THE SESSION'S. A preset's `model:` (`sonnet`,
 * `inherit`, …) is not re-resolved on the engine path: the sub-agent talks to
 * whatever provider the calling turn is on, because a preset alias has no
 * meaning to, say, a self-hosted SGLang endpoint — sending it there is a
 * failed request, not a model choice.
 *
 * THE PERMISSION MODE IS THE SESSION'S. A preset's `permissionMode:` is not
 * applied on the engine path: the session's gate governs every call the
 * sub-agent makes, exactly as it governs the caller's own, and the preset's
 * `tools:` grant is what narrows it. A delegation can therefore never do more
 * than the session that asked for it could.
 *
 * SEVERAL TASK CALLS IN ONE MESSAGE RUN CONCURRENTLY. Bound, this tool is
 * {@see ParallelSafe}, which the interface's rule 1 would forbid for a tool
 * that edits files — and that is the point of delegating: the caller asked
 * for parallel workers and owns keeping them off each other's files. The two
 * hazards that rule guards are closed here instead. The 90s group deadline
 * would kill every run, so the tool is {@see ExemptFromParallelDeadline} and
 * bounds itself by `maxTurns`; and a forked run that outlives a cancelled turn
 * would keep editing the tree, so every chunk and tool event checks the
 * parent pid it started under and abandons the run once that process is gone.
 *
 * A RUN THAT ENDS WITHOUT A REPORT CAN BE RESUMED. When the sub-agent hits its
 * step cap without answering, or fails part-way (a provider error, a dropped
 * connection, a cancelled parent), its transcript is saved to
 * {@see SuspendedDelegations} and the refusal names a `resume` id. Calling
 * Task again with that id continues the SAME conversation — every tool call
 * and result it already made — with `prompt` as the next instruction, and
 * another full `maxTurns` of steps. The id is stable across repeated resumes,
 * and the refusal counts them, so a caller can apply its own retry budget; a
 * report clears it. A refusal that says nothing about resuming is one where
 * nothing ran (bad grant, unknown agent) and there is nothing to continue.
 *
 * Mirrors the delegation role of sugar-crush's plan P8.13 (`Task` tool) over
 * the in-tree {@see AgentManager}/{@see AgentWorkerPool} machinery; see
 * {@see AgentWorkerPool::executeAll()} for what a dispatched worker actually
 * carries across the fork.
 */
final readonly class TaskTool implements Tool, ParallelSafe, ExemptFromParallelDeadline, DelegatesToEngine
{
    /** Step cap for a preset that declares no `maxTurns`. */
    public const DEFAULT_MAX_TURNS = 50;

    /** The instruction a resume continues with when the caller gives no better one. */
    public const DEFAULT_RESUME_PROMPT = 'Continue the task from where you stopped. When it is done, reply with your final report.';

    /**
     * @param \Closure(): void|null $heartbeat see {@see DelegatesToEngine}
     * @param SuspendedDelegations|null $suspended where resumable runs are kept;
     *        null uses {@see SuspendedDelegations::new()}
     */
    public function __construct(
        private ?AgentManager $agentManager = null,
        private ?AgentWorkerPool $workerPool = null,
        private ?EngineBackend $engine = null,
        private ?\Closure $heartbeat = null,
        private ?SuspendedDelegations $suspended = null,
    ) {}

    public function withEngine(EngineBackend $engine, ?\Closure $heartbeat = null): self
    {
        return new self($this->agentManager, $this->workerPool, $engine, $heartbeat, $this->suspended);
    }

    /**
     * Only the engine path forks safely: it bounds itself and watches for its
     * parent's death. The unbound pool path keeps the barrier it always had.
     */
    public function isParallelSafe(): bool
    {
        return $this->engine !== null && $this->agentManager !== null;
    }

    public function name(): string
    {
        return 'Task';
    }

    public function description(): string
    {
        return 'Delegate one self-contained task to a sub-agent from the agent roster and return that agent\'s final text.'
            . ' The sub-agent does not see this conversation, so the prompt must carry every path, constraint and'
            . ' expected output the task needs; the tool returns once the sub-agent finishes, not as it works.'
            . ' The sub-agent works with its own tools until it has an answer; several Task calls in one message run'
            . ' concurrently, so keep parallel agents off each other\'s files.'
            . ' Do not reach for it for work you can do directly in one call, and never parallelise a dependency with'
            . ' it — the delegated agent runs under the roster entry\'s own tool grants and this session\'s permission'
            . ' policy, so a narrower agent cannot be widened by phrasing.'
            . ' The result is the sub-agent\'s report or a loud failure naming why; it is not a diff, and the working'
            . ' tree may have moved underneath by the time it returns.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'description' => [
                    'type' => 'string',
                    'description' => 'Clear, concise 5-10 word description in active voice of what is delegated'
                        . ' (e.g. "Audit the auth middleware", not "delegates a task")',
                ],
                'prompt' => [
                    'type' => 'string',
                    'description' => 'The complete task for the sub-agent, self-contained: it never sees this'
                        . ' conversation, so include every path, constraint and expected-output shape inline',
                ],
                'agent' => [
                    'type' => 'string',
                    'description' => 'Roster name of the agent to run (e.g. "coder", "reviewer", "debugger",'
                        . ' "architect", "tester", "devops"); an unknown name is refused and the live roster is'
                        . ' named in the failure',
                ],
                'resume' => [
                    'type' => 'string',
                    'description' => 'Optional. The resume id from an earlier Task result that ended without a'
                        . ' report or was interrupted: continues that sub-agent\'s own conversation instead of'
                        . ' starting over. `agent` must name the same agent, and `prompt` is the instruction to'
                        . ' continue with (e.g. "continue and give your final report")',
                ],
            ],
            'required' => ['description', 'prompt', 'agent'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $startedAt = microtime(true);
        $toolCallId = (string) ($args['id'] ?? '');

        $prompt = trim((string) ($args['prompt'] ?? ''));
        if ($prompt === '') {
            return $this->refusal($toolCallId, 'the Task tool needs a non-empty "prompt": the complete, self-contained task the sub-agent should carry out');
        }

        $agentName = trim((string) ($args['agent'] ?? ''));
        if ($agentName === '') {
            return $this->refusal($toolCallId, 'the Task tool needs a non-empty "agent": the roster name to run the task as');
        }

        // Bound or absent — the LAUNCH decision, before anything is dispatched.
        if ($this->agentManager === null) {
            return $this->refusal(
                $toolCallId,
                'the Task tool is not wired to a session AgentManager on this launch, so it cannot delegate'
                . ' (the registration feed is the documented Bootstrap seam; refusing rather than inventing a manager)',
            );
        }

        // The roster is the authority on names; answering with the live list on
        // a miss is what keeps a hallucinated agent cheap to recover from.
        if ($this->agentManager->get($agentName) === null) {
            return $this->refusal($toolCallId, sprintf(
                'agent "%s" is not in the session roster (registered: %s)',
                $agentName,
                $this->rosterNames(),
            ));
        }

        $resumeId = trim((string) ($args['resume'] ?? ''));
        $suspension = null;
        if ($resumeId !== '') {
            if ($this->engine === null) {
                return $this->refusal($toolCallId, 'resume needs the engine-bound Task path, which this launch did not wire; start a new Task instead');
            }

            $suspension = $this->suspendedStore()->load($resumeId);
            if ($suspension === null) {
                return $this->refusal($toolCallId, sprintf(
                    'resume id "%s" is unknown, expired or unreadable, so that run CANNOT be resumed; start a new Task instead',
                    $resumeId,
                ));
            }

            if ($suspension['agent'] !== $agentName) {
                return $this->refusal($toolCallId, sprintf(
                    'resume id "%s" belongs to agent "%s", not "%s"; resume it with that agent',
                    $resumeId,
                    $suspension['agent'],
                    $agentName,
                ));
            }
        }

        try {
            $subAgent = $this->agentManager->createSubAgent($agentName, $prompt);
        } catch (\RuntimeException|\LogicException $refusal) {
            return $this->refusal($toolCallId, $refusal->getMessage());
        }

        if ($this->engine !== null) {
            return $this->runOnEngine(
                $this->engine,
                $this->agentManager,
                $subAgent,
                $toolCallId,
                $startedAt,
                $suspension === null ? null : ['id' => $resumeId] + $suspension,
            );
        }

        $request = new CompleteRequest(
            model: $subAgent->agent->model,
            messages: [],
            tools: null,
            systemPrompt: $subAgent->agent->prompt,
        );

        try {
            /** @var list<AgentResult> $results */
            $results = iterator_to_array(
                $this->agentManager->executeAll([$subAgent], $request, $this->workerPool),
                false,
            );
        } catch (\RuntimeException $refusal) {
            // E644 grant-resolution failures arrive here AFTER the manager has
            // settled the batch with the reason; forwarding that text is what
            // keeps the model-facing story identical to the operator-facing one.
            return $this->refusal($toolCallId, $refusal->getMessage());
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($results === []) {
            return $this->refusal($toolCallId, sprintf(
                'sub-agent "%s" was cancelled before it produced a result',
                $agentName,
            ), $durationMs);
        }

        $result = $results[0];

        if ($result->isFailure()) {
            return $this->refusal($toolCallId, sprintf(
                'sub-agent "%s" %s%s%s',
                $agentName,
                $result->status->value,
                $result->error !== null ? ': ' . $result->error->getMessage() : '',
                $result->output !== null && trim($result->output) !== ''
                    ? ' — partial output: ' . trim($result->output)
                    : '',
            ), $durationMs);
        }

        if ($result->output === null || trim($result->output) === '') {
            return $this->refusal($toolCallId, sprintf(
                'sub-agent "%s" completed without any output text',
                $agentName,
            ), $durationMs);
        }

        return new ToolResult(
            toolCallId: $toolCallId,
            content: trim($result->output),
            isError: false,
            durationMs: $durationMs,
        );
    }

    /**
     * Run $subAgent to completion through the bound engine's tool loop — see
     * the class doc for what it inherits and what it is narrowed to.
     *
     * @param array{id: string, agent: string, transcript: list<\SugarCraft\Crush\Messages\Message>, resumes: int}|null $suspension
     *        the saved run to continue, or null for a fresh one
     */
    private function runOnEngine(
        EngineBackend $engine,
        AgentManager $manager,
        SubAgent $subAgent,
        string $toolCallId,
        float $startedAt,
        ?array $suspension = null,
    ): ToolResult {
        $agentName = $subAgent->agent->name;

        try {
            $granted = $manager->grantedToolsFor($subAgent);
            $systemPrompt = $manager->systemPromptFor($subAgent);
        } catch (\RuntimeException $refusal) {
            $this->settle($subAgent, SubAgent::STATUS_FAILED, '', $refusal->getMessage());

            return $this->refusal($toolCallId, $refusal->getMessage());
        }

        $tools = array_values(array_filter(
            $granted ?? $engine->tools(),
            static fn (Tool $tool): bool => !$tool instanceof DelegatesToEngine,
        ));
        $maxTurns = max(1, $subAgent->agent->maxTurns ?? self::DEFAULT_MAX_TURNS);

        if ($suspension !== null) {
            // The saved transcript already opens with the preset's system turn.
            $messages = [...$suspension['transcript'], new UserMessage($subAgent->task)];
        } else {
            $messages = trim($systemPrompt) === '' ? [] : [new SystemMessage($systemPrompt)];
            $messages[] = new UserMessage($subAgent->task);
        }
        $resumes = $suspension === null ? 0 : $suspension['resumes'] + 1;

        $orphanGuard = self::orphanGuard();
        $heartbeat = $this->heartbeat;
        $onProgress = static function () use ($orphanGuard, $heartbeat): void {
            $orphanGuard();
            if ($heartbeat !== null) {
                $heartbeat();
            }
        };

        $subAgent->status = SubAgent::STATUS_RUNNING;
        $subAgent->startedAt = new \DateTimeImmutable();

        try {
            $turn = $engine
                ->withTools($tools)
                ->withMaxSteps($maxTurns)
                ->completeTranscript(
                    $messages,
                    onEvent: static function () use ($onProgress): void {
                        $onProgress();
                    },
                    onReasoning: static function () use ($onProgress): void {
                        $onProgress();
                    },
                    onHeartbeat: $heartbeat,
                );
        } catch (TurnInterrupted $failure) {
            $why = sprintf('sub-agent "%s" failed: %s', $agentName, $failure->getMessage());
            $this->settle($subAgent, SubAgent::STATUS_FAILED, '', $why);

            return $this->refusal(
                $toolCallId,
                $why . '. ' . $this->suspend($agentName, $failure->transcript, $resumes, $suspension['id'] ?? null),
                self::elapsedMs($startedAt),
            );
        }

        $reply = $turn->reply;
        $content = trim($reply->content);
        $this->settle($subAgent, SubAgent::STATUS_COMPLETE, $content, null);
        $subAgent->tokensUsed += $reply->usage?->totalTokens ?? 0;
        $subAgent->costUsd += $reply->usage?->costUsd ?? 0.0;

        if ($content === '') {
            return $this->refusal($toolCallId, sprintf(
                'sub-agent "%s" ended without a final report (step cap %d); any work it did is in the tree'
                . ' but was not summarised. %s',
                $agentName,
                $maxTurns,
                $this->suspend($agentName, $turn->transcript, $resumes, $suspension['id'] ?? null),
            ), self::elapsedMs($startedAt));
        }

        if ($suspension !== null) {
            $this->suspendedStore()->forget($suspension['id']);
        }

        return new ToolResult(
            toolCallId: $toolCallId,
            content: $content,
            isError: false,
            durationMs: self::elapsedMs($startedAt),
        );
    }

    /**
     * Save a run that ended without a report and say how to continue it — or,
     * if it cannot be saved, say plainly that it cannot be resumed.
     *
     * @param list<\SugarCraft\Crush\Messages\Message> $transcript
     */
    private function suspend(string $agentName, array $transcript, int $resumes, ?string $id): string
    {
        try {
            $id = $this->suspendedStore()->save($agentName, $transcript, $resumes, $id);
        } catch (\RuntimeException $unsaved) {
            return 'It CANNOT be resumed (the run could not be saved: ' . $unsaved->getMessage() . '); start a new Task instead';
        }

        return sprintf(
            'Resume it by calling Task again with "resume": "%s" and agent "%s"%s',
            $id,
            $agentName,
            $resumes > 0 ? sprintf(' (it has been resumed %d time%s)', $resumes, $resumes === 1 ? '' : 's') : '',
        );
    }

    private function suspendedStore(): SuspendedDelegations
    {
        return $this->suspended ?? SuspendedDelegations::new();
    }

    /**
     * A check that throws once the process this run started under is gone.
     * Called from PHP-level progress sinks only (never from the provider's
     * transport callback), so the throw unwinds the run cleanly: a forked Task
     * whose turn was cancelled stops at its next chunk or tool event instead
     * of editing the tree for nobody.
     *
     * @return \Closure(): void
     */
    private static function orphanGuard(): \Closure
    {
        if (!function_exists('posix_getppid')) {
            return static function (): void {};
        }

        $parent = posix_getppid();

        return static function () use ($parent): void {
            if (posix_getppid() !== $parent) {
                throw new \RuntimeException('the turn that delegated it is gone (parent process exited), so the run was abandoned');
            }
        };
    }

    private function settle(SubAgent $subAgent, string $status, string $output, ?string $error): void
    {
        $subAgent->status = $status;
        $subAgent->output = $output;
        $subAgent->error = $error;
        $subAgent->completedAt = new \DateTimeImmutable();
    }

    private static function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * @param non-empty-string $why
     */
    private function refusal(string $toolCallId, string $why, ?int $durationMs = null): ToolResult
    {
        return new ToolResult(
            toolCallId: $toolCallId,
            content: 'Error: Task refused — ' . $why,
            isError: true,
            durationMs: $durationMs,
        );
    }

    /**
     * Comma-joined names of every agent the bound manager currently answers.
     */
    private function rosterNames(): string
    {
        $names = [];
        foreach ($this->agentManager?->all() ?? [] as $agent) {
            $names[] = $agent->name;
        }
        sort($names);

        return $names === [] ? 'none' : implode(', ', $names);
    }
}
