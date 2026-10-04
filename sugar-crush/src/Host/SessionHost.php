<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host;

use React\Promise\PromiseInterface;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\InteractiveTurn;
use SugarCraft\Crush\Backend\PendingAsk;
use SugarCraft\Crush\BackendToolEventsMsg;
use SugarCraft\Crush\BangShellResultMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\CommandParser;
use SugarCraft\Crush\Commands\BangShell;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Commands\Specs\BuiltInCommands;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\ContextCompactor;
use SugarCraft\Crush\Context\IdleCompactionPolicy;
use SugarCraft\Crush\Events\PermissionAsked;
use SugarCraft\Crush\Events\PermissionResolved;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Host\Commands\CommandContext;
use SugarCraft\Crush\Host\Commands\CommandEffect;
use SugarCraft\Crush\Host\Commands\CommandEffectKind;
use SugarCraft\Crush\Host\Commands\CommandResult;
use SugarCraft\Crush\Host\Commands\CommandText;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Permissions\SafetyClassifier;
use SugarCraft\Crush\Permissions\SessionPermissionMemo;
use SugarCraft\Crush\Session\SessionLock;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\Util\TokenTracker;

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

    /** Thrash breaker's run of refilled compactions (roadmap 2.10 / §4.23). */
    private int $consecutiveRefills = 0;

    /** E17 calibration of the token estimate, and the raw proxy it pairs with. */
    private ?float $calibration = null;

    private ?int $estimateAtDispatch = null;

    /** @var \ArrayObject<int, array{0: int, 1: object}> the turn's live inbox */
    private readonly \ArrayObject $inbox;

    private readonly TokenTracker $spend;

    private readonly ContextCompactor $compactor;

    /** @var array<string, CommandSpec> */
    private readonly array $customCommands;

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

    /** The mode this session's turns run in; null is the workspace gate's. */
    private ?PermissionMode $permissionMode = null;

    /** How many settled questions {@see resolution()} remembers. */
    private const RESOLUTIONS_KEPT = 64;

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
    ) {
        $this->history = array_values($history);
        $this->inbox = new \ArrayObject();
        $this->grants = SessionPermissionMemo::new();
        $this->spend = new TokenTracker();
        $this->compactor = new ContextCompactor($compactorConfig ?? CompactorConfig::new());
        $root = $this->root();
        $this->customCommands = $customCommands ?? array_filter(
            $workspace->commandLoader?->loadAll($root) ?? [],
            static fn (CommandSpec $spec): bool => $spec->isFileBased(),
        );
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
     */
    public static function new(
        string $sessionId,
        WorkspaceContext $workspace,
        array $history = [],
        ?TranscriptStore $transcripts = null,
        ?SessionLock $lease = null,
        ?CompactorConfig $compactorConfig = null,
        ?array $customCommands = null,
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

    /** The session's todo list (roadmap 3.C), as its `Todo` tool last wrote it. */
    public function todos(): \SugarCraft\Crush\Todo\TodoList
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
        if ($this->permissionMode !== null) {
            return $this->permissionMode;
        }
        $backend = $this->workspace->backend;

        return $backend instanceof EngineBackend ? $backend->permissionGate()?->mode() : $this->workspace->permissionGate?->mode();
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
            $this->grants = $this->grants->withGrant($ask->tool, $ask->arguments);
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
            ?? CommandResult::refused(sprintf('/%s is not a built-in command.', $name));
        if (!$result->isRefused()) {
            $this->applyCommandResult($result);
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

    /** Let go of the session's lock; the host must not be used after. */
    public function release(): void
    {
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

            return $this->admitExpanded(
                $text,
                $turns->expandCustomCommand($command[0], $command[1], $this->directive($command[0])),
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

        return $this->admitPrompt($text, $options->resolveMentions, $text);
    }

    /**
     * A command file's expansion, judged as the TUI judges it: an expansion
     * that produced nothing is refused, not sent, and its mentions are never
     * read (audit 15b-15) — the body is repository-authored, with its own `@`
     * form a trust tier may just have refused. The `/rewind` draft is the line
     * as typed.
     */
    private function admitExpanded(string $typed, ?string $expanded): TurnTicket
    {
        $expanded = trim((string) $expanded);
        if ($expanded === '') {
            return TurnTicket::refused($this->turns()->emptyCustomCommandNotice($typed));
        }

        return $this->admitPrompt($expanded, false, $typed);
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
        $run = static function () use ($turns, $spec, $arguments, $root, $trusted, $gate): array {
            $gated = [];
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
            function (array $result) use ($text): void {
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
                $this->admitExpanded($text, $expanded);
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
     */
    private function admitPrompt(string $text, bool $resolveMentions, string $draft, ?array $verdicts = null): TurnTicket
    {
        $turns = $this->turns();
        $ledger = $this->ledger();

        // Spend cap, where the TUI checks it: after command dispatch.
        if ($ledger->capReached($this->spend, $this->workspace->maxCostUsd)) {
            return TurnTicket::refused($ledger->refusalNotice(
                $this->spend,
                (float) $this->workspace->maxCostUsd,
                SpendLedger::CROSSED_BY_PREVIOUS_TURN,
            ));
        }

        // The automatic tier: the synchronous heuristic route.
        $tokenLimit = $this->meter()->limit($this->backend());
        $tokenCount = $this->estimate($this->history);
        $wireHistory = CompactionService::compactionWire($this->history);
        $baseHistory = $this->history;
        $reports = [];
        $refilled = null;
        if ($this->compactor->shouldCompact($wireHistory, $tokenLimit)) {
            $compaction = $this->compaction();
            if (IdleCompactionPolicy::thrashTripped($this->consecutiveRefills)) {
                return TurnTicket::refused($compaction->thrashBreakerNotice());
            }

            $tier = $turns->inlineTier(
                $compaction,
                $this->compactor,
                $this->history,
                $wireHistory,
                $tokenLimit,
                $tokenCount,
                $this->estimate(...),
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
                $verdicts = $this->fireTurnHooks($text, $resolveMentions, $draft);
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

        return $this->dispatch($text, $resolveMentions, $draft, $baseHistory, $reports, $notes, $refilled);
    }

    /**
     * Fire `UserPromptSubmit` and, into an empty history, `SessionStart` for
     * one submission — this host's dispatch site for both, as
     * `Chat::dispatchTurnHooks()` is the TUI's. In process, the verdicts come
     * back now; when a hook leaves the process the chain is forked
     * (audit 15b-04), the session is held, null is returned, and
     * {@see admitPrompt()} is re-entered with the verdicts when they land.
     *
     * @return array{0: HookResult, 1: ?HookResult}|null
     */
    private function fireTurnHooks(string $text, bool $resolveMentions, string $draft): ?array
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
            function (array $verdicts) use ($text, $resolveMentions, $draft): void {
                $this->admitPrompt($text, $resolveMentions, $draft, $verdicts);
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

        $this->applyCommandResult($result);

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

        return $result->effect(CommandEffectKind::OpenTitleEditor) !== null
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
     */
    private function applyCommandResult(CommandResult $result): void
    {
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

                case CommandEffectKind::SwitchSession:
                case CommandEffectKind::RenameSession:
                case CommandEffectKind::OpenTitleEditor:
                    break;
            }
        }

        $this->history = [...$this->history, ...$result->rows];
        $this->save();
    }

    private function runAsync(CommandEffect $effect): void
    {
        $describe = $effect->describe();
        try {
            $promise = ($effect->run())();
        } catch (\Throwable $e) {
            $this->appendCommandRow("**Error:** {$e->getMessage()}");

            return;
        }

        $promise->then(
            function (mixed $value) use ($describe): void {
                $text = $describe === null ? null : $describe($value);
                if ($text !== null) {
                    $this->appendCommandRow($text);
                }
            },
            function (\Throwable $e): void {
                $this->appendCommandRow("**Error:** {$e->getMessage()}");
            },
        );
    }

    private function occupy(CommandEffect $effect): void
    {
        $cancellation = $effect->cancellation() ?? new CancellationToken();
        $this->turn = $cancellation;
        $this->workflowTurn = $effect->isWorkflow();
        $generation = ++$this->generation;

        $land = function (string $report) use ($generation): void {
            $this->appendCommandRow($report);
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
            $land("**Error:** {$e->getMessage()}");

            return;
        }

        $promise->then($land, static fn (\Throwable $e) => $land("**Error:** {$e->getMessage()}"));
    }

    /** One UI-only reply row a command's later answer lands as, saved. */
    private function appendCommandRow(string $text): void
    {
        $this->history[] = Message::assistant($text)->withUiOnly();
        $this->save();
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
            $this->history[] = Message::notice($notice);
            $this->save();

            return TurnTicket::refused($notice);
        }

        $this->history[] = Message::notice($turns->bangRunningNotice($bang));
        $cancellation = new CancellationToken();
        $this->turn = $cancellation;
        $generation = ++$this->generation;
        $this->save();

        $cmd = (BangShell::cmd($bang, $root, $cancellation, $generation))();
        $cmd->promise->then(function (mixed $msg) use ($generation): void {
            // A result for a command already cancelled is stale, as in the TUI.
            if (!$msg instanceof BangShellResultMsg || $generation !== $this->generation) {
                return;
            }
            $this->history[] = $msg->message;
            $this->turn = null;
            $this->save();
            $this->releaseQueue();
        });

        return TurnTicket::handled();
    }

    /** The session as a host command reads it ({@see CommandContext}). */
    private function commandContext(): CommandContext
    {
        $backend = $this->workspace->backend;

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
     */
    private function dispatch(
        string $text,
        bool $resolveMentions,
        string $draft,
        array $baseHistory,
        array $reports,
        array $notes,
        ?bool $refilled,
    ): TurnTicket {
        $turns = $this->turns();
        $preTurnHistory = [...$baseHistory, ...$reports];

        [$userTurn, $attachmentNotes] = $turns->userTurnMessage(
            $text,
            $resolveMentions,
            $this->root(),
            $this->workspace->sessionStore,
            $this->workspace->permissionGate,
        );
        $newTurnMessages = [...$reports, ...$notes, ...$attachmentNotes, $userTurn];

        // The 70% reminder: dropped from the base unconditionally, appended
        // only when due, so the history holds at most one, current, copy.
        $tokenLimit = $this->meter()->limit($this->backend());
        $dueForReminder = $this->compactor->shouldSendReminder(CompactionService::compactionWire($baseHistory), $tokenLimit);
        $preStrip = $baseHistory;
        $baseHistory = CompactionService::withoutContextReminders($baseHistory);
        if ($dueForReminder) {
            $newTurnMessages[] = CompactionService::contextReminderMessage($this->estimate($preStrip));
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

        $run = $this->runner()->start(
            backend: TurnRunner::backendForTurn(
                $this->backend(),
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
            notices: $this->workspace->notices,
            agentManager: $this->workspace->agentManager,
            transcripts: $this->transcripts,
            sessionId: $this->sessionId,
        );
        $turnId = $this->runner()->turnIdOf($cancellation);

        // The workspace snapshot belongs to the checkpoint and is complete
        // before the turn can fork and write a file.
        if ($capture !== null) {
            $capture();
        }

        $this->settleWith($run(), $generation, $cancellation);

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

            $this->ledger()->account($this->spend, $reply->usage);
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
            $this->save();
            $this->releaseQueue();
        });
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
            $this->pendingAsks[$event->ask->askId] = $event->ask;
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
     * The workspace's backend, judged by this session's own permission mode
     * when it has one: a gate in that mode over the same rules, so a client
     * choosing `accept-edits` for one session changes nothing for another.
     */
    private function backend(): Backend
    {
        $backend = $this->workspace->backend ?? throw new \LogicException('This session has no backend.');
        if ($this->permissionMode === null || !$backend instanceof EngineBackend) {
            return $backend;
        }
        $gate = $backend->permissionGate();
        if ($gate !== null && $gate->mode() === $this->permissionMode) {
            return $backend;
        }

        return $backend->withPermissionGate(new PermissionGate(
            $this->permissionMode,
            $gate?->rules() ?? [],
            new SafetyClassifier(),
            'session',
        ));
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

    private function meter(): ContextMeter
    {
        $service = $this->workspace->service(ContextMeter::class);

        return $service instanceof ContextMeter ? $service : ContextMeter::new();
    }
}
