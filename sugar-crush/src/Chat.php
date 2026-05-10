<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

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
 */
final class Chat implements Model
{
    private readonly Backend $backend;

    /**
     * @param list<Message> $history
     */
    public function __construct(
        public readonly array $history = [],
        public readonly string $inputBuf = '',
        public readonly bool $inFlight = false,
        ?Backend $backend = null,
        private readonly bool $streaming = false,
        private readonly ?\Closure $onToken = null,
    ) {
        $this->backend = $backend ?? new Backend\EchoBackend();
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof AssistantMsg) {
            return [new self(
                history: [...$this->history, $msg->message],
                inputBuf: $this->inputBuf,
                inFlight: false,
                backend: $this->backend,
                streaming: $this->streaming,
                onToken: $this->onToken,
            ), null];
        }
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === "\x03" /* ^C */) {
            return [$this, Cmd::quit()];
        }
        if ($this->inFlight) {
            // Ignore keystrokes while waiting for the backend
            // (avoids the user racing ahead and queuing another
            // turn into a half-formed history).
            return [$this, null];
        }

        return match (true) {
            $msg->type === KeyType::Enter
                => $this->submit(),
            $msg->type === KeyType::Char
                => [$this->withInputBuf($this->inputBuf . $msg->rune), null],
            $msg->type === KeyType::Space
                => [$this->withInputBuf($this->inputBuf . ' '), null],
            $msg->type === KeyType::Backspace
                => [$this->withInputBuf(self::dropLast($this->inputBuf)), null],
            $msg->type === KeyType::Escape
                => [$this, Cmd::quit()],
            default => [$this, null],
        };
    }

    public function view(): string
    {
        return Renderer::render($this);
    }

    public function backend(): Backend
    {
        return $this->backend;
    }

    public function withStreaming(bool $enable): self
    {
        return new self(
            history: $this->history,
            inputBuf: $this->inputBuf,
            inFlight: $this->inFlight,
            backend: $this->backend,
            streaming: $enable,
            onToken: $this->onToken,
        );
    }

    public function onToken(callable $callback): self
    {
        return new self(
            history: $this->history,
            inputBuf: $this->inputBuf,
            inFlight: $this->inFlight,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback),
        );
    }

    public function isStreaming(): bool
    {
        return $this->streaming;
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
        $next = new self(
            history: [...$this->history, Message::user($text)],
            inputBuf: '',
            inFlight: true,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $this->onToken,
        );
        $backend = $this->backend;
        $history = $next->history;
        $onToken = $this->streaming ? $this->onToken : null;
        $cmd = static fn(): Msg => new AssistantMsg($backend->complete($history, $onToken));
        return [$next, $cmd];
    }

    private function withInputBuf(string $buf): self
    {
        return new self(
            history: $this->history,
            inputBuf: $buf,
            inFlight: $this->inFlight,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $this->onToken,
        );
    }

    /**
     * Drop the last UTF-8 codepoint from `$s`. Plain `substr(-1)`
     * would corrupt multi-byte input — a backspace after typing
     * an emoji should remove the whole grapheme.
     */
    private static function dropLast(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        $i = strlen($s) - 1;
        while ($i > 0 && (ord($s[$i]) & 0xc0) === 0x80) {
            $i--;
        }
        return substr($s, 0, $i);
    }
}
