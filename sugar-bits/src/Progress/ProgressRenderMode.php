<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Progress;

/**
 * Controls how the progress bar is rendered.
 *
 * Mirrors ratatui's LineGauge render modes.
 */
enum ProgressRenderMode
{
    /** Block characters (default) — filled █, empty ░, with optional percent text. */
    case Block;

    /** Single-line: filled ━ (U+2501), empty ─ (U+2500), no percentage text. */
    case Line;

    /** Thin gauge: filled ▌ (U+2592), empty ▒ (U+2592), with percent text like Block. */
    case Slim;
}
