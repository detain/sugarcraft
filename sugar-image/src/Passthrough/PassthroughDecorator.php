<?php

declare(strict_types=1);

namespace SugarCraft\Image\Passthrough;

use SugarCraft\Image\Renderer\Renderer;

/**
 * F-12: common surface of the two passthrough envelopes.
 *
 * A terminal multiplexer sways control sequences addressed to the outer
 * terminal unless they ride inside a mux-specific passthrough wrapper.
 * chafa models exactly two muxes worth wrapping (chafa-term.h
 * ChafaPassThroughType: CHAFA_PASSTHROUGH_NONE / _TMUX / _SCREEN) and
 * SugarCraft ships one decorator per mux:
 *
 * - {@see \SugarCraft\Image\TmuxPassthroughDecorator} — tmux: whole-run
 *   envelope `ESC P tmux; … ESC \` with every inner ESC doubled, so tmux's
 *   parser can find its own terminator past arbitrary embedded sequences.
 * - {@see ScreenPassthrough} — GNU Screen: the payload is split into
 *   begin/end-bounded packets of at most 200 bytes with NO ESC-doubling,
 *   plus the byte-wise String-Terminator workaround for sixel end
 *   sequences (chafa-sixel-renderer.c end_sixels).
 *
 * Both decorators wrap an inner {@see \SugarCraft\Image\Renderer\Renderer}
 * and expose this `wrap()` as the pure byte-level half of their job; the
 * streaming half (encodeBandStream) lives on the decorators themselves
 * because each mux's framing rules chunk differently.
 */
interface PassthroughDecorator
{
    /**
     * Rewrite a complete ANSI byte string so every control-sequence run
     * it contains travels through the multiplexer intact.
     *
     * Bytes outside recognised runs (DCS `ESC P`, OSC `ESC ]`, APC `ESC _`)
     * are copied verbatim; a run left unterminated by truncation is wrapped
     * to the end of the input, mirroring chicle's flush-on-close behaviour.
     */
    public function wrap(string $ansi): string;

    /** The renderer being enveloped (unwrapping for configuration sites). */
    public function inner(): Renderer;

    /**
     * Wrap a reconfigured renderer in THIS envelope flavour — how Mosaic's
     * with*() sites re-apply the decorator after swapping the inner one,
     * polymorphically and without instanceof ladders over concrete classes.
     */
    public function decorate(Renderer $inner): Renderer;
}
