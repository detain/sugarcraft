<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host;

use React\Promise\PromiseInterface;
use SugarCraft\Core\Msg;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\InteractiveTurn;
use SugarCraft\Crush\Backend\ObservesReasoning;
use SugarCraft\Crush\BackendToolEventsMsg;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PruningMode;
use SugarCraft\Crush\Diagnostics\NoticeSink;
use SugarCraft\Crush\Events\PermissionAsked;
use SugarCraft\Crush\Events\PermissionResolved;
use SugarCraft\Crush\Events\ReasoningDelta;
use SugarCraft\Crush\Events\SpendCapBreached;
use SugarCraft\Crush\Events\StepStarted;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Events\TokenDelta;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Events\UsageUpdated;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\SessionPermissionMemo;
use SugarCraft\Crush\Role;

/**
 * Runs one backend dispatch of a session's turn and says what happened in it
 * (roadmap O-2f, Appendix O §4.2 "Backend completion + inbox + generation
 * guard → `Host\TurnRunner`").
 *
 * EXTRACTED FROM CHAT, NOT BESIDE IT. This was the body of
 * `Chat::scheduleBackendCompletion()` and `::drainToolEventInbox()`: the
 * per-dispatch backend wiring (the spend cap paired with the dispatch, the
 * compactor config, the session id, the session's "always" grants), the four
 * callbacks a backend reports through, and the settle that turns the
 * backend's promise into the Msg the TUI folds. The logic never needed a
 * screen — only `Cmd::promise()` did — so it lives here, returns a thunk the
 * caller schedules ({@see start()}), and `Chat` wraps that thunk in a `Cmd`.
 *
 * THE INBOX STAYS. Every callback still appends `[generation, event]` to the
 * caller's shared `\ArrayObject`, in the backend's own order, because the TUI's
 * live pump drains it one entry per render and a second ordering authority
 * would let a finish land before its start. What is new is the SECOND
 * audience: {@see listen()}ers and the session's durable {@see EventLog}.
 *
 * THE FIRST WRITER OF THE EVENT LOG. A turn is bracketed by durable
 * `turn.started` and `turn.completed` events; between them the caller reports
 * each event as it FOLDS it ({@see recordToolStarted()},
 * {@see recordToolFinished()}, {@see recordEvent()}), because only then does
 * the row exist that the event names — by the identity
 * {@see TranscriptStore::identify()} gives it, the id the row is saved
 * under. Durable events are written to the log BEFORE a listener hears them,
 * so a broadcast can never get ahead of what a replay reads. Streaming deltas
 * stay ephemeral: never written, and only built at all when someone listens.
 *
 * A turn is keyed by the dispatch's {@see CancellationToken}, which is unique
 * per dispatch and which the caller already holds for the turn in flight — so
 * the runner adds no state to the model that uses it, and a token's turn
 * record goes when the token does. A token the runner never started (a test
 * driving a fold by hand, a Chat-native tool batch) records nothing.
 *
 * MUTABLE ON PURPOSE, like {@see \SugarCraft\Crush\Agents\Live\AgentLiveRegistry}:
 * it is a live service (the turns in flight and the listeners), not a value.
 * Registered on {@see WorkspaceContext::service()} under its class name; a
 * Chat with none registered takes the one keyed to its own inbox
 * ({@see of()}), which every clone of that Chat shares.
 *
 * THE SESSION'S CONTEXT LEDGER (roadmap 2.2-2). What the turns have pruned
 * or summarised out of the model's view is session state, so it is kept
 * here between turns ({@see ledger()} / {@see saveLedger()}), handed to each
 * engine dispatch and taken back from its reply. Before this every turn
 * started from an empty ledger.
 *
 * NEVER FATAL TO THE TURN. The log is a second audience: a write that fails
 * (a locked database, a payload JSON cannot carry) drops that event — it is
 * not broadcast either, the {@see EventLog} contract — and a listener that
 * throws is detached. Neither can cost the user the reply.
 */
final class TurnRunner
{
    /** @var \WeakMap<object, self>|null */
    private static ?\WeakMap $owned = null;

