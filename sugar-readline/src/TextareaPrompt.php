<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

/**
 * Multi-line text input with per-line cursor navigation, optional max-line cap,
 * and a default initial value.
 *
 * Keyboard contract (a conversation must be completable without the mouse or
 * a programmatic call): Enter submits, Shift+Enter and Ctrl+J insert a new
 * line, Escape / Ctrl+C abort, Ctrl+U kills the text before the cursor on the
 * current line. Up / Down move between lines; Home / End jump within the
 * current line. Readline's pump pushes the kitty keyboard protocol for the
 * life of the prompt, so capable terminals report the chords; on wires that
 * never report modified keys, Shift+Enter and Ctrl+J send bare 0x0A/0x0D,
 * which decode as a plain Enter and submit. Wire pins: ReadlineWireChordTest.
 */
final class TextareaPrompt
{
    /** @var list<string> */
    private array $lines = [''];

    private int $line   = 0;
    private int $col    = 0;
    private int $maxLines = 0;     // 0 = unlimited
    private bool $submitted = false;
    private bool $aborted   = false;

    private string $labelStyle  = '1;36';
    private string $cursorStyle = '7';

    /** @var int|null Display bound in cells; null = unset (width-adoption eligible), 0 = explicit unbounded. */
    private ?int $displayWidth = null;

    /** @var int|null Terminal row budget; null = unset (height-adoption eligible), 0 = explicit unbounded. */
    private ?int $viewportRows = null;

    public function __construct(private readonly string $label) {}

    public static function new(string $label): self
    {
        return new self($label);
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public function withDefault(string $value): self
    {
        $clone = clone $this;
        $clone->lines = explode("\n", $value);
        // Clamp to maxLines if set (ordering caveat: withMaxLines() should precede
        // withDefault() for the clamp to engage, matching upstream promptkit behavior).
        if ($clone->maxLines > 0 && count($clone->lines) > $clone->maxLines) {
            $clone->lines = array_slice($clone->lines, 0, $clone->maxLines);
        }
        $clone->line  = count($clone->lines) - 1;
        $clone->col   = self::charCount($clone->lines[$clone->line]);
        return $clone;
    }

    public function withMaxLines(int $max): self
    {
        $clone = clone $this;
        $clone->maxLines = max(0, $max);
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Input
    // -------------------------------------------------------------------------

    public function handleChar(string $char): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }
        if ($char === '' || self::charCount($char) !== 1) {
            return $this;
        }
        // Control bytes are keys, not content: the Readline pump never routes
        // them here (they decode to symbolic names), but direct callers could.
        // Dropping them keeps an embedded LF from forging an extra line.
        if (\ord($char) < 32 || \ord($char) === 127) {
            return $this;
        }

        $clone = clone $this;
        $current = $clone->lines[$clone->line];
        $clone->lines[$clone->line] = self::sliceChars($current, 0, $clone->col)
                                    . $char
                                    . self::sliceChars($current, $clone->col);
        $clone->col++;
        return $clone;
    }

    /**
     * Insert pasted text as literal content: control bytes are stripped (they
     * could forge submits/aborts through the char-by-char path) and newlines
     * split into lines. Mode-agnostic by design — a paste is text, never a
     * stream of keystrokes.
     */
    public function insertPastedText(string $text): self
    {
        $clean = \preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/u', '', $text) ?? '';
        if ($clean === '') {
            return $this;
        }
        if ($this->submitted || $this->aborted) {
            return $this;
        }

        $prompt = $this;
        $chunks = \explode("\n", $clean);
        foreach ($chunks as $index => $chunk) {
            if ($index > 0) {
                $prompt = $prompt->insertNewline();
            }
            if ($chunk !== '') {
                $prompt = $prompt->appendLiteral($chunk);
            }
        }
        return $prompt;
    }

    /** Insert a control-free run of characters at the cursor. */
    private function appendLiteral(string $text): self
    {
        $prompt = $this;
        foreach (\mb_str_split($text, 1, 'UTF-8') as $char) {
            $prompt = $prompt->handleChar($char);
        }
        return $prompt;
    }

