<?php

declare(strict_types=1);

namespace CandyCore\Bits\Cursor;

use CandyCore\Core\Cmd;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Util\Ansi;

/**
 * Text-cursor primitive used inside {@see \CandyCore\Bits\TextInput\TextInput}
 * (and similar). Renders the cell under it either highlighted (reverse
 * video) or plain depending on {@see $mode} and the current blink state.
 *
 * - When {@see Mode::Blink} and focused, the cursor toggles every
 *   {@see $blinkSpeed} seconds and reschedules the next pulse from
 *   {@see update()}.
 * - When {@see Mode::Static}, the cell is always highlighted.
 * - When {@see Mode::Hidden} or unfocused, the cell renders plain.
 */
final class Cursor implements Model
{
    private static int $nextId = 0;

    public readonly int $id;

    private function __construct(
        public readonly string $char,
        public readonly Mode $mode,
        public readonly bool $focused,
        public readonly bool $blinkOn,
        public readonly float $blinkSpeed,
        ?int $id = null,
    ) {
        $this->id = $id ?? ++self::$nextId;
    }

    public static function new(string $char = ' ', Mode $mode = Mode::Blink, float $blinkSpeed = 0.5): self
    {
        return new self($char, $mode, false, true, $blinkSpeed);
    }

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if (!$msg instanceof BlinkMsg || $msg->id !== $this->id) {
            return [$this, null];
        }
        if (!$this->focused || $this->mode !== Mode::Blink) {
            return [$this, null];
        }
        $next = new self($this->char, $this->mode, true, !$this->blinkOn, $this->blinkSpeed, $this->id);
        return [$next, $next->blink()];
    }

    public function view(): string
    {
        $highlighted = match ($this->mode) {
            Mode::Static => $this->focused,
            Mode::Blink  => $this->focused && $this->blinkOn,
            Mode::Hidden => false,
        };
        if ($highlighted) {
            return Ansi::sgr(Ansi::REVERSE) . $this->char . Ansi::reset();
        }
        return $this->char;
    }

    /**
     * Focus the cursor and (for blink mode) start the blink loop.
     *
     * @return array{0:self, 1:?\Closure}
     */
    public function focus(): array
    {
        $next = new self($this->char, $this->mode, true, true, $this->blinkSpeed, $this->id);
        $cmd = $this->mode === Mode::Blink ? $next->blink() : null;
        return [$next, $cmd];
    }

    public function blur(): self
    {
        return new self($this->char, $this->mode, false, true, $this->blinkSpeed, $this->id);
    }

    public function setChar(string $c): self
    {
        return new self($c, $this->mode, $this->focused, $this->blinkOn, $this->blinkSpeed, $this->id);
    }

    public function setMode(Mode $m): self
    {
        return new self($this->char, $m, $this->focused, true, $this->blinkSpeed, $this->id);
    }

    private function blink(): \Closure
    {
        $id = $this->id;
        return Cmd::tick($this->blinkSpeed, static fn(): Msg => new BlinkMsg($id));
    }
}
