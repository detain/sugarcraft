<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Core\Msg;

/**
 * The take did not become text (C-TUI-6.l). `line` is an already-translated
 * sentence from the `media.voice.*` family — refusals are visible by
 * design: off, no recorder, empty take, and endpoint failures all land here
 * so the user learns which case they are in instead of watching silence.
 *
 * The fold raises a notice (C-TUI-6.m). The sentence inside was assembled
 * only from status codes and sanitized endpoint text, so it is printable as
 * received; the API key was never part of it.
 */
final class VoiceFailedMsg implements Msg
{
    public function __construct(
        public readonly string $line,
    ) {
    }
}
