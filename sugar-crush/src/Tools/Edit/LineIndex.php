<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Edit;

/**
 * A file split into lines with each line's byte offset, plus the helpers the
 * line-based {@see MatchStage}s share: turning `old_string` into lines and a
 * window of file lines back into a byte span.
 *
 * A line here never includes its `\n`; a CRLF file's lines keep their `\r`, and
 * {@see span()} stops before it so the file's own line ending survives the edit.
 */
final readonly class LineIndex
{
    /**
     * @param list<string> $lines
     * @param list<int>    $starts
     */
    private function __construct(
        public array $lines,
        public array $starts,
        private string $content,
    ) {}

    public static function of(string $content): self
    {
        $lines = explode("\n", $content);
        $starts = [];
        $offset = 0;
        foreach ($lines as $line) {
            $starts[] = $offset;
            $offset += strlen($line) + 1;
        }

        return new self($lines, $starts, $content);
    }

    public function count(): int
    {
        return \count($this->lines);
    }

    /**
     * The byte span of lines $first..$last inclusive: from the start of the
     * first line to the end of the last one, its `\r` excluded, plus the `\n`
     * that follows when $withNewline asks for it and the file has one.
     *
     * @return array{0: int, 1: int} offset and length
     */
    public function span(int $first, int $last, bool $withNewline): array
    {
        $start = $this->starts[$first];
        $end = $this->starts[$last] + strlen(rtrim($this->lines[$last], "\r"));
        if ($withNewline) {
            $end = $this->starts[$last] + strlen($this->lines[$last]);
            if ($end < strlen($this->content) && $this->content[$end] === "\n") {
                $end++;
            }
        }

        return [$start, $end - $start];
    }

    /**
     * `old_string` as lines, with the trailing newline (if any) noted rather
     * than kept as an empty last line, so `"a\nb\n"` is the two lines it reads
     * as.
     *
     * @return array{lines: list<string>, trailingNewline: bool}
     */
    public static function needle(string $old): array
    {
        $trailing = str_ends_with($old, "\n") && $old !== "\n";
        $lines = explode("\n", $trailing ? substr($old, 0, -1) : $old);

        return ['lines' => $lines, 'trailingNewline' => $trailing];
    }
}