    public function handleKey(string $key): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }

        // One canonical key language (same choke as TextPrompt and
        // SelectionPrompt): raw control bytes become symbolic names here so
        // the arms below fire identically whether driven through Readline or
        // fed bytes directly.
        $key = Key::normalize($key);

        return match ($key) {
            // GNU-readline movement aliases on the multi-line surface:
            // Ctrl+P/N walk lines, Ctrl+A/E jump line ends. Ctrl+B/F stay
            // out here — mirroring them would widen the step's scope and
            // the arrows already own the column moves.
            Key::Up, 'ctrl_p'      => $this->moveLine(-1),
            Key::Down, 'ctrl_n'    => $this->moveLine(1),
            Key::Left              => $this->moveCol(-1),
            Key::Right             => $this->moveCol(1),
            Key::Home, 'ctrl_a'    => $this->moveColTo(0),
            Key::End, 'ctrl_e'     => $this->moveColTo(self::charCount($this->lines[$this->line])),
            Key::Backspace => $this->deleteBeforeCursor(),
            Key::Delete    => $this->deleteUnderCursor(),
            // Enter submits the conversation (a keyboard must be able to
            // finish it); a newline is an explicit chord.
            Key::Enter     => $this->submit(),
            'shift_enter', 'ctrl_j' => $this->insertNewline(),
            'ctrl_u'       => $this->killBeforeCursor(),
            Key::Space     => $this->handleChar(' '),
            Key::Escape, Key::CtrlC => $this->abort(),
            default        => $this,
        };
    }

    public function submit(): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }
        $clone = clone $this;
        $clone->submitted = true;
        return $clone;
    }

    public function abort(): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }
        $clone = clone $this;
        $clone->aborted = true;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    public function value(): string
    {
        return $this->aborted ? '' : implode("\n", $this->lines);
    }

    public function isSubmitted(): bool { return $this->submitted; }
    public function isAborted(): bool   { return $this->aborted; }

    public function cursorLine(): int { return $this->line; }
    public function cursorCol(): int  { return $this->col; }
    public function lineCount(): int  { return count($this->lines); }
    public function lines(): array  { return $this->lines; }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    public function view(): string
    {
        $bound = $this->displayWidth;
        $out = [RowFit::row([[Ansi::sanitize($this->label), $this->labelStyle]], $bound)];

        [$first, $limit, $showIndicators] = $this->paintWindow();
        $last = min(count($this->lines), $first + $limit);
        if ($showIndicators && $first > 0) {
            $out[] = RowFit::row([['…', null]], $bound);
        }
        for ($i = $first; $i < $last; $i++) {
            $text = Ansi::sanitize($this->lines[$i]);
            if ($i !== $this->line) {
                $out[] = RowFit::row([[$text, null]], $bound);
                continue;
            }
            $before = self::sliceChars($text, 0, $this->col);
            $under  = self::sliceChars($text, $this->col, 1);
            $after  = self::sliceChars($text, $this->col + 1);
            $out[]  = RowFit::row([
                [$before, null],
                [$under === '' ? ' ' : $under, $this->cursorStyle],
                [$after, null],
            ], $bound);
        }
        if ($showIndicators && $last < count($this->lines)) {
            $out[] = RowFit::row([['…', null]], $bound);
        }
        return implode("\n", $out);
    }

    /**
     * Which lines the frame paints: [first, count, indicators?].
     *
     * The budget is rows minus the label and (when both ends are cut) the
     * two `…` indicator rows — hence rows-3 when indicators are allowed.
     * Below 5 rows there is no room to hint at hidden lines without
     * eating the body, so indicators are gated off and the window is a
     * single line around the cursor (tiny-terminal gate, documented in
     * the README).
     *
     * @return array{0:int,1:int,2:bool}
     */
    private function paintWindow(): array
    {
        $rows = $this->viewportRows;
        $total = count($this->lines);
        if ($rows === null || $rows <= 0 || $total <= 1) {
            return [0, max(1, $total), false];
        }

        $bodyCap = max(1, $rows - 3);
        $indicators = $rows >= 5;

        $first = min($this->line, max(0, $total - $bodyCap));
        if ($this->line >= $first + $bodyCap) {
            $first = $this->line - $bodyCap + 1;
        }
        $first = max(0, min($first, $total - 1));

        if ($total <= $bodyCap) {
            return [0, $total, false];
        }

        return [$first, $bodyCap, $indicators];
    }

    /**
     * Bound the painted height to $rows terminal rows (label and scroll
     * indicators included in the budget). Mirrors the width surface: 0 or
     * null renders every line unbounded, null being the adoption-eligible
     * state {@see Readline::run()} fills from the terminal row count.
     */
    public function withViewportRows(?int $rows): self
    {
        $clone = clone $this;
        $clone->viewportRows = $rows === null ? null : max(0, $rows);
        return $clone;
    }

    /** Current row budget; null = unset (adoption-eligible), 0 = explicit unbounded. */
    public function viewportRows(): ?int
    {
        return $this->viewportRows;
    }

    /**
     * Bound the rendered lines to $cells display columns (CJK/emoji aware).
     * Mirrors {@see TextPrompt::withDisplayWidth()}: pass 0 to opt out
     * (lines paint unbounded), pass null to leave the bound unset — the
     * only state {@see Readline::run()} will auto-adopt a width into.
     * Long lines are right-clipped with the shared `…` budget; keeping the
     * cursor column inside the window on such lines is the cell-aware
     * cursor model's job (S-24 tracks it separately).
     */
    public function withDisplayWidth(?int $cells): self
    {
        $clone = clone $this;
        $clone->displayWidth = $cells === null ? null : max(0, $cells);
        return $clone;
    }

    /** Current display viewport width in cells; null = unset (adoption-eligible), 0 = explicit unbounded. */
    public function displayWidth(): ?int
    {
        return $this->displayWidth;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function moveLine(int $delta): self
    {
        $target = $this->line + $delta;
        $clamped = max(0, min(count($this->lines) - 1, $target));
        if ($clamped === $this->line) {
            return $this;
        }
        $clone = clone $this;
        $clone->line = $clamped;
        $clone->col  = min($clone->col, self::charCount($clone->lines[$clamped]));
        return $clone;
    }

    private function moveCol(int $delta): self
    {
        return $this->moveColTo($this->col + $delta);
    }

    private function moveColTo(int $position): self
    {
        $clamped = max(0, min(self::charCount($this->lines[$this->line]), $position));
        if ($clamped === $this->col) {
            return $this;
        }
        $clone = clone $this;
        $clone->col = $clamped;
        return $clone;
    }

    private function deleteBeforeCursor(): self
    {
        if ($this->col > 0) {
            $clone = clone $this;
            $current = $clone->lines[$clone->line];
            $clone->lines[$clone->line] = self::sliceChars($current, 0, $clone->col - 1)
                                        . self::sliceChars($current, $clone->col);
            $clone->col--;
            return $clone;
        }
        // Backspace at column 0: merge with previous line.
        if ($this->line === 0) {
            return $this;
        }
        $clone   = clone $this;
        $prev    = $clone->lines[$clone->line - 1];
        $current = $clone->lines[$clone->line];
        $clone->lines[$clone->line - 1] = $prev . $current;
        array_splice($clone->lines, $clone->line, 1);
        $clone->line--;
        $clone->col = self::charCount($prev);
        return $clone;
    }

    /** Ctrl+U: erase everything before the cursor on the current line. */
    private function killBeforeCursor(): self
    {
        if ($this->col === 0) {
            return $this;
        }
        $clone = clone $this;
        $clone->lines[$clone->line] = self::sliceChars($clone->lines[$clone->line], $clone->col);
        $clone->col = 0;
        return $clone;
    }

    private function deleteUnderCursor(): self
    {
        $current = $this->lines[$this->line];
        if ($this->col < self::charCount($current)) {
            $clone = clone $this;
            $clone->lines[$clone->line] = self::sliceChars($current, 0, $clone->col)
                                        . self::sliceChars($current, $clone->col + 1);
            return $clone;
        }
        // Delete at end of line: merge with next line.
        if ($this->line >= count($this->lines) - 1) {
            return $this;
        }
        $clone = clone $this;
        $clone->lines[$clone->line] = $current . $clone->lines[$clone->line + 1];
        array_splice($clone->lines, $clone->line + 1, 1);
        return $clone;
    }

    private function insertNewline(): self
    {
        if ($this->maxLines > 0 && count($this->lines) >= $this->maxLines) {
            return $this;
        }
        $clone   = clone $this;
        $current = $clone->lines[$clone->line];
        $head    = self::sliceChars($current, 0, $clone->col);
        $tail    = self::sliceChars($current, $clone->col);
        $clone->lines[$clone->line] = $head;
        array_splice($clone->lines, $clone->line + 1, 0, [$tail]);
        $clone->line++;
        $clone->col = 0;
        return $clone;
    }

    private static function charCount(string $s): int
    {
        return mb_strlen($s, 'UTF-8');
    }

    private static function sliceChars(string $s, int $start, ?int $length = null): string
    {
        return $length === null
            ? mb_substr($s, $start, null, 'UTF-8')
            : mb_substr($s, $start, $length, 'UTF-8');
    }
}
