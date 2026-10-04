<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Events\PermissionAsked;
use SugarCraft\Crush\Events\PermissionResolved;
use SugarCraft\Crush\Events\SpendCapBreached;
use SugarCraft\Crush\Events\StepStarted;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Events\UsageUpdated;
use SugarCraft\Crush\Host\RemoteSessionHost;
use SugarCraft\Crush\Host\SessionEvent;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\DenialKind;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;
use SugarCraft\Crush\Usage;

use function React\Promise\reject;

/**
 * The {@see \SugarCraft\Crush\Backend} of a TUI attached to a running
 * `sugarcrush serve` (roadmap O-8a, Appendix O §4.9): a turn is the SERVER's
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
 */
final class RemoteBackend implements InteractiveTurn, ObservesReasoning
{
    /** Why a one-shot completion is refused on an attached session. */
    public const ONE_SHOT_REFUSAL = 'this terminal is attached to a sugarcrush server: only turns run over the attachment, and the server titles and compacts its own sessions';

    /** How often a running turn checks for a soft cancel to pass on. */
    public const SOFT_CANCEL_POLL_SECONDS = 0.1;

    private function __construct(
        private readonly RemoteSessionHost $host,
        private readonly LoopInterface $loop,
    ) {
    }

    public static function new(RemoteSessionHost $host, ?LoopInterface $loop = null): self
    {
        return new self($host, $loop ?? Loop::get());
    }

    public function host(): RemoteSessionHost
    {
        return $this->host;
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

        $finish = static function (?Message $message, ?\Throwable $error) use (&$settled, &$detachEvents, &$detachClose, &$detachCancel, &$softTimer, &$asks, $loop, $deferred): void {
            if ($settled) {
                return;
            }
            $settled = true;
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

                    return;
                }
                if ($type === SessionEvent::TURN_STARTED && $takeNextStart && $eventTurn !== null) {
                    $turnId = $eventTurn;
                    $takeNextStart = false;
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
                    $onEvent?->__invoke(new SpendCapBreached((int) ($data['calls'] ?? 0), (float) ($data['spent'] ?? 0.0), (float) ($data['cap'] ?? 0.0)));

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
            static function (array $admission) use (&$admitted, &$buffer, &$turnId, &$queueId, &$takeNextStart, &$settled, &$cancelRequested, $withdraw, $handle, $finish): void {
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