    /**
     * The turns this runner started and has not yet closed.
     *
     * @var \WeakMap<CancellationToken, array{turnId: string, sessionId: ?string, transcripts: ?TranscriptStore, spendCapped: bool, parked: ?array{0: Message, 1: bool}}>
     */
    private \WeakMap $turns;

    /** @var array<int, \Closure(SessionEvent): void> */
    private array $listeners = [];

    /**
     * The context ledger of each session this runner ran a turn for, keyed
     * by session id ('' for a turn with none) — the copy a host that
     * persists nothing keeps between turns (roadmap 2.2-2). A persisting
     * host reads the store's instead, every dispatch, so a `/rewind`,
     * `/branch` or another process's save is never shadowed by this copy.
     *
     * @var array<string, ContextLedger>
     */
    private array $ledgers = [];

    private int $nextListener = 0;

    private function __construct(private readonly TranscriptProjector $projector)
    {
        $this->turns = new \WeakMap();
    }

    public static function new(?TranscriptProjector $projector = null): self
    {
        return new self($projector ?? TranscriptProjector::new());
    }

    /**
     * The runner belonging to $owner — the fallback a Chat with no
     * workspace-registered runner uses, keyed to its live inbox so every
     * clone of one Chat lineage shares it.
     */
    public static function of(object $owner): self
    {
        self::$owned ??= new \WeakMap();

        return self::$owned[$owner] ??= self::new();
    }

    /** The projector the caller folds this runner's events through. */
    public function projector(): TranscriptProjector
    {
        return $this->projector;
    }

    /**
     * Hear every event this runner records — durable ones after the log
     * numbered them, ephemeral ones live. The protocol layer's broadcast
     * seam (Appendix O §6.4). Returns the closure that detaches it.
     *
     * @param \Closure(SessionEvent): void $listener
     * @return \Closure(): void
     */
    public function listen(\Closure $listener): \Closure
    {
        $key = $this->nextListener++;
        $this->listeners[$key] = $listener;

        return function () use ($key): void {
            unset($this->listeners[$key]);
        };
    }

    /** Whether anyone hears this runner's events. */
    public function hasListeners(): bool
    {
        return $this->listeners !== [];
    }

    /** The id of the turn $turn dispatched, or null when this runner did not start it. */
    public function turnIdOf(?CancellationToken $turn): ?string
    {
        return $turn === null ? null : ($this->turns[$turn]['turnId'] ?? null);
    }

    /**
     * $backend wired for ONE dispatch of a session's turn.
     *
     * - E20: the session's dollar ceiling is threaded DOWN into the engine
     *   that will spend it, as (ceiling, baseline) PAIRED with the dispatch —
     *   not installed at launch — so a `/budget` raised mid-session never
     *   applies retroactively to a turn already forked. A capability check
     *   rather than a `Backend` method: an echo backend has no steps to cap.
     * - Audit R1: the engine's per-turn App prices its skill budget against
     *   the SAME {@see CompactorConfig} the session compacts with. Null keeps
     *   the backend's own default, which is the session's default too.
     * - Step 0.13-a: the session this turn belongs to, read per dispatch so a
     *   `/resume`, `/branch` or tab switch is followed into the hooks'
     *   `sessionId` and the providers' session-affinity header.
     * - Roadmap 1.C-2: the "always allow (this session)" answers ride into
     *   this turn's gate as Allow rules, read per dispatch so a grant given
     *   mid-turn covers every later turn. They answer an Ask, never lift a
     *   Deny ({@see \SugarCraft\Crush\Permissions\PermissionGate::withSessionRules()}).
     */
    public static function backendForTurn(
        Backend $backend,
        ?float $maxCostUsd,
        float $spentUsd,
        ?CompactorConfig $compactorConfig,
        ?string $sessionId,
        SessionPermissionMemo $grants,
    ): Backend {
        if (!$backend instanceof EngineBackend) {
            return $backend;
        }
        if ($maxCostUsd !== null) {
            $backend = $backend->withSpendCap($maxCostUsd, $spentUsd);
        }
        if ($compactorConfig !== null) {
            $backend = $backend->withCompactorConfig($compactorConfig);
        }
        if ($sessionId !== null) {
            $backend = $backend->withSessionId($sessionId);
        }
        if ($grants->patterns() !== [] && ($gate = $backend->permissionGate()) !== null) {
            $backend = $backend->withPermissionGate($gate->withSessionRules($grants->rules()));
        }

        return $backend;
    }

