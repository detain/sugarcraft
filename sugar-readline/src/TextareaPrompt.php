<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

use SugarCraft\Style\Style;
/**
 * Native design: erikgeiser/promptkit (the declared antecedent, see
 * composer.json) ships no multi-line widget; the multi-line requirement
 * comes from the wider ecosystem census in
 * docs/research/libraries/sugar-readline-research.md.
 *
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
final class TextareaPrompt implements PromptInterface, SizingInterface
{
    /** @var list<string> */
    private array $lines = [''];

    private int $line   = 0;
    private int $col    = 0;
    private int $maxLines = 0;     // 0 = unlimited
    private bool $submitted = false;
    private bool $aborted   = false;

    private string $labelStyle  = Styles::LABEL;
    private string $cursorStyle = Styles::CURSOR;

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

    /**
     * The house value-object idiom (finding 027): every fluent with*
     * returns a clone carrying the given property changes. sugar-core's
     * generic Concerns\Mutable constructor-spreads promoted properties;
     * this state lives in private fields, not constructor parameters, so
     * the mutate is hand-rolled with an identical shape.
     *
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        $clone = clone $this;
        foreach ($changes as $property => $value) {
            $clone->$property = $value;
        }
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Style seam (finding 036): palette defaults come from Styles;
    // setters accept a composed sugar-style Style or a raw SGR code string.
    // -------------------------------------------------------------------------

    public function withLabelStyle(Style|string $style): self
    {
        return $this->mutate(['labelStyle' => Styles::code($style)]);
    }

    public function withCursorStyle(Style|string $style): self
    {
        return $this->mutate(['cursorStyle' => Styles::code($style)]);
    }

    public function withDefault(string $value): self
    {
        // Order-independence law (finding 092): whichever of the limit
        // setters runs last enforces the line clamp.
        $lines = explode("\n", $value);
        if ($this->maxLines > 0 && count($lines) > $this->maxLines) {
            $lines = array_slice($lines, 0, $this->maxLines);
        }
        $line = count($lines) - 1;
        return $this->mutate([
            'lines' => $lines,
            'line'  => $line,
            'col'   => self::charCount($lines[$line]),
        ]);
    }

    /**
     * Cap the editor at $max lines (0 = unlimited).
     *
     * A cap applied after a default re-slices the lines immediately —
     * the clamp is enforced by whichever setter runs last, not by chain
     * order (finding 092). The caret follows the last surviving line.
     */
    public function withMaxLines(int $max): self
    {
        $max     = max(0, $max);
        $changes = ['maxLines' => $max];
        if ($max > 0 && count($this->lines) > $max) {
            $lines            = array_slice($this->lines, 0, $max);
            $changes['lines'] = $lines;
            $changes['line']  = count($lines) - 1;
            $changes['col']   = self::charCount($lines[$changes['line']]);
        }
        return $this->mutate($changes);
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
     * Insert pasted text as literal content: control bytes are stripped
     * (they could forge submits/aborts through the char-by-char path) but
     * TAB is deliberately kept — code pasting into a textarea needs its
     * indentation, and the README promises TAB on both surfaces — and LF
     * splits into lines. Each cleaned line then splices in ONE clone (see
     * appendLiteral). Mode-agnostic by design — a paste is text, never a
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

    /**
     * Insert a control-free run of characters at the cursor in one splice.
     *
     * The run arrives pre-parsed from insertPastedText: control bytes are
     * already stripped (only TAB survives, and TAB is CONTENT here, so
     * handleChar's key gate must not see it) and LF has been exploded into
     * lines. Replaying per character through handleChar applied a gate this
     * path had already passed — silently eating every pasted TAB — and paid
     * one immutable clone per character, each copying the whole lines array
     * (the ~n² clipboard cost, measured 12k chars ≈ 144ms at annotation
     * time). One splice copies it once.
     */
    private function appendLiteral(string $text): self
    {
        if ($text === '') {
            return $this;
        }

        $clone = clone $this;
        $current = $clone->lines[$clone->line];
        $clone->lines[$clone->line] = self::sliceChars($current, 0, $clone->col)
                                    . $text
                                    . self::sliceChars($current, $clone->col);
        $clone->col += self::charCount($text);
        return $clone;
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
            Key::Up, Key::CtrlP      => $this->moveLine(-1),
            Key::Down, Key::CtrlN    => $this->moveLine(1),
            Key::Left              => $this->moveColTo(self::clusterStart($this->lines[$this->line], $this->col)),
            Key::Right             => $this->moveColTo(self::clusterEnd($this->lines[$this->line], $this->col)),
            Key::Home, Key::CtrlA    => $this->moveColTo(0),
            Key::End, Key::CtrlE     => $this->moveColTo(self::charCount($this->lines[$this->line])),
            Key::Backspace => $this->deleteBeforeCursor(),
            Key::Delete    => $this->deleteUnderCursor(),
            // Enter submits the conversation (a keyboard must be able to
            // finish it); a newline is an explicit chord.
            Key::Enter     => $this->submit(),
            Key::ShiftEnter, Key::AltEnter, Key::CtrlJ => $this->insertNewline(),
            Key::CtrlU      => $this->killBeforeCursor(),
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
            $caretEnd = self::clusterEnd($text, $this->col);
            $before = self::sliceChars($text, 0, $this->col);
            $under  = self::sliceChars($text, $this->col, $caretEnd - $this->col);
            $after  = self::sliceChars($text, $caretEnd);
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
        return $this->mutate(['viewportRows' => $rows === null ? null : max(0, $rows)]);
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
        return $this->mutate(['displayWidth' => $cells === null ? null : max(0, $cells)]);
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
            $start = self::clusterStart($current, $clone->col);
            $clone->lines[$clone->line] = self::sliceChars($current, 0, $start)
                                        . self::sliceChars($current, $clone->col);
            $clone->col = $start;
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
                                        . self::sliceChars($current, self::clusterEnd($current, $clone->col));
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

    /**
     * Byte offset of a codepoint index inside a line.
     */
    private static function byteAt(string $line, int $col): int
    {
        return strlen(self::sliceChars($line, 0, max(0, $col)));
    }

    /**
     * Codepoint index just past the grapheme cluster that starts at or
     * after $col (finding 026: caret moves and forward deletes must walk
     * WHOLE clusters - family emoji and flags are multi-codepoint).
     * Without ext-intl grapheme_extract every codepoint is its own
     * cluster, which is the pre-fix behavior.
     */
    private static function clusterEnd(string $line, int $col): int
    {
        $len = self::charCount($line);
        if ($col >= $len) {
            return $len;
        }
        if (!function_exists('grapheme_extract')) {
            return $col + 1;
        }
        $next = 0;
        $out = grapheme_extract($line, 1, 0 /* GRAPHEME_EXTR_COUNT — the constant is intl-conditional; 0 is its value */, self::byteAt($line, $col), $next);
        if (!is_string($out) || $next <= 0) {
            return $col + 1;
        }
        return self::charCount(substr($line, 0, $next));
    }

    /**
     * Codepoint index where the grapheme cluster ending at $col starts -
     * the backspace/Left target (whole-cluster stepping again, finding 026).
     */
    private static function clusterStart(string $line, int $col): int
    {
        if ($col <= 0) {
            return 0;
        }
        if (!function_exists('grapheme_extract')) {
            return $col - 1;
        }
        $start = 0;
        $limit = self::byteAt($line, $col);
        while (true) {
            $next = 0;
            $out = grapheme_extract($line, 1, 0 /* GRAPHEME_EXTR_COUNT — the constant is intl-conditional; 0 is its value */, $start, $next);
            if (!is_string($out) || $next <= $start || $next >= $limit) {
                break;
            }
            $start = $next;
        }
        return self::charCount(substr($line, 0, $start));
    }
}
