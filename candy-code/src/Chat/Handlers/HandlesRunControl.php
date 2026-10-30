<?php

declare(strict_types=1);

namespace SugarCraft\Code\Chat\Handlers;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseMode;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Msg\FocusGainedMsg;
use SugarCraft\Core\Msg\FocusLostMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Forms\ItemList\LoadMoreMsg;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Config\StatusLineCommand;
use SugarCraft\Code\Config\StatusLineSession;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Help;
use SugarCraft\Code\Skills\SkillMentions;
use SugarCraft\Code\Host\SessionTabState;
use SugarCraft\Code\Host\SessionSwitch;
use SugarCraft\Code\Tui\ComposerVim;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\Tui\SessionPicker;
use SugarCraft\Code\Tui\SessionTabs;
use SugarCraft\Code\Tui\TextSelection;
use SugarCraft\Code\Tui\Components\PaneLabel;
use SugarCraft\Code\Support\SystemClipboard;
use SugarCraft\Code\Support\StartupSummary;
use SugarCraft\Code\Attachments\FileMentions;
use SugarCraft\Code\Attachments\MentionIndex;
use SugarCraft\Code\Support\ClipboardImage;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\QueueMode;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Events\MediaProgress;
use SugarCraft\Code\Events\ReasoningDelta;
use SugarCraft\Code\Events\SpendCapBreached;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Media\PreviewSlots;
use SugarCraft\Code\Events\TokenDelta;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Events\ToolProgress;
use SugarCraft\Code\Events\ToolStarted;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Permissions\DenialKind;
use SugarCraft\Code\Permissions\PermissionFlow;
use SugarCraft\Code\Permissions\PermissionPrompt;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionPromptStage;
use SugarCraft\Code\Permissions\PermissionReply;
use SugarCraft\Code\Tools\ToolCall as EngineToolCall;
use SugarCraft\Code\Commands\CommandLoader;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\McpAuthCommand;
use SugarCraft\Code\Commands\McpPromptCommands;
use SugarCraft\Code\Commands\ShareCommand;
use SugarCraft\Code\Commands\WebSearchCommand;
use SugarCraft\Code\Tools\BuiltIn\WebSearch;
use SugarCraft\Code\Observability\EventSink;
use SugarCraft\Code\Observability\NullEventSink;
use SugarCraft\Code\Palette\PaletteAction;
use SugarCraft\Code\Palette\PaletteState;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Forms\Vim\VimState;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Mouse\MouseEvent;
use SugarCraft\Mouse\Sentinel;
use SugarCraft\Mouse\Zone;
use SugarCraft\Mouse\ZoneClickTracker;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowEngineInterface;
use SugarCraft\Code\Workflows\WorkflowResult;
use SugarCraft\Code\Context\ContextCompactor;
use SugarCraft\Code\Context\CompactorConfig;
use SugarCraft\Code\Context\ContextWindow;
use SugarCraft\Code\Context\IdleCompactionPolicy;
use SugarCraft\Code\Context\KeepWarmPolicy;
use SugarCraft\Code\Context\RuleLoader;
use SugarCraft\Code\Context\RulesState;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Session\DebouncedTranscriptWriter;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\PromptHistory;
use SugarCraft\Code\Session\SessionLock;
use SugarCraft\Code\Session\SessionStore;
use SugarCraft\Code\Util\TokenTracker;
use SugarCraft\Code\Util\TokenEstimate;
use SugarCraft\Code\App\BackgroundSessionSpawnedMsg;
use SugarCraft\Code\App\BackgroundSessionStoppedMsg;
use SugarCraft\Code\App\CancelledWorkflowReportMsg;
use SugarCraft\Code\App\CustomCommandExpandedMsg;
use SugarCraft\Code\App\PermissionModeToggledMsg;
use SugarCraft\Code\App\ClipboardImagePastedMsg;
use SugarCraft\Code\App\SideQuestionAnsweredMsg;
use SugarCraft\Code\App\DreamPassCompletedMsg;
use SugarCraft\Code\App\MemoryConsolidatedMsg;
use SugarCraft\Code\App\BackendToolEventsMsg;
use SugarCraft\Code\App\PermissionRequestMsg;
use SugarCraft\Code\App\RuntimeNoticePumpMsg;
use SugarCraft\Code\App\TurnHooksResolvedMsg;
use SugarCraft\Code\App\AgentMessageSentMsg;
use SugarCraft\Code\App\HistoryCompactedMsg;
use SugarCraft\Code\App\McpLoginProgressMsg;
use SugarCraft\Code\App\PromptSuggestionMsg;
use SugarCraft\Code\App\SessionLockRetryMsg;
use SugarCraft\Code\App\StatusLineResultMsg;
use SugarCraft\Code\App\BangShellResultMsg;
use SugarCraft\Code\App\IdleCompactTickMsg;
use SugarCraft\Code\App\KeepWarmPingedMsg;
use SugarCraft\Code\Backend\CacheHealthWatch;
use SugarCraft\Code\Backend\CacheReusingSummaryBackend;
use SugarCraft\Code\Backend\SummarisesWithCache;
use SugarCraft\Code\Message;
use SugarCraft\Code\App\PermissionReplyMsg;
use SugarCraft\Code\App\TranscriptFlushMsg;
use SugarCraft\Code\App\BackgroundTickMsg;
use SugarCraft\Code\App\CancelAgentRunMsg;
use SugarCraft\Code\App\CloseAgentViewMsg;
use SugarCraft\Code\App\StatusLineTickMsg;
use SugarCraft\Code\App\StartupSummaryGatheredMsg;
use SugarCraft\Code\App\InitialPromptMsg;
use SugarCraft\Code\App\OpenAgentViewMsg;
use SugarCraft\Code\App\SessionTitledMsg;
use SugarCraft\Code\App\ToolEventPumpMsg;
use SugarCraft\Code\App\AgentControlMsg;
use SugarCraft\Code\App\ToolResultsMsg;
use SugarCraft\Code\App\GoalJudgedMsg;
use SugarCraft\Code\App\AssistantMsg;

