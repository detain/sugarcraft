<?php

declare(strict_types=1);

namespace SugarCraft\Code\Chat\Handlers;

use SugarCraft\Code\Renderer;
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
use SugarCraft\Code\Skills\ActiveSkillEffects;
use SugarCraft\Code\Skills\SkillMentions;
use SugarCraft\Code\Host\SessionTabState;
use SugarCraft\Code\Host\SessionSwitch;
use SugarCraft\Code\Host\ContextSources;
use SugarCraft\Code\Tui\ComposerVim;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;
use SugarCraft\Code\Tui\ScreenReaderMode;
use SugarCraft\Code\Tui\SessionPicker;
use SugarCraft\Code\Tui\SessionTabs;
use SugarCraft\Code\Tui\TextSelection;
use SugarCraft\Code\Tui\Components\PaneLabel;
use SugarCraft\Code\Support\Clusters;
use SugarCraft\Code\Support\EnvBool;
use SugarCraft\Code\Support\StartupSummary;
use SugarCraft\Code\Support\SystemClipboard;
use SugarCraft\Code\Attachments\FileMentions;
use SugarCraft\Code\Attachments\MentionIndex;
use SugarCraft\Code\Automation\WebhookEvent;
use SugarCraft\Code\Support\ClipboardImage;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\QueueMode;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\AgentMentions;
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
use SugarCraft\Code\Commands\KeyBindingRegistry;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\McpAuthCommand;
use SugarCraft\Code\Commands\McpPromptCommands;
use SugarCraft\Code\Commands\ShareCommand;
use SugarCraft\Code\Commands\WebSearchCommand;
use SugarCraft\Code\Tools\BuiltIn\WebSearch;
use SugarCraft\Code\Observability\EventSink;
use SugarCraft\Code\Observability\NullEventSink;
use SugarCraft\Code\Palette\PaletteAction;
use SugarCraft\Code\Palette\PaletteCatalog;
use SugarCraft\Code\Palette\PaletteEntry;
use SugarCraft\Code\Palette\PaletteEntryKind;
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
use SugarCraft\Code\Context\ContextCategories;
use SugarCraft\Code\Context\CompactionSource;
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
use SugarCraft\Code\App\ContextMeasuredMsg;
use SugarCraft\Code\App\SideQuestionAnsweredMsg;
use SugarCraft\Code\App\DreamPassCompletedMsg;
use SugarCraft\Code\App\MemoryConsolidatedMsg;
use SugarCraft\Code\App\ModelCatalogReadyMsg;
use SugarCraft\Code\App\BackendToolEventsMsg;
use SugarCraft\Code\App\PermissionRequestMsg;
use SugarCraft\Code\App\RuntimeNoticePumpMsg;
use SugarCraft\Code\App\SessionPickerSearchMsg;
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
use SugarCraft\Code\Chat\Handlers\HandlesKeys;

/**
 * The pointer half of {@see \SugarCraft\Code\Chat}: text-selection tracking,
 * click/wheel dispatch and the mouse-zone refusal whitelists. Extracted verbatim
 * (the M6 ruling path for the Chat.php size ratchet); every method keeps the
 * class's own state and constants through `$this`/`self`.
 */
