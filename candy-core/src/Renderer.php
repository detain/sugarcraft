<?php

declare(strict_types=1);

namespace CandyCore\Core;

use CandyCore\Core\Util\Ansi;
use CandyCore\Core\Util\Parser;
use CandyCore\Core\Util\Token;
use CandyCore\Core\Util\Width;

/**
 * Frame-diff renderer. Compares the new frame against the previously
 * rendered frame and emits the smallest payload that gets the
 * terminal to the new state, sparing both local CPU and (more
 * importantly) SSH bandwidth on remote sessions.
 *
 * Modes:
 *
 *   - **Full-screen line-diff** (default since v1; still the safe
 *     baseline). For each row that changed it emits
 *     `cursorTo(row, 1) + eraseLine + new_line`. Small frames, but
 *     repaints whole lines even when only one cell changed.
 *   - **Full-screen cell-diff** (the "cursed renderer", default in
 *     Bubble Tea v2, opt-in here via `$cellDiff: true`). For each
 *     row that changed it tokenises both prev and current lines via
 *     {@see Parser}, finds the longest common prefix, and emits a
 *     partial repaint from the first divergence. Dramatically smaller
 *     for the common case of slow-changing lines (progress bars,
 *     status counters, ticking clocks) — the headline win behind the
 *     v2 blog-post claim of "monetarily quantifiable" SSH bandwidth
 *     savings.
 *   - **Inline** (`$inline = true`). Only owns the program's own
 *     rows. First frame saves the cursor; subsequent frames restore +
 *     erase-to-end + repaint. Used by CandyShell `input` / `confirm` /
 *     `spin` so the user's prompt scrollback stays intact.
 *
 * Newlines between rendered lines are sent as `\r\n` so raw-mode ttys
 * (where `ONLCR` is disabled) still return to column 1. Frames are
 * wrapped in DEC 2026 synchronized-update markers so the terminal
 * commits each repaint atomically.
 *
 * @internal
 */
final class Renderer
{
    /** @var list<string>|null */
    private ?array $lastLines = null;

    /**
     * @param resource $out
     * @param bool     $inline    draw inline instead of taking over the screen
     * @param bool     $cellDiff  enable the cursed cell-diff algorithm
     *                            (Bubble Tea v2 default — meaningful
     *                            bandwidth win for SSH but slightly more
     *                            CPU per frame; ignored when `$inline`)
     */
    public function __construct(
        private $out,
        private readonly bool $inline = false,
        private readonly bool $cellDiff = false,
    ) {}

    public function render(string $frame): void
    {
        $lines = $frame === '' ? [''] : explode("\n", $frame);

        if ($this->lastLines === null) {
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
            $body = Ansi::cursorRestore() . Ansi::eraseToEnd() . implode("\r\n", $lines);
            fwrite($this->out, Ansi::syncBegin() . $body . Ansi::syncEnd());
            $this->lastLines = $lines;
            return;
        }

        $payload = $this->cellDiff
            ? $this->diffCells($this->lastLines, $lines)
            : $this->diffLines($this->lastLines, $lines);

        if ($payload !== '') {
            fwrite($this->out, Ansi::syncBegin() . $payload . Ansi::syncEnd());
        }
        $this->lastLines = $lines;
    }

    public function reset(): void
    {
        $this->lastLines = null;
    }

    /**
     * Original line-diff: full repaint of every row that changed.
     *
     * @param list<string> $prev
     * @param list<string> $curr
     */
    private function diffLines(array $prev, array $curr): string
    {
        $max = max(count($prev), count($curr));
        $payload = '';
        for ($i = 0; $i < $max; $i++) {
            $a = $prev[$i] ?? null;
            $b = $curr[$i] ?? null;
            if ($a === $b) {
                continue;
            }
            $payload .= Ansi::cursorTo($i + 1, 1) . Ansi::eraseLine();
            if ($b !== null) {
                $payload .= $b;
            }
        }
        return $payload;
    }

