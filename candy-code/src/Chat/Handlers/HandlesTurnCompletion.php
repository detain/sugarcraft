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
use SugarCraft\Code\App\InitialPromptMsg;
use SugarCraft\Code\App\OpenAgentViewMsg;
use SugarCraft\Code\App\SessionTitledMsg;
use SugarCraft\Code\App\ToolEventPumpMsg;
use SugarCraft\Code\App\AgentControlMsg;
use SugarCraft\Code\App\ToolResultsMsg;
use SugarCraft\Code\App\GoalJudgedMsg;
use SugarCraft\Code\App\AssistantMsg;
use SugarCraft\Code\Chat\Handlers\HandlesRunControl;
use SugarCraft\Code\Message;
use SugarCraft\Code\Lang;

// Route() family: turn-completion arms (the Assistant settle, goal verdict,
// side question, handoff, dream pass, auto commit, memory consolidation,
// cancelled workflow report, compaction, prompt suggestion, session title). W7-S4.
trait HandlesTurnCompletion
{
    /**
     * Route family handler: owns the class-disjoint Msg arms listed below;
     * returns null when none match so route()'s chain can try the next
     * family (W7-S4 decomposition, behaviour-identical to the old inline
     * dispatch — arms kept verbatim, including their comments).
     */
    private function handleTurnCompletionMessages(Msg $msg): ?array
    {

        if ($msg instanceof AssistantMsg) {
            // Account the turn FIRST - before the staleness guard, before the
            // tool-call routing, before anything that can return early. Three
            // reasons, and each of them is a way the most expensive turns would
            // have gone unbilled:
            //
            //  - The tool-call branch below returns through beginToolCalls(),
            //    and a turn that called tools is a turn of several provider
            //    calls, i.e. the expensive kind.
            //  - A SUPERSEDED reply (the guard immediately below) was completed
            //    and charged for whether or not the user still wanted it.
            //    Dropping it from the transcript is right; forgetting the money
            //    is not.
            //  - This arm is reached exactly ONCE per settled turn WHOSE
            //    GENERATION STILL MATCHES, including on the tool-event path,
            //    which re-sends the same reply here after draining its queue
            //    (see applyBackendToolEvent()) - so accounting here cannot
            //    double-count. The domain matters: a tool turn superseded
            //    MID-QUEUE never reaches this arm at all, because
            //    applyBackendToolEvent()'s own staleness guard breaks the chain
            //    before the queue drains and no AssistantMsg is ever
            //    synthesised. Measured, that lost the whole turn's usage - "the
            //    expensive kind" above, unbilled - so that guard accounts too,
            //    and the two are mutually exclusive by construction: once it
            //    fires it returns a null Cmd, so the chain stops there.
            //
            // A null $usage is the provider having reported nothing, which is the
            // ordinary streamed-turn answer and must not become a zero-dollar
            // call - see {@see Usage}. addTotalUsage(), not addUsage(): the figure
            // crossing this seam is a TOTAL with no input/output split, and
            // TokenTracker keeps those in their own bucket rather than pretending
            // the whole turn was input.
            $this->accountUsage($msg->message->usage);

            // A reply for a turn that was aborted (double-Escape) or
            // otherwise superseded arrives after inFlight/generation have
            // already moved on - drop it rather than appending it after
            // whatever the user has done since. See AssistantMsg's
            // docblock and the Escape arm below.
            if ($msg->generation !== null && $msg->generation !== $this->generation) {
                return [$this, null];
            }

            // Roadmap 1.B-2: an engine turn comes back with the rows it added,
            // step by step. Folded in here - the tool rows the live events drew
            // stamped with their step, the model's own record of each step
            // hidden beside them - so the next request replays the turn as
            // calls and results instead of tool output read back as prose.
            // Every other reply carries none and passes through untouched.
            [$turnHistory, $message] = Message::settleTurnTranscript($this->history, $msg->message);

            // The settled Message supersedes whatever was streamed: it is what
            // the provider actually committed to, and on the failure path
            // ({@see scheduleBackendCompletion()}'s rejection handler) it is
            // the error notice, which must not be preceded by a half-sentence
            // the user would read as a complete answer. Clearing here rather
            // than in each branch keeps the two exits (plain reply, tool
            // calls) from drifting apart.
            // The thinking goes with it: the settled Message carries its own
            // `reasoning`, which {@see Renderer::renderAssistantTurn()} paints
            // from the transcript, so leaving the live accumulation up would
            // show the same thought twice.
            $settled = $this->mutate(array_merge(
                // A user who opened the collapsed live thought keeps it open
                // on the settled turn that now carries it.
                ['streamingText' => '', 'reasoningText' => '', 'expanded' => $this->expandedAfterLiveThought($message->reasoning)],
                // Whatever settled, it ends a workflow turn if one was running
                // (driveWorkflowFiber() delivers the run's report here).
                ['workflowTurnInFlight' => false],
                // E17: fold this turn's estimate-vs-real observation into the
                // calibration HERE — this mutate is the one point every exit
                // of the arm passes through, the tool-call branch above it
                // and the plain reply below, so the pairing is neither missed
                // on multi-step turns nor done twice. Reading
                // $msg->message->usage, not the tracker: the tracker holds the
                // session SUM, and calibration needs THIS turn against the
                // estimate THIS dispatch recorded.
                $this->turnEstimateObservation($msg->message->usage),
            ));

            // Check if the message has tool calls to execute
            if ($message->toolCalls !== [] && $this->tools !== []) {
                return $settled->beginToolCalls($message);
            }

            // THE ordinary end of a turn, and so the drain point that matters:
            // {@see finishToolCalls()} writes `'inFlight' => true`, which means a
            // turn that called tools keeps running and settles at a LATER
            // AssistantMsg — this one, once the model answers without asking for
            // another call. Draining on ANY AssistantMsg would fire mid-turn,
            // between two tool steps; the tool-call branch a few lines above
            // returns before this point, which is what keeps it from happening.
            //
            // The null Cmd this used to return is where the drained turn's Cmd
            // goes.
            //
            // E707 (round 81): a reply the provider stopped at its OUTPUT
            // ceiling is otherwise indistinguishable from one the model chose
            // to end - the text simply stops. One Role::System notice rides
            // immediately after the turn it describes (the same shape every
            // other post-prompt notice on this route takes), appended INSIDE
            // the settle so it lands in persisted history exactly once per
            // stopped turn and survives re-render.
            [$done, $doneCmd] = self::releaseQueuedPrompts([$settled->mutate([
                'history' => [
                    ...$turnHistory,
                    $message,
                    ...($message->lengthStopped ? [$this->outputLengthStoppedNotice()] : []),
                    // F2, same append shape as the two lines around it: a turn
                    // the STEP ceiling ended mid-exchange gets one transcript
                    // notice pointing at `maxToolSteps`, so an unfinished
                    // agentic loop never reads as a completed answer.
                    ...($message->stepsTruncated ? [$this->stepsTruncatedNotice()] : []),
                    // The third harness stop, same append shape: a turn the
                    // repeat-call loop guard ENDED says so here rather than
                    // leaving it to the model's no-tools summary to mention.
                    ...($message->loopGuardStoppedBy !== null
                        ? [$this->loopGuardStoppedNotice($message->loopGuardStoppedBy)]
                        : []),
                    // Audit 15b-15, same append shape: an attachment the
                    // provider could not carry (an image to a model without
                    // vision) went as a text placeholder, and the user hears
                    // it here rather than wondering why the model is blind.
                    ...($message->attachmentNotice !== null
                        ? [Message::notice($message->attachmentNotice)]
                        : []),
                    // Billing fix, same append shape as the E707 line above:
                    // a turn the app could NOT price gets exactly one
                    // transcript-visible notice naming the model, so a $0.00
                    // in the budget readout is never silently read as "free".
                    ...($message->usage?->unpricedModel !== null
                        ? [$this->unpricedModelNotice($message->usage->unpricedModel)]
                        : []),
                ],
                'inFlight' => false,
                'inFlightCancellation' => null,
            ]), null]);

            // Step 3.D-3: a session with a live `/goal` is not done yet — the
            // title model judges this turn (below, once the per-turn side
            // calls are scheduled) and the agent goes back to work until the
            // goal is met. Only when nothing queued took the turn's place: the
            // user's own message carries the conversation on, and the turn it
            // starts is judged when it settles.
            $goal = $done->inFlight ? null : \SugarCraft\Code\Goal\GoalState::activeIn($done->history);

            // The turn is over and nothing queued took its place: guess the
            // user's next message in the background (→ accepts it). Batched
            // beside, never before, whatever the drain scheduled. Not while a
            // goal keeps the conversation going: the guess would be stale
            // before the box is free to show it.
            $suggest = $done->inFlight || $goal !== null ? null : $done->schedulePromptSuggestion();

            // Roadmap 5.2, same seam and same rule: auto-memory consolidation
            // on the tool-less summary backend, throttled on disk to one run
            // per project per five minutes. Only the gating runs here; the
            // request runs in the backend's fork and the notes are written in
            // this process as its promise settles (MemoryConsolidatedMsg).
            $consolidate = $done->inFlight || $done->memoryStore === null ? null
                : \SugarCraft\Code\Memory\AutoMemoryConsolidator::new(
                    \SugarCraft\Code\Memory\MemoryWriter::new($done->memoryStore, $done->projectRoot()),
                )->call($done->summaryBackend, $done->history, $done->spendCapReached(), $done->currentSessionId);
            // Step 3.G, same seam: `autoCommit: turn` commits what this turn
            // changed, with a subject from the title model. Only when nothing
            // queued took the turn's place — a queued prompt is the same
            // conversation carrying on, and commits once it settles.
            $autoCommit = $done->inFlight ? null : $done->scheduleAutoCommit();
            // Roadmap 5.4-3, same seam: the dream pass folds the compaction
            // journal into memory in a restricted-tool turn of the session's
            // engine (read-only Memory + Read, via EngineBackend::withTools),
            // throttled on disk to one pass per project per two hours. Only
            // the gating runs here (a stat and a small state read); the turn
            // runs in the engine's fork, and the notes are written in this
            // process as it settles (DreamPassCompletedMsg).
            $dream = $done->inFlight || $done->memoryStore === null ? null
                : \SugarCraft\Code\Memory\DreamPass::new(
                    \SugarCraft\Code\Memory\MemoryWriter::new($done->memoryStore, $done->projectRoot()),
                )->call($done->backend, $done->spendCapReached(), $done->currentSessionId);
            $judge = null;
            if ($goal !== null) {
                [$done, $judge] = $done->scheduleGoalJudge($goal);
            }
            // Roadmap 5.14a: the `notify` setting's bell or desktop
            // notification, once the turn is really over — not while a queued
            // prompt or a goal's judging carries it on.
            $notify = $done->inFlight ? null : $done->terminalNotification('candy-code: ' . Lang::t('chat.notify.turn_finished'));
            // P-CTX.d: the context pane's stamp refreshes off the loop, from
            // the transcript this settle just produced.
            $measure = $done->inFlight ? null : $done->measureContextCmd();
            $cmds = array_values(array_filter([
                $doneCmd,
                $suggest,
                $consolidate === null ? null : Cmd::promise($consolidate),
                $autoCommit,
                $dream === null ? null : Cmd::promise($dream),
                $judge,
                $notify,
                $measure,
            ]));

            return [$done, match (count($cmds)) {
                0 => null,
                1 => $cmds[0],
                default => Cmd::batch(...$cmds),
            }];
        }
        if ($msg instanceof GoalJudgedMsg) {
            return $this->landGoalVerdict($msg);
        }
        if ($msg instanceof SideQuestionAnsweredMsg) {
            // Roadmap 5.14b: accounted first (the call ran on the user's key),
            // then one UI-only row — shown, never sent — unless the session
            // it was asked in is no longer the one on screen.
            $this->accountUsage($msg->usage, self::modelOf($this->titleBackend)); // OBS-3: the btw call ran on the title engine
            if ($msg->sessionId !== $this->currentSessionId) {
                return [$this, null];
            }

            return [$this->mutate(['history' => [
                ...$this->history,
                Message::assistant(\SugarCraft\Code\Host\Commands\BtwHostCommand::answerRow($msg))->withUiOnly(),
            ]]), null];
        }
        if ($msg instanceof \SugarCraft\Code\Host\Commands\HandoffSeededMsg) {
            return $this->landHandoff($msg); // Roadmap 5.14c.
        }
        if ($msg instanceof DreamPassCompletedMsg) {
            // Roadmap 5.4-3: the notes, the memory-history commit and the
            // journal cursor are already written (in this process, as the
            // turn settled). Accounted first — the pass ran on the user's
            // key — then one display-only notice when a note was saved,
            // changed or removed, or a skill draft was proposed (`/skills`
            // reviews it); a pass that did neither says nothing.
            $this->accountUsage($msg->usage);
            $notice = \SugarCraft\Code\Memory\DreamPass::notice($msg);
            if ($notice === null) {
                return [$this, null];
            }

            return [$this->mutate(['history' => [...$this->history, Message::notice($notice)]]), null];
        }
        if ($msg instanceof \SugarCraft\Code\Workspace\AutoCommittedMsg) {
            // Step 3.G: the commit is already made (or refused). Accounted
            // first — the subject came from a model call on the user's key —
            // then one display-only line saying what was committed, or why not.
            $this->accountUsage($msg->usage, self::modelOf($this->titleBackend)); // OBS-3: the subject came from the title engine

            return [$this->mutate(['history' => [...$this->history, Message::notice($msg->notice())]]), null];
        }
        if ($msg instanceof MemoryConsolidatedMsg) {
            // Roadmap 5.2: the notes are already written (in this process,
            // as the call settled). Accounted first, on the rule every
            // provider-call arm follows, then one display-only notice when a
            // note was saved, changed or removed; a run that saved nothing
            // says nothing. Shown even after a session switch: the notes are
            // the project's, not the session's, and a write is never silent.
            $this->accountUsage($msg->usage, self::modelOf($this->summaryBackend)); // OBS-3: consolidation ran on the summary engine
            $notice = \SugarCraft\Code\Memory\AutoMemoryConsolidator::notice($msg);
            if ($notice === null) {
                return [$this, null];
            }

            return [$this->mutate(['history' => [...$this->history, Message::notice($notice)]]), null];
        }
        if ($msg instanceof CancelledWorkflowReportMsg) {
            // The report of a run Esc Esc cancelled: appended, and NOTHING
            // else — the turn it occupied was released by the cancel, and
            // whatever turn is running now is not this report's to settle.
            return [$this->mutate(['history' => [...$this->history, $msg->message]]), null];
        }
        if ($msg instanceof HistoryCompactedMsg) {
            // Accounted BEFORE the latch check, and for the same reason the
            // AssistantMsg arm accounts before its staleness guard: the
            // summarization call went out on the user's key and was billed
            // whether or not its answer is still wanted. Dropping the summaries
            // is right; forgetting the money is not.
            $this->accountUsage($msg->usage, self::modelOf($this->summaryBackend)); // OBS-3: the compaction summary ran on the summary engine

            // Roadmap 2.10: an ahead-of-need summary condenses nothing when it
            // lands - it is held for the 85% tier in submit(), under its own
            // latch. One that is no longer the one out (superseded, or asked
            // for in another session) is dropped like a stale /compact; one
            // that came back unusable is held as `[]`, so this cycle does not
            // ask again (see the property's docblock).
            if ($msg->background) {
                $held = $this->backgroundSummary;
                if ($held === null || $held['id'] !== $msg->compactionId) {
                    return [$this, null];
                }
                if ($held['sessionId'] !== $this->currentSessionId) {
                    return [$this->mutate(['backgroundSummary' => null]), null];
                }
                // CTX-4: a HOLD verdict discards the summaries just as an error
                // or a hook block does — a collapsed splice must never be
                // offered to the 85% tier. The tier re-parks on its own and the
                // fresh request's landing carries the advisory.
                $usable = $msg->error === null && $msg->blockedBy === null && $msg->safeguardHold === null;

                return [$this->mutate(['backgroundSummary' => [
                    ...$held,
                    'summaries' => $usable ? $msg->summaries : [],
                ]]), null];
            }

            // CTX-5: the idle fold's landing. Same message shape as a
            // `/compact` answer, its own latch, and a different ending — it
            // rewrites the transcript where it stands and dispatches no turn
            // (nobody sent anything). Checked before the supersede guard
            // below for the same reason the background latch is checked
            // first: this compactionId was never in pendingCompactionId, so
            // the generic guard would silently eat it.
            if ($this->pendingIdleCompaction !== null
                && $msg->compactionId === $this->pendingIdleCompaction['id']
            ) {
                return $this->andContextRemeasure($this->applyIdleCompaction($msg));
            }

            // Superseded: a second /compact was issued, or one of the FOUR
            // release routes abandoned this one - /clear, /rewind, the palette's
            // New session action, or the double-Escape cancel arm below (which
            // became a release route once the 85% tier started parking turns
            // behind a summarization). Dropped rather than applied - see
            // HistoryCompactedMsg's $compactionId docblock for why this is its
            // own latch and not the generation counter.
            if ($msg->compactionId !== $this->pendingCompactionId) {
                return [$this, null];
            }

            return $this->andContextRemeasure($this->applyModelCompaction($msg));
        }
        if ($msg instanceof PromptSuggestionMsg) {
            // Accounted first, on the rule every provider-call arm follows:
            // the call was made on the user's key whatever becomes of it.
            $this->accountUsage($msg->usage, self::modelOf($this->titleBackend)); // OBS-3: the suggestion ran on the title engine

            $text = self::sanitizePromptSuggestion($msg->suggestion);
            $stale = $msg->generation !== $this->generation
                || $msg->historyCount !== count($this->history)
                || $msg->sessionId !== $this->currentSessionId;
            if ($text === '' || $stale || $this->inFlight) {
                return [$this, null];
            }

            return [$this->mutate(['promptSuggestion' => [
                'text' => $text,
                'generation' => $msg->generation,
                'historyCount' => $msg->historyCount,
                'sessionId' => $msg->sessionId,
            ]]), null];
        }
        if ($msg instanceof SessionTitledMsg) {
            // Accounted before either guard below, on the same rule the other
            // three provider-call arms follow: the titler is a real call on the
            // user's key, and a session that was switched away from or a title
            // that came back unusable cost exactly as much as one that did not.
            $this->accountUsage($msg->usage, self::modelOf($this->titleBackend)); // OBS-3: the title ran on the title engine

            // The title call is fire-and-forget: by the time it lands the
            // user may have switched sessions, and a title belonging to a
            // session we are no longer on must not overwrite this one's.
            if ($msg->sessionId !== $this->currentSessionId) {
                return [$this, null];
            }
            // The latch (audit B2, P-A4): a name the USER set is never
            // replaced, and neither is any name latched since the request
            // went out — `/rename` typed while it was in flight, or a name
            // read back from the store whose source this window cannot vouch
            // for. `/rename --auto` and a blank inline rename clear the name
            // first, which is the one way a generated title lands again. The
            // store refuses the write on the same rule; this guard keeps the
            // UI from showing a title the store never took.
            if (
                $this->currentSessionTitleSource === \SugarCraft\Code\Session\TitleSource::User
                || $this->currentSessionName !== null
            ) {
                return [$this, null];
            }
            $title = self::sanitizeSessionTitle($msg->title);
            if ($title === '') {
                return [$this, null];
            }
            return [$this->mutate([
                'currentSessionName' => $title,
                'currentSessionTitleSource' => \SugarCraft\Code\Session\TitleSource::Auto,
            ]), null];
        }

        return null;
    }

    /**
     * P-CTX.d: ride a context remeasurement onto a landing where the fold
     * actually rewrote the transcript — the pane's stamp is stale from this
     * message on. A dropped or cancelled fold releases its latch and leaves
     * the history value-identical: nothing changed to measure, so those keep
     * the null-shaped schedule they always had.
     *
     * @param array{0: self, 1: ?\Closure} $applied
     * @return array{0: self, 1: ?\Closure}
     */
    private function andContextRemeasure(array $applied): array
    {
        [$done, $cmd] = $applied;
        if ($done->history === $this->history) {
            return $applied;
        }

        return [$done, Cmd::batch($cmd, $done->measureContextCmd())];
    }
}
