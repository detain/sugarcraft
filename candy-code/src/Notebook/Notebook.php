<?php

declare(strict_types=1);

namespace SugarCraft\Code\Notebook;

/**
 * A Jupyter notebook — the nbformat v4 document, parsed, edited and written
 * back (roadmap C-TOOL-6.b, GAP-TL-15).
 *
 * This is the model behind the `NotebookEdit` tool and `Read`'s cell pages.
 * It owns the two properties the whole feature rests on:
 *
 *  - **Round-trip byte identity.** {@see parse()} decodes with
 *    `json_decode(..., false)` — objects as `stdClass`, arrays as arrays —
 *    so an empty metadata object stays `{}` rather than collapsing to `[]`,
 *    a float `1.0` keeps its fraction, and every key keeps the order the
 *    file wrote it in (`stdClass` property order IS insertion order, and
 *    the decoder inserts in document order). {@see serialize()} re-encodes
 *    with the indent detected from the original bytes (nbformat's writer
 *    emits one space) plus the four flags that close the remaining gaps to
 *    Python's `json.dumps(..., indent=1, sort_keys=True,
 *    ensure_ascii=False)`: unescaped slashes, unicode, line terminators
 *    (U+2028/U+2029 ride raw, exactly as nbformat writes them) and the
 *    zero-fraction. The one residual the flags cannot close — PHP escapes
 *    control characters as `\u00XX` with UPPERCASE hex where Python writes
 *    lowercase — is normalised by {@see lowerEscapeHex()}, walking doubled
 *    backslashes first so a literal `\\uABCD` in a string is never
 *    touched. A cell this model does not modify is therefore written back
 *    byte-for-byte as it was read: metadata, outputs, attachments and all.
 *    (nbformat is not installed on the build host, and its writer's shape
 *    is pinned here from `nbformat/v4/nbjson.py`'s documented
 *    `indent=1, sort_keys=True` output plus the lane's byte-identity
 *    tests over real-shaped fixtures.)
 *
 *  - **Cells addressed by id.** nbformat 4.5 introduced a stable per-cell
 *    `id`, and this model addresses cells by it. An older notebook (or a
 *    hand-written one) without ids is addressed positionally as
 *    `cell-<n>`, n counting from 0 — the same key shape a caller would
 *    paste from a `cell-0`-style listing. The addressing mode is derived
 *    per cell, not per document: an id-bearing document with one cell
 *    missing its id still addresses that one cell by index.
 *
 * Every edit ({@see replace()}, {@see insert()}, {@see delete()}) returns
 * a NEW instance, which is what lets the tool refuse anything ambiguous
 * with the original file provably untouched — the bytes only leave this
 * class through {@see serialize()}, and the caller decides when to write.
 *
 * ## Edit semantics (the lane decision this docblock records; GAP-TL-15)
 *
 *  - **replace** keeps the cell's `id` and `metadata` exactly as they are,
 *    swaps `source`, and — for a code cell — clears `outputs` to `[]` and
 *    sets `execution_count` to null, because new source with stale outputs
 *    attached would show the model results the code no longer produces.
 *    When `cell_type` is given and differs, the cell flips type and gains
 *    or loses `outputs`/`execution_count` to match the new type — a
 *    markdown cell carrying outputs is not valid nbformat. A flipped cell
 *    is rebuilt in nbformat's canonical sorted key order (that is what
 *    Jupyter itself writes for a cell it just produced); a same-type
 *    replace mutates in place and keeps the original key order.
 *  - **insert** puts the new cell after `cell_id`, or FIRST when no id is
 *    given (Claude Code's NotebookEdit behaves the same way), requires
 *    `cell_type`, and on nbformat ≥ 4.5 mints an 8-hex-digit id
 *    (`bin2hex(random_bytes(4))`) — the id is this model's addressing
 *    handle, so an unaddressable fresh cell would be a trap on the very
 *    next call. Below 4.5 no id is written, because nbformat < 4.5 files
 *    must not carry the key.
 *  - **delete** removes the cell and nothing else.
 *  - `source` is normalised to nbformat's line-list form (`["a\n", "b"]`),
 *    terminators preserved inside each element; empty source becomes the
 *    empty list. A source read in as a single string (legal in the wild)
 *    round-trips untouched until the cell is edited.
 *
 * Non-v4 documents — nbformat 3, a stray JSON file, anything whose `cells`
 * is not a list — are REFUSED at {@see parse()} by returning null; this
 * class never coerces a notebook it does not fully understand.
 */
