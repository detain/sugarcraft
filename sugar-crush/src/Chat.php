<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

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
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\SessionPicker;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Tools\ToolCall as EngineToolCall;
use SugarCraft\Crush\Commands\AgentsCommand;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\McpAuthCommand;
use SugarCraft\Crush\Commands\ShareCommand;
use SugarCraft\Crush\Palette\PaletteAction;
use SugarCraft\Crush\Palette\PaletteState;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Mouse\MouseEvent;
use SugarCraft\Mouse\Zone;
use SugarCraft\Mouse\ZoneClickTracker;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowEngineInterface;
use SugarCraft\Crush\Workflows\WorkflowLoadException;
use SugarCraft\Crush\Workflows\WorkflowNotFoundException;
use SugarCraft\Crush\Workflows\WorkflowNotRunningException;
use SugarCraft\Crush\Workflows\WorkflowResult;
use SugarCraft\Crush\Workflows\WorkflowStatus;
use SugarCraft\Crush\Context\ContextCompactor;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionStore;

/**
 * The chat shell, as a SugarCraft {@see Model}.
 *
 * Three pieces of state:
 *
 *   - `history`    — `list<Message>` accumulated so far
 *   - `inputBuf`   — the user's in-progress draft of the next turn
 *   - `inFlight`   — `true` while a backend call is in progress.
 *                    Input is suppressed and the renderer shows a
 *                    "thinking…" indicator.
 *
 * Sending: pressing Enter on a non-empty input pushes the
 * Message onto history, clears the buffer, sets `inFlight`,
 * and schedules a Cmd that calls `Backend::complete()` and
 * dispatches the result back as an {@see AssistantMsg}.
 *
 * The Backend is held privately and isn't part of equality —
 * tests use {@see Backend\EchoBackend}, prod uses whatever
 * adapter the user wires in {@see bin/sugarcrush}.
 *
 * **Tool Use:** Callbacks can be registered via `registerTool()`.
 * When an assistant message contains tool calls, they are executed
 * and the results are appended to history before the next backend
 * call continues.
 */
final class Chat implements Model
{
    private readonly Backend $backend;

    /** @var WorkflowEngineInterface|null Optional workflow engine for /workflow command */
    private readonly ?WorkflowEngineInterface $workflowEngine;

    /** @var ContextCompactor Context compactor for /compact command and automatic compaction */
    private readonly ContextCompactor $compactor;

    /** @var AgentManager|null Agent manager for /agents command */
    private ?AgentManager $agentManager = null;

    /** @var MemoryStore|null Memory store for /memory command */
    private ?MemoryStore $memoryStore = null;

    /** @var SessionStore|EnhancedSessionStore|null Session store for /branch and /rename commands */
    private SessionStore|EnhancedSessionStore|null $sessionStore = null;

    /** @var string|null ID of the currently active session */
    private ?string $currentSessionId = null;

    /**
     * Token budget used as the {@see ContextCompactor::shouldSendReminder()}
     * denominator. Mirrors the fixed 100,000-token proxy limit already used
     * by {@see shouldPromptIdleCompaction()} — so the 70% reminder tier
     * fires at ~70,000 estimated tokens, comfortably ahead of the 100,000
     * hard idle-compaction threshold.
     */
    private const REMINDER_TOKEN_LIMIT = 100000;

    /**
     * Wall-clock budget for {@see forkToolCalls()}'s forked children.
     * A tool call that never returns (e.g. a hung shell command) would
     * otherwise leave the parent blocked forever waiting on pcntl_waitpid();
     * past this deadline, stragglers are SIGKILLed and reported as timeouts.
     */
    private const PARALLEL_TOOL_TIMEOUT_SECONDS = 30;

    /**
     * Two Escape presses within this window while a request is in flight
     * abort it (see the Escape arm in {@see update()}). A single Escape
     * never quits the app any more - use /exit, Ctrl+C, or the palette's
     * Exit action for that.
     */
    private const DOUBLE_ESCAPE_WINDOW_SECONDS = 0.6;

    /**
     * One-shot prompt for the background title call. Deliberately terse:
     * opencode's title agent sends barely more than "Generate a title for
     * this conversation" and a cheap model does worse, not better, with an
     * elaborate system prompt.
     */
    private const TITLE_PROMPT = 'Generate a session title in 4-8 words summarising this conversation. Reply with the title only: one line, no quotes, no trailing punctuation.';

    /**
     * Longest auto-title we keep. Matches opencode's own 100-char cap; a
     * tab strip has nowhere to put more than that anyway.
     */
    private const TITLE_MAX_CHARS = 100;

    /**
     * How many palette rows the MRU list remembers. Small on purpose: the
     * bias is only meant to keep the handful of rows a user actually cycles
     * through near the top, not to permanently re-rank the whole palette.
     */
    private const PALETTE_MRU_LIMIT = 8;

    /**
     * Transcript lines moved per wheel notch (crush_feat.md §8 E4's literal
     * `$delta = ... ? -3 : 3`). Three keeps a notch's worth of context
     * overlapping between the old and new window instead of paging blind.
     */
    private const SCROLL_WHEEL_LINES = 3;

    /**
     * How far (Manhattan cells) the pointer may stray between press and
     * release and still count as a click rather than a text selection
     * (crush_feat.md §8 E8).
     *
     * One cell, not zero: a press and release one cell apart is a shaky
     * hand on a two-cell-wide tab, while a deliberate selection sweep
     * always crosses more ground than that. Zone bounds alone cannot make
     * this call — a tool-call row or a palette row is one zone spanning the
     * full width, so dragging across it to copy the text starts AND ends
     * inside the same zone and {@see ZoneClickTracker} happily calls it a
     * click.
     */
    private const CLICK_DRAG_TOLERANCE_CELLS = 1;

    /**
     * Reconciliation id of the background-session poll subscription
     * (crush_feat.md section 5 E4). Stable across rebuilds so `Program`
     * recognises the timer it already started instead of restarting it on
     * every update cycle.
     */
    private const BACKGROUND_POLL_SUBSCRIPTION = 'crush.background-poll';

    /**
     * How often the poll pump wakes (seconds).
     *
     * Two, per the spec sketch: `BackgroundSupervisor::HEARTBEAT_TIMEOUT_SECS`
     * is 15, so this is fast enough to report a stall promptly and slow
     * enough that a mostly-idle TUI is not repainting on a hot timer.
     */
    private const BACKGROUND_POLL_SECONDS = 2.0;

    /**
     * @param list<Message> $history
     * @param array<string, callable> $tools Map of tool name => callable(array $arguments): mixed
     * @param callable|null $onToolCall Optional callback called when tools are invoked
     */
    public function __construct(
        public readonly array $history = [],
        public readonly string $inputBuf = '',
        public readonly bool $inFlight = false,
        ?Backend $backend = null,
        private readonly bool $streaming = false,
        private readonly ?\Closure $onToken = null,
        private readonly array $tools = [],
        private readonly ?\Closure $onToolCall = null,
        private readonly ?\SugarCraft\Crush\Agents\AgentPoolConfig $agentPoolConfig = null,
        private readonly ?\SugarCraft\Crush\Agents\AgentWorkerPool $effectivePool = null,
        ?WorkflowEngineInterface $workflowEngine = null,
        ?AgentManager $agentManager = null,
        ?CompactorConfig $compactorConfig = null,
        ?MemoryStore $memoryStore = null,
        \SugarCraft\Crush\Session\SessionStore|\SugarCraft\Crush\Session\EnhancedSessionStore|null $sessionStore = null,
        ?string $currentSessionId = null,
        private readonly ?\DateTimeImmutable $lastActivityAt = null,
        /** Highlighted row in the "/" popup (see {@see slashMenuMatches()}). */
        private readonly int $slashMenuIndex = 0,
        /** Active {@see Theme} name (see {@see theme()}); resolved lazily, not stored as an object. */
        private readonly string $themeName = 'dark',
        /** Ctrl+P command palette state; null when closed. */
        private readonly ?PaletteState $palette = null,
        /**
         * Optional callable(string $key, string $value): void, fired when
         * the Switch Model/Switch Theme palette actions (or /theme) apply a
         * choice - the persistence side effect itself (writing to
         * ~/.sugar-crush/config.json) lives in Bootstrap::chat()'s wiring,
         * not here, so this stays a no-op by default for tests/embedders
         * that never call withOnConfigChange()/pass one to the constructor.
         */
        private readonly ?\Closure $onConfigChange = null,
        /**
         * Bumped by every submit()/beginToolCalls()/finishToolCalls() call that schedules a
         * backend Cmd; stamped onto that Cmd's eventual {@see AssistantMsg}
         * so a reply for a turn that was later aborted (see Escape-Escape
         * handling below) or superseded is recognisable as stale and
         * dropped in update() rather than appended after newer messages.
         */
        private readonly int $generation = 0,
        /**
         * Shared cancel flag for the turn currently in flight; null when
         * idle. See {@see \SugarCraft\Crush\Backend\CancellationToken}'s
         * docblock for why this can't be a normal immutable value-object
         * field - Escape-Escape needs to mutate the SAME instance the
         * already-scheduled Cmd captured.
         */
        private readonly ?CancellationToken $inFlightCancellation = null,
        /**
         * Wall-clock timestamp (microtime(true)) of the most recent
         * un-paired Escape press; null once consumed/expired. Drives the
         * double-Escape-to-abort window in update().
         */
        private readonly ?float $lastEscapeAt = null,
        /**
         * Real terminal dimensions, sourced from {@see WindowSizeMsg} - the
         * one size candy-core's Program actually dispatches at startup AND
         * again on every SIGWINCH resize (see Program::installSignalHandlers()).
         * Null until the first WindowSizeMsg arrives (or for a Chat built
         * directly in a test, never); {@see rows()}/{@see cols()} fall back
         * to {@see TuiRenderer::getTerminalSize()}'s own detection in that
         * case. Renderer MUST read this instead of querying terminal size
         * itself - a second, independent, statically-cached size source
         * (which is what caused #1403's fix to not fully land: it clipped
         * to a size that could silently disagree with the real terminal, or
         * never picked up a live resize).
         */
        private readonly ?int $rows = null,
        private readonly ?int $cols = null,
        /**
         * The candy-mosaic probe-once capability instance (W1.G2/E2 - see
         * {@see ToolResult::mosaic()}'s docblock), exposed here per
         * crush_feat.md section 9's literal `new Chat(..., mosaic: $mosaic)`
         * so a future renderer (E3) can read the SAME detected protocol an
         * image-bearing {@see ToolResult} was produced against instead of
         * re-probing the TTY independently. {@see
         * \SugarCraft\Crush\Cli\Bootstrap::chat()} passes {@see
         * ToolResult::mosaic()} here; null for a Chat built directly in a
         * test that never needs it.
         */
        private readonly ?\SugarCraft\Mosaic\Mosaic $mosaic = null,
        /**
         * Hook chain gating this Chat's OWN ({@see registerTool()}) tool
         * calls, so `PreToolUse`/`PostToolUse` fire for a call no matter
         * which of the two pipelines crush_feat.md §1 D describes dispatched
         * it. Before this, {@see Runtime}'s engine pipeline ran every call
         * through {@see HookManager} while the Chat-native pipeline called
         * the registered closure with zero gating - so the same `rm -rf`
         * argument was denied by {@see \SugarCraft\Crush\Hooks\BuiltIn\ConfirmRemoveHook}
         * on one path and executed on the other.
         *
         * Null (the default) keeps the pre-gating behaviour for tests and
         * embedders that never wire hooks; {@see \SugarCraft\Crush\Cli\Bootstrap::chat()}
         * passes the same built-in guard chain (`Bootstrap::hooks()`) it
         * hands the engine backend.
         */
        private readonly ?HookManager $hooks = null,
        /**
         * Name of the current session, once it has one — set by the user's
         * `/rename`, or by the background auto-title call (see
         * {@see scheduleTitleGeneration()}). Non-null is the "already
         * named, don't auto-title" latch; the store's `name` column is the
         * durable copy, this is the in-memory mirror the UI reads.
         */
        private readonly ?string $currentSessionName = null,
        /**
         * Dedicated cheap/small-model Backend for the one-shot session
         * title call. opencode's #20269 was a main-model parameter leaking
         * into the small-model title request through a shared request
         * builder and silently breaking titling; keeping the title call on
         * its OWN Backend instance means it can never inherit the main
         * conversation's model or params. Null falls back to the main
         * backend as a last resort (same order opencode uses).
         */
        private readonly ?Backend $titleBackend = null,
        /**
         * The permission prompt currently blocking this turn, or null when
         * nothing is waiting on the user (crush_feat.md §1 E2). Set by
         * {@see requestPermission()}, cleared by {@see answerPermission()};
         * {@see Renderer} reads it through {@see pendingPermission()}.
         */
        private readonly ?PermissionRequestMsg $pendingPermission = null,
        /**
         * The {@see Deferred} whose promise is the Cmd that keeps the turn
         * suspended while $pendingPermission is showing. Answering resolves
         * it, which is the whole "block on a UI decision" mechanism - the
         * same Deferred object is shared by every `mutate()` clone in
         * between, so the Chat instance that receives the reply settles the
         * promise the Chat instance that raised the prompt handed out.
         */
        private readonly ?Deferred $permissionDeferred = null,
        /**
         * The gated batch parked while $pendingPermission is showing, in
         * {@see gateToolCall()}'s return shape.
         *
         * Carried across the pause rather than re-gated on resume so the
         * `PreToolUse` chain runs EXACTLY once per tool call: re-gating would
         * fire every hook's side effects (AuditHook's log line, accumulated
         * state) a second time, and would re-ask the question the user just
         * answered.
         *
         * @var list<array{0: ToolCall, 1: ?ToolResult, 2: ?HookContext, 3: ?\SugarCraft\Crush\Hooks\HookResult}>
         */
        private readonly array $pendingPermissionJobs = [],
        /**
         * Tool names the user answered {@see PermissionReply::Always} for,
         * as `[name => true]` - opencode's `approved: Rule[]`, per session
         * and in memory only. Consulted by {@see gateToolCall()}, which
         * turns an ASK for a granted tool straight into permission without
         * prompting again.
         *
         * @var array<string, bool>
         */
        private readonly array $permissionGrants = [],
        /**
         * Tool-call ids whose output the user has explicitly expanded, keyed
         * by id ({@see ToolResult::$id}), value always true - a collapsed
         * call is simply absent rather than stored as false, so the map stays
         * the size of what the user actually opened rather than growing one
         * entry per tool call for the life of the session.
         *
         * {@see Renderer::renderToolResults()} hides a successful call's body
         * unless its id is in here (crush_feat.md §1 E5's "hide-on-success by
         * default"); Ctrl+O toggles it (see {@see toggleToolOutput()}).
         *
         * @var array<string, bool>
         */
        private readonly array $expanded = [],
        /**
         * Most-recently-used Ctrl+P palette rows, most recent FIRST, capped
         * at {@see PALETTE_MRU_LIMIT} (crush_feat.md §4 E7). Only consulted
         * by the empty-query root list ({@see paletteMatchResults()}), which
         * floats recent rows to the top of their category - a typed query
         * stays purely relevance-ranked so the matcher's score, not history,
         * decides what the user is pointing at.
         *
         * In-memory for the life of the process: cross-session persistence
         * would have to be written/read by Bootstrap the way `themeName` is,
         * which is outside this step's file scope; the constructor param is
         * how a seeded list would arrive once that lands.
         *
         * @var list<string>
         */
        private readonly array $paletteMru = [],
        /**
         * How many lines the transcript is scrolled back from the newest
         * one (crush_feat.md §8 E4). 0 pins the view to the bottom, which
         * is what every non-wheel path leaves it at.
         *
         * Measured from the BOTTOM rather than the top because that is the
         * end {@see Renderer::render()} anchors to: it clips a too-tall
         * frame to its tail so the input box and newest turn stay visible,
         * and this offset just moves that window's start earlier.
         *
         * Clamped at both ends, in the two places that can know each bound:
         * {@see withScrollOffset()} refuses to go below 0, and the upper
         * bound is the painted frame's own overflow ({@see
         * Renderer::maxScrollOffset()}), applied when a wheel event is
         * handled and again by the renderer against the frame it is
         * actually building.
         */
        private readonly int $scrollOffset = 0,
        /**
         * Supervisor the `/bg` and `/fork` commands dispatch onto
         * (crush_feat.md section 5 E3). Before this, `BackgroundSupervisor`
         * had no caller anywhere in the codebase: the fork+daemonize,
         * heartbeat and reconnect machinery was fully built and fully
         * unit-tested but unreachable from chat, so a user could not
         * background a task at all.
         *
         * Null (the default) keeps every existing embedder/test working and
         * makes `/bg` answer with the same "<thing> not configured" line the
         * other optional collaborators on this class use.
         */
        private readonly ?\SugarCraft\Crush\Sessions\BackgroundSupervisor $backgroundSupervisor = null,
        /**
         * Last status this Chat reported for each background session, keyed
         * by session id (crush_feat.md section 5 E4).
         *
         * The poll pump is edge-triggered, not level-triggered: a session
         * sitting at `running` for ten minutes must not append a transcript
         * line every two seconds. This map is the "last-known" side of that
         * diff, and lives on the model rather than on the supervisor because
         * it is a property of what the USER has already been told, not of
         * the session itself - a second embedder watching the same
         * supervisor has its own notion of what it has shown.
         *
         * @var array<string, string> session id => BackgroundSessionStatus::value
         */
        private readonly array $backgroundStatuses = [],
    ) {
        $this->backend = $backend ?? new Backend\EchoBackend();
        $this->workflowEngine = $workflowEngine;
        $this->agentManager = $agentManager;
        $this->compactor = new ContextCompactor($compactorConfig ?? CompactorConfig::new());
        $this->memoryStore = $memoryStore;
        $this->sessionStore = $sessionStore;
        $this->currentSessionId = $currentSessionId;
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof AssistantMsg) {
            // A reply for a turn that was aborted (double-Escape) or
            // otherwise superseded arrives after inFlight/generation have
            // already moved on - drop it rather than appending it after
            // whatever the user has done since. See AssistantMsg's
            // docblock and the Escape arm below.
            if ($msg->generation !== null && $msg->generation !== $this->generation) {
                return [$this, null];
            }

            $message = $msg->message;

            // Check if the message has tool calls to execute
            if ($message->toolCalls !== [] && $this->tools !== []) {
                return $this->beginToolCalls($message);
            }

            return [$this->mutate([
                'history' => [...$this->history, $message],
                'inFlight' => false,
                'inFlightCancellation' => null,
            ]), null];
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
        if ($msg instanceof BackendToolEventsMsg) {
            return $this->applyBackendToolEvent($msg);
        }
        if ($msg instanceof SessionTitledMsg) {
            // The title call is fire-and-forget: by the time it lands the
            // user may have switched sessions, and a title belonging to a
            // session we are no longer on must not overwrite this one's.
            if ($msg->sessionId !== $this->currentSessionId) {
                return [$this, null];
            }
            $title = self::sanitizeSessionTitle($msg->title);
            if ($title === '') {
                return [$this, null];
            }
            return [$this->mutate(['currentSessionName' => $title]), null];
        }
        if ($msg instanceof BackgroundSessionSpawnedMsg) {
            // Unlike the title call above this is NOT session-scoped: the
            // user asked for it out loud with a slash command, so the answer
            // belongs in whatever transcript is in front of them now.
            $notice = $msg->error !== null
                ? "Could not start background session '{$msg->name}': {$msg->error}"
                : ($msg->command === '/fork'
                    ? "Forked into background session {$msg->sessionId} ('{$msg->name}') — use /agents to check status."
                    : "Backgrounded as {$msg->sessionId} ('{$msg->name}') — use /agents to check status.");

            return [$this->mutate(['history' => [...$this->history, Message::assistant($notice)]]), null];
        }
        if ($msg instanceof BackgroundTickMsg) {
            return $this->pumpBackgroundSessions();
        }
        if ($msg instanceof WindowSizeMsg) {
            // The one authoritative size - see the constructor docblock on
            // $rows/$cols for why Renderer must read these instead of
            // querying terminal size itself.
            return [$this->mutate(['rows' => $msg->rows, 'cols' => $msg->cols]), null];
        }
        if ($msg instanceof MouseMsg) {
            return $this->handleMouse($msg);
        }
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === "\x03" /* ^C */) {
            return [$this, Cmd::quit()];
        }
        // A blocking permission prompt owns the keyboard while it is up, and
        // is checked ahead of BOTH the Escape arm and the inFlight
        // blanket-swallow below: the turn is inFlight by definition while
        // waiting on this answer, so without this arm every reply keystroke
        // would be discarded and the prompt could never be answered, and
        // Escape would abort the whole turn rather than refuse this one call.
        if ($this->pendingPermission !== null) {
            return $this->handlePermissionKey($msg);
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
        if ($msg->type === KeyType::Escape && $this->palette === null) {
            if (!$this->inFlight) {
                return [$this->mutate(['lastEscapeAt' => null]), null];
            }

            $now = microtime(true);
            $isSecondPress = $this->lastEscapeAt !== null
                && ($now - $this->lastEscapeAt) <= self::DOUBLE_ESCAPE_WINDOW_SECONDS;

            if (!$isSecondPress) {
                return [$this->mutate(['lastEscapeAt' => $now]), null];
            }

            $this->inFlightCancellation?->cancel();

            return [$this->mutate([
                'inFlight' => false,
                'inFlightCancellation' => null,
                'lastEscapeAt' => null,
                'generation' => $this->generation + 1,
                'history' => [...$this->history, Message::system('_Request cancelled._')],
            ]), null];
        }
        if ($this->inFlight) {
            // Ignore keystrokes while waiting for the backend
            // (avoids the user racing ahead and queuing another
            // turn into a half-formed history).
            return [$this, null];
        }

        // While the Ctrl+P command palette is open, every keystroke feeds
        // its own query/navigation/dispatch handling instead of inputBuf/the
        // "/" popup - see handlePaletteKey()'s docblock.
        if ($this->palette !== null) {
            return $this->handlePaletteKey($msg);
        }

        return match (true) {
            // Alt/Shift/Ctrl+Enter insert a newline instead of submitting.
            // Alt+Enter is the reliable one across plain terminals (ESC+CR,
            // now decoded correctly by InputReader - see candy-core's
            // Alt-prefixed-key fix); Shift/Ctrl+Enter only arrive
            // distinguishably on terminals that report the Kitty keyboard
            // protocol unprompted, but cost nothing to also honor.
            $msg->type === KeyType::Enter && ($msg->alt || $msg->shift || $msg->ctrl)
                => [$this->withInputBuf($this->inputBuf . "\n"), null],
            $msg->type === KeyType::Enter
                => $this->slashMenuShouldIntercept()
                    ? $this->completeSlashMenuSelection()
                    : $this->submit(),
            // Up/Down navigate the "/" popup while it's showing (see
            // slashMenuMatches()); otherwise fall through to the default
            // no-op arm below, unchanged from before this popup existed.
            $msg->type === KeyType::Up && $this->slashMenuMatches() !== []
                => [$this->moveSlashMenuSelection(-1), null],
            $msg->type === KeyType::Down && $this->slashMenuMatches() !== []
                => [$this->moveSlashMenuSelection(1), null],
            // Shell-history-style recall: Up on an empty input box (and no
            // "/" popup showing - the arm above already claimed that case)
            // fills inputBuf with the last message the user actually sent.
            $msg->type === KeyType::Up && $this->inputBuf === ''
                => [$this->withInputBuf($this->lastUserMessageContent()), null],
            // Ctrl+P opens the command palette. Checked before the generic
            // Char arm below, or the literal "p" would be typed into the
            // input buffer instead - same reasoning as Ctrl+A just below.
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'p'
                => [$this->mutate(['palette' => PaletteState::root()]), null],
            // Ctrl+O expands/collapses the most recent tool call's output
            // (crush_feat.md §1 E5) - successful tool bodies are hidden by
            // default, and this is the only way to see one. Checked before
            // the generic Char arm below, or the literal "o" would be typed
            // into the input buffer instead - same reasoning as Ctrl+P above.
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'o'
                => $this->toggleLatestToolOutput(),
            // R20: Ctrl+A re-runs the exact same /agents dispatch submit()
            // already uses for typed input (handleAgentsCommand()), giving
            // KeyboardHandler's Ctrl+A shortcut (Pane::Agents in the
            // disconnected App/Tui system) a real, reachable equivalent on
            // this, the live, Chat path. Must be checked before the generic
            // Char arm below, or the literal "a" would be typed into the
            // input buffer instead.
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'a'
                => $this->withInputBuf('/agents')->submit(),
            // Word-delete: Ctrl+W (the usual terminal-wide convention) or a
            // correctly alt-flagged Backspace (see candy-core's
            // Alt-prefixed-key fix - before it, Alt+Backspace mis-decoded
            // as a bare Escape and quit the app instead of reaching here at
            // all). Must be checked before the plain Backspace arm below.
            ($msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'w')
                || ($msg->type === KeyType::Backspace && $msg->alt)
                => [$this->withInputBuf(self::dropLastWord($this->inputBuf)), null],
            // R20: Ctrl+Tab / Ctrl+Shift+Tab cycle the active session
            // through the real SessionStore listing — see
            // cycleSessionTab()'s docblock for reachability caveats and how
            // this relates to Tui\SessionTabs.
            $msg->type === KeyType::Tab && $msg->ctrl
                => $this->cycleSessionTab($msg->shift ? -1 : 1),
            $msg->type === KeyType::Char
                => [$this->withInputBuf($this->inputBuf . $msg->rune), null],
            $msg->type === KeyType::Space
                => [$this->withInputBuf($this->inputBuf . ' '), null],
            $msg->type === KeyType::Backspace
                => [$this->withInputBuf(self::dropLast($this->inputBuf)), null],
            default => [$this, null],
        };
    }

