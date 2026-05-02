<?php

declare(strict_types=1);

namespace CandyCore\Core;

use CandyCore\Core\Util\Ansi;

/**
 * Line-diff renderer. Compares the new frame to the previously rendered
 * frame and only rewrites lines that actually changed, sparing the
 * terminal a full repaint per tick.
 *
 * Strategy:
 *   1. First frame: home cursor, erase to end, write the whole frame.
 *   2. Subsequent frames: split both frames by "\n"; emit
 *      `cursor-to(row,1) + erase-line + new-line` only for rows where the
 *      content differs. If the new frame has fewer lines than the old one,
 *      erase the extra rows.
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

    /** @param resource $out */
    public function __construct(private $out) {}

    public function render(string $frame): void
    {
        $lines = $frame === '' ? [''] : explode("\n", $frame);

        if ($this->lastLines === null) {
            // First render: home + erase to end + full frame.
            $payload = Ansi::cursorTo(1, 1) . Ansi::eraseToEnd() . implode("\r\n", $lines);
            fwrite($this->out, $payload);
            $this->lastLines = $lines;
            return;
        }

        if ($this->lastLines === $lines) {
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
            fwrite($this->out, $payload);
        }
        $this->lastLines = $lines;
    }

    public function reset(): void
    {
        $this->lastLines = null;
    }
}
