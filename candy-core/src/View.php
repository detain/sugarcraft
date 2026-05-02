<?php

declare(strict_types=1);

namespace CandyCore\Core;

use CandyCore\Core\Util\Color;

/**
 * Rich render result. Models that want per-frame control over
 * cursor shape, window title, taskbar progress, etc. can return a
 * {@see View} from {@see Model::view()} instead of a plain string.
 * Mirrors Bubble Tea v2's `tea.View` struct.
 *
 * Only the {@see $body} field is required — every other field is
 * optional and only emitted when it changes between frames so the
 * terminal isn't spammed with redundant escapes.
 *
 * For the simple case, `Model::view()` keeps returning a string and
 * nothing changes — the runtime auto-wraps it.
 */
final class View
{
    public function __construct(
        public readonly string $body,
        public readonly ?Cursor $cursor = null,
        public readonly ?string $windowTitle = null,
        /**
         * Taskbar / window progress (OSC 9;4). Pass a {@see Progress}
         * value with the desired state + percent; pass `null` to leave
         * it untouched (note: this does *not* clear an active bar —
         * use `Progress::Remove` for that).
         */
        public readonly ?Progress $progressBar = null,
        /**
         * Override the terminal's default foreground colour for the
         * frame (OSC 10). `null` leaves it alone. Persists across
         * the program's lifetime — the runtime restores nothing on
         * teardown for this field.
         */
        public readonly ?Color $foregroundColor = null,
        /** Mirror of {@see $foregroundColor} for the background (OSC 11). */
        public readonly ?Color $backgroundColor = null,
    ) {}
}
