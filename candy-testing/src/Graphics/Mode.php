<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Graphics;

use SugarCraft\Testing\Lang;

/**
 * The terminal graphics protocols a stream can speak.
 *
 * Mirrors the three renderer families in sugarcraft/candy-mosaic
 * (SixelRenderer, KittyRenderer, Iterm2Renderer) but is deliberately a local,
 * dependency-free vocabulary so the decoders stand alone.
 */
enum Mode: string
{
    case Sixel = 'sixel';
    case Kitty = 'kitty';
    case Iterm2 = 'iterm2';

    /**
     * Sniff the protocol a byte stream speaks from its control introducer.
     *
     * Sixel rides a DCS with a `q` color-space introducer; Kitty (as emitted by
     * candy-mosaic) rides a DCS whose parameter starts with `q`, or the standard
     * APC `_G` form; iTerm2 rides an OSC 1337. The probe matches the framing the
     * decoders themselves accept — a transmit embedded in surrounding screen text
     * still sniffs correctly. Detection is best-effort framing only; the stream is
     * parsed strictly once a mode is chosen.
     */
    public static function detect(string $stream): self
    {
        if (str_contains($stream, "\x1b]1337;")) {
            return self::Iterm2;
        }

        if (str_contains($stream, "\x1bPq") || str_contains($stream, "\x1b_G")) {
            return self::Kitty;
        }

        if (str_contains($stream, "\x1bP")) {
            return self::Sixel;
        }

        throw new MalformedGraphicsException(Lang::t('graphics.detect.unknown_protocol'));
    }
}
