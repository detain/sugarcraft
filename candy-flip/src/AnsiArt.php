<?php

declare(strict_types=1);

namespace SugarCraft\Flip;

use InvalidArgumentException;
use OutOfRangeException;
use RuntimeException;

/**
 * A parsed `.ansi` ANSI-art document (the candy-top logo corpus format).
 *
 * Byte-level format this parses: static files are raw SGR-escaped text, one
 * terminal row per `\n`, with a `\x1b[0m` reset closing the last row;
 * animated files wrap the stream in `\x1b[?25l` / `\x1b[?25h` (hide/show
 * cursor) and start EVERY frame after the first with a cursor-up-and-return
 * `\x1b[<H>A\r` (H = logo height) followed by a full-height redraw. There are
 * no embedded frame delays — pacing is the caller's concern (see
 * {@see ArtLoop}).
 *
 * Anything the corpus never emits (unknown CSI sequences, well-formed OSC
 * strings such as hyperlinks, stray `\r` inside a row body) is dropped
 * conservatively: art is replayed cell-wise, so a positioning sequence we
 * cannot honour must not leak into the model as a phantom glyph. Dropped
 * bytes are documented per site below.
 *
 * Deliberately NOT done: grapheme clustering. The corpus is strictly
 * codepoint-per-cell (box-drawing, half/full blocks, and ASCII), and the
 * generating renderer wrote one SGR run per cell region — clustering would
 * merge adjacent same-colour codepoints that a terminal paints separately.
 *
 * Immutable fixed document: all state is built in {@see fromString()} and
 * never mutated afterwards; no `with*()` surface is meaningful for artwork.
 */
final class AnsiArt
{
    /** Cursor-up + carriage-return header introducing each redraw frame. */
    private const FRAME_HEADER = '/\A\x1b\[(\d+)A\r/';

    /** Lookahead split point between frames (the cursor-up+CR full-redraw convention). */
    private const FRAME_SPLIT = '/(?=\x1b\[\d+A\r)/';

    /** An SGR sequence: ESC [ <parameter bytes> m (';' is inside \x30-\x3f). */
    private const SGR_AT = '/\G\x1b\[([\x30-\x3f]*)m/';

    /** Any other CSI sequence (final byte @-Z or a-z minus m) — dropped. */
    private const OTHER_CSI_AT = '/\G\x1b\[[\x30-\x3f]*[@-Za-ln-z]/';

    /**
     * A complete OSC string (ESC ] … terminated by BEL or ST) — dropped.
     * Hyperlink payloads (`\x1b]8;;url\a`) are otherwise printable text and
     * would re-enter the model as phantom glyph cells.
     */
    private const OSC_AT = '/\G\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)/';

    /**
     * @param list<list<list<AnsiCell>>> $frames  frames[row][col] = cell
     * @param int<0,max> $width   max cell-count over every row of every frame
     * @param int<0,max> $height  row count of frame 0 (the canonical layout)
     */
    private function __construct(
        private readonly array $frames,
        private readonly int $width,
        private readonly int $height,
    ) {}

    /**
     * @throws InvalidArgumentException when the bytes hold no renderable rows
     */
    public static function fromString(string $bytes): self
    {
        if ($bytes === '') {
            throw new InvalidArgumentException('AnsiArt: empty byte string, nothing to parse');
        }

        // The cursor-hide/show pairs carry no per-cell meaning once we model
        // frames explicitly; strip them everywhere (also tolerates static
        // files that were saved mid-animation by tooling).
        $clean = str_replace(["\x1b[?25l", "\x1b[?25h"], '', $bytes);

        $chunks = preg_split(self::FRAME_SPLIT, $clean);
        if ($chunks === false) {
            throw new InvalidArgumentException('AnsiArt: frame split failed (PCRE error)');
        }

        $frames = [];
        $width = 0;
        foreach ($chunks as $chunk) {
            if ($chunk === '') {
                continue; // empty chunk between adjacent headers: not a frame
            }
            $header = [];
            if (preg_match(self::FRAME_HEADER, $chunk, $header) === 1) {
                $chunk = substr($chunk, strlen($header[0]));
            }
            $frame = self::parseFrame($chunk, $width);
            if ($frame === []) {
                continue; // keep only frames that render >=1 row
            }
            $frames[] = $frame;
        }

        // width === 0 means every surviving row was blank: rows exist, but
        // there is no artwork — treat as garbage rather than an empty canvas.
        if ($frames === [] || $width === 0) {
            throw new InvalidArgumentException('AnsiArt: no renderable cells found in byte string');
        }

        return new self($frames, $width, count($frames[0]));
    }

    /**
     * @throws RuntimeException when the path is missing, unreadable, or a directory
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("AnsiArt: cannot read art file '{$path}'");
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException("AnsiArt: failed to read art file '{$path}'");
        }

        return self::fromString($bytes);
    }

    /** Max cell count over every row of every frame (half-blocks are single cells). */
    public function width(): int
    {
        return $this->width;
    }

