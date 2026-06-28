<?php

declare(strict_types=1);

namespace SugarCraft\Core;

use SugarCraft\Core\Util\Color;

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
        /**
         * Per-frame mouse-tracking mode. Pass `null` to leave the
         * current setting untouched, {@see MouseMode::Off} to disable
         * tracking, or one of the on values to activate it. The
         * runtime emits the matching DEC private-mode pair only when
         * the value differs from the current mode.
         */
        public readonly ?MouseMode $mouseMode = null,
        /**
         * Per-frame focus reporting (DEC ?1004). `null` leaves the
         * current state alone; `true` / `false` toggle.
         */
        public readonly ?bool $reportFocus = null,
        /**
         * Per-frame bracketed-paste (DEC ?2004). `null` leaves the
         * current state alone.
         */
        public readonly ?bool $bracketedPaste = null,
        /**
         * Pixel-graphics image layer: a map of image id → {@see ImagePlacement}
         * (raw sixel/kitty/iTerm2 bytes + cell footprint). The {@see $body}
         * carries a one-cell marker ({@see ImageOverlay::marker()}) at the
         * top-left of each image's box; the runtime resolves those markers to
         * screen positions, paints the blobs on top of the text frame, and uses
         * the footprint to clear an image's cells when it moves or disappears.
         * Empty (the default) means no images.
         *
         * @var array<int, ImagePlacement>
         */
        public readonly array $images = [],
    ) {
    }
}
