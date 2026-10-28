<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;
use SugarCraft\Code\App\AssistantMsg;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Backend\InteractiveTurn;
use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Backend\QueueMode;
use SugarCraft\Code\Background\ScheduleRegistry;
use SugarCraft\Code\App\BackendToolEventsMsg;
use SugarCraft\Code\App\BangShellResultMsg;
use SugarCraft\Code\Chat;
use SugarCraft\Code\CommandParser;
use SugarCraft\Code\Commands\BangShell;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\McpPromptCommands;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Commands\Specs\BuiltInCommands;
use SugarCraft\Code\Context\Compaction\HistoryFingerprint;
use SugarCraft\Code\Context\CompactorConfig;
use SugarCraft\Code\Context\ContextCompactor;
use SugarCraft\Code\Context\IdleCompactionPolicy;
use SugarCraft\Code\Diagnostics\NoticeSink;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Events\PermissionAsked;
use SugarCraft\Code\Events\PermissionResolved;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Events\ToolStarted;
use SugarCraft\Code\Goal\GoalJudge;
use SugarCraft\Code\Goal\GoalLoop;
use SugarCraft\Code\Goal\GoalState;
use SugarCraft\Code\App\GoalJudgedMsg;
use SugarCraft\Code\App\MemoryConsolidatedMsg;
use SugarCraft\Code\App\DreamPassCompletedMsg;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\App\HistoryCompactedMsg;
use SugarCraft\Code\App\PromptSuggestionMsg;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandEffect;
use SugarCraft\Code\Host\Commands\CommandEffectKind;
use SugarCraft\Code\Host\Commands\CommandResult;
use SugarCraft\Code\Host\Commands\CommandText;
use SugarCraft\Code\Host\ContextSources;
use SugarCraft\Code\Lang;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\Memory\AutoMemoryConsolidator;
use SugarCraft\Code\Memory\DreamPass;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionReply;
use SugarCraft\Code\Permissions\SafetyClassifier;
use SugarCraft\Code\Permissions\SessionPermissionMemo;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionLock;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\Usage;
use SugarCraft\Code\Util\TokenTracker;
use SugarCraft\Code\Workspace\AutoCommittedMsg;

/**
 * One open session, driven without a screen (roadmap O-2g, Appendix O §4.3).
 *
 * NOT A MODEL. {@see Chat} is the TUI's driver of a session; this is a
 * server's, an ACP adapter's or a test's. Both admit a submission through the
 * same {@see TurnController}, run the turn through the same
 * {@see TurnRunner}, fold its tool events through the same
 * {@see TranscriptProjector} and save through the same {@see TranscriptStore},
 * so the two cannot disagree about what a prompt becomes, which rows a turn
 * leaves or what the event log says about it. What this does NOT have is
 * everything that only exists because a person is looking: a draft, a cursor,
 * overlays, the live repaint.
 *
 * WHAT {@see submit()} DOES, in the TUI's order:
 * - mid-turn ({@see isBusy()}): {@see TurnController::midTurnRoute()} — a
 *   command is refused, a `!cmd` queued, anything else steered into the
 *   running turn (roadmap 1.C-3, and also held as a follow-up so it is never
 *   lost), queued, or — a host's own delivery — sent after cancelling the
 *   running turn;
 * - idle: a `!cmd` runs off this thread and holds the session until its
 *   row lands, as in the TUI; a command FILE is expanded exactly as the TUI
 *   expands it, off this thread when its body runs a shell (audit 15b-20); a
 *   built-in slash command runs through the same `Host\Commands` body the TUI
 *   runs (roadmap O-2h, {@see runCommand()}) — a screen-only one is answered
 *   with `CommandResult::clientOnly()`;
 *   the spend cap refuses; the automatic compaction tier compacts the
 *   history with the synchronous heuristic (roadmap 2.5) — the TUI's
 *   model-written route is not wired here yet — or refuses at the blocking
 *   tier; the two turn-lifecycle hooks fire gate-first, off this thread when
 *   a hook leaves the process (audit 15b-04); the prompt's mentions are
 *   attached; and the turn is dispatched with its `/rewind` checkpoint, the
 *   picker's turn count, its `message.created` and its 70% reminder.
 *
 * THE LOOP OWNS NOTHING HERE THAT IS NOT ON IT. A turn settles through its
 * backend's promise, so a host on a ReactPHP loop never blocks; the turn's
 * live events sit on this host's inbox until {@see pump()} folds them (a
 * server calls it on its own tick) and whatever is left folds when the turn
 * settles. A queued prompt goes out as soon as the turn before it settles.
 *
 * QUESTIONS ARE ANSWERED HERE, NOT IN A MODAL (roadmap O-3b, Appendix O §5.3,
 * §6.7). A turn's {@see PermissionAsked} carries the {@see PendingAsk} the
 * child is blocked on; this host keeps it, by `askId`, until it is settled,
 * so any client — the first to answer — can reach the child through
 * {@see answer()}, and a client that reconnects is handed the questions still
 * open ({@see pendingAsks()}). An `always` becomes this session's grant for
 * every later turn, as the TUI's does, and a session may run in a permission
 * mode of its own ({@see setPermissionMode()}) without touching the
 * workspace's gate.
 *
 * MUTABLE ON PURPOSE, like {@see TurnRunner}: a host is the live state of one
 * session (its rows, its queue, its turn in flight), not a value, and
 * {@see SessionHub} owns one per open session.
 */
final class SessionHost
{
    /** @var list<Message> */
    private array $history;

    /** @var list<string> prompts waiting behind the running turn */
    private array $queue = [];

    /** The dispatch in flight, or the token a parked submission holds. */
    private ?CancellationToken $turn = null;

    private int $generation = 0;

    /** Whether the work holding {@see $turn} is a workflow run (`/workflow pause|status` may still run). */
    private bool $workflowTurn = false;

    /**
     * Whether {@see self::release()} has run (C-CTX-2.c): a settle work item
     * that answers AFTER the session closed is still billed — the provider
     * call was really made and the spend belongs to the ledger — but its
     * transcript row has no session left to land in, so it is dropped.
     */
    private bool $released = false;

    /** Thrash breaker's run of refilled compactions (roadmap 2.10 / §4.23). */
    private int $consecutiveRefills = 0;

    /**
     * When the last prompt was accepted (ISS-064): the anchor for the idle
     * compaction advisory in {@see submit()}. Null until this process's first
     * prompt, so a cold session never advises.
     */
    private ?\DateTimeImmutable $lastPromptAt = null;

    /**
     * When the last turn settled (C-CTX-6.a): the second clock of the idle
     * watch. A session whose turn just ended has NOT been idle since the
     * prompt that started it — the fold verdict measures from whichever of
     * the two stamps is later, the served mirror of the TUI tick re-deriving
     * idleness from its activity clock at every check.
     */
    private ?\DateTimeImmutable $lastSettledAt = null;

    /**
     * The ahead-of-need summary of roadmap 2.10, host edition (ISS-064): the
     * mirrors the TUI's `$backgroundSummary` — requested beside the turn at
     * the 70% reminder tier so the 85% tier can splice model-written records
     * instead of waiting on a round-trip. `summaries` is null while the call
     * is out and `[]` when it came back unusable — held as a failure so one
     * cycle does not re-ask a failing summarizer every turn, dropped as soon
     * as the reminder tier stops firing or the fingerprint moves.
     * `safeguardHold` carries a CTX-4 HOLD verdict's detail so the tier that
     * lands the heuristic fold can say why; the held summaries themselves are
     * discarded by the settle below and never spliced.
     *
     * @var null|array{id:string,fingerprint:HistoryFingerprint,summaries:?array<string,string>,safeguardHold:?string}
     */
    private ?array $aheadOfNeed = null;

    /**
     * The CTX-5 idle auto-compaction watch, served-host edition. The TUI
     * re-derives its verdict from a periodic tick because it has a keystroke
     * stream to notice; this host has two clocks that matter — the instant
     * the last prompt was accepted and the instant the last turn settled
     * (C-CTX-6.a) — so the whole watch is one alarm, re-armed from whichever
     * stamp is later: on every accepted prompt, on every settle, and on an
     * alarm that fires into a running turn instead of being lost with it.
     * Every condition lives in
     * the callback ({@see onIdleAutoCompaction()}), which fires a pure state
     * check and never aborts work in flight — the E646 law, unchanged: the
     * fold is a money decision, and a timer is allowed to re-ask whether
     * the money is worth spending, not to kill a request already made.
     */
    private ?TimerInterface $idleAutoTimer = null;

    /** E17 calibration of the token estimate, and the raw proxy it pairs with. */
    private ?float $calibration = null;

    private ?int $estimateAtDispatch = null;

    /** @var \ArrayObject<int, array{0: int, 1: object}> the turn's live inbox */
    private readonly \ArrayObject $inbox;

    private readonly TokenTracker $spend;

    private readonly ContextCompactor $compactor;

    /**
     * WEB-2: this session's `/model` override, built by
     * {@see \SugarCraft\Code\Host\Commands\ModelHostCommand} from the
     * workspace's own provider factory. Null until a served switch happens;
     * never written to settings, so the NEXT launch is unaffected — the same
     * session-scoped life {@see self::$permissionMode} gives a mode change.
     */
    private ?Backend $sessionBackend = null;

    /**
     * C-WEB-1.a: the `{provider, model}` pair a served `/model` switched to,
     * recorded beside the {@see self::$sessionBackend} it built. Null while
     * the session still runs the launch row's pair. The store row is never
     * rewritten by a served switch — W7a-2's law keeps the provider axis
     * unpersisted on both surfaces — so a summary read from the row alone
     * would keep naming the pair the web's picker already replaced.
     *
     * @var null|array{provider: string, model: string}
     */
    private ?array $modelOverride = null;

    /**
     * C-WEB-1.a: hears "this session's summary just changed" from the lanes
     * that change it without a status transition — a served `/model` or
     * `/rename`. The server's feed binds it while a session is followed and
     * clears it with the feed; where nothing binds it (the terminal) nothing
     * extra is announced, exactly as before.
     *
     * @var (\Closure(): void)|null
     */
    private ?\Closure $onSummaryChanged = null;

    /** @var array<string, CommandSpec> */
    private readonly array $customCommands;

    /**
     * This host's MCP client as a lazy provider (C-MCP-2.a), shaped exactly
     * like Chat's — resolved when a mention or a prompt command actually
     * needs it, never when this closure is merely built. A null answer is
     * the honest state of a launch that trusted no MCP config; every
     * consumer turns it into its own refusal.
     *
     * @var \Closure(): ?McpClient
     */
    private readonly \Closure $mcpProvider;

    /** @var array<string, PendingAsk> the turn's open questions, by askId */
    private array $pendingAsks = [];

    /**
     * How recent questions were settled, by askId — what a late second
     * answer is told it lost to. Bounded: only the newest are kept.
     *
     * @var array<string, PermissionResolved>
     */
    private array $resolutions = [];

    /** The session's "always" answers, handed to every later turn's gate. */
    private SessionPermissionMemo $grants;

    /**
     * C-CTX-2.a: the session's own runtime-notice inbox.
     *
     * serve arms the process-wide sink in `Bootstrap::workspace()` and only
     * ever drains it through {@see Chat} — which a served session has no
     * instance of — so a row recorded inside a turn's forked child used to
     * vanish. The host keeps a sink per session instead, armed exactly when
     * the workspace's was (a test or embedder that never armed one keeps
     * dropping, byte-identically), and every fork the host starts runs under
     * {@see RuntimeNoticeSink::using()} so the child's records land HERE. The
     * drain is wake-driven — the sink's one-shot loop watcher — plus a fold
     * at every {@see pump()} and at settle; no timer is minted anywhere
     * (E646). {@see release()} closes the pair of socket fds with the host.
     */
    private readonly NoticeSink $notices;

    /** The mode this session's turns run in; null is the workspace gate's. */
    private ?PermissionMode $permissionMode = null;

    /** How many settled questions {@see resolution()} remembers. */
    private const RESOLUTIONS_KEPT = 64;

    /**
     * The built-ins whose accepted answer rewrites what the prompt is built
     * from, so their result earns a `context.changed` announce (C-CTX-2.d):
     * `/rules` toggles a rule pack in the shared RulesState, `/memory` writes
     * a note to a memory store, `/init` sets an instruction file in motion.
     * Keyed on the dispatched name because these three answer with plain
     * reply rows no effect kind marks.
     */
    private const CONTEXT_CHANGING_COMMANDS = ['rules', 'memory', 'init'];

