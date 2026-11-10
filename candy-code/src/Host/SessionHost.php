<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Agents\Live\SessionMailbox;
use SugarCraft\Code\Agents\PrimaryAgent;
use SugarCraft\Code\App\AssistantMsg;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CacheHealthWatch;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Backend\InteractiveTurn;
use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Backend\PendingClientCall;
use SugarCraft\Code\Backend\QueueMode;
use SugarCraft\Code\Backend\SummarisesWithCache;
use SugarCraft\Code\Background\ScheduleRegistry;
use SugarCraft\Code\App\BackendToolEventsMsg;
use SugarCraft\Code\App\BangShellResultMsg;
use SugarCraft\Code\Chat;
use SugarCraft\Code\CommandParser;
use SugarCraft\Code\Commands\BangShell;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Channels\ChannelToolPolicyService;
use SugarCraft\Code\Commands\McpPromptCommands;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Commands\Specs\BuiltInCommands;
use SugarCraft\Code\Context\Compaction\HistoryFingerprint;
use SugarCraft\Code\Context\CompactorConfig;
use SugarCraft\Code\Context\ContextCompactor;
use SugarCraft\Code\Context\IdleCompactionPolicy;
use SugarCraft\Code\Context\KeepWarmPolicy;
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
use SugarCraft\Code\Observability\EventSink;
use SugarCraft\Code\Observability\EventSinkFactory;
use SugarCraft\Code\Observability\NullEventSink;
use SugarCraft\Code\App\HistoryCompactedMsg;
use SugarCraft\Code\App\PromptSuggestionMsg;
use SugarCraft\Code\Host\SessionHost\SessionLandings;
use SugarCraft\Code\Host\SessionHost\SessionClientTools;
use SugarCraft\Code\Host\SessionHost\SessionIdleCompaction;
use SugarCraft\Code\Host\SessionHost\SessionCommands;
use SugarCraft\Code\Host\SessionHost\SessionAdmission;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandEffect;
use SugarCraft\Code\Host\Commands\CommandEffectKind;
use SugarCraft\Code\Host\Commands\CommandResult;
use SugarCraft\Code\Host\Commands\CommandText;
use SugarCraft\Code\Host\ClientTools\ClientToolRegistry;
use SugarCraft\Code\Host\ClientTools\ClientToolSpec;
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
use SugarCraft\Core\Util\Sanitize;

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
    use SessionLandings;
    use SessionClientTools;
    use SessionIdleCompaction;
    use SessionCommands;
    use SessionAdmission;

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
     * @var null|array{id:string,fingerprint:HistoryFingerprint,summaries:?array<string,string>,safeguardHold:?string,model?:string}
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
     * C-AGT-4.b: the primary agent this session was switched to by
     * `/agent use` through a host surface. The NAME lives here, not a
     * narrowed backend — {@see backend()} resolves it against the roster
     * and applies the narrowing afresh on every read (MAJOR-2: the switch
     * is honoured, not just recorded, on the serve path). Like
     * {@see self::$modelOverride} it dies with the host: the choice is
     * session-scoped and never written to config. A name that stops
     * resolving (the roster was rebuilt without it) runs un-narrowed.
     */
    private ?string $primaryAgentOverride = null;

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
     * C-TOOL-6.h: the tools clients have hosted for THIS session, one
     * registry per host — the same lifetime law as the transcript lease.
     * A connection's registrations die with it ({@see
     * forgetClientTools()}); the roster a turn dispatches with is fixed at
     * turn start, so a registration reaches the NEXT turn's tools, never
     * the one in flight. Whether any client may register at all is the
     * `server.clientTools` door — that setting belongs to the protocol
     * step (6.i), not to this substrate.
     */
    private readonly ClientToolRegistry $clientTools;

    /**
     * C-TOOL-6.h: this session's open client-hosted calls, by callId, each
     * paired with the registration that carried it — the handle
     * {@see resolveClientCall()} needs to answer a call and to refuse an
     * answer from a stale registration with the same reading as a stale id.
     * Settled-but-unpruned entries (a deadline that fired, a turn that
     * ended) are dropped lazily at the next call.
     *
     * @var array<string, array{pending: PendingClientCall, clientKey: string, registrationId: string}>
     */
    private array $pendingClientCalls = [];

    /**
     * C-TOOL-6.h: the serve-side listener for a freshly opened client call
     * ({@see onClientCall()}); null until 6.i wires it.
     *
     * @var (\Closure(PendingClientCall, string, string): void)|null
     */
    private ?\Closure $clientCallListener = null;

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

    /**
     * C-SES-4.d: this session's OTLP export sink — built lazily from the
     * workspace's {@see EventSinkFactory} when serve has the endpoint lit,
     * a {@see NullEventSink} otherwise. One per host, never shared: the
     * encoder keys open turn spans by step number, and the sessions hosted
     * in one process march over identical numbers. {@see fold()} is the
     * single choke that feeds it; {@see release()} flushes it best-effort
     * beside the transcript, and the factory's one PID-guarded shutdown
     * hook covers whatever a crash skipped. The serve loop never blocks
     * on a POST (C-SES-4.b law) — consume() only ever batches.
     */
    private ?EventSink $otelSink = null;

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
        private readonly bool $ephemeral = false,
        private readonly ?string $ephemeralName = null,
    ) {
        $this->history = array_values($history);
        $this->inbox = new \ArrayObject();
        $this->clientTools = new ClientToolRegistry();
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
     * @param bool $ephemeral C-SES-7.e: an incognito host — it reads the
     *        store like any other but writes nothing: transcript saves, the
     *        usage-record sink, permission-grant persistence and every
     *        post-turn settle pass are skipped
     * @param string|null $ephemeralName the name given at create, carried on
     *        the host itself because an ephemeral session has no store row
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
        bool $ephemeral = false,
        ?string $ephemeralName = null,
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
            $ephemeral,
            $ephemeralName,
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
     * C-SES-7.e: true for an incognito host — created with
     * `session.create {ephemeral:true}`, never written to the store.
     */
    public function isEphemeral(): bool
    {
        return $this->ephemeral;
    }

    /**
     * C-SES-7.e: the name carried by an ephemeral host, which has no store
     * row to read it from. Null for a persisted host (its name lives in the
     * sessions table); for an ephemeral host it is the `name` given at
     * create, and the summary falls back to it.
     */
    public function ephemeralName(): ?string
    {
        return $this->ephemeralName;
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
     * C-AGT-4.b: the preset name this session's `/agent use` switched the
     * primary agent to, or null while it runs the plain session default
     * (`use default` stores null exactly like the terminal's revert). The
     * serve path honours the name: each turn dispatch resolves it against
     * the roster and narrows through {@see PrimaryAgent::apply()}, so the
     * badge and the wire agree while the host lives. The same host-lifetime
     * law as {@see self::$modelOverride}: a restart resolves the launch
     * roster afresh and finds no override.
     */
    public function primaryAgentOverride(): ?string
    {
        return $this->primaryAgentOverride;
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
     * The gate this session is consented by — the session resolution: the
     * session's own mode over the base rules when it names one, else the
     * base gate itself. {@see backend()} recomputes this per dispatch and
     * then folds the primary agent's own mode on top, so an agent hat
     * narrows turns only — it never widens out-of-turn consent. Out-of-turn
     * side-effect surfaces (`generate.submit`) must consult this, not the
     * bare workspace gate, or a bypass-admitted session would run tools
     * freely in-turn yet be refused at the RPC door (R15 MAJOR-1).
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
            if (!$this->ephemeral) {
                $this->transcripts->savePermissionGrants($this->sessionId, $this->grants->grants());
            }
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

        // C-SEC-8.d: the served twin of the TUI's submit choke. `session.send`
        // reaches this method through `TurnMethods`, so a client cannot route
        // around the strip, and every admission route below — admit,
        // admitWhileBusy, the queue and the steering — carries the cleaned
        // bytes. The joiner-tier rule is the display path's own
        // (`Sanitize::stripInvisibleFormatting`, audit 15b-28), so ZWJ emoji
        // and legitimate script joiners survive here as they do on screen.
        // Attachments ride SubmitOptions and are never touched, which is how
        // a client keeps bytes it MEANT: send them as a file. The notice is
        // announced like the idle advisory beside it — written to history,
        // saved, and pushed at clients before the turn's own rows.
        $stripped = Sanitize::stripInvisibleFormatting($text, $invisibleRemoved);
        if ($invisibleRemoved > 0) {
            $notice = Message::notice(Lang::t('chat.notice.invisible_stripped', ['count' => $invisibleRemoved]));
            $this->history[] = $notice;
            $this->save();
            $this->announceTranscriptRow($notice);
            $text = $stripped;
            if ($text === '') {
                // The whole prompt was invisible formatting: refuse it as the
                // empty prompt it now is, with the notice above as the
                // account of why bytes the client saw as non-empty went away.
                return TurnTicket::refused($this->turns()->emptyPromptNotice())->withIdempotencyKey($options->idempotencyKey);
            }
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
            // The mapping law — typed recall row for a structured frame,
            // plain row otherwise, undecodable dropped — is shared with the
            // TUI via SessionTurnRules::noticeSlotFor() (TUIARCH-04).
            $message = SessionTurnRules::noticeSlotFor($notice);
            if ($message !== null) {
                $this->appendMessageRow($message);
            }
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
     * @return int how many loop prompts and cross-session messages fired (C-AGT-4.e)
     */
    public function tick(int $now): int
    {
        $fired = 0;

        // C-AGT-4.e: this session's cross-session mail rides the same beat as
        // its loops.  Draining BEFORE the schedule lookup means mail lands even
        // on a host with no ScheduleRegistry; each message enters exactly as a
        // due loop row does — notice row, save, transcript announcement, and a
        // Followup submit — so an idle session turns it next and a busy one
        // finds it waiting behind the running turn.  The frames carry no
        // authority (SessionMailbox::frame states so in the model's own words).
        foreach ($this->drainSessionMail() as [$label, $prompt]) {
            $notice = Message::notice(Lang::t('host.mail.queued', ['from' => $label]));
            $this->history[] = $notice;
            $this->save();
            $this->announceTranscriptRow($notice);
            $this->submit($prompt, SubmitOptions::new()->withDelivery(QueueMode::Followup));
            $fired++;
        }

        $registry = $this->workspace->service(ScheduleRegistry::class);
        if (!$registry instanceof ScheduleRegistry) {
            return $fired;
        }

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
     * Read this session's cross-session mail (C-AGT-4.e).  The mailbox is the
     * same on-disk AgentInbox-signature store a sending session writes into;
     * consuming is what releases it.  Each taken message returns as
     * `[sender label, framed prompt]` — the frame is SessionMailbox's own
     * `<session-message>` shape, which states in the model's own words that
     * the mail carries no authority.  A host whose home is not writable (no
     * default root), or whose mailbox raises anything at all, drains to
     * nothing: a mail fault must never take the loop beat (and thus the
     * schedules) down with it.
     *
     * @return list<array{string, string}>
     */
    private function drainSessionMail(): array
    {
        $drained = [];

        try {
            $mailbox = SessionMailbox::forSession($this->sessionId);
            if ($mailbox === null) {
                return [];
            }
            foreach ($mailbox->take() as $message) {
                $drained[] = [$message->senderName(), SessionMailbox::frame($message)];
            }
        } catch (\Throwable) {
            // Fail quiet beside the loop: a mailbox nobody can read stays
            // where it is; the next beat tries again.
            return $drained;
        }

        return $drained;
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
        // C-SES-4.d: flush the session's OTLP sink beside the transcript —
        // the turn tail that never reached a batch boundary leaves here.
        // Flush consults the property, never otelSink(): a session that
        // folded nothing has no sink and must not mint one to flush it.
        $this->otelSink?->flush();
        $this->lease?->release();
        $this->lease = null;
    }

    /**
     * C-MCP-4.c: one UI-only notice row from a later-landing process event —
     * today the served `mcp auth login` beats that {@see \SugarCraft\Code\Host\ServedMcpLogins}
     * collects and {@see \SugarCraft\Code\Protocol\ServerContext::pump()}
     * routes. The row enters history, save and the `message.created`
     * announcement exactly like a command's own row, carrying the `notice`
     * kind Role::System rows paint with.
     */
    public function appendNotice(string $text): void
    {
        $this->appendMessageRow(Message::notice($text));
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
        // GAP-AGT-20: the served session's own roster answers `@agent:`, the
        // same manager this host's `Task` tool delegates through.
        $agentManager = $this->workspace->agentManager;

        [$userTurn, $attachmentNotes] = $turns->userTurnMessage(
            $text,
            $resolveMentions,
            $this->root(),
            $this->workspace->sessionStore,
            $this->workspace->permissionGate,
            $this->workspace->skills,
            mcpProvider: $this->mcpProvider,
            extraAttachments: $attachments,
            agentRoster: $agentManager === null ? null : static fn (string $name): bool => $agentManager->get($name) !== null,
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
        $reminder = SessionTurnRules::contextReminderFor($dueForReminder, $this->estimate($preStrip));
        if ($reminder !== null) {
            $newTurnMessages[] = $reminder;
        }

        // ISS-064: ahead-of-need summarisation (roadmap 2.10, the host mirror
        // of the TUI's dispatchTurn block): at the same 70% tier that appends
        // the reminder, ask for the summaries the 85% tier will need, so the
        // turn goes out AND the compaction's model lines are already on their
        // way. One request per cycle: an in-flight one is left alone, a landed
        // one is kept while its fingerprint matches — failures included — and
        // dropped once the tier stops firing because nothing will need it.
        $dispatched = [...$baseHistory, ...$newTurnMessages];
        // C-CTX-5.b: the same skip rules the TUI's dispatchTurn block applies,
        // owned by CompactionService: the REQUEST arm goes through
        // aheadOfNeedAllowed() — the 64,000-token floor plus the model — and
        // the DROP arm consults only aheadOfNeedModelMatches(), because the
        // floor is a request rule and re-applying it on the way out would
        // discard an idle-minted small-window slot that was never expected
        // to clear it (MINOR-2, round-1 review). Same tier arithmetic as the
        // reminder above, over the dispatched history.
        $aheadEngine = $this->backend();
        $currentModel = $aheadEngine instanceof EngineBackend ? $aheadEngine->model() : '';
        $aheadTokens = $this->aheadOfNeed !== null || $dueForReminder
            ? CompactionService::tierTokenCount($this->estimate($dispatched), $dispatched)
            : 0;
        if ($this->aheadOfNeed !== null && (
            !CompactionService::aheadOfNeedModelMatches($this->aheadOfNeed['model'] ?? null, $currentModel)
            || SessionTurnRules::summariesOutOfDate(
                $dueForReminder,
                $this->aheadOfNeed['summaries'] !== null,
                $this->aheadOfNeed['summaries'] !== null && $this->aheadOfNeed['fingerprint']->matches(
                    $this->compaction()->historyFingerprint($this->compactor, $dispatched),
                ),
            )
        )) {
            $this->aheadOfNeed = null;
        }
        // The request-arm conjunction is SessionTurnRules::
        // aheadOfNeedRequestAllowed() (TUIARCH-04); needing a backend to
        // exist at all is the host-only term ANDed in beside it.
        if (SessionTurnRules::aheadOfNeedRequestAllowed(
            $dueForReminder,
            $this->aheadOfNeed !== null,
            $this->ledger()->capReached($this->spend, $this->workspace->maxCostUsd),
            $aheadTokens,
            $currentModel,
        )
            && $this->workspace->backend !== null
        ) {
            if ($this->requestAheadOfNeed($dispatched, $baseHistory)) {
                // Every minting site stamps its model now — the CTX-5 idle
                // watch stamps its own slot at request time (MINOR-1,
                // round-1 review) — so a switch discards whichever kind it
                // held. This stamp rides on the slot this block asked for.
                $this->aheadOfNeed = [...$this->aheadOfNeed, 'model' => $currentModel];
            }
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
                $this->clientTools,
                $this->clientCallSink(),
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

            $this->ledger()->account($this->spend, $reply->usage, $this->usageRecordSink(), null, 'turn');
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
        // C-SES-7.e: an incognito session runs no post-turn passes at all —
        // no memory consolidation, no auto-commit, no dream, no prompt
        // suggestion. Each carries a durable side (memory files, git); the
        // session's turns answer live and die with the process.
        if ($this->ephemeral) {
            return;
        }

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
     * This session's sink, built once per host. A dark serve process
     * registers no factory on the workspace, so the answer is the null
     * sink: one no-op call per folded event and nothing else. A host
     * opened for an id a previous host closed gets a fresh live sink
     * from the factory, never the predecessor's flushed-and-deaf one.
     */
    private function otelSink(): EventSink
    {
        if ($this->otelSink instanceof EventSink) {
            return $this->otelSink;
        }

        $factory = $this->workspace->service(EventSinkFactory::class);

        return $this->otelSink = $factory instanceof EventSinkFactory
            ? $factory->forSession($this->sessionId)
            : new NullEventSink();
    }

    /**
     * One reported event, folded as the TUI's live pump folds it: a tool's
     * start becomes its running placeholder and its finish replaces that row
     * (newest first, by call id); every event is reported to the runner, which
     * logs the durable ones and tells the listeners.
     */
    private function fold(?CancellationToken $turn, object $event): void
    {
        // C-SES-4.d: the observation feed comes FIRST — every event this
        // host folds reaches the sink, including the three shapes below
        // that return early, and the sink's contract (never throws, never
        // blocks) is why a fold site may hand over before it does any work
        // of its own.
        $this->otelSink()->consume($event);

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
        // C-SES-7.e: an incognito host never touches the store. This is THE
        // transcript persistence choke — every row the host takes on is
        // saved through here — so skipping it leaves nothing on disk.
        if ($this->ephemeral) {
            return;
        }

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
     * nothing for another. A recorded primary agent (C-AGT-4.b, MAJOR-2)
     * narrows the result through {@see PrimaryAgent::apply()} exactly as the
     * terminal does — recomputed on every read, so the switch lands on each
     * subsequent served turn and clearing it lands the plain base on the
     * next one, with nothing stored to revert.
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
        if ($gated !== null && $gated !== $base) {
            $backend = $backend->withPermissionGate($gated);
        }

        $manager = $this->workspace->agentManager;
        $agent = $this->primaryAgentOverride === null ? null : $manager?->get($this->primaryAgentOverride);
        if ($agent === null) {
            return $backend;
        }
        // MAJOR-2: the switch is applied here, per dispatch — the same
        // narrowing the terminal performs at the switch itself (roster,
        // model, effort, mode, instructions), just recomputed instead of
        // stored, because the session gate above rides a per-turn ceiling.
        // The launch slice needs no revert record: the base this folds over
        // was never narrowed in the first place.

        [, $backend] = PrimaryAgent::apply($backend, $manager, $agent);

        return $backend;
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
            return $this->channelScoped($base);
        }

        return $this->channelScoped(new PermissionGate(
            $mode,
            $base?->rules() ?? [],
            new SafetyClassifier(),
            'session',
        ));
    }

    /**
     * The code-chat D6 admission door: when the workspace carries a
     * {@see ChannelToolPolicyService} — every Bootstrap workspace build does,
     * the local chat launch included — the session's name is parsed for the
     * §2.3 channel grammar and the live channel tool policy is attached to
     * the gate about to run — RESOLVED AT ADMISSION, fresh every dispatch,
     * exactly the way the memo grants ride {@see TurnRunner::backendForTurn()}.
     * An unscoped session, a workspace hand-built without the service, or an
     * unnamed session returns the gate untouched, object for object, so every
     * other surface is byte-identical.
     */
    private function channelScoped(?PermissionGate $gate): ?PermissionGate
    {
        if ($gate === null) {
            // The ceiling law stands: a scope never manufactures a gate
            // where none is wired.
            return null;
        }

        $service = $this->workspace->service(ChannelToolPolicyService::class);
        if (!$service instanceof ChannelToolPolicyService) {
            return $gate;
        }

        $name = $this->ephemeralName;
        if ($name === null && !$this->ephemeral) {
            $row = $this->workspace->sessionStore?->session($this->sessionId);
            $name = \is_array($row) ? ($row['name'] ?? null) : null;
        }

        return $gate->withChannelScope($service->scopeFor($name));
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
            // r9f: no exec pin on this box can reach this arm (an opted-in
            // row forks at mustFork whenever canFork() holds) — the
            // threading is pinned structurally by
            // {@see \SugarCraft\Code\Tests\Host\ServedProjectCommandTrustTest::testTheSynchronousDirectiveCallSiteCarriesBothOptInLists()}.
            trustedBundleCommands: $this->workspace->trustedBundleCommands,
            mcpPromptShell: $this->workspace->mcpPromptShell,
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
        // C-SES-7.e: the usage sink is the only durable usage ledger an open
        // host writes; an incognito session has no ledger to append to.
        if ($this->ephemeral) {
            return null;
        }

        $store = $this->workspace->sessionStore;
        if (!$store instanceof EnhancedSessionStore) {
            return null;
        }

        return function (Usage $usage, ?string $model = null, ?string $source = null) use ($store): void {
            $backend = $this->backend();
            $store->appendUsageRecord(
                $this->sessionId,
                $usage,
                $model ?? ($backend instanceof EngineBackend ? $backend->model() : null),
                $source,
            );
        };
    }

    private function meter(): ContextMeter
    {
        $service = $this->workspace->service(ContextMeter::class);

        return $service instanceof ContextMeter ? $service : ContextMeter::new();
    }
}
