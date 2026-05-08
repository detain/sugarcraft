<?php

declare(strict_types=1);

namespace SugarCraft\Bits\TextArea;

use SugarCraft\Bits\Cursor\BlinkMsg;
use SugarCraft\Bits\Cursor\Cursor;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
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
 */
final class TextArea implements Model
{
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
    ) {}

    /** Construct a fresh instance with default state. */
    public static function new(): self
    {
        return new self(
            lines: [''],
            row: 0,
            col: 0,
            placeholder: '',
            charLimit: 0,
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
        if (!$msg instanceof KeyMsg || !$this->focused) {
            return [$this, null];
        }

        if ($msg->ctrl) {
            return match ($msg->rune) {
                'a'     => [$this->moveCursor($this->row, 0), null],
                'e'     => [$this->moveCursor($this->row, $this->lineLen($this->row)), null],
                'u'     => [$this->deleteToLineStart(), null],
                'k'     => [$this->deleteToLineEnd(), null],
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

        // Render with the embedded cursor at (row, col).
        $relRow = $this->row - $start;
        $out = [];
        foreach ($rows as $i => $line) {
            if ($i !== $relRow) {
                $out[] = $line;
                continue;
            }
            $out[] = $this->renderCursorLine($line);
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
        );
    }

    public function value(): string
    {
        return implode("\n", $this->lines);
    }

    public function reset(): self
    {
        return $this->mutate(lines: [''], row: 0, col: 0, rowOffset: 0);
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
        $sum = 0;
        foreach ($this->lines as $l) {
            $sum += mb_strlen($l, 'UTF-8');
        }
        $sum += max(0, count($this->lines) - 1); // newlines
        return $sum;
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
        );
    }
}
