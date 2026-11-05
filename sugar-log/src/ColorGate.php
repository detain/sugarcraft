<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use SugarCraft\ColorProfile\Probe;

/**
 * Shared destination gate for ANSI delivery (C2).
 *
 * Styling may only reach destinations that can render it: a real terminal,
 * or anywhere at all when the user sets CLICOLOR_FORCE=1 (explicit override,
 * same spelling as the sugar-colorprofile detection chain). The widely-assumed
 * FORCE_COLOR name is NOT honored by this gate — the
 * construction-time color-profile probe does read it (sugar-colorprofile
 * DetectionChain step 2, ranked above NO_COLOR).
 *
 * @internal Library plumbing; not part of the public API surface.
 */
final class ColorGate
{
    /** Whether bytes written to $target may carry ANSI escape sequences. */
    public static function allowsColor(mixed $target): bool
    {
        if (Probe::isForceColor()) {
            return true;
        }

        return \is_resource($target) && \stream_isatty($target);
    }
}