trait HandlesPointer
{
    /**
     * Drag-to-select and copy-on-release over the transcript.
     *
     * The comment this replaces ended §8 E8's drag guard with "so the
     * terminal's own copy-on-select is what the gesture accomplishes" — but
     * with SGR mouse tracking on, the terminal runs no selection of its own:
     * every press, drag and release is reported to the app instead. So the
     * sweep the E8 guard carefully declines to treat as a click did nothing
     * at all. This is the gesture, owned here the way tmux copy-mode, crush
     * and opencode own it:
     *
     *  - a left PRESS inside the transcript's text region (as the last frame
     *    measured it, {@see Renderer::selectableRegion()}) anchors a
     *    selection; a press anywhere else — chrome, the input box, an overlay
     *    frame (which publishes no region) — anchors nothing, and every press
     *    drops the previous selection's highlight;
     *  - MOTION with the button held moves its head, and once the pointer
     *    has strayed past {@see CLICK_DRAG_TOLERANCE_CELLS} (the SAME drift
     *    that stops the release dispatching a click) the covered cells are
     *    highlighted on every repaint;
     *  - the RELEASE reads the covered text off the frame on screen and
     *    copies it: OSC 52 through the frame's own clipboard relay, plus the
     *    host's clipboard tool ({@see SystemClipboard}, which is what reaches
     *    the clipboard from inside tmux). The highlight stays up as the
     *    record of what was copied until the next press, key, wheel notch or
     *    resize.
     *
     * Runs BEFORE {@see handlePointer()} and changes nothing it decides: the
     * click tracker still sees every event, and a drag still dispatches no
     * click. Drift is folded in here too — {@see recordPressDrift()} is a
     * running maximum, so recording the same cell twice changes nothing.
     */
    private function trackTextSelection(MouseMsg $msg): ?\Closure
    {
        if ($msg instanceof MouseWheelMsg) {
            if (self::$textSelection?->settled === true) {
                self::$textSelection = null;
            }

            return null;
        }

        if ($msg->button !== MouseButton::Left) {
            return null;
        }

        // zoneSpace() keeps the SGR report's 1-based cells - the base the zone
        // scanner, sugar-mouse's Selection and Renderer::selectableRegion()
        // all speak - so the pointer is wired straight in, never rebased.
        [$col, $row] = self::zoneSpace($msg->x, $msg->y);

        if ($msg instanceof MouseClickMsg) {
            $region = Renderer::selectableRegion();
            self::$textSelection = $region !== null && self::mouseClicksEnabled()
                ? TextSelection::at($col, $row, $region)
                : null;

            return null;
        }

        $selection = self::$textSelection;
        if ($selection === null || $selection->settled) {
            return null;
        }

        self::recordPressDrift($msg->x, $msg->y);
        $selection = $selection->withHead($col, $row);
        if ((self::$pressGesture[2] ?? 0) > self::CLICK_DRAG_TOLERANCE_CELLS) {
            $selection = $selection->withDragging();
        }

        if (!$msg instanceof MouseReleaseMsg) {
            self::$textSelection = $selection;

            return null;
        }

        $text = $selection->dragging ? $selection->extract(Renderer::selectableLines()) : '';
        if ($text === '') {
            self::$textSelection = null;

            return null;
        }

        self::$textSelection = $selection->withSettled(mb_strlen($text, 'UTF-8'));

        return Cmd::batch(
            $this->relayWidgetCmd(Cmd::setClipboard($text)),
            static function () use ($text): ?Msg {
                SystemClipboard::copy($text);

                return null;
            },
        );
    }

