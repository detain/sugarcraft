<?php

declare(strict_types=1);

namespace SugarCraft\Forms\TextArea;

use SugarCraft\Forms\Cursor\BlinkMsg;
use SugarCraft\Forms\Cursor\Cursor;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Editor;

/**
 * Multi-line text input. Holds a list of lines and a (row, col) cursor.
 * Enter splits the current line; Backspace at the start of a line merges
 * with the previous one. All edits are multibyte-safe (`mb_substr` /
 * `mb_strlen`), so wide characters (`日本`) navigate as single graphemes.
 *
 * Column / row navigation: ←→↑↓, Home/End (line), Ctrl+Home / Ctrl+End
 * (document), Ctrl+A / Ctrl+E (line), Ctrl+U (delete to start of line),
 * Ctrl+K (delete to end of line). Tab inserts four spaces.
 *
 * Embeds a {@see Cursor} for the visual caret. The parent Model decides
 * what to do with `Enter` when no insertion is desired (this component
 * always inserts a newline on Enter).
 *
 * Clipboard (E736 5.13, round 88): {@see withSelect()} anchors one corner
 * of a selection at the current cursor; the other corner tracks the caret.
 * `Ctrl+C` copies the selection to the terminal clipboard through the Cmd
 * channel (`Cmd::setClipboard` → OSC 52; never a direct write — TEA law),
 * `Ctrl+X` copies then deletes it, and a `PasteMsg` replaces the selection
 * and inserts at the caret. An empty selection makes copy/cut silent no-ops.
 * Selection spans render as reverse-video. The anchor survives plain typing
 * (deliberate: clearing it from every edit path would churn the keyboard
 * behaviour this component has shipped with); `clearSelection()` resets it.
 */
final class TextArea implements Model
{
    use Mutable;

    /**
     * E736-F2/2.4 (round 85): per-snapshot memo for totalLength()'s O(lines)
     * mb_strlen scan (charLimit guard + stats hit it every keystroke). The
     * widget is immutable — every mutate() rebuilds a fresh instance so the
     * memo is structurally snapshot-scoped, same convention as
     * Form::valuesMemo (E736 3.3).
     */
    private ?int $totalLengthMemo = null;
    /**
     * @param list<string>             $lines
     * @param ?\Closure(string): ?string $validate
     */
    private function __construct(
        public readonly array $lines,
        public readonly int $row,
        public readonly int $col,
        public readonly string $placeholder,
        public readonly int $charLimit,
        public readonly int $width,
        public readonly int $height,
        public readonly bool $focused,
        public readonly Cursor $cursor,
        public readonly int $rowOffset,
        public readonly bool $showLineNumbers = false,
        public readonly int $maxWidth = 0,
        public readonly int $maxHeight = 0,
        public readonly string $endOfBufferCharacter = '~',
        public readonly string $prompt = '',
        public readonly ?\Closure $validate = null,
        public readonly ?string $err = null,
        /** Optional dynamic-prompt closure: `fn(int $rowIndex, string $line): string`. Wins over $prompt when set. */
        public readonly ?\Closure $promptFunc = null,
        /**
         * Dynamic-height mode (mirrors upstream Bubbles #910). When on,
         * {@see view()} renders only as many rows as the content has,
         * capped by `$maxHeight` (0 = unlimited). When off (default),
         * `$height` is the fixed row count.
         */
        public readonly bool $dynamic = false,
        /**
         * Filename suffix used when {@see openInEditor()} writes the
         * seed temp file. Mirrors upstream Bubbles' editor example —
         * `.md` triggers vim's markdown ftplugin, `.json` opens with
         * JSON syntax highlighting, etc. Default `.txt`.
         */
        public readonly string $editorExtension = '.txt',
        /**
         * Selection anchor cell (E736 5.13, round 88). The ACTIVE end of the
         * selection is always the current cursor (`row`,`col`); the anchor is
         * the fixed end set via {@see withSelect()}. `null` anchor = no
         * selection. Both ints move together — the paired `anchorSet`
         * sentinel on {@see mutate()} keeps the nullable-field house law.
         */
        public readonly ?int $anchorRow = null,
        /** Column of the {@see $anchorRow} anchor; null exactly when anchorRow is. */
        public readonly ?int $anchorCol = null,
    ) {}

    /**
     * Construct a fresh instance with default state.
     *
     * `charLimit` defaults to 65536 to bound the buffer against a
     * paste-DoS (an unbounded multi-line buffer lets a single paste
     * balloon memory). Opt out with `withCharLimit(0)` for genuinely
     * unlimited input — enforcement is gated on `charLimit > 0`, so 0
     * restores the old unbounded behaviour.
     */
    public static function new(): self
    {
        return new self(
            lines: [''],
            row: 0,
            col: 0,
            placeholder: '',
            charLimit: 65536,
            width: 0,
            height: 0,
            focused: false,
            cursor: Cursor::new(),
            rowOffset: 0,
        );
    }