// Route() family: run-control lifecycle arms (agents, permissions, tools,
// hooks, status line, lock retry, MCP login, idle compact). W7-S4.
trait HandlesRunControl
{
    /**
     * Route family handler: owns the class-disjoint Msg arms listed below;
     * returns null when none match so route()'s chain can try the next
     * family (W7-S4 decomposition, behaviour-identical to the old inline
     * dispatch — arms kept verbatim, including their comments).
     */
    private function handleRunControlMessages(Msg $msg): ?array
    {

        // Roadmap P-C2: the shell left the Agent View. The key that left it
        // (Esc) never reached this chat, but an Esc pressed BEFORE the view
        // opened may still be armed — so the first Esc after the view closes
        // is a first press again, never the second half of a turn cancel.
        if ($msg instanceof CloseAgentViewMsg) {
            return [$this->lastEscapeAt === null ? $this : $this->mutate(['lastEscapeAt' => null]), null];
        }

        // C-TUI-3.g (GAP-UI-N4): the terminal's own "the user left / came
        // back", enabled by `reportFocus` in `Chat::programOptions()`. The
        // loss only stamps time; the regain decides whether the absence was
        // long enough to deserve the recap Claude Code prints on return.
        if ($msg instanceof FocusLostMsg) {
            \SugarCraft\Code\Tui\AwayTracker::focusLost();

            return [$this, null];
        }

        if ($msg instanceof FocusGainedMsg) {
            return $this->awayRecapOnFocusBack();
        }

        // crush-media W3.1: the media form's collected Generate action, handed
        // back by the shell. It runs through the SAME /generate tool pipeline
        // the typed command uses — one dispatch path, and the form itself
        // dials nothing (its Submit echo row is the bare command word).
        if ($msg instanceof \SugarCraft\Code\Tui\MediaForm\GenerateFromMediaFormMsg) {
            return $this->applyCommandResult(
                (new \SugarCraft\Code\Host\Commands\GenerateHostCommand())->runRequest(
                    $this->commandContext(),
                    '/generate',
                    $msg->request->toArray(),
                ),
            );
        }

        // Roadmap 5.7-1: `Alt+M`, or any other door, switching the mode.
        if ($msg instanceof PermissionModeToggledMsg) {
            return $this->togglePermissionMode($msg);
        }

        // Roadmap P-B3: `c` (or `x` on a running run) on the live agents
        // strip. Only a call the turn on screen is still running is named,
        // and only that call stops (1.C-4b) — the turn carries on.
        if ($msg instanceof CancelAgentRunMsg) {
            if ($this->inFlight && $msg->parentCallId !== '') {
                foreach ($this->history as $message) {
                    if ($message->pendingToolCallId === $msg->parentCallId) {
                        $this->inFlightCancellation?->cancelTool($msg->parentCallId);

                        break;
                    }
                }
            }

            return [$this, null];
        }

        // Roadmaps P-D2/P-D3: the shell's word for something the user asked
        // of one or more delegated runs — the Agent View composer's Enter,
        // its Ctrl+X chords, the dashboard's c/r/s, Ctrl+G's broadcast. The
        // runs' mailboxes and the live registry the ids name are this
        // chat's, so the shell only translates and this arm acts. P-AGENT.b's
        // INTERRUPT rides the same submit path: it is a delivery of the
        // draft, only with the mailbox mode set to skip the step's unstarted
        // calls — control verbs are the rest.
        if ($msg instanceof AgentControlMsg) {
            return \in_array($msg->verb, [AgentControlMsg::MESSAGE, AgentControlMsg::INTERRUPT], true)
                ? $this->submitToAgents($msg)
                : $this->controlAgents($msg);
        }

        if ($msg instanceof ToolResultsMsg) {
            return $this->finishToolCalls($msg);
        }
        if ($msg instanceof PermissionRequestMsg) {
            return $this->requestPermission($msg);
        }
        if ($msg instanceof PermissionReplyMsg) {
            return $this->answerPermission($msg->reply);
        }
        if ($msg instanceof TickRequest) {
            // A paced replay hop ({@see foldHop()}) that reaches a driver
            // with no loop to arm its timer — a synchronous chain unwinder,
            // any model-only embedder — is answered HERE instead of stalling
            // the queue: produce the hop now, skipping only the delay, never
            // the event. The real host intercepts TickRequest in
            // Program::dispatch() before update() ever sees one, so this arm
            // exists for the headless shape alone.
            $produced = ($msg->produce)();

            return $produced === null ? [$this, null] : [$this, Cmd::send($produced)];
        }
        if ($msg instanceof BackendToolEventsMsg) {
            return $this->applyBackendToolEvent($msg);
        }
        if ($msg instanceof ToolEventPumpMsg) {
            // Roadmap P-B2: the live agent lines' clock moves on the pump's
            // own tick (every 0.1 s while a turn runs), so the spinner turns
            // and the elapsed figures count without view() reading a clock.
            $this->agentLive()->advance();

            return $this->pumpLiveToolEvents();
        }
        if ($msg instanceof RuntimeNoticePumpMsg) {
            return $this->pumpRuntimeNotices();
        }
        if ($msg instanceof TranscriptFlushMsg) {
            // The debounce tick (audit R2): write whatever is newest. $this is
            // returned unchanged, so update() schedules nothing new.
            $this->transcripts()->flush();

            return [$this, null];
        }
        if ($msg instanceof SessionLockRetryMsg) {
            return [$this->retakenSessionLock(), null];
        }
        if ($msg instanceof BangShellResultMsg) {
            return $this->landBangShellResult($msg);
        }

        if ($msg instanceof InitialPromptMsg) {
            return $this->submitInitialPrompt($msg->prompt);
        }
        if ($msg instanceof StatusLineTickMsg) {
            // The `statusLine` command's launch site. Only the decision runs
            // here on the update path — a TTL comparison and the in-flight
            // check — while the command itself runs in a forked child, so a
            // slow one no longer freezes the frame once per tick (ISS-095).
            // Its text lands through the StatusLineResultMsg fold below.
            //
            // Returns $this unchanged: the runner holds the text in process
            // state ({@see StatusLineCommand::line()}), not on the model, so
            // there is nothing to fold into a new Chat. The repaint comes
            // from Program re-rendering after the update, which it does for
            // every Msg — this arm does not have to ask for one.
            //
            // The snapshot handed to cmd() is what the command finds on its
            // stdin (GAP-UI-23), gathered by the helper below on the update
            // path. The gather costs one small HEAD-file read and some
            // arithmetic, on a tick that only exists when a status line is
            // configured.
            return [$this, StatusLineCommand::cmd($this->statusLineStdin())];
        }
        if ($msg instanceof StatusLineResultMsg) {
            StatusLineCommand::settle($msg->line);

            return [$this, null];
        }
        if ($msg instanceof StartupSummaryGatheredMsg) {
            // VIS-1-P2 landing: the off-view gather's bag parks for the next
            // paint (store() also drops the memoized frames of this
            // session+root). Returns
            // $this unchanged — the facts live in the summary's own cache,
            // not on the model — and Program repaints after every Msg anyway,
            // the StatusLineResultMsg precedent one line above.
            StartupSummary::store($this, $msg->facts);

            return [$this, null];
        }
        if ($msg instanceof TurnHooksResolvedMsg) {
            return $this->resumeTurnHooks($msg);
        }
        if ($msg instanceof CustomCommandExpandedMsg) {
            return $this->resumeCustomCommand($msg);
        }
        if ($msg instanceof McpLoginProgressMsg) {
            return $this->handleMcpLoginProgress($msg);
        }
        if ($msg instanceof IdleCompactTickMsg) {
            // C-CTX-5.c: `cacheTtl` re-times the fold to the provider's
            // prompt-cache expiry; the hour mode's path below is byte-unchanged.
            if ($this->compactor->config()->idleAutoMode === CompactorConfig::IDLE_AUTO_CACHE_TTL) {
                // C-CTX-5.d: keep-warm speaks only where the fold declined.
                // "Declined" is structural here — the same model instance and
                // no command out — so a tick the fold took (fold mutation,
                // parked fold, heuristic fallback) can never also buy a ping.
                [$folded, $foldCmd] = $this->handleCacheTtlIdleCompactTick();
                if ($folded !== $this || $foldCmd !== null) {
                    return [$folded, $foldCmd];
                }

                return $this->handleKeepWarmTick();
            }

            return $this->handleIdleCompactTick();
        }
        if ($msg instanceof KeepWarmPingedMsg) {
            return $this->handleKeepWarmPingedMsg($msg);
        }

        return null;
    }