    /**
     * Everything {@see handleMouse()} did before text selection existed:
     * clicks, wheel scrolling, and the §8 E8 drag guard.
     *
     * @return array{0:self,1:?\Closure}
     */
    private function handlePointer(MouseMsg $msg): array
    {
        if ($msg instanceof MouseWheelMsg) {
            // E744 WS4: while the session picker is up, the wheel belongs to
            // the picker's rows — the list under the pointer is the scroll
            // context, exactly as a live terminal would scroll what the user
            // aimed at. Only with no picker does the notch reach the
            // transcript (both polarities pinned).
            if ($this->sessionPicker !== null) {
                return $this->sessionPickerWheel($msg->button);
            }
            // The `/new` folder picker's list scrolls under the wheel too.
            if ($this->dirPicker !== null) {
                return $this->handleDirPickerKey(new KeyMsg($msg->button === MouseButton::WheelUp ? KeyType::Up : KeyType::Down));
            }

            return $this->scrollTranscript($msg->button);
        }

        if ($msg->button !== MouseButton::Left) {
            return [$this, null];
        }

        // Motion is still not translated into a sugar-mouse event (nothing
        // consumes hover), but with the button down it is the terminal
        // narrating a drag, so it feeds §8 E8's drift before being dropped.
        if ($msg instanceof MouseMotionMsg) {
            self::recordPressDrift($msg->x, $msg->y);

            return [$this, null];
        }

        // Built in the registry's coordinate space, not the terminal's: the
        // tracker's release-agreement gate (cdcd550be) pairs a release with
        // its press only when the release re-resolves to the SAME zone the
        // press stored — a moved or vanished zone drops the click rather than
        // firing a stale control (see {@see zoneSpace()}). Drift below stays
        // absolute — it is only ever compared with itself, and a translation
        // cannot change a distance.
        [$zoneCol, $zoneRow] = self::zoneSpace($msg->x, $msg->y);

        $event = match (true) {
            $msg instanceof MouseClickMsg   => MouseEvent::press($zoneCol, $zoneRow),
            $msg instanceof MouseReleaseMsg => MouseEvent::release($zoneCol, $zoneRow),
            default                         => null,
        };
        if ($event === null) {
            return [$this, null];
        }

        $hit = self::zoneAt($msg->x, $msg->y);

        if ($msg instanceof MouseClickMsg) {
            self::$pressGesture = [$msg->x, $msg->y, 0];

            // BOTH DIRECTIONS OF THE GESTURE ARE UNDER THE GUARD, not just
            // the half that dispatches. The press is recorded in one
            // update() and read back in a later one, so a press that lands
            // under a capture used to sit in the static tracker until the
            // capture cleared and then fire: measured before this line —
            // press `pane:menu` under a live prompt, answer it with `n`,
            // release, and the palette opens. The keyboard has no such
            // window; a key under a modal is consumed the moment it arrives.
            // Handing the tracker a NULL press zone consumes this one the
            // same way, through its own documented "Press hit nothing" state
            // rather than by returning early — the later release still pairs
            // and is still thrown away, which is the property
            // {@see refuseMouseDispatch()}'s placement argument rests on.
            if ($hit !== null && $this->refuseMouseDispatch($hit->id) !== null) {
                $hit = null;
            }
        } else {
            self::recordPressDrift($msg->x, $msg->y);
        }

        $drift = self::$pressGesture[2] ?? 0;
        if ($msg instanceof MouseReleaseMsg) {
            // Cleared unconditionally, including on the releases the tracker
            // rejects, so a press abandoned outside any zone cannot leave
            // stale drift to poison the NEXT click.
            self::$pressGesture = null;
        }

        $click = self::clickTracker()->track($event, $hit);
        if ($click === null) {
            return [$this, null];
        }

        // §8 E8. The pair is clean by zone, but the pointer travelled far
        // enough across it that the user was sweeping out a text selection,
        // not pointing at a control — dispatch nothing; the selection itself
        // was copied by {@see trackTextSelection()}.
        if ($drift > self::CLICK_DRAG_TOLERANCE_CELLS) {
            return [$this, null];
        }

        $zoneId = $click->zone->id;

        // The capture guards the keyboard has had all along, now applied to
        // the click that asks for the same thing. Placed HERE rather than at
        // the top of this method - see {@see refuseMouseDispatch()} for both
        // the divergence table and why the tracker has to see the pair first.
        $refused = $this->refuseMouseDispatch($zoneId);
        if ($refused !== null) {
            return $refused;
        }

        $tabPrefix = Renderer::SESSION_TAB_ZONE_PREFIX;
        if (str_starts_with($zoneId, $tabPrefix)) {
            return $this->selectSessionTab(substr($zoneId, strlen($tabPrefix)));
        }

        $panePrefix = Renderer::PANE_ZONE_PREFIX;
        if (str_starts_with($zoneId, $panePrefix)) {
            return $this->selectPane(substr($zoneId, strlen($panePrefix)));
        }

        // §8 E5. The zone id carries the SAME key {@see $expanded} is keyed by
        // (see {@see Renderer::recordToolCallZone()}), so the click lands on
        // {@see toggleToolOutput()} - the one Ctrl+O already drives - rather
        // than on a parallel click-only expansion state that could disagree
        // with it. Unlike Ctrl+O, which can only name the LAST tool call
        // (Chat has no history cursor to select an earlier one with), a click
        // names the exact row the user pointed at, so this also reaches the
        // older calls the keyboard cannot.
        $toolPrefix = Renderer::TOOL_CALL_ZONE_PREFIX;
        if (str_starts_with($zoneId, $toolPrefix)) {
            return [$this->toggleToolOutput(substr($zoneId, strlen($toolPrefix))), null];
        }

        $pickerPrefix = Renderer::PALETTE_ITEM_ZONE_PREFIX;
        if (str_starts_with($zoneId, $pickerPrefix)) {
            return $this->selectPaletteItem(substr($zoneId, strlen($pickerPrefix)));
        }

        // Roadmap P-B3: a live agents strip item (`agent:<runId>`). The run
        // opens in the shell that hosts this chat, which owns the panes, so
        // the click becomes its message; a chat with no shell drops it.
        $agentPrefix = \SugarCraft\Code\Tui\AgentStrip::ZONE_PREFIX;
        if (str_starts_with($zoneId, $agentPrefix) && strlen($zoneId) > strlen($agentPrefix)) {
            $runId = substr($zoneId, strlen($agentPrefix));

            return [$this, static fn (): OpenAgentViewMsg => new OpenAgentViewMsg($runId)];
        }

        // Roadmap P-C2: a Task row's live agent line (`agent-line:<runId>`)
        // opens that run's Agent View, and the view header's own zones walk
        // it: `agent-nav:main`, the breadcrumb, leaves the view — the mouse
        // twin of Esc — and `agent-nav:<runId>`, a sibling arrow, opens that
        // sibling. Allowed mid-turn like `agent:` (watching a run is the point
        // of the view), refused under a modal by refuseMouseDispatch() above
        // like any zone.
        $linePrefix = Renderer::AGENT_LINE_ZONE_PREFIX;
        if (str_starts_with($zoneId, $linePrefix) && strlen($zoneId) > strlen($linePrefix)) {
            $runId = substr($zoneId, strlen($linePrefix));

            return [$this, static fn (): OpenAgentViewMsg => new OpenAgentViewMsg($runId)];
        }
        if ($zoneId === \SugarCraft\Code\Tui\AgentViewHeader::BACK_ZONE) {
            return [$this, static fn (): CloseAgentViewMsg => new CloseAgentViewMsg()];
        }
        $navPrefix = \SugarCraft\Code\Tui\AgentViewHeader::NAV_ZONE_PREFIX;
        if (str_starts_with($zoneId, $navPrefix) && strlen($zoneId) > strlen($navPrefix)) {
            $runId = substr($zoneId, strlen($navPrefix));

            return [$this, static fn (): OpenAgentViewMsg => new OpenAgentViewMsg($runId)];
        }

        // Appendix P §3.2. One glyph of the highlighted picker row's `✎ ★ ✕`
        // cluster. Checked before the row prefix: the glyph zones sit inside
        // the row's zone and name a narrower intent.
        $actPrefix = Renderer::SESSION_ACT_ZONE_PREFIX;
        if (str_starts_with($zoneId, $actPrefix)) {
            return $this->runSessionRowAct($zoneId);
        }

        // E744 WS4. A picker row, keyed by the ABSOLUTE filtered row index it
        // is painted at (see {@see sessionRowIndex()} for the validation that
        // put the zone on the whitelist in the first place).
        $rowPrefix = Renderer::SESSION_ROW_ZONE_PREFIX;
        if (str_starts_with($zoneId, $rowPrefix)) {
            return $this->selectSessionRow(substr($zoneId, strlen($rowPrefix)));
        }

        // P-CTX.e: the status bar's context meter. A click toggles the
        // Context pane's dock — the mouse twin of the `context:` menu row
        // and `/pane toggle context`. Answered with a DockPaneMsg rather
        // than a direct dock mutation so App::applyDockCommand stays the
        // one writer of the dock state.
        if ($zoneId === Renderer::CTXMETER_ZONE) {
            return [$this, static fn (): \SugarCraft\Code\App\DockPaneMsg
                => new \SugarCraft\Code\App\DockPaneMsg('toggle', Pane::Context->value)];
        }

        return [$this, null];
    }