    /**
     * Cycle the active session forward ($direction=1) or backward
     * ($direction=-1) through {@see SessionStore::listSessions()}'s real,
     * persisted row order — the same order {@see Renderer}'s session tab
     * strip displays, so switching here and switching via the rendered
     * strip stay in sync. A no-op when there is no session store, no
     * current session, or fewer than 2 sessions to switch between.
     *
     * Mirrors {@see \SugarCraft\Crush\Tui\SessionTabs}'s
     * `cycleForward()`/`cycleBackward()` wraparound semantics and its
     * `CTRL_TAB`/`CTRL_SHIFT_TAB` key bindings, without persisting a
     * `SessionTabs` instance on `Chat` itself — adding one would widen
     * Chat's already-large immutable constructor/`mutate()` surface well
     * beyond this item's "KeyMsg dispatch site only" scope for this file.
     * `currentSessionId` is already a real, mutate()-able field
     * ({@see withCurrentSessionId()}), so no constructor change was needed.
     *
     * Reachability note: most terminals send Ctrl+Tab as a `CSI 1;5I`
     * sequence that candy-core's `InputReader` does not yet decode into a
     * `KeyMsg` (a separate, pre-existing gap in a file outside this item's
     * scope — Ctrl+Tab isn't in the generic modifier-key CSI table there).
     * That decoder gap is NOT the only thing standing between this handler
     * and a real `bin/sugarcrush` user, though — even a `KeyMsg` source that
     * bypasses it entirely (Kitty-protocol terminals, scripted input) hits
     * the early-return above, because `Cli\Bootstrap::chat()` (the live
     * construction path) never passes a `currentSessionId`, `init()` returns
     * `null` (no startup Cmd selects or creates one either), and nothing
     * else in the live path calls {@see withCurrentSessionId()} except this
     * method and `handleBranchCommand()` — which itself requires an
     * existing `currentSessionId` to fork from, a circular, pre-existing
     * gap of its own. So a freshly-launched `bin/sugarcrush` session has
     * `currentSessionId === null` for its entire lifetime unless the user
     * first runs `/branch` against a session id it has no way to have
     * started with. Concretely, this method is exercised and correct
     * against any Chat that already carries a non-null `currentSessionId`
     * (as every test below does), but is a guaranteed no-op for a Chat
     * built the way `Bootstrap::chat()` actually builds it. Wiring an
     * initial `currentSessionId` into `Bootstrap::chat()`/`init()` (e.g.
     * selecting the most-recent row from `SessionStore::listSessions()`, or
     * creating one) would close this, but touches `src/Cli/Bootstrap.php`,
     * which is outside this item's declared file scope — left as an
     * explicit follow-up rather than fixed here.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function cycleSessionTab(int $direction): array
    {
        if ($this->sessionStore === null || $this->currentSessionId === null) {
            return [$this, null];
        }

        $ids = array_column($this->sessionStore->listSessions(), 'id');
        $count = count($ids);
        if ($count < 2) {
            return [$this, null];
        }

        $currentIndex = array_search($this->currentSessionId, $ids, true);
        if ($currentIndex === false) {
            return [$this, null];
        }

        $nextIndex = ($currentIndex + $direction + $count) % $count;

        return [$this->withCurrentSessionId($ids[$nextIndex]), null];
    }

    /**
     * Handle tool calls in an assistant message: show a "running" placeholder
     * for each one IMMEDIATELY (visible on the very next render, before any
     * of them execute), fork all of them right away (see {@see forkToolCalls()}),
     * and schedule a Cmd that waits for them off the render loop (see
     * {@see waitForToolChildrenAsync()}) - the same non-blocking-socket
     * rationale as {@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()},
     * applied to tool execution instead of the provider call. Finishing is
     * handled by {@see finishToolCalls()} once the resulting
     * {@see ToolResultsMsg} arrives.
     *
     * The `PreToolUse` chain runs FIRST, over the whole batch, before any
     * placeholder is appended or any child forked (crush_feat.md §1 E2): a
     * hook may answer {@see \SugarCraft\Crush\Hooks\HookResult::ask()}, in
     * which case nothing about this turn may proceed until the user decides,
     * and a "running" spinner for a call that has not been permitted yet
     * would be a lie. The gated batch is then handed to
     * {@see dispatchToolCalls()} - directly when nothing needs asking, or by
     * {@see answerPermission()} once the answer arrives - so each call is
     * gated exactly once either way.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function beginToolCalls(Message $message): array
    {
        $gated = array_map(
            fn(ToolCall $call): array => $this->gateToolCall($call),
            $message->toolCalls,
        );

        foreach ($gated as [$call, , , $ask]) {
            if ($ask !== null) {
                return $this->mutate(['pendingPermissionJobs' => $gated])->requestPermission(
                    new PermissionRequestMsg($message, $call, $ask->message, $this->generation),
                );
            }
        }

        return $this->dispatchToolCalls($message, $gated);
    }

    /**
     * Raise a blocking permission prompt and suspend the turn on it.
     *
     * The returned Cmd is a promise that is deliberately never settled here:
     * it stays pending - and the turn with it - until
     * {@see answerPermission()} resolves the same {@see Deferred}. That is
     * the "schedule a Cmd that resolves once the user answers" half of
     * crush_feat.md §1 E2, and it reuses the exact Deferred/Cmd::promise
     * pattern {@see waitForToolChildrenAsync()} already uses for tool
     * children.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function requestPermission(PermissionRequestMsg $msg): array
    {
        $deferred = new Deferred();

        $next = $this->mutate([
            'pendingPermission' => $msg,
            'permissionDeferred' => $deferred,
            'inFlight' => true,
        ]);

        return [$next, Cmd::promise(static fn(): PromiseInterface => $deferred->promise())];
    }

    /**
     * Apply the user's answer to the prompt {@see requestPermission()} put up
     * and release the suspended turn.
     *
     * Both permitting replies resume the SAME gated batch that was parked, so
     * hooks are not re-run and the question is not re-asked; the only
     * difference is that {@see PermissionReply::Always} also records a
     * session grant so {@see gateToolCall()} stops asking about that tool.
     * A rejection ends the turn with an honest transcript line instead of
     * silently dropping it.
     *
     * An answer permits exactly the call it was asked about. A batch can
     * carry several outstanding ASKs, so a permitting reply clears only the
     * answered one (plus, for `Always`, the other queued asks for that same
     * tool - that is what "always" means) and then re-suspends on the next
     * one still outstanding. Dispatching the whole parked batch off one
     * answer would run calls the user was never even shown.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function answerPermission(PermissionReply $reply): array
    {
        $request = $this->pendingPermission;
        if ($request === null) {
            return [$this, null];
        }

        $jobs = $this->pendingPermissionJobs;
        $cleared = [
            'pendingPermission' => null,
            'permissionDeferred' => null,
            'pendingPermissionJobs' => [],
        ];

        // Settle the waiting Cmd before anything else: whatever happens next
        // returns its own Cmd, and a promise left pending here would keep the
        // Program waiting on a decision that has already been made.
        $this->permissionDeferred?->resolve(null);

        if (!$reply->permits()) {
            return [$this->mutate([
                ...$cleared,
                'inFlight' => false,
                'inFlightCancellation' => null,
                // The assistant message goes in too: it never reached
                // history (dispatchToolCalls() appends it together with the
                // placeholders, and this batch never got that far), and a
                // transcript that shows the refusal without showing what was
                // refused is worse than showing neither.
                'history' => [
                    ...$this->history,
                    $request->assistantMessage,
                    Message::system("_Permission denied: {$request->toolCall->name} was not run._"),
                    // The refusal also has to exist as a RESULT, not only as
                    // a system note (crush_feat.md §1 E7): the assistant
                    // message above carries the tool call, so leaving it
                    // unanswered puts a tool_use block on the next request's
                    // wire with no matching tool_result. It doubles as the
                    // producer for the struck-through denied row
                    // {@see Renderer::renderToolResults()} draws.
                    Message::assistant('')->withToolResults([ToolResult::error(
                        $request->toolCall->name,
                        "Permission denied: {$request->toolCall->name} was not run.",
                        $request->toolCall->id,
                    )]),
                ],
            ]), null];
        }

        $grants = $this->permissionGrants;
        if ($reply === PermissionReply::Always) {
            $grants[$request->toolCall->name] = true;
            $cleared['permissionGrants'] = $grants;
        }

        // Drop the ASK the user just answered (and, under a fresh Always
        // grant, this tool's other queued asks) - every other entry keeps its
        // ASK, because consent for one call is not consent for another.
        $jobs = array_map(
            static function (array $job) use ($request, $grants): array {
                if ($job[3] === null) {
                    return $job;
                }

                $answered = $job[0] === $request->toolCall || ($grants[$job[0]->name] ?? false);

                return $answered ? [$job[0], $job[1], $job[2], null] : $job;
            },
            $jobs,
        );

        foreach ($jobs as [$call, , , $ask]) {
            if ($ask !== null) {
                return $this->mutate([...$cleared, 'pendingPermissionJobs' => $jobs])->requestPermission(
                    new PermissionRequestMsg($request->assistantMessage, $call, $ask->message, $this->generation),
                );
            }
        }

        return $this->mutate($cleared)->dispatchToolCalls($request->assistantMessage, $jobs);
    }

    /**
     * Decide a permission prompt from a keystroke.
     *
     * Kept to the three answers {@see PermissionReply} defines, translated to
     * a {@see PermissionReplyMsg} so the decision path is identical whether
     * it came from a key, a palette action or a test. Any other key is
     * ignored rather than guessed at - this prompt gates tool execution, so
     * "the user pressed something" must never read as consent.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handlePermissionKey(KeyMsg $msg): array
    {
        $rune = strtolower($msg->rune ?? '');

        $reply = match (true) {
            $msg->type === KeyType::Escape => PermissionReply::Reject,
            $msg->type === KeyType::Char && $rune === 'n' => PermissionReply::Reject,
            $msg->type === KeyType::Char && $rune === 'y' => PermissionReply::Once,
            $msg->type === KeyType::Char && $rune === 'a' => PermissionReply::Always,
            default => null,
        };

        if ($reply === null) {
            return [$this, null];
        }

        return $this->answerPermission($reply);
    }

    /**
     * The prompt currently blocking this turn, if any.
     *
     * {@see Renderer} reads this to draw the modal; a null answer means no
     * decision is outstanding.
     */
    public function pendingPermission(): ?PermissionRequestMsg
    {
        return $this->pendingPermission;
    }

    /**
     * Tool names granted "always" for this session, as `[name => true]`.
     *
     * @return array<string, bool>
     */
    public function permissionGrants(): array
    {
        return $this->permissionGrants;
    }

    /**
     * Show a "running" placeholder per call, fork the already-gated batch and
     * schedule the Cmd that waits for the children.
     *
     * Split out of {@see beginToolCalls()} so the resume path
     * ({@see answerPermission()}) re-enters here with the gated batch it
     * parked, instead of re-entering the gate.
     *
     * @param list<array{0: ToolCall, 1: ?ToolResult, 2: ?HookContext, 3: ?\SugarCraft\Crush\Hooks\HookResult}> $gated
     * @return array{0:Chat,1:?\Closure}
     */
    private function dispatchToolCalls(Message $message, array $gated): array
    {
        $placeholders = array_map(
            static fn(ToolCall $call): Message => Message::toolRunning($call),
            $message->toolCalls,
        );

        $generation = $this->generation + 1;
        $cancellation = new CancellationToken();
        $next = $this->mutate([
            'history' => [...$this->history, $message, ...$placeholders],
            'inFlight' => true,
            'inFlightCancellation' => $cancellation,
            'generation' => $generation,
        ]);

        $jobs = $this->forkToolCalls($gated);
        $cmd = Cmd::promise(function () use ($jobs, $cancellation, $message, $generation): PromiseInterface {
            return $this->waitForToolChildrenAsync($jobs, $cancellation)->then(
                static fn(array $results): Msg => new ToolResultsMsg($message, $results, $generation),
            );
        });

        return [$next, $cmd];
    }

    /**
     * Handle a completed {@see ToolResultsMsg}: replace each "running"
     * placeholder {@see beginToolCalls()} put in history with its real
     * result (matched by {@see Message::$pendingToolCallId}), then schedule
     * the follow-up backend call exactly like {@see submit()}'s tail does.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function finishToolCalls(ToolResultsMsg $msg): array
    {
        if ($msg->generation !== null && $msg->generation !== $this->generation) {
            return [$this, null];
        }

        $resultsById = [];
        foreach ($msg->results as $result) {
            $resultsById[$result->id ?? $result->name] = $result;
        }

        $newHistory = [];
        foreach ($this->history as $historyMessage) {
            $pendingId = $historyMessage->pendingToolCallId;
            if ($pendingId !== null && isset($resultsById[$pendingId])) {
                $result = $resultsById[$pendingId];
                $newHistory[] = Message::assistant($result->isError() ? "Tool error: {$result->error}" : $result->result)
                    ->withToolResults([$result]);

                continue;
            }
            $newHistory[] = $historyMessage;
        }

        $generation = $this->generation + 1;
        $cancellation = new CancellationToken();
        $next = $this->mutate([
            'history' => $newHistory,
            'inFlight' => true,
            'inFlightCancellation' => $cancellation,
            'generation' => $generation,
        ]);

        return [$next, $this->scheduleBackendCompletion($next, $cancellation, $generation)];
    }

    /**
     * Apply ONE queued backend tool-lifecycle event to history, then
     * re-dispatch whatever is left of the queue.
     *
     * This is the consuming half of the `$onEvent` seam {@see Backend} threads
     * through {@see Backend\EngineBackend}/{@see Runtime} (crush_feat.md §1 E1).
     * Before it, an agentic backend could run several rounds of tool calls
     * inside one `complete()` and the user saw nothing but a "thinking…"
     * spinner: only the final Message escaped, so none of {@see Renderer}'s
     * tool rendering ever fired for that pipeline.
     *
     * One event per `update()` (rather than folding the whole queue in a single
     * pass) is what makes the *running* half visible: each returned Chat is
     * rendered before the next event is applied, so an engine-dispatched call
     * walks through the same placeholder-then-replace states
     * {@see beginToolCalls()}/{@see finishToolCalls()} produce for a
     * {@see registerTool()} one. Note this cannot make {@see
     * Backend\EngineBackend}'s FORKED path retroactively live - it replays its
     * queue when the child's payload lands - but the transcript states it
     * produces are identical either way.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function applyBackendToolEvent(BackendToolEventsMsg $msg): array
    {
        if ($msg->generation !== null && $msg->generation !== $this->generation) {
            return [$this, null];
        }

        $remaining = $msg->events;
        $event = array_shift($remaining);

        // Queue drained: hand the turn's reply to the ordinary AssistantMsg
        // arm so tool calls the model asked for on TOP of the engine's own
        // (Chat-native $tools) still get picked up by beginToolCalls().
        if ($event === null) {
            return [$this, Cmd::send(new AssistantMsg($msg->message, $msg->generation))];
        }

        $next = $event instanceof ToolStarted
            ? $this->appendToolRunningPlaceholder($event)
            : $this->replaceToolRunningPlaceholder($event);

        return [$next, Cmd::send(new BackendToolEventsMsg($remaining, $msg->message, $msg->generation))];
    }

    /**
     * Append the "running" placeholder for an engine-dispatched tool call -
     * {@see beginToolCalls()}'s first half, driven by a {@see ToolStarted}
     * instead of by a Message's own `$toolCalls`.
     *
     * The event's engine-side identity is converted through
     * {@see ToolCall::fromEngineCall()} rather than by hand so the placeholder's
     * `pendingToolCallId` keys exactly the way the rest of the Chat-side
     * pipeline keys (W2.S1b).
     */
    private function appendToolRunningPlaceholder(ToolStarted $event): self
    {
        $call = ToolCall::fromEngineCall(
            new EngineToolCall($event->toolCallId, $event->toolName, $event->arguments),
        );

        return $this->mutate(['history' => [...$this->history, Message::toolRunning($call)]]);
    }