    /**
     * Open one dispatch of a turn and return the thunk that runs it: a
     * `\Closure(): PromiseInterface<Msg>` the caller schedules (Chat hands it
     * to `Cmd::promise()`). The durable `turn.started` is written now, so it
     * precedes every event the turn can produce.
     *
     * The thunk resolves to an {@see AssistantMsg} when every event the turn
     * reported was already folded live, else to a {@see BackendToolEventsMsg}
     * carrying the rest; a failed turn resolves the same way around a UI-only
     * error row (audit 15b-03: the model did not say it, so it must not be
     * replayed to it as its own words next turn).
     *
     * @param list<Message> $history the session's history; only the rows the
     *        model may see are sent (audit 15b-03)
     * @param \ArrayObject<int, array{0: int, 1: object}> $inbox the caller's live
     *        inbox; every callback appends `[generation, event]` to it
     * @param bool $streaming whether token deltas are delivered live
     * @param (\Closure(string): void)|null $tokenObserver an embedder's extra
     *        raw-chunk sink, called after the inbox append
     * @param (\Closure(): bool)|null $reportObserverFailure whether a throwing
     *        observer is reported on `error_log` (read when it throws)
     * @param NoticeSink|null $notices the runtime-notice inbox whose per-turn
     *        budget this dispatch opens; null when the caller does not own
     *        that inbox's drain (E199)
     * @param AgentManager|null $agentManager whose projected sub-agent rows
     *        this dispatch clears
     * @param TranscriptStore|null $transcripts the session's store: its event
     *        log is written and its rows named; null records nothing durable
     * @param string|null $sessionId the session this turn belongs to, captured
     *        now so a session switch mid-turn cannot re-home its events
     */
    public function start(
        Backend $backend,
        array $history,
        \ArrayObject $inbox,
        int $generation,
        CancellationToken $cancellation,
        bool $streaming = true,
        ?\Closure $tokenObserver = null,
        ?\Closure $reportObserverFailure = null,
        ?NoticeSink $notices = null,
        ?AgentManager $agentManager = null,
        ?TranscriptStore $transcripts = null,
        ?string $sessionId = null,
    ): \Closure {
        // E199's wiring seam: ONE DISPATCH, ONE BUDGET. A tool-continuation
        // re-enters here and re-opens the budget, which is the rhythm
        // `beginTurn()`'s doc-block names: the cap protects what ONE engine
        // loop can push into the transcript between reads. Only the drain
        // OWNER passes a sink — arming from a hosted Chat nobody appointed
        // would re-open the budget underneath the owner's turn.
        $notices?->beginTurn();

        // ONE DISPATCH, ONE PROJECTION WINDOW. The mirror rows a delegated
        // run leaves in the parent's AgentManager describe beats of whatever
        // turn is about to run; rows from before it are ghosts by
        // construction. Rows of the turn that just settled survive until this
        // next dispatch, which is the point: between turns is where the
        // finished report gets read.
        $agentManager?->clearProjectedSubAgents();

        $this->open($cancellation, $history, $transcripts, $sessionId);

        // Only the rows the model may see (audit 15b-03): command echoes and
        // their output, notices and error strings live in the same list for
        // the transcript's sake and never go out as turns.
        $visible = Message::agentVisible($history);

        // The token seam: deltas go onto the shared inbox for the live pump;
        // $tokenObserver is an ADDITIONAL, optional sink, invoked after the
        // append so a throwing observer cannot cost the UI the delta it holds
        // — nor the rest of the turn. A broken observer is detached for the
        // remainder of THIS turn (it is a local of this call) and, when asked
        // for, reported once through error_log: quiet by default, because the
        // audience is the embedder whose sink threw, not the person at the
        // terminal, whose turn completes normally either way.
        $userSink = $tokenObserver;
        $onToken = !$streaming ? null : static function (string $delta) use ($inbox, $generation, &$userSink, $reportObserverFailure): void {
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
                if ($reportObserverFailure !== null && $reportObserverFailure()) {
                    error_log('Chat: onToken observer threw, detaching it for this turn: ' . $e->getMessage());
                }
            }
        };

