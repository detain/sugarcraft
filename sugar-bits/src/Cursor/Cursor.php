<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Cursor;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Sprinkles\Style;

/**
 * Text-cursor primitive used inside {@see \SugarCraft\Bits\TextInput\TextInput}
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
        public readonly ?Style $style = null,
        public readonly ?Style $textStyle = null,
    ) {
        $this->id = $id ?? ++self::$nextId;
    }

    /** Construct a fresh instance with default state. */
    public static function new(string $char = ' ', Mode $mode = Mode::Blink, float $blinkSpeed = 0.5): self
    {
        return new self($char, $mode, false, true, $blinkSpeed);
    }

    /** Bubble-Tea Init — returns the bootstrap Cmd (cursor blink, first tick, etc.) or null. */
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

    /** Render the component as a multi-line ANSI string. */
    public function view(): string
    {
        $highlighted = match ($this->mode) {
            Mode::Static => $this->focused,
            Mode::Blink  => $this->focused && $this->blinkOn,
            Mode::Hidden => false,
        };
        if ($highlighted) {
            // Caller-supplied $style takes precedence over the default
            // reverse-video highlight.
            if ($this->style !== null) {
                return $this->style->render($this->char);
            }
            return Ansi::sgr(Ansi::REVERSE) . $this->char . Ansi::reset();
        }
        // Off-state: optional textStyle paints the cell when not
        // highlighted (matches upstream Bubbles' TextStyle).
        if ($this->textStyle !== null) {
            return $this->textStyle->render($this->char);
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
        $next = new self($this->char, $this->mode, true, true, $this->blinkSpeed, $this->id, $this->style, $this->textStyle);
        $cmd = $this->mode === Mode::Blink ? $next->blink() : null;
        return [$next, $cmd];
    }

    /** Release focus; companion to { focus()}. */
    public function blur(): self
    {
        return new self($this->char, $this->mode, false, true, $this->blinkSpeed, $this->id, $this->style, $this->textStyle);
    }

    public function setChar(string $c): self
    {
        return new self($c, $this->mode, $this->focused, $this->blinkOn, $this->blinkSpeed, $this->id, $this->style, $this->textStyle);
    }

    public function setMode(Mode $m): self
    {
        return new self($this->char, $m, $this->focused, true, $this->blinkSpeed, $this->id, $this->style, $this->textStyle);
    }

    /**
     * Highlight style — used when the cursor cell is "on" (focused +
     * static mode, or blink-on). Default null = reverse video.
     */
    public function withStyle(?Style $s): self
    {
        return new self($this->char, $this->mode, $this->focused, $this->blinkOn, $this->blinkSpeed, $this->id, $s, $this->textStyle);
    }

    /**
     * Off-state style — used when the cursor cell is "off" (unfocused,
     * blink-off, or Hidden mode). Default null = bare char.
     */
    public function withTextStyle(?Style $s): self
    {
        return new self($this->char, $this->mode, $this->focused, $this->blinkOn, $this->blinkSpeed, $this->id, $this->style, $s);
    }

    /** Stable per-instance ID. Mirror upstream Bubbles `ID()`. */
    public function id(): int { return $this->id; }

    /** Current cursor mode. */
    public function mode(): Mode { return $this->mode; }

    /** Configured blink interval in seconds. Mirrors Bubbles' `BlinkSpeed`. */
    public function blinkSpeed(): float { return $this->blinkSpeed; }

    /**
     * True when the cursor is currently in the "off" half of a blink
     * cycle (the cell is rendered without highlight). Mirrors Bubbles'
     * `IsBlinked()`.
     */
    public function isBlinked(): bool
    {
        if ($this->mode !== Mode::Blink) {
            return false;
        }
        return !$this->blinkOn;
    }

    private function blink(): \Closure
    {
        $id = $this->id;
        return Cmd::tick($this->blinkSpeed, static fn(): Msg => new BlinkMsg($id));
    }
}
