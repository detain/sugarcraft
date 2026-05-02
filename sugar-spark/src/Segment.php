<?php

declare(strict_types=1);

namespace CandyCore\Spark;

/**
 * Base class for the chunks produced by {@see Inspector::parse()}. Every
 * segment can both render itself verbatim ({@see raw()}) and produce a
 * human-readable description ({@see describe()}).
 *
 * Concrete subtypes:
 *   - {@see TextSegment}     — visible payload between escape sequences.
 *   - {@see SequenceSegment} — a single ANSI escape sequence with a
 *     decoded label.
 */
abstract class Segment
{
    abstract public function raw(): string;
    abstract public function describe(): string;
}
