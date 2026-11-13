<?php

declare(strict_types=1);

namespace SugarCraft\AnsiInspect;

/**
 * Base class for the chunks produced by {@see Inspector::parse()}. Every
 * segment can both render itself verbatim ({@see raw()}) and produce a
 * human-readable description ({@see describe()}).
 *
 * Concrete subtypes:
 *   - {@see TextSegment}     — visible payload between escape sequences.
 *   - {@see SequenceSegment} — a single ANSI escape sequence with a
 *     decoded label.
 *
 * Mirrors the entry shape charmbracelet/sequin prints per stream chunk:
 * payload bytes and their annotation, kept addressable apart.
 *
 * @note Sequences are rendered in normalised form by {@see raw()}.  OSC/DCS
 *   terminators are normalised to BEL/ST and may differ byte-for-byte from
 *   the original input (e.g. OSC ST \x1b\\ becomes BEL \x07).
 */
abstract class Segment
{
    abstract public function raw(): string;
    abstract public function describe(): string;
}