    /**
     * Should this click be refused because something on screen is capturing
     * input, and what does refusing it look like — or null to let it through.
     *
     * WHY THIS EXISTS. {@see update()} dispatches a `MouseMsg` above its
     * `if (!$msg instanceof KeyMsg)` early return, and every capture guard in
     * that method sits BELOW that return. So all of them — the keybinding
     * reference, the permission prompt, the palette, the session picker —
     * were on the keyboard path only, and the mouse answered the same request
     * the opposite way. Measured at `995eb257`, the commit this is written
     * against, with a prompt up and idle (`pendingPermission` set,
     * `inFlight` false — the state {@see update()}'s own `AssistantMsg` arm
     * produces, argued at length above its `keyHelp` arm):
     *
     *   | click on      | did                            | keyboard, same state |
     *   |---------------|--------------------------------|----------------------|
     *   | `toolcall:*`  | toggled that tool body         | Ctrl+O: nothing      |
     *   | `pane:menu`   | opened the palette             | Ctrl+P: nothing      |
     *   | `pane:agents` | ran `/agents`, +2 history rows | Ctrl+A: nothing      |
     *   | `tab:<id>`    | switched session, prompt still up | Ctrl+Tab: nothing |
     *
     * The last row's keyboard counterpart is `Ctrl+Tab`, not the `Ctrl+R` two
     * revisions of this table said: {@see Commands\KeyBindingRegistry} binds
     * `Ctrl+R` to "Open the session picker" and `Ctrl+Tab` to "Switch to the
     * next session", and switching is what a tab click asks for (the same
     * name {@see selectSessionTab()}'s own comment uses). The row's ANSWER
     * was right either way — `Ctrl+Tab` under an idle prompt is refused by
     * the `$pendingPermission` arm exactly as `Ctrl+R` is — but it was
     * attached to the wrong key.
     *
     * NOT a permission bypass, and the accurate finding is sharper than that
     * label: no zone that survives the prompt reaches
     * {@see handlePermissionKey()} or the deferred resolution, so a click can
     * neither grant, deny nor dismiss it. What it could do is mutate the
     * transcript, the session and the overlay state underneath a modal that
     * is advertised as owning the screen.
     *
     * THE ORDER OF THE ARMS BELOW IS {@see update()}'S OWN ORDER, and that is
     * the whole of why `inFlight` is checked between the modals and the
     * overlays rather than after them. On the keyboard the reference and the
     * prompt are tested ABOVE the mid-turn block, and the mid-turn block is
     * tested ABOVE the palette and the picker — so mid-turn, with the palette
     * open, `Ctrl+Tab` does NOT reach {@see handlePaletteKey()}: it is
     * refused by {@see refuseWhileInFlight()}, visibly, and the palette is
     * closed by the notice. A first revision of this method put the overlay
     * arm first, and measured against that revision the two devices diverged
     * again in a quieter way: `Ctrl+Tab` wrote a line and closed the palette
     * while the `tab:` click under the same palette did nothing at all and
     * said nothing. Refusing is not the same as agreeing; this commit's own
     * standard is that a refusal the keyboard makes VISIBLY is made visibly
     * by the mouse too.
     *
     * WHICH STATES, and why each one:
     *
     *   * {@see $keyHelp} and {@see $pendingPermission} are full modals that
     *     mark no zones of their own, so nothing may dispatch. (`toolcall:`
     *     zones do not survive the reference — it replaces the transcript —
     *     but `pane:menu` does, and clicking it opened the palette.)
     *   * `inFlight` is NOT a capture state, on either device: {@see update()}
     *     refuses three named keys mid-turn and lets everything else run. The
     *     two gestures that reach a turn-starting or session-changing arm
     *     ({@see selectSessionTab()}, {@see selectPane()}'s Agents arm) are
     *     let THROUGH this guard on purpose, so they reach their own site and
     *     are refused there with the keyboard's own notice —
     *     {@see midTurnRefusalOfItsOwn()} names them, and its docblock says
     *     why it is a list rather than a rule.
     *   * {@see $sessionPicker} owns zones since E744 WS4 — the
     *     `session-row:<n>` family marking the rows it paints. Those stay
     *     live like the palette's; every OTHER zone visible while the picker
     *     is up belongs to the frame behind it and is refused, same as the
     *     full modals.
     *   * {@see $palette} is the one overlay that owns zones —
     *     `picker-item:<n>`, which §8 E6 exists to make clickable. Those stay
     *     live; everything else is background and is refused. Mid-turn a row
     *     is still live and still refused per-row, by the same method Enter
     *     reaches ({@see selectPaletteItem()} →
     *     {@see runSelectedPaletteActionWhileInFlight()}).
     *
     * WHAT COUNTS AS ONE OF THE PALETTE'S OWN ROWS is
     * {@see paletteRowIndex()}, not a bare `str_starts_with()` on the
     * prefix, and the difference is measured rather than stylistic. With the
     * bare prefix test, two WIDENING mutations of it survived the whole
     * suite: `'picker-item:'` → `'p'` (which let every `pane:` zone through
     * the guard — behavioural: a `pane:agents` click under an open palette
     * then ran `/agents`) and `'picker-item:'` → `'picker-item'`, which
     * nothing pinned at all because no zone id distinguishes the two today.
     * Deriving the answer from the LIVE {@see PaletteState} instead — the id
     * must be the prefix followed by digits that name a row the palette
     * actually has — makes both of those refuse a real palette click and die
     * on the spot, rather than leaving them merely unobserved.
     *
     * THE WHEEL IS NOT ROUTED THROUGH HERE, by decision and by measurement.
     * Reading the transcript while deciding how to answer a prompt is
     * legitimate, and refusing it would create a NEW divergence rather than
     * close one: `PageUp`/`PageDown` ({@see update()}'s arm, above the
     * prompt/palette/picker guards and below the reference's) already scroll
     * in every one of these states, and {@see scrollTranscript()} already
     * redirects the wheel onto the reference when that is what is up. Driven
     * under a live prompt at `995eb257`: a wheel notch moved `scrollOffset`
     * 0 → 3 ({@see SCROLL_WHEEL_LINES}) and `PageUp` moved it 0 → 1 (a page
     * clamped by {@see Renderer::maxScrollOffset()}). Different distances,
     * same answer — which is the property this method is about.
     *
     * WHY AT THE DISPATCH POINT AND NOT AT THE TOP OF {@see handleMouse()}.
     * Two reasons, one of them measured. The zone id is what decides the
     * palette case, and it does not exist until the tracker has resolved a
     * pair — a guard above that could only be all-or-nothing and would take
     * §8 E6's clickable palette rows down with it. And the press/release
     * tracker is STATIC state that outlives any modal: driven at `995eb257`,
     * a press with no matching release still pairs with an arbitrarily later
     * one (press on `pane:menu`, skip the release entirely, release again →
     * the palette opens). A guard that returned before {@see clickTracker()}
     * saw the release would leave that press armed for the whole life of the
     * prompt and fire it the moment the user answered. Refusing after the
     * pair resolves consumes the gesture and throws it away.
     *
     * THAT ARGUMENT IS ABOUT ONE DIRECTION OF THE GESTURE, and the mirror of
     * it — press UNDER the capture, release after it clears — is closed at
     * the press instead, by {@see handleMouse()} handing the tracker a null
     * press zone for a press this method would refuse. It has to be closed
     * somewhere: before that line, press `pane:menu` under a live prompt,
     * answer the prompt, release, and the palette opened — the same
     * fire-the-moment-they-answered behaviour the paragraph above rejects a
     * top-of-method guard for. Both halves are pinned:
     * {@see \SugarCraft\Code\Tests\MouseModalGuardTest::testAPressInterruptedByAPromptCannotFireOnceThePromptIsGone()}
     * for press-outside/release-under, and
     * `MouseModalGuardTest::testThePressMadeUnderAPromptIsGoneOnceThePromptIs()`
     * for press-under/release-after. Each direction is killed by exactly the
     * one test written for it: measured, removing the press-side line reds only
     * the second, and removing the dispatch-side call reds only the first.
     *
     * ONE GESTURE THE KEYBOARD CANNOT MAKE SURVIVES BOTH HALVES, and it is
     * recorded rather than closed. `inFlight` is not a capture, so a press on
     * `tab:<id>` mid-turn is NOT nulled at the press — it is let past by
     * {@see midTurnRefusalOfItsOwn()} so that its own dispatch site can refuse
     * it with the keyboard's notice. If the turn then SETTLES before the
     * button comes up, the release resolves against an idle model and the
     * session switches, silently. Driven: pressed mid-turn on the other tab,
     * `inFlight` cleared, released — `currentSessionId` moves and `+0` history
     * rows; the identical gesture completed wholly mid-turn is refused with
     * `+1`. Both ENDPOINTS evaluate correctly — this is not a stale answer,
     * it is two correct answers to a gesture that spanned a state change — and
     * the keyboard has no analogue, because a key is consumed the instant it
     * arrives. Left alone deliberately: a click is only made on the release,
     * the state at the release is idle, and refusing it would mean refusing a
     * legal request because of a condition that has since gone away. The
     * comparable prompt case is NOT left alone, and the asymmetry is the
     * point — a prompt is a capture, so its press is nulled and the gesture is
     * consumed.
     *
     * @param string $zoneId the id of the zone the completed click landed in
     *
     * @return array{0:self,1:?\Closure}|null null when the click may proceed
     */
    private function refuseMouseDispatch(string $zoneId): ?array
    {
        if ($this->keyHelp !== null || $this->flow->pendingPermission !== null) {
            return [$this, null];
        }

        if ($this->inFlight && $this->midTurnRefusalOfItsOwn($zoneId)) {
            return null;
        }

        if ($this->sessionPicker !== null && $this->sessionRowIndex($zoneId) === null) {
            return [$this, null];
        }

        if ($this->palette !== null && $this->paletteRowIndex($zoneId) === null) {
            return [$this, null];
        }

        return null;
    }

