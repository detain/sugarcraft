<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tui;

/**
 * Immutable text-editing buffer behind one compose field.
 *
 * The compose form needs six tiny inputs, not a widget library: this keeps
 * candy-mail on its existing sugar-core-only dependency set (a form-toolkit
 * dependency would drag a theme/validation stack into a send-path CLI).
 * The cursor counts characters, never bytes, so multibyte text edits whole
 * code points.
 *
 * Control characters never enter the buffer: a pasted ESC would otherwise
 * ride into the message and the terminal repaint alike. A multiline buffer
 * keeps "\n" only; a single-line buffer folds line breaks into spaces.
 */
final class TextBuffer
{
    /** @var list<string> */
    private array $chars;

    private function __construct(
        string $value,
        public readonly int $cursor,
        public readonly bool $multiline,
    ) {
        $this->chars = $value === '' ? [] : (\preg_split('//u', $value, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
    }

    public static function new(string $value = '', bool $multiline = false): self
    {
        $clean = self::clean($value, $multiline);

        return new self($clean, \mb_strlen($clean), $multiline);
    }

    public function value(): string
    {
        return \implode('', $this->chars);
    }

    public function length(): int
    {
        return \count($this->chars);
    }

    public function isEmpty(): bool
    {
        return $this->chars === [];
    }

    public function insert(string $text): self
    {
        $clean = self::clean($text, $this->multiline);
        if ($clean === '') {
            return $this;
        }
        $add   = \preg_split('//u', $clean, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $chars = \array_merge(\array_slice($this->chars, 0, $this->cursor), $add, \array_slice($this->chars, $this->cursor));

        return new self(\implode('', $chars), $this->cursor + \count($add), $this->multiline);
    }

    public function backspace(): self
    {
        if ($this->cursor === 0) {
            return $this;
        }
        $chars = $this->chars;
        \array_splice($chars, $this->cursor - 1, 1);

        return new self(\implode('', $chars), $this->cursor - 1, $this->multiline);
    }

    public function delete(): self
    {
        if ($this->cursor >= \count($this->chars)) {
            return $this;
        }
        $chars = $this->chars;
        \array_splice($chars, $this->cursor, 1);

        return new self(\implode('', $chars), $this->cursor, $this->multiline);
    }

    public function left(): self
    {
        return $this->at(\max(0, $this->cursor - 1));
    }

    public function right(): self
    {
        return $this->at(\min(\count($this->chars), $this->cursor + 1));
    }

    /** Start of the current line (the whole buffer when single-line). */
    public function home(): self
    {
        return $this->at($this->lineStart());
    }

    public function end(): self
    {
        $i = $this->cursor;
        $n = \count($this->chars);
        while ($i < $n && $this->chars[$i] !== "\n") {
            $i++;
        }

        return $this->at($i);
    }

    /** Move one line up keeping the column; stays put on the first line. */
    public function up(): self
    {
        $start = $this->lineStart();
        if ($start === 0) {
            return $this;
        }
        $col       = $this->cursor - $start;
        $prevEnd   = $start - 1;
        $prevStart = $prevEnd;
        while ($prevStart > 0 && $this->chars[$prevStart - 1] !== "\n") {
            $prevStart--;
        }

        return $this->at($prevStart + \min($col, $prevEnd - $prevStart));
    }

    /** Move one line down keeping the column; stays put on the last line. */
    public function down(): self
    {
        $n   = \count($this->chars);
        $end = $this->end()->cursor;
        if ($end >= $n) {
            return $this;
        }
        $col       = $this->cursor - $this->lineStart();
        $nextStart = $end + 1;
        $nextEnd   = $nextStart;
        while ($nextEnd < $n && $this->chars[$nextEnd] !== "\n") {
            $nextEnd++;
        }

        return $this->at($nextStart + \min($col, $nextEnd - $nextStart));
    }

    /** True when the cursor sits on the first line. */
    public function onFirstLine(): bool
    {
        return $this->lineStart() === 0;
    }

    /** True when the cursor sits on the last line. */
    public function onLastLine(): bool
    {
        return $this->end()->cursor >= \count($this->chars);
    }

    public function clear(): self
    {
        return new self('', 0, $this->multiline);
    }

    /**
     * Split into lines with the cursor's (line, column) position.
     *
     * @return array{lines: list<list<string>>, row: int, col: int}
     */
    public function layout(): array
    {
        $lines = [[]];
        $row   = 0;
        $col   = 0;
        foreach ($this->chars as $i => $ch) {
            if ($i === $this->cursor) {
                $row = \count($lines) - 1;
                $col = \count($lines[$row]);
            }
            if ($ch === "\n") {
                $lines[] = [];
                continue;
            }
            $lines[\count($lines) - 1][] = $ch;
        }
        if ($this->cursor >= \count($this->chars)) {
            $row = \count($lines) - 1;
            $col = \count($lines[$row]);
        }

        return ['lines' => $lines, 'row' => $row, 'col' => $col];
    }

    private function at(int $cursor): self
    {
        return $cursor === $this->cursor ? $this : new self($this->value(), $cursor, $this->multiline);
    }

    private function lineStart(): int
    {
        $i = $this->cursor;
        while ($i > 0 && $this->chars[$i - 1] !== "\n") {
            $i--;
        }

        return $i;
    }

    private static function clean(string $text, bool $multiline): string
    {
        $text = \str_replace(["\r\n", "\r"], "\n", $text);
        if (!\mb_check_encoding($text, 'UTF-8')) {
            $text = \mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        if (!$multiline) {
            $text = \str_replace("\n", ' ', $text);
        }
        // Drop C0 controls (keeping the LF a multiline buffer owns), DEL, C1.
        $keep = $multiline ? '/[\x00-\x09\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/' : '/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/';

        return \preg_replace($keep, '', $text) ?? '';
    }
}
