<?php

declare(strict_types=1);

namespace SugarCraft\Code\Backend;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Events\PermissionAsked;
use SugarCraft\Code\Events\PermissionResolved;
use SugarCraft\Code\Events\SpendCapBreached;
use SugarCraft\Code\Events\StepStarted;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Events\ToolStarted;
use SugarCraft\Code\Events\UsageUpdated;
use SugarCraft\Code\Host\Commands\CommandEffect;
use SugarCraft\Code\Host\Commands\CommandEffectKind;
use SugarCraft\Code\Host\Commands\CommandResult;
use SugarCraft\Code\Host\RemoteSessionHost;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Message;
use SugarCraft\Code\Permissions\DenialKind;
use SugarCraft\Code\Permissions\PermissionReply;
use SugarCraft\Code\Role;
use SugarCraft\Code\Tools\ToolResult as EngineToolResult;
use SugarCraft\Code\Usage;

use function React\Promise\reject;

/**
 * The {@see \SugarCraft\Code\Backend} of a TUI attached to a running
 * `candy-code serve` (roadmap O-8a, Appendix O §4.9): a turn is the SERVER's
 * turn on the attached session, and what the Chat sees of it is the server's
 * event stream projected back onto the callbacks it would get from a local
 * engine turn.
 *
 * WHAT A TURN IS. {@see completeInteractive()} sends the history's last user
 * row as `session.send` (queued behind whatever another client is running, as
 * the server decides) and follows that turn's events by `turnId`:
 *
 *  - `assistant.delta` / `reasoning.delta` → `$onToken` / `$onReasoning`;
 *  - `tool.started` / `tool.finished` → {@see ToolStarted} / {@see ToolFinished};
 *  - `permission.requested` → {@see PermissionAsked}, whose {@see PendingAsk}
 *    answers with `permission.respond` — so the attached TUI's approval modal
 *    is one more client of the server's first-answer-wins question, and an
 *    answer given anywhere else settles it here as {@see PermissionResolved};
 *  - `subagent.*`, `spend_cap.breached` → their engine events;
 *  - `turn.step` / `usage.updated` → `$onStep`;
 *  - `assistant.completed` + `turn.completed` → the reply (an error or a
 *    cancellation on the server rejects).
 *
 * Esc-Esc ({@see CancellationToken::cancel()}) becomes a hard
 * `session.cancel`, a soft cancel the soft one; a steer is not taken — Chat
 * then sends it as the next prompt, which is where a server turn that could
 * not read it would put it too.
 *
 * WHAT IT REFUSES. A one-shot completion ({@see completeAsync()},
 * {@see complete()}) — a title, a summary, a commit message — has no method on
 * the wire: the server titles and compacts its own sessions. It rejects with
 * {@see ONE_SHOT_REFUSAL} rather than starting a turn the user never asked for.
 *
 * WHAT ELSE LANDS IN IT (C-SES-7.f). The class carries a second inbox beside
 * the turn it is running: a listener that lives as long as the backend
 * watches the attached session for turns it did NOT start — another client's —
 * and folds their `assistant.delta` streams by `turnId` so a foreign turn's
 * bytes can never interleave with this window's own; the rows a
 * `command.exec` reply carries queue beside it ({@see queueRemoteExec()}).
 * {@see \SugarCraft\Code\Chat} drains both through `RemoteFeedPumpMsg` ticks
 * (and directly on an exec answer); {@see drainRemoteFeed()} and
 * {@see takeRemoteResults()} are that drain.
 */
final class RemoteBackend implements InteractiveTurn, ObservesReasoning
{
    /** Why a one-shot completion is refused on an attached session. */
    public const ONE_SHOT_REFUSAL = 'this terminal is attached to a candy-code server: only turns run over the attachment, and the server titles and compacts its own sessions';

    /** How often a running turn checks for a soft cancel to pass on. */
    public const SOFT_CANCEL_POLL_SECONDS = 0.1;

    /** How often an attached window drains the foreign-turn feed (C-SES-7.f). */
    public const FEED_PUMP_SECONDS = 0.5;

