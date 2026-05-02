<?php

declare(strict_types=1);

namespace CandyCore\Core;

use CandyCore\Core\Util\Ansi;

/**
 * Line-diff renderer. Compares the new frame to the previously rendered
 * frame and only rewrites lines that actually changed, sparing the
 * terminal a full repaint per tick.
 *
 * Two modes:
 *
 *   - Full-screen (default): owns the entire viewport. First frame
 *     homes the cursor to (1,1) + erases to end + paints; subsequent
 *     frames cell-diff and emit `cursorTo(row, 1) + eraseLine` for
 *     each changed row only.
 *   - Inline: only owns its own rows. First frame saves the cursor
 *     at the current position and paints from there; subsequent
 *     frames restore the cursor + erase to end + repaint. Useful for
 *     non-alt-screen programs (CandyShell `input` / `confirm` /
 *     `spin`) so the user's prompt scrollback stays intact.
 *
 * Newlines between rendered lines are sent as `\r\n` so raw-mode ttys (where
 * `ONLCR` is disabled) still return to column 1.
 *
 * @internal
 */
final class Renderer
{
    /** @var list<string>|null */
    private ?array $lastLines = null;

    /**
     * @param resource $out
     */
    public function __construct(
        private $out,
        private readonly bool $inline = false,
    ) {}

    public function render(string $frame): void
    {
        $lines = $frame === '' ? [''] : explode("\n", $frame);

        if ($this->lastLines === null) {
            // First render: paint from row 1 (full-screen) or at the
            // current cursor (inline). Wrap in synchronized-update
            // markers so the terminal commits the frame atomically.
            $body = $this->inline
                ? Ansi::cursorSave() . implode("\r\n", $lines)
                : Ansi::cursorTo(1, 1) . Ansi::eraseToEnd() . implode("\r\n", $lines);
            fwrite($this->out, Ansi::syncBegin() . $body . Ansi::syncEnd());
            $this->lastLines = $lines;
            return;
        }

        if ($this->lastLines === $lines) {
            return;
        }

        if ($this->inline) {
            // Inline mode: full repaint of the program's own region
            // each frame. Cell-diffing inline is doable (track row
            // count, step back N rows) but a full repaint is correct
            // and stays well within typical inline-prompt frame sizes.
            $body = Ansi::cursorRestore() . Ansi::eraseToEnd() . implode("\r\n", $lines);
            fwrite($this->out, Ansi::syncBegin() . $body . Ansi::syncEnd());
            $this->lastLines = $lines;
            return;
        }

        $prev = $this->lastLines;
        $prevCount = count($prev);
        $currCount = count($lines);
        $max = max($prevCount, $currCount);

        $payload = '';
        for ($i = 0; $i < $max; $i++) {
            $a = $prev[$i] ?? null;
            $b = $lines[$i] ?? null;
            if ($a === $b) {
                continue;
            }
            $payload .= Ansi::cursorTo($i + 1, 1) . Ansi::eraseLine();
            if ($b !== null) {
                $payload .= $b;
            }
        }

        if ($payload !== '') {
            // Wrap every diff payload in synchronized-update markers too —
            // partial repaints are exactly when tearing is most visible.
            fwrite($this->out, Ansi::syncBegin() . $payload . Ansi::syncEnd());
        }
        $this->lastLines = $lines;
    }

    public function reset(): void
    {
        $this->lastLines = null;
    }
}
