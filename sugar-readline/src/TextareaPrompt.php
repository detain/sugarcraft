<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

/**
 * Multi-line text input with per-line cursor navigation, optional max-line cap,
 * and a default initial value.
 *
 * Newlines are entered with {@see Key::Enter}; submission is via {@see submit()}
 * or by feeding {@see Key::CtrlC} (abort) / external trigger. Up / Down move
 * between lines; Home / End jump within the current line.
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

        $clone = clone $this;
        $current = $clone->lines[$clone->line];
        $clone->lines[$clone->line] = self::sliceChars($current, 0, $clone->col)
                                    . $char
                                    . self::sliceChars($current, $clone->col);
        $clone->col++;
        return $clone;
    }

    public function handleKey(string $key): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }

        return match ($key) {
            Key::Up        => $this->moveLine(-1),
            Key::Down      => $this->moveLine(1),
            Key::Left      => $this->moveCol(-1),
            Key::Right     => $this->moveCol(1),
            Key::Home      => $this->moveColTo(0),
            Key::End       => $this->moveColTo(self::charCount($this->lines[$this->line])),
            Key::Backspace => $this->deleteBeforeCursor(),
            Key::Delete    => $this->deleteUnderCursor(),
            Key::Enter     => $this->insertNewline(),
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

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    public function view(): string
    {
        $out = [Ansi::wrap(Ansi::sanitize($this->label), $this->labelStyle)];
        foreach ($this->lines as $i => $text) {
            $text = Ansi::sanitize($text);
            if ($i !== $this->line) {
                $out[] = $text;
                continue;
            }
            $before = self::sliceChars($text, 0, $this->col);
            $under  = self::sliceChars($text, $this->col, 1);
            $after  = self::sliceChars($text, $this->col + 1);
            $out[]  = $before
                    . Ansi::wrap($under === '' ? ' ' : $under, $this->cursorStyle)
                    . $after;
        }
        return implode("\n", $out);
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