    /**
     * @param list<Message> $history
     * @param array<string, CommandSpec>|null $customCommands
     */
    private function __construct(
        private readonly string $sessionId,
        private readonly WorkspaceContext $workspace,
        private readonly TranscriptStore $transcripts,
        private ?SessionLock $lease,
        array $history,
        ?CompactorConfig $compactorConfig,
        ?array $customCommands,
        ?\Closure $mcpProvider,
    ) {
        $this->history = array_values($history);
        $this->inbox = new \ArrayObject();
        // The session's own "always" answers, saved with it (Chat does the same).
        $this->grants = SessionPermissionMemo::fromGrants($transcripts->loadPermissionGrants($sessionId));
        // SES-1b: serve adopts the durable ledger the way the interactive
        // launch adopts it (the `tokenTracker:` seam in Bootstrap::chat()) —
        // a host over a session whose usage_records hold rows must not show
        // $0 to its live surfaces (spentUsd(), the cap checks) while the
        // session's cost columns say otherwise. An empty or absent ledger —
        // a fresh session, or a base store without the table — keeps the
        // zero tracker, byte-identical to every host built before the table.
        $store = $workspace->sessionStore;
        $this->spend = $store instanceof EnhancedSessionStore
            ? ($store->resumedTokenTracker($sessionId) ?? new TokenTracker())
            : new TokenTracker();
        $this->compactor = new ContextCompactor($compactorConfig ?? CompactorConfig::new());
        $root = $this->root();
        // C-MCP-2.a: the same lazy shape Chat's mcpClientProvider() carries —
        // on serve, where the workspace's tool build has already memoised
        // this process's client, this resolves to that memo; where nothing
        // is trusted it answers null, and every consumer below (the merged
        // rows, the forked fetch, the mention reader in dispatch()) turns a
        // null into its own honest refusal. Injectable for the surface test.
        $this->mcpProvider = $mcpProvider
            ?? static fn (): ?McpClient => Bootstrap::mcpClient($root);
        // C-SEC-4.a: the launch's trust answer rides in here exactly as it
        // rides the TUI load (the `projectCommandsTrusted:` seam in Chat's
        // constructor), so a granted project's built-in override is a command
        // row over serve and the web too — and an ungranted one is refused.
        // C-MCP-2.a: the served command table carries the MCP prompt rows
        // UNDER those disk rows, merged by the one shared precedence helper
        // Chat's own mergeCustomCommandRows() is spelled against — a disk
        // file of the same name keeps its shadow here exactly as on the TUI.
        $this->customCommands = McpPromptCommands::mergeUnderDisk(
            McpPromptCommands::specs($this->mcpProvider),
            $customCommands ?? array_filter(
                $workspace->commandLoader?->loadAll($root, $workspace->projectCommandsTrusted) ?? [],
                static fn (CommandSpec $spec): bool => $spec->isFileBased(),
            ),
        );
        // C-CTX-2.a: adopt the launch's arm state, never invent one. The
        // process sink is armed by Bootstrap::workspace() on serve; a test
        // or embedder that never armed it (or a workspace handed a fresh
        // unarmed sink) leaves this host dropping notices exactly as every
        // host built before this step did. Armed here means this process is
        // the reader — the child a turn forks pins to this sink through the
        // using() windows in dispatch(), requestAheadOfNeed() and
        // scheduleGoalJudge() and writes rows back over its own transport.
        $this->notices = NoticeSink::new();
        if ($workspace->notices->isArmed() && $this->notices->arm()) {
            $this->armNoticeWake();
        }
    }

    /**
     * A host for $sessionId over $workspace.
     *
     * @param list<Message> $history the session's transcript as loaded
     * @param TranscriptStore|null $transcripts where it is saved; null takes the
     *        workspace's registered one when it stores into the workspace's
     *        session store, else one over that store
     * @param SessionLock|null $lease the lock this host holds on the session,
     *        released by {@see release()}
     * @param array<string, CommandSpec>|null $customCommands the session's
     *        command files; null loads them from the workspace's loader
     * @param (\Closure(): ?McpClient)|null $mcpProvider this host's MCP client
     *        as a lazy provider (C-MCP-2.a); null takes the workspace root's
     *        launch-memoised client over {@see Bootstrap::mcpClient()}, the
     *        same shape Chat's mcpClientProvider() carries
     */
    public static function new(
        string $sessionId,
        WorkspaceContext $workspace,
        array $history = [],
        ?TranscriptStore $transcripts = null,
        ?SessionLock $lease = null,
        ?CompactorConfig $compactorConfig = null,
        ?array $customCommands = null,
        ?\Closure $mcpProvider = null,
    ): self {
        if (trim($sessionId) === '') {
            throw new \InvalidArgumentException('A session host needs a non-empty session id.');
        }

        return new self(
            $sessionId,
            $workspace,
            $transcripts ?? self::transcriptsFor($workspace),
            $lease,
            $history,
            $compactorConfig,
            $customCommands,
            $mcpProvider,
        );
    }

