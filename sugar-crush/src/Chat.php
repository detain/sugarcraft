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
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Forms\ItemList\LoadMoreMsg;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Config\StatusLineCommand;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\SessionPicker;
use SugarCraft\Crush\Tui\TextSelection;
use SugarCraft\Crush\Tui\Components\PaneLabel;
use SugarCraft\Crush\Support\SystemClipboard;
use SugarCraft\Crush\Attachments\FileMentions;
use SugarCraft\Crush\Support\ClipboardImage;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\QueueMode;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Events\ReasoningDelta;
use SugarCraft\Crush\Events\SpendCapBreached;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Events\TokenDelta;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Permissions\DenialKind;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionPromptStage;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Tools\ToolCall as EngineToolCall;
use SugarCraft\Crush\Commands\CommandLoader;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\McpAuthCommand;
use SugarCraft\Crush\Commands\ShareCommand;
use SugarCraft\Crush\Commands\WebSearchCommand;
use SugarCraft\Crush\Tools\BuiltIn\WebSearch;
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
use SugarCraft\Crush\Workflows\WorkflowResult;
use SugarCraft\Crush\Context\ContextCompactor;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\ContextWindow;
use SugarCraft\Crush\Context\IdleCompactionPolicy;
use SugarCraft\Crush\Context\RuleLoader;
use SugarCraft\Crush\Context\RulesState;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Session\DebouncedTranscriptWriter;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\PromptHistory;
use SugarCraft\Crush\Session\SessionLock;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Util\TokenTracker;
use SugarCraft\Crush\Util\TokenEstimate;

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
     * Since roadmap 1.C-4 an engine turn's {@see \SugarCraft\Crush\Events\StepStarted}
     * and {@see \SugarCraft\Crush\Events\UsageUpdated} ride it too, for the
     * status bar; they never reach a {@see BackendToolEventsMsg}.
     *
     * @var \ArrayObject<int, array{0: int, 1: ToolStarted|ToolFinished|SpendCapBreached|SubAgentActivity|TokenDelta|ReasoningDelta|\SugarCraft\Crush\Events\StepStarted|\SugarCraft\Crush\Events\UsageUpdated}>
     */
    private readonly \ArrayObject $liveToolEvents;

    /** @var WorkflowEngineInterface|null Optional workflow engine for /workflow command */
    private readonly ?WorkflowEngineInterface $workflowEngine;

    /**
     * The coalescing transcript writer (audit R2) — see
     * {@see DebouncedTranscriptWriter}. Carried by object identity across
     * {@see mutate()} for the reason {@see $liveToolEvents} is: the pending
     * snapshot has to reach whichever clone is on screen when the tick lands.
     */
    private readonly DebouncedTranscriptWriter $transcriptWriter;

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
     * Every prompt ↑/↓ can recall, oldest first: the {@see $promptHistory}
     * file as it stood at launch — so a fresh client's first ↑ lands on the
     * last prompt of the PREVIOUS session — plus each prompt sent through
     * Enter since. Loaded once and carried by {@see mutate()}, for the same
     * one-read-per-process reason as {@see $customCommands}.
     *
     * Empty, and unused, when no {@see $promptHistory} is wired: recall then
     * reads the transcript's own user rows, see {@see recallEntries()}.
     *
     * @var list<string>
     */
    private readonly array $inputHistory;

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

    /**
     * The rule packs this SESSION turned off with `/rules` (P6.S3).
     *
     * Mutable and shared by object identity across every {@see mutate()} clone,
     * exactly like {@see $tokenTracker} and for the same reason — a fresh instance
     * per keystroke would reset the set and silently switch every pack back on the
     * moment the user touched the keyboard. It is therefore passed through
     * `mutate()`'s property list rather than rebuilt there.
     *
     * THE SAME OBJECT THE TURN'S BACKEND HOLDS. {@see \SugarCraft\Crush\Cli\Bootstrap::chat()}
     * builds one instance and hands it to both this Chat and the
     * {@see \SugarCraft\Crush\Backend\EngineBackend} that assembles each turn's
     * App, because the writer of a toggle (a command handled here) and the reader
     * of it (the prompt splice, two layers inside the backend) are not in a call
     * chain with each other. That is also why this is never null: a Chat built by
     * an embedder or a test gets its own set, so `/rules` always has something to
     * toggle and always answers with its own transcript line instead of the
     * "not configured" degradation the optional collaborators above need.
     */
    private readonly RulesState $rulesState;

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

    /** Alias of {@see \SugarCraft\Crush\Host\CompactionService::PARK_NOTICE_PREFIX}, which documents it. */
    private const PARK_NOTICE_PREFIX = \SugarCraft\Crush\Host\CompactionService::PARK_NOTICE_PREFIX;

    /**
     * Set to any value other than empty or `0` to keep the "onToken observer
     * threw, detaching it for this turn" line on stderr (E175). The DETACH is
     * never gated — the environment decides whether anyone is TOLD about a
     * broken embedder sink, never whether the TURN survives it, and a switch
     * that could suppress the detach would let a misconfigured debug flag
     * resurrect the whole-turn loss the catch exists to prevent. Off by
     * default for the reason E154 argued: this fires mid-turn, with the
     * alternate screen held by the renderer, and the audience is the embedder
     * who owns the throwing sink rather than the person at the terminal, who
     * can do nothing with it while their reply streams normally either way.
     * Mirrors the `SUGARCRUSH_DEBUG_*` trio in SkillLoader/CommandLoader/
     * RuleLoader; {@see debugStreamRequested()} is the one funnel.
     */
    public const DEBUG_STREAM_ENV = 'SUGARCRUSH_DEBUG_STREAM';

    /**
     * Floor of the E17 calibration factor — an alias: the clamp and the
     * argument for 1.0 live on {@see \SugarCraft\Crush\Host\ContextMeter::CALIBRATION_MIN}
     * since O-2c moved the meter there. Kept so the constructor docblock's
     * citation resolves to the one value.
     */
    private const TOKEN_CALIBRATION_MIN = \SugarCraft\Crush\Host\ContextMeter::CALIBRATION_MIN;

    /**
     * Ceiling of the E17 calibration factor — an alias of
     * {@see \SugarCraft\Crush\Host\ContextMeter::CALIBRATION_MAX}, which
     * carries the argument for 3.0.
     */
    private const TOKEN_CALIBRATION_MAX = \SugarCraft\Crush\Host\ContextMeter::CALIBRATION_MAX;

    /**
     * Whether {@see DEBUG_STREAM_ENV} asks for the observer-failure report —
     * one funnel so the call site cannot drift on the gate, exactly as
     * {@see \SugarCraft\Crush\Context\RuleLoader} funnels its own refusals.
     * unset, empty and `0` all read as off, matching every other
     * `SUGARCRUSH_*` switch.
     */
    private static function debugStreamRequested(): bool
    {
        $value = getenv(self::DEBUG_STREAM_ENV);

        return $value !== false && $value !== '' && $value !== '0';
    }

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
     * Ceiling on ONE clipboard copy's character count, enforced on the
     * OSC 52 payload the frame relays for its widgets (E744 WS1).
     *
     * 65_536 = 64 KiB in characters, the family bound this codebase already
     * keeps for oversized terminal payloads (the 64 KiB stderr-tail group).
     * OSC 52 is a synchronous escape sequence: a terminal that rate-limits,
     * or a paste of a whole file copied by triple-click, can stall or
     * outright drop an oversized response, and several terminals cap the
     * sequence anyway — clipping HERE is what turns a silent terminal-side
     * loss into a visible transcript notice naming the clip. The count is
     * characters, not bytes, because the payload crosses the wire base64
     * of UTF-8 and the user's mental model of "what I selected" is glyphs.
     */
    public const OSC52_MAX_CHARS = 65_536;

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
     * Reconciliation id of the debounced transcript save's tick (audit R2) —
     * declared by {@see subscriptions()} only while a snapshot is pending.
     */
    private const TRANSCRIPT_FLUSH_SUBSCRIPTION = 'crush.transcript-flush';

    /**
     * Reconciliation id of the read-only window's lock retry (audit SES-3
     * residual) — declared by {@see subscriptions()} only while the open
     * session is read-only because another TUI holds it.
     */
    private const SESSION_LOCK_RETRY_SUBSCRIPTION = 'crush.session-lock-retry';

    /**
     * How often a read-only window retries the lock (seconds). The retry is
     * one non-blocking `flock()` on a file in the config directory
     * ({@see SessionLock::acquire()}), so the period is about how soon after
     * the other window closes this one notices, not about cost: a second reads
     * as "at once" to someone who just quit the other terminal.
     */
    private const SESSION_LOCK_RETRY_SECONDS = 1.0;

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

    /** Alias of {@see \SugarCraft\Crush\Host\CompactionService::CONTEXT_REMINDER_PREFIX}, which documents it. */
    private const CONTEXT_REMINDER_PREFIX = \SugarCraft\Crush\Host\CompactionService::CONTEXT_REMINDER_PREFIX;

    /** Alias of {@see \SugarCraft\Crush\Host\CompactionService::BLOCKED_TURN_PREFIX}, which documents it. */
    private const BLOCKED_TURN_PREFIX = \SugarCraft\Crush\Host\CompactionService::BLOCKED_TURN_PREFIX;

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
         * conversation's model or params. Null means the session is never
         * auto-titled - deliberately NOT a fallback to the main backend,
         * which may be agentic or tool-armed (audit 15b-12; see
         * {@see scheduleTitleGeneration()}). The prompt suggestion rides
         * this same backend and is likewise skipped when it is null.
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
         * @var list<array{0: ToolCall, 1: ?ToolResult, 2: ?HookContext, 3: ?\SugarCraft\Crush\Hooks\HookResult, 4: string}>
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
         * @var \ArrayObject<int, array{0: int, 1: ToolStarted|ToolFinished|SpendCapBreached|SubAgentActivity|TokenDelta|ReasoningDelta}>|null
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
         * How many AUTOMATIC-tier compactions in a row have left the context back
         * at or over the tier that asked for them — the circuit breaker's counter
         * (prompt_expand.md §4.23, which records that Claude Code shipped the
         * thrash loop and had to fix it: "now detects when context refills to the
         * limit immediately after compacting three times in a row and stops with
         * an actionable error instead of burning API calls").
         *
         * "Refills to the limit immediately" is MEASURED AT COMPACTION COMPLETION
         * and it means exactly one thing here: once the rewrite is in, the same
         * estimate the tier itself read — {@see ContextCompactor::shouldCompact()}
         * over the compacted wire against {@see contextTokenLimit()} — still says
         * over the tier. That is the pair the 85% block in {@see submit()} judged
         * to compact in the first place, so nothing new is measured and no second
         * definition of "full" is introduced. The justification for judging the
         * rewrite rather than the next prompt: if the history this compaction
         * produced is still at or over the tier, then the very next prompt
         * re-enters the tier with no work done in between, which IS the refill the
         * changelog phrase describes.
         *
         * TWO ROUTES WRITE IT, because they are the automatic tier's two outputs:
         * {@see applyModelCompaction()} when a parked summarization lands, and
         * the synchronous heuristic pass in {@see submit()} when there was no
         * model to ask. Both extend the run when the rewrite left the context over
         * its tier AND the prompt did not go out, and both break it back to zero on
         * an under-tier rewrite, so a compaction that genuinely shrinks the history
         * restores the tier without anything being latched by hand. The middle case
         * is written by NEITHER: an attempt whose prompt reached the model — rescued
         * by the intra-exchange truncation or simply never blocked — is progress
         * rather than futility, and holds the run exactly as it stands
         * (ruling P8.S5-R6).
         * {@see handleClearCommand()} resets it beside the transcript, as does
         * every session change ({@see sessionChangeResets()}), and a
         * manual `/compact` never reads or writes it at all: the user chose that
         * one, so the breaker guards only the tier that acts on its own.
         */
        private readonly int $consecutiveRefillCompactions = 0,
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
         * The submission whose turn-lifecycle hook chain is running in a forked
         * child right now (audit 15b-04), or null when none is.
         *
         * WHY IT EXISTS: a {@see Hooks\ScriptHook} on `UserPromptSubmit` or
         * `SessionStart` is a blocking `proc_open()` drain of up to 60 s, and it
         * used to run inside {@see update()}, freezing the frame, Escape and
         * Ctrl+C for as long as the script took. {@see pendTurnHooks()} now
         * forks the chain from a Cmd and parks the submission HERE, with
         * `inFlight` held so a second Enter queues ({@see enqueuePrompt()})
         * rather than racing it, and {@see resumeTurnHooks()} re-enters
         * {@see submit()} once the {@see TurnHooksResolvedMsg} lands.
         *
         * `draft`/`cursor` are the box exactly as Enter found it, so the re-entry
         * hands {@see dispatchTurn()} the same `/rewind` draft capture the
         * synchronous path did and a refusal can put the text back.
         *
         * @var ?array{text: string, draft: string, cursor: int}
         */
        private readonly ?array $pendingTurnHooks = null,
        /**
         * The verdicts a forked turn-hook chain returned, set ONLY for the length
         * of the {@see submit()} re-entry {@see resumeTurnHooks()} runs, which
         * {@see dispatchTurnHooks()} consumes instead of running the hooks a
         * second time. Matched on `text`, and cleared again as soon as the
         * re-entry returns, so it can never judge a later prompt.
         *
         * @var ?array{text: string, prompt: Hooks\HookResult, session: ?Hooks\HookResult, boxOccupied: bool}
         */
        private readonly ?array $resolvedTurnHooks = null,
        /**
         * The file-based command whose template is being expanded in a forked
         * child right now (audit 15b-20), or null when none is.
         *
         * WHY IT EXISTS: a body's `` !`…` `` forms run real shell commands under
         * a shared {@see CommandSpec::SHELL_BUDGET_SECONDS} budget, and the
         * expansion used to run inside {@see update()}, freezing the frame,
         * Escape and Ctrl+C for up to the whole budget. {@see pendCustomCommand()}
         * parks the command line HERE the way {@see $pendingTurnHooks} parks a
         * prompt, and {@see resumeCustomCommand()} re-enters {@see submit()} once
         * the {@see CustomCommandExpandedMsg} lands.
         *
         * @var ?array{text: string, draft: string, cursor: int}
         */
        private readonly ?array $pendingCustomCommand = null,
        /**
         * The expansion a forked child produced, consumed by
         * {@see expandCustomCommand()} in place of expanding the template again
         * — which would run every `` !`…` `` a second time. Matched on the typed
         * command line.
         *
         * It outlives the {@see submit()} re-entry in ONE case: when that
         * re-entry parks the expanded prompt behind a forked turn-hook chain
         * ({@see $pendingTurnHooks}), {@see resumeTurnHooks()} re-runs submit()
         * from the top once more, and the expansion has to be there for it. It
         * is cleared whenever no turn-hook chain is pending
         * ({@see withoutStaleCustomExpansion()}), so it can never expand a later,
         * separately typed command.
         *
         * @var ?array{text: string, expanded: string}
         */
        private readonly ?array $resolvedCustomCommand = null,
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
        /**
         * The launch's rulebook toggle set, shared by reference with the backend
         * that builds each turn's App. Null — which is every caller except
         * {@see \SugarCraft\Crush\Cli\Bootstrap::chat()} and a test that wants to
         * observe a toggle from both sides — allocates a fresh empty set here, so
         * `/rules` is never degraded for want of wiring.
         *
         * @see \SugarCraft\Crush\Chat::$rulesState for why one object is held by
         *      two owners instead of a copy pushed to each.
         */
        ?RulesState $rulesState = null,
        /**
         * The {@see rawTokenProxy()} figure — PRE-calibration, deliberately:
         * pairing a real against a calibrated estimate would fold the prior
         * factor into the observation — for the history THIS Chat dispatched
         * a turn with, kept from submit until the turn settles so the
         * settlement can pair it with what the provider actually counted —
         * the last-real-measurement half of E17. Null when no turn is
         * awaiting its observation; one-shot, cleared by
         * {@see turnEstimateObservation()} on the first settled AssistantMsg
         * either way, so a compaction or titler settlement can never pair
         * with a dispatch estimate it did not pay for.
         */
        private readonly ?int $promptEstimateAtDispatch = null,
        /**
         * Empirical scale factor from the ESTIMATOR's unit (script-weighted
         * {@see TokenEstimate} + 10 per message, {@see rawTokenProxy()}) to the PROVIDER's
         * counted tokens, derived from the last
         * settled turn that reported usage (E17 step (a): "use the last real
         * measurement to calibrate the estimator"). Null = no observation
         * yet, which is every offline run, every non-reporting provider, and
         * every turn before the first settlement — and it behaves exactly as
         * the raw proxy always did. Clamped on the way in to
         * [{@see TOKEN_CALIBRATION_MIN}, {@see TOKEN_CALIBRATION_MAX}]: the
         * observation can tighten the tier's estimate but can never loosen
         * the raw proxy below itself. {@see estimateTokenCount()} is the sole
         * reader; see it for the unit caveat the clamp bounds exist to cap.
         */
        private readonly ?float $tokenEstimateCalibration = null,
        /**
         * The search tool `/websearch` runs on, so the command's success path
         * is reachable without a live SearXNG endpoint.
         *
         * Null — every caller but a test — lets {@see WebSearchCommand} build
         * its own {@see WebSearch} exactly as before, so nothing about a real
         * launch changes. The seam exists because the transcript-wiring test
         * for `/websearch` could only assert `Role::Assistant` on the reply
         * when the configured endpoint happened to answer; the day it did
         * not, the command took its failure branch, appended a
         * `Role::System` notice, and the test reported an endpoint outage as
         * a transcript regression.
         */
        private readonly ?WebSearch $webSearch = null,
        /**
         * The grayed "you might say next" suggestion the empty input box
         * shows (→ accepts it), with the conversation state it was written
         * for: the turn `generation`, the `history` length and the session.
         * {@see promptSuggestion()} answers null the moment any of those
         * moves, so no reset site - a new turn, a /clear, a session switch,
         * an appended notice - can leave a stale suggestion on screen.
         * Written by {@see PromptSuggestionMsg} only.
         *
         * @var array{text: string, generation: int, historyCount: int, sessionId: ?string}|null
         */
        private readonly ?array $promptSuggestion = null,
        /**
         * The cross-session prompt file ↑/↓ recall reads at launch and every
         * Enter appends to. Null — tests and embedders — keeps recall on the
         * transcript's user rows, exactly as before the file existed;
         * {@see \SugarCraft\Crush\Cli\Bootstrap::chat()} wires the real one.
         */
        private readonly ?PromptHistory $promptHistory = null,
        /** Pre-resolved {@see $inputHistory}; null reads {@see $promptHistory}. */
        ?array $inputHistory = null,
        /**
         * Index into {@see recallEntries()} of the entry ↑/↓ last put in the
         * box, or null when the user is not walking history. Walking stops by
         * itself the moment the draft stops matching that entry — see
         * {@see isWalkingHistory()} — so no edit path has to clear it.
         */
        private readonly ?int $inputHistoryCursor = null,
        /** The draft the box held when the walk began; ↓ past the newest entry gives it back. */
        private readonly string $inputHistoryDraft = '',
        /**
         * Whether the turn in flight is a `/workflow run` or `/workflow resume`
         * — a fiber {@see driveWorkflowFiber()} steps — rather than a model
         * turn (audit WF-4). Read only by {@see submit()}'s mid-turn gate,
         * which lets `/workflow pause` and `/workflow status` through while it
         * holds: pausing the run is the one thing the user needs to do to it
         * while it runs, and neither command touches the history the run is
         * appending to (pause writes the pause file, status reads state).
         *
         * Set by the two commands that start such a turn and cleared by the two
         * exits it has: the AssistantMsg arm that settles it and the
         * double-Escape cancel arm. Nothing else can end the turn — every other
         * command is refused while it runs — so the flag cannot outlive it.
         */
        private readonly bool $workflowTurnInFlight = false,
        /** Shared transcript writer; null builds this lineage's own. See {@see $transcriptWriter}. */
        ?DebouncedTranscriptWriter $transcriptWriter = null,
        /**
         * Whether this Chat takes the single-writer lock on the session it has
         * open (audit SES-3(b)). Off by default — tests and embedders that
         * build two Chats on one store keep working — and switched on by
         * {@see withSessionLocking()}, which {@see \SugarCraft\Crush\Cli\Bootstrap::chat()}
         * calls on every real launch.
         */
        private readonly bool $sessionLocking = false,
        /** The lock this Chat holds on {@see $currentSessionId}, when locking is on and it won it. */
        private readonly ?SessionLock $sessionLock = null,
        /**
         * True when another TUI holds the open session's lock: the transcript
         * is shown, but nothing that would write to the session or start a
         * turn runs, and nothing is saved. `/branch` forks the session into a
         * new one this window owns. See {@see readOnlyRefusal()}.
         */
        private readonly bool $readOnlySession = false,
        /**
         * The last draft a read-only window refused, held so the box is free
         * for `/branch` and put back once the fork succeeds — see
         * {@see refuseReadOnly()}.
         */
        private readonly ?string $readOnlyDraft = null,
        /**
         * The words left on the command line (`sugarcrush fix the bug`),
         * submitted as the first prompt from {@see init()} (audit CLI-2(b)).
         */
        private readonly ?string $initialPrompt = null,
        /**
         * `\Closure(string $provider): Backend` — how a provider switch
         * (Ctrl+P "Switch model", `/model <provider>`) builds the replacement
         * backend (N-P3a). {@see \SugarCraft\Crush\Cli\Bootstrap::chat()}
         * passes one that captures the launch's skill registry,
         * {@see $rulesState}, agent manager and a worker pool rebuilt for the
         * new provider, so the switched engine keeps `Task` and the session's
         * `/rules` toggles. Null — embedders and tests — falls back to
         * {@see \SugarCraft\Crush\Cli\Bootstrap::backendFor()} with what
         * this Chat holds; see {@see selectPaletteProvider()}.
         */
        private readonly ?\Closure $backendFactory = null,
        /**
         * The project-root collaborators this Chat's session runs in — the
         * non-UI half of {@see \SugarCraft\Crush\Cli\Bootstrap::chat()}, built by
         * {@see \SugarCraft\Crush\Cli\Bootstrap::workspace()} (roadmap O-2a).
         * Null for embedders and tests, which keep every collaborator they
         * passed above.
         *
         * THE LAST CONSTRUCTOR SLOT THE HOST EXTRACTION NEEDS. The `Host\*`
         * services O-2b…O-2h move out of this class register on the workspace
         * and are read through
         * {@see \SugarCraft\Crush\Host\WorkspaceContext::service()}, so a
         * service that leaves Chat does not arrive back as a parameter here.
         * Today it is read by {@see selectPaletteProvider()} (its backend
         * factory, ahead of {@see $backendFactory}) and by the runtime-notice
         * pump ({@see pumpRuntimeNotices()}, {@see runtimeNoticeWake()}),
         * which drain the workspace's own inbox.
         */
        private readonly ?\SugarCraft\Crush\Host\WorkspaceContext $workspace = null,
        /**
         * Roadmap 1.C-4: the latest `step` frame of an engine turn — its step
         * number and the request's context pressure — read by the status
         * bar and by the Escape arm (a turn that reports steps can stop at a
         * boundary, so its first Escape is a soft cancel). Stamped with the
         * {@see $generation} it arrived under ({@see $liveStepGeneration}), so
         * a later turn never shows an earlier one's step; read through
         * {@see liveStep()}.
         */
        private readonly ?\SugarCraft\Crush\Events\StepStarted $liveStep = null,
        /** Roadmap 1.C-4: the latest `usage` frame of that turn — see {@see liveUsage()}. */
        private readonly ?\SugarCraft\Crush\Events\UsageUpdated $liveUsage = null,
        /** The generation {@see $liveStep} and {@see $liveUsage} belong to. */
        private readonly int $liveStepGeneration = -1,
        /**
         * Who set {@see $currentSessionName} (roadmap P-A4): `User` for
         * `/rename` and the inline title editor, `Auto` for a latched
         * generated title, null when unknown — unnamed, or a name read back
         * from the store on a switch or resume. The UI half of the audit B2
         * latch: the {@see SessionTitledMsg} arm never replaces a `User` name,
         * nor any name it cannot vouch was generated. Reset to null by
         * {@see mutate()} whenever the session changes.
         */
        private readonly ?\SugarCraft\Crush\Session\TitleSource $currentSessionTitleSource = null,
        /**
         * The inline session-title editor (roadmap P-A4), or null when closed.
         * Opened by a bare `/rename`, the palette's "Rename session…" and a
         * double-click on the current tab; while open it owns the keyboard
         * ({@see handleTitleEditorKey()}), Renderer paints it as a row above
         * the input box and mirrors the draft into the current tab. Closed by
         * {@see mutate()} whenever the session changes, so a draft opened for
         * one session can never rename another.
         */
        private readonly ?\SugarCraft\Forms\TextInput\TextInput $titleEditor = null,
        /**
         * The last click on a session tab — `[tab id, microtime]` — so a
         * second click on the same tab within {@see TAB_DOUBLE_CLICK_SECONDS}
         * reads as a double-click. Kept here because candy-mouse's
         * `ClickResult` carries a zone and a button, never a click count.
         *
         * @var array{0: string, 1: float}|null
         */
        private readonly ?array $lastTabClick = null,
        /**
         * Settings keys a save changed while a turn was running whose apply
         * rebuilds the engine (roadmap N-P3, Appendix N §4.8) — parked here
         * and applied by {@see update()} once no turn is in flight, so the
         * backend is never swapped under a turn whose completion handlers
         * still hold the old one. See {@see applySettings()}.
         *
         * @var list<string>
         */
        private readonly array $pendingSettingsApply = [],
        /**
         * What the last settings save did, as a sugar-toast alert painted
         * over the frame's top-right corner (N-P3), or null. Never a
         * transcript row: a "setting changed" row would be sent to the
         * provider with every later turn. Cleared by the
         * {@see \SugarCraft\Crush\Tui\Settings\SettingsToastExpiredMsg} its own
         * tick delivers.
         */
        private readonly ?\SugarCraft\Toast\Toast $settingsToast = null,
        /** Stamp of {@see $settingsToast}: an older toast's expiry tick must not clear a newer one. */
        private readonly int $settingsToastGeneration = 0,
    ) {
        $this->transcriptWriter = $transcriptWriter ?? new DebouncedTranscriptWriter();
        $this->inputHistory = $inputHistory ?? ($promptHistory?->entries() ?? []);
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
        // Same seam, same reason, for the BACKEND: a /workflow stage runs this
        // Chat's engine tool loop (Agents\EngineExecutor). Re-bound on every
        // construction rather than once, because a provider switch builds a
        // Chat with a new backend while the engine is shared across mutate().
        if ($workflowEngine instanceof WorkflowEngine && $backend instanceof \SugarCraft\Crush\Backend\EngineBackend) {
            $workflowEngine->bindEngineBackend($backend);
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

        // Per-model absolute caps (2.9) resolve against the backend's model.
        // The constructor runs on every mutate(), so a `/model` switch — a new
        // backend — rebuilds the compactor for the new model; with no override
        // naming the model forModel() is the base config itself.
        $compactorConfig = $this->compactorConfig ?? CompactorConfig::new();
        if ($this->backend instanceof Backend\EngineBackend) {
            $compactorConfig = $compactorConfig->forModel($this->backend->model(), $this->backend->provider()->name());
        }
        $this->compactor = new ContextCompactor($compactorConfig);
        $this->tokenTracker = $tokenTracker ?? new TokenTracker();
        $this->rulesState = $rulesState ?? RulesState::new();
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
        $wake = $this->runtimeNoticeWake();
        if ($this->initialPrompt === null) {
            return $wake;
        }

        // The command-line prompt (audit CLI-2(b)) rides init() as a Msg so
        // the turn starts from update(), where its Cmd reaches the Program —
        // the same road a prompt typed into the box and sent with Enter takes.
        $prompt = Cmd::send(new InitialPromptMsg($this->initialPrompt));

        return $wake === null ? $prompt : Cmd::batch($wake, $prompt);
    }

    /**
     * Submit the command-line prompt {@see init()} delivered, exactly as if it
     * had been typed into the box and sent — so a read-only session refuses it
     * and keeps it as the draft, and a slash command runs as a command.
     *
     * With the session picker up (bare `--resume`), choosing comes first: the
     * words become the draft, and Enter sends them once a session is picked.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function submitInitialPrompt(string $prompt): array
    {
        $next = $this->mutate(['initialPrompt' => null, 'inputBuf' => $prompt]);

        if ($next->sessionPicker !== null) {
            return [$next, null];
        }

        return $next->submit();
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
        // O-2a: the workspace's inbox when this Chat runs in one, else the
        // process's current sink — the same instance on every launch
        // Bootstrap builds, since workspace() arms the process sink.
        $sink = $this->workspace?->notices ?? RuntimeNoticeSink::current();

        if (!$this->drainsRuntimeNotices || !$sink->hasTransport()) {
            return null;
        }

        return Cmd::promise(static function () use ($sink): PromiseInterface {
            $deferred = new Deferred();

            $armed = $sink->notifyOnceWhenPending(
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
        if ($msg instanceof \SugarCraft\Crush\Tui\Settings\SettingsToastExpiredMsg) {
            return [
                $msg->generation === $this->settingsToastGeneration && $this->settingsToast !== null
                    ? $this->mutate(['settingsToast' => null])
                    : $this,
                null,
            ];
        }

        [$next, $cmd] = $this->route($msg);

        // N-P3: a settings save that rebuilds the engine waited out the turn
        // that was running ({@see applySettings()}); the first message after
        // which nothing is in flight applies it. Here rather than at each of
        // the routes that settle a turn, so no settle path can forget it.
        if ($next instanceof self && $next->pendingSettingsApply !== [] && !$next->inFlight) {
            [$next, $released] = $next->releasePendingSettings();
            $cmd = $cmd === null ? $released : Cmd::batch($cmd, $released);
        }

        if ($next instanceof self && $next !== $this) {
            // A route that moved to another session (picker, tab, Ctrl+Tab,
            // `/branch`, palette New session) hands the lock over BEFORE the
            // save below is considered, so a `/branch` out of a read-only
            // session saves into the branch it now owns.
            if ($next->currentSessionId !== $this->currentSessionId) {
                $next = $next->relockedForCurrentSession();
            }

            // Hands the snapshot to the debounced writer; the write itself
            // rides the tick subscriptions() declares while one is pending, so
            // no route's Cmd changes shape and no cancel arm can drop it.
            $next->persistTranscript($this);
        }

        return [$next, $cmd];
    }

    /**
     * Save the transcript whenever a message changed it, so the session can be
     * resumed later exactly as it stood ({@see switchToSession()},
     * `sugarcrush --continue`).
     *
     * Keyed on the history ARRAY changing, which is an identity check on the
     * common path: {@see mutate()} hands an untouched history through as the
     * same array, so a keystroke costs one pointer comparison and no write.
     * Mid-turn changes are saved too — a tool row landing is exactly the state
     * a crash would otherwise lose, and a "running" placeholder saved that way
     * is healed into an "interrupted" row when it is resumed
     * ({@see reviveTranscriptMessage()}).
     *
     * DEBOUNCED SINCE AUDIT R2. This used to write the whole conversation
     * synchronously inside `update()` on every change. It now hands the
     * snapshot to {@see DebouncedTranscriptWriter}, which writes the newest one
     * when the {@see TRANSCRIPT_FLUSH_SUBSCRIPTION} tick lands — at most once
     * per {@see DebouncedTranscriptWriter::DELAY_SECONDS} — and synchronously on
     * a session switch, before a fork and at shutdown; see that class for the
     * full list and what a SIGKILL can still lose.
     *
     * A READ-ONLY session never saves (audit SES-3(b)): another TUI owns it.
     *
     * The write itself is {@see \SugarCraft\Crush\Host\TranscriptStore}'s
     * (roadmap O-2b), so a host without a screen saves a transcript exactly
     * as the TUI does.
     */
    private function persistTranscript(self $previous): void
    {
        if ($this->readOnlySession
            || $this->currentSessionId === null
            || !$this->sessionStore instanceof EnhancedSessionStore
            || $this->history === $previous->history
        ) {
            return;
        }

        $this->transcripts()->schedule($this->currentSessionId, $this->history);
    }

    /**
     * This session's {@see \SugarCraft\Crush\Host\TranscriptStore} (roadmap
     * O-2b): the one the workspace registered on its
     * {@see \SugarCraft\Crush\Host\WorkspaceContext::service()} locator when
     * it is over this Chat's own session store, else one built over that
     * store — so an embedder or a test with no workspace still persists, and
     * a workspace whose store is not this Chat's is never written through by
     * mistake. Either way it is bound to THIS lineage's
     * debounced writer, the one {@see subscriptions()}' flush tick and the
     * shutdown flush watch; a snapshot scheduled anywhere else would wait for
     * a tick nobody declared. Built per call rather than held: the locator is
     * how the O-2 extractions avoid growing Chat's state.
     */
    private function transcripts(): \SugarCraft\Crush\Host\TranscriptStore
    {
        $registered = $this->workspace?->service(\SugarCraft\Crush\Host\TranscriptStore::class);
        if ($registered instanceof \SugarCraft\Crush\Host\TranscriptStore
            && $registered->store() === $this->sessionStore
        ) {
            return $registered->withWriter($this->transcriptWriter);
        }

        return \SugarCraft\Crush\Host\TranscriptStore::new($this->sessionStore, $this->transcriptWriter);
    }

    /**
     * Write any transcript change still waiting on its debounce tick, now.
     *
     * Public for the host and for tests: the TUI never needs to call it — the
     * writer flushes on its own tick, on session switches and forks, and at
     * shutdown — but an embedder that drives `update()` without running
     * {@see subscriptions()}, or a test asserting on the store, does.
     */
    public function flushTranscript(): void
    {
        $this->transcripts()->flush();
    }

    /**
     * Turn on the single-writer session lock (audit SES-3(b)) and take it for
     * the session this Chat has open.
     *
     * When another TUI already holds that session, the Chat comes back
     * READ-ONLY: the transcript is on screen, a notice says who has it and
     * offers `/branch` to fork it, and nothing typed is sent or saved until
     * the user forks or switches to a session no one else has open — or the
     * other TUI lets go, which the lock retry {@see subscriptions()} ticks
     * while read-only notices within a second ({@see retakenSessionLock()}). Every
     * later session switch moves the lock with it
     * ({@see relockedForCurrentSession()}).
     *
     * A no-op without an {@see EnhancedSessionStore} or an open session.
     */
    public function withSessionLocking(): self
    {
        return $this->mutate(['sessionLocking' => true])->relockedForCurrentSession();
    }

    /** True while another TUI owns the open session — see {@see withSessionLocking()}. */
    public function isReadOnlySession(): bool
    {
        return $this->readOnlySession;
    }

    /**
     * Submit $prompt as this session's first prompt once {@see init()} runs —
     * the leftover command-line words (audit CLI-2(b)). Null or blank clears it.
     */
    public function withInitialPrompt(?string $prompt): self
    {
        $prompt = $prompt === null || trim($prompt) === '' ? null : $prompt;

        return $this->mutate(['initialPrompt' => $prompt]);
    }

    /**
     * Release the lock on the session being left and take the one for the
     * session now open, deciding read-only afresh.
     *
     * Releasing FIRST matters only for a switch back to a session this window
     * held a moment ago through a stale clone; taking the new lock first would
     * then fail against ourselves.
     */
    private function relockedForCurrentSession(): self
    {
        if (!$this->sessionLocking) {
            return $this;
        }

        $id = $this->currentSessionId;
        if ($this->sessionLock !== null && $this->sessionLock->sessionId() === $id) {
            return $this;
        }

        $this->sessionLock?->release();

        if ($id === null || !$this->sessionStore instanceof EnhancedSessionStore) {
            return $this->mutate(['sessionLock' => null, 'readOnlySession' => false]);
        }

        $transcripts = $this->transcripts();
        $lock = $transcripts->lock($id);
        if ($lock !== null) {
            return $this->mutate(['sessionLock' => $lock, 'readOnlySession' => false]);
        }

        return $this->mutate([
            'sessionLock' => null,
            'readOnlySession' => true,
            'history' => [...$this->history, Message::notice(sprintf(
                self::READ_ONLY_SESSION_NOTICE,
                $this->currentSessionName ?? $id,
                self::lockHolderClause($transcripts->lockHolder($id)),
            ))],
        ]);
    }

    /**
     * The read-only window's way back to writable (audit SES-3 residual): the
     * lock retry {@see subscriptions()} ticks while {@see $readOnlySession}
     * is set. Unchanged — the same instance, so `update()` saves nothing —
     * while the other TUI still holds the session.
     *
     * Before this a read-only window became writable only on a session
     * switch, `/branch` or a relaunch, so closing the OTHER terminal left this
     * one refusing every prompt on a session nobody held any more.
     *
     * THE TRANSCRIPT IS RELOADED FROM THE STORE, never kept: the holder kept
     * writing after this window loaded, and this window's copy is the older
     * one. Saving it — which the first change after the upgrade would do —
     * would erase whatever the holder added, the very loss the lock exists to
     * prevent. The reload also closes the "loaded before locking" stale-load
     * residual for this path. The state tied to the transcript being replaced
     * goes with it ({@see sessionChangeResets()}), and a draft a refusal
     * stashed comes back into an empty box, as `/branch` puts it back.
     *
     * NOT WHILE A REQUEST IS IN FLIGHT: replacing the history under a running
     * request would hand its reply a transcript it was not asked about. The
     * retry simply waits for the next tick.
     */
    private function retakenSessionLock(): self
    {
        $id = $this->currentSessionId;
        if (!$this->readOnlySession
            || !$this->sessionLocking
            || $this->inFlight
            || $id === null
            || !$this->sessionStore instanceof EnhancedSessionStore
        ) {
            return $this;
        }

        $transcripts = $this->transcripts();
        $lock = $transcripts->lock($id);
        if ($lock === null) {
            return $this;
        }

        // Anything still waiting on the debounce tick is written first, for
        // the reason switchToSession() does. A read-only window schedules
        // nothing (persistTranscript()), so this is a guard, not a save.
        $transcripts->flush();
        $draft = $this->readOnlyDraft;

        return $this->mutate([
            ...$this->sessionChangeResets(),
            'sessionLock' => $lock,
            'readOnlySession' => false,
            'readOnlyDraft' => null,
            'history' => [...$transcripts->load($id), Message::notice(sprintf(
                self::SESSION_WRITABLE_NOTICE,
                $this->currentSessionName ?? $id,
            ))],
            ...($draft !== null && trim($this->inputBuf) === '' ? ['inputBuf' => $draft] : []),
        ]);
    }

    /** ` (pid N)` for the read-only notices, or '' when the holder is unknown. */
    private static function lockHolderClause(?int $pid): string
    {
        return $pid === null ? '' : " (pid {$pid})";
    }

    /**
     * Rebuild one saved transcript row for a resume: every field
     * {@see Message::fromArray()} can restore - tool calls and results,
     * reasoning, usage - with one repair. A "running" placeholder in a saved
     * transcript is a tool call whose process is gone, so it comes back as the
     * same "interrupted" error row {@see reviveCheckpointMessage()} makes for
     * `/rewind`; left as a placeholder it would spin forever for a result that
     * cannot arrive.
     *
     * The builder is {@see \SugarCraft\Crush\Host\TranscriptStore::reviveRow()}
     * since roadmap O-2b; kept here, by name, for the callers and tests that
     * cite it.
     *
     * @param array<string, mixed> $row
     */
    public static function reviveTranscriptMessage(array $row): Message
    {
        return \SugarCraft\Crush\Host\TranscriptStore::reviveRow($row);
    }

    /**
     * $history with every "running" placeholder replaced by the same
     * "interrupted" row {@see reviveCheckpointMessage()} builds for `/rewind`
     * and {@see reviveTranscriptMessage()} for a resume - the live-session
     * twin of those two, for a turn the user aborted (audit 15b-02).
     *
     * Reusing that builder rather than writing a third shape keeps all three
     * "this call lost its runner" paths rendering identically and keeps the
     * wire valid: the healed row carries an error tool_result under the SAME
     * call id, so the next request has no `tool_use` block left unanswered.
     * The thought that led to the call rides along, as it does onto a
     * finished row ({@see replaceToolRunningPlaceholder()}).
     *
     * The REASON differs (audit R15): nothing restarted here, the user
     * cancelled, and the row's text is what the model reads about the call on
     * the next request, so it says {@see CANCELLED_TOOL_CALL}.
     *
     * @return list<Message>
     */
    private function historyWithInterruptedPlaceholders(): array
    {
        return array_map(static function (Message $message): Message {
            if ($message->pendingToolCallId === null) {
                return $message;
            }

            $healed = self::interruptedToolCallMessage(
                $message->content,
                $message->pendingToolCallId,
                self::CANCELLED_TOOL_CALL,
            );

            return trim((string) $message->reasoning) !== ''
                ? $healed->withReasoning($message->reasoning)
                : $healed;
        }, $this->history);
    }

    /**
     * The saved transcript of $sessionId as Messages, or [] when the store
     * has none (or cannot say) —
     * {@see \SugarCraft\Crush\Host\TranscriptStore::load()} over $store.
     *
     * @return list<Message>
     */
    public static function loadTranscript(
        \SugarCraft\Crush\Session\SessionStore|EnhancedSessionStore|null $store,
        string $sessionId,
    ): array {
        return \SugarCraft\Crush\Host\TranscriptStore::new($store)->load($sessionId);
    }

    /**
     * Make $sessionId the current session AND put its conversation back on
     * screen - the one route every session switch takes (the picker's Enter,
     * a tab click, Ctrl+Tab).
     *
     * Switching the id alone was the old behaviour and it was worse than a
     * no-op: the model kept the previous session's transcript under the new
     * id, so "resuming" a conversation continued a different one, and with
     * transcripts now saved on change it would also have written that
     * conversation over the resumed session's own.
     *
     * Everything tied to the transcript being replaced is released with it -
     * see {@see sessionChangeResets()} for the list and the reason for each.
     */
    private function switchToSession(string $sessionId, ?string $name): self
    {
        // The session being left may still have a save waiting on its debounce
        // tick (audit R2). Written now, so switching straight back reads it.
        $transcripts = $this->transcripts();
        $transcripts->flush();
        $history = $transcripts->load($sessionId);

        return $this->mutate([
            ...$this->sessionChangeResets(),
            'currentSessionId' => $sessionId,
            'currentSessionName' => $name,
            // P-A4: who named the resumed session, from its row — so an
            // auto-titled name stays replaceable and a user's stays latched.
            'currentSessionTitleSource' => $name === null ? null : $this->storedTitleSource($sessionId),
            'history' => [...$history, Message::notice(
                '_Resumed session ' . ($name ?? $sessionId) . '._',
            )],
        ]);
    }

    /**
     * The state that belongs to the conversation being LEFT, reset whenever a
     * different session is put in front of the user: {@see switchToSession()}
     * (picker Enter, tab click, Ctrl+Tab) and the palette's New session
     * ({@see handlePaletteNewSession()}). Shared so the two routes cannot drift
     * apart again - the breaker reset was once present on `/clear` alone
     * (audit 15b-06).
     *
     * - `pendingCompactionId`: a `/compact` issued against the old transcript
     *   must not rewrite the new one when its summary lands.
     * - `queuedPrompts`: typed for the old conversation, not this one.
     * - `scrollOffset`: indexes into the transcript that went away.
     * - `consecutiveRefillCompactions`: the thrash breaker counts refills of ONE
     *   context - the same reason {@see handleClearCommand()} resets it. Carried
     *   across, a tripped run in session A refused the first prompt of session B
     *   through {@see thrashBreakerRefusal()} before B had compacted once.
     * - `lastActivityAt`: when the user last prompted the OLD session. Read
     *   against the new session's size it would judge B idle (or active) on A's
     *   clock; null is "idleness unknown", which {@see IdleCompactionPolicy}
     *   never prompts on - the same state a fresh launch or `--continue` starts
     *   in. The next real prompt stamps it again.
     *
     * @return array<string, mixed>
     */
    private function sessionChangeResets(): array
    {
        return [
            'pendingCompactionId' => null,
            'queuedPrompts' => [],
            'scrollOffset' => 0,
            'consecutiveRefillCompactions' => 0,
            'lastActivityAt' => null,
        ];
    }

    /**
     * Who set the name the store holds for $sessionId (its `title_source`),
     * or null when the row records none.
     */
    private function storedTitleSource(string $sessionId): ?\SugarCraft\Crush\Session\TitleSource
    {
        $row = $this->sessionStore?->getSession($sessionId);

        return \is_array($row) ? \SugarCraft\Crush\Session\TitleSource::fromStored($row['title_source'] ?? null) : null;
    }

    /**
     * The name the store holds for $sessionId, or null when it has none.
     */
    private function storedSessionName(string $sessionId): ?string
    {
        if ($this->sessionStore === null) {
            return null;
        }

        $row = $this->sessionStore->getSession($sessionId);
        $stored = \is_array($row) ? (string) ($row['name'] ?? '') : '';

        return $stored !== '' ? $stored : null;
    }

    /**
     * Open the session picker over the chat, as Ctrl+R and `/sessions` do -
     * the launch-time door for `sugarcrush --resume` with no id. Unchanged
     * when there is no store or nothing to pick.
     */
    public function withSessionPickerOpen(): self
    {
        $picker = $this->buildSessionPicker();

        return $picker === null ? $this : $this->mutate(['sessionPicker' => $picker]);
    }

    /**
     * @return array{0: Model, 1: ?\Closure}
     */
    private function route(Msg $msg): array
    {
        // Roadmap P-C2: the shell left the Agent View. The key that left it
        // (Esc) never reached this chat, but an Esc pressed BEFORE the view
        // opened may still be armed — so the first Esc after the view closes
        // is a first press again, never the second half of a turn cancel.
        if ($msg instanceof CloseAgentViewMsg) {
            return [$this->lastEscapeAt === null ? $this : $this->mutate(['lastEscapeAt' => null]), null];
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

            // The turn is over and nothing queued took its place: guess the
            // user's next message in the background (→ accepts it). Batched
            // beside, never before, whatever the drain scheduled.
            $suggest = $done->inFlight ? null : $done->schedulePromptSuggestion();

            // Roadmap 5.2, same seam and same rule: auto-memory consolidation
            // on the tool-less summary backend, throttled on disk to one run
            // per project per five minutes. Only the gating runs here; the
            // request runs in the backend's fork and the notes are written in
            // this process as its promise settles (MemoryConsolidatedMsg).
            $consolidate = $done->inFlight || $done->memoryStore === null ? null
                : \SugarCraft\Crush\Memory\AutoMemoryConsolidator::new(
                    \SugarCraft\Crush\Memory\MemoryWriter::new($done->memoryStore, $done->projectRoot()),
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
                : \SugarCraft\Crush\Memory\DreamPass::new(
                    \SugarCraft\Crush\Memory\MemoryWriter::new($done->memoryStore, $done->projectRoot()),
                )->call($done->backend, $done->spendCapReached(), $done->currentSessionId);
            $cmds = array_values(array_filter([
                $doneCmd,
                $suggest,
                $consolidate === null ? null : Cmd::promise($consolidate),
                $autoCommit,
                $dream === null ? null : Cmd::promise($dream),
            ]));

            return [$done, match (count($cmds)) {
                0 => null,
                1 => $cmds[0],
                default => Cmd::batch(...$cmds),
            }];
        }
        if ($msg instanceof DreamPassCompletedMsg) {
            // Roadmap 5.4-3: the notes, the memory-history commit and the
            // journal cursor are already written (in this process, as the
            // turn settled). Accounted first — the pass ran on the user's
            // key — then one display-only notice when a note was saved,
            // changed or removed; a pass that changed nothing says nothing.
            $this->accountUsage($msg->usage);
            $notice = \SugarCraft\Crush\Memory\DreamPass::notice($msg);
            if ($notice === null) {
                return [$this, null];
            }

            return [$this->mutate(['history' => [...$this->history, Message::notice($notice)]]), null];
        }
        if ($msg instanceof \SugarCraft\Crush\Workspace\AutoCommittedMsg) {
            // Step 3.G: the commit is already made (or refused). Accounted
            // first — the subject came from a model call on the user's key —
            // then one display-only line saying what was committed, or why not.
            $this->accountUsage($msg->usage);

            return [$this->mutate(['history' => [...$this->history, Message::notice($msg->notice())]]), null];
        }
        if ($msg instanceof MemoryConsolidatedMsg) {
            // Roadmap 5.2: the notes are already written (in this process,
            // as the call settled). Accounted first, on the rule every
            // provider-call arm follows, then one display-only notice when a
            // note was saved, changed or removed; a run that saved nothing
            // says nothing. Shown even after a session switch: the notes are
            // the project's, not the session's, and a write is never silent.
            $this->accountUsage($msg->usage);
            $notice = \SugarCraft\Crush\Memory\AutoMemoryConsolidator::notice($msg);
            if ($notice === null) {
                return [$this, null];
            }

            return [$this->mutate(['history' => [...$this->history, Message::notice($notice)]]), null];
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
        if ($msg instanceof CancelledWorkflowReportMsg) {
            // The report of a run Esc Esc cancelled: appended, and NOTHING
            // else — the turn it occupied was released by the cancel, and
            // whatever turn is running now is not this report's to settle.
            return [$this->mutate(['history' => [...$this->history, $msg->message]]), null];
        }
        if ($msg instanceof InitialPromptMsg) {
            return $this->submitInitialPrompt($msg->prompt);
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
        if ($msg instanceof TurnHooksResolvedMsg) {
            return $this->resumeTurnHooks($msg);
        }
        if ($msg instanceof CustomCommandExpandedMsg) {
            return $this->resumeCustomCommand($msg);
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
        if ($msg instanceof PromptSuggestionMsg) {
            // Accounted first, on the rule every provider-call arm follows:
            // the call was made on the user's key whatever becomes of it.
            $this->accountUsage($msg->usage);

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
            $this->accountUsage($msg->usage);

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
                $this->currentSessionTitleSource === \SugarCraft\Crush\Session\TitleSource::User
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
                'currentSessionTitleSource' => \SugarCraft\Crush\Session\TitleSource::Auto,
            ]), null];
        }
        if ($msg instanceof BackgroundSessionSpawnedMsg) {
            // Unlike the title call above this is NOT session-scoped: the
            // user asked for it out loud with a slash command, so the answer
            // belongs in whatever transcript is in front of them now.
            $notice = \SugarCraft\Crush\Host\Commands\BackgroundCommand::spawnedNotice($msg);

            return [$this->mutate(['history' => [...$this->history, Message::assistant($notice)->withUiOnly()]]), null];
        }
        if ($msg instanceof BackgroundSessionStoppedMsg) {
            // Record the settled status as already announced, so the next
            // background poll does not repeat it as "is now stopped".
            $statuses = $this->backgroundStatuses;
            $settled = $this->backgroundSupervisor?->getSession($msg->sessionId);
            if ($settled !== null) {
                $statuses[$msg->sessionId] = $settled->status->value;
            }

            return [$this->mutate([
                'history' => [...$this->history, Message::assistant(self::backgroundStopNotice($msg))->withUiOnly()],
                'backgroundStatuses' => $statuses,
            ]), null];
        }
        if ($msg instanceof BackgroundTickMsg) {
            return $this->pumpBackgroundSessions();
        }
        if ($msg instanceof WindowSizeMsg) {
            // The one authoritative size - see the constructor docblock on
            // $rows/$cols for why Renderer must read these instead of
            // querying terminal size itself.
            // A reflow moves every cell, so a highlight measured on the old
            // frame would sit over text it never selected.
            self::$textSelection = null;

            return [$this->mutate(['rows' => $msg->rows, 'cols' => $msg->cols]), null];
        }
        if ($msg instanceof MouseMsg) {
            return $this->handleMouse($msg);
        }
        if ($msg instanceof PasteMsg) {
            // E704. The whole bracketed payload — `InputReader` collects the
            // bytes between `CSI 200~` and `CSI 201~` (only ever emitted once
            // {@see programOptions()} asks for mode 2004) into ONE message, so
            // a multi-line paste is one atomic edit rather than the byte-burst
            // that used to dispatch one submit per line. Inserted AT the caret
            // through TextArea's string seam; the cursor lands at the END of
            // the pasted text, which is the law every other single edit of
            // this box follows. It does not submit and it does not answer a
            // permission prompt: the Enter that follows a paste is the user's
            // own keystroke and submits the WHOLE draft once, through
            // {@see submit()}'s existing trim — content is inserted verbatim,
            // with no pre-trim, because the boundary sanitize upstream
            // (ProgramOptions::$sanitizePaste, default ON) is the one place
            // untrusted bytes get stripped. The companion PasteEndMsg carries
            // no payload and stays dropped below, with every other non-KeyMsg.
            //
            // E744 WS3 routes this through the widget's OWN paste arm so the
            // frame stops owning a private copy of the draft-edit law: the
            // widget replaces a live selection before inserting (E736 5.13),
            // and on an empty selection `deleteSelection()` returns the
            // editor unchanged — making the focused no-selection outcome
            // byte-identical to the insertString call this arm used to make.
            // A dropped/unfocused editor answers its paste with the SAME
            // instance, and the fallback below keeps this box's pre-E744
            // contract for that state verbatim: paste lands regardless.
            //
            // Audit 15b-15: a paste that is nothing but the path of an image
            // file - what dropping a screenshot onto most terminals types -
            // becomes an `@` mention of it instead, so the image is attached
            // when the prompt is sent rather than its path sent as prose.
            $imagePath = self::pastedImagePath($msg->content);
            if ($imagePath !== null) {
                return [$this->withInput($this->input->insertString($this->mentionFor($imagePath) . ' ')), null];
            }

            [$pasted] = $this->input->update($msg);
            \assert($pasted instanceof TextArea);
            if ($pasted === $this->input) {
                return [$this->withInput($this->input->insertString($msg->content)), null];
            }

            return [$this->withInput($pasted), null];
        }
        if ($msg instanceof ClipboardImagePastedMsg) {
            // Ctrl+V's answer (audit 15b-15): the saved image goes into the
            // draft as an `@` mention at the caret, exactly as a typed or
            // dropped one would, so it is attached on send and visible - and
            // removable - until then.
            if ($msg->path === null) {
                return [$this->mutate(['history' => [...$this->history, Message::notice(self::NO_CLIPBOARD_IMAGE_NOTICE)]]), null];
            }

            return [$this->withInput($this->input->insertString($this->mentionFor($msg->path) . ' ')), null];
        }

        // E744 WS5: the session picker's load-more edge. The ONLY production
        // source of this Msg in crush is candy-forms ItemList's
        // arrival-on-last-row rule (a keyboard step, wheel notch, or click
        // that moves the cursor onto the last row of the loaded page while
        // more may exist upstream), raised as Cmd::send and relayed back
        // through the frame by the E744 WS1 wrapper. Consumed while the
        // picker is up; with no picker the signal has no owner and joins
        // every other unhandled Msg below.
        if ($msg instanceof LoadMoreMsg) {
            return $this->handleSessionLoadMore();
        }

        if (!$msg instanceof KeyMsg) {
            return [$this, null];
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
        // BOTH encodings of Ctrl+C. candy-core's InputReader normalizes every
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
        // P-A4: the inline session-title editor owns the keyboard while it is
        // open — ahead of the Escape arm, so Escape closes the editor instead
        // of arming a turn cancel, and ahead of the mid-turn refusals, because
        // naming a session starts no turn and rewrites no history.
        if ($this->titleEditor !== null) {
            return $this->handleTitleEditorKey($msg);
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
            // Roadmap 1.C-4: a turn already asked to stop softly is cancelled
            // HARD by the next Escape, however long after the first it comes
            // — the status bar says so ("Esc to cancel now") for as long as
            // the turn takes to reach its boundary.
            $isSecondPress = ($this->lastEscapeAt !== null
                && ($now - $this->lastEscapeAt) <= self::DOUBLE_ESCAPE_WINDOW_SECONDS)
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
                    $this->workflowTurnInFlight ? self::WORKFLOW_CANCELLED_NOTICE : '_Request cancelled._',
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
            // E705: App::init() now pushes the Kitty DISAMBIGUATE flag, so on
            // Kitty-capable terminals Shift+Enter (`CSI 13;2u`) and
            // Ctrl+Enter (`CSI 13;5u`) arrive as Enter KeyMsgs carrying their
            // modifier flags and land here. Alt+Enter stays the reliable one
            // everywhere (ESC+CR, decoded by candy-core's Alt-prefixed-key
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
            $msg->type === KeyType::Enter
                => $this->slashMenuShouldIntercept()
                    ? $this->completeSlashMenuSelection()
                    : $this->recordInputHistory()->submit(),
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
            // NON-blank set: parse("\x0C") is Ctrl+L and types the letter, so a
            // form feed is recalled-only too.
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
            // `!$msg->ctrl` so a ctrl-flagged rune keeps falling through to the
            // ctrl-Char arm below, which TYPES the letter - `ctrl: true,
            // rune: 'b'` behaves exactly as it did before this arm existed.
            $msg->type === KeyType::Char && $msg->alt && !$msg->ctrl && $msg->rune === 'b'
                => [$this->withInputCursor($this->wordLeftOffset()), null],
            $msg->type === KeyType::Char && $msg->alt && !$msg->ctrl && $msg->rune === 'f'
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

        return [$this->switchToSession($ids[$nextIndex], $this->storedSessionName($ids[$nextIndex])), null];
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
        // documented in. Measured: the two Chat-native callers build the ASK
        // with $this->generation on the very object they then call
        // (beginToolCalls() and answerPermission(), and mutate() touches no
        // 'generation' at either site), so the comparison below is
        // tautologically FALSE at both. The THIRD constructor in `src/`, the
        // engine path wired by roadmap 1.C-2 (pumpLiveToolEvents()), stamps
        // the inbox entry's generation only after comparing it equal to
        // $this->generation and answering a stale question itself -- so it
        // cannot make the comparison true either.
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
        // appeared. Measured: the "y" set fails on `!$answered->inFlight` in
        // `ChatTest::testPermissionKeysDecideThePrompt()`. Domain of both
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
    private function answerPermission(PermissionReply $reply, string $note = ''): array
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

        // THE ENGINE PATH (roadmap 1.C-2) has no batch here to resume or end:
        // the call belongs to the forked turn child, which is blocked on this
        // answer and does the rest itself — runs the call, or hands the model
        // the refusal (with $note as feedback it reads). So the answer goes out
        // through the question's own handle and the turn stays in flight.
        // `reply()` answering false (the turn already settled the question) is
        // harmless: the modal comes down either way.
        //
        // "Always" is remembered as a PATTERN ({@see \SugarCraft\Crush\Permissions\SessionPermissionMemo})
        // — for a question the gate put alone, the only kind it is offered on —
        // kept in the session's grant map beside the Chat-native exact-call
        // grants, and handed to every later turn's gate.
        if ($request->pendingAsk !== null) {
            $ask = $request->pendingAsk;
            if ($reply === PermissionReply::Always && $ask->offers(PermissionReply::Always) && !$ask->isSettled()) {
                $memo = \SugarCraft\Crush\Permissions\SessionPermissionMemo::fromGrants($this->permissionGrants)
                    ->withGrant($ask->tool, $ask->arguments);
                $cleared['permissionGrants'] = [...$this->permissionGrants, ...$memo->grants()];
            }
            $ask->reply($reply, $note === '' ? null : $note);

            return [$this->mutate($cleared), null];
        }

        // A denial ENDS the turn, with no AssistantMsg to follow it, so a queue
        // released only at update()'s settle arm would strand here — the
        // permission prompt is a mid-turn state by definition, which makes it one
        // of the likelier places for a queue to have accumulated. The permitting
        // path below keeps the turn running and deliberately does not drain.
        if (!$reply->permits()) {
            // A rejection's feedback is the model's to read, on this path as
            // on the engine one (1.C-2).
            $refusal = \SugarCraft\Crush\Permissions\ApprovalVerdict::rejectedByUser($note)
                ->denialMessage("{$request->toolCall->name} was not run.");

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
                    // Deliberately AGENT-VISIBLE (audit 15b-03): EngineBackend's
                    // toTypedMessages() flattens tool calls and results away,
                    // so on that path this note is the model's only record
                    // that the call it asked for was refused.
                    Message::system('_' . DenialKind::Refused->reason($refusal) . '_'),
                    // The refusal also has to exist as a RESULT, not only as
                    // a system note (crush_feat.md §1 E7): the assistant
                    // message above carries the tool call, so leaving it
                    // unanswered puts a tool_use block on the next request's
                    // wire with no matching tool_result. It doubles as the
                    // producer for the struck-through denied row
                    // {@see Renderer::renderToolResults()} draws.
                    Message::assistant('')->withToolResults([ToolResult::denied(
                        $request->toolCall->name,
                        DenialKind::Refused,
                        $refusal,
                        $request->toolCall->id,
                    )]),
                ],
            ]), null]);
        }

        $grants = $this->permissionGrants;
        if ($reply === PermissionReply::Always) {
            // KEYED ON THE CALL, AND ONLY FOR THE GATE'S QUESTION (audit
            // F-P9). This used to be `$grants[<tool name>] = true`, which
            // turned "Always" on `Bash ls` into session-wide consent to every
            // later Bash ask, whatever the command — including asks a user's
            // hooks.yaml script raised ("confirm before touching prod").
            // permissionGrantKey() returns null for any ask a hook other than
            // the gate raised, so "Always" there settles as "Once": the user
            // hook's question will be put again on the next call.
            $answeredAsk = null;
            foreach ($jobs as $job) {
                if ($job[0] === $request->toolCall) {
                    $answeredAsk = $job[3];
                    break;
                }
            }

            $grantKey = $answeredAsk === null ? null : self::permissionGrantKey($request->toolCall, $answeredAsk);
            if ($grantKey !== null) {
                $grants[$grantKey] = true;
                $cleared['permissionGrants'] = $grants;
            }
        }

        // Drop the ASK the user just answered (and, under a fresh Always
        // grant, other queued asks the same grant answers) - every other entry
        // keeps its ASK, because consent for one call is not consent for
        // another.
        $jobs = array_map(
            static function (array $job) use ($request, $grants): array {
                if ($job[3] === null) {
                    return $job;
                }

                $key = self::permissionGrantKey($job[0], $job[3]);
                $answered = $job[0] === $request->toolCall || ($key !== null && isset($grants[$key]));

                // Drop ONLY the ASK the user just answered; slot 4 (the pre-hook
                // note) rides across the re-entry so a settled question's
                // model-visible context reaches the result the same way an
                // immediate permission's does.
                return $answered ? [$job[0], $job[1], $job[2], null, $job[4]] : $job;
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
     * A pasted command does not walk this table. Bracketed paste arrives as one
     * {@see PasteMsg}, which {@see update()} catches at its own arm ABOVE the
     * `!$msg instanceof KeyMsg` return and inserts into the draft box -- so it
     * reaches neither this prompt handler nor the Char arms, and cannot arm,
     * disarm, or answer on the user's behalf (pre-E704 the PasteMsg was dropped
     * outright; it was still never a keystroke, so the table read the same way
     * either way). Only an UNBRACKETED paste on a terminal that ignores mode
     * 2004 -- delivered as a burst of raw `Char` keys -- walks this table.
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

        // ── the rejection note, which only `r` at an armed prompt opens ──
        //
        // The note is typed into the draft box — the one text editor this
        // model has — so here every key edits it, and only Enter (refuse with
        // it) and Escape (back to the question, text kept) do anything else.
        if ($this->permissionStage === PermissionPromptStage::WritingNote) {
            if ($msg->type === KeyType::Escape) {
                return [$this->mutate(['permissionStage' => PermissionPromptStage::Armed]), null];
            }
            if ($msg->type === KeyType::Enter && !$msg->alt && !$msg->shift && !$msg->ctrl) {
                $note = trim($this->inputBuf);

                return $this->withInputBuf('')->answerPermission(PermissionReply::Reject, $note);
            }

            return $this->delegateToInput($msg);
        }

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

        // `r` opens the rejection note: the refusal then carries the user's
        // words, which the model reads beside the refused call.
        if ($isChar && $rune === 'r') {
            return [$this->mutate(['permissionStage' => PermissionPromptStage::WritingNote]), null];
        }

        // `x` refuses AND stops: on an engine turn the soft cancel is raised
        // first, so the turn ends at the step boundary after this call instead
        // of the model trying something else; a turn that cannot stop softly
        // (Chat's own path, where a refusal already ends the turn) just refuses.
        // `x`, not `s`: a letter that answers on the first keystroke should be
        // one prose rarely opens with (see the residual below).
        if ($isChar && $rune === 'x') {
            $this->inFlightCancellation?->cancelSoft();

            return $this->answerPermission(PermissionReply::Reject, 'The user refused this call and stopped the turn.');
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
     * declares 109 live rows across 11 contexts — 113 in all, four of them
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
     * drives. This arm is still the only KEYSTROKE route on a genuinely EMPTY
     * line, where there is no character for the cursor to sit in front of, and
     * it stays for that case (and because the footer already advertises it).
     * The paste half of the original sentence expired with E704 — a pasted
     * `?why` now lands in the box verbatim, a second route to the same draft
     * that is likewise not a keystroke.
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
     * The calls granted "always" for this session, as `[grant key => true]`.
     *
     * Each key is a {@see permissionGrantKey()}: the tool name, a space, and
     * the call's arguments as canonical JSON — so a grant covers one exact
     * call, never the tool as a whole (audit F-P9).
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

        // How many placeholders each id may still claim. Ids repeat across a
        // session (DSML/MiniMax restart at `dsml_call_0` every response), so
        // the walk below runs NEWEST FIRST and each result resolves only as
        // many rows as it accounts for - this batch's - rather than every row
        // in history that ever carried the id (audit 15b-02). A batch with two
        // id-less calls of one tool still resolves both rows, as it did.
        $resultsById = [];
        $claims = [];
        foreach ($msg->results as $result) {
            $key = $result->id ?? $result->name;
            $resultsById[$key] = $result;
            $claims[$key] = ($claims[$key] ?? 0) + 1;
        }

        $newHistory = [];
        foreach (array_reverse($this->history) as $historyMessage) {
            $pendingId = $historyMessage->pendingToolCallId;
            if ($pendingId !== null && ($claims[$pendingId] ?? 0) > 0) {
                $claims[$pendingId]--;
                // The placeholder's content IS Message::describeToolCall()'s
                // one-liner, and it is the only carrier of the call's
                // arguments that reaches this point - the result itself never
                // saw them. Carrying it onto the finished result is what lets
                // a collapsed row still say WHAT ran (crush_feat.md §3 E2).
                $result = $resultsById[$pendingId]
                    ->withDescription($historyMessage->content)
                    ->withArguments($historyMessage->pendingToolArguments);
                $newHistory[] = self::toolResultMessage($result, $historyMessage->reasoning);

                continue;
            }
            $newHistory[] = $historyMessage;
        }
        $newHistory = array_reverse($newHistory);

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
            // And the turn's durable record closes here for the same reason
            // (roadmap O-2f): nothing after this point will.
            $this->turnRunner()->completeParked($msg->turn);

            return [$this, null];
        }

        $runner = $this->turnRunner();
        $remaining = $msg->events;
        $event = array_shift($remaining);

        // Queue drained: hand the turn's reply to the ordinary AssistantMsg
        // arm so tool calls the model asked for on TOP of the engine's own
        // (Chat-native $tools) still get picked up by beginToolCalls(). The
        // turn's durable end is written now, after the last row it produced
        // and before the reply it settles on lands.
        if ($event === null) {
            $runner->completeParked($msg->turn);

            return [$this, Cmd::send(new AssistantMsg($msg->message, $msg->generation))];
        }

        $rest = new BackendToolEventsMsg($remaining, $msg->message, $msg->generation, $msg->turn);

        if ($event instanceof SpendCapBreached) {
            // E20's mid-turn abort lands in the SAME ordered story as the
            // tool calls around it — appended, then the chain continues to
            // the turn's settled message exactly as any other event does.
            $next = $this->appendSpendCapNotice($event);
            $runner->recordEvent($msg->turn, $event);

            return [$next, Cmd::send($rest)];
        }

        if ($event instanceof SubAgentActivity) {
            // A delegation beat updates the PARENT's AgentManager mirror —
            // the manager is a service this model deliberately mutates, like
            // the inbox itself, so the Chat state is returned unchanged and
            // the chain continues in order. No transcript row: the ToolStarted
            // for the Task call is the transcript-visible story of this run;
            // the mirror's audience is the dashboard, and it reads the
            // manager, not the transcript. The manager hands back the beat
            // as it stored it — a finished run stamped with its child
            // session id (P-C1) — and that frame is what the live line and
            // the server's `agent.status` see.
            $event = $this->agentManager?->projectRemoteSubAgent($event) ?? $event;
            // Roadmap P-B2: and the live line under that Task row. On this
            // path (the settled queue — a turn whose beats the live pump
            // never got to, which is every turn without ext-pcntl) the beats
            // arrive after the fact, so the line is filled from what the run
            // really did and ends on its real outcome; nothing pretends it
            // was live.
            $this->agentLive()->apply($event);
            $runner->recordEvent($msg->turn, $event);

            return [$this, Cmd::send($rest)];
        }

        $next = $event instanceof ToolStarted
            ? $this->appendToolRunningPlaceholder($event, '', $msg->turn)
            : $this->replaceToolRunningPlaceholder($event, $msg->turn);

        return [$next, Cmd::send($rest)];
    }

    /**
     * This session's {@see \SugarCraft\Crush\Host\TurnRunner} (roadmap O-2f):
     * the one the workspace registered on its
     * {@see \SugarCraft\Crush\Host\WorkspaceContext::service()} locator, else
     * the one keyed to this Chat lineage's live inbox, which every `mutate()`
     * clone shares by identity — so a turn {@see scheduleBackendCompletion()}
     * opened is found again by the folds that report it, and no constructor
     * slot is spent on it.
     */
    private function turnRunner(): \SugarCraft\Crush\Host\TurnRunner
    {
        $registered = $this->workspace?->service(\SugarCraft\Crush\Host\TurnRunner::class);

        return $registered instanceof \SugarCraft\Crush\Host\TurnRunner
            ? $registered
            : \SugarCraft\Crush\Host\TurnRunner::of($this->liveToolEvents);
    }

    /**
     * The turn whose events the live pump is folding: the dispatch in flight,
     * for an entry stamped with the current generation. An entry of any other
     * generation belongs to a turn this Chat has already let go of.
     */
    private function liveTurn(int $generation): ?CancellationToken
    {
        return $generation === $this->generation ? $this->inFlightCancellation : null;
    }

    /**
     * The live state of every delegated run this session has heard about
     * (roadmap P-B2, Appendix P §4.5) — what the line under each Task row is
     * drawn from ({@see Renderer::renderView()}).
     *
     * A mutable service, like {@see $agentManager}: the event arms above and
     * in {@see pumpLiveToolEvents()} fold frames into it, the pump's tick
     * advances its clock, and `view()` only reads it. The workspace's own one
     * when it registered one ({@see \SugarCraft\Crush\Host\WorkspaceContext::service()});
     * otherwise the one keyed to this Chat lineage's live inbox, which every
     * `mutate()` clone shares by identity — so no constructor slot is spent
     * on it. Runs are found by their Task call id, so a run outliving its
     * session (a `/new` keeps the inbox) is simply never looked up again.
     */
    public function agentLive(): \SugarCraft\Crush\Agents\Live\AgentLiveRegistry
    {
        $registered = $this->workspace?->service(\SugarCraft\Crush\Agents\Live\AgentLiveRegistry::class);

        return $registered instanceof \SugarCraft\Crush\Agents\Live\AgentLiveRegistry
            ? $registered
            : \SugarCraft\Crush\Agents\Live\AgentLiveRegistry::of($this->liveToolEvents);
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
            // accessor's contract is. SpendCapBreached is the same exclusion
            // with a third reason: it is not even per-call — it is one
            // verdict about the turn — and a consumer pairing starts with
            // finishes would never close it. SubAgentActivity is excluded on
            // the per-call ground: a delegation beat belongs to a run
            // BEHIND a Task call, not to the pairing of any tool row itself,
            // and consumers of this accessor pair starts with finishes.
            if ($event instanceof TokenDelta || $event instanceof ReasoningDelta
                || $event instanceof SpendCapBreached || $event instanceof SubAgentActivity) {
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
     * under `src/` or `bin/` at all. The live path's `$onReasoning` closure —
     * built by {@see \SugarCraft\Crush\Host\TurnRunner::start()} for
     * {@see scheduleBackendCompletion()} since O-2f — appends to the shared
     * inbox DIRECTLY, because it runs where no Chat exists to call a method
     * on. WHY IT STILL EARNS ITS PLACE (rule 6 — a dormant seam gets
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

        // ── the engine turn's permission questions (roadmap 1.C-2) ──
        //
        // Matched by askId BEFORE the generation check: a settlement names the
        // one question it settles, and the modal up for that question has to
        // come down whatever happened to the turn — this is how a question the
        // turn's end settled (`cancelled`) stops being drawn as answerable. A
        // settlement for anything else (the user's own answer, already
        // applied) changes nothing.
        if ($event instanceof \SugarCraft\Crush\Events\PermissionResolved) {
            // Every settlement of the turn's questions is part of its durable
            // story (roadmap O-2f), whoever settled it.
            $this->turnRunner()->recordEvent($this->liveTurn($generation), $event);
            $open = $this->pendingPermission?->pendingAsk;
            if ($open === null || $open->askId !== $event->askId) {
                return [$this, $more];
            }
            $this->permissionDeferred?->resolve(null);

            return [$this->mutate([
                'pendingPermission' => null,
                'permissionStage' => PermissionPromptStage::Armed,
                'permissionDeferred' => null,
            ]), $more];
        }

        if ($event instanceof \SugarCraft\Crush\Events\PermissionAsked) {
            $ask = $event->ask;
            if ($ask->isSettled()) {
                return [$this, $more];
            }
            // A question from a turn the user has since abandoned is ANSWERED,
            // not skipped: the child is blocked on it, and skipping would
            // leave it blocked until the teardown that abandoning already
            // started. A refusal is the only safe answer for nobody.
            if ($generation !== $this->generation) {
                $ask->reply(PermissionReply::Reject, 'the turn this question belonged to was abandoned');

                return [$this, $more];
            }
            // "Always" given earlier this session answers it without a
            // prompt — but only a question the permission gate put alone
            // ({@see Backend\PendingAsk::offers()}): a user hook's question is
            // put every time, exactly as on the Chat-native path.
            if ($ask->offers(PermissionReply::Always)
                && \SugarCraft\Crush\Permissions\SessionPermissionMemo::fromGrants($this->permissionGrants)
                    ->allows($ask->tool, $ask->arguments, $this->projectRoot())) {
                $this->turnRunner()->recordEvent($this->liveTurn($generation), $event);
                $ask->reply(PermissionReply::Once);

                return [$this, $more];
            }
            // One modal at a time: the question goes back to the head of the
            // inbox and the tool-event tick, which runs while a turn is in
            // flight, offers it again once the prompt that is up is answered.
            if ($this->pendingPermission !== null) {
                $this->liveToolEvents->exchangeArray([$entry, ...$this->liveToolEvents->getArrayCopy()]);

                return [$this, null];
            }

            // Recorded once, as it goes up: a question re-queued behind an
            // open modal (above) is not asked again until it is shown.
            $this->turnRunner()->recordEvent($this->liveTurn($generation), $event);
            [$asking, $wait] = $this->requestPermission(new PermissionRequestMsg(
                // No parked batch on this path — the child owns the call — so
                // the assistant message is a placeholder answerPermission()'s
                // engine branch never reads.
                Message::assistant(''),
                ToolCall::fromEngineCall(new EngineToolCall($ask->toolCallId, $ask->tool, $ask->arguments)),
                $ask->reason,
                $generation,
                $ask,
            ));

            return [$asking, $more === null ? $wait : Cmd::batch($wait, $more)];
        }

        if ($generation !== $this->generation) {
            return [$this, $more];
        }

        // Roadmap 1.C-4: the running turn's step and bill, for the status bar
        // and the Escape arm. Stamped with the generation, so the pair always
        // describes one turn: the first event of a new turn clears the other.
        if ($event instanceof \SugarCraft\Crush\Events\StepStarted || $event instanceof \SugarCraft\Crush\Events\UsageUpdated) {
            $sameTurn = $this->liveStepGeneration === $generation;
            $this->turnRunner()->recordEvent($this->liveTurn($generation), $event);

            return [$this->mutate([
                'liveStep' => $event instanceof \SugarCraft\Crush\Events\StepStarted ? $event : ($sameTurn ? $this->liveStep : null),
                'liveUsage' => $event instanceof \SugarCraft\Crush\Events\UsageUpdated ? $event : ($sameTurn ? $this->liveUsage : null),
                'liveStepGeneration' => $generation,
            ]), $more];
        }

        if ($text !== null) {
            // Ephemeral (Appendix O §6.5): heard live by a listener, never
            // logged — the settled reply is the durable copy of these bytes.
            $this->turnRunner()->recordEvent($this->liveTurn($generation), new TokenDelta($text));

            return [$this->mutate(['streamingText' => $this->streamingText . $text]), $more];
        }

        if ($thought !== null) {
            $this->turnRunner()->recordEvent($this->liveTurn($generation), new ReasoningDelta($thought));

            return [$this->mutate(['reasoningText' => $this->reasoningText . $thought]), $more];
        }

        if ($event instanceof SpendCapBreached) {
            $this->turnRunner()->recordEvent($this->liveTurn($generation), $event);

            return [$this->appendSpendCapNotice($event), $more];
        }

        if ($event instanceof SubAgentActivity) {
            // Same projection as the settled-queue arm, on the live edge:
            // generation-stale beats were dropped by the guard above, so what
            // lands here belongs to the turn on screen. The pump consumes ONE
            // entry per tick and re-arms itself via $more; the mirror update
            // costs the transcript nothing. The stamped frame it returns
            // (child session id, P-C1) is the one passed on.
            $event = $this->agentManager?->projectRemoteSubAgent($event) ?? $event;

            // Roadmap P-B2: the live line under the Task row. Five parallel
            // runs beat up to twenty times a second between them, and one
            // entry per 0.1 s tick would fall behind that for good — so every
            // beat queued right behind this one (same turn, nothing else in
            // between, so no ordering is crossed) is folded in on this same
            // tick (Appendix P §4.6).
            $batch = [$event];
            $queue = $this->liveToolEvents->getArrayCopy();
            $taken = 0;
            while (($peek = $queue[$taken] ?? null) !== null
                && $peek[1] instanceof SubAgentActivity
                && $peek[0] === $generation) {
                $batch[] = $this->agentManager?->projectRemoteSubAgent($peek[1]) ?? $peek[1];
                $taken++;
            }
            if ($taken > 0) {
                $this->liveToolEvents->exchangeArray(array_slice($queue, $taken));
                $more = count($this->liveToolEvents) > 0 ? Cmd::send(new ToolEventPumpMsg()) : null;
            }
            $this->agentLive()->applyBatch($batch);
            foreach ($batch as $beat) {
                $this->turnRunner()->recordEvent($this->liveTurn($generation), $beat);
            }

            return [$this, $more];
        }

        $next = $event instanceof ToolStarted
            // The step's thinking is parked on the placeholder rather than
            // dropped with the reset below: it is what led to this call, and
            // it becomes the finished row's collapsible "💭 Thought".
            ? $this->appendToolRunningPlaceholder($event, $this->reasoningText, $this->liveTurn($generation))
            // A ToolFinished deliberately does NOT reset the partial: the
            // model has not spoken since the reset its ToolStarted already
            // did, so there is nothing to clear and clearing would be
            // indistinguishable either way.
            : $this->replaceToolRunningPlaceholder($event, $this->liveTurn($generation));

        // The model stopped talking and started doing. Whatever prose
        // introduced this call belongs to the step that is now over, and the
        // next step's deltas are a new utterance - see $streamingText's
        // docblock for why an accumulation spanning steps would visibly
        // shrink when the turn settles.
        if ($event instanceof ToolStarted) {
            $next = $next->mutate([
                'streamingText' => '',
                'reasoningText' => '',
                'expanded' => $next->expandedAfterLiveThought($this->reasoningText),
            ]);
        }

        return [$next, $more];
    }

    /**
     * Append the "running" placeholder for an engine-dispatched tool call -
     * {@see beginToolCalls()}'s first half, driven by a {@see ToolStarted}
     * instead of by a Message's own `$toolCalls`. The row is
     * {@see \SugarCraft\Crush\Host\TranscriptProjector::placeholder()}'s, so
     * a host with no screen appends exactly this one (roadmap O-2f), and the
     * turn's durable `tool.started` is recorded beside it.
     */
    private function appendToolRunningPlaceholder(ToolStarted $event, string $reasoning = '', ?CancellationToken $turn = null): self
    {
        $runner = $this->turnRunner();
        $placeholder = $runner->projector()->placeholder($event, $reasoning);
        $next = $this->mutate(['history' => [...$this->history, $placeholder]]);
        $runner->recordToolStarted($turn, $event, $placeholder);

        return $next;
    }

    /**
     * {@see $expanded} once the live thought has left the in-flight paint.
     *
     * The collapsed live thought is keyed {@see Renderer::THOUGHT_LIVE_KEY}
     * because its text is still growing; once it lands somewhere settled - a
     * finished turn, or the placeholder of the tool call it led to - it is
     * keyed by its content ({@see Renderer::thoughtKey()}). Moving an open
     * live thought onto that key keeps it open across the hand-off instead of
     * snapping shut under the user's cursor, and dropping the live key either
     * way stops the next turn's thought opening pre-expanded.
     *
     * @return array<string, bool>
     */
    private function expandedAfterLiveThought(?string $landed): array
    {
        $expanded = $this->expanded;
        if (!isset($expanded[Renderer::THOUGHT_LIVE_KEY])) {
            return $expanded;
        }

        unset($expanded[Renderer::THOUGHT_LIVE_KEY]);
        if ($landed !== null && trim($landed) !== '') {
            $expanded[Renderer::thoughtKey($landed)] = true;
        }

        return $expanded;
    }

    /**
     * Put E20's mid-turn abort on the transcript (the pump and the batched
     * backend-event chain share this one writer so the wording cannot drift
     * between the fork path and the blocking path).
     *
     * THE GUARANTEE IT NAMES IS DELIBERATELY DIFFERENT from
     * {@see spendCapRefusal()}'s. That one refuses to START: nothing was
     * billed, the draft sits in the box. This one says a call already
     * happened and the loop stopped before the NEXT one — the distinction
     * E20's step demands the messages keep apart, because they are different
     * promises about money already spent.
     */
    private function appendSpendCapNotice(SpendCapBreached $event): self
    {
        return $this->mutate(['history' => [
            ...$this->history,
            Message::notice($this->spendLedger()->midTurnNotice($event)),
        ]]);
    }

    /**
     * Replace an engine-dispatched call's placeholder with its real result -
     * {@see finishToolCalls()}'s replace-by-id half, through
     * {@see \SugarCraft\Crush\Host\TranscriptProjector::finish()} so a host
     * with no screen writes the same row (roadmap O-2f). That method keeps the
     * account of how the placeholder is found — by the event's call id, newest
     * first (audit 15b-02) — and why an unmatched result is appended rather
     * than dropped. The turn's durable `tool.finished` is recorded beside it,
     * naming the finished row by the identity its save will keep.
     */
    private function replaceToolRunningPlaceholder(ToolFinished $event, ?CancellationToken $turn = null): self
    {
        $runner = $this->turnRunner();
        [$history, $row, $replaced] = $runner->projector()->finish($this->history, $event);
        $next = $this->mutate(['history' => $history]);
        $runner->recordToolFinished($turn, $event, $row, $replaced);

        return $next;
    }

    /**
     * The finished-tool-call history entry: {@see finishToolCalls()} writes it
     * for a Chat-native call, and the engine path through
     * {@see replaceToolRunningPlaceholder()}. One builder —
     * {@see \SugarCraft\Crush\Host\TranscriptProjector::resultRow()} — so the
     * two pipelines cannot drift apart on what a finished row looks like.
     */
    private static function toolResultMessage(ToolResult $result, ?string $reasoning = null): Message
    {
        return \SugarCraft\Crush\Host\TranscriptProjector::resultRow($result, $reasoning);
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
     *  - A callback that RETURNS a `ToolResult` has every field carried
     *    through, its structural {@see ToolResult::$denial} included, so it
     *    can still declare its own refusal — an MCP tool whose server refused,
     *    a `Skill` a policy stopped, or a wrapper enforcing its own gate each
     *    really did have the call blocked, and disbelieving them would make a
     *    refusal real only when THIS process made it.
     *
     * WHAT CHANGED, AND IT IS THE CHANGE THIS DOC-BLOCK SAID WOULD CHANGE THE
     * ANSWER (audit F-P8). A returned result used to declare a refusal by
     * SPELLING one: an `error` opening with a roster prefix was classified as
     * refused. That let any text forge it — a Bash child's stderr, an MCP
     * server's error body. Now a callback DECLARES a refusal with
     * {@see ToolResult::denied()} (PHP code in this process choosing to say
     * "blocked"), and an `error` that merely opens with `Permission denied:`
     * is an ordinary failure. Both halves are asserted by
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
                    denial: $raw->denial,
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
     * AND THE TEXT IS NO LONGER WHAT IS CLASSIFIED AT ALL (audit F-P8).
     * {@see isDeniedResult()} and
     * {@see \SugarCraft\Crush\Permissions\ToolRefusal::fromEvent()} now read
     * the result's structural {@see ToolResult::$denial}, which the catch
     * above never sets — so E308's guarantee (a throw is a failure, never a
     * refusal) holds by construction, and holds equally for a tool that
     * FAILS without throwing, which the wrapper alone never covered. The
     * wrapper stays: it is the engine's wording, and it tells the model the
     * call failed rather than quoting an exception that reads like a refusal.
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
        foreach ($gated as [$toolCall, $denied, $hookContext, $ask, $preContext]) {
            if ($ask !== null) {
                // Reaching the fork boundary with an unanswered ASK means the
                // batch was released without the user deciding on this call.
                // Enforce the invariant here as well as in answerPermission()
                // so a future caller cannot widen permission by accident: the
                // call is reported as unapproved instead of being run.
                $denied ??= ToolResult::denied(
                    $toolCall->name,
                    DenialKind::Unanswered,
                    "{$toolCall->name} was not approved.",
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
                $jobs[] = ['toolCall' => $toolCall, 'file' => null, 'pid' => null, 'result' => $denied, 'hookContext' => null, 'preContext' => ''];
                continue;
            }

            if (!$canFork) {
                $jobs[] = ['toolCall' => $toolCall, 'file' => null, 'pid' => null, 'result' => $this->executeToolSynchronously($toolCall), 'hookContext' => $hookContext, 'preContext' => $preContext];
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
                $jobs[] = ['toolCall' => $toolCall, 'file' => null, 'pid' => null, 'result' => $this->executeToolSynchronously($toolCall), 'hookContext' => $hookContext, 'preContext' => $preContext];
                continue;
            }

            if ($pid === 0) {
                $this->storeToolResult($file, $toolCall);
                \SugarCraft\Crush\Support\ForkedChild::exitNow(0);
            }

            $jobs[] = ['toolCall' => $toolCall, 'file' => $file, 'pid' => $pid, 'result' => null, 'hookContext' => $hookContext, 'preContext' => $preContext];
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
     * batch on (crush_feat.md §1 E2). A call the user has already answered
     * {@see PermissionReply::Always} for skips that: the ASK becomes plain
     * permission, with its HookContext intact so `PostToolUse` still runs —
     * but only when {@see permissionGrantKey()} matches, i.e. the gate alone
     * asked and the arguments are the granted ones (audit F-P9).
     *
     * @return array{0: ToolCall, 1: ?ToolResult, 2: ?HookContext, 3: ?\SugarCraft\Crush\Hooks\HookResult, 4: string}
     *     [the call to execute (arguments rewritten by a MODIFY hook), a
     *     pre-resolved error result when the call was DENIED, the context to
     *     hand `PostToolUse` once the call finishes (null when it will not
     *     run), the unanswered ASK decision when one is outstanding, the
     *     pre-hook model-visible note (empty on every non-permitting arm —
     *     a DENY's collected note must not reach a result slot; an ASK's
     *     note rides its settled verdict because {@see HookRegistry::executeHooks()}
     *     rebuilds the question carrying it, and dies with the call when the
     *     park is never answered)]
     */
    private function gateToolCall(ToolCall $toolCall): array
    {
        if ($this->hooks === null || !isset($this->tools[$toolCall->name])) {
            return [$toolCall, null, null, null, ''];
        }

        // The Chat mirror of audit F-H3, encoded by the engine path's own
        // {@see Runtime::hookInput()}. A bare json_encode() here escaped every
        // `/`, so a hook's `grep -qF /etc/passwd` guard never matched, and its
        // `?: '{}'` fallback handed a deny hook an EMPTY argument map while the
        // call ran with its real arguments. Arguments that cannot be encoded
        // are refused before any hook runs, exactly as Runtime refuses them.
        try {
            $toolInput = Runtime::hookInput($toolCall->arguments);
        } catch (\JsonException $e) {
            return [
                $toolCall,
                ToolResult::denied($toolCall->name, DenialKind::Hook, sprintf(
                    'the arguments of %s could not be encoded as JSON for the hook chain (%s), so no hook could judge them',
                    $toolCall->name,
                    $e->getMessage(),
                ), $toolCall->id),
                null,
                null,
                '',
            ];
        }

        $context = new HookContext(
            sessionId: $this->currentSessionId ?? '',
            toolName: $toolCall->name,
            toolArgs: $toolCall->arguments,
            toolInput: $toolInput,
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

            // A grant answers only the question it was given for (audit
            // F-P9): the gate's own ask, for these exact arguments. A user
            // hook's ask — or a gate ask about a different command — yields
            // a null key or a key nobody granted, and is put to the user.
            $grantKey = self::permissionGrantKey($toolCall, $hookResult);

            return $grantKey !== null && isset($this->permissionGrants[$grantKey])
                ? [$toolCall, null, $context, null, $hookResult->additionalContext]
                : [$toolCall, null, $context, $hookResult, $hookResult->additionalContext];
        }

        if (!$hookResult->isAllowed() && !$hookResult->isModified()) {
            return [
                $toolCall,
                ToolResult::denied($toolCall->name, DenialKind::Hook, $hookResult->message, $toolCall->id),
                null,
                null,
                '',
            ];
        }

        [$toolCall, $context] = self::applyRewrite($toolCall, $context, $hookResult);

        return [$toolCall, null, $context, null, $hookResult->additionalContext];
    }

    /**
     * The identity a {@see PermissionReply::Always} grant is filed under for
     * this call, or null when no grant may ever answer this ask (audit F-P9).
     *
     * NULL UNLESS THE GATE ALONE ASKED ({@see HookResult::askedOnlyBy()},
     * stamped by the registry, which a hook cannot forge). A user hook's ASK
     * is a question about this call's content — the reason the hook exists —
     * and the Chat path used to let one "Always" on any earlier call of the
     * same tool answer it with no prompt. That is F-P7's defect made
     * session-wide; opencode deliberately scopes approvals to patterns for
     * the same reason.
     *
     * OTHERWISE `<tool> <canonical JSON arguments>`: the arguments with every
     * object's keys sorted recursively, so `{"a":1,"b":2}` and `{"b":2,"a":1}`
     * are one call, encoded as Runtime's hook input is (slashes and unicode
     * unescaped, invalid UTF-8 substituted). Exact arguments, deliberately not
     * a command-prefix pattern: a prefix is a judgement about which commands
     * are equivalent ("git log" ≡ "git log -p"?) that the gate's own rules
     * already express, and the conservative reading of "always allow this"
     * is "this call, again". Null if the arguments will not encode, in which
     * case nothing is granted and the question is put again.
     */
    private static function permissionGrantKey(
        ToolCall $toolCall,
        \SugarCraft\Crush\Hooks\HookResult $ask,
    ): ?string {
        if (!$ask->askedOnlyBy(\SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook::NAME)) {
            return null;
        }

        try {
            $json = json_encode(
                self::canonicalArguments($toolCall->arguments),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            return null;
        }

        return $toolCall->name . ' ' . $json;
    }

    /**
     * $value with every string-keyed array's keys sorted, recursively; a
     * list keeps its order (element order is meaning there).
     */
    private static function canonicalArguments(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonicalArguments(...), $value);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
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
     * Append one model-visible note to a tool result's content.
     *
     * The Chat-path twin of {@see Runtime::annotate()}: an empty note returns
     * the SAME instance untouched (byte-identical no-op), a non-empty one lands
     * on whichever half the provider actually reads — `error ?? result`, exactly
     * what `toWire()`/`toEngineResult()` emit — so a note reaches the model
     * regardless of whether the call succeeded.
     */
    private static function withAppendedModelNote(ToolResult $result, string $note): ToolResult
    {
        if ($note === '') {
            return $result;
        }

        return new ToolResult(
            $result->name,
            $result->error === null
                ? ($result->result === '' ? $note : $result->result . "\n\n" . $note)
                : $result->result,
            $result->error === null
                ? null
                : ($result->error . "\n\n" . $note),
            $result->id,
            $result->imageBytes,
            $result->imagePath,
            $result->imageProtocol,
            $result->diff,
            $result->durationMs,
            $result->description,
            $result->arguments,
            $result->denial,
        );
    }

    /**
     * Run the `PostToolUse` hook chain over a finished tool call's output,
     * in the parent process, and return the result unchanged.
     *
     * Paired with {@see gateToolCall()}: `$context` is null exactly when the
     * pre-hook never allowed the call (no hooks wired, unknown tool, or a
     * DENY), which is also when {@see Runtime} skips its own postToolUse -
     * a call that never ran has no output to observe.
     *
     * `$preContext` is the note {@see gateToolCall()} collected before the call
     * ran, appended on the same seam the post-hook note uses and in the same
     * order as {@see Runtime::settle()}: the post chain observes the RAW tool
     * output first, then pre, then post, so the model-visible bytes are
     * `result\n\npre\n\npost` and an unused field stays byte-identical.
     */
    private function applyPostToolUse(
        ?HookContext $context,
        ToolResult $result,
        string $preContext = '',
    ): ToolResult {
        $postNote = '';
        $withheldReason = null;
        $withheldBy = null;
        if ($context !== null && $this->hooks !== null) {
            // R-1: the Chat path's live consumer of a permitting hook's
            // `additionalContext` — the same field Runtime::settle() appends on
            // the engine path. The verdict is captured here and appended below
            // after the pre-note, so the hook still observes the raw output.
            //
            // The hook observes what the model would read: the error text for
            // a failed call (Runtime hands it ToolResult::content(), which is
            // the error there), not the empty `result` slot beside it — a
            // secret printed to stderr is still a secret.
            try {
                $hookResult = $this->hooks->postToolUse($context->withToolOutput($result->error ?? $result->result));

                // The Chat mirror of audit F-H1: a PostToolUse verdict that
                // does not permit (deny, a timed-out chain, an ASK nobody can
                // answer after the fact, an unknown action) WITHHOLDS the
                // output. Only `additionalContext` used to be read here, so a
                // secret scanner exiting 2 let the key through and its reason
                // went nowhere. permitsExecution(), the allow-list, as
                // Runtime::settle() reads it.
                if (!$hookResult->permitsExecution()) {
                    $withheldReason = $hookResult->message;
                    $withheldBy = $hookResult->refusingHook();
                } else {
                    // Read ONLY on the permitting arm: a blocking verdict's
                    // note was written while reading the output it refused.
                    $postNote = $hookResult->additionalContext;
                }
            } catch (\Throwable $e) {
                // As Runtime::settle() (audit R6): a hook's own throw already
                // arrives as a refusal naming it; anything that still escapes
                // the chain has vetted nothing either, so it withholds rather
                // than unwinding the batch's collection out of the event loop.
                $withheldReason = \SugarCraft\Crush\Hooks\HookResult::failureReason($e);
            }
        }

        if ($withheldReason !== null) {
            $result = self::withheld($result, $withheldReason, $withheldBy);
            // As Runtime::settle(): the call's only audit record, since the
            // audit hook runs last and the chain returned before it (R7).
            try {
                $audit = $this->hooks?->hook(
                    \SugarCraft\Crush\Hooks\HookEvent::PostToolUse->value,
                    \SugarCraft\Crush\Hooks\BuiltIn\AuditHook::NAME,
                );
                if ($audit instanceof \SugarCraft\Crush\Hooks\BuiltIn\AuditHook && $context !== null) {
                    $audit->recordWithheld($context, $withheldReason, $withheldBy);
                }
            } catch (\Throwable) {
                // Best-effort: a log line must not decide what the model reads.
            }
        }

        // Kept on the withheld arm too: the pre-note came from PreToolUse
        // hooks reading the arguments, before any output existed.
        $result = self::withAppendedModelNote($result, $preContext);

        return self::withAppendedModelNote($result, $postNote);
    }

    /**
     * The result a refusing `PostToolUse` chain leaves in place of the output
     * it objected to — the Chat mirror of {@see Runtime::withheld()} (audit
     * F-H1), same text, so a transcript reads the same whichever pipeline ran
     * the call.
     *
     * The image and diff are dropped with the text (both render the output
     * the hook refused). The error/ok split is KEPT: the call ran, and whether
     * it succeeded is unchanged by whether the model may read its output, so
     * the text lands in whichever slot the tool's own outcome used. No denial
     * kind: nothing refused the CALL, which is what that field records.
     */
    private static function withheld(ToolResult $result, string $reason, ?string $hook = null): ToolResult
    {
        $text = \SugarCraft\Crush\Hooks\HookResult::withheldNotice($hook, $reason);

        return new ToolResult(
            $result->name,
            $result->error === null ? $text : '',
            $result->error === null ? null : $text,
            $result->id,
            durationMs: $result->durationMs,
            description: $result->description,
            arguments: $result->arguments,
        );
    }

    /**
     * The tool-shaped context a turn-lifecycle hook is dispatched with (P7.S2).
     *
     * Smuggles a session/prompt event through {@see HookContext} instead of
     * extending it — the full WHY, including what each slot carries and why
     * `model`/`provider` stay empty, is on
     * {@see \SugarCraft\Crush\Hooks\HookManager::sessionStart()}.
     */
    private function turnHookContext(string $event, string $prompt, bool $atStartup): HookContext
    {
        return $this->turnController()->turnHookContext(
            $event,
            $prompt,
            $atStartup,
            $this->currentSessionId,
            $this->projectRoot(),
        );
    }

    /**
     * The workspace's {@see \SugarCraft\Crush\Host\TurnController} (roadmap
     * O-2g): what a submitted line becomes, queueing and steering, command-file
     * expansion, the turn hooks and the dispatch's bookkeeping are its logic
     * over this session's state. Read through
     * {@see \SugarCraft\Crush\Host\WorkspaceContext::service()} so the
     * extraction adds no constructor state; a Chat with no workspace, or one
     * that registered none, gets a fresh one — it is stateless, so the two
     * cannot admit a submission differently.
     */
    private function turnController(): \SugarCraft\Crush\Host\TurnController
    {
        $service = $this->workspace?->service(\SugarCraft\Crush\Host\TurnController::class);

        return $service instanceof \SugarCraft\Crush\Host\TurnController
            ? $service
            : \SugarCraft\Crush\Host\TurnController::new();
    }

    /**
     * Why a turn-lifecycle verdict does not permit the turn, or null when it does.
     *
     * An unanswered ASK FAILS CLOSED here for the same reason it does on the tool
     * path: {@see submit()} has no UI that can put the question and no queue that
     * could hold the turn until it was answered, so honouring an ASK as permission
     * would run a prompt a hook explicitly asked to pause.
     */
    private function turnHookRefusalReason(\SugarCraft\Crush\Hooks\HookResult $result): ?string
    {
        return $this->turnController()->turnHookRefusalReason($result);
    }

    /**
     * Fire `UserPromptSubmit` and (once per session) `SessionStart` for one
     * submitted prompt, and say what the turn tail should do with the verdicts.
     *
     * Returns the `role: system` notes to place immediately BEFORE the user's
     * message, or the refusal pair {@see submit()} must return instead of
     * dispatching anything.
     *
     * TWO CALL SITES, ONE PER SUBMISSION: {@see submit()}'s tail for a prompt that
     * goes out now, and {@see scheduleParkedCompaction()} for one parked behind the
     * 85% tier's summarization — the parked route returns before submit()'s tail,
     * so no submission reaches both (audit 15b-01).
     *
     * THE TWO ORDERS DIFFER DELIBERATELY:
     * - FIRED gate-first: UserPromptSubmit before SessionStart. Each event spawns a
     *   real script process, and a SessionStart hook that fired only to discover its
     *   own prompt was blocked would have produced a note with nowhere to go.
     * - INSERTED in the order Anthropic's insertion-point table implies
     *   (prompt_expand.md §4.12, external-verified 2026-09-05): SessionStart's note
     *   at "start of conversation, before the first prompt" sits ahead of the
     *   UserPromptSubmit note that rides "alongside the submitted prompt", and both
     *   ahead of the user's line.
     *
     * role:system is the NON-SPOOFABLE operator channel (plan :2584-2586) and is
     * already what {@see contextReminderMessage()} rides, so a hook note goes onto
     * HISTORY and never into the prompt assembler — which is precisely why wiring
     * these events moves no prompt golden. Hook text is expected to be factual
     * statements rather than imperatives (§4.12 wording guidance); no wrapper or
     * framing is added around it here, because a wrapper authored in this file would
     * read as an instruction, which is what that guidance is against.
     *
     * NO HOOKS WIRED IS A LITERAL NO-OP: the guard below returns no notes and no
     * refusal, so `$newTurnMessages` holds exactly what it held before this method
     * existed and the request payload is byte-identical. A wired chain that produced
     * no stdout lands the same way, since an empty `additionalContext` adds no
     * message — the {@see applyPostToolUse()} contract, applied to turn events.
     *
     * DOCUMENTED GAP (decision A — startup-only, no second fire point). The
     * SessionStart gate reads `count($this->history) === 0` AT THE DISPATCH POINT,
     * which makes it a once-per-EMPTY-HISTORY gate rather than a once-per-SESSION
     * one, and the two readings differ in BOTH directions:
     *
     * - A turn that wrote history before the first dispatched prompt takes the slot
     *   and SessionStart then never fires for the session — an early-return slash
     *   command, an idle-compaction prompt, or this method's own block notice.
     *   `resume` and {@see handlePaletteNewSession()} do not re-fire it either: the
     *   latter appends its notice to history rather than emptying anything.
     * - {@see handleClearCommand()} IS the one production site that puts history back
     *   to `[]`, so after `/clear` the gate re-opens and SessionStart fires AGAIN on
     *   the next submitted prompt. What it fires with is still `source: startup`, so
     *   Anthropic's `clear` source is not discriminated anywhere: `startup` is the
     *   only value this call site ever sends, on a first turn where the label is
     *   accurate and on a post-`/clear` turn where it is not. Discriminating them
     *   needs a "session already started" flag this gate does not have — recorded as
     *   a known gap for a follow-up, NOT changed here, because this step's done-when
     *   is the two dispatch sites.
     * - `compact` is genuinely unreachable on this path: every compaction tier either
     *   returns before dispatch or rewrites history to a non-empty summary.
     *
     * Chat's {@see init()} cannot close any of this — TEA `init()` returns a
     * Closure and cannot mutate the Model — and firing at construction/`withHooks()`
     * would need an async command on an immutable model for a seam the done-when does
     * not ask about. Recorded, not deleted: the two events still have exactly one
     * production call site each.
     *
     * DOCUMENTED DIVERGENCE from `HookEvent::stderrToUserOnly()`'s strict reading:
     * a block REASON surfaces as a transcript `Message::system()` rather than on
     * stderr. WHY: `fwrite(STDERR, …)` from here is unassertable in a test and prints
     * into the suite's own output — the ruling {@see \SugarCraft\Crush\Cli\Bootstrap}
     * already records for this same question — and `tests/Cli/StderrEmitterCensusTest`
     * pins Chat.php's emitter counts as a literal, so any new emitter means editing a
     * census file outside this step's scope. The transcript seam IS the surface that
     * answers "where does the user see it", and it is what the two refusal helpers in
     * {@see submit()} already use. Decision E's load-bearing half IS honoured: the
     * hook's note is discarded outright on block, never smuggled in anyway.
     * `RuntimeNoticeSink` is the strict stderr-only realization, deferred with its
     * reason rather than half-wired here.
     *
     * OFF THE UPDATE PATH WHEN A HOOK LEAVES THE PROCESS (audit 15b-04). A
     * {@see Hooks\ScriptHook} in either chain is a blocking `proc_open()` drain of
     * up to 60 s, and running it here froze the TUI — no repaint, Escape or
     * Ctrl+C — for the whole of it. Such a chain is forked from a Cmd instead
     * ({@see pendTurnHooks()}): the second slot is then the PENDING pair rather
     * than a refusal, both callers return it unchanged exactly as they return a
     * refusal, and {@see resumeTurnHooks()} re-runs {@see submit()} when the
     * verdicts land, which reaches this method again and consumes them from
     * {@see $resolvedTurnHooks}. A chain of in-process PHP hooks still runs right
     * here: it was never the blocking kind, and a fork would run it on a copy of
     * memory and drop whatever state it keeps.
     *
     * @return array{0: list<Message>, 1: ?array{0: self, 1: ?\Closure}}
     */
    private function dispatchTurnHooks(string $text): array
    {
        if ($this->hooks === null) {
            return [[], null];
        }

        $turns = $this->turnController();

        // Each event is named ONCE, here: docs/HOOKS.md's events table cites this
        // method as the dispatch site of both, and its drift guard counts the
        // references.
        $promptEvent = \SugarCraft\Crush\Hooks\HookEvent::UserPromptSubmit;
        $sessionEvent = \SugarCraft\Crush\Hooks\HookEvent::SessionStart;

        // THE RE-ENTRY (audit 15b-04): a chain that was forked off update() by
        // {@see pendTurnHooks()} has already been judged, and {@see resumeTurnHooks()}
        // runs submit() again with its verdicts stored here. Consumed instead of
        // run, so a script hook fires exactly once per submission, and matched on
        // the text so a verdict can only ever judge the prompt it was given.
        $resolved = $this->resolvedTurnHooks;
        // SessionStart rides the first prompt into an EMPTY history — the
        // once-per-empty-history gate documented above.
        $firesSessionStart = count($this->history) === 0;
        if ($resolved !== null && $resolved['text'] === $text) {
            $promptResult = $resolved['prompt'];
            // On the re-entry the child already decided whether SessionStart
            // fired: it read the history at the moment the prompt was submitted,
            // which is the moment the gate is defined against, so a notice that
            // landed in history during the wait cannot un-fire it.
            $sessionResult = $resolved['session'];
        } else {
            $resolved = null;
            $promptContext = $this->turnHookContext($promptEvent->value, $text, false);
            $sessionContext = $firesSessionStart ? $this->turnHookContext($sessionEvent->value, $text, true) : null;

            // OUT-OF-PROCESS HOOKS GO TO A FORKED CHILD; IN-PROCESS ONES STAY
            // HERE. The question is asked before anything runs so a chain with a
            // ScriptHook in it never starts on the TUI's own thread. The contexts
            // are built HERE and handed down, so this method stays the one
            // dispatch site docs/HOOKS.md's events table names for both.
            if ($this->turnHooksMustFork($firesSessionStart ? [$promptEvent, $sessionEvent] : [$promptEvent])) {
                return [[], $this->pendTurnHooks($text, $promptContext, $sessionContext)];
            }

            // Gate-first, the order the forked child runs too.
            [$promptResult, $sessionResult] = \SugarCraft\Crush\Host\TurnController::runTurnHooks(
                $this->hooks,
                $promptContext,
                $sessionContext,
            );
        }

        $blocked = $this->turnHookRefusalReason($promptResult);
        if ($blocked !== null) {
            // HookEvent::discardsOnBlock(): the prompt is NOT submitted. The draft
            // stays in the box exactly as the other refusals in submit() leave it
            // — unless the user typed a NEW draft while a forked chain ran, in
            // which case {@see resumeTurnHooks()} keeps theirs (losing typed text
            // is the worse error) and the notice quotes the prompt instead of
            // claiming it is still in the box.
            return [[], [$this->mutate([
                'history' => [...$this->history, Message::notice(
                    $turns->turnHookBlockedNotice($blocked, $text, $resolved['boxOccupied'] ?? false),
                )],
            ]), null]];
        }

        return [$turns->turnHookNotes($promptResult, $sessionResult), null];
    }

    /**
     * Whether this submission's turn-hook chain has to leave the TUI's thread
     * (audit 15b-04): a hook on one of $events runs out of process AND this
     * build can fork. Without pcntl the chain runs synchronously here exactly as
     * it did before, the same degradation {@see forkToolCalls()} takes.
     *
     * @param list<\SugarCraft\Crush\Hooks\HookEvent> $events
     */
    private function turnHooksMustFork(array $events): bool
    {
        return $this->turnController()->turnHooksMustFork($this->hooks, $events);
    }

    /**
     * Park $text behind a forked turn-hook chain and hand back the Cmd that runs
     * it (audit 15b-04). $sessionContext is null when SessionStart does not fire
     * for this submission (history was not empty when it was submitted).
     *
     * The model half is pure: `inFlight` is held with a fresh
     * {@see CancellationToken} so the mid-turn policy applies unchanged — Enter
     * queues, `/` commands are refused, double-Escape cancels — the generation is
     * bumped so a verdict for an abandoned submission is recognisably stale, and
     * the box is consumed the way {@see dispatchTurn()} consumes it, so the text
     * cannot be queued a second time by the next Enter. The fork itself happens in
     * the Cmd, never here.
     *
     * Built from `$this`, like every refusal: whatever submit() computed on the way
     * here (a compaction rewrite, a breaker outcome) is dropped and recomputed by
     * the re-entry, so the turn goes out against the state the user is looking at
     * when the verdict lands.
     *
     * @return array{0: self, 1: \Closure}
     */
    private function pendTurnHooks(string $text, HookContext $promptContext, ?HookContext $sessionContext): array
    {
        $cancellation = new CancellationToken();
        $generation = $this->generation + 1;

        $next = $this->mutate([
            'inFlight' => true,
            'inFlightCancellation' => $cancellation,
            'generation' => $generation,
            'lastEscapeAt' => null,
            'pendingTurnHooks' => [
                'text' => $text,
                'draft' => $this->inputBuf,
                'cursor' => $this->inputCursorOffset(),
            ],
            'inputBuf' => '',
        ]);

        return [$next, self::forkTurnHooksCmd(
            $this->hooks ?? throw new \LogicException('pendTurnHooks() needs a hook manager'),
            $promptContext,
            $sessionContext,
            $generation,
            $text,
            $cancellation,
        )];
    }

    /**
     * Poll interval for a forked turn-hook child — the same 50 ms
     * {@see waitForToolChildrenAsync()} polls tool children at; the poll
     * itself is {@see \SugarCraft\Crush\Host\TurnController::forkPayload()}'s.
     */
    private const TURN_HOOK_POLL_SECONDS = \SugarCraft\Crush\Host\TurnController::FORK_POLL_SECONDS;

    /**
     * The Cmd that runs a turn's hook chain in a forked child and resolves with a
     * {@see TurnHooksResolvedMsg} (audit 15b-04).
     *
     * FORK + WNOHANG POLL, the shape {@see forkToolCalls()} and
     * {@see waitForToolChildrenAsync()} already use: the child runs the chain to
     * completion (gate-first, as {@see dispatchTurnHooks()} documents —
     * SessionStart only once UserPromptSubmit permitted) and writes both verdicts
     * through {@see Support\ToolIpcFiles} (0600, atomic rename); the parent polls
     * from a loop timer, so the frame keeps painting and the keyboard stays live
     * for however long a script hook takes.
     *
     * A CHILD THAT REPORTS NOTHING FAILS CLOSED: a crashed or truncated payload
     * becomes a DENY, because "the prompt gate could not answer" letting the
     * prompt through is the same widening {@see HookResult::permitsExecution()}'s
     * allow-list exists to prevent.
     *
     * CANCEL KILLS THE TREE. A double-Escape cancels $cancellation; the next poll
     * takes the child and every process under it — the setsid'd hook script and
     * whatever that script started — through
     * {@see Support\ProcessContainment::killTreeAsync()}, reaps it on the
     * bounded WNOHANG window {@see reapKilledToolChildren()} uses (as loop
     * timers, audit R3), and resolves null so nothing is dispatched.
     *
     * QUITTING DOES NOT WAIT FOR IT. Ctrl+C quits at once (it could not while
     * the chain ran inside update()); the child runs out its chain — bounded by
     * the chain budget for script hooks — and exits, and a payload nobody
     * collects is a {@see Support\ToolIpcFiles::CHAT_PREFIX} file the next
     * launch's sweep removes.
     *
     * Static, capturing only the manager and contexts, so the closure holds no
     * Chat state that could go stale while it waits. No fork possible (pcntl_fork
     * returning -1) runs the chain inline as the pre-15b-04 path did: blocking,
     * but never wrong.
     */
    private static function forkTurnHooksCmd(
        HookManager $hooks,
        HookContext $promptContext,
        ?HookContext $sessionContext,
        int $generation,
        string $text,
        CancellationToken $cancellation,
    ): \Closure {
        $run = static fn (): array => \SugarCraft\Crush\Host\TurnController::runTurnHooks($hooks, $promptContext, $sessionContext);

        return self::forkedPayloadCmd(
            static function () use ($run): string {
                [$prompt, $session] = $run();

                return \SugarCraft\Crush\Host\TurnController::turnHookPayload($prompt, $session);
            },
            static function (string $file) use ($generation, $text): Msg {
                [$prompt, $session] = self::collectTurnHookResults($file);

                return new TurnHooksResolvedMsg($generation, $text, $prompt, $session);
            },
            static function () use ($run, $generation, $text): Msg {
                [$prompt, $session] = $run();

                return new TurnHooksResolvedMsg($generation, $text, $prompt, $session);
            },
            $cancellation,
        );
    }

    /**
     * Run $childWork in a forked child and resolve with the Msg its payload
     * decodes to — the one fork + WNOHANG-poll shape behind both pieces of
     * {@see submit()} that leave the update path: a turn's script hooks (audit
     * 15b-04, {@see forkTurnHooksCmd()}) and a command file's shell forms (audit
     * 15b-20, {@see forkCustomCommandCmd()}).
     *
     * - The child runs $childWork, writes the string it returns through
     *   {@see Support\ToolIpcFiles} (0600, atomic rename) and exits through
     *   {@see Support\ForkedChild::exitNow()}, never back into the loop.
     * - The parent polls from a loop timer every {@see TURN_HOOK_POLL_SECONDS},
     *   so the frame keeps painting and the keyboard stays live, and hands the
     *   payload file to $collect once the child has been reaped.
     * - CANCEL KILLS THE TREE: once $cancellation fires, the next poll takes the
     *   child and everything under it — a setsid'd hook script or `bash -c`, and
     *   whatever that started — through {@see Support\ProcessContainment::killTreeAsync()},
     *   reaps it on {@see reapKilledToolChildren()}'s bounded window (both on
     *   the loop, audit R3), discards the payload and resolves null, so
     *   nothing is dispatched.
     * - A failed fork (-1) runs $inline here, blocking but never wrong — the
     *   pre-fix behaviour, as {@see forkToolCalls()} degrades.
     *
     * @param \Closure(): string          $childWork runs in the child; returns the payload
     * @param \Closure(string): Msg       $collect   decodes the payload file (and discards it)
     * @param \Closure(): Msg             $inline    the synchronous fallback
     */
    private static function forkedPayloadCmd(
        \Closure $childWork,
        \Closure $collect,
        \Closure $inline,
        CancellationToken $cancellation,
    ): \Closure {
        return Cmd::promise(static fn (): PromiseInterface => \SugarCraft\Crush\Host\TurnController::forkPayload(
            $childWork,
            $collect,
            $inline,
            $cancellation,
            self::REAP_BUDGET_SECONDS,
            self::REAP_POLL_MICROSECONDS / 1_000_000,
        ));
    }

    /**
     * @return array{action: string, message: string, modifiedInput: ?string, additionalContext: string}
     */
    private static function turnHookResultToArray(\SugarCraft\Crush\Hooks\HookResult $result): array
    {
        return \SugarCraft\Crush\Host\TurnController::turnHookResultToArray($result);
    }

    /**
     * Read back what a turn-hook child wrote, discarding the payload either way.
     * Anything unreadable is a DENY — see {@see forkTurnHooksCmd()}.
     *
     * @return array{0: \SugarCraft\Crush\Hooks\HookResult, 1: ?\SugarCraft\Crush\Hooks\HookResult}
     */
    private static function collectTurnHookResults(string $file): array
    {
        return \SugarCraft\Crush\Host\TurnController::turnHookResultsFromPayload(self::takeIpcPayload($file));
    }

    private static function turnHookResultFromArray(mixed $row): ?\SugarCraft\Crush\Hooks\HookResult
    {
        return \SugarCraft\Crush\Host\TurnController::turnHookResultFromArray($row);
    }

    /**
     * Re-enter {@see submit()} with a forked chain's verdicts (audit 15b-04).
     *
     * Dropped when it is stale: no submission is pending, or the generation moved
     * (a double-Escape cancelled it), or it judged other text.
     *
     * Otherwise the box is seeded back with the draft Enter consumed — text and
     * cursor, so {@see dispatchTurn()}'s `/rewind` capture is what the synchronous
     * path recorded — and submit() runs again from the top against the CURRENT
     * state, with {@see $resolvedTurnHooks} standing in for the hook run. Whatever
     * the user typed during the wait is put back afterwards:
     *  - the turn went out (or parked): dispatchTurn() blanked the box, and their
     *    new draft replaces the blank;
     *  - the prompt was refused: the refusal leaves the submitted text in the box,
     *    which is kept when they typed nothing, and replaced by their draft when
     *    they did (the notice then quotes the prompt — see dispatchTurnHooks()).
     *
     * A refusal ends the "turn" the pending state stood in for, so the prompts
     * queued during the wait are released here, as at every other turn end.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function resumeTurnHooks(TurnHooksResolvedMsg $msg): array
    {
        $pending = $this->pendingTurnHooks;
        if ($pending === null || $msg->generation !== $this->generation || $msg->text !== $pending['text']) {
            return [$this, null];
        }

        $typedDraft = $this->input;
        $boxOccupied = trim($this->inputBuf) !== '';

        [$after, $cmd] = $this->mutate([
            'inFlight' => false,
            'inFlightCancellation' => null,
            'pendingTurnHooks' => null,
            'resolvedTurnHooks' => [
                'text' => $msg->text,
                'prompt' => $msg->prompt,
                'session' => $msg->session,
                'boxOccupied' => $boxOccupied,
            ],
            'inputBuf' => $pending['draft'],
        ])->withInputCursor($pending['cursor'])->submit();

        $after = $after->mutate(['resolvedTurnHooks' => null])->withoutStaleCustomExpansion();
        if ($after->inFlight || $boxOccupied) {
            $after = $after->mutate(['input' => $typedDraft]);
        }

        if ($after->inFlight) {
            return [$after, $cmd];
        }

        return self::releaseQueuedPrompts([$after, $cmd]);
    }

    /**
     * Whether expanding the command file $text names would run a shell, and so
     * has to leave the TUI's thread (audit 15b-20).
     *
     * True only when every one of these holds, so every other expansion stays
     * synchronous and byte-identical:
     * - $text names a file-based command whose body has a `` !`…` `` form
     *   ({@see CommandSpec::hasShellSubstitution()}) — `$ARGUMENTS` and `@path`
     *   are bounded in-process work;
     * - the form could actually reach a shell: a PROJECT-tier body in an
     *   untrusted checkout has every shell form refused by
     *   {@see refuseCommandShell()}'s first check, which reads nothing but this
     *   model, so forking it would buy nothing;
     * - no expansion for this exact line is already in hand (the re-entry);
     * - this build can fork. Without pcntl the expansion runs here as before,
     *   the degradation {@see forkToolCalls()} takes.
     */
    private function customCommandMustFork(string $text): bool
    {
        return $this->turnController()->customCommandMustFork(
            $text,
            $this->customCommands,
            $this->resolvedCustomCommand['text'] ?? null,
            $this->projectCommandsTrusted,
        );
    }

    /**
     * Park the command line $text behind a forked template expansion and hand
     * back the Cmd that runs it (audit 15b-20) — the {@see pendTurnHooks()}
     * shape, for the same reasons: `inFlight` held with a fresh
     * {@see CancellationToken} so Enter queues and double-Escape cancels, the
     * generation bumped so an abandoned expansion is recognisably stale, and the
     * box consumed so the line cannot be submitted twice. Pure; the fork happens
     * in the Cmd.
     *
     * @return array{0: self, 1: \Closure}
     */
    private function pendCustomCommand(string $text): array
    {
        $cancellation = new CancellationToken();
        $generation = $this->generation + 1;

        $next = $this->mutate([
            'inFlight' => true,
            'inFlightCancellation' => $cancellation,
            'generation' => $generation,
            'lastEscapeAt' => null,
            'pendingCustomCommand' => [
                'text' => $text,
                'draft' => $this->inputBuf,
                'cursor' => $this->inputCursorOffset(),
            ],
            'inputBuf' => '',
        ]);

        return [$next, $this->forkCustomCommandCmd($text, $generation, $cancellation)];
    }

    /**
     * The Cmd that expands $text's template in a forked child and resolves with
     * a {@see CustomCommandExpandedMsg} (audit 15b-20), through
     * {@see forkedPayloadCmd()}.
     *
     * The child expands against THIS model — the state at the moment Enter was
     * pressed, which is what the synchronous path expanded against — so the
     * closure captures `$this` on purpose; nothing it reads can go stale, since
     * the model is immutable.
     *
     * THE EXPANSION CROSSES THE BOUNDARY BASE64-ENCODED: it carries raw command
     * output, which need not be UTF-8, and JSON's substitution would hand the
     * model different bytes from the ones the synchronous path sent.
     *
     * THE PERMISSION GATE'S STATE DOES NOT CROSS IT, which is why the child also
     * reports every command it put to the gate (see
     * {@see refuseCommandShell()}). A cancelled expansion reports nothing, so
     * its evaluations are not replayed — the commands it judged never ran.
     */
    private function forkCustomCommandCmd(string $text, int $generation, CancellationToken $cancellation): \Closure
    {
        $chat = $this;
        $run = static function () use ($chat, $text): array {
            $gated = [];
            $expanded = $chat->expandCustomCommand($text, static function (string $command) use (&$gated): void {
                $gated[] = $command;
            });

            return [$expanded, $gated];
        };

        return self::forkedPayloadCmd(
            static function () use ($run): string {
                [$expanded, $gated] = $run();

                return \SugarCraft\Crush\Host\TurnController::customCommandPayload($expanded, $gated);
            },
            static function (string $file) use ($generation, $text): Msg {
                [$expanded, $gated] = self::collectCustomCommandExpansion($file);

                return new CustomCommandExpandedMsg($generation, $text, $expanded, $gated);
            },
            static function () use ($run, $generation, $text): Msg {
                [$expanded, $gated] = $run();

                return new CustomCommandExpandedMsg($generation, $text, $expanded, $gated);
            },
            $cancellation,
        );
    }

    /**
     * Read back what an expansion child wrote, discarding the payload either
     * way. Anything unreadable is a null expansion, which
     * {@see resumeCustomCommand()} refuses rather than sends.
     *
     * @return array{0: ?string, 1: list<string>}
     */
    private static function collectCustomCommandExpansion(string $file): array
    {
        return \SugarCraft\Crush\Host\TurnController::customCommandExpansionFromPayload(self::takeIpcPayload($file));
    }

    /**
     * Re-enter {@see submit()} with a forked expansion (audit 15b-20).
     *
     * Dropped when stale — no command is pending, the generation moved (a
     * double-Escape cancelled it), or it expanded another line — exactly as
     * {@see resumeTurnHooks()} drops a stale verdict.
     *
     * Otherwise, in this order:
     * 1. the child's permission-gate evaluations are replayed against the
     *    session's gate, so the Auto-mode circuit breaker counts what the
     *    synchronous path would have counted ({@see forkCustomCommandCmd()});
     * 2. a child that reported no expansion is refused visibly with the line
     *    back in the box — sending the raw `/name` would deliver the body's
     *    intent with none of it expanded;
     * 3. the box is seeded back with the line Enter consumed (text and cursor,
     *    so `/rewind`'s draft capture matches the synchronous path) and submit()
     *    runs from the top with the expansion in {@see $resolvedCustomCommand}:
     *    the empty-expansion refusal, spend cap, compaction tiers and turn hooks
     *    all apply to it unchanged. When those turn hooks park it again
     *    (15b-04), the expansion is kept for that second re-entry.
     *
     * What the user typed during the wait is put back afterwards, and a refusal
     * releases the prompts queued meanwhile — both as in resumeTurnHooks().
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function resumeCustomCommand(CustomCommandExpandedMsg $msg): array
    {
        $pending = $this->pendingCustomCommand;
        if ($pending === null || $msg->generation !== $this->generation || $msg->text !== $pending['text']) {
            return [$this, null];
        }

        $gate = $msg->gatedCommands === [] ? null : $this->permissionGate();
        foreach ($msg->gatedCommands as $command) {
            // Root-less on purpose: a Bash verdict never reads the root
            // (audit F-J3-rem(b), pinned by ChatBashGateRootTest).
            $gate?->evaluate(new \SugarCraft\Crush\ToolCall('Bash', ['command' => $command]));
        }

        $typedDraft = $this->input;
        $boxOccupied = trim($this->inputBuf) !== '';

        $resumed = $this->mutate([
            'inFlight' => false,
            'inFlightCancellation' => null,
            'pendingCustomCommand' => null,
            'inputBuf' => $pending['draft'],
        ])->withInputCursor($pending['cursor']);

        if ($msg->expanded === null) {
            $after = $resumed->mutate([
                'history' => [...$this->history, Message::notice(
                    $this->turnController()->expansionFailedNotice($msg->text, $boxOccupied),
                )],
            ]);
            $cmd = null;
        } else {
            [$after, $cmd] = $resumed->mutate([
                'resolvedCustomCommand' => ['text' => $msg->text, 'expanded' => $msg->expanded],
            ])->submit();
            $after = $after->withoutStaleCustomExpansion();
        }

        if ($after->inFlight || $boxOccupied) {
            $after = $after->mutate(['input' => $typedDraft]);
        }

        if ($after->inFlight) {
            return [$after, $cmd];
        }

        return self::releaseQueuedPrompts([$after, $cmd]);
    }

    /**
     * Drop a kept {@see $resolvedCustomCommand} unless a turn-hook chain is still
     * pending on it — the one re-entry it is kept for.
     */
    private function withoutStaleCustomExpansion(): self
    {
        return $this->resolvedCustomCommand !== null && $this->pendingTurnHooks === null
            ? $this->mutate(['resolvedCustomCommand' => null])
            : $this;
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
                // Audit F-P8: a callback's DECLARED refusal is structure, so
                // it crosses as the kind's backing value, not via the text.
                'denial' => $result->denial?->value,
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
     * @param list<array{toolCall: ToolCall, file: ?string, pid: ?int, result: ?ToolResult, hookContext: ?HookContext, preContext: string}> $jobs
     * @return PromiseInterface<list<ToolResult>>
     */
    private function waitForToolChildrenAsync(array $jobs, CancellationToken $cancellation): PromiseInterface
    {
        $deferred = new Deferred();

        // PostToolUse runs here, on the parent's side of the fork boundary,
        // for the same reason PreToolUse runs before the fork - see
        // forkToolCalls()'s docblock. The pre-hook note crosses the same
        // boundary inside the job array and is appended by the same call.
        $collect = fn(array $job): ToolResult => $this->applyPostToolUse(
            $job['hookContext'] ?? null,
            $job['result'] ?? $this->collectToolResult((string) $job['file'], $job['toolCall']),
            (string) ($job['preContext'] ?? ''),
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
            //
            // killTree(), not a bare SIGKILL of the fork (the Chat mirror of
            // audit F-E2/B2): a tool child's own commands run setsid'd in
            // their own process group, so killing only the forked PHP pid
            // reparented `bash` to init and the cancelled command finished
            // anyway. killTree() freezes the tree, signals every member's
            // group and pid, degrades to the old single-pid kill without
            // /proc, and leaves the root for the reap below.
            //
            // Audit R3: killTreeAsync(), so each tree's /proc walk takes its
            // own loop ticks instead of ~110 ms of the render thread per
            // child, and the shared reap budget is a timer too. All trees are
            // walked concurrently and the batch settles once every one is
            // signalled and the shared window has collected what it can.
            $settled = true;
            $loop->cancelTimer($timer);

            $stragglers = [];
            $kills = [];
            foreach ($pendingIndexes as $index => $_) {
                $kills[] = \SugarCraft\Crush\Support\ProcessContainment::killTreeAsync($jobs[$index]['pid'], $loop);
                $stragglers[] = $jobs[$index]['pid'];
            }

            \React\Promise\all($kills)
                ->then(static fn(): PromiseInterface => \SugarCraft\Crush\Support\ProcessContainment::reapAsync(
                    $stragglers,
                    self::REAP_BUDGET_SECONDS,
                    self::REAP_POLL_MICROSECONDS / 1_000_000,
                    $loop,
                ))
                ->then(static function () use ($deferred, $collect, $jobs): void {
                    $deferred->resolve(array_map($collect, $jobs));
                });
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
     * THE LIVE SITES REAP ON THE LOOP NOW (audit R3): both cancel branches
     * hand their pids to {@see \SugarCraft\Crush\Support\ProcessContainment::reapAsync()}
     * with this same per-site budget and poll, after
     * {@see \SugarCraft\Crush\Support\ProcessContainment::killTreeAsync()}.
     * This synchronous spelling is the reference the budget tests pin, and
     * the one to use from a site that is not inside a loop callback.
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
     * Read one forked child's IPC payload and discard it, whether or not it was
     * readable — shared by the tool fan-out ({@see collectToolResult()}) and the
     * turn-hook fork ({@see collectTurnHookResults()}), which write through the
     * same {@see \SugarCraft\Crush\Support\ToolIpcFiles} file shape.
     *
     * Unconditionally, and including the `.partial` sibling: the old "only
     * unlink what we successfully read" left an empty or half-written payload
     * behind forever, which is the same leak ToolIpcFiles::sweep() exists to mop
     * up after a cancel.
     */
    private static function takeIpcPayload(string $file): string|false
    {
        $data = is_file($file) ? file_get_contents($file) : false;
        \SugarCraft\Crush\Support\ToolIpcFiles::discard($file);

        return $data;
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
        $data = self::takeIpcPayload($file);

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
            denial: isset($r['denial']) && is_string($r['denial']) ? DenialKind::tryFrom($r['denial']) : null,
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
        $copy = $this->trackTextSelection($msg);
        [$next, $cmd] = $this->handlePointer($msg);

        if ($copy === null) {
            return [$next, $cmd];
        }

        return [$next, $cmd === null ? $copy : Cmd::batch($cmd, $copy)];
    }

    /**
     * The mouse text selection in progress or just copied, or null.
     *
     * Static for {@see $pressGesture}'s reason — the gesture spans several
     * `update()` calls on an immutable Chat — and public because the renderer
     * paints its highlight and the pane shell routes a drag's release here
     * even when it lands over chrome ({@see textSelectionInProgress()}).
     */
    public static function textSelection(): ?TextSelection
    {
        return self::$textSelection;
    }

    /** True between a press that anchored a selection and its release. */
    public static function textSelectionInProgress(): bool
    {
        return self::$textSelection !== null && !self::$textSelection->settled;
    }

    /** Drop any selection; for tests and for hosts that stop painting the chat. */
    public static function clearTextSelection(): void
    {
        self::$textSelection = null;
    }

    /** @see textSelection() */
    private static ?TextSelection $textSelection = null;

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
        // scanner, candy-mouse's Selection and Renderer::selectableRegion()
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
        $agentPrefix = \SugarCraft\Crush\Tui\AgentStrip::ZONE_PREFIX;
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
        if ($zoneId === \SugarCraft\Crush\Tui\AgentViewHeader::BACK_ZONE) {
            return [$this, static fn (): CloseAgentViewMsg => new CloseAgentViewMsg()];
        }
        $navPrefix = \SugarCraft\Crush\Tui\AgentViewHeader::NAV_ZONE_PREFIX;
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
     * Is this zone one of the session picker's OWN rows right now — the
     * whitelist the picker arm of {@see refuseMouseDispatch()} checks, under
     * the same triple validation {@see paletteRowIndex()} states (prefix,
     * digits only, row exists) and the same measured reason: a bare prefix
     * test would let a forged `session-row:`-prefixed id through.
     *
     * "Exists" here is stronger than the palette's `< count()`: the id must
     * name a row in the CURRENTLY PAINTED window — {@see SessionPicker::rowZoneLines()}
     * is the same pure derivation {@see Renderer::renderSessionPicker()} marks
     * zones from, so a zone can only be whitelisted where a line was actually
     * rendered to click on. A row scrolled out of the window keeps its index
     * in the filtered list but is unclickable until it is painted again; the
     * arrow keys still reach it.
     */
    private function sessionRowIndex(string $zoneId): ?int
    {
        if ($this->sessionPicker === null) {
            return null;
        }

        // An action glyph (`session-act:<row>:<verb>`) passes only for the
        // HIGHLIGHTED row and a verb its painted cluster actually shows —
        // the cluster is drawn on that row alone, so any other pairing is a
        // zone nothing painted.
        $actPrefix = Renderer::SESSION_ACT_ZONE_PREFIX;
        if (str_starts_with($zoneId, $actPrefix)) {
            if (preg_match('/\A(\d+):([a-z]+)\z/', substr($zoneId, strlen($actPrefix)), $m) !== 1) {
                return null;
            }
            [$width, $height] = SessionPicker::overlayGeometry($this->cols(), $this->rows(), Renderer::SHELL_CHROME_COLS);
            $row = (int) $m[1];

            return $row === $this->sessionPicker->selectedIndex()
                && array_key_exists($m[2], $this->sessionPicker->actionSegments($width, $this->theme()))
                && array_key_exists($row, $this->sessionPicker->rowZoneLines($width, $height, $this->theme()))
                ? $row
                : null;
        }

        $prefix = Renderer::SESSION_ROW_ZONE_PREFIX;
        if (!str_starts_with($zoneId, $prefix)) {
            return null;
        }

        $index = substr($zoneId, strlen($prefix));
        if (preg_match('/\A\d+\z/', $index) !== 1) {
            return null;
        }

        [$width, $height] = SessionPicker::overlayGeometry(
            $this->cols(),
            $this->rows(),
            Renderer::SHELL_CHROME_COLS,
        );
        $row = (int) $index;

        return array_key_exists($row, $this->sessionPicker->rowZoneLines($width, $height, $this->theme()))
            ? $row
            : null;
    }

    /**
     * Click-to-select on an open session picker row (E744 WS4).
     *
     * Deliberately SELECT-only, unlike the palette's click (§8 E6 mirrors
     * Enter and dispatches the row): activating a row here RESUMES a session
     * wholesale, and the pointer affordance the acceptance asked for is
     * "forward mouse into the list pane" — the widget's own mouse semantics.
     * Activation stays one deliberate `↵` away.
     *
     * The row index was validated against the painted window by
     * {@see sessionRowIndex()} at the guard; this re-checks through the
     * picker's own clamp because the state could have been rebuilt between
     * the press and the release the tracker pairs (a wheel notch in another
     * update(), say) — a click whose row vanished under it selects nothing.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function selectSessionRow(string $index): array
    {
        $picker = $this->sessionPicker;
        if ($picker === null || preg_match('/\A\d+\z/', $index) !== 1) {
            return [$this, null];
        }

        [$next, $cmd] = $picker->updateClick((int) $index);
        if ($next === $picker) {
            return [$this, null];
        }

        return [$this->mutate(['sessionPicker' => $next]), $cmd === null ? null : $this->relayWidgetCmd($cmd)];
    }

    /**
     * A click on one glyph of the highlighted picker row's cluster: `✎`
     * renames, `★` pins, `✕` arms the delete (and a second `✕` confirms it).
     * Each is the SAME keystroke path the keyboard takes — the Ctrl alias,
     * which means the same thing in and out of the filter — so the mouse
     * cannot do anything the keys cannot, and every refusal applies to both.
     *
     * The zone was whitelisted by {@see sessionRowIndex()}; it is re-checked
     * here because the picker can have changed between press and release.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function runSessionRowAct(string $zoneId): array
    {
        if ($this->sessionRowIndex($zoneId) === null) {
            return [$this, null];
        }

        $rune = match (substr($zoneId, (int) strrpos($zoneId, ':') + 1)) {
            'rename' => 'e',
            'pin' => 'f',
            'delete' => 'd',
            default => null,
        };

        return $rune === null
            ? [$this, null]
            : $this->handleSessionPickerKey(new KeyMsg(KeyType::Char, $rune, ctrl: true));
    }

    /**
     * Wheel while the picker is up (E744 WS4): the notch moves the picker's
     * selection through the widget's own wheel arm, and a navigation that
     * ARRIVES on the last loaded row raises the WS5 load-more edge — relayed
     * through the same WS1 wrapper the keyboard browse uses, so both pointer
     * devices feed one pipeline.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function sessionPickerWheel(MouseButton $direction): array
    {
        $picker = $this->sessionPicker;
        \assert($picker !== null);

        [$next, $cmd] = $picker->updateWheel($direction);

        return [
            $this->mutate(['sessionPicker' => $next]),
            $cmd === null ? null : $this->relayWidgetCmd($cmd),
        ];
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
        // P-A4: a second click on the same tab inside the double-click window
        // renames that session inline. The first click of the pair already
        // switched to it (or it was current), so the editor always opens on
        // the session the tab names. Under an open palette or picker the pair
        // is not a rename — those overlays own the screen.
        $now = microtime(true);
        $previous = $this->lastTabClick;
        $isDoubleClick = $previous !== null
            && $previous[0] === $id
            && $now - $previous[1] <= self::TAB_DOUBLE_CLICK_SECONDS;
        if ($isDoubleClick && $id === $this->currentSessionId && $this->palette === null && $this->sessionPicker === null) {
            $clicked = $this->mutate(['lastTabClick' => null]);

            return $clicked->readOnlyRefusal('/rename') ?? $clicked->handleRenameCommand('/rename');
        }

        [$next, $cmd] = $this->switchToTab($id);

        return [$next->mutate(['lastTabClick' => [$id, $now]]), $cmd];
    }

    /**
     * {@see selectSessionTab()}'s single-click answer: switch to the session
     * the tab names.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function switchToTab(string $id): array
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

        return [$this->switchToSession($id, $this->storedSessionName($id)), null];
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
            // not the {@see refuseInFlightCommand()} a TYPED `/agents` gets,
            // because that notice ends "your draft is still in the box:
            // press Enter again" — true of a typed command, and a sentence
            // about a draft that a click never touched would be a claim
            // attached to the wrong thing. (Ctrl+A goes through
            // {@see runCommand()}'s own refusal, which names the command and
            // says the draft was not touched.)
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
        // `bracketedPaste: true` (E704) asks the terminal to wrap pasted text
        // in `CSI 200~ … CSI 201~`, which is what lets a multi-line paste reach
        // {@see update()} as ONE {@see PasteMsg} instead of a burst of Char/
        // Enter keys that submit a line each. `Program::setupTerminal()` writes
        // the `CSI ?2004h` enable only when this is set; the default sanitize
        // (`ProgramOptions::$sanitizePaste`) stays ON so the payload is
        // escape/control-stripped by InputReader before it reaches the model.
        return new ProgramOptions(
            useAltScreen: true,
            mouseMode: self::mouseMode(),
            bracketedPaste: true,
        );
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

    /**
     * Swap the backend — also the live half of a settings save that rebuilds
     * the engine ({@see applySettings()}), which only ever calls this between
     * turns: the completion handlers of a running turn close over the backend
     * it started on.
     */
    public function withBackend(Backend $backend): self
    {
        return $this->mutate(['backend' => $backend, 'summaryBackend' => $this->summaryBackendFollowing($backend)]);
    }

    /**
     * The summary backend after a switch to $backend (roadmap 2.4-2). The
     * launch's {@see \SugarCraft\Crush\Backend\CacheReusingSummaryBackend}
     * sends the conversation's own request, and its engine half is the
     * engine it was built from — left alone, a `/model` or provider switch
     * kept summarising on the LAUNCH model, whose cache the switched
     * conversation no longer shares. Rebuilt on the new engine, carrying the
     * launch-time summary model (never re-read here: that key is read at
     * launch). A summary backend that does not reuse the cache, or a new
     * backend that cannot, is kept as it is.
     */
    private function summaryBackendFollowing(Backend $backend): ?Backend
    {
        $current = $this->summaryBackend;
        if (!$current instanceof \SugarCraft\Crush\Backend\CacheReusingSummaryBackend
            || !$backend instanceof \SugarCraft\Crush\Backend\SummarisesWithCache) {
            return $current;
        }
        $launch = $current->engine();

        return \SugarCraft\Crush\Backend\CacheReusingSummaryBackend::new(
            $current->toolless(),
            $backend instanceof Backend\EngineBackend
                ? $backend->withSummaryModel($launch instanceof Backend\EngineBackend ? $launch->summaryModel() : null)
                : $backend,
        );
    }

    /**
     * The `workerProvider` spec a pool's forked workers should build after a
     * switch to `$provider` (W1-h's carried half of N-P3a): the launch's own
     * derivation ({@see \SugarCraft\Crush\Cli\Bootstrap::workerProviderSpec()}),
     * on the model the switched engine actually runs — a `/model <p> <m>`
     * whose save failed still runs `<m>` this session. A backend that is not
     * an engine gets NO spec, so a worker refuses rather than quietly running
     * the provider the session just left.
     *
     * @return ?array<string, mixed>
     */
    private static function poolWorkerSpecFor(string $provider, Backend $backend): ?array
    {
        if (!$backend instanceof Backend\EngineBackend) {
            return null;
        }

        $spec = \SugarCraft\Crush\Cli\Bootstrap::workerProviderSpec($provider);
        if ($spec !== null) {
            $spec['model'] = $backend->model();
        }

        return $spec;
    }

    /**
     * Keys whose live apply rebuilds the engine ({@see withEngineSettings()}),
     * and is therefore held while a turn runs. Every other `Live` key is Chat
     * state or a Cmd and applies at once, even mid-turn: it touches no history.
     */
    private const ENGINE_SETTINGS = ['maxToolSteps'];

    /** Widest the settings toast is drawn, in cells. */
    public const SETTINGS_TOAST_COLS = 56;

    /** How long the settings toast stays up. */
    private const SETTINGS_TOAST_SECONDS = 6.0;

    /**
     * Make a settings save take effect (roadmap N-P3, Appendix N §4.6): each
     * changed key goes by its {@see \SugarCraft\Crush\Config\Settings\ApplyMode}.
     *
     *  - LIVE, Chat state: `theme` is re-read and becomes {@see $themeName}.
     *  - LIVE, Cmd: `statusLine` re-installs the status-line command
     *    ({@see \SugarCraft\Crush\Config\StatusLineCommand::reconfigure()}) in
     *    the returned Cmd — the command is process state, and changing it is a
     *    side effect `update()` does not perform.
     *  - LIVE, engine: {@see ENGINE_SETTINGS} rebuild the backend through
     *    {@see withBackend()} — at once when idle, otherwise parked in
     *    {@see $pendingSettingsApply} for {@see update()} to apply once the
     *    turn has ended.
     *  - NEXT TURN: nothing to do. The engine re-reads the merged settings at
     *    every turn start, in the turn child, so the next turn has them.
     *  - RESTART / NEXT LAUNCH: nothing can apply them now; the toast says so.
     *  - `provider` and `layout` are live through their own doors (`/model`,
     *    the pane shell), which apply them as they write them.
     *
     * The values are read back from the merged settings rather than carried
     * in: the session tier, a reset and a value another tier still outranks
     * all resolve exactly as the next launch would resolve them. That is one
     * settings read per save — an explicit act, never a keystroke.
     *
     * Feedback is a sugar-toast alert ({@see settingsToast()}), never a
     * transcript row — those are sent to the provider with every turn.
     *
     * @param list<string> $changed the keys the save set or reset
     * @param string|null $savedTo where they went (a path, or the session tier's description)
     * @return array{0: self, 1: ?\Closure}
     */
    public function applySettings(array $changed, ?string $savedTo = null): array
    {
        $changed = array_values(array_unique(array_map('strval', $changed)));
        if ($changed === []) {
            return [$this, null];
        }

        $chat = $this;
        $cmds = [];
        $now = $held = $nextTurn = $restart = [];
        $engine = false;
        $config = null;

        foreach ($changed as $key) {
            $mode = \SugarCraft\Crush\Config\Settings\SettingsSchema::byKey($key)?->applyMode
                ?? \SugarCraft\Crush\Config\Settings\ApplyMode::Restart;

            if ($mode === \SugarCraft\Crush\Config\Settings\ApplyMode::NextTurn) {
                $nextTurn[] = $key;
                continue;
            }

            if ($mode !== \SugarCraft\Crush\Config\Settings\ApplyMode::Live) {
                $restart[] = $key;
                continue;
            }

            if (\in_array($key, self::ENGINE_SETTINGS, true)) {
                if ($chat->inFlight) {
                    $held[] = $key;
                } else {
                    $engine = true;
                    $now[] = $key;
                }
                continue;
            }

            if ($key === 'theme') {
                $config ??= \SugarCraft\Crush\Cli\Bootstrap::readUserConfig();
                $theme = \is_string($config['theme'] ?? null) ? $config['theme'] : 'dark';
                try {
                    // theme() throws on a name it does not know, and a hand
                    // edit can put one in a file the reset now falls back to.
                    Theme::byName($theme);
                    $chat = $chat->mutate(['themeName' => $theme]);
                } catch (\InvalidArgumentException) {
                    $restart[] = $key;
                    continue;
                }
            } elseif ($key === 'statusLine') {
                $cmds[] = static function (): StatusLineTickMsg {
                    StatusLineCommand::reconfigure(\SugarCraft\Crush\Cli\Bootstrap::readUserConfig());

                    // configure() zeroed the refresh clock, so the tick arm
                    // runs the new command now instead of a refresh period on.
                    return new StatusLineTickMsg();
                };
            }

            $now[] = $key;
        }

        if ($engine) {
            $chat = $chat->withEngineSettings();
        }

        if ($held !== []) {
            $chat = $chat->mutate([
                'pendingSettingsApply' => array_values(array_unique([...$chat->pendingSettingsApply, ...$held])),
            ]);
        }

        [$chat, $toastCmd] = $chat->withSettingsToast(
            self::settingsSavedSummary(\count($changed), $savedTo, \count($now), \count($held), \count($nextTurn), $restart),
            $restart === [] ? \SugarCraft\Toast\ToastType::Success : \SugarCraft\Toast\ToastType::Info,
        );
        $cmds[] = $toastCmd;

        return [$chat, \count($cmds) === 1 ? $cmds[0] : Cmd::batch(...$cmds)];
    }

    /**
     * The settings keys parked until the running turn ends ({@see applySettings()}).
     *
     * @return list<string>
     */
    public function pendingSettingsApply(): array
    {
        return $this->pendingSettingsApply;
    }

    /** The settings-save toast to paint, or null ({@see applySettings()}). */
    public function settingsToast(): ?\SugarCraft\Toast\Toast
    {
        return $this->settingsToast;
    }

    /**
     * Apply what {@see applySettings()} parked while a turn ran. Called by
     * {@see update()} on the first message after which no turn is in flight.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function releasePendingSettings(): array
    {
        $keys = $this->pendingSettingsApply;
        $chat = $this->mutate(['pendingSettingsApply' => []])->withEngineSettings();

        return $chat->withSettingsToast(
            'The turn ended, so ' . implode(', ', $keys) . ' now ' . (\count($keys) === 1 ? 'applies' : 'apply') . '.',
            \SugarCraft\Toast\ToastType::Success,
        );
    }

    /**
     * Re-derive every {@see ENGINE_SETTINGS} key on the engine from the
     * merged settings. Idempotent, so it re-applies them all rather than
     * tracking which one moved.
     *
     * `maxToolSteps` goes through {@see \SugarCraft\Crush\Cli\Bootstrap::resolvedMaxToolSteps()},
     * the rule the launch applies, so a live value and a launch value can
     * never parse differently. Unset (a reset) means the engine's own default,
     * which is read off `EngineBackend`'s constructor rather than restated.
     * A non-engine backend has no step ceiling to move.
     */
    private function withEngineSettings(): self
    {
        if (!$this->backend instanceof Backend\EngineBackend) {
            return $this;
        }

        $steps = \SugarCraft\Crush\Cli\Bootstrap::resolvedMaxToolSteps()
            ?? (int) (new \ReflectionParameter([Backend\EngineBackend::class, '__construct'], 'maxSteps'))->getDefaultValue();

        return $this->withBackend($this->backend->withMaxSteps($steps));
    }

    /**
     * Show `$text` as the settings toast, with the tick that takes it down.
     *
     * The toast never expires by the wall clock (no duration): it goes when
     * its own {@see \SugarCraft\Crush\Tui\Settings\SettingsToastExpiredMsg}
     * lands, so a frame is a function of the model and nothing else.
     *
     * @return array{0: self, 1: \Closure}
     */
    private function withSettingsToast(string $text, \SugarCraft\Toast\ToastType $type): array
    {
        $generation = $this->settingsToastGeneration + 1;
        $toast = \SugarCraft\Toast\Toast::new(self::SETTINGS_TOAST_COLS)
            ->withDuration(null)
            ->withSymbolSet(\SugarCraft\Toast\SymbolSet::Unicode)
            ->alert($type, $text);

        return [
            $this->mutate(['settingsToast' => $toast, 'settingsToastGeneration' => $generation]),
            Cmd::tick(
                self::SETTINGS_TOAST_SECONDS,
                static fn (): \SugarCraft\Crush\Tui\Settings\SettingsToastExpiredMsg => new \SugarCraft\Crush\Tui\Settings\SettingsToastExpiredMsg($generation),
            ),
        ];
    }

    /**
     * "Saved 3 settings to ~/.sugar-crush/config.json · 1 applies now ·
     * 1 next turn · 1 needs a restart (instructions)".
     *
     * @param list<string> $restart
     */
    private static function settingsSavedSummary(int $count, ?string $savedTo, int $now, int $held, int $nextTurn, array $restart): string
    {
        $parts = [sprintf('Saved %d setting%s', $count, $count === 1 ? '' : 's')
            . ($savedTo === null || $savedTo === '' ? '' : ' to ' . $savedTo)];
        if ($now > 0) {
            $parts[] = $now . ' ' . ($now === 1 ? 'applies' : 'apply') . ' now';
        }
        if ($held > 0) {
            $parts[] = $held . ' when this turn ends';
        }
        if ($nextTurn > 0) {
            $parts[] = $nextTurn . ' next turn';
        }
        if ($restart !== []) {
            $parts[] = \count($restart) . ' ' . (\count($restart) === 1 ? 'needs' : 'need') . ' a restart (' . implode(', ', $restart) . ')';
        }

        return implode(' · ', $parts);
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
     * The launch's workspace — the host services (Appendix O §4.3) this Chat
     * resolves — or null for a Chat built without one (tests, embedders).
     */
    public function workspace(): ?\SugarCraft\Crush\Host\WorkspaceContext
    {
        return $this->workspace;
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
     * The session's rulebook toggle set — the state `/rules` reads and writes
     * (P6.S3).
     *
     * Bare accessor, no `get`, per the project convention. Never null (see the
     * property's doc-block for the reason), and it is THE SAME OBJECT the
     * turn's backend holds on a real launch, which is what makes a toggle here
     * reach the prompt with no push-back step.
     */
    public function rulesState(): RulesState
    {
        return $this->rulesState;
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

    /** Who set {@see currentSessionName()} — see {@see $currentSessionTitleSource}. */
    public function currentSessionTitleSource(): ?\SugarCraft\Crush\Session\TitleSource
    {
        return $this->currentSessionTitleSource;
    }

    /** The open inline session-title editor, or null — read by Renderer. */
    public function titleEditor(): ?\SugarCraft\Forms\TextInput\TextInput
    {
        return $this->titleEditor;
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
     * THE BUILT POOL RUNS THIS CHAT'S ENGINE when there is a real one. On an
     * {@see Backend\EngineBackend} over any provider but the offline echo,
     * each agent is a whole tool loop through that engine
     * ({@see \SugarCraft\Crush\Agents\EngineExecutor}, run in the pool's
     * forked child) — the same executor `/workflow` stages use. Otherwise the
     * pool keeps {@see \SugarCraft\Crush\Agents\ProcessExecutor}'s worker,
     * which consults `workerProvider` once and fails closed without one: an
     * echo or command backend is not a model an agent should be run against
     * as though it were (E663).
     *
     * The resolved pool is then driven THROUGH {@see AgentManager::executeAll()}
     * whenever an AgentManager is configured, rather than being iterated
     * directly. The manager registers each SubAgent and mirrors the pool's
     * per-result usage back onto it, which is the only thing that makes
     * {@see AgentManager::elapsedSeconds()}/tokensUsed()/costUsd() -- and so
     * AgentDashboardPane::agentEntry()'s status line -- observe real work instead
     * of zeros (crush_feat.md section 5 E6). Dispatch stays single-pass: the
     * manager accumulates with `+=`, so the pool must never also be iterated
     * for the same SubAgent instances or usage would be counted twice.
     *
     * A BUILT POOL BOUNDS EACH AGENT BY THE CONFIG'S TIMEOUT (audit AG-5).
     * Since WF-1 the pool enforces {@see \SugarCraft\Crush\Agents\SubAgent::$timeout}
     * on its forking path, and a SubAgent built without one carries the
     * constructor's 300 s default — so an agent run here was killed at 300 s
     * whatever {@see \SugarCraft\Crush\Agents\AgentPoolConfig::$defaultTimeoutSeconds}
     * said, while the ProcessExecutor branch already took the config's figure.
     * Each agent is therefore dispatched as a copy carrying the config's
     * timeout (`0` = no per-agent bound); the copies are what the manager
     * registers. An explicit pool ({@see withWorkerPool()}) is the caller's,
     * and its agents run exactly as given.
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
            //
            // E652: the config's provider spec goes to BOTH sides. The executor is
            // what puts it on the forked worker's startup frame; the pool parameter
            // is what a pool that builds its OWN default executor (no injected one)
            // would carry. Feeding both keeps the two construction paths honest —
            // neither can silently fork a worker with no provider while the other
            // has one. A null spec stays null: the worker then FAILS naming the
            // absence rather than fabricating, which is the point of E641-era
            // fail-closed behaviour this wiring preserves.
            if ($this->backend instanceof Backend\EngineBackend
                && !$this->backend->provider() instanceof \SugarCraft\Crush\Providers\EchoProvider
            ) {
                $pool = (new \SugarCraft\Crush\Agents\AgentWorkerPool(
                    maxConcurrent: $this->agentPoolConfig->maxConcurrent,
                    workerProvider: $this->agentPoolConfig->workerProvider,
                    forkedExecutor: new \SugarCraft\Crush\Agents\EngineExecutor($this->backend),
                ))->withStopOnFirstFailure($this->agentPoolConfig->stopOnFirstFailure)
                    ->withMaxRetries($this->agentPoolConfig->maxRetries);
            } else {
                $executor = new \SugarCraft\Crush\Agents\ProcessExecutor(
                    timeoutSeconds: $this->agentPoolConfig->defaultTimeoutSeconds,
                    workerProvider: $this->agentPoolConfig->workerProvider,
                );
                $pool = (new \SugarCraft\Crush\Agents\AgentWorkerPool(
                    maxConcurrent: $this->agentPoolConfig->maxConcurrent,
                    executor: $executor,
                    workerProvider: $this->agentPoolConfig->workerProvider,
                ))->withStopOnFirstFailure($this->agentPoolConfig->stopOnFirstFailure)
                    ->withMaxRetries($this->agentPoolConfig->maxRetries);
            }

            $timeout = $this->agentPoolConfig->defaultTimeoutSeconds;
            $agents = array_map(
                static fn(\SugarCraft\Crush\Agents\SubAgent $agent): \SugarCraft\Crush\Agents\SubAgent => $agent->timeout === $timeout
                    ? $agent
                    : new \SugarCraft\Crush\Agents\SubAgent(
                        id: $agent->id,
                        agent: $agent->agent,
                        task: $agent->task,
                        createdAt: $agent->createdAt,
                        timeout: $timeout,
                        maxRetries: $agent->maxRetries,
                        isolation: $agent->isolation,
                        permissionGate: $agent->permissionGate,
                        teamId: $agent->teamId,
                        teammateId: $agent->teammateId,
                    ),
                $agents,
            );
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
     * Verbatim error text {@see historyWithInterruptedPlaceholders()} writes
     * onto a tool call that was still running when the USER cancelled the
     * turn (double-Escape). The row is otherwise the restart row
     * {@see reviveCheckpointMessage()} builds; only the reason differs,
     * because "by restart" was false for a session that never restarted and
     * the model reads this text on the next request (audit R15, the 15b-02
     * residual). {@see isInterruptedResult()} matches it too, so it draws the
     * same struck-through `⊘ interrupted` state.
     */
    public const CANCELLED_TOOL_CALL = 'Tool call interrupted: the user cancelled the turn';

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
     * IT READS THE STRUCTURAL FIELD, NEVER THE TEXT (audit F-P8). This was a
     * wrapper around {@see DenialKind::classify()} over `$result->error`, and
     * that text is the tool's own output: a Bash `printf 'Permission denied:
     * …'; exit 1`, or an MCP server's error, was drawn struck through as a
     * call that never ran though it had run. Every party that refuses a call
     * now builds the result with {@see ToolResult::denied()}, which carries
     * the kind in {@see ToolResult::$denial}, and that is all this reads. Kept
     * rather than inlined at the call sites: `isDeniedResult()` is what the
     * renderer, the tests and the doc-blocks across this application all name.
     * A caller that wants to know WHICH of the three kinds stopped the call —
     * the thing a bool cannot say — reads `$result->denial` directly.
     */
    public static function isDeniedResult(ToolResult $result): bool
    {
        return $result->denial !== null;
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
        return $result->error === self::INTERRUPTED_TOOL_CALL
            || $result->error === self::CANCELLED_TOOL_CALL;
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
        // The builder is Host\TranscriptStore's since roadmap O-2b, so a host
        // without a screen heals a checkpoint exactly as `/rewind` does.
        return \SugarCraft\Crush\Host\TranscriptStore::reviveCheckpointRow($row);
    }

    /**
     * The "this call lost its runner" row: a synthetic assistant turn whose
     * error tool_result answers $callId, so the next request's wire has no
     * `tool_use` left unanswered. One builder for every heal path, so they
     * render and serialise identically and differ only in $reason
     * ({@see INTERRUPTED_TOOL_CALL} after a restart, {@see CANCELLED_TOOL_CALL}
     * after a user cancel).
     */
    private static function interruptedToolCallMessage(string $content, string $callId, string $reason): Message
    {
        return \SugarCraft\Crush\Host\TranscriptStore::interruptedToolCallRow($content, $callId, $reason);
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
     * The {@see $expanded} key of the newest thought on screen, or null when
     * there is none: the in-flight thought ({@see Renderer::THOUGHT_LIVE_KEY})
     * while one is being written, otherwise the newest history entry carrying
     * reasoning - a settled reply, or a tool row (or its running placeholder)
     * the thought was parked on. Public because {@see Renderer} offers the
     * Ctrl+O hint on exactly the row this names.
     */
    public function latestThoughtKey(): ?string
    {
        if (trim($this->reasoningText) !== '') {
            return Renderer::THOUGHT_LIVE_KEY;
        }

        foreach (array_reverse($this->history) as $msg) {
            if ($msg->reasoning !== null && trim($msg->reasoning) !== '') {
                return Renderer::thoughtKey($msg->reasoning);
            }
        }

        return null;
    }

    /**
     * Toggle every id {@see latestToolResultIds()} returns, plus the
     * {@see latestThoughtKey()}, as one unit, so a batch of parallel tool
     * calls and the thought beside them open and close together instead of
     * needing one keypress each. The unit follows the FIRST id's current
     * state so a half-expanded unit converges rather than inverting into a
     * different half-expanded one.
     *
     * @return array{0: self, 1: null}
     */
    private function toggleLatestToolOutput(): array
    {
        $ids = $this->latestToolResultIds();
        $thought = $this->latestThoughtKey();
        if ($thought !== null) {
            $ids[] = $thought;
        }
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
     * The step the turn on screen last reported (roadmap 1.C-4), or null
     * when no turn is running or the running one reports no steps (a
     * command backend, a workflow, the echo default).
     */
    public function liveStep(): ?\SugarCraft\Crush\Events\StepStarted
    {
        return $this->inFlight && $this->liveStepGeneration === $this->generation ? $this->liveStep : null;
    }

    /** The usage the turn on screen last reported (roadmap 1.C-4); null as for {@see liveStep()}. */
    public function liveUsage(): ?\SugarCraft\Crush\Events\UsageUpdated
    {
        return $this->inFlight && $this->liveStepGeneration === $this->generation ? $this->liveUsage : null;
    }

    /** Whether the turn on screen has been asked to stop at its next step boundary (first Escape). */
    public function stopRequested(): bool
    {
        return $this->inFlight && ($this->inFlightCancellation?->isSoftCancelled() ?? false);
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
            // Carried by object identity like the two above it: this is the
            // session's `/rules` toggle set, and a clone that allocated a fresh one
            // would switch every pack back on at the next keystroke.
            'rulesState' => $this->rulesState,
            'maxCostUsd' => $this->maxCostUsd,
            'summaryBackend' => $this->summaryBackend,
            'pendingCompactionId' => $this->pendingCompactionId,
            // Carried, not recomputed: this is a run of results across turns, and
            // a clone that reset it to zero on the next keystroke would make the
            // breaker un-trippable — every keystroke would hand the tier a clean
            // slate. Same reason 'tokenTracker' above is carried by identity.
            'consecutiveRefillCompactions' => $this->consecutiveRefillCompactions,
            'queuedPrompts' => $this->queuedPrompts,
            'pendingTurnHooks' => $this->pendingTurnHooks,
            'resolvedTurnHooks' => $this->resolvedTurnHooks,
            'pendingCustomCommand' => $this->pendingCustomCommand,
            'resolvedCustomCommand' => $this->resolvedCustomCommand,
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
            // Both halves of the E17 estimator calibration are model state on
            // purpose (not tracker fields): the estimate is a per-DISPATCH
            // pairing that belongs to the turn this clone submitted, and the
            // factor is a read-only-after-settlement decision the next
            // keystroke must still see — dropped from this map either one
            // would evaporate on the first character typed after settling.
            'promptEstimateAtDispatch' => $this->promptEstimateAtDispatch,
            'tokenEstimateCalibration' => $this->tokenEstimateCalibration,
            'webSearch' => $this->webSearch,
            'promptSuggestion' => $this->promptSuggestion,
            'promptHistory' => $this->promptHistory,
            'inputHistory' => $this->inputHistory,
            'inputHistoryCursor' => $this->inputHistoryCursor,
            'inputHistoryDraft' => $this->inputHistoryDraft,
            'workflowTurnInFlight' => $this->workflowTurnInFlight,
            // By identity — see the property's doc-block.
            'transcriptWriter' => $this->transcriptWriter,
            // The lock and the read-only verdict travel together: a clone that
            // dropped the lock would let the next session switch skip its
            // release, and one that dropped the verdict would start saving a
            // session another TUI owns on the next keystroke.
            'sessionLocking' => $this->sessionLocking,
            'sessionLock' => $this->sessionLock,
            'readOnlySession' => $this->readOnlySession,
            'readOnlyDraft' => $this->readOnlyDraft,
            'initialPrompt' => $this->initialPrompt,
            // Dropped here, a provider switch made after the first keystroke
            // would lose Task and the `/rules` set again (N-P3a).
            'backendFactory' => $this->backendFactory,
            // By identity, like the collaborators it bundles: a clone that
            // dropped it would lose the switch factory and the session's
            // notice inbox on the first keystroke (O-2a).
            'workspace' => $this->workspace,
            // 1.C-4: dropped here, the step on the status bar would vanish on
            // the first keystroke typed mid-turn.
            'liveStep' => $this->liveStep,
            'liveUsage' => $this->liveUsage,
            'liveStepGeneration' => $this->liveStepGeneration,
            'currentSessionTitleSource' => $this->currentSessionTitleSource,
            'titleEditor' => $this->titleEditor,
            'lastTabClick' => $this->lastTabClick,
            'pendingSettingsApply' => $this->pendingSettingsApply,
            'settingsToast' => $this->settingsToast,
            'settingsToastGeneration' => $this->settingsToastGeneration,
        ];

        // P-A4: who named the session, and a half-typed title, both belong to
        // ONE session. Every route that changes the session — a switch, a
        // resume, /new, /branch, a picker fork — passes `currentSessionId`, so
        // this is the one place that keeps them from leaking into the next
        // session without each route having to remember. A route that read the
        // new session's own source from the store ({@see switchToSession()})
        // passes it in the same change, and that wins.
        if (array_key_exists('currentSessionId', $changes) && $changes['currentSessionId'] !== $this->currentSessionId) {
            $constructorProps['currentSessionTitleSource'] = $changes['currentSessionTitleSource'] ?? null;
            $constructorProps['titleEditor'] = null;
        }

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
     * TWENTY-FOUR OF {@see \SugarCraft\Crush\Cli\Bootstrap}'S LAUNCH-WARNING CALL
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
     * WHAT THIS SAID: FOURTEEN. WHAT IS TRUE NOW: twenty-three — E78 (round 42)
     * routed `reportPrunedSessions()`'s retention summary onto the seam, E86
     * (round 43) routed `mcpClient()`'s start-then-throw catch, and P7.S3
     * routed the two enabled-skill drop notices in
     * `Bootstrap::promptEnabledSkills()` as the seventeenth and eighteenth,
     * plus that method's two `enabledSkills` shape notices as the nineteenth
     * and twentieth, and E653 (round 65) added the twenty-first, the
     * narrowed-grant aggregate drain in `Bootstrap::chat()`, and E172 (round
     * 70) added the twenty-second, the command-file skip aggregate drained
     * from `CommandLoader::skippedFiles()`.
     * WHY THE SENTENCE STILL EARNS ITS PLACE: the
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
     * whatever it is handed. These rows used to be part of the CONVERSATION —
     * re-sent to the model on every turn, so an unbounded list would have been a
     * per-token cost for the whole session. They are {@see Message::notice()}
     * rows now (audit 15b-03): a launch warning is addressed to the person who
     * can fix the config, not to the model, and the cap bounds the transcript.
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
            $messages[] = Message::notice($notice);
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
        //
        // The settings view (roadmap N-P1) is the overlay this rule does NOT
        // close off mid-turn, and not by an exemption here: it writes no history
        // and sends nothing to the model, so its doors that matter mid-turn —
        // `Ctrl+,` then Enter on the settings pane, and the F10 menu row — open
        // it in the shell ({@see \SugarCraft\Crush\App\App::openSettings()})
        // without ever reaching this method. A TYPED `/settings` is refused here
        // like every other slash command.
        if ($this->inFlight) {
            // The classification is {@see \SugarCraft\Crush\Host\TurnController::midTurnRoute()}'s,
            // shared with a headless host: bare `/exit`/`/quit` quit, `/workflow
            // pause|status` controls a running workflow, every other command is
            // refused, a `!cmd` (roadmap 5.14g) waits its turn — steering it in
            // would hand the agent the text `!git status` to interpret — and
            // anything else is delivered as Enter delivers it. Roadmap 1.C-3
            // (decision D6): Enter mid-turn STEERS the running turn — the agent
            // reads the message at its next step boundary — and Tab queues it
            // for after the turn ({@see queueOwnsTab()}). The `queueMode`
            // setting that could make another mode Enter's default is N-P4g's;
            // until then the mode is fixed ({@see QueueMode::onEnter()}).
            return match ($this->turnController()->midTurnRoute(
                $text,
                // Only consulted past the `/exit` arm, so it is read lazily.
                $text !== '/exit' && $text !== '/quit' && $this->isWorkflowControlDuringWorkflowTurn($text),
                \SugarCraft\Crush\Host\SubmitOptions::new(),
            )) {
                \SugarCraft\Crush\Host\TurnController::ROUTE_QUIT => [$this, Cmd::quit()],
                \SugarCraft\Crush\Host\TurnController::ROUTE_WORKFLOW_CONTROL => $this->handleWorkflowCommand($text),
                \SugarCraft\Crush\Host\TurnController::ROUTE_REFUSE_COMMAND => $this->refuseInFlightCommand($text),
                \SugarCraft\Crush\Host\TurnController::ROUTE_STEER => $this->steerPrompt($text),
                // An interrupt is a host's delivery; Enter never asks for one,
                // and a queued follow-up is what this arm always did otherwise.
                default => $this->enqueuePrompt($text),
            };
        }

        // A READ-ONLY SESSION (audit SES-3(b)) refuses here, ahead of the
        // file-based commands and the built-in arms both: a prompt would start
        // a turn on a session another TUI is writing, and so would a command
        // file, which is a prompt. Only the commands that leave the session
        // untouched get through — see {@see readOnlyRefusal()}.
        $readOnly = $this->readOnlyRefusal($text);
        if ($readOnly !== null) {
            return $readOnly;
        }

        // `!<command>` RUNS A SHELL COMMAND FOR THE USER (roadmap 5.14g) and
        // puts its output in the transcript as a user-role row the model reads
        // on the next turn. Checked after the read-only refusal (a window that
        // may not start a turn may not run commands either) and before
        // file-based commands, which are `/`-prefixed and cannot collide.
        // Nothing asks permission — the person who would be asked typed it —
        // but a configured Deny rule, and plan mode's read-only rule, still
        // refuse it ({@see Commands\BangShell::refusal()}). It calls no model:
        // the command runs off the update path, holding the turn slot until
        // its row lands (see below).
        $bang = \SugarCraft\Crush\Commands\BangShell::commandOf($text);
        if ($bang !== null) {
            $root = $this->projectRoot();
            $refused = \SugarCraft\Crush\Commands\BangShell::refusal($bang, $this->permissionGate(), $root);
            if ($refused !== null) {
                // The box is consumed, as a send consumes it: a refusal that
                // kept the line would wedge {@see releaseQueuedPrompts()},
                // which reads a kept draft as "refused, retry later". ↑
                // recalls it.
                return [$this->mutate([
                    'history' => [...$this->history, Message::notice(
                        $this->turnController()->bangRefusedNotice($bang, $refused),
                    )],
                    'inputBuf' => '',
                ]), null];
            }

            // THE COMMAND OCCUPIES THE TURN (the W5-i follow-up): `inFlight`
            // is held under its own token and a bumped generation, so a prompt
            // typed while it runs waits behind it like behind any turn, and
            // Esc Esc cancels it — the token makes the Cmd's next poll kill the
            // command's process tree, and the generation bump strands its
            // result. {@see landBangShellResult()} releases the slot.
            $cancellation = new CancellationToken();
            $generation = $this->generation + 1;

            return [$this->mutate([
                'history' => [...$this->history, Message::notice(
                    $this->turnController()->bangRunningNotice($bang),
                )],
                'inputBuf' => '',
                'inFlight' => true,
                'inFlightCancellation' => $cancellation,
                'generation' => $generation,
                'lastEscapeAt' => null,
            ]), \SugarCraft\Crush\Commands\BangShell::cmd($bang, $root, $cancellation, $generation)];
        }

        // FILE-BASED COMMANDS ARE CHECKED FIRST, ahead of dispatchCommand()'s
        // built-in arms, and that ordering IS the override
        // {@see CommandLoader::loadAll()} documents: a project `compact.md` is
        // meant to replace `/compact`, and a check placed after the match arms
        // could never replace anything they already handle. The popup is built
        // on the same precedence ({@see slashCommandRows()}), so what is listed
        // and what runs cannot disagree — a claim that was FALSE for the
        // `/name:arg` spelling until {@see expandCustomCommand()} learned it,
        // and that {@see CommandRegistry::CONTROL_PLANE} bounds: nine names
        // are reserved to the application and never reach this map at all.
        //
        // It rewrites $text instead of returning a [Chat, Cmd] pair like the
        // built-ins do, because a file-based command IS a prompt — everything
        // below (spend cap, idle compaction, the 85%/95% tiers, the turn
        // dispatch itself) must apply to it exactly as it applies to typed
        // prose. Returning early would route the one kind of prompt a repository
        // can author around every one of those checks.
        // A BODY THAT RUNS A SHELL IS EXPANDED OFF THE UPDATE PATH (audit 15b-20):
        // its `` !`…` `` forms can take the whole shell budget, and running them
        // here froze the frame and the keyboard for all of it. The command line
        // is parked and the expansion forked from a Cmd; the expanded text comes
        // back as a {@see CustomCommandExpandedMsg} and re-enters this method,
        // where expandCustomCommand() consumes it, so everything below applies
        // to it exactly as on the synchronous path.
        if ($this->customCommandMustFork($text)) {
            return $this->pendCustomCommand($text);
        }

        $expanded = $this->expandCustomCommand($text);
        // Audit 15b-15: `@file` mentions are resolved in what the USER typed,
        // never in a file-based command's expansion. A command body is
        // repository-authored text with its own `@` include form, which
        // {@see Commands\CommandSpec::expandTemplate()} already resolves or
        // REFUSES by trust tier - and a refusal can leave the path standing in
        // the text, so reading mentions out of the expansion would attach the
        // very file the tier just refused to include.
        //
        // Nor in a background session's result (roadmap 4.3-1/4.3-3): the
        // announcement reaches the agent through this same door, quoting the
        // daemon's answer verbatim, and an `@path` in that answer is the
        // model's text, not the user asking for a file.
        $mentionsAreTheUsers = $expanded === null
            && !\SugarCraft\Crush\Sessions\BackgroundSession::isAnnouncement($text);
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
        $wireHistory = self::compactionWire($this->history);

        $baseHistory = $this->history;
        // `$this`, until the automatic tier has something to report about the
        // breaker's counter — the state the turn is dispatched from is a separate
        // decision from the history it is dispatched with, and conflating them is
        // how a counter written on one branch and read on another goes missing.
        $turnCarrier = $this;
        $compactionNotice = null;
        // Written only by {@see scheduleParkedCompaction()}'s spend-cap arm
        // (§E31), and a different kind of report from the two beside it rather
        // than a fourth thing to track: it says why the model was NOT asked,
        // where $compactionNotice says what the heuristic did instead. Both may
        // stay null; when both are set they ride the same turn, this one first,
        // because it is the reason for the other.
        $capNotice = null;
        // Distinct from $compactionNotice because BOTH can ride the same rescued
        // dispatch: the compaction notice reports the between-exchanges rewrite
        // that was just adopted, the truncation notice reports the intra-exchange
        // truncation layered on top of it. Sharing one variable meant a rescued
        // sync dispatch that had ALSO compacted committed its [summary] lines
        // announced only as "N messages reached the 95% blocking tier" — the
        // rewrite adopted in silence (review cycle 4, finding 1).
        $truncationNotice = null;

        if ($this->compactor->shouldCompact($wireHistory, $tokenLimit)) {
            // THE CIRCUIT BREAKER FIRST, ahead of both routes it can stop, because
            // spending nothing is the entire point (prompt_expand.md §4.23): a test
            // after the parked call would still have paid for the summarization,
            // and one after the heuristic would still have rewritten the
            // transcript. It belongs to THIS block and nowhere else — not beside
            // {@see spendCapRefusal()} above, which would also stop a manual
            // `/compact`, a command, or the prompt of a session whose tier has not
            // fired — so tripping the breaker leaves every route the user chose
            // themselves exactly as available as it was. A session that is both
            // tripped and past the blocking tier now hears this notice rather than
            // "Blocked until": the blocking refusal tells them to free space they
            // have just been shown cannot be freed by the automatic route, which is
            // the older and less actionable of the two facts.
            if (IdleCompactionPolicy::thrashTripped($this->consecutiveRefillCompactions)) {
                return $this->thrashBreakerRefusal();
            }

            // Ask the model to write the summaries first, when there is one to
            // ask (crush_code.md Phase 5 item 6). Returns null - and the
            // synchronous heuristic below then runs unchanged - whenever there is
            // no summary backend, the history holds no exchange a model could
            // usefully summarise, or the spend cap is reached, in which last case
            // it also fills $capNotice so the downgrade below says so (see that
            // variable's comment; the cap arm is dormant from here today, and the
            // shape of its answer is argued in the method's own docblock). That
            // "null falls back to exactly what this tier did before" is what makes
            // the model route safe to add here: the offline path is not merely
            // similar, it is the same code.
            //
            // A non-null answer has ALREADY run the UserPromptSubmit gate (and is
            // its refusal when the hook blocked): parking submits the prompt, so
            // the hook fires there, and this early return skips the tail call below
            // so it never fires twice (audit 15b-01).
            $parked = $this->scheduleParkedCompaction($text, $tokenCount, $tokenLimit, $capNotice, $mentionsAreTheUsers);
            if ($parked !== null) {
                return $parked;
            }

            // The synchronous heuristic route, as
            // {@see \SugarCraft\Crush\Host\TurnController::inlineTier()} runs
            // it for a headless host too: the rewrite adopted only when it bought
            // something (announcing "saved 0%" every turn would be noise), the
            // state block (roadmap 2.5) built from the WHOLE history as the
            // `/compact` and parked routes build it, the blocking tier tested
            // against the COMPACTED wire — "blocked until space is freed" only
            // means anything once the automatic way of freeing it was tried —
            // and, before refusing, the INTRA-exchange rescue (prompt_plan.md
            // P4.S4, backlog §12.2 E18) for one exchange larger than the window.
            //
            // THE BREAKER'S MEASUREMENT ($tier['refilled']) is the tier's own —
            // the same estimate function over the compaction's output against the
            // same window, read whether or not the rewrite was adopted — and it is
            // WRITTEN AFTER the outcome is known, on the two exits below.
            $tier = $this->turnController()->inlineTier(
                $this->compactionService(),
                $this->compactor,
                $this->history,
                $wireHistory,
                $tokenLimit,
                $tokenCount,
                $this->estimateTokenCount(...),
            );
            $baseHistory = $tier['history'];
            $tokenCount = $tier['tokenCount'];
            $compactionNotice = $tier['compactionNotice'];
            $truncationNotice = $tier['truncationNotice'];

            if ($tier['outcome'] === 'blocked') {
                return $this->withCompactionOutcome($tier['refilled'], turnSent: false)
                    ->foregroundBlockedResponse(
                        $text,
                        $baseHistory,
                        $tokenCount,
                        $tokenLimit,
                        $compactionNotice,
                    );
            }

            if ($tier['outcome'] === 'sent') {
                // The rewrite went out WITH the turn. Under the tier that breaks the
                // run; over it the run holds, because the prompt reached the model —
                // the no-path-forward case is the blocking refusal above, and only
                // that case (ruling P8.S5-R6).
                $turnCarrier = $this->withCompactionOutcome($tier['refilled'], turnSent: true);
            }
            // 'rescued': THE RESCUE EXEMPTION (ruling P8.S5-R6) — $turnCarrier
            // stays `$this`, so the run is neither extended nor broken by this
            // attempt; the reason is argued once, on {@see withCompactionOutcome()}.
        }

        $newTurnMessages = [];
        // Report-then-prompt, in rewrite order: every history rewrite committed
        // below is announced BEFORE the user's line — the ordering
        // contextCompactedMessage() established — and a rescued dispatch reports
        // BOTH rewrites it commits: the between-exchanges compaction first, the
        // intra-exchange truncation second. The parked route has always carried
        // both (compactionChanges() writes that rewrite report into history when
        // it lands); silence here was this route's own doing.
        //
        // DROPPED ON A HOOK BLOCK, and safely so: the turn-hook refusal below returns
        // its own notice only, so a rescue announced here goes unreported when a hook
        // blocks the prompt. The rewrite is dropped with it, not persisted: the
        // refusal commits pre-compaction history, so the tier re-runs next submit.
        if ($capNotice !== null) {
            // First of the three because it is the reason the next one exists: the
            // model was withheld, and what follows is what was done instead. This is
            // the third slot of the same kind {@see submit()} already had to separate
            // for the two rewrites — one report per fact, because a message that
            // carries two facts reports neither of them once one of them is
            // truncated.
            $newTurnMessages[] = Message::notice($capNotice);
        }
        if ($compactionNotice !== null) {
            $newTurnMessages[] = $compactionNotice;
        }
        if ($truncationNotice !== null) {
            $newTurnMessages[] = $truncationNotice;
        }

        // TURN-LIFECYCLE HOOKS (P7.S2) fire here and nowhere earlier. Every arm
        // above returns BEFORE a draft has become a submitted prompt — queued
        // mid-turn, custom-command expansion, built-in dispatch, spend cap, idle
        // compaction, the thrash breaker and the 95% refusal — and Anthropic fires
        // UserPromptSubmit on the real submitted text, so a slash command that
        // never reached a model must not look like one to a hook. A blocked prompt
        // returns the refusal pair instead of dispatching; the notes, when there
        // are any, go immediately ahead of the user's line.
        //
        // The ONE arm above that does submit is the 85% tier's parked route, and
        // it is not an exception to the gate: {@see scheduleParkedCompaction()}
        // runs this same call at the moment it commits to parking and returns
        // before reaching here, so each submission is judged exactly once
        // (audit 15b-01 — it used to be judged never).
        [$turnHookNotes, $turnHookRefusal] = $this->dispatchTurnHooks($text);

        if ($turnHookRefusal !== null) {
            return $turnHookRefusal;
        }

        // THE STATE BEFORE THIS PROMPT, as `/rewind` restores it (audit SES-1),
        // taken at the one point where the rewrite reports are in and nothing of
        // the submission itself is yet: the compacted/rescued history PLUS the
        // reports that describe that rewrite, and NOT the hook notes or the
        // user's line. See {@see dispatchTurn()}'s $preTurnHistory for why the
        // rewrite stays and the submission goes.
        $preTurnHistory = [...$baseHistory, ...$newTurnMessages];

        foreach ($turnHookNotes as $note) {
            $newTurnMessages[] = $note;
        }

        // Audit 15b-15: `@file` mentions become attachments on the user's line,
        // and anything a mention could not attach is said just ahead of it.
        [$userTurn, $attachmentNotes] = $this->userTurnMessage($text, $mentionsAreTheUsers);
        foreach ($attachmentNotes as $note) {
            $newTurnMessages[] = $note;
        }
        $newTurnMessages[] = $userTurn;

        return $turnCarrier->dispatchTurn(
            $baseHistory,
            $newTurnMessages,
            $tokenLimit,
            $preTurnHistory,
            $this->inputBuf,
            $this->inputCursorOffset(),
        );
    }

    /**
     * The user row a submitted prompt becomes, with its `@file` mentions
     * attached, plus one notice per mention that could not be (audit 15b-15).
     *
     * The ONE constructor of a dispatched user turn, used by both routes that
     * commit one - {@see submit()}'s and the parked compaction's - so a prompt
     * that happens to cross the 85% tier does not lose its attachments. The
     * files are read HERE, once, and the snapshot rides on the message (see
     * {@see Attachment} for why not at send time); {@see FileMentions} bounds
     * that read. The notices are UI-only: they report on the user's own input
     * and the model already sees the mention text itself.
     *
     * `$resolveMentions` is false for a file-based command's expansion; see
     * {@see submit()} for why its `@` forms are never read here.
     *
     * The keyword mentions (`@diff`, `@session:<id>`, `@https://…`, roadmap
     * 5.8) are resolved beside the files by
     * {@see \SugarCraft\Crush\Attachments\ContextMentions}: this session's
     * store answers `@session:`, and a URL a permission rule denies `WebFetch`
     * is refused rather than fetched.
     *
     * @return array{0: Message, 1: list<Message>}
     */
    private function userTurnMessage(string $text, bool $resolveMentions = true): array
    {
        return $this->turnController()->userTurnMessage(
            $text,
            $resolveMentions,
            $this->projectRoot(),
            $this->sessionStore,
            $this->permissionGate(),
        );
    }

    /**
     * Land a finished `!cmd` (roadmap 5.14g): append its row, give back the
     * turn slot it occupied, and send whatever was queued behind it — the
     * release every turn end does ({@see releaseQueuedPrompts()}).
     *
     * A result stamped with a generation that is no longer current belongs to
     * a command Esc Esc already cancelled: that arm released the slot and said
     * so, and the command's late row is dropped like a cancelled turn's reply.
     * An unstamped result (an embedder's own {@see Commands\BangShell::cmd()}
     * call) is only appended, the way it always was.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function landBangShellResult(BangShellResultMsg $msg): array
    {
        if ($msg->generation === null) {
            return [$this->mutate(['history' => [...$this->history, $msg->message]]), null];
        }

        if ($msg->generation !== $this->generation || !$this->inFlight) {
            return [$this, null];
        }

        return self::releaseQueuedPrompts([$this->mutate([
            'history' => [...$this->history, $msg->message],
            'inFlight' => false,
            'inFlightCancellation' => null,
            'lastActivityAt' => new \DateTimeImmutable(),
        ]), null]);
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
    private const IN_FLIGHT_QUOTE_MAX_CHARS = \SugarCraft\Crush\Host\TurnController::IN_FLIGHT_QUOTE_MAX_CHARS;

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
        return \SugarCraft\Crush\Host\TurnController::quoteDraft($text);
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
            'history' => [...$this->history, Message::notice(
                $this->turnController()->queuedNotice(count($queue), $text),
            )],
        ]), null];
    }

    /**
     * Steer a prompt sent mid-turn into the RUNNING turn (roadmap 1.C-3): the
     * turn's own handle ({@see CancellationToken::steer()}) carries it to the
     * forked turn's parent, which writes it down as a `steer` frame, and the
     * engine loop appends it at its next step boundary — skipping the step's
     * calls that have not started, so the message is read before more work
     * is done.
     *
     * NEVER LOST. The text is ALSO held on {@see $queuedPrompts}, exactly as
     * {@see enqueuePrompt()} holds a follow-up: when the turn settles,
     * {@see releaseQueuedPrompts()} drops it if the turn delivered it (the
     * settled transcript carries its `[steering]` row) and otherwise sends it
     * as the next prompt — a steer that arrived after the turn's last step
     * boundary, or a turn that could not take one, ends up exactly where a
     * queued message would have.
     *
     * A turn whose handle refuses the steer (it is already stopping) or that
     * is not an engine turn is simply queued.
     *
     * @return array{0:self,1:?\Closure}
     */
    private function steerPrompt(string $text): array
    {
        if (!$this->backend instanceof Backend\InteractiveTurn
            || $this->inFlightCancellation?->steer($text) === null) {
            return $this->enqueuePrompt($text);
        }

        $queue = [...$this->queuedPrompts, $text];

        return [$this->mutate([
            'queuedPrompts' => $queue,
            'inputBuf' => '',
            'history' => [...$this->history, Message::notice(
                $this->turnController()->steeringNotice($text),
            )],
        ]), null];
    }

    /**
     * Whether a bare Tab QUEUES the draft for after the running turn
     * (roadmap 1.C-3, `chat.queue`) — the follow-up half of "Enter steers,
     * Tab queues" (Codex, nanobot). Only mid-turn, with a draft that would be
     * sent (not a slash command, which is refused mid-turn), and only when
     * neither completion owns Tab and no modal is up — the same reachability
     * contract as {@see slashMenuOwnsTab()}: the shell yields Tab on exactly
     * this predicate, so a yielded Tab always lands on a live arm.
     */
    public function queueOwnsTab(): bool
    {
        $draft = trim($this->inputBuf);

        return $this->inFlight
            && $draft !== ''
            && !str_starts_with($draft, '/')
            && $this->keyHelp === null
            && $this->pendingPermission === null
            && $this->palette === null
            && $this->sessionPicker === null
            && !$this->slashMenuOwnsTab()
            && !$this->mentionOwnsTab();
    }

    /**
     * Hold the draft as a follow-up for after the running turn (`chat.queue`).
     *
     * @return array{0:self,1:?\Closure}
     */
    private function queueDraft(): array
    {
        return $this->enqueuePrompt(trim($this->inputBuf));
    }

    /**
     * $queue without the entries the turn that just settled delivered as
     * steers (roadmap 1.C-3).
     *
     * A delivered steer is a hidden user row of exactly
     * {@see Backend\SocketSteerInbox::content()}'s bytes, folded into the
     * history from the turn's transcript. Only the rows AFTER the turn's own
     * prompt — the last user row the transcript shows — are counted, so a
     * steer an earlier turn delivered cannot swallow the same words queued
     * now; each delivered row cancels one queued entry.
     *
     * @param list<string>  $queue
     * @param list<Message> $history
     * @return list<string>
     */
    private static function withoutDeliveredSteers(array $queue, array $history): array
    {
        return \SugarCraft\Crush\Host\TurnController::withoutDeliveredSteers($queue, $history);
    }

    /**
     * Refuse, VISIBLY, a file-based command whose template expanded to nothing.
     *
     * @return array{0:self,1:?\Closure}
     */
    private function refuseEmptyCustomCommand(string $text): array
    {
        return [$this->mutate([
            'history' => [...$this->history, Message::notice(
                $this->turnController()->emptyCustomCommandNotice($text),
            )],
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
            'history' => [...$this->history, Message::notice(
                $this->turnController()->inFlightCommandNotice($text),
            )],
        ]), null];
    }

    /**
     * The row {@see withSessionLocking()} adds when the session it opened is
     * held by another TUI (audit SES-3(b)): `%s` the session's name or id,
     * `%s` ` (pid N)` or ''. Public because the read-only tests and the
     * README quote it.
     */
    public const READ_ONLY_SESSION_NOTICE = 'Session %s is open in another sugarcrush%s, so this window is '
        . 'read-only: nothing typed here is sent to the model or saved to that session. Type /branch to fork '
        . 'it into a new session this window owns and carry on there, or close the other window and this one '
        . 'becomes writable by itself.';

    /**
     * The row double-Escape adds when the turn it cancels is a workflow run:
     * the run's agents are being stopped, and its partial report — a
     * {@see CancelledWorkflowReportMsg} — follows once they have.
     */
    public const WORKFLOW_CANCELLED_NOTICE = '_Workflow cancelled: stopping its agents. Its report follows._';

    /**
     * The row {@see retakenSessionLock()} adds when a read-only window takes
     * the lock after the other TUI let go: `%s` the session's name or id.
     * Public for the tests that quote it.
     */
    public const SESSION_WRITABLE_NOTICE = 'The other sugarcrush has closed session %s, so this window can write '
        . 'to it now. The transcript was reloaded to include what it saved.';

    /**
     * The row {@see refuseReadOnly()} adds for input a read-only session will
     * not run: `%s` the quoted draft, `%s` the session's name or id.
     */
    public const READ_ONLY_REFUSAL = \SugarCraft\Crush\Host\TurnController::READ_ONLY_REFUSAL;

    /**
     * The built-in commands a READ-ONLY session still runs: the ones that only
     * read, change this window's own view or settings, leave the process, or
     * move to ANOTHER session (`/branch`, `/sessions`, `/fork` and `/bg`, which
     * read the stored transcript and write only to a new session).
     *
     * AN ALLOWLIST, so it fails closed: a command added later is refused in a
     * read-only window until someone decides it is safe here. Left out on
     * purpose: `/clear`, `/compact`, `/rename`, `/rewind`, `/undo`, `/redo`
     * (each rewrites the session another TUI is writing; the last three can
     * rewrite its files too) and `/workflow run|resume` (a run appends to
     * it). `/diff` only reads the checkpoints and the files, so it is in.
     * `/workflow list|status` read only, and are let through by
     * {@see isReadOnlySafeCommand()}.
     */
    private const READ_ONLY_COMMANDS = [
        'exit', 'quit', 'keys', 'help', 'permissions', 'notices', 'rules', 'budget', 'share',
        'agent', 'agents', 'memory', 'bg', 'background', 'fork', 'branch', 'sessions', 'theme',
        'mcp', 'websearch', 'pane', 'layout', 'model', 'editor', 'settings', 'config', 'diff',
        'context', 'tokens',
    ];

    /**
     * The refusal for $text when this session is read-only and $text would
     * write to it or start a turn; null when it may run.
     *
     * @return array{0: self, 1: ?\Closure}|null
     */
    private function readOnlyRefusal(string $text): ?array
    {
        if (!$this->readOnlySession || $this->isReadOnlySafeCommand($text)) {
            return null;
        }

        return $this->refuseReadOnly($text);
    }

    /**
     * Say why $text did not run, and hold on to it.
     *
     * THE BOX IS CLEARED AND THE DRAFT STASHED, rather than left in place: the
     * way out of a read-only window is typing `/branch`, and a draft left in
     * the box would have to be deleted first — measured live, the next thing
     * typed was appended to it and refused in turn. {@see handleBranchCommand()}
     * puts the stashed draft back once the fork succeeds, so the prompt the
     * user meant to send is waiting in the window that can now send it.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function refuseReadOnly(string $text): array
    {
        return [$this->mutate([
            'history' => [...$this->history, Message::notice($this->turnController()->readOnlyNotice(
                $text,
                $this->currentSessionName ?? (string) $this->currentSessionId,
            ))],
            'inputBuf' => '',
            'readOnlyDraft' => $text,
        ]), null];
    }

    /**
     * Whether $text is a built-in command {@see READ_ONLY_COMMANDS} lets
     * through. A file-based command is a PROMPT ({@see submit()}), so one that
     * shadows an allowed name is refused like any prompt.
     */
    private function isReadOnlySafeCommand(string $text): bool
    {
        if (self::isBareMcpAuthCommand($text)) {
            return true;
        }

        if (!str_starts_with($text, '/') || $this->resolveCustomCommand($text) !== null) {
            return false;
        }

        $tokens = self::commandTokens($text);
        $name = substr($tokens[0], 1);

        if ($name === 'workflow') {
            return \in_array($tokens[1] ?? 'list', ['list', 'status'], true);
        }

        return \in_array($name, self::READ_ONLY_COMMANDS, true);
    }

    /**
     * Whether $text is `/workflow pause …` or `/workflow status …` typed while
     * the turn in flight is a workflow run — the one exception to
     * {@see refuseInFlightCommand()}'s rule besides `/exit`/`/quit` (audit WF-4).
     *
     * Since WF-2 the engine can pause a LIVE run, but the run occupies the
     * turn, so the refusal used to block the very command that pauses it; the
     * only way through was Esc Esc, which releases the turn instead of pausing
     * the run. Neither command rewrites history the run is appending to: pause
     * writes the pause file, status reads state, and both answer with UI-only
     * rows that leave the turn running
     * ({@see \SugarCraft\Crush\Host\Commands\WorkflowCommand}, whose results
     * hold the turn).
     *
     * Narrow on purpose: only while the work in flight IS a workflow run (a
     * model turn still refuses), only those two verbs (`run`/`resume` would
     * interleave a second run), and only when no file-based command overrides
     * `/workflow` — a custom `workflow.md` is a prompt, and a prompt queues.
     */
    private function isWorkflowControlDuringWorkflowTurn(string $text): bool
    {
        if (!$this->workflowTurnInFlight || $this->workflowEngine === null) {
            return false;
        }

        $tokens = self::commandTokens($text);
        if ($tokens[0] !== '/workflow' || !\in_array($tokens[1] ?? '', ['pause', 'status'], true)) {
            return false;
        }

        return $this->resolveCustomCommand($text) === null;
    }

    /**
     * Run a command line on the user's behalf — a menu-bar row, Ctrl+N,
     * the shell's provider picker — WITHOUT touching the draft (audit 15b-05).
     *
     * WHY THIS EXISTS. The shell used to run these by feeding synthetic
     * keystrokes into {@see update()}: Backspace/Delete until the box was
     * empty, then `/name`, then Enter. The draft was therefore destroyed
     * BEFORE anything decided whether the command could run, so mid-turn the
     * user lost their half-written follow-up and was then told by
     * {@see refuseInFlightCommand()} that "your draft is still in the box" —
     * the box held `/model`, not anything they wrote. Idle, the command ran
     * and the draft was silently gone. The keystrokes also went wherever
     * {@see update()} routes keys first, so with an overlay or the permission
     * prompt up they landed there instead of in the draft.
     *
     * SAME DOOR AS A TYPED COMMAND, MINUS THE DRAFT. Idle, the line is seeded
     * into `inputBuf` and {@see submit()} runs unchanged — the
     * {@see releaseQueuedPrompts()} technique — so the file-based override,
     * every {@see dispatchCommand()} arm, and (for a line that turns out to be
     * a prompt, e.g. a project command file) the spend cap, compaction tiers
     * and turn hooks all apply exactly as when it is typed. The draft widget
     * (text AND cursor) and the slash-popup highlight are put back afterwards.
     *
     * Three deliberate differences from Enter on a typed line:
     *  - MID-TURN IT IS REFUSED BEFORE ANYTHING IS TOUCHED, with a notice that
     *    says the draft was left alone — the typed refusal's "press Enter
     *    again" would run the user's own draft, not this command. Bare
     *    `/exit`/`/quit` still quit, as in submit().
     *  - IT IS NOT RECORDED FOR ↑ RECALL ({@see recordInputHistory()}): recall
     *    gives back what the user wrote, and a menu pick is not that.
     *  - OVERLAYS ARE CLOSED FIRST (palette, session picker, key reference):
     *    submit() is only ever reached from {@see update()} below all three
     *    arms, so its handlers assume none is up; the command may open its own
     *    (bare `/model` opens the provider list).
     *
     * WHEN THE COMMAND WRITES THE BOX ITSELF (`/rewind` puts the rewound
     * prompt back), a non-blank user draft still wins: that prompt is in ↑
     * recall, the unsent draft is nowhere else. A blank draft takes what the
     * command left — what typing it would have shown — except the command's
     * own line, which only a refusal leaves behind.
     *
     * Not a prompt door: a line that is not a command is rejected rather than
     * sent to the model, because no caller of this means to send prose.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function runCommand(string $commandText): array
    {
        $text = trim($commandText);
        if (!str_starts_with($text, '/') && !self::isBareMcpAuthCommand($text)) {
            throw new \InvalidArgumentException(sprintf(
                'runCommand() takes a command line (a leading "/", or "mcp auth ..."); got "%s".',
                self::quoteDraftForNotice($text),
            ));
        }

        if ($this->inFlight) {
            if ($text === '/exit' || $text === '/quit') {
                return [$this, Cmd::quit()];
            }

            return [$this->mutate([
                'history' => [...$this->history, Message::notice(
                    $this->turnController()->runCommandInFlightNotice($text),
                )],
            ]), null];
        }

        $draft = $this->input;
        $draftBlank = trim($this->inputBuf) === '';

        [$after, $cmd] = $this->mutate([
            'palette' => null,
            'sessionPicker' => null,
            'keyHelp' => null,
        ])->withInputBuf($text)->submit();

        $left = trim($after->inputBuf);
        if ($draftBlank && $left !== '' && $left !== $text) {
            return [$after, $cmd];
        }

        return [$after->mutate([
            'input' => $draft,
            'slashMenuIndex' => $this->slashMenuIndex,
        ]), $cmd];
    }

    /**
     * Run a palette-only action (a {@see CommandRegistry} row flagged
     * `slashVisible: false`, e.g. "New session") on the user's behalf, by its
     * palette label — the {@see runCommand()} twin for rows {@see submit()}
     * has no arm for.
     *
     * The shell used to open the palette with Ctrl+P and type the label into
     * its filter. That never touched the draft while the palette was closed,
     * but an ALREADY-open palette toggled shut on that Ctrl+P, so the label
     * was typed into the draft and Enter sent it to the model as a prompt;
     * with the session picker or key reference up the keys went there
     * instead. This dispatches the action directly, from a fresh root palette
     * so the handlers see the state Enter on that row would (MRU recorded,
     * palette closed by the handler), and leaves the draft alone.
     *
     * Mid-turn it is refused exactly as Enter on that palette row is
     * ({@see runSelectedPaletteActionWhileInFlight()}): Exit quits, View
     * settings opens the read-only view, anything else gets
     * {@see refuseInFlightAction()}'s notice, which closes the palette and
     * claims nothing about the draft.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function runPaletteAction(string $label): array
    {
        $action = PaletteAction::byLabel($label);
        if ($action === null) {
            throw new \InvalidArgumentException(sprintf('No palette action is labelled "%s".', $label));
        }

        $opened = $this->mutate([
            'palette' => PaletteState::root(),
            'sessionPicker' => null,
            'keyHelp' => null,
        ]);

        if ($this->inFlight) {
            return match ($action) {
                PaletteAction::Exit => [$opened->mutate(['palette' => null]), Cmd::quit()],
                PaletteAction::OpenSettings => $opened->mutate(['palette' => null])->openSettingsView(),
                default => $opened->refuseInFlightAction($label),
            };
        }

        return $opened->rememberPaletteUse($label)->runRootPaletteAction($label);
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
     * and Ctrl+A, which reaches {@see runCommand()}'s refusal instead and so
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
            'history' => [...$this->history, Message::notice(
                $this->turnController()->inFlightActionNotice($what),
            )],
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
     *   * Ctrl+A. Its arm runs `/agents` through {@see runCommand()}, which
     *     leaves the draft alone and refuses mid-turn with a notice that says
     *     so (audit 15b-34; the arm used to replace the draft with `/agents`
     *     and submit it, and this route then answered with the TYPED refusal,
     *     whose "press Enter again" would have sent the user's draft). It also
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
                ->runCommand('/agents');
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

        // Roadmap 1.C-3: a steer the turn delivered mid-turn is done; only
        // the ones it never read (and the Tab-queued follow-ups) go out now.
        $queue = self::withoutDeliveredSteers($chat->queuedPrompts, $chat->history);
        if ($queue !== $chat->queuedPrompts) {
            $chat = $chat->mutate(['queuedPrompts' => $queue]);
            if ($queue === []) {
                return [$chat, $cmd];
            }
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
     * The checkpoint written further down strips $preTurnHistory through the
     * same {@see withoutContextReminders()}, so it carries no stale copy either.
     *
     * `$pendingCompactionId` is deliberately untouched. A `/compact`
     * summarization outstanding across a turn is a supported state (see
     * {@see HistoryCompactedMsg}), and on the parked route the landing
     * compaction has already released the latch before this is reached.
     *
     * THE CHECKPOINT IS THE STATE BEFORE THE PROMPT, NOT AFTER IT (audit
     * SES-1). It used to serialise `$next->history`, which ends with the user's
     * line, beside a draft that IS that line — so `/rewind` put the prompt back
     * in the box AND left it in the transcript: Enter sent it twice and the
     * model saw two consecutive user rows. $preTurnHistory is what the caller
     * says the transcript was before this submission, and "before" is chosen as:
     *
     *  - EVERY ROW THE SUBMISSION ITSELF ADDED IS OUT: the user's line, the
     *    UserPromptSubmit hook notes written beside it, the 70% reminder. A
     *    resend through the restored draft regenerates all three, so keeping
     *    any of them would double it.
     *  - THE COMPACTION THE SUBMISSION TRIGGERED STAYS IN, with the report rows
     *    that describe it (the tier notice, the intra-exchange truncation
     *    notice, the spend-cap notice; on the parked route the park notice and
     *    the landing report). A rewrite is a fact about the transcript, not
     *    about the prompt: it may have been a model summarisation already
     *    billed, and restoring the pre-compaction transcript would put the
     *    session straight back over the tier, so the resend re-parks and pays
     *    again — and each rewind-and-resend would count as one more refill
     *    against the thrash breaker. Nothing is lost by keeping it: the
     *    PREVIOUS turn's checkpoint still holds the uncompacted transcript, so
     *    `/rewind 2` reaches it. The reports travel with the rewrite because a
     *    transcript of `[summary]` rows with the notice that explains them cut
     *    away reads as corruption.
     *
     * The draft and caret come from the caller too, because only it knows them: on
     * {@see submit()}'s route they are the pre-clear buffer, while on the parked
     * route the buffer was consumed one update() earlier and the box now holds
     * whatever the user has typed since.
     *
     * @param list<Message> $baseHistory
     * @param list<Message> $newTurnMessages
     * @param int $tokenLimit PROVIDER-COUNTED window from {@see contextTokenLimit()}.
     * @param list<Message> $preTurnHistory The transcript as it stood before this
     *                                  submission, for the checkpoint only.
     * @param string $preTurnDraft The draft `/rewind` re-seeds the box with.
     * @param ?int $preTurnCursor The caret within it, or null for end-of-text.
     * @return array{0:Chat,1:?\Closure}
     */
    private function dispatchTurn(
        array $baseHistory,
        array $newTurnMessages,
        int $tokenLimit,
        array $preTurnHistory,
        string $preTurnDraft,
        ?int $preTurnCursor,
    ): array {
        // Defence in depth for audit SES-3(b): submit() already refused, but a
        // turn reached by another route (a parked compaction resuming) must
        // not write checkpoints into a session another TUI owns either.
        if ($this->readOnlySession) {
            return $this->refuseReadOnly($this->turnController()->turnPrompt($newTurnMessages) ?? '');
        }

        // Reminder-tier check (R21's ContextCompactor::shouldSendReminder(),
        // 70% of the token budget by default). Unlike the idle-compaction
        // prompt in submit() — which short-circuits the turn entirely and never
        // calls the backend — this is a soft, non-blocking notice: the real
        // prompt still goes out, but a system-role warning is appended
        // alongside it so the user sees context is filling up well before
        // the hard 85%/95% compaction tiers would kick in.
        $baseWire = self::compactionWire($baseHistory);
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
            'expanded' => $this->expandedAfterLiveThought(null),
            // E17: pair THIS number — the RAW proxy over exactly the
            // history being dispatched — with what the provider reports when
            // the turn settles. Recomputed here rather than threaded from
            // submit()'s tier because $baseHistory IS the history the tier
            // last measured (raw history, compacted, or rescued, whichever
            // won). RAW rather than estimateTokenCount()'s calibrated output
            // on purpose: pairing real against raw×f would fold the previous
            // factor back into the observation and send it cycling period-2
            // around the true ratio's square root — see
            // {@see rawTokenProxy()}. Against the raw proxy the settled
            // factor IS the correction, so consecutive turns against a
            // steady provider CONVERGE instead of alternating.
            'promptEstimateAtDispatch' => $this->rawTokenProxy($baseHistory),
        ]);

        $turns = $this->turnController();

        // Auto-save checkpoint before processing prompt: THE STATE BEFORE THE
        // PROMPT (audit SES-1; see the docblock), so the restored transcript and
        // the restored draft never hold the same line twice. The draft is the
        // CALLER'S, not $next's — $next above already blanked inputBuf (E681).
        // The files come too (item 3.A-1), but not here: the capture is run at
        // the head of the turn's own Cmd below, after the frame is painted and
        // before the turn can fork and write a file — and only for a Chat given
        // an explicit project root, never the getcwd() fallback.
        $workspaceCapture = $turns->saveCheckpoint(
            $this->sessionStore,
            $this->currentSessionId,
            $this->projectRoot,
            $turns->checkpointState($preTurnHistory, $preTurnDraft, $preTurnCursor, $this->currentSessionId),
        );

        // The row's turn count and last-prompt preview, which the session
        // picker shows instead of the system prompt every session shares
        // (Appendix P §3.1, audit B3). The prompt is the user row this turn
        // added, so the parked-compaction route records the prompt it sent,
        // not whatever the box holds now.
        $turns->recordTurn($this->sessionStore, $this->currentSessionId, $newTurnMessages);

        // The prompt's row, announced to the session's durable event log
        // (Appendix O §6.5 `message.created`) BEFORE the dispatch below writes
        // `turn.started`, whose `messageId` names this same row.
        $turns->recordMessagesCreated($next->transcripts(), $this->currentSessionId, $newTurnMessages, $next->turnRunner());

        $completion = $this->scheduleBackendCompletion($next, $cancellation, $generation);
        if ($workspaceCapture !== null) {
            // Same Cmd, capture first: the snapshot is complete before the
            // completion forks the turn that may edit the files.
            $turn = $completion;
            $completion = static function () use ($workspaceCapture, $turn): mixed {
                $workspaceCapture();

                return $turn();
            };
        }
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
    private function expandCustomCommand(string $text, ?\Closure $onGateEvaluated = null): ?string
    {
        // THE RE-ENTRY (audit 15b-20): a forked child already expanded this
        // command line, shell forms and all, and {@see resumeCustomCommand()}
        // runs submit() again with the result stored here. Consumed instead of
        // expanded, so each `` !`…` `` runs exactly once per submission and the
        // turn-hook verdicts that may follow judge the same string.
        $resolved = $this->resolvedCustomCommand;
        if ($resolved !== null && $resolved['text'] === $text) {
            return $resolved['expanded'];
        }

        $command = $this->resolveCustomCommand($text);
        if ($command === null) {
            return null;
        }
        [$spec, $arguments] = $command;

        return $this->turnController()->expandCustomCommand(
            $spec,
            $arguments,
            $this->commandDirective($spec, $onGateEvaluated),
        );
    }

    /**
     * The file-based command a typed `/name …` names, with its argument string,
     * or null when it names none — the lookup half of
     * {@see expandCustomCommand()}, split out so {@see customCommandMustFork()}
     * asks about exactly the spec the expansion would use.
     *
     * @return ?array{0: CommandSpec, 1: string}
     */
    private function resolveCustomCommand(string $text): ?array
    {
        return $this->turnController()->resolveCustomCommand($text, $this->customCommands);
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
    private function commandDirective(CommandSpec $spec, ?\Closure $onGateEvaluated = null): \Closure
    {
        return $this->turnController()->commandDirective(
            $spec,
            $this->projectRoot(),
            $this->projectCommandsTrusted,
            $this->permissionGate(),
            $onGateEvaluated,
        );
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
    private function refuseCommandShell(CommandSpec $spec, string $command, ?\Closure $onGateEvaluated = null): ?string
    {
        return $this->turnController()->refuseCommandShell(
            $spec,
            $command,
            $this->projectCommandsTrusted,
            $this->permissionGate(),
            $onGateEvaluated,
        );
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
     * when the turn reaches the backend, so a registry row with no handler
     * reds the suite. Since DH-CMDS the handler is named by the command's own
     * spec file under `builtin-commands/` ({@see
     * \SugarCraft\Crush\Commands\Specs\BuiltInCommands}), together with its
     * aliases and what it is handed, so this method has no per-command arm.
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
     * - `$text === '/' . $name` ({@see
     *   \SugarCraft\Crush\Commands\Specs\BuiltInCommand::accepts()} for a
     *   `CommandArguments::None` spec) is what keeps the four argument-less
     *   commands argument-less. `/exit now` and `/keys foo` were prompts before this
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
        if (self::isBareMcpAuthCommand($text)) {
            return $this->handleMcpAuthCommand($text);
        }

        $parsed = (new CommandParser())->parse($text);
        if ($parsed === null || !str_starts_with($text, '/' . $parsed->name)) {
            return null;
        }

        // TABLE-DRIVEN (DH-CMDS): the spelling is looked up in the spec files
        // under builtin-commands/, which name each command's handler, its
        // aliases and what it is handed. There is no per-command arm here any
        // more, so a row and its dispatch cannot be added apart.
        //
        // `accepts()` is what keeps the argument-less commands argument-less:
        // `/exit now` and `/keys foo` were prompts before the parse refactor
        // because their arms compared the WHOLE trimmed buffer, and a bare name
        // match would quietly turn both into commands.
        $command = \SugarCraft\Crush\Commands\Specs\BuiltInCommands::forSpelling($parsed->name);
        if ($command === null || !$command->accepts($text, $parsed->name)) {
            return null;
        }

        $handler = (string) $command->handler;

        return match ($command->arguments) {
            \SugarCraft\Crush\Commands\Specs\CommandArguments::None => $this->{$handler}(),
            \SugarCraft\Crush\Commands\Specs\CommandArguments::Text => $this->{$handler}($text),
            \SugarCraft\Crush\Commands\Specs\CommandArguments::Parsed => $this->{$handler}($parsed->args),
        };
    }

    /**
     * The session as a {@see \SugarCraft\Crush\Host\Commands\HostCommand}
     * reads it (roadmap O-2h): this model's history and collaborators, with
     * the services shared by identity so a command that writes a memory or
     * toggles a rule pack changes the same objects the next turn reads. A
     * headless {@see \SugarCraft\Crush\Host\SessionHost} builds the same
     * value from its own state, which is how the two run one command body.
     */
    private function commandContext(): \SugarCraft\Crush\Host\Commands\CommandContext
    {
        return \SugarCraft\Crush\Host\Commands\CommandContext::new(
            history: $this->history,
            sessionId: $this->currentSessionId,
            root: $this->projectRoot,
            cols: $this->cols(),
            readOnly: $this->readOnlySession,
            permissionGate: $this->permissionGate(),
            backend: $this->backend,
            titleBackend: $this->titleBackend,
            agentManager: $this->agentManager,
            sessionStore: $this->sessionStore,
            transcripts: $this->transcripts(),
            turnRunner: $this->turnRunner(),
            rulesState: $this->rulesState,
            workflowEngine: $this->workflowEngine,
            backgroundSupervisor: $this->backgroundSupervisor,
            memoryStore: $this->memoryStore,
            webSearch: $this->webSearch,
            contextTokenLimit: $this->contextTokenLimit(),
            contextTokens: $this->contextTokens(),
            workspace: $this->workspace,
        );
    }

    /**
     * Run $command's logic for $text and apply what it decided — the body of
     * every handler whose command moved to `Host\Commands` (roadmap O-2h).
     * The handler names stay, because the spec files route to them and the
     * docs and tests cite them.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function runHostCommand(\SugarCraft\Crush\Host\Commands\HostCommand $command, string $text): array
    {
        return $this->applyCommandResult($command->run($this->commandContext(), $text));
    }

    /**
     * Apply a {@see \SugarCraft\Crush\Host\Commands\CommandResult} to this
     * model: the box is consumed, the effects become this model's own state
     * (a cleared transcript also drops the stream, the scroll and the
     * expansions; a restored checkpoint puts its draft and caret back; an
     * off-turn effect becomes a Cmd), and the rows are appended last.
     *
     * The turn is released unless the result holds it: every command but
     * `/workflow pause|status` runs idle, where that changes nothing, and
     * those two run inside the workflow turn they control (audit WF-4).
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function applyCommandResult(\SugarCraft\Crush\Host\Commands\CommandResult $result): array
    {
        $history = $this->history;
        $changes = ['inputBuf' => ''];
        if (!$result->holdsTurn) {
            $changes['inFlight'] = false;
        }
        $cmds = [];
        $cursor = null;
        $openTitleEditor = false;

        foreach ($result->effects as $effect) {
            switch ($effect->kind) {
                case \SugarCraft\Crush\Host\Commands\CommandEffectKind::ClearTranscript:
                    $history = [];
                    $changes = [
                        ...$changes,
                        'streamingText' => '',
                        'reasoningText' => '',
                        'scrollOffset' => 0,
                        'expanded' => [],
                        // An outstanding `/compact` summarization is abandoned:
                        // the exchanges it summarised are gone.
                        'pendingCompactionId' => null,
                        // Unarm the breaker with the transcript that tripped it.
                        'consecutiveRefillCompactions' => 0,
                    ];
                    break;

                case \SugarCraft\Crush\Host\Commands\CommandEffectKind::RestoreCheckpoint:
                    $history = $effect->messages();
                    // E681: the draft the checkpoint captured goes back into the
                    // box — one of mutate()'s replace-the-whole-draft routes.
                    $changes['inputBuf'] = $effect->draft();
                    // A summary landing after a restore would compact the
                    // transcript the user just recovered (see applyModelCompaction()).
                    $changes['pendingCompactionId'] = null;
                    $cursor = $effect->cursor();
                    break;

                case \SugarCraft\Crush\Host\Commands\CommandEffectKind::SwitchSession:
                    $changes['currentSessionId'] = $effect->sessionId();
                    // Out of a read-only window, the draft it refused comes
                    // back: this window can send it now ({@see refuseReadOnly()}).
                    $changes['inputBuf'] = $this->readOnlyDraft ?? '';
                    $changes['readOnlyDraft'] = null;
                    break;

                case \SugarCraft\Crush\Host\Commands\CommandEffectKind::RenameSession:
                    $changes['currentSessionName'] = $effect->title();
                    $changes['currentSessionTitleSource'] = $effect->titleSource();
                    break;

                case \SugarCraft\Crush\Host\Commands\CommandEffectKind::OpenTitleEditor:
                    $openTitleEditor = true;
                    break;

                case \SugarCraft\Crush\Host\Commands\CommandEffectKind::Async:
                    $cmds[] = Cmd::promise($effect->run());
                    break;

                case \SugarCraft\Crush\Host\Commands\CommandEffectKind::OccupyTurn:
                    // The run is a turn: it occupies the session, the spinner
                    // runs, and a second prompt queues behind it. Released by
                    // the AssistantMsg arm when its report settles.
                    $cancellation = $effect->cancellation() ?? new CancellationToken();
                    $changes['inFlight'] = true;
                    $changes['inFlightCancellation'] = $cancellation;
                    $changes['workflowTurnInFlight'] = $effect->isWorkflow();
                    $cmds[] = self::settleCommandRun($effect->run(), $cancellation);
                    break;
            }
        }

        $changes['history'] = [...$history, ...$result->rows];
        $next = $this->mutate($changes);
        // AFTER the mutate: naming `inputBuf` rebuilds the draft with the caret
        // at the end, so a captured offset is re-applied to the rebuilt draft.
        if ($cursor !== null) {
            $next = $next->withInputCursor($cursor);
        }
        if ($openTitleEditor) {
            $next = $next->openTitleEditor();
        }

        return [$next, match (\count($cmds)) {
            0 => null,
            1 => $cmds[0],
            default => Cmd::batch(...$cmds),
        }];
    }

    /**
     * The Cmd that settles an occupying command run ({@see \SugarCraft\Crush\Host\Commands\CommandEffect::occupyTurn()}):
     * its report becomes the reply that releases the turn — or, when Esc Esc
     * already released it, a {@see CancelledWorkflowReportMsg} that only
     * appends the row, so it cannot settle a turn started since.
     *
     * Resolves, never rejects: a rejection would surface as candy-core's
     * `ExceptionMsg`, which nothing handles, and leave `inFlight` latched.
     *
     * @param \Closure(): PromiseInterface<string> $run
     */
    private static function settleCommandRun(\Closure $run, CancellationToken $cancellation): \Closure
    {
        return Cmd::promise(static function () use ($run, $cancellation): PromiseInterface {
            $land = static function (string $text) use ($cancellation): Msg {
                $report = Message::assistant($text)->withUiOnly();

                return $cancellation->isCancelled()
                    ? new CancelledWorkflowReportMsg($report)
                    : new AssistantMsg($report);
            };

            try {
                return $run()->then($land, static fn (\Throwable $e): Msg => $land("**Error:** {$e->getMessage()}"));
            } catch (\Throwable $e) {
                return \React\Promise\resolve($land("**Error:** {$e->getMessage()}"));
            }
        });
    }

    /**
     * `/exit` (`/quit`) — the same quit Ctrl+C and the palette's Exit action
     * send, reachable without a modifier key.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleExitCommand(): array
    {
        return [$this, Cmd::quit()];
    }

    /**
     * `/init [focus]` (roadmap 5.14e) — the one command that STARTS A TURN.
     * It is a canned prompt, so it re-enters {@see submit()} with that prompt
     * as the draft — the releaseQueuedPrompts() technique — and the spend cap,
     * the compaction tiers and the UserPromptSubmit hook judge it exactly as
     * they judge typed prose. The argument, if any, rides along as a focus
     * instruction.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleInitCommand(string $text): array
    {
        return $this->withInputBuf(
            \SugarCraft\Crush\Commands\InitCommand::prompt(self::commandArgument($text)),
        )->submit();
    }

    /**
     * `/settings [search]` (`/config`) — roadmap N-P1. The settings view is
     * SHELL state ({@see \SugarCraft\Crush\App\App::$settingsEditor}), so
     * this clears the box and asks the host for it over the Cmd channel, the
     * `/layout` technique; the argument pre-fills the view's search. Nothing is
     * written to the transcript: the view is the answer, and a row here would
     * be sent to the model.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleSettingsCommand(string $text): array
    {
        return $this->withInputBuf('')->openSettingsView(self::commandArgument($text));
    }

    /**
     * Ask the shell for the settings view, leaving the draft alone — the
     * palette row's path, which (unlike the typed command) put nothing in
     * the box to clear.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function openSettingsView(string $query = ''): array
    {
        return [$this, Cmd::send(new \SugarCraft\Crush\Tui\Settings\OpenSettingsMsg($query))];
    }

    /**
     * `/editor [text]` (roadmap 5.14h) — hands the terminal to $VISUAL/$EDITOR
     * through candy-core's Cmd::exec; what the editor saves comes back as one
     * PasteMsg into the emptied box and is NOT sent. The argument, if any, is
     * the text the editor opens on. Allowed in a read-only window: it edits the
     * draft, never the session.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleEditorCommand(string $text): array
    {
        return [
            $this->withInputBuf(''),
            \SugarCraft\Crush\Commands\EditorCommand::new()->cmd(self::commandArgument($text)),
        ];
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
     * `/permissions` — what this session is actually gated by; the report is
     * {@see \SugarCraft\Crush\Host\Commands\PermissionsCommand}'s (roadmap
     * O-2h), read off the launch's live gate and never through its evaluator.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handlePermissionsCommand(string $inputText): array
    {
        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\PermissionsCommand(), $inputText);
    }

    /**
     * `/context` (and `/tokens`): where the next request's context window
     * goes (roadmap 5.6) — {@see \SugarCraft\Crush\Host\Commands\ContextHostCommand}.
     * The history figure is {@see contextTokens()}, the status bar's own, so
     * the two surfaces cannot disagree.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleContextCommand(string $inputText): array
    {
        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\ContextHostCommand(), $inputText);
    }

    /**
     * `/sweep [n]` (roadmap 3.B-2): prune by hand the tool outputs since the
     * last prompt, or the last n — {@see \SugarCraft\Crush\Host\Commands\SweepHostCommand}.
     * Never mid-turn: {@see submit()} refuses every slash command while one
     * runs, so the turn's own ledger cannot overwrite the sweep.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleSweepCommand(string $inputText): array
    {
        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\SweepHostCommand(), $inputText);
    }

    /**
     * `/pruning [auto|manual|off|default]` (roadmap 3.B-2): show or set this
     * session's pruning mode — {@see \SugarCraft\Crush\Host\Commands\PruningHostCommand}.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handlePruningCommand(string $inputText): array
    {
        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\PruningHostCommand(), $inputText);
    }

    /**
     * This session's context ledger as its next turn would start from it
     * (roadmap 2.2-2) — {@see \SugarCraft\Crush\Host\Commands\CommandContext::contextLedger()},
     * the one the ledger commands read, over this model's runner and store.
     */
    private function sessionContextLedger(): \SugarCraft\Crush\Context\Pruning\ContextLedger
    {
        return \SugarCraft\Crush\Host\Commands\CommandContext::new(
            history: $this->history,
            sessionId: $this->currentSessionId,
            transcripts: $this->transcripts(),
            turnRunner: $this->turnRunner(),
        )->contextLedger();
    }

    /**
     * `/notices` — every warning this launch raised, whole on the transcript
     * ({@see \SugarCraft\Crush\Host\Commands\NoticesHostCommand}).
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleNoticesCommand(string $inputText): array
    {
        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\NoticesHostCommand(), $inputText);
    }

    /**
     * One caller-supplied value, made safe to be PART OF A REPORT LINE — see
     * {@see \SugarCraft\Crush\Host\Commands\PermissionsCommand::reportField()},
     * where the `/permissions` report moved (roadmap O-2h). Kept here because
     * `Commands\NoticesCommand`, `Commands\MemoryHistoryCommand` and the
     * provider switch's report all render through this name.
     */
    public static function reportField(string $value): string
    {
        return \SugarCraft\Crush\Host\Commands\PermissionsCommand::reportField($value);
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
     * `/model <provider> <model>` (roadmap N-P3b) switches the MODEL too, not
     * just the provider - the same switch with the model id carried through
     * {@see selectPaletteProvider()}'s second argument.
     *
     * PERSISTENCE: the `onConfigChange` callback is where a `provider` choice
     * becomes durable, and the callback that writes
     * `~/.sugar-crush/config.json` is installed by `Cli\Bootstrap::chat()`; a
     * Chat built without one (every embedder, and most tests here) switches
     * for this session and persists nothing. A MODEL choice persists through
     * the workspace's {@see \SugarCraft\Crush\Config\Settings\SettingsWriter}
     * instead, into the per-provider `models` setting (decision D9).
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

        // A provider name is a single token and a model id another. More than
        // two means the user typed a sentence, and guessing which words were
        // the names would switch to something they did not ask for - so say
        // what the command takes and which names exist, in the transcript,
        // where the answer is readable.
        if (count($args) > 2) {
            $available = implode(', ', $this->availableProviderNames());

            return [$this->withInputBuf('')->mutate(['history' => [
                ...$this->history,
                Message::assistant("Usage: /model [provider [model]]. Available: {$available}")->withUiOnly(),
            ]]), null];
        }

        return $this->withInputBuf('')->selectPaletteProvider($args[0], $args[1] ?? null);
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
        // The heading is clipped like every row below (audit R4): under the old
        // 20-column floor its 20 columns always fit, which hid that it was the
        // one line this method never clipped.
        $budget = max(1, ($this->cols ?? 80) - self::HELP_CHROME_COLS);
        $lines = [self::clip('Slash commands (' . count($commands) . '):', $budget)];

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

        // max(1, …), the floor {@see Renderer} and
        // {@see Commands\TranscriptTable} now size their boxes by (audit R4).
        // The old max(20, …) floor made the listing over-wide on any terminal
        // under 30 columns: 20 columns of rows painted inside 10 of chrome is
        // wider than the terminal, and an over-wide transcript line collides
        // with the row below. With a floor of 1 every row is clipped to what
        // actually fits, down to a lone "…". ($budget is computed above, for
        // the heading.)
        foreach ($byCategory as $category => $rows) {
            $lines[] = '';
            $lines[] = self::clip($category, $budget);
            foreach ($rows as $spec) {
                // One row per spec, measured on the bytes that will print: a
                // command's text may come from a cloned repository's command
                // file or any CommandSpec::new() caller, and an LF/CR or escape
                // in it would break this column layout or reach the terminal
                // (audit 15b-16, the "/" popup's sibling surface).
                $specName = PaneLabel::of($spec->name);
                $specHint = $spec->argumentHint !== null ? PaneLabel::of($spec->argumentHint) : '';
                $specDescription = PaneLabel::of($spec->description);
                $left = '  /' . $specName . ($specHint !== '' ? ' ' . $specHint : '');
                $leftWidth = Width::string($left);
                if ($leftWidth <= self::HELP_NAME_COLS - 2) {
                    $lines[] = self::clip($left . str_repeat(' ', self::HELP_NAME_COLS - $leftWidth) . $specDescription, $budget);
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
                $lines[] = self::clip(str_repeat(' ', self::HELP_NAME_COLS) . $specDescription, $budget);
            }
        }

        $lines[] = '';
        // Clipped like every other row: at 40 columns this sentence is the
        // longest line in the listing, and an unclipped trailer would be the
        // one over-wide row the rest of this method exists to avoid.
        $lines[] = self::clip('Press ? or type /keys for the keyboard shortcut reference.', $budget);

        return [$this->withInputBuf('')->mutate(['history' => [
            ...$this->history,
            Message::assistant(implode("\n", $lines))->withUiOnly(),
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
     *   mid-turn branch at the head of submit(), and Ctrl+A — whose arm runs
     *   `/agents` through {@see runCommand()} — is intercepted ahead of its arm
     *   by {@see refuseWhileInFlight()}. Pinned by
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
     * - THE COMPACTION CIRCUIT BREAKER: reset to zero, beside the transcript whose
     *   rewrites it counted. The run prompt_expand.md §4.23 counts is a run of ONE
     *   context refilling, and this command is the user declaring that context
     *   finished; leaving the count standing would refuse the first ordinary prompt
     *   of a brand-new session because of a transcript that is no longer there.
     * - SPEND TOTAL AND CAP: untouched, and deliberately so. Both belong to the
     *   LAUNCH rather than to the transcript ({@see $tokenTracker} is carried by
     *   object identity through every clone), and money already spent does not
     *   become unspent because the screen was cleared.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleClearCommand(): array
    {
        // The decision is {@see \SugarCraft\Crush\Host\Commands\ClearCommand}'s
        // (roadmap O-2h); what it means for THIS model — the stream, the scroll,
        // the expansions, a pending `/compact` and the breaker all reset with
        // the transcript, the breaker being the one reset not derived from a
        // compaction's own result — is {@see applyCommandResult()}'s.
        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\ClearCommand(), '/clear');
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
     * The title service this session titles itself through (O-2d): the one
     * registered on the workspace when this Chat runs in one, else a default —
     * so an embedder with no workspace titles exactly as before.
     */
    private function titleService(): \SugarCraft\Crush\Host\TitleService
    {
        $service = $this->workspace?->service(\SugarCraft\Crush\Host\TitleService::class);

        return $service instanceof \SugarCraft\Crush\Host\TitleService ? $service : \SugarCraft\Crush\Host\TitleService::new();
    }

    /**
     * The fire-and-forget Cmd that asks the tool-less {@see $titleBackend} to
     * name the session, or null when this turn shouldn't trigger one.
     *
     * The gating, the request and the conditional store write live in
     * {@see \SugarCraft\Crush\Host\TitleService::titleCall()} — once per session, on the first
     * agent-visible user turn, only with a store, a session, no name yet and a
     * title backend (audit 15b-12: never the main backend); the write is
     * {@see SessionStore::renameSessionIfUnnamed()}, so a `/rename` typed while
     * the request is in flight wins in the store (audit B2) and the
     * {@see SessionTitledMsg} arm keeps it in the UI. Every answer, usable or
     * not, still dispatches that Msg to carry the call's cost.
     *
     * NOT SEPARATELY GATED BY THE SPEND CAP: this is only ever scheduled from
     * {@see submit()}'s turn-dispatch tail, which sits AFTER
     * {@see spendCapRefusal()}. `/compact` is different and IS gated — see
     * {@see scheduleModelCompaction()} — because it is reachable by a user
     * typing it at a session that is already over.
     */
    private function scheduleTitleGeneration(self $next): ?\Closure
    {
        $call = $this->titleService()->titleCall(
            $next->titleBackend,
            $this->sessionStore,
            $next->currentSessionId,
            $next->currentSessionName,
            $next->history,
        );

        return $call === null ? null : Cmd::promise($call);
    }

    /**
     * {@see \SugarCraft\Crush\Host\TitleService::sanitizeTitle()}: model output reduced to one safe
     * line for the tab strip. Kept here because `/rename`, the session picker
     * and `/bg` names reuse the same rule.
     */
    private static function sanitizeSessionTitle(string $raw): string
    {
        return \SugarCraft\Crush\Host\TitleService::sanitizeTitle($raw);
    }

    /**
     * The grayed suggestion the empty input box shows, or null when there is
     * none to show: none has arrived, a turn is running, or the conversation
     * has moved since it was written (see the `$promptSuggestion` docblock).
     */
    public function promptSuggestion(): ?string
    {
        $s = $this->promptSuggestion;
        if (
            $s === null
            || $this->inFlight
            || $s['generation'] !== $this->generation
            || $s['historyCount'] !== count($this->history)
            || $s['sessionId'] !== $this->currentSessionId
        ) {
            return null;
        }

        return $s['text'];
    }

    /**
     * The fire-and-forget Cmd that asks the tool-less {@see $titleBackend} to
     * guess the user's next message, or null when this settle should not ask
     * — no title backend, suggestions off on the {@see \SugarCraft\Crush\Host\TitleService} or by
     * `SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS`, the spend cap reached, or no
     * assistant reply to follow up. See {@see \SugarCraft\Crush\Host\TitleService::suggestionCall()}.
     */
    private function schedulePromptSuggestion(): ?\Closure
    {
        $call = $this->titleService()->suggestionCall(
            $this->titleBackend,
            $this->history,
            $this->generation,
            $this->currentSessionId,
            $this->spendCapReached(),
        );

        return $call === null ? null : Cmd::promise($call);
    }

    /** {@see \SugarCraft\Crush\Host\TitleService::sanitizeSuggestion()}: the guess as one safe input-box line. */
    private static function sanitizePromptSuggestion(string $raw): string
    {
        return \SugarCraft\Crush\Host\TitleService::sanitizeSuggestion($raw);
    }

    /**
     * Build the Cmd that calls the backend with $next's history and
     * dispatches its outcome as an {@see AssistantMsg} stamped with
     * $generation - the common tail {@see submit()} and the tool-call
     * pipeline (see {@see beginToolCalls()}/{@see ToolResultsMsg}) both
     * schedule once their turn's history is settled.
     *
     * The turn itself runs in {@see \SugarCraft\Crush\Host\TurnRunner::start()}
     * (roadmap O-2f): the per-dispatch backend wiring, the four callbacks the
     * backend reports through, and the settle. What stays here is what is
     * this Chat's to decide — which of its fields the dispatch reads, whether
     * it owns the runtime-notice drain — and the `Cmd` wrapper, which is the
     * only part of the old body that ever needed a TUI.
     *
     * Also the point where the backend's `$onEvent` tool-lifecycle seam is
     * consumed (crush_feat.md §1 E1). The callback only QUEUES events on
     * {@see $liveToolEvents}: it runs inside the backend, where there is no
     * dispatcher and no way to mutate an immutable Chat, so the live pump
     * ({@see pumpLiveToolEvents()}) folds what it reaches and the rest rides
     * out on the resolved {@see BackendToolEventsMsg}, which
     * {@see applyBackendToolEvent()} turns into transcript states one event at
     * a time. A turn that called no tools resolves to a plain
     * {@see AssistantMsg} exactly as before.
     */
    private function scheduleBackendCompletion(self $next, CancellationToken $cancellation, int $generation): \Closure
    {
        $run = $this->turnRunner()->start(
            backend: \SugarCraft\Crush\Host\TurnRunner::backendForTurn(
                $next->backend,
                $this->maxCostUsd,
                $this->spentUsd(),
                $this->compactorConfig,
                $next->currentSessionId,
                \SugarCraft\Crush\Permissions\SessionPermissionMemo::fromGrants($next->permissionGrants),
            ),
            history: $next->history,
            inbox: $next->liveToolEvents,
            generation: $generation,
            cancellation: $cancellation,
            // `$next->streaming` gates incremental delivery of the ANSWER;
            // thinking is always delivered (it has no non-incremental form).
            streaming: $next->streaming,
            // An embedder's raw-chunk observer. A throwing one is detached for
            // the rest of the turn either way; whether that is REPORTED is
            // {@see DEBUG_STREAM_ENV}'s call (E175), read when it throws.
            tokenObserver: $next->onToken,
            reportObserverFailure: self::debugStreamRequested(...),
            // E199: opening the per-turn notice budget is the DRAIN OWNER'S
            // act, not any Chat's — a hosted or embedder Chat nobody appointed
            // must not re-open it underneath the owner's turn. The workspace's
            // sink is this session's inbox (W2-e); without a workspace, the
            // process's current one.
            notices: $this->drainsRuntimeNotices ? ($this->workspace?->notices ?? RuntimeNoticeSink::current()) : null,
            agentManager: $next->agentManager,
            // The durable story of the turn (Appendix O §6.5) goes to this
            // session's event log, rows named by the identity their save keeps.
            transcripts: $next->transcripts(),
            sessionId: $next->currentSessionId,
        );

        return Cmd::promise($run);
    }

    /**
     * Handle /workflow commands — {@see \SugarCraft\Crush\Host\Commands\WorkflowCommand}
     * (roadmap O-2h). What stays here is the one rule about THIS window: a
     * read-only window refuses `run` and `resume` before a run can set
     * `workflowTurnInFlight`, because a run appends to the transcript of a
     * session another TUI owns.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleWorkflowCommand(string $inputText): array
    {
        [$command] = \SugarCraft\Crush\Host\Commands\WorkflowCommand::subcommand($inputText);
        if ($this->workflowEngine !== null && $this->readOnlySession && \in_array($command, ['run', 'resume'], true)) {
            return $this->refuseReadOnly($inputText);
        }

        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\WorkflowCommand(), $inputText);
    }

    /**
     * `/workflow run <name> [key=val ...]` —
     * {@see \SugarCraft\Crush\Host\Commands\WorkflowCommand::start()}: the run
     * goes into a `\Fiber` stepped from a loop timer, so `update()` returns on
     * the tick Enter was pressed and the renderer sees the run's sub-agents
     * live between polls; it occupies the turn until its report lands.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function workflowRun(string $inputText, string $args): array
    {
        if ($this->workflowEngine === null) {
            return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\WorkflowCommand(), $inputText);
        }

        return $this->applyCommandResult(
            \SugarCraft\Crush\Host\Commands\WorkflowCommand::start($this->workflowEngine, $inputText, $args),
        );
    }

    /**
     * Render a finished run as the assistant's reply —
     * {@see \SugarCraft\Crush\Host\Commands\WorkflowCommand::describeWorkflowResult()}.
     */
    private static function describeWorkflowResult(string $workflowName, WorkflowResult $result, bool $resumed = false): string
    {
        return \SugarCraft\Crush\Host\Commands\WorkflowCommand::describeWorkflowResult($workflowName, $result, $resumed);
    }

    /**
     * Step a workflow fiber from the event loop until it terminates, then
     * deliver its report as the reply —
     * {@see \SugarCraft\Crush\Host\Commands\WorkflowCommand::drive()} steps it
     * (the loop is free between two resumes, which is when a frame paints the
     * live sub-agents), {@see settleCommandRun()} lands it: an AssistantMsg
     * that releases the turn, or a {@see CancelledWorkflowReportMsg} once Esc
     * Esc ($cancellation) already released it. Resolves, never rejects.
     */
    private function driveWorkflowFiber(\Fiber $fiber, ?CancellationToken $cancellation = null): \Closure
    {
        return self::settleCommandRun(
            static fn (): PromiseInterface => \SugarCraft\Crush\Host\Commands\WorkflowCommand::drive(
                $fiber,
                self::WORKFLOW_STEP_INTERVAL_SECONDS,
            ),
            $cancellation ?? new CancellationToken(),
        );
    }

    /**
     * Handle /share: export the session to a local file (roadmap X-35a) —
     * {@see \SugarCraft\Crush\Host\Commands\ShareHostCommand}. The reply is
     * {@see ShareCommand}'s own output, which names the written path.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleShareCommand(string $inputBuf): array
    {
        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\ShareHostCommand(), $inputBuf);
    }

    /**
     * Handle /websearch — {@see \SugarCraft\Crush\Host\Commands\WebSearchHostCommand},
     * the one command whose exchange the model reads.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleWebSearchCommand(string $inputBuf): array
    {
        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\WebSearchHostCommand(), $inputBuf);
    }

    /**
     * A slash command that exited non-zero, reported IN the transcript —
     * {@see \SugarCraft\Crush\Host\Commands\CommandResult::failure()}.
     *
     * USER-REPORTED CRASH. The callers each did
     * `return [$this, static fn() => print $output];`, and that is a fatal
     * rather than a diagnostic: `print` is an EXPRESSION whose value is
     * `int 1`, so the closure is a `Cmd` returning an int, and
     * {@see \SugarCraft\Core\Program::dispatch()} requires a `Msg` — the app
     * died on the first `/websearch` with no query. Writing to stdout was the
     * wrong shape even before the TypeError: the screen belongs to
     * candy-core's frame renderer. So the echo and the output both land, the
     * output as `Role::System` — an app-generated failure notice is not a
     * model reply and must not be replayed to the provider as one.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function commandFailureResponse(string $inputBuf, string $output, int $exitCode): array
    {
        return $this->applyCommandResult(\SugarCraft\Crush\Host\Commands\CommandResult::failure($inputBuf, $output, $exitCode));
    }

    /**
     * `/agents` (`/agent`) — {@see \SugarCraft\Crush\Host\Commands\AgentsHostCommand}.
     * A Chat built without an agent manager (an embedder; a launch always has
     * one) answers "not configured" rather than throwing out of `update()`.
     *
     * `/agent <id|name>` naming a run this launch knows — its run id, or an
     * agent name with exactly one run in {@see agentLive()} — opens that
     * run's Agent View (roadmap P-C2) instead of printing the preset. The
     * view is a TUI surface, so this half stays here rather than on the host.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleAgentsCommand(string $inputBuf): array
    {
        $runId = $this->liveAgentRunFor(\SugarCraft\Crush\Host\Commands\CommandText::words($inputBuf)[0] ?? '');
        if ($runId !== null) {
            // The echo row, as CommandResult's own echo builds it.
            [$chat] = $this->applyCommandResult(\SugarCraft\Crush\Host\Commands\CommandResult::new(Message::user($inputBuf)->withUiOnly()));

            return [$chat, static fn (): OpenAgentViewMsg => new OpenAgentViewMsg($runId)];
        }

        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\AgentsHostCommand(), $inputBuf);
    }

    /**
     * The run `/agent <arg>` names: a run id {@see agentLive()} holds, or the
     * one run of the agent named $arg — null when none, or when the name is
     * ambiguous (the preset inspection answers it instead).
     */
    private function liveAgentRunFor(string $arg): ?string
    {
        if ($arg === '') {
            return null;
        }
        $live = $this->agentLive();
        if ($live->get($arg) !== null) {
            return $arg;
        }
        $named = array_values(array_filter(
            $live->all(),
            static fn (\SugarCraft\Crush\Agents\Live\AgentLiveState $state): bool => $state->name === $arg,
        ));

        return \count($named) === 1 ? $named[0]->id : null;
    }

    /**
     * `/rules` — list the operator's rule packs, or toggle one for this
     * session only (prompt_plan.md P6.S3) —
     * {@see \SugarCraft\Crush\Host\Commands\RulesHostCommand}. The toggle
     * mutates the shared {@see RulesState} this model carries by identity, so
     * the transcript and the next prompt cannot disagree about a pack.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleRulesCommand(string $inputBuf): array
    {
        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\RulesHostCommand(), $inputBuf);
    }

    /**
     * `/pane dock <left|right> [name]` / `/pane toggle [name]` — the command
     * twins of the drag gesture: dock moves a pane into the named side's
     * dock column, appended at the end of it (a drag picks its slot by the row
     * it lands on; a command has no pointer to aim with). `/pane toggle
     * [name]` is its undock twin:
     * send a pane to its home side, or free it from whatever slot holds it —
     * the one keyboard route that releases a single docked pane without
     * `/layout reset` tearing the whole layout down (the gesture has no
     * undock half: releasing over the centre just cancels). With no name the
     * FOCUSED pane moves, and `App::applyDockCommand()` answers a
     * non-dockable focus in words.
     *
     * Usage failures are answered on the transcript rather than routed to
     * the model — the verb is load-bearing, so there is no superset reading
     * of a half-typed `/pane` to fall back to. The pane NAME is not
     * validated here: `Pane::tryFrom` on the shell's side is the single
     * source, and a name Chat accepted but App rejects still gets a status
     * line naming it.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handlePaneCommand(string $inputBuf): array
    {
        $tokens = self::commandTokens($inputBuf);
        $verb = strtolower($tokens[1] ?? '');

        if ($verb === 'toggle') {
            if (count($tokens) > 3) {
                return $this->gestureUsageResponse($inputBuf, 'usage: /pane toggle [pane name]');
            }

            $name = isset($tokens[2]) ? strtolower($tokens[2]) : null;

            $next = $this->mutate([
                'history' => [...$this->history, Message::user($inputBuf)->withUiOnly()],
                'inputBuf' => '',
                'inFlight' => false,
            ]);

            return [$next, Cmd::send(new App\DockPaneMsg('toggle', $name))];
        }

        $side = strtolower($tokens[2] ?? '');

        if (count($tokens) > 4 || $verb !== 'dock' || ($side !== 'left' && $side !== 'right')) {
            return $this->gestureUsageResponse($inputBuf, 'usage: /pane dock <left|right> [pane name] | /pane toggle [pane name]');
        }

        $name = isset($tokens[3]) ? strtolower($tokens[3]) : null;

        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputBuf)->withUiOnly()],
            'inputBuf' => '',
            'inFlight' => false,
        ]);

        return [$next, Cmd::send(new App\DockPaneMsg($side, $name))];
    }

    /**
     * `/layout reset` — restore the launch default dock (files-left at the
     * seeded shares), dropping every drag-resized column and every docked
     * pane except the default's. Same transcript discipline as
     * {@see handlePaneCommand()}: the word `reset` is the command, anything
     * else is usage, never a prompt to the model.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleLayoutCommand(string $inputBuf): array
    {
        $tokens = self::commandTokens($inputBuf);

        if (count($tokens) !== 2 || strtolower($tokens[1]) !== 'reset') {
            return $this->gestureUsageResponse($inputBuf, 'usage: /layout reset');
        }

        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputBuf)->withUiOnly()],
            'inputBuf' => '',
            'inFlight' => false,
        ]);

        return [$next, Cmd::send(new App\LayoutResetMsg())];
    }

    /**
     * Echo a mis-typed gesture command: user line plus a system usage hint,
     * draft cleared — the `commandFailureResponse` shape without the
     * exit-code machinery these handlers do not have.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function gestureUsageResponse(string $inputBuf, string $usage): array
    {
        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputBuf)->withUiOnly(), Message::notice($usage)],
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
        $reply = $this->spendLedger()->budgetReply(
            self::commandArgument($inputText),
            $this->tokenTracker,
            $this->maxCostUsd,
        );

        return $this->budgetResponse($inputText, $reply['response'], $reply['cap'], clearCap: $reply['clearCap']);
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
        return $this->spendLedger()->statusLine($this->tokenTracker, $cap ?? $this->maxCostUsd);
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
            'history' => [...$this->history, Message::user($inputText)->withUiOnly(), Message::assistant($response)->withUiOnly()],
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

        return $this->scheduleHookGatedCompaction($inputText)
            ?? $this->compactNow($inputText, $this->history, []);
    }

    /**
     * The heuristic `/compact` behind a wired PreCompact chain (roadmap 2.12),
     * or null when no PreCompact hook is wired — then the caller compacts
     * synchronously exactly as before.
     *
     * The chain never runs inside `update()`: like the model route, the command
     * answers at once with a notice, sets the summarization latch, and the
     * compaction happens when the {@see HistoryCompactedMsg} lands — carrying
     * the chain's refusal, if any, in `blockedBy`, and `$prefix` as its
     * `heuristicNotice` (the model was never asked, so the report must not say
     * the model failed).
     *
     * @return array{0:Chat,1:\Closure}|null
     */
    private function scheduleHookGatedCompaction(string $inputText, string $prefix = ''): ?array
    {
        $gate = $this->preCompactGate(
            \SugarCraft\Crush\Host\CompactionService::TRIGGER_MANUAL,
            \SugarCraft\Crush\Host\CompactionService::compactFocus($inputText),
            new CancellationToken(),
        );
        if ($gate === null) {
            return null;
        }

        $compactionId = bin2hex(random_bytes(8));
        $cmd = Cmd::promise(static fn (): PromiseInterface => $gate()->then(
            static fn (?\SugarCraft\Crush\Hooks\HookResult $verdict): ?HistoryCompactedMsg => $verdict === null
                ? null
                : new HistoryCompactedMsg(
                    $compactionId,
                    blockedBy: \SugarCraft\Crush\Host\CompactionService::preCompactRefusal($verdict),
                    heuristicNotice: $prefix,
                ),
        ));

        return [$this->mutate([
            'history' => [
                ...$this->history,
                Message::user($inputText)->withUiOnly(),
                Message::assistant($this->compactionService()->preCompactPendingNotice())->withUiOnly(),
            ],
            'inputBuf' => '',
            'inFlight' => false,
            'pendingCompactionId' => $compactionId,
        ]), $cmd];
    }

    /**
     * The `PreCompact` chain for one compaction (roadmap 2.12), as a closure
     * that runs it OFF `update()` and resolves its verdict — or null when no
     * PreCompact hook is wired, so an unhooked session's route is unchanged.
     *
     * The one dispatch site docs/HOOKS.md's events table names for the event.
     * An out-of-process chain (a {@see \SugarCraft\Crush\Hooks\ScriptHook})
     * runs in a forked child exactly as the turn hooks do (audit 15b-04,
     * {@see forkedPayloadCmd()}), so the frame keeps painting; an in-process
     * chain runs inline when the closure is called, which is already off the
     * update path. The closure resolves null when $cancellation fired — nothing
     * is dispatched then.
     *
     * @return (\Closure(): PromiseInterface<?\SugarCraft\Crush\Hooks\HookResult>)|null
     */
    private function preCompactGate(string $trigger, string $focus, CancellationToken $cancellation): ?\Closure
    {
        $event = \SugarCraft\Crush\Hooks\HookEvent::PreCompact;
        $hooks = $this->hooks;
        if ($hooks === null || !$hooks->hasHooksFor($event, $event->value)) {
            return null;
        }

        $context = $this->compactionService()->compactionHookContext(
            $event->value,
            ['trigger' => $trigger, 'custom_instructions' => $focus],
            $this->currentSessionId ?? '',
            $this->projectRoot(),
        );
        $fork = $this->turnHooksMustFork([$event]);

        return static fn (): PromiseInterface => self::compactionHookPromise(
            static fn (): \SugarCraft\Crush\Hooks\HookResult => $hooks->preCompact($context),
            $fork,
            $cancellation,
        );
    }

    /**
     * The `PostCompact` chain for a compaction that was just applied (roadmap
     * 2.12), as a fire-and-forget Cmd — or null when no PostCompact hook is
     * wired or $before → $after condensed nothing (no new
     * {@see \SugarCraft\Crush\Host\CompactionService::COMPACTION_BOUNDARY} row).
     *
     * Observe-only: the rewrite already happened, so the Cmd resolves null
     * whatever the chain says. It runs off `update()` on the same fork-or-inline
     * split as {@see preCompactGate()}. `compact_summary` is the newest summary
     * row the model now reads in place of the condensed rows.
     *
     * @param list<Message> $before
     * @param list<Message> $after
     */
    private function postCompactCmd(string $trigger, array $before, array $after): ?\Closure
    {
        $event = \SugarCraft\Crush\Hooks\HookEvent::PostCompact;
        $hooks = $this->hooks;
        if ($hooks === null || !$hooks->hasHooksFor($event, $event->value)) {
            return null;
        }

        $boundaries = static fn (array $rows): int => count(array_filter(
            $rows,
            static fn (Message $row): bool => \SugarCraft\Crush\Host\CompactionService::isCompactionBoundary($row),
        ));
        if ($boundaries($after) <= $boundaries($before)) {
            return null;
        }

        $context = $this->compactionService()->compactionHookContext(
            $event->value,
            ['trigger' => $trigger, 'compact_summary' => \SugarCraft\Crush\Host\CompactionService::latestCompactSummary($after)],
            $this->currentSessionId ?? '',
            $this->projectRoot(),
        );
        $fork = $this->turnHooksMustFork([$event]);

        return Cmd::promise(static fn (): PromiseInterface => self::compactionHookPromise(
            static fn (): \SugarCraft\Crush\Hooks\HookResult => $hooks->postCompact($context),
            $fork,
            new CancellationToken(),
        )->then(static fn (): ?Msg => null));
    }

    /**
     * Run one compaction hook chain and resolve its verdict, forked when
     * $fork (the chain leaves the process) and inline otherwise — the shape
     * {@see forkTurnHooksCmd()} gives the turn hooks, through the same
     * {@see forkedPayloadCmd()}, so no new fork site exists for it.
     *
     * FAILS CLOSED: a chain that throws, or a child that reports nothing, is a
     * DENY — for PreCompact that skips the compaction rather than letting an
     * unvetted rewrite through. Resolves null when $cancellation fired.
     *
     * @param \Closure(): \SugarCraft\Crush\Hooks\HookResult $run
     * @return PromiseInterface<?\SugarCraft\Crush\Hooks\HookResult>
     */
    private static function compactionHookPromise(\Closure $run, bool $fork, CancellationToken $cancellation): PromiseInterface
    {
        $guarded = static function () use ($run): \SugarCraft\Crush\Hooks\HookResult {
            try {
                return $run();
            } catch (\Throwable $e) {
                return \SugarCraft\Crush\Hooks\HookResult::deny('hook failed: ' . $e::class . ': ' . $e->getMessage());
            }
        };

        if (!$fork) {
            return \React\Promise\resolve($cancellation->isCancelled() ? null : $guarded());
        }

        // The verdict rides home on a TurnHooksResolvedMsg because that is the
        // Msg forkedPayloadCmd()'s collector contract returns; it is unwrapped
        // below and never reaches update().
        $async = self::forkedPayloadCmd(
            static fn (): string => json_encode(
                ['verdict' => self::turnHookResultToArray($guarded())],
                JSON_INVALID_UTF8_SUBSTITUTE,
            ) ?: '',
            static function (string $file): Msg {
                $data = self::takeIpcPayload($file);
                $decoded = ($data !== false && $data !== '') ? json_decode($data, true) : null;
                $verdict = (\is_array($decoded) ? self::turnHookResultFromArray($decoded['verdict'] ?? null) : null)
                    ?? \SugarCraft\Crush\Hooks\HookResult::deny('the compaction hooks ended without reporting a verdict');

                return new TurnHooksResolvedMsg(0, '', $verdict);
            },
            static fn (): Msg => new TurnHooksResolvedMsg(0, '', $guarded()),
            $cancellation,
        )();

        return $async->promise->then(
            static fn (?Msg $msg): ?\SugarCraft\Crush\Hooks\HookResult => $msg instanceof TurnHooksResolvedMsg ? $msg->prompt : null,
        );
    }

    /**
     * The workspace's {@see \SugarCraft\Crush\Host\CompactionService} (roadmap
     * O-2e): every compaction decision, layout and sentence below is its logic
     * over this session's state. Read through
     * {@see \SugarCraft\Crush\Host\WorkspaceContext::service()} so the
     * extraction adds no constructor state; a Chat with no workspace, or one that
     * registered none, gets a fresh service — it is stateless, so the two cannot
     * compact differently.
     */
    private function compactionService(): \SugarCraft\Crush\Host\CompactionService
    {
        $service = $this->workspace?->service(\SugarCraft\Crush\Host\CompactionService::class);

        return $service instanceof \SugarCraft\Crush\Host\CompactionService ? $service : \SugarCraft\Crush\Host\CompactionService::new();
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
        $next = $this->mutate([
            ...$this->compactionChanges($inputText, $baseHistory, $summaries, $prefix),
            // The draft became this command when Enter was pressed, and this
            // command starts no turn.
            'inputBuf' => '',
            'inFlight' => false,
        ]);

        return [$next, $this->postCompactCmd(\SugarCraft\Crush\Host\CompactionService::TRIGGER_MANUAL, $baseHistory, $next->history)];
    }

    /**
     * What compacting $baseHistory does to the TRANSCRIPT and to nothing else,
     * as a `mutate()` change set: the compacted history plus the answer line
     * ({@see \SugarCraft\Crush\Host\CompactionService::compactedHistory()}, which says what "compacted" means
     * and where the report sits), and the summarization latch released.
     *
     * One shared definition of what `/compact` did, reached directly by
     * {@see compactNow()} when there was no model to ask and by
     * {@see applyModelCompaction()} when there was.
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
     * transcript already carries it (the model route). $tierNotice switches the
     * report line to the automatic tier's {@see contextCompactedMessage()}.
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
        return [
            'history' => $this->compactionService()->compactedHistory(
                $this->compactor,
                $inputText,
                $baseHistory,
                $summaries,
                $this->contextTokenLimit(...),
                $this->estimateTokenCount(...),
                $prefix,
                $tierNotice,
            ),
            // Whatever summarization was outstanding has either just been
            // consumed or has just been superseded by this compaction; either
            // way nothing is pending now.
            'pendingCompactionId' => null,
        ];
    }

    /**
     * The instruction the summarization model is given —
     * {@see \SugarCraft\Crush\Host\CompactionService::COMPACT_SUMMARY_PROMPT}, named here too because the
     * summary suites read it off Chat.
     */
    private const COMPACT_SUMMARY_PROMPT = \SugarCraft\Crush\Host\CompactionService::COMPACT_SUMMARY_PROMPT;

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
            $capNotice = $this->compactionService()->compactCommandCapNotice($this->spentUsd(), (float) $this->maxCostUsd);

            return $this->scheduleHookGatedCompaction($inputText, $capNotice)
                ?? $this->compactNow($inputText, $this->history, [], $capNotice);
        }

        // The probe mirrors what is about to be appended - the `/compact` echo
        // and the notice below, both UI-only - so the offered set is derived from
        // the shape the landing compacts (see buildSummarizationRequest()). Both
        // are UI-only, so neither is an exchange: compactionWire() drops them and
        // the offered set is the one the CURRENT conversation earns.
        $echoed = [...$this->history, Message::user($inputText)->withUiOnly()];
        $request = $this->buildSummarizationRequest(
            [...$echoed, Message::assistant('')->withUiOnly()],
            null,
            null,
            \SugarCraft\Crush\Host\CompactionService::compactFocus($inputText),
        );
        if ($request === null) {
            return null;
        }

        $next = $this->mutate([
            'history' => [...$echoed, Message::assistant(
                $this->compactionService()->summarisingNotice($request['count']),
            )->withUiOnly()],
            'inputBuf' => '',
            'inFlight' => false,
            'pendingCompactionId' => $request['id'],
        ]);

        return [$next, $request['cmd']];
    }

    /**
     * The half of a model-written compaction that is the same on both routes:
     * decide whether there is anything to ask this session's
     * {@see $summaryBackend}, and build the request —
     * {@see \SugarCraft\Crush\Host\CompactionService::buildSummarizationRequest()}, whose docblock says why the
     * caller supplies the whole probe and what the cancellation token is for.
     * The request is wrapped in a Cmd here; it always lands as a
     * {@see HistoryCompactedMsg}.
     *
     * A wired PreCompact chain ({@see preCompactGate()}, roadmap 2.12) runs
     * FIRST inside the same Cmd — `manual` on the `/compact` route, `auto` on the
     * parked one — so a refusal lands as a {@see HistoryCompactedMsg} carrying
     * `blockedBy` before any summarization is paid for, and a permitting chain's
     * note steers the summary beside `/compact`'s $focus.
     *
     * @param list<Message> $probeHistory
     * @return array{id:string,count:int,cmd:\Closure}|null
     */
    private function buildSummarizationRequest(array $probeHistory, ?string $parkedSubmission, ?CancellationToken $cancellation = null, string $focus = ''): ?array
    {
        $request = $this->compactionService()->buildSummarizationRequest(
            $this->summaryBackend,
            $this->compactor,
            $probeHistory,
            $parkedSubmission,
            $cancellation,
            $focus,
        );
        if ($request === null) {
            return null;
        }

        $gate = $this->preCompactGate(
            $parkedSubmission === null
                ? \SugarCraft\Crush\Host\CompactionService::TRIGGER_MANUAL
                : \SugarCraft\Crush\Host\CompactionService::TRIGGER_AUTO,
            $focus,
            $cancellation ?? new CancellationToken(),
        );
        if ($gate === null) {
            return ['id' => $request['id'], 'count' => $request['count'], 'cmd' => Cmd::promise($request['promise'])];
        }

        $summarize = $request['promise'];
        $compactionId = $request['id'];

        return ['id' => $compactionId, 'count' => $request['count'], 'cmd' => Cmd::promise(
            static fn (): PromiseInterface => $gate()->then(
                static function (?\SugarCraft\Crush\Hooks\HookResult $verdict) use ($summarize, $compactionId, $parkedSubmission): mixed {
                    if ($verdict === null) {
                        return null;
                    }

                    $refusal = \SugarCraft\Crush\Host\CompactionService::preCompactRefusal($verdict);
                    if ($refusal !== null) {
                        return new HistoryCompactedMsg($compactionId, parkedSubmission: $parkedSubmission, blockedBy: $refusal);
                    }

                    return $summarize($verdict->additionalContext);
                },
            ),
        )];
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
     * A {@see CancellationToken} IS created here and armed on the summarization
     * call, so the double-Escape arm in {@see update()} signals cancellation of the
     * provider request as well as abandoning the turn — honouring the token is
     * BEST-EFFORT per the {@see Backend} contract, so a backend that polls it stops,
     * while one that ignores it is billed to its end — the one key that stays live
     * in this window and can abandon a parked turn is exactly the key that must stop
     * the spend on it
     * (backlog §E32). What is NOT done here is a `$generation` bump: generation
     * belongs to a backend TURN and there is no turn yet, so it stays the job of
     * {@see dispatchTurn()} when the compaction lands. Arming a token without a
     * generation bump is deliberate, and it holds because the two guards do
     * different jobs — generation makes a landing's TURN stale, the latch makes a
     * landing's SUMMARY stale, and the latch is released by the same arm that
     * cancels this token, so a cancelled round-trip can publish nothing either
     * way.
     *
     * $tokenCount is ESTIMATED tokens (script-weighted + 10 per message) of the
     * PRE-compaction history and $tokenLimit is the PROVIDER-COUNTED window, the
     * same two figures {@see submit()} read for the heuristic notice; the notice
     * below names the unit of each because they are not the same kind of number.
     *
     * THE SPEND CAP is checked here, and the check is DORMANT RATHER THAN DEAD —
     * said plainly rather than left to look covered, because {@see submit()} runs
     * {@see spendCapRefusal()} before this tier, so a capped session's ordinary
     * prompt is refused outright and never reaches the 85% block at all. It is
     * kept because the gate belongs to the provider call rather than to the
     * caller's ordering, and it is ASSERTED by driving this method directly past
     * that ordering — see {@see \SugarCraft\Crush\Tests\Chat\AutomaticCompactionModelSummaryTest}'s
     * `testTheParkedTierTellsTheUserWhenTheCapStoppedTheModelAsk`. What changed
     * (backlog §E31) is the ANSWER it gives: the tier used to fall through to the
     * heuristic in silence, which made the same blocked provider call TOLD on the
     * `/compact` route and invisible here, and which left a real ordering risk —
     * if {@see spendCapRefusal()} ever moves, a capped session would take the
     * lossier path with no word. It now hands its caller the notice the sibling
     * route composes from the same helper, so the facts agree; the tail differs
     * because `/compact` can honestly tell the user to run the command again after
     * raising the cap, and this route is about to send their prompt on the
     * heuristic regardless, so that advice would advertise a second way to arrive
     * where the user already is.
     *
     * The cap that CAN fire on this route from a live caller fires at the other
     * end of it, in {@see applyModelCompaction()}: the summarization is billed, so
     * it can be the call that crosses the cap, and the parked turn must not be
     * dispatched once it has.
     *
     * Returning null on that path, with the notice out through `$capNotice`, is
     * the shape this method keeps. The alternatives were measured against what
     * they cost rather than picked for tidiness: returning a non-null
     * `[$next, $cmd]` pair would have to be a COMPLETED parked turn, and the only
     * way to complete one without a round-trip is {@see compactNow()} — which sets
     * `inFlight` false and starts NO backend turn, so the caller's `return
     * $parked` would silently drop the prompt the user pressed Enter for and skip
     * the 95% re-check and the oversized-exchange rescue that
     * {@see applyModelCompaction()} runs before dispatching. That is the shape
     * backlog §E31 suggests, and it is wrong for this route precisely because the
     * route OWES a turn; `/compact` does not, which is why the sibling is free to
     * answer with a finished compaction. Handing the notice to the caller's own
     * continuation instead keeps every one of those behaviours and costs one
     * by-reference out parameter — the same two-notice slot problem
     * {@see submit()} already solves for the compaction and reminder notices.
     *
     * @param string $inputText The submitted prompt, echoed now and dispatched
     *                          when the {@see HistoryCompactedMsg} lands.
     * @param int $tokenCount ESTIMATED tokens in the pre-compaction history.
     * @param int $tokenLimit PROVIDER-COUNTED window from {@see contextTokenLimit()}.
     * @param ?string &$capNotice Set to the shared spend-cap sentence when the cap
     *                          is what stopped the model ask, left untouched
     *                          otherwise. Out-parameter rather than return value
     *                          for the reason the paragraph above gives: null must
     *                          keep meaning "no model route, take the heuristic",
     *                          which is exactly what the capped caller must still
     *                          do — now with something to say about it.
     * @return array{0:Chat,1:?\Closure}|null
     */
    private function scheduleParkedCompaction(string $inputText, int $tokenCount, int $tokenLimit, ?string &$capNotice = null, bool $resolveMentions = true): ?array
    {
        // OFFLINE BEATS CAPPED, in that order and for the reason
        // {@see scheduleModelCompaction()} states: with no provider at all there
        // is nothing the cap can have prevented, so an offline session must get
        // the plain heuristic path rather than be told a model ask was withheld.
        if ($this->summaryBackend === null) {
            return null;
        }

        if ($this->spendCapReached()) {
            // Dormant from submit() today - see the docblock. The turn is not
            // refused here: only the model was withheld, and the heuristic rewrite
            // the caller goes on to do is still owed the prompt.
            $capNotice = $this->compactionService()->parkedTurnCapNotice($this->spentUsd(), (float) $this->maxCostUsd);

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
        //
        // The token is armed on the SUMMARIZATION call itself (§E32): before this,
        // the double-Escape below abandoned the parked turn and released the latch
        // while the provider request went on running to its end and being billed for
        // the whole transcript, because this route created no token at all and the
        // seam below took none. It is stored in `inFlightCancellation` rather than a
        // second field of its own: that property is read by exactly one place — the
        // `?->cancel()` in {@see update()}'s Escape arm, which is the only thing that
        // could want it — and {@see dispatchTurn()} overwrites it with the turn's own
        // token the moment the compaction lands, which is the same hand-off the field
        // already models for `inFlight`.
        $cancellation = new CancellationToken();
        $request = $this->buildSummarizationRequest(
            [...$this->history, Message::notice(''), Message::user($inputText)],
            $inputText,
            $cancellation,
        );
        if ($request === null) {
            return null;
        }

        // THE USERPROMPTSUBMIT GATE FIRES HERE on this route (audit 15b-01), and only
        // once the request above has proved the prompt WILL be parked. Parking is
        // the submission: the draft is consumed, the prompt is echoed and `inFlight`
        // is held, and {@see applyModelCompaction()} sends it with no further say
        // from the user. Before this, {@see submit()} returned the parked pair ahead
        // of its own {@see dispatchTurnHooks()} call and the landing dispatched with
        // no hook ever run — a secret-blocking hook that held below the tier let the
        // very same prompt reach the model above it, and its note was lost.
        //
        // WHY HERE AND NOT EARLIER IN submit(): every null return above hands the
        // prompt back to submit()'s heuristic route, which runs the hook itself at
        // its tail — firing here first would run it twice for one submission, and
        // firing before the tier at all would run it for prompts the thrash breaker
        // and the synchronous 95% refusal turn away unsubmitted, which the gate's
        // own contract (see submit()'s TURN-LIFECYCLE comment) rules out.
        // WHY NOT AT THE LANDING: a blocked prompt would already have been echoed,
        // parked and paid a summarization for. The one residue of firing at park
        // time is that the landing's own refusals (spend cap crossed by the
        // summary, still over 95%) can turn away a prompt the hook already saw;
        // those are refusals of a prompt the user DID submit, so the hook having
        // judged it is the accurate reading, not a phantom fire.
        //
        // A refusal is returned as-is: built from `$this`, so nothing is parked, no
        // summarization Cmd leaves, the draft stays in the box and the last row is
        // the `Hook denied:` notice — exactly what the unparked route returns.
        [$hookNotes, $hookRefusal] = $this->dispatchTurnHooks($inputText);
        if ($hookRefusal !== null) {
            return $hookRefusal;
        }

        // THE NOTES ARE WRITTEN NOW, immediately ahead of the echoed prompt — the
        // slot the unparked route gives them ("notes go immediately ahead of the
        // user's line") — rather than carried on the HistoryCompactedMsg and spliced
        // in at the landing. The echo is already the parked route's one committed
        // copy of the turn, so a note written beside it reaches the dispatched turn
        // through the same history the landing sends, and it needs no new state:
        // nothing rides the message, so a double-Escape cancel or a superseded
        // compaction id leaves the note in the transcript beside its own prompt —
        // where the unparked route's cancel leaves it too — and can never resurface
        // on a later, unrelated turn. The request is rebuilt over the probe that now
        // includes them because the offered exchange set is derived from the exact
        // shape compacted at the landing (see the probe comment above); it is pure,
        // so discarding the first build costs nothing but the derivation. `??` keeps
        // the first build if a rebuild ever found nothing, which only costs the
        // newest condensed exchange its model summary, never the turn.
        if ($hookNotes !== []) {
            $request = $this->buildSummarizationRequest(
                [...$this->history, Message::notice(''), ...$hookNotes, Message::user($inputText)],
                $inputText,
                $cancellation,
            ) ?? $request;
        }

        // Audit 15b-15: the committed prompt carries its `@file` attachments
        // here exactly as on the unparked route (see userTurnMessage()).
        [$parkedUserTurn, $parkedAttachmentNotes] = $this->userTurnMessage($inputText, $resolveMentions);

        $next = $this->mutate([
            // Kept short on purpose — see CompactionService::parkNotice().
            'history' => [
                ...$this->history,
                Message::notice($this->compactionService()->parkNotice($tokenCount, $tokenLimit, $request['count'])),
                ...$hookNotes,
                ...$parkedAttachmentNotes,
                $parkedUserTurn,
            ],
            'inputBuf' => '',
            'inFlight' => true,
            'inFlightCancellation' => $cancellation,
            'pendingCompactionId' => $request['id'],
            'lastActivityAt' => new \DateTimeImmutable(),
            // Belt-and-braces, same rule dispatchTurn() follows: whatever is
            // about to be sent starts from a blank partial and a blank thought.
            'streamingText' => '',
            'reasoningText' => '',
            'expanded' => $this->expandedAfterLiveThought(null),
        ]);

        return [$next, $request['cmd']];
    }

    /**
     * The user-role half of the summarization request —
     * {@see \SugarCraft\Crush\Host\CompactionService::renderExchangesForSummary()}.
     *
     * @param list<array{key:string,user:string,assistant:string}> $exchanges
     */
    private static function renderExchangesForSummary(array $exchanges): string
    {
        return \SugarCraft\Crush\Host\CompactionService::renderExchangesForSummary($exchanges);
    }

    /**
     * The prior summaries to carry into a re-compaction (crush_code.md Phase 8,
     * the recursive merge) — {@see \SugarCraft\Crush\Host\CompactionService::priorSummariesFromHistory()}, which
     * carries the fence ruling P8.S3-R1 and the forgery-surface account.
     *
     * @param array<array-key, array{role?: string, content?: string}> $wireHistory
     *
     * @return list<string>
     */
    private static function priorSummariesFromHistory(array $wireHistory): array
    {
        return \SugarCraft\Crush\Host\CompactionService::priorSummariesFromHistory($wireHistory);
    }

    /**
     * The third message of a re-compaction request: the carried prior summaries
     * fenced in `<prior-summary>` plus the merge rules —
     * {@see \SugarCraft\Crush\Host\CompactionService::renderPriorSummariesForSummary()}.
     *
     * @param non-empty-list<string> $priors
     */
    private static function renderPriorSummariesForSummary(array $priors): string
    {
        return \SugarCraft\Crush\Host\CompactionService::renderPriorSummariesForSummary($priors);
    }

    /**
     * The record format {@see COMPACT_SUMMARY_PROMPT} asks for, named here too
     * because the summary suites read it off Chat:
     * {@see \SugarCraft\Crush\Host\CompactionService::SUMMARY_FACETS}, {@see \SugarCraft\Crush\Host\CompactionService::SUMMARY_FACET_PATTERN}
     * and {@see \SugarCraft\Crush\Host\CompactionService::SUMMARY_FACET_NONE}.
     */
    private const SUMMARY_FACETS = \SugarCraft\Crush\Host\CompactionService::SUMMARY_FACETS;

    private const SUMMARY_FACET_PATTERN = \SugarCraft\Crush\Host\CompactionService::SUMMARY_FACET_PATTERN;

    private const SUMMARY_FACET_NONE = \SugarCraft\Crush\Host\CompactionService::SUMMARY_FACET_NONE;

    /**
     * Turn the model's numbered records into the key => summary map
     * {@see Context\ContextCompactor::withExchangeSummaries()} wants —
     * {@see \SugarCraft\Crush\Host\CompactionService::parseExchangeSummaries()}.
     *
     * @param list<string> $keys
     * @return array<string, string>
     */
    private static function parseExchangeSummaries(string $reply, array $keys): array
    {
        return \SugarCraft\Crush\Host\CompactionService::parseExchangeSummaries($reply, $keys);
    }

    /**
     * Longest summary line kept, in characters —
     * {@see \SugarCraft\Crush\Host\CompactionService::SUMMARY_LINE_MAX_CHARS}.
     */
    private const SUMMARY_LINE_MAX_CHARS = \SugarCraft\Crush\Host\CompactionService::SUMMARY_LINE_MAX_CHARS;

    /**
     * One bounded, control-byte-free line —
     * {@see \SugarCraft\Crush\Host\CompactionService::sanitizeSummaryLine()}.
     */
    private static function sanitizeSummaryLine(string $text): string
    {
        return \SugarCraft\Crush\Host\CompactionService::sanitizeSummaryLine($text);
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
        [$next, $cmd] = $this->landModelCompaction($msg);

        // PostCompact (roadmap 2.12) fires for a compaction that was APPLIED —
        // never for one a PreCompact hook blocked — and rides beside whatever
        // the landing itself returned (on the parked route, the turn).
        $post = $msg->blockedBy === null
            ? $this->postCompactCmd(
                $msg->parkedSubmission === null
                    ? \SugarCraft\Crush\Host\CompactionService::TRIGGER_MANUAL
                    : \SugarCraft\Crush\Host\CompactionService::TRIGGER_AUTO,
                $this->history,
                $next->history,
            )
            : null;

        return [$next, $post === null ? $cmd : ($cmd === null ? $post : Cmd::batch($cmd, $post))];
    }

    /**
     * The landing {@see applyModelCompaction()} wraps — see that method's
     * account above for what is condensed and when the parked turn is sent.
     *
     * A PreCompact refusal (`$msg->blockedBy`, roadmap 2.12) condenses
     * nothing: the reason is reported as a UI-only line and, on the parked
     * route, the turn continues against the uncompacted history through every
     * check below exactly as a compaction that freed nothing would.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function landModelCompaction(HistoryCompactedMsg $msg): array
    {
        $prefix = $this->compactionService()->modelSummaryFallbackPrefix($msg);
        $blocked = $msg->blockedBy === null ? [] : [
            'history' => [
                ...$this->history,
                Message::notice($this->compactionService()->compactionBlockedNotice(
                    $msg->blockedBy,
                    $msg->parkedSubmission !== null,
                )),
            ],
            'pendingCompactionId' => null,
        ];

        // The '/compact' line - or, on the parked route, the user's prompt - is
        // already in the transcript from the scheduling pass, so this must not
        // append a second one.
        if ($msg->parkedSubmission === null) {
            return [$this->mutate($blocked !== [] ? $blocked : $this->compactionChanges('', $this->history, $msg->summaries, $prefix)), null];
        }

        $compacted = $this->mutate($blocked !== [] ? $blocked : $this->compactionChanges('', $this->history, $msg->summaries, $prefix, true));

        // Hoisted above every judgement below because the breaker's measurement and
        // the 95% tier must read the SAME post-compaction state, or the two would
        // disagree about whether this rewrite was worth anything. Pure functions of
        // $compacted, so moving them changes nothing about the ordering of the checks
        // that consume them. Where the counter itself is written is the next block.
        $tokenLimit = $this->contextTokenLimit();
        $compactedWire = self::compactionWire($compacted->history);

        // THE BREAKER'S MEASUREMENT on this route — the same pair the tier used to
        // decide to park in the first place, applied to what the model's summaries
        // actually produced. This is the count §4.23's changelog describes: the
        // context refilled to the limit immediately after compacting, three times in
        // a row — and three is the point at which the next one is refused instead of
        // paid for. A `/compact` landing never reaches this line (it returns above),
        // so the number stays a record of what the AUTOMATIC tier achieved.
        //
        // Measured here and APPLIED at the exits, because the transition depends on
        // what the attempt then decides to do with the rewrite: both refusals below
        // settle the run with `turnSent: false`, the ordinary dispatch with
        // `turnSent: true` (which BREAKS under tier and HOLDS over it), and the
        // rescued dispatch takes none of this method at all — it returns
        // `$compacted` as it stands, since a tier that got the prompt out is not the
        // futility the run counts (ruling P8.S5-R6, argued once on
        // {@see withCompactionOutcome()}).
        // `$compacted` is therefore deliberately never reassigned: it is the
        // un-counted state the exemption needs.
        $refilled = $compacted->compactor->shouldCompact($compactedWire, $tokenLimit);

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
            return self::releaseQueuedPrompts($compacted->withCompactionOutcome($refilled, turnSent: false)
                ->spendCapTurnRefusal(
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
        // pre-echo equivalent. Both figures are the hoisted pair above.
        if ($compacted->compactor->shouldCompactForeground($compactedWire, $tokenLimit)) {
            // The same intra-exchange rescue submit()'s synchronous tier runs
            // (prompt_plan.md P4.S4, backlog §12.2 E18), and this route NEEDS it:
            // the model summarisation that just landed condensed the OLDER
            // exchanges and preserved the recent ten verbatim, so an oversized
            // newest exchange survives it untouched. Measured on this branch
            // before the rescue was wired here: 12 trivial pairs plus one
            // 800,000-char exchange, three parked attempts, estimate
            // 200,287 -> 200,518 -> 200,771, every one refused, the summariser
            // called three times, and the conversation backend never reached -
            // each refusal leaving its notice in history for the next attempt to
            // count. Null here means the overflow is only aggregate, and the
            // between-exchanges refusal below stands exactly as before.
            $rescued = $compacted->intraExchangeTruncation($compactedWire, $compacted->history, $tokenLimit);
            if ($rescued !== null) {
                // No new user message: the echo went in at park time. The
                // truncation notice rides last, Role::System like every other
                // post-prompt message on this route.
                //
                // Dispatched from `$compacted` — the state the measurement was taken
                // ON but not written INTO: this attempt got its turn out by
                // truncating the exchange that overflowed, so it is not the futile
                // refill the run counts (ruling P8.S5-R6).
                //
                // The checkpoint's pre-turn state keeps the truncation notice for
                // the reason the compaction report stays (see dispatchTurn()): it
                // describes a rewrite of the history, not the prompt.
                return $compacted->dispatchTurn(
                    $rescued['history'],
                    [$rescued['notice']],
                    $tokenLimit,
                    [...self::withoutParkedSubmission($rescued['history'], $msg->parkedSubmission), $rescued['notice']],
                    $msg->parkedSubmission,
                    null,
                );
            }

            // '' rather than the prompt: the echo is already in history, and the
            // refusal must not put a second copy of it there. $compactionNotice
            // is left null for the same reason - the rewrite this refusal has to
            // report is ALREADY reported, by the contextCompactedMessage() line
            // compactionChanges() wrote into $compacted->history above.
            // `$compacted->withCompactionOutcome(..., turnSent: false)`: the rewrite
            // landed over the tier and the prompt is going out NEITHER way — the
            // exact no-path-forward case the run extends for.
            return self::releaseQueuedPrompts(
                $compacted->withCompactionOutcome($refilled, turnSent: false)
                    ->foregroundBlockedResponse(
                        '',
                        $compacted->history,
                        $compacted->estimateTokenCount($compacted->history),
                        $tokenLimit,
                    )
            );
        }

        // No new user message: the echo went in at park time. Everything else a
        // turn needs - generation, cancellation token, checkpoint, titler - is
        // dispatchTurn()'s, which is the same code submit() runs.
        //
        // The run BREAKS when the rewrite got the context under its tier and HOLDS
        // when it did not: the prompt goes out either way, so this exit never
        // extends (ruling P8.S5-R6, and submit()'s unrescued dispatch above takes
        // the same pair of answers).
        //
        // THE CHECKPOINT'S DRAFT IS THE PARKED PROMPT, not the box: parking
        // consumed the draft one update() ago, so `$compacted->inputBuf` is
        // whatever the user has typed while the summarization was out — before
        // audit SES-1 that scratch text is what `/rewind` re-seeded, beside a
        // transcript that still held the prompt. No caret: the one the user
        // submitted from went with the consumed draft, so the restore lands at
        // end-of-text.
        return $compacted->withCompactionOutcome($refilled, turnSent: true)
            ->dispatchTurn(
                $compacted->history,
                [],
                $tokenLimit,
                self::withoutParkedSubmission($compacted->history, $msg->parkedSubmission),
                $msg->parkedSubmission,
                null,
            );
    }

    /**
     * $history with the parked submission's own rows removed, for the pre-turn
     * checkpoint (audit SES-1) — {@see \SugarCraft\Crush\Host\CompactionService::withoutParkedSubmission()}.
     *
     * @param list<Message> $history
     * @return list<Message>
     */
    private static function withoutParkedSubmission(array $history, string $prompt): array
    {
        return \SugarCraft\Crush\Host\CompactionService::withoutParkedSubmission($history, $prompt);
    }

    /**
     * Handle /sessions — OPEN the live {@see SessionPicker} overlay
     * (crush_feat.md section 5 E8), filtered when an argument is given:
     * `/sessions auth` opens it with `auth` already in the `/` filter
     * (Appendix P §3.2).
     *
     * Until E8 this folded the picker's first frame into an assistant turn,
     * so the widget's keyboard surface was rendered but unreachable. It now
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

        $query = self::commandArgument($inputText);
        $picker = $this->buildSessionPicker($query);
        if ($picker === null) {
            return $this->sessionResponse($inputText, 'No sessions recorded yet.');
        }

        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputText)->withUiOnly(), Message::assistant(
                'Session picker open — ↑/↓ or wheel browse, click selects, ↵ resume, / filter, '
                . 'r rename, d delete, p pin, f fork, esc close.',
            )->withUiOnly()],
            'inputBuf' => '',
            'inFlight' => false,
            'sessionPicker' => $picker,
        ]);

        return [$next, null];
    }

    /**
     * Build a {@see SessionPicker} over the store's sessions, or null when
     * there is no store or no session to pick.
     *
     * Null (rather than an empty picker) is what keeps Ctrl+R from opening
     * a modal the user cannot do anything with; every call site treats it as
     * "don't open".
     *
     * The work tree's git branch is read HERE, once, for Ctrl+B to filter by
     * ({@see SessionStore::gitBranchAt()} reads `HEAD` and runs nothing): the
     * picker used to `exec` git on every Ctrl+B, a blocking child process on
     * the key path.
     *
     * A $query opens the filter with it and loads
     * {@see SessionPicker::SEARCH_LIMIT} rows to rank over, the same load the
     * `/` key triggers.
     */
    private function buildSessionPicker(string $query = ''): ?SessionPicker
    {
        if ($this->sessionStore === null) {
            return null;
        }

        $limit = $query === '' ? SessionPicker::PAGE_SIZE : SessionPicker::SEARCH_LIMIT;
        [$rows, $more] = $this->sessionPickerRows($limit, false);
        if ($rows === []) {
            return null;
        }

        $picker = SessionPicker::new(
            $rows,
            $limit,
            $more,
            SessionStore::gitBranchAt($this->projectRoot()),
            $this->currentSessionId,
        );

        return $query === '' ? $picker : $picker->withQuery($query);
    }

    /**
     * Read one picker page from the store: the user's own sessions (main,
     * branch and background, pinned first, newest first, archived ones only
     * when $archived), the sub-agent sessions under them, and how many
     * children each has — three queries, run when the picker opens or an
     * action changes the store, never per keystroke.
     *
     * The second element says whether the page FILLED its limit, the only
     * thing that arms the load-more edge (E744 WS5): the page is grown by
     * widening the limit, not by an offset walk.
     *
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function sessionPickerRows(int $limit, bool $archived): array
    {
        $store = $this->sessionStore;
        if ($store === null) {
            return [[], false];
        }

        $kinds = \SugarCraft\Crush\Session\SessionKind::class;
        $query = \SugarCraft\Crush\Session\SessionQuery::new();
        $top = $store->listSessionsFiltered(
            $query->withKinds($kinds::Main, $kinds::Branch, $kinds::Background)
                ->withIncludeArchived($archived)
                ->withPinnedFirst()
                ->withLimit($limit),
        );
        if ($top === []) {
            return [[], false];
        }

        $ids = array_map(static fn(\SugarCraft\Crush\Session\SessionRow $row): string => $row->id, $top);
        $parents = array_flip($ids);
        $children = $store->childCount($ids);
        // Newest sub-agent rows first; 500 bounds the read on a store that
        // has run a great many Task calls. Only children of a loaded row are
        // kept — the rest have nothing to be shown under.
        $subagents = array_values(array_filter(
            $store->listSessionsFiltered($query->withKinds($kinds::Subagent)->withIncludeArchived()->withLimit(500)),
            static fn(\SugarCraft\Crush\Session\SessionRow $row): bool => $row->parentId !== null && isset($parents[$row->parentId]),
        ));
        $subagentCount = [];
        foreach ($subagents as $row) {
            $subagentCount[$row->parentId] = ($subagentCount[$row->parentId] ?? 0) + 1;
        }

        $live = $this->inFlight ? $this->currentSessionId : null;

        return [self::sanitizeSessionRows([...$top, ...$subagents], $children, $subagentCount, $live), count($top) >= $limit];
    }

    /**
     * Consume the picker's load-more edge (E744 WS5) — the one crush consumer
     * of the r88 ItemList `LoadMoreMsg`.
     *
     * The page is grown by WIDENING the top-N fetch (`limit + PAGE_SIZE`),
     * not by a cursor walk. The order is deterministic (pinned, then
     * `updated_at DESC, rowid DESC`), so a widened prefix is stable for the
     * lifetime of one opened picker; a session SAVED mid-browse can shift the
     * tail of the page, which a re-open corrects. A fetch that comes back
     * SHORT closes the edge, so the last row stops asking. A search has
     * already loaded its rows and pages nothing
     * ({@see SessionPicker::needsStoreFetch()}).
     *
     * The relayed Cmd that carried the Msg here has already been spent — this
     * method returns no Cmd of its own; growth is pure state.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleSessionLoadMore(): array
    {
        $picker = $this->sessionPicker;
        if ($picker === null || !$picker->needsStoreFetch() || $this->sessionStore === null) {
            return [$this, null];
        }

        $limit = $picker->nextFetchLimit();
        [$rows, $more] = $this->sessionPickerRows($limit, $picker->showsArchived());

        return [$this->mutate(['sessionPicker' => $picker->withFetchedRows($rows, $limit, $more)]), null];
    }

    /**
     * Re-read the store into $picker after an action changed it, at the
     * page size it already shows, keeping the highlight on $keepId (or on
     * the row it was on) when that row is still listed.
     */
    private function reloadSessionPicker(SessionPicker $picker, ?string $keepId = null): SessionPicker
    {
        $limit = max($picker->fetchLimit(), $picker->query() !== '' || $picker->isFiltering() ? SessionPicker::SEARCH_LIMIT : 0);
        [$rows, $more] = $this->sessionPickerRows($limit, $picker->showsArchived());

        return $picker->withReloadedRows($rows, $limit, $more, $keepId);
    }

    /**
     * Map typed store rows onto the picker's sanitized row shape.
     *
     * `summary` is the session's last prompt (`last_preview`), not its
     * system prompt: every session shares nearly the same system prompt, so
     * the row and footer used to read identically for all of them (audit B3).
     * `gitBranch` is the branch the session was opened on (audit B1). An
     * unnamed session shows as `(untitled <id8>…)`; `title` keeps the real
     * name (null when there is none), which is what an inline rename starts
     * from.
     *
     * @param list<\SugarCraft\Crush\Session\SessionRow> $rows
     * @param array<string, int>                          $children  direct children per id, every kind
     * @param array<string, int>                          $subagents sub-agent children per id
     * @param ?string                                     $liveId    the session a turn is running in
     *
     * @return list<array<string, mixed>>
     */
    private static function sanitizeSessionRows(array $rows, array $children = [], array $subagents = [], ?string $liveId = null): array
    {
        $field = static fn(?string $text): ?string => ($clean = self::sanitizeSessionField((string) $text)) !== '' ? $clean : null;

        return array_map(
            static function (\SugarCraft\Crush\Session\SessionRow $row) use ($field, $children, $subagents, $liveId): array {
                $name = $field($row->name);

                return [
                    'sessionId' => $row->id,
                    'sessionName' => $name ?? '(untitled ' . substr(self::sanitizeSessionField($row->id), 0, 8) . '…)',
                    'title' => $name,
                    'summary' => $field($row->lastPreview) ?? '',
                    'gitBranch' => $field($row->gitBranch),
                    'lastActivity' => $row->updatedAt,
                    'cwd' => $field($row->cwd),
                    'pinned' => $row->pinned,
                    'archived' => $row->archived(),
                    'kind' => $row->kind->value,
                    'turns' => $row->turns,
                    'provider' => $field($row->provider) ?? '',
                    'model' => $field($row->model) ?? '',
                    'status' => $field($row->status),
                    'parentId' => $row->parentId,
                    'agent' => $field($row->agent),
                    'children' => $children[$row->id] ?? 0,
                    'subagents' => $subagents[$row->id] ?? 0,
                    'live' => $liveId !== null && $row->id === $liveId,
                ];
            },
            $rows,
        );
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
     * section 5 E8, Appendix P §3.2) and act on what it reports:
     *
     * - `browse` / `edit` — keep the navigated or edited picker.
     * - `resume` — switch to the highlighted session and close the overlay.
     * - `preview` — load the highlighted session's last messages into the
     *   footer ({@see previewSessionInPicker()}).
     * - `close` — Escape.
     * - a {@see SessionListAction} — a store change or session switch, run by
     *   {@see runSessionListAction()}.
     * - null — a key the picker does not bind is swallowed rather than
     *   falling through to `inputBuf`, so a stray letter cannot type into a
     *   chat box the user cannot see.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleSessionPickerKey(KeyMsg $msg): array
    {
        $picker = $this->sessionPicker;
        if ($picker === null) {
            return [$this, null];
        }

        [$next, $action, $cmd] = $picker->handleKey($msg);
        // E744 WS5: a navigation that ARRIVED on the last loaded row while the
        // store signalled more pages raises the widget's load-more Cmd. It
        // rides the same WS1 relay as the draft editor's clipboard writes —
        // Cmd::send frames are not RawMsg, so the relay passes it through
        // untouched and the Program re-dispatches LoadMoreMsg into update().
        $relay = $cmd === null ? null : $this->relayWidgetCmd($cmd);

        if ($action instanceof \SugarCraft\Crush\Tui\SessionListAction) {
            return $this->runSessionListAction($next, $action);
        }

        return match ($action) {
            'browse', 'edit' => [$this->mutate(['sessionPicker' => $next]), $relay],
            // Ctrl+R opens the picker mid-turn and ↑/↓/space browse it, but
            // resuming adopts another session's history and id wholesale — the
            // running turn's transcript replaced under it — so mid-turn that one
            // action is refused with a notice naming it. Same rule as the
            // palette's; see {@see runSelectedPaletteActionWhileInFlight()}.
            'resume' => $this->inFlight
                ? $this->refuseInFlightAction('Resume session')
                : $this->resumeSelectedSession($next),
            'preview' => [$this->mutate(['sessionPicker' => $this->previewSessionInPicker($next)]), null],
            'close' => [$this->mutate(['sessionPicker' => null]), null],
            default => [$this, null],
        };
    }

    /**
     * Carry out a row action the picker reported (Appendix P §3.2). Each
     * writes the store, then re-reads it into the picker, so what the list
     * shows is always what is stored.
     *
     * Refusals the picker cannot know about are made here and shown in its
     * footer: fork switches sessions, so it waits out a running turn, and
     * delete re-checks the session on screen in case the picker is stale.
     * Delete removes the row's sub-agent children with it and DETACHES its
     * branch and background children, which are conversations of their own;
     * `D` ({@see SessionListAction::DeleteWithChildren}) takes those too.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function runSessionListAction(SessionPicker $picker, \SugarCraft\Crush\Tui\SessionListAction $action): array
    {
        $store = $this->sessionStore;
        if ($store === null) {
            return [$this->mutate(['sessionPicker' => $picker]), null];
        }

        $Action = \SugarCraft\Crush\Tui\SessionListAction::class;
        $selected = $picker->selectedSession();
        $id = $selected['sessionId'] ?? null;
        $keep = fn(SessionPicker $next): array => [$this->mutate(['sessionPicker' => $next]), null];

        try {
            switch ($action) {
                case $Action::Filter:
                    // One read when the filter opens; the ranking then runs over
                    // these rows, so typing a query never touches the store.
                    if ($picker->fetchLimit() >= SessionPicker::SEARCH_LIMIT) {
                        return $keep($picker);
                    }
                    [$rows, $more] = $this->sessionPickerRows(SessionPicker::SEARCH_LIMIT, $picker->showsArchived());

                    return $keep($picker->withFetchedRows($rows, SessionPicker::SEARCH_LIMIT, $more));

                case $Action::ToggleChildren:
                    // Sub-agent rows are loaded with every page; showing them is display state.
                    return $keep($picker);

                case $Action::ToggleArchived:
                    return $keep($this->reloadSessionPicker($picker));

                case $Action::Pin:
                    if ($id === null) {
                        return $keep($picker);
                    }
                    $store->setPinned($id, !($selected['pinned'] ?? false));

                    return $keep($this->reloadSessionPicker($picker, $id));

                case $Action::Archive:
                    if ($id === null || $id === $this->currentSessionId) {
                        return $keep($picker);
                    }
                    $store->archive($id);

                    return $keep($this->reloadSessionPicker($picker)->withNotice('Archived. Press a to show archived sessions, u to bring one back.'));

                case $Action::Unarchive:
                    if ($id === null) {
                        return $keep($picker);
                    }
                    $store->unarchive($id);

                    return $keep($this->reloadSessionPicker($picker, $id));

                case $Action::Delete:
                case $Action::DeleteWithChildren:
                    $target = $picker->armedDeleteId();
                    if ($target === null || $target === $this->currentSessionId) {
                        return $keep($picker->withNotice('This is the session on screen; switch to another before deleting it.'));
                    }
                    $deleted = $store->deleteSession($target, $action === $Action::DeleteWithChildren);
                    $count = count($deleted);

                    return $keep($this->reloadSessionPicker($picker)->withNotice(
                        $count > 1 ? "Deleted the session and {$this->pluralSessions($count - 1)} under it." : 'Deleted the session.',
                    ));

                case $Action::Rename:
                    $rename = $picker->renameTarget();
                    if ($rename === null) {
                        return $keep($picker);
                    }
                    $title = self::sanitizeSessionTitle(self::sanitizeSessionField($rename['title']));
                    if ($title === '') {
                        // A blank name is the "back to automatic" request, as
                        // in the inline title editor (P-A4) — never a user title
                        // of '' that would block the auto-titler for good. The
                        // session on screen is re-titled from its history; any
                        // other row is only made unnamed, there being no
                        // history here to title it from.
                        if ($rename['id'] === $this->currentSessionId) {
                            [$next, $titleCmd, $line] = $this->regenerateSessionTitle();

                            return [$next->mutate(['sessionPicker' => $next->reloadSessionPicker($picker, $rename['id'])->withNotice($line)]), $titleCmd];
                        }
                        $store->clearSessionName($rename['id']);

                        return $keep($this->reloadSessionPicker($picker, $rename['id'])->withNotice('Session name cleared.'));
                    }
                    $store->renameSession($rename['id'], $title, \SugarCraft\Crush\Session\TitleSource::User);
                    $next = $rename['id'] === $this->currentSessionId
                        ? $this->mutate(['currentSessionName' => $title, 'currentSessionTitleSource' => \SugarCraft\Crush\Session\TitleSource::User])
                        : $this;

                    return [$next->mutate(['sessionPicker' => $this->reloadSessionPicker($picker, $rename['id'])]), null];

                case $Action::Fork:
                    if ($id === null) {
                        return $keep($picker);
                    }
                    if ($this->inFlight) {
                        return $this->refuseInFlightAction('Fork session');
                    }
                    // forkSession() copies the STORED transcript: write any
                    // debounced change first, or the fork starts behind the
                    // screen (audit R2) — the same flush /branch does.
                    $this->transcripts()->flush();
                    $forkId = $store->forkSession($id);

                    return [$this->mutate(['sessionPicker' => null])->switchToSession($forkId, $this->storedSessionName($forkId)), null];
            }
        } catch (\Throwable $e) {
            return $keep($picker->withNotice('Error: ' . self::sanitizeSessionField($e->getMessage())));
        }

        return $keep($picker);
    }

    /** "1 session" / "N sessions". */
    private function pluralSessions(int $count): string
    {
        return $count . ($count === 1 ? ' session' : ' sessions');
    }

    /**
     * Space: the highlighted session's last few messages, shown in the
     * picker's footer. Read from the saved transcript on this one key (never
     * per frame), each message cut to its first line and sanitized like
     * every other stored string the picker paints.
     */
    private function previewSessionInPicker(SessionPicker $picker): SessionPicker
    {
        $selected = $picker->selectedSession();
        if ($selected === null) {
            return $picker;
        }

        $lines = [];
        foreach (self::loadTranscript($this->sessionStore, $selected['sessionId']) as $message) {
            // A row hidden from the user — the per-turn `<turn-context>` block,
            // a nudge — is the model's, not a line of the conversation.
            if ($message->uiOnly || !$message->userVisible || ($message->role !== Role::User && $message->role !== Role::Assistant)) {
                continue;
            }
            $text = trim((string) preg_replace('/\s+/u', ' ', self::sanitizeSessionField($message->content)));
            if ($text !== '') {
                $lines[] = ($message->role === Role::User ? 'you: ' : 'ai:  ') . $text;
            }
        }

        return $picker->withPreview(
            $selected['sessionId'],
            $lines === [] ? ['(no saved messages to preview)'] : array_slice($lines, -SessionPicker::PREVIEW_LINES),
        );
    }

    /**
     * Adopt the picker's highlighted row as the current session and close
     * the overlay.
     *
     * `currentSessionName` is re-read from the store rather than taken from
     * the picker row, whose `sessionName` is a display label (an unnamed
     * row reads `(untitled …)`): latching that would look like a user-set
     * title and suppress the auto-titling pass in
     * {@see scheduleTitleGeneration()}, which skips any Chat that already
     * has a `currentSessionName`.
     *
     * A sub-agent row is not switched to: it is a record of a delegated run,
     * not a conversation to continue, so Enter opens it in the read-only
     * Agent View instead (P-C2).
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function resumeSelectedSession(SessionPicker $picker): array
    {
        $selected = $picker->selectedSession();
        if ($selected === null || $this->sessionStore === null) {
            return [$this->mutate(['sessionPicker' => null]), null];
        }

        // Roadmap P-C2: a sub-agent row opens the read-only Agent View on its
        // stored transcript, in the shell that hosts this chat (`Space` still
        // previews its last messages in the footer).
        if (($selected['kind'] ?? 'main') === 'subagent') {
            $childId = (string) $selected['sessionId'];
            $agent = trim((string) ($selected['agent'] ?? '')) === '' ? null : (string) $selected['agent'];

            return [
                $this->mutate(['sessionPicker' => null]),
                static fn (): OpenAgentViewMsg => new OpenAgentViewMsg($childId, $childId, $agent),
            ];
        }

        $sessionId = $selected['sessionId'];

        return [$this->mutate(['sessionPicker' => null])->switchToSession($sessionId, $this->storedSessionName($sessionId)), null];
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
            'history' => [...$this->history, Message::user($inputText)->withUiOnly(), Message::assistant($response)->withUiOnly()],
            'inputBuf' => '',
            'inFlight' => false,
        ]);
        return [$next, null];
    }

    /**
     * Handle /branch — fork the current session and move onto the copy:
     * {@see \SugarCraft\Crush\Host\Commands\BranchCommand} decides, and {@see applyCommandResult()}
     * moves this window (and, out of a read-only window, hands back the draft
     * it refused). `update()` then moves the session lock onto the branch
     * (audit SES-3(b)).
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleBranchCommand(string $inputText): array
    {
        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\BranchCommand(), $inputText);
    }

    /**
     * Handle /bg (alias /background) — {@see \SugarCraft\Crush\Host\Commands\BackgroundCommand}: dispatch
     * a task onto the {@see \SugarCraft\Crush\Sessions\BackgroundSupervisor}
     * off-turn and hand the prompt straight back, or `/bg stop <id>`. The
     * answer lands later as a {@see BackgroundSessionSpawnedMsg} or
     * {@see BackgroundSessionStoppedMsg}.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleBackgroundCommand(string $inputText): array
    {
        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\BackgroundCommand(), $inputText);
    }

    /** The transcript line for a settled `/bg stop` — {@see \SugarCraft\Crush\Host\Commands\BackgroundCommand::stopNotice()}. */
    private static function backgroundStopNotice(BackgroundSessionStoppedMsg $msg): string
    {
        return \SugarCraft\Crush\Host\Commands\BackgroundCommand::stopNotice($msg);
    }

    /**
     * Handle /fork — clone this conversation and run the prompt against the
     * clone in a background session: {@see \SugarCraft\Crush\Host\Commands\ForkCommand}. `/branch` moves
     * this window onto its copy; `/fork` leaves it where it is.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleForkCommand(string $inputText): array
    {
        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\ForkCommand(), $inputText);
    }

    /**
     * The argument text of a dispatched command — the rule is
     * {@see \SugarCraft\Crush\Host\Commands\CommandText::argument()}, which
     * the moved command bodies read too (roadmap O-2h), so the name/argument
     * boundary cannot drift from the parser's (audit 15b-22).
     */
    private static function commandArgument(string $inputText): string
    {
        return \SugarCraft\Crush\Host\Commands\CommandText::argument($inputText);
    }

    /**
     * A slash command split into whitespace tokens, the command word first —
     * {@see \SugarCraft\Crush\Host\Commands\CommandText::tokens()} (audit 15b-24).
     *
     * @return list<string>
     */
    private static function commandTokens(string $inputText): array
    {
        return \SugarCraft\Crush\Host\Commands\CommandText::tokens($inputText);
    }

    /**
     * Handle /rename — name the current session (roadmap P-A4):
     * {@see \SugarCraft\Crush\Host\Commands\RenameCommand}. `/rename <title>` is the user's title,
     * `/rename --auto` asks the title model, and a bare `/rename` opens the
     * inline title editor ({@see openTitleEditor()}).
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleRenameCommand(string $inputText): array
    {
        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\RenameCommand(), $inputText);
    }

    /** Longest title the inline editor accepts — the session picker's own cap. */
    private const TITLE_EDITOR_MAX = 120;

    /**
     * Two clicks on the same session tab this close together are a
     * double-click (roadmap P-A4). candy-mouse reports no click count, so the
     * window is this model's own; 400 ms is the common desktop default.
     */
    public const TAB_DOUBLE_CLICK_SECONDS = 0.4;

    /**
     * Name the current session as the USER —
     * {@see \SugarCraft\Crush\Host\Commands\RenameCommand::userTitle()} writes it as
     * {@see \SugarCraft\Crush\Session\TitleSource::User}, and it is latched
     * here with that source so a generated title in flight can never displace
     * it. A title that sanitises to nothing is refused rather than stored.
     *
     * @return array{0: self, 1: string} the next model and the line to report
     */
    private function applyUserSessionTitle(string $raw): array
    {
        [$response, $effects] = \SugarCraft\Crush\Host\Commands\RenameCommand::userTitle($this->commandContext(), $raw);

        return [$this->withTitleEffects($effects), $response];
    }

    /**
     * Drop the current title and ask the title model for a new one — the
     * answer to `/rename --auto` and to a blank inline rename:
     * {@see \SugarCraft\Crush\Host\Commands\RenameCommand::regenerate()}. The in-memory name is cleared
     * with the store's, so the {@see SessionTitledMsg} arm latches whatever
     * comes back — unless the user names the session again meanwhile.
     *
     * @return array{0: self, 1: ?\Closure, 2: string} the next model, the title Cmd, the line to report
     */
    private function regenerateSessionTitle(): array
    {
        [$response, $effects] = \SugarCraft\Crush\Host\Commands\RenameCommand::regenerate($this->commandContext());
        $cmd = null;
        foreach ($effects as $effect) {
            if ($effect->kind === \SugarCraft\Crush\Host\Commands\CommandEffectKind::Async) {
                $cmd = Cmd::promise($effect->run());
            }
        }

        return [$this->withTitleEffects($effects), $cmd, $response];
    }

    /**
     * This model with a rename's effects latched — the name and who chose it —
     * and nothing else: the title editor and the picker answer with their own
     * rows.
     *
     * @param list<\SugarCraft\Crush\Host\Commands\CommandEffect> $effects
     */
    private function withTitleEffects(array $effects): self
    {
        $next = $this;
        foreach ($effects as $effect) {
            if ($effect->kind === \SugarCraft\Crush\Host\Commands\CommandEffectKind::RenameSession) {
                $next = $next->mutate([
                    'currentSessionName' => $effect->title(),
                    'currentSessionTitleSource' => $effect->titleSource(),
                ]);
            }
        }

        return $next;
    }

    /**
     * Open the inline title editor on the current session, prefilled with its
     * name and the cursor at the end. A no-op without a store or a session —
     * there would be nothing to save into.
     */
    private function openTitleEditor(): self
    {
        if ($this->sessionStore === null || $this->currentSessionId === null) {
            return $this;
        }

        $input = \SugarCraft\Forms\TextInput\TextInput::new()
            ->withPrompt('')
            ->withCharLimit(self::TITLE_EDITOR_MAX)
            ->setValue($this->currentSessionName ?? '');
        // The blink Cmd is dropped, as the picker's inline rename drops it:
        // the row repaints on every keystroke.
        [$input] = $input->focus();
        \assert($input instanceof \SugarCraft\Forms\TextInput\TextInput);

        return $this->mutate(['titleEditor' => $input->cursorEnd()]);
    }

    /**
     * Every key while the inline title editor is open (roadmap P-A4): Enter
     * saves, Escape closes it unchanged, anything else edits the draft. An
     * empty draft saved is the "back to automatic" request — the name is
     * cleared and regenerated ({@see regenerateSessionTitle()}) — never a
     * blank user title that would block the auto-titler for good.
     *
     * The answer goes to the transcript as one UI-only row; nothing reaches
     * the model.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleTitleEditorKey(KeyMsg $msg): array
    {
        $editor = $this->titleEditor;
        \assert($editor !== null);

        if ($msg->type === KeyType::Escape) {
            return [$this->mutate(['titleEditor' => null]), null];
        }

        if ($msg->type !== KeyType::Enter) {
            [$edited] = $editor->update($msg);
            \assert($edited instanceof \SugarCraft\Forms\TextInput\TextInput);

            return [$this->mutate(['titleEditor' => $edited]), null];
        }

        $closed = $this->mutate(['titleEditor' => null]);
        if ($closed->sessionStore === null || $closed->currentSessionId === null) {
            return [$closed, null];
        }

        $cmd = null;
        try {
            if (trim($editor->value) === '') {
                [$next, $cmd, $response] = $closed->regenerateSessionTitle();
            } else {
                [$next, $response] = $closed->applyUserSessionTitle($editor->value);
            }
        } catch (\Throwable $e) {
            [$next, $response] = [$closed, 'Error: ' . self::sanitizeSessionField($e->getMessage())];
        }

        return [
            $next->mutate(['history' => [...$next->history, Message::assistant($response)->withUiOnly()]]),
            $cmd,
        ];
    }

    /**
     * Handle /rewind — restore an earlier checkpoint: the conversation, the
     * project's files (item 3.A-2), or both — {@see \SugarCraft\Crush\Host\Commands\RewindCommand}. A
     * restore puts the checkpoint's draft and caret back in the box
     * ({@see applyCommandResult()}) and abandons an outstanding `/compact`.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleRewindCommand(string $inputText): array
    {
        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\RewindCommand(), $inputText);
    }

    /**
     * `/undo` — take back the last turn: revert this session's last
     * auto-commit (step 3.G), or else `/rewind 1 --both` — {@see \SugarCraft\Crush\Host\Commands\UndoCommand}.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleUndoCommand(): array
    {
        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\UndoCommand(), '/undo');
    }

    /**
     * The Cmd that makes a `turn`-mode auto-commit (step 3.G) once a turn has
     * settled, or null when it should not: `autoCommit` is not `turn`, there is
     * no session, store or project root, or the checkpoint taken before this
     * turn's prompt holds no git snapshot to tell the turn's changes from what
     * was there already.
     *
     * The git work runs in the Cmd, never in update(): it collects the files
     * changed since that checkpoint, commits the user's own earlier changes to
     * them first, and asks the cheap title model for the subject
     * ({@see \SugarCraft\Crush\Workspace\CommitMessageWriter}) — or uses a
     * plain one when there is no title model or the spend cap is reached. The
     * commit is made as the answer settles, in this process, and reported by
     * an {@see \SugarCraft\Crush\Workspace\AutoCommittedMsg}.
     */
    private function scheduleAutoCommit(): ?\Closure
    {
        $config = $this->workspace?->userConfig ?? [];
        $mode = \SugarCraft\Crush\Workspace\AutoCommitter::modeFrom($config[\SugarCraft\Crush\Workspace\AutoCommitter::SETTINGS_KEY] ?? null);
        $store = $this->sessionStore;
        $sessionId = $this->currentSessionId;
        if ($mode !== \SugarCraft\Crush\Workspace\AutoCommitter::MODE_TURN || !$store instanceof EnhancedSessionStore
            || $sessionId === null || $this->projectRoot === null || $this->projectRoot === '') {
            return null;
        }

        try {
            $latest = $store->listCheckpoints($sessionId, 1)[0]['state_data'] ?? null;
        } catch (\Throwable) {
            return null;
        }
        $workspace = self::checkpointWorkspace($latest);
        if (!\SugarCraft\Crush\Workspace\WorkspaceCheckpointer::isCaptured($workspace) || !\is_array($workspace)) {
            return null;
        }

        $committer = \SugarCraft\Crush\Workspace\AutoCommitter::new($this->projectRoot)
            ->withSessionId($sessionId)
            ->withTrailer(\SugarCraft\Crush\Workspace\AutoCommitter::trailerFor($config['attribution'] ?? null));
        $checkpointer = $store->workspaceCheckpointer($this->projectRoot);
        $backend = $this->spendCapReached() ? null : $this->titleBackend;
        $context = '';
        foreach (array_reverse(Message::agentVisible($this->history)) as $message) {
            if ($message->role === Role::User) {
                $context = $message->content;
                break;
            }
        }

        return Cmd::promise(static function () use ($committer, $checkpointer, $workspace, $backend, $context, $sessionId): PromiseInterface {
            try {
                $prepared = $committer->prepareTurn($checkpointer, $workspace);
            } catch (\Throwable $e) {
                $prepared = $e->getMessage();
            }
            if ($prepared === null) {
                return \React\Promise\resolve(null);
            }
            if (\is_string($prepared)) {
                return \React\Promise\resolve(new \SugarCraft\Crush\Workspace\AutoCommittedMsg(error: $prepared, sessionId: $sessionId));
            }

            $finish = static function (?string $reply, ?Usage $usage) use ($committer, $prepared, $sessionId): Msg {
                $subject = ($reply === null ? null : \SugarCraft\Crush\Workspace\CommitMessageWriter::subjectFrom($reply))
                    ?? \SugarCraft\Crush\Workspace\CommitMessageWriter::fallback($prepared['paths']);
                $outcome = $committer->commit($prepared['paths'], $subject);

                return new \SugarCraft\Crush\Workspace\AutoCommittedMsg(
                    sha: $outcome['sha'],
                    subject: $outcome['subject'],
                    paths: $outcome['paths'],
                    snapshot: $prepared['snapshot'],
                    error: $outcome['ok'] ? null : $outcome['reason'],
                    usage: $usage,
                    sessionId: $sessionId,
                );
            };
            if ($backend === null) {
                return \React\Promise\resolve($finish(null, null));
            }

            return $backend->completeAsync(\SugarCraft\Crush\Workspace\CommitMessageWriter::request($prepared['diff'], $context))->then(
                static fn (Message $reply): Msg => $finish($reply->content, $reply->usage),
                // The commit still happens, with a plain subject: a title
                // model that failed is no reason to leave the turn uncommitted.
                static fn (\Throwable $e): Msg => $finish(null, null),
            );
        });
    }

    /**
     * `/redo` (item 3.A-2) — step forward one checkpoint over what `/rewind`
     * or `/undo` set aside — {@see \SugarCraft\Crush\Host\Commands\RedoCommand}. The files move only when
     * they are where the conversation is.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleRedoCommand(): array
    {
        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\RedoCommand(), '/redo');
    }

    /**
     * `/diff [n]` (item 3.A-2) — what changed in the files since checkpoint n
     * — {@see \SugarCraft\Crush\Host\Commands\DiffCommand}. Read-only; the rows are UI-only.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleDiffCommand(string $inputText): array
    {
        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\DiffCommand(), $inputText);
    }

    /**
     * The workspace outcome a decoded checkpoint state carries, if any —
     * {@see \SugarCraft\Crush\Host\Commands\Checkpoints::checkpointWorkspace()}.
     *
     * @param mixed $state
     * @return array<string, mixed>|null
     */
    private static function checkpointWorkspace(mixed $state): ?array
    {
        return \SugarCraft\Crush\Host\Commands\Checkpoints::checkpointWorkspace($state);
    }

    /**
     * Handle /memory commands — {@see \SugarCraft\Crush\Host\Commands\MemoryCommand} (roadmap O-2h):
     * list, add, search, delete, edit, clear, import, log and restore over the
     * session's home store and project root, through the router the `Memory`
     * tool shares.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleMemoryCommand(string $inputText): array
    {
        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\MemoryCommand(), $inputText);
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
     * `withCharLimit(0)` keeps TextArea's pre-limit unbounded behaviour. Its
     * 65536 default is a paste-DoS guard, and this box DOES now take a paste
     * (E704: {@see update()} inserts a `PasteMsg` through
     * {@see TextArea::insertString()}); the length is deliberately left
     * uncapped because the SAME box also receives arbitrarily long revived
     * checkpoint rows through the Up arm, where a cap would silently truncate
     * one — the feature loss this limit was always set to zero to avoid. What
     * bounds a paste is upstream, not here: `InputReader` runs the payload
     * through `Sanitize::untrusted()` (escapes and C0/C1 control bytes
     * stripped) before it becomes a PasteMsg, so the reachable damage from a
     * huge clipboard is a long draft the user can see and clear, not a
     * terminal hijack or a model injection. Length is a UX ceiling, not a
     * security one, and clamping it here would break the checkpoint case.
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
     *     method's callers (`grep -n 'withInputBuf('`): the Up-recall arm,
     *     {@see runCommand()} seeding a command it then restores the draft
     *     over (menu rows, Ctrl+N, Ctrl+A), the keyHelp `?` append, and `/keys`
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
     * The plain-text call site still guards on `!$msg->ctrl`: a ctrl chord
     * the chat has not decided to route is a DEAD KEY inside
     * `TextArea::update()` (its ctrl table answers a fixed rune set and drops
     * everything else), and the chat's key table is the authority on which
     * ctrl chords mean something — see {@see update()}'s Ctrl+C arm, which
     * since E744 WS2 deliberately DOES arrive here when the draft carries a
     * selection, because at that point the chord is the widget's own copy.
     *
     * The widget's Cmd slot is no longer dropped (E744 WS1 — the acceptance
     * half of "Cmd plumbing, widget to frame"): whatever the editor raises
     * goes through {@see relayWidgetCmd()}, so a copy/cut reaches the
     * terminal with the frame's OSC 52 policy applied instead of vanishing
     * the way it did pre-E744.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function delegateToInput(KeyMsg $msg): array
    {
        [$next, $cmd] = $this->input->update($msg);
        \assert($next instanceof TextArea);

        return [$this->withInput($next), $cmd === null ? null : $this->relayWidgetCmd($cmd)];
    }

    /**
     * Relay one widget-raised Cmd through the frame (E744 WS1).
     *
     * LAZY on purpose: the widget contract is `Closure(): ?Msg`, evaluated by
     * `Program::scheduleCmd()` on a future tick, and wrapping the closure —
     * rather than invoking it here — is what keeps that timing. Every Cmd a
     * widget in this tree can currently raise (`setClipboard`, `exec`,
     * `send`, `batch`) is pure-at-invoke; the one impure factory in the
     * vocabulary is `promise`, which no widget emits — if one ever does, the
     * wrapper relays it untouched, which is the correct outcome anyway.
     *
     * The frame's policy is exactly one rule wide: an OSC 52 clipboard write
     * over {@see OSC52_MAX_CHARS} is clipped and the clip is announced as a
     * runtime notice. A payload that parses as OSC 52 but does not
     * base64-decode passes VERBATIM — the relay refuses to fabricate a
     * terminal sequence it cannot fully account for, and the alternative
     * (dropping it) is precisely the silent loss this relay exists to end.
     * Any other Msg is relayed unchanged: nothing in the widget's vocabulary
     * may vanish between the model and the interpreter.
     *
     * The notice rides this session's {@see \SugarCraft\Crush\Diagnostics\NoticeSink}
     * — the workspace's (W2-e: a session's own inbox, so two sessions never
     * read each other's notices), else the process's current one, resolved
     * when the Cmd runs as {@see RuntimeNoticeSink::record()} resolved it — the
     * transcript's own diagnostic channel, drained by the subscription this
     * class already wires, and NOT stderr: an unarmed sink (one-shot hosts,
     * embedded drivers) loses the notice and keeps the clip, which is
     * disclosed here rather than papered over with a second channel.
     */
    private function relayWidgetCmd(\Closure $cmd): \Closure
    {
        $workspaceSink = $this->workspace?->notices;

        return static function () use ($cmd, $workspaceSink): ?Msg {
            $msg = $cmd();

            return $msg instanceof RawMsg
                ? self::cappedOsc52($msg, $workspaceSink ?? RuntimeNoticeSink::current())
                : $msg;
        };
    }

    /**
     * Apply the frame's OSC 52 policy to one raw terminal write.
     *
     * Recognises exactly the shape {@see Ansi::setClipboard()} produces —
     * `OSC "52;" selection ";" base64 BEL` — because that is the only OSC 52
     * producer in this tree. The pattern is assembled from Ansi's own
     * constants so no raw escape byte appears in this file (the same law
     * that keeps the transcript free of hand-rolled SGR).
     */
    private static function cappedOsc52(RawMsg $msg, \SugarCraft\Crush\Diagnostics\NoticeSink $notices): RawMsg
    {
        $pattern = '/\A' . preg_quote(Ansi::OSC, '/') . '52;(.);([A-Za-z0-9+\/=]*)'
            . preg_quote(Ansi::BEL, '/') . '\z/s';
        if (preg_match($pattern, $msg->bytes, $out) !== 1) {
            return $msg;
        }

        $decoded = base64_decode($out[2], true);
        if ($decoded === false) {
            return $msg;
        }

        $chars = mb_strlen($decoded, 'UTF-8');
        if ($chars <= self::OSC52_MAX_CHARS) {
            return $msg;
        }

        $clipped = mb_substr($decoded, 0, self::OSC52_MAX_CHARS, 'UTF-8');
        $notices->record(
            'Clipboard copy clipped to ' . self::OSC52_MAX_CHARS . ' of ' . $chars . ' characters.'
        );

        return new RawMsg(Ansi::setClipboard($clipped, $out[1]));
    }

    /**
     * Where the cursor is in {@see $inputBuf}, as a character offset from
     * the start of the whole draft (newlines counted as one character each).
     *
     * Flat rather than (row, column) because every caller — Ctrl+W's word
     * boundary, {@see Renderer::renderInput()}'s cursor glyph, the
     * {@see dispatchTurn()} draft capture `/rewind` restores — reasons about the
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

    /** What Ctrl+V says when the clipboard held no image a tool could read. */
    private const NO_CLIPBOARD_IMAGE_NOTICE = 'No image on the clipboard to attach. Ctrl+V reads an image through '
        . 'pngpaste (macOS), wl-paste (Wayland) or xclip (X11); paste text with your terminal\'s own paste key, '
        . 'or attach a file with @path.';

    /**
     * The `@` mention that names $path in a draft: relative to the project
     * root when it is inside it (what a user would have typed), quoted when it
     * holds whitespace (the mention grammar's quoted form,
     * {@see FileMentions}).
     */
    private function mentionFor(string $path): string
    {
        $root = rtrim($this->projectRoot(), '/');
        if ($root !== '' && str_starts_with($path, $root . '/')) {
            $path = substr($path, strlen($root) + 1);
        }

        return preg_match('/\s/u', $path) === 1 ? '@"' . $path . '"' : '@' . $path;
    }

    /**
     * The image file a paste names, when the WHOLE paste is one path to an
     * existing PNG/JPEG/GIF/WebP file - or null, and the paste is text.
     *
     * Dropping a file onto a terminal types its path in whichever spelling
     * that terminal uses: bare, single- or double-quoted (GNOME, Konsole),
     * backslash-escaped (macOS Terminal, iTerm2), or as a `file://` URI. Each
     * is undone here. The test is on the file's magic bytes, not its name, so
     * a `.png` that is not an image stays text. A path containing `"` is left
     * as text too: the mention grammar has no way to quote it.
     */
    private static function pastedImagePath(string $content): ?string
    {
        $text = trim($content);
        if ($text === '' || strlen($text) > 4096 || preg_match('/[\r\n]/', $text) === 1) {
            return null;
        }

        if (str_starts_with($text, 'file://')) {
            $text = rawurldecode((string) preg_replace('~^file://(?:localhost)?~', '', $text));
        }
        if (strlen($text) >= 2 && ($text[0] === "'" || $text[0] === '"') && $text[-1] === $text[0]) {
            $text = substr($text, 1, -1);
        }

        $candidates = [$text];
        if (str_contains($text, '\\')) {
            $candidates[] = (string) preg_replace('/\\\\(.)/s', '$1', $text);
        }

        foreach ($candidates as $path) {
            if ($path === '' || str_contains($path, '"')) {
                continue;
            }
            if (str_starts_with($path, '~/')) {
                $home = (string) (getenv('HOME') ?: '');
                $path = $home === '' ? $path : rtrim($home, '/') . substr($path, 1);
            }
            if (!str_starts_with($path, '/') || !is_file($path)) {
                continue;
            }
            $head = (string) @file_get_contents($path, false, null, 0, 16);
            if (FileMentions::sniffImage($head) !== null) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Whether a bare Tab completes an `@file` mention rather than cycling
     * panes (audit 15b-15): the caret ends an `@` token in the draft and no
     * modal is up. The modal guards are {@see slashMenuOwnsTab()}'s four, for
     * the reason its docblock gives - a shell that yields Tab on a condition
     * update() does not answer turns Tab into a dead key.
     *
     * No filesystem here: it is read by the shell on every key, so it only
     * asks whether the caret is IN a mention. A mention that completes to
     * nothing leaves the draft as it is - Tab inside a half-typed path is a
     * completion attempt, never a pane switch out from under the typing.
     */
    public function mentionOwnsTab(): bool
    {
        return $this->keyHelp === null
            && $this->pendingPermission === null
            && $this->palette === null
            && $this->sessionPicker === null
            && $this->mentionTokenAtCaret() !== null;
    }

    /**
     * The `@` token the caret ends, in CHARACTER offsets (the widget's unit).
     *
     * @return array{start: int, partial: string}|null
     */
    private function mentionTokenAtCaret(): ?array
    {
        if (!str_contains($this->inputBuf, '@')) {
            return null;
        }

        $caret = strlen(mb_substr($this->inputBuf, 0, $this->inputCursorOffset(), 'UTF-8'));
        $token = FileMentions::tokenAt($this->inputBuf, $caret);
        if ($token === null) {
            return null;
        }

        return ['start' => mb_strlen(substr($this->inputBuf, 0, $token['start']), 'UTF-8'), 'partial' => $token['partial']];
    }

    /**
     * Tab on an `@file` mention: replace the typed path with its completion
     * from the project root ({@see FileMentions::complete()}), closing a
     * unique file with a space so the next word can follow.
     *
     * @return array{0: self, 1: null}
     */
    private function completeMention(): array
    {
        $token = $this->mentionTokenAtCaret();
        if ($token === null) {
            return [$this, null];
        }

        $completion = FileMentions::complete($token['partial'], $this->projectRoot());
        if ($completion === null) {
            return [$this, null];
        }

        $replacement = '@' . $completion['path'] . ($completion['unique'] ? ' ' : '');
        $from = $token['start'];
        $to = $from + 1 + mb_strlen($token['partial'], 'UTF-8');
        $buffer = mb_substr($this->inputBuf, 0, $from, 'UTF-8') . $replacement . mb_substr($this->inputBuf, $to, null, 'UTF-8');

        return [$this->withInputBuf($buffer)->withInputCursor($from + mb_strlen($replacement, 'UTF-8')), null];
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
     * Ctrl+P palette already uses for the same purpose - and holding only
     * labels that contain EVERY query character in order (see
     * {@see $paletteMatcher}). Returns [] when the palette is closed.
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
            if (!$this->palette->isPicker()) {
                $items = $this->rankRootPaletteLabels($items);
            }

            return array_map(
                static fn(string $label): MatchResult => new MatchResult($query, $label, 0, []),
                $items,
            );
        }

        return self::paletteFuzzyMatches($query, $items);
    }

    /**
     * Most queries {@see paletteFuzzyMatches()} remembers per label set; past
     * it the oldest is forgotten. Generous for a type-and-backspace session
     * over one picker, yet bounded however long the palette stays open.
     */
    private const PALETTE_MATCH_MEMO_LIMIT = 64;

    /**
     * The palette's one matcher, built on first use. `requireFullQuery`
     * (candy-fuzzy #3): the matcher is a LOCAL alignment, so in plain mode
     * any label sharing a single character with the query matched - "xq"
     * listed "Exit" on its "x" alone - and the palette widened instead of
     * narrowing as the user typed. Full-query mode keeps exactly the labels
     * that contain every typed character in order, initials included ("swm"
     * still finds "Switch model").
     */
    private static ?SmithWatermanMatcher $paletteMatcher = null;

    /**
     * Ranked results per query for the label set they were computed over
     * (candy-fuzzy #2). paletteMatchResults() is asked several times per
     * keystroke - the key arm, the zone mapping, the renderer - and the
     * labels do not change between keystrokes, so each (query, labels) pair
     * is aligned once. A different label list (palette mode switch, a
     * provider or theme appearing) discards the whole memo: results are only
     * ever served for the exact list they were computed from.
     *
     * @var array{labels: list<string>, results: array<string, list<MatchResult>>}|null
     */
    private static ?array $paletteMatchMemo = null;

    /**
     * @param list<string> $labels
     * @return list<MatchResult>
     */
    private static function paletteFuzzyMatches(string $query, array $labels): array
    {
        if (self::$paletteMatchMemo === null || self::$paletteMatchMemo['labels'] !== $labels) {
            self::$paletteMatchMemo = ['labels' => $labels, 'results' => []];
        }

        // Keyed by the query string; a numeric query ("12") becomes an int
        // key, which array_key_exists() and the lookup below normalise the
        // same way, so it still round-trips.
        if (array_key_exists($query, self::$paletteMatchMemo['results'])) {
            return self::$paletteMatchMemo['results'][$query];
        }

        if (count(self::$paletteMatchMemo['results']) >= self::PALETTE_MATCH_MEMO_LIMIT) {
            unset(self::$paletteMatchMemo['results'][array_key_first(self::$paletteMatchMemo['results'])]);
        }

        self::$paletteMatcher ??= SmithWatermanMatcher::new(requireFullQuery: true);

        return self::$paletteMatchMemo['results'][$query] = array_values(self::$paletteMatcher->matchAll($query, $labels));
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
            'models' => $this->paletteModelLabels((string) $this->palette->provider),
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
            // E3: the query buffer answers the caret like the draft does.
            // Typing inserts AT the caret and Backspace erases BEFORE it, so
            // the append-only pair these arms replaces (`query . rune`,
            // `dropLast(query)`) is gone - a caret parked at the end makes
            // those two exactly the old behaviour. ctrl-flagged runes keep
            // typing the LETTER (the draft's documented rule; the only ctrl
            // chord with an arm above is the Ctrl+P toggle-close).
            $msg->type === KeyType::Char
                => [$this->insertAtPaletteCaret($msg->rune), null],
            $msg->type === KeyType::Space
                => [$this->insertAtPaletteCaret(' '), null],
            $msg->type === KeyType::Backspace
                => [$this->eraseBeforePaletteCaret(), null],
            $msg->type === KeyType::Delete
                => [$this->eraseAfterPaletteCaret(), null],
            // Caret motion, cluster-wise (see {@see clusterStartBefore()}).
            // Alt/Ctrl+arrow chords move ONE cluster here, not one word: word
            // motion stays the draft's alone for now, and a one-step move is
            // what these chords did NOT do at all before (no-op), so this is
            // a strict superset, never a wrong-word jump.
            $msg->type === KeyType::Left
                => [$this->movePaletteCaret(
                    self::clusterStartBefore($this->palette->query, $this->palette->queryCursor)
                ), null],
            $msg->type === KeyType::Right
                => [$this->movePaletteCaret(
                    self::clusterEndAfter($this->palette->query, $this->palette->queryCursor)
                ), null],
            $msg->type === KeyType::Home
                => [$this->movePaletteCaret(0), null],
            $msg->type === KeyType::End
                => [$this->movePaletteCaret(mb_strlen($this->palette->query, 'UTF-8')), null],
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
     * BLANKET, WITH TWO EXCEPTIONS, and the reason it is blanket is measured
     * rather than assumed: every root action other than Exit delegates to a
     * handler that writes `inFlight` ({@see handleShareCommand()},
     * {@see handleAgentsCommand()}, {@see handleSessionsCommand()},
     * {@see handleMcpAuthCommand()}), wipes history
     * ({@see handlePaletteNewSession()}), or swaps the backend the running
     * agentic loop is about to make its NEXT provider call on
     * ({@see selectPaletteProvider()} — {@see finishToolCalls()} re-enters the
     * backend mid-turn, so this one is not hypothetical). `Exit` is an exception
     * for exactly the reason bare `/exit` is one in {@see submit()}: it ends the
     * process, so there is nothing left to corrupt. `View settings` is the
     * other: it opens a read-only view the shell holds and writes no turn state.
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

        return match (PaletteAction::byLabel($label)) {
            PaletteAction::Exit => [$this->mutate(['palette' => null]), Cmd::quit()],
            // N-P1: the settings view only reads, and it is shell state, so a
            // running turn has nothing it could corrupt.
            PaletteAction::OpenSettings => $this->mutate(['palette' => null])->openSettingsView(),
            default => $this->refuseInFlightAction($label),
        };
    }

    private function withPaletteQuery(string $query, ?int $queryCursor = null): self
    {
        return $this->mutate(['palette' => $this->palette->withQuery($query, $queryCursor)]);
    }

    /**
     * Type one rune at the palette caret and park the caret after it.
     *
     * Inserting at a cluster boundary and advancing by the rune's whole
     * codepoint count keeps the caret on a boundary even when the rune is
     * itself multi-codepoint (a paste arrives as one wide rune, not as its
     * parts), which is the invariant {@see movePaletteCaret()} relies on.
     */
    private function insertAtPaletteCaret(string $rune): self
    {
        $query = $this->palette->query;
        $at    = $this->palette->queryCursor;

        return $this->withPaletteQuery(
            mb_substr($query, 0, $at, 'UTF-8') . $rune . mb_substr($query, $at, null, 'UTF-8'),
            $at + mb_strlen($rune, 'UTF-8'),
        );
    }

    /** Delete the cluster before the caret; the caret follows it left. */
    private function eraseBeforePaletteCaret(): self
    {
        $query = $this->palette->query;
        $cut   = self::clusterStartBefore($query, $this->palette->queryCursor);

        return $this->withPaletteQuery(
            mb_substr($query, 0, $cut, 'UTF-8') . mb_substr($query, $this->palette->queryCursor, null, 'UTF-8'),
            $cut,
        );
    }

    /** Delete the cluster after the caret; the caret stays put. */
    private function eraseAfterPaletteCaret(): self
    {
        $query = $this->palette->query;
        $at    = $this->palette->queryCursor;
        $cut   = self::clusterEndAfter($query, $at);

        return $this->withPaletteQuery(
            mb_substr($query, 0, $at, 'UTF-8') . mb_substr($query, $cut, null, 'UTF-8'),
            $at,
        );
    }

    /** Pure caret move; {@see PaletteState::withQueryCursor()} clamps it. */
    private function movePaletteCaret(int $offset): self
    {
        return $this->mutate(['palette' => $this->palette->withQueryCursor($offset)]);
    }

    /**
     * The grapheme-cluster boundary strictly left of codepoint offset `$at`
     * (0 when there is none - the left clamp).
     *
     * ICU segmentation via `grapheme_extract()`, the one segmentation
     * candy-core guarantees (`ext-intl` is its declared dependency, and
     * `Width` walks the same ICU boundaries since E68). Queries are a few
     * keystrokes long and the walk stops at `$at`, so this runs the length
     * of the caret's own column, not of any buffer - the ~24ms full-frame
     * warning on {@see Renderer::scanRoot()} does not reach a per-keystroke
     * walk over a search string.
     */
    private static function clusterStartBefore(string $s, int $at): int
    {
        $prev = 0;
        $cp   = 0;
        $byte = 0;
        while ($cp < $at) {
            $cluster = grapheme_extract($s, 1, 0, $byte, $next);
            if ($cluster === false || $cluster === '') {
                break;
            }
            $prev = $cp;
            $cp   += mb_strlen($cluster, 'UTF-8');
            $byte  = $next;
        }

        return $prev;
    }

    /**
     * The grapheme-cluster boundary at or after codepoint offset `$at` -
     * the end of the cluster `$at` sits inside, or the next one's end when
     * `$at` already names a boundary (so Right from a boundary advances a
     * whole cluster); the draft length when there is nothing left.
     */
    private static function clusterEndAfter(string $s, int $at): int
    {
        $total = mb_strlen($s, 'UTF-8');
        $cp    = 0;
        $byte  = 0;
        while ($cp < $total) {
            $cluster = grapheme_extract($s, 1, 0, $byte, $next);
            if ($cluster === false || $cluster === '') {
                break;
            }
            $cp += mb_strlen($cluster, 'UTF-8');
            $byte = $next;
            if ($cp > $at) {
                return $cp;
            }
        }

        return $total;
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
            'providers' => $this->pickPaletteProvider($label),
            'models' => $this->selectPaletteProvider((string) $this->palette->provider, $label),
            'themes' => $this->selectPaletteTheme($label),
            // Recorded BEFORE dispatch (crush_feat.md §4 E7): several root
            // actions return a Cmd/second-level palette rather than a plain
            // copy of $this, so the MRU has to be folded into the instance
            // the handler runs against, not bolted onto its result.
            default => $this->rememberPaletteUse($label)->runRootPaletteAction($label),
        };
    }

    /**
     * The provider list's Enter (N-P3b): switch to $name, then — when that
     * provider has more than one model worth offering — reopen the palette on
     * its model list, so Ctrl+P → Switch model reaches a model as `/model
     * <provider> <model>` does. A failed switch closes the palette as before.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function pickPaletteProvider(string $name): array
    {
        [$next, $cmd] = $this->selectPaletteProvider($name);
        if ($next->backend === $this->backend || \count($next->paletteModelLabels($name)) < 2) {
            return [$next, $cmd];
        }

        return [$next->mutate(['palette' => PaletteState::root()->withModelsOf($name)]), $cmd];
    }

    /**
     * The model rows the palette offers for $provider
     * ({@see \SugarCraft\Crush\Config\Settings\ModelChoice::paletteLabels()}):
     * the model running now when it runs on that provider, the one saved for
     * it in `models`, then its configured default.
     *
     * @return list<string>
     */
    private function paletteModelLabels(string $provider): array
    {
        $current = $this->backend instanceof \SugarCraft\Crush\Backend\EngineBackend
            && $this->backend->provider()->name() === $provider
                ? $this->backend->model()
                : null;

        try {
            $persisted = \SugarCraft\Crush\Cli\Bootstrap::readUserConfig()['models'][$provider] ?? null;
            $default = \SugarCraft\Crush\Cli\Bootstrap::availableProviders()[$provider]['model'] ?? null;
        } catch (\Throwable) {
            $persisted = $default = null;
        }

        return \SugarCraft\Crush\Config\Settings\ModelChoice::paletteLabels(
            $current,
            \is_string($persisted) ? $persisted : null,
            \is_string($default) ? $default : null,
        );
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
            // The gesture phase's palette twins: the same handlers the slash
            // commands reach, driven with fully-formed text.
            PaletteAction::DockPaneLeft => $closed->handlePaneCommand('/pane dock left'),
            PaletteAction::DockPaneRight => $closed->handlePaneCommand('/pane dock right'),
            PaletteAction::LayoutReset => $closed->handleLayoutCommand('/layout reset'),
            // N-P1: the settings view is the shell's; this only asks for it.
            PaletteAction::OpenSettings => $closed->openSettingsView(),
            // P-A4: the session actions. Rename opens the inline editor through
            // the slash handler, refused in a read-only window as `/rename` is.
            PaletteAction::RenameSession => $closed->readOnlyRefusal('/rename') ?? $closed->handleRenameCommand('/rename'),
            PaletteAction::BranchSession => $closed->handleBranchCommand('/branch'),
            PaletteAction::PinSession => $closed->togglePinCurrentSession(),
            PaletteAction::DeleteSession => $closed->openSessionListToDelete(),
            PaletteAction::Exit => [$closed, Cmd::quit()],
            default => [$closed, null],
        };
    }

    /**
     * The palette's "Pin or unpin session" (roadmap P-A4): flip the current
     * session's pin — the picker's `p`, without opening the picker. Pinned
     * sessions list first in the picker and the tab strip and survive pruning.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function togglePinCurrentSession(): array
    {
        if ($this->sessionStore === null || $this->currentSessionId === null) {
            $line = 'No active session to pin. Start a new conversation first.';
        } else {
            $pinned = (bool) ($this->sessionStore->getSession($this->currentSessionId)['pinned'] ?? false);
            $this->sessionStore->setPinned($this->currentSessionId, !$pinned);
            $line = $pinned ? 'Unpinned this session.' : 'Pinned this session: it lists first in the session picker and the tab strip.';
        }

        return [$this->mutate(['history' => [...$this->history, Message::assistant($line)->withUiOnly()]]), null];
    }

    /**
     * The palette's "Delete session…" (roadmap P-A4): the session list, open,
     * with the delete keys named in its footer. Deleting stays the picker's
     * two-press `d` — the press that shows what goes with a session (its
     * sub-agent children) before anything is removed — so the palette never
     * deletes blind, and the session on screen is refused there as everywhere.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function openSessionListToDelete(): array
    {
        [$next, $cmd] = $this->handleSessionsCommand('/sessions');
        $picker = $next->sessionPicker;
        if ($picker === null) {
            return [$next, $cmd];
        }

        return [
            $next->mutate(['sessionPicker' => $picker->withNotice('Highlight a session and press d twice to delete it; the session on screen cannot be deleted.')]),
            $cmd,
        ];
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
     * Switch to provider `$name`, and to `$model` on it when one is named
     * (N-P3b: `/model <provider> <model>`).
     *
     * A MODEL CHOICE IS SAVED BEFORE THE BACKEND IS BUILT, into the
     * per-provider `models` setting through the workspace's
     * {@see \SugarCraft\Crush\Config\Settings\SettingsWriter} (decision D9;
     * the user chose that the model choice persists). The order matters: the
     * launch factory resolves the model through `Bootstrap::selectedModelName()`,
     * which reads that setting, so the provider is BUILT on the chosen id - its
     * context window and prices follow it - instead of being built on the old
     * one and relabelled. {@see \SugarCraft\Crush\Backend\EngineBackend::withModel()}
     * is applied on top so the choice wins this session even where `--model`
     * or `$SUGARCRUSH_MODEL` outrank the saved entry, and where nothing could
     * be saved. A save that fails is reported, never fatal: the switch still
     * happens for this session.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function selectPaletteProvider(string $name, ?string $model = null): array
    {
        $model = $model === null || trim($model) === '' ? null : trim($model);
        $saved = null;
        if ($model !== null) {
            $writer = $this->workspace?->service(\SugarCraft\Crush\Config\Settings\SettingsWriter::class);
            if (!$writer instanceof \SugarCraft\Crush\Config\Settings\SettingsWriter) {
                $saved = 'for this session only: nothing here saves a model choice';
            } elseif (!\in_array($name, $this->availableProviderNames(), true)) {
                // Never save a model under a name that is not a provider: the
                // entry would sit in config.json, read by nothing.
                $saved = null;
            } else {
                try {
                    $layered = \SugarCraft\Crush\Cli\Bootstrap::readUserConfig()['models'] ?? [];
                    $path = \SugarCraft\Crush\Config\Settings\ModelChoice::persist(
                        $writer,
                        $name,
                        $model,
                        \is_array($layered) ? $layered : [],
                    );
                    $saved = 'saved as models.' . self::reportField($name) . ' in ' . self::reportField($path);
                } catch (\Throwable $e) {
                    $saved = 'not saved: ' . self::reportField($e->getMessage());
                }
            }
        }

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
            //
            // AND THE REST OF THE LAUNCH'S COLLABORATORS ARE THREADED (N-P3a).
            // Built from the root and the gate alone, the new engine came back
            // without the session's {@see $rulesState} — every `/rules` toggle
            // stopped reaching the prompt — and without a task manager, so
            // `Task` silently left the tool list. The launch's factory carries
            // the skill registry, the set, the manager and a pool rebuilt for
            // the new provider; the embedder fallback carries what this Chat
            // holds.
            //
            // THE WORKSPACE FIRST (O-2a): Bootstrap now builds the launch's
            // factory on the WorkspaceContext, whose backendFor() carries the
            // manager and the `/rules` set even when it was built without one.
            // A bare `backendFactory` (N-P3a) is still honoured for embedders
            // that pass only that.
            if ($this->workspace !== null) {
                $backend = $this->workspace->backendFor($name);
            } elseif ($this->backendFactory !== null) {
                $backend = ($this->backendFactory)($name);
            } else {
                $backend = \SugarCraft\Crush\Cli\Bootstrap::backendFor(
                    $name,
                    $this->projectRoot,
                    gate: $this->permissionGate(),
                    rulesState: $this->rulesState,
                    taskManager: $this->agentManager,
                );
                if ($backend instanceof \SugarCraft\Crush\Backend\EngineBackend) {
                    $backend = $backend->withRulesState($this->rulesState);
                }
            }

            // A model is a property of the engine; a command-line backend has
            // no model to switch, and saying "switched" would be a lie.
            if ($model !== null && !$backend instanceof \SugarCraft\Crush\Backend\EngineBackend) {
                throw new \RuntimeException('this provider does not take a model choice');
            }
            if ($model !== null) {
                $backend = $backend->withModel($model);
            }
        } catch (\Throwable $e) {
            // A model choice saved before the build failed stays saved (it is
            // a valid entry for a real provider), so the reply says so rather
            // than letting the next launch surprise the user with it.
            $note = $model !== null && $saved !== null && str_starts_with($saved, 'saved')
                ? " (model '" . self::reportField($model) . "' was {$saved})"
                : '';

            return [$this->mutate([
                'palette' => null,
                'history' => [...$this->history, Message::assistant("Could not switch to provider '{$name}': {$e->getMessage()}{$note}")->withUiOnly()],
            ]), null];
        }

        $this->onConfigChange?->__invoke('provider', $name);

        $report = $model === null
            ? "Switched to provider '{$name}'."
            : "Switched to provider '{$name}', model '" . self::reportField($model) . "'"
                . ($saved === null ? '.' : " ({$saved}).");

        return [$this->mutate([
            'palette' => null,
            'backend' => $backend,
            'summaryBackend' => $this->summaryBackendFollowing($backend),
            // The pool {@see executeAgents()} builds from this config forks
            // workers that construct `workerProvider` themselves — the launch
            // provider's spec, until now, so after a switch away from it an
            // agent on the process-executor path still ran on the old
            // provider. See {@see poolWorkerSpecFor()}.
            'agentPoolConfig' => $this->agentPoolConfig?->withWorkerProvider(self::poolWorkerSpecFor($name, $backend)),
            'history' => [...$this->history, Message::assistant($report)->withUiOnly()],
        ]), null];
    }

    private function selectPaletteTheme(string $name): array
    {
        $this->onConfigChange?->__invoke('theme', $name);

        return [$this->mutate([
            'palette' => null,
            'themeName' => $name,
            'history' => [...$this->history, Message::assistant("Theme set to '{$name}'.")->withUiOnly()],
        ]), null];
    }

    /**
     * @return array{0: self, 1: ?\Closure}
     */
    private function handlePaletteNewSession(): array
    {
        if ($this->sessionStore === null) {
            return [$this->mutate([
                'history' => [...$this->history, Message::assistant('Session store not configured. Set a SessionStore to create sessions.')->withUiOnly()],
            ]), null];
        }

        // Chat's Backend interface exposes no provider/model name to record
        // here - 'sugarcrush'/'unknown' are honest placeholders, not
        // fabricated telemetry (same disclosed-gap pattern as Renderer's own
        // R20 docblock elsewhere in this class).
        $sessionId = bin2hex(random_bytes(8));
        $this->sessionStore->createSession($sessionId, 'sugarcrush', 'unknown');

        return [$this->mutate([
            // The pending `/compact`, queued prompts, scroll position, thrash
            // breaker and activity stamp all belong to the session being left.
            ...$this->sessionChangeResets(),
            // A new session starts from an empty transcript. Carrying the old
            // one across would send it to the model under the new id and, now
            // that transcripts are saved on change, store it there as well.
            // UI-only (audit 15b-03): a transcript that opened on an assistant
            // row is one strict providers reject, and the model did not say it.
            'history' => [Message::assistant("New session created: {$sessionId}")->withUiOnly()],
            'currentSessionId' => $sessionId,
            'currentSessionName' => null,
        ]), null];
    }

    /**
     * @return array{0: self, 1: ?\Closure}
     */
    private function handlePaletteOpenDocs(): array
    {
        $message = 'Docs: see README.md in this project, or '
            . 'https://sugarcraft.github.io/lib/sugar-crush.html';

        return [$this->mutate(['history' => [...$this->history, Message::assistant($message)->withUiOnly()]]), null];
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
     * What ↑/↓ recall walks, oldest first.
     *
     * With a {@see $promptHistory} wired that is {@see $inputHistory}: every
     * prompt typed, across sessions. Without one it is the transcript's own
     * user rows, read live, which is what the recall answered before the file
     * existed and what a Chat built in a test still gets. Only real user
     * turns either way - assistant replies, tool/system rows and user rows
     * hidden from the user are skipped.
     *
     * @return list<string>
     */
    private function recallEntries(): array
    {
        if ($this->promptHistory !== null) {
            return $this->inputHistory;
        }

        $entries = [];
        foreach ($this->history as $message) {
            // Rows hidden from the user (the `<turn-context>` block, a nudge)
            // were never typed, so they are not recalled.
            if ($message->role === Role::User && $message->userVisible) {
                $entries[] = $message->content;
            }
        }

        return $entries;
    }

    /**
     * Whether the box still holds the entry ↑/↓ last recalled, unedited.
     *
     * Derived rather than latched, so every edit path - a typed character, a
     * paste, a completion, a submit - ends the walk without having to know it
     * exists: the moment the draft differs from the recalled entry, ↑/↓ go
     * back to being the editor's (or the popup's) keys.
     */
    private function isWalkingHistory(): bool
    {
        if ($this->inputHistoryCursor === null) {
            return false;
        }

        $entries = $this->recallEntries();

        return isset($entries[$this->inputHistoryCursor])
            && $entries[$this->inputHistoryCursor] === $this->inputBuf;
    }

    /**
     * Step the recall one entry older ($step = -1) or newer (+1).
     *
     * ↑ from outside a walk stashes the current draft and lands on the newest
     * entry; ↑ on the oldest stays there. ↓ past the newest entry ends the
     * walk and gives back the stashed draft, the way a shell returns you to
     * the line you were typing.
     */
    private function walkHistory(int $step): self
    {
        $entries = $this->recallEntries();
        if ($entries === []) {
            return $this;
        }

        $walking = $this->isWalkingHistory();
        $draft = $walking ? $this->inputHistoryDraft : $this->inputBuf;
        $from = $walking ? (int) $this->inputHistoryCursor : count($entries);
        $to = $from + $step;

        if ($to < 0) {
            return $this;
        }

        if ($to >= count($entries)) {
            return $this->mutate([
                'inputBuf' => $draft,
                'inputHistoryCursor' => null,
                'inputHistoryDraft' => '',
            ]);
        }

        return $this->mutate([
            'inputBuf' => $entries[$to],
            'inputHistoryCursor' => $to,
            'inputHistoryDraft' => $draft,
        ]);
    }

    /**
     * Add the draft Enter is about to send to the recall list, and to the
     * cross-session file, before {@see submit()} consumes it.
     *
     * Recorded as TYPED - a slash command included, a custom command before its
     * expansion - because recall gives back what the user wrote, not what the
     * model received. An exact repeat of the newest entry is not added twice
     * (the file applies the same rule to what is already on disk). With no
     * file wired this only ends the walk; the transcript row submit() appends
     * is what recall will read.
     */
    private function recordInputHistory(): self
    {
        $text = trim($this->inputBuf);
        $changes = [];
        if ($this->inputHistoryCursor !== null || $this->inputHistoryDraft !== '') {
            $changes = ['inputHistoryCursor' => null, 'inputHistoryDraft' => ''];
        }

        // `/exit` and `/quit` are how a session ENDS, so recording them would
        // make the leaving command the first thing ↑ offers in every new one.
        if ($text !== '' && $text !== '/exit' && $text !== '/quit' && $this->promptHistory !== null) {
            $this->promptHistory->append($text);
            $newest = $this->inputHistory === [] ? null : $this->inputHistory[array_key_last($this->inputHistory)];
            if ($newest !== $text) {
                $changes['inputHistory'] = [...$this->inputHistory, $text];
            }
        }

        // No clone when nothing moved: an Enter on an empty box must still
        // hand back the receiver itself, as submit() does.
        return $changes === [] ? $this : $this->mutate($changes);
    }

    /**
     * The idle clock of the live agents strip (roadmap P-B3): a finished
     * delegated run stays on the strip for {@see \SugarCraft\Crush\Tui\AgentStrip::LINGER_SECONDS}
     * after it ended, and that clock — {@see \SugarCraft\Crush\Agents\Live\AgentLiveRegistry::now()}
     * — only moves when a {@see ToolEventPumpMsg} arrives. While a turn runs
     * the tool-event tick sends one every {@see TOOL_EVENT_POLL_SECONDS}; once
     * the turn ends nothing did, so a finished run stayed on the strip until
     * the next turn. One second is the strip's own resolution (its elapsed
     * figures are whole seconds).
     */
    private const AGENT_STRIP_SUBSCRIPTION = 'crush.agent-strip-linger';

    private const AGENT_STRIP_TICK_SECONDS = 1.0;

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
        } elseif ($this->agentStripLingers()) {
            // Between turns, only while a FINISHED run is still lingering on
            // the strip: each tick advances the strip's clock (the
            // ToolEventPumpMsg arm), and the reconcile after the tick that
            // ages the last one out drops this — so an idle session that
            // never delegated pays nothing.
            $subscriptions = ($subscriptions ?? new \SugarCraft\Core\Subscriptions())->withTick(
                self::AGENT_STRIP_SUBSCRIPTION,
                self::AGENT_STRIP_TICK_SECONDS,
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
        // The debounced transcript save (audit R2). Declared only while a
        // snapshot is waiting, so it costs nothing on an idle session: the
        // first change starts the timer, its tick writes the newest snapshot,
        // and the reconcile after that cancels it. Changes in between only
        // replace the snapshot — see DebouncedTranscriptWriter.
        if ($this->transcriptWriter->hasPending()) {
            $subscriptions = ($subscriptions ?? new \SugarCraft\Core\Subscriptions())->withTick(
                self::TRANSCRIPT_FLUSH_SUBSCRIPTION,
                $this->transcriptWriter->delaySeconds(),
                static fn (): \SugarCraft\Core\Msg => new TranscriptFlushMsg(),
            );
        }

        // The read-only window's lock retry (audit SES-3 residual). Declared
        // only while another TUI holds the open session, so a writable
        // session — every session but a second window's — pays nothing; the
        // reconcile after the retry that wins the lock cancels it.
        if ($this->readOnlySession
            && $this->sessionLocking
            && $this->currentSessionId !== null
            && $this->sessionStore instanceof EnhancedSessionStore
        ) {
            $subscriptions = ($subscriptions ?? new \SugarCraft\Core\Subscriptions())->withTick(
                self::SESSION_LOCK_RETRY_SUBSCRIPTION,
                self::SESSION_LOCK_RETRY_SECONDS,
                static fn (): \SugarCraft\Core\Msg => new SessionLockRetryMsg(),
            );
        }

        // THIS SESSION'S inbox (W2-e): the workspace's sink when there is one,
        // the process's current one otherwise — the same sink
        // {@see pumpRuntimeNotices()} drains, so the poll is armed exactly
        // when that drain has something to take.
        if ($this->drainsRuntimeNotices
            && ($this->inFlight || ($this->workspace?->notices ?? RuntimeNoticeSink::current())->hasPending())
        ) {
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
     * Whether a finished delegated run is still lingering on the live agents
     * strip ({@see AGENT_STRIP_SUBSCRIPTION}). Read against the strip's own
     * clock, which is exactly what the tick advances: a run whose linger the
     * stale clock has not yet aged out arms one more tick, and that tick ages
     * it out. A run still "running" with no turn to finish it keeps nothing
     * armed — there is no spinner to turn between turns.
     */
    private function agentStripLingers(): bool
    {
        foreach (\SugarCraft\Crush\Tui\AgentStrip::items($this->agentLive()) as $state) {
            if ($state->isFinished()) {
                return true;
            }
        }

        return false;
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
     * THE RESULT COMES BACK (roadmap 4.3-1). A session that settles —
     * completed, failed, stopped or timed out — is reported twice: the status
     * notice every transition gets, and its
     * {@see \SugarCraft\Crush\Sessions\BackgroundSession::announcement()} as
     * a USER-ROLE turn, so the model that started the work reads the answer and
     * its stats rather than the user copying it across. Before this the daemon
     * buffered the output, the supervisor restored it onto the session, and
     * this poll dropped it on the floor behind a status-only notice.
     *
     * The announcement goes through the queue, not around it. It is appended to
     * {@see $queuedPrompts} and, when no turn is running, drained at once by
     * {@see releaseQueuedPrompts()} — i.e. through {@see submit()}, the door
     * every queued prompt takes, so the spend cap, the context tiers and the
     * UserPromptSubmit hooks judge it exactly as they judge a typed one, and the
     * user's half-typed draft survives the auto-dispatch. While a turn IS
     * running it waits for that turn to settle, like any prompt typed mid-turn
     * (steering it into the running turn is the queue's job, not this poll's).
     * Several sessions settling in one poll share ONE turn.
     *
     * A session `/bg stop` ended is NOT announced: the stop arm records its
     * settled status first, so no transition reaches this loop, and the user
     * who stopped it gets no surprise turn.
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
        $announcements = [];
        foreach ($statuses as $id => $status) {
            if (($this->backgroundStatuses[$id] ?? null) === $status) {
                continue;
            }
            $session = $supervisor->getSession($id);
            $name = $session?->name ?? $id;
            $notices[] = Message::notice(sprintf(
                "Background session %s ('%s') is now %s.",
                $id,
                $name,
                $status,
            ));
            if ($session !== null && $session->isSettled()) {
                $announcements[] = $session->announcement();
            }
        }

        if ($notices === [] && $statuses === $this->backgroundStatuses) {
            // Nothing moved - returning $this keeps the renderer's diff empty
            // instead of repainting the transcript twice a second.
            return [$this, null];
        }

        if ($announcements !== [] && $this->inFlight) {
            $notices[] = Message::notice(count($announcements) === 1
                ? 'Its result goes to the agent as soon as this turn finishes.'
                : sprintf('Their %d results go to the agent as soon as this turn finishes.', count($announcements)));
        }

        $polled = $this->mutate([
            'history' => [...$this->history, ...$notices],
            'backgroundStatuses' => $statuses,
            'queuedPrompts' => $announcements === []
                ? $this->queuedPrompts
                : [...$this->queuedPrompts, implode("\n\n", $announcements)],
        ]);

        if ($announcements === [] || $polled->inFlight) {
            return [$polled, null];
        }

        return self::releaseQueuedPrompts([$polled, null]);
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
        // O-2a: drains the inbox runtimeNoticeWake() watches — the workspace's
        // own sink, so a host running several sessions shows each its own.
        $notices = ($this->workspace?->notices ?? RuntimeNoticeSink::current())->drain();
        $rearm = $this->runtimeNoticeWake();

        if ($notices === []) {
            return [$this, $rearm];
        }

        // UI-only (audit 15b-03): these carry git stderr and model-authored
        // tool names from parser warnings, and are addressed to the user.
        $messages = [];
        foreach ($notices as $notice) {
            $messages[] = Message::notice($notice);
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
        return $this->contextMeter()->shouldPromptIdleCompaction($tokenCount, $lastActivityAt, $this->contextTokenLimit());
    }

    /**
     * The workspace's {@see \SugarCraft\Crush\Host\ContextMeter} (roadmap
     * O-2c): every context figure below is its arithmetic over this session's
     * state. Read through {@see \SugarCraft\Crush\Host\WorkspaceContext::service()}
     * so the extraction adds no constructor state; a Chat with no workspace,
     * or one that registered none, gets a fresh meter — it is stateless, so
     * the two cannot measure differently.
     */
    private function contextMeter(): \SugarCraft\Crush\Host\ContextMeter
    {
        $meter = $this->workspace?->service(\SugarCraft\Crush\Host\ContextMeter::class);

        return $meter instanceof \SugarCraft\Crush\Host\ContextMeter ? $meter : \SugarCraft\Crush\Host\ContextMeter::new();
    }

    /**
     * The workspace's {@see \SugarCraft\Crush\Host\SpendLedger} (roadmap
     * O-2c) — the spend decisions and notices below, over this session's
     * {@see $tokenTracker} and {@see $maxCostUsd}. Same locator and the same
     * stateless fallback as {@see contextMeter()}.
     */
    private function spendLedger(): \SugarCraft\Crush\Host\SpendLedger
    {
        $ledger = $this->workspace?->service(\SugarCraft\Crush\Host\SpendLedger::class);

        return $ledger instanceof \SugarCraft\Crush\Host\SpendLedger ? $ledger : \SugarCraft\Crush\Host\SpendLedger::new();
    }

    /**
     * Estimate token count for a message history: the script-weighted
     * {@see TokenEstimate} (1 token ≈ 4 chars for ASCII/Latin, heavier for CJK,
     * other non-Latin scripts and emoji - audit 15b-13) + 10 per message, the
     * same figure as {@see ContextCompactor}'s internal countTokens() since
     * 15b-13-rem, so the idle-compaction threshold agrees with what /compact
     * itself would report in every script. They read the same rows too: this
     * skips UI-only rows, and the compactor is never handed them
     * ({@see compactionWire()}).
     *
     * CALIBRATED since E17: the raw script-weighted + 10 proxy is multiplied by the
     * session's last estimate-vs-real observation ({@see $tokenEstimateCalibration})
     * when one exists. Read that field's docblock and
     * {@see turnEstimateObservation()} before concluding the units here are
     * the provider's — they are an EMPIRICAL SCALE toward them, bounded to
     * [1.0, 3.0], and until {@see Providers\CompleteResponse::$usage} is
     * populated through `Runtime`'s fold the scale carries the whole turn's
     * billed total over a prompt-only estimate (HIGH, safe direction).
     * Consequence for the claim above: with a calibration in force this
     * agrees with the provider's counter MORE than
     * {@see ContextCompactor::countTokens()} does — the compactor still
     * counts raw — so the 70% idle nudge can arrive a little earlier than a
     * `/compact` would self-report. That is a nudge deciding to fire, not a
     * tier refusing, and the direction (nudge before the lossy automatic
     * route is ever needed) is the correct one to be wrong in.
     *
     * @param list<Message> $history
     */
    private function estimateTokenCount(array $history): int
    {
        return $this->contextMeter()->estimate($history, $this->tokenEstimateCalibration);
    }

    /**
     * The PRE-calibration proxy — {@see TokenEstimate::ofText()} (chars/4
     * for ASCII/Latin, heavier per character for CJK, other scripts and
     * emoji) + 10 per message — the raw half of
     * {@see estimateTokenCount()}, exposed as its own concept because E17's
     * observation must be paired against THIS, never against the calibrated
     * output: recording raw×f at dispatch and folding real/(raw×f) at settle
     * carries the previous factor back into every later observation, and the
     * factor then cycles period-2 around the square root of the true ratio
     * (a real 4× ratio oscillates 1.33↔3.0 forever, under-estimating every
     * other turn — the direction that fires the tier LATE). Paired against
     * the raw proxy, the stored factor is the true correction itself and
     * repeated settles against a steady provider converge to it.
     *
     * @param list<Message> $history
     */
    private function rawTokenProxy(array $history): int
    {
        // Audit 15b-15's attachment and image weights live with the rest of
        // the arithmetic: {@see \SugarCraft\Crush\Host\ContextMeter::rawTokens()}.
        return $this->contextMeter()->rawTokens($history);
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
     * different units - a script-weighted estimated count against a
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
     * Approximate by construction - it is a script-weighted character proxy
     * ({@see TokenEstimate}), not a
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
        return $this->contextMeter()->limit($this->backend);
    }

    /**
     * This session's provider-reported spend in US dollars.
     *
     * A DIFFERENT unit from everything {@see contextTokens()} and
     * {@see contextTokenLimit()} deal in: those are a script-weighted estimate of what
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
        return $this->spendLedger()->spent($this->tokenTracker);
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
        $this->spendLedger()->account($this->tokenTracker, $usage);
    }

    /**
     * Pair the estimate this Chat dispatched its turn with (E17), against what
     * the settled Message says the provider actually counted, and return the
     * mutate keys that record the outcome.
     *
     * THE ESTIMATE SIDE IS RAW — {@see rawTokenProxy()}, not the calibrated
     * figure — and the choice is load-bearing arithmetic, not style: the
     * provider settles the SAME history the dispatch measured, so real/raw is
     * the true correction and storing it converges across repeated settles.
     * Pairing against the calibrated estimate instead (raw×f) would store
     * real/(raw×f) = r/f, a map whose period-2 orbit around √r never settles
     * (r=4 cycles f between 1.33 and 3.0) and half the turns run
     * under-estimated — fires the tier LATE, the unsafe direction.
     *
     * THE UNIT STORY, named rather than papered over, because the pairing is
     * not the clean prompt-vs-prompt comparison E17 step (a) ultimately
     * wants: the estimate is script-weighted over the history the tier measured, and
     * the observation is the provider's count of a prompt that also carries
     * the system block, the tool schemas, and the fresh user turn — summed
     * over every step the agentic loop made, completion tokens included,
     * because that is what a {@see Usage} carries when the split has not been
     * crossed onto its carrier. {@see Providers\CompleteResponse::$usage}
     * landed in this commit as the carrier; until `Runtime`'s two fold sites
     * and the providers pass it, {@see Usage::promptTokens()} answers null
     * and this prefers the total it does answer with — the conversation's OWN
     * total ({@see Usage::ownTokens()}), never the share its Task sub-agents
     * billed into the turn, which measures their prompts, not this one. A
     * turn whose every token was delegated therefore observes nothing and
     * keeps the existing factor. Both distortions push
     * the same way — observed HIGH — so the calibrated estimate fires the
     * tier EARLIER than the raw proxy, the safe direction against an
     * overflow, and {@see \SugarCraft\Crush\Host\ContextMeter::CALIBRATION_MAX} bounds how early;
     * {@see \SugarCraft\Crush\Host\ContextMeter::CALIBRATION_MIN} refuses to let any pairing loosen the
     * proxy below itself. When the carrier is wired the first expression
     * starts answering and the inflation stops by itself — that is why the
     * prompt half is preferred wherever both exist.
     *
     * ONE-SHOT BY CONSTRUCTION: `promptEstimateAtDispatch` is cleared on
     * every return, observed or not. Only `submit()` sets it, only the
     * settled AssistantMsg arm reads it, and the four other
     * {@see accountUsage()} callers (titler, compaction, superseded-tool,
     * parked summary) never route through that arm — so no non-chat billing
     * can masquerade as a history-size observation. An unobserved settle (a
     * stream that reported nothing, the ordinary case) leaves the EXISTING
     * factor alone by omitting the key rather than nulling it: one silent
     * turn must not erase a measurement a loud turn already made.
     *
     * @return array{promptEstimateAtDispatch: null, tokenEstimateCalibration?: float}
     */
    private function turnEstimateObservation(?Usage $usage): array
    {
        $calibration = $this->contextMeter()->calibrationFrom($this->promptEstimateAtDispatch, $usage);

        if ($calibration === null) {
            return ['promptEstimateAtDispatch' => null];
        }

        return [
            'promptEstimateAtDispatch' => null,
            'tokenEstimateCalibration' => $calibration,
        ];
    }

    /**
     * Whether any settled turn this session actually reported usage.
     *
     * False on every offline run and on a streamed session whose provider
     * never sent a usage block. {@see Runtime}'s streaming path documents that
     * content chunks carry `tokensUsed=0`; since the billing fix the paid
     * providers append one terminal usage frame (
     * {@see Providers\OpenAIProvider::completeStream()}), so a session of
     * streamed OpenAI turns now DOES report, and only a server that omits the
     * usage document — or the offline/echo paths — still reaches here empty.
     * The spend readout needs this because `$0.0000` would otherwise be
     * printed for "we have no idea" as confidently as for "you have spent
     * nothing". A priced-0-but-blind turn is a THIRD answer, kept distinct by
     * {@see Util\TokenTracker::hasUnpricedUsage()} rather than by this flag:
     * an unpriced model DID report tokens (so this reads true), it just has no
     * rate to multiply them by.
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
        return $this->spendLedger()->hasReported($this->tokenTracker);
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
        return $this->spendLedger()->capReached($this->tokenTracker, $this->maxCostUsd);
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
        return \SugarCraft\Crush\Host\SpendLedger::isUsableCap($cap);
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

        return $this->spendCapTurnRefusal(\SugarCraft\Crush\Host\SpendLedger::CROSSED_BY_PREVIOUS_TURN);
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
        $notice = $this->spendLedger()->refusalNotice($this->tokenTracker, (float) $this->maxCostUsd, $crossing);

        return [$this->mutate([
            // The draft is KEPT: the user's prompt was never sent, and clearing
            // the box would lose it to a refusal they may well answer by
            // raising the cap.
            'history' => [...$this->history, Message::assistant($notice)->withUiOnly()],
            'inFlight' => false,
        ]), null];
    }

    /**
     * Settle the run of automatic-tier compactions after one attempt — the circuit
     * breaker's arithmetic in one place, because TWO ROUTES do it and a second copy
     * is how the two would drift into counting different things.
     *
     * Both arguments come from the attempt itself. `$stillOverTier` is the
     * measurement, not the counter: did this rewrite leave us at or over the number
     * that asked for it, judged with the estimate-and-window pair the tier itself
     * used. `$turnSent` is the outcome: did the prompt this tier was protecting
     * actually reach the model. Three transitions, and the third is the whole point
     * of separating them:
     *
     * - under tier → BREAK to zero. The compaction worked, which is what makes the
     *   breaker self-restoring: nothing is latched by hand and no second key needs
     *   releasing beside it.
     * - over tier, turn NOT sent → EXTEND. This is the loop §4.23 shipped: the
     *   rewrite bought nothing, the user's prompt sat unsent, and the next prompt
     *   will ask for the same rewrite again and get the same nothing.
     * - over tier, turn sent → HOLD, unchanged. The tier is expensive and unglamorous
     *   here, but it DID get the prompt out, and the run exists to count futility.
     *
     * The third line is ruling P8.S5-R6, verbatim: "§4.23's breaker stops a FUTILE
     * refill loop; a rescued dispatch (truncateOversizedExchange path, E18) is NOT
     * futile — each rescue truncates further and the turn goes out, which is the
     * shipped, pinned P4.S4 UX (ContextCompactorTest::testAnOversizedExchangeStopsBeingRefusedAndTheWireReallyGetsShorter() 'an exchange that cannot
     * fit must be truncated, not re-refused'). Therefore: a compaction attempt whose
     * outcome is a rescued dispatch carries the counter UNCHANGED on BOTH routes
     * (parked-landing rescue + sync rescue branches). The breaker keeps authority
     * exactly where refill means 'no path forward' (the 95% blocking cascade).
     * Candidate 2 rejected: overriding a pinned in-repo guarantee via §1.11 waiver on
     * an out-of-ceiling file, re-opening shipped UX, to police a route that makes
     * monotone progress, is the wrong trade."
     *
     * The ruling's wording names the RESCUE branches, and the rescue exits do carry
     * the run unchanged by returning the state that never took the write at all
     * (`$turnCarrier` left at `$this` in {@see submit()}, `$compacted` before any
     * write in {@see applyModelCompaction()}, which applies this method at its other
     * three exits instead). Measured on this branch, exempting the rescue
     * alone moved the pinned E18 drive's refusal from attempt 4 to attempt 5 and left
     * it red: after the first rescue the oversized exchange sits in the recent window
     * permanently, so attempts 2-4 re-enter the 85% tier, get no rescue (nothing is
     * individually oversized any more — the truncation already happened), and go out
     * over the tier at 93,126 → 93,149 → 93,172. Those are the same three facts the
     * rescue case is, so they take the same answer, and HOLD is what makes the
     * ruling's own criterion — "refill means no path forward" — true of the code
     * rather than only of its example.
     *
     * What the probe measured, so the quotation is not read as a growth claim: on a
     * 100,000-token window with one 800,000-character exchange the wire the rescued
     * turns went out on read 93,115 then 93,138 then 93,161 — under the 95,000
     * blocking tier every time, creeping up only by the ~12 tokens each new turn
     * adds. The rescue re-applies to the same exchange rather than shrinking it
     * further; what makes it non-futile is that the prompt reaches the model.
     */
    private function withCompactionOutcome(bool $stillOverTier, bool $turnSent): self
    {
        return $this->mutate([
            'consecutiveRefillCompactions' => $this->compactionService()->refillCount(
                $this->consecutiveRefillCompactions,
                $stillOverTier,
                $turnSent,
            ),
        ]);
    }

    /**
     * Refuse the automatic compaction tier because it has thrashed: this many
     * compactions in a row each put the context straight back over the tier AND
     * each ended with the turn unsent — a rescued dispatch does not count, and so
     * can never reach this method (ruling P8.S5-R6).
     * (prompt_expand.md §4.23 — upstream shipped exactly this loop and fixed it
     * with "detects when context refills to the limit immediately after compacting
     * three times in a row and stops with an actionable error instead of burning
     * API calls").
     *
     * The shape is {@see spendCapTurnRefusal()}'s on purpose — a refusal that stops
     * spending money keeps the draft and appends one report, and the two differ in
     * KIND and not in mechanics: the cap is a ceiling the user set and can lift with
     * a command, this is a property of the transcript, which no command lifts.
     *
     * ROLE DIFFERS from that sibling, and deliberately: it appends `Role::System`
     * where the cap refusal appends `Role::assistant`. Every other message this tier
     * writes is Role::System ({@see scheduleParkedCompaction()}'s notice,
     * {@see contextCompactedMessage()}, {@see contextReminderMessage()}) for the
     * reason those docblocks give — nothing after the user's echoed prompt renders
     * as an assistant turn, and a Role::Assistant message there is a PREFILL the
     * provider continues. This refusal sits on the same route as those, beside the
     * same transcript, so it takes the same role; the cap refusal answers a typed
     * prompt on a route that has appended no echo at all, where an assistant turn is
     * what the user sees an answer as.
     *
     * The notice names no percentage: the tier's numbers are config the user did not
     * set and cannot read off a transcript line, while what they CAN act on is the
     * size of the recent window the compaction preserves. Nor does it name
     * `/compact`: the breaker does not stop that command, and telling a user whose
     * context will not shrink to run the command that will not shrink it again would
     * be the least useful sentence available. `inFlight` is written false for the
     * same defensive reason the cap refusal gives — on this route it already is
     * false, and a refusal that leaves the flag set is a wedged session.
     *
     * @return array{0:self,1:?\Closure}
     */
    private function thrashBreakerRefusal(): array
    {
        $notice = $this->compactionService()->thrashBreakerNotice();

        return [$this->mutate([
            // The draft is KEPT by NOT writing the input box, exactly as the cap
            // refusal keeps it: nothing was sent, and the fix for this refusal is a
            // decision about the transcript the user has to make while looking at the
            // prompt they wanted sent.
            'history' => [...$this->history, Message::notice($notice)],
            'inFlight' => false,
        ]), null];
    }

    /**
     * The soft, non-blocking context-usage reminder {@see dispatchTurn()}
     * appends whenever {@see ContextCompactor::shouldSendReminder()} fires —
     * {@see \SugarCraft\Crush\Host\CompactionService::contextReminderMessage()}.
     */
    private function contextReminderMessage(int $tokenCount): Message
    {
        return \SugarCraft\Crush\Host\CompactionService::contextReminderMessage($tokenCount);
    }

    /**
     * Drop every context-usage reminder from a history —
     * {@see \SugarCraft\Crush\Host\CompactionService::withoutContextReminders()}, which {@see dispatchTurn()}
     * calls on EVERY dispatch so history carries at most one copy.
     *
     * @param list<Message> $history
     * @return list<Message>
     */
    private static function withoutContextReminders(array $history): array
    {
        return \SugarCraft\Crush\Host\CompactionService::withoutContextReminders($history);
    }

    /**
     * Whether $msg is one of {@see contextReminderMessage()}'s own products —
     * {@see \SugarCraft\Crush\Host\CompactionService::isContextReminder()}.
     */
    private static function isContextReminder(Message $msg): bool
    {
        return \SugarCraft\Crush\Host\CompactionService::isContextReminder($msg);
    }

    /**
     * Rebuild a `list<Message>` from a compacted wire, reusing the original
     * objects for every row compaction preserved and hiding, not deleting, the
     * rows it replaced — {@see \SugarCraft\Crush\Host\CompactionService::messagesFromWire()}.
     *
     * @param array<array{role:string,content:string}> $wire
     * @param list<Message> $original
     * @return list<Message>
     */
    private function messagesFromWire(array $wire, array $original): array
    {
        return \SugarCraft\Crush\Host\CompactionService::messagesFromWire($wire, $original);
    }

    /**
     * The wire a {@see ContextCompactor} is handed: $history's AGENT-VISIBLE
     * rows only, tool rows keyed — {@see \SugarCraft\Crush\Host\CompactionService::compactionWire()}.
     *
     * @param list<Message> $history
     * @return list<array{role:string,content:string,tool?:array{name:string,arguments:array<string,mixed>,error:bool}}>
     */
    private static function compactionWire(array $history): array
    {
        return \SugarCraft\Crush\Host\CompactionService::compactionWire($history);
    }

    /**
     * How many attempts have run into the 95% blocking tier since the
     * conversation last got a turn out — {@see \SugarCraft\Crush\Host\CompactionService::blockedAttempts()}.
     *
     * @param list<Message> $history
     */
    private static function blockedAttempts(array $history): int
    {
        return \SugarCraft\Crush\Host\CompactionService::blockedAttempts($history);
    }

    /**
     * The compactor a compaction of $history runs with: this session's, with
     * {@see blockedAttempts()} fewer exchanges preserved —
     * {@see \SugarCraft\Crush\Host\CompactionService::attemptCompactor()}.
     *
     * @param list<Message> $history
     */
    private function attemptCompactor(array $history): ContextCompactor
    {
        return $this->compactionService()->attemptCompactor($this->compactor, $history);
    }

    /**
     * The row a compaction leaves between the rows it condensed and the rows it
     * preserved (roadmap 1.B-3) — {@see \SugarCraft\Crush\Host\CompactionService::COMPACTION_BOUNDARY}, named
     * here too because {@see Renderer} and the transcript tests read it off Chat.
     */
    public const COMPACTION_BOUNDARY = \SugarCraft\Crush\Host\CompactionService::COMPACTION_BOUNDARY;

    /**
     * Whether $message is the boundary row a compaction wrote —
     * {@see \SugarCraft\Crush\Host\CompactionService::isCompactionBoundary()}.
     */
    public static function isCompactionBoundary(Message $message): bool
    {
        return \SugarCraft\Crush\Host\CompactionService::isCompactionBoundary($message);
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
     *  - Retrying works, eventually. Every attempt since the last turn that got
     *    out - each refusal, and each `/compact` typed after one - makes the
     *    next compaction preserve one exchange fewer ({@see blockedAttempts()}),
     *    so the history really does shrink per attempt. On 13 equal exchanges
     *    of ~10,000 estimated tokens against an 88,000-token window the second
     *    retry goes out with eight preserved. The dead end is a SINGLE
     *    exchange bigger than the tier, not a large history.
     *  - `/compact` is such an attempt too and compacts with the reduced
     *    window itself, so after it the next retry goes out on that fixture.
     *  - `/clear` frees everything at once.
     *
     *    THE SHRINK USED TO BE AN ACCIDENT of the compactor reading UI-only
     *    rows: the refusal's echo/refusal pair and `/compact`'s own pair counted
     *    as exchanges and pushed a real one out of the preserved ten. Since the
     *    compactor reads agent-visible rows only (audit 15b-03-rem(a)) those
     *    rows count for nothing, and without the explicit count this refusal's
     *    advice would have become false - every retry refused against the same
     *    estimate.
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
     * @param int $tokenCount ESTIMATED tokens (script-weighted proxy) in $history.
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
        $next = $this->mutate([
            'history' => $this->compactionService()->foregroundBlockedRows(
                $inputText,
                $history,
                $tokenCount,
                $tokenLimit,
                $compactionNotice,
            ),
            'inputBuf' => '',
            'inFlight' => false,
            'lastActivityAt' => new \DateTimeImmutable(),
        ]);

        return [$next, null];
    }

    /**
     * The notice the 85% tier leaves behind after rewriting history under the
     * user — {@see \SugarCraft\Crush\Host\CompactionService::contextCompactedMessage()}, which says why it is
     * Role::System and why it rides before the user turn.
     */
    private function contextCompactedMessage(
        int $beforeMessages,
        int $afterMessages,
        int $savedPercentage,
        int $tokenCount,
        int $tokenLimit,
    ): Message {
        return \SugarCraft\Crush\Host\CompactionService::contextCompactedMessage($beforeMessages, $afterMessages, $savedPercentage, $tokenCount, $tokenLimit);
    }

    /**
     * The INTRA-exchange rescue of the 95% blocking tier (prompt_plan.md P4.S4,
     * backlog §12.2 E18) over this session's compactor and calibrated estimate —
     * see {@see \SugarCraft\Crush\Host\CompactionService::intraExchangeTruncation()} for what it truncates, why it
     * splices rather than rebuilds, and the alignment contract both call sites
     * ({@see submit()} and {@see applyModelCompaction()}) keep.
     *
     * @param array<array{role:string,content:string}> $wire
     * @param list<Message> $baseHistory
     * @return array{history: list<Message>, notice: Message}|null
     */
    private function intraExchangeTruncation(array $wire, array $baseHistory, int $tokenLimit): ?array
    {
        return $this->compactionService()->intraExchangeTruncation(
            $this->compactor,
            $wire,
            $baseHistory,
            $tokenLimit,
            $this->estimateTokenCount(...),
        );
    }

    /**
     * A copy of $message with $content swapped in and every other field carried
     * across — {@see \SugarCraft\Crush\Host\CompactionService::messageWithContent()}, whose field list is the
     * maintenance contract `CompactionHidesNotDeletesTest` checks.
     */
    private static function messageWithContent(Message $message, string $content): Message
    {
        return \SugarCraft\Crush\Host\CompactionService::messageWithContent($message, $content);
    }

    /**
     * The notice the intra-exchange rescue writes when truncating oversized
     * messages lets a turn through — {@see \SugarCraft\Crush\Host\CompactionService::contextTruncatedMessage()},
     * whose docblock carries the wording contract and the tests that pin it.
     */
    private function contextTruncatedMessage(int $truncatedMessages, int $tokenCount, int $tokenLimit): Message
    {
        return \SugarCraft\Crush\Host\CompactionService::contextTruncatedMessage($truncatedMessages, $tokenCount, $tokenLimit);
    }

    /**
     * The notice the settle arm writes when a reply stopped at the provider's
     * OUTPUT ceiling - E707 (round 81), the sibling of
     * {@see contextTruncatedMessage()} one side of the request further out:
     * that one reports text dropped going IN, this one reports a reply cut
     * short coming OUT.
     *
     * It names the knob and where it lives, because the actionable difference
     * between the two failures is that this one has a setting an operator can
     * raise (see `maxOutputTokens` in docs/SETTINGS.md) while a context
     * overflow does not. It states the uncertainty plainly - the reply may or
     * may not have finished its thought - rather than dressing a silent
     * truncation up as a completed turn, which is the exact defect this notice
     * exists to close.
     */
    private function outputLengthStoppedNotice(): Message
    {
        return Message::notice(
            'The provider stopped this reply at its output limit, so the text above may end mid-thought. '
            . 'Raise "maxOutputTokens" in ~/.sugar-crush/config.json for a longer single reply, '
            . 'or ask for the remainder in your next message.'
        );
    }

    /**
     * The notice the settle arm writes when the app's OWN step ceiling ended
     * the turn before the model finished - F2 (spawn-latency plan), the
     * sibling of {@see outputLengthStoppedNotice()} on the harness side of the
     * same silent-truncation family: that one reports a reply the PROVIDER cut
     * short, this one reports a loop THIS app stopped. Before F2 this exit was
     * silent, which is the defect the notice closes.
     *
     * Like its siblings it names the knob an operator holds (`maxToolSteps`,
     * see docs/SETTINGS.md) and the one action that costs nothing - asking
     * again resumes where the ceiling stopped, because the transcript keeps
     * every settled step.
     *
     * THE WORDING FOLLOWS WHAT THE REPLY NOW IS (wave 9 follow-up of
     * w8a-steps). It used to say the turn stopped "with tool results still
     * pending" and that "the answer above is incomplete". Since the budget
     * exit makes one last no-tools request asking for what is done, what
     * remains and what comes next, the reply above is normally that summary —
     * nothing is pending, and calling it an incomplete answer misdescribes it.
     * "Describes where it stopped" is true of the summary and of the fallback
     * (the last step's prose, when the summary came back empty) alike.
     */
    private function stepsTruncatedNotice(): Message
    {
        return Message::notice(
            'This turn used all of its tool steps before the work was done, so the reply above describes where it '
            . 'stopped — what is finished and what remains — not a finished answer. Say "continue" to pick it up '
            . 'from there, or raise "maxToolSteps" in ~/.sugar-crush/config.json for longer agentic turns.'
        );
    }

    /**
     * The notice the settle arm writes when the repeat-call loop guard ENDED
     * the turn ({@see Backend\ToolCallLoopGuard::END_TURN_AT} identical calls
     * to $toolName, same arguments, same result). Distinct from
     * {@see stepsTruncatedNotice()} on purpose: raising `maxToolSteps` is the
     * wrong remedy for a model stuck repeating itself, so this names the loop
     * and the remedies that do help. The reply above is the no-tools summary
     * EngineBackend asks for on this exit, as on the budget one.
     *
     * $toolName is the model-chosen call name, so it is quoted through
     * {@see quoteDraftForNotice()} like any other untrusted text in a notice.
     */
    private function loopGuardStoppedNotice(string $toolName): Message
    {
        return Message::notice(sprintf(
            'This turn was ended by the repeat-call loop guard: the model called %s %d times with the same arguments '
            . 'and got the same result each time. The reply above describes where it stopped. Point it at a different '
            . 'approach, or say "continue" once something has changed.',
            self::quoteDraftForNotice($toolName),
            Backend\ToolCallLoopGuard::END_TURN_AT,
        ));
    }

    /**
     * The notice the settle arm writes when a turn arrived from a model this
     * app has NO price on file for — the billing fix's loud-unknown, the
     * sibling of {@see outputLengthStoppedNotice()} one seam further out:
     * that one reports a reply cut short, this one reports a bill it could
     * not compute.
     *
     * WHY IT EXISTS: the fabricated $0.01/1k fallback this replaced invented
     * dollars for ANY unknown model; simply dropping it would have made the
     * same hole silent, because `0.0` in the budget readout is BOTH "free"
     * and "unpriced" ({@see Usage}). The notice names the model, says which
     * of the two it is NOT, and points at the ONE remedy an operator holds —
     * the user-tier `modelPrices` map (see `modelPrices` in
     * docs/SETTINGS.md) — while being honest that the cap arithmetic kept
     * running on a LOWER BOUND meanwhile. Emitted at most once per settled
     * turn, inside the settle, so it persists exactly like every notice on
     * this route.
     */
    private function unpricedModelNotice(string $model): Message
    {
        return Message::notice(
            'This app has no price on file for model "' . $model . '", so this turn billed $0.00 '
            . 'as a lower bound, not as a free call — spend totals and the spend cap are under-counted '
            . 'until a rate exists. Declare one under "modelPrices" in ~/.sugar-crush/config.json '
            . '(USD per 1M tokens, keys "input" and "output") to price it.'
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

        // UI-only, both: the prompt was held back, not sent - see
        // foregroundBlockedResponse() for the same rule.
        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputText)->withUiOnly(), Message::assistant($response)->withUiOnly()],
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
        $afterTheme = self::commandArgument($inputText);

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
            'history' => [...$this->history, Message::user($inputText)->withUiOnly(), Message::assistant("Theme set to '{$afterTheme}'.")->withUiOnly()],
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
        return $this->runHostCommand(new \SugarCraft\Crush\Host\Commands\McpAuthHostCommand(), $inputBuf);
    }

    /**
     * Whether $text is the leading-slash-less `mcp auth …` command spelling.
     *
     * `mcp` and `auth` must each be WHOLE words: a raw `str_starts_with($text,
     * 'mcp auth')` used to claim prose like "mcp authentication keeps failing on
     * my server, why?" — idle it ran the handler ("Unknown sub-command
     * 'authentication'") instead of asking the model, and mid-turn it refused the
     * draft instead of queueing it (audit 15b-11). Any whitespace run separates
     * the words because {@see parseMcpArgs()} tokenises on `\s+`, so every draft
     * claimed here reduces to the argv the handler expects. One helper serves
     * both {@see submit()}'s mid-turn refusal and {@see dispatchCommand()}'s idle
     * dispatch, so what is refused mid-turn and what runs idle cannot drift.
     * Delegates to {@see \SugarCraft\Crush\Host\TurnController::isBareMcpAuthCommand()},
     * which the mid-turn route and the headless host classify with.
     */
    private static function isBareMcpAuthCommand(string $text): bool
    {
        return \SugarCraft\Crush\Host\TurnController::isBareMcpAuthCommand($text);
    }

    /**
     * Split an MCP command line into {@see McpAuthCommand::execute()}'s argv.
     *
     * Both spellings reduce to the same argv: the leading command word is
     * dropped whether it is written `/mcp`, `/mcp:` or `mcp`, and the `auth` noun the
     * bare form spells out is optional under the slash form - `/mcp list`
     * and `mcp auth list` are the same command.
     *
     * @return list<string>
     */
    private static function parseMcpArgs(string $inputBuf): array
    {
        return \SugarCraft\Crush\Host\Commands\McpAuthHostCommand::arguments($inputBuf);
    }
}
