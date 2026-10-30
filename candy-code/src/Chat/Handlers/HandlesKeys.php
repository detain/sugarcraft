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
use SugarCraft\Code\Tui\MessagePicker\OpenMessagePickerMsg;
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
use SugarCraft\Code\Chat\Handlers\HandlesTurnCompletion;
use SugarCraft\Code\Chat\Handlers\HandlesSessionSurface;
use SugarCraft\Code\Message;
use SugarCraft\Code\Lang;

// W7-S4 route() decomposition — the KEYS family.
//
// Owns the KeyMsg gate, the mouse-selection/vim/palette prematch arms and
// the terminal binding table (the old `return match (true)` spine). Every
// arm kept verbatim from src/Chat.php except the gate rewrite noted inline.
trait HandlesKeys
{
    /**
     * Route family handler: owns the KeyMsg gate, the prematch arms, and the
     * terminal binding table (W7-S4 decomposition). The gate answers null for
     * every non-key message so route()'s other family links can take them —
     * the same relative order they held inline before the split. A KeyMsg
     * always resolves here: the table carries `default => [$this, null]`.
     */
    private function handleKeysMessages(Msg $msg): ?array
    {
        // W7-S4: this gate used to end route() for every non-key message with
        // [$this, null]. A family handler's "not my family" answer is `return
        // null;`, and route()'s terminal `?? [$this, null]` re-materialises the
        // old reply byte-identically.
        if (!$msg instanceof KeyMsg) {
            return null;
        }
        // A key dismisses a mouse selection's lingering highlight. Ctrl+C
        // over one is the "copy" reflex every terminal user has, and the text
        // is ALREADY on the clipboard (the release copied it), so that press
        // only dismisses — quitting out from under a fresh copy would be the
        // most surprising answer the chord could give.
        $selection = self::$textSelection;
        if ($selection !== null) {
            self::$textSelection = null;
            if ($selection->settled && $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'c') {
                return [$this, null];
            }
        }
        // BOTH encodings of Ctrl+C. sugar-core's InputReader normalizes every
        // control byte 0x01-0x1a into (Char, chr(0x60 + code), ctrl: true), so
        // the real terminal delivers ^C as rune 'c' WITH the ctrl flag and
        // never as the raw "\x03" this used to test for alone — which meant
        // Ctrl+C could not quit the app on the live path at all. The raw rune
        // is still accepted for callers that synthesize a KeyMsg directly.
        //
        // E744 WS2 precedence (the key-routing decision in the acceptance):
        // with a selection up, the flagged pair is the EDITOR'S copy chord —
        // the draft is the focused surface and its widget already answers
        // ctrl+c exactly this way (TextArea's own table); delegating reuses
        // that answer instead of reimplementing selection extraction here,
        // and the Cmd it raises rides the E744 WS1 relay. Quitting stays the
        // outcome for every no-selection press, and for the raw synthetic
        // "\x03" in ANY state — that encoding is a programmatic escape hatch,
        // not the terminal's copy gesture, and a caller that sends it means
        // the signal, not the chord.
        if ($msg->type === KeyType::Char
            && ($msg->rune === "\x03" || ($msg->ctrl && $msg->rune === 'c'))) {
            if ($msg->ctrl && $msg->rune === 'c' && $this->input->hasSelection()) {
                return $this->delegateToInput($msg);
            }

            return [$this, Cmd::quit()];
        }

        // The keybinding reference owns the keyboard while it is up, and is
        // checked this high for the same reason the permission prompt is: it
        // is a full modal the user cannot see past, so a key that reached the
        // transcript scroll or the input box below would act on something
        // hidden. Ctrl+C stays above it — quitting must never need the modal
        // dismissed first.
        //
        // It outranks the permission prompt, a pair NO producer that exists
        // today can put up. WHICH lock forbids it is the part two revisions of
        // this comment got wrong, so it is stated as measured rather than as
        // reasoned:
        //
        //   * the reference only opens from an idle turn — the "?" arm and
        //     submit()'s /keys branch both sit below the inFlight swallow at
        //     the foot of this method;
        //   * a prompt does NOT "only exist mid-turn". That sentence was here,
        //     and it is refuted by one update() call: this method's AssistantMsg
        //     arm writes 'inFlight' => false and does not clear
        //     $pendingPermission, and mutate() carries it forward, so
        //     prompt-up-and-idle is a state update() itself produces. The
        //     public constructor reaches it too — parameters are not
        //     visibility-scoped, so `new Chat(flow: new PermissionFlow(pendingPermission: $ask))` builds
        //     it with no exception. No WIRED producer sends a second
        //     AssistantMsg while a prompt is up (beginToolCalls() parks the
        //     WHOLE gated batch on the first ask and dispatches nothing, so the
        //     only Cmd outstanding across a live prompt is the permission
        //     Deferred, which is not a Msg source) — measured by walking every
        //     'inFlight' => false site in this file. So this is an API-surface
        //     hole today and a live one the moment the engine path lands;
        //   * and in that separated state the pair is STILL refused, by the
        //     $pendingPermission arm ALONE — driven: "?" leaves keyHelp null and
        //     Ctrl+P leaves palette null with inFlight already false. The arm
        //     above, not the swallow below, is what closes this door;
        //   * requestPermission()'s generation guard closes no door here at
        //     all: measured, both internal producers stamp the generation that
        //     is current on the object they call. It is dormant defence for the
        //     unwired engine path; its docblock carries the measurement. An
        //     UNSTAMPED ask still applies, by design, since that is what every
        //     internal caller and any future pipeline (PermissionRequestMsg's
        //     own docblock names the engine path) may legitimately send.
        //
        // So the pair is ordered rather than assumed away, and
        // Renderer::renderStatusBar() announces the buried prompt instead of
        // leaving it invisible and silent — that cue is the half of this which
        // is reachable, driven, and does bite. Both halves are pinned in
        // KeyHelpTest: testThePromptAndTheReferenceCannotBothBeRaisedByRealInput()
        // for the live-turn state and
        // testAPromptOutlivesItsTurnAndTheReferenceIsStillRefused() for the
        // separated one.
        if ($this->keyHelp !== null) {
            return $this->handleKeyHelpKey($msg);
        }

        // Page Up/Down scroll the transcript a screenful at a time -- the
        // keyboard equivalent of the wheel, which was the only way to move
        // through history. A page is the visible rows less two so a couple of
        // lines of context carry over between screens, the same overlap the
        // three-line wheel notch keeps.
        if ($msg->type === KeyType::PageUp || $msg->type === KeyType::PageDown) {
            $page = max(1, $this->rows - 2);

            return $this->scrollBy($msg->type === KeyType::PageUp ? $page : -$page);
        }
        // A blocking permission prompt owns the keyboard while it is up, and
        // is checked ahead of BOTH the Escape arm and the inFlight
        // blanket-swallow below: the turn is inFlight by definition while
        // waiting on this answer, so without this arm every reply keystroke
        // would be discarded and the prompt could never be answered, and
        // Escape would abort the whole turn rather than refuse this one call.
        if ($this->flow->pendingPermission !== null) {
            return $this->handlePermissionKey($msg);
        }
        // P-A4: the inline session-title editor owns the keyboard while it is
        // open — ahead of the Escape arm, so Escape closes the editor instead
        // of arming a turn cancel, and ahead of the mid-turn refusals, because
        // naming a session starts no turn and rewrites no history.
        if ($this->titleEditor !== null) {
            return $this->handleTitleEditorKey($msg);
        }
        // C-CTX-3.b: a pending `#` quick-add owns the keyboard while its
        // confirm row is up — Enter and Escape are its two answers, and any
        // other key cancels the offer and lands in the restored draft.
        // Ahead of the Escape arm below, so Escape answers the confirm
        // instead of arming a turn cancel, and ahead of the mid-turn
        // refusals for the same reason the title editor is: this keystroke
        // starts no turn and rewrites no history.
        if ($this->pendingQuickAdd !== null) {
            return $this->handleQuickAddKey($msg);
        }
        // Escape is checked before the inFlight blanket-swallow below (like
        // Ctrl+C above) because its whole point while a request is running
        // is to let the user cancel it. A single Escape never quits the app
        // any more (see this arm's history - it used to, and Alt+Backspace's
        // terminal-decoding bug made Escape fire on its own by accident,
        // quitting unexpectedly). Two Escapes within
        // DOUBLE_ESCAPE_WINDOW_SECONDS while inFlight abort the in-progress
        // turn instead. Excluded while the palette is open so Escape keeps
        // its existing, more specific meaning there - see
        // handlePaletteKey()'s own Escape arm, reached below once this `if`
        // doesn't match.
        // The session picker is excluded for the same reason the palette is:
        // Escape closes the overlay there (see handleSessionPickerKey()),
        // which is more specific than "cancel the in-flight turn".
        // TUI-1 adds the same more-specific exclusion for the `@` mention
        // popup, one arm EARLIER than that `if` rather than a sixth conjunct:
        // while the popup is up, Escape is the popup's close, and it must not
        // arm the two-beat stop binding at all (plan: Esc closes BEFORE the
        // stop-binding arms). mentionPopupOpen()'s own modal guards make the
        // palette/picker exclusions moot here.
        if ($msg->type === KeyType::Escape && $this->mentionPopupOpen()) {
            return [$this->mutate(['mentionMenuDismissed' => true]), null];
        }

        // ── TUI-3A: vim-mode composer, wiring half 1 of 2 ─────────────────
        // (a) The SETTING is the truth and the widget carries the arm flag;
        // the keystroke that FINDS them disagreeing is spent re-arming,
        // because replaying a key through a widget built for the other mode
        // is how stray characters get typed. UiSettings is memoized (the
        // save that flips it calls forget()), so with the setting off — the
        // DEFAULT, and every wire-dormant run — this comparison is
        // `false !== false` per keystroke and the whole region below is
        // inert. Toggling mid-session therefore applies on the NEXT
        // keystroke, not the frame: no reload, no restart, one spent key.
        $vimEnabled = self::vimModeEnabled();
        if ($msg instanceof KeyMsg && $vimEnabled !== $this->input->vimMode) {
            return [$this->mutate([
                'input' => $this->input->withVimMode($vimEnabled),
                'composerVim' => ComposerVim::empty(),
            ]), null];
        }

        // (b) An armed vim grammar (a pending `d`/`y`/`c` operator, a
        // text-object scope waiting for its target, or an in-progress count)
        // resolves HERE, ahead of the double-Escape arm below, because its
        // Escape means "cancel the operator" and must not arm the two-beat
        // stop binding. Every keyboard owner above the composer gates it out
        // the same way the Escape arm is gated: while a modal or the `@`/`$`
        // popup is open that surface keeps its keys and the pending simply
        // waits. Enter is the one overlap with an owner BELOW — the slash
        // menu and the mention accept bind it first, so under a popup the
        // operator dies and the menu gets its Enter, exactly as it would
        // with vim off. A key that is no operator material at all (Tab, an
        // arrow, a chord) clears the whole armed grammar and re-routes
        // through update() — the recursion is one step deep, since the
        // cleaned clone is unarmed. That is the queue-owns-Tab /
        // palette-owns-arrows guarantee: a forgotten `d` or a forgotten
        // count can never hold the keyboard hostage for one extra
        // keystroke.
        if (
            $this->composerVim->isArmed()
            && $msg instanceof KeyMsg
            && $this->titleEditor === null
            && $this->palette === null
            && $this->sessionPicker === null
            && $this->dirPicker === null
            && $this->historySearch === null
            && !$this->mentionPopupOpen()
        ) {
            if ($msg->type === KeyType::Enter && $this->slashMenuShouldIntercept()) {
                return $this->mutate(['composerVim' => $this->composerVim->pendingCleared()])->update($msg);
            }

            [$vimInput, $vimState, $vimHandled] = ComposerVim::resolve($msg, $this->input, $this->composerVim);
            if ($vimHandled) {
                return [$this->mutate(['input' => $vimInput, 'composerVim' => $vimState]), null];
            }

            return $this->mutate(['composerVim' => $vimState])->update($msg);
        }

        // (c) While vim is armed and the widget sits in INSERT or VISUAL,
        // Escape is that state's exit key and belongs to the widget: the
        // two-beat stop arm below unconditionally claims every otherwise
        // unowned Escape (the pre-vim behaviour), which would trap a user in
        // Insert. NORMAL-state Escape therefore keeps its byte-identical
        // turn-cancel meaning; a modal or the `@` popup above keeps its own
        // Escape owner untouched, and with the setting off `vimMode` is false
        // so this arm is inert.
        if (
            $msg->type === KeyType::Escape
            && $this->input->vimMode
            && $this->input->vimState !== VimState::Normal
            && $this->titleEditor === null
            && $this->palette === null
            && $this->sessionPicker === null
            && $this->dirPicker === null
            && $this->historySearch === null
            && !$this->mentionPopupOpen()
        ) {
            return $this->delegateToInput($msg);
        }

        if ($msg->type === KeyType::Escape && $this->palette === null && $this->sessionPicker === null && $this->dirPicker === null && $this->historySearch === null) {
            if (!$this->inFlight) {
                // C-TUI-3.d (GAP-UI-15): the IDLE twin of the double-Escape.
                // The stamp is stored NEGATIVE while idle — the sign records
                // which state armed it — so a press that straddles a turn
                // boundary can never pair with an in-flight stamp, and the
                // in-flight window check below reads positive stamps only (a
                // negative one re-arms the soft cancel). Both presses idle,
                // inside the same window, with an empty draft, and the
                // message picker opens over the shell.
                $now = microtime(true);
                if ($this->lastEscapeAt !== null && $this->lastEscapeAt < 0
                    && ($now + $this->lastEscapeAt) <= self::doubleEscapeWindowSeconds()
                    && trim($this->inputBuf) === '') {
                    return [$this->mutate(['lastEscapeAt' => null]), Cmd::send(new OpenMessagePickerMsg())];
                }

                return [$this->mutate(['lastEscapeAt' => -$now]), null];
            }

            $now = microtime(true);
            // Roadmap 1.C-4: a turn already asked to stop softly is cancelled
            // HARD by the next Escape, however long after the first it comes
            // — the status bar says so ("Esc to cancel now") for as long as
            // the turn takes to reach its boundary.
            $isSecondPress = ($this->lastEscapeAt !== null
                && ($now - $this->lastEscapeAt) <= self::doubleEscapeWindowSeconds())
                || $this->stopRequested();

            if (!$isSecondPress) {
                // The first Escape is a SOFT cancel when the turn can take one:
                // an engine turn that reports its steps lets the step's tools
                // finish and makes no further provider call (`cancel_soft`).
                // Anything else — a command backend, a workflow, the window
                // before a turn's first step — keeps the old rule: only a
                // second Escape inside the window does anything.
                if ($this->liveStep() !== null) {
                    $this->inFlightCancellation?->cancelSoft();
                    // 1.C-4b: and the call running right now stops too
                    // (`cancel_tool{callId}`), rather than holding the turn
                    // until it finishes — it settles as cancelled, and the
                    // turn ends at the boundary with every result in place.
                    foreach ($this->history as $message) {
                        if ($message->pendingToolCallId !== null) {
                            $this->inFlightCancellation?->cancelTool($message->pendingToolCallId);
                        }
                    }
                }

                return [$this->mutate(['lastEscapeAt' => $now]), null];
            }

            $this->inFlightCancellation?->cancel();
            $parkedDraft = $this->pendingTurnHooks['draft'] ?? $this->pendingCustomCommand['draft'] ?? null;

            return [$this->mutate([
                // A submission parked behind a forked turn-hook chain (audit
                // 15b-04) is released with the turn it stood in for: the cancel
                // above makes the chain's poll kill the child, the generation bump
                // below strands any verdict already on its way, and the prompt —
                // never echoed, so it would otherwise be gone — goes back into the
                // box when the box is still empty.
                //
                // A command line parked behind a forked template expansion (audit
                // 15b-20) is released the same way, and so is any expansion kept
                // for a turn-hook re-entry that will now never happen.
                ...($parkedDraft !== null && trim($this->inputBuf) === ''
                    ? ['inputBuf' => $parkedDraft]
                    : []),
                'pendingTurnHooks' => null,
                'pendingCustomCommand' => null,
                'resolvedCustomCommand' => null,
                'inFlight' => false,
                'inFlightCancellation' => null,
                'lastEscapeAt' => null,
                'workflowTurnInFlight' => false,
                'generation' => $this->generation + 1,
                // Every "running" placeholder of the aborted turn is healed into
                // the "interrupted" row first (audit 15b-02): the generation
                // bump below strands the ToolFinished that would have resolved
                // it, so left alone it spun for the rest of the session - and a
                // later turn reusing the call id (DSML's `dsml_call_0`) had its
                // result written onto this dead row instead of its own.
                // A workflow turn says what the cancel is doing, since its
                // report is still to come (driveWorkflowFiber()).
                'history' => [...$this->historyWithInterruptedPlaceholders(), Message::notice(
                    Lang::t($this->workflowTurnInFlight ? 'chat.workflow.cancelled' : 'chat.request.cancelled'),
                )],
                // Half a sentence left under the cancellation notice would
                // read as an answer the user is still waiting on. The
                // generation bump also strands any delta still in the inbox,
                // so nothing can type into the void after this.
                'streamingText' => '',
                'reasoningText' => '',
                'expanded' => $this->expandedAfterLiveThought(null),
                // The generation bump does NOT cover a summarization: the latch
                // is $pendingCompactionId, deliberately not the generation
                // counter (see that property's docblock). Releasing it here is
                // load-bearing since crush_code.md Phase 5 item 6 wired the 85%
                // tier, because a PARKED submission
                // ({@see scheduleParkedCompaction()}) holds `inFlight` true with
                // no turn running, which is what makes this arm reachable during
                // the parked window at all - measured, it and Ctrl+C are the only
                // two keys the swallow below leaves live there. Without this the
                // latch still matched when the summary landed and
                // {@see applyModelCompaction()} dispatched the very turn the user
                // had just cancelled.
                //
                // Released UNCONDITIONALLY rather than only for a parked turn:
                // this arm cannot tell a parked submission from a `/compact`
                // running alongside a real turn without new state on Chat, and of
                // the two possible errors, abandoning a compaction the user can
                // simply re-run is strictly cheaper than sending a cancelled
                // prompt to the provider. The prompt or the `/compact` line is
                // still in the transcript either way - both routes echo before
                // the request leaves. Whether the CALL is still paid for depends
                // on the backend: the parked summarization carries its own token
                // ({@see scheduleParkedCompaction()}, backlog §E32), so a provider
                // that honours the best-effort {@see Backend} contract stops
                // spending here, while one that ignores it is billed as before,
                // because update() accounts usage ahead of the latch check. A
                // `/compact` summarization is always in the second group - it is
                // scheduled with no token, on purpose, and that method's own
                // docblock says why the two triggers differ.
                'pendingCompactionId' => null,
                // CTX-5: same release for an idle fold in flight. Escape is the
                // user being HERE; a fold landing under a session they just
                // interrupted rewrites the transcript they were reading.
                'pendingIdleCompaction' => null,
                // `queuedPrompts` is deliberately ABSENT, which is a decision and
                // not an omission. This arm clears `inFlight`, so it is the one
                // turn-ending site that does NOT call
                // {@see releaseQueuedPrompts()}: the user just asked to stop the
                // RUNNING turn, which says nothing about a message they typed
                // deliberately while it ran, and dispatching it here would send
                // the one thing they may have been trying to stop. Nor is it
                // dropped — that would silently destroy the user's text. It stays
                // queued and stays visible ({@see Renderer::renderStatusBar()}
                // counts it), and goes out when the next turn settles.
            ]), null];
        }
        // MID-TURN KEY POLICY. This used to be
        //
        //     if ($this->inFlight) {
        //         // Ignore keystrokes while waiting for the backend
        //         // (avoids the user racing ahead and queuing another
        //         // turn into a half-formed history).
        //         return [$this, null];
        //     }
        //
        // a blanket swallow, and it was the whole of a user-reported bug: for the
        // length of a turn the input box, the Ctrl+P palette, the session picker,
        // Up-recall and Ctrl+O were all dead, because every one of them is
        // lexically BELOW this point. It was NOT an async defect — the completion
        // already runs in a forked child ({@see Backend\EngineBackend::completeAsync()})
        // and the loop was delivering the keystrokes; they arrived here and were
        // dropped on purpose.
        //
        // The swallow's stated reason is real, so it is SPLIT rather than deleted.
        // The hazard was never "a key reached the input box"; it was "a key
        // STARTED A TURN, or rewrote the history a running turn is about to append
        // to". So the policy now lives at the three places that can actually do
        // that, and everything else runs mid-turn exactly as it does when idle:
        //
        //   * {@see submit()} — Enter ENQUEUES ({@see enqueuePrompt()}) instead of
        //     dispatching, and a draft that starts with `/` is refused
        //     ({@see refuseInFlightCommand()}) rather than queued;
        //   * {@see handlePaletteKey()} — the palette opens and browses, but Enter
        //     on an action other than Exit is refused;
        //   * {@see handleSessionPickerKey()} — the picker opens and browses, but
        //     `resume` is refused.
        //
        // Plus the three keys below, which reach a turn-starting or
        // history-replacing arm without passing any of those three.
        if ($this->inFlight) {
            $refused = $this->refuseWhileInFlight($msg);
            if ($refused !== null) {
                return $refused;
            }
        }

        // While the Ctrl+P command palette is open, every keystroke feeds
        // its own query/navigation/dispatch handling instead of inputBuf/the
        // "/" popup - see handlePaletteKey()'s docblock.
        if ($this->palette !== null) {
            return $this->handlePaletteKey($msg);
        }

        // Same rule for the session picker overlay (crush_feat.md section 5
        // E8): while it is up every keystroke browses/resumes rather than
        // reaching inputBuf. Checked after the palette so the two modals
        // have a fixed, documented precedence even though they cannot both
        // be open.
        if ($this->sessionPicker !== null) {
            return $this->handleSessionPickerKey($msg);
        }

        // And for the `/new` folder picker: every key browses or chooses.
        if ($this->dirPicker !== null) {
            return $this->handleDirPickerKey($msg);
        }

        // Same rule for the Ctrl+G history search (plan_comparison TUI-2):
        // while it is up every keystroke searches/cycles/accepts rather than
        // reaching inputBuf. Checked last among the modals, so the palette,
        // the session picker and the folder picker keep a fixed precedence
        // even though only one of them can be open at a time.
        if ($this->historySearch !== null) {
            return $this->handleHistorySearchKey($msg);
        }

        return match (true) {
            // Alt/Shift/Ctrl+Enter insert a newline instead of submitting.
            // E705: App::init() now pushes the Kitty DISAMBIGUATE flag, so on
            // Kitty-capable terminals Shift+Enter (`CSI 13;2u`) and
            // Ctrl+Enter (`CSI 13;5u`) arrive as Enter KeyMsgs carrying their
            // modifier flags and land here. Alt+Enter stays the reliable one
            // everywhere (ESC+CR, decoded by sugar-core's Alt-prefixed-key
            // fix); on legacy terminals the modified chords are physically
            // indistinguishable from plain Enter — the terminal sends the same
            // CR byte — so they submit, which is the honest degradation.
            //
            // Inserted AT the cursor rather than appended, which is the whole
            // point of the widget: before this arm went through
            // {@see TextArea::insertRune()} it appended to the end of the
            // draft, so splitting an existing line in two was impossible.
            $msg->type === KeyType::Enter && ($msg->alt || $msg->shift || $msg->ctrl)
                => [$this->withInput($this->input->insertRune("\n")), null],
            $msg->type === KeyType::Enter && $this->slashMenuShouldIntercept()
                => $this->completeSlashMenuSelection(),
            // TUI-1: Enter accepts the highlighted `@` file while the popup
            // is up — the draft is still being composed, so this must not
            // submit. completeMention() takes the selected row when the
            // popup is open and falls back to the prefix path otherwise.
            $msg->type === KeyType::Enter && $this->mentionPopupOpen()
                => $this->completeMention(),
            $msg->type === KeyType::Enter
                => $this->recordInputHistory()->submit(),
            // Walking prompt history (↑ on an empty box started it, see the
            // recall arm below) outranks the "/" popup: a recalled `/sessions`
            // opens the popup, and without this ↑ would start browsing it
            // instead of stepping on to the prompt before.
            !$msg->ctrl && !$msg->alt && $msg->type === KeyType::Up && $this->isWalkingHistory()
                => [$this->walkHistory(-1), null],
            !$msg->ctrl && !$msg->alt && $msg->type === KeyType::Down && $this->isWalkingHistory()
                => [$this->walkHistory(1), null],
            // Up/Down navigate the "/" popup while it's showing (see
            // slashMenuMatches()); otherwise fall through to the default
            // no-op arm below, unchanged from before this popup existed.
            $msg->type === KeyType::Up && $this->slashMenuMatches() !== []
                => [$this->moveSlashMenuSelection(-1), null],
            $msg->type === KeyType::Down && $this->slashMenuMatches() !== []
                => [$this->moveSlashMenuSelection(1), null],
            // TUI-1: the `@` popup browses with the same two keys. The two
            // popups cannot be open at once (a `/` prefix dies at the first
            // space; a mention token needs `@`), so arm order here is
            // precedence-free — the slash arms stay first for byte-identical
            // existing behaviour.
            $msg->type === KeyType::Up && $this->mentionPopupOpen()
                => [$this->moveMentionSelection(-1), null],
            $msg->type === KeyType::Down && $this->mentionPopupOpen()
                => [$this->moveMentionSelection(1), null],
            // Bare Tab completes the HIGHLIGHTED "/" popup row into the draft
            // (the row slashMenuIndex() points at, which Up/Down above move —
            // not "the first match"), and does nothing else: it never submits.
            //
            // Reaching this arm at all is half a fix. Tab is the pane-cycling
            // key of the shell that HOSTS this model, and
            // Tui\KeyboardHandler::claims() used to take it unconditionally,
            // so no keystroke ever arrived here — which is precisely the bug
            // reported ("it switches your active other window"). That claim is
            // now conditional on the same `slashMenuMatches() !== []` test
            // this arm makes, and the two conditions have to stay identical:
            // if the shell yields on a broader condition than this arm answers,
            // Tab becomes a dead key instead of a completion.
            //
            // Two deliberate differences from the Enter path
            // (completeSlashMenuSelection() via slashMenuShouldIntercept()):
            //   - ONE match is not special-cased, and neither is an already-
            //     exact name. Enter needs that exception because Enter's other
            //     job is submitting, and "/agents" + Enter must run the command
            //     rather than re-fill the same text. Tab has no other job here,
            //     so "/compact" + Tab simply re-completes to "/compact " — a
            //     visible no-op, and the alternative (falling through to cycle
            //     panes on an exact name only) would make Tab's meaning depend
            //     on a distinction the popup does not draw on screen.
            //   - The trailing space IS appended, same as Enter's completion,
            //     because several commands take arguments (/rename <name>) and
            //     the space is what closes the popup: slashMenuPrefix() returns
            //     null once inputBuf holds a space, so the very next Tab is a
            //     pane cycle again.
            $msg->type === KeyType::Tab
                && !$msg->ctrl && !$msg->alt && !$msg->shift
                && $this->slashMenuOwnsTab()
                => $this->completeSlashMenuSelection(),
            // Audit 15b-15: Tab completes an `@file` mention the caret ends,
            // like a shell completes a path. Same reachability contract as
            // the arm above - the shell yields Tab on mentionOwnsTab(), the
            // one predicate both sides read.
            $msg->type === KeyType::Tab
                && !$msg->ctrl && !$msg->alt && !$msg->shift
                && $this->mentionOwnsTab()
                => $this->completeMention(),
            // Roadmap 1.C-3 (`chat.queue`): mid-turn, Tab holds the draft for
            // after the turn while Enter steers it in. Same contract again —
            // the shell yields Tab on queueOwnsTab().
            $msg->type === KeyType::Tab
                && !$msg->ctrl && !$msg->alt && !$msg->shift
                && $this->queueOwnsTab()
                => $this->recordInputHistory()->queueDraft(),
            // Shell-history-style recall: Up on an empty input box (and no
            // "/" popup showing - the arm above already claimed that case)
            // fills inputBuf with the last prompt the user sent - from this
            // session or, on a fresh launch, the previous one - and starts a
            // walk that further ↑/↓ presses continue (the arms above Enter's
            // neighbours).
            $msg->type === KeyType::Up && $this->inputBuf === ''
                => [$this->walkHistory(-1), null],
            // → on an empty box takes the grayed suggestion as the draft,
            // cursor at its end, ready to edit or send. With no suggestion
            // showing it falls through to the editor, where it is the
            // no-op cursor move it always was.
            $msg->type === KeyType::Right && !$msg->alt && !$msg->ctrl && !$msg->shift
                && $this->inputBuf === '' && $this->promptSuggestion() !== null
                => [$this->withInputBuf((string) $this->promptSuggestion()), null],
            // Ctrl+P opens the command palette. Checked before the generic
            // Char arm below, or the literal "p" would be typed into the
            // input buffer instead - same reasoning as Ctrl+A just below.
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'p'
                => [$this->mutate(['palette' => PaletteState::root()]), null],
            // Ctrl+O expands/collapses the most recent tool call's output
            // (crush_feat.md §1 E5) and the most recent thought - successful
            // tool bodies and settled thoughts are hidden by default, and with
            // clicks off this is the only way to see one. Checked before
            // the generic Char arm below, or the literal "o" would be typed
            // into the input buffer instead - same reasoning as Ctrl+P above.
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'o'
                => $this->toggleLatestToolOutput(),
            // Ctrl+D opens the image-generation form (crush-media W3.10). The
            // rune is free of every claim set - not a shell chord, not in
            // chatCtrlRunes(), and the session picker's own `ctrl+d` delete is
            // unreachable from here because handleSessionPickerKey() routes
            // above this match while the picker is up. Checked before the
            // generic Char arm for the same reason as Ctrl+P above; the form
            // is screen-only until its generate row fires, so this paints, it
            // never spends.
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'd'
                => $this->openMediaFormView(),
            // Ctrl+V attaches the clipboard's IMAGE (audit 15b-15). A terminal
            // pastes text only - Ctrl+Shift+V / Cmd+V already arrive as one
            // bracketed PasteMsg - so the one thing this chord can add is the
            // pixels a screenshot tool left on the clipboard, read through the
            // platform tool off the update path ({@see ClipboardImage}) and
            // answered by {@see ClipboardImagePastedMsg}. Checked before the
            // generic Char arm for the same reason as Ctrl+P above.
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'v'
                => [$this, static fn (): Msg => new ClipboardImagePastedMsg(ClipboardImage::save())],
            // Ctrl+R opens the live session picker (crush_feat.md section 5
            // E8). NOT the Ctrl+O that section suggests: §1 E5 already bound
            // Ctrl+O to tool-output expansion above, and that is the only
            // way to read a hidden tool body. `r` is a chord
            // Commands\KeyBindingRegistry gives to Chat (chatCtrlRunes()) and
            // to no shell row, so the pane shell falls it straight through to
            // here from any ordinary pane -- the exception being the three
            // states KeyboardHandler::shellOwnsKeyboard() covers, where the
            // registry yields the chord back rather than let this arm open a
            // picker underneath a view that claims up/down/enter. It mirrors
            // Claude Code's `--resume` picker mnemonic.
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'r'
                => [$this->mutate(['sessionPicker' => $this->buildSessionPicker()]), null],
            // Ctrl+G opens the reverse history search (plan_comparison TUI-2,
            // owner ruling R10). The chord is shared with the shell's
            // `shell.group-input` Broadcast row and YIELDS to it while one of
            // the shell's keyboard-owning views is up — see
            // KeyBindingRegistry::chatCtrlRunesYieldedToShell() for the
            // declared dual claim; in every ordinary pane it reaches here.
            // While the overlay is already open this arm is unreachable (the
            // route above feeds the widget, whose own ctrl+g cycles matches).
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'g'
                => [$this->mutate(['historySearch' => $this->buildHistorySearch()]), null],
            // R20: Ctrl+A re-runs the exact same /agents dispatch submit()
            // already uses for typed input (handleAgentsCommand()), giving
            // KeyboardHandler's Ctrl+A shortcut (Pane::Agents in the
            // disconnected App/Tui system) a real, reachable equivalent on
            // this, the live, Chat path. Must be checked before the generic
            // Char arm below, or the literal "a" would be typed into the
            // input buffer instead.
            //
            // Through runCommand(), not `withInputBuf('/agents')->submit()`
            // (audit 15b-34): the chord is a shortcut, not something the
            // user typed, so it must leave their draft alone — the old arm
            // replaced whatever was in the box with `/agents` and submitted
            // it, silently discarding the draft.
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'a'
                => $this->runCommand('/agents'),
            // Ctrl+L clears the screen and repaints the frame (rv-a11y,
            // 2026-10-14 review). The line-diff renderer only writes changed
            // rows, so foreign bytes (kernel messages, child-process noise)
            // that smear the frame used to stick until a resize. clearScreen()
            // writes erase+home verbatim; the SAME-SIZE WindowSizeMsg then
            // rides Program::dispatch()'s resize path, whose renderer reset is
            // unconditional outside inline mode (crush runs the alt screen),
            // so the next frame repaints every row. Checked before the generic
            // ctrl-Char arm below — that arm used to type the letter here, and
            // the flip is disclosed in Renderer/KeyHelpTest's byte map and
            // ChatInputCursorTest's chord roster.
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'l'
                => [$this, Cmd::batch(
                    Cmd::clearScreen(),
                    fn (): Msg => new WindowSizeMsg($this->cols(), $this->rows()),
                )],
            // "?" opens the keybinding reference, but ONLY on a BLANK input
            // line. It is a plain printable character with no modifier, so an
            // unconditional bind would make a question impossible to type -
            // the input box has no other way to receive it.
            //
            // trim(), not === '', and that is a deliberate correction rather
            // than tidying. submit() below tests trim($this->inputBuf) before
            // matching "/keys", so while this arm tested the RAW buffer the two
            // routes to the same reference disagreed on exactly one class of
            // draft: trimmed-empty but not empty. Driven one keystroke at a
            // time on the raw form, a single Space press put the box in a state
            // where typing "/keys" onto the draft and pressing Enter opened the
            // reference while "?" typed " ?" instead - so "/keys" WAS an escape
            // hatch there, which is the one thing the docs for it say it is not.
            // Every blank-but-not-empty draft disagreed -- six of them in today's
            // corpus (" ", "  ", "\0", "\n", "\t", " \t "), four when this was
            // written -- and the remaining members agreed, as did all six of the
            // non-draft states in KeyHelpTest::openRouteStates(). No count is load-
            // bearing here: the class is "trim()-empty but not ===''", and the
            // corpus grows into it whenever a further member of that class is found
            // reachable, which is exactly what happened to "\0" and "\n".
            // Pinned in both directions by
            // KeyHelpTest::testTheTwoRoutesAgreeOnEveryBlankAndNonBlankDraft().
            //
            // Four of those six -- "\t", " \t ", plus "\r" and "\x0B", which the
            // corpus does not carry -- cannot be TYPED, and the verb is the whole
            // correction: the previous revision called them "SYNTHETIC drafts: no
            // keystroke produces them", which is wrong. Measured at sugar-core's
            // decoder, InputReader::parse("\t") yields KeyType::Tab, not
            // KeyType::Char "\t", and the Tab arm below leaves the buffer alone, so
            // no rune puts "\t" or " \t " in the box. Space and "\0" (Ctrl+Space)
            // are the only two of trim()'s six bytes a keystroke lands, and that
            // whole map is asserted byte by byte in the test named above.
            //
            // Untypeable is not unreachable. The Up arm above copies a
            // recallEntries() row in VERBATIM - on a Chat with no prompt file
            // wired those are the transcript's user rows - and
            // Chat::reviveCheckpointMessage() turns a checkpoint row whose role is
            // neither 'assistant' nor 'system' -- a 'tool' row, whose output is full
            // of tabs -- into a user message with its content unchanged. ('system'
            // used to land here too; that was a bug, fixed in E33's review round,
            // and this route never depended on it.) Driven end to end in that
            // test: a revived tool row plus one Up puts "\t/keys" (or "\t", or
            // " \t ") in the box. These are drafts a user can hold; they are just
            // not drafts a user can type. Same for "\u{000C}", which is in the
            // NON-blank set: parse("\x0C") decodes to Ctrl+L, the repaint
            // chord, which types nothing, so a form feed is recalled-only too.
            // And since E704 there is a paste route in too: bracketed paste
            // decodes to one PasteMsg and update() inserts its payload at the
            // caret VERBATIM, so "\t" (sanitize keeps tabs) reaches the box by
            // a route that is neither a keystroke nor the Up arm. Pre-E704 this
            // paragraph claimed the opposite -- that update() returned the
            // IDENTICAL object handed a PasteMsg -- and cited `grep -rn
            // PasteMsg src/`, an instrument that had been self-refuting since
            // the day it was written; the driven ingest pins now live in
            // PasteIngestTest, with the old drop-pin flipped in KeyHelpTest.
            //
            // Space is driven twice in that corpus, as KeyType::Space and as
            // KeyType::Char " ". Measured, InputReader::parse(" ") yields only the
            // former, so the second form is the corpus being stricter than the
            // decoder rather than a second thing the decoder does - which is a
            // narrower claim than the "either" an earlier revision made here.
            //
            // The cost is one press, not one character: this arm does NOT clear
            // the buffer, so on a " " draft the space survives behind the
            // overlay, and typing a literal "?" after leading whitespace now
            // needs "??" exactly as it already did on an empty line - measured,
            // " ??" leaves " ?". Widening the guard therefore removes the
            // disagreement without taking anything from the draft.
            //
            // The Up arm above keeps === '' and must: it OVERWRITES the buffer
            // with the recalled message, so a trim() there would silently eat a
            // whitespace draft. Same-looking guard, opposite conclusion, because
            // one arm destroys the buffer and this one does not.
            //
            // The blank-line guard still costs one keystroke on the message
            // that STARTS with "?": typed left to right on an empty line,
            // "?why" used to leave inputBuf empty and the reference open. The
            // escape hatch is the second "?" - see handleKeyHelpKey(), where
            // "?" both closes the reference and lands the literal character, so
            // "??why" types "?why" and the footer hint on screen says so.
            // /keys is NOT that hatch: it opens the same reference, which is
            // not what a user COMPOSING a "?" question wants.
            //
            // A previous revision of this comment called that cost "total"
            // on the grounds that "this input box has no cursor movement at
            // all (no KeyType::Left or KeyType::Right arm anywhere in this
            // file) ... so column 0 is only ever reached by typing the first
            // character". Both halves of that were true when written and the
            // first half is now FALSE: crush_code.md Phase 3 item 1 delegated
            // the draft to sugar-forms' TextArea, and Left/Right/Home/End
            // reach it from the arms at the foot of this match. So there is now
            // a SECOND hatch, and it is the ordinary one a user would reach
            // for: type "why", press Home, type "?" - the guard does not fire
            // (trim("why") is not empty), the Char goes to the widget, and the
            // draft becomes "?why". Driven in
            // ChatInputCursorTest::testHomeThenAQuestionMarkComposesALeadingQuestionMark().
            // The "??" hatch stays, unchanged and still the only one on a
            // genuinely empty line, where there is no other character for the
            // cursor to sit in front of.
            $msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt
                && $msg->rune === '?' && trim($this->inputBuf) === ''
                => [$this->withKeyHelp(0), null],
            // Word-delete: Ctrl+W (the usual terminal-wide convention) or a
            // correctly alt-flagged Backspace (see sugar-core's
            // Alt-prefixed-key fix - before it, Alt+Backspace mis-decoded
            // as a bare Escape and quit the app instead of reaching here at
            // all). Must be checked before the plain Backspace arm below.
            //
            // Ctrl+Backspace is in this arm and is an UPGRADE, not a
            // bug-for-bug restoration: at HEAD it reached the plain-Backspace
            // arm and deleted one character. It shares the boundary helper
            // with Ctrl+W deliberately, because word motion (Alt/Ctrl+←/→)
            // now exists, so a keyboard where the modifier means "by word" on
            // the arrows and "by character" on Backspace would be
            // inconsistent with itself. Real bytes: `CSI 127;5u`, the Kitty
            // spelling, decodes to KeyMsg(Backspace, ctrl).
            ($msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'w')
                || ($msg->type === KeyType::Backspace && ($msg->alt || $msg->ctrl))
                => [$this->deleteInputWordBefore(), null],
            // The forward mirror of the arm above, through the forward
            // boundary helper. A pure ADDITION: `CSI 3;5~` was a no-op at
            // HEAD (nothing claimed a ctrl-flagged Delete, and there was no
            // Delete arm at all), so there is no previous behaviour to keep.
            $msg->type === KeyType::Delete && $msg->ctrl
                => [$this->deleteInputWordAfter(), null],
            // Word motion. Checked ahead of the plain Left/Right delegation
            // below, and ahead of nothing else - no arm above claims an
            // arrowed modifier. See {@see wordLeftOffset()} for why Chat owns
            // the boundary instead of the widget.
            //
            // Both modifiers, and which BYTES reach this arm is measured at
            // sugar-core's decoder rather than assumed:
            // InputReader::parse("\x1b[1;5D") yields KeyMsg(Left, ctrl) - the
            // xterm-family spelling - and parse("\x1b[1;3D") yields
            // KeyMsg(Left, alt), the spelling terminals that report modifiers
            // in CSI parameters use for Alt. Home/End arrive as both
            // `CSI H`/`CSI F` and `CSI 1~`/`CSI 4~`, and Delete as `CSI 3~`;
            // all five were driven through the decoder.
            //
            // Two encodings a terminal may send for the same INTENT, and both
            // reach word motion now:
            //
            //   * the CSI spellings the arms below match directly -
            //     `CSI 1;3D`/`CSI 1;5D` decode to KeyMsg(Left, alt/ctrl), the
            //     xterm-family and modifier-in-parameter spellings;
            //   * `ESC b`/`ESC f`, readline's Alt+B/Alt+F word motion, decodes
            //     as KeyMsg(Char 'b'|'f', alt) on terminals that send Alt as a
            //     bare ESC prefix. Those two runes had an arm of their own
            //     (E2) since before this comment only promised them; they
            //     route to the SAME offset helpers as the arrows, which is the
            //     whole point - one boundary rule, two byte spellings.
            //
            // One encoding still does NOT reach here, and it is not a
            // regression - it behaves exactly as it did before this arm
            // existed:
            //
            //   * `ESC ESC[D`, the ESC-prefixed Alt+Left some terminals emit,
            //     decodes as TWO messages (Escape, then a bare Left), so the
            //     Escape is consumed by the Escape arm above and the Left
            //     moves one character rather than one word.
            ($msg->type === KeyType::Left && ($msg->alt || $msg->ctrl))
                => [$this->withInputCursor($this->wordLeftOffset()), null],
            ($msg->type === KeyType::Right && ($msg->alt || $msg->ctrl))
                => [$this->withInputCursor($this->wordRightOffset()), null],
            // The ESC-prefix spelling of the two arms above (E2). Checked with
            // `!$msg->ctrl` so a ctrl-flagged rune keeps falling through to
            // the ctrl-Char arm below — since ISS-047 that arm DROPS `ctrl:
            // true, rune: 'b'` instead of typing it, but either way the
            // word-motion pair stays the alt form's alone.
            $msg->type === KeyType::Char && $msg->alt && !$msg->ctrl && $msg->rune === 'b'
                => [$this->withInputCursor($this->wordLeftOffset()), null],
            $msg->type === KeyType::Char && $msg->alt && !$msg->ctrl && $msg->rune === 'f'
                => [$this->withInputCursor($this->wordRightOffset()), null],
            // Roadmap 5.7-1 (decision D8): `Alt+M` toggles plan mode.
            // Shift+Tab stays the shell's backward pane cycle.
            $msg->type === KeyType::Char && $msg->alt && !$msg->ctrl && strtolower($msg->rune) === 'm'
                => $this->togglePermissionMode(new PermissionModeToggledMsg()),
            // C-AGT-4.b (`chat.agent-cycle`): `Alt+A` cycles the session's
            // PRIMARY agent — default, then each roster preset that presents
            // as primary, then back to default. It lands between turns like
            // the switch above: `switchPrimaryAgent()` refuses with a notice
            // row while a turn is in flight, and a roster with nothing to
            // offer stays a silent no-op.
            $msg->type === KeyType::Char && $msg->alt && !$msg->ctrl && strtolower($msg->rune) === 'a'
                => $this->cyclePrimaryAgent(),
            // C-TUI-3.e (`chat.verbose-toggle`): `Alt+O` flips the session's
            // verbose dial — `expandToolOutput`, the settings view's key — in
            // one keystroke, saved to the session tier and never to disk. The
            // rune is lowered like the Alt+M and Alt+A rows above, because a
            // real terminal can hand either case through the same chord.
            $msg->type === KeyType::Char && $msg->alt && !$msg->ctrl && strtolower($msg->rune) === 'o'
                => $this->toggleVerboseOutput(),
            // R20: Ctrl+Tab / Ctrl+Shift+Tab cycle the active session
            // through the OPEN TABS — see cycleSessionTab()'s docblock for
            // the decode/routing chain a real keypress takes and how this
            // relates to Tui\SessionTabs.
            $msg->type === KeyType::Tab && $msg->ctrl
                => $this->cycleSessionTab($msg->shift ? -1 : 1),
            // ISS-047: a ctrl-flagged Char is either one of the draft widget's
            // own line edits or it is DROPPED — it never types its letter.
            // Before this, every unclaimed chord inserted the bare rune, so a
            // readline user's `abc` plus Ctrl+U typed `abcu` where it should
            // have cleared the line (the failure scenario, reproduced byte for
            // byte by the issue's verification round). Ctrl+K and Ctrl+X
            // normally never come down this path in the live TUI — the shell's
            // keymap binds Ctrl+K as the palette's second spelling — so what
            // this arm really owns is Ctrl+E and Ctrl+U plus every direct
            // driver of Chat (test harnesses, embedders) for all four runes.
            $msg->type === KeyType::Char && $msg->ctrl && \in_array($msg->rune, self::CTRL_DRAFT_RUNES, true)
                => $this->delegateToInput($msg),
            $msg->type === KeyType::Char && $msg->ctrl
                => [$this, null],
            // Ctrl+Space types a space, which is exactly what HEAD did with
            // it: `CSI 32;5u` decodes to KeyMsg(Space, ctrl), and HEAD's
            // Space arm did not inspect modifiers. It needs its own arm here
            // because TextArea::update() answers a ctrl-flagged key from its
            // OWN ctrl table and drops everything else, so a ctrl-flagged
            // Space handed to the widget would be swallowed. Different route
            // from the ctrl-flagged Char above by decision, not accident: the
            // space's ctrl form has never meant anything but typing a space
            // (HEAD did not inspect modifiers on Space at all), while a
            // letter's ctrl form means editing or nothing — ISS-047 chose
            // nothing.
            $msg->type === KeyType::Space && $msg->ctrl
                => [$this->withInput($this->input->insertRune(' ')), null],
            // Vertical motion, but only on a draft that HAS a second line.
            // Single-line drafts keep today's behaviour exactly: the Up arms
            // above (slash popup, recall-on-empty) and then the no-op default
            // below, all of which are pinned. On a multi-line draft the no-op
            // was the bug - the newline was insertable and then unreachable.
            !$msg->ctrl && ($msg->type === KeyType::Up || $msg->type === KeyType::Down)
                && str_contains($this->inputBuf, "\n")
                => $this->delegateToInput($msg),
            // Everything left that edits or moves within the draft is the
            // widget's: character/space insertion and Backspace (which used
            // to be this file's hand-rolled append/dropLast pair), plus
            // Left/Right/Home/End/Delete, none of which had an arm here at
            // all before - this input box had no cursor movement whatsoever.
            //
            // Below the modal arms above by construction: a live permission
            // prompt, the keybinding reference, the palette and the session
            // picker each return before this match is reached, so typing
            // stays inert while any of them owns the keyboard.
            //
            // ── the `!$msg->ctrl` guard is load-bearing ──────────────────
            //
            // TextArea::update() opens with `if ($msg->ctrl)` and answers from
            // its own seven-entry rune table, dropping every other
            // ctrl-flagged key REGARDLESS of type. So a ctrl-flagged member of the list
            // below reaches the widget and dies there. That is how Ctrl+Space
            // and Ctrl+Backspace became dead keys when this delegation
            // replaced HEAD's hand-rolled Space/Backspace arms; both now have
            // an explicit arm above, as does Ctrl+Delete.
            //
            // The guard is TOTAL rather than per-key on purpose: a KeyType
            // added to this list in future cannot silently inherit the
            // widget's ctrl-swallowing, it simply keeps whatever this file
            // decided for its ctrl form.
            //
            // Ctrl+Home / Ctrl+End are the two ctrl forms left as no-ops, and
            // that is a deliberate omission for a later round rather than an
            // oversight: they were no-ops at HEAD too, so nothing regresses.
            // What they SHOULD mean is start / end of the whole draft, since
            // plain Home/End are line-scoped here (TextArea::update() answers
            // them with moveCursor($row, …), and this box can have rows) — and
            // deciding that is a keymap addition, not part of the ctrl filter.
            // TUI-3A: vim-mode composer, wiring half 2 of 2 — the letters
            // the sugar-forms Vim layer does NOT bind and a host must: `d`/`y`/`c`
            // arm an operator, `1`-`9` arm a count (C-TUI-1), `D Y C o O e p
            // P` act at once. NORMAL state
            // only (Insert is the lib's verbatim passthrough — typing must
            // reach the draft), nothing armed yet (that grammar resolved
            // above the
            // modals), no visible `/` or `@`/`$` popup (a popup owns the
            // letters that filter it — claiming `d` mid-`/do` or mid-`@src/`
            // would eat the user's own typing), and it sits LAST among the claim arms on purpose: the
            // `?` key-help, the alt word chords, the ctrl family and every
            // modal keep their pinned precedence. What this arm can claim is
            // only what would otherwise reach the widget and be swallowed —
            // with vim off, `$this->input->vimMode` is false and the arm is
            // inert, byte-for-byte the delegation below.
            $msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt
                && !$this->composerVim->isArmed()
                && $this->input->vimMode
                && $this->input->vimState === VimState::Normal
                && $this->slashMenuMatches() === []
                && !$this->mentionPopupOpen()
                && $this->composerVim->claimsKey($msg->rune)
                => $this->applyComposerVim($msg),
            !$msg->ctrl && \in_array($msg->type, self::DRAFT_KEYS, true)
                => $this->delegateToInput($msg),
            default => [$this, null],
        };
    }