    /**
     * The JSON document the configured `statusLine` command finds on its
     * stdin (GAP-UI-23), built from the accessors the status bar itself
     * paints — see {@see StatusLineSession} for the shape and its purity
     * argument. The command's working directory is the one the runner
     * already launches it in ({@see StatusLineCommand::workingDirectory()});
     * getcwd() only answers for the shape PHP would run it under otherwise.
     *
     * WHY THE VERSION IS MEMOIZED: {@see Help::versionString()} shells out
     * to git on a dev checkout (ISS-082) and carries no memo of its own, so
     * calling it per tick would put a child spawn on the paint path every
     * two seconds. Measured, it also crossed the refresh TTL inside the
     * StatusLineSegmentTest pin that proves ticks do NOT re-arm a Cmd
     * mid-burst — the gather's own cost defeated the cadence it serves.
     * A build that self-updates restarts the process, so a per-process
     * memo is the same answer the next launch would compute; a function
     * -local static rather than a property, because the value is not model
     * state and must not ride Chat clones or session persistence.
     */
    private function statusLineStdin(): string
    {
        static $version = null;

        $version ??= Help::versionString();

        $backend = $this->backend();
        $engine = $backend instanceof EngineBackend ? $backend : null;
        $turnUsage = $this->liveUsage()?->turnUsage;
        // The bar's own honesty gate, carried into the payload
        // ({@see \SugarCraft\Code\Renderer::spendIndicator()}): a session
        // nothing has ever billed has NO spend figure, not a zero one. The
        // arithmetic below is the bar's, and the gate is part of it — copying
        // the sum while dropping the test is what turned "cannot measure"
        // into a fabricated `$0` for a script to bank on.
        $reported = $this->hasReportedSpend() || $turnUsage !== null;

        return (new StatusLineSession(
            sessionId: $this->currentSessionId(),
            cwd: StatusLineCommand::workingDirectory() ?: (getcwd() !== false ? getcwd() : null),
            modelId: $engine === null ? null : ($engine->servedModel() ?? $engine->model()),
            projectDir: $this->projectRoot() ?: null,
            version: $version,
            totalCostUsd: $reported ? $this->spentUsd() + ($turnUsage === null ? 0.0 : $turnUsage->costUsd) : null,
            provider: $engine?->provider()->name(),
            permissionMode: $this->currentPermissionMode()?->value,
            contextTokens: $this->contextTokens(),
            contextPercent: (int) round($this->contextUsagePercent() * 100),
            branch: SessionStore::gitBranchAt($this->projectRoot()),
        ))->toJson();
    }

