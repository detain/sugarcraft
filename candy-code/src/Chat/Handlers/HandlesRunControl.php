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
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Forms\ItemList\LoadMoreMsg;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Config\StatusLineCommand;
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
            return [$this, StatusLineCommand::cmd()];
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
                return $this->handleCacheTtlIdleCompactTick();
            }

            return $this->handleIdleCompactTick();
        }

        return null;
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
     * @return array{0:Chat,1:?\Closure}
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
}
