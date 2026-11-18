<?php

declare(strict_types=1);

namespace SugarCraft\Core;

use SugarCraft\AnsiParser\AnsiTokenizer;
use SugarCraft\AnsiParser\Token;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

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
 *     {@see AnsiTokenizer}, finds the longest common prefix, and emits a
 *     partial repaint from the first divergence. Dramatically smaller
 *     for the common case of slow-changing lines (progress bars,
 *     status counters, ticking clocks) — the headline win behind the
 *     v2 blog-post claim of "monetarily quantifiable" SSH bandwidth
 *     savings.
 *   - **Inline** (`$inline = true`). Only owns the program's own
 *     rows. First frame saves the cursor; subsequent frames restore +
 *     erase-to-end + repaint. Used by CandyScriptui `input` / `confirm` /
 *     `spin` so the user's prompt scrollback stays intact.
 *
 * Newlines between rendered lines are sent as `\r\n` so raw-mode ttys
 * (where `ONLCR` is disabled) still return to column 1. Frames are
 * wrapped in DEC 2026 synchronized-update markers so the terminal
 * commits each repaint atomically.
 */
final class Renderer
{
    /**
     * Wall-clock ceiling for a synchronous write (audit sugar-core-018). The
     * value lives on {@see Writer}, which owns the write loop now.
     */
    public const WRITE_WAIT_DEADLINE = Writer::WRITE_WAIT_DEADLINE;

    /** How many consecutive stream_select() false returns {@see Writer} tolerates. */
    public const MAX_SELECT_FALSE_RETRY = Writer::MAX_SELECT_FALSE_RETRY;

    /** @var list<string>|null */
    private ?array $lastLines = null;

    /** @var array<string, list<Token>> token cache keyed by string */
    private array $tokenCache = [];

    /**
     * Hard cap on {@see $tokenCache} entries. A long-running program renders
     * an unbounded number of distinct lines over its lifetime, so an
     * uncapped cache is a slow memory leak. When the cap is reached the
     * oldest half is dropped in one pass (amortized O(1) eviction); evicted
     * lines are simply re-parsed on next sight to the identical token list,
     * so output stays byte-identical — this only bounds memory.
     */
    private const TOKEN_CACHE_MAX = 2000;

    /** Shared tokenizer for cell-diff repaints — avoids repeated allocation. */
    private AnsiTokenizer $tokenizer;

    private readonly Writer $writer;

    /**
     * @param resource $out
     * @param bool     $inline    draw inline instead of taking over the screen
     * @param bool     $cellDiff  enable the cursed cell-diff algorithm
     *                            (Bubble Tea v2 default — meaningful
     *                            bandwidth win for SSH but slightly more
     *                            CPU per frame; ignored when `$inline`)
     */
    public function __construct(
        $out,
        private readonly bool $inline = false,
        private readonly bool $cellDiff = false,
        private readonly bool $synchronized = true,
        ?Writer $writer = null,
    ) {
        $this->tokenizer = AnsiTokenizer::new();
        // Program passes its own writer so frames and program writes share one
        // ordered queue. Standalone renderers get a synchronous writer on $out.
        $this->writer = $writer ?? new Writer($out);
    }

    /**
     * Tee every byte chunk this renderer emits to the given recorder
     * (or pass null to detach). Wired by {@see Program::setRecorder()};
     * direct callers shouldn't need this.
     */
    public function setRecorder(?Recorder $recorder): void
    {
        $this->writer->setRecorder($recorder);
    }

    public function render(string $frame): void
    {
        $lines = $frame === '' ? [''] : explode("\n", $frame);

        if ($this->lastLines === null) {
            $body = $this->inline
                ? Ansi::cursorSave() . implode("\r\n", $lines)
                : Ansi::cursorTo(1, 1) . Ansi::eraseToEnd() . implode("\r\n", $lines);
            $this->emit($this->sync($body));
            $this->lastLines = $lines;
            return;
        }

        if ($this->lastLines === $lines) {
            return;
        }

        if ($this->inline) {
            $body = Ansi::cursorRestore() . Ansi::eraseToEnd() . implode("\r\n", $lines);
            $this->emit($this->sync($body));
            $this->lastLines = $lines;
            return;
        }

        $payload = $this->cellDiff
            ? $this->diffCells($this->lastLines, $lines)
            : $this->diffLines($this->lastLines, $lines);

        if ($payload !== '') {
            $this->emit($this->sync($payload));
        }
        $this->lastLines = $lines;
    }

