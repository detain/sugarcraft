<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Acp;

use SugarCraft\Crush\Host\SessionHost;

/**
 * One Agent Client Protocol session: the {@see SessionHost} it drives and
 * what the adapter has to remember about the prompt in flight (roadmap 5.9-1).
 *
 * A PROMPT IS ANSWERED ONCE, WHEN THE SESSION GOES IDLE. `session/prompt` is
 * a request whose response is the turn's end, so its id is held here until
 * the host has no turn left — the turn it started, and anything that turn
 * left queued — and then answered with the stop reason the last
 * `turn.completed` named ({@see AcpServer::tick()}).
 *
 * WHAT WAS STREAMED, so nothing is said twice or lost. The live deltas are
 * folded on the host's tick and a turn that settles between two ticks drops
 * the ones not yet folded; the settled `assistant.completed` carries the
 * whole reply. What was already sent of it is kept here, so the adapter can
 * send exactly the part the editor has not seen.
 *
 * MUTABLE ON PURPOSE, like the host it wraps: the live state of one session.
 */
final class AcpSession
{
    private string|int|null $promptId = null;

    private bool $prompting = false;

    private string $streamedText = '';

    private string $streamedThought = '';

    private ?string $stopReason = null;

    private ?string $error = null;

    private ?\Closure $detach = null;

    private function __construct(public readonly SessionHost $host)
    {
    }

    public static function new(SessionHost $host): self
    {
        return new self($host);
    }

    public function sessionId(): string
    {
        return $this->host->sessionId();
    }

    /** Keep the closure that stops this session's event listener. */
    public function listening(\Closure $detach): void
    {
        $this->detach = $detach;
    }

    /** Stop hearing the host's events. */
    public function detach(): void
    {
        $detach = $this->detach;
        $this->detach = null;
        if ($detach !== null) {
            $detach();
        }
    }

    /** Hold the `session/prompt` request $id until its turn ends. */
    public function beginPrompt(string|int|null $id): void
    {
        $this->promptId = $id;
        $this->prompting = true;
        $this->stopReason = null;
        $this->error = null;
        $this->beginTurn();
    }

    public function isPrompting(): bool
    {
        return $this->prompting;
    }

    public function promptId(): string|int|null
    {
        return $this->promptId;
    }

    /**
     * Let go of the prompt; returns the stop reason and error its turn left.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function endPrompt(): array
    {
        $ended = [$this->stopReason, $this->error];
        $this->prompting = false;
        $this->promptId = null;
        $this->stopReason = null;
        $this->error = null;

        return $ended;
    }

    /** A new turn: nothing of its reply has been streamed yet. */
    public function beginTurn(): void
    {
        $this->streamedText = '';
        $this->streamedThought = '';
    }

    public function streamedText(string $delta): void
    {
        $this->streamedText .= $delta;
    }

    public function streamedThought(string $delta): void
    {
        $this->streamedThought .= $delta;
    }

    /**
     * The part of $settled the editor has not been sent: all of it when the
     * stream sent nothing (or something else), else what follows the stream.
     */
    public function unsentText(string $settled): string
    {
        return self::remainder($settled, $this->streamedText);
    }

    public function unsentThought(string $settled): string
    {
        return self::remainder($settled, $this->streamedThought);
    }

    /** What the turn that just completed ended on. */
    public function completed(?string $stopReason, ?string $error): void
    {
        $this->stopReason = $stopReason;
        $this->error = $error;
    }

    private static function remainder(string $settled, string $streamed): string
    {
        if ($streamed === '') {
            return $settled;
        }

        return str_starts_with($settled, $streamed) ? substr($settled, \strlen($streamed)) : '';
    }
}
