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
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Config\StatusLineCommand;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\SessionPicker;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\ObservesReasoning;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Events\ReasoningDelta;
use SugarCraft\Crush\Events\TokenDelta;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Permissions\DenialKind;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionPromptStage;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Tools\ToolCall as EngineToolCall;
use SugarCraft\Crush\Commands\AgentsCommand;
use SugarCraft\Crush\Commands\CommandLoader;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\McpAuthCommand;
use SugarCraft\Crush\Commands\ShareCommand;
use SugarCraft\Crush\Commands\WebSearchCommand;
use SugarCraft\Crush\Palette\PaletteAction;
use SugarCraft\Crush\Palette\PaletteState;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Mouse\MouseEvent;
use SugarCraft\Mouse\Sentinel;
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
use SugarCraft\Crush\Context\ContextWindow;
use SugarCraft\Crush\Context\IdleCompactionPolicy;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Util\TokenTracker;

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
    /**
     * The key types {@see update()} hands straight to the draft editor when no
     * arm above has claimed them and no ctrl flag is set.
     *
     * A `const` rather than an inline array so it is one readable list AND so
     * a test can read the delegation set back instead of re-typing it: a
     * KeyType added here is a keystroke the draft newly answers, and
     * `ChatInputCursorTest::testEveryDelegatedKeyTypeIsDisclosedInTheReference()`
     * fails until {@see Commands\KeyBindingRegistry} discloses it. That
     * instrument exists because `Delete`, `Left`/`Right` and `Home`/`End`
     * arrived live and undocumented, and nothing noticed for a round.
     *
     * `Up`/`Down` are NOT here: they delegate only on a draft that already has
     * a second line, which is a condition, not a plain membership test — see
     * their own arm.
     */
    private const DRAFT_KEYS = [
        KeyType::Char,
        KeyType::Space,
        KeyType::Backspace,
        KeyType::Delete,
        KeyType::Left,
        KeyType::Right,
        KeyType::Home,
        KeyType::End,
    ];

    /**
     * The user's in-progress draft of the next turn, as a plain string.
     *
     * DERIVED, not stored: it is `$this->input->value()`, re-read in the
     * constructor on every clone. It stays a public property (rather than
     * becoming an accessor) because it is the field {@see Renderer}, the
     * checkpoint state map and this lib's existing assertions already read, and
     * because "what is in the box" genuinely is state — it is only the
     * CURSOR that the widget added, and that is exposed separately by
     * {@see inputCursorOffset()}.
     *
     * Writing it still works, through the `inputBuf` key on
     * {@see mutate()} or {@see withInputBuf()}: that key means "replace the
     * whole draft" and reseeds the widget with the cursor at the end.
     */
    public readonly string $inputBuf;

    /**
     * The draft's editor. See the constructor parameter of the same name for
     * why this, and not {@see $inputBuf}, is the source of truth.
     */
    public readonly TextArea $input;

    private readonly Backend $backend;

    /**
     * The live tool-event inbox: the ONE deliberately mutable object on this
     * otherwise immutable model (crush_feat.md §1 E1).
     *
     * {@see Backend::completeAsync()}'s `$onEvent` callback fires deep inside
     * the backend while the turn is still running — for
     * {@see Backend\EngineBackend} on a ReactPHP readable-stream edge, one
     * frame per tool call. There is no dispatcher reachable from there and no
     * way to hand a new Chat back to `Program`, so the callback appends here
     * and {@see subscriptions()}'s poll wakes `update()` to drain it. Sharing
     * one instance across every `mutate()` clone is the whole point: an event
     * appended against the Chat that scheduled the turn has to be visible to
     * the Chat that is on screen ten keystrokes later.
     *
     * Entries are `[generation, event]` pairs so a queue still being filled by
     * an aborted turn's backend can be dropped rather than applied on top of
     * whatever the user did since — same staleness contract as
     * {@see AssistantMsg::$generation}.
     *
     * Also carries {@see TokenDelta}s — the assistant's reply as it is written
     * (crush_code.md Phase 0 item 13) — and {@see ReasoningDelta}s — the
     * model's thinking while it writes it (E456/E494) — on this SAME queue
     * rather than ones of their own, because the order of "the model thought
     * this", "the model said this" and "the model called that tool" is the
     * story of an agentic turn and three queues could not preserve it.
     *
     * @var \ArrayObject<int, array{0: int, 1: ToolStarted|ToolFinished|TokenDelta|ReasoningDelta}>
     */
    private readonly \ArrayObject $liveToolEvents;

    /** @var WorkflowEngineInterface|null Optional workflow engine for /workflow command */
    private readonly ?WorkflowEngineInterface $workflowEngine;

    /** @var ContextCompactor Context compactor for /compact command and automatic compaction */
    private readonly ContextCompactor $compactor;

    /**
     * File-based commands discovered at construction, name => spec — the merged
     * user+project tiers of {@see CommandLoader::loadAll()} with the built-in
     * rows already dropped back out (see the constructor body).
     *
     * RESOLVED ONCE, then carried by {@see mutate()} like any other field, and
     * that is the whole reason this exists as a property beside
     * {@see $commandLoader}: `mutate()` runs the constructor on EVERY keystroke,
     * so a loader consulted there instead would walk two directories per
     * character typed. The cost of caching is that a command file added while
     * the session is running is not seen until the next launch — stated rather
     * than hidden, and the trade is one filesystem walk per process against one
     * per keypress.
     *
     * @var array<string, CommandSpec>
     */
    private readonly array $customCommands;

    /**
     * This session's running PROVIDER-COUNTED spend, fed one entry per settled
     * turn by {@see update()} and read by the status bar's spend readout and by
     * `/budget` (crush_code.md Phase 5 item 7).
     *
     * Mutable and shared by object identity across every {@see mutate()} clone,
     * exactly like {@see $liveToolEvents} and for the same reason: a fresh
     * instance per keystroke would reset the total to zero on the next frame.
     * That is why it is resolved in the constructor body and passed through
     * `mutate()`'s property list rather than rebuilt there the way
     * {@see $compactor} is.
     */
    private readonly TokenTracker $tokenTracker;

    /** @var AgentManager|null Agent manager for /agents command */
    private ?AgentManager $agentManager = null;

    /** @var MemoryStore|null Memory store for /memory command */
    private ?MemoryStore $memoryStore = null;

    /** @var SessionStore|EnhancedSessionStore|null Session store for /branch and /rename commands */
    private SessionStore|EnhancedSessionStore|null $sessionStore = null;

    /** @var string|null ID of the currently active session */
    private ?string $currentSessionId = null;

    /**
     * Columns the `/help` listing gives up to the chrome it will be painted
     * inside: {@see Renderer}'s shell border + padding(1, 2) is 6, and the rest
     * is slack so the listing does not sit flush against the border. Same
     * arithmetic as {@see Renderer}'s own SHELL_CHROME_COLS, kept here rather
     * than reached for across the class boundary because this side only needs
     * the number, not the layout.
     */
    private const HELP_CHROME_COLS = 10;

    /**
     * Where the `/help` listing's description column starts. A constant rather
     * than the widest name-plus-hint in the registry: that is `/websearch`'s, and
     * measured with `Width::string()` over `CommandRegistry::all()` its hint
     * alone is 58 columns and its whole `  /name <hint>` column is 71 - which on
     * an 80-column terminal would leave the descriptions nowhere to go. Rows
     * wider than this spill their description onto the next line instead.
     */
    private const HELP_NAME_COLS = 24;

    /**
     * Wall-clock budget for {@see forkToolCalls()}'s forked children.
     * A tool call that never returns (e.g. a hung shell command) would
     * otherwise leave the parent blocked forever waiting on pcntl_waitpid();
     * past this deadline, stragglers are SIGKILLed and reported as timeouts.
     */
    private const PARALLEL_TOOL_TIMEOUT_SECONDS = 30;

    /**
     * How long {@see reapKilledToolChildren()} spends collecting a batch of
     * SIGKILLed tool children before giving up on whatever is left of it.
     *
     * 100ms is two loop frames at the 50ms tick this routine polls on, which
     * is the budget that matters: it has to be long enough that the ordinary
     * case (the children are already gone) never reaches the end of it, and
     * short enough that a child which cannot be reaped at all does not become a
     * visible stall in the TUI.
     *
     * THE WHOLE BATCH, not each child — see
     * {@see reapKilledToolChildren()} for why that distinction is the fix and
     * not a detail.
     */
    private const REAP_BUDGET_SECONDS = 0.1;

    /** How often {@see reapKilledToolChildren()} re-asks. */
    private const REAP_POLL_MICROSECONDS = 5_000;

    /**
     * How often {@see driveWorkflowFiber()} resumes a suspended workflow.
     *
     * The same 50ms {@see waitForToolChildrenAsync()} polls its children at,
     * and chosen the same way: it is the interval the loop gets to itself, so
     * it has to be short enough that a repaint feels immediate and long enough
     * that resuming is not the thing burning the CPU. It REPLACES the pool's
     * own 5ms `usleep()` backoff while a fiber is driving
     * ({@see \SugarCraft\Crush\Agents\AgentWorkerPool::idle()}), so it is also
     * the granularity at which a finished sub-agent is noticed.
     */
    private const WORKFLOW_STEP_INTERVAL_SECONDS = 0.05;

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
     * Reconciliation id of the live tool-event poll subscription
     * (crush_feat.md §1 E1). Stable across rebuilds for the same reason
     * {@see BACKGROUND_POLL_SUBSCRIPTION} is.
     */
    private const TOOL_EVENT_POLL_SUBSCRIPTION = 'crush.tool-event-poll';

    /**
     * How often the live tool-event pump wakes (seconds) while a turn is in
     * flight.
     *
     * Only the LATENCY of noticing a newly queued event, not the drain rate:
     * once woken, {@see pumpLiveToolEvents()} re-sends itself a
     * {@see ToolEventPumpMsg} per event, so a burst of ten events drains at
     * Cmd speed rather than one per tick. A tenth of a second reads as
     * instant to a human and still leaves the loop idle 99% of a turn spent
     * waiting on the provider.
     */
    private const TOOL_EVENT_POLL_SECONDS = 0.1;

    /**
     * Reconciliation id of the `statusLine` poll subscription. Stable across
     * rebuilds for the reason {@see BACKGROUND_POLL_SUBSCRIPTION} gives: an id
     * that changed per update would make `Program` tear the timer down and
     * start a new one every cycle, so the tick would never actually fire.
     *
     * No sibling `*_SECONDS` constant here, deliberately. The period is
     * {@see \SugarCraft\Crush\Config\StatusLineCommand::REFRESH_SECONDS},
     * read at the declaration site, because the runner DERIVES its own timeout
     * from it — a second copy of the number here could drift into a timeout
     * longer than the period, which is precisely the overlap that derivation
     * exists to make impossible.
     */
    private const STATUS_LINE_SUBSCRIPTION = 'crush.status-line';

    /**
     * Reconciliation id of the runtime-notice poll subscription (E171).
     * Stable across rebuilds for {@see BACKGROUND_POLL_SUBSCRIPTION}'s reason.
     */
    private const RUNTIME_NOTICE_SUBSCRIPTION = 'crush.runtime-notice-poll';

    /**
     * How often the runtime-notice inbox is polled (seconds) while the tick is
     * declared at all.
     *
     * SLOWER THAN {@see TOOL_EVENT_POLL_SECONDS} ON PURPOSE, and the difference
     * is not a guess about cost. A tool event is a two-state walk the user
     * watches — running, then done — so a tenth of a second is the difference
     * between a visible transition and a jump. A notice is one static row of
     * prose about something that already went wrong; half a second later it
     * reads identically, and the slower tick halves the wake-ups on the exact
     * path (a turn in flight) where the loop is also servicing the tool-event
     * pump, the provider socket and the spinner.
     */
    private const RUNTIME_NOTICE_POLL_SECONDS = 0.5;

    /**
     * Stable head of the context-usage reminder {@see contextReminderMessage()}
     * builds, and the ONLY thing {@see isContextReminder()} matches on.
     *
     * A PREFIX RATHER THAN THE WHOLE MESSAGE BECAUSE THE WHOLE MESSAGE IS NOT A
     * CONSTANT: everything after this point embeds the current estimated token
     * count, so two copies written on two different turns are never byte-equal.
     * A full-text equality check would therefore never match, the
     * deduplication in {@see dispatchTurn()} would look correct in review, and
     * every copy would still pile up — while a test that only asserts "a
     * reminder is present" passed either way. The count of copies is the only
     * thing that discriminates the fix from the bug, which is why
     * `tests/Chat/ContextReminderDedupTest.php` asserts a quantity.
     *
     * Carried in the message CONTENT rather than as a new `Message` field on
     * purpose: content is one of the few things {@see Message::toWire()} emits,
     * so a reminder that has been through a checkpoint save/restore or a wire
     * round-trip is still recognisable. A dedicated field has no
     * representation in either, so the dedup would silently stop working on a
     * resumed session — the same lossiness documented on
     * {@see messagesFromWire()}.
     *
     * THAT COVERS ONLY THE CONTENT HALF OF THE PREDICATE, and
     * {@see isContextReminder()} needs both halves: the marker AND
     * `Role::System`. The wire half was always sound —
     * {@see messagesFromWire()} rebuilds the role with `Role::from()` — but the
     * checkpoint half was not, until E33's review round: with no `'system'` arm
     * in {@see reviveCheckpointMessage()} the ROW survived a checkpoint intact
     * while the restored message came back as `Role::User`, so one `/rewind`
     * put a copy beyond this predicate's reach forever. Both halves round-trip
     * now; changing either method's role handling breaks the dedup silently,
     * not loudly.
     */
    private const CONTEXT_REMINDER_PREFIX = 'Heads up: this conversation has grown to ~';

    /**
     * @param list<Message> $history
     * @param array<string, callable> $tools Map of tool name => callable(array $arguments): mixed
     * @param callable|null $onToolCall Optional callback called when tools are invoked
     */
    public function __construct(
        public readonly array $history = [],
        string $inputBuf = '',
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
        /**
         * Thresholds the context tiers use. Promoted to a property so
         * {@see mutate()} can carry it: it used to be a plain parameter that
         * only reached the constructor, and mutate() passed `null` in its
         * place, so a Chat built with custom thresholds silently reverted to
         * the defaults on the first keystroke. Nothing in the repo passed one
         * yet, which is why it went unnoticed — and why wiring the tiers
         * (crush_code.md Phase 5 item 5) had to fix it first.
         */
        private readonly ?CompactorConfig $compactorConfig = null,
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
         * Optional callable(string $key, string $value): void, fired by FOUR
         * DOORS: the Ctrl+P palette's Switch Model row, `/model <provider>`,
         * the palette's Switch Theme row, and `/theme <name>`.
         *
         * TWO WRITERS WITH TWO DOORS EACH, and the route was followed rather
         * than inferred: {@see handleModelCommand()} ends in
         * {@see selectPaletteProvider()}, which is also the palette row's own
         * handler and holds the only `('provider', …)` invoke in the class;
         * {@see selectPaletteTheme()} and {@see handleThemeCommand()} are the
         * `theme` pair. That is why a `/model` choice persists exactly as a
         * palette choice does with no separate persistence code for it.
         *
         * WHAT THIS SAID: "the Switch Model/Switch Theme palette actions (or
         * /theme)". WHAT IS TRUE NOW: that names three doors out of four, and
         * the missing one is `/model` — so a reader debugging a `provider` that
         * persisted when they expected it not to had the wrong mental model,
         * and one written down twice in this file. WHY THE ENUMERATION STILL
         * EARNS ITS PLACE rather than being cut back to "whenever a choice is
         * applied": the doors are ordinary private-method calls with no shared
         * marker or interface, so no tooling can list them and this sentence is
         * the only index a reader has. It is the identical drift E81 corrected
         * in {@see \SugarCraft\Crush\Config\LayeredSettings} one file over;
         * E106 found this file still carrying it, and
         * {@see \SugarCraft\Crush\Tests\Chat\ChatConfigChangeDoorsDocumentationDriftTest}
         * now reds on the fourth instance instead of leaving it to a reader.
         *
         * The persistence side effect itself (writing to
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
         * How far $pendingPermission is along at answering itself, and the
         * reason an ordinary slash command typed at a live prompt no longer
         * answers it — see {@see handlePermissionKey()} for the rule and the
         * measured table, and {@see PermissionPromptStage} for the states.
         *
         * Meaningless while $pendingPermission is null; every raise sets it
         * back to {@see PermissionPromptStage::Armed} ({@see requestPermission()}
         * is the ONLY writer of a non-null $pendingPermission, so arming there
         * covers a first ask and a queued one alike).
         */
        private readonly PermissionPromptStage $permissionStage = PermissionPromptStage::Armed,
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
        /**
         * The live session picker overlay, or null when it is closed
         * (crush_feat.md section 5 E8).
         *
         * Persisted on the model rather than rebuilt per keystroke because
         * the picker owns navigation state (selected row, branch filter):
         * before this field existed, `/sessions` rendered the picker's FIRST
         * frame into a chat message, so ↑/↓ had nothing to move and the
         * widget's whole keyboard surface was unreachable from
         * `bin/sugarcrush`.
         *
         * Modal precedence mirrors {@see $palette}: a blocking permission
         * prompt still owns the keyboard ahead of both, and only one of
         * picker/palette can be open at a time because each is opened from
         * a dispatch arm the other's routing block already claimed.
         */
        private readonly ?SessionPicker $sessionPicker = null,
        /**
         * The shared live tool-event inbox - see the {@see $liveToolEvents}
         * property docblock for why it is mutable and why every `mutate()`
         * clone must be handed the SAME instance. Defaulting to null (and
         * allocating here) keeps every existing embedder/test constructor
         * call working unchanged.
         *
         * @var \ArrayObject<int, array{0: int, 1: ToolStarted|ToolFinished|TokenDelta|ReasoningDelta}>|null
         */
        ?\ArrayObject $liveToolEvents = null,
        /**
         * The in-flight assistant reply as far as it has arrived — the
         * accumulation of this turn's {@see TokenDelta}s, drained off
         * {@see $liveToolEvents} by {@see pumpLiveToolEvents()}
         * (crush_code.md Phase 0 item 13).
         *
         * Deliberately NOT a {@see Message} in {@see $history}. A half-written
         * reply must never be checkpointed, compacted, counted towards the
         * context budget or re-sent to the model as if it were a finished
         * turn, and everything that walks $history would treat it as one.
         * {@see Renderer} paints it above the "thinking…" placeholder instead,
         * and the settled {@see AssistantMsg} — which is authoritative, being
         * what the provider actually committed to — clears it as it appends
         * the real message.
         *
         * Reset on {@see ToolStarted} as well, so each step of an agentic turn
         * shows its OWN prose: {@see Backend\EngineBackend::complete()} returns
         * only the final step's content, so an accumulation spanning steps
         * would visibly shrink when the turn settled.
         *
         * KNOWN LOSS, not a rendering detail: on a multi-step turn every
         * intermediate step's prose is painted and then DISCARDED. "Let me
         * check the clock. " streams, the reset blanks it when the tool
         * starts, and only the last step's "It is noon." survives into the
         * transcript — the earlier sentence reaches no message and no
         * checkpoint. The reset is not the cause; `complete()` collapsing a
         * multi-step turn down to its last step is, which leaves the reset as
         * the only honest option (the alternative is prose that shrinks on
         * settle). The real fix is to commit each step's assistant message as
         * that step ends, so the partial has a settled message to be
         * superseded by; that is tracked as its own follow-up and is
         * deliberately out of scope here.
         */
        private readonly string $streamingText = '',
        /**
         * The model's THINKING so far this step — the accumulation of this
         * turn's {@see ReasoningDelta}s, drained off {@see $liveToolEvents} by
         * {@see pumpLiveToolEvents()} (E456/E494).
         *
         * A field of its own rather than a flavour of {@see $streamingText},
         * and the separation is a CORRECTNESS boundary, not a styling one.
         * {@see Runtime::runStreaming()} accumulates the token channel's bytes
         * into the {@see Messages\AssistantMessage} that the agentic loop feeds
         * back to the model and that the transcript checkpoints; the two
         * accumulators are painted differently AND must never be merged, or a
         * thought would be re-sent to the model as something the assistant
         * said. {@see Tests\Backend\ReasoningPaintTest} pins both halves.
         *
         * Cleared on exactly the occasions {@see $streamingText} is, for
         * exactly its reasons: on {@see ToolStarted} because the thinking that
         * introduced a call belongs to the step that is now over, and on settle
         * because the finished {@see Message} carries its own `reasoning` which
         * {@see Renderer} paints from the transcript instead.
         */
        private readonly string $reasoningText = '',
        /**
         * The project root this session was launched against — `--root`'s
         * value as {@see \SugarCraft\Crush\Cli\Bootstrap::chat()} resolved
         * it, or null for a Chat built without one.
         *
         * Chat needs its own copy rather than reading the engine's: the two
         * places below that resolve a root — the {@see HookContext} this
         * class's OWN tool pipeline builds, and the working directory `/bg`
         * hands a spawned session — run entirely inside Chat, with no App
         * and no Runtime in reach. Both used to call `getcwd()` bare, so a
         * `--root <lib>` run gated its Chat-side tool calls against the
         * monorepo and backgrounded work into it too (crush_code.md Phase 0
         * item 6).
         */
        private readonly ?string $projectRoot = null,
        /**
         * Scroll position of the in-app keybinding reference, in lines from
         * its first row; null while the reference is closed (crush_code.md
         * Phase 8 item 2). One nullable int rather than a bool plus an int
         * for the same reason {@see $palette} is one nullable object: "is it
         * open" and "where in it am I" are never independently meaningful,
         * and a pair would let them disagree.
         *
         * Only the lower bound is clamped on the way in
         * ({@see withKeyHelp()}); the upper bound belongs to the frame that
         * is about to be drawn, so {@see Renderer::renderKeyHelp()} re-clamps
         * against its own content the way the transcript's scroll offset is
         * re-clamped in {@see Renderer::renderView()}.
         */
        private readonly ?int $keyHelp = null,
        /**
         * The draft's editing widget — `candy-forms`' {@see TextArea}, which
         * owns the value AND the cursor (crush_code.md Phase 3 item 1).
         *
         * `$inputBuf` above is now a SEED for this, not a peer of it: when
         * both arrive, the widget wins and `$inputBuf` is re-derived from
         * `value()` in the body below, so the two can never disagree about
         * what is in the box. {@see mutate()} is what keeps that rule honest
         * across a clone — see the reseed guard there.
         */
        ?TextArea $input = null,
        /**
         * The session's spend accumulator — see the {@see $tokenTracker}
         * property docblock for why the SAME instance has to reach every
         * clone. Null allocates a fresh one, which keeps every existing
         * embedder/test constructor call working unchanged and means an
         * offline run tracks a real (empty) total rather than none at all.
         */
        ?TokenTracker $tokenTracker = null,
        /**
         * Hard ceiling on this session's provider spend in US dollars, or null
         * for no cap (crush_code.md Phase 5 item 7). Set from
         * `$SUGARCRUSH_MAX_COST` at launch and from `/budget <n>` at runtime.
         *
         * Enforced on the way IN to a turn, not on the way out: see
         * {@see spendCapRefusal()} for exactly which side of the cap the check
         * lands on and what that means for the final total.
         *
         * VALIDATED IN THE CONSTRUCTOR BODY: a non-null value that is not a
         * positive finite number of dollars ({@see isUsableSpendCap()}) throws
         * rather than being silently coerced or ignored. `/budget` and
         * `$SUGARCRUSH_MAX_COST` both refuse such values at their own edge with
         * a message, and this closes the third door — the public constructor,
         * through which `0.0`, a negative, `NAN` and `INF` all used to reach the
         * field. That is not pedantry about an unreachable case: `NAN` and `INF`
         * compare false against everything, so either one installed a cap that
         * refused nothing while the status bar advertised `$nan` / `$inf`, and
         * `INF` was reachable from `/budget 1e309`. Because `mutate()` goes back
         * through here, the invariant holds for every clone, which is what lets
         * {@see spendCapReached()} be one comparison.
         */
        private readonly ?float $maxCostUsd = null,
        /**
         * Dedicated tool-less Backend for `/compact`'s model-written exchange
         * summaries (crush_code.md Phase 5 item 6), built by
         * {@see \SugarCraft\Crush\Cli\Bootstrap::summaryBackend()}.
         *
         * Separate from {@see $backend} for a reason that is not tidiness:
         * `Backend::complete()` on the main backend runs the whole agentic
         * loop — tools, hooks, permission gate, up to `maxSteps` provider
         * calls — so routing a summarization request through it lets the model
         * call `Bash` and raise a permission prompt DURING a compaction. This
         * backend carries no tools, no hooks, no skills and no instruction
         * preamble, so a summarization is one plain completion and can be
         * nothing else.
         *
         * Null is the ordinary offline/unit-test answer, and it is not an
         * error path: `/compact` then uses the heuristic summarizer it always
         * used ({@see ContextCompactor::generateExchangeSummary()}).
         */
        private readonly ?Backend $summaryBackend = null,
        /**
         * Identifies the `/compact` summarization currently out at the model,
         * or null when none is (crush_code.md Phase 5 item 6).
         *
         * A plain id rather than the generation counter: `/compact` does not
         * start a turn, so it does not bump `$generation`, and the four sites
         * that guard on generation are a closed set
         * ({@see \SugarCraft\Crush\Tests\Renderer\KeyHelpTest}
         * asserts exactly that) which this must not silently join. A
         * {@see HistoryCompactedMsg} whose id does not match this is dropped —
         * which is also how a second `/compact` supersedes the first.
         *
         * THREE COMMANDS RELEASE IT, and they are named individually because
         * they are the complete set of routes that put a transcript on screen
         * the user did not just ask to compact: `/clear`
         * ({@see handleClearCommand()}), `/rewind`
         * ({@see handleRewindCommand()}) and the Ctrl+P palette's New session
         * action ({@see handlePaletteNewSession()}). A FOURTH route releases it
         * for a different reason - the double-Escape cancel arm in
         * {@see update()}, which abandons a turn rather than replacing a
         * transcript; see the comment there for why it is unconditional and why
         * it became necessary once the 85% tier started parking turns behind a
         * summarization ({@see scheduleParkedCompaction()}). Note the palette
         * action is
         * NOT reachable as `/new`: the registry row is `slashVisible: false`
         * ({@see \SugarCraft\Crush\Commands\CommandRegistry}) and
         * {@see dispatchCommand()} has no `new` arm, so a typed `/new` falls
         * through and is sent to the model as an ordinary prompt.
         */
        private readonly ?string $pendingCompactionId = null,
        /**
         * Prompts the user pressed Enter on WHILE a turn was in flight, oldest
         * first — the queue the user asked for ("new messages should be typable
         * and sendable (well really queued for processing if its mid processing
         * the previous message)").
         *
         * FIFO and unbounded rather than a single slot: two follow-up thoughts
         * typed during one long turn are two messages, and silently replacing
         * the first with the second is the "queued message the user cannot see"
         * failure in another form.
         *
         * ORDINARY PROMPTS ONLY. A draft that starts with `/` is REFUSED
         * mid-turn rather than queued ({@see refuseInFlightCommand()}), so
         * nothing in here can reach {@see dispatchCommand()} when
         * {@see releaseQueuedPrompts()} drains it — which is also why the drain
         * cannot rewrite history under a turn that is still settling.
         *
         * Drained by {@see releaseQueuedPrompts()} at the four sites a backend
         * turn can END (see that method); deliberately NOT drained by the
         * double-Escape cancel arm in {@see update()}, which held these
         * deliberately — cancelling the running turn says nothing about the next
         * one.
         *
         * IN-MEMORY FOR THE LIFE OF THE PROCESS, like {@see $paletteMru}, and
         * deliberately absent from {@see dispatchTurn()}'s checkpoint payload: a
         * queued prompt is a message the user is watching the status bar for, and
         * a session revived hours later dispatching one they have forgotten about
         * is worse than losing it. Recorded rather than fixed, because the notice
         * {@see enqueuePrompt()} writes IS checkpointed, so a revived session still
         * shows what was queued even though it will not send it.
         *
         * @var list<string>
         */
        private readonly array $queuedPrompts = [],
        /**
         * Discovers `*.md` commands under `~/.sugar-crush/commands` and
         * `<root>/.sugar-crush/commands` (crush_code.md Phase 2 item 4). Null —
         * the default — means no file-based commands at all, which is what every
         * existing embedder and unit test gets, so nothing that does not ask for
         * disk discovery acquires it.
         *
         * THE INSTANCE, not the loaded map, because the loader is also the thing
         * that ANSWERS FOR the load: {@see \SugarCraft\Crush\Cli\Bootstrap::chat()}
         * drains {@see CommandLoader::refusedDirectories()} off this same object
         * after construction, so a commands directory refused for pointing
         * outside the checkout reaches the launch report rather than only
         * `error_log()`.
         */
        private readonly ?CommandLoader $commandLoader = null,
        /**
         * Pre-resolved {@see $customCommands}; null asks the constructor to load
         * from {@see $commandLoader}. Present so {@see mutate()} can carry the
         * cache across a clone — and so a test can inject a map without touching
         * a filesystem.
         *
         * @var array<string, CommandSpec>|null
         */
        ?array $customCommands = null,
        /**
         * Whether the operator has opted THIS project root in to running the
         * `` !`cmd` `` form of a PROJECT-tier command file
         * ({@see \SugarCraft\Crush\Cli\Bootstrap::projectCommandShellIsTrusted()}
         * reads `trustedProjectCommands` from `~/.sugar-crush/config.json`).
         *
         * DEFAULTS TO FALSE, and the default is the security property rather
         * than a convention: `<root>/.sugar-crush/commands/*.md` arrives in a
         * `git clone`, so a hostile repository plus one innocuous-looking
         * `/review` is arbitrary command execution unless somebody said yes
         * first. Every embedder and every test that does not pass this argument
         * therefore gets the refusing behaviour, which is the direction to be
         * wrong in.
         *
         * IT DOES NOT GATE THE USER TIER. `~/.sugar-crush/commands/*.md` is the
         * operator's own file and needs no per-project grant — see
         * {@see refuseCommandShell()} for the full rule and for what the
         * permission gate adds on top of it.
         */
        private readonly bool $projectCommandsTrusted = false,
        /**
         * Whether THIS Chat is the process's drain owner for
         * {@see \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink} (E171).
         *
         * ONE INBOX, ONE READER, AND THAT IS WHY THIS IS A FIELD RATHER THAN A
         * GLOBAL. The sink is process-wide because the subsystems that write to
         * it are `final readonly` value objects several layers below anything
         * holding a model, and on the interactive path they are not even in
         * this process — see that class's doc-block. Its DRAIN is destructive:
         * {@see \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink::drain()}
         * takes the rows and clears them. So a second Chat that also polled
         * would not duplicate the rows, it would STEAL them, and which of the
         * two transcripts a warning landed in would be whichever one's tick
         * fired first.
         *
         * DEFAULTS TO FALSE, which makes {@see subscriptions()} independent of
         * process-wide state for every Chat nobody appointed — an embedder's, a
         * test's, one built by a subcommand. That is not a convenience: it is
         * MEASURED. With the poll conditioned on the sink alone,
         * `--filter '(BootstrapTest|DsmlToolCallParserTest|MinimaxXmlFallback`
         * `ToolCallParserTest|StatusLineSegmentTest|ChatTest|AppModelTest)'`
         * (PHP 8.3.6) went `Tests: 381, Failures: 2` — two cases in
         * `tests/Renderer/StatusLineSegmentTest` asserting that an idle Chat
         * declares no subscription, reddened by a row a parser test twenty
         * classes earlier had left in a static. The tests were right and the
         * condition was wrong: an idle Chat that never owned the inbox has
         * nothing to poll for.
         *
         * SET IN EXACTLY ONE PLACE — {@see \SugarCraft\Crush\Cli\Bootstrap::chat()},
         * which is also the only caller of `RuntimeNoticeSink::arm()` in
         * `src/`. Appointing the reader and opening the inbox are the same
         * decision and are made in the same method.
         */
        private readonly bool $drainsRuntimeNotices = false,
    ) {
        // The widget is the source of truth; $inputBuf is its projection.
        // Seeding via setValue() lands the cursor at the end of the draft,
        // which is what every "replace the draft" route wants (a submit
        // clearing it, an Up recall, a palette fill).
        $this->input = $input ?? self::freshInput()->setValue($inputBuf);
        $this->inputBuf = $this->input->value();
        $this->liveToolEvents = $liveToolEvents ?? new \ArrayObject();
        $this->backend = $backend ?? new Backend\EchoBackend();
        $this->workflowEngine = $workflowEngine;
        $this->agentManager = $agentManager;
        // Chat is the only place that holds BOTH collaborators, so it is where
        // the engine learns which manager its parallel-stage sub-agents should
        // register with -- without this link a /workflow run's agents would
        // still bypass the manager the renderer reads telemetry from
        // (crush_feat.md section 5 E6). Idempotent across mutate(), and an
        // engine that was constructed with its own manager keeps it.
        if ($agentManager !== null
            && $workflowEngine instanceof WorkflowEngine
            && $workflowEngine->agentManager() === null
        ) {
            $workflowEngine->setAgentManager($agentManager);
        }
        if ($maxCostUsd !== null && !self::isUsableSpendCap($maxCostUsd)) {
            throw new \InvalidArgumentException(sprintf(
                'A spend cap must be a positive finite number of US dollars, or null for no cap; got %s. '
                . 'Zero and negative are refused rather than read as "no cap" because they are the opposite '
                . 'request, and a non-finite cap compares false against every spend so it would silently '
                . 'enforce nothing.',
                var_export($maxCostUsd, true),
            ));
        }

        // FILE-BASED ROWS ONLY. loadAll() merges the built-in registry underneath
        // the two disk tiers so a project file can override a built-in by name;
        // what is kept here is the result of that merge MINUS everything that is
        // still a built-in row, because the built-ins are already reachable
        // through CommandRegistry and dispatchCommand()'s match arms. Keeping
        // them would list every built-in twice in the "/" popup. A project file
        // that DOES override a built-in survives the filter — it is file-based by
        // then — which is exactly the override loadAll() documents.
        $this->customCommands = $customCommands ?? array_filter(
            $commandLoader?->loadAll($this->projectRoot()) ?? [],
            static fn(CommandSpec $spec): bool => $spec->isFileBased(),
        );

        $this->compactor = new ContextCompactor($this->compactorConfig ?? CompactorConfig::new());
        $this->tokenTracker = $tokenTracker ?? new TokenTracker();
        $this->memoryStore = $memoryStore;
        $this->sessionStore = $sessionStore;
        $this->currentSessionId = $currentSessionId;
    }

    /**
     * Arm the mid-session seam's edge-driven wake-up (E193).
     *
     * THE ONLY THING THIS MODEL WANTS AT STARTUP, and it is deliberately not a
     * subscription. {@see subscriptions()}' runtime-notice tick is declared on
     * `$inFlight || RuntimeNoticeSink::hasPending()`, and `Program` re-evaluates
     * that only when it reconciles — after `init()` and after every dispatched
     * `Msg`. A notice raised while the UI is IDLE therefore arms nothing and
     * waits for whatever `Msg` arrives next, which on a genuinely idle session
     * is the user's next keystroke. MEASURED end to end; the numbers and the
     * two controls are in
     * {@see \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink::notifyOnceWhenPending()}'s
     * doc-block, which is also where the argument against fixing it with an
     * unconditional tick lives.
     *
     * RETURNS null FOR EVERY Chat THAT IS NOT THE PROCESS'S DRAIN OWNER, which
     * is the same gate {@see subscriptions()} applies first and for the same
     * reason: {@see \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink::drain()}
     * is destructive, so a second listening `Chat` would take rows out from
     * under the real transcript.
     *
     * ## A HOST THAT DRIVES `update()` ITSELF OWNS THE PUMPING (E223)
     *
     * TWO CALLERS IN THIS TREE, AND THE SECOND ONE IS THE HOSTED SHAPE —
     * CHECKED RATHER THAN ASSUMED, because the obvious sentence ("only
     * `Program` calls this") is false. `SugarCraft\Core\Program::run()` calls
     * it on the active model, and {@see \SugarCraft\Crush\App\App::init()}
     * FORWARDS it: the shell batches its own OSC 11 query with
     * `$this->chat?->init()`, and passes the Cmd `Chat::update()` returns
     * straight through untouched. So the in-tree hosted pane already
     * discharges both parts below, and the gap E223 records belongs to an
     * embedder OUTSIDE this tree that drives `update()` itself. Such a host
     * gets the seam's idle wake-up only if it does what those two do, in two
     * parts:
     *
     *  1. CALL THIS AND RUN WHAT IT HANDS BACK. The return is the arming
     *     `Cmd`; a host that never runs it never installs the watcher, and the
     *     seam is back to "the notice waits for whatever `Msg` arrives next",
     *     which on an idle session is the user's next keystroke.
     *  2. KEEP RUNNING THE Cmd `update()` RETURNS. Handling a
     *     {@see RuntimeNoticePumpMsg} drains the inbox AND hands back a
     *     RE-arm; drop it and the seam delivers one notice per session.
     *
     * NOT EXPOSED AS ITS OWN ACCESSOR, deliberately. Everything above is
     * already reachable through the `Model` contract, and a second public door
     * onto the same `Cmd` would be a second thing to keep in step with
     * whatever `init()` grows next. The gap E223 records is a missing
     * SENTENCE, not a missing capability, and
     * {@see \SugarCraft\Crush\Tests\Chat\HostedRuntimeNoticeWakeTest} pins
     * the whole loop running with no `Program` anywhere — including the
     * dormancy, which a doc-block on its own leaves unasserted.
     */
    public function init(): ?\Closure
    {
        return $this->runtimeNoticeWake();
    }

    /**
     * A Cmd that resolves with one {@see RuntimeNoticePumpMsg} the next time a
     * notice lands on the sink's cross-fork transport — see {@see init()}.
     *
     * NULL WHEN THERE IS NO TRANSPORT, rather than a promise that never
     * settles. Without one the sink is on its in-process array backend, which
     * only this process can write to and only synchronously; every such write
     * happens inside an `update()` or an `init()`, and `Program` reconciles
     * after both, so `hasPending()` is consulted in time and the tick takes it
     * from there. The gap this closes is specifically the OFF-LOOP writer, and
     * off-loop writers reach the sink through the datagram pair or not at all.
     *
     * THE `!$armed` ARM INSIDE THE PROMISE RESOLVES WITH null AND NOT WITH A
     * PUMP. `Cmd::promise()` accepts `?Msg`, and null dispatches nothing —
     * which is what must happen if the transport disappeared between the
     * check above and the factory running (a `reset()` from a test's
     * `tearDown`, or a second `Bootstrap::chat()`). Resolving with a
     * `RuntimeNoticePumpMsg` instead would drain an empty inbox, re-arm, fail
     * to arm again, and resolve immediately once more: a hot loop, built out
     * of the fix for a missing wake-up.
     */
    private function runtimeNoticeWake(): ?\Closure
    {
        if (!$this->drainsRuntimeNotices || !RuntimeNoticeSink::hasTransport()) {
            return null;
        }

        return Cmd::promise(static function (): PromiseInterface {
            $deferred = new Deferred();

            $armed = RuntimeNoticeSink::notifyOnceWhenPending(
                static function () use ($deferred): void {
                    $deferred->resolve(new RuntimeNoticePumpMsg());
                },
            );

            if (!$armed) {
                $deferred->resolve(null);
            }

            return $deferred->promise();
        });
    }

    public function update(Msg $msg): array
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

            $message = $msg->message;

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
            $settled = $this->mutate(['streamingText' => '', 'reasoningText' => '']);

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
            return self::releaseQueuedPrompts([$settled->mutate([
                'history' => [...$this->history, $message],
                'inFlight' => false,
                'inFlightCancellation' => null,
            ]), null]);
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
        if ($msg instanceof ToolEventPumpMsg) {
            return $this->pumpLiveToolEvents();
        }
        if ($msg instanceof RuntimeNoticePumpMsg) {
            return $this->pumpRuntimeNotices();
        }
        if ($msg instanceof StatusLineTickMsg) {
            // The `statusLine` command's ONE side-effecting call site. Runs
            // here rather than in view() because view() may not have side
            // effects, and on a TICK rather than on every Msg because a
            // proc_open() per update is one per keystroke.
            //
            // Returns $this unchanged and a null Cmd: the runner holds the
            // text in process state ({@see StatusLineCommand::line()}), not on
            // the model, so there is nothing to fold into a new Chat. The
            // repaint comes from Program re-rendering after the update, which
            // it does for every Msg — this arm does not have to ask for one.
            StatusLineCommand::refresh();

            return [$this, null];
        }
        if ($msg instanceof HistoryCompactedMsg) {
            // Accounted BEFORE the latch check, and for the same reason the
            // AssistantMsg arm accounts before its staleness guard: the
            // summarization call went out on the user's key and was billed
            // whether or not its answer is still wanted. Dropping the summaries
            // is right; forgetting the money is not.
            $this->accountUsage($msg->usage);

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

            return $this->applyModelCompaction($msg);
        }
        if ($msg instanceof SessionTitledMsg) {
            // Accounted before either guard below, on the same rule the other
            // three provider-call arms follow: the titler is a real call on the
            // user's key, and a session that was switched away from or a title
            // that came back unusable cost exactly as much as one that did not.
            $this->accountUsage($msg->usage);

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
        // BOTH encodings of Ctrl+C. candy-core's InputReader normalizes every
        // control byte 0x01-0x1a into (Char, chr(0x60 + code), ctrl: true), so
        // the real terminal delivers ^C as rune 'c' WITH the ctrl flag and
        // never as the raw "\x03" this used to test for alone -- which meant
        // Ctrl+C could not quit the app on the live path at all. The raw rune
        // is still accepted for callers that synthesize a KeyMsg directly.
        if ($msg->type === KeyType::Char
            && ($msg->rune === "\x03" || ($msg->ctrl && $msg->rune === 'c'))) {
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
        //     visibility-scoped, so `new Chat(pendingPermission: $ask)` builds
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
        // The session picker is excluded for the same reason the palette is:
        // Escape closes the overlay there (see handleSessionPickerKey()),
        // which is more specific than "cancel the in-flight turn".
        if ($msg->type === KeyType::Escape && $this->palette === null && $this->sessionPicker === null) {
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
                // Half a sentence left under the cancellation notice would
                // read as an answer the user is still waiting on. The
                // generation bump also strands any delta still in the inbox,
                // so nothing can type into the void after this.
                'streamingText' => '',
                'reasoningText' => '',
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
                // the request leaves - and the call is still billed, because
                // update() accounts usage ahead of the latch check.
                'pendingCompactionId' => null,
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

        return match (true) {
            // Alt/Shift/Ctrl+Enter insert a newline instead of submitting.
            // Alt+Enter is the reliable one across plain terminals (ESC+CR,
            // now decoded correctly by InputReader - see candy-core's
            // Alt-prefixed-key fix); Shift/Ctrl+Enter only arrive
            // distinguishably on terminals that report the Kitty keyboard
            // protocol unprompted, but cost nothing to also honor.
            //
            // Inserted AT the cursor rather than appended, which is the whole
            // point of the widget: before this arm went through
            // {@see TextArea::insertRune()} it appended to the end of the
            // draft, so splitting an existing line in two was impossible.
            $msg->type === KeyType::Enter && ($msg->alt || $msg->shift || $msg->ctrl)
                => [$this->withInput($this->input->insertRune("\n")), null],
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
            // R20: Ctrl+A re-runs the exact same /agents dispatch submit()
            // already uses for typed input (handleAgentsCommand()), giving
            // KeyboardHandler's Ctrl+A shortcut (Pane::Agents in the
            // disconnected App/Tui system) a real, reachable equivalent on
            // this, the live, Chat path. Must be checked before the generic
            // Char arm below, or the literal "a" would be typed into the
            // input buffer instead.
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'a'
                => $this->withInputBuf('/agents')->submit(),
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
            // keystroke produces them", which is wrong. Measured at candy-core's
            // decoder, InputReader::parse("\t") yields KeyType::Tab, not
            // KeyType::Char "\t", and the Tab arm below leaves the buffer alone, so
            // no rune puts "\t" or " \t " in the box. Space and "\0" (Ctrl+Space)
            // are the only two of trim()'s six bytes a keystroke lands, and that
            // whole map is asserted byte by byte in the test named above.
            //
            // Untypeable is not unreachable. The Up arm above copies
            // lastUserMessageContent() in VERBATIM, and
            // Chat::reviveCheckpointMessage() turns a checkpoint row whose role is
            // neither 'assistant' nor 'system' -- a 'tool' row, whose output is full
            // of tabs -- into a user message with its content unchanged. ('system'
            // used to land here too; that was a bug, fixed in E33's review round,
            // and this route never depended on it.) Driven end to end in that
            // test: a revived tool row plus one Up puts "\t/keys" (or "\t", or
            // " \t ") in the box. These are drafts a user can hold; they are just
            // not drafts a user can type. Same for "\u{000C}", which is in the
            // NON-blank set: parse("\x0C") is Ctrl+L and types the letter, so a
            // form feed is recalled-only too.
            // Nor is there a paste route in: candy-core's decoder DOES emit a paste
            // message for bracketed paste, and update() returns the IDENTICAL object
            // when handed one -- both now asserted in that test. That pair replaces
            // the instrument this comment used to cite, "`grep -rn PasteMsg src/` is
            // empty", which stopped being true the moment it was written: every hit
            // that grep returns today is prose in this file claiming there are none.
            // A driven assertion cannot refute itself that way.
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
            // the draft to candy-forms' TextArea, and Left/Right/Home/End
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
            // correctly alt-flagged Backspace (see candy-core's
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
            // candy-core's decoder rather than assumed:
            // InputReader::parse("\x1b[1;5D") yields KeyMsg(Left, ctrl) - the
            // xterm-family spelling - and parse("\x1b[1;3D") yields
            // KeyMsg(Left, alt), the spelling terminals that report modifiers
            // in CSI parameters use for Alt. Home/End arrive as both
            // `CSI H`/`CSI F` and `CSI 1~`/`CSI 4~`, and Delete as `CSI 3~`;
            // all five were driven through the decoder.
            //
            // Two encodings a terminal may send for the same INTENT do NOT
            // reach here, and neither is a regression - both behave exactly as
            // they did before this arm existed:
            //
            //   * `ESC ESC[D`, the ESC-prefixed Alt+Left some terminals emit,
            //     decodes as TWO messages (Escape, then a bare Left), so the
            //     Escape is consumed by the Escape arm above and the Left
            //     moves one character rather than one word;
            //   * `ESC b`/`ESC f`, readline's Alt+B/Alt+F word motion, decodes
            //     as KeyMsg(Char "b", alt) and is therefore TYPED by the
            //     delegation below, exactly as it was before this change.
            //
            // Binding those two is a keymap addition rather than part of
            // moving the draft into the widget, so it is left as a follow-up
            // instead of smuggled in here.
            ($msg->type === KeyType::Left && ($msg->alt || $msg->ctrl))
                => [$this->withInputCursor($this->wordLeftOffset()), null],
            ($msg->type === KeyType::Right && ($msg->alt || $msg->ctrl))
                => [$this->withInputCursor($this->wordRightOffset()), null],
            // R20: Ctrl+Tab / Ctrl+Shift+Tab cycle the active session
            // through the real SessionStore listing — see
            // cycleSessionTab()'s docblock for the decode/routing chain a
            // real keypress takes and how this relates to Tui\SessionTabs.
            $msg->type === KeyType::Tab && $msg->ctrl
                => $this->cycleSessionTab($msg->shift ? -1 : 1),
            // A ctrl-flagged Char no arm above claimed types the LETTER, and
            // that is pinned rather than tidy: KeyHelpTest's byte map asserts
            // Ctrl+L puts "l" in the box and Ctrl+K puts "k", which is the
            // measurement behind its "a form feed is not typeable" claim.
            // So it goes to insertRune() and NOT to update(), because
            // TextArea::update() reserves ctrl+a/e/u/k/o for its own line
            // edits and would swallow those two instead of typing them.
            // Re-binding them is a keymap decision, not part of moving the
            // buffer into the widget.
            $msg->type === KeyType::Char && $msg->ctrl
                => [$this->withInput($this->input->insertRune($msg->rune)), null],
            // Ctrl+Space types a space, which is exactly what HEAD did with
            // it: `CSI 32;5u` decodes to KeyMsg(Space, ctrl), and HEAD's
            // Space arm did not inspect modifiers. It needs its own arm here
            // because TextArea::update() answers a ctrl-flagged key from its
            // OWN ctrl table (rune a/e/u/k/o) and drops everything else, so a
            // ctrl-flagged Space handed to the widget is swallowed. Same
            // reasoning, same route, as the ctrl-flagged Char above.
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
            // its own five-entry rune table, dropping every other ctrl-flagged
            // key REGARDLESS of type. So a ctrl-flagged member of the list
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
            !$msg->ctrl && \in_array($msg->type, self::DRAFT_KEYS, true)
                => $this->delegateToInput($msg),
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
     * Reachability: live end to end since 737da6413 (W3.S1). The chain a
     * real keypress takes is
     *   candy-core `InputReader` decodes `CSI 1;5I`/`CSI 1;6I` into
     *   `KeyMsg(Tab, ctrl: true[, shift: true])`
     *   -> `Tui\KeyboardHandler` deliberately declines Ctrl+Tab (its
     *      pane-cycling arm requires an UNmodified Tab) so the App shell
     *      passes it down
     *   -> this handler,
     * and `Cli\Bootstrap::seedSession()` now creates-or-resumes a real
     * session row before constructing the Chat, so `currentSessionId` is
     * non-null from the first frame instead of for the whole process
     * lifetime. This docblock previously said the opposite on both counts;
     * both statements were written before that commit and were left stale
     * for four days.
     *
     * What remains is the `$count < 2` guard, and it is intended behaviour
     * rather than a gap: a boot seeds exactly ONE row, so Ctrl+Tab has
     * nothing to cycle to until the user makes a second session — via the
     * Ctrl+P palette's "New session" ({@see handlePaletteNewSession()},
     * which really does call `SessionStore::createSession()`) or `/branch`.
     * That is the same threshold `Renderer::renderSessionTabStrip()` uses to
     * decide whether to draw the strip at all, so the two surfaces appear
     * together.
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
        // An ASK for a turn that was abandoned (double-Escape) or otherwise
        // superseded can still land: the PreToolUse chain that raised it ran
        // against the generation that was current when the batch was gated.
        // Putting its prompt up would suspend a turn nobody is waiting on, and
        // would force inFlight true behind an overlay that OUTRANKS the prompt
        // (see Renderer::renderView()'s chain). The message carries a
        // generation for exactly this comparison; the AssistantMsg arm in
        // update() is the pattern, and $generation being null still means
        // "unstamped, apply it" there and here alike.
        //
        // DORMANT DEFENCE, stated as such rather than as a live path closed --
        // the same honest form shellOwnsKeyboard()'s unobservable conjunct is
        // documented in. Measured: both callers build the ASK with
        // $this->generation on the very object they then call (beginToolCalls()
        // and answerPermission(), and mutate() touches no 'generation' at
        // either site), so the comparison below is tautologically FALSE at
        // every internal call site. `grep 'new PermissionRequestMsg' src/`
        // finds exactly those two lines; nothing else constructs one, and the
        // engine path that would -- PermissionRequestMsg's own docblock names
        // it -- is not wired.
        //
        // What that bounds is which tests can watch this guard FIRE. It does
        // NOT bound which tests reach a STAMPED ask, and the first version of
        // this comment claimed the second: ChatTest's whole permission suite
        // reaches one, through exactly the two producers above. They cannot
        // make the comparison true, which is the property -- not that they
        // never get here. No count is given for that suite either; the
        // previous revision said "14 ChatTest tests" and it was correct when
        // written, which is the exact shape every stale figure in this comment
        // has had. Rows 3 and 4 below measure it when a reader mutates.
        //
        // FIND THE RIGHT SITE BEFORE MUTATING. This exact predicate --
        // `$msg->generation !== null && $msg->generation !== $this->generation`
        // -- appears FOUR times in this file: update()'s AssistantMsg arm, this
        // method, finishToolCalls() and applyBackendToolEvent(). THREE of the
        // four bodies are byte-identical apart from indentation -- the first
        // three named. applyBackendToolEvent()'s is not: its arm bills the
        // superseded turn's usage before dropping its events, because the chain
        // it breaks means no AssistantMsg is ever synthesised for that turn and
        // update()'s accounting arm is never reached. So a replace-first sed
        // lands on the AssistantMsg arm, not here; the previous revision of this
        // table did exactly that and then recorded the wrong site's numbers as a
        // refutation of the right ones. The table below is therefore anchored by
        // SITE, and the mis-site's figures are kept beside it as the tell.
        // Reproduce with
        // `grep -nF 'generation !== null && $msg->generation !== $this->generation' src/Chat.php`.
        //
        // The four sites, which method owns each, and WHICH THREE are mutually
        // indistinguishable are asserted rather than narrated, by
        // KeyHelpTest::testTheGenerationGuardPredicateAppearsInExactlyFourNamedMethods().
        //
        // Mutations of the guard belonging to THIS method -- the block
        // immediately below this comment, inside requestPermission() -- each judged
        // by the targeted files going red. NO LINE NUMBER is given, deliberately:
        // the previous two revisions carried one ("1175", corrected to "1220"), it
        // was wrong both times because editing this comment moves the guard under
        // it, and the grep above plus the four-site test already name the site
        // exactly. A figure that cannot survive its own paragraph does not belong
        // in the paragraph.
        //
        // The TRIO column carries NAMES, not counts, for the same reason. The
        // revision before last recorded "1 failure / 1 error / 1 error / 1
        // failure" and "raw trio totals 2 / 2 / 2 / 2"; rows 3 and 4 were 3 raw /
        // 2 behavioural even as it was written, because the test added in that
        // very commit (testThePromptAndTheReferenceCannotBothBeRaisedByRealInput)
        // reaches a stamped-and-current ask and so trips them. A trio count is
        // fed by the files being measured; a class name (STALE / CURRENT) is not.
        //
        // The CHATTEST column carries counts, and the round that replaced them
        // with a name made the column worse rather than better -- see below the
        // table. A name is only an improvement when it identifies the population;
        // "the permission suite" identified neither of the two it was applied to.
        //
        //   | mutation                     | trio (behavioural)   | ChatTest        |
        //   |------------------------------|----------------------|-----------------|
        //   | guard deleted                | STALE only           | green           |
        //   | throw when the guard FIRES   | STALE only           | green           |
        //   | throw on ANY stamped ask     | STALE + CURRENT      | 14 errors       |
        //   | 2nd conjunct dropped, so     | STALE + CURRENT      | 1 error,        |
        //   | every stamped ask is dropped |                      | 11 failures,    |
        //   |                              |                      | 6 warnings      |
        //
        // The ChatTest figures are counts again, with their domain, because the
        // revision that replaced them with the NAME "the permission suite" named
        // a population that does not exist: the two rows report 14 and 12
        // problems, and ChatTest has no 14-test and no 12-test suite to be. What
        // the two rows share is the population, and THAT is the durable label:
        // both reds land on the SAME ELEVEN ChatTest methods, measured by name --
        // testApprovingAnAskDispatchesTheRewrittenCallTheUserWasShown,
        // testASessionGrantCannotSilentlyDispatchAnAsksOwnRewrite,
        // testAskHookSuspendsTheTurnInsteadOfRunningOrDenyingTheCall,
        // testTheSuspendingCmdStaysPendingUntilTheUserAnswers,
        // testOnceReplyRunsTheToolAndGatesItExactlyOnce,
        // testAlwaysReplyGrantsTheToolForTheRestOfTheSession,
        // testRejectReplyRefusesTheCallAndEndsTheTurn,
        // testPermissionKeysDecideThePrompt, testUnmappedKeyLeavesThePermissionPromptUp,
        // testAnsweringOneAskDoesNotReleaseTheOtherCallsInTheBatch and
        // testAlwaysForOneToolDoesNotReleaseAnAskForAnother. The counts differ
        // only because testPermissionKeysDecideThePrompt is a data-provider test:
        // row 3 reds all four of its data sets (y / a / n / escape), row 4 only
        // the two approving ones -- and the reason is worth recording, because it
        // is this round's own rule seen from the other side. Under row 4 the ask
        // is dropped, so the turn is left NOT in flight; the refusing sets assert
        // exactly `!inFlight`, so they pass VACUOUSLY on a prompt that never
        // appeared. Measured: the "y" set fails at ChatTest.php:3756 on
        // `assertSame(false, !$answered->inFlight)`. Domain of both
        // figures: tests/ChatTest.php alone at 215 tests, mutated at
        // requestPermission()'s guard (the site this comment sits above) in a
        // sandbox copy of this lib, PHP 8.3.6. Counts go stale; the eleven names
        // and the data-provider explanation are what survive a test being added.
        //
        // where the two trio classes are, and this is the RULE that keeps the table
        // true as tests are added:
        //
        //   STALE   -- a test that hands this method a stamped ask from a SUPERSEDED
        //              turn. Exactly one exists and only one can exist per distinct
        //              way of building one:
        //              KeyHelpTest::testASupersededAskNeverPutsUpAPrompt().
        //   CURRENT -- every test that reaches a stamped ask AT ALL, whoever built
        //              it. Open-ended by construction, and RE-ENUMERATED by running
        //              row 3 and reading the reds back rather than by reasoning about
        //              who ought to be in it, because the previous revision's list of
        //              "the three KeyHelpTest tests" was short by one BEFORE this
        //              round touched anything (testEachQueuedAskArmsAfresh reaches a
        //              stamped ask through answerPermission()'s resume path and was
        //              not listed). Re-measured at THIS commit, row 3 reds these SIX:
        //              KeyHelpTest::testWithAPromptUpNeitherKeyReachesItsOverlay(),
        //              KeyHelpTest::testAKeyThePromptActsOnReachesItAndYApprovesRatherThanRefuses(),
        //              KeyHelpTest::testAPromptOutlivesItsTurnAndTheReferenceIsStillRefused(),
        //              KeyHelpTest::testEachQueuedAskArmsAfresh(),
        //              MouseModalGuardTest::testAClickUnderALivePromptIsRefusedExactlyAsTheKeyIs() and
        //              MouseModalGuardTest::testAPromptRaisedOverAnOpenOverlayOutranksItOnBothDevices(),
        //              the last two being the members from outside KeyHelpTest -- both
        //              drive a real PreToolUse ask hook to put a modal up, one for the
        //              click path to be refused by and one to establish that a prompt
        //              over an open overlay is a state real input can build at all.
        //              Row 3's raw total is 8: these six, the STALE member, and the
        //              text pin.
        //              testThePromptAndTheReferenceCannotBothBeRaisedByRealInput() is
        //              NOT a member: it was split into the first two above and now
        //              stops at the in-flight half.
        //
        // Rows 1 and 2 are bounded, rows 3 and 4 are not, and that difference is the
        // whole content of the table. Row 2 is the honest bound on OBSERVABILITY:
        // exactly one test anywhere can watch this branch being taken. Row 3 is what
        // refutes the wording that claimed no other test can even REACH a stamped
        // ask. Row 4 changes BEHAVIOUR as well as observability, which is why its
        // ChatTest column is the loud one: the stamped-and-current path is what
        // ChatTest exercises.
        //
        // Measured with this comment: RendererTest, Commands/KeyBindingDriftTest and
        // Chat/InFlightInputQueueTest contribute ZERO behavioural reds to all four
        // rows, even though they are three fifths of the domain by file count --
        // their asks are UNSTAMPED, and none of these four mutations touches an
        // unstamped ask. The domain is what the rows were measured over; it is not
        // the set of files that react. That silence is a fact about THIS site: the
        // same mutations at the wrong site (below) red Chat/InFlightInputQueueTest
        // six times and RendererTest not at all, which is the reverse shape.
        //
        // Every row ALSO reds
        // testTheGenerationGuardPredicateAppearsInExactlyFourNamedMethods(), because
        // each of these mutations edits the guard's TEXT and that test reads the four
        // blocks back. By design, and the reason it is excluded from the column
        // above rather than folded into it: it is a "you changed the guard, re-read
        // this table" alarm, not evidence about the guard's reach. So raw = the
        // column above plus exactly one, on every row -- stated as a rule, because
        // the revision that stated it as "2 / 2 / 2 / 2" was already wrong on two.
        //
        // The variant that made the previous revision's error visible: discarding
        // EVERY ask rather than only stamped ones (`if (true)`) reds every trio test
        // that needs a prompt to appear at all, plus ChatTest 1 error, 11 failures,
        // 6 warnings -- so the trio is NOT silent about it, and the rows above are
        // not covering it either. It is the ONE figure in this comment kept as a
        // count, because it is the mis-site tell described below and a tell needs a
        // magnitude. Re-measured AT THIS COMMIT, over all FIVE files of the
        // domain: 31 raw / 30 behavioural, composed of 7 in RendererTest, 17 in
        // KeyHelpTest (including the pin), 4 KeyBindingDriftTest data sets, 1 in
        // Chat/InFlightInputQueueTest and 2 in MouseModalGuardTest. The figure this
        // replaces read "20 raw / 19 behavioural, composed of 5 / 12 / 3"; driven
        // against `995eb257` with none of this round's files present it came out at
        // 29 raw over the four files that then existed, so it had gone stale on its
        // own before the fifth file was written -- exactly the growth its own caveat
        // below predicts, recorded here as a re-measurement rather than as a delta.
        // The EXCLUSION RULE that produces the
        // second number, stated because the revision before last recorded a total no
        // rule produced ("14 behavioural (16 raw)"): raw MINUS exactly one, the
        // text-reading pin, testTheGenerationGuardPredicateAppearsInExactlyFourNamedMethods.
        // Nothing else is excluded.
        //
        // That total MUST NOT be read as fixed -- it grows by one for every
        // prompt-dependent test added to any of the FIVE files of the domain named
        // two paragraphs up (it grew by two on this commit, both in
        // MouseModalGuardTest). The sentence used to say "those three files", which
        // named the historical trio while sitting under a paragraph that had just
        // re-declared the domain as five. What is load-bearing is the rule and the
        // fact that the figure is not zero.
        //
        // At the WRONG site (update()'s AssistantMsg arm) the same two mutations,
        // ALL FOUR figures re-driven at this commit and each stated over BOTH
        // domains, because the previous revision compared a re-measured right-site
        // number against a wrong-site number taken over the smaller domain and
        // flagged only that it carried someone else's date:
        //
        //   * 2nd-conjunct-dropped -> 2 raw / 1 behavioural over the five-file
        //     domain (Chat/InFlightInputQueueTest::testTheDrainedPromptCarriesIts
        //     OwnGenerationAndCancellationToken), which is 1 raw / 0 behavioural
        //     when restricted to the historical trio; ChatTest 6 failures.
        //   * `if (true)` -> 13 raw / 12 behavioural over the five-file domain,
        //     composed of 5 in KeyHelpTest (including the pin), 6 in
        //     Chat/InFlightInputQueueTest and 2 in MouseModalGuardTest; restricted
        //     to the trio that is 5 raw / 4 behavioural. ChatTest 1 error, 37
        //     failures, 6 warnings, over its 222 tests.
        //
        // The previous revision published "trio 3" for that last one. It is 4
        // behavioural over the trio now, and the reason is the one its own sentence
        // gave and then failed to apply: it tracks the real-gate group in
        // KeyHelpTest, and that group became FOUR when testEachQueuedAskArmsAfresh
        // was added to the CURRENT list above -- a correction made in one half of
        // this comment and not in the half that cites it.
        //
        // The old tell -- "zero behavioural trio reds means you mutated the wrong
        // place" -- is now HALF TRUE and is corrected rather than repeated: it holds
        // for 2nd-conjunct-dropped and no longer for `if (true)`, because the
        // KeyHelpTest tests built on promptRaisedByTheRealGate() drive a real
        // AssistantMsg through update() and so react at BOTH sites. The tell that
        // does not decay is the magnitude AND the population, both re-measured
        // above over the SAME five-file domain: at the right site `if (true)` reds
        // the whole prompt-dependent population (30 behavioural, led by RendererTest
        // and KeyHelpTest), at the wrong site 12. The population tell is a RATIO,
        // not a zero, and the previous revision wrote it as a zero fifty lines below
        // its own enumeration, which had already said otherwise:
        // Chat/InFlightInputQueueTest is 1 of the right site's 30 and 6 of the wrong
        // site's 12 -- three percent against half. So the two sites are told apart
        // by WHICH files answer as much as by how many: a mutation at the right site
        // can make at most one queue test fail while one at the wrong site makes six,
        // and one at the wrong site cannot make RendererTest or KeyBindingDriftTest
        // fail at all.
        // And the four-site text pin fires at either site, which is the cheapest
        // signal that you edited SOMETHING and must re-read this table.
        //
        // Domains: "trio" is the historical name for tests/RendererTest.php +
        // tests/Renderer/KeyHelpTest.php + tests/Commands/KeyBindingDriftTest.php,
        // the three files that CONSTRUCTED a PermissionRequestMsg when the rows
        // below were first measured; the set is FIVE today (see the paragraph after
        // next) -- asserted, not narrated, and by a token scan for
        // `new …PermissionRequestMsg` rather than by the
        // `grep -rl PermissionRequestMsg tests/` this line used to cite, which also
        // matches files that merely mention the class in a comment:
        // KeyHelpTest::testTheGuardMutationDomainIsTheFilesThatBuildAPermissionRequestMsg(),
        // whose docblock states what that instrument can and cannot see. PHP 8.3.6.
        //
        // A FOURTH file joined without costing a re-measurement: W2's
        // tests/Chat/InFlightInputQueueTest.php raises an UNSTAMPED ask (to reach
        // answerPermission()'s denial path, which is one of the four places a queued
        // prompt is released), and no row of this table touches an unstamped ask --
        // the same reason two of the original three contribute nothing.
        //
        // A FIFTH did cost one, and the rows above are the re-measurement.
        // tests/MouseModalGuardTest.php hand-builds an unstamped ask for most of its
        // states, but ONE of its tests raises a prompt through a real PreToolUse ask
        // hook and so reaches a stamped, current one. That is the rule this comment
        // already stated, applied: re-measuring is due when a file joins the set
        // with a STAMPED ask.
        //
        // ChatTest is measured separately BECAUSE rows 3 and 4 show the domain's
        // silence does not cover it. Rows 1 and 2 red inside KeyHelpTest alone; rows
        // 3 and 4 red there AND in MouseModalGuardTest, which is what stopped
        // "KeyHelpTest ALONE" being true. RendererTest, KeyBindingDriftTest and
        // InFlightInputQueueTest build UNSTAMPED asks, which no row of this table
        // touches.
        //
        // So this is not what makes the reference-over-prompt state
        // unreachable, and the sentence claiming it was "the one way that state
        // is reachable through the front door" was wrong: there is no producer
        // for that door today. That unreachability is now asserted rather than
        // stated -- driven from both ends through a real PreToolUse ask hook by
        // KeyHelpTest::testThePromptAndTheReferenceCannotBothBeRaisedByRealInput().
        // What actually protects the user there is the cue
        // -- Renderer::KEY_HELP_OVER_PROMPT -- which is driven and does bite.
        // The guard stays because the engine path is coming and an unstamped
        // ASK is the legitimate case it must keep letting through; deleting a
        // correct guard because today's producers cannot trip it is how the
        // path arrives unprotected.
        if ($msg->generation !== null && $msg->generation !== $this->generation) {
            return [$this, null];
        }

        $deferred = new Deferred();

        $next = $this->mutate([
            'pendingPermission' => $msg,
            // Armed AFRESH, and stated here because this method is the resume
            // path too: answerPermission() re-enters it for the next queued
            // ask, and mutate() would otherwise carry the stage the user left
            // the PREVIOUS question in - so a `/` typed at question one would
            // silently make question two unanswerable.
            'permissionStage' => PermissionPromptStage::Armed,
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
            // Reset with the prompt rather than left behind: the stage is only
            // meaningful while a prompt is up, and a Chat carrying
            // ConfirmingAlways with nothing pending is a state nothing means.
            // requestPermission() arms the next ask anyway, so this is hygiene
            // on the accessor, not the arm rule.
            'permissionStage' => PermissionPromptStage::Armed,
            'permissionDeferred' => null,
            'pendingPermissionJobs' => [],
        ];

        // Settle the waiting Cmd before anything else: whatever happens next
        // returns its own Cmd, and a promise left pending here would keep the
        // Program waiting on a decision that has already been made.
        $this->permissionDeferred?->resolve(null);

        // A denial ENDS the turn, with no AssistantMsg to follow it, so a queue
        // released only at update()'s settle arm would strand here — the
        // permission prompt is a mid-turn state by definition, which makes it one
        // of the likelier places for a queue to have accumulated. The permitting
        // path below keeps the turn running and deliberately does not drain.
        if (!$reply->permits()) {
            return self::releaseQueuedPrompts([$this->mutate([
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
                    Message::system('_' . DenialKind::Refused->reason("{$request->toolCall->name} was not run.") . '_'),
                    // The refusal also has to exist as a RESULT, not only as
                    // a system note (crush_feat.md §1 E7): the assistant
                    // message above carries the tool call, so leaving it
                    // unanswered puts a tool_use block on the next request's
                    // wire with no matching tool_result. It doubles as the
                    // producer for the struck-through denied row
                    // {@see Renderer::renderToolResults()} draws.
                    Message::assistant('')->withToolResults([ToolResult::error(
                        $request->toolCall->name,
                        DenialKind::Refused->reason("{$request->toolCall->name} was not run."),
                        $request->toolCall->id,
                    )]),
                ],
            ]), null]);
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
     * Answers are still the three {@see PermissionReply} defines and still go
     * out as a {@see PermissionReplyMsg}, so the decision path is identical
     * whether it came from a key, a palette action or a test. What a KEY has to
     * get past first is the arm rule.
     *
     * ── THE ARM RULE, and the defect it closes ──
     *
     * This arm sits above the input box ({@see update()}, the
     * `$this->pendingPermission !== null` check), so while a prompt is up EVERY
     * `Char` key reaches here and nothing types. That alone made an ordinary
     * slash command an answer: `/agents` hit `a` on its second keystroke and
     * wrote a session-long grant, `/init` hit `n` on its third and denied the
     * call. So a prompt is now ARMED or DISARMED
     * ({@see PermissionPromptStage}):
     *
     *   * it goes up ARMED, and every newly-raised queued ask arms afresh
     *     ({@see requestPermission()} is the sole writer of a non-null
     *     `$pendingPermission` and sets the stage in the same `mutate()`);
     *   * one keystroke that is not an answer DISARMS it, because a person
     *     typing prose or a command is not a person answering;
     *   * while disarmed `y`/`n`/`a` do nothing at all;
     *   * `Enter` RE-ARMS and answers nothing — the recovery, without which a
     *     disarmed prompt could never be answered from the keyboard;
     *   * `Escape` is live in every stage, and refuses. Nothing a user TYPES
     *     produces it, and the answer it gives is the safe one.
     *
     * And `a` no longer grants on its own: it raises a confirm that one `y`
     * commits, because {@see PermissionReply::Always} is the only reply that
     * outlives the call it answers ({@see gateToolCall()} honours
     * `permissionGrants[<tool>]` for the rest of the session). `n`/Escape at
     * the confirm cancel back to an ARMED prompt (the user is plainly deciding
     * in the dialog); any other key cancels back to a DISARMED one.
     *
     * ── MEASURED, one `KeyMsg(Char, c)` per character, at a `bash` prompt ──
     *
     *   | typed          | answers at | rune | outcome                        |
     *   |----------------|------------|------|--------------------------------|
     *   | `/keys`        | never      | --   | swallowed, prompt disarmed     |
     *   | `/agents`      | never      | --   | swallowed, prompt disarmed     |
     *   | `/branch main` | never      | --   | swallowed, prompt disarmed     |
     *   | `/compact`     | never      | --   | swallowed, prompt disarmed     |
     *   | `/init`        | never      | --   | swallowed, prompt disarmed     |
     *   | `/new`         | never      | --   | swallowed, prompt disarmed     |
     *   | `/help`, `/quit`, `/model` | never | -- | swallowed, prompt disarmed |
     *   | `/agents`, Enter, `y` | 9th | `y`  | approved ONCE (the recovery)   |
     *   | `yes` / `Y`    | 1st        | `y`  | approved once                  |
     *   | `no` / `nay`   | 1st        | `n`  | denied                         |
     *   | `a`            | never      | --   | confirm raised, nothing granted|
     *   | `an`           | never      | --   | confirm cancelled, still armed |
     *   | `ay` / `aye`   | 2nd        | `y`  | ALWAYS: `{"bash":true}`        |
     *
     * Every session grant in that table now costs two deliberate keystrokes,
     * and not one of the six slash commands reaches an answer at all.
     *
     * A pasted command does not walk this table: bracketed paste decodes to a
     * `PasteMsg` and {@see update()} drops it (the identical object comes back
     * -- asserted in KeyHelpTest). Only an UNBRACKETED paste, delivered as raw
     * `Char` keys, does.
     *
     * ── THE RESIDUAL, stated rather than apologised for ──
     *
     * A message that BEGINS with `y` or `n` still answers on its first
     * keystroke — `yes`, `no`, `nay` above. That is not a hole in the arm rule,
     * it is the rule working: those keys ARE the answers, and the first
     * keystroke is the only one at which the prompt has no evidence to the
     * contrary. Closing it needs an Enter-to-commit modal (type the letter,
     * press Enter), which was weighed and rejected: it taxes every single
     * answer to the common question in order to catch a message that happens to
     * open with one of two letters, and the outcomes it would catch are
     * "allowed this one call" and "refused this one call" — both recoverable,
     * neither persistent.
     *
     * A first-keystroke `a` (`aye`, `and`, `also`) opens the confirm rather
     * than granting, which is why the confirm is where the second keystroke was
     * spent: it costs a `y` in second position to matter, and any other next
     * key cancels it. `ay`/`aye` in the table are that residual, driven.
     *
     * All of it is pinned keystroke-for-keystroke by
     * KeyHelpTest::testTypingAtALivePromptIsSwallowedUntilEnterReArmsIt() and
     * its confirm/recovery siblings, so any change shows up as a red test
     * rather than as a silent shift.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handlePermissionKey(KeyMsg $msg): array
    {
        $rune = strtolower($msg->rune ?? '');
        $isChar = $msg->type === KeyType::Char;

        // ── the confirm, which only `a` at an armed prompt can raise ──
        //
        // Checked first because in this stage the letters mean something else
        // entirely: `y` is not "allow once", it is "yes, the whole session".
        if ($this->permissionStage === PermissionPromptStage::ConfirmingAlways) {
            if ($isChar && $rune === 'y') {
                return $this->answerPermission(PermissionReply::Always);
            }

            // `n`/Escape are the confirm's OWN answers, so pressing one proves
            // the user is reading this dialog and deciding in it - the base
            // prompt stays armed and one `y` still allows the call. Anything
            // else is the same evidence that raised the confirm by accident,
            // so it cancels AND disarms.
            $stage = ($msg->type === KeyType::Escape || ($isChar && $rune === 'n'))
                ? PermissionPromptStage::Armed
                : PermissionPromptStage::Disarmed;

            return [$this->mutate(['permissionStage' => $stage]), null];
        }

        // Enter is the re-arm, and answers nothing. It is the recovery from a
        // disarm: without it a user who typed one character at a prompt could
        // never answer it from the keyboard at all, which is a worse failure
        // than the one the disarm fixes. Answering nothing is deliberate -
        // "press Enter to continue" habits would make a session grant one
        // muscle-memory keystroke away again.
        if ($msg->type === KeyType::Enter) {
            return [$this->mutate(['permissionStage' => PermissionPromptStage::Armed]), null];
        }

        // Escape stays live in EVERY stage, and is the one answer key the
        // disarm does not take away. Two reasons, both about what can produce
        // it: no message, slash command or shortcut hunt types an Escape, so it
        // is not the accident the arm rule exists to stop; and the answer it
        // gives is the REFUSING one, so even a stray Escape (this app has a
        // documented source - see update()'s Escape arm on the Alt+Backspace
        // decoding bug) costs the paused call and can never grant anything. A
        // modal that cannot be dismissed while disarmed would be the worse
        // trade. {@see Renderer::PERMISSION_DISARMED_OPTIONS} says so on screen.
        if ($msg->type === KeyType::Escape) {
            return $this->answerPermission(PermissionReply::Reject);
        }

        if ($this->permissionStage === PermissionPromptStage::Disarmed) {
            return [$this, null];
        }

        // `a` no longer grants; it ASKS. The reply it leads to is the only one
        // that outlives the call being answered, so it is the only one worth a
        // second keystroke - see PermissionPromptStage::ConfirmingAlways.
        if ($isChar && $rune === 'a') {
            return [$this->mutate(['permissionStage' => PermissionPromptStage::ConfirmingAlways]), null];
        }

        $reply = match (true) {
            $isChar && $rune === 'n' => PermissionReply::Reject,
            $isChar && $rune === 'y' => PermissionReply::Once,
            default => null,
        };

        if ($reply === null) {
            // THE ARM RULE. A key that is not an answer is evidence that the
            // person at the keyboard is not answering - they are typing a
            // message, or a slash command, or hunting for a shortcut - so the
            // answer keys go inert until Enter says otherwise. This one line is
            // what makes `/agents` at a live prompt harmless instead of a
            // session-long grant; the docblock's table is measured against it.
            return [$this->mutate(['permissionStage' => PermissionPromptStage::Disarmed]), null];
        }

        return $this->answerPermission($reply);
    }

    /**
     * Route one keystroke into the open keybinding reference.
     *
     * Escape/Enter/q/? close it — the four spellings of "done reading" a
     * dismissable overlay conventionally answers, and "?" closes for the same
     * reason a second Ctrl+P closes the palette rather than reopening it on
     * top of itself. Up/Down and PageUp/PageDown scroll, because the list is
     * taller than a terminal ({@see \SugarCraft\Crush\Commands\KeyBindingRegistry}
     * declares 63 live rows across 9 contexts — 67 in all, four of them
     * dormant and therefore unlisted) and clipping it with no way to reach the
     * rest would hide exactly the bindings this screen exists to disclose.
     *
     * Everything else is swallowed rather than falling through, for the reason
     * {@see handleSessionPickerKey()} gives: a stray letter must not type into
     * an input box the user cannot see behind the modal.
     *
     * ── the "?" close also TYPES a literal "?" ────────────────────────────
     *
     * That one exception is what makes a message beginning with "?" typeable
     * again, and it is a real cost repaid rather than a flourish. `update()`'s
     * "?" arm binds the character on an empty input line, and when this arm was
     * written that input box HAD no cursor movement and no paste path, so
     * "?why" could not be composed AT ALL — measured then, the "?" opened the
     * reference and the remaining runes were swallowed, leaving inputBuf empty.
     *
     * The cursor half of that is no longer true: crush_code.md Phase 3 item 1
     * moved the draft into `candy-forms`' TextArea, so there is now a second
     * route — type "why", press Home, type "?" — which
     * `ChatInputCursorTest::testHomeThenAQuestionMarkComposesALeadingQuestionMark()`
     * drives. This arm is still the only route on a genuinely EMPTY line,
     * where there is no character for the cursor to sit in front of, and it
     * stays for that case (and because the footer already advertises it).
     *
     * Three options were weighed. Dropping the "?" shortcut is not one of them:
     * the shortcut is the feature. Letting the next unbound printable rune fall
     * through into the input would type "?why" from exactly those keystrokes,
     * but it inverts this overlay's swallow-everything invariant for every
     * letter — a reader trying `j` to scroll would lose their place and find
     * "?j" in the box — and it still leaves a lone "?" message untypeable,
     * because "?" itself would keep closing without typing. Gating the OPEN on
     * some further condition needs a signal that distinguishes "about to
     * compose" from "about to read", and this model has none: the two states
     * are byte-identical.
     *
     * So the second "?" carries the character. One rule, one sentence, one
     * extra keystroke ("??why" types "?why", "??" then Enter sends "?"), no new
     * field on the model, and the reader who wants a clean dismissal has the
     * three other spellings — which is why {@see Renderer::renderKeyHelp()}'s
     * footer lists both behaviours on screen instead of leaving the insert to
     * surprise someone. /keys is NOT this escape hatch and never was: it opens
     * the same reference, which is no help to a user composing a question.
     *
     * The append is written against `$this->inputBuf` rather than as a plain
     * `'?'` because that is what the arm MEANS — "the character the keystroke
     * denotes, at the caret". Today the buffer is always empty here (the "?"
     * arm requires it, and `submit()`'s /keys branch clears it), so the two
     * spellings agree; the day the reference can be opened over a draft they
     * would not, and appending is the reading that stays correct.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleKeyHelpKey(KeyMsg $msg): array
    {
        $offset = $this->keyHelp ?? 0;
        // The overlay's body is rows() - 5 lines tall (rows() - 2 for the box,
        // less its two border rows and its footer hint — see
        // Renderer::renderKeyHelp()). One less than that, so a page leaves a
        // row of context rather than jumping to wholly unfamiliar text.
        $page = max(1, $this->rows() - 6);
        $rune = $msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt ? $msg->rune : null;

        return match (true) {
            $msg->type === KeyType::Escape,
            $msg->type === KeyType::Enter,
            $rune === 'q' => [$this->withKeyHelp(null), null],
            // Closes AND types the character — see this method's docblock for
            // why the insert is here and not behind a new binding.
            $rune === '?' => [$this->withKeyHelp(null)->withInputBuf($this->inputBuf . '?'), null],
            $msg->type === KeyType::Up => [$this->withKeyHelp($offset - 1), null],
            $msg->type === KeyType::Down => [$this->withKeyHelp($offset + 1), null],
            $msg->type === KeyType::PageUp => [$this->withKeyHelp($offset - $page), null],
            $msg->type === KeyType::PageDown => [$this->withKeyHelp($offset + $page), null],
            default => [$this, null],
        };
    }

    /**
     * How far the keybinding reference is scrolled, or null when it is
     * closed. {@see Renderer::renderKeyHelp()} reads this to decide whether
     * to draw the overlay at all.
     */
    public function keyHelp(): ?int
    {
        return $this->keyHelp;
    }

    /**
     * Open the keybinding reference at $offset lines down, or close it with
     * null.
     *
     * Clamped against {@see Renderer::keyHelpMaxOffset()} — the height the
     * LAST rendered reference overflowed by — exactly as {@see scrollBy()}
     * clamps the transcript against {@see Renderer::maxScrollOffset()}, and
     * for the same reason: an offset that grew past the content would make
     * the next press feel dead while the number silently ran away. Before the
     * overlay has ever been drawn that ceiling is 0, so a caller opening at a
     * non-zero offset lands at the top.
     *
     * "Exactly as" includes {@see scrollBy()}'s short-circuit: a clamped offset
     * equal to the one already held hands back `$this` rather than a fresh
     * Chat, so holding Down at the end of the reference does not allocate a
     * model and diff a frame for every dead notch.
     */
    public function withKeyHelp(?int $offset): self
    {
        $clamped = $offset === null
            ? null
            : max(0, min($offset, Renderer::keyHelpMaxOffset()));

        if ($clamped === $this->keyHelp) {
            return $this;
        }

        return $this->mutate(['keyHelp' => $clamped]);
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
     * Whether the pending prompt is still listening for a letter, has been
     * disarmed by a keystroke that was plainly not an answer, or is waiting on
     * the confirm for a session-wide grant.
     *
     * {@see Renderer::renderPermissionPrompt()} reads this to draw the state
     * the user is actually in — a disarmed prompt that silently ate keys and
     * looked identical to an armed one would trade one invisible behaviour for
     * another. Meaningless while {@see pendingPermission()} is null.
     */
    public function permissionStage(): PermissionPromptStage
    {
        return $this->permissionStage;
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
                // The placeholder's content IS Message::describeToolCall()'s
                // one-liner, and it is the only carrier of the call's
                // arguments that reaches this point - the result itself never
                // saw them. Carrying it onto the finished result is what lets
                // a collapsed row still say WHAT ran (crush_feat.md §3 E2).
                $result = $resultsById[$pendingId]->withDescription($historyMessage->content);
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
            // Superseded mid-queue: the transcript states this event described
            // are dropped, but the turn behind it COMPLETED and was charged for,
            // and this is the last chance to say so. Returning here breaks the
            // re-dispatch chain, so no AssistantMsg is ever synthesised and
            // update()'s own accounting arm is never reached - measured, a
            // superseded tool turn's usage went entirely unbilled while a
            // superseded plain turn's was recorded. Reached at most once per
            // turn, for the same reason: the chain stops here.
            $this->accountUsage($msg->message->usage);

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
     * Append a tool-lifecycle event to the live inbox
     * ({@see $liveToolEvents}).
     *
     * The one mutating public method on this immutable model, and the seam a
     * {@see Backend}'s `$onEvent` callback writes through: the callback runs
     * inside the backend, where the Chat instance it could return has nowhere
     * to go. {@see subscriptions()} polls for what lands here and
     * {@see pumpLiveToolEvents()} turns it into transcript state.
     *
     * @param int|null $generation Turn this event belongs to; entries stamped
     *                             with a generation other than the one current
     *                             at drain time are dropped (an aborted turn's
     *                             backend can keep reporting for a while).
     *                             Null stamps the Chat's current generation,
     *                             which is what a caller with no turn of its
     *                             own (a test, an embedder) wants.
     */
    public function enqueueToolEvent(ToolStarted|ToolFinished $event, ?int $generation = null): void
    {
        $this->liveToolEvents[] = [$generation ?? $this->generation, $event];
    }

    /**
     * Tool-lifecycle events queued by the backend but not yet folded into the
     * transcript, oldest first.
     *
     * @return list<ToolStarted|ToolFinished>
     */
    public function liveToolEvents(): array
    {
        $events = [];
        foreach ($this->liveToolEvents as [, $event]) {
            // TokenDelta and ReasoningDelta share the queue (see the
            // property docblock) but are not tool lifecycle events, and this
            // accessor's contract is.
            if ($event instanceof TokenDelta || $event instanceof ReasoningDelta) {
                continue;
            }
            $events[] = $event;
        }

        return $events;
    }

    /**
     * Append one fragment of assistant text to the live inbox
     * ({@see $liveToolEvents}) — the text counterpart of
     * {@see enqueueToolEvent()}, written through by a {@see Backend}'s
     * `$onToken` callback (crush_code.md Phase 0 item 13).
     *
     * Mutating for exactly the reason that one is: the callback fires inside
     * the backend, on a ReactPHP readable edge for {@see Backend\EngineBackend},
     * where a returned Chat would have nowhere to go.
     *
     * @param int|null $generation see {@see enqueueToolEvent()} — a delta from
     *                             a turn the user has since aborted is dropped
     *                             at drain time rather than typed onto the
     *                             screen after the cancellation notice.
     */
    public function enqueueToken(string $text, ?int $generation = null): void
    {
        if ($text === '') {
            return;
        }

        $this->liveToolEvents[] = [$generation ?? $this->generation, new TokenDelta($text)];
    }

    /**
     * Append one fragment of the model's THINKING to the live inbox
     * ({@see $liveToolEvents}) — the EMBEDDER and TEST entry point onto that
     * inbox (E456/E494).
     *
     * WHAT THIS SAID BEFORE: that it is "written through by a
     * {@see Backend\ObservesReasoning} backend's `$onReasoning` callback".
     * WHAT IS TRUE NOW: it never was. Measured — this method has no caller
     * under `src/` or `bin/` at all. The live path's `$onReasoning` closure in
     * {@see scheduleBackendCompletion()} appends to the shared inbox DIRECTLY,
     * because it must be a `static` closure and so has no `$this` to call a
     * method on. WHY IT STILL EARNS ITS PLACE (rule 6 — a dormant seam gets
     * wired or justified, never deleted): it is the same public shape
     * {@see enqueueToken()} has, and it is how anything OUTSIDE a
     * {@see Backend} — an embedder hosting this model, or a test driving the
     * paint without a backend — puts a thought in front of the renderer. The
     * risk a dormant parallel implementation carries is DRIFT, so the two are
     * pinned as equivalent rather than merely both present; see
     * `ReasoningPaintTest`'s agreement test, which also records the one half of
     * the duplication no assertion on painted text can cover.
     *
     * Mutating for the reason {@see enqueueToken()} is: the callback fires
     * inside the backend — for {@see Backend\EngineBackend} on the ReactPHP
     * readable edge that drains a `reasoning` frame off the fork's socket —
     * where a returned Chat would have nowhere to go.
     *
     * NOT a {@see enqueueToken()} call with different styling. A thought
     * routed onto the token channel would end up in
     * {@see $streamingText}, and one layer down it would end up in the
     * {@see Messages\AssistantMessage} the model is re-sent; see
     * {@see ReasoningDelta}.
     *
     * @param int|null $generation see {@see enqueueToolEvent()} — a thought
     *                             from a turn the user has since aborted is
     *                             dropped at drain time rather than painted
     *                             under the cancellation notice.
     */
    public function enqueueReasoning(string $text, ?int $generation = null): void
    {
        if ($text === '') {
            return;
        }

        $this->liveToolEvents[] = [$generation ?? $this->generation, new ReasoningDelta($text)];
    }

    /**
     * The in-flight reply as far as it has arrived; empty outside a turn, and
     * outside a turn the model has actually started answering.
     *
     * {@see Renderer} reads this to replace the static "assistant is
     * thinking…" placeholder with the words as they are written.
     */
    public function streamingText(): string
    {
        return $this->streamingText;
    }

    /**
     * The model's thinking for the current step as far as it has arrived;
     * empty outside a turn, and outside a turn whose backend reports reasoning
     * at all.
     *
     * {@see Renderer} reads this to paint the thought above the reply, dimmed
     * and collapsed, so a model that thinks for two minutes before its first
     * content byte is visibly working instead of showing a frozen
     * "assistant is thinking…".
     */
    public function reasoningText(): string
    {
        return $this->reasoningText;
    }

    /**
     * Prompts typed and sent while a turn was in flight, oldest first — see the
     * constructor param's docblock.
     *
     * {@see Renderer::renderStatusBar()} reads this so the count sits beside the
     * "thinking…" spinner: a queued message the user cannot see is a lost
     * message, and the transcript notice {@see enqueuePrompt()} writes scrolls
     * away while the status bar does not.
     *
     * @return list<string>
     */
    public function queuedPrompts(): array
    {
        return $this->queuedPrompts;
    }

    /**
     * Drain ONE entry from the live inbox and fold it into the transcript,
     * re-scheduling itself while anything is left (crush_feat.md §1 E1).
     *
     * One event per `update()` for the same reason
     * {@see applyBackendToolEvent()} does it: each returned Chat is rendered
     * before the next event is applied, which is what makes an
     * engine-dispatched call visibly walk from *running* to *done* instead of
     * appearing already-finished. Now that {@see Backend\EngineBackend}
     * streams each event the moment it fires rather than replaying the batch
     * at the end of the turn, that walk happens WHILE the tools run.
     *
     * Stale entries are skipped rather than applied, and skipping still
     * re-schedules, so a queue full of an aborted turn's events empties
     * instead of blocking the ones behind it.
     *
     * {@see TokenDelta}s and {@see ReasoningDelta}s are the exception to
     * one-entry-per-update, and are COALESCED: a run of consecutive deltas OF
     * THE SAME KIND is folded into a single append. Same kind, because the two
     * accumulate into different fields and folding across the boundary would
     * append one channel's bytes to the other's — the precise corruption
     * {@see ReasoningDelta} exists to prevent. One-at-a-time is what makes a
     * tool call's running→done walk visible, but a delta has no such two-state
     * shape — it is text — and a provider emits hundreds to thousands of them
     * per reply. Rendering the whole transcript once per token would spend the
     * turn repainting instead of streaming, while coalescing bounds the repaint
     * rate at the pump's own
     * {@see TOOL_EVENT_POLL_SECONDS} tick and loses nothing: the user cannot
     * read faster than the screen refreshes either way. Coalescing stops at
     * the first non-delta entry, so text never jumps ahead of the tool call it
     * preceded.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function pumpLiveToolEvents(): array
    {
        $pending = $this->liveToolEvents->getArrayCopy();
        $entry = array_shift($pending);

        if ($entry === null) {
            return [$this, null];
        }

        [$generation, $event] = $entry;

        // Consumed-prefix cursor rather than an array_shift per coalesced
        // delta: shifting re-indexes the whole remainder every time, so a
        // burst of n deltas cost O(n^2) to fold into one append. One slice at
        // the end is O(n) for the same result.
        $consumed = 0;
        $text = null;
        $thought = null;
        if ($event instanceof TokenDelta || $event instanceof ReasoningDelta) {
            // Coalesce only entries of the SAME class as the head, so a run of
            // thinking never folds into the reply's accumulator or the reverse.
            $kind = $event::class;
            $run = $event->text;
            while (($peek = $pending[$consumed] ?? null) !== null
                && $peek[1] instanceof $kind
                && $peek[0] === $generation) {
                $consumed++;
                $run .= $peek[1]->text;
            }
            if ($event instanceof TokenDelta) {
                $text = $run;
            } else {
                $thought = $run;
            }
        }

        $this->liveToolEvents->exchangeArray(
            $consumed === 0 ? array_values($pending) : array_slice($pending, $consumed),
        );
        $more = count($this->liveToolEvents) > 0 ? Cmd::send(new ToolEventPumpMsg()) : null;

        if ($generation !== $this->generation) {
            return [$this, $more];
        }

        if ($text !== null) {
            return [$this->mutate(['streamingText' => $this->streamingText . $text]), $more];
        }

        if ($thought !== null) {
            return [$this->mutate(['reasoningText' => $this->reasoningText . $thought]), $more];
        }

        $next = $event instanceof ToolStarted
            ? $this->appendToolRunningPlaceholder($event)
            // A ToolFinished deliberately does NOT reset the partial: the
            // model has not spoken since the reset its ToolStarted already
            // did, so there is nothing to clear and clearing would be
            // indistinguishable either way.
            : $this->replaceToolRunningPlaceholder($event);

        // The model stopped talking and started doing. Whatever prose
        // introduced this call belongs to the step that is now over, and the
        // next step's deltas are a new utterance - see $streamingText's
        // docblock for why an accumulation spanning steps would visibly
        // shrink when the turn settles.
        if ($event instanceof ToolStarted) {
            $next = $next->mutate(['streamingText' => '', 'reasoningText' => '']);
        }

        return [$next, $more];
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

        $newHistory = [];
        $replaced = false;
        foreach ($this->history as $historyMessage) {
            if (!$replaced && $historyMessage->pendingToolCallId === $event->toolCallId) {
                // Same reasoning as finishToolCalls(): the placeholder content
                // is Message::describeToolCall()'s one-liner, and ToolFinished
                // carries no arguments, so this is the only point at which the
                // finished row can learn WHAT ran (crush_feat.md §3 E2).
                $newHistory[] = self::toolResultMessage($result->withDescription($historyMessage->content));
                $replaced = true;

                continue;
            }
            $newHistory[] = $historyMessage;
        }

        if (!$replaced) {
            $newHistory[] = self::toolResultMessage($result);
        }

        return $this->mutate(['history' => $newHistory]);
    }

    /**
     * The finished-tool-call history entry {@see replaceToolRunningPlaceholder()}
     * writes, in both its replace and its append branch.
     *
     * Split out only so the two branches cannot drift apart now that the
     * replace branch attaches a description the append branch has no way of
     * knowing (there is no placeholder to read it off).
     */
    private static function toolResultMessage(ToolResult $result): Message
    {
        return Message::assistant($result->isError() ? "Tool error: {$result->error}" : $result->result)
            ->withToolResults([$result]);
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
     * THE TWO BRANCHES ARE TRUSTED DIFFERENTLY ON PURPOSE, and the reason is
     * recorded here because E348 found the tree said nothing about it.
     *
     *  - A callback that THROWS has its message wrapped by
     *    {@see executionFailure()}, so nothing it said can reach
     *    {@see isDeniedResult()}'s roster (E308). An exception message is
     *    whatever string was nearest — an OS error, an HTTP body, a library's
     *    prose — and the tool did not CHOOSE to say "this call was blocked".
     *  - A callback that RETURNS a `ToolResult` has its `error` field carried
     *    through VERBATIM, roster prefix and all, so it can declare its own
     *    refusal and be drawn struck through and listed in a
     *    `--output-format json` document's `refusals` array. That is the
     *    decision, not a gap E308 missed: an MCP tool whose server refused,
     *    a `Skill` a policy stopped, or a wrapper enforcing its own gate each
     *    really did have the call blocked, and disbelieving them would make a
     *    refusal real only when THIS process made it — false the moment a tool
     *    is out-of-process.
     *
     * The reasoning, the boundary between the branches, and what would change
     * the answer (a typed `DenialKind` field on `ToolResult`, so a callback
     * DECLARES a refusal instead of spelling one) are asserted rather than
     * only written down, by
     * {@see \SugarCraft\Crush\Tests\Chat\CallbackAuthoredRefusalTest}.
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
            return [ToolResult::error($name, self::executionFailure($name, $e), $toolCall->id), null, false];
        }
    }

    /**
     * The error text a tool that THREW is reported with.
     *
     * A TOOL THAT THROWS COULD OTHERWISE FORGE A REFUSAL (E308). The catch
     * above used to put `$e->getMessage()` into the result's error field
     * verbatim, and {@see isDeniedResult()} reads exactly that field and hands
     * it to {@see DenialKind::classify()}, which asks whether the text OPENS
     * with a roster prefix. So a tool whose exception message began
     * `Permission denied:` — an MCP server quoting its own refusal, a `Skill`
     * re-raising an OS error, any text that happens to start that way — was
     * drawn struck through in the TUI and listed in a `--output-format json`
     * document's `refusals` array as a call THAT NEVER RAN. It ran, and it
     * failed, and those are different facts about what the model just did.
     *
     * WHY THE FIX IS A WRAPPER AND NOT ANOTHER SCANNER. The round-49
     * co-occurrence guard finds a class that spells a roster prefix; this
     * catch is generic, so the throwing class is not named here and need not
     * be in this repository at all. There is nothing for a scanner to look at.
     * A wrapper is structural: the text now opens with a literal that is on no
     * roster, so `classify()` answers null whatever the exception said.
     *
     * THE SHAPE IS {@see \SugarCraft\Crush\Runtime}'s, DELIBERATELY. The
     * engine path was never exposed to this — `Runtime::executionFailure()`
     * already wraps a throw as `Error: <tool> failed with <class>: <message>`
     * — and that asymmetry between the two paths is what E308 recorded. Two
     * renderings of one event would have replaced it with a different
     * asymmetry, so this spells the same sentence.
     * {@see \SugarCraft\Crush\Tests\ChatTest} asserts the two agree by
     * running both.
     */
    private static function executionFailure(string $tool, \Throwable $e): string
    {
        return sprintf('Error: %s failed with %s: %s', $tool, $e::class, $e->getMessage());
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
                    DenialKind::Unanswered->reason("{$toolCall->name} was not approved."),
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

            $file = \SugarCraft\Crush\Support\ToolIpcFiles::reserve(
                \SugarCraft\Crush\Support\ToolIpcFiles::CHAT_PREFIX,
                'json',
            );
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
            projectRoot: $this->projectRoot(),
        );

        $hookResult = $this->hooks->preToolUse($context);

        if ($hookResult->isAsk()) {
            // An ASK can carry a rewrite an earlier hook in the same chain
            // made (see HookRegistry::executeHooks()): the question was put
            // about the REWRITTEN call, so the job queued here has to be the
            // rewritten one or an approval would dispatch the originals the
            // user was never shown.
            [$toolCall, $context] = self::applyRewrite($toolCall, $context, $hookResult);

            return ($this->permissionGrants[$toolCall->name] ?? false)
                ? [$toolCall, null, $context, null]
                : [$toolCall, null, $context, $hookResult];
        }

        if (!$hookResult->isAllowed() && !$hookResult->isModified()) {
            return [
                $toolCall,
                ToolResult::error($toolCall->name, DenialKind::Hook->reason($hookResult->message), $toolCall->id),
                null,
                null,
            ];
        }

        [$toolCall, $context] = self::applyRewrite($toolCall, $context, $hookResult);

        return [$toolCall, null, $context, null];
    }

    /**
     * The tool call a hook chain's rewrite (if any) says should actually run,
     * paired with the context that describes THAT call.
     *
     * Both halves move together deliberately. The context returned here is the
     * one {@see applyPostToolUse()} hands `PostToolUse`, and the incoming one
     * still describes the model's PROPOSAL — so leaving it behind made
     * `AuditHook` record a command that was never executed, on precisely the
     * calls (the rewritten ones) whose record anybody would care about.
     *
     * Returns $toolCall untouched when the result carries no rewrite, or when
     * the rewrite will not decode to an argument map ({@see
     * \SugarCraft\Crush\Hooks\HookResult::rewrittenArgs()}, which is also
     * where the JSON-list case is refused) — the same conservative fallback
     * the engine path takes, and the reason
     * {@see \SugarCraft\Crush\Hooks\ScriptHook::modifyOrDeny()} refuses to
     * emit a non-object rewrite in the first place.
     *
     * ONLY A MODIFY OR AN ASK CARRIES A REWRITE HERE, matching
     * {@see Runtime::rewrittenArguments()} (which gates on `isModified()`) and
     * {@see Runtime::asAsked()} (the ASK half) — the "decision for decision"
     * claim on {@see gateToolCall()} is only true if this side draws the same
     * line. A plain ALLOW carrying a `modifiedInput` is constructible (the
     * {@see \SugarCraft\Crush\Hooks\HookResult} constructor is public) and
     * {@see \SugarCraft\Crush\Hooks\HookRegistry::executeHooks()} never
     * re-scans one, since only a MODIFY makes the loop take another pass — so
     * honouring it would dispatch arguments no hook in the chain ever judged,
     * which is the fail-open the re-scan exists to close.
     *
     * @return array{0: ToolCall, 1: HookContext}
     */
    private static function applyRewrite(
        ToolCall $toolCall,
        HookContext $context,
        \SugarCraft\Crush\Hooks\HookResult $result,
    ): array {
        $decoded = $result->isModified() || $result->isAsk()
            ? $result->rewrittenArgs()
            : null;

        return $decoded !== null
            ? [
                new ToolCall($toolCall->name, $decoded, $toolCall->id),
                $context->withRewrittenArgs($decoded, (string) $result->modifiedInput),
            ]
            : [$toolCall, $context];
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

        // 0600 + atomic rename, via the same helper Runtime's fork path uses:
        // this payload is a whole tool result (file bodies, fetched pages) and
        // was landing world-readable in /tmp under the ambient umask.
        \SugarCraft\Crush\Support\ToolIpcFiles::write($file, $json === false ? '' : $json);
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

            // Signal them ALL first, then collect them TOGETHER against one
            // shared budget: killing and reaping pid-by-pid made the stall
            // proportional to the number of children, which is not what
            // REAP_BUDGET_SECONDS says.
            $stragglers = [];
            foreach ($pendingIndexes as $index => $_) {
                if (function_exists('posix_kill')) {
                    posix_kill($jobs[$index]['pid'], SIGKILL);
                }
                $stragglers[] = $jobs[$index]['pid'];
            }

            self::reapKilledToolChildren($stragglers);

            $settled = true;
            $loop->cancelTimer($timer);
            $deferred->resolve(array_map($collect, $jobs));
        });

        return $deferred->promise();
    }

    /**
     * Collect ONE tool child we have just SIGKILLed, over a bounded `WNOHANG`
     * window — the single-child spelling of
     * {@see reapKilledToolChildren()}, which is what the live call site uses.
     *
     * This was an unflagged `pcntl_waitpid()`, which is the same defect
     * {@see \SugarCraft\Crush\Runtime::reapKilled()} already carries the fix
     * and the reasoning for: `posix_kill()` above is guarded because ext-posix
     * is not guaranteed, and in exactly the build where that guard skips,
     * NOTHING KILLED THE CHILD — so the wait that follows is unbounded on a
     * tool that had already refused to finish.
     *
     * It is worse here than there by one degree, and that is why this fix is
     * in this bundle. {@see Runtime::executeConcurrently()} runs inside the
     * forked completion child, on nobody's event loop; this loop body is a
     * {@see \React\EventLoop\LoopInterface::addPeriodicTimer()} callback in
     * the TUI PROCESS. A blocking wait here does not stall a turn, it stalls
     * the render and the keyboard — including the Escape-Escape that reaches
     * this same routine through $cancellation.
     *
     * @param int $pid
     */
    private static function reapKilledToolChild(int $pid): void
    {
        self::reapKilledToolChildren([$pid]);
    }

    /**
     * Collect a whole batch of just-SIGKILLed tool children over ONE bounded
     * `WNOHANG` window.
     *
     * THE BUDGET IS PER SITE, NOT PER CHILD, and the singular spelling above
     * could not deliver that. The give-up branch of
     * {@see waitForToolChildrenAsync()} calls it once per pending pid, so the
     * stall it produced was N x {@see REAP_BUDGET_SECONDS}: MEASURED on this
     * host at fc597e81, 1 child 0.101s, 4 children 0.405s, 8 children 0.810s.
     * `PARALLEL_TOOL_TIMEOUT_SECONDS` is a fan-out timeout, so "several
     * children at once" is the ordinary shape of this branch and not the
     * exotic one — and eight tenths of a second of frozen render and dead
     * keyboard is exactly the visible stall the 100ms figure was chosen to
     * stay under.
     *
     * Every pid is polled on every turn of the loop rather than one being
     * drained before the next is looked at, so the budget is SHARED and not
     * SPENT BY THE FIRST: a batch where child one needs the whole window
     * would otherwise leave every other child a zombie, this branch having
     * cancelled its own timer on the way out. A pid still unreaped when the
     * window closes is left as a zombie deliberately — a slot in the process
     * table, against a blocked loop being a dead terminal.
     *
     * @param list<int> $pids
     */
    private static function reapKilledToolChildren(array $pids): void
    {
        if ($pids === []) {
            return;
        }

        $status = 0;
        $deadline = microtime(true) + self::REAP_BUDGET_SECONDS;

        while (true) {
            foreach ($pids as $slot => $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                    unset($pids[$slot]);
                }
            }

            if ($pids === [] || microtime(true) >= $deadline) {
                return;
            }

            usleep(self::REAP_POLL_MICROSECONDS);
        }
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
        // Unconditionally, and including the `.partial` sibling: the old
        // "only unlink what we successfully read" left an empty or
        // half-written payload behind forever, which is the same leak
        // ToolIpcFiles::sweep() exists to mop up after a cancel.
        \SugarCraft\Crush\Support\ToolIpcFiles::discard($file);

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
        // not pointing at a control — dispatch nothing so the terminal's own
        // copy-on-select is what the gesture accomplishes.
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
     *   * {@see $sessionPicker} marks no zones of its own either: every zone
     *     visible while it is up belongs to the frame BEHIND it. Same answer
     *     as the full modals.
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
     * {@see \SugarCraft\Crush\Tests\MouseModalGuardTest::testAPressInterruptedByAPromptCannotFireOnceThePromptIsGone()}
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
        if ($this->keyHelp !== null || $this->pendingPermission !== null) {
            return [$this, null];
        }

        if ($this->inFlight && $this->midTurnRefusalOfItsOwn($zoneId)) {
            return null;
        }

        if ($this->sessionPicker !== null) {
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
     *     {@see \SugarCraft\Crush\Tests\MouseModalGuardTest::testAnIdThatCollidesWithTheRowWhitelistPastItsPrefixIsStillSwallowed()},
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

    /**
     * Click-to-select in the command palette / picker (crush_feat.md §8 E6).
     *
     * §8 E6 asks explicitly for the click to "dispatch the same Msg/Cmd the
     * Enter key currently dispatches" rather than a parallel confirm path, so
     * this only moves `selectedIndex` onto the clicked row and then hands off
     * to the exact method {@see handlePaletteKey()}'s Enter arm would reach in
     * this state. Everything that hangs off a confirm (mode transitions into
     * the providers/themes list, the §4 E7 MRU bump, `Cmd::quit()` for Exit)
     * therefore behaves identically whether the row was chosen with the
     * keyboard or the mouse.
     *
     * WHICH METHOD THAT IS DEPENDS ON `inFlight`, and a previous revision of
     * this docblock named {@see runSelectedPaletteAction()} outright — true
     * of an idle turn and false of every other, which is how the click came
     * to run mid-turn what Enter refuses. Enter's arm has branched since
     * (`$this->inFlight ? runSelectedPaletteActionWhileInFlight() : ...`), and
     * with the palette open and a turn running the two devices then disagreed
     * on 8 of the palette's 9 root rows — only `Exit` agreed, because it is
     * the one row the mid-turn arm also allows. `New session` wiped the
     * history a streaming reply was appending to; `Switch model` opened the
     * providers submenu, whose own docblock calls it "the backend the running
     * agentic loop is about to make its NEXT provider call on". So the branch
     * is mirrored here rather than described, and
     * `PaletteClickTest::testEveryPaletteRowAnswersAClickExactlyAsItAnswersEnter()`
     * drives every row through both devices in both states — a data provider
     * over `inFlight`, which it is because the revision that added it drove
     * mid-turn only while this sentence already claimed both. Idle parity held
     * when it was measured; it is now read back, which is a different thing.
     *
     * The index is re-checked against the CURRENT match list rather than
     * trusted from the zone: zones describe the previously-painted frame, and
     * a row that has since disappeared (an async reply landing, a
     * re-filtered list) would otherwise confirm whatever action drifted into
     * that slot. Out-of-range, or a click arriving after the palette closed,
     * is a no-op — the safe answer for a stale click is to run nothing.
     *
     * THIS GUARD'S THREE CONDITIONS OVERLAP, and the overlap is arithmetic
     * rather than defensive habit, so it is stated as such. `paletteMatches()`
     * answers `[]` for a null palette ({@see paletteMatchResults()}'s first
     * line), so `$row >= count(...)` is already true whenever the palette is
     * gone: the range test alone refuses every case the null test refuses.
     * Measured — dropping the null test reds nothing, and dropping the range
     * test reds nothing, because each covers the other. Only dropping the
     * range test HERE and in {@see paletteRowIndex()} together reds anything
     * (`PaletteClickTest::testAnOutOfRangePickerIndexRunsNothing()`), and the
     * same is true of the digit test and
     * `PaletteClickTest::testANonNumericPickerIndexRunsNothing()`. All three
     * conditions are therefore reported as survivors in this commit's own
     * mutation list; they stay because this method is reachable with the
     * palette CLOSED — {@see refuseMouseDispatch()}'s palette arm only applies
     * while it is open — and because a dispatch method that is safe only by
     * virtue of its caller is one refactor away from not being safe.
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

        $onTheRow = $this->mutate(['palette' => $this->palette->withSelectedIndex($row)]);

        return $this->inFlight
            ? $onTheRow->runSelectedPaletteActionWhileInFlight()
            : $onTheRow->runSelectedPaletteAction();
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
        $notch = match ($button) {
            MouseButton::WheelUp   => self::SCROLL_WHEEL_LINES,
            MouseButton::WheelDown => -self::SCROLL_WHEEL_LINES,
            default                => 0,
        };

        // With the keybinding reference up, the wheel drives IT: the transcript
        // is behind a full-screen modal, and scrolling something the user
        // cannot see is the same defect handleKeyHelpKey() swallows stray keys
        // to avoid. The reference counts DOWN from its first row (see
        // $keyHelp), the transcript counts BACK from its newest line, so the
        // notch is negated to keep "wheel up" meaning "towards the start".
        if ($this->keyHelp !== null) {
            return [$this->withKeyHelp($this->keyHelp - $notch), null];
        }

        return $this->scrollBy($notch);
    }

    /**
     * Scroll the transcript by $delta lines (positive scrolls BACK through
     * history, matching the wheel-up direction).
     *
     * Shared by the wheel and by Page Up/Page Down, which move a screenful
     * instead of a notch — the keyboard equivalent the wheel already had.
     *
     * @return array{0:self,1:?\Closure}
     */
    private function scrollBy(int $delta): array
    {
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

        // Mid-turn a tab click is the request Ctrl+Tab makes, and
        // {@see refuseWhileInFlight()} answers that one with a visible notice
        // instead of a switch — so this answers it with the SAME notice, by
        // the same method and the same label, rather than switching sessions
        // out from under a reply that is still streaming into this one.
        //
        // Checked AFTER the two validity gates above, not before: a click on
        // the tab that is already current, or on an id no store knows, was
        // going to be a no-op either way, so it is answered with a no-op.
        //
        // THAT IS A DELIBERATE DIVERGENCE FROM Ctrl+Tab, and the reason a
        // previous revision gave for it — "announcing a refusal of nothing
        // would be noise the keyboard never makes" — is FALSE and is withdrawn
        // in place. The keyboard makes exactly that noise: Ctrl+Tab is refused
        // at the head of {@see update()}'s mid-turn block by
        // {@see refuseWhileInFlight()}, ABOVE {@see cycleSessionTab()}, so it
        // never learns whether a switch was available. Driven mid-turn on a
        // store holding ONE session, where cycling is a no-op: +1 history row,
        // '"Switch session" does not run while a turn is in flight'.
        //
        // The divergence stands anyway, because the two gestures do not make
        // the same request. Ctrl+Tab asks for "the next session", which mid-turn
        // is always a session change to refuse; a click NAMES its target, and a
        // click on the tab you are already on asks for no change at all.
        // Refusing it would attach the sentence "Switch session does not run"
        // to a switch the user did not ask for. The palette staying up over it
        // is the same rule read forwards: nothing was written, so there is
        // nothing for an overlay to hide ({@see refuseInFlightAction()}). Both
        // halves are pinned, keyboard and mouse in the identical state, by
        // {@see \SugarCraft\Crush\Tests\MouseModalGuardTest::testMidTurnAClickOnTheCurrentTabRefusesNothingWhileCtrlTabStillDoes()}.
        if ($this->inFlight) {
            return $this->refuseInFlightAction('Switch session');
        }

        return [$this->withCurrentSessionId($id), null];
    }

    /**
     * Click-to-switch pane (crush_feat.md §8 E3).
     *
     * §8 E3 sketches `$app->withPane(Pane::from($name))`. This method does
     * something else, and the reason recorded here for that was FALSE.
     *
     * ## WHAT THIS DOCBLOCK USED TO SAY
     *
     * That `App::$pane` "belongs to the `App`/`Tui\Renderer` system that
     * nothing constructs (`bin/sugarcrush` runs THIS model)", and therefore
     * that "jumping a pane field no live frame reads would be a switch the
     * user can never see". It cited {@see Renderer}'s class docblock as
     * agreeing, and §5 E7's recommendation to retire the system outright.
     *
     * ## WHAT IS TRUE NOW — BOTH HALVES WERE WRONG
     *
     * **The system is constructed.** `bin/sugarcrush` ends in
     * `new Program(Bootstrap::app($args->root), Chat::programOptions())`, and
     * {@see \SugarCraft\Crush\Cli\Bootstrap::app()} builds the `App` with
     * `->withChat(self::chat($root))`. So the root Model on a real launch is
     * the `App` shell HOSTING this Chat, not this Chat;
     * {@see \SugarCraft\Crush\App\App::view()} calls
     * {@see \SugarCraft\Crush\Tui\Renderer::renderView()}, which is the live
     * frame. {@see Renderer}'s class docblock has said so since the R20.fix
     * paragraph ("the `App`-keyed pane system it belongs to is no longer
     * disconnected"); the tree was contradicting itself, and this side was the
     * stale one.
     *
     * **And the live frame does read `$a->pane`.** `renderView()` diverts
     * `Pane::Agents` to the full-width `AgentDashboardPane` before any sidebar
     * is built; `Tui\Renderer::leftSidebar()` branches on `Pane::Files` /
     * `Pane::Tools`; `Tui\Renderer::rightSidebar()` branches on `Pane::Skills`
     * / `Pane::Settings`. A pane switch is emphatically visible.
     *
     * ## THE REAL REASON THIS METHOD CANNOT TAKE E3'S SKETCH
     *
     * Ownership, not reachability. `$app` is not in scope and cannot be: this
     * is a method on the HOSTED content model, whose `update()` contract
     * returns `array{0:self,1:?\Closure}` — a Chat and a Cmd.
     * {@see \SugarCraft\Crush\App\App::delegateToChat()} takes the returned
     * Chat and re-wraps it with `withChat()`; there is no channel by which a
     * value this method computes becomes the host's `$pane`. Chat holds no
     * reference to its host, and giving it one would invert the hosting
     * relation.
     *
     * ⚠️ There IS a channel, and it is unwired rather than absent — recorded so
     * the next reader does not re-derive "impossible" from this paragraph.
     * `App\SelectPaneMsg` exists, `App::update()` answers it with
     * `withPane($msg->pane)`, and `delegateToChat()` passes this method's Cmd
     * straight up to `Program` — so a Cmd dispatching a `SelectPaneMsg` WOULD
     * reach the host. Nothing in `src/` constructs one today (only
     * `tests/App/AppTest.php` and `tests/App/AppModelTest.php` do), so that
     * message is a dormant seam, not a live route, and wiring it is a
     * behavioural change and not this docblock's business. Backlog E76.
     *
     * So a pane click dispatches the same thing the keyboard
     * already dispatches for that pane on the live path — E3's "just a
     * direct jump instead of `next()`", against the surfaces that exist:
     *
     * - {@see Pane::Menu} → open the Ctrl+P palette. The palette IS this
     *   path's menu surface; the status bar's "Ctrl+P menu" hint is the
     *   region marked for it. A click is ignored while the palette is
     *   already open: it captures keyboard input while up, so re-rooting it
     *   from underneath would undo navigation the keyboard cannot.
     *
     *   THAT ARM IS UNREACHABLE TODAY and is kept as an inner guard, with the
     *   arithmetic stated rather than asserted. This method has exactly one
     *   call site — {@see handleMouse()}, below
     *   {@see refuseMouseDispatch()} — and that guard already refuses every
     *   zone except the palette's own `picker-item:<n>` rows while the
     *   palette is up, so `pane:menu` cannot arrive here with
     *   `$this->palette` set. Measured: deleting this arm's `!== null` test
     *   and always re-rooting reds NOTHING.
     *
     *   OVER WHAT, stated as a reachability argument rather than as a suite
     *   size, because the size is the part that goes stale. The figure this
     *   replaces read "the full suite (8778 tests, at this commit)" — a count
     *   taken on base `995eb257`, three master commits and 29 `tests/Tools/*`
     *   tests before the commit whose docblock asserted it. A count cannot be
     *   re-verified by reading it, so it is replaced by a scope that can be
     *   re-derived: this method is reachable ONLY from {@see handleMouse()}'s
     *   `pane:` dispatch, so only a test that delivers a mouse event can red
     *   it, and the mutation was driven over every test file that names any
     *   mouse `Msg`, `MouseButton`, `MouseAction`, `MouseEvent`,
     *   `selectPane`, `handleMouse` or `PANE_ZONE_PREFIX`, unioned with the
     *   mouse/palette domain of {@see paletteRowIndex()} — 20 files, 935
     *   tests, 52355 assertions, green before and after. Re-derive the file
     *   list with that grep; do not trust this paragraph's `20`.
     *   It stays because it is the same rule stated where the state change
     *   lives, and it is the arm that would still hold if a second caller
     *   ever reached this method from somewhere the outer guard does not
     *   cover. An earlier revision of {@see refuseMouseDispatch()} justified
     *   keeping it as also answering "a stale zone id arriving from a frame
     *   this method never scanned"; that was false and is withdrawn — both
     *   tests read the SAME live `$this->palette` on the same instance, so
     *   there is no frame one of them can see and the other cannot.
     * - {@see Pane::Agents} → the same `handleAgentsCommand('/agents')` the
     *   Ctrl+A shortcut and the palette's SwitchAgent action already run.
     *
     * Every other case is inert HERE, which is a narrower claim than the one
     * this paragraph used to make.
     *
     * ⚠️ WHAT IT USED TO SAY: "Files/Tools/Skills/Settings/Help have NO live
     * surface on this path at all (they are `Tui\Components\*` stubs keyed on
     * `App`)". WHAT IS TRUE NOW — and was already true when that was written,
     * for the same reason the paragraph above is being rewritten — is that
     * `FilesPane`, `ToolsPane`, `SkillsPane` and `SettingsPane` are all
     * rendered by the live `Tui\Renderer::leftSidebar()`/`rightSidebar()` off
     * `App::$pane`. They have live surfaces.
     *
     * ⚠️ AND THE CORRECTION ITSELF WAS WRONG ABOUT `Help`, which is worth more
     * than a silent edit because it is the second time this docblock has
     * argued from an absence that is not there. WHAT THE CORRECTION SAID:
     * "(`Help` has no `Pane` case and no arm anywhere, so for that one the old
     * sentence held.)" WHAT IS TRUE NOW: {@see Pane} declares
     * `case Help = 'help';`, `Pane::Help->label()` returns `'Help'`, and
     * `tests/Tui/PaneTest.php` asserts all of it. Only the second half holds —
     * no `match` arm anywhere in `src/` names `Pane::Help`. And it has a live
     * surface for the same reason every other pane does:
     * `Tui\Components\MenuBar::paneTabs()` renders
     * `'Currently: ' . $a->pane->label()` unconditionally, so a frame on
     * `Pane::Help` differs from one on `Pane::Chat` (measured at 120x40: line 0
     * reads `… Currently: Help` against `… Currently: Chat`), and that is
     * already pinned — `Tui\ComponentTest::testMenuBarWithDifferentPaneLabels()`'s
     * table includes `Pane::Help => 'Help'`. WHY THIS STILL
     * EARNS ITS PLACE: the point the paragraph is making — that these panes
     * lack a WRITER reachable from here, not a surface — is unchanged and
     * covers `Help` too. What it cannot claim is that `Help` is a special case.
     *
     * WHY THE ARM STILL EARNS `default => [$this, null]`: what those panes
     * lack is not a surface but a WRITER reachable from here — see the
     * ownership paragraph above. Chat cannot move `App::$pane`, and it has no
     * second, Chat-local rendering of Files/Tools/Skills/Settings to move
     * instead; Chat/Input have no separate focus to move either, because every
     * keystroke already goes to the input box. Nothing marks a zone for those
     * panes, so this arm is only reached by a stale zone from a previous
     * frame; answering it with an invented state change would be worse than
     * answering it with nothing.
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
            // Mid-turn, refused rather than run: the arm below appends the
            // echo and the listing to the very history the turn is about to
            // write to, which is what the keyboard's own Ctrl+A refusal
            // exists to prevent. Through {@see refuseInFlightAction()} and
            // not the {@see refuseInFlightCommand()} that Ctrl+A uses,
            // because that notice ends "your draft is still in the box:
            // press Enter again" — true of a typed command, and a sentence
            // about a draft that a click never touched would be a claim
            // attached to the wrong thing.
            Pane::Agents => $this->inFlight
                ? $this->refuseInFlightAction('/agents')
                : $this->handleAgentsCommand('/agents'),
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
     * The directory this session is rooted at, already resolved — the
     * configured {@see $projectRoot} (`--root`), else the process directory.
     *
     * Resolved here rather than at each call site so the two consumers (the
     * hook contexts {@see gateToolCall()} builds and the working directory
     * {@see scheduleBackgroundSpawn()} hands a spawned session) can never
     * drift apart, and so a test can assert the resolved answer without
     * reaching for the nullable field.
     */
    public function projectRoot(): string
    {
        return $this->projectRoot ?? (getcwd() ?: '');
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
     * The resolved pool is then driven THROUGH {@see AgentManager::executeAll()}
     * whenever an AgentManager is configured, rather than being iterated
     * directly. The manager registers each SubAgent and mirrors the pool's
     * per-result usage back onto it, which is the only thing that makes
     * {@see AgentManager::elapsedSeconds()}/tokensUsed()/costUsd() -- and so
     * Renderer::agentDisplayState()'s status line -- observe real work instead
     * of zeros (crush_feat.md section 5 E6). Dispatch stays single-pass: the
     * manager accumulates with `+=`, so the pool must never also be iterated
     * for the same SubAgent instances or usage would be counted twice.
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

        if ($this->agentManager !== null) {
            return $this->agentManager->executeAll($agents, $request, $pool);
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
     * THE THREE STRINGS ARE NO LONGER SPELLED HERE (E239). WHAT THIS SAID:
     * three quoted literals, and the paragraph above naming the producers that
     * each wrote their own copy. WHAT IS TRUE NOW: the roster is
     * {@see DenialKind}, a leaf enum in `src/Permissions/` with no
     * dependencies, and all three producers named above build their reason
     * through {@see DenialKind::reason()} — so this constant is a projection
     * of that enum rather than a fourth place a prefix is written down. WHY
     * THIS CONSTANT STILL EARNS ITS PLACE: it is the shape two consumers
     * already read ({@see \SugarCraft\Crush\Renderer::renderToolResults()}
     * through {@see isDeniedResult()}, and
     * {@see \SugarCraft\Crush\Cli\NonInteractive}), and it is public API
     * that an embedder can iterate. Removing it would be a break bought for
     * nothing; deriving it makes drift impossible instead.
     *
     * AND IT IS NOW TAGGED AS WELL AS DESCRIBED (E304). The paragraph above
     * has called this a deprecated projection since E239; nothing in the tree
     * said so to a TOOL, so an embedder grepping for the tag found four fully
     * supported symbols for three kinds. Iterating this constant still works
     * and will keep working — the tag names where the supported list is.
     *
     * @deprecated Use \SugarCraft\Crush\Permissions\DenialKind::prefixes()
     *             instead. This constant is a projection of that enum and is
     *             kept only so an embedder iterating it does not break.
     *
     * @var list<string>
     */
    public const DENIED_ERROR_PREFIXES = [
        DenialKind::Refused->value,
        DenialKind::Unanswered->value,
        DenialKind::Hook->value,
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
     *
     * THE MATCHING ITSELF MOVED TO {@see DenialKind::classify()} (E239) and
     * this method is now the `?ToolResult`-shaped wrapper around it. Kept
     * rather than inlined at the call sites: `isDeniedResult()` is what the
     * renderer, the tests and the doc-blocks across this application all name,
     * and it carries the null-error case that a bare `classify()` cannot.
     * A caller that wants to know WHICH of the three kinds stopped the call —
     * the thing a bool cannot say — should reach for `DenialKind::classify()`
     * directly.
     */
    public static function isDeniedResult(ToolResult $result): bool
    {
        $error = $result->error;

        return $error !== null && DenialKind::classify($error) !== null;
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
     * THE ROLE IS PRESERVED FOR ALL THREE {@see Role} CASES, and until E33's
     * review round it was not: the match below read
     * `default => Message::user($content)` with no `'system'` arm, so every
     * app-authored system row came back as a USER message. Measured, one
     * `/rewind` turned the context-usage reminder into "the user said 'Heads
     * up: this conversation has grown to ~70109 estimated tokens… Consider
     * running /compact soon'" on the provider wire, and did the same to
     * `_Request cancelled._`, the compaction notice and the automatic tier's
     * report. It also defeated {@see withoutContextReminders()} permanently:
     * {@see isContextReminder()} requires `Role::System` — deliberately, so a
     * user QUOTING the reminder is never deleted — so a mis-roled copy could
     * never be stripped again and one more accrued per rewind.
     *
     * A row whose role is NEITHER of the three ('tool' is the one a fixture
     * constructs; nothing in this app serialises it, because {@see Role} has no
     * such case and {@see Message::toolRunning()} uses `Role::System` plus a
     * `pendingToolCallId` that the arm above intercepts) still becomes a user
     * message with its content unchanged. That is a coercion, not a contract —
     * see the Up-arrow comment in {@see update()}, which depends on it only for
     * REACHABILITY of untypeable drafts, and would need a real `Role` case to
     * fix rather than an arm here.
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
            'system'    => Message::system($content),
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
            'compactorConfig' => $this->compactorConfig,
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
            'permissionStage' => $this->permissionStage,
            'permissionDeferred' => $this->permissionDeferred,
            'pendingPermissionJobs' => $this->pendingPermissionJobs,
            'permissionGrants' => $this->permissionGrants,
            'expanded' => $this->expanded,
            'paletteMru' => $this->paletteMru,
            'scrollOffset' => $this->scrollOffset,
            'backgroundSupervisor' => $this->backgroundSupervisor,
            'backgroundStatuses' => $this->backgroundStatuses,
            'sessionPicker' => $this->sessionPicker,
            'projectRoot' => $this->projectRoot,
            // Passed by object identity on purpose: an event the backend
            // appends to the turn's inbox has to reach whichever clone is on
            // screen when the pump next runs.
            'liveToolEvents' => $this->liveToolEvents,
            'streamingText' => $this->streamingText,
            // A field missing from this map silently resets on the next
            // keystroke - for this one that means the thinking on screen
            // vanishes the moment the user touches the keyboard mid-turn.
            'reasoningText' => $this->reasoningText,
            'keyHelp' => $this->keyHelp,
            'input' => $this->input,
            // Passed by object identity, for the reason 'liveToolEvents' above
            // is: it is the session's running spend, and a clone that allocated
            // a fresh tracker would zero the total on the next keystroke.
            'tokenTracker' => $this->tokenTracker,
            'maxCostUsd' => $this->maxCostUsd,
            'summaryBackend' => $this->summaryBackend,
            'pendingCompactionId' => $this->pendingCompactionId,
            'queuedPrompts' => $this->queuedPrompts,
            'commandLoader' => $this->commandLoader,
            // Carried, so the disk walk happens once per process rather than
            // once per keystroke — see the property's doc-block.
            'customCommands' => $this->customCommands,
            // Carried like every other launch-time decision: it is read on the
            // keystroke that submits a `/command`, which is always a clone of
            // the Chat the Bootstrap built, so a value dropped here would make
            // the trust grant evaporate on the first character typed and turn
            // every project command's !`cmd` into a refusal.
            'projectCommandsTrusted' => $this->projectCommandsTrusted,
            // A field missing from this map silently resets on the next
            // keystroke — for this one that would mean the drain owner stops
            // being the drain owner the moment the user types, and every
            // mid-session notice for the rest of the session goes nowhere.
            'drainsRuntimeNotices' => $this->drainsRuntimeNotices,
        ];

        // The two write routes into the draft, kept from fighting.
        //
        // A change naming `input` is the widget having edited itself, and the
        // constructor re-derives `inputBuf` from its value(), so the stale
        // `inputBuf` carried above is harmless.
        //
        // A change naming `inputBuf` ALONE is "replace the whole draft" —
        // submit clearing it, an Up recall, a slash/palette completion, a
        // checkpoint restore. Carrying the old widget through would let it
        // overrule the new string (it wins in the constructor), so it is
        // dropped and the constructor rebuilds it from the string with the
        // cursor at the end. Without this the draft would silently ignore
        // every one of those writes.
        if (array_key_exists('inputBuf', $changes) && !array_key_exists('input', $changes)) {
            unset($constructorProps['input']);
        }

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
     * Register a callback(string $key, string $value): void fired when any of
     * FOUR DOORS applies a choice: the Ctrl+P palette's Switch Model row,
     * `/model <provider>`, the palette's Switch Theme row, and `/theme <name>`.
     *
     * WHAT THIS SAID: "the Switch Model/Switch Theme palette actions (or
     * /theme)", omitting `/model`. WHY IT IS SPELLED OUT HERE AND NOT MERELY
     * CROSS-REFERENCED to the constructor param that says the same thing: an
     * embedder deciding whether to install a callback reads the method they are
     * about to call, not the promoted-property doc-block one screen up, and an
     * enumeration that is short by one door is what makes them believe `/model`
     * is session-only. Both copies are pinned together by
     * {@see \SugarCraft\Crush\Tests\Chat\ChatConfigChangeDoorsDocumentationDriftTest},
     * so the duplication cannot drift apart silently.
     *
     * See the constructor param's docblock for the route each door takes, and
     * for why the actual persistence side effect lives in Bootstrap::chat()'s
     * wiring rather than in this class.
     */
    public function withOnConfigChange(callable $callback): self
    {
        return $this->mutate([
            'onConfigChange' => $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback),
        ]);
    }

    /**
     * Seed the transcript with the warnings a LAUNCH produced, so they are
     * readable from inside the alt screen.
     *
     * THE SEAM EXISTS BECAUSE stderr IS NOT A SURFACE AN INTERACTIVE USER HAS.
     * {@see \SugarCraft\Crush\Cli\Bootstrap::warnPermissionConfig()} writes to
     * stderr, which is the right channel for `-p` and for post-exit scrollback
     * and was, before this seam, the only channel ANY launch warning had. Four
     * `warnPermissionConfig*` call sites and `Bootstrap::reportPrunedSessions()`
     * still have only that channel, by the judgement recorded on
     * `warnPermissionConfigInTranscript()`. MEASURED on a
     * real `bin/sugarcrush` launch under a pty: the line lands 0.47s before
     * `\e[?1049h`, and replaying the captured stream through a `candy-vt`
     * `Terminal(120, 40)` finds no trace of it on the visible screen — the
     * alternate buffer painted over it, and the primary buffer it was written
     * into is not shown again until the session ENDS. An operator whose tool set
     * a checkout just cut to `Bash` could not see that it had happened.
     *
     * A TRANSCRIPT ROW, not a new render surface, and the choice is the cheap
     * one on purpose: {@see Renderer} already lays out, wraps and scrolls
     * {@see Role::System} rows — `/compact`'s report, `/branch`'s confirmation
     * and the background-session status notices are all this shape — so a
     * warning routed here inherits a surface that is already scrollable and
     * already correct at every width, instead of a banner that would have to
     * learn all of that again.
     *
     * SIXTEEN OF {@see \SugarCraft\Crush\Cli\Bootstrap}'S LAUNCH-WARNING CALL
     * SITES ARE ROUTED HERE, and the rest deliberately are not.
     *
     * WHERE THAT NUMBER COMES FROM — do not `grep` for it. The identifier
     * `warnPermissionConfigInTranscript` occurs about twice as often in
     * `Bootstrap.php` as it is CALLED, because most occurrences are the
     * declaration and `{@see}` references in the doc-blocks explaining this
     * very split. The count is a token scan: `token_get_all()` with whitespace
     * and comments stripped, counting each T_STRING of that name both preceded
     * by `::` and followed by `(`. One command re-derives it —
     * `vendor/bin/phpunit --filter BootstrapTranscriptSeamCallSiteCensusTest` —
     * and {@see \SugarCraft\Crush\Tests\Cli\BootstrapTranscriptSeamCallSiteCensusTest}
     * fails this sentence, by name, the moment a call site is added.
     *
     * WHAT THIS SAID: FOURTEEN. WHAT IS TRUE NOW: sixteen — E78 (round 42)
     * routed `reportPrunedSessions()`'s retention summary onto the seam and E86
     * (round 43) routed `mcpClient()`'s start-then-throw catch, and neither
     * round updated this paragraph. WHY THE SENTENCE STILL EARNS ITS PLACE: the
     * number is not decoration, it is the claim that the split below is a
     * DECISION applied to a known set rather than a description of wherever the
     * calls happen to be; without a count a reader cannot tell those apart.
     * That is also why round 44 (E97) made it a test instead of just correcting
     * it for the third time.
     *
     * The rule the split was
     * made on lives on
     * {@see \SugarCraft\Crush\Cli\Bootstrap::warnPermissionConfigInTranscript()}:
     * a warning earns a row iff it names something the session can no longer DO
     * — a provider that degraded to echo, agent presets that did not load, a
     * refused project hook file, dropped permission rules, a cut or empty tool
     * set, a refused project directory, skipped skill files. Warnings that
     * report a malformed config entry WITHOUT the session being diminished stay
     * on stderr, because making a transcript row of each of those is how a
     * useful notice becomes a wall a user scrolls past.
     *
     * THE LIST IS CAPPED AT ITS SOURCE, not here — see that method's
     * `LAUNCH_NOTICE_LIMIT` and `LAUNCH_NOTICE_MAX_CHARS`. This method appends
     * whatever it is handed, and the reason that is safe is that these rows are
     * part of the CONVERSATION: they are sent to the model on every turn, so an
     * unbounded list would be a per-token cost for the whole session.
     *
     * APPENDS, and callers depend on that: {@see Bootstrap::app()} calls this a
     * SECOND time with only the notices its post-`chat()` scan added, because
     * handing it the whole list again would double every row.
     *
     * @param list<string> $notices sentences, without trailing punctuation
     */
    public function withLaunchNotices(array $notices): self
    {
        $messages = [];
        foreach ($notices as $notice) {
            $notice = trim($notice);
            if ($notice === '') {
                continue;
            }
            $messages[] = Message::system($notice);
        }

        // $this, not a clone, when there is nothing to say: a launch with no
        // warnings is the common one, and an identical-but-new instance would
        // make the caller's `$chat` a different object for no reason.
        if ($messages === []) {
            return $this;
        }

        return $this->mutate(['history' => [...$this->history, ...$messages]]);
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

        // MID-TURN, Enter QUEUES instead of dispatching. This is the arm the
        // user's report asked for ("new messages should be typable and sendable
        // (well really queued for processing if its mid processing the previous
        // message)"), and it is checked ahead of everything below because the
        // whole point is that none of it runs yet: a second dispatchTurn() while
        // one is in flight is exactly the "racing ahead and queuing another turn
        // into a half-formed history" the blanket swallow in {@see update()} was
        // there to prevent.
        //
        // The `/` test is the whole classifier — see
        // {@see refuseInFlightCommand()} for why a per-command list was rejected.
        // `/exit` and `/quit` are the two that still go through mid-turn: they end
        // the process, so there is no state left for them to corrupt, and Ctrl+C
        // (checked above the mid-turn block) already quits mid-turn anyway. The
        // bare-name test mirrors {@see dispatchCommand()}'s own — `/exit now` is a
        // prompt there and must stay one here.
        if ($this->inFlight) {
            if ($text === '/exit' || $text === '/quit') {
                return [$this, Cmd::quit()];
            }

            if (str_starts_with($text, '/') || str_starts_with($text, 'mcp auth')) {
                return $this->refuseInFlightCommand($text);
            }

            return $this->enqueuePrompt($text);
        }

        // FILE-BASED COMMANDS ARE CHECKED FIRST, ahead of dispatchCommand()'s
        // built-in arms, and that ordering IS the override
        // {@see CommandLoader::loadAll()} documents: a project `compact.md` is
        // meant to replace `/compact`, and a check placed after the match arms
        // could never replace anything they already handle. The popup is built
        // on the same precedence ({@see slashCommandRows()}), so what is listed
        // and what runs cannot disagree — a claim that was FALSE for the
        // `/name:arg` spelling until {@see expandCustomCommand()} learned it,
        // and that {@see CommandRegistry::CONTROL_PLANE} bounds: seven names
        // are reserved to the application and never reach this map at all.
        //
        // It rewrites $text instead of returning a [Chat, Cmd] pair like the
        // built-ins do, because a file-based command IS a prompt — everything
        // below (spend cap, idle compaction, the 85%/95% tiers, the turn
        // dispatch itself) must apply to it exactly as it applies to typed
        // prose. Returning early would route the one kind of prompt a repository
        // can author around every one of those checks.
        $expanded = $this->expandCustomCommand($text);
        if ($expanded !== null) {
            // AN EXPANSION THAT PRODUCED NOTHING IS REFUSED, not sent. The
            // empty-draft guard at the top of this method runs against the
            // TYPED text, and `/greet` is not empty — but a body of
            // `$ARGUMENTS` invoked with no arguments expands to `''`, and
            // without this the session dispatched a real turn carrying a user
            // message whose content was the empty string. Refused visibly
            // rather than swallowed: pressing Enter and watching nothing happen
            // reads as a wedged app, and the author of the file is the only one
            // who can fix it.
            $expanded = trim($expanded);
            if ($expanded === '') {
                return $this->refuseEmptyCustomCommand($text);
            }
            $text = $expanded;
        } else {
            $dispatched = $this->dispatchCommand($text);
            if ($dispatched !== null) {
                return $dispatched;
            }
        }

        // Spend cap, evaluated AFTER dispatchCommand() on purpose: a capped
        // session must still be able to type `/budget 10` to raise the cap or
        // `/budget off` to clear it, and a check ahead of dispatch would lock
        // the user out of the only control that unlocks it.
        $refusal = $this->spendCapRefusal();
        if ($refusal !== null) {
            return $refusal;
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

        // The 85% and 95% compaction tiers (crush_code.md Phase 5 item 5).
        // ContextCompactor::shouldCompact()/shouldCompactForeground() had zero
        // call sites anywhere in src/ — the tiered design in the compactor's
        // own class docblock existed only as prose, so a session filled up
        // until the provider rejected it. Both are evaluated here, at the same
        // per-turn point as the 70% reminder {@see dispatchTurn()} adds, and
        // deliberately with NO idle-time gate: a session actively being driven
        // past 95% is the dangerous case, not the one someone walked away from.
        //
        // Order is by descending severity, and the foreground test runs
        // against the ALREADY-COMPACTED history on purpose. "Blocked until
        // space is freed" only means anything once the automatic way of
        // freeing it has been tried and come up short — compaction preserves
        // the most recent exchanges in full, so a handful of enormous ones
        // genuinely cannot be shrunk, and that is the state worth refusing on.
        $tokenLimit = $this->contextTokenLimit();
        $wireHistory = array_map(
            static fn(Message $msg): array => $msg->toWire(),
            $this->history
        );

        $baseHistory = $this->history;
        $compactionNotice = null;

        if ($this->compactor->shouldCompact($wireHistory, $tokenLimit)) {
            // Ask the model to write the summaries first, when there is one to
            // ask (crush_code.md Phase 5 item 6). Returns null - and the
            // synchronous heuristic below then runs unchanged - whenever there is
            // no summary backend, the spend cap is reached, or the history holds
            // no exchange a model could usefully summarise. That "null falls back
            // to exactly what this tier did before" is what makes the model route
            // safe to add here: the offline path is not merely similar, it is the
            // same code.
            $parked = $this->scheduleParkedCompaction($text, $tokenCount, $tokenLimit);
            if ($parked !== null) {
                return $parked;
            }

            $compactedWire = $this->compactor->compact($wireHistory);
            $savedPercentage = $this->compactor->savingsPercentage();

            // Adopt the compacted history only when it actually bought
            // something. A history of at most recentPreserveCount exchanges is
            // returned untouched no matter how large it is, and announcing
            // "saved 0%" on every turn from there on would be noise reporting
            // work that did not happen.
            //
            // The adoption decision is made HERE, before the blocking tier is
            // consulted, so that BOTH outcomes go on to report the same thing.
            // It used to sit after: the turn-still-goes-out path suppressed its
            // notice at 0% to avoid noise while the turn-refused path adopted
            // the rewrite unconditionally and said nothing about it at all -
            // the asymmetry ran the wrong way round, leaving the destructive
            // outcome as the silent one.
            if ($savedPercentage > 0) {
                $compactedHistory = $this->messagesFromWire($compactedWire, $this->history);
                $baseHistory = $compactedHistory;
                $tokenCount = $this->estimateTokenCount($compactedHistory);
                $compactionNotice = $this->contextCompactedMessage(
                    count($this->history),
                    count($compactedHistory),
                    $savedPercentage,
                    $tokenCount,
                    $tokenLimit,
                );
            }

            // Still tested against the COMPACTED wire even when the result was
            // not adopted: "blocked until space is freed" only means anything
            // once the automatic way of freeing it has been tried, and a
            // compaction that freed nothing is exactly the answer "there was
            // none to free".
            if ($this->compactor->shouldCompactForeground($compactedWire, $tokenLimit)) {
                return $this->foregroundBlockedResponse(
                    $text,
                    $baseHistory,
                    $tokenCount,
                    $tokenLimit,
                    $compactionNotice,
                );
            }
        }

        $newTurnMessages = [];
        if ($compactionNotice !== null) {
            $newTurnMessages[] = $compactionNotice;
        }
        $newTurnMessages[] = Message::user($text);

        return $this->dispatchTurn($baseHistory, $newTurnMessages, $tokenLimit);
    }

    /**
     * Longest excerpt of the user's own text a mid-turn notice quotes back.
     *
     * Bounded because these notices are transcript messages and the transcript
     * is a fixed-width pane: {@see Renderer::fitToPane()} wraps rather than
     * cuts, so an unbounded quote costs ROWS rather than correctness, and a
     * pasted 4KB draft would push the turn it is about to follow off the frame.
     * Short enough to identify the message, which is the whole job — the full
     * text is what eventually goes out, and until it does it is on
     * {@see $queuedPrompts}.
     */
    private const IN_FLIGHT_QUOTE_MAX_CHARS = 60;

    /**
     * One bounded, control-byte-free excerpt of untrusted draft text, for a
     * notice that is about to be painted.
     *
     * {@see sanitizeSummaryLine()} does the flattening and the ESC-stripping —
     * the same treatment model-authored text gets, and for the same reason: this
     * is keystroke data, so a bracketed-paste dump can carry ESC/C0/DEL, and it
     * is bound for a frame.
     */
    private static function quoteDraftForNotice(string $text): string
    {
        $clean = self::sanitizeSummaryLine($text);
        if (mb_strlen($clean, 'UTF-8') > self::IN_FLIGHT_QUOTE_MAX_CHARS) {
            $clean = mb_substr($clean, 0, self::IN_FLIGHT_QUOTE_MAX_CHARS - 1, 'UTF-8') . '…';
        }

        return $clean;
    }

    /**
     * Hold a prompt the user sent while a turn was running, to be dispatched by
     * {@see releaseQueuedPrompts()} when that turn ends.
     *
     * The draft is CONSUMED (the box empties, exactly as a real send empties it)
     * because from the user's point of view the message has been sent — it is
     * simply waiting its turn. That is what the report asked for; a send that
     * left the text in the box would read as a send that failed.
     *
     * Role::System for the notice, and that is a measured constraint rather than
     * a style choice: {@see Backend\EngineBackend::toTypedMessages()} maps
     * Role::Assistant to an AssistantMessage and {@see Providers\VertexProvider}'s
     * Anthropic path renders it as an `assistant` turn, i.e. a PREFILL the
     * provider continues instead of an instruction it reads. This notice lands
     * AFTER the running turn's user message, so an assistant role here would
     * prefill the very reply that is in flight. Same rule
     * {@see scheduleParkedCompaction()} follows for the same reason.
     *
     * NOT echoed as a Message::user(): a second user turn appended before the
     * first one's reply would leave the reply attached to the wrong prompt in the
     * pair grouping {@see Context\ContextCompactor} builds. The quoted excerpt in
     * the notice is what makes the message visible in the transcript, and
     * {@see Renderer::renderStatusBar()} carries the live count.
     *
     * @return array{0:self,1:?\Closure}
     */
    private function enqueuePrompt(string $text): array
    {
        $queue = [...$this->queuedPrompts, $text];

        return [$this->mutate([
            'queuedPrompts' => $queue,
            'inputBuf' => '',
            'history' => [...$this->history, Message::system(sprintf(
                'Queued (%d waiting) — sent as soon as this turn finishes: %s',
                count($queue),
                self::quoteDraftForNotice($text),
            ))],
        ]), null];
    }

    /**
     * Refuse, VISIBLY, a file-based command whose template expanded to nothing.
     *
     * @return array{0:self,1:?\Closure}
     */
    private function refuseEmptyCustomCommand(string $text): array
    {
        return [$this->mutate([
            'history' => [...$this->history, Message::system(sprintf(
                '%s is a command file whose template expanded to nothing — most often a body that is only '
                . '$ARGUMENTS or $1, invoked with no arguments. Nothing was sent: an empty prompt costs a '
                . 'turn and tells the model nothing. Pass arguments, or give the file a body that stands '
                . 'on its own.',
                self::quoteDraftForNotice($text),
            ))],
        ]), null];
    }

    /**
     * Refuse, VISIBLY, a slash command submitted while a turn is running.
     *
     * WHY REFUSED AND NOT QUEUED, since an ordinary prompt is queued: a queued
     * command would run minutes later against a transcript the user is no longer
     * looking at, and the commands most likely to be typed in the dead time are
     * exactly the destructive ones — `/clear` and `/rewind` would delete the
     * reply the user had just started reading. "Not now" is the honest answer;
     * "later, silently" is not.
     *
     * WHICH DRAFTS THIS CLAIMS, stated as the mechanical rule it is: every draft
     * whose first character is `/`, plus the leading-slash-less `mcp auth …`
     * spelling {@see dispatchCommand()} also accepts. NOT a per-command
     * classification, and that is deliberate — a list of unsafe names would be a
     * second copy of {@see dispatchCommand()}'s arms to keep in step, and the
     * classification is not close: measured over the arms there, every handler
     * either rewrites `history`, writes `inFlight`, or swaps `backend` /
     * `currentSessionId`, i.e. every one of them touches state the running turn
     * is about to write. The rule therefore over-claims by exactly one class —
     * `/notacommand`, which when idle is sent to the model as prose — and that
     * cost is one refusal notice on a draft nothing advertises.
     *
     * THE TWO EXCEPTIONS ARE HANDLED BY THE CALLER, not here: bare `/exit` and
     * `/quit` still quit mid-turn ({@see submit()}), because they end the process
     * and so have no state left to corrupt — and because Ctrl+C, which is checked
     * above the whole key-policy block, already quits mid-turn, so refusing their
     * typed spellings would be an inconsistency rather than a safeguard.
     *
     * THE DRAFT IS KEPT. Nothing is lost: the line is still in the box, so Enter
     * once the turn settles runs it, and the notice says so.
     *
     * @return array{0:self,1:?\Closure}
     *
     * MOVED HERE FROM ABOVE {@see refuseEmptyCustomCommand()}, where it had
     * been stranded as a second stacked doc-comment. PHP attaches only the
     * LAST of a run of them, so this block documented nothing at all and this
     * method read as undocumented; a reader who found the prose would have
     * attributed it to the empty-template refusal, which is a different rule
     * for a different reason. Three such pairs were in this file.
     */
    private function refuseInFlightCommand(string $text): array
    {
        return [$this->mutate([
            'history' => [...$this->history, Message::system(sprintf(
                '%s is a command, and commands do not run while a turn is in flight — it would rewrite '
                . 'history this turn is about to append to. Your draft is still in the box: press Enter '
                . 'again once the turn finishes, or Esc Esc to cancel the turn now.',
                self::quoteDraftForNotice($text),
            ))],
        ]), null];
    }

    /**
     * Refuse, VISIBLY, an OVERLAY action chosen while a turn is running — a
     * Ctrl+P palette row or the session picker's `resume`.
     *
     * Same rule and same reason as {@see refuseInFlightCommand()}: the overlays
     * now OPEN and BROWSE mid-turn (that is half the bug report), but their
     * dispatch arms delegate to the very command handlers that write `inFlight`
     * and rewrite `history`. Opening a palette to look at it is free; pressing
     * Enter on `New session` while a reply is streaming is not.
     *
     * $what is the row label, or the picker's action, so the notice names what
     * was refused rather than announcing that something was.
     *
     * THE OVERLAY RULE THIS METHOD ESTABLISHES, stated here because it is the
     * one place all four mid-turn refusal routes now agree on and because a
     * previous revision left one of them out of it: A MID-TURN REFUSAL CLOSES
     * THE OVERLAY IT IS WRITTEN UNDER, AND A GESTURE THAT WRITES NOTHING
     * CLOSES NOTHING. The four routes are this method, its two dispatch-site
     * callers ({@see selectSessionTab()}, {@see selectPane()}'s Agents arm),
     * and Ctrl+A, which reaches {@see refuseInFlightCommand()} instead and so
     * clears the two overlay fields at its own arm in
     * {@see refuseWhileInFlight()} — measured before that line, mid-turn under
     * an open palette OR picker, `Ctrl+A` left the overlay up over its notice
     * while the `pane:agents` click that asks for the same thing closed it.
     * The converse half of the rule is why a click on the ALREADY-CURRENT
     * session tab leaves the palette up: it writes no notice, so there is
     * nothing under the overlay to see ({@see selectSessionTab()}).
     *
     * @return array{0:self,1:?\Closure}
     */
    private function refuseInFlightAction(string $what): array
    {
        return [$this->mutate([
            // The overlay is closed as part of refusing: leaving it up over a
            // notice the user cannot see is how the original bug felt.
            'palette' => null,
            'sessionPicker' => null,
            'history' => [...$this->history, Message::system(sprintf(
                '"%s" does not run while a turn is in flight — it would change state this turn is about '
                . 'to write. Wait for the turn to finish, or Esc Esc to cancel it now.',
                self::quoteDraftForNotice($what),
            ))],
        ]), null];
    }

    /**
     * The three keys that reach a turn-starting or history-replacing arm without
     * passing {@see submit()}, {@see handlePaletteKey()} or
     * {@see handleSessionPickerKey()} — so they are policed here, at the head of
     * the mid-turn block in {@see update()}. Null means "no policy applies, run
     * the arm exactly as an idle turn would".
     *
     * ENUMERATED, not pattern-matched, and each one has its own reason:
     *
     *   * Ctrl+A. Its arm is `withInputBuf('/agents')->submit()`, so it both
     *     DESTROYS the draft and submits a command. Routed to the same refusal a
     *     typed `/agents` gets, from `$this` — the draft never moves. It also
     *     closes any open overlay, for the reason spelled out at its arm below
     *     and stated as one rule at {@see refuseInFlightAction()}.
     *   * Ctrl+Tab / Ctrl+Shift+Tab. {@see cycleSessionTab()} adopts another
     *     session's history and id wholesale, which is the running turn's
     *     transcript replaced under it.
     *   * `?` on a blank draft. This one is SILENT, and deliberately so: it is
     *     the only member of the three that is not a refusal but a PRESERVED
     *     invariant. The keybinding reference is documented as opening only from
     *     an idle turn, and {@see update()}'s modal-precedence comment reasons
     *     from that — the reference is checked ABOVE the permission prompt, and
     *     the pair "reference up over a live prompt" is asserted unreachable by
     *     real input (`KeyHelpTest::testThePromptAndTheReferenceCannotBothBeRaised
     *     ByRealInput()`). Opening it mid-turn makes that pair reachable, since a
     *     prompt only ever exists mid-turn. So `?` keeps typing nothing, exactly
     *     as it did before this split, and widening it is a keymap decision with
     *     its own modal-precedence work to do. A notice would be wrong here too:
     *     the user pressed a key that has never done anything in this state.
     *
     * `/keys`, the reference's other route, needs no arm of its own: it starts
     * with `/`, so {@see refuseInFlightCommand()} already claims it.
     *
     * @return array{0:self,1:?\Closure}|null
     */
    private function refuseWhileInFlight(KeyMsg $msg): ?array
    {
        if ($msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'a') {
            // The overlay is cleared HERE and not inside
            // {@see refuseInFlightCommand()}, on arithmetic rather than taste:
            // that method's other caller is {@see submit()}, which
            // {@see update()} reaches only BELOW its palette and picker arms,
            // so neither field can be set there and the line would be dead at
            // that call site. It is not dead at this one — this arm sits
            // ABOVE those same two arms, which is exactly why Ctrl+A can be
            // pressed with an overlay up.
            //
            // Without it the two devices diverged in the direction nobody
            // checked: the `pane:agents` CLICK is let past the overlay by
            // {@see midTurnRefusalOfItsOwn()} and refused at its own site
            // through {@see refuseInFlightAction()}, which closes both overlay
            // fields, while Ctrl+A refused through the command notice and
            // closed nothing. Measured, both overlays: overlay-after-KEY true,
            // overlay-after-CLICK false. The notice TEXT still differs, and
            // that difference is deliberate and argued at {@see selectPane()};
            // the overlay was not a difference anybody chose.
            return $this->mutate(['palette' => null, 'sessionPicker' => null])
                ->refuseInFlightCommand('/agents');
        }

        if ($msg->type === KeyType::Tab && $msg->ctrl) {
            return $this->refuseInFlightAction('Switch session');
        }

        if ($msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt
            && $msg->rune === '?' && trim($this->inputBuf) === ''
        ) {
            return [$this, null];
        }

        return null;
    }

    /**
     * Dispatch whatever {@see enqueuePrompt()} is holding, now that the turn it
     * was queued behind has ended.
     *
     * INERT unless there is a queue: with `$queuedPrompts` empty — which is every
     * pre-existing state — this returns its argument's two values unchanged.
     *
     * THROUGH {@see submit()}, NOT {@see dispatchTurn()}. dispatchTurn()'s
     * docblock warns that a third caller is where the generation stamp, the
     * {@see Backend\CancellationToken}, the checkpoint or the title Cmd goes
     * missing — and submit() adds four more things a typed prompt gets and a
     * queued one must not silently lose: the spend cap, the idle-compaction
     * nudge, and the 85%/95% context tiers. A queued prompt is a typed prompt
     * that waited, so it goes through the same door: the text is seeded into
     * `inputBuf` and submit() runs unchanged.
     *
     * WHY A LOOP. Draining exactly one entry per settle would strand the rest
     * whenever the first one does not start a turn — the spend cap refuses and
     * clears `inFlight`, and then nothing would ever settle again to release
     * entry two. The loop re-reads `$chat` each pass, so it stops the moment a
     * turn IS in flight (the ordinary case: entry one dispatches, the rest wait
     * for its settle) and is bounded by the queue's length either way, since
     * every pass either shortens the queue or breaks.
     *
     * THE USER'S DRAFT IS RESTORED at the end, widget and cursor column both.
     * Seeding submit() through `inputBuf` is what lets the drain reuse the real
     * turn-start path, but the box may well hold a NEW draft by the time a turn
     * settles, and {@see dispatchTurn()} blanks `inputBuf` on its way out. Losing
     * a half-typed line to a queue release would be the same class of bug this
     * whole change exists to fix.
     *
     * THE ONE REFUSAL THAT KEEPS THE DRAFT is why the loop checks `inputBuf`
     * after each pass: {@see spendCapTurnRefusal()} deliberately does not consume
     * the line and writes no `Message::user()` echo, so a capped session would
     * leave the drained prompt nowhere but the box — and the box is about to be
     * restored to the user's own draft. Such an entry goes back to the HEAD of
     * the queue and the loop stops, because whatever refused this one refuses the
     * next one too. It stays visible in the status bar rather than vanishing.
     *
     * @param array{0:self,1:?\Closure} $settled the turn-ending result to augment
     * @return array{0:self,1:?\Closure}
     */
    private static function releaseQueuedPrompts(array $settled): array
    {
        [$chat, $cmd] = $settled;
        if ($chat->queuedPrompts === []) {
            return $settled;
        }

        $cmds = $cmd === null ? [] : [$cmd];
        $draft = $chat->input;

        while (!$chat->inFlight && $chat->queuedPrompts !== []) {
            $queue = $chat->queuedPrompts;
            $text = (string) array_shift($queue);

            [$after, $next] = $chat->mutate([
                'queuedPrompts' => $queue,
                'inputBuf' => $text,
            ])->submit();

            if ($next !== null) {
                $cmds[] = $next;
            }

            if (trim($after->inputBuf) === $text) {
                $chat = $after->mutate(['queuedPrompts' => [$text, ...$queue]]);
                break;
            }

            $chat = $after;
        }

        return [
            $chat->mutate(['input' => $draft]),
            match (count($cmds)) {
                0 => null,
                1 => $cmds[0],
                default => Cmd::batch(...$cmds),
            },
        ];
    }

    /**
     * Start a turn: commit $baseHistory plus $newTurnMessages, arm the
     * cancellation and generation a reply is matched against, checkpoint, and
     * schedule the completion (batched with the session titler when there is
     * one).
     *
     * ONE COPY BECAUSE THIS IS THE ONLY THING THAT STARTS A TURN, and two
     * callers need it: {@see submit()} on the ordinary route, and
     * {@see applyModelCompaction()} when a turn the 85% tier parked behind a
     * summarization is finally sent ({@see scheduleParkedCompaction()}). A second
     * copy is where `$generation`, the {@see CancellationToken}, the checkpoint or
     * the title Cmd goes missing, and none of those omissions are visible to a
     * test that only asserts a Cmd came back.
     *
     * $baseHistory is the history the turn is sent AGAINST — already compacted if
     * a tier compacted it — and $newTurnMessages is what this turn adds to it
     * (the 85% notice, the user's line). The reminder tier is evaluated HERE,
     * against $baseHistory, which on the parked route means it is judged against
     * the post-model-compaction history rather than the pre-compaction one it
     * would have seen in {@see submit()}. It cannot nag about a state a tier just
     * fixed either way.
     *
     * The reminder's figure is derived from $baseHistory rather than passed in,
     * so the number in the message and the history the predicate ran against
     * cannot disagree. That is not a behaviour change: measured over all three
     * of {@see submit()}'s paths — no tier, tier adopted, tier not adopted — the
     * count it used to pass in was already `estimateTokenCount($baseHistory)` in
     * every one.
     *
     * Every dispatch drops any reminder $baseHistory already carries, whether
     * or not the tier fires this turn, and only the firing appends a fresh one.
     * So the committed history holds EXACTLY ONE while the estimate is over the
     * tier — always the one carrying the current figure — and NONE once it falls
     * back under, which is what makes `/compact` clear the warning it was run in
     * answer to. That is a rewrite of $baseHistory, not an append — see
     * {@see withoutContextReminders()} for the pile-up it prevents, and for why
     * a fire-once latch and a render-from-state reminder were both rejected.
     * The checkpoint written further down serialises `$next->history`, so it
     * inherits the dedup with no second site to keep in step.
     *
     * `$pendingCompactionId` is deliberately untouched. A `/compact`
     * summarization outstanding across a turn is a supported state (see
     * {@see HistoryCompactedMsg}), and on the parked route the landing
     * compaction has already released the latch before this is reached.
     *
     * @param list<Message> $baseHistory
     * @param list<Message> $newTurnMessages
     * @param int $tokenLimit PROVIDER-COUNTED window from {@see contextTokenLimit()}.
     * @return array{0:Chat,1:?\Closure}
     */
    private function dispatchTurn(array $baseHistory, array $newTurnMessages, int $tokenLimit): array
    {
        // Reminder-tier check (R21's ContextCompactor::shouldSendReminder(),
        // 70% of the token budget by default). Unlike the idle-compaction
        // prompt in submit() — which short-circuits the turn entirely and never
        // calls the backend — this is a soft, non-blocking notice: the real
        // prompt still goes out, but a system-role warning is appended
        // alongside it so the user sees context is filling up well before
        // the hard 85%/95% compaction tiers would kick in.
        $baseWire = array_map(
            static fn(Message $msg): array => $msg->toWire(),
            $baseHistory
        );
        $dueForReminder = $this->compactor->shouldSendReminder($baseWire, $tokenLimit);

        // Order is load-bearing, twice over.
        //
        // (1) STRIP UNCONDITIONALLY, APPEND CONDITIONALLY. Scoping the strip
        // inside the `if` — which is what this arm did when the dedup first
        // landed — leaves the last copy a session was ever sent in history
        // forever once the estimate falls back UNDER the tier, which is
        // precisely what /compact is for. It stays on the provider wire every
        // turn thereafter, quoting a figure from before the compaction: at 22%
        // of the window the transcript still read "grown to ~70440 estimated
        // tokens, past the ... threshold" immediately after a /compact. Pinned
        // by ContextReminderDedupTest::
        // testABelowThresholdDispatchRemovesAStalePreExistingReminder().
        //
        // (2) THE FIGURE IS COUNTED BEFORE THE STRIP — $preStrip, not the
        // rewritten $baseHistory — so the number the message quotes is the same
        // number the predicate above just compared against the threshold, and
        // the sentence "grown to ~N estimated tokens, past the ... threshold"
        // cannot contradict itself. Counting after the strip would quote a
        // figure 53 estimated tokens per dropped copy BELOW the threshold it
        // claims to be past; on a history sized exactly to the threshold with
        // one stale copy present that is 69,947 against a threshold of 70,000.
        // Pinned by ContextReminderDedupTest::
        // testTheQuotedFigureIsNeverBelowTheThresholdItSaysItIsPast().
        //
        // WHAT N DOES NOT DO IS OVERSTATE THE HISTORY COMMITTED BELOW, which an
        // earlier draft of this comment claimed. It overstates post-strip
        // $baseHistory IN ISOLATION, by 53 estimated tokens per dropped copy —
        // but that array is never committed on its own, and the copy appended
        // here weighs the same 53 as each one just dropped, so the two cancel.
        // Measured over four turns of a one-line prompt, est(committed) less N:
        // +65 on the FIRST fire (nothing to drop, so the prompt's ~12 plus the
        // reminder's 53) and +12 on every turn after it (drop and append
        // cancel, leaving just the prompt). Never negative.
        //
        // 53 is estimateTokenCount()'s own chars/4 + 10 over this message's
        // 169-172 content chars, and its domain is the QUOTED FIGURE, not the
        // window: it holds for every figure from 100 to 999,999, is 52 only
        // below 100, and reaches 54 only once the figure passes 1,000,000.
        // Since the figure is at least the 70% threshold, that covers every
        // window from ~143 tokens up to ~1.43 million — i.e. every real
        // provider window, 54 arriving only on a 2M-context model (or on a
        // history run absurdly far past a small window). Both units here are
        // the estimate, never a provider count.
        $preStrip = $baseHistory;
        $baseHistory = self::withoutContextReminders($baseHistory);
        if ($dueForReminder) {
            $newTurnMessages[] = $this->contextReminderMessage($this->estimateTokenCount($preStrip));
        }

        $generation = $this->generation + 1;
        $cancellation = new CancellationToken();
        $next = $this->mutate([
            'history' => [...$baseHistory, ...$newTurnMessages],
            'inputBuf' => '',
            'inFlight' => true,
            'inFlightCancellation' => $cancellation,
            'generation' => $generation,
            'lastActivityAt' => new \DateTimeImmutable(),
            // Belt-and-braces: every settled/cancelled path already clears
            // these, but a new turn must start from a blank partial and a blank
            // thought no matter how the previous one ended.
            'streamingText' => '',
            'reasoningText' => '',
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
     * The prompt a typed `/name …` should send when `name` is one of this
     * session's file-based commands, or null when it is not one — in which case
     * {@see submit()} falls through to {@see dispatchCommand()} and then to the
     * model, exactly as before.
     *
     * THE NAME IS PARSED HERE RATHER THAN TAKEN FROM
     * {@see CommandParser::parse()}, and that is not duplication for its own
     * sake: `normalizeName()` strips every character outside `[A-Za-z0-9_-]` and
     * lower-cases the rest, so it reports `/deploy/staging` as `deploystaging`
     * and `/Foo` as `foo`. Both are legal command file names
     * ({@see CommandSpec::NAME_PATTERN} allows `/` as the subdirectory namespace
     * separator, which is the whole point of `deploy/staging.md`), so a lookup
     * keyed on the parser's name would silently fail to find the commands the
     * loader most deliberately supports. The name here is "everything up to the
     * first whitespace", which is also what the "/" popup completes
     * ({@see slashMenuPrefix()}), so the string the user picked is the string
     * looked up.
     *
     * THE POSITIONAL TOKENS ARE STILL THE PARSER'S, fed the argument string this
     * method isolated rather than the raw draft. That is what makes `$1` and
     * `$ARGUMENTS` two views of ONE string instead of two independent parses that
     * could disagree about where the arguments began — the parser's own idea of
     * where the name ends is the part that is wrong here, its shell-quote
     * splitting is the part that is right, and this takes only the second.
     */
    private function expandCustomCommand(string $text): ?string
    {
        if ($this->customCommands === []) {
            return null;
        }

        // `/s` so a bare "/name" with a trailing newline still parses, and `\S+`
        // so the name stops at the first whitespace of any kind.
        if (preg_match('/^\/(\S+)(?:\s+(.*))?$/s', $text, $matches) !== 1) {
            return null;
        }

        $name = $matches[1];
        $arguments = trim($matches[2] ?? '');

        $spec = $this->customCommands[$name] ?? null;

        // THE COLON INVOCATION FORM, `/name:arg`, which
        // {@see CommandParser::parse()} accepts and this method did not:
        // `parse()` terminates the name at the first `:` and treats the rest as
        // arguments, so `/compact:x` reached the BUILT-IN `/compact` while a
        // project `compact.md` sat unread — i.e. every built-in was still
        // reachable, un-overridden, through its colon spelling, which falsifies
        // the precedence claim in {@see submit()}. Tried only after the whole
        // `\S+` name misses, because `:` is not legal in a command file's name
        // ({@see CommandSpec::NAME_PATTERN}) and so an exact hit can never be
        // the colon form.
        //
        // The colon's tail is PREPENDED to the arguments rather than replacing
        // them, matching `parse()`: it reads `/compact:x y` as name `compact`
        // with `x y`, and the two paths must not disagree about what the
        // arguments were.
        if ($spec === null) {
            $colon = strpos($name, ':');
            if ($colon !== false) {
                $candidate = $this->customCommands[substr($name, 0, $colon)] ?? null;
                if ($candidate !== null) {
                    $spec = $candidate;
                    $arguments = trim(substr($name, $colon + 1) . ' ' . $arguments);
                }
            }
        }

        if ($spec === null) {
            return null;
        }

        return $spec->expandTemplate(
            $arguments,
            (new CommandParser())->parse('/c ' . $arguments)?->args ?? [],
            $this->commandDirective($spec),
        );
    }

    /**
     * The resolver {@see CommandSpec::expandTemplate()} calls for the two
     * template forms that leave the string: `` !`cmd` `` and `@path`.
     *
     * THIS METHOD IS THE POLICY AND {@see CommandSpec} IS THE MECHANISM, and the
     * split is where it is because of what each side can see. The spec is a
     * value object read off disk; the launch's one
     * {@see \SugarCraft\Crush\Permissions\PermissionGate} and the answer to
     * "did the operator trust this checkout" live here. A spec that could gate
     * itself would be a repository-supplied file deciding its own permissions.
     *
     * THE ROOT IS RESOLVED ONCE, outside the closure, so every substitution in
     * one expansion is judged against the same directory even though
     * {@see projectRoot()} falls back to `getcwd()` — a command whose own
     * `` !`cd /tmp && …` `` moved the process must not move the boundary its
     * later `@path` forms are checked against.
     */
    private function commandDirective(CommandSpec $spec): \Closure
    {
        $root = $this->projectRoot();

        return function (string $kind, string $payload, float $secondsRemaining) use ($spec, $root): string {
            if ($kind === 'include') {
                return $spec->includeFile($payload, $root);
            }

            $refusal = $this->refuseCommandShell($spec, $payload);
            if ($refusal !== null) {
                return $refusal;
            }

            return $spec->runShellSubstitution($payload, $root, $secondsRemaining);
        };
    }

    /**
     * Why this `` !`cmd` `` may not run, or null if it may.
     *
     * TWO CHECKS, IN THIS ORDER, and the order is the substantive decision:
     *
     * 1. THE TIER. `CommandSpec::$tier === 'project'` means the file came out of
     *    `<root>/.sugar-crush/commands`, i.e. out of a `git clone`, and running
     *    a shell out of it needs {@see $projectCommandsTrusted} — the operator
     *    having named this root under `trustedProjectCommands`. `'user'` is the
     *    operator's own `~/.sugar-crush/commands` and needs no per-project
     *    grant. `null` is neither: nothing on disk produced it, so an in-process
     *    caller built it with {@see CommandSpec::new()} and had to write the
     *    command out in PHP to do so, which is not a boundary this check can add
     *    anything to.
     *
     * 2. THE GATE, and only then, because {@see PermissionGate::evaluate()}
     *    MUTATES Auto mode's circuit-breaker counters. A command refused by the
     *    tier rule is one that was never going to run, and its own doc-block
     *    forbids moving a counter for a call that did not really happen.
     *
     * ONLY `Deny` REFUSES; an `Ask` proceeds. That is this codebase's own rule,
     * not a shortcut: {@see PermissionGate::refuses()} states that a caller which
     * cannot show the blocking permission prompt must not turn "would have
     * asked" into "no", and template expansion happens inside `submit()` with no
     * prompt available. The cost is stated rather than hidden — in the shipped
     * default mode, which answers `Ask` for `Bash`, a `` !`…` `` in an
     * authorised command file runs WITHOUT a prompt. What makes that acceptable
     * is that authorisation is check 1: it is either the operator's own file or a
     * checkout they explicitly trusted. What the gate still buys is the
     * argument-sensitive half a declaration cannot reach — an explicit
     * `Deny Bash(rm *)`, and the `rm -rf /` breaker, both of which read
     * `arguments['command']` and so need the real command string this passes.
     */
    private function refuseCommandShell(CommandSpec $spec, string $command): ?string
    {
        if ($spec->tier === 'project' && !$this->projectCommandsTrusted) {
            return sprintf(
                '[!`%s` was not run: /%s came from this project\'s .sugar-crush/commands, which arrives '
                . 'with the repository, and a command file from a checkout may only run a shell if you have '
                . 'listed this project under "trustedProjectCommands" in ~/.sugar-crush/config.json — the '
                . 'rest of the command file was sent.]',
                CommandSpec::abbreviateForm($command),
                $spec->name,
            );
        }

        // NO GATE IS NOT A REFUSAL. `permissionGate()` answers null for every
        // embedder and most tests — a Chat built without a hook chain and
        // without an EngineBackend — and refusing there would mean a session
        // with NO permission configuration was STRICTER than one running the
        // shipped default mode, which answers `Ask` and proceeds. Check 1 is
        // what carries the authorisation in that case, and it has already run:
        // the file is either the operator's own or a checkout they named.
        $gate = $this->permissionGate();
        if ($gate === null) {
            return null;
        }

        // `\SugarCraft\Crush\ToolCall`, the TUI-side half of the two ToolCall
        // pairs crush_feat.md §1 D flags — NOT `Tools\ToolCall`, which is the
        // engine-side pair and which PermissionGate does not accept. Named
        // fully rather than imported so the choice is visible at the call site.
        if ($gate->evaluate(new \SugarCraft\Crush\ToolCall('Bash', ['command' => $command]))
            === \SugarCraft\Crush\Permissions\PermissionDecision::Deny) {
            return sprintf(
                '[!`%s` was not run: this session\'s permission mode (%s) denies it]',
                CommandSpec::abbreviateForm($command),
                $gate->mode()->value,
            );
        }

        return null;
    }

    /**
     * Route a submitted draft to a slash-command handler, or return null when
     * it is an ordinary prompt for the model.
     *
     * The name is parsed by {@see CommandParser::parse()} (crush_code.md Phase
     * 4 item 7), which was already built, already tested, and already used by
     * {@see \SugarCraft\Crush\Commands\AgentsCommand} - while this method's
     * predecessor re-derived the same thing inline as sixteen
     * `str_starts_with($text, '/name')` calls. Dispatching on the parsed NAME
     * instead of on a prefix is what makes the set of live commands a thing a
     * test can enumerate: `tests/Commands/SlashDispatchTest.php`'s
     * `testEverySlashVisibleRegistryRowHasALiveDispatchHandler()` submits
     * `/name` for every `slashVisible` row in {@see CommandRegistry} and fails
     * when the turn reaches the backend, so a registry row with no arm here
     * reds the suite. The before/after dispatch table for the refactor lives in
     * that file's other methods rather than in prose here.
     *
     * Two guards keep the parse from widening what dispatches, because
     * `parse()` is deliberately more forgiving than the chain it replaced:
     *
     * - it LOWERCASES and strips punctuation out of the name it reports, so
     *   `/KEYS` and `/keys` and `/k:eys` all parse to `keys`. The old chain
     *   compared raw bytes and matched none but the last, and `KeyHelpTest`'s
     *   draft corpus asserts `/KEYS` is sent to the model as prose. Requiring
     *   the canonical spelling to appear verbatim at the head of the draft
     *   keeps that exact, for every command at once.
     * - `$text === '/' . $name` is what keeps the four argument-less commands
     *   argument-less. `/exit now` and `/keys foo` were prompts before this
     *   refactor because their arms compared the WHOLE trimmed buffer; a bare
     *   name match would have quietly turned both into commands.
     *
     * What did change, deliberately, is that a name is no longer a PREFIX:
     * `/compactfoo` and `/rewind3` used to be swallowed by the `/compact` and
     * `/rewind` handlers, and now go to the model like any other typo. Nothing
     * advertised them and no test named them - the before/after table for
     * every registry spelling is in the Phase 4 item 7 report.
     *
     * @return array{0: self, 1: ?\Closure}|null
     *
     * MOVED HERE FROM ABOVE {@see expandCustomCommand()}, where it had been
     * stranded as a second stacked doc-comment and so documented nothing. The
     * mis-attribution was the expensive half: `expandCustomCommand()` returns
     * `?string`, and a reader taking the block above it at face value would
     * have read this `@return array{0: self, 1: ?\Closure}|null` as ITS
     * contract.
     */
    private function dispatchCommand(string $text): ?array
    {
        // The bare "mcp auth …" form, which predates the discoverable `/mcp`
        // spelling and has no leading slash - so CommandParser sees ordinary
        // prose and returns null for it. Kept as its own branch, ahead of the
        // parse, so existing muscle memory and the palette's ToggleMcp action
        // keep working.
        if (str_starts_with($text, 'mcp auth')) {
            return $this->handleMcpAuthCommand($text);
        }

        $parsed = (new CommandParser())->parse($text);
        if ($parsed === null || !str_starts_with($text, '/' . $parsed->name)) {
            return null;
        }

        // Commands that take no arguments at all. `/help me name this variable`
        // is a prompt, not a request for the command list.
        if ($text === '/' . $parsed->name) {
            $bare = match ($parsed->name) {
                // Same as Ctrl+C / the palette's Exit action, just reachable
                // without a modifier key.
                'exit', 'quit' => [$this, Cmd::quit()],
                'keys' => $this->handleKeysCommand(),
                'help' => $this->handleHelpCommand(),
                'clear' => $this->handleClearCommand(),
                default => null,
            };
            if ($bare !== null) {
                return $bare;
            }
        }

        // Commands that take optional arguments. Each handler is passed the
        // WHOLE draft rather than $parsed->args: they do their own argument
        // parsing already, and re-splitting it here would be a second parse to
        // keep in step with theirs.
        return match ($parsed->name) {
            // Down here rather than in the bare-only block above, and this is a
            // DELIBERATE break with `/keys`, which it otherwise resembles.
            //
            // `/help me name this variable` is prose, so `help` is bare-only;
            // `/keys extra` is the same judgement one notch weaker. Measured,
            // `permissions` shipped in that block and so `/permissions rules`
            // went to the MODEL — which is the exact defect this command was
            // added to fix, surviving in every spelling but one. The cost is
            // not symmetric here: `/keys` mis-routed is a reference screen a
            // model answers badly, `/permissions` mis-routed is a question
            // about the local gate answered by the one participant that cannot
            // see it, and answered plausibly.
            //
            // The argument is then IGNORED, not parsed, because the report is
            // total: mode, source, every rule in evaluation order, and the
            // breaker. There is no sub-view for `/permissions rules` to select
            // — it is already on the screen — so every spelling gets a superset
            // of what it asked for rather than a "no such subcommand".
            'permissions' => $this->handlePermissionsCommand($text),
            'compact' => $this->handleCompactCommand($text),
            'budget' => $this->handleBudgetCommand($text),
            'workflow' => $this->handleWorkflowCommand($text),
            'share' => $this->handleShareCommand($text),
            // Both spellings: the registry row is `agents`, and `/agent` was
            // reachable under the old prefix chain, so it stays reachable.
            'agent', 'agents' => $this->handleAgentsCommand($text),
            'memory' => $this->handleMemoryCommand($text),
            'bg', 'background' => $this->handleBackgroundCommand($text),
            'fork' => $this->handleForkCommand($text),
            'branch' => $this->handleBranchCommand($text),
            'rename' => $this->handleRenameCommand($text),
            'rewind' => $this->handleRewindCommand($text),
            'sessions' => $this->handleSessionsCommand($text),
            'theme' => $this->handleThemeCommand($text),
            'mcp' => $this->handleMcpAuthCommand($text),
            'websearch' => $this->handleWebSearchCommand($text),
            // The only arm that wants the parsed arguments rather than the raw
            // text: a provider name is one token, and CommandParser has
            // already unquoted it.
            'model' => $this->handleModelCommand($parsed->args),
            default => null,
        };
    }

    /**
     * `/keys` - the same in-app keybinding reference "?" opens, under a NAME
     * rather than a shortcut. That is the whole justification, and it is about
     * DISCOVERY, not about reach: the row is in CommandRegistry, so typing "/k"
     * lists it in the "/" popup. Nothing on screen names "?" before the
     * reference is open -- the idle status bar is "~0K / 100K context (0%) ·
     * Enter to send · Ctrl+P menu · /exit or ^C to quit", measured -- and the
     * two places it IS named are this row's own popup description, "(or press
     * ?)", which is precisely the work the row does, and the trailer
     * {@see handleHelpCommand()} prints under its listing ("Press ? or type
     * /keys for the keyboard shortcut reference."). The second one is NEW and
     * this diff is what created it: the sentence that stood here said "the ONE
     * place", and the `/help` split falsified it in the same commit that
     * claimed to have corrected this docblock for the split.
     *
     * `/help` WAS a second spelling of this command and is no longer one
     * (crush_code.md Phase 4 item 2): it lists the commands now, so everything
     * below is about `/keys` alone. `KeyHelpTest`'s draft corpus was re-driven
     * against that split rather than trimmed to fit it -- the `/help`-shaped
     * drafts stayed in it and now assert the reference does NOT open.
     *
     * It is NOT an escape hatch for a half-typed draft, and three earlier
     * versions of this comment were wrong about that -- twice by promising a
     * hatch that does not exist, once by denying an asymmetry that did. $text is
     * the WHOLE trimmed buffer and the match against it is exact. Driven as real
     * keystrokes (Chat::update() with KeyMsg, two-message history over
     * EchoBackend, 100x30): "why" then "/keys" leaves inputBuf 'why/keys' with
     * the "/" popup empty, and Enter SENDS "why/keys" to the model as a prompt;
     * "why" then "/" is 'why/' with no popup either; and clearing the draft
     * first -- "why" then three Backspaces -- makes BOTH work again. Pinned by
     * KeyHelpTest::testSlashKeysInAHalfTypedDraftIsSentAsAPromptNotAsACommand().
     *
     * Say WHICH two routes, because the sentence that used to stand here --
     * "the two routes agree about WHETHER the reference opens" -- is true of one
     * pairing and false of another, and a reader takes the false one.
     *
     * TRUE, and the escape-hatch property this is all for: with a draft D in the
     * box, TYPING "/keys" onto it and pressing Enter opens the reference exactly
     * when "?" on D does. Both reduce to trim(D) === '' -- this route matches
     * trim(D . "/keys") against "/keys", the "?" arm in update() tests trim(D)
     * -- so it is a property of the two guards rather than of any corpus, and
     * the corpus demonstrates it rather than establishing it. Saying so is the
     * point: a previous revision counted the corpus as evidence for a claim its
     * own predicates already forced.
     *
     * FALSE: that "?" and this route agree in general. SUBMITTING a draft that
     * IS the command modulo whitespace opens the reference where "?" types a
     * character -- measured, " /keys", "/keys " and "\t/keys" all open by Enter
     * and none of them by "?" -- and on every blank draft "?" opens while Enter
     * sends nothing at all. The two are COMPLEMENTARY, never both open, and the
     * exact disagreement set is asserted rather than described.
     *
     * That is not an escape hatch: reaching it means the draft was the command
     * and nothing else, which is the command working as named. The hatch the
     * docs deny is the FIRST pairing, and it stays denied.
     *
     * And it was denied wrongly until round 4: the "?" arm tested the raw buffer
     * while this one trims, so a whitespace-only draft opened via typing "/keys"
     * onto it and typed " ?" via "?" -- see the "?" arm in update() for the
     * widened guard and its cost. What earns the claim today is a corpus chosen
     * against the PREDICATE (drafts either side of the blank/non-blank boundary,
     * including " ", "  ", "\t", " \t ", " x " and the command-modulo-whitespace
     * ones) rather than against frame distinctness, which is what the six-state
     * corpus was chosen for and is why it could not see the hole. Both live in
     * KeyHelpTest: ::testTheTwoRoutesAgreeOnEveryBlankAndNonBlankDraft() for the
     * draft boundary and all three routes, and
     * ::testTheCommandAndTheShortcutOpenTheReferenceInExactlyTheSameStates() for
     * the six non-draft states (empty+idle, a half-typed draft, a turn in
     * flight, the palette open, a permission prompt pending, a long transcript
     * scrolled back), each asserted to paint a distinct frame. Note the
     * in-flight state, where NEITHER route opens it.
     *
     * One residual asymmetry, deliberately kept: this route CLEARS the input
     * buffer and the "?" arm does not, so on a " " draft "/keys"+Enter discards
     * the space and "?" leaves it. That is the ordinary behaviour of a submitted
     * command, and the reference is modal either way.
     *
     * The way to type a message that STARTS with "?" is the second "?", which
     * closes the reference and lands the literal character (see
     * {@see handleKeyHelpKey()}).
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleKeysCommand(): array
    {
        return [$this->withInputBuf('')->withKeyHelp(0), null];
    }

    /**
     * `/permissions` — what this session is actually gated by.
     *
     * The name sat in {@see CommandRegistry::CONTROL_PLANE} for two rounds with
     * no row and no arm: reserved against a project's `permissions.md`, on
     * behalf of a command that did not exist. Typing it sent the word
     * "/permissions" to the MODEL, which is the one place a question about
     * local policy has no business going.
     *
     * A TRANSCRIPT MESSAGE, not a modal overlay like `/keys`. The answer is
     * text worth scrolling back to, worth having above the turn it explains,
     * and worth still being there when the next refusal lands — which is
     * exactly when it gets typed.
     *
     * READ-ONLY, in the strong sense: see {@see permissionsReport()} for the
     * accessors it is built on and why reaching for
     * {@see PermissionGate::evaluate()} here would have been a bug rather
     * than a shortcut.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handlePermissionsCommand(string $inputText): array
    {
        return [
            $this->mutate([
                'history' => [
                    ...$this->history,
                    Message::user($inputText),
                    Message::assistant($this->permissionsReport()),
                ],
                'inputBuf' => '',
                'inFlight' => false,
            ]),
            null,
        ];
    }

    /**
     * The `/permissions` body, DERIVED from the launch's live
     * {@see PermissionGate} rather than restating what the config said.
     *
     * WHY DERIVED MATTERS HERE MORE THAN USUAL. A permission screen that
     * disagrees with the enforcing gate is worse than no screen: it tells
     * somebody they are in `plan` while `bypass-permissions` runs. So every
     * line comes off the gate itself — {@see PermissionGate::mode()},
     * {@see PermissionGate::modeSource()}, {@see PermissionGate::rules()},
     * {@see PermissionGate::autoBreaker()} — the mode's own sentence comes off
     * {@see PermissionMode::description()}, and the breaker's thresholds come
     * back from the gate alongside the counters so this method never writes
     * "of 3" in its own hand.
     *
     * WHAT IT MUST NOT USE, and this is the trap the item was really about:
     * {@see PermissionGate::evaluate()} MUTATES the Auto-mode circuit breaker.
     * Building a preview on it — "what would this gate say about a Write?" —
     * would advance or reset the strike counters every time a user opened a
     * read-only screen, i.e. a safety state changed by being looked at. The
     * gate grew read-only doors for this; nothing here calls the evaluator.
     *
     * Rule patterns, classifier categories AND the mode source are run through
     * {@see reportField()} on the way out — NOT through
     * {@see Sanitize::untrusted()} directly, which preserves the two bytes that
     * matter most to a report built line-per-fact. All three are
     * caller-supplied text landing in the transcript — the source label is
     * built around a config path that `--config` can name — and an ESC byte in
     * any of them would put raw ANSI in front of the frame-diff renderer. That
     * is the `[33m`-as-literal-text defect `Commands\NoRawAnsiInTranscriptTest`
     * guards at the SOURCE for the `ob_start()`-captured commands; this one is
     * not among them (it writes no stdout, so that census cannot see it by
     * construction), and its guard is
     * `Commands\PermissionsCommandTest::testTheReportHasExactlyTheLinesTheRendererIntended()`
     * — a RUNTIME check on the bytes actually produced, which is the stronger
     * half of that pair anyway. The mode source was measured getting through
     * before that test was written.
     *
     * A LINE OF THIS REPORT IS ONE LINE BY CONSTRUCTION, and that is a property
     * of the whole method rather than of any field: `$lines` is assembled here
     * and joined with `"\n"` at the bottom, so the report's line count is
     * exactly `count($rules) + 6` for every possible config — the mode line,
     * the description, a blank, one rules line (the header, or the "none
     * configured" sentence that stands in its place, which is why the formula
     * needs no special case at zero), one line per rule, a blank, and the
     * breaker. Nothing a caller supplies may change it.
     * {@see reportField()} is what enforces
     * that, and the paragraph there records what got through before it existed.
     */
    private function permissionsReport(): string
    {
        $gate = $this->permissionGate();
        if ($gate === null) {
            // NOT "you are unprotected": this Chat has no gate to report, which
            // is the ordinary shape for an embedder and for a Chat built with
            // neither a hook chain nor an engine backend. Whatever hooks are
            // installed still run — see checkProjectCommandShell()'s own
            // "no gate is not a refusal" note.
            return 'No permission gate is attached to this session, so no mode and no rule are '
                . 'deciding anything here. That is what an embedder gets, and a Chat built without a '
                . 'hook chain and without an engine backend; a `sugarcrush` launch always builds one. '
                . 'Any hooks that are installed still run.';
        }

        $mode = $gate->mode();

        $lines = [
            sprintf(
                'Permission mode: %s — from %s',
                // The mode is enum-constrained and safe by construction; the
                // SOURCE is not. Bootstrap builds it around a file path, and
                // that path can come from `--config`, so it is caller text on
                // its way into the transcript exactly as a rule pattern is.
                $mode->value,
                $gate->modeSource() === null
                    ? 'a source this gate did not record'
                    : self::reportField($gate->modeSource()),
            ),
            $mode->description(),
            '',
        ];

        $rules = $gate->rules();
        if ($rules === []) {
            // The path is deliberately not sentence-final. `Cli\ProjectTierRefusalInventoryTest`
            // enumerates every dot-path literal in src/ and a trailing period
            // makes `config.json.` a second, unclassified entry — measured, it
            // reds that inventory.
            // BOTH files are named, and the omission this replaces was one the
            // feature already knew about: `Cli\Bootstrap::PERMISSION_SETTINGS_KEYS`
            // lists `permissionRules`, `permissionConfigLayers()` merges the
            // `settings.json` layer beneath `config.json`, and
            // `Cli\BootstrapToolAndPermissionSettingsTest::testTheGateRemembersWhichFileSetTheMode()`
            // asserts the source label printed one line above this one can read
            // `settings.json`. Sending a user to edit one of two files
            // is a coin flip they lose half the time, and they lose it silently
            // — rules in the file this sentence did not name still load.
            $lines[] = 'Rules: none configured, so every decision above is the mode\'s own. '
                . 'A `permissionRules` array in ~/.sugar-crush/config.json or in '
                . '~/.sugar-crush/settings.json is where they go; config.json wins where both set a key.';
        } else {
            $lines[] = sprintf(
                'Rules (%d), tried in this order — the first one that matches decides, ahead of the mode:',
                count($rules),
            );
            foreach ($rules as $index => $rule) {
                $lines[] = sprintf(
                    '  %d. %-5s %s',
                    $index + 1,
                    $rule->action->value,
                    self::reportField($rule->pattern),
                );
            }
        }

        $lines[] = '';
        $lines[] = self::autoBreakerLine($gate);

        return implode("\n", $lines);
    }

    /**
     * One caller-supplied value, made safe to be PART OF A REPORT LINE.
     *
     * {@see Sanitize::untrusted()} is the wrong tool on its own here, and the
     * reason is a deliberate feature of it: it PRESERVES `\t`, `\n` and `\r`
     * (`Util\Sanitize` strips `[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]`, and those
     * three are excluded). That is right for a sink that renders a paragraph
     * and wrong for one that builds a line-per-fact report and joins with
     * `"\n"`: an LF inside a rule pattern or a `--config` path does not become
     * visible text, it becomes A NEW REPORT LINE, indistinguishable from one
     * this method wrote.
     *
     * MEASURED, on the build that shipped this screen. A single rule whose
     * `pattern` carried two LFs added
     * `Permission mode: bypass-permissions - from --permission-mode` to a
     * report drawn off a gate that was in `default`; a `modeSource` carrying
     * LFs added `Rules (9), tried in this order:` to a gate holding no rules at
     * all; and a CR mid-pattern let the tail of the value overwrite the head of
     * its own line on a real terminal. So the screen whose entire purpose is to
     * stop a lie about permissions could be made to tell one, in the gate's own
     * voice, by the config it reads — and `permissionRules[].pattern` is
     * validated only by `is_string()` in {@see \SugarCraft\Crush\Cli\Bootstrap},
     * while `~/.sugar-crush/config.json` is writable by any model holding
     * `Write` under `auto` or `bypass-permissions`.
     *
     * ESCAPED, not stripped. A pattern that really does contain a newline is a
     * broken rule its author needs to SEE; deleting the byte silently would
     * print a pattern that is not the one the gate is matching with, which is
     * the same class of lie one step quieter. `\n` renders as the two
     * characters a user would have typed. TAB goes with them: it forges no
     * line, but it does move the cursor across the `%-5s` action column and
     * mis-set the alignment that makes the rule list readable as a table.
     *
     * The guard is
     * `Commands\PermissionsCommandTest::testTheReportHasExactlyTheLinesTheRendererIntended()`,
     * which counts LINES against the count the renderer intended rather than
     * scanning for residue bytes — the residue scan that shipped with this
     * screen asserted a byte class that was a strict SUBSET of what
     * `untrusted()` already removes, so it could only ever confirm that
     * `untrusted()` had been called.
     */
    private static function reportField(string $value): string
    {
        return strtr(Sanitize::untrusted($value), [
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]);
    }

    /**
     * Where the Auto-mode circuit breaker stands, in the gate's own numbers.
     *
     * Reported for every mode rather than only for `auto`, and saying plainly
     * that it is idle elsewhere: the counters exist on every gate, and a line
     * that simply vanished under the other five modes reads as "there is no
     * such thing" rather than "it is not counting right now".
     *
     * Both thresholds come back from {@see PermissionGate::autoBreaker()}. They
     * are private constants of the evaluator, and printing this method's own
     * copy of them is precisely the drift that would let the screen advertise
     * "of 3" the day the evaluator started escalating at 4.
     */
    private static function autoBreakerLine(PermissionGate $gate): string
    {
        $breaker = $gate->autoBreaker();

        if ($gate->mode() !== PermissionMode::Auto) {
            return sprintf(
                'Auto-mode circuit breaker: idle. It only counts under `%s`, and this session is `%s`.',
                PermissionMode::Auto->value,
                $gate->mode()->value,
            );
        }

        return sprintf(
            'Auto-mode circuit breaker: %d of %d consecutive blocks (%s), %d of %d blocks this session. '
            . 'Reaching either threshold turns the next block into a prompt instead of a refusal.',
            $breaker['consecutiveBlocks'],
            $breaker['strikeThreshold'],
            $breaker['lastBlockedCategory'] === null
                ? 'nothing blocked yet'
                : 'last category: ' . self::reportField($breaker['lastBlockedCategory']),
            $breaker['totalBlocks'],
            $breaker['totalBlockThreshold'],
        );
    }

    /**
     * `/model` (crush_code.md Phase 4 item 1) - the slash spelling of the
     * Ctrl+P palette's Switch Model action, which until now was the ONLY way
     * to change provider mid-session even though `Tui\Components\SettingsPane`'s
     * footer already advertised the command.
     *
     * Bare `/model` opens the provider list in exactly the state Ctrl+P →
     * "Switch model" opens it in - `withMode()` resets query and selection, so
     * `PaletteState::root()->withMode('providers')` and the palette's own
     * transition produce the identical triple. It does NOT record a palette
     * MRU use: that ordering is about which rows a Ctrl+P user reaches for,
     * and typing a command is not reaching for a row.
     *
     * `/model <provider>` skips the list, through the same
     * {@see selectPaletteProvider()} the list's own Enter runs - so the switch
     * carries the launch's one PermissionGate and project root across, reports
     * an unknown name into the transcript rather than throwing, and fires
     * `$onConfigChange('provider', …)`.
     *
     * PERSISTENCE: session-only from this class's point of view. The
     * `onConfigChange` callback is where a `provider` choice becomes durable,
     * and the callback that writes `~/.sugar-crush/config.json` is installed by
     * `Cli\Bootstrap::chat()`; a Chat built without one (every embedder, and
     * every test here) switches for this session and persists nothing.
     *
     * @param list<string> $args
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleModelCommand(array $args): array
    {
        if ($args === []) {
            return [$this->withInputBuf('')->mutate([
                'palette' => PaletteState::root()->withMode('providers'),
            ]), null];
        }

        // A provider name is a single token. More than one means the user typed
        // a sentence, and guessing which word was the name would switch to
        // something they did not ask for - so say what the command takes and
        // which names exist, in the transcript, where the answer is readable.
        if (count($args) !== 1) {
            $available = implode(', ', $this->availableProviderNames());

            return [$this->withInputBuf('')->mutate(['history' => [
                ...$this->history,
                Message::assistant("Usage: /model [provider]. Available: {$available}"),
            ]]), null];
        }

        return $this->withInputBuf('')->selectPaletteProvider($args[0]);
    }

    /**
     * `/help` (crush_code.md Phase 4 item 2) - the command list, which is what
     * `/help` means in every other CLI. It used to be a second spelling of
     * `/keys`, i.e. two names for the keyboard reference and no name at all for
     * the thing a first-time user is actually asking for.
     *
     * Rendered from {@see CommandRegistry::slashCommands()} rather than from a
     * hand-written list, argument hints included - the hints were parsed,
     * stored and shown by nothing until Phase 4.
     *
     * Laid out against the CURRENT terminal width and then frozen into
     * history, like every other message this class writes: the transcript
     * renderer does not re-wrap an assistant turn, and a line wider than the
     * frame collides with the row below it (see {@see Renderer::render()}'s
     * tail clip). A later resize to something narrower can therefore leave
     * this listing over-wide - that is the pre-existing behaviour of every
     * long message here, not something this command adds, and the alternative
     * (re-rendering history on resize) is a change to how Message works.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleHelpCommand(): array
    {
        // slashCommandRows() rather than CommandRegistry::slashCommands(): the
        // listing that answers "what can I type" must name the file-based
        // commands too, or a project ships a command whose only discovery route
        // is reading the repository.
        $commands = $this->slashCommandRows();

        // Counted here, at render time, from the list being rendered - a
        // command count written into a docblock or a test literal is exactly
        // the number that goes stale the next time a row is added.
        $lines = ['Slash commands (' . count($commands) . '):'];

        // GROUPED, not walked in declared order: the registry INTERLEAVES its
        // categories - the 'Session' rows arrive in several separate runs, and
        // the 'App' rows in two - so a "heading whenever the category changes"
        // walk prints some headings more than once. Deliberately no counts here:
        // the sentence this replaces claimed "three separate runs" printing a
        // heading "five times", which are two different wrong numbers and
        // mutually impossible, and any literal in this spot goes stale the next
        // time a row moves - `clear`, added by this Phase, is what turned the
        // `[compact]` run into `[compact, clear]`. The property itself is
        // derived from the registry and asserted by
        // `Commands\SlashDispatchTest::testTheHelpListingPrintsOneHeadingPerCategoryNotOnePerRun()`.
        // Category order is first-appearance order, the same rule
        // {@see \SugarCraft\Crush\Tui\Components\MenuBar} groups its menus
        // by.
        /** @var array<string, list<\SugarCraft\Crush\Commands\CommandSpec>> $byCategory */
        $byCategory = [];
        foreach ($commands as $spec) {
            $byCategory[$spec->category][] = $spec;
        }

        // max(20, …) is {@see Renderer}'s own floor convention for every box it
        // sizes, kept rather than invented. It does mean the listing can be
        // over-wide on a very narrow terminal, and the exact threshold is 26
        // columns of TERMINAL: below that, the 20-column floor plus the 6
        // columns of shell chrome it is painted inside exceeds the terminal
        // itself. Exactly as every other box here can be at that size - and at
        // that size the status bar is over-wide too, at 54 columns, measured.
        // Not made worse by this command, not fixed by it.
        $budget = max(20, ($this->cols ?? 80) - self::HELP_CHROME_COLS);
        foreach ($byCategory as $category => $rows) {
            $lines[] = '';
            $lines[] = self::clip($category, $budget);
            foreach ($rows as $spec) {
                $left = '  /' . $spec->name . ($spec->argumentHint !== null ? ' ' . $spec->argumentHint : '');
                $leftWidth = Width::string($left);
                if ($leftWidth <= self::HELP_NAME_COLS - 2) {
                    $lines[] = self::clip($left . str_repeat(' ', self::HELP_NAME_COLS - $leftWidth) . $spec->description, $budget);
                    continue;
                }

                // A hint too wide for the name column spills onto its own row
                // rather than shoving the description off the edge - the usual
                // `--help` layout, and the reason the description column is a
                // constant instead of the widest row in the registry. Three
                // widths are in play and all three are `/websearch`'s, so each
                // is named with the domain it is true of: the number that
                // belongs HERE is its whole `  /name <hint>` column at 71
                // columns, while its popup head `/name <hint>` is 69 and its
                // hint alone is 58 (all measured with `Width::string()` over
                // `CommandRegistry::all()`). 71 is wider than this method's own
                // budget on an 80-column terminal, which is 70, so a
                // description column sized to it would leave the descriptions
                // nowhere to go.
                $lines[] = self::clip($left, $budget);
                $lines[] = self::clip(str_repeat(' ', self::HELP_NAME_COLS) . $spec->description, $budget);
            }
        }

        $lines[] = '';
        // Clipped like every other row: at 40 columns this sentence is the
        // longest line in the listing, and an unclipped trailer would be the
        // one over-wide row the rest of this method exists to avoid.
        $lines[] = self::clip('Press ? or type /keys for the keyboard shortcut reference.', $budget);

        return [$this->withInputBuf('')->mutate(['history' => [
            ...$this->history,
            Message::assistant(implode("\n", $lines)),
        ]]), null];
    }

    /**
     * `/clear` (crush_code.md Phase 4 item 2) - empty the transcript and STAY
     * in this session. Deliberately not `/new`: the palette's New session
     * action ({@see handlePaletteNewSession()}) mints a fresh session id and
     * leaves the old conversation where it was, which is the opposite trade.
     *
     * Exactly what it touches, and what it does not:
     *
     * - TRANSCRIPT: emptied. That IS the feedback - a confirmation message
     *   would leave the transcript non-empty, which is the one thing the
     *   command promises.
     * - TOKEN/CONTEXT COUNTERS: reset, because there are none to reset. The
     *   status bar's "~NK / 100K context" is {@see estimateTokenCount()} over
     *   `$history` on every render, and so are the compaction tiers - clearing
     *   history is what makes them read zero.
     * - SCROLL OFFSET and EXPANDED TOOL BODIES: reset. Both index into the
     *   transcript that just went away.
     * - PARTIAL STREAMING TEXT: cleared, so a `/clear` typed the instant after
     *   an aborted turn cannot repaint half a reply above an empty transcript.
     * - SESSION ID: kept. This is the distinction from `/new`.
     * - SESSION FILE ON DISK: untouched. No `createSession()`, no delete, no
     *   rename - which also means the session's title survives.
     * - CHECKPOINTS: untouched, so `/rewind` still reaches the turns this
     *   command cleared from view. That is a deliberate choice and the
     *   arguable one: `/clear` is a "get this off my screen and out of the
     *   model's context" command, not a redaction tool, and destroying
     *   recovery points is not something an undo-less TUI command should do
     *   silently.
     * - IN-FLIGHT TURN: not cancelled, and still UNREACHABLE mid-turn — but by a
     *   different mechanism than it once was, and the difference is worth
     *   stating because the old one was a blanket keystroke swallow that is now
     *   gone. `update()` used to drop EVERY key while a turn ran, which made this
     *   method unreachable as a side effect of the input box being dead. Typing
     *   mid-turn now works; what keeps this method out of reach is
     *   {@see refuseInFlightCommand()}, which claims every `/`-prefixed draft
     *   submitted while `inFlight` and answers with a visible notice instead of
     *   dispatching. Escape is still the cancel.
     *
     *   Both of {@see submit()}'s entry points are covered: Enter reaches the
     *   mid-turn branch at the head of submit(), and Ctrl+A — whose arm would
     *   otherwise replace the draft with `/agents` and submit it — is intercepted
     *   ahead of its arm by {@see refuseWhileInFlight()}. Pinned by
     *   {@see \SugarCraft\Crush\Tests\Commands\SlashDispatchTest::testSlashClearIsUnreachableWhileATurnIsInFlight()}.
     *   What once falsified the claim was a bug in the `/compact` summarization
     *   clearing `inFlight` out from under a running turn — fixed at the source
     *   (see {@see compactionChanges()}) and pinned by
     *   {@see \SugarCraft\Crush\Tests\Chat\CompactModelSummaryTest::testALandingCompactionLeavesARunningTurnInFlightAndItsReplyStillLands()}.
     * - AN OUTSTANDING `/compact` SUMMARIZATION: abandoned. Its
     *   {@see HistoryCompactedMsg} still arrives and is dropped, because the
     *   exchanges it summarised are the ones this command just deleted -
     *   applying it would resurrect them as summaries above an emptied
     *   transcript.
     * - SPEND TOTAL AND CAP: untouched, and deliberately so. Both belong to the
     *   LAUNCH rather than to the transcript ({@see $tokenTracker} is carried by
     *   object identity through every clone), and money already spent does not
     *   become unspent because the screen was cleared.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleClearCommand(): array
    {
        return [$this->mutate([
            'history' => [],
            'inputBuf' => '',
            'streamingText' => '',
            'reasoningText' => '',
            'scrollOffset' => 0,
            'expanded' => [],
            'pendingCompactionId' => null,
        ]), null];
    }

    /**
     * Provider names `/model <name>` accepts, for the usage message. Read from
     * {@see \SugarCraft\Crush\Cli\Bootstrap::availableProviders()} - the same
     * list the palette's provider mode browses - and degraded to the empty
     * string rather than propagated if that read throws, because a usage
     * message is not worth failing a turn over.
     *
     * @return list<string>
     */
    private function availableProviderNames(): array
    {
        try {
            return array_map('strval', array_keys(\SugarCraft\Crush\Cli\Bootstrap::availableProviders()));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Hard-clip one already-laid-out line to $cols columns, ellipsis included.
     * Used by the `/help` listing, whose rows are built from registry data
     * that can be arbitrarily long.
     */
    private static function clip(string $line, int $cols): string
    {
        if (Width::string($line) <= $cols) {
            return $line;
        }

        $out = mb_substr($line, 0, max(1, $cols - 1));
        while ($out !== '' && Width::string($out) > $cols - 1) {
            $out = mb_substr($out, 0, mb_strlen($out) - 1);
        }

        return $out . '…';
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
     * Failure is silent TO THE USER by design — a session that stays unnamed is
     * a non-event, and surfacing a title-generation error mid-turn is worse than
     * no title. It is no longer silent to the SPEND TRACKER: an unusable title
     * and a refused rename both still dispatch a {@see SessionTitledMsg} whose
     * only remaining job is to carry the call's cost. Only a rejected promise
     * dispatches nothing, because a rejection carries no figure to report.
     *
     * NOT SEPARATELY GATED BY THE SPEND CAP, and that is a measured claim rather
     * than an omission. This is only ever scheduled from {@see submit()}'s
     * turn-dispatch tail, which sits AFTER {@see spendCapRefusal()} — so a
     * session already at its cap has its turn refused and never reaches here.
     * The one window is the turn that CROSSES the cap, whose own cost is not
     * known until it settles, i.e. after this call has already gone out. A gate
     * here could therefore only ever refuse a call the turn-level gate had
     * already let through, and it fires at most once per session in any case.
     * `/compact` is different and IS gated — see
     * {@see scheduleModelCompaction()} — because it is reachable by a user
     * typing it at a session that is already over.
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
                    // Every exit from here dispatches a Msg, including the two
                    // that produce no title, because the Msg is also what
                    // carries the call's COST to the tracker. Silence used to be
                    // the answer on both, which meant an unusable title or a
                    // failed rename made the call free in the readout. An empty
                    // $title is dropped by update()'s arm; the usage is not.
                    $title = self::sanitizeSessionTitle($msg->content);
                    if ($title === '') {
                        return new SessionTitledMsg($sessionId, '', $msg->usage);
                    }
                    try {
                        $store->renameSession($sessionId, $title);
                    } catch (\Throwable) {
                        // AN HONEST GAP: this exit is the same construction as the
                        // empty-title one above, which IS pinned
                        // (ChatTest::testAnEmptyGeneratedTitleIsNeverPersistedButItsCostStillIs),
                        // but it has no test of its own. Both store classes are
                        // `final`, so a throwing store cannot be substituted, and
                        // provoking a real PDO write failure mid-suite (a chmod'd
                        // sqlite file) is not deterministic across the users CI
                        // runs as. Named rather than faked with a presence check.
                        return new SessionTitledMsg($sessionId, '', $msg->usage);
                    }
                    return new SessionTitledMsg($sessionId, $title, $msg->usage);
                },
                // Still nothing on a rejection, and still deliberately silent
                // (see this method's docblock): there is no Message, so no
                // figure, and nothing for the user to be told about.
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

        $inbox = $next->liveToolEvents;

        // The consuming half of the backend's `$onToken` seam, and the fix for
        // the second half of crush_code.md Phase 0 item 13: this used to be
        // `$next->streaming ? $next->onToken : null`, i.e. null on every real
        // run, because nothing ever set either field — so even a backend that
        // streamed perfectly had nowhere to stream TO.
        //
        // The Chat's own live rendering no longer depends on an embedder
        // supplying a callback: deltas go onto the same inbox the tool events
        // use and {@see pumpLiveToolEvents()} folds them into
        // {@see $streamingText}. $onToken stays an ADDITIONAL, optional
        // observer for embedders that want the raw chunks (a logger, a
        // non-TUI shell), and is invoked after the queue append so a throwing
        // embedder callback cannot cost the UI the delta it is holding.
        //
        // Nor may it cost the UI the REST of the turn. An exception raised
        // here unwinds through the backend and out of the Cmd::promise()
        // factory below, so no promise is created, no AssistantMsg is ever
        // dispatched, and the Chat sits inFlight with no way to settle short
        // of an abort — a whole turn lost to a misbehaving logger. A broken
        // observer is therefore detached for the remainder of THIS turn
        // (per-turn because $userSink is a local of this call, so one bad
        // delta does not disable the embedder's sink forever) and reported
        // once through error_log rather than once per token.
        //
        // NOT GATED, AND THE DECISION WAS MADE RATHER THAN DEFERRED (E154).
        // That last clause settled the FREQUENCY without ever asking about the
        // CHANNEL, and the channel is the interesting half: this closure runs
        // inside the streaming loop of a turn already in flight, so by the time
        // it can fire the alternate screen has been up for the whole session and
        // the write lands on a frame the renderer believes it owns. Every
        // launch-time stderr write in this application was routed for exactly
        // that reason, onto
        // {@see \SugarCraft\Crush\Cli\Bootstrap::warnPermissionConfigInTranscript()}.
        //
        // THE SEAM IS UNREACHABLE FROM HERE, verified rather than assumed: it
        // appends to a static list `Bootstrap::chat()` drains into
        // {@see withLaunchNotices()} ONCE, at construction, and this fires
        // mid-turn long afterwards. So the choice is between fd 2 and a
        // `SUGARCRUSH_DEBUG_*` gate on
        // {@see \SugarCraft\Crush\Skills\SkillLoader::recordSkip()}'s
        // quiet-by-default contract — and the gate is the better answer, because
        // the audience is the EMBEDDER whose `onToken` threw rather than the
        // person at the terminal, who cannot act on "your logger raised" and
        // whose turn completes normally either way.
        //
        // WHY IT IS STILL UNCONDITIONAL: `StreamingWiringTest::
        // testAThrowingObserverLosesItsOwnDeltasButNotTheTurn()` asserts this
        // line reaches `error_log()`, and it is right to — "the failure is not
        // swallowed silently" is the contract as it stands. Gating is therefore
        // a two-file change and belongs in a round where both files are in one
        // lane's hands. Do not gate this without amending that test in the same
        // commit, and do not delete this paragraph instead of doing so.
        $userSink = $next->onToken;
        $onToken = !$next->streaming ? null : static function (string $delta) use ($inbox, $generation, &$userSink): void {
            if ($delta === '') {
                return;
            }
            $inbox[] = [$generation, new TokenDelta($delta)];
            if ($userSink === null) {
                return;
            }

            try {
                $userSink($delta);
            } catch (\Throwable $e) {
                $userSink = null;
                error_log('Chat: onToken observer threw, detaching it for this turn: ' . $e->getMessage());
            }
        };

        return Cmd::promise(static function () use ($backend, $history, $onToken, $cancellation, $generation, $inbox): PromiseInterface {
            $onEvent = static function (ToolStarted|ToolFinished $event) use ($inbox, $generation): void {
                $inbox[] = [$generation, $event];
            };

            // E494 - the last hop of E456, and the reason the user could watch
            // a frozen "assistant is thinking..." for two minutes while the
            // thinking itself was already crossing EngineBackend's socket. The
            // channel was built end to end in round 56 and then nobody passed a
            // sink, so every reasoning frame reached the parent process and was
            // dropped on the floor.
            //
            // NO embedder seam and no `$next->streaming` gate, deliberately, on
            // both counts unlike $onToken above. Reasoning is display-only: it
            // never enters $history, never reaches the model and never reaches
            // a checkpoint (see {@see ReasoningDelta}), so there is nothing here
            // an embedder's callback could be needed for and nothing a
            // streaming-off session would be protected from. What "streaming
            // off" turns off is incremental delivery of the ANSWER; a thought
            // has no non-incremental form to fall back to - the settled
            // Message's own `reasoning` is what the transcript shows afterwards,
            // and this is the only chance to show it as it happens.
            $onReasoning = static function (string $delta) use ($inbox, $generation): void {
                if ($delta === '') {
                    return;
                }
                $inbox[] = [$generation, new ReasoningDelta($delta)];
            };

            // All three handlers share the inbox with the LIVE pump
            // ({@see Chat::pumpLiveToolEvents()}), and both drain it
            // destructively - so an event is applied exactly once no matter
            // which of the two got to it first.
            //
            // ASKED STRUCTURALLY, never by class name and never by arity
            // sniffing: a backend that can report thinking declares
            // {@see Backend\ObservesReasoning}, and one that cannot is called
            // with the four arguments its signature actually documents. Passing
            // the fifth unconditionally would "work" - PHP drops surplus
            // positional arguments to a userland method without a murmur - and
            // that silence is exactly the failure mode this branch exists to
            // make impossible to reintroduce.
            $promise = $backend instanceof ObservesReasoning
                ? $backend->completeAsync($history, $onToken, $cancellation, $onEvent, $onReasoning)
                : $backend->completeAsync($history, $onToken, $cancellation, $onEvent);

            return $promise->then(
                static function (Message $msg) use ($inbox, $generation): ?Msg {
                    $events = self::drainToolEventInbox($inbox, $generation);

                    return $events === []
                        ? new AssistantMsg($msg, $generation)
                        : new BackendToolEventsMsg($events, $msg, $generation);
                },
                static function (\Throwable $e) use ($inbox, $generation): ?Msg {
                    // A turn that failed AFTER running tools still shows what
                    // those tools did - otherwise the placeholders queued for
                    // them would be the only trace and they never even render.
                    $message = Message::assistant('_[error: ' . $e->getMessage() . ']_');
                    $events = self::drainToolEventInbox($inbox, $generation);

                    return $events === []
                        ? new AssistantMsg($message, $generation)
                        : new BackendToolEventsMsg($events, $message, $generation);
                },
            );
        });
    }

    /**
     * Take everything this turn queued but the live pump never got to, and
     * leave the inbox empty.
     *
     * Called once per turn, when the backend's promise settles. Events
     * belonging to some OTHER generation are discarded rather than returned:
     * they can only be an aborted turn's, and the resolving turn's
     * {@see BackendToolEventsMsg} would carry them under the wrong stamp.
     *
     * Undrained {@see TokenDelta}s and {@see ReasoningDelta}s are discarded
     * outright, whatever their generation. They share the inbox (see
     * {@see $liveToolEvents}) but not this destination:
     * {@see BackendToolEventsMsg} carries tool lifecycle states, and the
     * settled Message beside them already contains every byte those deltas
     * described — its content for the one, its `reasoning` for the other.
     * Applying them here would in any case be too late to be streaming — the
     * turn is over.
     *
     * @param \ArrayObject<int, array{0: int, 1: ToolStarted|ToolFinished|TokenDelta|ReasoningDelta}> $inbox
     * @return list<ToolStarted|ToolFinished>
     */
    private static function drainToolEventInbox(\ArrayObject $inbox, int $generation): array
    {
        $events = [];
        foreach ($inbox as [$eventGeneration, $event]) {
            if ($eventGeneration === $generation
                && !$event instanceof TokenDelta
                && !$event instanceof ReasoningDelta) {
                $events[] = $event;
            }
        }
        $inbox->exchangeArray([]);

        return $events;
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
     * ## This used to freeze the whole TUI, and why the obvious fix was wrong
     *
     * `WorkflowEngine::run()` was called synchronously from inside `update()`,
     * so no frame painted, no keystroke was read and no spinner turned until
     * the last stage was over — for as long as the run took, up to
     * `ProcessExecutor`'s 300s-per-worker ceiling.
     *
     * The fix recorded here for a long time was "the fork-plus-socket pattern
     * {@see Backend\EngineBackend::completeAsync()} already uses". Measured,
     * that pattern would have made the command asynchronous and made the
     * feature it was blocking permanently unreachable: the split-pane
     * compositor renders from `AgentManager::liveOutputs()`, which reads the
     * manager's sub-agent map — an object graph in THIS process. Fork the
     * workflow and every sub-agent it creates lives, and dies, in a child the
     * renderer cannot see. The parent would repaint a blank pane promptly.
     *
     * ## What it does instead
     *
     * The run goes into a `\Fiber`, and the driver resumes it from a periodic
     * timer on the same ReactPHP loop that repaints
     * ({@see driveWorkflowFiber()}). A fiber suspends its whole call stack, so
     * one suspension point deep inside the pool
     * ({@see \SugarCraft\Crush\Agents\AgentWorkerPool::idle()}) yields the
     * entire `Chat → WorkflowEngine → AgentManager → AgentWorkerPool` chain
     * back to the loop, and everything stays in this process where the
     * renderer can see it.
     *
     * Nothing runs before this method returns: the fiber is not started here,
     * only handed to the timer. `update()` returns on the same tick the user
     * pressed Enter, with the command echoed and `inFlight` set.
     *
     * WHAT IS NOT COVERED: the yield granularity is one poll of a parallel
     * stage's worker pool. A stage type that blocks the PARENT rather than
     * dispatching to workers still holds the fiber for its duration; it just
     * no longer holds it for the whole workflow.
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

        $engine = $this->workflowEngine;

        // Built here, started by the driver's FIRST timer tick. Everything
        // inside runs off the main stack.
        $fiber = new \Fiber(static function () use ($engine, $workflowName, $context): string {
            try {
                return self::describeWorkflowResult($workflowName, $engine->run($workflowName, $context));
            } catch (\Throwable $e) {
                // One catch, where there used to be three arms
                // ({@see WorkflowNotFoundException}, {@see WorkflowLoadException},
                // everything else) that produced the same string: inside a
                // fiber the distinction matters LESS, not more, because an
                // uncaught throw here surfaces on the driver's timer tick with
                // no user-facing context at all.
                return "**Error:** {$e->getMessage()}";
            }
        });

        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputText)],
            'inputBuf' => '',
            // The workflow is a turn: it occupies the session, the spinner
            // should run, and a second prompt must queue behind it rather than
            // interleave with it. Cleared by update()'s AssistantMsg arm when
            // driveWorkflowFiber() settles, on both the success and the error
            // path -- both resolve, neither rejects.
            'inFlight' => true,
        ]);

        return [$next, $next->driveWorkflowFiber($fiber)];
    }

    /**
     * Render a finished run as the assistant's reply.
     *
     * Static and split out of {@see workflowRun()} so the fiber body closes
     * over nothing but its arguments: a fiber outlives the `Chat` that created
     * it (that instance is replaced on the very next `update()`), and capturing
     * `$this` would pin a stale model for the length of the run.
     */
    private static function describeWorkflowResult(string $workflowName, WorkflowResult $result): string
    {
        $response = $result->isFailure()
            ? "**Workflow '{$workflowName}' failed**\n\n"
            : "**Workflow '{$workflowName}' completed**\n\n";
        $response .= "ID: `{$result->workflowId}`\n";
        $response .= "Status: {$result->status->value}\n";
        $response .= "Stages completed: " . count($result->stageResults) . "\n";
        $response .= "Total tokens: {$result->totalTokens}\n";
        $response .= "Total cost: \${$result->totalCost}";
        // The failing stage's message, or the reason never reaches the
        // user at all: a failed run used to print the word "completed" in
        // bold with `Status: failed` under it and nothing else, so a stage
        // refused for declaring a tool this session's mode denies looked
        // like a workflow that had simply not worked. The engine puts the
        // reason on the stage; this is the only place that can show it.
        $failure = $result->firstFailure();
        if ($failure !== null && ($failure->error ?? '') !== '') {
            $response .= "\n\nStage '{$failure->stageName}': {$failure->error}";
        }

        return $response;
    }

    /**
     * Step a workflow fiber from the event loop until it terminates, then
     * deliver its report as the assistant's reply.
     *
     * ## The invariant this exists to hold
     *
     * BETWEEN two resumes the loop is free. That is the entire point: candy-core's
     * `Program` repaints from its own periodic timer on this same loop, so a
     * frame lands in every gap, and `Renderer::renderView()` reads
     * `AgentManager::liveOutputs()` at that moment — the sub-agents the
     * suspended fiber has running, in this process, with the partial text
     * `AgentWorkerPool::pumpProgress()` mirrored onto them on its last poll.
     *
     * `start()` therefore happens on the first TICK, never inline: doing it
     * here would run the workflow up to its first suspension point inside
     * `update()`, which is the freeze this change is about, only shorter.
     *
     * ## Failure and cancellation
     *
     * The promise RESOLVES on a throwing fiber rather than rejecting. A
     * rejection dispatches candy-core's `ExceptionMsg`, which this model does
     * not handle, so a workflow that died would have cleared nothing and left
     * `inFlight` latched on forever with no message to explain it. An error
     * notice through the ordinary AssistantMsg arm both tells the user and
     * releases the turn.
     *
     * The timer is cancelled on every exit, including the throwing one; a live
     * periodic timer holding a terminated fiber would resume it and raise
     * `FiberError` on the next tick.
     *
     * ⚠️ KNOWN LIMITATION, stated rather than hidden by a generation stamp:
     * double-Escape releases the TURN (it clears `inFlight` and bumps the
     * generation) but does NOT stop the workflow — the fiber keeps being
     * resumed and its report still lands, because this Msg carries no
     * generation. That is deliberate for now: the run really did happen and
     * its result is worth showing, and silently dropping it would leave the
     * user with forked workers they cannot see and no record they ran.
     * Actually CANCELLING mid-run means threading a `CancellationToken` down
     * to `AgentWorkerPool::cancelAll()`, which is its own change.
     *
     * WHAT THAT LIMITATION USED TO IMPLY, and no longer does: because the
     * released turn accepts input again, a user could type a SECOND
     * `/workflow run` while the first was still stepping, and get it. Measured
     * — two runs live at once, exiting in an order unrelated to the order they
     * started, each popping the other's SIGINT/SIGTERM frame off
     * `WorkflowEngine`'s LIFO handler stack, and both collapsing onto one
     * `$resultsByName` slot when they shared a name (so `/workflow pause` on
     * run A's own printed id persisted run B). `WorkflowEngine` now REFUSES a
     * run that would interleave with a live one and says so; see
     * `WorkflowEngine::$liveRunOwners`. Nesting — a stage re-entering `run()`
     * on the same call stack — is unaffected and still works. So the turn is
     * still released without stopping the run; what changed is that the
     * released turn can no longer start a second one on top of it.
     */
    private function driveWorkflowFiber(\Fiber $fiber): \Closure
    {
        return Cmd::promise(static function () use ($fiber): PromiseInterface {
            $deferred = new Deferred();
            $loop = Loop::get();
            $timer = null;

            $settle = static function (string $text) use ($deferred): void {
                $deferred->resolve(new AssistantMsg(Message::assistant($text)));
            };

            $timer = $loop->addPeriodicTimer(
                self::WORKFLOW_STEP_INTERVAL_SECONDS,
                static function () use ($fiber, $loop, &$timer, $settle): void {
                    try {
                        $fiber->isStarted() ? $fiber->resume() : $fiber->start();
                    } catch (\Throwable $e) {
                        $loop->cancelTimer($timer);
                        $settle("**Error:** {$e->getMessage()}");

                        return;
                    }

                    if (!$fiber->isTerminated()) {
                        return;
                    }

                    $loop->cancelTimer($timer);
                    $settle((string) $fiber->getReturn());
                },
            );

            return $deferred->promise();
        });
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
            // BOTH tiers named, because Bootstrap::workflowEngine() searches
            // both: naming only the home one sends a user who checked a
            // workflow into their repo off to fix the wrong directory. The
            // project tier's `.yaml`-only rule is named for the same reason —
            // otherwise a user who committed `deploy.php` is pointed at the
            // right directory and the wrong extension (see
            // WorkflowRegistry::__construct() for why that tier refuses PHP).
            $response = "No workflows found. They are read from `.sugar-crush/workflows/*.yaml` "
                . "(project, YAML only — skipped entirely if that directory resolves outside the "
                . "checkout, which the launch reports on stderr) or "
                . "`~/.sugar-crush/workflows/*.{yaml,php}`.";
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
            return $this->commandFailureResponse($inputBuf, $output, $exitCode);
        }

        return $this->shareResponse($inputBuf, $output);
    }

    /**
     * Handle /websearch command.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleWebSearchCommand(string $inputBuf): array
    {
        $afterCommand = ltrim(substr($inputBuf, 11)); // "/websearch" = 10 chars
        $args = $afterCommand !== '' ? preg_split('/\s+/', $afterCommand) : [];

        ob_start();
        $command = new WebSearchCommand();
        $exitCode = $command->execute($this, $args);
        $output = ob_get_clean();

        if ($exitCode !== 0) {
            return $this->commandFailureResponse($inputBuf, $output, $exitCode);
        }

        return $this->webSearchResponse($inputBuf, $output);
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

    /**
     * A slash command that exited non-zero, reported IN the transcript.
     *
     * USER-REPORTED CRASH. The three callers each did
     * `return [$this, static fn() => print $output];`, and that is a fatal
     * rather than a diagnostic: `print` is an EXPRESSION whose value is
     * `int 1`, so the closure is a `Cmd` returning an int.
     * {@see \SugarCraft\Core\Program::scheduleCmd()} dispatches whatever
     * non-null a Cmd returns, and {@see \SugarCraft\Core\Program::dispatch()}
     * requires a `Msg` — so the app died with
     * "Argument #1 ($msg) must be of type Msg, int given" on the first
     * `/websearch` with no query. `/share` and `/agents` carried the identical
     * line, and `/agents` is one Ctrl+A away.
     *
     * Writing to stdout was the wrong shape even before the TypeError: the
     * screen belongs to candy-core's frame renderer, so a bare `print` during
     * a TUI run paints over a frame it did not compose and is erased by the
     * next one. The old comment at the `/agents` site said "output error but
     * don't add to history", which is why the failure had nowhere to appear.
     *
     * Both messages are added, unlike before: the command ECHO so the
     * transcript shows what was typed, and the output as `Role::System`
     * rather than `assistant` because an app-generated failure notice is not
     * a model reply and must not be replayed to the provider as one.
     *
     * `$exitCode` is named in the fallback only — a command that fails
     * silently would otherwise produce an empty transcript line, which reads
     * as "nothing happened" for the one case where something did.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function commandFailureResponse(string $inputBuf, string $output, int $exitCode): array
    {
        $trimmed = trim($output);
        $notice = $trimmed !== ''
            ? $trimmed
            : sprintf('Command failed with exit code %d and produced no output.', $exitCode);

        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputBuf), Message::system($notice)],
            'inputBuf' => '',
            'inFlight' => false,
        ]);

        return [$next, null];
    }

    /**
     * Return a websearch command response, adding both user command and assistant response to history.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function webSearchResponse(string $inputBuf, string $response): array
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
        // R20.fix: Bootstrap::chat() USED to pass no `agentManager:` -- so
        // this was reachable with zero configuration via a typed "/agents"
        // *and*, since R20 added the Ctrl+A shortcut below in update(), via a
        // single accidental keystroke. crush_code.md Phase 1 item 1 closed
        // that gap (Bootstrap::agentManager() now supplies a real one on every
        // launch), so the branch below is no longer what a CLI user hits; it
        // remains the degradation for embedders that construct a Chat
        // directly, and is kept because that is a supported construction --
        // Chat's every other collaborator is optional the same way. The
        // former "?? throw" here escaped
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

        if ($exitCode !== 0) {
            return $this->commandFailureResponse($inputBuf, $output, $exitCode);
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
     * `/budget` — show the session's reported spend, or set/clear the cap
     * {@see spendCapRefusal()} enforces (crush_code.md Phase 5 item 7).
     *
     * Three forms, and the bare one is the reason this command is worth having
     * even to a user who never sets a cap: it is the only place the
     * input/output/unsplit token breakdown {@see TokenTracker::summary()}
     * computes is shown at all. The status bar has room for the dollar figure
     * and nothing else.
     *
     * `0` is REFUSED rather than read as "no cap", and so is anything else that
     * is not a positive finite number — see {@see isUsableSpendCap()}, which is
     * the same test the constructor and `$SUGARCRUSH_MAX_COST` apply. A cap of
     * zero and no cap are opposite intentions, and quietly turning the stricter
     * one into the looser one is the wrong direction to guess in.
     *
     * The cap lives for this session only — it is deliberately not written to
     * `~/.sugar-crush/config.json`. A persisted cap would silently refuse turns
     * in a later session whose spend the user never looked at, and the env var
     * is already the way to make one stick across launches.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleBudgetCommand(string $inputText): array
    {
        $argument = trim(mb_substr(trim($inputText), mb_strlen('/budget')));

        if ($argument === '') {
            return $this->budgetResponse($inputText, $this->budgetStatusLine(), null);
        }

        if (in_array(strtolower($argument), ['off', 'none', 'clear'], true)) {
            return $this->budgetResponse(
                $inputText,
                $this->maxCostUsd === null
                    ? 'No spend cap was set. ' . $this->budgetStatusLine()
                    : 'Spend cap cleared. ' . $this->budgetStatusLine(),
                null,
                clearCap: true,
            );
        }

        // A leading `$` is what a human types; accepted rather than rejected,
        // and stripped before the numeric test so `$5` and `5` mean the same.
        $amount = ltrim($argument, '$');
        // is_numeric() is not enough on its own, and the gap was reachable:
        // `/budget 1e309` is numeric and casts to INF, which is `> 0.0` and so
        // used to install a cap that rendered as `$inf` and — every comparison
        // against INF being false — refused nothing. isUsableSpendCap() is the
        // one definition of a cap this app will act on; see its docblock.
        if (!is_numeric($amount) || !self::isUsableSpendCap((float) $amount)) {
            return $this->budgetResponse(
                $inputText,
                'Usage: /budget <amount> to cap this session\'s spend (e.g. /budget 5 or /budget $2.50), '
                . '/budget off to clear it, /budget on its own to see where you are. '
                . 'The amount must be a real number greater than zero — a cap of 0 and no cap are opposite '
                . 'requests, so `0` is refused rather than guessed at, and a figure too large to represent '
                . '(`1e309`, which is infinity) is refused rather than accepted as a cap that would then '
                . 'never trigger.',
                null,
            );
        }

        $cap = (float) $amount;

        return $this->budgetResponse(
            $inputText,
            sprintf('Spend cap set to $%.4f. ', $cap) . $this->budgetStatusLine($cap),
            $cap,
        );
    }

    /**
     * Where this session stands, in the two units it can honestly report.
     *
     * Says "not reported" rather than `$0.0000` when nothing has arrived: an
     * offline run, a shell-out backend and a streamed session whose provider
     * sends no usage block all reach here with an empty tracker, and printing a
     * zero would claim knowledge of a spend nobody measured. The same
     * distinction the status bar draws with `$?` — see {@see hasReportedSpend()}.
     */
    private function budgetStatusLine(?float $cap = null): string
    {
        $cap ??= $this->maxCostUsd;
        $capText = $cap === null ? 'no cap' : sprintf('cap $%.4f', $cap);

        if (!$this->hasReportedSpend()) {
            return 'Spend so far: not reported by this provider (' . $capText
                . '). Streamed turns and self-hosted providers commonly report no usage at all, '
                . 'and an unreported session is never refused by the cap.';
        }

        return sprintf('Spend so far: $%.4f (%s). %s', $this->spentUsd(), $capText, $this->usageSummary());
    }

    /**
     * One exit for every `/budget` form: append the user's line and the answer,
     * and carry (or clear) the cap in the same clone.
     *
     * $clearCap exists because a null $cap is ambiguous here — "leave it alone"
     * for the show/usage forms, "remove it" for `off` — and a bool saying which
     * is cheaper to read than two near-identical exits.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function budgetResponse(string $inputText, string $response, ?float $cap, bool $clearCap = false): array
    {
        $changes = [
            'history' => [...$this->history, Message::user($inputText), Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
        ];
        if ($cap !== null) {
            $changes['maxCostUsd'] = $cap;
        } elseif ($clearCap) {
            $changes['maxCostUsd'] = null;
        }

        return [$this->mutate($changes), null];
    }

    /**
     * `/compact` — condense the transcript, either straight away on the local
     * heuristic or, when there is a model to ask, after its summaries land.
     *
     * Which of the two happened is visible to the caller only as the returned
     * Cmd: a non-null Cmd means NOTHING has been rewritten yet and the
     * transcript carries a "summarising…" notice instead, and the rewrite
     * happens in {@see applyModelCompaction()} when the
     * {@see HistoryCompactedMsg} lands. So this method does not always compact,
     * and on the model route it returns a Cmd — which the old one-line summary
     * of it ("manually compact chat history") described neither of.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleCompactCommand(string $inputText): array
    {
        $scheduled = $this->scheduleModelCompaction($inputText);
        if ($scheduled !== null) {
            return $scheduled;
        }

        return $this->compactNow($inputText, $this->history, []);
    }

    /**
     * `/compact` typed and answered in one `update()` — the synchronous route,
     * taken when there is no model to ask for summaries.
     *
     * Thin on purpose. Everything about the transcript is
     * {@see compactionChanges()}; what this adds is the part that belongs to
     * the COMMAND rather than to the compaction — the draft was consumed by
     * submitting it, and no turn was started. Those two facts are true here and
     * false on the {@see applyModelCompaction()} route, which is exactly why
     * they live at the call site and not inside the shared part. Before they
     * were split, the landing compaction inherited both and so wiped a draft
     * the user was still typing and cleared `inFlight` out from under a turn
     * that was still running.
     *
     * @param list<Message> $baseHistory
     * @param array<string, string> $summaries
     * @return array{0:Chat,1:?\Closure}
     */
    private function compactNow(string $inputText, array $baseHistory, array $summaries, string $prefix = ''): array
    {
        return [$this->mutate([
            ...$this->compactionChanges($inputText, $baseHistory, $summaries, $prefix),
            // The draft became this command when Enter was pressed, and this
            // command starts no turn.
            'inputBuf' => '',
            'inFlight' => false,
        ]), null];
    }

    /**
     * What compacting $baseHistory does to the TRANSCRIPT and to nothing else,
     * as a `mutate()` change set: the compacted history plus the answer line,
     * and the summarization latch released.
     *
     * $summaries cover the exchanges the model wrote lines for; the heuristic
     * covers the rest. One shared definition of what `/compact` did, reached
     * directly by {@see compactNow()} when there was no model to ask and by
     * {@see applyModelCompaction()} when there was — the two must not drift
     * into two answers.
     *
     * DELIBERATELY NOT IN HERE: `inputBuf` and `inFlight`. A compaction says
     * nothing about the user's draft or about whether a turn is running, and on
     * the asynchronous route both are live state belonging to whatever the user
     * has done since — see {@see HistoryCompactedMsg}, whose whole contract is
     * that the user can keep typing and can send another turn while a
     * summarization is out. {@see compactNow()} sets them because a submitted
     * command legitimately does.
     *
     * $inputText is the draft to echo back as the user's line, or '' when the
     * transcript already carries it — which is the case on the model route,
     * where {@see scheduleModelCompaction()} wrote it out before the request
     * left. Echoing it twice would put a second `/compact` in the transcript
     * for one command.
     *
     * $tierNotice switches the report line from `/compact`'s answer to the
     * automatic tier's own {@see contextCompactedMessage()} — same words, same
     * Role::System, as the synchronous 85% route. It is a report ROLE and WORDING
     * switch only; what gets condensed is identical either way.
     *
     * THE REPORT'S POSITION differs from the synchronous route's and stays that
     * way. The report lands at the END of the history, which on the parked route
     * is AFTER the echoed prompt, whereas the synchronous route's identical notice
     * rides before it. Two things were checked before leaving it:
     *
     *  - Durability. It used to be erased by the next compaction, because
     *    {@see Context\ContextCompactor::groupIntoPairs()} dropped a
     *    non-user/non-assistant message directly following a user turn. That is
     *    fixed in the grouping, so both routes' reports now survive, and the
     *    grouping fix was the whole answer — no message had to move.
     *  - Bedrock. {@see Providers\BedrockProvider::formatMessages()} maps every
     *    SystemMessage to role `user` (backlog §E19), so adjacent notices become
     *    consecutive same-role turns, which Converse rejects. Moving the report
     *    ahead of the prompt does NOT help: measured on the dispatched wire, the
     *    tail is `system user system system` with the report where it is and
     *    `system system user system` with it moved, i.e. four consecutive `user`
     *    entries after Bedrock's mapping either way, because the park notice and
     *    the 70% reminder already bracket the prompt. Only §E19's own fix — hoist
     *    SystemMessage into the Converse request's `system` field — changes that
     *    number, so the position is chosen for what the reader sees instead: the
     *    transcript reads in the order things happened, and the outcome of the
     *    wait belongs after the prompt it was waiting for.
     *
     * @param list<Message> $baseHistory
     * @param array<string, string> $summaries
     * @return array<string, mixed>
     */
    private function compactionChanges(
        string $inputText,
        array $baseHistory,
        array $summaries,
        string $prefix = '',
        bool $tierNotice = false,
    ): array {
        $originalCount = count($baseHistory);

        // Convert history to wire format for the compactor
        $wireHistory = array_map(
            static fn(Message $msg): array => $msg->toWire(),
            $baseHistory
        );

        // Compact the history using all 5 stages. The summaries ride on a COPY
        // of the compactor: savingsPercentage() is per-instance state read
        // straight after compact(), so both calls have to land on the same
        // object.
        $compactor = $summaries === [] ? $this->compactor : $this->compactor->withExchangeSummaries($summaries);
        $compactedWire = $compactor->compact($wireHistory);
        $savingsPercentage = $compactor->savingsPercentage();

        $compactedHistory = $this->messagesFromWire($compactedWire, $baseHistory);

        $newCount = count($compactedHistory);

        // Build response message
        if ($originalCount === 0) {
            $report = Message::assistant($prefix . 'Nothing to compact: chat history is empty.');
        } elseif ($tierNotice) {
            // The automatic tier reports through the very notice its synchronous
            // route uses, so the two routes say the same thing about the same
            // rewrite instead of two things. Role::System comes with it, and on
            // the parked route that role is load-bearing rather than cosmetic:
            // this line sits AFTER the echoed prompt in the history the parked
            // turn is about to be SENT with - the last message of it unless the
            // 70% reminder follows - and a Role::Assistant message after the
            // user's line is an assistant turn the provider CONTINUES instead of
            // an instruction it reads (see {@see scheduleParkedCompaction()}).
            //
            // The counts go in ORIGINAL then NEW, which is the order
            // contextCompactedMessage() renders as "was N messages, now M": swap
            // them and the report claims the compaction GREW the history.
            $report = Message::system($prefix . $this->contextCompactedMessage(
                $originalCount,
                $newCount,
                $savingsPercentage,
                $this->estimateTokenCount($compactedHistory),
                $this->contextTokenLimit(),
            )->content);
        } else {
            $report = Message::assistant(
                $prefix
                . "Context compacted: was {$originalCount} messages, now {$newCount} messages "
                . "(saved {$savingsPercentage}% tokens)"
            );
        }

        $echo = $inputText === '' ? [] : [Message::user($inputText)];

        return [
            'history' => [...$compactedHistory, ...$echo, $report],
            // Whatever summarization was outstanding has either just been
            // consumed or has just been superseded by this compaction; either
            // way nothing is pending now.
            'pendingCompactionId' => null,
        ];
    }

    /**
     * The instruction the summarization model is given. Deliberately narrow: one
     * line per exchange, no prose around them, and a hard ceiling on each line
     * so a chatty model cannot make a "compaction" larger than what it replaced.
     */
    private const COMPACT_SUMMARY_PROMPT = <<<'PROMPT'
        You are compacting a coding-assistant conversation so it fits in a smaller context window.
        You will be given numbered exchanges. For each one, write ONE line recording what was asked
        and what was actually done or decided — file paths, command names, decisions, and outcomes
        are what matter; pleasantries are not.

        Rules:
        - Output exactly one line per exchange, in the same order, prefixed with the exchange number
          and a period, like "1. ...".
        - No preamble, no blank lines, no markdown, no commentary. Nothing but the numbered lines.
        - Keep each line under 200 characters. Losing detail is expected; inventing it is not.
        - If an exchange contains nothing worth keeping, say so plainly on its line.
        PROMPT;

    /**
     * Ask the model to summarise the exchanges `/compact` is about to condense,
     * off the render loop, or null when there is nothing to ask or nobody to ask
     * (crush_code.md Phase 5 item 6).
     *
     * Null is the ordinary answer and it is not a failure: no summary backend
     * (offline, either `$SUGARCRUSH_BACKEND_CMD*` shell-out, every unit test),
     * or a history with
     * nothing a model could usefully summarise. The caller then compacts
     * synchronously on the heuristic exactly as it always did.
     *
     * When it is non-null, `/compact` answers IMMEDIATELY with a one-line notice
     * and rewrites nothing yet. The rewrite happens in
     * {@see applyModelCompaction()} when the {@see HistoryCompactedMsg} lands.
     * The alternative — awaiting the completion inside `update()` — would freeze
     * the whole TUI for the length of a provider call, and this codebase
     * deliberately puts no total-request timeout on a completion because one can
     * legitimately run for many minutes.
     *
     * The request goes out on {@see $summaryBackend}, which carries no tools: see
     * that property's docblock for why the tool-capable main backend is the wrong
     * thing to route a compaction through.
     *
     * GATED BY THE SPEND CAP, which needs saying because `/compact` reaches this
     * point past {@see spendCapRefusal()}: the cap is checked after
     * {@see dispatchCommand()} so `/budget` still works while capped, and
     * `/compact` dispatches there too. Measured before this gate existed, a
     * session $5.00 into a $1.00 cap fired a full-conversation completion on the
     * provider's DEFAULT model — the biggest single prompt this app sends — and
     * the reported cost of it was then thrown away as well.
     *
     * Gating costs the user nothing but summary QUALITY, which is why gating was
     * the right answer here and refusing the command would not have been: null
     * from this method is the offline answer, so `/compact` still compacts, just
     * on the heuristic. The user is told which one ran and how to get the other
     * back. The alternative — letting the call through because compaction is what
     * frees context, so refusing it could corner a user whose only other exit is
     * `/clear` — argues against refusing the COMMAND, and nothing here refuses
     * the command.
     *
     * @return array{0:Chat,1:?\Closure}|null
     */
    private function scheduleModelCompaction(string $inputText): ?array
    {
        // Checked here as well as inside buildSummarizationRequest() because the
        // ORDER matters: with no provider at all there is nothing for the spend
        // cap to have prevented, so the offline answer must win over the
        // cap-reached notice below.
        if ($this->summaryBackend === null) {
            return null;
        }

        if ($this->spendCapReached()) {
            // Answered here rather than by returning null, because a silent
            // downgrade to the heuristic is indistinguishable from having no
            // provider at all — and the user set the ceiling that caused it, so
            // they are the one person who can lift it.
            return $this->compactNow(
                $inputText,
                $this->history,
                [],
                sprintf(
                    'Spend cap reached ($%.4f of $%.4f), so the model was not asked to summarise — '
                    . 'compacted with the local heuristic instead. Raise the cap with /budget %.2f '
                    . 'and run /compact again for model-written summaries. ',
                    $this->spentUsd(),
                    (float) $this->maxCostUsd,
                    $this->spentUsd() * 2,
                ),
            );
        }

        // The `/compact` line and the notice below form a PAIR, so the probe
        // stands the notice in as an empty assistant turn - see
        // buildSummarizationRequest() on why the role and position matter and the
        // content does not.
        $echoed = [...$this->history, Message::user($inputText)];
        $request = $this->buildSummarizationRequest([...$echoed, Message::assistant('')], null);
        if ($request === null) {
            return null;
        }

        $next = $this->mutate([
            'history' => [...$echoed, Message::assistant(
                'Summarising ' . $request['count'] . ' earlier '
                . ($request['count'] === 1 ? 'exchange' : 'exchanges')
                . ' with the model — the transcript will compact when they arrive.',
            )],
            'inputBuf' => '',
            'inFlight' => false,
            'pendingCompactionId' => $request['id'],
        ]);

        return [$next, $request['cmd']];
    }

    /**
     * The half of a model-written compaction that is the same on both routes:
     * decide whether there is anything to ask, and build the request.
     *
     * Shared rather than copied because the two routes' TRANSCRIPT and turn
     * semantics are genuinely different — `/compact` consumes the draft and
     * starts no turn, the 85% tier parks a turn it is about to start — while
     * "which exchanges would a compaction of this history condense, and what do
     * we send to get lines for them" is one question with one answer. Returning
     * the id and the count rather than a finished `Chat` is what lets each
     * caller write its own notice and its own `inFlight`.
     *
     * $probeHistory is the history the compaction will eventually run against —
     * the caller's current history PLUS every message it is about to append,
     * with an empty stand-in for the notice whose text is not known until this
     * returns a count. It must not be the pre-echo history: the appended
     * messages change the pair grouping, and the preserved tail is the last
     * `recentPreserveCount` PAIRS, so deriving from the pre-echo history left
     * the newest condensed exchange outside the offered set every time — one
     * exchange per compaction silently falling back to the `[exchanged
     * information]` placeholder however cooperative the model was.
     *
     * The stand-in's CONTENT does not matter (its pair is the newest, so it is
     * always inside the preserved tail) but its ROLE and POSITION do, because
     * those are what the grouping counts. That is why the caller supplies the
     * whole probe rather than this method appending a placeholder of a role only
     * one of the two routes uses.
     *
     * Null is the ordinary answer and not a failure: no {@see $summaryBackend}
     * (offline, either `$SUGARCRUSH_BACKEND_CMD*` shell-out, every unit test),
     * or nothing a model
     * could usefully be asked. Each caller then does what it did before this
     * existed — compact on the heuristic.
     *
     * @param list<Message> $probeHistory
     * @param ?string $parkedSubmission Rides onto the {@see HistoryCompactedMsg};
     *                                  see that parameter's docblock.
     * @return array{id:string,count:int,cmd:\Closure}|null
     */
    private function buildSummarizationRequest(array $probeHistory, ?string $parkedSubmission): ?array
    {
        $backend = $this->summaryBackend;
        if ($backend === null) {
            return null;
        }

        $wireHistory = array_map(
            static fn(Message $msg): array => $msg->toWire(),
            $probeHistory,
        );
        $exchanges = $this->compactor->exchangesToSummarize($wireHistory);
        if ($exchanges === []) {
            return null;
        }

        $compactionId = bin2hex(random_bytes(8));
        $prompt = [
            Message::system(self::COMPACT_SUMMARY_PROMPT),
            Message::user(self::renderExchangesForSummary($exchanges)),
        ];
        $keys = array_map(static fn(array $e): string => $e['key'], $exchanges);

        $cmd = Cmd::promise(
            static function () use ($backend, $prompt, $compactionId, $keys, $parkedSubmission): PromiseInterface {
                return $backend->completeAsync($prompt)->then(
                    // The usage rides along so update() can bill it. A compaction
                    // asks a model to read the WHOLE earlier conversation, so it is
                    // routinely the largest single prompt this app sends; a readout
                    // that silently omitted it was under-reporting its own biggest
                    // call.
                    static fn(Message $msg): ?Msg => new HistoryCompactedMsg(
                        $compactionId,
                        self::parseExchangeSummaries($msg->content, $keys),
                        null,
                        $msg->usage,
                        $parkedSubmission,
                    ),
                    // Reported, not swallowed: unlike the session-title call this
                    // rides beside, a failure here changes what the compaction
                    // PRESERVES, and the user is about to lose the originals. No
                    // usage on this path - a rejection hands back a Throwable, not a
                    // Message, so there is no figure to read and inventing a zero
                    // would claim the call was free.
                    //
                    // $parkedSubmission survives a failure on purpose: the turn the
                    // user pressed Enter for still has to go out, and it goes out
                    // against a heuristically-compacted history.
                    static fn(\Throwable $e): ?Msg => new HistoryCompactedMsg(
                        $compactionId,
                        [],
                        $e->getMessage(),
                        null,
                        $parkedSubmission,
                    ),
                );
            }
        );

        return ['id' => $compactionId, 'count' => count($exchanges), 'cmd' => $cmd];
    }

    /**
     * The automatic 85% tier's model route: echo the submitted prompt, park its
     * turn behind a summarization round-trip, and return the Cmd — or null when
     * there is no model route, in which case {@see submit()} compacts
     * synchronously on the heuristic exactly as it always did (crush_code.md
     * Phase 5 item 6).
     *
     * WHY THE TURN IS PARKED rather than sent against a heuristic compaction:
     * this tier is the one that actually fires in real use — nobody types
     * `/compact`, the session just fills up — so it is the tier whose losses the
     * user never chose. Until this existed, `/compact` asked the model and the
     * 85% tier did not, which meant the exchanges replaced by `[exchanged
     * information]` placeholders were precisely the ones nobody elected to
     * compact.
     *
     * `inFlight` IS SET TRUE HERE even though no backend turn has started, and
     * that is the point: the user pressed Enter, a turn is going to happen, and
     * {@see update()}'s blanket swallow is what stops a second one being
     * submitted on top of the parked one. Walking every arm above that swallow,
     * FOUR keys stay live in the parked window and only one of them touches the
     * parked turn:
     *
     *  - Ctrl+C quits;
     *  - PageUp and PageDown scroll the transcript — they sit above the swallow
     *    ({@see update()}, the scroll arm) and were mis-stated here as swallowed.
     *    Driven, `PageUp` during the parked window moves `scrollOffset` 0 -> 18;
     *  - the double-Escape cancel arm abandons the turn.
     *
     * The permission prompt and the keybinding reference sit above the swallow
     * too, but neither can be up here — no backend turn has run, so nothing has
     * asked for permission, and the reference only opens from an idle turn.
     *
     * So the conclusion the design rests on is unchanged, and it is about
     * ABANDONMENT rather than about how many keys are live: scrolling cannot
     * abandon a parked turn, so the cancel arm remains the ONE route that can,
     * which is why it releases `$pendingCompactionId` and why `/clear`,
     * `/rewind` and the palette's New session action needed no change — none of
     * those three is reachable here.
     *
     * `$generation` is NOT bumped and no {@see CancellationToken} is created:
     * both belong to a backend turn, and there is not one yet. They are created
     * by {@see dispatchTurn()} when the compaction lands.
     *
     * $tokenCount is ESTIMATED tokens (chars/4 + 10 per message) of the
     * PRE-compaction history and $tokenLimit is the PROVIDER-COUNTED window, the
     * same two figures {@see submit()} read for the heuristic notice; the notice
     * below names the unit of each because they are not the same kind of number.
     *
     * THE SPEND CAP is checked here and the check is UNASSERTED DEFENCE — said
     * plainly rather than left to look covered, because no test drives it and a
     * mutation deleting it survives the suite. Measured, {@see submit()} runs
     * {@see spendCapRefusal()} before this tier, so a capped session's ordinary
     * prompt is refused outright and never reaches the 85% block at all. It is
     * kept because the gate belongs to the provider call rather than to the
     * caller's ordering, and null (the heuristic) is the same answer the offline
     * path gives — unlike {@see scheduleModelCompaction()}, which says so out
     * loud because `/compact` genuinely does reach its own check past the
     * refusal. That asymmetry is itself a risk if the ordering upstream ever
     * changes (a capped session would take the lossier path in silence); backlog
     * §E31 records the safer dormant shape.
     *
     * The cap that CAN fire on this route fires at the other end of it, in
     * {@see applyModelCompaction()}: the summarization is billed, so it can be
     * the call that crosses the cap, and the parked turn must not be dispatched
     * once it has.
     *
     * @param string $inputText The submitted prompt, echoed now and dispatched
     *                          when the {@see HistoryCompactedMsg} lands.
     * @param int $tokenCount ESTIMATED tokens in the pre-compaction history.
     * @param int $tokenLimit PROVIDER-COUNTED window from {@see contextTokenLimit()}.
     * @return array{0:Chat,1:?\Closure}|null
     */
    private function scheduleParkedCompaction(string $inputText, int $tokenCount, int $tokenLimit): ?array
    {
        // Unreachable from submit() today - see the docblock. Null, not a notice:
        // with the turn itself already refused upstream there is no compaction to
        // announce a downgrade of.
        if ($this->spendCapReached()) {
            return null;
        }

        // NOTICE FIRST, PROMPT LAST, and both the order and the roles are
        // load-bearing. All three facts below were measured:
        //
        //  * NOTHING AFTER THE PROMPT RENDERS AS AN ASSISTANT TURN. That is the
        //    property, stated as what it is: the history is NOT guaranteed to end
        //    on the user's line, on this route or on submit()'s, and an earlier
        //    revision of this comment claimed it was. Measured parked wire:
        //    `[..., system park notice, user prompt, system tier report,
        //    system 70% reminder]`, so the LAST role is `system` — and
        //    submit()'s synchronous route ends on `system` too whenever the
        //    reminder fires. What must never sit after the prompt is a
        //    Role::Assistant message, because that is a PREFILL the provider
        //    continues instead of an instruction it reads:
        //    {@see Backend\EngineBackend::toTypedMessages()} maps Role::Assistant
        //    to an AssistantMessage and Role::System to a SystemMessage, and
        //    {@see Providers\VertexProvider}'s Anthropic path renders the first
        //    as an `assistant` turn while hoisting the second out of `messages`
        //    entirely. The landing report is Role::System for this same reason.
        //  * Role::System for the notice, like the two notices this tier already
        //    emits ({@see contextCompactedMessage()},
        //    {@see contextReminderMessage()}): it is the app reporting on itself.
        //  * The notice goes BEFORE the prompt because it reports on history that
        //    already existed, which is the same asymmetry the synchronous route
        //    has ({@see contextCompactedMessage()}'s docblock). It no longer has
        //    to go there to SURVIVE: {@see Context\ContextCompactor}'s pair
        //    grouping used to drop a non-user/non-assistant message that directly
        //    followed a user turn, which is why an earlier revision of this
        //    comment called the position load-bearing for durability — and the
        //    tier report this route appends AFTER the prompt was being erased by
        //    the very next compaction as a result. That is fixed in the grouping
        //    itself ({@see Context\ContextCompactor::groupIntoPairs()}, which now
        //    carries such a message on the open pair), because two other victims
        //    were not app notices at all and could not be moved: the 70% reminder
        //    and `_Request cancelled._`.
        //
        // Echoed BEFORE the request leaves, so the prompt is never invisible
        // while the round-trip is out and never unrecoverable if the round-trip
        // is abandoned - compare the synchronous route, where it appears the
        // instant Enter is pressed. The probe below mirrors this exact shape,
        // empty notice and all, because the grouping counts roles and positions
        // and not content.
        $request = $this->buildSummarizationRequest(
            [...$this->history, Message::system(''), Message::user($inputText)],
            $inputText,
        );
        if ($request === null) {
            return null;
        }

        $next = $this->mutate([
            // Kept short on purpose: {@see view()} paints a transcript message as
            // one unwrapped row (backlog §E22), so every character past the frame
            // width is an over-wide line. This is not the app's worst case - the
            // 95% refusal is 423 characters and the idle advisory 391 - but it is
            // a new message and there is no reason for it to join them.
            'history' => [...$this->history, Message::system(sprintf(
                'Context reached the automatic-compaction tier at ~%d estimated tokens of a '
                . '%d-token context window. Summarising %d earlier %s with the model first; '
                . 'the turn goes out when they land.',
                $tokenCount,
                $tokenLimit,
                $request['count'],
                $request['count'] === 1 ? 'exchange' : 'exchanges',
            )), Message::user($inputText)],
            'inputBuf' => '',
            'inFlight' => true,
            'pendingCompactionId' => $request['id'],
            'lastActivityAt' => new \DateTimeImmutable(),
            // Belt-and-braces, same rule dispatchTurn() follows: whatever is
            // about to be sent starts from a blank partial and a blank thought.
            'streamingText' => '',
            'reasoningText' => '',
        ]);

        return [$next, $request['cmd']];
    }

    /**
     * The user-role half of the summarization request: the exchanges, numbered,
     * in the order {@see Context\ContextCompactor::exchangesToSummarize()}
     * returned them, so the model's "1." lines map back by position.
     *
     * @param list<array{key:string,user:string,assistant:string}> $exchanges
     */
    private static function renderExchangesForSummary(array $exchanges): string
    {
        $out = [];
        foreach ($exchanges as $i => $exchange) {
            $n = $i + 1;
            $out[] = "### Exchange {$n}\nUser: {$exchange['user']}\nAssistant: {$exchange['assistant']}";
        }

        return implode("\n\n", $out);
    }

    /**
     * Turn the model's numbered reply into the key => summary map
     * {@see Context\ContextCompactor::withExchangeSummaries()} wants.
     *
     * Positional: line "3." belongs to $keys[2], because that is the order
     * {@see renderExchangesForSummary()} presented them in. A number outside the
     * range, a duplicate, or a missing line is simply not mapped — the exchange
     * then falls back to the heuristic, which is why a partially-obeyed
     * instruction degrades instead of mis-attributing a summary.
     *
     * Every line is flattened and bounded before it is kept. This is model-
     * authored text bound for the transcript AND for the next prompt: a raw ESC
     * could repaint the chrome around it, embedded newlines would break the
     * one-summary-per-message shape stage 3's grouping relies on, and an
     * unbounded line would let a "compaction" be larger than what it replaced.
     *
     * @param list<string> $keys
     * @return array<string, string>
     */
    private static function parseExchangeSummaries(string $reply, array $keys): array
    {
        $summaries = [];
        foreach (preg_split('/\R/', $reply) ?: [] as $line) {
            if (preg_match('/^\s*(\d+)\s*[.)]\s*(.+)$/u', $line, $m) !== 1) {
                continue;
            }
            $index = (int) $m[1] - 1;
            if ($index < 0 || !isset($keys[$index]) || isset($summaries[$keys[$index]])) {
                continue;
            }
            $text = self::sanitizeSummaryLine($m[2]);
            if ($text === '') {
                continue;
            }
            $summaries[$keys[$index]] = $text;
        }

        return $summaries;
    }

    /**
     * Longest model-written summary line kept, in characters.
     *
     * Matches the ceiling {@see COMPACT_SUMMARY_PROMPT} asks the model for, so
     * the bound the instruction states and the bound the code enforces are one
     * number rather than two that can drift apart.
     */
    private const SUMMARY_LINE_MAX_CHARS = 200;

    /**
     * One bounded, control-byte-free line — same treatment
     * {@see Message::describeToolCall()} gives a model-authored tool label, and
     * for the same two reasons: the text is untrusted, and it is going somewhere
     * that assumes one line.
     */
    private static function sanitizeSummaryLine(string $text): string
    {
        $flattened = preg_replace('/[\p{C}\s]+/u', ' ', $text);
        if ($flattened === null) {
            // Invalid UTF-8 makes the /u pattern bail; strip byte-wise instead
            // so malformed input can never smuggle control bytes into a frame.
            $flattened = preg_replace('/[[:cntrl:]\s]+/', ' ', $text) ?? '';
        }
        $flattened = trim($flattened);
        if (mb_strlen($flattened) > self::SUMMARY_LINE_MAX_CHARS) {
            $flattened = mb_substr($flattened, 0, self::SUMMARY_LINE_MAX_CHARS - 1) . '…';
        }

        return $flattened;
    }

    /**
     * Apply the compaction the model's summaries were fetched for.
     *
     * Runs against the history as it stands NOW, not as it stood when `/compact`
     * was typed: the call was fire-and-forget, so the transcript may have GROWN
     * (a background notice, another whole turn) or SHRUNK (an automatic
     * compaction tier fired as that turn was dispatched) in the meantime. Growth
     * is harmless — the new messages are the newest exchanges, which a
     * compaction preserves in full. Shrinkage is harmless for the same reason
     * plus one more: summaries are keyed by exchange CONTENT, so a shifted or
     * shortened history cannot mis-attach them; the ones whose exchange is gone
     * simply go unused and those exchanges fall back to the heuristic.
     *
     * WHOLESALE REPLACEMENT is the case neither of those covers, and it is not
     * handled here — it is handled by never reaching here. `/rewind` and the
     * palette's New session action both put a transcript in place that the user
     * did not just ask to compact, so both release `$pendingCompactionId` and
     * this message is dropped by {@see update()}'s latch check instead. Measured
     * before that fix: a `/rewind` with a summarization outstanding compacted
     * the freshly-restored transcript, replacing five recovered exchanges with
     * `[exchanged information]` placeholders — the summaries did not even apply,
     * because they were keyed to the content the rewind had just discarded.
     *
     * Nothing about the user's draft or about `inFlight` is touched ON THE
     * `/compact` ROUTE ($msg->parkedSubmission === null): see
     * {@see compactionChanges()} for why that separation is the whole point of
     * this method not calling {@see compactNow()}. On the 85% tier's PARKED route
     * both are settled here by design, because there the compaction is the thing
     * a submitted turn was waiting on - see below.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function applyModelCompaction(HistoryCompactedMsg $msg): array
    {
        $prefix = '';
        if ($msg->error !== null) {
            $prefix = 'Model summarisation failed (' . self::sanitizeSummaryLine($msg->error)
                . ') — compacted with the local heuristic instead. ';
        } elseif ($msg->summaries === []) {
            $prefix = 'The model returned no usable summaries — compacted with the local heuristic instead. ';
        }

        // The '/compact' line - or, on the parked route, the user's prompt - is
        // already in the transcript from the scheduling pass, so this must not
        // append a second one.
        if ($msg->parkedSubmission === null) {
            return [$this->mutate($this->compactionChanges('', $this->history, $msg->summaries, $prefix)), null];
        }

        $compacted = $this->mutate($this->compactionChanges('', $this->history, $msg->summaries, $prefix, true));

        // From here on this is the 85% tier's continuation, not `/compact`:
        // {@see scheduleParkedCompaction()} echoed a prompt and held `inFlight`
        // true for a turn that has not been sent yet, and this is where it is
        // sent.
        //
        // THE SPEND CAP IS RE-CHECKED FIRST, and it is not the check
        // {@see submit()} already ran: the summarization above is itself a billed
        // provider call, its usage was accounted by {@see update()} moments ago,
        // and it can be the call that crosses the cap. Without this, a cap of
        // $1.00 crossed at $1.10 by the summary dispatched the parked turn anyway
        // while a freshly typed prompt at the same spend was refused - i.e. the
        // one route that starts a turn without passing spendCapRefusal() was the
        // one route that could start it over budget. This is NOT the documented
        // "the turn that crosses the cap runs to completion" allowance either:
        // there the crossing happens inside a turn already under way and there is
        // nothing to interrupt, whereas here the crossing has already happened in
        // a previous update() and the app would be electing to start a fresh
        // chargeable turn with the cap known to be breached.
        //
        // Checked ahead of the 95% tier because the two refusals say different
        // things about what to do next - the blocking one says "re-send and it
        // will get through after a pass or two", which is false while the cap
        // stands - and because money outranks context.
        // Both of this route's refusals END a turn — {@see scheduleParkedCompaction()}
        // held `inFlight` true with nothing running, and these are the writes that
        // release it — so both drain, for the same reason the permission denial
        // above does. A parked window is mid-turn from the keyboard's point of
        // view (measured: the swallow this bundle split left only Ctrl+C and
        // double-Escape live there), so a queue can absolutely have accumulated
        // across it.
        if ($compacted->spendCapReached()) {
            return self::releaseQueuedPrompts($compacted->spendCapTurnRefusal(
                'The summarization this turn was parked behind is what reached the cap; that call went out '
                . 'before the cap was met and is billed. Your prompt is in the transcript above, unsent.'
            ));
        }
        //
        // The 95% blocking tier is re-tested HERE rather than in {@see submit()}
        // because on this route the compaction happened in a different update()
        // call, so the compacted history the check has to judge only exists now.
        // Its ordering semantics are the ones submit() uses: blocking is tested
        // AFTER compaction has been given its chance, because "blocked until
        // space is freed" only means something once the automatic way of freeing
        // it has been tried. The wire it judges is the whole post-compaction
        // history INCLUDING the echoed prompt and the notices - which is what is
        // actually about to go to the provider, and so is the honest thing to
        // measure, even though submit()'s synchronous route judges its
        // pre-echo equivalent.
        $tokenLimit = $this->contextTokenLimit();
        $compactedWire = array_map(
            static fn(Message $m): array => $m->toWire(),
            $compacted->history
        );
        if ($compacted->compactor->shouldCompactForeground($compactedWire, $tokenLimit)) {
            // '' rather than the prompt: the echo is already in history, and the
            // refusal must not put a second copy of it there. $compactionNotice
            // is left null for the same reason - the rewrite this refusal has to
            // report is ALREADY reported, by the contextCompactedMessage() line
            // compactionChanges() wrote into $compacted->history above.
            return self::releaseQueuedPrompts($compacted->foregroundBlockedResponse(
                '',
                $compacted->history,
                $compacted->estimateTokenCount($compacted->history),
                $tokenLimit,
            ));
        }

        // No new user message: the echo went in at park time. Everything else a
        // turn needs - generation, cancellation token, checkpoint, titler - is
        // dispatchTurn()'s, which is the same code submit() runs.
        return $compacted->dispatchTurn($compacted->history, [], $tokenLimit);
    }

    /**
     * Handle /sessions command — OPEN the live {@see SessionPicker} overlay
     * (crush_feat.md section 5 E8).
     *
     * Until E8 this folded the picker's first frame into an assistant turn,
     * so the widget's ↑/↓/Enter/Space/Ctrl+B keyboard surface was rendered
     * but unreachable — a screenshot of a picker, not a picker. It now
     * latches a real instance on {@see $sessionPicker}; {@see update()}
     * routes every subsequent keystroke into it via
     * {@see handleSessionPickerKey()} and {@see Renderer::render()}
     * composites it through the same {@see \SugarCraft\Veil\Veil} slot the
     * Ctrl+P palette uses.
     *
     * The assistant line is deliberately a one-line hint rather than the
     * rendered picker: the overlay is what the user is looking at, and
     * duplicating it into the scrollback would leave a stale copy behind
     * once the selection moves.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleSessionsCommand(string $inputText): array
    {
        if ($this->sessionStore === null) {
            return $this->sessionResponse($inputText, 'Session store not configured. Set a SessionStore to use /sessions.');
        }

        $picker = $this->buildSessionPicker();
        if ($picker === null) {
            return $this->sessionResponse($inputText, 'No sessions recorded yet.');
        }

        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputText), Message::assistant(
                'Session picker open — ↑/↓ browse, ↵ resume, space preview, esc close.',
            )],
            'inputBuf' => '',
            'inFlight' => false,
            'sessionPicker' => $picker,
        ]);

        return [$next, null];
    }

    /**
     * Build a {@see SessionPicker} over every row
     * {@see SessionStore::listSessions()} currently returns, or null when
     * there is no store or no session to pick.
     *
     * Null (rather than an empty picker) is what keeps Ctrl+R from opening
     * a modal the user cannot do anything with; both call sites treat it as
     * "don't open".
     *
     * The row text is scrubbed here, at the boundary, because the picker's
     * own output reaches the screen verbatim: {@see Renderer} composites the
     * widget's already-styled frame and cannot re-sanitize it without
     * destroying SessionPicker's legitimate SGR. Session names are model
     * output on the live path ({@see scheduleTitleGeneration()} auto-titles
     * via the backend), so they must not be trusted — see
     * {@see sanitizeSessionField()}.
     */
    private function buildSessionPicker(): ?SessionPicker
    {
        if ($this->sessionStore === null) {
            return null;
        }

        $rows = $this->sessionStore->listSessions();
        if ($rows === []) {
            return null;
        }

        $sessions = array_map(
            static function (array $row): array {
                $id = (string) ($row['id'] ?? '');
                $name = self::sanitizeSessionField((string) ($row['name'] ?? ''));

                return [
                    'sessionId' => $id,
                    'sessionName' => $name !== '' ? $name : $id,
                    'summary' => self::sanitizeSessionField((string) ($row['system_prompt'] ?? '')),
                    'gitBranch' => null,
                    'lastActivity' => (string) ($row['updated_at'] ?? ''),
                ];
            },
            $rows,
        );

        return SessionPicker::new($sessions);
    }

    /**
     * Neutralize one stored session string before it is painted into the
     * picker overlay.
     *
     * The same pair {@see Renderer}'s own `untrusted()` composes:
     * `Sanitize::untrusted()` for ANSI/C0/C1/DEL, then a Private-Use-Area
     * strip. The second half is the security half — `U+E000`/`U+E001` are
     * well-formed UTF-8 that `Sanitize::untrusted()` leaves alone, and
     * {@see sanitizeSessionTitle()} does not remove either, so a
     * model-chosen title could otherwise smuggle {@see \SugarCraft\Mouse\Mark}
     * zone sentinels into the frame and register attacker-chosen hit boxes
     * in the registry {@see zoneAt()} reads. The whole U+E000–U+F8FF block
     * goes, not just the two sentinel codepoints: nothing in it is
     * meaningful in a session name, and a narrower strip would have to be
     * revisited every time Mark's marker encoding grows.
     *
     * The `/u` pattern refuses to run on invalid UTF-8 (returns null), which
     * would fail open on exactly the malformed input an attacker controls,
     * so the null branch still removes the two sentinel byte sequences
     * verbatim rather than handing the text back untouched.
     */
    private static function sanitizeSessionField(string $text): string
    {
        $text = Sanitize::untrusted($text);

        return preg_replace('/[\x{E000}-\x{F8FF}]/u', '', $text)
            ?? str_replace([Sentinel::OPEN, Sentinel::CLOSE], '', $text);
    }

    /**
     * Route one keystroke into the open session picker (crush_feat.md
     * section 5 E8).
     *
     * Translates the {@see KeyMsg} into the key names
     * {@see SessionPicker::handleKey()} already understands and acts on the
     * action it reports back:
     *
     * - `browse` — keep the navigated picker.
     * - `resume` — switch {@see currentSessionId()} to the highlighted row
     *   and close the overlay.
     * - `preview` — no state change: the picker's own footer already shows
     *   the selected session's summary, so Space is a deliberate no-op that
     *   simply leaves the overlay up.
     * - `close` / `null` — Escape closes; anything the widget does not bind
     *   is swallowed rather than falling through to `inputBuf`, so a stray
     *   letter cannot type into a chat box the user cannot see.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleSessionPickerKey(KeyMsg $msg): array
    {
        $picker = $this->sessionPicker;
        if ($picker === null) {
            return [$this, null];
        }

        [$next, $action] = $picker->handleKey(self::sessionPickerKeyName($msg));

        return match ($action) {
            'browse' => [$this->mutate(['sessionPicker' => $next]), null],
            // Ctrl+R opens the picker mid-turn and ↑/↓/space browse it, but
            // resuming adopts another session's history and id wholesale — the
            // running turn's transcript replaced under it — so mid-turn that one
            // action is refused with a notice naming it. Same rule as the
            // palette's; see {@see runSelectedPaletteActionWhileInFlight()}.
            'resume' => $this->inFlight
                ? $this->refuseInFlightAction('Resume session')
                : $this->resumeSelectedSession($next),
            'preview' => [$this->mutate(['sessionPicker' => $next]), null],
            'close' => [$this->mutate(['sessionPicker' => null]), null],
            default => [$this, null],
        };
    }

    /**
     * Map a {@see KeyMsg} onto the key name
     * {@see SessionPicker::handleKey()} matches against.
     *
     * Only the widget's own bindings are translated; everything else
     * becomes a name it does not bind, which it answers with a null action.
     */
    private static function sessionPickerKeyName(KeyMsg $msg): string
    {
        return match (true) {
            $msg->type === KeyType::Up => 'up',
            $msg->type === KeyType::Down => 'down',
            $msg->type === KeyType::Enter => 'enter',
            $msg->type === KeyType::Space => ' ',
            $msg->type === KeyType::Escape => 'escape',
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'b' => 'ctrl+b',
            // j/k only when unmodified - Ctrl+K is a shell chord.
            $msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt => $msg->rune,
            default => '',
        };
    }

    /**
     * Adopt the picker's highlighted row as the current session and close
     * the overlay.
     *
     * `currentSessionName` is re-read from the store rather than taken from
     * the picker row, whose `sessionName` falls back to the raw id for
     * display: latching that id would look like a user-set title and
     * suppress the auto-titling pass in
     * {@see scheduleTitleGeneration()}, which skips any Chat that already
     * has a `currentSessionName`.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function resumeSelectedSession(SessionPicker $picker): array
    {
        $selected = $picker->selectedSession();
        if ($selected === null || $this->sessionStore === null) {
            return [$this->mutate(['sessionPicker' => null]), null];
        }

        $sessionId = $selected['sessionId'];
        $name = null;
        foreach ($this->sessionStore->listSessions() as $row) {
            if ((string) ($row['id'] ?? '') === $sessionId) {
                $stored = (string) ($row['name'] ?? '');
                $name = $stored !== '' ? $stored : null;
                break;
            }
        }

        return [$this->mutate([
            'sessionPicker' => null,
            'currentSessionId' => $sessionId,
            'currentSessionName' => $name,
            'history' => [...$this->history, Message::system(
                '_Resumed session ' . ($name ?? $sessionId) . '._',
            )],
        ]), null];
    }

    /**
     * The open session picker overlay, or null when it is closed.
     *
     * {@see Renderer::render()} reads this to decide whether to composite
     * the picker over the frame.
     */
    public function sessionPicker(): ?SessionPicker
    {
        return $this->sessionPicker;
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
        // then a synthesised stand-in.
        //
        // The middle arm is what a real launch takes now. Until crush_code.md
        // Phase 1 item 1, Bootstrap::chat() passed no AgentManager, so every
        // `/bg` and `/fork` fell through to defaultBackgroundAgent() — and
        // BackgroundSupervisor::spawnSession() feeds $agent->provider and
        // $agent->model straight into the daemon's command line, so those
        // daemons were launched with the literal strings "unknown"/"unknown".
        // Registering the roster fixed that as a side effect: the session's
        // real provider/model now reach the child. That is a live behaviour
        // change, so it is pinned by
        // ChatTest::testBackgroundSpawnRunsTheDaemonAsARosterAgentNotTheUnknownStandIn()
        // rather than left to be silently undone by unwiring the manager.
        //
        // The stand-in stays for embedders that construct a Chat with no
        // manager: refusing to background anything without one would leave
        // this command as unreachable as the supervisor it drives.
        $agent = $this->agentManager?->get('default')
            ?? ($this->agentManager?->all()[0] ?? null)
            ?? self::defaultBackgroundAgent();
        // The session is spawned into the SAME tree this run is rooted at:
        // a `--root <lib>` run that backgrounded work into the enclosing
        // monorepo would have the child acting outside the parent's jail.
        $workingDirectory = $this->projectRoot() ?: '.';
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
                // An outstanding `/compact` summarization is ABANDONED, for the
                // same reason `/clear` abandons one: the transcript it was
                // fetched for is no longer on screen. This one is the sharper
                // case of the two — measured, a summary landing after a rewind
                // compacted the transcript the user had just RECOVERED, and
                // since the summaries were keyed to the discarded content none
                // of them applied, so five restored exchanges came back as
                // `[exchanged information]` placeholders. See
                // {@see applyModelCompaction()}.
                'pendingCompactionId' => null,
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
        //
        // "Every CHANGE" is the honest scope, and the guard is what makes it
        // one: a write of the string already in the box leaves the match set
        // alone, so it must leave the selection alone too. Same rule, same
        // reason, as {@see withInput()}'s - where the case that matters is a
        // cursor move.
        $changes = ['inputBuf' => $buf];
        if ($buf !== $this->inputBuf) {
            $changes['slashMenuIndex'] = 0;
        }

        return $this->mutate($changes);
    }

    /**
     * A blank, focused draft editor.
     *
     * **TextArea, not TextInput, and that is measured rather than assumed.**
     * `candy-forms` ships both; `TextInput` is single-line. This box is not:
     * the Alt/Shift/Ctrl+Enter arm in {@see update()} inserts a newline, and
     * {@see reviveCheckpointMessage()} can put a multi-line tool row in the
     * box via the Up arm. Driven before choosing — a two-line draft
     * ("ab", Alt+Enter, "cd") rendered through {@see Renderer::renderInput()}
     * paints a genuine TWO-ROW bordered box, so multi-line drafts are a live,
     * visible feature and not a latent one. On `TextInput` the cursor is a
     * single flat offset with no notion of rows, so Home/End would jump to
     * the ends of the whole draft rather than of the line the user is on, and
     * Up/Down would mean nothing on a draft that visibly has rows.
     *
     * Focused at construction because {@see TextArea::update()} returns the
     * receiver unchanged for every `KeyMsg` while blurred — an unfocused
     * widget here would silently swallow all typing. The Cmd `focus()`
     * returns (the cursor-blink tick) is deliberately dropped: Chat paints
     * its own block cursor in {@see Renderer::renderInput()} and never calls
     * {@see TextArea::view()}, so a blink subscription would drive redraws
     * for a cursor nothing reads.
     *
     * `withCharLimit(0)` restores TextArea's pre-limit unbounded behaviour.
     * Its 65536 default is a paste-DoS guard, and this box has no paste path
     * (a `PasteMsg` is dropped by {@see update()}), but it DOES receive
     * arbitrarily long revived checkpoint rows through the Up arm — a cap
     * would silently truncate one, which is a feature loss the previous
     * hand-rolled string did not have.
     *
     * Two collisions the plan for this change flagged are DISSOLVED by this
     * choice rather than resolved by policy, and both are properties of
     * TextArea that TextInput does not share:
     *
     *   * **History has one owner.** `TextInput` carries `withHistory()`/
     *     `addToHistory()` and binds Up/Down to it, which would have fought
     *     Chat's own recall (the Up-on-empty arm in {@see update()}, and
     *     {@see reviveCheckpointMessage()}'s checkpoint revival). TextArea has
     *     no history field at all — measured, `grep -n history` over
     *     `candy-forms/src/TextArea/TextArea.php` returns nothing — so Chat
     *     stays the sole owner and there is no second mechanism to disable.
     *   * **Completion has one owner.** Likewise `setSuggestions()`/
     *     `currentSuggestion()` exist only on `TextInput`. The "/" popup keeps
     *     writing through {@see withInputBuf()}, unchanged — as do the four
     *     other whole-draft writers, which is the complete list of that
     *     method's callers (`grep -n 'withInputBuf('`): the Up-recall arm, the
     *     Ctrl+A `/agents` dispatch, the keyHelp `?` append, and `/keys`
     *     clearing the box. The palette is NOT among them: it has no
     *     fill-on-select at all — its selections run actions, and its own
     *     query buffer is a separate string this widget never sees.
     */
    private static function freshInput(): TextArea
    {
        [$focused] = TextArea::new()->withCharLimit(0)->focus();
        \assert($focused instanceof TextArea);

        return $focused;
    }

    /**
     * Replace the draft's editor, keeping its cursor.
     *
     * The `input` key on {@see mutate()} is the "the widget edited itself"
     * write, as against `inputBuf`'s "replace the whole draft".
     *
     * The slashMenuIndex reset is conditional on the TEXT having changed, and
     * that condition is the whole point of the guard rather than an
     * optimisation. This is the every-keystroke route, so it is also the route
     * a pure cursor MOVE takes — and a move does not change the filtered match
     * set, so re-highlighting the top match would silently throw away a
     * selection the user made with ↑/↓ and send Enter to the wrong entry
     * ("/" then two Downs then Left used to land back on index 0). When the
     * text does change the reset is exactly {@see withInputBuf()}'s: a stale
     * index from a differently filtered list must not leak into the new one.
     */
    private function withInput(TextArea $input): self
    {
        $changes = ['input' => $input];
        if ($input->value() !== $this->inputBuf) {
            $changes['slashMenuIndex'] = 0;
        }

        return $this->mutate($changes);
    }

    /**
     * Hand one keystroke to the draft editor.
     *
     * Both call sites guard on `!$msg->ctrl` and they are the only two — a
     * ctrl-flagged key must never arrive here, because `TextArea::update()`
     * answers ctrl from its own five-rune table and DROPS everything else,
     * which turns a delegated ctrl chord into a dead key rather than an error.
     * See the guard's comment at the foot of {@see update()} for the two that
     * died that way and where they live now.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function delegateToInput(KeyMsg $msg): array
    {
        [$next] = $this->input->update($msg);
        \assert($next instanceof TextArea);

        return [$this->withInput($next), null];
    }

    /**
     * Where the cursor is in {@see $inputBuf}, as a character offset from
     * the start of the whole draft (newlines counted as one character each).
     *
     * Flat rather than (row, column) because every caller — Ctrl+W's word
     * boundary, {@see Renderer::renderInput()}'s cursor glyph,
     * {@see App\App::clearInputKeys()}'s synthetic clear — reasons about the
     * draft as one string, which is also the shape the checkpoint state map
     * and every slash-command parser see.
     */
    public function inputCursorOffset(): int
    {
        $offset = 0;
        foreach (explode("\n", $this->inputBuf) as $row => $line) {
            if ($row >= $this->input->line()) {
                break;
            }
            $offset += mb_strlen($line, 'UTF-8') + 1;
        }

        return $offset + $this->input->column();
    }

    /**
     * Move the draft's cursor to a flat character offset (clamped to the
     * draft), leaving the text alone.
     */
    private function withInputCursor(int $offset): self
    {
        return $this->withInput(self::seekInput($this->input, $offset));
    }

    /** Map a flat character offset onto the widget's (row, column) cursor. */
    private static function seekInput(TextArea $input, int $offset): TextArea
    {
        $offset = max(0, $offset);
        foreach (explode("\n", $input->value()) as $row => $line) {
            $len = mb_strlen($line, 'UTF-8');
            if ($offset <= $len) {
                return $input->setCursor($row, $offset);
            }
            $offset -= $len + 1;
        }

        return $input->moveToEnd();
    }

    /**
     * The offset one word to the LEFT of the cursor.
     *
     * Deliberately the same boundary {@see dropLastWord()} uses, applied to
     * the draft up to the cursor rather than to the whole draft, so Ctrl+W
     * and Alt+Left cannot disagree about where a word starts. TextArea has no
     * word MOTION to delegate to — measured over
     * `vendor/sugarcraft/candy-forms/src/TextArea/TextArea.php`, its only
     * word-aware member is the public `word()` (the run of non-whitespace
     * under the cursor, a reader with no cursor effect), and it has no vim
     * mode at all: `vimWordForward()`/`vimWordBackward()`/`$vimMode` live in
     * the sibling `TextInput.php`, which this box does not use. So Chat
     * keeps ownership of the boundary and drives the widget's public
     * {@see TextArea::setCursor()} with it. `\s` in that pattern includes
     * "\n", so word motion crosses a line break rather than sticking at
     * column 0.
     */
    private function wordLeftOffset(): int
    {
        $at = $this->inputCursorOffset();
        $before = mb_substr($this->inputBuf, 0, $at, 'UTF-8');

        return mb_strlen(self::dropLastWord($before), 'UTF-8');
    }

    /**
     * The offset one word to the RIGHT of the cursor — past any whitespace
     * under it, then past the run of non-whitespace after that. Trailing
     * whitespace with no word behind it moves to the end of the draft, which
     * is {@see wordLeftOffset()}'s mirror image (`dropLastWord()` on a
     * whitespace-only prefix collapses it to 0).
     */
    private function wordRightOffset(): int
    {
        $at = $this->inputCursorOffset();
        $after = mb_substr($this->inputBuf, $at, null, 'UTF-8');
        if (preg_match('/^\s*[^\s]+/u', $after, $m) !== 1) {
            return mb_strlen($this->inputBuf, 'UTF-8');
        }

        return $at + mb_strlen($m[0], 'UTF-8');
    }

    /**
     * Ctrl+W / Alt+Backspace: drop the word before the cursor and leave the
     * cursor where that word started, keeping everything after it.
     *
     * Before the cursor existed this was `dropLastWord($inputBuf)` — the
     * tail of the WHOLE draft. This is a strict generalisation: with the
     * cursor at the end (which is where every seed, recall and completion
     * leaves it) the two agree byte for byte, and mid-draft this one no
     * longer eats text the user has already moved past.
     */
    private function deleteInputWordBefore(): self
    {
        $at = $this->inputCursorOffset();
        $kept = self::dropLastWord(mb_substr($this->inputBuf, 0, $at, 'UTF-8'));
        $tail = mb_substr($this->inputBuf, $at, null, 'UTF-8');

        return $this->withInput(self::seekInput(
            $this->input->setValue($kept . $tail),
            mb_strlen($kept, 'UTF-8'),
        ));
    }

    /**
     * Ctrl+Delete: drop the word AFTER the cursor and leave the cursor where
     * it was, keeping everything before it.
     *
     * The mirror of {@see deleteInputWordBefore()}, and it shares the boundary
     * with word motion the same way that one does — {@see wordRightOffset()}
     * here, {@see wordLeftOffset()} there — so a forward word-delete can never
     * take a different amount of text than a forward word-move skips over.
     */
    private function deleteInputWordAfter(): self
    {
        $at = $this->inputCursorOffset();
        $kept = mb_substr($this->inputBuf, 0, $at, 'UTF-8');
        $tail = mb_substr($this->inputBuf, $this->wordRightOffset(), null, 'UTF-8');

        return $this->withInput(self::seekInput($this->input->setValue($kept . $tail), $at));
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
        $prefix = $this->slashMenuPrefix();

        return $prefix === null ? [] : CommandRegistry::filter($prefix, $this->slashCommandRows());
    }

    /**
     * Every row the "/" popup and `/help` may list: the built-in slash-visible
     * rows with this session's file-based commands merged over them by NAME.
     *
     * OVERRIDE, NOT APPEND, and by name rather than by position, because that is
     * the tiering {@see CommandLoader::loadAll()} already performs and this is
     * the surface it was performed for: a project `compact.md` replaces the
     * built-in `/compact` row instead of producing a second `/compact` the user
     * has to choose between. {@see submit()} honours the same precedence — it
     * consults the file-based map BEFORE {@see dispatchCommand()} — so the row a
     * user picks in the popup is the one that runs. If those two disagreed, the
     * popup would be advertising a command that cannot be reached.
     *
     * Rebuilt per call rather than cached: it is two array walks over ~25 rows,
     * and the alternative is a third copy of the same list that can go stale
     * against {@see $customCommands}.
     *
     * @return list<CommandSpec>
     */
    public function slashCommandRows(): array
    {
        $byName = [];
        foreach (CommandRegistry::slashCommands() as $spec) {
            $byName[$spec->name] = $spec;
        }

        foreach ($this->customCommands as $name => $spec) {
            if ($spec->slashVisible) {
                $byName[$name] = $spec;
            }
        }

        return array_values($byName);
    }

    /**
     * The file-based commands this session discovered, name => spec.
     *
     * @return array<string, CommandSpec>
     */
    public function customCommands(): array
    {
        return $this->customCommands;
    }

    /**
     * {@see slashMenuMatches()}'s rows with their matched-character indices
     * kept, in the SAME order and the same length - the spec list is derived
     * from this one inside {@see CommandRegistry::filter()}, so the two cannot
     * fall out of step.
     *
     * Exists for the same reason {@see paletteMatchResults()} does
     * (crush_code.md Phase 4 item 5): {@see Renderer::renderSlashMenu()} needs
     * the indices to highlight the run the user actually typed, and the popup
     * was the one of the two command surfaces that could not.
     *
     * @return list<MatchResult>
     */
    public function slashMenuMatchResults(): array
    {
        $prefix = $this->slashMenuPrefix();

        return $prefix === null
            ? []
            : CommandRegistry::filterMatchResults($prefix, $this->slashCommandRows());
    }

    /**
     * The in-progress command name being typed (everything after the leading
     * "/"), or null when the popup must not show at all.
     *
     * ONE guard for both {@see slashMenuMatches()} and
     * {@see slashMenuMatchResults()}, not a copy in each: the renderer pairs row
     * N of the first with row N of the second, so a guard that drifted between
     * them would silently highlight one command's matched run on another
     * command's row - the failure the fallback in
     * {@see Renderer::renderSlashMenu()} can only stop from crashing, not from
     * being wrong.
     */
    private function slashMenuPrefix(): ?string
    {
        if (!str_starts_with($this->inputBuf, '/') || str_contains($this->inputBuf, ' ')) {
            return null;
        }

        return substr($this->inputBuf, 1);
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
     * Whether a bare Tab completes the "/" popup's highlighted row RIGHT NOW.
     *
     * The ONE predicate both halves of that binding read: {@see update()}'s
     * bare-Tab arm, which performs the completion, and
     * {@see \SugarCraft\Crush\Tui\KeyboardHandler::claims()}, which drops its
     * pane-cycling claim so the key can reach that arm at all. Dropping the
     * shell's claim does not BIND Tab anywhere — it only lets it fall through
     * — so a shell that yielded on a condition this class does not answer
     * would turn Tab into a DEAD keystroke. That is not hypothetical: while
     * the predicate was `slashMenuMatches() !== []` on both sides, Ctrl+P then
     * Tab (measured through App::update()) cycled no pane and completed
     * nothing, because update() returns to handlePaletteKey() BEFORE its Tab
     * arm. Matching arms is not enough; the two must match on REACHABILITY,
     * which is why the modal guards are named here rather than at either
     * call site.
     *
     * The four conjuncts are exactly update()'s own early returns that can
     * swallow a bare Tab, in its order:
     *   - {@see $keyHelp} — {@see handleKeyHelpKey()} ends in `default =>
     *     [$this, null]`. Not reachable from real input WITH a slash draft
     *     (measured: both openers require an empty/cleared inputBuf — the "?"
     *     arm guards on `trim($this->inputBuf) === ''` and /keys clears it),
     *     but the public constructor builds the pair, the same API-surface
     *     hole the $pendingPermission ordering comment above records;
     *   - {@see $pendingPermission} — {@see handlePermissionKey()};
     *   - {@see $palette} — {@see handlePaletteKey()};
     *   - {@see $sessionPicker} — {@see handleSessionPickerKey()}.
     * $inFlight is NOT among them: {@see refuseWhileInFlight()} has no bare-Tab
     * arm and returns null for it, so completion works mid-turn (driven in
     * SlashMenuTabCompletionTest).
     */
    public function slashMenuOwnsTab(): bool
    {
        return $this->keyHelp === null
            && $this->pendingPermission === null
            && $this->palette === null
            && $this->sessionPicker === null
            && $this->slashMenuMatches() !== [];
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
            // Mid-turn the palette browses but does not dispatch — see
            // {@see runSelectedPaletteActionWhileInFlight()}.
            $msg->type === KeyType::Enter
                => $this->inFlight
                    ? $this->runSelectedPaletteActionWhileInFlight()
                    : $this->runSelectedPaletteAction(),
            default => [$this, null],
        };
    }

    /**
     * The mid-turn half of {@see runSelectedPaletteAction()}: the palette OPENS,
     * filters and navigates while a turn is in flight (that is half the bug
     * report — Ctrl+P did nothing at all before this bundle), but Enter on a row
     * is refused rather than dispatched.
     *
     * BLANKET, WITH ONE EXCEPTION, and the reason it is blanket is measured
     * rather than assumed: every root action other than Exit delegates to a
     * handler that writes `inFlight` ({@see handleShareCommand()},
     * {@see handleAgentsCommand()}, {@see handleSessionsCommand()},
     * {@see handleMcpAuthCommand()}), wipes history
     * ({@see handlePaletteNewSession()}), or swaps the backend the running
     * agentic loop is about to make its NEXT provider call on
     * ({@see selectPaletteProvider()} — {@see finishToolCalls()} re-enters the
     * backend mid-turn, so this one is not hypothetical). `Exit` is the exception
     * for exactly the reason bare `/exit` is one in {@see submit()}: it ends the
     * process, so there is nothing left to corrupt.
     *
     * The two submenu transitions (Switch Model, Switch Theme) are refused too,
     * even though transitioning the palette's own mode is harmless: refusing the
     * LEAF and allowing the branch would walk the user into a list whose every
     * row then says no. `Switch Theme` is the one row that would be safe end to
     * end (it writes `themeName` and appends a line, and touches no turn state);
     * allowing it is a deliberate follow-up rather than part of this landing,
     * because the value is cosmetic and the rule's worth is that it is ONE rule.
     *
     * @return array{0:self,1:?\Closure}
     */
    private function runSelectedPaletteActionWhileInFlight(): array
    {
        $matches = $this->paletteMatches();
        if ($matches === []) {
            return [$this->mutate(['palette' => null]), null];
        }

        $label = $matches[min($this->palette->selectedIndex, count($matches) - 1)];

        if (PaletteAction::byLabel($label) === PaletteAction::Exit) {
            return [$this->mutate(['palette' => null]), Cmd::quit()];
        }

        return $this->refuseInFlightAction($label);
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
     * The launch's one {@see \SugarCraft\Crush\Permissions\PermissionGate}, as
     * seen from whichever of Chat's two collaborators is holding it, or null
     * when this Chat was built without one (every embedder and most tests).
     *
     * The hook chain is consulted as well as the backend, and not only as a
     * fallback for a non-engine backend: the chain is the collaborator that
     * SURVIVES a provider switch, so it is the more trustworthy of the two
     * about what this session has been gating on.
     */
    private function permissionGate(): ?\SugarCraft\Crush\Permissions\PermissionGate
    {
        $hook = $this->hooks?->hook(
            \SugarCraft\Crush\Hooks\HookEvent::PreToolUse->value,
            \SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook::NAME,
        );

        if ($hook instanceof \SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook) {
            return $hook->gate();
        }

        return $this->backend instanceof \SugarCraft\Crush\Backend\EngineBackend
            ? $this->backend->permissionGate()
            : null;
    }

    /**
     * @return array{0: self, 1: ?\Closure}
     */
    private function selectPaletteProvider(string $name): array
    {
        try {
            // The launch's ONE gate is carried across the switch rather than
            // left to be rebuilt: PermissionGate's Auto-mode circuit breaker
            // is per-INSTANCE state, so a fresh gate hands a model sitting at
            // two strikes a clean slate and escalation-to-Ask never fires. It
            // also re-reads the config, which means a file edited mid-session
            // would put the engine and Chat's own tool path on two different
            // modes — the exact "one gate for the whole launch" invariant
            // Bootstrap::chat() builds for.
            //
            // THE ROOT IS THREADED TOO, and omitting it silently SHORTENED the
            // guard chain. Bootstrap::backendFor() falls back to `getcwd()`
            // for a null root, and the launch's root is not the process
            // directory whenever `--root` was given — so a trusted project's
            // `.sugar-crush/hooks.yaml` was loaded at launch and then dropped
            // by the switch, leaving Chat's own tool path and the engine path
            // on two different chains. A guard silently missing from the chain
            // is the one failure a guard must not have (see
            // {@see \SugarCraft\Crush\Hooks\HookConfig}), and it fails exactly
            // when it matters: the tool call the hook existed to stop.
            $backend = \SugarCraft\Crush\Cli\Bootstrap::backendFor(
                $name,
                $this->projectRoot,
                gate: $this->permissionGate(),
            );
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
            // Released for the same reason `/clear` and `/rewind` release it: a
            // `/compact` issued against the OLD session's transcript must not
            // rewrite whatever is in front of the user under a new session id.
            'pendingCompactionId' => null,
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
     *
     * Now the PALETTE query's backspace only. The draft's backspace moved to
     * {@see TextArea}, which needs a cursor-relative delete this cannot do;
     * the palette query has no cursor, so it still wants exactly this.
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
     * FOUR THINGS. IT SAID "TWO" AND ENUMERATED TWO, and both halves were true
     * when written; the `statusLine` clock and then the runtime-notice poll
     * arrived below without this sentence moving, so a reader who trusted the
     * count stopped reading at the second bullet — which is where the two
     * conditional ticks that are easiest to get wrong begin. The list below is
     * the first two; the other two document themselves at their `if`.
     *
     *   - waking up often enough to run
     *     {@see \SugarCraft\Crush\Sessions\BackgroundSupervisor::tick()},
     *     which is what flips a session whose heartbeats have stopped to
     *     `Stalled` and back again. Before this returned anything, that
     *     method had no caller on the live path at all.
     *   - while a turn is in flight, draining the live tool-event inbox
     *     ({@see $liveToolEvents}) so engine-dispatched tool calls appear in
     *     the transcript as they start and finish rather than all at once
     *     when the turn ends (crush_feat.md §1 E1). This is the wake-up half
     *     of that mechanism: the backend appends off-loop with no way to
     *     dispatch a Msg, so something has to come back and look.
     *
     * Returns null - not an empty Subscriptions - whenever there is nothing
     * to poll. `Program` reconciles the set every cycle, so a subscription
     * declared unconditionally would keep a timer waking the event loop (and
     * re-rendering) forever in the overwhelmingly common case of an idle chat
     * whose user has never run `/bg`. For the same reason the tool-event tick
     * is dropped the moment the turn ends: outside a turn nothing can append
     * to the inbox, and anything still in it is drained by the resolving
     * turn's {@see BackendToolEventsMsg}.
     */
    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        $subscriptions = null;

        if ($this->backgroundSupervisor !== null && $this->backgroundSupervisor->hasActiveSessions()) {
            $subscriptions = (new \SugarCraft\Core\Subscriptions())->withTick(
                self::BACKGROUND_POLL_SUBSCRIPTION,
                self::BACKGROUND_POLL_SECONDS,
                static fn (): \SugarCraft\Core\Msg => new BackgroundTickMsg(),
            );
        }

        if ($this->inFlight || count($this->liveToolEvents) > 0) {
            $subscriptions = ($subscriptions ?? new \SugarCraft\Core\Subscriptions())->withTick(
                self::TOOL_EVENT_POLL_SUBSCRIPTION,
                self::TOOL_EVENT_POLL_SECONDS,
                static fn (): \SugarCraft\Core\Msg => new ToolEventPumpMsg(),
            );
        }

        // The mid-session transcript seam's poll (E171). Declared on the same
        // terms as the two above and for the same stated reason: an
        // unconditional tick would keep a timer waking the loop and repainting
        // forever on a launch where nothing ever warns, which is the
        // overwhelmingly common one.
        //
        // `hasPending()` is a QUERY, never a drain — see its doc-block. It is
        // one array check, or on the cross-fork transport one `stream_select()`
        // with a zero timeout. It runs once per `Program` reconcile, i.e. once
        // per Msg, not on a timer of its own.
        //
        // GATED ON $drainsRuntimeNotices FIRST, and that clause is not
        // defensive tidiness — see the property's doc-block for the two
        // StatusLineSegmentTest cases that measured what its absence costs.
        // `drain()` is destructive, so a second polling Chat would steal rows
        // from the real transcript rather than duplicate them.
        //
        // ORed WITH $inFlight RATHER THAN RELYING ON hasPending() ALONE, and
        // that is the load-bearing half. The mid-session emitters that are on a
        // live path — the two tool-call parsers, and `SglangProvider`'s two
        // argument-decode refusals — raise their notices DURING a turn, and on
        // the interactive path they do so inside
        // {@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()}'s
        // forked child. Waiting for `hasPending()` to go true would work, but
        // only on whatever Msg happened to arrive next; arming for the whole
        // turn means the row appears while the turn is still running, which is
        // the entire point of a seam that is not launch-only.
        //
        // `WorktreeManager`'S FOUR (E192) ARE ON THE SEAM AND ON NO PATH, and
        // this list named them among the four above as though they were. WHAT
        // IS TRUE NOW, checked rather than assumed: nothing in `src/` or `bin/`
        // constructs a `WorktreeManager` — only its own doc-comments mention
        // the constructor and the factory — and `Team::claimTask()`, the one
        // method that takes one, has no caller in `src/` either. The class is
        // dormant, its own doc-block now says so, and the census pins it. They
        // are named here anyway rather than dropped, because when a first
        // caller does arrive it will be from tool dispatch, i.e. inside a turn,
        // and this clause is the one that will cover it.
        //
        // AND `hasPending()` ALONE IS NOT MERELY WEAKER, IT CAN NEVER FIRE ON
        // ITS OWN (E193). `Program` consults this method only when it
        // reconciles, i.e. after `init()` and after every dispatched `Msg`. On
        // an idle session there is no next `Msg` to reconcile after, so a
        // notice that becomes pending here arms nothing at all — MEASURED on a
        // real `Program`, zero rows after two seconds of loop time with the row
        // still sitting in the socket. That gap is closed OUTSIDE this method,
        // by the edge-driven watcher {@see init()} arms; this clause is what
        // covers the in-turn case, where the watcher and the tick are both live
        // and either may win.
        if ($this->drainsRuntimeNotices && ($this->inFlight || RuntimeNoticeSink::hasPending())) {
            $subscriptions = ($subscriptions ?? new \SugarCraft\Core\Subscriptions())->withTick(
                self::RUNTIME_NOTICE_SUBSCRIPTION,
                self::RUNTIME_NOTICE_POLL_SECONDS,
                static fn (): \SugarCraft\Core\Msg => new RuntimeNoticePumpMsg(),
            );
        }

        // The `statusLine` command's clock. Declared only while one is
        // CONFIGURED, which is the same conditionality the two above have and
        // for the reason this docblock gives: an unconditional tick would keep
        // a timer waking the loop and repainting forever on every launch,
        // including the overwhelmingly common one where nobody set the key.
        //
        // Unlike the two above there is no state that can end it mid-session:
        // {@see StatusLineCommand::configure()} runs once per launch, so once a
        // user has asked for a periodically-refreshed readout the timer is the
        // feature rather than an artefact of one. The refresh itself is
        // additionally TTL-gated, so a tick that arrives early costs a
        // comparison and nothing else.
        if (StatusLineCommand::active() !== null) {
            $subscriptions = ($subscriptions ?? new \SugarCraft\Core\Subscriptions())->withTick(
                self::STATUS_LINE_SUBSCRIPTION,
                StatusLineCommand::REFRESH_SECONDS,
                static fn (): \SugarCraft\Core\Msg => new StatusLineTickMsg(),
            );
        }

        return $subscriptions;
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
     * Drain {@see RuntimeNoticeSink} into the transcript (E171).
     *
     * THE READER THAT THE LAUNCH SEAM DOES NOT HAVE. Warnings raised while
     * `Bootstrap` was BUILDING this Chat reach the transcript through
     * {@see withLaunchNotices()}, which is called once at construction.
     * Warnings raised after that — a tool-call parser refusing a malformed
     * invoke on turn forty, a provider degrading mid-session — had only
     * `error_log()`, i.e. fd 2, i.e. a frame the renderer believes it owns and
     * a primary buffer the user does not see again until they quit.
     *
     * {@see Role::System} rows, the same shape `withLaunchNotices()` uses and
     * for its reason: {@see Renderer} already lays out, wraps and scrolls that
     * role at every width, so a warning routed here inherits a correct surface
     * instead of a banner that would have to learn all of it again.
     *
     * ONE APPEND FOR THE WHOLE BATCH, unlike {@see pumpLiveToolEvents()}. That
     * method renders between entries because a tool call has a running→done
     * walk worth seeing; a notice is finished prose the moment it exists, and
     * rendering between two of them would only cost a repaint. The batch is
     * bounded at the sink — see {@see RuntimeNoticeSink::drain()} — so "the
     * whole batch" cannot be unbounded.
     *
     * $this UNCHANGED when nothing was pending, which the tick makes the
     * common case: `Program` re-renders after every update, and returning a
     * new-but-identical Chat would repaint the transcript twice a second for
     * the whole of every turn.
     *
     * BOTH RETURN PATHS RE-ARM, INCLUDING THE EMPTY ONE, and that is not
     * symmetry for its own sake. {@see \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink::notifyOnceWhenPending()}
     * is one-shot, so whatever fires it consumes it; if the empty path did not
     * re-arm, the first pump that happened to find nothing would leave the
     * session with no wake-up for the rest of its life. That path is REACHED,
     * and by an ordinary interleaving rather than an exotic one: during a turn
     * both the `$inFlight` tick and the watcher are live, and whichever
     * dispatches second drains an inbox the other already emptied.
     *
     * THE EMPTY ARM IS PINNED BY {@see \SugarCraft\Crush\Tests\Diagnostics\RuntimeNoticeSinkDeliveryTest::testAPumpThatFindsNothingStillRenewsTheOneShotWake()}
     * AND BY NOTHING ELSE, which was found by mutation rather than assumed:
     * returning `null` here SURVIVED the whole `RuntimeNoticeSink` filter until
     * that test existed, because every other pump in the suite finds a row and
     * takes the other branch.
     *
     * IT STILL RETURNS `$this` UNCHANGED WHEN THERE IS NOTHING, so
     * {@see \SugarCraft\Crush\Tests\Diagnostics\RuntimeNoticeSinkDeliveryTest::testTheSecondPumpAddsNothingBecauseTheFirstConsumedTheInbox()}'s
     * point survives: an empty pump must not repaint. Only the Cmd differs.
     *
     * ONE DOC-BLOCK AND NOT TWO, which is why the paragraphs above read as two
     * halves written a round apart — they were. E193's re-arm paragraphs landed
     * as a SECOND doc-comment stacked between the original block and this
     * declaration, and PHP attaches only the last one: the `@return` tag below
     * had come off the method entirely (VERIFIED by
     * `ReflectionMethod::getDocComment()`, which returned the re-arm block with
     * no `@return` in it), and the original block's reasoning — the batching
     * argument, the `Role::System` argument — was orphaned prose that no tool
     * and no `{@see}` could resolve. Merged rather than either half deleted.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function pumpRuntimeNotices(): array
    {
        $notices = RuntimeNoticeSink::drain();
        $rearm = $this->runtimeNoticeWake();

        if ($notices === []) {
            return [$this, $rearm];
        }

        $messages = [];
        foreach ($notices as $notice) {
            $messages[] = Message::system($notice);
        }

        return [$this->mutate(['history' => [...$this->history, ...$messages]]), $rearm];
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
     * The replicated *logic* now lives in one place —
     * {@see IdleCompactionPolicy::shouldPrompt()} — so the two callers cannot
     * disagree about the idle window or the size threshold the way they did
     * when each wrote both numbers itself. Chat still supplies its own limit:
     * the backend is what it can see, and it must not reach for a Runtime.
     *
     * Returns true when the session has been idle longer than
     * {@see IdleCompactionPolicy::IDLE_SECONDS} AND the estimated token count
     * is past the whole context window {@see contextTokenLimit()} reports.
     *
     * Called once per turn from submit() (see {@see idleCompactionPromptResponse()})
     * right before a real prompt would be dispatched to the backend.
     *
     * @param int $tokenCount Estimated token count for the conversation
     * @param \DateTimeImmutable|null $lastActivityAt When the user was last active
     */
    public function shouldPromptIdleCompaction(int $tokenCount, ?\DateTimeImmutable $lastActivityAt = null): bool
    {
        return IdleCompactionPolicy::shouldPrompt($tokenCount, $lastActivityAt, $this->contextTokenLimit());
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
     * Current history's estimated size as a fraction of
     * {@see contextTokenLimit()} - for the status bar's context-usage
     * indicator ({@see Renderer}).
     *
     * The denominator changed meaning in crush_code.md Phase 5 item 4: it is
     * the model's real context window whenever the backend can report one, so
     * this fraction is now "how full is the window", not "how close to a
     * fixed 100,000-token proxy". Numerator and denominator are also in
     * different units - an estimated chars/4 count against a
     * provider-counted window - which is why {@see Renderer} prints the pair
     * with a leading `~` rather than as a measured percentage.
     *
     * Still not clamped to [0, 1]: a value above 1.0 is real signal - the
     * history as it stands genuinely does not fit - not a bug to hide. It does
     * NOT predict a refusal, and an earlier draft of this docblock said it
     * did. The next turn runs automatic compaction first, and whether the
     * 95% tier then refuses depends entirely on whether compaction can free
     * enough: measured, a 2,400-message history reading 122% of the
     * 100,000-token fallback window compacts to 1% and IS dispatched (see
     * {@see \SugarCraft\Crush\Tests\Renderer\KeyHelpTest}'s
     * `turn in flight, big context` state), while 13 exchanges of ~50,000
     * chars reading 325% cannot be shrunk past the tier and IS refused.
     */
    public function contextUsagePercent(): float
    {
        return $this->estimateTokenCount($this->history) / $this->contextTokenLimit();
    }

    /**
     * The numerator behind {@see contextUsagePercent()}: the current
     * history's size in tokens. Exposed so the status bar can print an
     * absolute count next to the percentage instead of multiplying the
     * fraction back out against a limit it would have to hardcode.
     *
     * Approximate by construction - it is a chars/4 proxy, not a
     * provider-reported usage figure - so any UI showing it must say so.
     */
    public function contextTokens(): int
    {
        return $this->estimateTokenCount($this->history);
    }

    /**
     * The denominator behind {@see contextUsagePercent()} and the budget every
     * context tier is a percentage of: the 70% reminder, the 85% automatic
     * compaction, the 95% blocking refusal and the idle-compaction prompt.
     *
     * This IS the live model's advertised context window now, whenever the
     * backend implements {@see \SugarCraft\Crush\Backend\ReportsContextWindow}
     * and reports a positive one - crush_code.md Phase 5 item 4, which replaced
     * a hardcoded 100,000 that matched no provider in this repo: measured over
     * every `contextWindow()` in `src/Providers/`, the six with a model behind
     * them report 8,192 / 16,385 / 128,000 / 196,608 / 200,000 / 1,048,570
     * depending on model, and not one of them 100,000. The seventh (Echo)
     * reports 0 for "unknown".
     *
     * SIX PROVIDERS, SIX-PLUS FIGURES - the counts are of different things and
     * were never equal. 1,048,570 is the newest of them:
     * {@see \SugarCraft\Crush\Providers\SglangProvider::contextWindow()}
     * became model-aware and answers that for the DeepSeek-V4 family, keeping
     * 196,608 for MiniMax.
     *
     * THAT LAST FIGURE IS TRANSCRIBED AND DECAYS, and this line is the proof:
     * it read 393,216 from the day the model-awareness landed until the sweep
     * that found it here, because the constant was corrected in
     * `SglangProvider.php` and nothing swept the places DESCRIBING it. Do not
     * reason from the number. The only durable claim in this paragraph is that
     * the figure is MODEL-AWARE and provider-reported; the six literals are
     * illustrative of the spread, not a contract, and no test pins them.
     *
     * A backend with no model behind it still gets
     * {@see ContextWindow::FALLBACK_TOKENS}, which is that same 100,000, so
     * the offline path acts exactly as it did before - and note that "no model
     * behind it" is a claim about the PROVIDER, not about the backend class.
     * {@see Backend\EchoBackend} is reachable only through this class's
     * constructor default; the CLI's offline fallback AND its
     * degrade-after-provider-failure path both build
     * `EngineBackend(EchoProvider)`
     * ({@see \SugarCraft\Crush\Cli\Bootstrap::backend()}), which DOES
     * implement the capability. That is why
     * {@see \SugarCraft\Crush\Providers\EchoProvider::contextWindow()}
     * reports 0 rather than a made-up figure: measured, a 1,000,000 there put
     * the live offline tiers at 700,000 / 850,000 / 950,000 / 1,000,000
     * estimated tokens, i.e. switched all four off on the default path.
     */
    public function contextTokenLimit(): int
    {
        return ContextWindow::ofBackend($this->backend);
    }

    /**
     * This session's provider-reported spend in US dollars.
     *
     * A DIFFERENT unit from everything {@see contextTokens()} and
     * {@see contextTokenLimit()} deal in: those are a chars/4 estimate of what
     * was sent, this is what the provider said it billed. {@see Renderer} shows
     * them as two separate segments of the status bar for that reason and never
     * combines them - see {@see Usage} for the full statement of the hazard.
     *
     * Exactly 0.0 is BOTH "nothing was reported" and "this provider is free",
     * which is why the readout keys off {@see hasReportedSpend()} rather than
     * off this being positive.
     */
    public function spentUsd(): float
    {
        return $this->tokenTracker->totalCost();
    }

    /**
     * Put one provider call's reported usage on the session tracker, or do
     * nothing when the provider reported none.
     *
     * The ONE place spend is recorded, and the reason it is a named method
     * rather than four copies of two lines: this app makes provider calls from
     * four places — a turn's completion ({@see update()}'s `AssistantMsg` arm),
     * a tool turn superseded mid-queue ({@see applyBackendToolEvent()}),
     * `/compact`'s summarization ({@see HistoryCompactedMsg}) and the session
     * titler ({@see SessionTitledMsg}) — and every one of them is on the user's
     * key. Three of the four were dropping their figure before this existed, so
     * the readout was under-reporting exactly the calls the user never asked for
     * out loud.
     *
     * A null $usage is "the provider reported nothing", which is the ordinary
     * streamed-turn answer and must not become a zero-dollar call — see
     * {@see Usage} for why zero and unknown are different claims.
     *
     * `addTotalUsage()`, not `addUsage()`: the figure crossing every one of
     * those seams is a TOTAL with no input/output split, and
     * {@see Util\TokenTracker} keeps those in their own bucket rather than
     * pretending the whole call was input.
     *
     * Mutates the tracker, which every clone of this Chat shares by object
     * identity — that is the whole reason the tracker is not immutable, and it is
     * what lets a Cmd's resolved Msg account against the session the user is
     * still in.
     */
    private function accountUsage(?Usage $usage): void
    {
        if ($usage === null) {
            return;
        }

        $this->tokenTracker->addTotalUsage($usage->totalTokens, $usage->costUsd);
    }

    /**
     * Whether any settled turn this session actually reported usage.
     *
     * False on every offline run and on a streamed session whose provider
     * never sent a usage block - {@see Runtime}'s streaming path documents
     * that chunks carry `tokensUsed=0`, and
     * {@see Providers\OpenAIProvider::completeStream()} states it outright. The
     * spend readout needs this because `$0.0000` would otherwise be printed for
     * "we have no idea" as confidently as for "you have spent nothing".
     *
     * A READOUT concern only. The cap DECISION does not consult it — see
     * {@see spendCapReached()}, where the same fail-open falls out of the
     * arithmetic (an unreported session's spend is `0.0`, and a cap is always
     * positive) rather than from a second clause. It used to be a clause there,
     * and it was one nothing could make load-bearing: a mutation deleting it
     * survived, because the test named after it passed through the comparison.
     */
    public function hasReportedSpend(): bool
    {
        return $this->tokenTracker->totalTokens() > 0 || $this->tokenTracker->totalCost() > 0.0;
    }

    /**
     * The spend ceiling `/budget` and `$SUGARCRUSH_MAX_COST` set, or null when
     * this session has none. Always a positive finite number when non-null —
     * {@see isUsableSpendCap()}, enforced in the constructor.
     *
     * Three sites enforce it, and they are not interchangeable:
     * {@see spendCapRefusal()} refuses a turn the user submitted,
     * {@see scheduleModelCompaction()} declines to ask the model for `/compact`'s
     * summaries (the compaction still runs, on the heuristic), and
     * {@see applyModelCompaction()} refuses a turn the 85% tier parked when the
     * summarization it was parked behind is what reached the cap. All three
     * decide with {@see spendCapReached()}.
     */
    public function maxCostUsd(): ?float
    {
        return $this->maxCostUsd;
    }

    /**
     * The token/cost line `/budget` prints — {@see TokenTracker::summary()}, so
     * the wording lives with the buckets it describes rather than being
     * re-spelled here.
     */
    public function usageSummary(): string
    {
        return $this->tokenTracker->summary();
    }

    /**
     * Whether this session has reached its spend cap, i.e. whether the app
     * should stop making provider calls on the user's key
     * (crush_code.md Phase 5 item 7).
     *
     * The one definition of "over budget", asked by EVERY enforcement site:
     * {@see spendCapRefusal()} for a turn the user submitted,
     * {@see scheduleModelCompaction()} for the summarization call `/compact`
     * makes on its own initiative, and {@see applyModelCompaction()} for a turn
     * the 85% tier parked behind a summarization that may itself have crossed the
     * cap. They differ in what they DO about it, not in how they decide it.
     *
     * FALSE WHENEVER THERE IS NO CAP, which is the ordinary case. False with a
     * cap too whenever the reported spend is still below it — and since a cap is
     * a positive finite number by construction ({@see isUsableSpendCap()},
     * enforced in the constructor, so `/budget`, `$SUGARCRUSH_MAX_COST` and a
     * direct `new Chat(...)` cannot get around it), a session no provider has
     * reported anything for has a spend of `0.0` and is therefore never over.
     * That is the deliberate fail-OPEN, and it is arithmetic rather than a
     * separate clause: a streamed session whose provider sends no usage block
     * would otherwise be refused from its first turn on the strength of a figure
     * nobody supplied, which is why a cap here is a budget guard and not a
     * security control. `$0.0000` and "unknown" stay distinguishable for the
     * READOUT via {@see hasReportedSpend()}; for the decision they agree.
     */
    private function spendCapReached(): bool
    {
        $cap = $this->maxCostUsd;

        return $cap !== null && $this->spentUsd() >= $cap;
    }

    /**
     * Whether $cap is a spend ceiling this app will act on: a positive, finite
     * number of dollars.
     *
     * The single definition, because three entry points reach for it and they
     * disagreed. `0` and a negative are REFUSED rather than read as "no cap" —
     * a cap of zero and no cap are opposite intentions and quietly turning the
     * stricter one into the looser one is the wrong direction to guess in.
     * Non-finite is refused for a blunter reason, and it was reachable from user
     * input: `is_numeric('1e309')` is true and `(float) '1e309'` is `INF`, which
     * is `> 0.0`, so `/budget 1e309` used to install a cap that rendered as
     * `$inf` on the status bar and — since every comparison against `INF` is
     * false — silently meant no cap at all. `NAN` is worse still: every
     * comparison against it is false in BOTH directions.
     */
    public static function isUsableSpendCap(float $cap): bool
    {
        return is_finite($cap) && $cap > 0.0;
    }

    /**
     * Refuse this turn when the session has already reached its spend cap, or
     * null when it has not (crush_code.md Phase 5 item 7).
     *
     * WHICH SIDE OF THE CAP THIS IS, stated exactly, because the two possible
     * behaviours have different messages and only one of them is implemented:
     * this refuses to START the turn once the accumulated spend has reached the
     * cap. It does NOT abort a turn that is already running, and it cannot —
     * {@see Backend\EngineBackend::completeAsync()} runs the turn in a forked
     * child, so the child's per-step figures do not reach this process until the
     * turn settles and there is nothing here to interrupt mid-flight. The
     * consequence is concrete and the message says it: the turn that crosses
     * the cap runs to completion, so the final total overshoots by that one
     * turn's cost, and the cap then refuses every turn after it.
     *
     * WHAT IT GOVERNS is the turn a user submitted from {@see submit()}, and only
     * that. It is not the app's only provider call and it is no longer the only
     * gate: `/compact`'s summarization has its own check at its own site
     * ({@see scheduleModelCompaction()}), because it is dispatched past this
     * point — the cap is deliberately evaluated AFTER {@see dispatchCommand()} so
     * that `/budget` still works while capped, and `/compact` dispatches there
     * too. And the 85% tier's PARKED turn is checked again where it is finally
     * dispatched ({@see applyModelCompaction()}), because that dispatch happens
     * in a later `update()` than this refusal ran in, with the summarization's own
     * cost accounted in between. The session titler needs no check of its own;
     * see {@see scheduleTitleGeneration()} for the measurement.
     *
     * Refusal is VISIBLE — the draft is kept and an assistant line explains
     * both the state and the way out. Silently truncating the history or
     * silently continuing are the two failure modes a cap exists to prevent, so
     * neither is on the table. There is no `Message::user()` echo of the draft
     * either, which is why this takes no draft argument: every other command exit
     * echoes the line it consumed, and this one did not consume it.
     *
     * @return array{0:self,1:?\Closure}|null
     */
    private function spendCapRefusal(): ?array
    {
        if (!$this->spendCapReached()) {
            return null;
        }

        return $this->spendCapTurnRefusal(
            'The turn that crossed the cap ran to completion; the cap refuses the NEXT turn rather than '
            . 'aborting one in flight.'
        );
    }

    /**
     * Refuse a turn because the cap is already reached, whatever reached it.
     *
     * TWO CALLERS, and the difference between them is WHAT crossed the cap, which
     * is why that clause is the parameter: {@see spendCapRefusal()} refuses a
     * freshly submitted prompt, where the crossing was a previous TURN, and
     * {@see applyModelCompaction()} refuses a turn the 85% tier parked, where the
     * crossing may have been the SUMMARIZATION the turn was parked behind — a
     * provider call this app made on its own initiative, in a previous `update()`.
     * Everything else is shared, because a refusal that worded the state
     * differently at the two sites would read as two different features.
     *
     * `inFlight` is cleared here. On {@see submit()}'s route it was already
     * false; on the parked route this is the write that releases the window the
     * tier was holding, and without it the session wedges — every keystroke
     * swallowed, with no turn to wait for.
     *
     * The draft is NOT cleared, and on the parked route there is nothing to
     * clear: the prompt was consumed at park time and is already echoed in the
     * transcript, which is also why this appends no `Message::user()` of its own.
     *
     * @return array{0:self,1:?\Closure}
     */
    private function spendCapTurnRefusal(string $crossing): array
    {
        $spent = $this->spentUsd();
        $cap = (float) $this->maxCostUsd;

        $notice = sprintf(
            'Spend cap reached — this turn was not sent. $%.4f of the $%.4f cap has been reported spent. '
            . '%s Raise it with /budget %.2f, clear it with /budget off, or restart '
            . 'without $SUGARCRUSH_MAX_COST.',
            $spent,
            $cap,
            $crossing,
            $spent * 2,
        );

        return [$this->mutate([
            // The draft is KEPT: the user's prompt was never sent, and clearing
            // the box would lose it to a refusal they may well answer by
            // raising the cap.
            'history' => [...$this->history, Message::assistant($notice)],
            'inFlight' => false,
        ]), null];
    }

    /**
     * Build the soft, non-blocking reminder message
     * {@see dispatchTurn()} appends whenever
     * {@see ContextCompactor::shouldSendReminder()} reports the conversation
     * has crossed its share of the budget ({@see CompactorConfig::$reminderThreshold},
     * 70% by default — which is why neither this docblock nor the message names
     * a percentage as if it were fixed). Rendered with a distinct
     * `Role::System` (a faint "system: …" line, see {@see Renderer}) rather
     * than the `Role::Assistant` bubble used for the hard idle-compaction
     * prompt, so the two are visually distinguishable and this one never
     * blocks the turn it rides along with.
     *
     * "WHENEVER", NOT "ONCE", WHICH IS WHAT AN EARLIER DRAFT OF THIS DOCBLOCK
     * SAID. The predicate is stateless, so it answers true on every turn past
     * the threshold and this is built afresh each time. What keeps history to
     * one copy is not this method and not a latch, but
     * {@see withoutContextReminders()} dropping the previous copy on every
     * dispatch — read that docblock before changing anything here, because the
     * $tokenCount interpolated below is exactly what makes two copies
     * unequal byte-for-byte.
     */
    private function contextReminderMessage(int $tokenCount): Message
    {
        return Message::system(
            self::CONTEXT_REMINDER_PREFIX
            . "{$tokenCount} estimated "
            . "tokens, past the context-usage reminder threshold. Consider "
            . "running /compact soon to keep the session responsive."
        );
    }

    /**
     * Drop every context-usage reminder from a history.
     *
     * {@see dispatchTurn()} calls this on EVERY dispatch and appends a fresh
     * copy only when the tier fires, so history carries exactly one reminder
     * while the estimate is over the tier and none once it drops back under.
     * The unconditional call is the load-bearing half: gated on the tier
     * instead, a session that compacts back under the line keeps the last
     * reminder it was sent forever.
     *
     * The bug this exists for: {@see ContextCompactor::shouldSendReminder()} is
     * pure and stateless — a bare `$tokenCount >= $threshold` with no latch and
     * no timestamp — so it answers true on EVERY turn once the estimate crosses
     * the threshold, and {@see dispatchTurn()} commits its answer into
     * `history` rather than rendering it from state. A session driven twenty
     * turns past the threshold therefore carried twenty near-identical system
     * messages, each one 53 ESTIMATED tokens (see the arm in
     * {@see dispatchTurn()} for that figure's derivation), each one also
     * checkpointed and re-sent on the wire every subsequent turn. Their own
     * bytes count toward the estimate that made the predicate true, so it
     * compounds; deduplication bounds it at one copy.
     *
     * NOT A REGRESSION, to be precise about the history. The waste was always
     * there, but {@see ContextCompactor::groupIntoPairs()} used to silently DROP a
     * non-user/non-assistant message sitting directly after a user turn — the
     * reminder's exact shape — so every compaction deleted every copy and the
     * pile-up was self-limiting by accident. Fixing that drop (it was also
     * eating `_Request cancelled._` and the automatic tier's own report) is
     * what made the accumulation observable. That fix did not cause this.
     *
     * Deduplication rather than a fire-once latch, and rather than keeping the
     * reminder out of `history` and rendering it from state:
     *
     *  - a latch would leave a figure from twenty turns ago on screen and would
     *    never take it down again. Dedup shows the current figure while the
     *    estimate is over the tier — the surviving copy is the one just built —
     *    and shows nothing once it is back under, because the strip above runs
     *    on every dispatch and only the append is conditional;
     *  - rendering from state needs a NEW render path for a user-facing message
     *    that {@see Renderer} currently gets for free by walking `Role::System`
     *    entries in history. Dedup keeps the visible transcript line at no
     *    render cost.
     *
     * Rewriting `history` here is not a new class of operation: this class
     * already replaces the array wholesale for the tool-result splice, for
     * `/clear`, for every compaction tier and for `/rewind`. And because
     * {@see dispatchTurn()} checkpoints `$next->history` itself, the persisted
     * copy inherits the fix with no second serialisation site to keep in step.
     *
     * Only reminders still carried VERBATIM are matched, which is the intended
     * scope: a reminder that a compaction folded into a summary line is no
     * longer a copy of anything, and its content no longer starts with the
     * marker.
     *
     * `array_values()` serves the `@return list<Message>` annotation and
     * nothing else — do not write a test for it. The sole consumer spreads the
     * result into a new array, which re-indexes anyway, so dropping it changes
     * no observable behaviour. The two things here that ARE observable are the
     * call being unconditional and the filter removing EVERY match rather than
     * the first (a session predating the dedup carries several copies and must
     * collapse to one in a single dispatch, not one copy per turn); both are
     * pinned in `tests/Chat/ContextReminderDedupTest.php`.
     *
     * @param list<Message> $history
     * @return list<Message>
     */
    private static function withoutContextReminders(array $history): array
    {
        return array_values(array_filter(
            $history,
            static fn(Message $msg): bool => !self::isContextReminder($msg),
        ));
    }

    /**
     * Whether $msg is one of {@see contextReminderMessage()}'s own products.
     *
     * Role AND marker, both required, and the role half is the one that matters
     * for safety: a user is entitled to paste the reminder's text back into a
     * prompt (quoting it to ask what it meant is the obvious way that happens),
     * and deleting their message would be data loss well beyond anything the
     * dedup is for. `Role::User` can never match here. See
     * {@see self::CONTEXT_REMINDER_PREFIX} for why the marker is a prefix and
     * not the whole string.
     */
    private static function isContextReminder(Message $msg): bool
    {
        return $msg->role === Role::System
            && str_starts_with($msg->content, self::CONTEXT_REMINDER_PREFIX);
    }

    /**
     * Rebuild a `list<Message>` from the wire arrays
     * {@see ContextCompactor::compact()} returns, REUSING the original
     * {@see Message} objects for every exchange compaction preserved in full.
     *
     * Rehydrating from the wire alone is lossy in a way the wire format hides:
     * {@see Message::toWire()} emits `role`, `content`, `attachments` and
     * `tool_calls` and nothing else, so `$createdAt`, `$toolResults`,
     * `$pendingToolCallId`, `$reasoning`, `$imageBytes` and `$imageProtocol`
     * have no representation there at all. {@see Renderer} renders three of
     * those - tool results, reasoning and images - so a pure round-trip
     * silently erased rendered tool output, model thinking and inline images
     * from the transcript and re-stamped every surviving turn with `time()`.
     * Measured on a preserved assistant turn before this fix:
     * `createdAt 1234567890 -> now, toolCalls 1 -> 0, toolResults 1 -> 0,
     * reasoning 'I thought hard' -> null, imageBytes 'PNGDATA' -> null`.
     * Tolerable while `/compact` was the only way to trigger it and the user
     * had typed it; not tolerable once submit()'s 85% tier does it
     * automatically, per turn, on a history the notice claims to have
     * preserved.
     *
     * So nothing is reconstructed that does not have to be. `compact()`
     * returns `[...summaries, ...preserved]` where the preserved block is the
     * last `recentPreserveCount` exchanges re-emitted with their role and
     * content unchanged - which makes it recoverable as the longest common
     * SUFFIX of `(role, content)` pairs between the wire and `$original`.
     * Everything in that suffix is handed back as the very same object;
     * everything before it genuinely IS new text (a `[summary] …` line, a
     * `[file: …, N lines]` stub, a `[3x] …` group) and is built fresh, stamped
     * now because that is when it came into existence.
     *
     * Matching from the END is what makes the alignment sound: the walk is
     * contiguous, so every reused message is the one at that exact distance
     * from the end of both lists. The two ways the suffix used to come up SHORT
     * were both {@see Context\ContextCompactor::groupIntoPairs()} losing a
     * message rather than anything here: it overwrote the earlier of two
     * consecutive assistant turns, and it dropped a non-user/non-assistant
     * message that directly followed a user turn. Both are fixed at the source,
     * so a short suffix now means only that the compaction genuinely rewrote
     * that far back — and a short suffix never meant a WRONG reuse either way,
     * only fewer messages keeping their metadata. And one way it could run LONG - an original
     * message whose content literally equals the summary line generated for it
     * - in which case a real `Message` with the identical role and content is
     * reused in place of a fresh one, which is why that is a curiosity rather
     * than a hazard.
     *
     * @param array<array{role:string,content:string}> $wire
     * @param list<Message> $original The history `$wire` was compacted from.
     * @return list<Message>
     */
    private function messagesFromWire(array $wire, array $original): array
    {
        $wire = array_values($wire);
        $original = array_values($original);
        $wireCount = count($wire);
        $originalCount = count($original);

        $preserved = 0;
        while ($preserved < $wireCount && $preserved < $originalCount) {
            $entry = $wire[$wireCount - 1 - $preserved];
            $candidate = $original[$originalCount - 1 - $preserved];
            if (
                ($entry['role'] ?? 'assistant') !== $candidate->role->value
                || ($entry['content'] ?? '') !== $candidate->content
            ) {
                break;
            }
            $preserved++;
        }

        $messages = [];
        foreach ($wire as $index => $entry) {
            if ($index >= $wireCount - $preserved) {
                $messages[] = $original[$originalCount - ($wireCount - $index)];
                continue;
            }

            $role = Role::from($entry['role'] ?? 'assistant');
            $content = $entry['content'] ?? '';
            $messages[] = match ($role) {
                Role::User => Message::user($content),
                Role::Assistant => Message::assistant($content),
                default => new Message($role, $content, time()),
            };
        }

        return $messages;
    }

    /**
     * The turn-refusing response of the 95% foreground tier
     * ({@see ContextCompactor::shouldCompactForeground()}).
     *
     * Reached only after automatic compaction has already run and failed to
     * get back under the tier, so there is nothing further this code can do on
     * its own: sending anyway means spending a round-trip on a request the
     * provider is entitled to reject. Shaped like
     * {@see idleCompactionPromptResponse()} - the typed text lands in history
     * so it is not lost, no Cmd is scheduled, and `inFlight` ends up false so
     * the next keystroke is accepted immediately (it was already false on
     * {@see submit()}'s route; on the parked route in
     * {@see applyModelCompaction()} this is the write that RELEASES the turn the
     * 85% tier was holding). It does not wedge, and the
     * message says how in terms that were MEASURED rather than assumed:
     *
     *  - Retrying works, eventually. Each refusal appends a small user/refusal
     *    pair, which pushes one enormous exchange out of the ten compaction
     *    preserves in full - so the history really does shrink per attempt.
     *    Driven on 13 equal exchanges of ~10,000 estimated tokens against an
     *    88,000-token window: refused at 100,487 estimated tokens, then the
     *    very next retry dispatched at 80,664. The dead end is a SINGLE
     *    exchange bigger than the tier, not a large history.
     *  - `/compact` does the same thing by the same mechanism (it adopts
     *    unconditionally and appends its own pair): 100,487 -> 80,574 on that
     *    fixture, and the following turn went out.
     *  - `/clear` frees everything at once.
     *
     * `/fork` is deliberately NOT offered, though an earlier draft offered it:
     * {@see handleForkCommand()} spawns a background session and leaves the
     * user on this branch with this history (see its docblock's contrast with
     * `/branch`, which keeps the history too), so it frees nothing here - and
     * without a BackgroundSupervisor and a SessionStore it answers "Background
     * sessions not configured" instead.
     *
     * $history is what this refusal COMMITS, and refusing the turn does not
     * mean leaving the transcript alone: when compaction freed something, the
     * older exchanges really were summarized away underneath the refusal, and
     * $compactionNotice - the very same {@see contextCompactedMessage()} the
     * dispatching path would have appended - is carried into history ahead of
     * the refusal so the user is told. It is null exactly when compaction
     * changed nothing, in which case $history is the untouched original and
     * there is nothing to report.
     *
     * REACHABLE DURING THE PARKED WINDOW, which it was not before the 85% tier
     * started parking turns, and this is the one `'inFlight' => false` write in
     * this file whose reachability class that change altered — so it is named
     * here rather than left to a census. It is safe, and the reason is specific:
     * {@see applyModelCompaction()} reaches it only through
     * {@see compactionChanges()}, which has already released
     * `$pendingCompactionId`, so the `'inFlight' => false` below cannot strand a
     * live summarization the way it would if the latch were still armed. Clearing
     * `inFlight` there is not belt-and-braces either: it is the write that
     * releases the parked window, without which the session wedges.
     *
     * @param string $inputText The prompt to echo as the user's line, or '' when
     *                          the transcript already carries it.
     * @param list<Message> $history The history to commit: compacted when
     *                               compaction freed anything, otherwise the
     *                               original untouched.
     * @param int $tokenCount ESTIMATED tokens (chars/4 proxy) in $history.
     * @param int $tokenLimit PROVIDER-COUNTED context window from
     *                        {@see contextTokenLimit()}.
     * @return array{0:Chat,1:?\Closure}
     */
    private function foregroundBlockedResponse(
        string $inputText,
        array $history,
        int $tokenCount,
        int $tokenLimit,
        ?Message $compactionNotice = null,
    ): array {
        $response = "This turn was NOT sent: the conversation is at ~{$tokenCount} "
            . "estimated tokens against a {$tokenLimit}-token context window, still "
            . "over the blocking tier after automatic compaction ran: compaction "
            . "preserves the most recent exchanges in full, and those alone overflow "
            . "this window. Each further attempt drops the oldest of them, so "
            . "re-sending or running /compact will get through after a pass or two; "
            . "/clear frees the whole context at once.";

        $committed = $history;
        if ($compactionNotice !== null) {
            $committed[] = $compactionNotice;
        }
        // '' means the transcript already carries the user's line - the same
        // convention {@see compactionChanges()} uses, and the case the parked
        // route in {@see applyModelCompaction()} arrives in, where the prompt was
        // echoed before the summarization request left. Without this guard that
        // route does not get two copies of the prompt (an earlier revision of this
        // comment said it did): it gets one copy plus a STRAY `Message::user('')`,
        // an empty user turn in the transcript and on the next wire.
        if ($inputText !== '') {
            $committed[] = Message::user($inputText);
        }

        $next = $this->mutate([
            'history' => [...$committed, Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
            'lastActivityAt' => new \DateTimeImmutable(),
        ]);

        return [$next, null];
    }

    /**
     * The notice the 85% tier ({@see ContextCompactor::shouldCompact()}) leaves
     * behind after rewriting history under the user.
     *
     * Role::System like the reminder, not Role::Assistant: it is the app
     * reporting on itself, and it rides along with the turn rather than
     * replacing it. Emitted on BOTH outcomes of that rewrite - the turn that
     * then goes out, and the turn {@see foregroundBlockedResponse()} refuses -
     * because what it reports is the rewrite, not the dispatch.
     *
     * Every figure names the domain it is true of, because the two token
     * numbers here are NOT the same kind of thing - the count is a chars/4
     * estimate, the window is what the provider advertises - and
     * {@see \SugarCraft\Crush\Tests\Integration\ContextWindowWiringTest} pins
     * each number against the label next to it rather than against the sentence,
     * so swapping the two reds a test.
     *
     * Position: this rides BEFORE the user turn (it reports on history that
     * already existed) while the reminder rides after it. That asymmetry is
     * deliberate and provider-safe, which was checked rather than assumed:
     * {@see \SugarCraft\Crush\Providers\VertexProvider}'s anthropic path
     * hoists every SystemMessage out of `messages` into the top-level `system`
     * field, so position cannot matter there, and
     * {@see \SugarCraft\Crush\Providers\OpenAIProvider} emits `role: system`
     * in place, which Chat Completions accepts anywhere.
     * {@see \SugarCraft\Crush\Providers\BedrockProvider} flattens
     * SystemMessage to `user` and so produces consecutive same-role turns - but
     * it already did that for the reminder and for every
     * {@see Message::toolRunning()} placeholder, so it is a pre-existing Bedrock
     * shape rather than anything this notice introduced (backlog §E19).
     */
    private function contextCompactedMessage(
        int $beforeMessages,
        int $afterMessages,
        int $savedPercentage,
        int $tokenCount,
        int $tokenLimit,
    ): Message {
        return Message::system(
            "Context reached the automatic-compaction tier, so older exchanges were "
            . "summarized: {$beforeMessages} messages -> {$afterMessages} messages, "
            . "~{$savedPercentage}% of the estimated token count freed "
            . "(~{$tokenCount} estimated tokens now, against a "
            . "{$tokenLimit}-token context window)."
        );
    }

    /**
     * Short-circuit a real prompt submission with an idle-compaction
     * advisory instead of calling the backend, mirroring how /compact
     * responds locally (see handleCompactCommand()). Also records this
     * submission as fresh activity, so the nudge does not repeat on the
     * very next message.
     *
     * What the advisory offers changed in crush_code.md Phase 5 item 5, and
     * the message had to change with it. It used to end "or send another
     * message to proceed anyway", which was true while nothing enforced the
     * window. It cannot be now: this tier fires only when the estimate is past
     * the WHOLE window ({@see IdleCompactionPolicy::shouldPrompt()}), which is
     * necessarily past the 85% and 95% tiers too, so the follow-up it invited
     * always lands on {@see submit()}'s compaction block. Measured on a
     * 26-message / 325,286-estimated-token fixture against the 100,000-token
     * fallback window: turn 1 got this advisory, turn 2 was refused and its
     * history[0] went from 50,003 chars to a summary line.
     *
     * "Refused" is not guaranteed either, which is why the text below promises
     * neither: whether the follow-up gets through depends on whether automatic
     * compaction can free enough (measured, a 2,400-message history at 122% of
     * the fallback window compacts to 1% and IS dispatched). Both outcomes
     * rewrite history, and that is the part worth saying out loud.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function idleCompactionPromptResponse(string $inputText, int $tokenCount): array
    {
        $limit = $this->contextTokenLimit();
        $response = "This session has been idle for over an hour and has grown to "
            . "~{$tokenCount} estimated tokens, past its {$limit}-token context "
            . "window. Run /compact to shrink the context before continuing. Sending "
            . "another message instead will not send it as-is: the next turn is over "
            . "the automatic-compaction tier, so older exchanges are summarized first, "
            . "and the turn is refused outright if that does not free enough.";

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