    /**
     * Replace an engine-dispatched call's placeholder with its real result -
     * {@see finishToolCalls()}'s replace-by-id half, and deliberately building
     * the same `Message::assistant(…)->withToolResults([…])` shape so
     * {@see Renderer::renderToolResults()} renders both pipelines identically
     * (including the W1.F1 diff and the image bytes that ride along on
     * {@see ToolResult}).
     *
     * Correlation is on {@see ToolFinished::$toolCallId}, NOT on the adapted
     * result's own `id`: a tool never sees its own call id, so built-ins
     * routinely return an invented one and only the event carries the id the
     * placeholder was keyed with (see {@see ToolFinished::fromResult()}).
     *
     * An unmatched result is appended rather than dropped - losing a tool's
     * output entirely is worse than showing it without a preceding placeholder.
     */
    private function replaceToolRunningPlaceholder(ToolFinished $event): self
    {
        $result = ToolResult::fromEngineResult($event->result, $event->toolName);
        $message = Message::assistant($result->isError() ? "Tool error: {$result->error}" : $result->result)
            ->withToolResults([$result]);

        $newHistory = [];
        $replaced = false;
        foreach ($this->history as $historyMessage) {
            if (!$replaced && $historyMessage->pendingToolCallId === $event->toolCallId) {
                $newHistory[] = $message;
                $replaced = true;

                continue;
            }
            $newHistory[] = $historyMessage;
        }

        if (!$replaced) {
            $newHistory[] = $message;
        }

        return $this->mutate(['history' => $newHistory]);
    }

    /**
     * Look up and invoke the registered callback for a tool call, without
     * firing {@see $onToolCall} - the listener fires exactly once, in the
     * parent process, once {@see finishToolCalls()} collects this call's
     * real result (see {@see forkToolCalls()}'s docblock for why that can't
     * happen in the child that actually runs this).
     *
     * A callback that already returns a {@see ToolResult} (e.g. one built
     * via {@see ToolResult::okWithImage()}/{@see ToolResult::withImage()} -
     * see W1.G2) is passed through as-is instead of being re-wrapped by
     * {@see ToolResult::ok()}: re-wrapping would `json_encode()` the object
     * (serializing its public properties, including raw `imageBytes`, into
     * the text `result` string shown to the model/user) and silently drop
     * every field ok() doesn't accept. The tool call's own `$toolCall->id`
     * still wins over whatever id the callback set, matching ok()'s
     * previous behaviour of always stamping the real id.
     *
     * @return array{0: ToolResult, 1: mixed, 2: bool} [result, raw callback
     *     output (only meaningful when $succeeded), succeeded]
     */
    private function invokeTool(ToolCall $toolCall): array
    {
        $name = $toolCall->name;
        $args = $toolCall->arguments;

        if (!isset($this->tools[$name])) {
            return [ToolResult::error($name, "Unknown tool: {$name}", $toolCall->id), null, false];
        }

        try {
            $callback = $this->tools[$name];
            $raw = $callback($args);
            if ($raw instanceof ToolResult) {
                $result = $raw->id === $toolCall->id ? $raw : new ToolResult(
                    $raw->name,
                    $raw->result,
                    $raw->error,
                    $toolCall->id,
                    $raw->imageBytes,
                    $raw->imagePath,
                    $raw->imageProtocol,
                    $raw->diff,
                    $raw->durationMs,
                );
                return [$result, $raw, true];
            }
            $result = ToolResult::ok($name, is_string($raw) ? $raw : (json_encode($raw) ?: 'null'), $toolCall->id);
            return [$result, $raw, true];
        } catch (\Throwable $e) {
            return [ToolResult::error($name, $e->getMessage(), $toolCall->id), null, false];
        }
    }

    /**
     * Fork one child per tool call via a direct pcntl_fork() fan-out (or,
     * when forking isn't available, run it synchronously in-process right
     * here - see {@see executeToolSynchronously()}).
     *
     * R14b.fix: the original design routed through AgentWorkerPool/SubAgent,
     * which - for its default ExecutorInterface (ProcessExecutor) - forks
     * once and then has that fork spawn a SECOND, unrelated process via
     * proc_open() to run an inline worker script. A closure cannot cross
     * that second boundary (proc_open starts a brand-new PHP process with no
     * shared memory, communicating only via JSON over pipes), so the worker
     * had no way to reach the real callback in $this->tools and fabricated
     * output instead - hence R14b's original fix disabled this path entirely.
     *
     * That whole detour was unnecessary: $this->tools' closures need to
     * survive only ONE process boundary - a direct pcntl_fork() of THIS
     * process - and a fork duplicates the entire process memory (copy-on-write),
     * so the child's copy of $this (and every closure it holds) is fully
     * intact and callable. This method forks one child per tool call, has each
     * child invoke the real closure via {@see invokeTool()} and write its
     * result to a temp file; {@see waitForToolChildrenAsync()} collects them
     * once every child has exited - genuinely concurrent, with the real
     * callback output, no registry needed.
     *
     * $onToolCall is deliberately NOT invoked inside a forked child: a
     * listener closure that mutates state by reference (e.g. a test's
     * `use (&$captured)` array) would mutate only the child's own
     * copy-on-write copy, invisible to the parent. It's invoked later, in
     * the parent, once {@see waitForToolChildrenAsync()} collects that
     * call's real result.
     *
     * Hook gating (crush_feat.md §1 E1) runs in the parent, before any fork:
     * a denied call must never reach a child at all, and a
     * {@see HookManager} whose hooks ran inside a forked child would have
     * every effect of that run (audit log, accumulated state) die with the
     * child's copy-on-write memory - the same reason $onToolCall is fired in
     * the parent rather than in {@see invokeTool()}. The gate itself now runs
     * one step earlier still, in {@see beginToolCalls()}, because an ASK
     * decision has to suspend the batch before any of it is forked
     * (crush_feat.md §1 E2) - this method receives the already-gated batch.
     *
     * @param list<array{0: ToolCall, 1: ?ToolResult, 2: ?HookContext, 3: ?\SugarCraft\Crush\Hooks\HookResult}> $gated
     * @return list<array{toolCall: ToolCall, file: ?string, pid: ?int, result: ?ToolResult, hookContext: ?HookContext}>
     */
    private function forkToolCalls(array $gated): array
    {
        $canFork = function_exists('pcntl_fork') && function_exists('pcntl_waitpid');

        $jobs = [];
        foreach ($gated as [$toolCall, $denied, $hookContext, $ask]) {
            if ($ask !== null) {
                // Reaching the fork boundary with an unanswered ASK means the
                // batch was released without the user deciding on this call.
                // Enforce the invariant here as well as in answerPermission()
                // so a future caller cannot widen permission by accident: the
                // call is reported as unapproved instead of being run.
                $denied ??= ToolResult::error(
                    $toolCall->name,
                    "Permission required: {$toolCall->name} was not approved.",
                    $toolCall->id,
                );
            }

            if ($denied !== null) {
                // A denied call is never forked and never reaches its
                // callback, but still becomes a job carrying an honest error
                // ToolResult under the ORIGINAL call id - so finishToolCalls()
                // replaces beginToolCalls()'s "running" placeholder for it
                // exactly as it does for an executed call, instead of leaving
                // a spinner that never resolves.
                $jobs[] = ['toolCall' => $toolCall, 'file' => null, 'pid' => null, 'result' => $denied, 'hookContext' => null];
                continue;
            }

            if (!$canFork) {
                $jobs[] = ['toolCall' => $toolCall, 'file' => null, 'pid' => null, 'result' => $this->executeToolSynchronously($toolCall), 'hookContext' => $hookContext];
                continue;
            }

            $file = sys_get_temp_dir() . '/sc_chat_tool_' . bin2hex(random_bytes(8)) . '.json';
            $pid = pcntl_fork();

            if ($pid === -1) {
                // Fork failed for this call only - run it synchronously right
                // here, same as the no-pcntl fallback.
                $jobs[] = ['toolCall' => $toolCall, 'file' => null, 'pid' => null, 'result' => $this->executeToolSynchronously($toolCall), 'hookContext' => $hookContext];
                continue;
            }

            if ($pid === 0) {
                $this->storeToolResult($file, $toolCall);
                \SugarCraft\Crush\Support\ForkedChild::exitNow(0);
            }

            $jobs[] = ['toolCall' => $toolCall, 'file' => $file, 'pid' => $pid, 'result' => null, 'hookContext' => $hookContext];
        }

        return $jobs;
    }

    /**
     * Run the `PreToolUse` hook chain for ONE Chat-native tool call and
     * report what should happen to it.
     *
     * Deliberately a mirror of {@see Runtime::executeToolCalls()}'s gating,
     * decision for decision, because the whole point of §1 E1 is that a
     * given tool call is treated identically whichever pipeline dispatched
     * it: an unknown tool is reported as unknown WITHOUT consulting hooks
     * (Runtime resolves the tool first and only then builds a HookContext),
     * only a true DENY blocks (a MODIFY is "allowed, with rewritten input",
     * and `isAllowed()` is false for it too), and an unparseable
     * `modifiedInput` falls back to the original arguments.
     *
     * ASK is the one decision this method cannot settle: the hook defers to
     * the user, so the call is neither run nor reported as denied here - it
     * is handed back in slot 3 for {@see beginToolCalls()} to suspend the
     * batch on (crush_feat.md §1 E2). A tool the user has already answered
     * {@see PermissionReply::Always} for skips that: the ASK becomes plain
     * permission, with its HookContext intact so `PostToolUse` still runs.
     *
     * @return array{0: ToolCall, 1: ?ToolResult, 2: ?HookContext, 3: ?\SugarCraft\Crush\Hooks\HookResult}
     *     [the call to execute (arguments rewritten by a MODIFY hook), a
     *     pre-resolved error result when the call was DENIED, the context to
     *     hand `PostToolUse` once the call finishes (null when it will not
     *     run), the unanswered ASK decision when one is outstanding]
     */
    private function gateToolCall(ToolCall $toolCall): array
    {
        if ($this->hooks === null || !isset($this->tools[$toolCall->name])) {
            return [$toolCall, null, null, null];
        }

        $context = new HookContext(
            sessionId: $this->currentSessionId ?? '',
            toolName: $toolCall->name,
            toolArgs: $toolCall->arguments,
            toolInput: json_encode($toolCall->arguments) ?: '{}',
            toolOutput: '',
            // Chat has no model/provider identity to report: Backend's whole
            // contract is complete(history), so neither ever reaches here.
            // Left empty rather than guessed at - every hook that ships with
            // sugar-crush gates on toolName/toolArgs/toolInput.
            model: '',
            provider: '',
            projectRoot: getcwd() ?: '',
        );

        $hookResult = $this->hooks->preToolUse($context);

        if ($hookResult->isAsk()) {
            return ($this->permissionGrants[$toolCall->name] ?? false)
                ? [$toolCall, null, $context, null]
                : [$toolCall, null, $context, $hookResult];
        }

        if (!$hookResult->isAllowed() && !$hookResult->isModified()) {
            return [
                $toolCall,
                ToolResult::error($toolCall->name, "Hook denied: {$hookResult->message}", $toolCall->id),
                null,
                null,
            ];
        }

        if ($hookResult->isModified()) {
            $decoded = json_decode($hookResult->modifiedInput ?? '', true);
            if (is_array($decoded)) {
                $toolCall = new ToolCall($toolCall->name, $decoded, $toolCall->id);
            }
        }

        return [$toolCall, null, $context, null];
    }

    /**
     * Run the `PostToolUse` hook chain over a finished tool call's output,
     * in the parent process, and return the result unchanged.
     *
     * Paired with {@see gateToolCall()}: `$context` is null exactly when the
     * pre-hook never allowed the call (no hooks wired, unknown tool, or a
     * DENY), which is also when {@see Runtime} skips its own postToolUse -
     * a call that never ran has no output to observe.
     */
    private function applyPostToolUse(?HookContext $context, ToolResult $result): ToolResult
    {
        if ($context !== null && $this->hooks !== null) {
            $this->hooks->postToolUse($context->withToolOutput($result->result));
        }

        return $result;
    }

    /**
     * Run a tool call that never crossed (or won't cross) a fork boundary -
     * pcntl unavailable, or this specific pcntl_fork() call failed. Safe,
     * and necessary, to fire $onToolCall directly here: there's no child
     * memory for its effects to be lost in (contrast {@see forkToolCalls()}'s
     * docblock on why a genuinely forked job can't do this).
     */
    private function executeToolSynchronously(ToolCall $toolCall): ToolResult
    {
        [$result, $raw, $succeeded] = $this->invokeTool($toolCall);

        if ($succeeded && $this->onToolCall !== null) {
            ($this->onToolCall)($toolCall->name, $toolCall->arguments, $raw);
        }

        return $result;
    }

    /**
     * Run inside a forked child (or synchronously, on fork failure): invoke
     * the real tool callback and write a JSON-safe payload the parent can
     * reconstruct via {@see collectToolResult()}. `raw` is best-effort
     * JSON-round-tripped for the parent's later $onToolCall call - tool
     * callbacks are documented as returning `mixed`, but anything that isn't
     * itself JSON-safe (a resource, a closure) can't survive any IPC
     * mechanism, forked or not, and isn't a realistic tool return value.
     *
     * `imageBytes` is base64-encoded before crossing this JSON-over-temp-file
     * boundary - raw binary (e.g. PNG bytes) is not valid UTF-8 and
     * `json_encode()` would fail/emit null for it otherwise, silently
     * dropping every image-bearing {@see ToolResult} (see {@see
     * ToolResult::okWithImage()}) once a call crosses the default
     * pcntl_fork() path (see W1.G2 reachability fix).
     */
    private function storeToolResult(string $file, ToolCall $toolCall): void
    {
        [$result, $raw, $succeeded] = $this->invokeTool($toolCall);

        $payload = [
            'succeeded' => $succeeded,
            'result' => [
                'name' => $result->name,
                'result' => $result->result,
                'error' => $result->error,
                'id' => $result->id,
                'imageBytes' => $result->imageBytes === null ? null : base64_encode($result->imageBytes),
                'imagePath' => $result->imagePath,
                'imageProtocol' => $result->imageProtocol,
                'diff' => $result->diff,
                'durationMs' => $result->durationMs,
            ],
            'raw' => json_decode(json_encode($raw) ?: 'null', true),
        ];

        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        file_put_contents($file, $json === false ? '' : $json);
    }

    /**
     * Non-blocking counterpart to the old (removed) waitForToolChildren():
     * resolves once every forked job in $jobs has exited, collecting each
     * one's real result via {@see collectToolResult()} (which fires
     * $onToolCall in the parent - see {@see forkToolCalls()}'s docblock),
     * polling via a periodic timer instead of a blocking usleep() loop so
     * the render/input loop keeps running while tools execute - same
     * rationale as {@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()}'s
     * fork+socket rewrite, here via WNOHANG polling since these jobs report
     * through temp files, not a socket. A hung tool (e.g. a stuck shell
     * command) is SIGKILLed past {@see PARALLEL_TOOL_TIMEOUT_SECONDS}, same
     * ceiling the old blocking version used. Escape-Escape abort (see
     * Chat::update()) also lands here via $cancellation, same as it does
     * for the backend call.
     *
     * @param list<array{toolCall: ToolCall, file: ?string, pid: ?int, result: ?ToolResult, hookContext: ?HookContext}> $jobs
     * @return PromiseInterface<list<ToolResult>>
     */
    private function waitForToolChildrenAsync(array $jobs, CancellationToken $cancellation): PromiseInterface
    {
        $deferred = new Deferred();

        // PostToolUse runs here, on the parent's side of the fork boundary,
        // for the same reason PreToolUse runs before the fork - see
        // forkToolCalls()'s docblock.
        $collect = fn(array $job): ToolResult => $this->applyPostToolUse(
            $job['hookContext'] ?? null,
            $job['result'] ?? $this->collectToolResult((string) $job['file'], $job['toolCall']),
        );

        $pendingIndexes = [];
        foreach ($jobs as $index => $job) {
            if ($job['pid'] !== null) {
                $pendingIndexes[$index] = true;
            }
        }

        if ($pendingIndexes === []) {
            $deferred->resolve(array_map($collect, $jobs));

            return $deferred->promise();
        }

        $loop = Loop::get();
        $settled = false;
        $timer = null;
        $deadline = microtime(true) + self::PARALLEL_TOOL_TIMEOUT_SECONDS;

        $timer = $loop->addPeriodicTimer(0.05, function () use (&$pendingIndexes, $jobs, $deadline, $cancellation, $collect, $loop, &$settled, &$timer, $deferred): void {
            if ($settled) {
                return;
            }

            foreach ($pendingIndexes as $index => $_) {
                $status = 0;
                if (pcntl_waitpid($jobs[$index]['pid'], $status, WNOHANG) === $jobs[$index]['pid']) {
                    unset($pendingIndexes[$index]);
                }
            }

            $mustStop = $pendingIndexes === [] || microtime(true) >= $deadline || $cancellation->isCancelled();
            if (!$mustStop) {
                return;
            }

            foreach ($pendingIndexes as $index => $_) {
                if (function_exists('posix_kill')) {
                    posix_kill($jobs[$index]['pid'], SIGKILL);
                }
                $status = 0;
                pcntl_waitpid($jobs[$index]['pid'], $status);
            }

            $settled = true;
            $loop->cancelTimer($timer);
            $deferred->resolve(array_map($collect, $jobs));
        });

        return $deferred->promise();
    }

    /**
     * Read + decode + delete a forked child's result file, reconstruct its
     * ToolResult, and fire $onToolCall in THIS (the parent) process when the
     * underlying callback succeeded - see forkToolCalls()'s docblock
     * for why that firing can't happen in the child itself. A missing or
     * unreadable file (the child never wrote one - killed by the timeout
     * above, or crashed) is reported as a timeout error rather than silently
     * dropped.
     */
    private function collectToolResult(string $file, ToolCall $toolCall): ToolResult
    {
        $data = is_file($file) ? file_get_contents($file) : false;
        if ($data !== false && $data !== '') {
            @unlink($file);
        }

        $decoded = ($data !== false && $data !== '') ? json_decode($data, true) : null;
        if (!is_array($decoded) || !is_array($decoded['result'] ?? null)) {
            return ToolResult::error($toolCall->name, 'Tool execution timed out or produced no result', $toolCall->id);
        }

        $r = $decoded['result'];
        $result = new ToolResult(
            (string) ($r['name'] ?? $toolCall->name),
            (string) ($r['result'] ?? ''),
            $r['error'] ?? null,
            $r['id'] ?? $toolCall->id,
            isset($r['imageBytes']) && is_string($r['imageBytes']) ? base64_decode($r['imageBytes'], true) ?: null : null,
            isset($r['imagePath']) && is_string($r['imagePath']) ? $r['imagePath'] : null,
            isset($r['imageProtocol']) && is_string($r['imageProtocol']) ? $r['imageProtocol'] : null,
            isset($r['diff']) && is_string($r['diff']) ? $r['diff'] : null,
            isset($r['durationMs']) && is_int($r['durationMs']) ? $r['durationMs'] : null,
        );

        if (($decoded['succeeded'] ?? false) === true && $this->onToolCall !== null) {
            ($this->onToolCall)($toolCall->name, $toolCall->arguments, $decoded['raw'] ?? null);
        }

        return $result;
    }

    /**
     * Returns the full literal frame every call. This used to compute its
     * own cell-level diff (via {@see Buffer}/{@see DiffEncoder}) and return
     * only the changed bytes - but Program (see `bin/sugarcrush`, a plain
     * `new Program(Bootstrap::chat(), ...)`) ALSO diffs whatever a Model's
     * view() returns, line-by-line, against the previous call's return
     * value (candy-core's own Renderer::render(), which every other Model
     * in this framework relies on for exactly this). Chat's pre-diffed
     * cursor-jump escape bytes were never literal display text, so
     * Program's Renderer was diffing one diff's raw bytes against the
     * previous diff's raw bytes as if they were screen content - any time
     * the two differed (i.e. almost always) that produced cursor
     * placement that had no relationship to the actual frame, which is
     * what made typed input / replies appear to land in the wrong row
     * (e.g. the status bar) once a conversation grew past a single frame.
     * Program's Renderer already does correct, safe diffing on real text;
     * doing it a second time here was redundant at best.
     *
     * Returns a {@see \SugarCraft\Core\View} instead of that bare string only
     * when the frame carries images - see {@see Renderer::renderView()}.
     */
    public function view(): string|\SugarCraft\Core\View
    {
        $view = Renderer::renderView($this);

        // The View wrapper exists only to carry the pixel-graphics layer an
        // image-bearing tool result puts on it (crush_feat.md §9 E3); Program
        // auto-wraps a plain string for every other frame, and returning the
        // literal frame keeps view() substitutable for its own body wherever
        // no image is on screen - which is every frame of a text-only session.
        return $view->images === [] ? $view->body : $view;
    }

