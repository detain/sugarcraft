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
 * @note Segments produced by the parser carry their true stream position
 *   through {@see byteOffset()} — an absolute offset into the input, not a
 *   running total of {@see raw()} lengths (the normalisations above mean
 *   those two diverge for the disclosed deviation shapes).
 */
abstract class Segment
{
    abstract public function raw(): string;
    abstract public function describe(): string;

    /**
     * Absolute byte offset where this segment starts in the parsed input, or
     * null for a segment that was hand-constructed and therefore carries no
     * stream position (finding 019 — the location sequin prints beside every
     * decoded entry).
     */
    abstract public function byteOffset(): ?int;
}