final class Notebook
{
    /** Flags that make PHP's encoder speak nbformat's dialect (see class doc-block). */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_LINE_TERMINATORS
        | JSON_PRESERVE_ZERO_FRACTION;

    /** Positional key prefix for cells the document gives no id to. */
    public const INDEX_KEY_PREFIX = 'cell-';

    /** nbformat_minor from which cells carry an `id` and inserts must mint one. */
    private const ID_MINOR = 5;

    /**
     * @param \stdClass        $doc    the decoded document, key order intact
     * @param int              $indent column width detected from the source bytes
     * @param int              $minor  nbformat_minor (0 when the file omitted it)
     * @param list<string>     $keys   the address key of every cell, in order
     * @param array<string,int> $slots key => cell index, first occurrence wins
     */
    private function __construct(
        private readonly \stdClass $doc,
        private readonly int $indent,
        private readonly int $minor,
        private readonly array $keys,
        private readonly array $slots,
    ) {}

    /**
     * Parse notebook bytes, or null when they are not an nbformat v4
     * document. Null means REFUSE, not reshape: a v3 file, invalid JSON, a
     * JSON value that is not an object, or a v4 object without a `cells`
     * list all answer null and the caller writes nothing.
     */
    public static function parse(string $bytes): ?self
    {
        $doc = json_decode($bytes);
        if (!$doc instanceof \stdClass || !property_exists($doc, 'nbformat')
            || !is_int($doc->nbformat) || $doc->nbformat !== 4
            || !property_exists($doc, 'cells') || !is_array($doc->cells)
            || array_keys($doc->cells) !== range(0, count($doc->cells) - 1)) {
            return null;
        }

        foreach ($doc->cells as $cell) {
            // Every cell must be an object carrying a cell_type: one blob
            // we cannot address is one blob we must not rewrite.
            if (!$cell instanceof \stdClass || !property_exists($cell, 'cell_type')
                || !is_string($cell->cell_type)) {
                return null;
            }
        }

        $minor = property_exists($doc, 'nbformat_minor') && is_int($doc->nbformat_minor)
            ? $doc->nbformat_minor
            : 0;

        return new self($doc, self::detectIndent($bytes), $minor, ...self::address($doc->cells));
    }

    /** Number of cells in the document. */
    public function cellCount(): int
    {
        return count($this->keys);
    }

    /** The address keys of every cell, in document order. */
    public function cellKeys(): array
    {
        return $this->keys;
    }

    /** Whether $key names a cell of this document. */
    public function hasCell(string $key): bool
    {
        return isset($this->slots[$key]);
    }

    /**
     * A renderable view of every cell, in document order: key, cell_type,
     * id (null below 4.5 or where the cell carries none), source as text,
     * execution_count for code cells (null when never run) and the cell's
     * raw outputs list. Consumed by {@see NotebookPages}.
     *
     * @return list<array{key: string, type: string, id: ?string, source: string, execution_count: ?int, outputs: list<mixed>}>
     */
    public function describe(): array
    {
        $rows = [];
        foreach ($this->keys as $i => $key) {
            $cell = $this->doc->cells[$i];
            $rows[] = [
                'key' => $key,
                'type' => (string) $cell->cell_type,
                'id' => property_exists($cell, 'id') && is_string($cell->id) ? $cell->id : null,
                'source' => self::sourceText($cell),
                'execution_count' => property_exists($cell, 'execution_count')
                    && is_int($cell->execution_count) ? $cell->execution_count : null,
                'outputs' => property_exists($cell, 'outputs') && is_array($cell->outputs)
                    ? array_values($cell->outputs) : [],
            ];
        }

        return $rows;
    }