    /**
     * How the terminal is asked to report mouse events (crush_feat.md §8 E1).
     *
     * `CellMotion` rather than `AllMotion`: hover-everywhere turns every
     * pointer move into a MouseMotionMsg on the ReactPHP read loop, and
     * nothing consumes hover yet.
     *
     * `SUGARCRUSH_DISABLE_MOUSE` turns tracking off completely. That escape
     * hatch is not optional politeness — while SGR mouse tracking is active
     * the terminal stops offering its own copy-on-select, which is the
     * single most-repeated complaint across every tool surveyed in §8.
     *
     * `SUGARCRUSH_DISABLE_MOUSE_CLICKS` deliberately does NOT change the
     * mode: wheel events are reported over the same tracking mode as
     * clicks, so "keep scroll, drop clicks" can only be honoured above the
     * protocol — by refusing to hit-test (see {@see zoneAt()}).
     */
    public static function mouseMode(): MouseMode
    {
        return self::envFlag('SUGARCRUSH_DISABLE_MOUSE') ? MouseMode::Off : MouseMode::CellMotion;
    }

    /**
     * Whether click/drag hit-testing is live. False when either
     * `SUGARCRUSH_DISABLE_MOUSE` (no tracking at all) or
     * `SUGARCRUSH_DISABLE_MOUSE_CLICKS` (clicks off, wheel kept) is set.
     */
    public static function mouseClicksEnabled(): bool
    {
        return self::mouseMode() !== MouseMode::Off
            && !self::envFlag('SUGARCRUSH_DISABLE_MOUSE_CLICKS');
    }

    /**
     * The marked zone under a reported pointer cell, or null when there is
     * none — the one hit-test entry point every future click handler goes
     * through, so `SUGARCRUSH_DISABLE_MOUSE_CLICKS` is enforced in exactly
     * one place instead of at each call site.
     *
     * Reads the registry {@see Renderer::scanRoot()} filled on the last
     * frame, because a click reports coordinates against what is currently
     * painted, not against the frame being built.
     *
     * `$col`/`$row` are terminal-absolute (that is what the SGR mouse report
     * carries), while the registry's boxes are relative to the frame that was
     * scanned. Those two agree only when this `Chat` painted the whole screen;
     * when the pane shell hosts it the frame is drawn inside a box, below a
     * menu bar and beside a sidebar, so {@see Renderer::zoneOrigin()} — which
     * the shell declares after compositing — is subtracted first. Standalone
     * it is `[0, 0]` and this is the old arithmetic exactly.
     */
    public static function zoneAt(int $col, int $row): ?Zone
    {
        if (!self::mouseClicksEnabled()) {
            return null;
        }

        [$col, $row] = self::zoneSpace($col, $row);

        return Renderer::scanner()->hit($col, $row);
    }

    /**
     * A terminal-absolute pointer cell rebased into the coordinate space the
     * zone registry recorded, by subtracting {@see Renderer::zoneOrigin()}.
     *
     * Every comparison against a recorded {@see Zone} has to go through here,
     * not just the hit-test: candy-mouse's {@see ZoneClickTracker} pairs a
     * press with its release by re-testing the PRESS's stored box against the
     * release event ({@see Zone::inBounds()}), so handing it an absolute event
     * while the box is pane-local rejects every click inside a hosted pane as
     * "released on a different zone" — the click resolves and then goes
     * nowhere. Standalone the origin is `[0, 0]` and this is the identity.
     *
     * @return array{0: int, 1: int}
     */
    private static function zoneSpace(int $col, int $row): array
    {
        [$originCol, $originRow] = Renderer::zoneOrigin();

        return [$col - $originCol, $row - $originRow];
    }

    /**
     * Press/Release pairing state for click dispatch.
     *
     * Static for the same reason {@see Renderer::scanner()} is: a click spans
     * two `update()` calls, and `Chat` is immutable — the press-half state
     * would be discarded with the intermediate instance if it lived on a
     * field, so no click could ever complete. One tracker per process
     * mirrors the single global manager bubblezone (and candy-mouse) assumes.
     */
    private static ?ZoneClickTracker $clickTracker = null;

    /**
     * The shared Press+Release pairing state machine (candy-mouse's
     * {@see ZoneClickTracker}), which is what makes a press on a tab followed
     * by a release somewhere else — a drag, or a text selection started on
     * the tab strip — dispatch nothing instead of switching sessions.
     */
    public static function clickTracker(): ZoneClickTracker
    {
        return self::$clickTracker ??= new ZoneClickTracker();
    }

    /**
     * The in-flight left press as `[col, row, drift]` — where it landed and
     * the furthest the pointer has strayed from it since (crush_feat.md
     * §8 E8), or null when no press is pending.
     *
     * Static for the same reason {@see $clickTracker} is: the press half is
     * recorded in one `update()` and read in a later one, and an immutable
     * Chat throws the intermediate instance away. Only the left button ever
     * reaches here — {@see handleMouse()} drops the others before this —
     * so one slot is enough, unlike the tracker's per-button map.
     *
     * @var array{0:int,1:int,2:int}|null
     */
    private static ?array $pressGesture = null;

    /**
     * Fold a pointer position reported while a press is pending into that
     * press's drift.
     *
     * Drift is the running MAXIMUM rather than the press→release delta
     * alone, because a selection sweep that ends back where it started
     * (drag right to highlight a line, drag back, release) has a delta of
     * zero and would otherwise read as a click.
     */
    private static function recordPressDrift(int $col, int $row): void
    {
        if (self::$pressGesture === null) {
            return;
        }

        [$pressCol, $pressRow, $drift] = self::$pressGesture;
        $distance = abs($col - $pressCol) + abs($row - $pressRow);

        self::$pressGesture = [$pressCol, $pressRow, max($drift, $distance)];
    }

