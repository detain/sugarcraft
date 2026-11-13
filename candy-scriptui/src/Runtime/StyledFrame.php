<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Runtime;

use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\View;
use SugarCraft\Style\Style;

/**
 * A model decorator that paints the lipgloss frame around an inner model.
 *
 * scriptui-26 (candy-scriptui-025): gum embeds the style surface in every
 * interactive command, and candy's plumbing (StyleBuilder) existed without a
 * single interactive consumer. Rather than teach all seven models about a
 * style field (and risk a forgotten forward in an update path), the command
 * wraps once: `view()` renders the inner body through the frame Style, and
 * every update re-wraps the next state so the chrome survives the session.
 * Identity is preserved while the inner model refuses to move.
 *
 * The frame Style is expected pre-clamped: ColourGate/StyleBuilder run
 * upstream, so --no-ansi, NO_COLOR and TERM=dumb reach the frame as plain
 * structure (border glyphs are text; colour and decoration are gone).
 *
 * Scope law: this decorates the plain-string view contract. A View-returning
 * model (cursor geometry, panes) would need offset math and is passed
 * through unchanged; none of the interactive candy models return View.
 */
final class StyledFrame implements Model
{
    public function __construct(
        public readonly Model $inner,
        private readonly Style $frame,
    ) {}

    public function init(): ?\Closure
    {
        return $this->inner->init();
    }

    /**
     * @return array{0: Model, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        [$next, $cmd] = $this->inner->update($msg);

        return [$next === $this->inner ? $this : new self($next, $this->frame), $cmd];
    }

    public function view(): string|View
    {
        $body = $this->inner->view();

        return is_string($body) ? $this->frame->render($body) : $body;
    }

    public function subscriptions(): ?Subscriptions
    {
        return $this->inner->subscriptions();
    }
}
