<?php

declare(strict_types=1);

namespace CandyCore\Spark;

/**
 * Visible text between escape sequences. {@see describe()} returns the
 * payload verbatim so it shows up unmodified in inspector reports.
 */
final class TextSegment extends Segment
{
    public function __construct(public readonly string $value) {}

    public function raw(): string      { return $this->value; }
    public function describe(): string { return $this->value; }
}