    /**
     * How many user `message.created` rows the feed remembers while waiting
     * for the `turn.started` that names one. A foreign turn is announced by
     * messageId before that; sixty-four is headroom no honest server uses,
     * and the cap is what keeps a long-lived window from growing without
     * bound if announcements and starts ever drift apart.
     */
    public const FEED_PROMPTS_MAX = 64;

    /** The turn the CURRENT {@see runTurn()} owns, mirrored for the feed listener. */
    private ?string $feedOwnTurnId = null;

    /** Its queue id, likewise — the `turn.dequeued`/`sent` edge arms adoption. */
    private ?string $feedOwnQueueId = null;

    /** True between "the server took our queued prompt" and its `turn.started`. */
    private bool $feedOwnStartPending = false;

    /**
     * Foreign turns by `turnId` — the interleaving guard of C-SES-7.f: every
     * delta is keyed by the turn that produced it, so nothing of another
     * client's turn can ride into this window's running one.
     *
     * @var array<string, array{prompt: string, text: string, offset: int, reply: ?string, done: bool, error: ?string}>
     */
    private array $feedForeign = [];

    /** @var array<string, string> messageId => row content, see FEED_PROMPTS_MAX. */
    private array $feedPrompts = [];

    /**
     * `command.exec` answers rebuilt into {@see CommandResult}s, waiting for
     * the pump that applies them exactly like the local host-command path.
     *
     * @var list<CommandResult>
     */
    private array $feedResults = [];

    private function __construct(
        private readonly RemoteSessionHost $host,
        private readonly LoopInterface $loop,
    ) {
        // C-SES-7.f: the foreign-turn watcher. The listener of a TURN rides
        // runTurn() and detaches with it; this one lives as long as the
        // backend, and its only job is to notice turns this window did not
        // start. This window's own events pass through here too, and the
        // turnId guards inside drop them: they are the per-turn listener's
        // business, and double-projecting them would duplicate rows.
        $host->onEvent(function (array $envelope): void {
            $this->observeForeignTurn($envelope);
        });
    }

    public static function new(RemoteSessionHost $host, ?LoopInterface $loop = null): self
    {
        return new self($host, $loop ?? Loop::get());
    }

    public function host(): RemoteSessionHost
    {
        return $this->host;
    }

    /**
     * Queue one `command.exec` answer (C-SES-7.f): the wire rows and the
     * effects the wire can replay. The pump applies it through the same
     * {@see \SugarCraft\Code\Chat::applyCommandResult()} the local
     * host-command path uses, so an executed `/compact` reads identically in
     * the window whether or not it ran there.
     *
     * @param array<string, mixed> $answer the `command.exec` result frame
     */
    public function queueRemoteExec(array $answer): void
    {
        $this->feedResults[] = self::wireResult($answer);
    }

    /**
     * Queue a pre-built result — the shape a dispatch refusal takes when the
     * window's own `command.exec` promise rejected: an echo plus the error
     * row, built by the caller while its context is still fresh.
     */
    public function queueRemoteResult(CommandResult $result): void
    {
        $this->feedResults[] = $result;
    }

    /**
     * Take everything queued by the two `queueRemote*` methods, oldest first.
     *
     * @return list<CommandResult>
     */
    public function takeRemoteResults(): array
    {
        $results = $this->feedResults;
        $this->feedResults = [];

        return $results;
    }