    /**
     * Click-to-switch session tab (crush_feat.md §8 E2), click-to-switch pane
     * (§8 E3), click-to-expand a tool call (§8 E5), click-to-select a palette
     * row (§8 E6), plus wheel-scroll of the transcript (§8 E4).
     *
     * Wheel events branch off FIRST and never reach the click tracker: they
     * are the one gesture that survives `SUGARCRUSH_DISABLE_MOUSE_CLICKS`
     * (see {@see mouseMode()}), and hit-testing them through
     * {@see zoneAt()} — which is where that flag is enforced — would take
     * scrolling down with clicks. candy-mouse's tracker ignores Scroll
     * anyway. Only left press/release are fed to it; motion is still
     * dropped rather than translated, since nothing consumes hover yet.
     *
     * The hit test uses the click's own coordinates against the zones
     * {@see Renderer::scanRoot()} recorded for the frame currently on
     * screen — see {@see zoneAt()}, which is also where
     * `SUGARCRUSH_DISABLE_MOUSE_CLICKS` is enforced.
     *
     * A pair the tracker accepts is then re-checked against how far the
     * pointer moved (§8 E8, {@see CLICK_DRAG_TOLERANCE_CELLS}): a drag
     * within one wide zone is a text selection, not a click, and must
     * dispatch nothing.
     *
     * @return array{0:self,1:?\Closure}
     */
    private function handleMouse(MouseMsg $msg): array
    {
        if ($msg instanceof MouseWheelMsg) {
            return $this->scrollTranscript($msg->button);
        }

        if ($msg->button !== MouseButton::Left) {
            return [$this, null];
        }

        // Motion is still not translated into a candy-mouse event (nothing
        // consumes hover), but with the button down it is the terminal
        // narrating a drag, so it feeds §8 E8's drift before being dropped.
        if ($msg instanceof MouseMotionMsg) {
            self::recordPressDrift($msg->x, $msg->y);

            return [$this, null];
        }

        // Built in the registry's coordinate space, not the terminal's: the
        // tracker re-tests the recorded box against this event to pair the
        // release with its press (see {@see zoneSpace()}). Drift below stays
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

        if ($msg instanceof MouseClickMsg) {
            self::$pressGesture = [$msg->x, $msg->y, 0];
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

        $click = self::clickTracker()->track($event, self::zoneAt($msg->x, $msg->y));
        if ($click === null) {
            return [$this, null];
        }

        // §8 E8. The pair is clean by zone, but the pointer travelled far
        // enough across it that the user was sweeping out a text selection,
        // not pointing at a control — dispatch nothing so the terminal's own
        // copy-on-select is what the gesture accomplishes.
        if ($drift > self::CLICK_DRAG_TOLERANCE_CELLS) {
            return [$this, null];
        }

        $zoneId = $click->zone->id;

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

        return [$this, null];
    }

    /**
     * Click-to-select in the command palette / picker (crush_feat.md §8 E6).
     *
     * §8 E6 asks explicitly for the click to "dispatch the same Msg/Cmd the
     * Enter key currently dispatches" rather than a parallel confirm path, so
     * this only moves `selectedIndex` onto the clicked row and then hands off
     * to {@see runSelectedPaletteAction()} — the exact method
     * {@see handlePaletteKey()}'s Enter arm calls. Everything that hangs off a
     * confirm (mode transitions into the providers/themes list, the §4 E7 MRU
     * bump, `Cmd::quit()` for Exit) therefore behaves identically whether the
     * row was chosen with the keyboard or the mouse.
     *
     * The index is re-checked against the CURRENT match list rather than
     * trusted from the zone: zones describe the previously-painted frame, and
     * a row that has since disappeared (an async reply landing, a
     * re-filtered list) would otherwise confirm whatever action drifted into
     * that slot. Out-of-range, or a click arriving after the palette closed,
     * is a no-op — the safe answer for a stale click is to run nothing.
     *
     * @return array{0:self,1:?\Closure}
     */
    private function selectPaletteItem(string $index): array
    {
        if ($this->palette === null || preg_match('/\A\d+\z/', $index) !== 1) {
            return [$this, null];
        }

        $row = (int) $index;
        if ($row >= count($this->paletteMatches())) {
            return [$this, null];
        }

        return $this->mutate(['palette' => $this->palette->withSelectedIndex($row)])
            ->runSelectedPaletteAction();
    }

    /**
     * Wheel-scroll the chat transcript (crush_feat.md §8 E4).
     *
     * `WheelUp` moves BACK into history, so it raises the offset — §8 E4's
     * sketch subtracts because its `$app->chatScrollOffset` counts from the
     * top; this one counts from the bottom (see the constructor's
     * `$scrollOffset` docblock for why that end is the anchor).
     *
     * The upper clamp comes from {@see Renderer::maxScrollOffset()}, the
     * overflow of the frame currently on screen — the same "a mouse event
     * is reported against what is painted, not against the frame being
     * built" rule {@see zoneAt()} follows. A transcript that fits the
     * window reports 0 and the wheel does nothing.
     *
     * §8 E4 gates this on `$app->pane === Pane::Chat`. There is no live
     * pane state to gate on — see {@see selectPane()} for why `App::$pane`
     * is not reachable from `bin/sugarcrush` — and the transcript is the
     * only scrollable surface this path renders, so the wheel always
     * addresses it.
     *
     * @return array{0:self,1:?\Closure}
     */
    private function scrollTranscript(MouseButton $button): array
    {
        $delta = match ($button) {
            MouseButton::WheelUp   => self::SCROLL_WHEEL_LINES,
            MouseButton::WheelDown => -self::SCROLL_WHEEL_LINES,
            default                => 0,
        };
        if ($delta === 0) {
            return [$this, null];
        }

        // Both ends are clamped here, not just the top: without the max(0)
        // a wheel-down at the bottom would compute -3, differ from the
        // current 0, and hand back a fresh instance for a scroll that
        // cannot happen - a new frame diffed for nothing on every notch.
        $offset = max(0, min($this->scrollOffset + $delta, Renderer::maxScrollOffset()));
        if ($offset === $this->scrollOffset) {
            return [$this, null];
        }

        return [$this->withScrollOffset($offset), null];
    }

    /**
     * How far back the transcript is scrolled, in lines from the newest
     * one. 0 means pinned to the bottom. Read by {@see Renderer::render()}.
     */
    public function scrollOffset(): int
    {
        return $this->scrollOffset;
    }

    /**
     * Scroll the transcript back $offset lines from the newest one.
     *
     * Negatives clamp to 0 — the bottom is the furthest the view can go
     * forward, there is nothing below the newest line. The upper end is
     * clamped by the caller against a real frame's overflow (see
     * {@see scrollTranscript()}); a value beyond it stored anyway is
     * harmless, since {@see Renderer::render()} re-clamps to whatever the
     * frame it is drawing can actually offer.
     */
    public function withScrollOffset(int $offset): self
    {
        return $this->mutate(['scrollOffset' => max(0, $offset)]);
    }

    /**
     * Make the clicked session current, if it is still a session.
     *
     * The id is re-checked against `listSessions()` rather than trusted from
     * the zone: zones describe the PREVIOUS frame, so a session deleted (or a
     * store swapped) between that frame and the click would otherwise leave
     * `currentSessionId` pointing at a row that no longer exists — which
     * {@see cycleSessionTab()} then treats as "current session not found" and
     * refuses to cycle out of, stranding the user.
     *
     * Unlike {@see cycleSessionTab()} this does NOT require a non-null
     * `currentSessionId` to start from — a click names its target absolutely,
     * so it works on a freshly-launched process that has not selected a
     * session yet (see that method's reachability note).
     *
     * @return array{0:self,1:?\Closure}
     */
    private function selectSessionTab(string $id): array
    {
        if ($id === '' || $id === $this->currentSessionId || $this->sessionStore === null) {
            return [$this, null];
        }

        if (!in_array($id, array_column($this->sessionStore->listSessions(), 'id'), true)) {
            return [$this, null];
        }

        return [$this->withCurrentSessionId($id), null];
    }

    /**
     * Click-to-switch pane (crush_feat.md §8 E3).
     *
     * §8 E3 sketches `$app->withPane(Pane::from($name))`, but `App::$pane`
     * belongs to the `App`/`Tui\Renderer` system that nothing constructs
     * (`bin/sugarcrush` runs THIS model — see {@see Renderer}'s class
     * docblock, and §5 E7, which recommends retiring that system outright).
     * Jumping a pane field no live frame reads would be a switch the user
     * can never see. So a pane click dispatches the same thing the keyboard
     * already dispatches for that pane on the live path — E3's "just a
     * direct jump instead of `next()`", against the surfaces that exist:
     *
     * - {@see Pane::Menu} → open the Ctrl+P palette. The palette IS this
     *   path's menu surface; the status bar's "Ctrl+P menu" hint is the
     *   region marked for it. A click is ignored while the palette is
     *   already open: it captures keyboard input while up, so re-rooting it
     *   from underneath would undo navigation the keyboard cannot.
     * - {@see Pane::Agents} → the same `handleAgentsCommand('/agents')` the
     *   Ctrl+A shortcut and the palette's SwitchAgent action already run.
     *
     * Every other case is honestly inert. Files/Tools/Skills/Settings/Help
     * have NO live surface on this path at all (they are `Tui\Components\*`
     * stubs keyed on `App`), and Chat/Input have no separate focus to move —
     * every keystroke already goes to the input box. Nothing marks a zone
     * for those panes, so this arm is only reached by a stale zone from a
     * previous frame; answering it with an invented state change would be
     * worse than answering it with nothing.
     *
     * @param string $name A {@see Pane} case value, as parsed off the zone id.
     *
     * @return array{0:self,1:?\Closure}
     */
    private function selectPane(string $name): array
    {
        return match (Pane::tryFrom($name)) {
            Pane::Menu => $this->palette !== null
                ? [$this, null]
                : [$this->mutate(['palette' => PaletteState::root()]), null],
            Pane::Agents => $this->handleAgentsCommand('/agents'),
            default => [$this, null],
        };
    }

    /**
     * Runtime options `bin/sugarcrush` starts the chat Program with. Exists
     * so the mouse-mode decision above is made once, next to the state it
     * governs, instead of being duplicated in the entrypoint script.
     */
    public static function programOptions(): ProgramOptions
    {
        return new ProgramOptions(useAltScreen: true, mouseMode: self::mouseMode());
    }

    /**
     * Treats an unset, empty, or literal `0` value as "not set" so
     * `SUGARCRUSH_DISABLE_MOUSE=0` reads as "leave the mouse on" rather than
     * as any-value-means-true.
     */
    private static function envFlag(string $name): bool
    {
        $value = getenv($name);

        return $value !== false && $value !== '' && $value !== '0';
    }

    public function backend(): Backend
    {
        return $this->backend;
    }

    public function withBackend(Backend $backend): self
    {
        return $this->mutate(['backend' => $backend]);
    }

    /**
     * Get the agent pool config, if set.
     */
    public function agentPoolConfig(): ?\SugarCraft\Crush\Agents\AgentPoolConfig
    {
        return $this->agentPoolConfig;
    }

    /**
     * Get the effective worker pool, if set.
     */
    public function pool(): ?\SugarCraft\Crush\Agents\AgentWorkerPool
    {
        return $this->effectivePool;
    }

    /**
     * Get the agent manager, if set.
     */
    public function agentManager(): ?\SugarCraft\Crush\Agents\AgentManager
    {
        return $this->agentManager;
    }

    /**
     * The background-session supervisor `/bg` and `/fork` dispatch onto, if set.
     */
    public function backgroundSupervisor(): ?\SugarCraft\Crush\Sessions\BackgroundSupervisor
    {
        return $this->backgroundSupervisor;
    }

    /**
     * Attach the supervisor `/bg` and `/fork` spawn onto.
     *
     * The supervisor is deliberately NOT immutable-cloned here: it owns live
     * child processes and open sockets, so every `mutate()` clone of this
     * Chat must keep pointing at the SAME instance or the sessions a previous
     * clone spawned become unreachable (the same reasoning as
     * {@see \SugarCraft\Crush\Backend\CancellationToken}).
     */
    public function withBackgroundSupervisor(?\SugarCraft\Crush\Sessions\BackgroundSupervisor $supervisor): self
    {
        return $this->mutate(['backgroundSupervisor' => $supervisor]);
    }

    /**
     * Get the memory store, if set.
     */
    public function memoryStore(): ?MemoryStore
    {
        return $this->memoryStore;
    }

    /**
     * Get the session store, if set.
     */
    public function sessionStore(): \SugarCraft\Crush\Session\SessionStore|\SugarCraft\Crush\Session\EnhancedSessionStore|null
    {
        return $this->sessionStore;
    }

    /**
     * Create a new Chat with an explicit session store.
     */
    public function withSessionStore(\SugarCraft\Crush\Session\SessionStore|\SugarCraft\Crush\Session\EnhancedSessionStore|null $sessionStore): self
    {
        return $this->mutate(['sessionStore' => $sessionStore]);
    }

    /**
     * Get the current session ID, if any.
     */
    public function currentSessionId(): ?string
    {
        return $this->currentSessionId;
    }

    /**
     * Create a new Chat with an explicit current session ID.
     */
    public function withCurrentSessionId(string $currentSessionId): self
    {
        return $this->mutate(['currentSessionId' => $currentSessionId]);
    }

    /**
     * Name of the current session, or null while it is still unnamed.
     *
     * Populated by `/rename` and by the background auto-title call
     * ({@see scheduleTitleGeneration()}); the UI reads this rather than
     * hitting the session store on every frame.
     */
    public function currentSessionName(): ?string
    {
        return $this->currentSessionName;
    }

    /**
     * Create a new Chat with an explicit session name. Passing null clears
     * the name, which re-arms the one-shot auto-title.
     */
    public function withCurrentSessionName(?string $currentSessionName): self
    {
        return $this->mutate(['currentSessionName' => $currentSessionName]);
    }

    /**
     * Create a new Chat with an explicit small-model Backend used only for
     * the background session-title call. See the constructor's
     * `$titleBackend` docblock for why it is deliberately a separate
     * instance from the conversation backend.
     */
    public function withTitleBackend(?Backend $titleBackend): self
    {
        return $this->mutate(['titleBackend' => $titleBackend]);
    }

    /**
     * Backward-compatible alias for pool().
     *
     * @deprecated Use pool() instead. This alias exists to ease migration.
     */
    public function workerPool(): ?\SugarCraft\Crush\Agents\AgentWorkerPool
    {
        return $this->pool();
    }

    /**
     * Execute multiple agents in parallel via the worker pool.
     *
     * If an explicit $effectivePool was set via withWorkerPool(), it is used directly.
     * Otherwise, if $agentPoolConfig was set via withAgentPoolConfig(), a pool is
     * built from that config. If neither is set, throws \RuntimeException.
     *
     * @param \SugarCraft\Crush\Agents\SubAgent[] $agents
     * @return \Generator<AgentResult>
     * @throws \RuntimeException When no pool or config is available
     */
    public function executeAgents(array $agents, \SugarCraft\Crush\Providers\CompleteRequest $request): \Generator
    {
        $pool = $this->effectivePool;
        if ($pool === null) {
            if ($this->agentPoolConfig === null) {
                throw new \RuntimeException(
                    'Cannot execute agents: no AgentWorkerPool or AgentPoolConfig available. '
                    . 'Call withWorkerPool() or withAgentPoolConfig() first.'
                );
            }
            // Wire config fields inline: create executor with timeout, pass maxConcurrent
            // to constructor, and apply stopOnFirstFailure via the pool's fluent setter.
            $executor = new \SugarCraft\Crush\Agents\ProcessExecutor(
                timeoutSeconds: $this->agentPoolConfig->defaultTimeoutSeconds,
            );
            $pool = (new \SugarCraft\Crush\Agents\AgentWorkerPool(
                maxConcurrent: $this->agentPoolConfig->maxConcurrent,
                executor: $executor,
            ))->withStopOnFirstFailure($this->agentPoolConfig->stopOnFirstFailure);
        }

        return $pool->executeAll($agents, $request);
    }

    /**
     * Create a new Chat with an explicit worker pool.
     */
    public function withWorkerPool(\SugarCraft\Crush\Agents\AgentWorkerPool $pool): self
    {
        return $this->mutate(['effectivePool' => $pool]);
    }

    /**
     * Create a new Chat with an agent pool config (used to build the worker pool on demand).
     */
    public function withAgentPoolConfig(\SugarCraft\Crush\Agents\AgentPoolConfig $config): self
    {
        return $this->mutate(['agentPoolConfig' => $config]);
    }

    /**
     * Create a new Chat with an explicit memory store.
     */
    public function withMemoryStore(MemoryStore $memoryStore): self
    {
        return $this->mutate(['memoryStore' => $memoryStore]);
    }

    /**
     * Create a new Chat with an explicit workflow engine.
     */
    public function withWorkflowEngine(WorkflowEngineInterface $engine): self
    {
        return $this->mutate(['workflowEngine' => $engine]);
    }

    /**
     * Get the workflow engine, if set.
     */
    public function workflowEngine(): ?WorkflowEngineInterface
    {
        return $this->workflowEngine;
    }

    /**
     * Get the shared candy-mosaic probe-once capability instance, if wired
     * (see this class's `$mosaic` constructor docblock, W1.G2/E2).
     */
    public function mosaic(): ?\SugarCraft\Mosaic\Mosaic
    {
        return $this->mosaic;
    }

    /**
     * The hook chain gating this Chat's own tool calls, if wired (see the
     * `$hooks` constructor docblock).
     */
    public function hooks(): ?HookManager
    {
        return $this->hooks;
    }

    /**
     * Gate this Chat's {@see registerTool()} calls through `$hooks`, the
     * same {@see HookManager} the engine pipeline already runs its calls
     * through (crush_feat.md §1 E1).
     *
     * @return self A new Chat with the hook chain attached
     */
    public function withHooks(HookManager $hooks): self
    {
        return $this->mutate(['hooks' => $hooks]);
    }

    /**
     * Tool-call ids whose output the user has expanded (crush_feat.md §1 E5).
     * {@see Renderer::render()} reads this to decide which tool bodies to
     * paint in full; ids absent from the map are collapsed.
     *
     * @return array<string, bool>
     */
    public function expanded(): array
    {
        return $this->expanded;
    }

    /**
     * True when $id's tool output is currently expanded.
     */
    public function isToolOutputExpanded(string $id): bool
    {
        return ($this->expanded[$id] ?? false) === true;
    }

    /**
     * Flip one tool call's collapsed/expanded state. Collapsing REMOVES the
     * key rather than storing false - see the constructor's `$expanded`
     * docblock for why the map only ever holds what the user opened.
     *
     * @return self A new Chat with $id's expansion state flipped
     */
    public function toggleToolOutput(string $id): self
    {
        $expanded = $this->expanded;
        if (($expanded[$id] ?? false) === true) {
            unset($expanded[$id]);
        } else {
            $expanded[$id] = true;
        }

        return $this->mutate(['expanded' => $expanded]);
    }

    /**
     * Error-message prefixes that mark a {@see ToolResult} as REFUSED rather
     * than merely failed (crush_feat.md §1 E7).
     *
     * Refusal is carried in the error text rather than in a dedicated flag
     * because every refusal producer already writes one of these three
     * sentences and they are the only text a result's error can start with
     * that means "this never ran": {@see answerPermission()} (the user
     * rejected the prompt), {@see forkToolCalls()} (an ASK reached the fork
     * boundary unanswered) and the hook gate in both {@see finishToolCalls()}
     * and {@see \SugarCraft\Crush\Runtime::execute()} - the latter reaching
     * here through {@see ToolResult::fromEngineResult()}, so an engine-path
     * denial renders identically to a Chat-path one.
     *
     * @var list<string>
     */
    public const DENIED_ERROR_PREFIXES = [
        'Permission denied:',
        'Permission required:',
        'Hook denied:',
    ];

    /**
     * Verbatim error text {@see reviveCheckpointMessage()} writes onto a tool
     * call that was still running when the process that started it went away
     * - crush_feat.md §1 E7's literal "Tool call interrupted by restart".
     * Also the marker {@see isInterruptedResult()} matches on, so the
     * renderer can draw it as its own state rather than as a plain failure.
     */
    public const INTERRUPTED_TOOL_CALL = 'Tool call interrupted by restart';

    /**
     * True when $result is a refusal - a call the user or a hook stopped -
     * rather than a call that ran and failed.
     *
     * crush_feat.md §1 E7 wants a refusal drawn as its own visual state
     * (struck through), not just another red error line, and §1's opencode
     * survey (line 111) is explicit that "denied" is "a distinct visual
     * state, not just an error color". {@see Renderer::renderToolResults()}
     * is the consumer; the classification lives here, next to the code that
     * writes those errors, so the renderer never has to guess.
     */
    public static function isDeniedResult(ToolResult $result): bool
    {
        $error = $result->error;
        if ($error === null) {
            return false;
        }

        foreach (self::DENIED_ERROR_PREFIXES as $prefix) {
            if (str_starts_with($error, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when $result stands in for a tool call that never finished
     * because the process running it went away - see
     * {@see reviveCheckpointMessage()}. Distinct from
     * {@see isDeniedResult()}: nobody refused this call, it simply lost its
     * runner, and the two deserve different words on screen.
     */
    public static function isInterruptedResult(ToolResult $result): bool
    {
        return $result->error === self::INTERRUPTED_TOOL_CALL;
    }

    /**
     * Rebuild one checkpointed history row into a {@see Message}, healing a
     * checkpoint that was taken while a tool call was still in flight
     * (crush_feat.md §1 E7).
     *
     * A row with a non-null `pendingToolCallId` is a {@see
     * Message::toolRunning()} placeholder whose call died with the previous
     * process. Replaying it verbatim would restore a spinner nothing can ever
     * resolve, AND would put a `tool_use` block on the next request's wire
     * with no matching `tool_result` - which providers reject outright. So it
     * is replaced by a synthetic assistant turn carrying a refusal-shaped
     * result under the SAME call id, exactly as the spec sketches: the
     * transcript stays honest about what happened and the wire stays
     * well-formed.
     *
     * The synthetic result is named from the row's `content` - a placeholder
     * stores {@see Message::describeToolCall()}'s human one-liner there and
     * carries no separate tool name - because {@see Renderer} prints that
     * value after "🔧 tool:", and the alternative (the opaque call id) would
     * put a wire identifier in front of the user.
     *
     * @param array<string, mixed> $row one raw checkpoint message, as
     *                                  {@see \SugarCraft\Crush\Session\EnhancedSessionStore::saveCheckpoint()}
     *                                  serialised it
     */
    public static function reviveCheckpointMessage(array $row): Message
    {
        $content = \is_string($row['content'] ?? null) ? $row['content'] : '';
        $pendingId = $row['pendingToolCallId'] ?? null;

        if (\is_string($pendingId) && $pendingId !== '') {
            return Message::assistant(self::INTERRUPTED_TOOL_CALL)
                ->withToolResults([ToolResult::error(
                    $content !== '' ? $content : $pendingId,
                    self::INTERRUPTED_TOOL_CALL,
                    $pendingId,
                )]);
        }

        return match ($row['role'] ?? '') {
            'assistant' => Message::assistant($content),
            default     => Message::user($content),
        };
    }

    /**
     * Ctrl+O's target: every tool-call id carried by the most recent
     * tool-result message in history, or [] when the conversation has none.
     *
     * Chat has no cursor or selection model over history - the transcript is
     * a flat rendered string, not a navigable list - so "the last tool call"
     * is the only unambiguous referent a single keystroke can name, and it is
     * also the one a user pressing Ctrl+O right after a call almost always
     * means. A per-result selector belongs with a real history cursor, which
     * this item does not introduce.
     *
     * @return list<string>
     */
    private function latestToolResultIds(): array
    {
        foreach (array_reverse($this->history) as $msg) {
            if ($msg->toolResults === []) {
                continue;
            }

            $ids = [];
            foreach ($msg->toolResults as $result) {
                $ids[] = $result->id ?? $result->name;
            }

            return $ids;
        }

        return [];
    }

    /**
     * Toggle every id {@see latestToolResultIds()} returns as one unit, so a
     * batch of parallel tool calls opens and closes together instead of
     * needing one keypress each. The batch follows the FIRST id's current
     * state so a half-expanded batch converges rather than inverting into a
     * different half-expanded batch.
     *
     * @return array{0: self, 1: null}
     */
    private function toggleLatestToolOutput(): array
    {
        $ids = $this->latestToolResultIds();
        if ($ids === []) {
            return [$this, null];
        }

        $expand = !$this->isToolOutputExpanded($ids[0]);
        $expanded = $this->expanded;
        foreach ($ids as $id) {
            if ($expand) {
                $expanded[$id] = true;
            } else {
                unset($expanded[$id]);
            }
        }

        return [$this->mutate(['expanded' => $expanded]), null];
    }

    /**
     * Timestamp of the last real user prompt submitted through submit(),
     * or null if none has been recorded yet on this instance.
     */
    public function lastActivityAt(): ?\DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    /**
     * Create a new Chat with an explicit last-activity timestamp. Mainly
     * useful for tests that need to simulate an idle session.
     */
    public function withLastActivity(\DateTimeImmutable $lastActivityAt): self
    {
        return $this->mutate(['lastActivityAt' => $lastActivityAt]);
    }

    /**
     * Merge changes into a new Chat instance.
     *
     * Only constructor-promoted properties are passed through.
     *
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): static
    {
        $constructorProps = [
            'history' => $this->history,
            'inputBuf' => $this->inputBuf,
            'inFlight' => $this->inFlight,
            'streaming' => $this->streaming,
            'onToken' => $this->onToken,
            'tools' => $this->tools,
            'onToolCall' => $this->onToolCall,
            'agentPoolConfig' => $this->agentPoolConfig,
            'effectivePool' => $this->effectivePool,
            'backend' => $this->backend,
            'workflowEngine' => $this->workflowEngine,
            'agentManager' => $this->agentManager,
            'compactorConfig' => null, // compactor is reconstructed from null config (uses default)
            'memoryStore' => $this->memoryStore,
            'sessionStore' => $this->sessionStore,
            'currentSessionId' => $this->currentSessionId,
            'lastActivityAt' => $this->lastActivityAt,
            'slashMenuIndex' => $this->slashMenuIndex,
            'themeName' => $this->themeName,
            'palette' => $this->palette,
            'onConfigChange' => $this->onConfigChange,
            'generation' => $this->generation,
            'inFlightCancellation' => $this->inFlightCancellation,
            'lastEscapeAt' => $this->lastEscapeAt,
            'rows' => $this->rows,
            'cols' => $this->cols,
            'mosaic' => $this->mosaic,
            'hooks' => $this->hooks,
            'currentSessionName' => $this->currentSessionName,
            'titleBackend' => $this->titleBackend,
            'pendingPermission' => $this->pendingPermission,
            'permissionDeferred' => $this->permissionDeferred,
            'pendingPermissionJobs' => $this->pendingPermissionJobs,
            'permissionGrants' => $this->permissionGrants,
            'expanded' => $this->expanded,
            'paletteMru' => $this->paletteMru,
            'scrollOffset' => $this->scrollOffset,
            'backgroundSupervisor' => $this->backgroundSupervisor,
            'backgroundStatuses' => $this->backgroundStatuses,
        ];

        return new self(...array_merge($constructorProps, $changes));
    }

    public function withStreaming(bool $enable): self
    {
        return $this->mutate(['streaming' => $enable]);
    }

    public function onToken(callable $callback): self
    {
        return $this->mutate([
            'onToken' => $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback),
        ]);
    }

    /**
     * Register a tool/function that the AI can call.
     *
     * @param string $name The tool name (must be unique)
     * @param callable(array $arguments): mixed $callback The function to call
     * @return self A new Chat with the tool registered
     */
    public function registerTool(string $name, callable $callback): self
    {
        $tools = $this->tools;
        $tools[$name] = $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback);
        return $this->mutate(['tools' => $tools]);
    }

    /**
     * Register a callback for tool call events.
     *
     * @param callable(string $name, array $arguments, mixed $result): void $callback
     * @return self
     */
    public function onToolCall(callable $callback): self
    {
        return $this->mutate([
            'onToolCall' => $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback),
        ]);
    }

    /**
     * Register a callback(string $key, string $value): void fired when the
     * Switch Model/Switch Theme palette actions (or /theme) apply a choice
     * - see the constructor param's docblock for why the actual persistence
     * side effect lives in Bootstrap::chat()'s wiring, not this class.
     */
    public function withOnConfigChange(callable $callback): self
    {
        return $this->mutate([
            'onConfigChange' => $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback),
        ]);
    }

    public function isStreaming(): bool
    {
        return $this->streaming;
    }

    /**
     * @return array<string, callable>
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * @return array{0:Chat,1:?\Closure}
     */
    private function submit(): array
    {
        $text = trim($this->inputBuf);
        if ($text === '') {
            return [$this, null];
        }

        // Handle /exit (and /quit) - same as Ctrl+C / the palette's Exit
        // action, just reachable without a modifier key.
        if ($text === '/exit' || $text === '/quit') {
            return [$this, Cmd::quit()];
        }

        // Handle /compact command to manually compact chat history
        if (str_starts_with($text, '/compact')) {
            return $this->handleCompactCommand($text);
        }

        // Handle /workflow commands locally without calling the backend
        if (str_starts_with($text, '/workflow')) {
            return $this->handleWorkflowCommand($text);
        }

        // Handle /share commands locally
        if (str_starts_with($text, '/share')) {
            return $this->handleShareCommand($text);
        }

        // Handle /agent (and /agents) commands locally
        if (str_starts_with($text, '/agent')) {
            return $this->handleAgentsCommand($text);
        }

        // Handle /memory commands locally
        if (str_starts_with($text, '/memory')) {
            return $this->handleMemoryCommand($text);
        }

        // Handle /bg (and its /background spelling) - dispatch a task onto
        // BackgroundSupervisor (crush_feat.md section 5 E3). Checked before
        // /branch only for readability; the two prefixes cannot collide.
        if (str_starts_with($text, '/bg') || str_starts_with($text, '/background')) {
            return $this->handleBackgroundCommand($text);
        }

        // Handle /fork command (clone this conversation into a background session)
        if (str_starts_with($text, '/fork')) {
            return $this->handleForkCommand($text);
        }

        // Handle /branch command (fork current session)
        if (str_starts_with($text, '/branch')) {
            return $this->handleBranchCommand($text);
        }

        // Handle /rename command (name current session)
        if (str_starts_with($text, '/rename')) {
            return $this->handleRenameCommand($text);
        }

        // Handle /rewind command (restore from checkpoint)
        if (str_starts_with($text, '/rewind')) {
            return $this->handleRewindCommand($text);
        }

        // Handle /sessions command (R20: list + render the real SessionPicker)
        if (str_starts_with($text, '/sessions')) {
            return $this->handleSessionsCommand($text);
        }

        // Handle /theme command (switch color theme)
        if (str_starts_with($text, '/theme')) {
            return $this->handleThemeCommand($text);
        }

        // Handle /mcp (the discoverable spelling) and the bare "mcp auth …"
        // form it replaces. The bare form has no leading slash, so it never
        // showed up in the "/" popup; it stays dispatched here so existing
        // muscle memory and the palette's ToggleMcp action keep working.
        if (str_starts_with($text, '/mcp') || str_starts_with($text, 'mcp auth')) {
            return $this->handleMcpAuthCommand($text);
        }

        // Idle-compaction check, once per turn, before dispatching a real
        // prompt to the backend. shouldPromptIdleCompaction() previously had
        // no live call site anywhere in the codebase — only tests invoked it
        // directly — so an idle, oversized session never actually got
        // nudged toward /compact.
        $tokenCount = $this->estimateTokenCount($this->history);
        if ($this->shouldPromptIdleCompaction($tokenCount, $this->lastActivityAt)) {
            return $this->idleCompactionPromptResponse($text, $tokenCount);
        }

        // Reminder-tier check (R21's ContextCompactor::shouldSendReminder(),
        // 70% of the token budget by default). Unlike the idle-compaction
        // prompt above — which short-circuits the turn entirely and never
        // calls the backend — this is a soft, non-blocking notice: the real
        // prompt still goes out, but a system-role warning is appended
        // alongside it so the user sees context is filling up well before
        // the hard 85%/95% compaction tiers would kick in.
        $wireHistory = array_map(
            static fn(Message $msg): array => $msg->toWire(),
            $this->history
        );
        $sendReminder = $this->compactor->shouldSendReminder($wireHistory, self::REMINDER_TOKEN_LIMIT);

        $newTurnMessages = [Message::user($text)];
        if ($sendReminder) {
            $newTurnMessages[] = $this->contextReminderMessage($tokenCount);
        }

        $generation = $this->generation + 1;
        $cancellation = new CancellationToken();
        $next = $this->mutate([
            'history' => [...$this->history, ...$newTurnMessages],
            'inputBuf' => '',
            'inFlight' => true,
            'inFlightCancellation' => $cancellation,
            'generation' => $generation,
            'lastActivityAt' => new \DateTimeImmutable(),
        ]);

        // Auto-save checkpoint before processing prompt
        if ($this->sessionStore !== null && $this->currentSessionId !== null && method_exists($this->sessionStore, 'saveCheckpoint')) {
            $chatState = [
                'messages' => $next->history,
                'inputBuf' => $next->inputBuf,
                'inFlight' => false,
                'agentContext' => [
                    'currentSessionId' => $this->currentSessionId,
                ],
            ];
            try {
                $this->sessionStore->saveCheckpoint($this->currentSessionId, $chatState);
            } catch (\Throwable) {
                // Ignore checkpoint save errors - don't block the prompt
            }
        }

        $completion = $this->scheduleBackendCompletion($next, $cancellation, $generation);
        $titleCmd = $this->scheduleTitleGeneration($next);

        // Batched, not sequenced: the title call must never delay the reply
        // the user is actually waiting on. Only wrap when there IS a title
        // Cmd so the common (unnamed-store-less) path keeps returning the
        // completion Cmd itself.
        return [$next, $titleCmd === null ? $completion : Cmd::batch($completion, $titleCmd)];
    }

    /**
     * Build the fire-and-forget Cmd that asks a small model to name the
     * session, or null when this turn shouldn't trigger one.
     *
     * Fires at most once per session, on the first real user turn, and
     * only when there is a store to persist into and no name yet (manual
     * `/rename` or a prior auto-title both latch `currentSessionName`).
     * Mirrors opencode's `ensureTitle()` gating.
     *
     * The request is built here and nowhere else, on its own Backend, with
     * no `$onToken`/`$onEvent`/cancellation threaded through: opencode's
     * #20269 was a main-turn parameter leaking into this cheap side-call
     * via a shared builder and silently killing titling.
     *
     * Failure is silent by design — a session that stays unnamed is a
     * non-event, and surfacing a title-generation error mid-turn is worse
     * than no title.
     */
    private function scheduleTitleGeneration(self $next): ?\Closure
    {
        $store = $this->sessionStore;
        $sessionId = $next->currentSessionId;
        if ($store === null || $sessionId === null || $next->currentSessionName !== null) {
            return null;
        }

        $userTurns = 0;
        foreach ($next->history as $message) {
            if ($message->role === Role::User) {
                ++$userTurns;
            }
        }
        if ($userTurns !== 1) {
            return null;
        }

        $backend = $next->titleBackend ?? $next->backend;
        $titlePrompt = [Message::system(self::TITLE_PROMPT), ...$next->history];

        return Cmd::promise(static function () use ($backend, $titlePrompt, $sessionId, $store): PromiseInterface {
            return $backend->completeAsync($titlePrompt)->then(
                static function (Message $msg) use ($store, $sessionId): ?Msg {
                    $title = self::sanitizeSessionTitle($msg->content);
                    if ($title === '') {
                        return null;
                    }
                    try {
                        $store->renameSession($sessionId, $title);
                    } catch (\Throwable) {
                        return null;
                    }
                    return new SessionTitledMsg($sessionId, $title);
                },
                static fn(\Throwable $e): ?Msg => null,
            );
        });
    }

    /**
     * Reduce raw model output to something safe to persist and to paint
     * into a one-line-per-tab strip.
     *
     * A title is untrusted text from a model: left alone it can carry an
     * ESC sequence that repaints the chrome around the tab, or embedded
     * newlines that blow the strip's single-row layout apart. Reasoning
     * models additionally prefix a `<think>` block that is not the answer.
     */
    private static function sanitizeSessionTitle(string $raw): string
    {
        $text = preg_replace('#<think>.*?</think>#is', '', $raw) ?? $raw;
        // OSC (…BEL or ST terminated) before CSI, so an OSC payload
        // containing bracket bytes isn't shredded into visible garbage.
        $text = preg_replace('/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)?/', '', $text) ?? $text;
        $text = preg_replace('/\x1b[@-_][0-?]*[ -\/]*[@-~]?/', '', $text) ?? $text;
        // Everything else in C0 except the newlines the line split needs.
        $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text) ?? $text;

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                return trim(mb_substr($line, 0, self::TITLE_MAX_CHARS));
            }
        }