    /**
     * Replace the source of the cell addressed by $key, optionally flipping
     * its type, returning the new document — or null when $key names no
     * cell. The kept cell loses its recorded outputs and run count (class
     * doc-block, "replace"); its id and metadata ride through untouched.
     */
    public function replace(string $key, string $newSource, ?string $newType = null): ?self
    {
        if (!isset($this->slots[$key])) {
            return null;
        }

        $cells = $this->doc->cells;
        $index = $this->slots[$key];
        $old = $cells[$index];
        $type = $newType ?? (string) $old->cell_type;

        if ($newType !== null && $newType !== (string) $old->cell_type) {
            // Type flip: rebuild in canonical key order so the added or
            // dropped outputs/execution_count land where nbformat would
            // write them, metadata (incl. attachments) and id carried.
            $cells[$index] = self::skeleton(
                $type,
                property_exists($old, 'id') && is_string($old->id) ? $old->id : null,
                self::splitLines($newSource),
                property_exists($old, 'metadata') && $old->metadata instanceof \stdClass
                    ? self::copyProperties($old->metadata)
                    : new \stdClass(),
            );
        } else {
            $updated = self::copyProperties($old);
            $updated->source = self::splitLines($newSource);
            if ($type === 'code') {
                // New source invalidates everything the old run produced.
                $updated->outputs = [];
                $updated->execution_count = null;
            }
            $cells[$index] = $updated;
        }

        return $this->withCells($cells);
    }

    /**
     * Insert a new cell of $type carrying $source, after the cell addressed
     * by $afterKey — or at the front when $afterKey is null — returning
     * `[new document, index of the new cell]`, or null when $afterKey names
     * no cell. On nbformat ≥ 4.5 the new cell mints an 8-hex id; the
     * caller reports it so the next address-by-id call can name the cell
     * it just made. Below 4.5 no id key is written at all.
     *
     * @return array{0: self, 1: int}|null
     */
    public function insert(?string $afterKey, string $source, string $type): ?array
    {
        $at = $afterKey === null ? 0 : (($this->slots[$afterKey] ?? null) === null ? null : $this->slots[$afterKey] + 1);
        if ($at === null) {
            return null;
        }

        $cell = self::skeleton($type, $this->minor >= self::ID_MINOR ? bin2hex(random_bytes(4)) : null, self::splitLines($source));

        $cells = $this->doc->cells;
        array_splice($cells, $at, 0, [$cell]);

        return [$this->withCells($cells), $at];
    }

    /**
     * Delete the cell addressed by $key, returning the new document — or
     * null when $key names no cell. No other cell's bytes are touched.
     */
    public function delete(string $key): ?self
    {
        if (!isset($this->slots[$key])) {
            return null;
        }

        $cells = $this->doc->cells;
        array_splice($cells, $this->slots[$key], 1);

        return $this->withCells($cells);
    }

    /**
     * Write the document back in nbformat's dialect: the source file's
     * indent, original key order, Python-compatible escapes, trailing
     * newline (nbformat's writer ends its output with one).
     */
    public function serialize(): string
    {
        $json = json_encode($this->doc, self::JSON_FLAGS | JSON_PRETTY_PRINT);
        if ($json === false) {
            // metadata is free-form and this model only ever holds a
            // decoded-valid tree, so an encode failure here would be
            // malformed UTF-8 from bytes we read. Refuse loudly at the
            // caller's floor rather than write a truncated notebook: the
            // tool treats a false as its own parse refusal.
            throw new \InvalidArgumentException('notebook document is not encodable');
        }

        if ($this->indent !== 4) {
            $json = self::reIndent($json, $this->indent);
        }

        return self::lowerEscapeHex($json) . "\n";
    }

    /**
     * The column width the source bytes were written at: the run of spaces
     * opening the first line after `{`. Undetectable (minified or odd
     * files) falls back to nbformat's own one-space writer.
     */
    private static function detectIndent(string $bytes): int
    {
        if (preg_match('/\A\s*\{\n( +)"/', $bytes, $m) === 1) {
            return strlen($m[1]);
        }

        return 1;
    }

