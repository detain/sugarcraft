<?php

declare(strict_types=1);

namespace CandyCore\Bits\Spinner;

use CandyCore\Core\Cmd;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;

/**
 * Animated spinner. Implements {@see Model} so it can be embedded in any
 * CandyCore app: return its `init()` Cmd from your model, forward
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

    public static function new(?Style $style = null): self
    {
        return new self($style ?? Style::line());
    }

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
}