    /**
     * Does this zone reach a dispatch site that refuses it mid-turn ITSELF,
     * with the keyboard's own notice — the reason {@see refuseMouseDispatch()}
     * lets it past an open overlay instead of swallowing it there.
     *
     * ENUMERATED, not derived, for the same reason {@see refuseWhileInFlight()}
     * enumerates its three keys: the property is "this site writes a mid-turn
     * refusal", which is a fact about the site's body, and a prefix rule that
     * guessed at it would go quietly wrong the moment a site's answer changed.
     * The two members are {@see selectSessionTab()} (Ctrl+Tab's own
     * `refuseInFlightAction('Switch session')`) and {@see selectPane()}'s
     * `Agents` arm. `pane:menu` is deliberately absent — Ctrl+P opens the
     * palette mid-turn, so the click that asks for the same thing does too —
     * and so is `toolcall:`, because Ctrl+O expands mid-turn.
     *
     * A palette ROW is absent too, and for a different reason: it is already
     * let through by the palette arm below, and {@see selectPaletteItem()}
     * refuses it mid-turn through the same method Enter reaches.
     *
     * THE `tab:` MEMBER IS A PREFIX, AND ONE ID UNDER IT DOES NOT WRITE A
     * NOTICE — the tab that is already current, which {@see selectSessionTab()}
     * answers with a silent no-op at its first validity gate. So the property
     * this method's name states holds of the ZONE FAMILY, not of every id in
     * it, and the exception is by decision rather than by oversight: see that
     * method for why a click naming the session you are already in is not a
     * request to change sessions, and {@see refuseInFlightAction()} for why an
     * answer that writes nothing leaves the overlay alone.
     *
     * Narrowing the member to "a tab that is not the current one" would move
     * that decision into the delivery whitelist and change no observable
     * answer, which is arithmetic and not a hope: both branches end in
     * `[$this, null]`. Refused here, the click is answered by the palette or
     * picker arm below, or falls out of the guard and is answered by
     * {@see selectSessionTab()}'s first validity gate; let through, it is
     * answered by that gate in every case. Driven mid-turn in all three
     * overlay states (none / palette / picker): `+0` history rows, session
     * unchanged, no `Cmd`, and the overlay exactly as it was, all three times.
     * Measured as a mutation over the mouse/palette domain, the narrowing reds
     * nothing. It is not adopted because the enumeration reads better as a
     * statement about which SITES carry the keyboard's notice than as one with
     * a per-id exception folded into it.
     */
    private function midTurnRefusalOfItsOwn(string $zoneId): bool
    {
        return str_starts_with($zoneId, Renderer::SESSION_TAB_ZONE_PREFIX)
            || $zoneId === Renderer::PANE_ZONE_PREFIX . Pane::Agents->value;
    }