    /**
     * Cursed cell-diff: per row, tokenise prev + curr, find the
     * longest common token prefix, and emit a partial repaint from
     * the first divergence. Falls through to a full line repaint
     * when the partial would be larger than the full payload.
     *
     * @param list<string> $prev
     * @param list<string> $curr
     */
    private function diffCells(array $prev, array $curr): string
    {
        $max = max(count($prev), count($curr));
        $payload = '';
        for ($i = 0; $i < $max; $i++) {
            $a = $prev[$i] ?? null;
            $b = $curr[$i] ?? null;
            if ($a === $b) {
                continue;
            }
            if ($a === null || $b === null) {
                $payload .= Ansi::cursorTo($i + 1, 1) . Ansi::eraseLine();
                if ($b !== null) {
                    $payload .= $b;
                }
                continue;
            }
            $partial = $this->repaintLine($i + 1, $a, $b);
            $full    = Ansi::cursorTo($i + 1, 1) . Ansi::eraseLine() . $b;
            $payload .= strlen($partial) <= strlen($full) ? $partial : $full;
        }
        return $payload;
    }

    /**
     * Token-aware partial repaint of one row. Computes the longest
     * common token prefix between `$prev` and `$curr`, tracks SGR
     * state across that prefix, then emits
     * `cursorTo(row, col_after_prefix) + eraseToLineEnd +
     *  active_SGR + suffix_from_first_diff`.
     */
    private function repaintLine(int $row, string $prev, string $curr): string
    {
        $prevTokens = (new Parser())->parse($prev);
        $currTokens = (new Parser())->parse($curr);

        $sgr = SgrState::initial();
        $col = 0;
        $byteOffset = 0;
        $count = min(count($prevTokens), count($currTokens));

        for ($k = 0; $k < $count; $k++) {
            $pt = $prevTokens[$k];
            $ct = $currTokens[$k];
            if (self::tokensEqual($pt, $ct)) {
                $sgr->applyCsi($ct);
                $col += self::tokenWidth($ct);
                $byteOffset += self::tokenByteLength($ct);
                continue;
            }
            // Both TEXT: walk character-by-character to find the
            // longest common visible prefix. Anything else breaks.
            if ($pt->type === Token::TEXT && $ct->type === Token::TEXT) {
                [$advBytes, $advCells] = self::commonTextPrefix($pt->data, $ct->data);
                $col        += $advCells;
                $byteOffset += $advBytes;
            }
            break;
        }
        if ($byteOffset >= strlen($curr)) {
            return Ansi::cursorTo($row, $col + 1) . Ansi::eraseToLineEnd();
        }
        return Ansi::cursorTo($row, $col + 1)
             . Ansi::eraseToLineEnd()
             . $sgr->toPrefix()
             . substr($curr, $byteOffset);
    }

    /**
     * Longest common UTF-8 grapheme-aware prefix between two text
     * runs. Returns `[byte_count, cell_count]`.
     *
     * @return array{0:int,1:int}
     */
    private static function commonTextPrefix(string $a, string $b): array
    {
        $bytes = 0;
        $cells = 0;
        $aLen = strlen($a);
        $bLen = strlen($b);
        $i = 0;
        while ($i < $aLen && $i < $bLen) {
            // Walk one UTF-8 codepoint at a time to keep multi-byte
            // characters atomic. We don't need full grapheme
            // segmentation here — partial repaints inside a grapheme
            // cluster are rare enough that this approximation is fine.
            $byte = ord($a[$i]);
            $len = match (true) {
                ($byte & 0x80) === 0    => 1,
                ($byte & 0xe0) === 0xc0 => 2,
                ($byte & 0xf0) === 0xe0 => 3,
                ($byte & 0xf8) === 0xf0 => 4,
                default                 => 1,
            };
            if ($i + $len > $aLen || $i + $len > $bLen) {
                break;
            }
            if (substr($a, $i, $len) !== substr($b, $i, $len)) {
                break;
            }
            $cluster = substr($a, $i, $len);
            $cells += Width::string($cluster);
            $bytes += $len;
            $i += $len;
        }
        return [$bytes, $cells];
    }

    private static function tokensEqual(Token $a, Token $b): bool
    {
        return $a->type === $b->type
            && $a->data === $b->data
            && $a->intermediate === $b->intermediate
            && $a->params === $b->params
            && $a->final === $b->final;
    }

    private static function tokenWidth(Token $t): int
    {
        return match ($t->type) {
            Token::TEXT    => Width::string($t->data),
            Token::CONTROL => 0,
            default        => 0,
        };
    }

    private static function tokenByteLength(Token $t): int
    {
        return match ($t->type) {
            Token::TEXT, Token::CONTROL => strlen($t->data),
            Token::ESC => 2,
            Token::CSI => 2 + strlen($t->intermediate) + strlen($t->params) + strlen($t->final),
            default    => 2 + strlen($t->data) + 2,
        };
    }
}
