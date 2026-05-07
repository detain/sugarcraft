<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Spinner;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;

/**
 * Animated spinner. Implements {@see Model} so it can be embedded in any
 * SugarCraft app: return its `init()` Cmd from your model, forward
 * {@see TickMsg}s into its `update()`, and call its `view()` wherever
 * you want the spinner to appear.
 *
 * ```php
 * $spinner = Spinner::new(Style::dot());
 * // in your model's init():     return $spinner->init();
 * // in your model's update():
 * //   if ($msg instanceof TickMsg) {
 * //       [$spinner, $cmd] = $spinner->update($msg);
 * //       return [$nextSelf->withSpinner($spinner), $cmd];
 * //   }
 * // in your model's view():     "loading {$spinner->view()}"
 * ```
 */
final class Spinner implements Model
{
    private static int $nextId = 0;

    public readonly int $id;

    private function __construct(
        public readonly Style $style,
        public readonly int $frame = 0,
        ?int $id = null,
    ) {
        $this->id = $id ?? ++self::$nextId;
    }

    /** Construct a fresh instance with default state. */
    public static function new(?Style $style = null): self
    {
        return new self($style ?? Style::line());
    }

    /** Bubble-Tea Init — returns the bootstrap Cmd (cursor blink, first tick, etc.) or null. */
    public function init(): ?\Closure
    {
        return $this->tick();
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if (!$msg instanceof TickMsg || $msg->id !== $this->id) {
            return [$this, null];
        }
        $next = new self($this->style, ($this->frame + 1) % count($this->style->frames), $this->id);
        return [$next, $next->tick()];
    }

    /** Render the component as a multi-line ANSI string. */
    public function view(): string
    {
        return $this->style->frames[$this->frame] ?? '';
    }

    /** Schedule the next tick. Returned as a Cmd from update() or init(). */
    public function tick(): \Closure
    {
        $id = $this->id;
        return Cmd::tick($this->style->interval(), static fn(): Msg => new TickMsg($id));
    }

    /**
     * Stable per-instance ID — used by {@see TickMsg} routing so two
     * spinners on the same loop don't both step on every tick.
     * Mirrors upstream Bubbles `ID()`.
     */
    public function id(): int { return $this->id; }

    /**
     * Read-only access to the active {@see Style}. Mirrors upstream's
     * public `Spinner` field, which callers inspect to read frame
     * counts, colour, FPS, etc. The same data is reachable via the
     * already-public `style` property; the method form is provided
     * for parity with the rest of the read-only API surface.
     */
    public function style(): Style { return $this->style; }

    /** Current frame index (0-based, modulo `count(style->frames)`). */
    public function frame(): int { return $this->frame; }

    /**
     * Swap the spinner style mid-flight. Frame index resets to 0 so the
     * new style starts from its first frame. The next tick adopts the
     * new style's interval automatically. Mirrors upstream's mutable
     * `Spinner` field assignment (rendered immutable here so snapshot-
     * based tests stay deterministic).
     */
    public function withStyle(Style $style): self
    {
        return new self($style, 0, $this->id);
    }
}