    /**
     * The row of the LIVE palette this zone id names, or null when it names
     * none — which is the whitelist {@see refuseMouseDispatch()} keys on.
     *
     * Derived from {@see $palette} rather than trusted from the id, because a
     * whitelist that is only a string prefix is a whitelist two widening
     * mutations pass unobserved (measured: see that method's docblock). Three
     * things must hold: the id starts with the prefix, the rest of it is
     * digits and nothing else, and those digits name a row the palette
     * currently HAS.
     *
     * WHAT EACH ONE IS WORTH, measured at this commit rather than asserted,
     * over the MOUSE/PALETTE DOMAIN — named by the rule that produces it, not
     * by its size, since the size is the half that goes stale without saying
     * so. The rule is `grep -rl` over `tests/` for `MouseClickMsg`,
     * `PALETTE_ITEM_ZONE_PREFIX`, `paletteMatches` or `PaletteState`; at this
     * commit it yields SIXTEEN files running 867 tests / 51906 assertions
     * green. Re-derive it rather than trusting those numbers. NOTE what it is
     * NOT: it is not every mouse-capable test file — `ChatScrollTest`,
     * `MouseWiringTest` and `Integration/FeatWiringReachabilityTest` deliver
     * mouse events and are outside it, which is why {@see selectPane()}'s
     * measurement unions them in rather than reusing this domain. A previous revision said "each one
     * kills a mutation on its own"; driven, each of the three individually
     * red NOTHING, and the sentence could not have been right anyway — three
     * checks, two widening mutations. What is true is narrower and is stated
     * per check:
     *
     *   * THE PREFIX is load-bearing on its own, and is pinned. Drop it and
     *     the two remaining checks read `substr($id, 12)` off whatever
     *     arrives, so any id whose twelfth character onwards is a short run
     *     of digits is delivered as a palette row. The tails of the two other
     *     zone families are not this code's to promise anything about — `tab:`
     *     is 4 characters, so offset 12 lands in the middle of a SESSION id,
     *     and `toolcall:` is 9, so it lands in the middle of a PROVIDER's
     *     tool-call id. Measured with ids chosen to collide (`alphabet0`,
     *     `abc0`): the mutation switches session and expands a tool body
     *     THROUGH an open palette. Reds
     *     {@see \SugarCraft\Code\Tests\MouseModalGuardTest::testAnIdThatCollidesWithTheRowWhitelistPastItsPrefixIsStillSwallowed()},
     *     which exists because the suite's ordinary fixtures (`session-a`,
     *     `call_1`) land on non-digits there and cannot see it.
     *   * THE DIGIT TEST reds nothing alone, and neither does
     *     {@see selectPaletteItem()}'s copy of it — `(int) 'abc'` is 0, so
     *     whichever one survives still refuses. Dropping BOTH reds
     *     `PaletteClickTest::testANonNumericPickerIndexRunsNothing()`. The
     *     pair is load-bearing; neither half is, and this is reported as a
     *     survivor rather than claimed as a kill. Nothing produces such an id
     *     today by construction — {@see Renderer::recordPaletteItemZones()}
     *     builds `(string) $id` from an `array<int, string>` key — which is
     *     why the test hand-marks one.
     *   * THE RANGE TEST is the same shape: nothing alone, and dropping it
     *     together with {@see selectPaletteItem()}'s range check reds
     *     `PaletteClickTest::testAnOutOfRangePickerIndexRunsNothing()`.
     *
     * WHY THE RANGE QUESTION IS ASKED TWICE, corrected. A previous revision
     * said the second asking "survives a frame the first never saw"; that is
     * false and is withdrawn in place, for the same arithmetic that withdrew
     * the identical claim at {@see selectPane()}: {@see handleMouse()} calls
     * {@see refuseMouseDispatch()} and then the dispatch arm on the SAME
     * `$this`, so both reads see the same live {@see $palette} and there is no
     * frame one of them can see and the other cannot. They are kept as two
     * because they answer to two different callers — this one is the delivery
     * whitelist and has to say "not a palette row" about ids that are not
     * palette rows at all, while the other is a dispatch method that must be
     * safe for any caller, including one arriving with the palette already
     * closed. Their measured value is joint, not individual, and it is
     * reported that way above rather than as two kills.
     */
    private function paletteRowIndex(string $zoneId): ?int
    {
        if ($this->palette === null) {
            return null;
        }

        $prefix = Renderer::PALETTE_ITEM_ZONE_PREFIX;
        if (!str_starts_with($zoneId, $prefix)) {
            return null;
        }

        $index = substr($zoneId, strlen($prefix));
        if (preg_match('/\A\d+\z/', $index) !== 1) {
            return null;
        }

        return (int) $index < count($this->paletteMatches()) ? (int) $index : null;
    }
}