    /** The store a host saves through when none was named. */
    public static function transcriptsFor(WorkspaceContext $workspace): TranscriptStore
    {
        $registered = $workspace->service(TranscriptStore::class);
        if ($registered instanceof TranscriptStore && $registered->store() === $workspace->sessionStore) {
            return $registered;
        }

        return TranscriptStore::new($workspace->sessionStore);
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * The session's command map — the workspace's file rows merged under the
     * launch-frozen MCP prompt rows (C-MCP-2.b: `command.list` and
     * `command.exec` answer from exactly this map, the same table submit()
     * resolves, so a listing can never promise a row exec will refuse).
     *
     * @return array<string, CommandSpec>
     */
    public function customCommands(): array
    {
        return $this->customCommands;
    }

    /** The session's todo list (roadmap 3.C), as its `Todo` tool last wrote it. */
    public function todos(): \SugarCraft\Code\Todo\TodoList
    {
        return $this->runner()->todos($this->transcripts, $this->sessionId);
    }

    public function workspace(): WorkspaceContext
    {
        return $this->workspace;
    }

    /** @return list<Message> */
    public function history(): array
    {
        return $this->history;
    }

    /** @return list<string> */
    public function queued(): array
    {
        return $this->queue;
    }

    /** Whether a turn (or a submission parked behind off-thread work) holds this session. */
    public function isBusy(): bool
    {
        return $this->turn !== null;
    }

    /** The id of the turn in flight, when the runner started one. */
    public function turnId(): ?string
    {
        return $this->runner()->turnIdOf($this->turn);
    }

    /** What the session has spent, as the provider reported it. */
    public function spentUsd(): float
    {
        return $this->ledger()->spent($this->spend);
    }

    /** The session as a client first sees it ({@see SessionSnapshot}). */
    public function snapshot(): SessionSnapshot
    {
        $lastSeq = 0;
        try {
            $lastSeq = $this->transcripts->events()?->latestSeq($this->sessionId) ?? 0;
        } catch (\Throwable) {
            // A snapshot never fails on its log; a client resyncs from 0.
        }

        return SessionSnapshot::new(
            $this->sessionId,
            $this->history,
            $this->queue,
            $this->isBusy(),
            $this->turnId(),
            $this->spentUsd(),
            $lastSeq,
            // SES-1 (≥ law): the spend figure's companion bit, straight off
            // the same tracker spentUsd() reads, so the pair cannot disagree.
            $this->spend->hasUnpricedUsage(),
        );
    }

    /**
     * Hear this session's events — durable ones once the log numbered them,
     * ephemeral ones live ({@see TurnRunner::listen()}). Returns the closure
     * that detaches the listener.
     *
     * @param \Closure(SessionEvent): void $listener
     * @return \Closure(): void
     */
    public function onEvent(\Closure $listener): \Closure
    {
        $sessionId = $this->sessionId;

        return $this->runner()->listen(static function (SessionEvent $event) use ($listener, $sessionId): void {
            if ($event->sessionId === $sessionId) {
                $listener($event);
            }
        });
    }

    /**
     * C-WEB-1.a: the `{provider, model}` pair this session's served `/model`
     * switched to, or null while it still runs the launch row's pair. Lives
     * for exactly as long as {@see self::$sessionBackend} does — the host
     * dies, the override dies with it, and the next open resolves the saved
     * choice through the launch's own layers.
     *
     * @return null|array{provider: string, model: string}
     */
    public function modelOverride(): ?array
    {
        return $this->modelOverride;
    }

    /**
     * C-WEB-1.a: bind (or pass null to clear) the hook that hears a summary
     * change with no status transition behind it — {@see self::$onSummaryChanged}.
     *
     * @param (\Closure(): void)|null $hook
     */
    public function onSummaryChanged(?\Closure $hook): void
    {
        $this->onSummaryChanged = $hook;
    }

    /** The session's durable event log, or null when its store keeps none. */
    public function events(): ?EventLog
    {
        return $this->transcripts->events();
    }

    /**
     * Record $event for this session through the path a turn's own events
     * take — logged first when durable, then heard by every listener — for
     * the facts only a driver knows (a prompt queued, the session's status).
     * Returns the event as heard, or null when a durable write failed.
     */
    public function announce(SessionEvent $event): ?SessionEvent
    {
        return $this->runner()->announce($event, $this->transcripts, $this->sessionId);
    }

    /**
     * The permission mode this session's turns run in: the one set by
     * {@see setPermissionMode()}, else the workspace gate's, else null when
     * the backend has no gate at all.
     */
    public function permissionMode(): ?PermissionMode
    {
        return $this->permissionGate()?->mode();
    }

    /**
     * Run this session's NEXT turns in $mode (null: the workspace gate's).
     * The turn in flight keeps the gate it was forked with. Whether a client
     * may choose $mode is the caller's question (`ServerConfig::admitsPermissionMode()`).
     */
    public function setPermissionMode(?PermissionMode $mode): void
    {
        $this->permissionMode = $mode;
    }

    /**
     * The gate this session is consented by — the same resolution
     * {@see backend()} forks onto its turn backend: the session's own mode
     * over the base rules when it names one, else the base gate itself.
     * Out-of-turn side-effect surfaces (`generate.submit`) must consult
     * this, not the bare workspace gate, or a bypass-admitted session would
     * run tools freely in-turn yet be refused at the RPC door (R15 MAJOR-1).
     */
    public function permissionGate(): ?PermissionGate
    {
        return $this->sessionGate($this->basePermissionGate());
    }

    /** The session's remembered "always" answers. */
    public function grants(): SessionPermissionMemo
    {
        return $this->grants;
    }

    /**
     * The questions the running turn is blocked on, oldest first.
     *
     * @return list<PendingAsk>
     */
    public function pendingAsks(): array
    {
        return array_values($this->pendingAsks);
    }

    /**
     * Answer the open question $askId, first answer wins (Appendix O §6.7).
     * An `always` the question offers is remembered as this session's grant
     * for every later turn. Returns how the question was settled, or null
     * when no open question has that id — it was answered already (see
     * {@see resolution()}), cancelled with its turn, or never asked here.
     */
    public function answer(string $askId, PermissionReply $reply, ?string $note = null): ?PermissionResolved
    {
        $ask = $this->pendingAsks[$askId] ?? null;
        if ($ask === null) {
            return null;
        }
        unset($this->pendingAsks[$askId]);

        $remember = $reply === PermissionReply::Always && $ask->offers(PermissionReply::Always) && !$ask->isSettled();
        if (!$ask->reply($reply, $note)) {
            return null;
        }
        if ($remember) {
            $this->grants = $this->grants->withGrant($ask->tool, $ask->arguments, $this->root());
            $this->transcripts->savePermissionGrants($this->sessionId, $this->grants->grants());
        }
        $resolution = $ask->resolution();
        if ($resolution !== null) {
            $this->remember($resolution);
        }

        return $resolution;
    }

    /** How the question $askId was settled, when it was settled recently here. */
    public function resolution(string $askId): ?PermissionResolved
    {
        return $this->resolutions[$askId] ?? null;
    }

    /**
     * Ask the running turn to stop at its next step boundary, letting the tool
     * in flight finish (`cancel_soft`, Appendix O §5.1). The turn settles on
     * its own; nothing is healed here.
     *
     * @return bool whether a turn was asked
     */
    public function cancelSoft(): bool
    {
        if ($this->turn === null || $this->turn->isCancelled()) {
            return false;
        }
        $this->turn->cancelSoft();

        return true;
    }

    /**
     * Drop the queued prompt at $index (0 is the next to go out).
     *
     * @return string|null the prompt dropped, or null when there was none there
     */
    public function removeQueued(int $index): ?string
    {
        if (!isset($this->queue[$index])) {
            return null;
        }
        $removed = $this->queue[$index];
        unset($this->queue[$index]);
        $this->queue = array_values($this->queue);

        return $removed;
    }

    /**
     * Submit $text to the session (see the class docblock for the order it is
     * judged in) and say how it was admitted.
     */
    public function submit(string $text, ?SubmitOptions $options = null): TurnTicket
    {
        $options ??= SubmitOptions::new();
        $text = trim($text);
        if ($text === '') {
            return TurnTicket::refused($this->turns()->emptyPromptNotice())->withIdempotencyKey($options->idempotencyKey);
        }

        // ISS-064: the idle-compaction advisory. The policy existed and this
        // host had no caller for it: a session idle past
        // `compaction.idleOfferSeconds` whose estimate is over the WHOLE window
        // hears it in the transcript, in the TUI's words, before the prompt
        // runs. One deliberate deviation from the TUI: the prompt is NOT held.
        // A server client has no `/compact` turn to answer the offer with
        // without a second round trip, and the automatic tier this very submit
        // runs IS the summarise-first-then-refuse the notice announces.
        $idleConfig = $this->compactor->config();
        if ($this->workspace->backend !== null && $this->lastPromptAt !== null
            && $idleConfig->idleOfferSeconds > 0 && !$this->isBusy()
        ) {
            $idleTokens = $this->estimate($this->history);
            $idleLimit = $this->meter()->limit($this->backend());
            if ($this->meter()->shouldPromptIdleCompaction(
                $idleTokens,
                $this->lastPromptAt,
                $idleLimit,
                $idleConfig->idleOfferSeconds,
            )) {
                $advisory = Message::notice(Lang::t('chat.compact.idle_advisory', [
                    'tokens' => $idleTokens,
                    'limit' => $idleLimit,
                ]));
                $this->history[] = $advisory;
                $this->save();
                $this->announceTranscriptRow($advisory);
            }
        }
        $this->lastPromptAt = new \DateTimeImmutable();
        $this->armIdleAutoCompaction();

        $ticket = $this->isBusy()
            ? $this->admitWhileBusy($text, $options)
            : $this->admit($text, $options);

        return $ticket->withIdempotencyKey($options->idempotencyKey);
    }

    /**
     * Put out the ahead-of-need summarisation request (roadmap 2.10) and
     * latch its landing slot — the block the 70% dispatch tier used to carry
     * inline, shared now with the CTX-5 idle watch. Sharing is the point:
     * the timer's whole trick is to run the very request the next dispatch
     * would have asked for, through the same gate, so the served splice —
     * billing included — never sees two sources and never pays twice.
     *
     * @param list<Message> $dispatched the history the summaries are asked against
     * @param list<Message> $baseHistory the conversation to summarise
     * @return bool whether a request actually went out
     */
    private function requestAheadOfNeed(array $dispatched, array $baseHistory): bool
    {
        $request = $this->compaction()->buildSummarizationRequest(
            $this->backend(),
            $this->compactor,
            $dispatched,
            null,
            null,
            '',
            true,
            $baseHistory,
        );
        if ($request === null) {
            return false;
        }

        $this->aheadOfNeed = [
            'id' => $request['id'],
            'fingerprint' => $request['fingerprint'],
            'summaries' => null,
            'safeguardHold' => null,
        ];
        // C-CTX-2.a: this request forks a model call from either of its
        // callers — the 70% dispatch tier or the CTX-5 idle timer — so it
        // carries its own using() window: the summaries' child records land
        // in THIS session's inbox wherever the request went out from, and
        // the queue fallback for a sink without transport fills the same way.
        RuntimeNoticeSink::using($this->notices, fn (): PromiseInterface => $request['promise']())
            ->then(function (HistoryCompactedMsg $msg) use ($request): void {
            // Billed before the latch check, as the TUI bills it: a
            // paid-for provider call is never unbilled, even when the
            // summaries it carried are superseded on arrival.
            $this->ledger()->account($this->spend, $msg->usage, $this->usageRecordSink());
            if ($this->aheadOfNeed === null || $this->aheadOfNeed['id'] !== $request['id']) {
                return;
            }
            // CTX-4: a HOLD is remembered so the tier that lands the
            // heuristic fold can say why, but its summaries are
            // discarded and never spliced.
            $this->aheadOfNeed['summaries'] = $msg->error === null && $msg->blockedBy === null && $msg->safeguardHold === null
                ? $msg->summaries
                : [];
            $this->aheadOfNeed['safeguardHold'] = $msg->safeguardHold;
        });

        return true;
    }

    /**
     * (Re-)arm the CTX-5 idle auto-compaction watch. Called from every event
     * that RE-STARTS the idle window: an accepted prompt, a settled turn, and
     * an alarm that fired into a running turn (C-CTX-6.a — dropping the watch
     * on a busy fire forfeits the fold the opt-in exists to make). Each call
     * replaces the deadline (cancel first — a user
     * who returned must not leave an orphan alarm that fires against the
     * NEXT quiet gap and charges for it to the wrong window), and the
     * opt-out shape never arms at all, so the default-off default costs a
     * served session exactly nothing.
     *
     * The +2 s slack exists because {@see IdleCompactionPolicy::shouldAutoCompact()}
     * keeps the offer clock's strict `>` semantics while an event-loop timer's
     * wall-clock elapsed at fire time can land a hair under its deadline —
     * and, unlike the TUI's tick, this watch gets no second chance. An
     * unattended fold that waits two extra seconds of an hour-long idle is
     * invisible; a fold whose one-shot alarm under-shot by a rounding and so
     * silently never fired until the next prompt is the bug.
     */
    private function armIdleAutoCompaction(): void
    {
        if ($this->idleAutoTimer !== null) {
            Loop::cancelTimer($this->idleAutoTimer);
            $this->idleAutoTimer = null;
        }

        $config = $this->compactor->config();
        if (!$config->idleAuto || !$config->autoCompacts()
            || $config->idleOfferSeconds <= 0 || $this->workspace->backend === null
        ) {
            return;
        }

        $markedAt = $this->lastPromptAt;
        $this->idleAutoTimer = Loop::addTimer(
            (float) ($config->idleOfferSeconds + 2),
            function () use ($markedAt): void {
                $this->idleAutoTimer = null;
                $this->onIdleAutoCompaction($markedAt);
            },
        );
    }

    /**
     * The idle watch's verdict, every condition re-derived at fire time —
     * between the arming and the alarm anything they depend on has had an
     * hour to move. Passing means exactly what passing means at the tier:
     * the shared {@see requestAheadOfNeed()}, whose landed summaries the
     * existing splice consumes on the next submit, nobody re-billed and no
     * turn ever dispatched unattended.
     */
    private function onIdleAutoCompaction(?\DateTimeImmutable $markedAt): void
    {
        // The user returned — a later prompt re-stamped the clock (and
        // re-armed) — so this is no longer an idle fold; the ordinary tiers
        // take it. The stamp identity alone says it: every event that moves
        // the window re-arms, so a live alarm always carries the current
        // prompt stamp.
        if ($this->lastPromptAt !== $markedAt) {
            return;
        }

        // C-CTX-6.a: a running turn is a momentary silence, not the end of
        // the watch. The TUI's tick simply re-checks the next minute and
        // loses nothing; this one-shot hands itself to the next gap with the
        // re-arm below, so the fold is judged from the settle instead of
        // forfeited to the turn that outlived the alarm.
        if ($this->isBusy()) {
            $this->armIdleAutoCompaction();

            return;
        }

        $config = $this->compactor->config();
        // One request per cycle (the dispatch mirror's own rule), nobody to
        // pay while the cap is breached, and the breaker: a fold run that
        // keeps refilling without a turn in between is the loop the tier
        // already refuses to start, and an unattended one has even less
        // business being started.
        if ($this->aheadOfNeed !== null
            || $this->workspace->backend === null
            || $this->ledger()->capReached($this->spend, $this->workspace->maxCostUsd)
            || IdleCompactionPolicy::thrashTripped($this->consecutiveRefills, $config->refillLimit)
        ) {
            return;
        }

        // Idle since the LATER of the two activity stamps — the prompt, or
        // the settle of the turn that ran after it (C-CTX-6.a). Measuring
        // from the prompt alone would count the turn itself as silence and
        // bill a fold moments after a reply landed.
        $idleAnchor = $this->lastPromptAt;
        if ($this->lastSettledAt !== null
            && ($idleAnchor === null || $this->lastSettledAt > $idleAnchor)
        ) {
            $idleAnchor = $this->lastSettledAt;
        }

        // The tier itself, measured exactly as submit() measures it, AND-ed
        // with the shared idle clock so one policy answers both verdicts.
        if (!IdleCompactionPolicy::shouldAutoCompact(
            $this->compactor->shouldCompact(
                CompactionService::compactionWire($this->history),
                $this->meter()->limit($this->backend()),
                CompactionService::tierTokenCount($this->estimate($this->history), $this->history),
            ),
            $idleAnchor,
            $config->idleAuto,
            idleSeconds: $config->idleOfferSeconds,
        )) {
            return;
        }

        if ($this->requestAheadOfNeed($this->history, $this->history)) {
            // The human learns what the machine did while nobody was
            // watching, in the transcript, like the advisory above it.
            // Message::notice() is uiOnly by construction, and
            // compactionWire() reads agent-visible rows only — the row
            // cannot move the fingerprint the splice validates against.
            $row = Message::notice(Lang::t('chat.compact.idle_auto_scheduled', [
                'tokens' => $this->estimate($this->history),
                'limit' => $this->meter()->limit($this->backend()),
            ]));
            $this->history[] = $row;
            $this->save();
            $this->announceTranscriptRow($row);
        }
    }

    /**
     * Cancel the turn in flight — or a submission parked behind off-thread
     * work — when $turnId is null or names it. Its running tool rows are
     * healed into the same "interrupted" rows the TUI writes, so the next
     * request's wire has no `tool_use` left unanswered. Queued prompts stay
     * queued and go out with the next submission's settle; a caller that
     * wants them gone clears them ({@see clearQueue()}).
     *
     * @return bool whether anything was cancelled
     */
    public function cancel(?string $turnId = null): bool
    {
        if ($this->turn === null || ($turnId !== null && $turnId !== $this->turnId())) {
            return false;
        }

        $this->turn->cancel();
        $this->turn = null;
        $this->workflowTurn = false;
        $this->generation++;
        $this->inbox->exchangeArray([]);
        $this->settleOpenAsks('the turn was cancelled');
        $this->history = array_map(static function (Message $message): Message {
            if ($message->pendingToolCallId === null) {
                return $message;
            }

            $healed = TranscriptStore::interruptedToolCallRow(
                $message->content,
                $message->pendingToolCallId,
                Chat::CANCELLED_TOOL_CALL,
            );

            return trim((string) $message->reasoning) !== '' ? $healed->withReasoning($message->reasoning) : $healed;
        }, $this->history);
        $this->save();

        return true;
    }

    /** Drop every queued prompt; returns how many there were. */
    public function clearQueue(): int
    {
        $count = \count($this->queue);
        $this->queue = [];

        return $count;
    }

    /**
     * Run the built-in command `/$name $args` (roadmap O-2h, Appendix O §6.3
     * `command.exec`) and say what it produced. Its rows are already in the
     * transcript and its effects applied when this returns; off-turn work it
     * started lands later as a row.
     *
     * Refused while a turn holds the session — a command would rewrite the
     * history that turn is about to append to — except `/workflow pause|status`
     * inside the workflow run they control. A name that is no built-in is
     * refused too: unlike {@see submit()}, this never sends text to the model.
     */
    public function runCommand(string $name, string $args = ''): CommandResult
    {
        $name = ltrim(trim($name), '/');
        $text = trim('/' . $name . ' ' . trim($args));

        if ($this->isBusy() && !$this->isWorkflowControl($text)) {
            return CommandResult::refused($this->turns()->hostCommandNotice($text));
        }

        $result = $this->dispatchCommand($text)
            ?? CommandResult::refused(Lang::t('host.command.unknown', ['name' => $name]));
        if (!$result->isRefused()) {
            $this->applyCommandResult($result, trim($name));
        }

        return $result;
    }

    /**
     * Fold whatever the running turn has reported since the last fold into
     * the transcript — running placeholders and finished tool rows, through
     * the {@see TranscriptProjector} the TUI's live pump uses — and hand
     * every event to the runner's listeners and log. A server calls this on
     * its own tick; what is still on the inbox when the turn settles folds
     * then.
     *
     * @return int how many events were folded
     */
    public function pump(): int
    {
        // C-CTX-2.a: the server's own tick is a drain point too — a session
        // nobody followed yet (or whose wake raced the tick) still lands its
        // notice rows here. The count a caller reads is the folded tool
        // events alone; notices are rows, not events.
        $this->pumpRuntimeNotices();
        if ($this->turn === null || \count($this->inbox) === 0) {
            return 0;
        }

        $entries = $this->inbox->getArrayCopy();
        $this->inbox->exchangeArray([]);
        $folded = 0;
        foreach ($entries as [$generation, $event]) {
            if ($generation !== $this->generation) {
                continue;
            }
            $this->fold($this->turn, $event);
            $folded++;
        }
        if ($folded > 0) {
            $this->save();
        }

        return $folded;
    }

    /**
     * C-CTX-2.a: land every pending runtime notice as its own transcript
     * row — the served mirror of {@see Chat::pumpRuntimeNotices()}.
     *
     * Each row goes through {@see appendMessageRow()}: saved with the
     * session and announced as the durable `message.created` whose kind is
     * `notice`, the shape the web reducer already paints. The one-shot wake
     * is renewed on EVERY path, the drain that found nothing included — the
     * read that empties the transport is precisely the moment the watcher
     * must be listening again (the same cadence Chat pins in
     * RuntimeNoticeSinkDeliveryTest).
     */
    private function pumpRuntimeNotices(): void
    {
        foreach ($this->notices->drain() as $notice) {
            $this->appendMessageRow(Message::notice($notice));
        }

        $this->armNoticeWake();
    }

    /**
     * Re-arm the sink's one-shot loop wake. Without a transport there is no
     * stream to read — an array-queue sink (socketpair refused, e.g. an
     * embedder out of fds) drains on pump and settle alone, which is the
     * same shape Chat's own wake gate keeps ({@see Chat::runtimeNoticeWake()}).
     */
    private function armNoticeWake(): void
    {
        if (!$this->notices->hasTransport()) {
            return;
        }

        $this->notices->notifyOnceWhenPending(function (): void {
            $this->pumpRuntimeNotices();
        });
    }

    /**
     * Advance this session's due `/loop` rows (C-AGT-1.b) — the generic
     * per-session clock a server calls on its own tick (C-AGT-4.e reuses it).
     *
     * Rate lives with the caller, never here: {@see
     * \SugarCraft\Code\Protocol\ServerContext::pump()} calls this at most
     * once per second riding ITS clock; minting a wall-clock timer per loop
     * row would be an E646-shaped request-side ceiling in disguise. For each
     * due row this host owns — only rows stamped with its own id, which is
     * {@see ScheduleRegistry::dueEphemeralFor()}'s keying; another session's
     * row (or an un-stamped TUI row) is never fired here — it re-arms the
     * row's next due in memory, appends a notice row exactly as the TUI pump
     * does, and hands the prompt to {@see submit()} as a
     * {@see QueueMode::Followup}: it becomes the next turn when the session
     * is idle and waits behind the running turn when it is not.
     *
     * @return int how many loop prompts fired
     */
    public function tick(int $now): int
    {
        $registry = $this->workspace->service(ScheduleRegistry::class);
        if (!$registry instanceof ScheduleRegistry) {
            return 0;
        }

        $fired = 0;
        foreach ($registry->dueEphemeralFor($this->sessionId, $now) as $row) {
            $registry->noteNextDue($row->name, $row->nextDueAfter($now));
            $notice = Message::notice(Lang::t('host.loop.queued', ['name' => $row->name]));
            $this->history[] = $notice;
            $this->save();
            $this->announceTranscriptRow($notice);
            $this->submit($row->prompt, SubmitOptions::new()->withDelivery(QueueMode::Followup));
            $fired++;
        }

        return $fired;
    }

    /**
     * Let go of the session's lock and its `/loop` rows; the host must not be
     * used after. Dropping the ephemeral rows keyed to this session is what
     * makes a served loop die with its session (C-AGT-1.b) — a row nobody
     * fires is a row nobody promised to fire, so close must clear the promise.
     */
    public function release(): void
    {
        // C-CTX-2.c: stamp the close FIRST — a settle work item answering
        // after this point is billed to the ledger and dropped from the
        // transcript (its landers consult this flag).
        $this->released = true;

        // C-CTX-2.a: the session's notice inbox dies with the session.
        // reset() cancels any armed loop watcher BEFORE closing both socket
        // fds — a watcher left on the loop over a closed stream would be
        // handed to stream_select() on every later iteration.
        $this->notices->reset();
        $registry = $this->workspace->service(ScheduleRegistry::class);
        if ($registry instanceof ScheduleRegistry) {
            $registry->removeEphemeralFor($this->sessionId);
        }
        $this->transcripts->flush();
        $this->lease?->release();
        $this->lease = null;
    }

    // ── admission ──────────────────────────────────────────────────────

    private function admitWhileBusy(string $text, SubmitOptions $options): TurnTicket
    {
        $turns = $this->turns();

        switch ($turns->midTurnRoute($text, $this->isWorkflowControl($text), $options)) {
            case TurnController::ROUTE_WORKFLOW_CONTROL:
                // `/workflow pause|status` inside the run they control (audit
                // WF-4): run, and leave the run holding the session.
                return $this->command($text) ?? TurnTicket::refused($turns->hostCommandNotice($text));

            case TurnController::ROUTE_SIDE_QUESTION:
                // `/btw` (roadmap 5.14b): answered beside the running turn,
                // never queued behind it and never in the model's history.
                return $this->command($text) ?? TurnTicket::refused($turns->hostCommandNotice($text));

            case TurnController::ROUTE_QUIT:
            case TurnController::ROUTE_REFUSE_COMMAND:
                return TurnTicket::refused($turns->hostCommandNotice($text));

            case TurnController::ROUTE_STEER:
                $steerId = $this->workspace->backend instanceof InteractiveTurn
                    ? $this->turn?->steer($text)
                    : null;
                // NEVER LOST, as in the TUI: a steer is also held as a
                // follow-up, and the settle drops it if the turn read it.
                $this->queue[] = $text;
                if ($steerId === null) {
                    return TurnTicket::queued(\count($this->queue));
                }

                return TurnTicket::steered($this->turnId(), $steerId, \count($this->queue));

            case TurnController::ROUTE_INTERRUPT:
                $this->cancel();

                return $this->admit($text, $options);

            default:
                $this->queue[] = $text;

                return TurnTicket::queued(\count($this->queue));
        }
    }

    private function admit(string $text, SubmitOptions $options): TurnTicket
    {
        $turns = $this->turns();

        // `!cmd` (roadmap 5.14g): the user's own shell command, run here as the
        // TUI runs it. It calls no model, so it needs no backend.
        $bang = BangShell::commandOf($text);
        if ($bang !== null) {
            return $this->runBang($bang);
        }

        // FILE-BASED COMMANDS FIRST, ahead of the built-ins: a project
        // `compact.md` replaces `/compact`, and a command file IS a prompt, so
        // everything below applies to its expansion.
        $command = $turns->resolveCustomCommand($text, $this->customCommands);
        if ($command !== null) {
            if ($this->workspace->backend === null) {
                return TurnTicket::refused($turns->noBackendNotice());
            }
            if ($turns->customCommandMustFork($text, $this->customCommands, null, $this->workspace->projectCommandsTrusted)) {
                return $this->parkExpansion($text, $command);
            }

            // C-MCP-2.a: the fetched-body leg Chat's expandCustomCommand runs,
            // for the no-fork shape of serve this branch takes. An MCP prompt
            // whose fetch fails expands to nothing and rides the existing
            // empty-expansion refusal below — it is never sent half-guessed.
            [$spec, $arguments] = $command;
            if (McpPromptCommands::isMcpPrompt($spec)) {
                $materialized = McpPromptCommands::materialize($spec, $arguments, $this->mcpProvider);
                if ($materialized === null) {
                    return $this->admitExpanded($text, '');
                }
                $spec = $materialized;
            }

            return $this->admitExpanded(
                $text,
                $turns->expandCustomCommand($spec, $arguments, $this->directive($spec)),
                $spec,
            );
        }

        // A built-in runs through its `Host\Commands` body, as in the TUI; a
        // `/word` that names none is prose, as it is there.
        $ran = $this->command($text);
        if ($ran !== null) {
            return $ran;
        }

        if ($this->workspace->backend === null) {
            return TurnTicket::refused($turns->noBackendNotice());
        }

        return $this->admitPrompt($text, $options->resolveMentions, $text, attachments: $options->attachments, modeCeiling: $options->modeCeiling);
    }

    /**
     * A command file's expansion, judged as the TUI judges it: an expansion
     * that produced nothing is refused, not sent, and its mentions are never
     * read (audit 15b-15) — the body is repository-authored, with its own `@`
     * form a trust tier may just have refused. The `/rewind` draft is the line
     * as typed.
     *
     * C-EXT-2.e: $spec is the command file's own spec (null for an expansion
     * no spec stands behind, like the MCP fetch-refusal leg). Beside it this
     * route applies the same two judgements Chat::submit() applies to a
     * command it expands: a `subtask:`/`agent:` command rides out wrapped in
     * {@see TurnController::wrapCommandDelegation()}'s delegation directive,
     * and a `model:` command pins exactly this one turn through
     * {@see TurnController::commandTurnModel()} — the pin is threaded to
     * {@see dispatch()}'s backend, never stored on the session, so the next
     * turn answers on the session's model by construction.
     */
    private function admitExpanded(string $typed, ?string $expanded, ?CommandSpec $spec = null): TurnTicket
    {
        $expanded = trim((string) $expanded);
        if ($expanded === '') {
            return TurnTicket::refused($this->turns()->emptyCustomCommandNotice($typed));
        }

        $oneTurnModel = null;
        $commandModelNotice = null;
        if ($spec !== null) {
            $expanded = $this->turns()->wrapCommandDelegation($expanded, $spec);
            $engine = $this->backend();
            $decided = $this->turns()->commandTurnModel($spec, $engine instanceof EngineBackend ? $engine : null);
            if ($decided !== null) {
                [$oneTurnModel, $commandModelNotice] = $decided;
            }
        }

        return $this->admitPrompt(
            $expanded,
            false,
            $typed,
            oneTurnModel: $oneTurnModel,
            commandModelNotice: $commandModelNotice,
        );
    }

    /**
     * Expand $text's command file in a forked child (audit 15b-20) and go on
     * once it answers. The session is held — mid-turn rules apply — until
     * then.
     *
     * @param array{0: CommandSpec, 1: string} $command
     */
    private function parkExpansion(string $text, array $command): TurnTicket
    {
        [$spec, $arguments] = $command;
        $turns = $this->turns();
        $root = $this->root();
        $trusted = $this->workspace->projectCommandsTrusted;
        $gate = $this->workspace->permissionGate;
        $provider = $this->mcpProvider;
        $run = static function () use ($turns, $spec, $arguments, $root, $trusted, $gate, $provider): array {
            $gated = [];
            // C-MCP-2.a: for an MCP prompt row the network fetch IS the
            // reason this turn is parked (the posture always forks one), so
            // it belongs in this child — the parent's keystroke and event
            // threads never block on a server. A refused fetch reports the
            // empty string, which rides the parent's existing empty-
            // expansion refusal; a fetched body replaces the marker before
            // the directive scan, so C-SEC-3 judges shell forms on what the
            // model will actually see.
            if (McpPromptCommands::isMcpPrompt($spec)) {
                $materialized = McpPromptCommands::materialize($spec, $arguments, $provider);
                if ($materialized === null) {
                    return ['', $gated];
                }
                $spec = $materialized;
            }
            $expanded = $turns->expandCustomCommand(
                $spec,
                $arguments,
                $turns->commandDirective($spec, $root, $trusted, $gate, static function (string $command) use (&$gated): void {
                    $gated[] = $command;
                }),
            );

            return [$expanded, $gated];
        };

        $this->park(
            static function () use ($run): string {
                [$expanded, $gated] = $run();

                return TurnController::customCommandPayload($expanded, $gated);
            },
            static fn (string $file): array => TurnController::customCommandExpansionFromPayload(TurnController::takePayload($file)),
            $run,
            function (array $result) use ($text, $command): void {
                [$expanded, $gated] = $result;
                // The child's gate evaluations, replayed against this session's
                // own gate, so Auto mode's circuit breaker counts what a
                // synchronous expansion would have counted. Root-less: a Bash
                // verdict never reads the root.
                foreach ($gated as $command) {
                    $this->workspace->permissionGate?->evaluate(new ToolCall('Bash', ['command' => $command]));
                }
                if ($expanded === null) {
                    return;
                }
                // C-MCP-2.a: a refused MCP fetch is the first expansion that
                // can only ever arrive through the forking posture, so its
                // refusal must land as the visible transcript notice Chat's
                // refuseEmptyCustomCommand records — dropping the returned
                // ticket silently would leave a web client watching a turn
                // that vanished. A null expansion (the child's own judge
                // refusal) keeps its existing silence; this leg is only the
                // empty-string one admitExpanded refuses.
                if (trim($expanded) === '') {
                    $this->appendMessageRow(Message::notice($this->turns()->emptyCustomCommandNotice($text)));

                    return;
                }
                $this->admitExpanded($text, $expanded, $command[0]);
            },
        );

        return TurnTicket::pending();
    }

    /**
     * A prompt (typed, or a command file's expansion) from the spend cap on.
     * $verdicts are a forked turn-hook chain's, on the re-entry that consumes
     * them; null fires the chain now.
     *
     * @param array{0: HookResult, 1: ?HookResult}|null $verdicts
     * @param list<\SugarCraft\Code\Attachment> $attachments pasted images riding this
     *     prompt (WEB-5b); threaded unchanged through a forked hook's resume
     * @param ?PermissionMode $modeCeiling the C-AGT-2.b gate ceiling of the
     *     submission that starts this turn; threaded unchanged through a
     *     forked hook's resume, null for an expansion that names none
     * @param ?string $oneTurnModel C-EXT-2.e: the model a command file's
     *     `model:` pinned for exactly this turn (see
     *     {@see TurnController::commandTurnModel()}); threaded unchanged
     *     through a forked hook's resume so the pin cannot be forked away
     * @param ?string $commandModelNotice C-EXT-2.e: the fall-back sentence when
     *     the requested model could not be served and the turn rides the
     *     session's model instead; a notice row, never an override
     */
    private function admitPrompt(
        string $text,
        bool $resolveMentions,
        string $draft,
        ?array $verdicts = null,
        array $attachments = [],
        ?PermissionMode $modeCeiling = null,
        ?string $oneTurnModel = null,
        ?string $commandModelNotice = null,
    ): TurnTicket {
        $turns = $this->turns();
        $ledger = $this->ledger();

        // Spend cap, where the TUI checks it: after command dispatch.
        if ($ledger->capReached($this->spend, $this->workspace->maxCostUsd)) {
            return TurnTicket::refused($ledger->refusalNotice(
                $this->spend,
                (float) $this->workspace->maxCostUsd,
                SpendLedger::crossedByPreviousTurn(),
            ));
        }

        // The automatic tier: the synchronous heuristic route.
        $tokenLimit = $this->meter()->limit($this->backend());
        $tokenCount = $this->estimate($this->history);
        $wireHistory = CompactionService::compactionWire($this->history);
        $baseHistory = $this->history;
        $reports = [];
        $refilled = null;
        // `compaction.mode` (roadmap N-P4b) as Chat::submit() reads it: `off`
        // skips the automatic tier, yet a prompt the window cannot take is
        // still refused with the blocking tier's refusal. `heuristic` and
        // `llm` need no arm here — the tier below always runs; an ahead-of-need
        // summary that landed (ISS-064) only replaces the heuristic lines it
        // would write, never the arm that fires them.
        $config = $this->compactor->config();
        // ISS-015: the tiers read the calibrated estimate plus tool-argument
        // bytes (the status bar's figure), not the content-only wire tally.
        $tierTokenCount = CompactionService::tierTokenCount($tokenCount, $this->history);
        if (!$config->autoCompacts() && $this->compactor->shouldCompactForeground($wireHistory, $tokenLimit, $tierTokenCount)) {
            $rows = $this->compaction()->foregroundBlockedRows('', $this->history, $tokenCount, $tokenLimit);

            return TurnTicket::refused(array_pop($rows)->content);
        }
        if ($config->autoCompacts() && $this->compactor->shouldCompact($wireHistory, $tokenLimit, $tierTokenCount)) {
            $compaction = $this->compaction();
            if (IdleCompactionPolicy::thrashTripped($this->consecutiveRefills, $config->refillLimit)) {
                return TurnTicket::refused($compaction->thrashBreakerNotice($config->refillLimit));
            }

            // ISS-064: a landed ahead-of-need summary whose fingerprint still
            // matches this history is spliced in — the tier gets the model's
            // records for the exchanges it is about to condense. Empty or
            // stale passes nothing extra: the heuristic lines as before.
            $spliced = null;
            $held = $this->aheadOfNeed;
            if ($held !== null && ($held['summaries'] ?? []) !== []
                && $held['fingerprint']->matches($compaction->historyFingerprint($this->compactor, $this->history))
            ) {
                $spliced = CompactionService::splicedSummaries($held['summaries'], $this->history);
                // Used up either way: the history it described is rewritten
                // below, and the next 70% dispatch asks afresh.
                $this->aheadOfNeed = null;
            }
            // CTX-4 on the served route: the slot holds a HOLD verdict's
            // detail instead of splices. Nothing is refused — a served turn
            // parks nothing and holding it would 400 a request already
            // accepted — the fold the tier writes is the heuristic one, so the
            // fold's own report carries the advisory naming the audit.
            $safeguardFold = null;
            if ($spliced === null && $held !== null && ($held['safeguardHold'] ?? null) !== null
                && $held['fingerprint']->matches($compaction->historyFingerprint($this->compactor, $this->history))
            ) {
                $safeguardFold = $held['safeguardHold'];
                $this->aheadOfNeed = null;
            }

            $tier = $turns->inlineTier(
                $compaction,
                $this->compactor,
                $this->history,
                $wireHistory,
                $tokenLimit,
                $tokenCount,
                $this->estimate(...),
                $spliced ?? [],
            );
            if ($tier['outcome'] === 'blocked') {
                // As the TUI's refusal does, the rewrite is kept: each further
                // attempt drops the oldest exchange, so a resend gets through.
                $this->consecutiveRefills = $compaction->refillCount($this->consecutiveRefills, $tier['refilled'], false);
                $rows = $compaction->foregroundBlockedRows('', $tier['history'], $tier['tokenCount'], $tokenLimit, $tier['compactionNotice']);
                $refusal = array_pop($rows);
                $this->history = $rows;
                $this->save();

                return TurnTicket::refused($refusal->content);
            }
            $baseHistory = $tier['history'];
            $this->announceCompaction($tier, $tokenCount);
            if ($safeguardFold !== null) {
                // Cause before effect: the advisory names the audit, the
                // tier's own report then measures the fold it describes.
                $reports[] = Message::notice($compaction->safeguardFoldAdvisory($safeguardFold));
            }
            foreach ([$tier['compactionNotice'], $tier['truncationNotice']] as $report) {
                if ($report instanceof Message) {
                    $reports[] = $report;
                }
            }
            // The rescue exemption (ruling P8.S5-R6): a rescued dispatch
            // neither extends nor breaks the breaker's run.
            $refilled = $tier['outcome'] === 'sent' ? $tier['refilled'] : null;
        }

        // The turn hooks, gate-first, off this thread when one leaves the process.
        $hooks = $this->workspace->hooks;
        $notes = [];
        if ($hooks !== null) {
            if ($verdicts === null) {
                $verdicts = $this->fireTurnHooks($text, $resolveMentions, $draft, $attachments, $modeCeiling, $oneTurnModel, $commandModelNotice);
                if ($verdicts === null) {
                    return TurnTicket::pending();
                }
            }

            [$prompt, $session] = $verdicts;
            $blocked = $turns->turnHookRefusalReason($prompt);
            if ($blocked !== null) {
                return TurnTicket::refused($blocked);
            }
            $notes = $turns->turnHookNotes($prompt, $session);
        }

        return $this->dispatch($text, $resolveMentions, $draft, $baseHistory, $reports, $notes, $refilled, $attachments, $modeCeiling, $oneTurnModel, $commandModelNotice);
    }

    /**
     * Fire `UserPromptSubmit` and, into an empty history, `SessionStart` for
     * one submission — this host's dispatch site for both, as
     * `Chat::dispatchTurnHooks()` is the TUI's. In process, the verdicts come
     * back now; when a hook leaves the process the chain is forked
     * (audit 15b-04), the session is held, null is returned, and
     * {@see admitPrompt()} is re-entered with the verdicts when they land.
     * The two C-EXT-2.e tail parameters ride the fork so a command's one-turn
     * model pin and its fall-back notice survive the chain.
     *
     * @return array{0: HookResult, 1: ?HookResult}|null
     */
    private function fireTurnHooks(string $text, bool $resolveMentions, string $draft, array $attachments = [], ?PermissionMode $modeCeiling = null, ?string $oneTurnModel = null, ?string $commandModelNotice = null): ?array
    {
        $hooks = $this->workspace->hooks ?? throw new \LogicException('fireTurnHooks() needs a hook manager');
        $turns = $this->turns();
        $firesSessionStart = \count($this->history) === 0;
        $promptEvent = HookEvent::UserPromptSubmit;
        $sessionEvent = HookEvent::SessionStart;
        $promptContext = $turns->turnHookContext($promptEvent->value, $text, false, $this->sessionId, $this->root());
        $sessionContext = $firesSessionStart
            ? $turns->turnHookContext($sessionEvent->value, $text, true, $this->sessionId, $this->root())
            : null;
        $run = static fn (): array => TurnController::runTurnHooks($hooks, $promptContext, $sessionContext);

        if (!$turns->turnHooksMustFork($hooks, $firesSessionStart ? [$promptEvent, $sessionEvent] : [$promptEvent])) {
            return $run();
        }

        $this->park(
            static function () use ($run): string {
                [$prompt, $session] = $run();

                return TurnController::turnHookPayload($prompt, $session);
            },
            static fn (string $file): array => TurnController::turnHookResultsFromPayload(TurnController::takePayload($file)),
            $run,
            function (array $verdicts) use ($text, $resolveMentions, $draft, $attachments, $modeCeiling, $oneTurnModel, $commandModelNotice): void {
                $this->admitPrompt($text, $resolveMentions, $draft, $verdicts, $attachments, $modeCeiling, $oneTurnModel, $commandModelNotice);
            },
        );

        return null;
    }

    /**
     * Hold the session behind $childWork in a forked child
     * ({@see TurnController::forkPayload()}) and run $resume with what it
     * reported — unless the hold was cancelled meanwhile. Whatever the resume
     * leaves idle releases the queue, as every turn end does.
     *
     * @param \Closure(): string $childWork
     * @param \Closure(string): array<int, mixed> $collect
     * @param \Closure(): array<int, mixed> $inline
     * @param \Closure(array<int, mixed>): void $resume
     */
    private function park(\Closure $childWork, \Closure $collect, \Closure $inline, \Closure $resume): void
    {
        $cancellation = new CancellationToken();
        $this->turn = $cancellation;
        $generation = ++$this->generation;

        TurnController::forkPayload($childWork, $collect, $inline, $cancellation)->then(
            function (?array $result) use ($generation, $resume): void {
                if ($result === null || $generation !== $this->generation) {
                    return;
                }
                $this->turn = null;
                $resume($result);
                if ($this->turn === null) {
                    $this->releaseQueue();
                }
            },
        );
    }

    // ── commands (roadmap O-2h) ────────────────────────────────────────

    /**
     * Run $text when it is a built-in command and say how it was taken, or
     * null when it is not one (prose, or a `/word` no built-in answers to).
     */
    private function command(string $text): ?TurnTicket
    {
        $result = $this->dispatchCommand($text);
        if ($result === null) {
            return null;
        }
        if ($result->isRefused()) {
            return TurnTicket::refused((string) $result->error);
        }

        // C-CTX-2.d: the dispatched name rides into the result application
        // so the context-changing roster can be keyed on it (see
        // {@see self::CONTEXT_CHANGING_COMMANDS}); the parser's own name is
        // what dispatchCommand matched, including the bare-MCP-auth shape.
        $parsed = (new CommandParser())->parse($text);
        $this->applyCommandResult($result, $parsed?->name ?? ltrim(CommandText::tokens($text)[0] ?? '', '/'));

        return TurnTicket::handled();
    }

    /**
     * The built-in $text dispatches to, run through its host command — the
     * TUI's table (`builtin-commands/`), so the two cannot route one draft
     * differently. Null when $text names no built-in. A built-in with no host
     * body, or one whose answer is a screen ({@see CommandEffectKind::OpenTitleEditor}),
     * is {@see CommandResult::clientOnly()}.
     */
    private function dispatchCommand(string $text): ?CommandResult
    {
        if (TurnController::isBareMcpAuthCommand($text)) {
            $spec = BuiltInCommands::forSpelling('mcp');
        } else {
            $parsed = (new CommandParser())->parse($text);
            if ($parsed === null || !str_starts_with($text, '/' . $parsed->name)) {
                return null;
            }
            $spec = BuiltInCommands::forSpelling($parsed->name);
            if ($spec !== null && !$spec->accepts($text, $parsed->name)) {
                return null;
            }
        }
        if (!$spec instanceof BuiltInCommand) {
            return null;
        }

        $command = $spec->instantiateHostCommand();
        if ($command === null) {
            return CommandResult::clientOnly($spec->name());
        }

        $result = $command->run($this->commandContext(), $text);

        // The screen-only follow-ups (the inline title editor, the media
        // form modal, the video player) are unservable here, so the headless
        // driver says so.
        return $result->effect(CommandEffectKind::OpenTitleEditor) !== null
            || $result->effect(CommandEffectKind::OpenMediaForm) !== null
            || $result->effect(CommandEffectKind::OpenVideoPlayer) !== null
            ? CommandResult::clientOnly($spec->name())
            : $result;
    }

    /**
     * Whether $text is `/workflow pause|status`, typed while the workflow run
     * it controls holds the session — the one command a busy session runs
     * (audit WF-4). A command file of that name is a prompt, so it queues.
     */
    private function isWorkflowControl(string $text): bool
    {
        if (!$this->workflowTurn || $this->workspace->workflowEngine === null) {
            return false;
        }

        $tokens = CommandText::tokens($text);

        return $tokens[0] === '/workflow'
            && \in_array($tokens[1] ?? '', ['pause', 'status'], true)
            && $this->turns()->resolveCustomCommand($text, $this->customCommands) === null;
    }

    /**
     * Apply what a command decided to this session — `Chat::applyCommandResult()`'s
     * headless twin. Effects first, then the rows, then a save.
     *
     * - The session id is this host's for life, so a `/branch` does not move
     *   it: the branch exists in the store (its reply names it) and a client
     *   opens it through the hub.
     * - A rename is already in the store; a host keeps no title in memory.
     * - Off-turn work runs on the loop; its answer lands as a UI-only row.
     * - An occupying run (a workflow) holds the session as a turn does, until
     *   its report lands; a cancelled run's report still lands, and releases
     *   nothing.
     *
     * $commandName is the dispatched name ('' when the caller has none); it
     * is what lets {@see self::CONTEXT_CHANGING_COMMANDS} mark a result that
     * rewrote the prompt's sources (C-CTX-2.d).
     */
    private function applyCommandResult(CommandResult $result, string $commandName = ''): void
    {
        // WEB-2: a prompt an init-style command queued is submitted only AFTER
        // this command's own rows have landed — the transcript reads echo,
        // marker, turn, exactly the order the TUI's `withInputBuf(...)->submit()`
        // route produces.
        $queuedPrompt = '';
        // C-WEB-1.a: a served `/model` or `/rename` changes what the session
        // summary says without changing its status, so the feed's status lane
        // never announces it; this flag carries the one announcement the
        // bound hook owes.
        $summaryChanged = false;
        foreach ($result->effects as $effect) {
            switch ($effect->kind) {
                case CommandEffectKind::ClearTranscript:
                    $this->history = [];
                    // The breaker counted rewrites of the transcript just cleared.
                    $this->consecutiveRefills = 0;
                    break;

                case CommandEffectKind::RestoreCheckpoint:
                    $this->history = $effect->messages();
                    break;

                case CommandEffectKind::Async:
                    $this->runAsync($effect);
                    break;

                case CommandEffectKind::OccupyTurn:
                    $this->occupy($effect);
                    break;

                case CommandEffectKind::SetPermissionGrants:
                    $this->grants = $effect->grants() ?? SessionPermissionMemo::new();
                    $this->transcripts->savePermissionGrants($this->sessionId, $this->grants->grants());
                    break;

                case CommandEffectKind::SetPermissionMode:
                    $this->setPermissionMode($effect->permissionMode());
                    break;

                case CommandEffectKind::SetModel:
                    $built = $effect->modelBackend();
                    if ($built !== null) {
                        $this->sessionBackend = $built;
                        // C-WEB-1.a: the names ride beside the backend. A
                        // provider-only switch names no model on the wire;
                        // the model the built engine resolved IS the one the
                        // session now runs, so the override carries that
                        // truth instead of blanking what the web reads.
                        $named = $effect->modelName();
                        if ($named === '' && $built instanceof EngineBackend) {
                            $named = $built->model();
                        }
                        $this->modelOverride = ['provider' => $effect->modelProvider(), 'model' => $named];
                        $summaryChanged = true;
                    }
                    break;

                case CommandEffectKind::CompactTranscript:
                    $this->typedCompact($effect->compactText());
                    break;

                case CommandEffectKind::SubmitPrompt:
                    $queuedPrompt = $effect->promptText();
                    break;

                case CommandEffectKind::RenameSession:
                    // C-WEB-1.a: the rename is already in the store (the host
                    // keeps no title in memory), but a served rename changed
                    // nothing the web's sidebar could hear — the summary only
                    // re-announced on a status transition. Flag it here.
                    $summaryChanged = true;
                    break;

                case CommandEffectKind::SwitchSession:
                case CommandEffectKind::OpenTitleEditor:
                    break;
            }
        }

        $this->history = [...$this->history, ...$result->rows];
        $this->save();
        foreach ($result->rows as $row) {
            $this->announceTranscriptRow($row);
        }

        // C-WEB-1.a: announced AFTER the command's own transcript rows — the
        // stream a client reads stays in the order the transcript settled —
        // and only once, however many summary-changing effects one command
        // carried.
        if ($summaryChanged && $this->onSummaryChanged !== null) {
            ($this->onSummaryChanged)();
        }

        // C-CTX-2.d: the handful of built-ins whose answer rewrites what the
        // prompt is built from — `/rules` toggles a rule pack, `/memory`
        // writes a note, `/init` sets an instruction file in motion — all
        // return plain reply rows no effect marks, so the roster is keyed on
        // the name the caller dispatched. A client hears `context.changed`
        // and re-reads `context.sources`; commands outside the roster cannot
        // move it, so they stay quiet.
        if ($commandName !== '' && \in_array($commandName, self::CONTEXT_CHANGING_COMMANDS, true)) {
            $this->announce(SessionEvent::new(SessionEvent::CONTEXT_CHANGED, ['reason' => $commandName], $this->sessionId));
        }

        if ($queuedPrompt !== '') {
            $ticket = $this->submit($queuedPrompt);
            if (!$ticket->isAdmitted()) {
                // The turn never went out (cap, blocking tier, a refusing
                // hook): the reason lands where the user just watched the
                // command settle, the way the TUI's stopped-goal row does.
                $this->appendCommandRow((string) $ticket->reason, true);
            }
        }
    }

    /**
     * WEB-2: the typed `/compact` fold on a served session — the shared
     * {@see CompactionService::compactedHistory()} engine, run now, on this
     * host's own compactor, window and estimate. A landed ahead-of-need
     * summary whose fingerprint still matches rides the fold; a landed HOLD
     * REFUSES it outright (CTX-4's typed-route verdict, the exact branch
     * `Chat::landModelCompaction()` lands), and anything else folds on the heuristic
     * the engine builds from the history itself. The echo and report rows come
     * back INSIDE the folded history — announce what the fold appended, and
     * the transcript matches the terminal row for row.
     */
    private function typedCompact(string $text): void
    {
        $compaction = $this->compaction();
        $held = $this->aheadOfNeed;
        $matches = $held !== null
            && $held['fingerprint']->matches($compaction->historyFingerprint($this->compactor, $this->history));

        if ($matches) {
            $this->aheadOfNeed = null;
            if ($held['safeguardHold'] !== null) {
                // The fold's own echo never lands on this branch, so the
                // refusal carries it: the draft must stay visible in the
                // transcript even though nothing was condensed.
                $this->appendMessageRow(Message::user($text)->withUiOnly());
                $this->appendMessageRow(Message::notice($compaction->safeguardHoldRefusal($held['safeguardHold'])));

                return;
            }
        }

        $summaries = $matches && ($held['summaries'] ?? []) !== []
            ? CompactionService::splicedSummaries($held['summaries'], $this->history)
            : [];

        $before = count($this->history);
        $this->history = $compaction->compactedHistory(
            $this->compactor,
            $text,
            $this->history,
            $summaries,
            fn (): int => $this->meter()->limit($this->backend()),
            $this->estimate(...),
        );
        $this->save();
        foreach (array_slice($this->history, $before) as $row) {
            $this->announceTranscriptRow($row);
        }

        // C-CTX-2.d: the fold rewrote the history the token breakdown and the
        // spliced sources ride on — but only past the safeguard-hold door,
        // which returns above without folding anything.
        $this->announce(SessionEvent::new(SessionEvent::CONTEXT_CHANGED, ['reason' => 'compaction'], $this->sessionId));
    }

    private function runAsync(CommandEffect $effect): void
    {
        $describe = $effect->describe();
        try {
            $promise = ($effect->run())();
        } catch (\Throwable $e) {
            $this->appendCommandRow(Lang::t('host.command.error', ['error' => $e->getMessage()]), true);

            return;
        }

        $promise->then(
            function (mixed $value) use ($describe): void {
                $row = $describe === null ? null : $describe($value);
                if ($row instanceof Message) {
                    $this->appendMessageRow($row);
                }
            },
            function (\Throwable $e): void {
                $this->appendCommandRow(Lang::t('host.command.error', ['error' => $e->getMessage()]), true);
            },
        );
    }

    private function occupy(CommandEffect $effect): void
    {
        $cancellation = $effect->cancellation() ?? new CancellationToken();
        $this->turn = $cancellation;
        $this->workflowTurn = $effect->isWorkflow();
        $generation = ++$this->generation;

        $land = function (string|Message $report, bool $error = false) use ($generation): void {
            // A Message report carries its own verdict (ISS-097); a bare
            // string is an ordinary local answer.
            $report instanceof Message
                ? $this->appendMessageRow($report)
                : $this->appendCommandRow($report, $error);
            if ($generation !== $this->generation) {
                // Cancelled: the report is the record of what ran, and the
                // session it held was released by the cancel.
                return;
            }
            $this->turn = null;
            $this->workflowTurn = false;
            $this->releaseQueue();
        };

        try {
            $promise = ($effect->run())();
        } catch (\Throwable $e) {
            $land(Lang::t('host.command.error', ['error' => $e->getMessage()]), true);

            return;
        }

        $promise->then($land, static fn (\Throwable $e) => $land(Lang::t('host.command.error', ['error' => $e->getMessage()]), true));
    }

    /**
     * One UI-only reply row a command's later answer lands as, saved.
     *
     * $error is the row's own verdict (ISS-097): the command's failure arms
     * pass true so the transcript paints the sentence in the error colour,
     * while a successful answer keeps the plain `local` label. The renderer
     * never sniffs the text, so the flag has to arrive with the producer.
     */
    private function appendCommandRow(string $text, bool $error = false): void
    {
        $this->appendMessageRow(Message::assistant($text)->withUiOnly()->withError($error));
    }

    /**
     * The one place a later-landing row enters history: the row is built,
     * verdict and all, by its producer (ISS-097) — this writes and saves it.
     */
    private function appendMessageRow(Message $row): void
    {
        $this->history[] = $row;
        $this->save();
        $this->announceTranscriptRow($row);
    }

    /**
     * `!$bang` (roadmap 5.14g), as the TUI runs it: a Deny rule or plan mode's
     * read-only rule refuses it ({@see BangShell::refusal()}); otherwise it
     * runs off this thread, holding the session under its own token so a
     * prompt typed meanwhile queues behind it and {@see cancel()} kills its
     * process tree, and its result lands as the user-role row the model reads
     * on the next turn.
     */
    private function runBang(string $bang): TurnTicket
    {
        $turns = $this->turns();
        $root = $this->root();
        $refused = BangShell::refusal($bang, $this->workspace->permissionGate, $root);
        if ($refused !== null) {
            $notice = $turns->bangRefusedNotice($bang, $refused);
            $row = Message::notice($notice);
            $this->history[] = $row;
            $this->save();
            $this->announceTranscriptRow($row);

            return TurnTicket::refused($notice);
        }

        $running = Message::notice($turns->bangRunningNotice($bang));
        $this->history[] = $running;
        $cancellation = new CancellationToken();
        $this->turn = $cancellation;
        $generation = ++$this->generation;
        $this->save();
        $this->announceTranscriptRow($running);
        $this->announceSessionStatus('busy');

        $cmd = (BangShell::cmd($bang, $root, $cancellation, $generation))();
        $cmd->promise->then(function (mixed $msg) use ($generation): void {
            // A result for a command already cancelled is stale, as in the TUI.
            if (!$msg instanceof BangShellResultMsg || $generation !== $this->generation) {
                return;
            }
            $this->history[] = $msg->message;
            $this->turn = null;
            $this->save();
            $this->announceTranscriptRow($msg->message);
            $this->releaseQueue();
            if (!$this->isBusy()) {
                $this->announceSessionStatus('idle');
            }
        });

        return TurnTicket::handled();
    }

    /**
     * Announce one command or bang transcript row as the `message.created`
     * event a driver renders it from (ISS-056). The rows were saved and read
     * back on the next turn, but no event carried them, so a web client
     * watched a refused command answer with silence. Same shape (and same
     * fail-soft) as {@see TurnController::recordMessagesCreated()}, widened
     * to every role — a command row is system or assistant, never user.
     */
    private function announceTranscriptRow(Message $row): void
    {
        if ($this->transcripts->events() === null || $this->transcripts->persists() !== true) {
            return;
        }

        try {
            $identity = $this->transcripts->identify($this->sessionId, $row);
            if ($identity === null) {
                return;
            }
            $kind = match ($row->role) {
                Role::User => 'user',
                Role::Assistant => 'assistant',
                default => 'notice',
            };
            $this->announce(SessionEvent::new(SessionEvent::MESSAGE_CREATED, [
                'messageId' => $identity[0],
                'ref' => $identity[1],
                'role' => $row->role->value,
                'kind' => $kind,
                'content' => $row->content,
                'createdAt' => (int) floor(microtime(true) * 1000),
            ], $this->sessionId));
        } catch (\Throwable) {
            // The row is saved; a command does not fail because its audience
            // missed the event, exactly as a turn does not.
        }
    }

    /**
     * Announce the session's status word (ISS-056): a `!bang` holds the
     * session without a turn, so no `turn.started`/`turn.completed` marks the
     * busy window for a driver that watches status instead of guessing.
     */
    private function announceSessionStatus(string $status): void
    {
        $this->announce(SessionEvent::new(SessionEvent::SESSION_STATUS, [
            'status' => $status,
        ], $this->sessionId));
    }

    /** The session as a host command reads it ({@see CommandContext}). */
    private function commandContext(): CommandContext
    {
        // The session's own view of its backend: an override this host's
        // `/model` stored must be what the bodies measure and switch from
        // (WEB-2); with no override this is exactly what was read before.
        $backend = $this->sessionBackend ?? $this->workspace->backend;

        return CommandContext::new(
            history: $this->history,
            sessionId: $this->sessionId,
            root: $this->workspace->root,
            permissionGate: $this->workspace->permissionGate,
            backend: $backend,
            titleBackend: $this->workspace->titleBackend,
            agentManager: $this->workspace->agentManager,
            sessionStore: $this->workspace->sessionStore,
            transcripts: $this->transcripts,
            turnRunner: $this->runner(),
            rulesState: $this->workspace->rulesState,
            workflowEngine: $this->workspace->workflowEngine,
            backgroundSupervisor: $this->workspace->backgroundSupervisor,
            memoryStore: $this->workspace->memoryStore,
            contextTokenLimit: $backend === null ? null : $this->meter()->limit($backend),
            contextTokens: $this->estimate($this->history),
            workspace: $this->workspace,
            grants: $this->grants,
            scheduleRegistry: $this->workspace->service(\SugarCraft\Code\Background\ScheduleRegistry::class),
            spendTracker: $this->spend,
            spendCap: $this->workspace->maxCostUsd,
        );
    }

    /**
     * WEB-3a: this session's structured context sources for `context.sources`
     * ({@see \SugarCraft\Code\Protocol\Methods\ContextMethods}). Built through
     * the same {@see commandContext()} `/context` runs with and measured by
     * the same {@see \SugarCraft\Code\Host\ContextSources} provider — with the
     * history figure taken from this host's own calibrated estimate, exactly
     * as the status bar shows it. No `Chat` state is reached: the session IS
     * the provider (the seam WEB-3a's brief anticipated).
     *
     * @return array<string, mixed>
     */
    public function contextSources(): array
    {
        $context = $this->commandContext();
        $instructionFiles = ContextSources::priceInstructionFiles($this->workspace->root);

        return ContextSources::answer(
            workspace: $this->workspace,
            breakdown: ContextSources::measure($context, $instructionFiles, historyTokens: $this->estimate($this->history)),
            instructionFiles: $instructionFiles,
        );
    }

    // ── dispatch ───────────────────────────────────────────────────────

    /**
     * Start the turn: commit the prompt with its reports and notes, take the
     * `/rewind` checkpoint of the state BEFORE it (audit SES-1), count it for
     * the picker, announce its row (`message.created`), and run it.
     *
     * @param list<Message> $baseHistory the history the turn is sent against
     * @param list<Message> $reports the rewrite reports that precede the prompt
     * @param list<Message> $notes the hook notes that ride beside it
     * @param bool|null $refilled the breaker's measurement when the tier's
     *        rewrite goes out with this turn, else null
     * @param list<\SugarCraft\Code\Attachment> $attachments pasted images
     *        riding this prompt (WEB-5b)
     * @param ?PermissionMode $modeCeiling the submission's gate ceiling for
     *        this turn only (C-AGT-2.b); null names none
     */
    private function dispatch(
        string $text,
        bool $resolveMentions,
        string $draft,
        array $baseHistory,
        array $reports,
        array $notes,
        ?bool $refilled,
        array $attachments = [],
        ?PermissionMode $modeCeiling = null,
        ?string $oneTurnModel = null,
        ?string $commandModelNotice = null,
    ): TurnTicket {
        $turns = $this->turns();
        $preTurnHistory = [...$baseHistory, ...$reports];

        [$userTurn, $attachmentNotes] = $turns->userTurnMessage(
            $text,
            $resolveMentions,
            $this->root(),
            $this->workspace->sessionStore,
            $this->workspace->permissionGate,
            $this->workspace->skills,
            mcpProvider: $this->mcpProvider,
            extraAttachments: $attachments,
        );
        $newTurnMessages = [...$reports, ...$notes, ...$attachmentNotes, $userTurn];
        if ($commandModelNotice !== null) {
            // C-EXT-2.e: the model fall-back sentence, reported immediately
            // ahead of the prompt it speaks for — the same slot Chat::submit()
            // gives the identical notice on the TUI route.
            array_splice($newTurnMessages, count($newTurnMessages) - 1, 0, [Message::notice($commandModelNotice)]);
        }

        // The 70% reminder: dropped from the base unconditionally, appended
        // only when due, so the history holds at most one, current, copy.
        $tokenLimit = $this->meter()->limit($this->backend());
        // ISS-015: the reminder tier reads the same wider numerator the submit
        // path's tiers do — calibrated estimate plus tool-argument bytes.
        $dueForReminder = $this->compactor->shouldSendReminder(
            CompactionService::compactionWire($baseHistory),
            $tokenLimit,
            CompactionService::tierTokenCount($this->estimate($baseHistory), $baseHistory),
        );
        $preStrip = $baseHistory;
        $baseHistory = CompactionService::withoutContextReminders($baseHistory);
        if ($dueForReminder) {
            $newTurnMessages[] = CompactionService::contextReminderMessage($this->estimate($preStrip));
        }

        // ISS-064: ahead-of-need summarisation (roadmap 2.10, the host mirror
        // of the TUI's dispatchTurn block): at the same 70% tier that appends
        // the reminder, ask for the summaries the 85% tier will need, so the
        // turn goes out AND the compaction's model lines are already on their
        // way. One request per cycle: an in-flight one is left alone, a landed
        // one is kept while its fingerprint matches — failures included — and
        // dropped once the tier stops firing because nothing will need it.
        $dispatched = [...$baseHistory, ...$newTurnMessages];
        if ($this->aheadOfNeed !== null && $this->aheadOfNeed['summaries'] !== null && (
            !$dueForReminder
            || !$this->aheadOfNeed['fingerprint']->matches(
                $this->compaction()->historyFingerprint($this->compactor, $dispatched),
            )
        )) {
            $this->aheadOfNeed = null;
        }
        if ($dueForReminder && $this->aheadOfNeed === null
            && $this->workspace->backend !== null
            && !$this->ledger()->capReached($this->spend, $this->workspace->maxCostUsd)
        ) {
            $this->requestAheadOfNeed($dispatched, $baseHistory);
        }

        if ($refilled !== null) {
            $this->consecutiveRefills = $this->compaction()->refillCount($this->consecutiveRefills, $refilled, true);
        }

        $this->history = [...$baseHistory, ...$newTurnMessages];
        $this->estimateAtDispatch = $this->meter()->rawTokens($baseHistory);
        $cancellation = new CancellationToken();
        $this->turn = $cancellation;
        $generation = ++$this->generation;

        $capture = $turns->saveCheckpoint(
            $this->workspace->sessionStore,
            $this->sessionId,
            $this->workspace->root,
            $turns->checkpointState($preTurnHistory, $draft, null, $this->sessionId),
        );
        $turns->recordTurn($this->workspace->sessionStore, $this->sessionId, $newTurnMessages);
        $this->save();
        $created = $turns->recordMessagesCreated($this->transcripts, $this->sessionId, $newTurnMessages, $this->runner());

        // C-CTX-2.a: two using() windows, not one. The first covers
        // start() — where the one-turn notice budget opens on THIS session's
        // sink (the drain owner passes its own, per E199's rule) — and the
        // second covers the fork itself: EngineBackend reads the routed sink
        // in the parent before pcntl_fork, so $run() must be called inside
        // the window for the child's records to land here and not in the
        // process sink nobody drains on serve.
        // C-EXT-2.e: the one-turn pin is applied HERE and nowhere else — a
        // withModel() clone handed to the runner, never stored on the session,
        // so the next dispatch reads $this->backend() at the session's model
        // by construction. Same rule as Chat::scheduleBackendCompletion().
        $turnBackend = $this->backend($modeCeiling);
        if ($oneTurnModel !== null && $turnBackend instanceof EngineBackend) {
            $turnBackend = $turnBackend->withModel($oneTurnModel);
        }

        $run = RuntimeNoticeSink::using($this->notices, fn (): \Closure => $this->runner()->start(
            backend: TurnRunner::backendForTurn(
                $turnBackend,
                $this->workspace->maxCostUsd,
                $this->spentUsd(),
                $this->compactor->config(),
                $this->sessionId,
                $this->grants,
            ),
            history: $this->history,
            inbox: $this->inbox,
            generation: $generation,
            cancellation: $cancellation,
            notices: $this->notices,
            agentManager: $this->workspace->agentManager,
            transcripts: $this->transcripts,
            sessionId: $this->sessionId,
        ));
        $turnId = $this->runner()->turnIdOf($cancellation);

        // The workspace snapshot belongs to the checkpoint and is complete
        // before the turn can fork and write a file.
        if ($capture !== null) {
            $capture();
        }

        RuntimeNoticeSink::using($this->notices, function () use ($run, $generation, $cancellation): void {
            $this->settleWith($run(), $generation, $cancellation);
        });

        $last = $created === [] ? null : $created[\count($created) - 1];

        return TurnTicket::started($turnId, $last['messageId'] ?? null);
    }

    /**
     * @param PromiseInterface<\SugarCraft\Core\Msg> $promise
     */
    private function settleWith(PromiseInterface $promise, int $generation, CancellationToken $turn): void
    {
        $promise->then(function (mixed $msg) use ($generation, $turn): void {
            if ($msg instanceof BackendToolEventsMsg) {
                if ($generation === $this->generation) {
                    foreach ($msg->events as $event) {
                        $this->fold($turn, $event);
                    }
                }
                $this->runner()->completeParked($msg->turn);
                $reply = $msg->message;
            } elseif ($msg instanceof AssistantMsg) {
                $reply = $msg->message;
            } else {
                return;
            }

            $this->ledger()->account($this->spend, $reply->usage, $this->usageRecordSink());
            if ($generation !== $this->generation) {
                // A cancelled turn's reply: billed, never shown.
                return;
            }

            $this->calibration = $this->meter()->calibrationFrom($this->estimateAtDispatch, $reply->usage) ?? $this->calibration;
            $this->foldLeftoverAnswers($generation);
            $this->inbox->exchangeArray([]);
            $this->settleOpenAsks('the turn ended');
            $this->history[] = $reply;
            $this->turn = null;
            $this->applyApprovedPlanExit();
            $this->save();
            // C-CTX-2.a: land the turn's last notice rows here, before the
            // next queued prompt re-opens the budget — the settle of the
            // turn is the read point its child's final writes precede, and
            // a wake left un-drained across release() would drop them.
            $this->pumpRuntimeNotices();
            $this->releaseQueue();

            // C-CTX-6.a: settling is the event that RE-STARTS the idle clock
            // when nobody is watching — stamp it and re-arm so the fold is
            // measured one idle window from this reply, never from the
            // prompt of the turn that just ate the previous alarm. The arm
            // cancels first, so a busy-fire alarm this settle supersedes is
            // replaced, never stacked: the watch stays single-slot.
            $this->lastSettledAt = new \DateTimeImmutable();
            $this->armIdleAutoCompaction();

            // W7a-3: a session with a live `/goal` is not done yet — the title
            // model judges this turn and the agent goes back to work until the
            // goal is met, the TUI's continuation loop (3.D-3) served. Only
            // when nothing queued took the turn's place: the user's own
            // message carries the conversation on, and the turn it starts is
            // judged when it settles. The decision table is the shared
            // {@see GoalLoop}'s, so budget and stop conditions cannot drift.
            $carriedOn = $this->turn !== null;
            $goal = $carriedOn ? null : GoalState::activeIn($this->history);
            if ($goal !== null) {
                $this->scheduleGoalJudge($goal);
            }

            // C-CTX-2.c: the same carried-on verdict feeds the settle work
            // table — a queued prompt carrying the conversation on means the
            // conversation never really settled, so auto-memory, the
            // auto-commit and the dream pass all ride the settle of the turn
            // that prompt starts, exactly as Chat's gate does.
            $this->runSettleWork($carriedOn, $goal !== null);

            // C-CTX-2.d: whatever the turn's tools wrote, the notice landers
            // appended, or a `/memory`-style row settled into the stores, the
            // answer is the same to a client — re-read `context.sources`.
            // One announce per settle, after the settle work has been armed,
            // so a client that reloads on hearing it measures a history that
            // already carries the reply.
            $this->announce(SessionEvent::new(SessionEvent::CONTEXT_CHANGED, ['reason' => 'settle'], $this->sessionId));
        });
    }

    /**
     * Ask the shared decision table what the settled turn earns and run it
     * (C-CTX-2.c — the served mirror of {@see \SugarCraft\Code\Chat\Handlers\HandlesTurnCompletion}'s
     * settle arms): auto-memory consolidation, the `turn`-mode auto-commit
     * and the dream pass, each under THIS session's notice sink so the
     * auxiliary call's runtime records land where C-CTX-2.a drains them, and
     * each landed through its own bill-then-show lander.
     *
     * The suggestion slot runs here too (C-CTX-2.d): a served session has a
     * composer — the web client's input box — and the answer rides it as the
     * ephemeral `prompt.suggested` event through
     * {@see self::landPromptSuggestion()}, never as a transcript row.
     */
    private function runSettleWork(bool $carriedOn, bool $goalActive): void
    {
        $engine = $this->backend();
        $work = TurnSettleWork::plan(
            carriedOn: $carriedOn,
            goalActive: $goalActive,
            memoryStore: $this->workspace->memoryStore,
            engineBackend: $engine,
            summaryBackend: $this->workspace->summaryBackend,
            titleBackend: $this->workspace->titleBackend,
            history: $this->history,
            capReached: $this->ledger()->capReached($this->spend, $this->workspace->maxCostUsd),
            sessionId: $this->sessionId,
            generation: $this->generation,
            projectRoot: $this->root(),
            userConfig: $this->workspace->userConfig,
            sessionStore: $this->workspace->sessionStore,
        );

        if ($work->suggestion !== null) {
            $factory = $work->suggestion;
            RuntimeNoticeSink::using($this->notices, static fn(): PromiseInterface => $factory())
                ->then(function (mixed $msg): void {
                    $this->landPromptSuggestion($msg);
                });
        }
        if ($work->consolidation !== null) {
            $factory = $work->consolidation;
            RuntimeNoticeSink::using($this->notices, static fn(): PromiseInterface => $factory())
                ->then(function (mixed $msg): void {
                    $this->landConsolidation($msg);
                });
        }
        if ($work->autoCommit !== null) {
            $factory = $work->autoCommit;
            RuntimeNoticeSink::using($this->notices, static fn(): PromiseInterface => $factory())
                ->then(function (mixed $msg): void {
                    $this->landAutoCommit($msg);
                });
        }
        if ($work->dream !== null) {
            $factory = $work->dream;
            RuntimeNoticeSink::using($this->notices, static fn(): PromiseInterface => $factory())
                ->then(function (mixed $msg) use ($engine): void {
                    $this->landDream($msg, $engine);
                });
        }
    }

    /**
     * Land a prompt-suggestion answer (C-CTX-2.d, mirroring Chat's
     * `PromptSuggestionMsg` arm): billed FIRST whatever becomes of it — the
     * guess ran on the user's key on the title engine, so the OBS-3 model
     * the ledger sees is that engine's — then shown to the composer as the
     * ephemeral `prompt.suggested` event only while it is still current:
     * same generation, same history length, same session, nothing in flight.
     * A suggestion is NEVER persisted — the point is a ghost prompt the user
     * may decline, and a transcript row would show the model its own guess
     * as conversation on the next turn. An answer arriving late, or after
     * {@see self::release()}, is billed and dropped.
     */
    private function landPromptSuggestion(mixed $msg): void
    {
        if (!$msg instanceof PromptSuggestionMsg) {
            return;
        }

        $title = $this->workspace->titleBackend;
        $this->ledger()->account($this->spend, $msg->usage, $this->usageRecordSink(), $title instanceof EngineBackend ? $title->model() : null);
        if ($this->released) {
            return;
        }

        // Chat's staleness test verbatim: any of these means the guess is no
        // longer about the turn that settled — a new turn moved the
        // generation, a queued prompt or a landed notice row moved the
        // history, and an in-flight turn has its own settle coming.
        $text = TitleService::sanitizeSuggestion($msg->suggestion);
        $stale = $msg->generation !== $this->generation
            || $msg->historyCount !== count($this->history)
            || $msg->sessionId !== $this->sessionId;
        if ($text === '' || $stale || $this->turn !== null) {
            return;
        }

        $this->announce(SessionEvent::new(SessionEvent::PROMPT_SUGGESTED, [
            'text' => $text,
            'generation' => $msg->generation,
        ], $this->sessionId));
    }

    /**
     * Land a consolidation answer (C-CTX-2.c, mirroring Chat's
     * `MemoryConsolidatedMsg` arm): billed FIRST whatever the outcome, on the
     * model the summary engine actually ran (the TUI's OBS-3 rule — the
     * ledger must not bill a call to a model that did not make it), then the
     * producer's own notice row, or nothing when the run saved nothing.
     * An answer arriving after {@see self::release()} is billed and dropped.
     */
    private function landConsolidation(mixed $msg): void
    {
        if (!$msg instanceof MemoryConsolidatedMsg) {
            return;
        }

        $summary = $this->workspace->summaryBackend;
        $this->ledger()->account($this->spend, $msg->usage, $this->usageRecordSink(), $summary instanceof EngineBackend ? $summary->model() : null);
        if ($this->released) {
            return;
        }

        $notice = AutoMemoryConsolidator::notice($msg);
        if ($notice === null) {
            return;
        }

        $this->appendMessageRow(Message::notice($notice));
    }

    /**
     * Land an auto-commit answer (C-CTX-2.c, mirroring Chat's
     * `AutoCommittedMsg` arm): the commit is already made (or refused), so
     * the subject's provider call is billed first — on the title engine's
     * model per OBS-3 — and the line saying what was committed, or why not,
     * ALWAYS shows. An answer arriving after {@see self::release()} is
     * billed and dropped.
     */
    private function landAutoCommit(mixed $msg): void
    {
        if (!$msg instanceof AutoCommittedMsg) {
            return;
        }

        $title = $this->workspace->titleBackend;
        $this->ledger()->account($this->spend, $msg->usage, $this->usageRecordSink(), $title instanceof EngineBackend ? $title->model() : null);
        if ($this->released) {
            return;
        }

        $this->appendMessageRow(Message::notice($msg->notice()));
    }

    /**
     * Land a dream-pass answer (C-CTX-2.c, mirroring Chat's
     * `DreamPassCompletedMsg` arm): billed first — the pass ran on the
     * session's own engine, so the engine {@see self::runSettleWork()} handed
     * the table IS the OBS-3 model here — then the producer's notice, or
     * nothing when the pass neither saved nor proposed. An answer arriving
     * after {@see self::release()} is billed and dropped.
     */
    private function landDream(mixed $msg, ?Backend $engine): void
    {
        if (!$msg instanceof DreamPassCompletedMsg) {
            return;
        }

        $this->ledger()->account($this->spend, $msg->usage, $this->usageRecordSink(), $engine instanceof EngineBackend ? $engine->model() : null);
        if ($this->released) {
            return;
        }

        $notice = DreamPass::notice($msg);
        if ($notice === null) {
            return;
        }

        $this->appendMessageRow(Message::notice($notice));
    }

    /**
     * Hold the turn slot and ask the title model to judge the live goal
     * (W7a-3, mirroring {@see Chat::scheduleGoalJudge()} exactly): the bump
     * of the generation strands anything already in flight, and the verdict
     * lands through {@see self::landGoalVerdict()} on this promise's own
     * channel — the same shape the ahead-of-need compaction uses.
     */
    private function scheduleGoalJudge(GoalState $goal): void
    {
        $judge = $this->workspace->titleBackend;
        $stop = GoalLoop::refusalReason($judge, $this->ledger()->capReached($this->spend, $this->workspace->maxCostUsd));
        if ($stop !== null || $judge === null) {
            $this->endGoal($goal, GoalState::STOPPED, Lang::t('chat.goal.check_stopped', [
                'reason' => $stop,
                'command' => $goal->mode->command(),
            ]));

            return;
        }

        $this->generation++;
        $this->turn = new CancellationToken();
        // C-CTX-2.a: the judge's fork runs under THIS session's sink, the
        // same window rule the ahead-of-need request keeps — its records
        // (a parser warning off the title call, say) are this session's.
        RuntimeNoticeSink::using($this->notices, fn (): PromiseInterface => GoalJudge::new()->call(
            $judge,
            $goal->condition,
            $this->history,
            $this->generation,
            $this->sessionId,
        )())->then(function (mixed $msg): void {
            $this->landGoalVerdict($msg);
        });
    }

    /**
     * Land the `/goal` judge's verdict on a served session (W7a-3): the
     * judge's usage is billed first — stamped with the JUDGE's model, the
     * threaded sink from W7a-1 — then the shared {@see GoalLoop} table says
     * whether the goal ended (met, budget spent, no usable verdict) or the
     * agent goes back to work with what is still missing.
     *
     * A prompt the user queued while the turn ran goes first and the goal
     * waits: the user's own message is the conversation carrying on, and the
     * turn it starts is judged when it settles like any other. The follow-up
     * prompt goes out through {@see self::submit()}, so every admission gate
     * applies to it, refusal row and all.
     */
    private function landGoalVerdict(mixed $msg): void
    {
        if (!$msg instanceof GoalJudgedMsg) {
            return;
        }

        $judge = $this->workspace->titleBackend;
        $this->ledger()->account($this->spend, $msg->usage, $this->usageRecordSink(), $judge instanceof EngineBackend ? $judge->model() : null);
        if ($msg->generation !== $this->generation || $this->turn === null || $msg->sessionId !== $this->sessionId) {
            // A verdict that raced a cancel or a newer turn: billed, never
            // acted on — the live turn owns the decision now.
            return;
        }

        $this->turn = null;
        $goal = GoalState::activeIn($this->history);
        if ($goal === null) {
            // `/goal clear` (or a resume that rewrote the transcript) during
            // judging: nothing to end, keep the queue moving.
            $this->releaseQueue();

            return;
        }

        if ($msg->verdict === null) {
            $this->endGoal($goal, GoalState::STOPPED, GoalLoop::stopped($goal, $msg->error)->notice);

            return;
        }

        $outcome = GoalLoop::afterVerdict($goal, $msg->verdict);
        if ($outcome->kind !== GoalLoop::CONTINUE) {
            $this->endGoal($goal, $outcome->status, $outcome->notice);

            return;
        }

        if ($this->queue !== []) {
            // The user's own message goes first and the goal waits — the
            // turn it starts is judged when it settles like any other.
            $this->releaseQueue();

            return;
        }

        $this->appendMessageRow($outcome->next->toMessage());
        $ticket = $this->submit($outcome->nextPrompt);
        if (!$ticket->isAdmitted()) {
            $this->appendCommandRow((string) $ticket->reason, true);
        }
    }

    /**
     * End $goal with $status and say so in $notice, then let the queue move —
     * the served twin of {@see Chat::endGoal()}/{@see Chat::goalEnded()}
     * minus the TUI's terminal-notification, which a headless session has no
     * terminal to notify.
     */
    private function endGoal(GoalState $goal, string $status, string $notice): void
    {
        $this->appendMessageRow(Message::notice($notice));
        $this->appendMessageRow($goal->withStatus($status)->toMessage());
        $this->releaseQueue();
    }

    /**
     * Roadmap 5.7-2: a plan the user approved through `PlanExit` ends plan
     * mode once its turn is over — the mode the approval names, else
     * `default` — and only while the session is still in `plan`, before a
     * queued prompt starts the next turn ({@see TurnRunner::approvedPlanExit()}).
     */
    private function applyApprovedPlanExit(): void
    {
        if ($this->permissionMode() !== PermissionMode::Plan) {
            return;
        }
        $switch = TurnRunner::approvedPlanExit($this->history);
        if ($switch !== null) {
            $this->setPermissionMode($switch->mode ?? PermissionMode::Default);
        }
    }

    /**
     * Send what is queued, the TUI's release rule: a steer the settled turn
     * delivered is done; the rest go out one at a time until one starts a turn
     * (or parks); a spend cap that would refuse them leaves them queued.
     */
    private function releaseQueue(): void
    {
        $this->queue = TurnController::withoutDeliveredSteers($this->queue, $this->history);
        while ($this->turn === null && $this->queue !== []) {
            if ($this->ledger()->capReached($this->spend, $this->workspace->maxCostUsd)) {
                return;
            }
            $this->admit((string) array_shift($this->queue), SubmitOptions::new());
        }
    }

    /**
     * One reported event, folded as the TUI's live pump folds it: a tool's
     * start becomes its running placeholder and its finish replaces that row
     * (newest first, by call id); every event is reported to the runner, which
     * logs the durable ones and tells the listeners.
     */
    private function fold(?CancellationToken $turn, object $event): void
    {
        $runner = $this->runner();
        $projector = $runner->projector();

        if ($event instanceof ToolStarted) {
            $placeholder = $projector->placeholder($event);
            $this->history[] = $placeholder;
            $runner->recordToolStarted($turn, $event, $placeholder);

            return;
        }

        if ($event instanceof ToolFinished) {
            [$this->history, $row, $replaced] = $projector->finish($this->history, $event);
            $runner->recordToolFinished($turn, $event, $row, $replaced);

            return;
        }

        if ($event instanceof PermissionAsked) {
            // An "always" given earlier answers it without asking — the same
            // rule the TUI's pump applies (Chat::pumpLiveToolEvents()): the
            // running turn's gate was built before the grant, so a later
            // call of the SAME turn, and every exact-call grant, is settled
            // here. Only a question the gate put alone; a hook's is put every
            // time.
            $ask = $event->ask;
            if (!$ask->isSettled() && $ask->offers(PermissionReply::Always)
                && $this->grants->allows($ask->tool, $ask->arguments, $this->root())) {
                $runner->recordEvent($turn, $event);
                $ask->reply(PermissionReply::Once);

                return;
            }
            $this->pendingAsks[$ask->askId] = $ask;
        } elseif ($event instanceof PermissionResolved) {
            unset($this->pendingAsks[$event->askId]);
            $this->remember($event);
        }

        $runner->recordEvent($turn, $event);
    }

    /**
     * The settlements a turn reported after its last fold — a question its
     * end cancelled — logged without the turn, which has already closed: the
     * runner's turn state is gone by the time the reply lands, so they are
     * announced directly, never left for a client to wait on.
     */
    private function foldLeftoverAnswers(int $generation): void
    {
        foreach ($this->inbox->getArrayCopy() as [$entryGeneration, $event]) {
            if ($entryGeneration !== $generation || !$event instanceof PermissionResolved) {
                continue;
            }
            unset($this->pendingAsks[$event->askId]);
            $this->announceResolution($event);
        }
    }

    /**
     * Settle every question still open as cancelled — its turn is gone — and
     * say so in the log, so no client keeps a card up for a child that will
     * never read the answer.
     */
    private function settleOpenAsks(string $reason): void
    {
        $open = $this->pendingAsks;
        $this->pendingAsks = [];
        foreach ($open as $ask) {
            $ask->cancel($reason);
            $this->announceResolution($ask->resolution() ?? PermissionResolved::cancelled($ask->askId, $reason));
        }
    }

    /**
     * `compaction.completed` for the automatic tier's rewrite, when it made
     * one (Appendix O §6.5): which route ran and what it bought, so a client
     * can say why the transcript just got shorter.
     *
     * @param array<string, mixed> $tier {@see TurnController::inlineTier()}'s answer
     */
    private function announceCompaction(array $tier, int $before): void
    {
        $kind = match (true) {
            $tier['compactionNotice'] instanceof Message => 'heuristic',
            $tier['truncationNotice'] instanceof Message => 'truncate',
            default => null,
        };
        if ($kind === null) {
            return;
        }
        $after = (int) ($tier['tokenCount'] ?? $before);
        $this->runner()->announce(SessionEvent::new(SessionEvent::COMPACTION_COMPLETED, [
            'kind' => $kind,
            'before' => $before,
            'after' => $after,
            'savedPct' => $before > 0 ? round(100 * max(0, $before - $after) / $before, 1) : 0.0,
        ], $this->sessionId), $this->transcripts, $this->sessionId);
    }

    private function announceResolution(PermissionResolved $resolution): void
    {
        $this->remember($resolution);
        $runner = $this->runner();
        $event = $runner->projector()->backendEvent($resolution, $this->sessionId, null);
        if ($event !== null) {
            $runner->announce($event, $this->transcripts, $this->sessionId);
        }
    }

    private function remember(PermissionResolved $resolution): void
    {
        unset($this->resolutions[$resolution->askId]);
        $this->resolutions[$resolution->askId] = $resolution;
        if (\count($this->resolutions) > self::RESOLUTIONS_KEPT) {
            array_shift($this->resolutions);
        }
    }

    // ── collaborators ──────────────────────────────────────────────────

    private function save(): void
    {
        try {
            $this->transcripts->save($this->sessionId, $this->history);
        } catch (\Throwable) {
            // A failed save never costs the session its turn; the next one retries.
        }
    }

    /** @param list<Message> $history */
    private function estimate(array $history): int
    {
        return $this->meter()->estimate($history, $this->calibration);
    }

    /**
     * The backend this session runs on: its own `/model` override when one
     * was chosen (WEB-2), else the workspace's — judged by this session's own
     * permission mode when it has one: a gate in that mode over the same
     * rules, so a client choosing `accept-edits` for one session changes
     * nothing for another.
     *
     * @param ?PermissionMode $ceiling when given (C-AGT-2.b, from
     *        {@see SubmitOptions::withModeCeiling()}), this ONE turn's gate
     *        is additionally held at or below it; the next independent
     *        submission, and every out-of-turn surface
     *        ({@see permissionGate()}), read the session mode alone.
     */
    private function backend(?PermissionMode $ceiling = null): Backend
    {
        $backend = ($this->sessionBackend ?? $this->workspace->backend) ?? throw new \LogicException('This session has no backend.');
        if (!$backend instanceof EngineBackend) {
            return $backend;
        }
        $base = $backend->permissionGate();
        $gated = $this->sessionGate($base, $ceiling);
        if ($gated === null || $gated === $base) {
            return $backend;
        }

        return $backend->withPermissionGate($gated);
    }

    /**
     * The gate the session's backend carries: the EngineBackend's own, or —
     * for a backend without gates — the workspace's.
     */
    private function basePermissionGate(): ?PermissionGate
    {
        $backend = $this->workspace->backend;

        return $backend instanceof EngineBackend ? $backend->permissionGate() : $this->workspace->permissionGate;
    }

    /**
     * $base itself unless this session names its own mode or $ceiling caps
     * this turn below the mode it has (C-AGT-2.b), in which case a gate in
     * the stricter of the two over the same rules — the single construction
     * site for session-cloned gates ({@see permissionGate()},
     * {@see backend()}). A ceiling never manufactures a gate where none is
     * wired, and a null $ceiling answers exactly as before.
     */
    private function sessionGate(?PermissionGate $base, ?PermissionMode $ceiling = null): ?PermissionGate
    {
        $mode = $this->permissionMode ?? $base?->mode();
        if ($ceiling !== null) {
            if ($mode === null) {
                return $base;
            }
            $mode = $mode->stricterOf($ceiling);
        }
        if ($mode === null || $base?->mode() === $mode) {
            return $base;
        }

        return new PermissionGate(
            $mode,
            $base?->rules() ?? [],
            new SafetyClassifier(),
            'session',
        );
    }

    private function root(): string
    {
        return $this->workspace->root ?? (getcwd() ?: '.');
    }

    private function directive(CommandSpec $spec): \Closure
    {
        return $this->turns()->commandDirective(
            $spec,
            $this->root(),
            $this->workspace->projectCommandsTrusted,
            $this->workspace->permissionGate,
        );
    }

    private function turns(): TurnController
    {
        $service = $this->workspace->service(TurnController::class);

        return $service instanceof TurnController ? $service : TurnController::new();
    }

    private function runner(): TurnRunner
    {
        $service = $this->workspace->service(TurnRunner::class);

        return $service instanceof TurnRunner ? $service : TurnRunner::of($this->inbox);
    }

    private function compaction(): CompactionService
    {
        $service = $this->workspace->service(CompactionService::class);

        return $service instanceof CompactionService ? $service : CompactionService::new();
    }

    private function ledger(): SpendLedger
    {
        $service = $this->workspace->service(SpendLedger::class);

        return $service instanceof SpendLedger ? $service : SpendLedger::new();
    }

    /**
     * The durable ledger sink SES-1 hands the host's accounting sites: one
     * `usage_records` row per accounted call, appended through the same
     * single guard the in-process tracker rides at {@see SpendLedger::account()}.
     * This is the serve path's mirror of {@see Chat::usageRecordSink()} —
     * SessionHost is NOT a Chat (its own class docblock says so) and settles
     * its turns through its own two accounting sites, so a sink handed only
     * to Chat would persist nothing for `serve --continue`. Null when the
     * workspace holds only the base store; accounting then stays exactly as
     * durable as it was before the table.
     *
     * The store's kind is judged at build time and the session id and model
     * are read at CALL time — not because a host can late-mint (its id is
     * `readonly`, named before the first turn), but because one shape for
     * both sinks is one shape to keep correct.
     *
     * W7a-1: the threaded model wins, exactly as Chat's sink prefers it, and
     * the fallback reads {@see backend()} — the SESSION's live backend — not
     * the workspace's. After a served `/model` the workspace backend is the
     * launch provider while turns run on the override; reading the workspace
     * here billed every auxiliary call against the model that no longer
     * answers.
     */
    private function usageRecordSink(): ?\Closure
    {
        $store = $this->workspace->sessionStore;
        if (!$store instanceof EnhancedSessionStore) {
            return null;
        }

        return function (Usage $usage, ?string $model = null) use ($store): void {
            $backend = $this->backend();
            $store->appendUsageRecord(
                $this->sessionId,
                $usage,
                $model ?? ($backend instanceof EngineBackend ? $backend->model() : null),
            );
        };
    }

    private function meter(): ContextMeter
    {
        $service = $this->workspace->service(ContextMeter::class);

        return $service instanceof ContextMeter ? $service : ContextMeter::new();
    }
}