        $runner = $this;

        return static function () use ($runner, $backend, $visible, $history, $onToken, $cancellation, $generation, $inbox, $transcripts, $sessionId): PromiseInterface {
            // Roadmap 2.2-2: the session's context ledger rides into the
            // turn, so its first request is projected exactly as the last
            // turn's last one was — the cache-stable rewrite a prune bought
            // is kept rather than re-derived from the full history. Read
            // here, when the turn runs, not when it is queued; forgotten
            // first for every row the history no longer has.
            $carriesLedger = $backend instanceof EngineBackend;
            if ($carriesLedger) {
                // Roadmap 3.B-2: and following the configured pruning mode
                // wherever the session chose none, read per turn.
                $backend = $backend->withContextLedger(
                    $runner->ledger($transcripts, $sessionId)
                        ->syncAgainstHistory($history)
                        ->withDefaultMode(PruningMode::configured()),
                );
            }

            // The permission events (1.C-2) share the inbox: a question has to
            // reach the screen in the turn's own event order, between the tool
            // events around it.
            $onEvent = static function (ToolStarted|ToolFinished|SpendCapBreached|SubAgentActivity|PermissionAsked|PermissionResolved $event) use ($inbox, $generation, $runner, $cancellation): void {
                if ($event instanceof SpendCapBreached) {
                    $runner->markSpendCapped($cancellation);
                }
                $inbox[] = [$generation, $event];
            };

            // E494: thinking, on the same inbox. No embedder seam and no
            // streaming gate: reasoning is display-only — it never enters the
            // history and never reaches the model — and a thought has no
            // non-incremental form to fall back to.
            $onReasoning = static function (string $delta) use ($inbox, $generation): void {
                if ($delta === '') {
                    return;
                }
                $inbox[] = [$generation, new ReasoningDelta($delta)];
            };

            // Roadmap 1.C-4: the turn's `step` / `usage` frames, in turn
            // order, for the live status bar and the soft-cancel arm.
            $onStep = static function (StepStarted|UsageUpdated $event) use ($inbox, $generation): void {
                $inbox[] = [$generation, $event];
            };

            // ASKED STRUCTURALLY, never by class name or arity sniffing: a
            // backend that can report thinking declares ObservesReasoning,
            // and one that can put a question to us declares InteractiveTurn
            // (the promise to answer every ASK the turn raises). A plain
            // backend is called with the four arguments it documents.
            $promise = match (true) {
                $backend instanceof InteractiveTurn
                    => $backend->completeInteractive($visible, $onToken, $cancellation, $onEvent, $onReasoning, $onStep),
                $backend instanceof ObservesReasoning
                    => $backend->completeAsync($visible, $onToken, $cancellation, $onEvent, $onReasoning),
                default => $backend->completeAsync($visible, $onToken, $cancellation, $onEvent),
            };

            // The permission events never ride a BackendToolEventsMsg: they
            // stay on the inbox for the live pump, which takes down a modal
            // whose question the turn's end settled (`cancelled`) — the settle
            // would otherwise drain that fact away with the turn and leave the
            // modal up over a question nobody is waiting on.
            $drain = static function () use ($inbox, $generation): array {
                $held = [];
                $rest = [];
                foreach ($inbox as $entry) {
                    if ($entry[1] instanceof PermissionAsked || $entry[1] instanceof PermissionResolved) {
                        $held[] = $entry;
                    } elseif ($entry[1] instanceof StepStarted || $entry[1] instanceof UsageUpdated) {
                        // Live-only (1.C-4): the settled reply carries the
                        // turn's real usage, and a finished turn has no step.
                        continue;
                    } else {
                        $rest[] = $entry;
                    }
                }
                $inbox->exchangeArray($rest);
                $events = self::drainInbox($inbox, $generation);
                $inbox->exchangeArray($held);

                return $events;
            };

            $settle = static function (Message $message, bool $failed) use ($runner, $drain, $generation, $cancellation, $transcripts, $sessionId, $carriesLedger): Msg {
                // Roadmap 2.2-2: the ledger the turn ended with becomes the
                // session's, and leaves the reply — it is transport, and the
                // reply goes on to be a stored row.
                if ($carriesLedger && $message->contextLedger !== null) {
                    $runner->saveLedger($transcripts, $sessionId, $message->contextLedger);
                    $message = $message->withContextLedger(null);
                }
                $events = $drain();
                if ($events === []) {
                    // Nothing left to fold: the reply lands next, so the turn
                    // closes now and its end follows every event it reported.
                    $runner->complete($cancellation, $message, $failed);

                    return new AssistantMsg($message, $generation);
                }

                // Events still to fold: the turn closes when the caller has
                // applied the last of them ({@see completeParked()}), so
                // `turn.completed` never lands above the tool rows it ended.
                $runner->park($cancellation, $message, $failed);

                return new BackendToolEventsMsg($events, $message, $generation, $cancellation);
            };

            return $promise->then(
                static fn (Message $msg): Msg => $settle($msg, false),
                // A turn that failed AFTER running tools still shows what
                // those tools did — otherwise the placeholders queued for
                // them would be the only trace and they never even render.
                static fn (\Throwable $e): Msg => $settle(
                    Message::assistant('_[error: ' . $e->getMessage() . ']_')->withUiOnly(),
                    true,
                ),
            );
        };
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
     * outright, whatever their generation: {@see BackendToolEventsMsg}
     * carries tool lifecycle states, and the settled Message beside them
     * already contains every byte those deltas described — its content for
     * the one, its `reasoning` for the other.
     *
     * @param \ArrayObject<int, array{0: int, 1: object}> $inbox
     * @return list<ToolStarted|ToolFinished|SpendCapBreached|SubAgentActivity>
     */
    public static function drainInbox(\ArrayObject $inbox, int $generation): array
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