    /**
     * The 60-second tick's verdict in `cacheTtl` mode (C-CTX-5.c) — the same
     * watch gate, the same transient-silence list and the same tail as
     * {@see handleIdleCompactTick()}, with the hour clock's over-tier AND-clock
     * replaced by the cache fold's single call into
     * {@see IdleCompactionPolicy::shouldAutoCompactAtCacheTtl()}: the floor
     * BELOW the automatic tier is part of that predicate because this clock
     * exists precisely for the middle band the tier never acts on.
     *
     * WHY THIS DUPLICATES THE SILENCE LIST INSTEAD OF CALLING THE HOUR TICK:
     * the two verdicts differ mid-method (the predicate AND, in cacheTtl
     * mode, the idle window itself — `cacheTtl` folds while
     * `compaction.idleOfferSeconds` still only arms the watch, per the step's
     * documented risk that a 5-minute cache folds at about 4 idle under the
     * 60-second tick). Folding the branch into one method would re-measure
     * the 85% tier on the cache path, where the tier's own fold would then
     * race it. The duplication is pinned structurally —
     * {@see \SugarCraft\Code\Tests\Context\CacheTtlIdleFoldTest}'s silence-list
     * equality — so an edit to one copy cannot silently let the other answer
     * "quiet" to a session with a dialog open.
     *
     * @return array{0:\SugarCraft\Code\Chat,1:?\Closure}
     */
    private function handleCacheTtlIdleCompactTick(): array
    {
        if (!$this->shouldWatchIdleCompaction()) {
            return [$this, null];
        }

        if ($this->inFlight
            || $this->inputBuf !== ''
            || $this->queuedPrompts !== []
            || $this->keyHelp !== null
            || $this->flow->pendingPermission !== null
            || $this->palette !== null
            || $this->sessionPicker !== null
            || $this->dirPicker !== null
            || $this->historySearch !== null
        ) {
            return [$this, null];
        }

        $wireHistory = self::compactionWire($this->history);
        $tokenLimit = $this->contextTokenLimit();
        if (!IdleCompactionPolicy::shouldAutoCompactAtCacheTtl(
            $this->backend,
            \SugarCraft\Code\Host\CompactionService::tierTokenCount($this->estimateTokenCount($this->history), $this->history),
            $tokenLimit,
            $this->lastActivityAt,
        )) {
            return [$this, null];
        }

        if ($this->spendCapReached()) {
            return $this->idleHeuristicFold($wireHistory, $tokenLimit);
        }

        $request = $this->buildSummarizationRequest(
            $this->history,
            null,
            null,
            '',
            false,
            null,
            true, // unattended: the automatic backend and the `auto` PreCompact trigger
        );
        if ($request === null) {
            return $this->idleHeuristicFold($wireHistory, $tokenLimit);
        }

        return [$this->mutate(['pendingIdleCompaction' => [
            'id' => $request['id'],
            'fingerprint' => $request['fingerprint'],
            'sessionId' => $this->currentSessionId,
        ]]), $request['cmd']];
    }

