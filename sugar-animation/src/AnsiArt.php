<?php

declare(strict_types=1);

namespace SugarCraft\Animation;

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
 * SGR state is CUMULATIVE ECMA-48 state carried across the whole document —
 * every row and every frame — exactly like a real terminal: a `ESC[97m` run
 * that follows `ESC[91;40m` means white-on-BLACK, not white-on-default. Each
 * {@see AnsiCell} therefore stores the fully RESOLVED canonical style
 * (attributes + foreground + background) active at that cell, serialised in
 * the fixed order documented on {@see self::canonicalSgr()}. Consumers blit
 * cells standalone into cell grids (a Surface paints a whole-cell style in
 * one shot), so a partial delta run would silently drop the inherited
 * components — the historical replace-per-run bug this class now guards.
 *
 * Anything the corpus never emits (unknown CSI sequences, well-formed OSC
 * strings such as hyperlinks, stray `\r` inside a row body) is dropped
 * conservatively: art is replayed cell-wise, so a positioning sequence we
 * cannot honour must not leak into the model as a phantom glyph. Unknown CSI
 * sequences are dropped across the FULL ECMA-48 final-byte range (0x40-0x7E)
 * except SGR `m` — including finals like `[ \ ] ^ _ ` { | } ~ that older
 * narrow classes let through as glyph cells. SA-A1 (-031) closed the
 * intermediate-byte hole: the SGR and CSI scans carry ECMA-48 intermediates
 * (0x20-0x2F) interleaved with parameters, so a sequence like `ESC[1 ;5m`
 * is consumed as ONE sequence (its intermediates ignored, exactly like a
 * terminal) instead of leaking its body as glyph cells, and an `ESC[`-led
 * run that matches no grammar is still consumed through its first final
 * byte (or to end of row when none arrives). Residual known limit: an OSC
 * string carrying a bare ESC inside its payload (before any BEL/ST
 * terminator) is truncated at the orphan-ESC skip boundary, so its remaining
 * printable tail can re-enter as glyph cells. Dropped
 * bytes are documented per site below. Unknown or truncated SGR parameters
 * are ignored gracefully and never corrupt the cumulative state.
 *
 * Parsing is bounded (SA-A1, -014): bytes handed to {@see self::fromString()}
 * may not exceed {@see Decoder::MAX_INPUT_BYTES} (the file path already reads
 * no further), and the walk refuses mid-parse once a document would spend
 * {@see self::MAX_CELLS} cells — .ansi input expands into retained cell
 * objects, so the cap is what keeps an 8 MB bomb from becoming a GB grid.
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

    /**
     * Upper bound on cells a parsed document may hold, counted across every
     * row of every frame (SA-A1, -014). .ansi bytes EXPAND into retained
     * cell objects at dozens of bytes of residency per input byte (QA
     * measured 1.24 GB of grid from an 8 MB file), so the walk counts cells
     * as it emits them and refuses mid-parse past the cap instead of
     * materialising the bomb. 200,000 leaves ~4x headroom over the largest
     * real document (the 214-file candy-top logo corpus peaks at 48,906
     * cells) while the worst-case residency stays bounded at roughly
     * 120 MB — the .ansi sibling of {@see Decoder::MAX_CELLS}'s grid cap.
     */
    public const MAX_CELLS = 200_000;

    /**
     * Attribute name -> SGR code in canonical (ascending) serialisation
     * order; the single source for {@see self::canonicalSgr()}'s ladder.
     */
    private const ATTR_CODES = [
        'bold' => 1, 'dim' => 2, 'italic' => 3, 'underline' => 4,
        'blink' => 5, 'reverse' => 7, 'hidden' => 8, 'strikethrough' => 9,
    ];

    /** Lookahead split point between frames (the cursor-up+CR full-redraw convention). */
    private const FRAME_SPLIT = '/(?=\x1b\[\d+A\r)/';

    /**
     * An SGR sequence: ESC [ <parameter bytes> m. SA-A1 (-031): the scan
     * class is widened to 0x20-0x3F so ECMA-48 intermediate bytes (0x20-0x2F)
     * interleaved with parameters (`ESC[1 ;5m`) still reach the SGR path as
     * a whole sequence; {@see self::applySgr()} strips them before parsing
     * codes, so a terminal-ignorable intermediate never reaches the code
     * table and — crucially — the sequence body never leaks as glyph cells.
     */
    private const SGR_AT = '/\G\x1b\[([\x20-\x3f]*)m/';

    /**
     * Any other CSI sequence (interleaved parameter/intermediate bytes, then
     * a final byte in the ECMA-48 range 0x40-0x7E except SGR `m`, which is
     * matched above) — dropped.
     */
    private const OTHER_CSI_AT = '/\G\x1b\[[\x20-\x3f]*[\x40-\x6c\x6e-\x7e]/';

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
     * @throws RuntimeException when the input crosses a documented cap: the
     *         resident-bytes ceiling ({@see Decoder::MAX_INPUT_BYTES}, the
     *         same gate the file entry applies) or the cell budget
     *         ({@see self::MAX_CELLS}, refused mid-walk)
     */
    public static function fromString(string $bytes): self
    {
        if ($bytes === '') {
            throw new InvalidArgumentException('AnsiArt: empty byte string, nothing to parse');
        }

        // SA-A1 (-014): the caps the class README promises for bytes handed
        // in directly — they are already resident, but the walk ceiling is
        // enforced before the first split so the line can never be steered
        // around by skipping fromFile().
        if (strlen($bytes) > Decoder::MAX_INPUT_BYTES) {
            throw new RuntimeException(Lang::t('ansiart.too_large', ['max' => Decoder::humanBytes(Decoder::MAX_INPUT_BYTES)]));
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
        // SA-A1 (-014): cells emitted by the whole walk, bounded by MAX_CELLS.
        $cellsSeen = 0;
        // One SGR cursor for the whole document: a terminal that receives
        // `\r` + cursor-up to start a redraw frame does NOT reset its colors.
        $sgrCursor = self::defaultSgrState();
        foreach ($chunks as $chunk) {
            if ($chunk === '') {
                continue; // empty chunk between adjacent headers: not a frame
            }
            $header = [];
            if (preg_match(self::FRAME_HEADER, $chunk, $header) === 1) {
                $chunk = substr($chunk, strlen($header[0]));
            }
            $frame = self::parseFrame($chunk, $width, $sgrCursor, $cellsSeen);
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

    /** SA-A1 (-014): the one voice both parse caps refuse with. */
    private static function cellBudgetExceeded(): RuntimeException
    {
        return new RuntimeException(Lang::t('ansiart.too_large', ['max' => (string) self::MAX_CELLS]));
    }

    /**
     * @throws RuntimeException when the path is missing, unreadable, or a directory
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(Lang::t('ansiart.cannot_read', ['path' => $path]));
        }
        // M-09 / SA-D1: the size gate IS the bounded read — the same shape
        // that cured Decoder::readFile()'s stat-then-slurp ladder (engine
        // twin of gifview-16): at most Decoder::MAX_INPUT_BYTES + 1 bytes are
        // pulled and the length actually seen decides, so a growing file
        // cannot slip the gap and a stat that cannot express the size can no
        // longer fail OPEN. ArtLibrary loads art through this method, which
        // makes this the one choke that bounds the whole file path.
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException(Lang::t('ansiart.read_failed', ['path' => $path]));
        }
        $bytes = stream_get_contents($handle, Decoder::MAX_INPUT_BYTES + 1);
        fclose($handle);
        if ($bytes === false) {
            throw new RuntimeException(Lang::t('ansiart.read_failed', ['path' => $path]));
        }
        if (strlen($bytes) > Decoder::MAX_INPUT_BYTES) {
            throw new RuntimeException(Lang::t('decoder.input_too_large', ['max' => Decoder::humanBytes(Decoder::MAX_INPUT_BYTES)]));
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
     * verbatim instead of walking cells. Each cell carries a canonical
     * WHOLE-state run, so a state change re-emits `ESC[0m` first (clearing
     * components the next state omits) followed by the new canonical run;
     * a change from default needs no reset, and each row plus the blob end
     * in default state. The result is terminal-correct as replay AND
     * cell-exactly re-parseable by {@see fromString()} — the accumulator
     * reproduces every canonical whole-state run byte-for-byte, so the
     * per-cell model and the raw stream stay in lockstep.
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
                    if ($previous !== '') {
                        $line .= "\x1b[0m"; // clear the carried whole-state first
                    }
                    $line .= $cell->sgr; // '' after a reset == default state
                    $previous = $cell->sgr;
                }
                $line .= $cell->glyph;
            }
            if ($previous !== '' && $index !== $last) {
                $line .= "\x1b[0m"; // rows re-open from default when replayed standalone
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
     * @param array $sgrCursor in/out: document-wide cumulative SGR state
     * @param int<0,max> $cellsSeen in/out: cells emitted by the walk so far,
     *        bounded by {@see self::MAX_CELLS} (SA-A1, -014)
     * @return list<list<AnsiCell>>
     */
    private static function parseFrame(string $body, int &$width, array &$sgrCursor, int &$cellsSeen): array
    {
        $lines = explode("\n", $body);
        // A trailing newline terminates the last row; it is not itself a row.
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $frame = [];
        foreach ($lines as $line) {
            $cells = self::tokenizeRow($line, $sgrCursor, $cellsSeen);
            $count = count($cells);
            if ($count > $width) {
                $width = $count;
            }
            $frame[] = $cells;
        }

        return $frame;
    }

    /**
     * Walk one row against the cumulative SGR cursor, emitting one cell per
     * codepoint. Each cell carries the canonical resolved style at that
     * point (see {@see self::canonicalSgr()}), so a consumer painting cells
     * standalone gets exactly what a terminal would show. `ESC[0m` and the
     * bare `ESC[m` reset the cursor to default, whose canonical form is ''.
     *
     * @param array $sgrCursor in/out: cumulative ECMA-48 SGR state
     * @param int<0,max> $cellsSeen in/out: cells emitted by the walk so far
     *        (SA-A1, -014: refused mid-row once the budget would be spent)
     * @return list<AnsiCell>
     */
    private static function tokenizeRow(string $line, array &$sgrCursor, int &$cellsSeen): array
    {
        $cells = [];
        $offset = 0;
        $length = strlen($line);

        // SA-A1 (-014): the budget can already be exhausted by earlier rows
        // of this or a prior frame; bail before touching this row so the
        // mid-walk refusal never pays for another split.
        if ($cellsSeen >= self::MAX_CELLS) {
            throw self::cellBudgetExceeded();
        }

        while ($offset < $length) {
            if ($line[$offset] === "\x1b") {
                $matched = [];
                if (preg_match(self::SGR_AT, $line, $matched, 0, $offset) === 1) {
                    $sgrCursor = self::applySgr($sgrCursor, $matched[1]);
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
                // SA-A1 (-031): a CSI we could not classify at all — an
                // intermediate-then-nonfinal body the widened grammars still
                // reject (e.g. a stray DEL mid-sequence). Consume the whole
                // sequence through its first ECMA-48 final byte (0x40-0x7E),
                // or to the end of the row when it never terminates, so a
                // malformed `ESC[` body can never leak as glyph cells. Only
                // `ESC[`-led runs take this path — a bare orphan ESC keeps its
                // one-byte skip (the corpus `\x1bA` still yields cell `A`).
                if ($offset + 1 < $length && $line[$offset + 1] === '[') {
                    $final = [];
                    if (preg_match('/[\x40-\x7e]/', $line, $final, PREG_OFFSET_CAPTURE, $offset + 2) === 1) {
                        $offset = $final[0][1] + 1; // consumed through the final byte
                    } else {
                        $offset = $length;         // unterminated CSI: the rest of the row is sequence body
                    }
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

            $resolved = self::canonicalSgr($sgrCursor);
            foreach (mb_str_split($run) as $glyph) {
                if ($glyph === "\r") {
                    continue; // stray CR inside a body row: no cell position to honour
                }
                // SA-A1 (-014): count the cell as it is emitted — the walk
                // refuses the moment the document would spend its budget,
                // so a small expanding input never materialises a GB grid.
                $cellsSeen++;
                if ($cellsSeen > self::MAX_CELLS) {
                    throw self::cellBudgetExceeded();
                }
                $cells[] = AnsiCell::new($glyph, $resolved);
            }
        }

        return $cells;
    }

    /**
     * The ECMA-48 SGR state a cell inherits: attribute flags plus optional
     * foreground/background colours. A colour is either a palette index
     * (0-15, the 16-colour + bright-16 range) or an RGB triple; null means
     * "terminal default".
     *
     * @return array{attrs: array<string, bool>, fg: ?array, bg: ?array}
     */
    private static function defaultSgrState(): array
    {
        return [
            'attrs' => [
                'bold' => false, 'dim' => false, 'italic' => false, 'underline' => false,
                'blink' => false, 'reverse' => false, 'hidden' => false, 'strikethrough' => false,
            ],
            'fg' => null,
            'bg' => null,
        ];
    }

    /**
     * Apply one `ESC[<params>m` run to the cumulative state (ECMA-48 SGR
     * subset: the codes the corpus uses plus the standard on/off and colour
     * family). Semicolon and colon sub-parameter forms of 38/48 are both
     * honoured. Unknown, out-of-range, or truncated parameters are ignored
     * gracefully — the state is never corrupted, matching terminal behaviour.
     *
     * @param array $state current cumulative state
     * @return array state after applying every parameter in the run
     */
    private static function applySgr(array $state, string $params): array
    {
        // SA-A1 (-031): the SGR scan class carries ECMA-48 intermediates
        // (0x20-0x2F) interleaved with parameters; terminals ignore them in
        // an SGR run, so drop them before parsing codes — a stray intermediate
        // must never be mistaken for (or glued onto) a numeric parameter.
        if ($params !== '' && $params !== '0') {
            $codes = '';
            for ($i = 0, $len = strlen($params); $i < $len; $i++) {
                if (ord($params[$i]) >= 0x30) {
                    $codes .= $params[$i];
                }
            }
            $params = $codes;
        }

        // '' and '0' (and any run starting with an omitted param) reset first.
        if ($params === '' || $params === '0') {
            return self::defaultSgrState();
        }

        // Each ';'-part may carry ':' sub-parameters (ITU T.416 form).
        $parts = array_map(
            static fn (string $part): array => array_map('intval', explode(':', $part)),
            explode(';', $params)
        );

        for ($i = 0, $n = count($parts); $i < $n; $i++) {
            $sub = $parts[$i];
            $code = $sub[0] ?? 0;
            $coloured = count($sub) > 1; // colon form: whole colour spec lives in $sub

            if ($coloured && ($code === 38 || $code === 48 || $code === 58)) {
                $state = self::applyExtendedColor($state, $code, $sub, colon: true);
                continue;
            }
            if (!$coloured && ($code === 38 || $code === 48 || $code === 58)) {
                // Semicolon form: the colour spec rides the FOLLOWING parts.
                $spec = [];
                $kind = $parts[$i + 1][0] ?? null;
                if ($kind === 5 && isset($parts[$i + 2])) {
                    $spec = [5, $parts[$i + 2][0]];
                } elseif ($kind === 2 && isset($parts[$i + 4])) {
                    $spec = [2, $parts[$i + 2][0], $parts[$i + 3][0], $parts[$i + 4][0]];
                }
                if ($spec === []) {
                    // Absent or malformed spec (e.g. a doc ending '38;5'):
                    // terminals bind EVERY remaining parameter to the
                    // 38/48/58 request and apply nothing — re-reading the
                    // leftovers as standalone codes would turn a missing
                    // colour index into a stray blink.
                    break;
                }
                $i += count($spec); // skip consumed spec parts
                $state = self::applyExtendedColor($state, $code, array_merge([$code], $spec));
                continue;
            }
            if ($coloured) {
                continue; // colon form on a plain code (e.g. '1:2'): not meaningful, ignore
            }

            $state = self::applySgrCode($state, $code);
        }

        return $state;
    }

    /**
     * A single SGR code with no colour payload: resets (0), attribute on
     * (1-9), attribute off (21-29), palette colours (30-37/90-97,
     * 40-47/100-107) and defaults (39/49). Everything else (fonts,
     * ideograms, 59 underline-default, 6 rapid-blink folded into blink
     * excluded, ...) is ignored gracefully.
     *
     * @param array $state
     * @return array
     */
    private static function applySgrCode(array $state, int $code): array
    {
        switch (true) {
            case $code === 0:
                return self::defaultSgrState();
            case $code === 1: $state['attrs']['bold'] = true; return $state;
            case $code === 2: $state['attrs']['dim'] = true; return $state;
            case $code === 3: $state['attrs']['italic'] = true; return $state;
            case $code === 4: $state['attrs']['underline'] = true; return $state;
            case $code === 5: $state['attrs']['blink'] = true; return $state;
            case $code === 7: $state['attrs']['reverse'] = true; return $state;
            case $code === 8: $state['attrs']['hidden'] = true; return $state;
            case $code === 9: $state['attrs']['strikethrough'] = true; return $state;
            // 21 is widely implemented as "underline off" (xterm family);
            // ECMA-48 double-underline has no canonical form here.
            case $code === 21: $state['attrs']['underline'] = false; return $state;
            case $code === 22: $state['attrs']['bold'] = false; $state['attrs']['dim'] = false; return $state;
            case $code === 23: $state['attrs']['italic'] = false; return $state;
            case $code === 24: $state['attrs']['underline'] = false; return $state;
            case $code === 25: $state['attrs']['blink'] = false; return $state;
            case $code === 27: $state['attrs']['reverse'] = false; return $state;
            case $code === 28: $state['attrs']['hidden'] = false; return $state;
            case $code === 29: $state['attrs']['strikethrough'] = false; return $state;
            case $code === 39: $state['fg'] = null; return $state;
            case $code === 49: $state['bg'] = null; return $state;
            case $code >= 30 && $code <= 37:
                $state['fg'] = ['palette' => $code - 30];
                return $state;
            case $code >= 90 && $code <= 97: // bright 16 (aixterm family), indices 8-15
                $state['fg'] = ['palette' => $code - 90 + 8];
                return $state;
            case $code >= 40 && $code <= 47:
                $state['bg'] = ['palette' => $code - 40];
                return $state;
            case $code >= 100 && $code <= 107:
                $state['bg'] = ['palette' => $code - 100 + 8];
                return $state;
            default:
                return $state; // unknown code: ignored gracefully, state unchanged
        }
    }

    /**
     * 38/48 (and 58, consumed-then-discarded so its payload can never be
     * misread as plain codes) in the `;5;n`/`;2;r;g;b` semicolon form or the
     * `:5:n`/`:2:Cs:r:g:b` colon form. The colon direct-colour syntax per
     * ITU T.416 carries a colourspace identifier between the kind and the
     * triple (usually empty: `38:2::r:g:b`); the semicolon form does not,
     * so the two spellings read the triple at different offsets. A colon
     * triple without the Cs slot is malformed (the byte is not optional in
     * that syntax) and a truncated spec in either form is ignored.
     *
     * @param array $state
     * @param list<int> $spec code followed by its payload: [38,5,n] or
     *                        [38,2,r,g,b] semicolon, [38,2,Cs,r,g,b] colon
     * @param bool $colon which spelling $spec arrived in
     * @return array
     */
    private static function applyExtendedColor(array $state, int $code, array $spec, bool $colon = false): array
    {
        $target = $code === 38 ? 'fg' : ($code === 48 ? 'bg' : null); // 58: underline colour — consumed, not modelled
        $kind = $spec[1] ?? null;

        if ($kind === 5 && isset($spec[2])) {
            $index = $spec[2];
            if ($index < 0 || $index > 255) {
                return $state;
            }
            if ($target !== null && $index < 16) {
                $state[$target] = ['palette' => $index]; // fold to canonical 3X/9X/4X/10X form
            } elseif ($target !== null) {
                $state[$target] = ['indexed' => $index];
            }
            return $state;
        }

        if ($kind === 2) {
            // Colon payload layout: Cs at index 2, triple at 3..5; the Cs
            // byte is NOT red — reading it as the first component shifts
            // every colon colour one slot (real emulators show rgb(12,34,56)
            // for '38:2::12:34:56', not rgb(0,12,34)).
            $first = $colon ? 3 : 2;
            if (isset($spec[$first], $spec[$first + 1], $spec[$first + 2])) {
                if ($target !== null) {
                    $state[$target] = ['rgb' => [
                        (int) max(0, min(255, $spec[$first])),
                        (int) max(0, min(255, $spec[$first + 1])),
                        (int) max(0, min(255, $spec[$first + 2])),
                    ]];
                }
                return $state;
            }
        }

        return $state; // malformed extended-colour spec: ignored
    }

    /**
     * Canonical serialisation of a resolved SGR state: one `ESC[…m` run with
     * active attributes in ascending code order (1,2,3,4,5,7,8,9), then the
     * foreground, then the background. Palette 0-7 use 3X/4X, bright 8-15
     * use 9X/10X, anything deeper uses `38;5;n` / `48;5;n`; RGB uses
     * `38;2;r;g;b` / `48;2;r;g;b`. The fully-default state serialises to ''
     * so default cells keep the empty-prefix round-trip property.
     *
     * @param array $state
     */
    private static function canonicalSgr(array $state): string
    {
        $segments = [];
        // SA-A1 (PERF-ANIM-11 fold): the attribute map is constant — hoisted
        // out of this per-run hot path so canonicalisation allocates nothing
        // per call for the ladder itself.
        foreach (self::ATTR_CODES as $attr => $code) {
            if ($state['attrs'][$attr]) {
                $segments[] = (string) $code;
            }
        }
        if (($col = $state['fg']) !== null) {
            $segments[] = self::canonicalColor($col, 30, 90, 38);
        }
        if (($col = $state['bg']) !== null) {
            $segments[] = self::canonicalColor($col, 40, 100, 48);
        }

        return $segments === [] ? '' : "\x1b[" . implode(';', $segments) . 'm';
    }

    /**
     * @param array $color ['palette'=>i] | ['indexed'=>i] | ['rgb'=>[r,g,b]]
     * @param int $base   normal palette base code (30 / 40)
     * @param int $bright bright palette base code (90 / 100)
     * @param int $ext    extended-colour selector code (38 / 48)
     */
    private static function canonicalColor(array $color, int $base, int $bright, int $ext): string
    {
        if (isset($color['rgb'])) {
            return $ext . ';2;' . implode(';', $color['rgb']);
        }
        $index = $color['indexed'] ?? $color['palette'];
        if ($index <= 7) {
            return (string) ($base + $index);
        }
        if ($index <= 15) {
            return (string) ($bright + $index - 8);
        }

        return $ext . ';5;' . $index;
    }
}