    // ── the session's context ledger (roadmap 2.2-2) ───────────────────

    /**
     * $sessionId's context ledger as the next turn will start from it: the
     * stored one when $transcripts persists, else the one this runner kept
     * from the session's last turn, else an empty one.
     */
    public function ledger(?TranscriptStore $transcripts, ?string $sessionId): ContextLedger
    {
        if ($sessionId !== null && $transcripts?->persists() === true) {
            $stored = $transcripts->loadLedger($sessionId);
            if ($stored !== null) {
                return $stored;
            }
        }

        return $this->ledgers[$sessionId ?? ''] ?? ContextLedger::new();
    }

    /**
     * Keep $ledger as $sessionId's: in this runner, and in the store when
     * $transcripts persists. The one writer of the session's ledger — a
     * settled turn's and a command's (`/sweep`, `/pruning`) both come here.
     */
    public function saveLedger(?TranscriptStore $transcripts, ?string $sessionId, ContextLedger $ledger): void
    {
        $this->ledgers[$sessionId ?? ''] = $ledger;
        if ($sessionId !== null && $transcripts?->persists() === true) {
            $transcripts->saveLedger($sessionId, $ledger);
        }
    }

    // ── what the caller folded ─────────────────────────────────────────

    /**
     * `tool.started` for a call whose placeholder the caller just appended.
     */
    public function recordToolStarted(?CancellationToken $turn, ToolStarted $event, Message $placeholder): void
    {
        $state = $this->state($turn);
        if ($state === null) {
            return;
        }

        $this->record($state, $this->projector->toolStartedEvent(
            $event,
            $state['transcripts']?->identityOf($placeholder),
            $state['sessionId'],
            $state['turnId'],
        ));
    }

