<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape\Ast;

/**
 * `Copy "<text>"` — upstream's clipboard-write command. Compiles to the
 * terminal-native OSC 52 clipboard-set sequence, so the keystroke event is
 * real (the same wire a hardware copy produces) and the payload travels
 * base64-confined, never raw.
 */
final readonly class CopyDirective implements Directive
{
    public function __construct(
        public string $text,
    ) {
    }
}
