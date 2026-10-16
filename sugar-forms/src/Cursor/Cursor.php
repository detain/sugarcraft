<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Cursor;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Sprinkles\Style;

/**
 * Text-cursor primitive used inside {@see \SugarCraft\Forms\TextInput\TextInput}
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
    /**
     * Process-wide lineage counter. An id identifies a cursor LINEAGE, not
     * the latest immutable snapshot: every mutation site carries the id
     * forward so blink ticks scheduled before a mutation still match after
     * it (E736 plan 3.1 review).
     *
     * Why a counter and not spl_object_id() as the audit suggested: object
     * handles are recycled by the engine once an object dies, and the
     * lineage's first snapshot usually IS dead after the first mutation —
     * a later Cursor::new() could inherit the recycled handle and share an
     * id with a still-live lineage, cross-wiring their blink loops.
     * Monotonic uniqueness among live lineages is the correctness property
     * BlinkMsg routing depends on; the counter's growth is harmless (63-bit
     * space, ids never persisted or sent on the wire).
     */
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

    /**
     * A direct clone is a NEW lineage: without re-keying, the copy would
     * share the original's id and a BlinkMsg aimed at either one would
     * toggle both (clone-collision case, E736 plan 3.1). PHP 8.3 permits
     * re-initialising a readonly property inside __clone() precisely for
     * this situation. Note this does NOT fire for shallow field clones —
     * cloning a field copies the cursor REFERENCE, preserving the lineage,
     * which is the intended TEA semantics.
     */
    public function __clone(): void
    {
        $this->id = ++self::$nextId;
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

    /**
     * Stable per-lineage ID — identical across every immutable snapshot of
     * one cursor; a direct clone re-keys to a fresh lineage. Mirror upstream
     * Bubbles `ID()`.
     */
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

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
