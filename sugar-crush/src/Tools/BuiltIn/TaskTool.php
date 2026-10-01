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
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Support\ParentProcessGuard;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Tools\DelegatesToEngine;
use SugarCraft\Crush\Tools\ExemptFromParallelDeadline;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\PromptGuidance;
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
 * THE DELEGATED RUN IS VISIBLE, NOT COARSE. To the PARENT MODEL the tool still
 * surfaces as one ordinary ToolStarted/ToolFinished pair — upstream's Task tool
 * behaves the same way from the caller's point of view, one call, one final
 * report — but the run's own beats (tool calls, thinking bursts) are recorded
 * onto the sub-agent row as they happen and cross the wire as
 * {@see SubAgentActivity} frames, which the parent projects into its own
 * AgentManager ({@see AgentManager::projectRemoteSubAgent()}). They must: the
 * engine path runs the whole delegation INSIDE
 * {@see EngineBackend::completeAsync()}'s forked child, so without the frames
 * the child's row — and every chunk streamed into it — died with the child and
 * the Agents dashboard showed nothing for a delegation that was visibly,
 * expensively running.
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
final readonly class TaskTool implements Tool, ParallelSafe, ExemptFromParallelDeadline, DelegatesToEngine, PromptGuidance
{
    /** Step cap for a preset that declares no `maxTurns`. */
    public const DEFAULT_MAX_TURNS = 50;

    /** The instruction a resume continues with when the caller gives no better one. */
    public const DEFAULT_RESUME_PROMPT = 'Continue the task from where you stopped. When it is done, reply with your final report.';

    /**
     * Byte ceiling on a run's rolling activity tail — the same buffer that is
     * recorded onto the row, sent as progress-frame tails, and left as the
     * finished row's output when there is no report. Frame size stays bounded
     * no matter how chatty the delegation gets.
     */
    public const ACTIVITY_TAIL_BYTES = 4096;

    /** Byte ceiling on the task snippet a started frame carries for display. */
    public const TASK_SNIPPET_BYTES = 200;

    /** Reasoning bytes accumulated before a "thinking" line is folded into the trail. */
    public const THINK_LINE_BYTES = 160;

    /**
     * UTF-8 cost of the '…' marker the clippers prepend/append. The clip
     * budgets reserve this much, so a clipped string stays WITHIN its byte
     * cap instead of overshooting it by two — the off-by-two a "1 byte for
     * the dots" reservation silently ships for every non-ASCII ellipsis.
     */
    public const ELLIPSIS_BYTES = 3;

    /**
     * @param \Closure(): void|null $heartbeat see {@see DelegatesToEngine}
     * @param \Closure(SubAgentActivity): void|null $subAgentEmitter see {@see DelegatesToEngine}
     * @param SuspendedDelegations|null $suspended where resumable runs are kept;
     *        null uses {@see SuspendedDelegations::new()}
     */
    public function __construct(
        private ?AgentManager $agentManager = null,
        private ?AgentWorkerPool $workerPool = null,
        private ?EngineBackend $engine = null,
        private ?\Closure $heartbeat = null,
        private ?SuspendedDelegations $suspended = null,
        private ?\Closure $subAgentEmitter = null,
    ) {}

    public function withEngine(
        EngineBackend $engine,
        ?\Closure $heartbeat = null,
        ?\Closure $subAgentEmitter = null,
    ): self {
        return new self($this->agentManager, $this->workerPool, $engine, $heartbeat, $this->suspended, $subAgentEmitter);
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
     * Batch-spawn doctrine for the session prompt (spawn-latency plan F1, with
     * the orchestration-mode sanction of F7). The one-call-per-message pattern
     * this prose exists to break is the dominant latency cost of delegating on
     * this harness: every un-batched spawn pays a full model round-trip before
     * the next worker even starts, while a message carrying N Task calls forks
     * all N at once for a single step.
     *
     * The fragment names no sibling tool ({@see PromptGuidance}), stays
     * self-contained when the tool is wired alone, and carries the live roster
     * only when a manager is bound — an unbound corpus build renders the
     * doctrine without a roster line rather than the lie of an empty one.
     */
    public function promptGuidance(): string
    {
        $doctrine = 'Delegate breadth-first work by emitting one Task call per independent unit of work inside a SINGLE message: every spawn in that message launches together, runs concurrently, and the whole batch costs one step — while one Task per message pays a fresh model round-trip for each spawn. Batching Task calls this way is the sanctioned shape of orchestration in this build, even where project instructions advise running sub-agents one at a time: that rule scopes to concurrent writes onto shared files, not to the spawn calls themselves. Keep spawns that share a message on disjoint files, and never batch a spawn that depends on an earlier spawn\'s answer — a dependency stays sequential. A name off the live roster is refused loudly with the full roster named in the refusal, so a batched guess is cheap to correct.';

        if ($this->agentManager === null) {
            return $doctrine;
        }

        return $doctrine . "\n\n" . 'Current agent roster: ' . $this->rosterNames() . '.';
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
                // Deprecation-tolerant alias (F4): NOT in `required`, NOT
                // mentioned first anywhere — `agent` stays the contract.
                'subagent_type' => [
                    'type' => 'string',
                    'description' => 'Optional alias of `agent` accepted for callers that emit this name instead;'
                        . ' `agent` wins when both are present. Prefer `agent`.',
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

        // F4: `subagent_type` is the field name this model family carries from
        // its upstream priors, so it is accepted as an ALIAS of `agent` — the
        // canonical name still wins whenever it is non-blank, and a call that
        // fills neither gets the byte-identical refusal, naming only `agent`.
        // Tolerating the prior beats refusing it; documenting only `agent` as
        // canonical keeps the prior from becoming the wire contract.
        $agentName = trim((string) ($args['agent'] ?? ''));
        if ($agentName === '') {
            $agentName = trim((string) ($args['subagent_type'] ?? ''));
        }
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

        $orphanGuard = ParentProcessGuard::capture('turn that delegated it');
        $heartbeat = $this->heartbeat;
        $onProgress = static function () use ($orphanGuard, $heartbeat): void {
            $orphanGuard();
            if ($heartbeat !== null) {
                $heartbeat();
            }
        };

        $emit = $this->subAgentEmitter;
        $subAgent->status = SubAgent::STATUS_RUNNING;
        $subAgent->startedAt = new \DateTimeImmutable();

        // Frame ordering within one run: strictly increasing, minted here (the
        // run's own stack), never derived from wall clock — a reader that sees
        // seq 7 then 5 knows 5 is stale, whatever the sockets did to the order.
        $seq = 0;
        $lastBeat = 0.0;
        $think = '';

        // The run's visible trail. Every line lands on the row's rolling
        // output; $immediate lines (tool boundaries) also go out as a progress
        // frame at once, while reasoning-driven beats share the heartbeat's
        // one-a-second budget — the dashboard is a monitor, not a tape deck.
        $record = static function (string $line, bool $immediate) use (
            $subAgent, $agentName, $emit, &$seq, &$lastBeat,
        ): void {
            $subAgent->output = self::appendActivity($subAgent->output, $line);
            if ($emit === null) {
                return;
            }
            $now = microtime(true);
            if (!$immediate && $now - $lastBeat < 1.0) {
                return;
            }
            $lastBeat = $now;
            $emit(new SubAgentActivity(
                SubAgentActivity::OP_PROGRESS,
                $subAgent->id,
                $agentName,
                '',
                ++$seq,
                self::tailClip($subAgent->output, self::ACTIVITY_TAIL_BYTES),
            ));
        };

        // A reasoning burst becomes ONE trail line, folded at
        // THINK_LINE_BYTES so a long thought streams as a sequence of lines
        // instead of never appearing until the burst ends.
        $foldThink = static function () use (&$think, $record): void {
            if ($think === '') {
                return;
            }
            $line = $think;
            $think = '';
            $record('thinking: '.self::tailClip($line, self::THINK_LINE_BYTES), false);
        };

        if ($emit !== null) {
            $emit(new SubAgentActivity(
                SubAgentActivity::OP_STARTED,
                $subAgent->id,
                $agentName,
                self::snippet($subAgent->task, self::TASK_SNIPPET_BYTES),
                ++$seq,
                '',
            ));
        }

        // Terminal beat: settle the row, then say so once. The report wins
        // when there is one; a run that ends without one keeps its activity
        // trail as the row's output — the story of what it got through —
        // rather than going blank on the dashboard at exactly the moment a
        // human looks.
        $finish = function (string $status, string $report, ?string $error) use (
            $subAgent, $agentName, $emit, &$seq, $foldThink,
        ): void {
            $foldThink();
            // Report wins; without one the trail IS the row's story.
            $this->settle($subAgent, $status, $report !== '' ? $report : $subAgent->output, $error);
            if ($emit === null) {
                return;
            }
            $emit(new SubAgentActivity(
                SubAgentActivity::OP_FINISHED,
                $subAgent->id,
                $agentName,
                '',
                ++$seq,
                self::tailClip($subAgent->output, self::ACTIVITY_TAIL_BYTES),
            ));
        };

        try {
            $turn = $engine
                ->withTools($tools)
                ->withMaxSteps($maxTurns)
                ->completeTranscript(
                    $messages,
                    // SubAgentActivity is deliberately NOT in this signature:
                    // the grant filter strips every DelegatesToEngine tool from
                    // the sub-agent's list, delegation is one level deep, and a
                    // nested emitter therefore never exists on this channel.
                    onEvent: static function (ToolStarted|ToolFinished $event) use ($onProgress, $record, $foldThink): void {
                        $onProgress();
                        if ($event instanceof ToolStarted) {
                            $foldThink();
                            $record('-> '.$event->toolName, true);

                            return;
                        }
                        $foldThink();
                        $record('<- '.$event->toolName.($event->result->isError() ? ' (error)' : ''), true);
                    },
                    onReasoning: static function (string $delta) use ($onProgress, &$think, $foldThink): void {
                        $onProgress();
                        // The heartbeat's "alive, nothing to show" frame is an
                        // empty delta ({@see EngineBackend::turnTools()}) — it
                        // carries no thought to fold.
                        if ($delta === '') {
                            return;
                        }
                        $think .= $delta;
                        if (strlen($think) >= self::THINK_LINE_BYTES) {
                            $foldThink();
                        }
                    },
                    onHeartbeat: $heartbeat,
                );
        } catch (TurnInterrupted $failure) {
            $why = sprintf('sub-agent "%s" failed: %s', $agentName, $failure->getMessage());
            $finish(SubAgent::STATUS_FAILED, '', $why);

            return $this->refusal(
                $toolCallId,
                $why . '. ' . $this->suspend($agentName, $failure->transcript, $resumes, $suspension['id'] ?? null),
                self::elapsedMs($startedAt),
            );
        }

        $reply = $turn->reply;
        $content = trim($reply->content);
        $finish(SubAgent::STATUS_COMPLETE, $content, null);
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

    private function settle(SubAgent $subAgent, string $status, string $output, ?string $error): void
    {
        $subAgent->status = $status;
        $subAgent->output = $output;
        $subAgent->error = $error;
        $subAgent->completedAt = new \DateTimeImmutable();
    }

    /**
     * Append one trail line to the rolling activity buffer, oldest lines
     * evicting from the head once the buffer passes {@see ACTIVITY_TAIL_BYTES}.
     * Whole-line eviction, never a byte slice: a cut mid-codepoint is exactly
     * the invalid UTF-8 the width-computing renderers trip over.
     */
    private static function appendActivity(string $current, string $line): string
    {
        $lines = $current === '' ? [] : explode("\n", $current);
        $lines[] = $line;

        $grown = implode("\n", $lines);
        while (strlen($grown) > self::ACTIVITY_TAIL_BYTES && count($lines) > 1) {
            $lines = array_slice($lines, 1);
            $grown = implode("\n", $lines);
        }

        return $grown;
    }

    /**
     * Keep the last $bytes of $text, byte-bounded for the wire, prepending the
     * ellipsis and dropping any leading fragment of a split codepoint (the
     * empty-pattern /u probe is the established validity oracle).
     */
    private static function tailClip(string $text, int $bytes): string
    {
        if (strlen($text) <= $bytes) {
            return $text;
        }

        if ($bytes <= self::ELLIPSIS_BYTES) {
            return ''; // the cap fits the marker alone or less — nothing displayable survives
        }

        $cut = substr($text, -($bytes - self::ELLIPSIS_BYTES));
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 1);
        }

        return '…' . $cut;
    }

    /**
     * The task's first line, byte-bounded, for the started frame's display
     * snippet — the delegated prompt can be pages, and a dashboard row shows
     * one.
     */
    private static function snippet(string $text, int $bytes): string
    {
        $first = strtok($text, "\r\n");
        $first = $first === false ? '' : trim($first);
        if (strlen($first) <= $bytes) {
            return $first;
        }

        if ($bytes < self::ELLIPSIS_BYTES) {
            return ''; // a cap below the marker cannot carry even the dots
        }

        $cut = substr($first, 0, $bytes - self::ELLIPSIS_BYTES);
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }

        return $cut . '…';
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
