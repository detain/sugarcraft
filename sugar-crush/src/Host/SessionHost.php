<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host;

use React\Promise\PromiseInterface;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\InteractiveTurn;
use SugarCraft\Crush\BackendToolEventsMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\BangShell;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\ContextCompactor;
use SugarCraft\Crush\Context\IdleCompactionPolicy;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Message;
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
 * - idle: a `!cmd` and every slash command are refused (both are TUI
 *   surfaces until O-2h); a command FILE is expanded exactly as the TUI
 *   expands it, off this thread when its body runs a shell (audit 15b-20);
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
        $this->generation++;
        $this->inbox->exchangeArray([]);
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

        switch ($turns->midTurnRoute($text, false, $options)) {
            case TurnController::ROUTE_QUIT:
            case TurnController::ROUTE_WORKFLOW_CONTROL:
            case TurnController::ROUTE_REFUSE_COMMAND:
                return TurnTicket::refused($turns->hostCommandNotice($text, true));

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

        $bang = BangShell::commandOf($text);
        if ($bang !== null) {
            return TurnTicket::refused($turns->hostBangNotice($bang));
        }

        if ($this->workspace->backend === null) {
            return TurnTicket::refused($turns->noBackendNotice());
        }

        // FILE-BASED COMMANDS FIRST, ahead of the slash-command refusal: a
        // project `compact.md` replaces `/compact`, and a command file IS a
        // prompt, so everything below applies to its expansion.
        $command = $turns->resolveCustomCommand($text, $this->customCommands);
        if ($command !== null) {
            if ($turns->customCommandMustFork($text, $this->customCommands, null, $this->workspace->projectCommandsTrusted)) {
                return $this->parkExpansion($text, $command);
            }

            return $this->admitExpanded(
                $text,
                $turns->expandCustomCommand($command[0], $command[1], $this->directive($command[0])),
            );
        }

        if (str_starts_with($text, '/') || TurnController::isBareMcpAuthCommand($text)) {
            return TurnTicket::refused($turns->hostCommandNotice($text, false));
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
        $created = $turns->recordMessagesCreated($this->transcripts, $this->sessionId, $newTurnMessages);

        $run = $this->runner()->start(
            backend: TurnRunner::backendForTurn(
                $this->backend(),
                $this->workspace->maxCostUsd,
                $this->spentUsd(),
                $this->compactor->config(),
                $this->sessionId,
                SessionPermissionMemo::new(),
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
            $this->inbox->exchangeArray([]);
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

        $runner->recordEvent($turn, $event);
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

    private function backend(): Backend
    {
        return $this->workspace->backend ?? throw new \LogicException('This session has no backend.');
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