    /**
     * The keep-warm verdict for one tick (C-CTX-5.d) — reached only from the
     * `cacheTtl` arm after {@see handleCacheTtlIdleCompactTick()} declined
     * the tick, which is exactly the "the fold did not take it" the policy
     * is named for. The transient-silence list below is the fold handler's
     * own, duplicated on purpose: a session with someone typing, a prompt
     * queued, or any overlay up is not unattended, and a ping billed to an
     * attended session is the harm this whole opt-in exists to bound. The
     * fold handlers stay byte-untouched so the structural pin between them
     * still compares what it always compared. The spend cap binds this tick
     * like every other unattended writer — see the gate below — and the idle
     * anchor is computed ONCE here and handed to both the verdict and the
     * latch, so the count the policy checked and the count the ping books are
     * the same count.
     *
     * @return array{Model, ?Cmd}
     */
    private function handleKeepWarmTick(): array
    {
        if (!$this->shouldWatchIdleCompaction()) {
            return [$this, null];
        }

        // A warm ping is a billed call, and a session that has spent its cap
        // is not to buy one — not even a max-cheap read of its own cache. The
        // served twin gets this gate for free (onIdleAutoCompaction refuses
        // past the cap before keep-warm is consulted); the TUI tick reaches
        // here directly from the fold's decline, so the cap binds HERE.
        if ($this->spendCapReached()) {
            return [$this, null];
        }

        if ($this->inFlight
            || $this->inputBuf !== ''
            || $this->queuedPrompts !== []
            || $this->keyHelp !== null
            || $this->flow->pendingPermission !== null
            || $this->palette !== null
            || $this->sessionPicker !== null
            || $this->dirPicker !== null
            || $this->historySearch !== null
        ) {
            return [$this, null];
        }

        // A warm ping is booked against the session it warms. A chat that has
        // not been saved under an id yet has nothing to book against, so an
        // id-less tick simply declines — the fold handlers do not need the id
        // until their own landings, but the keep-warm counters do, from the
        // first gate.
        $sessionKey = $this->currentSessionId;
        if ($sessionKey === null) {
            return [$this, null];
        }

        // One anchor per tick, computed here: the verdict below and the latch
        // in sendKeepWarmPing() must count against the SAME idle stretch, and
        // a second read of the stamp could only ever disagree with the first.
        $idleAnchorTs = $this->lastActivityAt?->getTimestamp();
        if ($idleAnchorTs === null) {
            return [$this, null];
        }

        $backend = $this->backendForKeepWarm();
        if (!KeepWarmPolicy::shouldPing(
            $this->workspace?->userConfig ?? [],
            $sessionKey,
            $backend,
            \SugarCraft\Code\Host\CompactionService::tierTokenCount($this->estimateTokenCount($this->history), $this->history),
            $this->contextTokenLimit(),
            foldDue: false, // the fold arm above just answered this tick; a second fold cannot be due
            turnInFlight: false, // $this->inFlight above IS this gate
            idleAnchorTs: $idleAnchorTs,
        )) {
            return [$this, null];
        }

        $cmd = $this->sendKeepWarmPing($backend, $sessionKey, $idleAnchorTs);

        return [$this, $cmd];
    }

