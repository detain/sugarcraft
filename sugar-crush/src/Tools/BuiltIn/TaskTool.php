<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\Live\ActivityItem;
use SugarCraft\Crush\Agents\Live\SubAgentActivityBuffer;
use SugarCraft\Crush\Agents\Live\SubAgentTranscriptLog;
use SugarCraft\Crush\Agents\Live\ToolSummary;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\TurnInterrupted;
use SugarCraft\Crush\Events\SpendCapBreached;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Support\ParentProcessGuard;
use SugarCraft\Crush\Support\SiblingSpendLedger;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Tools\DelegatesToEngine;
use SugarCraft\Crush\Tools\ActivitySink;
use SugarCraft\Crush\Tools\ExemptFromParallelDeadline;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\PromptGuidance;
use SugarCraft\Crush\Tools\SharesSiblingSpend;
use SugarCraft\Crush\Tools\StreamsActivity;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Usage;

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
 * THE PROVIDER IS THE SESSION'S; THE MODEL AND EFFORT ARE THE AGENT'S
 * (roadmap 4.1-1). The sub-agent talks to whatever provider the calling turn
 * is on, on the first of: the call's own `model` argument, the agent's model
 * when it names one (a preset's non-`inherit` `model:`, or the
 * `subagentModel` setting `Bootstrap::agentManager()` pins onto every agent
 * that would inherit), and the session's current model. A model the provider
 * cannot serve is REFUSED, never relabelled: a single-model server that
 * reports what it serves ({@see EngineBackend::servedModel()}) is held to it,
 * and a Claude Code tier alias (`sonnet`, `opus`, `haiku`) is accepted only
 * where the session's own model is of that tier, since on any other provider
 * it names nothing. A preset's `effort:` rides every request as the
 * reasoning effort, and is refused on a provider that would silently drop it
 * ({@see EngineBackend::honoursReasoningEffort()}).
 *
 * THE PERMISSION MODE NARROWS, NEVER WIDENS (roadmap 4.1-2). The session's
 * gate governs every call the sub-agent makes, exactly as it governs the
 * caller's own, and the preset's `tools:` grant narrows it. A preset's
 * `permissionMode:` narrows it further when it is the stricter of the two:
 * the sub-agent's own gate ({@see AgentManager::createSubAgent()}, built for
 * the stricter mode) is put to every call as a second gate through
 * {@see \SugarCraft\Crush\Hooks\BuiltIn\SubAgentGrantHook} — so a `plan`
 * reviewer cannot write under a `default` session — while a WIDER preset mode
 * (`bypass-permissions` under `default`) changes nothing. A delegation can
 * therefore never do more than the session that asked for it could.
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
 * EVERY RUN THAT RAN CAN BE RESUMED (step 4.7-1). Whether the sub-agent
 * reports, hits its step cap (its report is then the engine's no-tools
 * summary of where it stopped), or fails part-way (a provider error, a dropped
 * connection, a cancelled parent), its transcript is saved to
 * {@see SuspendedDelegations} and the result names a `resume` id. Calling
 * Task again with that id continues the SAME conversation — every tool call
 * and result it already made — with `prompt` as the next instruction, and
 * another full `maxTurns` of steps: a follow-up to a finished agent, or a
 * retry of a failed one. The id is stable across repeated resumes, and the
 * result counts them, so a caller can apply its own retry budget. A run that
 * fails also hands back the last 3 x {@see ACTIVITY_TAIL_BYTES} bytes of what
 * it produced, fenced. A refusal that says nothing about resuming is one where
 * nothing ran (bad grant, unknown agent) and there is nothing to continue.
 *
 * EVERY RUN KEEPS ITS OWN TRANSCRIPT, AND BECOMES A SESSION (step P-C1). The
 * process running the sub-agent writes the whole conversation — its task,
 * prose, thoughts, every tool call and (clipped) result, and how it ended — to
 * a {@see SubAgentTranscriptLog} under the delegating session, and names the
 * file on the started and finished frames. The PARENT, which alone writes
 * SQLite, reads it back into a `subagent` child session when the finished
 * frame lands ({@see AgentManager::projectRemoteSubAgent()}), so a finished
 * agent stays viewable. A resume continues the same log.
 *
 * THE SUB-AGENT'S SPEND IS THE CALLER'S SPEND (audit B4). Every result this
 * tool returns after a run — report, refusal or interruption alike — carries
 * the run's {@see Usage} on {@see ToolResult::usage()}, because the bookkeeping
 * on the {@see SubAgent} row lives in a forked child and dies with it. The
 * engine folds that usage into the calling turn's total and its spend-cap
 * check, and the delegated run's own cap starts from what the calling turn
 * had spent when it started ({@see EngineBackend}'s turn spend probe). A run
 * the cap stops is refused naming the cap, never passed off as a report.
 * Parallel Task calls are siblings in one forked group, and share one spend
 * file ({@see SharesSiblingSpend}, audit B4-rem): each run records its steps as
 * it bills them and its cap check counts the others', so a batch cannot spend
 * the remaining budget once per sibling; and a run whose child dies before it
 * reports is still billed for the steps it recorded. Each sibling's
 * {@see SubAgentActivity} beats reach the Agents pane through a per-member
 * relay the forking parent drains ({@see RelaysSubAgentActivity}), since the
 * bound emitter only writes from the turn process.
 *
 * Mirrors the delegation role of sugar-crush's plan P8.13 (`Task` tool) over
 * the in-tree {@see AgentManager}/{@see AgentWorkerPool} machinery; see
 * {@see AgentWorkerPool::executeAll()} for what a dispatched worker actually
 * carries across the fork.
 */
final readonly class TaskTool implements Tool, ParallelSafe, ExemptFromParallelDeadline, DelegatesToEngine, PromptGuidance, SharesSiblingSpend, StreamsActivity, \SugarCraft\Crush\Tools\RelaysPermissionAsks
{
    /**
     * Step cap for a preset that declares no `maxTurns`. 200 since
     * WAVE_PLAN_2 §5 (was 50), alongside the main loop's 1000; it must read
     * the same as {@see \SugarCraft\Crush\Agents\EngineExecutor::DEFAULT_MAX_TURNS},
     * the workflow path's copy of the same default.
     */
    public const DEFAULT_MAX_TURNS = 200;

    /**
     * The tool's wire name — also how a display surface recognises a
     * delegation (the tools sidebar leaves them to the Agents pane).
     */
    public const NAME = 'Task';

    /**
     * Claude Code's model TIER names (roadmap 4.1-1). A preset copied from
     * `.claude/agents` routinely says `model: sonnet`; no engine provider
     * serves an id by that name, so it resolves only where the session's own
     * model is of that tier, and is refused everywhere else.
     */
    public const CLAUDE_MODEL_ALIASES = ['sonnet', 'opus', 'haiku'];

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
     * @param SiblingSpendLedger|null $siblingSpend see {@see SharesSiblingSpend};
     *        set only on the copy a concurrent group's forked member runs
     * @param (\Closure(): float)|null $activityClock the clock the run's frame
     *        buffer paces itself by; null is wall time ({@see withActivityClock()})
     * @param string|null $transcriptRoot where each run's
     *        {@see SubAgentTranscriptLog} goes; null is
     *        {@see SubAgentTranscriptLog::defaultRoot()} ({@see withTranscriptRoot()})
     * @param \SugarCraft\Crush\Sessions\BackgroundSupervisor|null $backgroundSupervisor
     *        what a background run is spawned through (roadmap 4.3-2,
     *        {@see withBackgroundSupervisor()}); null runs every call in the
     *        foreground
     * @param string $backgroundDirectory the project a background run works in
     */
    public function __construct(
        private ?AgentManager $agentManager = null,
        private ?AgentWorkerPool $workerPool = null,
        private ?EngineBackend $engine = null,
        private ?\Closure $heartbeat = null,
        private ?SuspendedDelegations $suspended = null,
        private ?\Closure $subAgentEmitter = null,
        private ?SiblingSpendLedger $siblingSpend = null,
        private ?\Closure $activityClock = null,
        private ?string $transcriptRoot = null,
        private ?\SugarCraft\Crush\Sessions\BackgroundSupervisor $backgroundSupervisor = null,
        private string $backgroundDirectory = '',
    ) {}

    /**
     * Rebuild with the named constructor fields replaced, every other one
     * carried forward BY NAME — so a field added to the constructor cannot be
     * dropped by a wither that forgot to spell it.
     *
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /**
     * The same tool, writing each run's transcript log below $dir instead of
     * `~/.sugar-crush/subagents` (step P-C1) — the seam a test or an embedder
     * points elsewhere.
     */
    public function withTranscriptRoot(string $dir): self
    {
        return $this->mutate(['transcriptRoot' => $dir]);
    }

    /**
     * The same tool, able to run a delegation in the BACKGROUND (roadmap
     * 4.3-2): a call with `background: true`, or for an agent whose preset
     * says `background: true`, spawns a session daemon through $supervisor
     * working in $workingDirectory and returns its `agent_id` at once. The
     * spawn happens in the turn's forked child; the supervisor stamps the
     * session as the HOST's, so the host's own supervisor adopts it on its
     * next poll and announces the result ({@see BackgroundSupervisor::adoptHandedOff()}).
     */
    public function withBackgroundSupervisor(\SugarCraft\Crush\Sessions\BackgroundSupervisor $supervisor, string $workingDirectory): self
    {
        return $this->mutate(['backgroundSupervisor' => $supervisor, 'backgroundDirectory' => $workingDirectory]);
    }

    /** What a background run is spawned through, or null when none is bound. */
    public function backgroundSupervisor(): ?\SugarCraft\Crush\Sessions\BackgroundSupervisor
    {
        return $this->backgroundSupervisor;
    }

    public function withEngine(
        EngineBackend $engine,
        ?\Closure $heartbeat = null,
        ?\Closure $subAgentEmitter = null,
    ): self {
        return $this->mutate(['engine' => $engine, 'heartbeat' => $heartbeat, 'subAgentEmitter' => $subAgentEmitter]);
    }

    /**
     * The copy {@see \SugarCraft\Crush\Runtime::executeConcurrently()} runs
     * as one member of a concurrent group: its delegated run records each step
     * onto $ledger and counts its siblings' records in its spend-cap check
     * (audit B4-rem). Only the engine path uses it; the pool path is never
     * parallel-safe, so it is never a group member.
     */
    public function withSiblingSpend(SiblingSpendLedger $ledger): self
    {
        return $this->mutate(['siblingSpend' => $ledger]);
    }

    public function subAgentEmitter(): ?\Closure
    {
        return $this->subAgentEmitter;
    }

    public function withSubAgentEmitter(\Closure $emitter): self
    {
        return $this->mutate(['subAgentEmitter' => $emitter]);
    }

    /**
     * The same tool, pacing its activity frames by $clock instead of wall
     * time — how a test makes the {@see SubAgentActivityBuffer} flush
     * interval deterministic (a clock that always reads "later" flushes every
     * item at once; a frozen one holds them all for the finished frame).
     *
     * @param \Closure(): float $clock
     */
    public function withActivityClock(\Closure $clock): self
    {
        return $this->mutate(['activityClock' => $clock]);
    }

    /**
     * The copy a forked member of a concurrent group runs: every beat goes to
     * $sink (the member's datagram relay) instead of the turn-pinned emitter,
     * which would drop it from this process — see {@see StreamsActivity}.
     */
    public function withActivitySink(ActivitySink $sink): self
    {
        return $this->withSubAgentEmitter(static function (SubAgentActivity $activity) use ($sink): void {
            $sink->emit($activity);
        });
    }

    /**
     * The copy a forked member of a concurrent group runs (roadmap 1.C-5):
     * every question its delegated run raises goes to $approver — the
     * member's {@see \SugarCraft\Crush\Support\PermissionAskRelay} — instead
     * of the turn child's channel, which refuses from any other process. A
     * copy with no engine bound has no run to gate and is returned as is.
     *
     * @param \Closure(\SugarCraft\Crush\Tools\ToolCall, \SugarCraft\Crush\Hooks\HookResult): \SugarCraft\Crush\Permissions\ApprovalVerdict $approver
     */
    public function withPermissionApprover(\Closure $approver): self
    {
        if ($this->engine === null) {
            return $this;
        }

        return $this->mutate(['engine' => $this->engine->withPermissionApprover($approver)]);
    }

    /**
     * A queued member's placeholder beat: the roster name and the task it
     * will run, under {@see SubAgentActivity::queuedId()} — the SubAgent id
     * does not exist until the member's own process creates it.
     *
     * @param array<string, mixed> $args
     */
    public function queuedActivity(\SugarCraft\Crush\Tools\ToolCall $call, array $args): ?SubAgentActivity
    {
        $agentName = trim((string) ($args['agent'] ?? ''));
        if ($agentName === '') {
            $agentName = trim((string) ($args['subagent_type'] ?? ''));
        }
        if ($agentName === '' || $call->id() === '') {
            return null;
        }

        return new SubAgentActivity(
            SubAgentActivity::OP_QUEUED,
            SubAgentActivity::queuedId($call->id()),
            $agentName,
            self::snippet(trim((string) ($args['prompt'] ?? '')), self::TASK_SNIPPET_BYTES),
            1,
            '',
            parentCallId: $call->id(),
            description: is_string($args['description'] ?? null) ? $args['description'] : '',
        );
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
        return self::NAME;
    }

    public function description(): string
    {
        return 'Delegate one self-contained task to a sub-agent from the agent roster and return that agent\'s final text.'
            . ' The sub-agent does not see this conversation, so the prompt must carry every path, constraint and'
            . ' expected output the task needs; the tool returns once the sub-agent finishes, not as it works —'
            . ' unless `background` is true, when it returns the agent\'s id at once and the result arrives later'
            . ' as a new message on its own.'
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

    // @region schema
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
                'model' => [
                    'type' => 'string',
                    'description' => 'Optional. The model id to run this sub-agent on, overriding the agent\'s own'
                        . ' and the session\'s; omit it to use the agent\'s model (or the session\'s, for an agent'
                        . ' that inherits). A model this session\'s provider cannot serve is refused',
                ],
                'resume' => [
                    'type' => 'string',
                    'description' => 'Optional. The resume id an earlier Task result named — a finished report,'
                        . ' a step-capped summary or a failure: continues that sub-agent\'s own conversation'
                        . ' instead of starting over. `agent` must name the same agent, and `prompt` is the'
                        . ' instruction to continue with (e.g. "continue and give your final report", or a'
                        . ' follow-up question about its report)',
                ],
                'background' => [
                    'type' => 'boolean',
                    'description' => 'Optional. true runs the sub-agent in the background: the call returns'
                        . ' `{"agent_id": ...}` at once and the sub-agent\'s status, report and stats arrive later as a'
                        . ' new message, so do NOT sleep or poll for it. false runs it in the foreground even when'
                        . ' the agent\'s preset says `background: true`; omit it to follow the preset',
                ],
            ],
            'required' => ['description', 'prompt', 'agent'],
        ];
    }
    // @endregion schema

    // @region execute
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

        // Roadmap 4.3-2: the call's `background` wins; without one, the
        // preset's. A launch with nowhere to spawn a session runs it in the
        // foreground and says so, rather than refusing work it can do.
        $backgroundNote = null;
        $wantsBackground = is_bool($args['background'] ?? null)
            ? $args['background']
            : ($this->agentManager->get($agentName)?->background ?? false);
        if ($wantsBackground) {
            if ($this->backgroundSupervisor !== null && $this->engine !== null) {
                return $this->startInBackground($toolCallId, $agentName, $prompt, $args, $resumeId, $startedAt);
            }
            $backgroundNote = '[background was requested, but this launch cannot start background sessions,'
                . ' so the sub-agent ran in the foreground and this is its result]';
        }

        try {
            // 4.1-2: the agent's own mode, narrowed to the session's — see
            // AgentManager::createSubAgent().
            $subAgent = $this->agentManager->createSubAgent(
                $agentName,
                $prompt,
                $this->agentManager->get($agentName)?->permissionMode,
                $this->engine?->permissionGate()?->mode(),
            );
        } catch (\RuntimeException|\LogicException $refusal) {
            return $this->refusal($toolCallId, $refusal->getMessage());
        }

        // Roadmap 4.1-1: the call's own model, when it names one.
        $requestedModel = is_string($args['model'] ?? null) ? trim($args['model']) : '';

        if ($this->engine !== null) {
            $result = $this->runOnEngine(
                $this->engine,
                $this->agentManager,
                $subAgent,
                $toolCallId,
                $startedAt,
                $suspension === null ? null : ['id' => $resumeId] + $suspension,
                is_string($args['description'] ?? null) ? $args['description'] : '',
                $requestedModel,
            );

            return $backgroundNote === null ? $result : $result->withContent($backgroundNote . "\n\n" . $result->content());
        }

        $request = new CompleteRequest(
            model: $requestedModel !== '' ? $requestedModel : $subAgent->agent->model,
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
        // The worker's provider call is billed whether or not it produced a
        // usable answer, so every arm below carries it (audit B4).
        $spent = Usage::reported($result->tokensUsed, $result->costUsd);

        if ($result->isFailure()) {
            return $this->refusal($toolCallId, sprintf(
                'sub-agent "%s" %s%s%s',
                $agentName,
                $result->status->value,
                $result->error !== null ? ': ' . $result->error->getMessage() : '',
                // The partial output is the sub-agent's text inside a
                // harness sentence: neutralised, no header (step 0.15).
                $result->output !== null && trim($result->output) !== ''
                    ? ' — partial output: ' . \SugarCraft\Crush\Context\DelegatedOutputFence::neutralise(trim($result->output))
                    : '',
            ), $durationMs, $spent);
        }

        if ($result->output === null || trim($result->output) === '') {
            return $this->refusal($toolCallId, sprintf(
                'sub-agent "%s" completed without any output text',
                $agentName,
            ), $durationMs, $spent);
        }

        return new ToolResult(
            toolCallId: $toolCallId,
            content: \SugarCraft\Crush\Context\DelegatedOutputFence::wrap(trim($result->output)),
            isError: false,
            durationMs: $durationMs,
            usage: $spent,
        );
    }

    /**
     * Start $agentName on $prompt as a background session and answer at once
     * with its id (roadmap 4.3-2).
     *
     * The daemon runs the delegation through this tool in its own process
     * ({@see \SugarCraft\Crush\Sessions\BackgroundSessionRunner}), so the
     * preset's grants, model, effort and step cap hold, the run is resumable,
     * and the session's permission mode rides along: it is never run under
     * a wider one than the session that asked. With nobody at a terminal, a
     * call that would ASK is refused there, and the result says so.
     *
     * The id is the background session's: what `/bg`, the announcement and
     * the turn context's "Active subagents" row all call it by.
     *
     * @param array<string, mixed> $args
     */
    private function startInBackground(string $toolCallId, string $agentName, string $prompt, array $args, string $resumeId, float $startedAt): ToolResult
    {
        $agent = $this->agentManager?->get($agentName);
        $engine = $this->engine;
        $supervisor = $this->backgroundSupervisor;
        if ($agent === null || $engine === null || $supervisor === null) {
            return $this->refusal($toolCallId, 'a background run needs the session\'s agent roster, engine and background supervisor');
        }

        $description = is_string($args['description'] ?? null) ? trim($args['description']) : '';
        $delegation = array_filter([
            'agent' => $agentName,
            'description' => $description,
            'model' => is_string($args['model'] ?? null) ? trim($args['model']) : '',
            'resume' => $resumeId,
            'permissionMode' => $engine->permissionGate()?->mode()->value ?? '',
            'scope' => $engine->sessionId() ?? '',
        ], static fn (string $value): bool => $value !== '');

        try {
            $session = $supervisor->spawnSession(
                name: ($description !== '' ? $description : self::snippet($prompt, 60)) . ' (@' . $agentName . ')',
                // The daemon starts on the model this session is on now; the
                // agent's own model (or the call's) is chosen over it there,
                // exactly as in the foreground (4.1-1).
                agent: trim($engine->model()) === '' ? $agent : $agent->withModel($engine->model()),
                task: $prompt,
                workingDirectory: $this->backgroundDirectory !== '' ? $this->backgroundDirectory : (getcwd() ?: '.'),
                tags: ['task', \SugarCraft\Crush\Sessions\BackgroundSupervisor::AGENT_TAG_PREFIX . $agentName],
                delegation: $delegation,
            );
        } catch (\Throwable $e) {
            return $this->refusal($toolCallId, sprintf(
                'agent "%s" could not be started in the background (%s); run it in the foreground instead (omit `background`)',
                $agentName,
                $e->getMessage(),
            ), self::elapsedMs($startedAt));
        }

        return new ToolResult(
            toolCallId: $toolCallId,
            content: (string) json_encode(['agent_id' => $session->id, 'status' => 'running'], JSON_UNESCAPED_SLASHES)
                . "\n\n" . sprintf(
                    'Sub-agent "%s" is running in the background as %s. DO NOT sleep or poll for it, and do not call'
                    . ' Task again to check on it: when it finishes, its status, report and stats (runtime, tokens,'
                    . ' cost and the resume id that continues it) arrive in this conversation as a new message, and'
                    . ' a turn starts for it if none is running. Carry on with other work meanwhile, or end your turn'
                    . ' if there is none.',
                    $agentName,
                    $session->id,
                ),
            isError: false,
            durationMs: self::elapsedMs($startedAt),
        );
    }
    // @endregion execute

    /**
     * Run $subAgent to completion through the bound engine's tool loop — see
     * the class doc for what it inherits and what it is narrowed to.
     *
     * @param array{id: string, agent: string, transcript: list<\SugarCraft\Crush\Messages\Message>, resumes: int, transcriptLog?: ?string}|null $suspension
     *        the saved run to continue, or null for a fresh one
     */
    private function runOnEngine(
        EngineBackend $engine,
        AgentManager $manager,
        SubAgent $subAgent,
        string $toolCallId,
        float $startedAt,
        ?array $suspension = null,
        string $description = '',
        string $requestedModel = '',
    ): ToolResult {
        // @region setup
        $agentName = $subAgent->agent->name;

        // Roadmap 4.1-1: the run's model and reasoning effort, both refused
        // up front — before anything is billed — when the session's provider
        // cannot honour them, rather than relabelled or silently dropped.
        [$runModel, $modelRefusal] = self::chooseModel($engine, $subAgent->agent, $requestedModel);
        if ($modelRefusal !== null) {
            $this->settle($subAgent, SubAgent::STATUS_FAILED, '', $modelRefusal);

            return $this->refusal($toolCallId, $modelRefusal);
        }
        if ($runModel !== null) {
            $engine = $engine->withModel($runModel);
        }
        $effort = $subAgent->agent->effort;
        if ($effort !== null) {
            if (!$engine->honoursReasoningEffort()) {
                $why = sprintf(
                    'agent "%s" declares `effort: %s`, but provider "%s" sends no reasoning effort, so the run'
                    . ' would silently ignore it; remove `effort:` from the preset, or delegate on a provider that'
                    . ' honours it (sglang)',
                    $agentName,
                    $effort->value,
                    $engine->provider()->name(),
                );
                $this->settle($subAgent, SubAgent::STATUS_FAILED, '', $why);

                return $this->refusal($toolCallId, $why);
            }
            $engine = $engine->withReasoningEffort($effort->value);
        }

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

        // The roster above is narrowed by tool NAME only, so `Bash(git *)`
        // put all of Bash on the wire. Every call the run makes is held to the
        // preset's whole declaration — argument halves and argument-scoped
        // denials included — by this hook, ahead of the session gate (4.2).
        // 4.1-2: and to the preset's permission mode, as a second gate beside
        // the session's (both must pass), when it is the stricter one — a gate
        // no stricter than the session's could only repeat its questions. An
        // engine with no gate (an embedder's) compares against `default`: it
        // is the mode every agent carries when its preset declared none, so
        // only a declared `plan`/`dont-ask` narrows there.
        $sessionMode = $engine->permissionGate()?->mode() ?? \SugarCraft\Crush\Permissions\PermissionMode::Default;
        $modeGate = $subAgent->permissionGate !== null && $subAgent->permissionGate->mode()->isStricterThan($sessionMode)
            ? $subAgent->permissionGate
            : null;
        $engine = $engine->withSubAgentGrant(
            new \SugarCraft\Crush\Hooks\BuiltIn\SubAgentGrantHook($manager, $subAgent, $modeGate),
        );

        if ($suspension !== null) {
            // The saved transcript already opens with the preset's system turn.
            $messages = [...$suspension['transcript'], new UserMessage($subAgent->task)];
        } else {
            $messages = trim($systemPrompt) === '' ? [] : [new SystemMessage($systemPrompt)];
            $messages[] = new UserMessage($subAgent->task);
        }
        $resumes = $suspension === null ? 0 : $suspension['resumes'] + 1;

        // Step P-C1: the run's own transcript, written HERE — in the process
        // that runs it (the turn child, or a parallel member's grandchild) —
        // and read back into a child session by the parent once the finished
        // frame arrives. A run with no session has no parent session to be a
        // child of, so it keeps no log (nor does a process with no owned
        // home to keep one in). A resume continues the log its
        // suspension names, so the stored session follows the conversation.
        $parentSessionId = $engine->sessionId();
        $log = null;
        $logRoot = $this->transcriptRoot ?? SubAgentTranscriptLog::defaultRoot();
        if ($parentSessionId !== null && $logRoot !== null) {
            $savedLog = $suspension['transcriptLog'] ?? null;
            $log = \is_string($savedLog) && SubAgentTranscriptLog::isLogPath($savedLog, $logRoot)
                ? SubAgentTranscriptLog::at($savedLog)
                : SubAgentTranscriptLog::forRun($parentSessionId, $subAgent->id, $logRoot);
            $log->user($subAgent->task);
        }
        $logPath = $log?->path();
        // Prose and reasoning arrive as deltas; each is written as ONE item
        // when the step's next tool call (or the run's end) closes it.
        $logProse = '';
        $logThought = '';
        $flushLog = static function () use ($log, &$logProse, &$logThought): void {
            if ($log !== null && trim($logThought) !== '') {
                $log->thinking($logThought);
            }
            if ($log !== null && trim($logProse) !== '') {
                $log->assistant($logProse);
            }
            $logProse = '';
            $logThought = '';
        };

        // Step P-D1: the run's mailbox. What the user (from the Agent View,
        // HMAC-signed) or the delegating agent sends this run while it works
        // is read at each of its step boundaries, framed by sender, and
        // logged beside the rest of its transcript. Keyed by the parent
        // session like the log: no session, no mailbox to address.
        $turnInbox = null;
        $agentInbox = $parentSessionId === null ? null : \SugarCraft\Crush\Agents\Live\AgentInbox::forSession($parentSessionId);
        if ($agentInbox !== null) {
            try {
                $turnInbox = \SugarCraft\Crush\Backend\MailboxTurnInbox::new($agentInbox, $subAgent->id, $log);
            } catch (\InvalidArgumentException) {
                // An id no mailbox can be named by (an embedder's own
                // SubAgent): the run cannot be messaged, and still runs.
            }
        }

        // Step 4.7-1: a run that FAILS hands back the last 3 x 4096 bytes of
        // what it produced — its own text, the calls it made and their
        // results, from this run's steps only (the opening turns and a
        // resumed run's earlier steps are not "partial output" of this one).
        // Before it the caller got the failure reason and a resume id, and the
        // only trace of the work was the activity trail on a dashboard row the
        // MODEL never sees, so it could not judge whether to resume, redo or
        // give up. Labels are bracketed, not `assistant:`, so the fence does
        // not quote them; the body is fenced because it is foreign bytes
        // re-entering the caller's conversation (step 0.15).
        $opening = count($messages);
        $failureTailBytes = 3 * self::ACTIVITY_TAIL_BYTES;
        $failureTail = static function (array $transcript) use ($opening, $failureTailBytes): string {
            $parts = [];
            foreach (array_slice($transcript, $opening) as $message) {
                if ($message instanceof AssistantMessage) {
                    $text = trim($message->content());
                    foreach ($message->toolCalls() ?? [] as $call) {
                        $text .= ($text === '' ? '' : "\n") . '-> ' . $call->name();
                    }
                    $label = '[sub-agent]';
                } elseif ($message instanceof \SugarCraft\Crush\Messages\ToolResultMessage) {
                    $text = trim($message->content());
                    $label = $message->isError() ? '[tool error]' : '[tool result]';
                } else {
                    $text = trim($message->content());
                    $label = '[' . $message->role() . ']';
                }
                if ($text !== '') {
                    $parts[] = $label . ' ' . $text;
                }
            }
            if ($parts === []) {
                return '';
            }

            return sprintf(
                ".\n\nIts partial output before it stopped (the last %d bytes at most):\n%s",
                $failureTailBytes,
                \SugarCraft\Crush\Context\DelegatedOutputFence::wrap(self::tailClip(implode("\n", $parts), $failureTailBytes)),
            );
        };

        $orphanGuard = ParentProcessGuard::capture('turn that delegated it');
        $heartbeat = $this->heartbeat;
        $onProgress = static function () use ($orphanGuard, $heartbeat): void {
            $orphanGuard();
            if ($heartbeat !== null) {
                $heartbeat();
            }
        };
        // 1.C-4b: Esc stopped this call (`cancel_tool{callId}`). A run in
        // this process cannot be killed from outside it without killing the
        // turn, so it stops itself at the next point that is safe to throw
        // from — a tool about to start, a provider step about to go out —
        // and the failure boundary below settles it cancelled and resumable.
        // In a concurrent member this never fires: the turn child kills the
        // member's process instead (ToolCancelRequests answers only there).
        $stopIfCancelled = static function () use ($toolCallId): void {
            if (\SugarCraft\Crush\Support\ToolCancelRequests::isRequested($toolCallId)) {
                throw new \SugarCraft\Crush\Support\ToolCallCancelled();
            }
        };

        $emit = $this->subAgentEmitter;
        // The engine above is already on the run's model (4.1-1), so the row
        // names the model the requests actually go to.
        $subAgent->runModel = $engine->model();
        $subAgent->status = SubAgent::STATUS_RUNNING;
        $subAgent->startedAt = new \DateTimeImmutable();

        // Frame ordering within one run: strictly increasing, minted here (the
        // run's own stack), never derived from wall clock — a reader that sees
        // seq 7 then 5 knows 5 is stale, whatever the sockets did to the order.
        $seq = 0;
        $think = '';
        // Whether the trail's last line is part of an open thought: the
        // `thinking:` label goes on a thought's first line only, so one long
        // burst reads as one block instead of a label per folded line.
        $thinking = false;
        // Trail lines produced, for the row's "N more lines" count — the
        // output itself is clipped to ACTIVITY_TAIL_BYTES.
        $lines = 0;

        // P-B1: what the run did since its last frame (coalesced, at most 4
        // frames a second) and the figures every frame carries. The stats
        // are the run's own: steps and tokens from each billed step, tool
        // calls from its events, cost from the row's running total.
        $buffer = SubAgentActivityBuffer::new($this->activityClock);
        $stats = [
            'step' => 0,
            'maxSteps' => $maxTurns,
            'tools' => 0,
            'tokensIn' => 0,
            'tokensOut' => 0,
            'costUsd' => 0.0,
            'startedAt' => $startedAt,
        ];

        // One v2 frame. Its items get whatever byte budget the rest of the
        // frame leaves under SubAgentActivityBuffer::MAX_BYTES; the v1 fields
        // (tail, recent calls) keep their own bounds.
        $frame = static function (string $op, array $extra = []) use (
            $subAgent, $agentName, $toolCallId, $description, $buffer, &$seq, &$lines, &$stats, $logPath, $parentSessionId,
        ): SubAgentActivity {
            // P-C1: the log and the parent session ride the two frames that
            // bracket the run, not every progress beat.
            $bracket = $op === SubAgentActivity::OP_STARTED || $op === SubAgentActivity::OP_FINISHED;
            $stats['costUsd'] = $subAgent->costUsd;
            $build = static fn (array $items): SubAgentActivity => new SubAgentActivity(
                $op,
                $subAgent->id,
                $agentName,
                $extra['task'] ?? '',
                $seq + 1,
                $op === SubAgentActivity::OP_STARTED ? '' : self::tailClip($subAgent->output, self::ACTIVITY_TAIL_BYTES),
                $subAgent->tokensUsed,
                $subAgent->costUsd,
                $lines,
                $extra['model'] ?? '',
                $subAgent->contextTokens,
                $op === SubAgentActivity::OP_STARTED ? [] : $subAgent->recentCalls,
                parentCallId: $toolCallId,
                description: $description,
                items: $items,
                stats: $stats,
                outcome: $extra['outcome'] ?? '',
                error: $extra['error'] ?? null,
                resumeId: $extra['resumeId'] ?? null,
                transcriptLog: $bracket ? $logPath : null,
                parentSessionId: $bracket ? $parentSessionId : null,
            );
            $budget = SubAgentActivityBuffer::MAX_BYTES - strlen(serialize($build([])->toArray()));
            $seq++;

            return $build($buffer->flush($budget));
        };

        // An activity frame whenever the buffer says one is due. Asked on
        // every callback the run makes, so a pending item waits at most about
        // one flush interval.
        $flushIfDue = static function () use ($emit, $buffer, $frame): void {
            if ($emit !== null && $buffer->due()) {
                $emit($frame(SubAgentActivity::OP_PROGRESS));
            }
        };

        // The row counts tokens as each step is billed, not only once the
        // run settles. The baseline is whatever the row carried before this
        // run (a resumed run keeps counting from it); bill() later replaces
        // the live figure with the settled one.
        $baseTokens = $subAgent->tokensUsed;
        $baseCost = $subAgent->costUsd;
        $observeUsage = static function (?Usage $usage) use ($subAgent, &$stats, $flushIfDue): void {
            $subAgent->tokensUsed += $usage?->totalTokens ?? 0;
            $subAgent->costUsd += $usage?->costUsd ?? 0.0;
            // One response's total is its prompt plus its reply — the size
            // of the context that request carried, which is what "current
            // context" means for a row.
            if (($usage?->totalTokens ?? 0) > 0) {
                $subAgent->contextTokens = $usage->totalTokens;
            }
            $stats['step']++;
            $stats['tokensIn'] += $usage?->inputTokens ?? 0;
            $stats['tokensOut'] += $usage?->outputTokens ?? 0;
            $flushIfDue();
        };

        // The run's visible trail. Every line lands on the row's rolling
        // output, and its item (if it has one) on the frame buffer; frames go
        // out at the buffer's pace — the dashboard is a monitor, not a tape
        // deck.
        $record = static function (string $line, ?ActivityItem $item) use (
            $subAgent, $buffer, $flushIfDue, &$lines,
        ): void {
            $subAgent->output = self::appendActivity($subAgent->output, $line);
            $lines++;
            if ($item !== null) {
                $buffer->add($item);
            }
            $flushIfDue();
        };

        // A reasoning burst becomes trail lines folded at THINK_LINE_BYTES,
        // so a long thought streams instead of never appearing until it ends.
        // Only the burst's first line carries the label (and the one
        // `thinking` item); the rest continue under it, indented, until a
        // tool boundary closes the thought.
        $foldThink = static function () use (&$think, &$thinking, $record): void {
            if ($think === '') {
                return;
            }
            $line = self::tailClip($think, self::THINK_LINE_BYTES);
            $think = '';
            $record(($thinking ? '  ' : 'thinking: ') . $line, $thinking ? null : ActivityItem::thinking());
            $thinking = true;
        };

        // The transport's own beat (fired inside a provider call) doubles as
        // the buffer's clock tick, so an item buffered just before a long
        // request still goes out within the flush interval.
        $onHeartbeat = $emit === null ? $heartbeat : static function () use ($heartbeat, $flushIfDue): void {
            $flushIfDue();
            if ($heartbeat !== null) {
                $heartbeat();
            }
        };

        if ($emit !== null) {
            $emit($frame(SubAgentActivity::OP_STARTED, [
                'task' => self::snippet($subAgent->task, self::TASK_SNIPPET_BYTES),
                'model' => $subAgent->runModel,
            ]));
        }

        // Terminal beat: settle the row, then say so once, with how the run
        // really ended. The report wins when there is one; a run that ends
        // without one keeps its activity trail as the row's output — the
        // story of what it got through — rather than going blank on the
        // dashboard at exactly the moment a human looks.
        $finish = function (string $status, string $report, ?string $error, string $outcome, ?string $resumeId) use (
            $subAgent, $emit, $foldThink, $frame, $flushLog, $log,
        ): void {
            $foldThink();
            // The log is complete BEFORE the finished frame leaves: the
            // parent reads it into the child session the moment it lands.
            $flushLog();
            $log?->status($status, $outcome, $error);
            // Report wins; without one the trail IS the row's story.
            $this->settle($subAgent, $status, $report !== '' ? $report : $subAgent->output, $error);
            if ($emit === null) {
                return;
            }
            $emit($frame(SubAgentActivity::OP_FINISHED, [
                'outcome' => $outcome,
                'error' => $error,
                'resumeId' => $resumeId,
            ]));
        };

        // @endregion setup

        // @region run
        // Set when the engine's mid-turn spend cap stops the run: the loop
        // then RETURNS normally with whatever its last step said, which must
        // not be handed back as the sub-agent's report.
        $capStop = null;

        try {
            $turn = $engine
                ->withTools($tools)
                ->withMaxSteps($maxTurns)
                ->withSiblingSpend($this->siblingSpend)
                ->withStepUsageObserver($observeUsage)
                ->withTurnInbox($turnInbox)
                ->completeTranscript(
                    $messages,
                    // SubAgentActivity is deliberately NOT in this signature:
                    // the grant filter strips every DelegatesToEngine tool from
                    // the sub-agent's list, delegation is one level deep, and a
                    // nested emitter therefore never exists on this channel.
                    // SpendCapBreached IS: the run's own cap check emits it, and
                    // a narrower type here made that emit a TypeError that
                    // surfaced as an unexplained "failed" (audit B4).
                    onEvent: static function (ToolStarted|ToolFinished|SpendCapBreached $event) use ($onProgress, $stopIfCancelled, $record, $foldThink, &$capStop, &$thinking, &$stats, $subAgent, $log, $flushLog): void {
                        // Before the call runs; a throw from a finished
                        // event would only become that call's result.
                        if ($event instanceof ToolStarted) {
                            $stopIfCancelled();
                        }
                        $onProgress();
                        // A tool boundary ends the thought before it: the
                        // next burst opens under a fresh label.
                        $closeThought = static function () use ($foldThink, &$thinking): void {
                            $foldThink();
                            $thinking = false;
                        };
                        if ($event instanceof SpendCapBreached) {
                            $capStop = $event;
                            $closeThought();
                            $record(sprintf('stopped: spend cap $%.4f reached ($%.4f spent)', $event->capUsd, $event->spentUsd), null);

                            return;
                        }
                        if ($event instanceof ToolStarted) {
                            $closeThought();
                            $flushLog();
                            $log?->toolCall($event->toolCallId, $event->toolName, $event->arguments);
                            self::trackCall($subAgent, $event);
                            $stats['tools']++;
                            $record('-> '.self::describeCall($event), ActivityItem::toolStarted(
                                $event->toolCallId,
                                $event->toolName,
                                ToolSummary::of($event->toolName, $event->arguments),
                            ));

                            return;
                        }
                        $closeThought();
                        $log?->toolResult($event->toolCallId, $event->toolName, !$event->result->isError(), $event->result->content());
                        self::trackCall($subAgent, $event);
                        $record('<- '.$event->toolName.($event->result->isError() ? ' (error)' : ''), ActivityItem::toolFinished(
                            $event->toolCallId,
                            $event->toolName,
                            !$event->result->isError(),
                            $event->result->durationMs() ?? 0,
                        ));
                    },
                    onReasoning: static function (string $delta) use ($onProgress, &$think, $foldThink, $flushIfDue, &$logThought): void {
                        $onProgress();
                        $logThought .= $delta;
                        // The heartbeat's "alive, nothing to show" frame is an
                        // empty delta ({@see EngineBackend::turnTools()}) — it
                        // carries no thought to fold, but it still ticks the
                        // frame buffer.
                        if ($delta !== '') {
                            $think .= $delta;
                            if (strlen($think) >= self::THINK_LINE_BYTES) {
                                $foldThink();
                            }
                        }
                        $flushIfDue();
                    },
                    onHeartbeat: $onHeartbeat,
                    // P-B2: the run's prose, as `text` items — the parent's
                    // live line shows the newest fragment when the run is
                    // writing rather than calling tools. Items only: the v1
                    // tail stays the run's trail of what it did.
                    onToken: static function (string $delta) use ($onProgress, $buffer, $flushIfDue, &$logProse): void {
                        $onProgress();
                        $logProse .= $delta;
                        if ($delta !== '') {
                            $buffer->add(ActivityItem::text($delta));
                        }
                        $flushIfDue();
                    },
                    // P-B2: per-step stats from the turn loop's own step
                    // beat. Each step's start carries the loop's real step
                    // ceiling, and it ticks the frame buffer before the
                    // step's request goes out, so items waiting from the
                    // last step leave now rather than after a long call.
                    // The step count and token totals stay with the usage
                    // observer, which bills each step as it lands.
                    onStep: static function (\SugarCraft\Crush\Events\StepStarted|\SugarCraft\Crush\Events\UsageUpdated $event) use (&$stats, $flushIfDue, $stopIfCancelled): void {
                        if ($event instanceof \SugarCraft\Crush\Events\StepStarted) {
                            $stopIfCancelled();
                            $stats['maxSteps'] = $event->maxSteps;
                        }
                        $flushIfDue();
                    },
                );
        } catch (TurnInterrupted $failure) {
            // A run that failed part-way still billed every step it completed
            // (audit B4). The transcript holds those steps' assistant turns,
            // each with its own usage; the opening $messages are skipped so a
            // resumed run's earlier, already-billed steps are not counted twice.
            $spent = self::spentSince($failure->transcript, count($messages));
            self::bill($subAgent, $spent, $baseTokens, $baseCost);
            $cancelled = $failure->getPrevious() instanceof \SugarCraft\Crush\Support\ToolCallCancelled;
            $why = $cancelled
                ? sprintf('sub-agent "%s" was cancelled by the user (Esc)', $agentName)
                : sprintf('sub-agent "%s" failed: %s', $agentName, $failure->getMessage());
            $resume = $this->suspend($agentName, $failure->transcript, $resumes, $suspension['id'] ?? null, $resumeId, $logPath);
            $finish(
                $cancelled ? SubAgent::STATUS_STOPPED : SubAgent::STATUS_FAILED,
                '',
                $why,
                $cancelled ? SubAgentActivity::OUTCOME_CANCELLED : SubAgentActivity::OUTCOME_FAILED,
                $resumeId,
            );

            // P-D1: what the user told the run before it stopped is part of
            // why it stopped where it did.
            $inboxTrailer = $turnInbox?->trailer() ?? '';

            return $this->refusal(
                $toolCallId,
                $why . '. ' . $resume . $failureTail($failure->transcript) . ($inboxTrailer === '' ? '' : "\n\n" . $inboxTrailer),
                self::elapsedMs($startedAt),
                $spent,
            );
        }
        // @endregion run

        // @region finish
        $reply = $turn->reply;
        $spent = $reply->usage;
        self::bill($subAgent, $spent, $baseTokens, $baseCost);
        // Saved before the finished frame goes out, so the frame can name
        // the id a later Task call resumes it by (every path below saves).
        $resume = $this->suspend($agentName, $turn->transcript, $resumes, $suspension['id'] ?? null, $resumeId, $logPath);
        // P-D1: what the user told the run while it worked, on every return
        // below as on the failure path — the harness's note, so it goes
        // outside the report's fence.
        $inboxTrailer = $turnInbox?->trailer() ?? '';
        $inboxNote = $inboxTrailer === '' ? '' : "\n\n" . $inboxTrailer;

        if ($capStop !== null) {
            $why = sprintf(
                'sub-agent "%s" was stopped by the session spend cap after %d provider call%s ($%.4f spent of a $%.4f cap);'
                . ' any work it did is in the tree but was not summarised',
                $agentName,
                $capStop->completedCalls,
                $capStop->completedCalls === 1 ? '' : 's',
                $capStop->spentUsd,
                $capStop->capUsd,
            );
            $finish(SubAgent::STATUS_FAILED, '', $why, SubAgentActivity::OUTCOME_FAILED, $resumeId);

            return $this->refusal(
                $toolCallId,
                $why . '. ' . $resume . $failureTail($turn->transcript) . $inboxNote,
                self::elapsedMs($startedAt),
                $spent,
            );
        }

        $content = trim($reply->content);
        $finish(
            SubAgent::STATUS_COMPLETE,
            $content,
            null,
            $content === '' ? SubAgentActivity::OUTCOME_EMPTY : SubAgentActivity::OUTCOME_COMPLETE,
            $resumeId,
        );

        if ($content === '') {
            return $this->refusal($toolCallId, sprintf(
                'sub-agent "%s" ended without a final report (step cap %d); any work it did is in the tree'
                . ' but was not summarised. %s',
                $agentName,
                $maxTurns,
                $resume,
            ) . $failureTail($turn->transcript) . $inboxNote, self::elapsedMs($startedAt), $spent);
        }

        // A run that hit its step cap now ENDS IN A SUMMARY rather than in
        // silence: the engine makes one no-tools request asking what is done,
        // what remains and what comes next (WAVE_PLAN_2 §5), so $content is no
        // longer empty there and the refusal above no longer catches it. The
        // summary is the report — it is exactly what the caller needs — but
        // the run is still unfinished, so it keeps its resume id rather than
        // forgetting it: dropping resumability is the regression the summary
        // would otherwise have introduced.
        //
        // A run that FINISHED keeps its id too (step 4.7-1). A report used to
        // forget the suspension, so a follow-up question to the agent that
        // just did the work — "and the other module?", "fix what you found" —
        // started a stranger from nothing and paid for every read again.
        // Every run is now saved and its report names the id; the store's
        // age and count caps ({@see SuspendedDelegations}) bound the cost.
        //
        // The report re-enters the parent's conversation as foreign bytes,
        // so it is fenced (step 0.15) - before the harness's own step-cap
        // note is appended, which stays outside the fence. The dashboard row
        // ($finish above) keeps the raw report: it is read by a human, not
        // replayed to a model.
        $content = \SugarCraft\Crush\Context\DelegatedOutputFence::wrap($content);
        if ($reply->stepsTruncated) {
            $content .= sprintf(
                "\n\n[sub-agent \"%s\" stopped at its step cap (%d); the above is its own summary of where it stopped. %s.]",
                $agentName,
                $maxTurns,
                $resume,
            );
        } else {
            $content .= sprintf(
                "\n\n[sub-agent \"%s\" finished; to follow up with it in the same conversation instead of starting over: %s.]",
                $agentName,
                $resume,
            );
        }
        $content .= $inboxNote;

        return new ToolResult(
            toolCallId: $toolCallId,
            content: $content,
            isError: false,
            durationMs: self::elapsedMs($startedAt),
            usage: $spent,
        );
        // @endregion finish
    }

    /**
     * The model a delegated run must be pinned to, or null to stay on the
     * session's current one — and, when the session's provider cannot serve
     * the model the call or the agent asked for, why not (roadmap 4.1-1).
     *
     * First of: the call's own `model` argument, the agent's model when it
     * names one ({@see \SugarCraft\Crush\Agents\Agent::$inheritsModel}),
     * the session's model.
     *
     * @return array{0: ?string, 1: ?string} [model to pin, refusal]
     */
    private static function chooseModel(EngineBackend $engine, \SugarCraft\Crush\Agents\Agent $agent, string $requested): array
    {
        $model = trim($requested);
        $origin = 'the Task call\'s `model`';
        if ($model === '' || $model === 'inherit') {
            if ($agent->inheritsModel || trim($agent->model) === '' || $agent->model === 'inherit') {
                return [null, null];
            }
            $model = trim($agent->model);
            $origin = sprintf('agent "%s"\'s model', $agent->name);
        }

        $session = $engine->model();
        if ($model === $session) {
            return [null, null];
        }

        if (\in_array(strtolower($model), self::CLAUDE_MODEL_ALIASES, true)) {
            if (str_contains(strtolower($session), strtolower($model))) {
                return [null, null];
            }

            return [null, sprintf(
                '%s is "%s", a Claude Code tier name that no %s model answers to (this session runs "%s");'
                . ' name a model id this provider serves, or `inherit`',
                $origin,
                $model,
                $engine->provider()->name(),
                $engine->servedModel() ?? $session,
            )];
        }

        $served = $engine->servedModel();
        if ($served !== null && $served !== $model) {
            return [null, sprintf(
                '%s is "%s", but this session\'s %s server serves only "%s", so the run cannot be moved to it;'
                . ' use that model or `inherit`',
                $origin,
                $model,
                $engine->provider()->name(),
                $served,
            )];
        }

        return [$model, null];
    }

    /**
     * What the run billed in the steps it completed after the first $from
     * messages of $transcript — the opening turns (and, on a resume, the
     * saved run's own steps, billed by the Task call that made them).
     *
     * @param list<\SugarCraft\Crush\Messages\Message> $transcript
     */
    private static function spentSince(array $transcript, int $from): ?Usage
    {
        $usages = [];
        foreach (array_slice($transcript, $from) as $message) {
            if ($message instanceof AssistantMessage) {
                $usages[] = $message->usage();
            }
        }

        return Usage::sum($usages);
    }

    /**
     * Settle the run's spend onto its row (the Agents dashboard reads it):
     * the baseline the row carried before the run plus what the run billed,
     * REPLACING the live count {@see runOnEngine()}'s usage observer kept, so
     * the settled figure is the authority and nothing is counted twice.
     */
    private static function bill(SubAgent $subAgent, ?Usage $spent, int $baseTokens = 0, float $baseCost = 0.0): void
    {
        $subAgent->tokensUsed = $baseTokens + ($spent?->totalTokens ?? 0);
        $subAgent->costUsd = $baseCost + ($spent?->costUsd ?? 0.0);
    }

    /**
     * Keep the run's recent-call list current: a started call is appended
     * as running, its finish marks the SAME entry (matched by call id) ok or
     * error, and only the newest {@see SubAgentActivity::MAX_CALLS} are kept.
     * The list is what the Tools pane shows for this run.
     */
    private static function trackCall(SubAgent $subAgent, ToolStarted|ToolFinished $event): void
    {
        $calls = $subAgent->recentCalls;
        if ($event instanceof ToolStarted) {
            $calls[] = [
                'id' => $event->toolCallId,
                'label' => self::describeCall($event),
                'state' => SubAgentActivity::CALL_RUNNING,
                'at' => time(),
            ];
            $subAgent->recentCalls = array_slice($calls, -SubAgentActivity::MAX_CALLS);

            return;
        }

        for ($i = count($calls) - 1; $i >= 0; $i--) {
            if ($calls[$i]['id'] === $event->toolCallId && $calls[$i]['state'] === SubAgentActivity::CALL_RUNNING) {
                $calls[$i]['state'] = $event->result->isError() ? SubAgentActivity::CALL_ERROR : SubAgentActivity::CALL_OK;
                $subAgent->recentCalls = $calls;

                return;
            }
        }
    }

    /**
     * One tool call as a trail line: the model's own description when it
     * gave one (`Bash: List files`), else the call's argument dump
     * (`Read(path: "src/x.php")`) — the same one-liner the chat shows, so a
     * sub-agent's trail reads like the transcript rather than a bare name.
     */
    private static function describeCall(ToolStarted $event): string
    {
        $described = \SugarCraft\Crush\Message::describeToolCall(
            new \SugarCraft\Crush\ToolCall($event->toolName, $event->arguments),
        );

        return str_starts_with($described, $event->toolName . '(')
            ? $described
            : $event->toolName . ': ' . $described;
    }

    /**
     * Save a run that ended without a report and say how to continue it — or,
     * if it cannot be saved, say plainly that it cannot be resumed.
     *
     * @param list<\SugarCraft\Crush\Messages\Message> $transcript
     * @param string|null $savedId set to the id it was saved under; null when it could not be saved
     * @param string|null $transcriptLog the run's log, which a resume keeps writing (P-C1)
     */
    private function suspend(string $agentName, array $transcript, int $resumes, ?string $id, ?string &$savedId = null, ?string $transcriptLog = null): string
    {
        $savedId = null;
        try {
            $id = $this->suspendedStore()->save($agentName, $transcript, $resumes, $id, $transcriptLog);
        } catch (\RuntimeException $unsaved) {
            return 'It CANNOT be resumed (the run could not be saved: ' . $unsaved->getMessage() . '); start a new Task instead';
        }
        $savedId = $id;

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
    private function refusal(string $toolCallId, string $why, ?int $durationMs = null, ?Usage $spent = null): ToolResult
    {
        return new ToolResult(
            toolCallId: $toolCallId,
            content: 'Error: Task refused — ' . $why,
            isError: true,
            durationMs: $durationMs,
            // A refusal after a run still carries what the run spent: failing
            // does not un-bill it (audit B4).
            usage: $spent,
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