    /** Bubble-Tea Init — returns the bootstrap Cmd (cursor blink, first tick, etc.) or null. */
    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if ($msg instanceof BlinkMsg) {
            [$cursor, $cmd] = $this->cursor->update($msg);
            return [$this->mutate(cursor: $cursor), $cmd];
        }
        if ($msg instanceof TextAreaEditedMsg) {
            return [$this->setValue($msg->value), null];
        }
        if ($msg instanceof PasteMsg) {
            // E736 5.13: bracketed paste replaces the selection (when any) and
            // inserts at the caret — the same insertString seam the host app
            // routes its own paste through (sugar-crush E704 law: verbatim
            // payload, cursor lands at the end of the inserted text).
            if (!$this->focused) {
                return [$this, null];
            }
            return [$this->deleteSelection()->insertString($msg->content), null];
        }
        if (!$msg instanceof KeyMsg || !$this->focused) {
            return [$this, null];
        }

        if ($msg->ctrl) {
            return match ($msg->rune) {
                'a'     => [$this->moveCursor($this->row, 0), null],
                'e'     => [$this->moveCursor($this->row, $this->lineLen($this->row)), null],
                'u'     => [$this->deleteToLineStart(), null],
                'k'     => [$this->deleteToLineEnd(), null],
                'c'     => $this->copySelection(),
                'x'     => $this->cutSelection(),
                'o'     => [$this, $this->openInEditor()],
                default => [$this, null],
            };
        }