    /** Row count of frame 0; redraw frames are assumed full-height by the corpus. */
    public function height(): int
    {
        return $this->height;
    }

    public function frameCount(): int
    {
        return count($this->frames);
    }

    public function isAnimated(): bool
    {
        return count($this->frames) > 1;
    }

    /**
     * Rows of cells for one frame. Row lengths are kept NATURAL (ragged):
     * trailing default-background spaces are absent in the corpus, and
     * padding them would invent cells the artwork never painted.
     *
     * @return list<list<AnsiCell>>
     * @throws OutOfRangeException when the frame index is unknown
     */
    public function frameCells(int $frame): array
    {
        if ($frame < 0 || $frame >= count($this->frames)) {
            throw new OutOfRangeException("AnsiArt: frame {$frame} out of range 0.." . (count($this->frames) - 1));
        }

        return $this->frames[$frame];
    }

    /**
     * Raw single-frame byte blob for apps that prefer replaying escapes
     * verbatim instead of walking cells. Prefixes are re-emitted only on
     * SGR-state *transitions* (a default cell after a coloured one re-gets
     * its `\x1b[0m`), and each row closes in default state, so the blob is
     * both terminal-correct as replay and cell-exactly re-parseable by
     * {@see fromString()} — the per-cell model alone could not promise the
     * second half, since '' cannot distinguish "never coloured" from "just
     * reset".
     *
     * @throws OutOfRangeException when the frame index is unknown
     */
    public function sgrText(int $frame): string
    {
        $rows = $this->frameCells($frame);
        $last = count($rows) - 1;
        $out = [];
        foreach ($rows as $index => $row) {
            $line = '';
            $previous = '';
            foreach ($row as $cell) {
                if ($cell->sgr !== $previous) {
                    // At row start '' is already the state, so a default
                    // first cell emits nothing; later it means "reset back".
                    $line .= $cell->sgr === '' ? "\x1b[0m" : $cell->sgr;
                    $previous = $cell->sgr;
                }
                $line .= $cell->glyph;
            }
            if ($previous !== '' && $index !== $last) {
                $line .= "\x1b[0m"; // rows are self-contained: next row starts default
            }
            $out[] = $line;
        }

        return implode("\n", $out) . "\x1b[0m";
    }

    /**
     * Split one frame body into rows and tokenize each row into cells.
     * Returns [] when the body holds no rows at all.
     *
     * @param int<0,max> $width in/out: max row cell-count seen so far
     * @return list<list<AnsiCell>>
     */
    private static function parseFrame(string $body, int &$width): array
    {
        $lines = explode("\n", $body);
        // A trailing newline terminates the last row; it is not itself a row.
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $frame = [];
        foreach ($lines as $line) {
            $cells = self::tokenizeRow($line);
            $count = count($cells);
            if ($count > $width) {
                $width = $count;
            }
            $frame[] = $cells;
        }

        return $frame;
    }

    /**
     * Walk one row, tracking the active SGR prefix and emitting one cell per
     * codepoint. `\x1b[0m` and `\x1b[m` normalise the active prefix to '' so
     * default-state cells round-trip as empty prefixes.
     *
     * @return list<AnsiCell>
     */
    private static function tokenizeRow(string $line): array
    {
        $cells = [];
        $current = '';
        $offset = 0;
        $length = strlen($line);

        while ($offset < $length) {
            if ($line[$offset] === "\x1b") {
                $matched = [];
                if (preg_match(self::SGR_AT, $line, $matched, 0, $offset) === 1) {
                    $params = $matched[1];
                    $current = ($params === '' || $params === '0') ? '' : "\x1b[{$params}m";
                    $offset += strlen($matched[0]);
                    continue;
                }
                if (preg_match(self::OSC_AT, $line, $matched, 0, $offset) === 1) {
                    $offset += strlen($matched[0]); // complete OSC (BEL- or ST-terminated): dropped
                    continue;
                }
                if (preg_match(self::OTHER_CSI_AT, $line, $matched, 0, $offset) === 1) {
                    $offset += strlen($matched[0]); // unknown CSI: dropped, see class docblock
                    continue;
                }
                // Anything else ESC-led (orphan ESC, unterminated OSC tail) is
                // skipped one byte at a time: dropped, see class docblock.
                $offset++;
                continue;
            }

            $next = strpos($line, "\x1b", $offset);
            $run = $next === false
                ? substr($line, $offset)
                : substr($line, $offset, $next - $offset);
            $offset += strlen($run);

            foreach (mb_str_split($run) as $glyph) {
                if ($glyph === "\r") {
                    continue; // stray CR inside a body row: no cell position to honour
                }
                $cells[] = AnsiCell::new($glyph, $current);
            }
        }

        return $cells;
    }
}