    /**
     * `tool.finished` for a call whose finished $row the caller just wrote,
     * named by the identity its save will keep.
     */
    public function recordToolFinished(?CancellationToken $turn, ToolFinished $event, Message $row, ?Message $replaced): void
    {
        $state = $this->state($turn);
        if ($state === null) {
            return;
        }

        $this->record($state, $this->projector->toolFinishedEvent(
            $event,
            $row,
            $this->identify($state, $row),
            $replaced === null ? null : $state['transcripts']?->identityOf($replaced),
            $state['sessionId'],
            $state['turnId'],
        ));
    }

    /**
     * Any other event the caller folded: a permission question or its
     * settlement, a delegated run's beat, the step and bill frames, the spend
     * cap, a streaming delta. Ephemeral ones are built only when someone
     * listens.
     */
    public function recordEvent(?CancellationToken $turn, object $event): void
    {
        $state = $this->state($turn);
        if ($state === null) {
            return;
        }
        $ephemeral = $event instanceof TokenDelta || $event instanceof ReasoningDelta || $event instanceof StepStarted
            || ($event instanceof SubAgentActivity && !\in_array($event->op, [SubAgentActivity::OP_STARTED, SubAgentActivity::OP_FINISHED], true));
        if ($ephemeral && !$this->hasListeners()) {
            return;
        }
        if ($event instanceof SpendCapBreached) {
            $this->markSpendCapped($turn);
        }

        $projected = $this->projector->backendEvent($event, $state['sessionId'], $state['turnId']);
        if ($projected !== null) {
            $this->record($state, $projected);
        }
    }

    /**
     * Close a turn whose events were all folded: `assistant.completed` for
     * the reply (named by the identity of the row it lands as) and
     * `turn.completed`. A turn cancelled hard records only its end.
     *
     * @internal the settle calls this; a caller reaches it through {@see completeParked()}
     */
    public function complete(?CancellationToken $turn, Message $reply, bool $failed = false): void
    {
        $state = $this->state($turn);
        if ($state === null) {
            return;
        }
        unset($this->turns[$turn]);

        if ($turn->isCancelled()) {
            $this->record($state, SessionEvent::new(SessionEvent::TURN_COMPLETED, [
                'stopReason' => SessionEvent::STOP_CANCELLED,
            ], $state['sessionId'], $state['turnId']));

            return;
        }

        $identity = $this->identify($state, $reply);
        if ($failed) {
            $this->record($state, SessionEvent::new(SessionEvent::TURN_COMPLETED, array_filter([
                'stopReason' => SessionEvent::STOP_ERROR,
                'error' => $reply->content,
                'messageId' => $identity[0] ?? null,
            ], static fn (mixed $value): bool => $value !== null), $state['sessionId'], $state['turnId']));

            return;
        }

        $this->record($state, $this->projector->assistantCompletedEvent($reply, $identity, $state['sessionId'], $state['turnId']));
        $this->record($state, SessionEvent::new(SessionEvent::TURN_COMPLETED, [
            'stopReason' => TranscriptProjector::stopReason($reply, $turn->isSoftCancelled(), $state['spendCapped']),
        ], $state['sessionId'], $state['turnId']));
    }

    /**
     * Close a turn {@see start()}'s settle parked behind events the caller
     * still had to fold — called once the last of them is applied, or when
     * the caller drops them as superseded.
     */
    public function completeParked(?CancellationToken $turn): void
    {
        $state = $this->state($turn);
        if ($state === null || $state['parked'] === null) {
            return;
        }

        [$reply, $failed] = $state['parked'];
        $this->complete($turn, $reply, $failed);
    }

    /** @internal see {@see start()}'s settle */
    public function park(CancellationToken $turn, Message $reply, bool $failed): void
    {
        if (isset($this->turns[$turn])) {
            $state = $this->turns[$turn];
            $state['parked'] = [$reply, $failed];
            $this->turns[$turn] = $state;
        }
    }