        return '';
    }

    /**
     * Build the Cmd that calls the backend with $next's history and
     * dispatches its outcome as an {@see AssistantMsg} stamped with
     * $generation - the common tail {@see submit()} and the tool-call
     * pipeline (see {@see beginToolCalls()}/{@see ToolResultsMsg}) both
     * schedule once their turn's history is settled.
     *
     * Also the point where the backend's `$onEvent` tool-lifecycle seam is
     * consumed (crush_feat.md §1 E1). The callback only QUEUES events: it runs
     * inside the backend, where there is no dispatcher and no way to mutate an
     * immutable Chat, so the queue rides out on the resolved Msg and
     * {@see applyBackendToolEvent()} turns it into transcript states one event
     * at a time. A turn that called no tools resolves to a plain
     * {@see AssistantMsg} exactly as before.
     */
    private function scheduleBackendCompletion(self $next, CancellationToken $cancellation, int $generation): \Closure
    {
        $backend = $next->backend;
        $history = $next->history;
        $onToken = $next->streaming ? $next->onToken : null;

        return Cmd::promise(static function () use ($backend, $history, $onToken, $cancellation, $generation): PromiseInterface {
            /** @var list<ToolStarted|ToolFinished> $events */
            $events = [];
            $onEvent = static function (ToolStarted|ToolFinished $event) use (&$events): void {
                $events[] = $event;
            };

            // Both handlers capture $events BY REFERENCE: the closures are
            // built before the backend has run, so a by-value capture would
            // freeze the queue while it is still empty.
            return $backend->completeAsync($history, $onToken, $cancellation, $onEvent)->then(
                static function (Message $msg) use (&$events, $generation): ?Msg {
                    return $events === []
                        ? new AssistantMsg($msg, $generation)
                        : new BackendToolEventsMsg($events, $msg, $generation);
                },
                static function (\Throwable $e) use (&$events, $generation): ?Msg {
                    // A turn that failed AFTER running tools still shows what
                    // those tools did - otherwise the placeholders queued for
                    // them would be the only trace and they never even render.
                    $message = Message::assistant('_[error: ' . $e->getMessage() . ']_');

                    return $events === []
                        ? new AssistantMsg($message, $generation)
                        : new BackendToolEventsMsg($events, $message, $generation);
                },
            );
        });
    }

    /**
     * Handle /workflow commands locally.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleWorkflowCommand(string $inputText): array
    {
        // Check if workflow engine is configured
        if ($this->workflowEngine === null) {
            $response = "Workflow engine not configured. Set a WorkflowEngine to use /workflow commands.";
            return $this->workflowResponse($inputText, $response);
        }

        $afterWorkflow = ltrim(substr($inputText, 9));
        if ($afterWorkflow === '') {
            return $this->workflowHelpResponse($inputText);
        }

        $parts = preg_split('/\s+/', $afterWorkflow, 2);
        $command = $parts[0];
        $args = $parts[1] ?? '';

        return match ($command) {
            'run' => $this->workflowRun($inputText, $args),
            'pause' => $this->workflowPause($inputText, $args),
            'resume' => $this->workflowResume($inputText, $args),
            'status' => $this->workflowStatus($inputText, $args),
            'list' => $this->workflowList($inputText),
            default => $this->workflowHelpResponse($inputText, "Unknown command '{$command}'."),
        };
    }

    /**
     * Return a workflow command response, adding both user command and assistant response to history.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function workflowResponse(string $inputText, string $response): array
    {
        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputText), Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
        ]);
        return [$next, null];
    }

    /**
     * Show help text for /workflow command.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function workflowHelpResponse(string $inputText, ?string $error = null): array
    {
        $lines = [];
        if ($error !== null) {
            $lines[] = "**Error:** {$error}";
            $lines[] = '';
        }
        $lines[] = '**Available /workflow commands:**';
        $lines[] = '';
        $lines[] = '`/workflow run <name> [key=val ...]` — Run a workflow by name with optional context';
        $lines[] = '`/workflow pause <workflowId>` — Pause a running workflow';
        $lines[] = '`/workflow resume <workflowId>` — Resume a paused workflow';
        $lines[] = '`/workflow status <workflowId>` — Check workflow status';
        $lines[] = '`/workflow list` — List available workflows';
        $lines[] = '`/workflow` — Show this help text';
        $lines[] = '';
        $lines[] = "Note: pause/resume granularity is per-whole-stage only. A real interrupt "
            . "(Ctrl-C/SIGTERM) captures whatever stages have genuinely finished so far, but if it "
            . "lands while a 'parallel' stage is mid-flight, that stage's individual in-progress "
            . "agent results are NOT captured — the stage is simply re-run from scratch on resume. "
            . "There is no partial-credit resume for a parallel sub-stage.";

        return $this->workflowResponse($inputText, implode("\n", $lines));
    }

    /**
     * Handle /workflow run command.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function workflowRun(string $inputText, string $args): array
    {
        $argParts = preg_split('/\s+/', $args);
        $workflowName = $argParts[0] ?? '';

        if ($workflowName === '') {
            return $this->workflowHelpResponse($inputText, "Usage: /workflow run <name> [key=val ...]");
        }

        // Parse key=val context pairs
        $context = [];
        foreach (array_slice($argParts, 1) as $pair) {
            if (str_contains($pair, '=')) {
                [$k, $v] = explode('=', $pair, 2);
                $context[trim($k)] = trim($v);
            }
        }

        try {
            $result = $this->workflowEngine->run($workflowName, $context);
            $response = "**Workflow '{$workflowName}' completed**\n\n";
            $response .= "ID: `{$result->workflowId}`\n";
            $response .= "Status: {$result->status->value}\n";
            $response .= "Stages completed: " . count($result->stageResults) . "\n";
            $response .= "Total tokens: {$result->totalTokens}\n";
            $response .= "Total cost: \${$result->totalCost}";
        } catch (WorkflowNotFoundException $e) {
            $response = "**Error:** {$e->getMessage()}";
        } catch (WorkflowLoadException $e) {
            $response = "**Error:** {$e->getMessage()}";
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->workflowResponse($inputText, $response);
    }

    /**
     * Handle /workflow pause command.
     *
     * Pause (cooperative here, or via WorkflowEngine's real SIGINT/SIGTERM
     * handling on a genuine interrupt) captures whatever whole stages have
     * actually completed so far. Resume granularity stays per-whole-stage
     * only: if a 'parallel' stage is mid-flight when the pause happens, its
     * individual in-progress agent results are not captured and that stage
     * is re-run from scratch on resume — there is no partial-credit resume
     * for a parallel sub-stage. See WorkflowEngine's class docblock.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function workflowPause(string $inputText, string $args): array
    {
        $workflowId = trim($args);

        if ($workflowId === '') {
            return $this->workflowHelpResponse($inputText, "Usage: /workflow pause <workflowId>");
        }

        try {
            $this->workflowEngine->pause($workflowId);
            $response = "Workflow `{$workflowId}` has been paused.";
        } catch (WorkflowNotRunningException $e) {
            $response = "**Error:** {$e->getMessage()}";
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->workflowResponse($inputText, $response);
    }

    /**
     * Handle /workflow resume command.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function workflowResume(string $inputText, string $args): array
    {
        $workflowId = trim($args);

        if ($workflowId === '') {
            return $this->workflowHelpResponse($inputText, "Usage: /workflow resume <workflowId>");
        }

        try {
            $result = $this->workflowEngine->resume($workflowId);
            $response = "**Workflow '{$workflowId}' resumed and completed**\n\n";
            $response .= "ID: `{$result->workflowId}`\n";
            $response .= "Status: {$result->status->value}\n";
            $response .= "Stages completed: " . count($result->stageResults) . "\n";
            $response .= "Total tokens: {$result->totalTokens}\n";
            $response .= "Total cost: \${$result->totalCost}";
        } catch (WorkflowNotRunningException $e) {
            $response = "**Error:** {$e->getMessage()}";
        } catch (WorkflowNotFoundException $e) {
            $response = "**Error:** {$e->getMessage()}";
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->workflowResponse($inputText, $response);
    }

    /**
     * Handle /workflow status command.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function workflowStatus(string $inputText, string $args): array
    {
        $workflowId = trim($args);

        if ($workflowId === '') {
            return $this->workflowHelpResponse($inputText, "Usage: /workflow status <workflowId>");
        }

        try {
            $status = $this->workflowEngine->getStatus($workflowId);
            $response = "Workflow `{$workflowId}` status: **{$status->value}**";
        } catch (WorkflowNotRunningException $e) {
            $response = "**Error:** {$e->getMessage()}";
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->workflowResponse($inputText, $response);
    }

    /**
     * Handle /workflow list command.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function workflowList(string $inputText): array
    {
        $workflows = $this->workflowEngine->listWorkflows();

        if ($workflows === []) {
            $response = "No workflows found in `~/.sugar-crush/workflows/`.";
        } else {
            $lines = ['**Available workflows:**'];
            foreach ($workflows as $i => $name) {
                $lines[] = ($i + 1) . ". `{$name}`";
            }
            $response = implode("\n", $lines);
        }

        return $this->workflowResponse($inputText, $response);
    }

    /**
     * Handle /share command locally.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleShareCommand(string $inputBuf): array
    {
        // Parse args from the command (after "/share")
        $afterShare = ltrim(substr($inputBuf, 6));
        $args = $afterShare !== '' ? preg_split('/\s+/', $afterShare) : [];

        // Execute the ShareCommand - it outputs directly to stdout, capture via output buffering
        ob_start();
        $shareCommand = new ShareCommand();
        $exitCode = $shareCommand->execute($this, $args);
        $output = ob_get_clean();

        // ShareCommand returns 0 for success, non-zero for errors
        if ($exitCode !== 0) {
            return [$this, static fn() => print $output];
        }

        return $this->shareResponse($inputBuf, $output);
    }

    /**
     * Return a share command response, adding both user command and assistant response to history.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function shareResponse(string $inputBuf, string $response): array
    {
        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputBuf), Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
        ]);
        return [$next, null];
    }

    private function handleAgentsCommand(string $inputBuf): array
    {
        // R20.fix: Bootstrap::chat() (the real construction path
        // `bin/sugarcrush` uses) never passes an `agentManager:` -- so this
        // was reachable with zero configuration via a typed "/agents" *and*,
        // since R20 added the Ctrl+A shortcut below in update(), via a
        // single accidental keystroke. The former "?? throw" here escaped
        // uncaught out of Chat::update(): candy-core's Program has no
        // try/catch around its synchronous update() dispatch, so the
        // exception propagated out of the event loop entirely, skipping
        // teardownTerminal() and leaving the real terminal in whatever
        // raw/alt-screen state it was in. Degrade gracefully instead, the
        // same "<thing> not configured" pattern every other optional
        // collaborator on this class already follows (see
        // handleWorkflowCommand()/handleSessionsCommand()/
        // handleMemoryCommand() above).
        if ($this->agentManager === null) {
            return $this->agentsResponse($inputBuf, 'Agent manager not configured. Set an AgentManager to use /agents commands.');
        }

        // Parse args from the command (after "/agent" or "/agents").
        // /agents is 7 chars, /agent is 6 chars - detect which alias was used
        // by full-prefix match (not just presence of a trailing space) so a
        // bare "/agents" with no arguments slices off all 7 chars and yields
        // an empty $afterCommand instead of the trailing "s" of "/agents".
        $prefixLength = str_starts_with($inputBuf, '/agents') ? 7 : 6;
        $afterCommand = ltrim(substr($inputBuf, $prefixLength));
        $args = $afterCommand !== '' ? preg_split('/\s+/', $afterCommand) : [];

        // Execute the AgentsCommand - it outputs directly to stdout, capture via output buffering
        ob_start();
        $agentsCommand = new AgentsCommand($this->agentManager);
        $exitCode = $agentsCommand->execute($this, $args);
        $output = ob_get_clean();

        // If command failed (non-zero exit code), output error but don't add to history
        if ($exitCode !== 0) {
            return [$this, static fn() => print $output];
        }

        return $this->agentsResponse($inputBuf, $output);
    }

    /**
     * Return an agents command response, adding both user command and assistant response to history.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function agentsResponse(string $inputBuf, string $response): array
    {
        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputBuf), Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
        ]);
        return [$next, null];
    }

    /**
     * Handle /compact command to manually compact chat history.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleCompactCommand(string $inputText): array
    {
        $originalCount = count($this->history);

        // Convert history to wire format for the compactor
        $wireHistory = array_map(
            static fn(Message $msg): array => $msg->toWire(),
            $this->history
        );

        // Compact the history using all 5 stages
        $compactedWire = $this->compactor->compact($wireHistory);
        $savingsPercentage = $this->compactor->savingsPercentage();

        // Convert back to Message objects
        $compactedHistory = [];
        foreach ($compactedWire as $wire) {
            $role = \SugarCraft\Crush\Role::from($wire['role'] ?? 'assistant');
            $content = $wire['content'] ?? '';
            $compactedHistory[] = match ($role) {
                Role::User => Message::user($content),
                Role::Assistant => Message::assistant($content),
                default => new Message($role, $content, time()),
            };
        }

        $newCount = count($compactedHistory);

        // Build response message
        if ($originalCount === 0) {
            $response = "Nothing to compact: chat history is empty.";
        } else {
            $response = "Context compacted: was {$originalCount} messages, now {$newCount} messages (saved {$savingsPercentage}% tokens)";
        }

        $next = $this->mutate([
            'history' => [...$compactedHistory, Message::user($inputText), Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
        ]);

        return [$next, null];
    }

    /**
     * Handle /sessions command — list all sessions via the real
     * {@see SessionPicker}.
     *
     * Unlike /branch, /rename, and /rewind (which each act on the *current*
     * session), /sessions builds SessionPicker from every row
     * {@see SessionStore::listSessions()} actually returns and renders it
     * exactly as the interactive picker would — this is the concrete,
     * testable proof that R19's real `SessionStore` wiring is reachable for
     * more than checkpoint/branch bookkeeping. The rendered picker text is
     * folded into an assistant turn (same shape every other local command in
     * this file uses via `sessionResponse()`/`*Response()`), rather than
     * SessionPicker's own keyboard navigation being wired live — that would
     * need Chat to persist a `SessionPicker` instance across turns, widening
     * this file's constructor/`mutate()` surface well beyond this item's
     * "KeyMsg dispatch site / new /sessions command site" scope.
     *
     * R20.fix disclosure: in a real `bin/sugarcrush` run this will render
     * `SessionPicker::new([])` (an empty picker), not a populated list —
     * no production path (`Bootstrap::chat()`, `Chat::init()`, or any other
     * `src/`/`bin/` call site) ever calls
     * `SessionStore::createSession()`/`EnhancedSessionStore::createSession()`,
     * so `listSessions()` has no rows to return until that separate,
     * out-of-scope wiring lands. `SessionCommandTest` covers this method by
     * calling `createSession()` on the store directly, which is why the test
     * passes today despite this being unreachable with real data. See the
     * matching note on `Renderer::renderSessionTabStrip()`'s class docblock.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleSessionsCommand(string $inputText): array
    {
        if ($this->sessionStore === null) {
            return $this->sessionResponse($inputText, 'Session store not configured. Set a SessionStore to use /sessions.');
        }

        $rows = $this->sessionStore->listSessions();
        $sessions = array_map(
            static function (array $row): array {
                $id = (string) ($row['id'] ?? '');
                $name = (string) ($row['name'] ?? '');

                return [
                    'sessionId' => $id,
                    'sessionName' => $name !== '' ? $name : $id,
                    'summary' => (string) ($row['system_prompt'] ?? ''),
                    'gitBranch' => null,
                    'lastActivity' => (string) ($row['updated_at'] ?? ''),
                ];
            },
            $rows,
        );

        $picker = SessionPicker::new($sessions);
        $size = TuiRenderer::getTerminalSize();
        $response = $picker->render($size['cols'], $size['rows']);

        return $this->sessionResponse($inputText, $response);
    }

    /**
     * Return a session command response, adding both user command and assistant response to history.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function sessionResponse(string $inputText, string $response): array
    {
        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputText), Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
        ]);
        return [$next, null];
    }

    /**
     * Handle /branch command — fork the current session.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleBranchCommand(string $inputText): array
    {
        if ($this->sessionStore === null) {
            return $this->sessionResponse($inputText, 'Session store not configured. Set a SessionStore to use /branch and /rename commands.');
        }

        // /branch takes no arguments
        $afterBranch = ltrim(substr($inputText, 7)); // after "/branch"

        if ($this->currentSessionId === null) {
            return $this->sessionResponse($inputText, 'No active session. Start a new conversation first.');
        }

        if ($afterBranch !== '') {
            return $this->sessionResponse($inputText, 'Usage: /branch (takes no arguments)');
        }

        try {
            $newSessionId = $this->sessionStore->forkSession($this->currentSessionId);
            $response = "Branch created: {$newSessionId}";
        } catch (\InvalidArgumentException $e) {
            $response = "Error: {$e->getMessage()}";
        } catch (\Throwable $e) {
            $response = "Error: {$e->getMessage()}";
        }

        // Return Chat with same state but currentSessionId updated to the new branch
        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputText), Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
            'currentSessionId' => $newSessionId ?? $this->currentSessionId,
        ]);

        return [$next, null];
    }

    /**
     * Handle /bg (alias /background) — dispatch a task onto
     * {@see \SugarCraft\Crush\Sessions\BackgroundSupervisor} and hand the
     * prompt straight back (crush_feat.md section 5 E3).
     *
     * Claude Code's `/background` with no argument backgrounds the LIVE
     * conversation; that has no counterpart here, because `spawnSession()`
     * hands the child a task string, not a transcript, so an argument-less
     * `/bg` is answered with usage rather than silently backgrounding
     * something else.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleBackgroundCommand(string $inputText): array
    {
        if ($this->backgroundSupervisor === null) {
            return $this->sessionResponse($inputText, 'Background sessions not configured. Set a BackgroundSupervisor to use /bg and /fork.');
        }

        $task = self::commandArgument($inputText);
        if ($task === '') {
            return $this->sessionResponse($inputText, 'Usage: /bg <task>');
        }

        $name = self::backgroundSessionName($task);

        return $this->backgroundDispatch(
            $inputText,
            $this->scheduleBackgroundSpawn('/bg', $name, $task, null),
        );
    }

    /**
     * Handle /fork — clone this conversation and run $prompt against the
     * clone in a background session.
     *
     * The transcript copy is {@see SessionStore::forkSession()}, the same
     * call `/branch` makes, but `currentSessionId` deliberately stays put:
     * `/branch` MOVES the user onto the new branch, whereas `/fork` leaves
     * them where they are and sends the copy away to work (Claude Code's
     * split between the two). The forked id rides along as a tag so the
     * background session can be traced back to the transcript it came from.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleForkCommand(string $inputText): array
    {
        if ($this->backgroundSupervisor === null) {
            return $this->sessionResponse($inputText, 'Background sessions not configured. Set a BackgroundSupervisor to use /bg and /fork.');
        }

        $prompt = self::commandArgument($inputText);
        if ($prompt === '') {
            return $this->sessionResponse($inputText, 'Usage: /fork <prompt>');
        }

        if ($this->sessionStore === null) {
            return $this->sessionResponse($inputText, 'Session store not configured. Set a SessionStore to use /fork.');
        }

        if ($this->currentSessionId === null) {
            return $this->sessionResponse($inputText, 'No active session. Start a new conversation first.');
        }

        try {
            $forkedSessionId = $this->sessionStore->forkSession($this->currentSessionId);
        } catch (\Throwable $e) {
            return $this->sessionResponse($inputText, "Error: {$e->getMessage()}");
        }

        $name = self::backgroundSessionName($prompt);

        return $this->backgroundDispatch(
            $inputText,
            $this->scheduleBackgroundSpawn('/fork', $name, $prompt, $forkedSessionId),
        );
    }

    /**
     * Common tail of `/bg` and `/fork`: record the command, free the prompt,
     * and let $cmd report the outcome later.
     *
     * No assistant line is written here on purpose - the only honest thing to
     * say at this point is "asked to spawn", and the real answer (session id,
     * or the reason there isn't one) arrives as a
     * {@see BackgroundSessionSpawnedMsg}.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function backgroundDispatch(string $inputText, \Closure $cmd): array
    {
        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputText)],
            'inputBuf' => '',
            'inFlight' => false,
        ]);

        return [$next, $cmd];
    }

    /**
     * Build the Cmd that actually spawns the background session.
     *
     * `spawnSession()` proc_opens a daemon and then blocks on a socket
     * accept (up to 5s) waiting for it to connect. Running that inside
     * `update()` would stall the event loop - no repaint, no keystrokes -
     * for the whole handshake, which is precisely the thing `/bg` exists to
     * avoid, so it runs off-turn like every other side effect on this class
     * ({@see scheduleTitleGeneration()}).
     *
     * A failed spawn resolves rather than rejects: a rejection would surface
     * as candy-core's generic `ExceptionMsg` and lose the command context the
     * transcript line needs.
     *
     * @param string      $command         '/bg' or '/fork', echoed back in the transcript line.
     * @param string|null $forkedSessionId Transcript clone this session continues, tagged onto it; null for a plain /bg.
     */
    private function scheduleBackgroundSpawn(string $command, string $name, string $task, ?string $forkedSessionId): \Closure
    {
        $supervisor = $this->backgroundSupervisor;
        // A registered "default" agent first, then whatever IS registered,
        // then a synthesised stand-in: Bootstrap::chat() does not pass an
        // AgentManager at all today, and refusing to background anything
        // until it does would leave this command as unreachable as the
        // supervisor it drives.
        $agent = $this->agentManager?->get('default')
            ?? ($this->agentManager?->all()[0] ?? null)
            ?? self::defaultBackgroundAgent();
        $workingDirectory = getcwd() ?: '.';
        $tags = $forkedSessionId === null ? null : ['fork', 'session:' . $forkedSessionId];

        return Cmd::promise(static function () use ($supervisor, $command, $name, $task, $agent, $workingDirectory, $tags): PromiseInterface {
            try {
                $session = $supervisor->spawnSession(
                    name: $name,
                    agent: $agent,
                    task: $task,
                    workingDirectory: $workingDirectory,
                    tags: $tags,
                );

                return \React\Promise\resolve(new BackgroundSessionSpawnedMsg($command, $name, $session->id));
            } catch (\Throwable $e) {
                return \React\Promise\resolve(new BackgroundSessionSpawnedMsg($command, $name, null, $e->getMessage()));
            }
        });
    }

    /**
     * The stand-in agent a background session runs as when no AgentManager
     * is wired. Named "default" so a later, real registration replaces it
     * transparently.
     */
    private static function defaultBackgroundAgent(): \SugarCraft\Crush\Agents\Agent
    {
        return new \SugarCraft\Crush\Agents\Agent(
            name: 'default',
            description: 'Background session agent',
            prompt: '',
            model: 'unknown',
            provider: 'unknown',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );
    }

    /**
     * Everything after the leading "/command" token, trimmed; '' when the
     * command was typed bare.
     */
    private static function commandArgument(string $inputText): string
    {
        $parts = preg_split('/\s+/', trim($inputText), 2) ?: [];

        return isset($parts[1]) ? trim($parts[1]) : '';
    }

    /**
     * A one-line, control-character-free session name derived from the task.
     *
     * Reuses {@see sanitizeSessionTitle()} because a task string is typed by
     * the user and can carry pasted ESC sequences or newlines, and the name
     * ends up in a status list rendered one row per session.
     */
    private static function backgroundSessionName(string $task): string
    {
        $name = self::sanitizeSessionTitle($task);

        return $name === '' ? 'Background task' : $name;
    }

    /**
     * Handle /rename command — rename the current session.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleRenameCommand(string $inputText): array
    {
        if ($this->sessionStore === null) {
            return $this->sessionResponse($inputText, 'Session store not configured. Set a SessionStore to use /branch and /rename commands.');
        }

        // /rename requires exactly one argument: the new name
        $afterRename = ltrim(substr($inputText, 7)); // after "/rename"

        if ($afterRename === '') {
            return $this->sessionResponse($inputText, 'Usage: /rename <newName>');
        }

        if ($this->currentSessionId === null) {
            return $this->sessionResponse($inputText, 'No active session. Start a new conversation first.');
        }

        $newName = trim($afterRename);
        if ($newName === '') {
            return $this->sessionResponse($inputText, 'Usage: /rename <newName>');
        }

        try {
            $this->sessionStore->renameSession($this->currentSessionId, $newName);
            // Latch the name in-memory too: it is what suppresses the
            // background auto-title (see scheduleTitleGeneration()) from
            // later overwriting a name the user chose by hand.
            return $this->mutate(['currentSessionName' => $newName])
                ->sessionResponse($inputText, "Session renamed to '{$newName}'");
        } catch (\InvalidArgumentException $e) {
            $response = "Error: {$e->getMessage()}";
        } catch (\Throwable $e) {
            $response = "Error: {$e->getMessage()}";
        }

        return $this->sessionResponse($inputText, $response);
    }

    /**
     * Handle /rewind command — restore chat state from a checkpoint.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleRewindCommand(string $inputText): array
    {
        if ($this->sessionStore === null) {
            return $this->sessionResponse($inputText, 'Session store not configured.');
        }

        if (!method_exists($this->sessionStore, 'restoreCheckpoint')) {
            return $this->sessionResponse($inputText, 'Session store does not support checkpoints. Use an EnhancedSessionStore.');
        }

        if ($this->currentSessionId === null) {
            return $this->sessionResponse($inputText, 'No active session. Start a new conversation first.');
        }

        // Parse optional step count: /rewind or /rewind <n>
        $afterRewind = ltrim(substr($inputText, 7)); // after "/rewind"
        $stepsBack = 1;

        if ($afterRewind !== '') {
            $stepsBack = (int) trim($afterRewind);
            if ($stepsBack < 1) {
                $stepsBack = 1;
            }
        }

        try {
            // Get list of checkpoints to find the target
            $checkpoints = $this->sessionStore->listCheckpoints($this->currentSessionId, $stepsBack);

            if (empty($checkpoints)) {
                return $this->sessionResponse($inputText, 'No checkpoints available to rewind to.');
            }

            // Find the checkpoint N steps back (where N is stepsBack)
            $targetIndex = $checkpoints[min($stepsBack - 1, count($checkpoints) - 1)]['index'] ?? null;

            if ($targetIndex === null) {
                return $this->sessionResponse($inputText, 'Could not determine checkpoint index.');
            }

            // Restore the checkpoint
            $state = $this->sessionStore->restoreCheckpoint($this->currentSessionId, $targetIndex);

            if ($state === null) {
                return $this->sessionResponse($inputText, "Checkpoint {$targetIndex} not found.");
            }

            // Extract state data
            $messages = $state['state_data']['messages'] ?? $state['messages'] ?? [];
            // Convert raw arrays to Message objects before passing to Chat
            // constructor, healing any placeholder whose tool call died with
            // the checkpointing process (crush_feat.md §1 E7).
            $messages = array_map(
                static fn(array $msg): Message => self::reviveCheckpointMessage($msg),
                $messages,
            );
            $inputBuf = $state['state_data']['inputBuf'] ?? $state['inputBuf'] ?? '';

            // Build response
            $rewoundCount = count($this->history) - count($messages);
            $response = "Rewound {$rewoundCount} messages to checkpoint {$targetIndex}. Use /branch to save this state before continuing.";

            // Return Chat with restored state
            $next = $this->mutate([
                'history' => [...$messages, Message::user($inputText), Message::assistant($response)],
                'inputBuf' => '',
                'inFlight' => false,
            ]);

            return [$next, null];
        } catch (\Throwable $e) {
            return $this->sessionResponse($inputText, "Error during rewind: {$e->getMessage()}");
        }
    }

    /**
     * Handle /memory commands locally.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleMemoryCommand(string $inputText): array
    {
        if ($this->memoryStore === null) {
            return $this->memoryResponse($inputText, 'Memory store not configured. Set a MemoryStore to use /memory commands.');
        }

        $afterMemory = ltrim(substr($inputText, 7)); // after "/memory"
        if ($afterMemory === '') {
            return $this->memoryHelpResponse($inputText);
        }

        $parts = preg_split('/\s+/', $afterMemory, 2);
        $command = $parts[0];
        $args = $parts[1] ?? '';

        return match ($command) {
            'list' => $this->memoryList($inputText, $args),
            'add' => $this->memoryAdd($inputText, $args),
            'search' => $this->memorySearch($inputText, $args),
            'delete' => $this->memoryDelete($inputText, $args),
            'clear' => $this->memoryClear($inputText, $args),
            'edit' => $this->memoryEdit($inputText, $args),
            default => $this->memoryHelpResponse($inputText, "Unknown command '{$command}'."),
        };
    }

    /**
     * Return a memory command response, adding both user command and assistant response to history.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function memoryResponse(string $inputText, string $response): array
    {
        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputText), Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
        ]);
        return [$next, null];
    }

    /**
     * Show help text for /memory command.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function memoryHelpResponse(string $inputText, ?string $error = null): array
    {
        $lines = [];
        if ($error !== null) {
            $lines[] = "**Error:** {$error}";
            $lines[] = '';
        }
        $lines[] = '**Available /memory commands:**';
        $lines[] = '';
        $lines[] = '`/memory list [scope]` — List all memories for a scope (default: user)';
        $lines[] = '`/memory add <content>` — Add a new memory entry';
        $lines[] = '`/memory search <query>` — Search memories by content';
        $lines[] = '`/memory delete <id>` — Delete a memory by ID';
        $lines[] = '`/memory edit <id> <new_content>` — Edit an existing memory';
        $lines[] = '`/memory clear --scope <scope> --confirm` — Clear all memories for a scope';
        $lines[] = '`/memory` — Show this help text';
        $lines[] = '';
        $lines[] = 'Scopes: `user` (default), `project`, `agent`';

        return $this->memoryResponse($inputText, implode("\n", $lines));
    }

    /**
     * Handle /memory add <content> [--scope <scope>].
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function memoryAdd(string $inputText, string $args): array
    {
        if ($args === '') {
            return $this->memoryHelpResponse($inputText, 'Usage: /memory add <content> [--scope <scope>]');
        }

        // Parse --scope flag if present (can be before or after content)
        $scope = 'user';
        $content = $args;

        if (preg_match('/^--scope\s+(user|project|agent)\s+(.*)$/s', $args, $m)) {
            $scope = $m[1];
            $content = trim($m[2]);
        } elseif (preg_match('/^(.*?)\s+--scope\s+(user|project|agent)\s*$/s', $args, $m)) {
            $content = trim($m[1]);
            $scope = $m[2];
        }

        if ($content === '') {
            return $this->memoryHelpResponse($inputText, 'Usage: /memory add <content> [--scope <scope>]');
        }

        try {
            $id = $this->memoryStore->add($content, $scope);
            $response = "Memory created with ID: `{$id}` (scope: {$scope})";
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->memoryResponse($inputText, $response);
    }

    /**
     * Handle /memory list [scope].
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function memoryList(string $inputText, string $args): array
    {
        $scope = 'user';
        if ($args !== '') {
            $trimmed = trim($args);
            // Handle --scope <scope> syntax
            if (str_starts_with($trimmed, '--scope ')) {
                $scopeCandidate = trim(substr($trimmed, 8));
                if (in_array($scopeCandidate, ['user', 'project', 'agent'], true)) {
                    $scope = $scopeCandidate;
                }
            } elseif (in_array($trimmed, ['user', 'project', 'agent'], true)) {
                $scope = $trimmed;
            }
        }

        try {
            $entries = $this->memoryStore->list($scope);
            if ($entries === []) {
                $response = "No memories found for scope `{$scope}`.";
            } else {
                $lines = ["**Memories ({$scope}):**", ''];
                foreach ($entries as $entry) {
                    $tags = empty($entry->tags()) ? '' : ' [' . implode(', ', $entry->tags()) . ']';
                    $preview = mb_strlen($entry->content()) > 80
                        ? mb_substr($entry->content(), 0, 80) . '…'
                        : $entry->content();
                    $lines[] = '- **[' . $entry->type() . ']** `' . $entry->id() . '`' . $tags;
                    $lines[] = '  ' . $preview;
                }
                $response = implode("\n", $lines);
            }
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->memoryResponse($inputText, $response);
    }

    /**
     * Handle /memory search <query>.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function memorySearch(string $inputText, string $query): array
    {
        if ($query === '') {
            return $this->memoryHelpResponse($inputText, 'Usage: /memory search <query>');
        }

        try {
            $entries = $this->memoryStore->search($query);
            if ($entries === []) {
                $response = "No memories found matching `{$query}`.";
            } else {
                $lines = ["**Search results for `{$query}` ({$this->pluralize(count($entries), 'match')}):**", ''];
                foreach ($entries as $entry) {
                    $tags = empty($entry->tags()) ? '' : ' [' . implode(', ', $entry->tags()) . ']';
                    $preview = mb_strlen($entry->content()) > 80
                        ? mb_substr($entry->content(), 0, 80) . '…'
                        : $entry->content();
                    $lines[] = '- **[' . $entry->type() . ']** `' . $entry->id() . '` (scope: ' . $entry->scope() . ')' . $tags;
                    $lines[] = '  ' . $preview;
                }
                $response = implode("\n", $lines);
            }
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->memoryResponse($inputText, $response);
    }

    /**
     * Handle /memory delete <id>.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function memoryDelete(string $inputText, string $args): array
    {
        $id = trim($args);
        if ($id === '') {
            return $this->memoryHelpResponse($inputText, 'Usage: /memory delete <id>');
        }

        try {
            $entry = $this->memoryStore->get($id);
            if ($entry === null) {
                $response = "Memory `{$id}` not found.";
            } else {
                $this->memoryStore->delete($id);
                $response = "Memory `{$id}` deleted.";
            }
        } catch (\InvalidArgumentException $e) {
            $response = "**Error:** {$e->getMessage()}";
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->memoryResponse($inputText, $response);
    }

    /**
     * Handle /memory clear --scope <scope> --confirm.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function memoryClear(string $inputText, string $args): array
    {
        // Parse --scope and --confirm flags
        if (!preg_match('/--scope\s+(user|project|agent)\s+--confirm/s', $args, $m)) {
            return $this->memoryHelpResponse($inputText, 'Usage: /memory clear --scope <scope> --confirm');
        }

        $scope = $m[1];

        try {
            $this->memoryStore->clear($scope);
            $response = "All memories cleared for scope `{$scope}`.";
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->memoryResponse($inputText, $response);
    }

    /**
     * Handle /memory edit <id> <new_content>.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function memoryEdit(string $inputText, string $args): array
    {
        // Parse: <id> <new_content> — split on first whitespace, id is first token, rest is content
        $firstSpace = strpos($args, ' ');
        if ($firstSpace === false) {
            return $this->memoryHelpResponse($inputText, 'Usage: /memory edit <id> <new_content>');
        }

        $id = trim(substr($args, 0, $firstSpace));
        $newContent = trim(substr($args, $firstSpace + 1));

        if ($id === '' || $newContent === '') {
            return $this->memoryHelpResponse($inputText, 'Usage: /memory edit <id> <new_content>');
        }

        try {
            $entry = $this->memoryStore->get($id);
            if ($entry === null) {
                $response = "Memory `{$id}` not found.";
            } else {
                $this->memoryStore->update($id, $entry->withContent($newContent));
                $response = "Memory `{$id}` updated.";
            }
        } catch (\InvalidArgumentException $e) {
            $response = "**Error:** {$e->getMessage()}";
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->memoryResponse($inputText, $response);
    }

    /**
     * Show help text for /session commands.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function sessionHelpResponse(string $inputText, ?string $error = null): array
    {
        $lines = [];
        if ($error !== null) {
            $lines[] = "**Error:** {$error}";
            $lines[] = '';
        }
        $lines[] = '**Available /session commands:**';
        $lines[] = '';
        $lines[] = '`/rename <name>` — Name the current session for easy resume';
        $lines[] = '`/branch` — Fork the current session into a new copy';
        $lines[] = '`/rewind [n]` — Rewind n steps (default: 1) to a previous checkpoint';
        $lines[] = '`/session` — Show this help text';

        return $this->sessionResponse($inputText, implode("\n", $lines));
    }

    /**
     * Helper to pluralize a word based on count.
     */
    private function pluralize(int $count, string $word): string
    {
        if ($count === 1) {
            return "1 {$word}";
        }
        // Handle words ending in ch, x, s, o → add 'es'
        if (preg_match('/[chxso]$/', $word)) {
            return "{$count} {$word}es";
        }
        return "{$count} {$word}s";
    }

    private function withInputBuf(string $buf): self
    {
        // Resetting slashMenuIndex here (rather than only in the Up/Down
        // handlers) means every inputBuf change - not just the ones that
        // change the filtered match set - re-highlights the top match, so a
        // stale selection index from a previous, differently-filtered list
        // can never leak into the new one. See slashMenuMatches()'s docblock
        // for why this makes the stored index always valid without an
        // explicit clamp on every read.
        return $this->mutate(['inputBuf' => $buf, 'slashMenuIndex' => 0]);
    }

    /**
     * Commands from {@see CommandRegistry} matching the in-progress "/name"
     * being typed - the "/" popup's data source ({@see
     * Renderer::renderSlashMenu()}). Returns [] (hiding the popup) when
     * inputBuf isn't slash-prefixed, or once it contains a space: at that
     * point the command name is already fixed and the user is typing
     * arguments, so there is nothing left to filter/complete.
     *
     * @return list<\SugarCraft\Crush\Commands\CommandSpec>
     */
    public function slashMenuMatches(): array
    {
        if (!str_starts_with($this->inputBuf, '/') || str_contains($this->inputBuf, ' ')) {
            return [];
        }

        return CommandRegistry::filter(substr($this->inputBuf, 1));
    }

    /**
     * The "/" popup's currently-highlighted row index into {@see
     * slashMenuMatches()}'s current result - always in range for it, never
     * needs clamping by a caller (see {@see withInputBuf()}'s docblock).
     */
    public function slashMenuIndex(): int
    {
        return $this->slashMenuIndex;
    }

    /**
     * The active color theme, resolved from the stored name on every call -
     * cheap (a handful of Color/Theme factory calls, no I/O) and keeps
     * Chat's own stored state to a plain string rather than an object.
     */
    public function theme(): Theme
    {
        return Theme::byName($this->themeName);
    }

    public function withThemeName(string $themeName): self
    {
        return $this->mutate(['themeName' => $themeName]);
    }

    /**
     * Whether Enter should complete the "/" popup's selection instead of
     * submitting. False whenever the popup isn't showing, AND false when
     * the name typed so far is already an exact, complete match for one of
     * the registered commands - "/agents" + Enter should run /agents, not
     * silently re-fill the same text, even while the popup is still
     * technically showing that single match. Only a genuinely partial/
     * ambiguous prefix (e.g. "/age") intercepts Enter for completion.
     */
    private function slashMenuShouldIntercept(): bool
    {
        $matches = $this->slashMenuMatches();
        if ($matches === []) {
            return false;
        }

        $typed = strtolower(substr($this->inputBuf, 1));
        foreach ($matches as $spec) {
            if (strtolower($spec->name) === $typed) {
                return false;
            }
        }

        return true;
    }

    /**
     * Move the "/" popup's highlighted row by $direction, wrapping around
     * the current match list. A no-op (returns $this unchanged) when the
     * popup isn't showing.
     */
    private function moveSlashMenuSelection(int $direction): self
    {
        $count = count($this->slashMenuMatches());
        if ($count === 0) {
            return $this;
        }

        $next = ($this->slashMenuIndex + $direction + $count) % $count;

        return $this->mutate(['slashMenuIndex' => $next]);
    }

    /**
     * Enter, while the "/" popup is showing: complete the highlighted
     * command into inputBuf (with a trailing space, ready for arguments)
     * rather than submitting immediately - several commands take required
     * arguments (e.g. /rename <name>), so completing first and sending on a
     * second Enter is the more forgiving default.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function completeSlashMenuSelection(): array
    {
        $matches = $this->slashMenuMatches();
        $index = min($this->slashMenuIndex, count($matches) - 1);

        return [$this->withInputBuf('/' . $matches[$index]->name . ' '), null];
    }

    /**
     * The Ctrl+P command palette's current mode/query/selection, or null
     * when closed.
     */
    public function palette(): ?PaletteState
    {
        return $this->palette;
    }

    /**
     * Fuzzy-filtered (or full, when the query is empty) item labels for the
     * palette's current mode, ranked best-match-first via {@see
     * SmithWatermanMatcher} - the same matcher `phlix-console-client`'s own
     * Ctrl+P palette already uses for the same purpose. Returns [] when the
     * palette is closed.
     *
     * @return list<string>
     */
    public function paletteMatches(): array
    {
        return array_map(
            static fn(MatchResult $result): string => $result->haystack,
            $this->paletteMatchResults(),
        );
    }

    /**
     * {@see paletteMatches()}'s rows with their matched-character indices
     * kept, in the SAME order - the label list is just this list's haystacks
     * (crush_feat.md §4 E3: the indices used to be discarded here, so
     * {@see Renderer::renderPalette()} had nothing to highlight with and
     * could only bold whole rows).
     *
     * An empty query yields index-less results (a {@see Highlighter} no-ops
     * on those), MRU-biased and category-grouped per §4 E6/E7; a non-empty
     * query yields the matcher's own relevance order, ungrouped.
     *
     * @return list<MatchResult>
     */
    public function paletteMatchResults(): array
    {
        if ($this->palette === null) {
            return [];
        }

        $items = $this->paletteItemLabels();
        $query = $this->palette->query;
        if ($query === '' || $items === []) {
            if ($this->palette->mode !== 'providers' && $this->palette->mode !== 'themes') {
                $items = $this->rankRootPaletteLabels($items);
            }

            return array_map(
                static fn(string $label): MatchResult => new MatchResult($query, $label, 0, []),
                $items,
            );
        }

        return (new SmithWatermanMatcher())->matchAll($query, $items);
    }

    /**
     * The grouping label ("Session", "Model", …) the palette renders above a
     * root row, or null for a row that has none (provider/theme names).
     */
    public function paletteCategory(string $label): ?string
    {
        return PaletteAction::byLabel($label)?->category();
    }

    /**
     * Palette rows the user has run, most recent first (crush_feat.md §4 E7).
     *
     * @return list<string>
     */
    public function paletteMru(): array
    {
        return $this->paletteMru;
    }

    /**
     * Order the root palette's full (unfiltered) row list: recently-used rows
     * first, then declared registry order, and finally bucketed by category
     * preserving that first-seen order so each category stays contiguous and
     * {@see Renderer::renderPalette()} can emit one header per bucket without
     * re-sorting - the renderer must not reorder rows, or `selectedIndex`
     * would stop addressing the row the user sees highlighted.
     *
     * @param list<string> $labels
     * @return list<string>
     */
    private function rankRootPaletteLabels(array $labels): array
    {
        $recency = array_flip($this->paletteMru);

        // usort() is stable in PHP 8, so rows absent from the MRU keep their
        // declared registry order behind the recent ones.
        usort(
            $labels,
            static fn(string $a, string $b): int
                => ($recency[$a] ?? PHP_INT_MAX) <=> ($recency[$b] ?? PHP_INT_MAX),
        );

        $buckets = [];
        foreach ($labels as $label) {
            $buckets[$this->paletteCategory($label) ?? ''][] = $label;
        }

        return $buckets === [] ? [] : array_merge(...array_values($buckets));
    }

    /**
     * Record a palette row as just-used, moving it to the front of the MRU
     * list (and dropping any older entry for the same row) so the list stays
     * a recency order rather than a use-count histogram.
     */
    private function rememberPaletteUse(string $label): self
    {
        $mru = array_values(array_filter(
            $this->paletteMru,
            static fn(string $existing): bool => $existing !== $label,
        ));
        array_unshift($mru, $label);

        return $this->mutate(['paletteMru' => array_slice($mru, 0, self::PALETTE_MRU_LIMIT)]);
    }

    /**
     * @return list<string>
     */
    private function paletteItemLabels(): array
    {
        return match ($this->palette?->mode) {
            'providers' => array_keys(\SugarCraft\Crush\Cli\Bootstrap::availableProviders()),
            'themes' => Theme::names(),
            default => array_map(static fn(PaletteAction $a): string => $a->label(), PaletteAction::all()),
        };
    }

    /**
     * Route every keystroke while the palette is open - see the Ctrl+P
     * bind in update()'s main match(true) block for how it gets opened.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handlePaletteKey(KeyMsg $msg): array
    {
        return match (true) {
            $msg->type === KeyType::Escape
                => [$this->mutate(['palette' => null]), null],
            $msg->type === KeyType::Up
                => [$this->movePaletteSelection(-1), null],
            $msg->type === KeyType::Down
                => [$this->movePaletteSelection(1), null],
            // A second Ctrl+P closes the palette rather than opening it
            // again on top of itself.
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'p'
                => [$this->mutate(['palette' => null]), null],
            $msg->type === KeyType::Char
                => [$this->withPaletteQuery($this->palette->query . $msg->rune), null],
            $msg->type === KeyType::Space
                => [$this->withPaletteQuery($this->palette->query . ' '), null],
            $msg->type === KeyType::Backspace
                => [$this->withPaletteQuery(self::dropLast($this->palette->query)), null],
            $msg->type === KeyType::Enter
                => $this->runSelectedPaletteAction(),
            default => [$this, null],
        };
    }

    private function withPaletteQuery(string $query): self
    {
        return $this->mutate(['palette' => $this->palette->withQuery($query)]);
    }

    private function movePaletteSelection(int $direction): self
    {
        $count = count($this->paletteMatches());
        if ($count === 0) {
            return $this;
        }

        $next = ($this->palette->selectedIndex + $direction + $count) % $count;

        return $this->mutate(['palette' => $this->palette->withSelectedIndex($next)]);
    }

    /**
     * @return array{0: self, 1: ?\Closure}
     */
    private function runSelectedPaletteAction(): array
    {
        $matches = $this->paletteMatches();
        if ($matches === []) {
            return [$this->mutate(['palette' => null]), null];
        }

        $label = $matches[min($this->palette->selectedIndex, count($matches) - 1)];

        return match ($this->palette->mode) {
            'providers' => $this->selectPaletteProvider($label),
            'themes' => $this->selectPaletteTheme($label),
            // Recorded BEFORE dispatch (crush_feat.md §4 E7): several root
            // actions return a Cmd/second-level palette rather than a plain
            // copy of $this, so the MRU has to be folded into the instance
            // the handler runs against, not bolted onto its result.
            default => $this->rememberPaletteUse($label)->runRootPaletteAction($label),
        };
    }

    /**
     * @return array{0: self, 1: ?\Closure}
     */
    private function runRootPaletteAction(string $label): array
    {
        $action = PaletteAction::byLabel($label);
        if ($action === null) {
            return [$this->mutate(['palette' => null]), null];
        }

        // SwitchModel/SwitchTheme transition to a second-level list rather
        // than closing the palette - every other action below closes it
        // first (mutate() default-preserves $this->palette, so the handler
        // it delegates to must run against the ALREADY-closed copy, not
        // $this, or its own internal mutate() call would silently reopen
        // the palette in its result).
        if ($action === PaletteAction::SwitchModel) {
            return [$this->mutate(['palette' => $this->palette->withMode('providers')]), null];
        }
        if ($action === PaletteAction::SwitchTheme) {
            return [$this->mutate(['palette' => $this->palette->withMode('themes')]), null];
        }

        $closed = $this->mutate(['palette' => null]);

        return match ($action) {
            PaletteAction::ShareSession => $closed->handleShareCommand('/share'),
            PaletteAction::SwitchAgent => $closed->handleAgentsCommand('/agents'),
            PaletteAction::SwitchSession => $closed->handleSessionsCommand('/sessions'),
            PaletteAction::ToggleMcp => $closed->handleMcpAuthCommand('mcp auth list'),
            PaletteAction::NewSession => $closed->handlePaletteNewSession(),
            PaletteAction::OpenDocs => $closed->handlePaletteOpenDocs(),
            PaletteAction::Exit => [$closed, Cmd::quit()],
            default => [$closed, null],
        };
    }

    /**
     * @return array{0: self, 1: ?\Closure}
     */
    private function selectPaletteProvider(string $name): array
    {
        try {
            $backend = \SugarCraft\Crush\Cli\Bootstrap::backendFor($name);
        } catch (\Throwable $e) {
            return [$this->mutate([
                'palette' => null,
                'history' => [...$this->history, Message::assistant("Could not switch to provider '{$name}': {$e->getMessage()}")],
            ]), null];
        }

        $this->onConfigChange?->__invoke('provider', $name);

        return [$this->mutate([
            'palette' => null,
            'backend' => $backend,
            'history' => [...$this->history, Message::assistant("Switched to provider '{$name}'.")],
        ]), null];
    }

    private function selectPaletteTheme(string $name): array
    {
        $this->onConfigChange?->__invoke('theme', $name);

        return [$this->mutate([
            'palette' => null,
            'themeName' => $name,
            'history' => [...$this->history, Message::assistant("Theme set to '{$name}'.")],
        ]), null];
    }

    /**
     * @return array{0: self, 1: ?\Closure}
     */
    private function handlePaletteNewSession(): array
    {
        if ($this->sessionStore === null) {
            return [$this->mutate([
                'history' => [...$this->history, Message::assistant('Session store not configured. Set a SessionStore to create sessions.')],
            ]), null];
        }

        // Chat's Backend interface exposes no provider/model name to record
        // here - 'sugarcrush'/'unknown' are honest placeholders, not
        // fabricated telemetry (same disclosed-gap pattern as Renderer's own
        // R20 docblock elsewhere in this class).
        $sessionId = bin2hex(random_bytes(8));
        $this->sessionStore->createSession($sessionId, 'sugarcrush', 'unknown');

        return [$this->mutate([
            'history' => [...$this->history, Message::assistant("New session created: {$sessionId}")],
            'currentSessionId' => $sessionId,
        ]), null];
    }

    /**
     * @return array{0: self, 1: ?\Closure}
     */
    private function handlePaletteOpenDocs(): array
    {
        $message = 'Docs: see README.md in this project, or '
            . 'https://sugarcraft.github.io/lib/sugar-crush.html';

        return [$this->mutate(['history' => [...$this->history, Message::assistant($message)]]), null];
    }

    /**
     * Drop the last UTF-8 codepoint from `$s`. Plain `substr(-1)`
     * would corrupt multi-byte input — a backspace after typing
     * an emoji should remove the whole grapheme.
     */
    private static function dropLast(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        $i = strlen($s) - 1;
        while ($i > 0 && (ord($s[$i]) & 0xc0) === 0x80) {
            $i--;
        }
        return substr($s, 0, $i);
    }

    /**
     * Trim the trailing "word" off $s: any trailing whitespace, then any
     * trailing run of non-whitespace. Mirrors the usual terminal-wide
     * Ctrl+W convention. Multi-byte-safe by operating on whole characters
     * via preg (UTF-8 mode) rather than raw byte indices.
     */
    private static function dropLastWord(string $s): string
    {
        return (string) preg_replace('/[^\s]+\s*$/u', '', $s);
    }

    /**
     * The content of the most recently sent (Role::User) message in
     * history, or '' if none exists yet - backs the shell-history-style Up
     * arrow recall in update(). Only ever looks at real user turns, so it
     * skips over assistant replies and tool-result/system messages.
     */
    private function lastUserMessageContent(): string
    {
        for ($i = count($this->history) - 1; $i >= 0; $i--) {
            if ($this->history[$i]->role === Role::User) {
                return $this->history[$i]->content;
            }
        }

        return '';
    }

    /**
     * Declare the recurring work this model needs the runtime to drive
     * (crush_feat.md section 5 E4).
     *
     * Today that is exactly one thing: waking up often enough to run
     * {@see \SugarCraft\Crush\Sessions\BackgroundSupervisor::tick()}, which
     * is what flips a session whose heartbeats have stopped to `Stalled`
     * and back again. Before this returned anything, that method had no
     * caller on the live path at all.
     *
     * Returns null - not an empty Subscriptions - whenever there is nothing
     * to poll. `Program` reconciles the set every cycle, so a subscription
     * declared unconditionally would keep a timer waking the event loop (and
     * re-rendering) forever in the overwhelmingly common case of a user who
     * has never run `/bg`.
     */
    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        if ($this->backgroundSupervisor === null || !$this->backgroundSupervisor->hasActiveSessions()) {
            return null;
        }

        return (new \SugarCraft\Core\Subscriptions())->withTick(
            self::BACKGROUND_POLL_SUBSCRIPTION,
            self::BACKGROUND_POLL_SECONDS,
            static fn (): \SugarCraft\Core\Msg => new BackgroundTickMsg(),
        );
    }

    /**
     * Last status reported to the user for each background session, keyed by
     * session id.
     *
     * @return array<string, string>
     */
    public function backgroundStatuses(): array
    {
        return $this->backgroundStatuses;
    }

    /**
     * Run one poll of {@see \SugarCraft\Crush\Sessions\BackgroundSupervisor}
     * and announce whatever changed since the previous poll.
     *
     * Terminal sessions drop out of `getActiveSessions()`, so a session that
     * finished between two ticks would otherwise vanish without ever being
     * reported as finished - the previously-seen ids are therefore re-read
     * individually to catch that last transition.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function pumpBackgroundSessions(): array
    {
        $supervisor = $this->backgroundSupervisor;
        if ($supervisor === null) {
            return [$this, null];
        }

        $supervisor->tick();

        $statuses = [];
        foreach ($supervisor->getActiveSessions() as $id => $session) {
            $statuses[$id] = $session->status->value;
        }
        foreach (array_keys($this->backgroundStatuses) as $id) {
            if (isset($statuses[$id])) {
                continue;
            }
            $session = $supervisor->getSession($id);
            if ($session !== null) {
                $statuses[$id] = $session->status->value;
            }
        }

        $notices = [];
        foreach ($statuses as $id => $status) {
            if (($this->backgroundStatuses[$id] ?? null) === $status) {
                continue;
            }
            $name = $supervisor->getSession($id)?->name ?? $id;
            $notices[] = Message::system(sprintf(
                "Background session %s ('%s') is now %s.",
                $id,
                $name,
                $status,
            ));
        }

        if ($notices === [] && $statuses === $this->backgroundStatuses) {
            // Nothing moved - returning $this keeps the renderer's diff empty
            // instead of repainting the transcript twice a second.
            return [$this, null];
        }

        return [$this->mutate([
            'history' => [...$this->history, ...$notices],
            'backgroundStatuses' => $statuses,
        ]), null];
    }

    /**
     * Real terminal row count, from the last {@see WindowSizeMsg} this Chat
     * received - falls back to {@see TuiRenderer::getTerminalSize()}'s own
     * detection only when no WindowSizeMsg has arrived yet (a Chat built
     * directly, e.g. in a test, without going through a real Program).
     */
    public function rows(): int
    {
        return $this->rows ?? TuiRenderer::getTerminalSize()['rows'];
    }

    /** @see rows() */
    public function cols(): int
    {
        return $this->cols ?? TuiRenderer::getTerminalSize()['cols'];
    }

    /**
     * The same size a {@see WindowSizeMsg} would have recorded, as a wither.
     *
     * A hosted `Chat` (crush_feat.md section 5 E7, merge branch) does not own
     * the whole terminal: it lays out inside the shell's chat pane, several
     * rows and columns smaller. Without this the content model would lay out
     * against the FULL terminal and the pane would have to truncate every line
     * to fit, silently destroying content. The shell therefore hands the pane's
     * inner geometry down through here before rendering.
     *
     * Non-positive dimensions are ignored rather than stored: they would make
     * {@see rows()}/{@see cols()} report a nonsense viewport, and the renderer
     * divides by / clamps against both.
     */
    public function withSize(int $cols, int $rows): self
    {
        $changes = [];
        if ($cols > 0) {
            $changes['cols'] = $cols;
        }
        if ($rows > 0) {
            $changes['rows'] = $rows;
        }

        return $changes === [] ? $this : $this->mutate($changes);
    }

    /**
     * Check if idle compaction should be prompted based on token count and idle time.
     *
     * This replicates the logic from Runtime::shouldPromptIdleCompaction() for use
     * in the TUI event loop, where Runtime instance is not directly available.
     *
     * Returns true when session has been idle for more than 3600 seconds (1 hour)
     * AND token count exceeds 100,000.
     *
     * Called once per turn from submit() (see {@see idleCompactionPromptResponse()})
     * right before a real prompt would be dispatched to the backend.
     *
     * @param int $tokenCount Estimated token count for the conversation
     * @param \DateTimeImmutable|null $lastActivityAt When the user was last active
     */
    public function shouldPromptIdleCompaction(int $tokenCount, ?\DateTimeImmutable $lastActivityAt = null): bool
    {
        if ($tokenCount <= 100000) {
            return false;
        }

        if ($lastActivityAt === null) {
            return false;
        }

        $idleSeconds = time() - $lastActivityAt->getTimestamp();

        return $idleSeconds > 3600;
    }

    /**
     * Estimate token count for a message history using the same
     * 1-token≈4-chars heuristic as {@see ContextCompactor}'s internal
     * countTokens(), so the idle-compaction threshold agrees with what
     * /compact itself would report.
     *
     * @param list<Message> $history
     */
    private function estimateTokenCount(array $history): int
    {
        $total = 0;
        foreach ($history as $msg) {
            $total += (int) ceil(mb_strlen($msg->content) / 4);
            $total += 10; // role overhead
        }
        return $total;
    }

    /**
     * Current history's estimated size as a fraction of {@see
     * REMINDER_TOKEN_LIMIT} - the same fixed proxy limit already used by
     * the 70% reminder tier and idle-compaction check - for the status
     * bar's context-usage indicator ({@see Renderer}). Not clamped to
     * [0, 1]: a value above 1.0 is real signal (context has grown past the
     * reminder threshold and compaction hasn't run yet), not a bug to hide.
     */
    public function contextUsagePercent(): float
    {
        return $this->estimateTokenCount($this->history) / self::REMINDER_TOKEN_LIMIT;
    }

    /**
     * Build the soft, non-blocking reminder message surfaced once
     * {@see ContextCompactor::shouldSendReminder()} reports the conversation
     * has crossed its 70%-of-budget tier. Rendered with a distinct
     * `Role::System` (a faint "system: …" line, see {@see Renderer}) rather
     * than the `Role::Assistant` bubble used for the hard idle-compaction
     * prompt, so the two are visually distinguishable and this one never
     * blocks the turn it rides along with.
     */
    private function contextReminderMessage(int $tokenCount): Message
    {
        return Message::system(
            "Heads up: this conversation has grown to ~{$tokenCount} estimated "
            . "tokens, past the 70% context-usage reminder threshold. Consider "
            . "running /compact soon to keep the session responsive."
        );
    }

    /**
     * Short-circuit a real prompt submission with an idle-compaction
     * advisory instead of calling the backend, mirroring how /compact
     * responds locally (see handleCompactCommand()). Also records this
     * submission as fresh activity, so the nudge does not repeat on the
     * very next message.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function idleCompactionPromptResponse(string $inputText, int $tokenCount): array
    {
        $response = "This session has been idle for over an hour and has grown to "
            . "~{$tokenCount} estimated tokens. Run /compact to shrink the context "
            . "before continuing, or send another message to proceed anyway.";

        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputText), Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
            'lastActivityAt' => new \DateTimeImmutable(),
        ]);

        return [$next, null];
    }

    /**
     * Handle /theme command — switch the active color theme, or show the
     * current one + available choices when called with no argument.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleThemeCommand(string $inputText): array
    {
        $afterTheme = trim(ltrim(substr($inputText, 6))); // after "/theme"

        if ($afterTheme === '') {
            return $this->sessionResponse(
                $inputText,
                "Current theme: {$this->themeName}. Available: " . implode(', ', Theme::names()) . '.'
            );
        }

        try {
            Theme::byName($afterTheme);
        } catch (\InvalidArgumentException $e) {
            return $this->sessionResponse($inputText, $e->getMessage());
        }

        $this->onConfigChange?->__invoke('theme', $afterTheme);

        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputText), Message::assistant("Theme set to '{$afterTheme}'.")],
            'inputBuf' => '',
            'inFlight' => false,
            'themeName' => $afterTheme,
        ]);

        return [$next, null];
    }

    /**
     * Handle the `/mcp` slash command (and its legacy bare `mcp auth …`
     * spelling) for managing MCP server OAuth credentials.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleMcpAuthCommand(string $inputBuf): array
    {
        ob_start();
        $authStore = \SugarCraft\Crush\MCP\McpAuthStore::create();
        $command = new McpAuthCommand($authStore);
        $command->execute($this, self::parseMcpArgs($inputBuf));
        $output = (string) ob_get_clean();

        return $this->mcpAuthResponse($inputBuf, $output);
    }

    /**
     * Split an MCP command line into {@see McpAuthCommand::execute()}'s argv.
     *
     * Both spellings reduce to the same argv: the leading command word is
     * dropped whether it is written `/mcp` or `mcp`, and the `auth` noun the
     * bare form spells out is optional under the slash form - `/mcp list`
     * and `mcp auth list` are the same command.
     *
     * @return list<string>
     */
    private static function parseMcpArgs(string $inputBuf): array
    {
        $tokens = preg_split('/\s+/', trim($inputBuf), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (isset($tokens[0]) && ltrim($tokens[0], '/') === 'mcp') {
            array_shift($tokens);
        }

        if (($tokens[0] ?? null) === 'auth') {
            array_shift($tokens);
        }

        return array_values($tokens);
    }

    /**
     * Return an mcp auth command response, adding both user command and assistant response to history.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function mcpAuthResponse(string $inputBuf, string $response): array
    {
        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputBuf), Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
        ]);
        return [$next, null];
    }
}