    /** Whether a pump tick would fold anything (keeps the subscription idle-cheap). */
    public function remoteFeedPending(): bool
    {
        if ($this->feedResults !== []) {
            return true;
        }
        foreach ($this->feedForeign as $entry) {
            if ($entry['done'] === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fold the foreign turns that have COMPLETED (C-SES-7.f): a user row for
     * the prompt the other client sent, then the reply as the server's
     * `assistant.completed` carried it (or as its deltas streamed it, when
     * the completed frame never came). A turn still running is left in the
     * inbox — folding a live turn row-by-row would put the window in exactly
     * the interleaving the design forbids, and it lands whole one `turn.completed` later.
     *
     * @return list<Message>
     */
    public function drainRemoteFeed(): array
    {
        $rows = [];
        foreach ($this->feedForeign as $turnId => $entry) {
            if ($entry['done'] !== true) {
                continue;
            }
            unset($this->feedForeign[$turnId]);
            if ($entry['error'] !== null) {
                // The server's own failure row: its transcript will show the
                // same to anyone who re-snapshots, this window just sees it
                // without re-attaching.
                $rows[] = Message::notice($entry['error']);

                continue;
            }
            if ($entry['prompt'] !== '') {
                $rows[] = Message::user($entry['prompt']);
            }
            $content = $entry['reply'] ?? $entry['text'];
            if ($content !== '') {
                $rows[] = Message::assistant($content);
            }
        }

        return $rows;
    }

    /**
     * The persistent listener's body (C-SES-7.f). See the constructor for
     * what it is for; the guards that drop this window's OWN events are the
     * law of the whole method — a delta that belongs to a turn runTurn() is
     * projecting is the per-turn listener's, twice-projected here it would
     * fold as foreign on top of the live render.
     */
    private function observeForeignTurn(array $envelope): void
    {
        $sessionId = $this->host->sessionId();
        if ($sessionId === null || ($envelope['sessionId'] ?? null) !== $sessionId) {
            return;
        }
        $type = (string) ($envelope['type'] ?? '');
        $data = \is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
        $eventTurn = \is_string($envelope['turnId'] ?? null) ? $envelope['turnId'] : null;

        switch ($type) {
            case SessionEvent::MESSAGE_CREATED:
                // The prompt text a later `turn.started` will name by
                // messageId. Only the user-visible rows are announced this
                // way; ours pass through too and age out at the cap.
                if (($data['role'] ?? null) === 'user' && isset($data['messageId'], $data['content'])) {
                    $this->rememberForeignPrompt((string) $data['messageId'], (string) $data['content']);
                }

                return;

            case SessionEvent::TURN_DEQUEUED:
                // Our queued prompt became the next thing to run: the NEXT
                // `turn.started` is ours (the same edge runTurn()'s own
                // admission logic reads — see its $takeNextStart).
                if ($this->feedOwnQueueId !== null
                    && ($data['queueId'] ?? null) === $this->feedOwnQueueId
                    && ($data['reason'] ?? 'sent') === 'sent'
                ) {
                    $this->feedOwnStartPending = true;
                }

                return;

            case SessionEvent::TURN_STARTED:
                if ($eventTurn === null || $eventTurn === $this->feedOwnTurnId) {
                    return;
                }
                if ($this->feedOwnStartPending && $this->feedOwnTurnId === null) {
                    $this->feedOwnTurnId = $eventTurn;
                    $this->feedOwnStartPending = false;
                    // Anything the window had been treating as foreign under
                    // this id is this window's own turn, started: drop it.
                    unset($this->feedForeign[$eventTurn]);

                    return;
                }
                $messageId = \is_string($data['messageId'] ?? null) ? $data['messageId'] : '';
                $this->feedForeign[$eventTurn] = [
                    'prompt' => $this->feedPrompts[$messageId] ?? '',
                    'text' => '',
                    'offset' => 0,
                    'reply' => null,
                    'done' => false,
                    'error' => null,
                ];
                unset($this->feedPrompts[$messageId]);

                return;

            default:
                if ($eventTurn === null || $eventTurn === $this->feedOwnTurnId
                    || !isset($this->feedForeign[$eventTurn])
                ) {
                    // Everything of our own turn, every session-level event,
                    // and every frame for a turn the window never saw start.
                    return;
                }
                if ($type === SessionEvent::ASSISTANT_DELTA) {
                    // The same offset-dedup law the per-turn projection
                    // enforces — a replayed chunk must not double-append.
                    $chunk = (string) ($data['text'] ?? '');
                    $entry = $this->feedForeign[$eventTurn];
                    $offset = \is_int($data['offset'] ?? null) ? $data['offset'] : $entry['offset'];
                    if ($chunk === '' || $offset < $entry['offset']) {
                        return;
                    }
                    $this->feedForeign[$eventTurn]['offset'] = $offset + \strlen($chunk);
                    $this->feedForeign[$eventTurn]['text'] .= $chunk;

                    return;
                }
                if ($type === SessionEvent::ASSISTANT_COMPLETED) {
                    // Canonical over streamed — the same precedence the
                    // settled reply of a turn takes in $handle.
                    $this->feedForeign[$eventTurn]['reply'] = (string) ($data['content'] ?? '');

                    return;
                }
                if ($type === SessionEvent::TURN_COMPLETED) {
                    $this->feedForeign[$eventTurn]['done'] = true;
                    if (($data['stopReason'] ?? null) === SessionEvent::STOP_ERROR) {
                        $this->feedForeign[$eventTurn]['error'] = \is_string($data['error'] ?? null) && $data['error'] !== ''
                            ? $data['error']
                            : 'the turn failed on the server';
                    }

                    return;
                }

                return; // the window folds a foreign turn's ROWS, not its tool traffic
        }
    }

    private function rememberForeignPrompt(string $messageId, string $content): void
    {
        if ($messageId === '' || isset($this->feedPrompts[$messageId])) {
            return;
        }
        if (\count($this->feedPrompts) >= self::FEED_PROMPTS_MAX) {
            unset($this->feedPrompts[\array_key_first($this->feedPrompts)]);
        }
        $this->feedPrompts[$messageId] = $content;
    }

    /**
     * Rebuild a `command.exec` answer into the shape the local path produces
     * (C-SES-7.f). The wire carries rows whole and effects only as KIND
     * strings, so the two payload-free kinds — clearing the transcript and
     * opening the title editor — are the only ones replayed here; the rest
     * mutated the server's transcript, and the snapshot or the next durable
     * event carries their visible result.
     *
     * @param array<string, mixed> $answer
     */
    private static function wireResult(array $answer): CommandResult
    {
        $result = CommandResult::new();
        foreach ((array) ($answer['rows'] ?? []) as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $role = Role::tryFrom((string) ($row['role'] ?? ''));
            if ($role === null) {
                continue;
            }
            $content = (string) ($row['content'] ?? '');
            $message = match ($role) {
                Role::User => Message::user($content),
                Role::Assistant => Message::assistant($content),
                Role::System => Message::notice($content),
            };
            if (($row['uiOnly'] ?? false) === true) {
                $message = $message->withUiOnly();
            }
            $result = $result->withRows($message);
        }
        foreach ((array) ($answer['effects'] ?? []) as $effect) {
            $kind = \is_string($effect) ? CommandEffectKind::tryFrom($effect) : null;
            if ($kind === CommandEffectKind::ClearTranscript) {
                $result = $result->withEffect(CommandEffect::clearTranscript());
            } elseif ($kind === CommandEffectKind::OpenTitleEditor) {
                $result = $result->withEffect(CommandEffect::openTitleEditor());
            }
        }

        return $result;
    }

    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null, ?callable $onReasoning = null): Message
    {
        throw new \RuntimeException(self::ONE_SHOT_REFUSAL);
    }

    public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null, ?callable $onReasoning = null): PromiseInterface
    {
        return reject(new \RuntimeException(self::ONE_SHOT_REFUSAL));
    }

    public function completeInteractive(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null, ?callable $onReasoning = null, ?callable $onStep = null): PromiseInterface
    {
        $text = null;
        for ($i = \count($history) - 1; $i >= 0; $i--) {
            if ($history[$i] instanceof Message && $history[$i]->role === Role::User) {
                $text = $history[$i]->content;
                break;
            }
        }
        if ($text === null || \trim($text) === '') {
            return reject(new \RuntimeException('there is no prompt to send to the server'));
        }
        if (!$this->host->isOpen()) {
            return reject(new \RuntimeException($this->host->closeReason() ?? 'not connected to the server'));
        }
        if ($cancellation?->isCancelled() === true) {
            return reject(new \RuntimeException('cancelled'));
        }

        return $this->runTurn(
            $text,
            $onToken === null ? null : \Closure::fromCallable($onToken),
            $cancellation,
            $onEvent === null ? null : \Closure::fromCallable($onEvent),
            $onReasoning === null ? null : \Closure::fromCallable($onReasoning),
            $onStep === null ? null : \Closure::fromCallable($onStep),
        );
    }

    /**
     * Send $text and follow the turn it starts (or the one it is queued for)
     * to its end. See the class doc-block for the projection.
     *
     * Events are BUFFERED until `session.send` answers: the server writes a
     * turn's first durable events before the answer that names it, so the
     * turn id is learnt after events that carry it may already be here.
     */
    private function runTurn(
        string $text,
        ?\Closure $onToken,
        ?CancellationToken $cancellation,
        ?\Closure $onEvent,
        ?\Closure $onReasoning,
        ?\Closure $onStep,
    ): PromiseInterface {
        $host = $this->host;
        $loop = $this->loop;
        $sessionId = (string) $host->sessionId();
        $deferred = new Deferred();

        $settled = false;
        $admitted = false;
        $turnId = null;
        $queueId = null;
        $takeNextStart = false;
        /** @var list<array<string, mixed>> $buffer */
        $buffer = [];
        $offsets = ['text' => 0, 'reasoning' => 0];
        $reply = ['content' => null, 'reasoning' => null, 'usage' => null, 'lengthStopped' => false, 'stepsTruncated' => false];
        $streamed = '';
        /** @var array<string, PendingAsk> $asks */
        $asks = [];
        /** @var array<string, true> $answeredElsewhere */
        $answeredElsewhere = [];
        $softSent = false;
        $detachEvents = null;
        $detachClose = null;
        $detachCancel = null;
        $softTimer = null;

        // C-SES-7.f: this turn's identity, mirrored to the backend for the
        // persistent foreign-turn listener. The listener cannot tell "our
        // turn, admitted three frames ago" from a stranger without it, and
        // the closures below are static — so they are bound to the
        // properties by reference, the only non-$this handle they share.
        $mirrorOwn = &$this->feedOwnTurnId;
        $mirrorQueue = &$this->feedOwnQueueId;
        $mirrorPending = &$this->feedOwnStartPending;
        $mirrorForeign = &$this->feedForeign;

        $cancelRequested = false;
        // Stop OUR turn, or take OUR prompt out of the queue — never the turn
        // another client is running. Before the admission names either, this
        // does nothing; the admission then calls it again.
        $withdraw = static function () use ($host, &$turnId, &$queueId): void {
            if (!$host->isOpen()) {
                return;
            }
            if ($turnId !== null) {
                $host->cancel($turnId)->then(null, static function (): void {
                });
            } elseif ($queueId !== null) {
                $host->call('session.dequeue', ['sessionId' => (string) $host->sessionId(), 'queueId' => $queueId])->then(null, static function (): void {
                });
            }
        };

        $finish = static function (?Message $message, ?\Throwable $error) use (&$settled, &$detachEvents, &$detachClose, &$detachCancel, &$softTimer, &$asks, &$mirrorOwn, &$mirrorQueue, &$mirrorPending, $loop, $deferred): void {
            if ($settled) {
                return;
            }
            $settled = true;
            // C-SES-7.f: with the turn over, the feed listener goes back to
            // knowing nothing about which turn is ours — the next runTurn
            // names a new one, and until then every start it hears is
            // someone else's.
            $mirrorOwn = null;
            $mirrorQueue = null;
            $mirrorPending = false;
            foreach ([$detachEvents, $detachClose, $detachCancel] as $detach) {
                if ($detach instanceof \Closure) {
                    $detach();
                }
            }
            if ($softTimer instanceof TimerInterface) {
                $loop->cancelTimer($softTimer);
            }
            // A question still open when the turn is over has nobody left to
            // ask: its modal comes down as `cancelled`, and nothing is sent.
            foreach ($asks as $ask) {
                if (!$ask->isSettled()) {
                    $ask->cancel('the turn ended');
                }
            }
            if ($error !== null) {
                $deferred->reject($error);
            } else {
                $deferred->resolve($message);
            }
        };

        $settleAsk = static function (PermissionResolved $resolution) use ($host, &$answeredElsewhere, $onEvent): void {
            if (!isset($answeredElsewhere[$resolution->askId]) && $host->isOpen()) {
                // Cancelled here (the turn ended, the user walked away) is a
                // refusal there: the server's turn is still waiting on it.
                $host->answerPermission(
                    $resolution->askId,
                    $resolution->cancelled ? PermissionReply::Reject : ($resolution->reply ?? PermissionReply::Reject),
                    $resolution->note,
                )->then(null, static function (): void {
                    // Another client answered first (`already_resolved`), or
                    // the turn is gone: either way there is nothing to answer.
                });
            }
            if ($onEvent !== null) {
                $onEvent($resolution);
            }
        };

        $handle = static function (array $envelope) use (
            &$turnId,
            &$queueId,
            &$takeNextStart,
            &$offsets,
            &$reply,
            &$streamed,
            &$asks,
            &$answeredElsewhere,
            &$mirrorOwn,
            &$mirrorPending,
            &$mirrorForeign,
            $finish,
            $settleAsk,
            $onToken,
            $onReasoning,
            $onEvent,
            $onStep,
            $cancellation,
        ): void {
            $type = (string) ($envelope['type'] ?? '');
            $data = \is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
            $eventTurn = \is_string($envelope['turnId'] ?? null) ? $envelope['turnId'] : null;

            // Which turn is ours, when the admission did not say.
            if ($turnId === null) {
                if ($type === SessionEvent::TURN_DEQUEUED && $queueId !== null && ($data['queueId'] ?? null) === $queueId) {
                    if (($data['reason'] ?? 'sent') !== 'sent') {
                        $finish(null, new \RuntimeException('the queued prompt was removed on the server before it ran'));

                        return;
                    }
                    $takeNextStart = true;
                    $mirrorPending = true;

                    return;
                }
                if ($type === SessionEvent::TURN_STARTED && $takeNextStart && $eventTurn !== null) {
                    $turnId = $eventTurn;
                    $takeNextStart = false;
                    $mirrorOwn = $eventTurn;
                    $mirrorPending = false;
                    unset($mirrorForeign[$eventTurn]);
                }

                return;
            }
            if ($eventTurn !== $turnId) {
                return;
            }

            switch ($type) {
                case SessionEvent::ASSISTANT_DELTA:
                case SessionEvent::REASONING_DELTA:
                    $key = $type === SessionEvent::ASSISTANT_DELTA ? 'text' : 'reasoning';
                    $chunk = (string) ($data['text'] ?? '');
                    $offset = \is_int($data['offset'] ?? null) ? $data['offset'] : $offsets[$key];
                    if ($chunk === '' || $offset < $offsets[$key]) {
                        return; // a repeat: these bytes were already shown
                    }
                    $offsets[$key] = $offset + \strlen($chunk);
                    if ($key === 'text') {
                        $streamed .= $chunk;
                        $onToken?->__invoke($chunk);
                    } else {
                        $onReasoning?->__invoke($chunk);
                    }

                    return;

                case SessionEvent::TOOL_STARTED:
                    $onEvent?->__invoke(new ToolStarted(
                        (string) ($data['toolCallId'] ?? ''),
                        (string) ($data['name'] ?? ''),
                        \is_array($data['arguments'] ?? null) ? $data['arguments'] : [],
                    ));

                    return;

                case SessionEvent::TOOL_FINISHED:
                    $callId = (string) ($data['toolCallId'] ?? '');
                    $denial = null;
                    if (\is_array($data['denial'] ?? null)) {
                        foreach (DenialKind::cases() as $case) {
                            if ($case->name === ($data['denial']['kind'] ?? null)) {
                                $denial = $case;
                            }
                        }
                    }
                    $onEvent?->__invoke(new ToolFinished($callId, (string) ($data['name'] ?? ''), new EngineToolResult(
                        toolCallId: $callId,
                        content: (string) ($data['content'] ?? ''),
                        isError: ($data['isError'] ?? false) === true,
                        durationMs: \is_int($data['durationMs'] ?? null) ? $data['durationMs'] : null,
                        diff: \is_string($data['diff'] ?? null) ? $data['diff'] : null,
                        denial: $denial,
                    )));

                    return;

                case SessionEvent::PERMISSION_REQUESTED:
                    $ask = PendingAsk::fromFrame([...$data, 'suggestions' => $data['options'] ?? []], $settleAsk);
                    if ($ask === null || isset($asks[$ask->askId])) {
                        return;
                    }
                    $asks[$ask->askId] = $ask;
                    if ($onEvent === null) {
                        $ask->reply(PermissionReply::Reject, 'no approver is attached to this terminal');

                        return;
                    }
                    $onEvent(new PermissionAsked($ask));

                    return;

                case SessionEvent::PERMISSION_RESOLVED:
                    $askId = (string) ($data['askId'] ?? '');
                    $ask = $asks[$askId] ?? null;
                    if ($ask === null || $ask->isSettled()) {
                        return;
                    }
                    // Answered by another client (or timed out on the
                    // server): settled here as it was there, sending nothing.
                    $answeredElsewhere[$askId] = true;
                    $remote = PermissionReply::tryFrom((string) ($data['reply'] ?? ''));
                    $note = \is_string($data['note'] ?? null) ? $data['note'] : '';
                    if (($data['cancelled'] ?? false) === true || $remote === null) {
                        $ask->cancel($note);
                    } else {
                        $ask->reply($remote, $note);
                    }

                    return;

                case SessionEvent::SUBAGENT_STARTED:
                case SessionEvent::SUBAGENT_PROGRESS:
                case SessionEvent::SUBAGENT_FINISHED:
                    $activity = SubAgentActivity::fromArray($data);
                    if ($activity !== null) {
                        $onEvent?->__invoke($activity);
                    }

                    return;

                case SessionEvent::SPEND_CAP_BREACHED:
                    $onEvent?->__invoke(new SpendCapBreached((int) ($data['calls'] ?? 0), (float) ($data['spent'] ?? 0.0), (float) ($data['cap'] ?? 0.0), (bool) ($data['unpriced'] ?? false)));

                    return;

                case SessionEvent::TURN_STEP:
                    $step = StepStarted::fromArray($data);
                    if ($step !== null) {
                        $onStep?->__invoke($step);
                    }

                    return;

                case SessionEvent::USAGE_UPDATED:
                    $usage = UsageUpdated::fromArray($data);
                    if ($usage !== null) {
                        $onStep?->__invoke($usage);
                    }

                    return;

                case SessionEvent::ASSISTANT_COMPLETED:
                    $reply = [
                        'content' => (string) ($data['content'] ?? ''),
                        'reasoning' => \is_string($data['reasoning'] ?? null) ? $data['reasoning'] : null,
                        'usage' => Usage::fromArray($data['usage'] ?? null),
                        'lengthStopped' => ($data['lengthStopped'] ?? false) === true,
                        'stepsTruncated' => ($data['stepsTruncated'] ?? false) === true,
                    ];

                    return;

                case SessionEvent::TURN_COMPLETED:
                    $stop = (string) ($data['stopReason'] ?? SessionEvent::STOP_END_TURN);
                    if ($stop === SessionEvent::STOP_ERROR) {
                        $finish(null, new \RuntimeException(\is_string($data['error'] ?? null) && $data['error'] !== '' ? $data['error'] : 'the turn failed on the server'));

                        return;
                    }
                    if ($stop === SessionEvent::STOP_CANCELLED && ($reply['content'] === null || $cancellation?->isCancelled() === true)) {
                        $finish(null, new \RuntimeException($cancellation?->isCancelled() === true ? 'cancelled' : 'the turn was cancelled on the server'));

                        return;
                    }
                    $message = Message::assistant($reply['content'] ?? $streamed, reasoning: $reply['reasoning'])
                        ->withUsage($reply['usage'])
                        ->withLengthStopped($reply['lengthStopped'])
                        ->withStepsTruncated($reply['stepsTruncated']);
                    $finish($message, null);

                    return;
            }
        };

        // Heard from now on: anything that arrives before the admission names
        // the turn waits in $buffer.
        $detachEvents = $host->onEvent(static function (array $envelope) use ($sessionId, &$admitted, &$buffer, $handle): void {
            if (($envelope['sessionId'] ?? null) !== $sessionId) {
                return;
            }
            if (!$admitted) {
                $buffer[] = $envelope;

                return;
            }
            $handle($envelope);
        });
        $detachClose = $host->onClose(static function (string $reason) use ($finish): void {
            $finish(null, new \RuntimeException('lost the server: ' . $reason));
        });

        if ($cancellation !== null) {
            $detachCancel = $cancellation->onCancel(static function () use ($withdraw, &$cancelRequested, $finish): void {
                $cancelRequested = true;
                $withdraw();
                $finish(null, new \RuntimeException('cancelled'));
            });
            $softTimer = $loop->addPeriodicTimer(self::SOFT_CANCEL_POLL_SECONDS, static function () use ($cancellation, $host, &$turnId, &$softSent, &$softTimer, $loop): void {
                if ($softSent || !$cancellation->isSoftCancelled() || $cancellation->isCancelled() || $turnId === null) {
                    return;
                }
                $softSent = true;
                if ($softTimer instanceof TimerInterface) {
                    $loop->cancelTimer($softTimer);
                }
                $host->cancel($turnId, 'soft')->then(null, static function (): void {
                });
            });
        }

        $host->submit($text)->then(
            static function (array $admission) use (&$admitted, &$buffer, &$turnId, &$queueId, &$takeNextStart, &$settled, &$cancelRequested, &$mirrorOwn, &$mirrorQueue, &$mirrorPending, &$mirrorForeign, $withdraw, $handle, $finish): void {
                if ($settled) {
                    // Cancelled before the server said what it did with the
                    // prompt: undo exactly that, now that it is known.
                    if ($cancelRequested) {
                        $turnId = \is_string($admission['turnId'] ?? null) ? $admission['turnId'] : null;
                        $queueId = \is_string($admission['queueId'] ?? null) ? $admission['queueId'] : null;
                        $withdraw();
                    }

                    return;
                }
                switch ($admission['admitted'] ?? null) {
                    case 'started':
                        $turnId = \is_string($admission['turnId'] ?? null) ? $admission['turnId'] : null;
                        $takeNextStart = $turnId === null;
                        break;
                    case 'queued':
                        $queueId = \is_string($admission['queueId'] ?? null) ? $admission['queueId'] : null;
                        $takeNextStart = $queueId === null;
                        break;
                    case 'pending':
                        $takeNextStart = true;
                        break;
                    default:
                        $finish(null, new \RuntimeException(\sprintf('the server did not run the prompt (%s)', (string) ($admission['admitted'] ?? 'no admission'))));

                        return;
                }
                // C-SES-7.f: tell the persistent feed listener what the
                // admission decided, so it stops treating this window's own
                // turn as foreign — including any frames it filed as a
                // stranger's before the admission named the turn id.
                $mirrorOwn = $turnId;
                $mirrorQueue = $queueId;
                $mirrorPending = $takeNextStart;
                if ($turnId !== null) {
                    unset($mirrorForeign[$turnId]);
                }
                $admitted = true;
                $held = $buffer;
                $buffer = [];
                foreach ($held as $envelope) {
                    $handle($envelope);
                }
            },
            static function (\Throwable $e) use ($finish): void {
                $finish(null, new \RuntimeException('the server refused the prompt: ' . $e->getMessage(), 0, $e));
            },
        );

        return $deferred->promise();
    }
}