    /** @internal noted from the backend's own callback, so it survives the settle drain */
    public function markSpendCapped(?CancellationToken $turn): void
    {
        if ($turn !== null && isset($this->turns[$turn])) {
            $state = $this->turns[$turn];
            $state['spendCapped'] = true;
            $this->turns[$turn] = $state;
        }
    }

    /**
     * @param list<Message> $history
     */
    private function open(CancellationToken $turn, array $history, ?TranscriptStore $transcripts, ?string $sessionId): void
    {
        $state = [
            'turnId' => 't_' . bin2hex(random_bytes(8)),
            'sessionId' => $sessionId,
            'transcripts' => $sessionId !== null && $transcripts?->persists() === true ? $transcripts : null,
            'spendCapped' => false,
            'parked' => null,
        ];
        $this->turns[$turn] = $state;

        // The prompt this dispatch answers: the newest row the user typed and
        // the model is sent (a hidden harness row is not one).
        $prompt = null;
        for ($i = \count($history) - 1; $i >= 0; $i--) {
            $row = $history[$i] ?? null;
            if ($row instanceof Message && $row->role === Role::User && !$row->uiOnly && $row->stepId === null) {
                $prompt = $row;
                break;
            }
        }
        $identity = $prompt === null ? null : $this->identify($state, $prompt);

        $this->record($state, SessionEvent::new(SessionEvent::TURN_STARTED, array_filter([
            'turnId' => $state['turnId'],
            'messageId' => $identity[0] ?? null,
        ], static fn (mixed $value): bool => $value !== null), $sessionId, $state['turnId']));
    }

    /**
     * @return array{turnId: string, sessionId: ?string, transcripts: ?TranscriptStore, spendCapped: bool, parked: ?array{0: Message, 1: bool}}|null
     */
    private function state(?CancellationToken $turn): ?array
    {
        return $turn === null ? null : ($this->turns[$turn] ?? null);
    }

    /**
     * @param array{turnId: string, sessionId: ?string, transcripts: ?TranscriptStore} $state
     * @return array{0: string, 1: int}|null
     */
    private function identify(array $state, Message $row): ?array
    {
        if ($state['transcripts'] === null || $state['sessionId'] === null) {
            return null;
        }

        try {
            return $state['transcripts']->identify($state['sessionId'], $row);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Record a session event that no turn of this runner produced — the
     * prompt's own `message.created` (roadmap O-2g), written by
     * {@see TurnController::recordMessagesCreated()} before the turn starts —
     * through the same log-then-broadcast path the turn's events take, so a
     * listener hears it and a replay reads it under one seq.
     *
     * Returns the event as heard (a durable one stamped with the seq the log
     * gave it), or null when a durable event could not be written and so was
     * not broadcast either. A store that persists nothing, or no session id,
     * logs nothing and still broadcasts, as a turn's events do.
     */
    public function announce(SessionEvent $event, ?TranscriptStore $transcripts, ?string $sessionId): ?SessionEvent
    {
        return $this->record([
            'sessionId' => $sessionId,
            'transcripts' => $sessionId !== null && $transcripts?->persists() === true ? $transcripts : null,
        ], $event);
    }

    /**
     * Log a durable event (first), then tell the listeners.
     *
     * @param array{sessionId: ?string, transcripts: ?TranscriptStore} $state
     * @return SessionEvent|null the event as broadcast; null when a durable write failed
     */
    private function record(array $state, SessionEvent $event): ?SessionEvent
    {
        if ($event->isDurable()) {
            $log = $state['transcripts']?->events();
            if ($log !== null && $state['sessionId'] !== null) {
                try {
                    $event = $event->withSeq($log->append($state['sessionId'], $event->type, $event->payload(), $event->ts));
                } catch (\Throwable) {
                    // Not written, so not broadcast either (EventLog's
                    // contract): a listener must never get ahead of a replay.
                    return null;
                }
            }
        }

        foreach ($this->listeners as $key => $listener) {
            try {
                $listener($event);
            } catch (\Throwable) {
                unset($this->listeners[$key]);
            }
        }

        return $event;
    }
}