    /**
     * The engine a warm ping reuses: the session's own main engine, reached
     * through the summary backend exactly as the cache-reusing fold reaches
     * it ({@see CacheReusingSummaryBackend::engine()} — the conversation as
     * a turn would send it, plus one instruction row, tools advertised and
     * never dispatched). Null when neither half speaks that interface: a
     * ping is worth sending only on the frozen-prefix route.
     */
    private function backendForKeepWarm(): ?\SugarCraft\Code\Backend
    {
        $summary = $this->summaryBackend;
        if ($summary instanceof CacheReusingSummaryBackend) {
            return $summary->engine();
        }
        if ($summary instanceof SummarisesWithCache) {
            return $summary;
        }
        if ($this->backend instanceof SummarisesWithCache) {
            return $this->backend;
        }

        return null;
    }

    /**
     * C-TUI-3.g (GAP-UI-N4): a regain after a long absence recaps the
     * session the way Claude Code does on return — the SAME host call the
     * typed `/recap` runs, minus its echo row (a recap nobody typed should
     * not also print the command word at them). The stamp is consumed
     * FIRST, so one absence can never offer the recap twice however the
     * regain messages arrive. Every gate below stands down by silence: a
     * mid-turn or drafted-at window would land the row inside the user's
     * own work, and at the spend cap the automatic path asks nothing on the
     * user's key — the refusal voice belongs to the typed door alone. The
     * landing is the ordinary `SideQuestionAnsweredMsg` fold the recap
     * command already shares (billed `aux`, one row per absence).
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function awayRecapOnFocusBack(): array
    {
        if (\SugarCraft\Code\Tui\AwayTracker::focusGained() === null) {
            return [$this, null];
        }

        if (
            $this->inFlight
            || $this->inputBuf !== ''
            || !\SugarCraft\Code\Tui\AwayTracker::recapEnabled()
        ) {
            return [$this, null];
        }

        $titleBackend = $this->titleBackend;
        if ($titleBackend === null || $this->spendCapReached()) {
            return [$this, null];
        }

        $asked = 0;
        foreach (Message::agentVisible($this->history) as $message) {
            if ($message->role === \SugarCraft\Code\Role::User) {
                $asked++;
            }
        }

        if ($asked < \SugarCraft\Code\Tui\AwayTracker::MIN_USER_TURNS) {
            return [$this, null];
        }

        return [$this, Cmd::promise(\SugarCraft\Code\Host\Commands\BtwHostCommand::call(
            $titleBackend,
            \SugarCraft\Code\Host\Commands\RecapHostCommand::PROMPT,
            $this->history,
            $this->currentSessionId,
            framing: \SugarCraft\Code\Host\Commands\RecapHostCommand::FRAMING,
            failureKey: 'host.recap.failed',
        ))];
    }

    /**
     * Issue one warm ping: latch it on the watch (a second call cannot
     * overlap), and hand the engine the frozen-prefix ask — the landing is
     * routed to {@see handleKeepWarmPingedMsg()}. No timeout arms here
     * (E646): the call rides the engine's existing per-progress ceiling like
     * the fold's own request does, and a stalled ping simply holds its latch
     * until the landing or rejection settles it — which is also what stops
     * any retry storm without adding a timer. The rejection half of the map
     * turns a failed ping into an unbilled landing rather than letting
     * {@see Cmd::promise()} surface it as an ExceptionMsg: a keep-warm call
     * nobody waited for has no business raising an error on the screen.
     */
    private function sendKeepWarmPing(?\SugarCraft\Code\Backend $backend, string $key, int $anchorTs): ?\Closure
    {
        if (!$backend instanceof SummarisesWithCache) {
            return null;
        }

        if (!CacheHealthWatch::noteWarmPing($key, $anchorTs)) {
            return null;
        }

        $history = $this->history;

        return Cmd::promise(static fn(): PromiseInterface => $backend
            ->summariseAsync($history, KeepWarmPolicy::PING_INSTRUCTION)
            ->then(
                static fn(Message $reply): Msg => new KeepWarmPingedMsg($key, $reply->usage),
                static fn(\Throwable $failure): Msg => new KeepWarmPingedMsg($key, null),
            ));
    }

    /**
     * Book one landed warm ping: release the latch, and bill a call that
     * answered like any other under its own `warm` source label — which is
     * also where the person sees the count (/usage groups it with `turn`,
     * `aux`, `compact`, `title`). A ping that failed is released unbilled:
     * nothing answered, nothing warmed, nothing to record.
     */
    private function handleKeepWarmPingedMsg(KeepWarmPingedMsg $msg): array
    {
        if ($msg->sessionId !== $this->currentSessionId) {
            // The session was switched (or deleted) out from under the ask.
            // The latch belongs to the key that sent it, so release THAT
            // session's, not the one now loaded.
            CacheHealthWatch::settleWarmPing($msg->sessionId, false);

            return [$this, null];
        }

        CacheHealthWatch::settleWarmPing($msg->sessionId, $msg->usage !== null);
        if ($msg->usage !== null) {
            $this->accountUsage($msg->usage, null, 'warm');
        }

        return [$this, null];
    }
}