    /**
     * C-TUI-3.e (`chat.verbose-toggle`): one keystroke flips
     * `expandToolOutput` for this session — the same dial the settings
     * view edits, but a flip is not a page turn. The save rides the
     * session tier of the launch-built SettingsWriter, the workspace
     * service {@see \SugarCraft\Code\Chat::persistEffort()} uses, so the
     * writer's `refusals()` still judge the key; a session-tier write
     * never reaches the user-config door, which is what "nothing is
     * written to disk" means mechanically. {@see
     * \SugarCraft\Code\Chat::applySettings()} then does the live re-read
     * the renderer needs — `expandToolOutput` is a Live key and no engine
     * key, so a mid-turn press lands at once and nothing is parked until
     * the turn ends — and its generic "saved" toast is replaced by one
     * naming which way the flip went (the replaced toast's expiry tick
     * goes inert on the generation check, so neither timer strands a
     * toast). A Chat built outside the launch has no writer service:
     * that press is reported, never half-applied.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function toggleVerboseOutput(): array
    {
        $writer = $this->workspace?->service(\SugarCraft\Code\Config\Settings\SettingsWriter::class);
        if (!$writer instanceof \SugarCraft\Code\Config\Settings\SettingsWriter) {
            return $this->withSettingsToast(
                Lang::t('chat.verbose.unsavable'),
                \SugarCraft\Toast\ToastType::Warning,
            );
        }

        $on = !\SugarCraft\Code\Config\Settings\UiSettings::bool('expandToolOutput');

        try {
            $writer->write(
                \SugarCraft\Code\Config\Settings\SettingsTier::Session,
                ['expandToolOutput' => $on],
            );
        } catch (\Throwable $e) {
            return $this->withSettingsToast(
                Lang::t('chat.verbose.failed', ['error' => self::reportField($e->getMessage())]),
                \SugarCraft\Toast\ToastType::Warning,
            );
        }

        [$chat, $applyCmd] = $this->applySettings(
            ['expandToolOutput'],
            \SugarCraft\Code\Config\Settings\SettingsWriter::SESSION_TARGET,
        );
        [$chat, $toastCmd] = $chat->withSettingsToast(
            Lang::t($on ? 'chat.verbose.on' : 'chat.verbose.off'),
            \SugarCraft\Toast\ToastType::Success,
        );

        return [$chat, $applyCmd === null ? $toastCmd : Cmd::batch($applyCmd, $toastCmd)];
    }
}