    /**
     * Rebase JSON_PRETTY_PRINT's 4-space levels to $indent columns. Only
     * structural lines start with spaces in the encoder's output — raw
     * newlines cannot appear inside a string once U+2028/U+2029 ride
     * unescaped on their own physical line — so line-leading depth
     * arithmetic is exact.
     */
    private static function reIndent(string $json, int $indent): string
    {
        $out = [];
        foreach (explode("\n", $json) as $line) {
            $leading = strlen($line) - strlen(ltrim($line, ' '));
            $out[] = str_repeat(' ', intdiv($leading, 4) * $indent) . substr($line, $leading);
        }

        return implode("\n", $out);
    }

    /**
     * Python writes `\\u00xx` escapes with lowercase hex; PHP uses uppercase.
     * Doubled backslashes are consumed first so a literal `\\uABCD` (two
     * backslash characters then a word) inside a string value is never
     * mistaken for an escape.
     */
    private static function lowerEscapeHex(string $json): string
    {
        return preg_replace_callback(
            '/\\\\\\\\|\\\\u([0-9A-Fa-f]{4})/',
            static function (array $m): string {
                return isset($m[1]) ? '\\u' . strtolower($m[1]) : $m[0];
            },
            $json,
        ) ?? $json;
    }

    /**
     * The cell keys and the key=>index map, recomputed for a cell list —
     * ids first, positional `cell-<n>` keys for cells without one. A
     * duplicated id addresses its FIRST occurrence (real writers refuse
     * duplicates; addressing keeps the same verdict).
     *
     * @param array<int, \stdClass> $cells
     *
     * @return array{list<string>, array<string,int>}
     */
    private static function address(array $cells): array
    {
        $keys = [];
        $slots = [];
        foreach ($cells as $i => $cell) {
            $key = property_exists($cell, 'id') && is_string($cell->id) && $cell->id !== ''
                ? $cell->id
                : self::INDEX_KEY_PREFIX . $i;
            $keys[$i] = $key;
            if (!isset($slots[$key])) {
                $slots[$key] = $i;
            }
        }

        return [$keys, $slots];
    }

    /**
     * A fresh instance over a copy of this document frame with a replaced
     * cell list: every edit lands here, so addressing and the detected
     * indent always describe the bytes this instance will write — and the
     * instance the caller started from is untouched.
     *
     * @param array<int, \stdClass> $cells
     */
    private function withCells(array $cells): self
    {
        $fresh = self::copyProperties($this->doc);
        $fresh->cells = $cells;

        return new self($fresh, $this->indent, $this->minor, ...self::address($cells));
    }

    /**
     * nbformat's canonical key order for a brand-new or type-flipped cell
     * — `cell_type, execution_count, id, metadata, outputs, source` of
     * which a cell takes only the keys its type carries (that is exactly
     * what nbformat's `sort_keys=True` writer emits for such a cell).
     *
     * @param list<string> $lines
     */
    private static function skeleton(string $type, ?string $id, array $lines, ?\stdClass $metadata = null): \stdClass
    {
        $cell = new \stdClass();
        $cell->cell_type = $type;
        if ($type === 'code') {
            $cell->execution_count = null;
        }

        if ($id !== null) {
            $cell->id = $id;
        }

        $cell->metadata = $metadata ?? new \stdClass();
        if ($type === 'code') {
            $cell->outputs = [];
        }

        $cell->source = $lines;

        return $cell;
    }

    /** Copy a stdClass property-for-property, order intact. */
    private static function copyProperties(\stdClass $source): \stdClass
    {
        $copy = new \stdClass();
        foreach (get_object_vars($source) as $key => $value) {
            $copy->{$key} = $value;
        }

        return $copy;
    }

    /**
     * The cell's source as text. nbformat allows a bare string or a list of
     * lines; both flatten to the same text.
     */
    private static function sourceText(\stdClass $cell): string
    {
        $source = $cell->source ?? null;
        if (is_string($source)) {
            return $source;
        }
        if (is_array($source)) {
            return implode('', array_filter($source, 'is_string'));
        }

        return '';
    }

    /**
     * Split new source text into nbformat's line list, terminators kept
     * inside each element; empty text becomes the empty list.
     *
     * @return list<string>
     */
    private static function splitLines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $lines = preg_split('/(?<=\n)/u', $text);
        if ($lines === false) {
            return [$text];
        }
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }
}