        return match ($msg->type) {
            KeyType::Up        => [$this->moveCursor($this->row - 1, $this->col), null],
            KeyType::Down      => [$this->moveCursor($this->row + 1, $this->col), null],
            KeyType::Left      => [$this->moveLeft(), null],
            KeyType::Right     => [$this->moveRight(), null],
            KeyType::Home      => [$this->moveCursor($this->row, 0), null],
            KeyType::End       => [$this->moveCursor($this->row, $this->lineLen($this->row)), null],
            KeyType::Backspace => [$this->backspace(), null],
            KeyType::Delete    => [$this->deleteForward(), null],
            KeyType::Enter     => [$this->insertNewline(), null],
            KeyType::Tab       => [$this->insert('    '), null],
            KeyType::Space     => [$this->insert(' '), null],
            KeyType::Char      => [$this->insert($msg->rune), null],
            default            => [$this, null],
        };
    }

    /** Render the component as a multi-line ANSI string. */
    public function view(): string
    {
        // Empty + unfocused with placeholder.
        if ($this->totalLength() === 0 && !$this->focused && $this->placeholder !== '') {
            return $this->prefixWithGutter([$this->placeholder], 0)[0];
        }

        // Resolve the effective row count for this render. In static
        // mode this is just `$this->height`; in dynamic mode it's
        // `min(maxHeight, max(1, line count))`.
        $effectiveHeight = $this->effectiveHeight();

        // Slice rows by height (height = 0 means show all).
        $start = max(0, $this->rowOffset);
        $rows  = $effectiveHeight > 0
            ? array_slice($this->lines, $start, $effectiveHeight)
            : $this->lines;

        // End-of-buffer filler — vim-style `~` rows when the buffer
        // doesn't fill the configured height. Skipped in dynamic mode
        // since the effective height matches the content.
        if ($effectiveHeight > 0 && !$this->dynamic) {
            $shown = count($rows);
            for ($i = $shown; $i < $effectiveHeight; $i++) {
                $rows[] = $this->endOfBufferCharacter;
            }
        }

        if (!$this->focused) {
            return implode("\n", $this->prefixWithGutter($rows, $start));
        }

        // Render with the embedded cursor at (row, col). Selection spans
        // (E736 5.13) paint reverse-video per row; rows with neither the
        // caret nor a span keep the exact pre-clipboard render path.
        $relRow = $this->row - $start;
        $out = [];
        foreach ($rows as $i => $line) {
            $span = $this->selectionSpanOn($start + $i);
            if ($i !== $relRow) {
                $out[] = $span === null ? $line : $this->paintSpan($line, $span);
                continue;
            }
            $out[] = $span === null
                ? $this->renderCursorLine($line)
                : $this->renderCursorAndSpan($line, $span);
        }
        return implode("\n", $this->prefixWithGutter($out, $start));
    }

    /**
     * Apply line-number gutter + prompt to each visible line. `$startRow`
     * is the offset of the first row in `$lines` within the full buffer
     * (0-based) so line numbers stay correct under scroll.
     *
     * @param  list<string> $lines
     * @return list<string>
     */
    private function prefixWithGutter(array $lines, int $startRow): array
    {
        if (!$this->showLineNumbers && $this->prompt === '' && $this->promptFunc === null) {
            return $lines;
        }
        $totalLines = count($this->lines);
        $gutterWidth = $this->showLineNumbers ? max(2, strlen((string) $totalLines) + 1) : 0;
        $out = [];
        foreach ($lines as $i => $line) {
            $row = $startRow + $i;
            $gutter = '';
            if ($this->showLineNumbers) {
                $isFiller = $line === $this->endOfBufferCharacter && $row >= $totalLines;
                $label = $isFiller ? '' : (string) ($row + 1);
                $gutter = str_pad($label, $gutterWidth, ' ', STR_PAD_LEFT) . ' ';
            }
            $prompt = $this->promptFunc !== null
                ? ($this->promptFunc)($row, $line)
                : $this->prompt;
            $out[] = $gutter . $prompt . $line;
        }
        return $out;
    }

    // ---- focus + setters --------------------------------------------

    /**
     * @return array{0:self, 1:?\Closure}
     */
    public function focus(): array
    {
        [$cursor, $cmd] = $this->cursor->focus();
        return [$this->mutate(cursor: $cursor, focused: true), $cmd];
    }

    /** Release focus; companion to { focus()}. */
    public function blur(): self
    {
        return $this->mutate(cursor: $this->cursor->blur(), focused: false);
    }

    public function setValue(string $v): self
    {
        $lines = $v === '' ? [''] : explode("\n", $v);
        if ($this->charLimit > 0) {
            $remaining = $this->charLimit;
            $clamped   = [];
            foreach ($lines as $line) {
                $len = mb_strlen($line, 'UTF-8');
                if ($len <= $remaining) {
                    $clamped[]  = $line;
                    $remaining -= $len;
                    continue;
                }
                $clamped[] = mb_substr($line, 0, $remaining, 'UTF-8');
                $remaining = 0;
                break;
            }
            $lines = $clamped === [] ? [''] : $clamped;
        }
        $lastRow = count($lines) - 1;
        return $this->mutate(
            lines: $lines,
            row: $lastRow,
            col: mb_strlen($lines[$lastRow], 'UTF-8'),
            anchorRow: null,
            anchorCol: null,
            anchorSet: true,
        );
    }

    public function value(): string
    {
        return implode("\n", $this->lines);
    }

    public function reset(): self
    {
        return $this->mutate(
            lines: [''], row: 0, col: 0, rowOffset: 0,
            anchorRow: null, anchorCol: null, anchorSet: true,
        );
    }

    public function withPlaceholder(string $p): self { return $this->mutate(placeholder: $p); }
    public function withCharLimit(int $n): self      { return $this->mutate(charLimit: max(0, $n)); }
    public function withWidth(int $w): self          { return $this->mutate(width: max(0, $w)); }
    public function withHeight(int $h): self         { return $this->mutate(height: max(0, $h)); }
    public function withMaxWidth(int $w): self       { return $this->mutate(maxWidth: max(0, $w)); }
    public function withMaxHeight(int $h): self      { return $this->mutate(maxHeight: max(0, $h)); }

    /**
     * Toggle dynamic-height mode. When on, {@see view()} renders only
     * as many rows as the content has, capped at {@see $maxHeight} (0
     * means uncapped). When off (default), `$height` is the fixed row
     * count and short content gets padded with end-of-buffer glyphs.
     *
     * Mirrors upstream Bubbles `WithDynamicHeight` (#910).
     */
    public function withDynamic(bool $on = true): self
    {
        return $this->mutate(dynamic: $on);
    }

    /** Short alias for {@see withDynamic()}. */
    public function dynamic(bool $on = true): self { return $this->withDynamic($on); }

    /**
     * Filename suffix used when Ctrl+O opens the buffer in the user's
     * external `$EDITOR`. Pass with the leading dot (`'.md'`) or
     * without (`'md'`); both are accepted. Empty string skips the
     * suffix entirely. Mirrors the editor-example pattern in
     * upstream `bubbles/textarea`.
     */
    public function withEditorExtension(string $ext): self
    {
        return $this->mutate(editorExtension: $ext);
    }

    /** Short alias for {@see withEditorExtension()}. */
    public function editorExtension(string $ext): self
    {
        return $this->withEditorExtension($ext);
    }

    /**
     * Number of rows {@see view()} will render given the current
     * mode. In static mode (`dynamic=false`) returns `$height`; in
     * dynamic mode returns `min(maxHeight ?: ∞, max(1, line count))`
     * — at least one row, at most maxHeight if set, otherwise content
     * count.
     */
    public function effectiveHeight(): int
    {
        if (!$this->dynamic) {
            return $this->height;
        }
        $contentRows = max(1, count($this->lines));
        if ($this->maxHeight > 0) {
            return min($this->maxHeight, $contentRows);
        }
        return $contentRows;
    }

    /** Show 1-based line numbers in a left gutter. Default off. */
    public function showLineNumbers(bool $on = true): self
    {
        return $this->mutate(showLineNumbers: $on);
    }

    /**
     * Character drawn for empty rows beyond the last line of content
     * (the "end of buffer" filler vim shows). Defaults to `~`.
     */
    public function withEndOfBufferCharacter(string $c): self
    {
        return $this->mutate(endOfBufferCharacter: $c);
    }

    /** Static prefix prepended to every line. Mirrors Bubbles' `Prompt`. */
    public function withPrompt(string $p): self
    {
        return $this->mutate(prompt: $p);
    }

    /**
     * Dynamic prompt: closure `fn(int $rowIndex, string $line): string`.
     * Called once per visible row to compute the line prefix. When set,
     * wins over the static {@see withPrompt()} prefix. Pass null to
     * clear and revert to the static prompt. Mirrors Bubbles'
     * `SetPromptFunc` (with the row-index argument also exposed so
     * callers can render `> ` on the active row, ` ` elsewhere).
     */
    public function setPromptFunc(?\Closure $fn): self
    {
        return $this->mutate(promptFunc: $fn, promptFuncSet: true);
    }

    /**
     * Set a validator. Receives the joined value, returns an error
     * message or null. Re-runs after every edit; result is exposed via
     * {@see err()}.
     *
     * @param ?\Closure(string): ?string $fn  pass null to clear
     */
    public function withValidator(?\Closure $fn): self
    {
        return $this->mutate(
            validate: $fn,
            validateSet: true,
            err: $fn !== null ? $fn($this->value()) : null,
            errSet: true,
        );
    }

    // Short-form aliases.
    public function placeholder(string $p): self  { return $this->withPlaceholder($p); }
    public function charLimit(int $n): self       { return $this->withCharLimit($n); }
    public function width(int $w): self           { return $this->withWidth($w); }
    public function height(int $h): self          { return $this->withHeight($h); }
    public function maxWidth(int $w): self        { return $this->withMaxWidth($w); }
    public function maxHeight(int $h): self       { return $this->withMaxHeight($h); }
    public function prompt(string $p): self       { return $this->withPrompt($p); }
    public function validator(?\Closure $fn): self { return $this->withValidator($fn); }

    public function err(): ?string { return $this->err; }

    /** Move the cursor to (`$row`, `$col`); both clamp to range. */
    public function setCursor(int $row, int $col): self
    {
        return $this->moveCursor($row, $col);
    }

    /** Clamp the cursor's column on the current row. */
    public function setCursorColumn(int $col): self
    {
        return $this->moveCursor($this->row, $col);
    }

    // ---- selection + clipboard (E736 5.13) ----------------------------

    /**
     * Anchor a selection at (`$row`, `$col`); both clamp into the live
     * buffer. The ACTIVE end follows the caret from now on, so a caller
     * implementing shift-selection calls this on press, then lets arrows
     * move the caret. Re-anchoring at the caret position clears the
     * selection (empty span).
     */
    public function withSelect(int $row, int $col): self
    {
        $row = max(0, min(count($this->lines) - 1, $row));
        return $this->mutate(
            anchorRow: $row,
            anchorCol: max(0, min($this->lineLen($row), $col)),
            anchorSet: true,
        );
    }

    /** Drop the selection anchor; the caret itself is untouched. Idempotent. */
    public function clearSelection(): self
    {
        if ($this->anchorRow === null) {
            return $this;
        }
        return $this->mutate(anchorRow: null, anchorCol: null, anchorSet: true);
    }

    /** True when anchor and caret delimit a non-empty span. */
    public function hasSelection(): bool
    {
        return $this->normalizedSelection() !== null;
    }

    /**
     * The selected text, lines joined with `"\n"`; `''` when there is no
     * selection. Anchor/caret order is normalised — a backwards drag
     * (anchor below the caret) selects the same span as a forwards one.
     */
    public function selectedText(): string
    {
        $span = $this->normalizedSelection();
        if ($span === null) {
            return '';
        }
        [$r0, $c0, $r1, $c1] = $span;
        if ($r0 === $r1) {
            return mb_substr($this->lines[$r0], $c0, $c1 - $c0, 'UTF-8');
        }
        $parts = [mb_substr($this->lines[$r0], $c0, null, 'UTF-8')];
        for ($r = $r0 + 1; $r < $r1; $r++) {
            $parts[] = $this->lines[$r];
        }
        $parts[] = mb_substr($this->lines[$r1], 0, $c1, 'UTF-8');
        return implode("\n", $parts);
    }

    /**
     * Move cursor up one row, preserving column where possible.
     * Mirrors Bubbles' `CursorUp`. Clamps at the first row.
     */
    public function cursorUp(): self
    {
        if ($this->row === 0) {
            return $this;
        }
        return $this->moveCursor($this->row - 1, $this->col);
    }

    /**
     * Move cursor down one row, preserving column where possible.
     * Mirrors Bubbles' `CursorDown`. Clamps at the last row.
     */
    public function cursorDown(): self
    {
        if ($this->row >= count($this->lines) - 1) {
            return $this;
        }
        return $this->moveCursor($this->row + 1, $this->col);
    }

    /**
     * Jump to (0, 0). Mirrors Bubbles' `MoveToBegin`.
     */
    public function moveToBegin(): self
    {
        return $this->moveCursor(0, 0);
    }

    /**
     * Jump to the last row, end of last line. Mirrors Bubbles' `MoveToEnd`.
     */
    public function moveToEnd(): self
    {
        $lastRow = count($this->lines) - 1;
        return $this->moveCursor($lastRow, $this->lineLen($lastRow));
    }

    /**
     * Move cursor up by the configured display height (one viewport).
     * Mirrors Bubbles' `PageUp`. Falls back to a single row when height
     * is unset (`height = 0`).
     */
    public function pageUp(): self
    {
        $delta = $this->height > 0 ? $this->height : 1;
        return $this->moveCursor(max(0, $this->row - $delta), $this->col);
    }

    /**
     * Move cursor down by the configured display height. Mirrors
     * Bubbles' `PageDown`.
     */
    public function pageDown(): self
    {
        $delta = $this->height > 0 ? $this->height : 1;
        $lastRow = count($this->lines) - 1;
        return $this->moveCursor(min($lastRow, $this->row + $delta), $this->col);
    }

    /**
     * Insert a single rune (one or more bytes — multibyte safe) at the
     * cursor. Mirrors Bubbles' `InsertRune`.
     */
    public function insertRune(string $rune): self
    {
        if ($rune === '' || str_contains($rune, "\n")) {
            return $this->insertString($rune);
        }
        return $this->insert($rune);
    }

    /**
     * Return the word containing the cursor — a maximal run of
     * non-whitespace characters that the cursor sits in or adjacent
     * to. Mirrors Bubbles' `Word`. Returns the empty string when the
     * cursor is on whitespace.
     */
    public function word(): string
    {
        $line = $this->lines[$this->row] ?? '';
        $len  = mb_strlen($line, 'UTF-8');
        if ($len === 0) {
            return '';
        }
        $col = max(0, min($len, $this->col));
        // If the cursor is at the very end, look at the previous char.
        $probeAt = $col >= $len ? $len - 1 : $col;
        $charAt  = mb_substr($line, $probeAt, 1, 'UTF-8');
        if ($charAt === '' || ctype_space($charAt)) {
            return '';
        }

        // Walk left to the start of the word.
        $start = $probeAt;
        while ($start > 0) {
            $c = mb_substr($line, $start - 1, 1, 'UTF-8');
            if (ctype_space($c)) break;
            $start--;
        }
        // Walk right to the end of the word.
        $end = $probeAt;
        while ($end < $len - 1) {
            $c = mb_substr($line, $end + 1, 1, 'UTF-8');
            if (ctype_space($c)) break;
            $end++;
        }
        return mb_substr($line, $start, $end - $start + 1, 'UTF-8');
    }

    /**
     * Insert a string at the cursor; embedded newlines split lines.
     * Mirrors Bubbles' `InsertString` / `InsertRune`.
     */
    public function insertString(string $text): self
    {
        $next = $this;
        $first = true;
        foreach (explode("\n", $text) as $segment) {
            if (!$first) {
                $next = $next->insertNewline();
            }
            if ($segment !== '') {
                $next = $next->insert($segment);
            }
            $first = false;
        }
        return $next;
    }

    /** Pixel/cell width of the current line up to the cursor. */
    public function lineInfo(): array
    {
        return [
            'row'        => $this->row,
            'col'        => $this->col,
            'lineWidth'  => $this->lineLen($this->row),
            'totalLines' => count($this->lines),
            'totalChars' => $this->totalLength(),
        ];
    }

    public function lineCount(): int { return count($this->lines); }

    // ---- public read-only accessors ---------------------------------

    /** Mirrors upstream Bubbles `Focused()` — true when the area accepts input. */
    public function focused(): bool { return $this->focused; }

    /** Read-only access to the embedded {@see Cursor}. */
    public function cursor(): Cursor { return $this->cursor; }

    /** Currently-active line (0-based). Mirrors upstream `Line()`. */
    public function line(): int { return $this->row; }

    /** Cursor column on {@see line()} (0-based, codepoint count). Mirrors `Column()`. */
    public function column(): int { return $this->col; }

    /** Configured visible width in cells (0 = unbounded). */
    public function getWidth(): int { return $this->width; }

    /** Configured visible height in rows (0 = unbounded). */
    public function getHeight(): int { return $this->height; }

    /** Vertical scroll offset (0-based row index of the topmost visible line). */
    public function getRowOffset(): int { return $this->rowOffset; }

    /**
     * Idiomatic split for callers that prefer dedicated setters over
     * the bundled `with*` chain. Mirrors upstream's `SetWidth(int)`.
     */
    public function setWidth(int $w): self  { return $this->withWidth($w); }
    public function setHeight(int $h): self { return $this->withHeight($h); }

    // ---- editing primitives -----------------------------------------

    private function insert(string $rune): self
    {
        if ($this->charLimit > 0 && $this->totalLength() >= $this->charLimit) {
            return $this;
        }
        $line   = $this->lines[$this->row];
        $before = mb_substr($line, 0, $this->col, 'UTF-8');
        $after  = mb_substr($line, $this->col, null, 'UTF-8');
        $newLines           = $this->lines;
        $newLines[$this->row] = $before . $rune . $after;
        return $this->mutate(
            lines: $newLines,
            col: $this->col + mb_strlen($rune, 'UTF-8'),
        );
    }

    private function insertNewline(): self
    {
        if ($this->charLimit > 0 && $this->totalLength() >= $this->charLimit) {
            return $this;
        }
        $line   = $this->lines[$this->row];
        $before = mb_substr($line, 0, $this->col, 'UTF-8');
        $after  = mb_substr($line, $this->col, null, 'UTF-8');

        $newLines = $this->lines;
        array_splice($newLines, $this->row, 1, [$before, $after]);

        return $this->mutate(lines: $newLines, row: $this->row + 1, col: 0);
    }

    private function backspace(): self
    {
        if ($this->col > 0) {
            $line   = $this->lines[$this->row];
            $before = mb_substr($line, 0, $this->col - 1, 'UTF-8');
            $after  = mb_substr($line, $this->col, null, 'UTF-8');
            $newLines           = $this->lines;
            $newLines[$this->row] = $before . $after;
            return $this->mutate(lines: $newLines, col: $this->col - 1);
        }
        if ($this->row === 0) {
            return $this;
        }
        // Merge with previous line.
        $prev   = $this->lines[$this->row - 1];
        $newCol = mb_strlen($prev, 'UTF-8');
        $merged = $prev . $this->lines[$this->row];
        $newLines = $this->lines;
        $newLines[$this->row - 1] = $merged;
        array_splice($newLines, $this->row, 1);
        return $this->mutate(lines: $newLines, row: $this->row - 1, col: $newCol);
    }

    private function deleteForward(): self
    {
        $line = $this->lines[$this->row];
        $len  = mb_strlen($line, 'UTF-8');
        if ($this->col < $len) {
            $before = mb_substr($line, 0, $this->col, 'UTF-8');
            $after  = mb_substr($line, $this->col + 1, null, 'UTF-8');
            $newLines = $this->lines;
            $newLines[$this->row] = $before . $after;
            return $this->mutate(lines: $newLines);
        }
        if ($this->row >= count($this->lines) - 1) {
            return $this;
        }
        // Merge next line into this one.
        $merged = $line . $this->lines[$this->row + 1];
        $newLines = $this->lines;
        $newLines[$this->row] = $merged;
        array_splice($newLines, $this->row + 1, 1);
        return $this->mutate(lines: $newLines);
    }

    private function deleteToLineStart(): self
    {
        $line   = $this->lines[$this->row];
        $after  = mb_substr($line, $this->col, null, 'UTF-8');
        $newLines = $this->lines;
        $newLines[$this->row] = $after;
        return $this->mutate(lines: $newLines, col: 0);
    }

    private function deleteToLineEnd(): self
    {
        $line   = $this->lines[$this->row];
        $before = mb_substr($line, 0, $this->col, 'UTF-8');
        $newLines = $this->lines;
        $newLines[$this->row] = $before;
        return $this->mutate(lines: $newLines);
    }

    /**
     * Anchor/caret pair normalised to `[startRow, startCol, endRow, endCol]`
     * (lexicographic order, so a backwards selection is identical to the
     * forwards one), or null when empty. Ends clamp against the LIVE buffer:
     * a dangling anchor left by an external shrink can only ever collapse
     * the span, never index a missing line (fail-soft total function).
     *
     * @return ?array{0:int, 1:int, 2:int, 3:int}
     */
    private function normalizedSelection(): ?array
    {
        if ($this->anchorRow === null || $this->anchorCol === null) {
            return null;
        }
        $lastRow = count($this->lines) - 1;
        $ar = max(0, min($lastRow, $this->anchorRow));
        $ac = max(0, min($this->lineLen($ar), $this->anchorCol));
        $br = max(0, min($lastRow, $this->row));
        $bc = max(0, min($this->lineLen($br), $this->col));
        if ($ar === $br && $ac === $bc) {
            return null;
        }
        if ($ar < $br || ($ar === $br && $ac < $bc)) {
            return [$ar, $ac, $br, $bc];
        }
        return [$br, $bc, $ar, $ac];
    }

    /**
     * The half-open cell window `[start, end)` this selection covers ON
     * `$row` (codepoint indices), or null when the row holds no cells —
     * fully-selected middle rows report `[0, lineLen)`, an empty middle row
     * reports null (nothing to paint, the newline between rows is implicit).
     *
     * @return ?array{0:int, 1:int}
     */
    private function selectionSpanOn(int $row): ?array
    {
        $span = $this->normalizedSelection();
        if ($span === null) {
            return null;
        }
        [$r0, $c0, $r1, $c1] = $span;
        if ($row < $r0 || $row > $r1) {
            return null;
        }
        $start = $row === $r0 ? $c0 : 0;
        $end   = $row === $r1 ? $c1 : $this->lineLen($row);
        return $start >= $end ? null : [$start, $end];
    }

    /**
     * Wrap `$text`'s overlap with `$span` in reverse video. `$base` is the
     * cell offset `$text` starts at within its row, so the same painter
     * handles whole rows and the before/after fragments of a caret row.
     *
     * @param array{0:int, 1:int} $span
     */
    private function paintSpan(string $text, array $span, int $base = 0): string
    {
        $len = mb_strlen($text, 'UTF-8');
        $from = max(0, $span[0] - $base);
        $to   = min($len, $span[1] - $base);
        if ($from >= $to) {
            return $text;
        }
        return mb_substr($text, 0, $from, 'UTF-8')
            . Ansi::sgr(Ansi::REVERSE)
            . mb_substr($text, $from, $to - $from, 'UTF-8')
            . Ansi::reset()
            . mb_substr($text, $to, null, 'UTF-8');
    }

    /**
     * Caret row inside a selection: pre/caret/post fragments each painted
     * through {@see paintSpan()}. The cursor cell itself stays delegated to
     * the Cursor primitive (which already paints reverse video, same as
     * renderCursorLine) — a caret sitting on a selected cell lands inside
     * the same bar, so no extra wrap is emitted here. Adjacent runs render
     * as one continuous selection.
     *
     * @param array{0:int, 1:int} $span
     */
    private function renderCursorAndSpan(string $line, array $span): string
    {
        $lineLen = mb_strlen($line, 'UTF-8');
        $col     = max(0, min($lineLen, $this->col));
        $before  = mb_substr($line, 0, $col, 'UTF-8');
        $charAt  = $col < $lineLen ? mb_substr($line, $col, 1, 'UTF-8') : ' ';
        $after   = $col < $lineLen ? mb_substr($line, $col + 1, null, 'UTF-8') : '';
        return $this->paintSpan($before, $span, 0)
            . $this->cursor->setChar($charAt)->view()
            . $this->paintSpan($after, $span, $col + 1);
    }

    /**
     * Delete the selected cells (joining lines across a multi-row span) and
     * park the caret at the span start. No-op when the selection is empty.
     */
    private function deleteSelection(): self
    {
        $span = $this->normalizedSelection();
        if ($span === null) {
            return $this;
        }
        [$r0, $c0, $r1, $c1] = $span;
        $lines = $this->lines;
        if ($r0 === $r1) {
            $lines[$r0] = mb_substr($lines[$r0], 0, $c0, 'UTF-8')
                . mb_substr($lines[$r0], $c1, null, 'UTF-8');
        } else {
            $merged = mb_substr($lines[$r0], 0, $c0, 'UTF-8')
                . mb_substr($lines[$r1], $c1, null, 'UTF-8');
            array_splice($lines, $r0, $r1 - $r0 + 1, [$merged]);
        }
        return $this->mutate(
            lines: $lines,
            row: $r0,
            col: $c0,
            anchorRow: null,
            anchorCol: null,
            anchorSet: true,
        );
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    private function copySelection(): array
    {
        $text = $this->selectedText();
        if ($text === '') {
            return [$this, null];
        }
        return [$this, Cmd::setClipboard($text)];
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    private function cutSelection(): array
    {
        $text = $this->selectedText();
        if ($text === '') {
            return [$this, null];
        }
        return [$this->deleteSelection(), Cmd::setClipboard($text)];
    }

    private function moveLeft(): self
    {
        if ($this->col > 0) {
            return $this->mutate(col: $this->col - 1);
        }
        if ($this->row > 0) {
            $prevLen = $this->lineLen($this->row - 1);
            return $this->mutate(row: $this->row - 1, col: $prevLen);
        }
        return $this;
    }

    private function moveRight(): self
    {
        $lineLen = $this->lineLen($this->row);
        if ($this->col < $lineLen) {
            return $this->mutate(col: $this->col + 1);
        }
        if ($this->row < count($this->lines) - 1) {
            return $this->mutate(row: $this->row + 1, col: 0);
        }
        return $this;
    }

    private function moveCursor(int $row, int $col): self
    {
        $row = max(0, min(count($this->lines) - 1, $row));
        $col = max(0, min($this->lineLen($row), $col));
        return $this->mutate(row: $row, col: $col);
    }

    private function lineLen(int $row): int
    {
        return mb_strlen($this->lines[$row] ?? '', 'UTF-8');
    }

    private function totalLength(): int
    {
        if ($this->totalLengthMemo !== null) {
            return $this->totalLengthMemo;
        }
        $sum = 0;
        foreach ($this->lines as $l) {
            $sum += mb_strlen($l, 'UTF-8');
        }
        $sum += max(0, count($this->lines) - 1); // newlines
        return $this->totalLengthMemo = $sum;
    }

    /**
     * Build the `Cmd::exec` Cmd that hands the current value to the
     * user's external editor and dispatches a {@see TextAreaEditedMsg}
     * with the result. Returns `null` (no Cmd) when no editor can be
     * discovered or the temp file cannot be created — the keystroke
     * collapses to a no-op so the UI never wedges.
     *
     * Non-zero exit (vim `:cq`, child crash, proc_open failure) does
     * not produce a Msg either; the pre-edit value is preserved.
     */
    private function openInEditor(): ?\Closure
    {
        try {
            $argv = Editor::command();
        } catch (\RuntimeException) {
            return null;
        }

        $tmp = @tempnam(sys_get_temp_dir(), 'sc-textarea-');
        if ($tmp === false) {
            return null;
        }
        $ext = ltrim($this->editorExtension, '.');
        if ($ext !== '') {
            $renamed = $tmp . '.' . $ext;
            if (@rename($tmp, $renamed)) {
                $tmp = $renamed;
            }
        }
        if (@file_put_contents($tmp, $this->value()) === false) {
            @unlink($tmp);
            return null;
        }

        return Cmd::exec(
            [...$argv, $tmp],
            captureOutput: false,
            onComplete: static function (int $exit, string $out, string $err, ?\Throwable $error) use ($tmp): ?Msg {
                try {
                    if ($exit !== 0 || $error !== null) {
                        return null;
                    }
                    $content = @file_get_contents($tmp);
                    if ($content === false) {
                        return null;
                    }
                    return new TextAreaEditedMsg($content);
                } finally {
                    @unlink($tmp);
                }
            },
        );
    }

    /**
     * Render one physical line with the cursor cell spliced in.
     *
     * Cursor::view() renders exactly ONE cell (the char handed via
     * setChar), so the before/after split around that cell is inherently
     * the buffer renderer's job — moving it into Cursor would invert the
     * layering (primitive learning about lines). The apparent "manual
     * ANSI" is only line composition; the cell itself already delegates
     * to the cursor primitive (E736 plan 3.10). Past the line end the
     * cursor paints a space so an empty-line caret still shows.
     */
    private function renderCursorLine(string $line): string
    {
        $lineLen = mb_strlen($line, 'UTF-8');
        $before  = mb_substr($line, 0, $this->col, 'UTF-8');
        $charAt  = $this->col < $lineLen ? mb_substr($line, $this->col, 1, 'UTF-8') : ' ';
        $after   = $this->col < $lineLen ? mb_substr($line, $this->col + 1, null, 'UTF-8') : '';
        return $before . $this->cursor->setChar($charAt)->view() . $after;
    }

    /**
     * @param list<string>|null $lines
     */
    private function mutate(
        ?array $lines = null,
        ?int $row = null,
        ?int $col = null,
        ?string $placeholder = null,
        ?int $charLimit = null,
        ?int $width = null,
        ?int $height = null,
        ?bool $focused = null,
        ?Cursor $cursor = null,
        ?int $rowOffset = null,
        ?bool $showLineNumbers = null,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
        ?string $endOfBufferCharacter = null,
        ?string $prompt = null,
        ?\Closure $validate = null, bool $validateSet = false,
        ?string $err = null, bool $errSet = false,
        ?\Closure $promptFunc = null, bool $promptFuncSet = false,
        ?bool $dynamic = null,
        ?string $editorExtension = null,
        ?int $anchorRow = null, bool $anchorSet = false,
        ?int $anchorCol = null,
    ): self {
        $newLines = $lines ?? $this->lines;
        $resolvedValidate = $validateSet ? $validate : $this->validate;
        if (!$errSet) {
            if ($resolvedValidate !== null && $lines !== null) {
                $err = $resolvedValidate(implode("\n", $newLines));
            } else {
                $err = $this->err;
            }
        }
        return new self(
            lines:                 $newLines,
            row:                   $row                  ?? $this->row,
            col:                   $col                  ?? $this->col,
            placeholder:           $placeholder          ?? $this->placeholder,
            charLimit:             $charLimit            ?? $this->charLimit,
            width:                 $width                ?? $this->width,
            height:                $height               ?? $this->height,
            focused:               $focused              ?? $this->focused,
            cursor:                $cursor               ?? $this->cursor,
            rowOffset:             $rowOffset            ?? $this->rowOffset,
            showLineNumbers:       $showLineNumbers      ?? $this->showLineNumbers,
            maxWidth:              $maxWidth             ?? $this->maxWidth,
            maxHeight:             $maxHeight            ?? $this->maxHeight,
            endOfBufferCharacter:  $endOfBufferCharacter ?? $this->endOfBufferCharacter,
            prompt:                $prompt               ?? $this->prompt,
            validate:              $resolvedValidate,
            err:                   $err,
            promptFunc:            $promptFuncSet        ? $promptFunc : $this->promptFunc,
            dynamic:               $dynamic              ?? $this->dynamic,
            editorExtension:       $editorExtension      ?? $this->editorExtension,
            anchorRow:             $anchorSet            ? $anchorRow : $this->anchorRow,
            anchorCol:             $anchorSet            ? $anchorCol : $this->anchorCol,
        );
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
