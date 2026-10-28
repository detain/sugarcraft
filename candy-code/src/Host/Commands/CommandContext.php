<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Commands\TranscriptTable;
use SugarCraft\Code\Context\Pruning\ContextLedger;
use SugarCraft\Code\Context\Pruning\PruningMode;
use SugarCraft\Code\Context\RulesState;
use SugarCraft\Code\Host\TitleService;
use SugarCraft\Code\Host\TranscriptStore;
use SugarCraft\Code\Host\TurnRunner;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Message;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\SessionPermissionMemo;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionStore;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Background\ScheduleRegistry;
use SugarCraft\Code\Tools\BuiltIn\WebSearch;
use SugarCraft\Code\Util\TokenTracker;
use SugarCraft\Code\Workflows\WorkflowEngineInterface;

/**
 * Everything a {@see HostCommand} reads about the session it runs in
 * (roadmap O-2h) — built per command by its driver: `Chat::commandContext()`
 * from the TUI's model, `SessionHost::commandContext()` from a headless host.
 *
 * A READ-ONLY VIEW, never the session itself: a command answers with a
 * {@see CommandResult} and the driver applies it. The collaborators here are
 * the mutable services both drivers share by identity (the store, the gate,
 * the rules state, the agent manager) — a command that toggles a rule pack or
 * writes a memory changes them in place, exactly as the TUI's handlers did.
 *
 * The accessors {@see projectRoot()}, {@see currentSessionId()} and
 * {@see cols()} answer as `Chat`'s public ones of the same names do, so the
 * `Commands\*` classes that once took a `Chat` (their `execute(Chat|CommandContext …)`)
 * read either without a branch.
 */
final class CommandContext
{
    /**
     * @param list<Message> $history the transcript as it stands before the command
     * @param string|null $root the configured project root; null resolves to the process directory
     * @param int $cols the terminal width a table fits to; a headless driver passes a wide default
     * @param bool $readOnly whether this window may not write the session (another TUI holds its lock)
     * @param int|null $contextTokenLimit the window `/context` measures against, when known
     * @param int|null $contextTokens the history's token estimate, the status bar's own
     * @param SessionPermissionMemo $grants the session's "always" answers, which `/permissions` lists and revokes
     * @param TokenTracker|null $spendTracker the session's live spend tracker, when the driver carries one (C-SES-9)
     * @param float|null $spendCap the session's cost cap in USD, when configured (C-SES-9)
     */
    private function __construct(
        public readonly array $history,
        public readonly ?string $sessionId,
        public readonly ?string $root,
        public readonly int $cols,
        public readonly bool $readOnly,
        public readonly ?PermissionGate $permissionGate,
        public readonly ?Backend $backend,
        public readonly ?Backend $titleBackend,
        public readonly ?AgentManager $agentManager,
        public readonly SessionStore|EnhancedSessionStore|null $sessionStore,
        public readonly TranscriptStore $transcripts,
        public readonly TurnRunner $turnRunner,
        public readonly RulesState $rulesState,
        public readonly ?WorkflowEngineInterface $workflowEngine,
        public readonly ?BackgroundSupervisor $backgroundSupervisor,
        public readonly ?MemoryStore $memoryStore,
        public readonly ?WebSearch $webSearch,
        public readonly ?int $contextTokenLimit,
        public readonly ?int $contextTokens,
        public readonly ?WorkspaceContext $workspace,
        public readonly SessionPermissionMemo $grants,
        public readonly ?ScheduleRegistry $scheduleRegistry,
        public readonly ?TokenTracker $spendTracker,
        public readonly ?float $spendCap,
    ) {
    }

    /**
     * @param list<Message> $history
     */
    public static function new(
        array $history = [],
        ?string $sessionId = null,
        ?string $root = null,
        int $cols = self::HEADLESS_COLS,
        bool $readOnly = false,
        ?PermissionGate $permissionGate = null,
        ?Backend $backend = null,
        ?Backend $titleBackend = null,
        ?AgentManager $agentManager = null,
        SessionStore|EnhancedSessionStore|null $sessionStore = null,
        ?TranscriptStore $transcripts = null,
        ?TurnRunner $turnRunner = null,
        ?RulesState $rulesState = null,
        ?WorkflowEngineInterface $workflowEngine = null,
        ?BackgroundSupervisor $backgroundSupervisor = null,
        ?MemoryStore $memoryStore = null,
        ?WebSearch $webSearch = null,
        ?int $contextTokenLimit = null,
        ?int $contextTokens = null,
        ?WorkspaceContext $workspace = null,
        ?SessionPermissionMemo $grants = null,
        ?ScheduleRegistry $scheduleRegistry = null,
        ?TokenTracker $spendTracker = null,
        ?float $spendCap = null,
    ): self {
        return new self(
            history: array_values($history),
            sessionId: $sessionId,
            root: $root,
            cols: max(1, $cols),
            readOnly: $readOnly,
            permissionGate: $permissionGate,
            backend: $backend,
            titleBackend: $titleBackend,
            agentManager: $agentManager,
            sessionStore: $sessionStore,
            transcripts: $transcripts ?? TranscriptStore::new($sessionStore),
            turnRunner: $turnRunner ?? TurnRunner::new(),
            rulesState: $rulesState ?? RulesState::new(),
            workflowEngine: $workflowEngine,
            backgroundSupervisor: $backgroundSupervisor,
            memoryStore: $memoryStore,
            webSearch: $webSearch,
            contextTokenLimit: $contextTokenLimit,
            contextTokens: $contextTokens,
            workspace: $workspace,
            grants: $grants ?? SessionPermissionMemo::new(),
            scheduleRegistry: $scheduleRegistry,
            spendTracker: $spendTracker,
            spendCap: $spendCap,
        );
    }

    /**
     * The width a headless driver fits command tables to: a client renders
     * them in its own pane, so there is no terminal to measure. 120 columns is
     * the width the TUI's tables were tuned at.
     */
    public const HEADLESS_COLS = 120;

    /** The directory this session is rooted at, resolved as `Chat::projectRoot()` resolves it. */
    public function projectRoot(): string
    {
        return $this->root ?? (getcwd() ?: '');
    }

    public function currentSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function cols(): int
    {
        return $this->cols;
    }

    /** The width a transcript table fits to ({@see TranscriptTable::paneWidth()}). */
    public function paneWidth(): int
    {
        return TranscriptTable::paneWidth($this);
    }

    /**
     * The title service the session titles itself through: the workspace's
     * when it registered one, else a default.
     */
    public function titleService(): TitleService
    {
        $service = $this->workspace?->service(TitleService::class);

        return $service instanceof TitleService ? $service : TitleService::new();
    }

    /**
     * This session's context ledger as its next turn would start from it
     * (roadmap 2.2-2): the runner's, synced against the history, following
     * the configured pruning mode where the session chose none.
     */
    public function contextLedger(): ContextLedger
    {
        return $this->turnRunner
            ->ledger($this->transcripts, $this->sessionId)
            ->syncAgainstHistory($this->history)
            ->withDefaultMode(PruningMode::configured());
    }

    /** Keep $ledger as this session's — the runner's one writer of it. */
    public function saveContextLedger(ContextLedger $ledger): void
    {
        $this->turnRunner->saveLedger($this->transcripts, $this->sessionId, $ledger);
    }
}