    /** DEC 2026 wrap, unless the terminal never advertised the mode ({@see ProgramOptions::$synchronizedOutput}). */
    private function sync(string $body): string
    {
        return $this->synchronized ? Ansi::syncBegin() . $body . Ansi::syncEnd() : $body;
    }

    public function reset(): void
    {
        $this->lastLines = null;
        $this->tokenCache = [];
    }

    private function emit(string $bytes): void
    {
        $this->writer->write($bytes);
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
        // The erase bytes are row-independent; the full-repaint payload is
        // assembled only when it actually wins the comparison (PERF-05:
        // measuring `strlen($partial) <= strlen($full)` previously built
        // the loser string every divergent row, every frame).
        $erase = Ansi::eraseLine();
        for ($i = 0; $i < $max; $i++) {
            $a = $prev[$i] ?? null;
            $b = $curr[$i] ?? null;
            if ($a === $b) {
                continue;
            }
            if ($a === null || $b === null) {
                $payload .= Ansi::cursorTo($i + 1, 1) . $erase;
                if ($b !== null) {
                    $payload .= $b;
                }
                continue;
            }
            $partial = $this->repaintLine($i + 1, $a, $b);
            $prefix  = Ansi::cursorTo($i + 1, 1) . $erase;
            $payload .= strlen($partial) <= strlen($prefix) + strlen($b)
                ? $partial
                : $prefix . $b;
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
        $prevTokens = $this->getTokens($prev);
        $currTokens = $this->getTokens($curr);

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
     * The walk is O(min(len(a), len(b))) codepoints and consults
     * `Width::string()` per cluster — that is the honest cost centre of
     * a partial repaint (PERF leg sc-18: kept, not hidden). A memo
     * table is deliberately NOT added: keyed by arbitrary user-content
     * substrings it would be unbounded, and the walk ends at the first
     * differing token, which in practice is within the row's prefix.
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
            && $a->final === $b->final
            && $a->terminator === $b->terminator;
    }

    private static function tokenWidth(Token $t): int
    {
        return match ($t->type) {
            Token::TEXT    => Width::string($t->data),
            Token::CONTROL => 0,
            default        => 0,
        };
    }

    /**
     * Bytes of the source line `$t` was parsed from. String tokens (OSC /
     * DCS / APC / SOS / PM) count the terminator the parser actually saw:
     * charging every one a 2-byte ST made a BEL-closed OSC (`ESC ] 0;t BEL`)
     * one byte too long, so repaintLine() cut its suffix one byte late and
     * the partial repaint lost the first byte of the change.
     */
    private static function tokenByteLength(Token $t): int
    {
        return match ($t->type) {
            Token::TEXT, Token::CONTROL => strlen($t->data),
            Token::ESC => 2,
            Token::CSI => 2 + strlen($t->intermediate) + strlen($t->params) + strlen($t->final),
            default    => 2 + strlen($t->data) + strlen($t->terminator),
        };
    }

    /**
     * Parse a string into tokens, caching results by string key
     * to avoid re-parsing unchanged lines across frames.
     *
     * @return list<Token>
     */
    private function getTokens(string $s): array
    {
        if (isset($this->tokenCache[$s])) {
            return $this->tokenCache[$s];
        }
        if (\count($this->tokenCache) >= self::TOKEN_CACHE_MAX) {
            $this->tokenCache = \array_slice($this->tokenCache, self::TOKEN_CACHE_MAX >> 1, null, true);
        }
        // A row is a complete unit, but AnsiTokenizer is a STREAM tokeniser: an
        // escape left unterminated at the end of `$s` is buffered as pending
        // and prepended to the NEXT parse() — the next row, the other side of
        // the diff — whose tokens (and cache entry) then describe bytes that
        // are not in that row. flush() hands the tail back as TEXT so every
        // row's tokens cover exactly its own bytes.
        $tokens = $this->tokenizer->parse($s);
        foreach ($this->tokenizer->flush() as $tail) {
            $tokens[] = $tail;
        }
        return $this->tokenCache[$s] = $tokens;
    }
}
