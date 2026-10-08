<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * The sentinel law every collector obeys.
 *
 * WHY sentinels instead of exceptions or nulls: a monitor frame must render
 * on any host — a container without /sys, a BSD box, a laptop whose battery
 * was just unplugged. A collector that throws turns one missing file into a
 * dead frame; a fabricated zero draws a flat line that lies about an idle
 * machine. -1 ("not measured") and 'n/a' are the honest middle: renderers
 * test for them and draw a gap. Values match sugar-dash's ProcAvailability
 * (UNMEASURED / UNAVAILABLE_SENTINEL) so both libs speak one dialect.
 */
final class Sentinel
{
    /** A float metric that could not be measured this sample. */
    public const float UNMEASURED = -1.0;

    /** An integer metric (bytes, seconds, percent) that could not be measured. */
    public const int UNMEASURED_INT = -1;

    /** A text metric that could not be measured. */
    public const string UNAVAILABLE = 'n/a';

    private function __construct()
    {
    }
}
