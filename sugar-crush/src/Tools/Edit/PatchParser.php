<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Edit;

/**
 * Reads the patch `ApplyPatch` takes (roadmap 3.I-3): the `*** Begin Patch`
 * envelope opencode and Codex give GPT-family models, so a model trained on it
 * can change several files in one call.
 *
 *     *** Begin Patch
 *     *** Add File: src/New.php
 *     +<?php
 *     *** Update File: src/Cart.php
 *     *** Move to: src/Basket.php
 *     @@ function total
 *          $sum = 0;
 *     -    return $sum;
 *     +    return round($sum, 2);
 *     *** Delete File: src/Old.php
 *     *** End Patch
 *
 * In an Update section, a line starting `@@` opens a hunk and may name an
 * anchor (`@@ function total`) — a line the hunk sits after; several `@@`
 * lines in a row narrow step by step. A hunk's body lines start with a space
 * (context), `-` (removed) or `+` (added); an empty line is an empty context
 * line, since editors strip the space. `*** End of File` after a hunk pins it
 * to the file's end. The first hunk may leave out its `@@` line. A unified
 * diff hunk header (`@@ -12,7 +12,8 @@ fn`) is accepted, its numbers ignored:
 * places are found by content, never by line number.
 *
 * Deliberately strict where a guess would edit the wrong thing: an unknown
 * `***` line, a body line with no prefix, a hunk that changes nothing, two
 * sections naming one path and an empty patch are all refused with the line
 * number, and a refusal means nothing is applied. Lenient only where intent is
 * plain: CRLF line endings, blank lines between sections, trailing blank lines
 * at the end of a section, and a shell heredoc wrapper (`<<'EOF'` … `EOF`,
 * optionally after `apply_patch`) that a model copied from its training.
 */
final class PatchParser
{
    public const BEGIN = '*** Begin Patch';
    public const END = '*** End Patch';
    public const ADD = '*** Add File: ';
    public const UPDATE = '*** Update File: ';
    public const DELETE = '*** Delete File: ';
    public const MOVE = '*** Move to: ';
    public const END_OF_FILE = '*** End of File';

    /** @var list<string> */
    private array $lines = [];

    /** 1-based patch line of $lines[0], for error messages. */
    private int $lineOffset = 1;

    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * The operations $patch describes, in order.
     *
     * @return list<PatchOperation>
     * @throws \InvalidArgumentException naming the line and what is wrong
     *         with it; the message is written for the model to act on
     */
    public function parse(string $patch): array
    {
        [$this->lines, $this->lineOffset] = self::envelope($patch);

        $operations = [];
        $seen = [];
        $i = 0;
        $n = \count($this->lines);
        while ($i < $n) {
            $line = $this->lines[$i];
            if (trim($line) === '') {
                $i++;
                continue;
            }

            if (str_starts_with($line, self::ADD)) {
                $path = $this->path($line, self::ADD, $i);
                [$operation, $i] = $this->addSection($path, $i + 1);
            } elseif (str_starts_with($line, self::DELETE)) {
                $path = $this->path($line, self::DELETE, $i);
                $operation = new PatchOperation(PatchAction::Delete, $path);
                $i++;
            } elseif (str_starts_with($line, self::UPDATE)) {
                $path = $this->path($line, self::UPDATE, $i);
                [$operation, $i] = $this->updateSection($path, $i + 1);
            } else {
                throw $this->error($i, 'expected "*** Add File: ", "*** Update File: " or "*** Delete File: ", got ' . self::quote($line));
            }

            foreach ($operation->paths() as $named) {
                $key = self::pathKey($named);
                if (isset($seen[$key])) {
                    throw new \InvalidArgumentException(sprintf(
                        'the patch names %s in two sections; put every change to one file in one section',
                        $named,
                    ));
                }
                $seen[$key] = true;
            }
            $operations[] = $operation;
        }

        if ($operations === []) {
            throw new \InvalidArgumentException('the patch has no file sections between "' . self::BEGIN . '" and "' . self::END . '"');
        }

        return $operations;
    }

    /**
     * Every path $patch writes or removes, move destinations included, or null
     * when it does not parse. For callers that must judge a patch before the
     * tool runs (the permission gate, `protect-files`): a null is a patch the
     * tool will refuse, and such a caller fails closed on it.
     *
     * @return list<string>|null
     */
    public static function paths(mixed $patch): ?array
    {
        if (!\is_string($patch)) {
            return null;
        }
        try {
            $operations = self::new()->parse($patch);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $paths = [];
        foreach ($operations as $operation) {
            array_push($paths, ...$operation->paths());
        }

        return $paths;
    }

    /**
     * The lines between the Begin and End markers, and the 1-based patch line
     * the first of them is.
     *
     * @return array{0: list<string>, 1: int}
     */
    private static function envelope(string $patch): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $patch));
        $first = 1;

        // Outer blank lines carry nothing.
        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
            $first++;
        }
        while ($lines !== [] && trim($lines[\count($lines) - 1]) === '') {
            array_pop($lines);
        }

        // A heredoc wrapper, as the shell form of the same tool is written.
        if ($lines !== [] && preg_match('/^(?:apply_patch\s+)?<<-?\s*([\'"]?)(\w+)\1\s*$/', trim($lines[0]), $m) === 1
            && \count($lines) >= 2 && trim($lines[\count($lines) - 1]) === $m[2]) {
            $lines = \array_slice($lines, 1, -1);
            $first++;
        }

        if ($lines === [] || trim($lines[0]) !== self::BEGIN) {
            throw new \InvalidArgumentException('a patch must start with the line "' . self::BEGIN . '"');
        }
        if (\count($lines) < 2 || trim($lines[\count($lines) - 1]) !== self::END) {
            throw new \InvalidArgumentException('a patch must end with the line "' . self::END . '"');
        }

        return [\array_slice($lines, 1, -1), $first + 1];
    }

    /**
     * @return array{0: PatchOperation, 1: int} the operation and the index after it
     */
    private function addSection(string $path, int $i): array
    {
        $body = [];
        $n = \count($this->lines);
        while ($i < $n && !self::isSectionHeader($this->lines[$i])) {
            $line = $this->lines[$i];
            if ($line === '' && $this->onlyBlankUntilSection($i)) {
                $i = $this->nextSection($i);
                break;
            }
            if (!str_starts_with($line, '+')) {
                throw $this->error($i, 'every line of an "' . trim(self::ADD) . '" section starts with "+", got ' . self::quote($line));
            }
            $body[] = substr($line, 1);
            $i++;
        }

        return [new PatchOperation(PatchAction::Add, $path, $body === [] ? '' : implode("\n", $body) . "\n"), $i];
    }

    /**
     * @return array{0: PatchOperation, 1: int} the operation and the index after it
     */
    private function updateSection(string $path, int $i): array
    {
        $n = \count($this->lines);
        $moveTo = null;
        if ($i < $n && str_starts_with($this->lines[$i], self::MOVE)) {
            $moveTo = $this->path($this->lines[$i], self::MOVE, $i);
            $i++;
        }

        $hunks = [];
        $anchors = [];
        $old = [];
        $new = [];
        $changes = 0;
        $start = $i;
        $flush = function (bool $endOfFile, int $at) use (&$hunks, &$anchors, &$old, &$new, &$changes, &$start): void {
            if ($old === [] && $new === [] && $anchors === []) {
                return;
            }
            if ($changes === 0) {
                throw $this->error($start, 'this hunk has no "-" or "+" line, so it changes nothing');
            }
            $hunks[] = new PatchHunk($anchors, $old, $new, $endOfFile);
            $anchors = [];
            $old = [];
            $new = [];
            $changes = 0;
            $start = $at;
        };

        while ($i < $n && !self::isSectionHeader($this->lines[$i])) {
            $line = $this->lines[$i];

            if (str_starts_with($line, '@@')) {
                if ($old !== [] || $new !== []) {
                    $flush(false, $i);
                }
                if ($anchors === []) {
                    $start = $i;
                }
                $anchor = self::anchor($line);
                if ($anchor !== '') {
                    $anchors[] = $anchor;
                }
            } elseif (trim($line) === self::END_OF_FILE) {
                if ($old === [] && $new === []) {
                    throw $this->error($i, '"' . self::END_OF_FILE . '" must follow a hunk');
                }
                $flush(true, $i + 1);
            } elseif ($line === '') {
                if ($this->onlyBlankUntilSection($i)) {
                    $i = $this->nextSection($i);
                    break;
                }
                $old[] = '';
                $new[] = '';
            } else {
                $body = substr($line, 1);
                if ($line[0] === ' ') {
                    $old[] = $body;
                    $new[] = $body;
                } elseif ($line[0] === '-') {
                    $old[] = $body;
                    ++$changes;
                } elseif ($line[0] === '+') {
                    $new[] = $body;
                    ++$changes;
                } else {
                    throw $this->error($i, 'a hunk line starts with " " (context), "-" (removed) or "+" (added), got ' . self::quote($line));
                }
            }
            $i++;
        }
        $flush(false, $i);

        if ($hunks === [] && $moveTo === null) {
            throw new \InvalidArgumentException(sprintf('the "%s%s" section has no hunks', self::UPDATE, $path));
        }

        return [new PatchOperation(PatchAction::Update, $path, hunks: $hunks, moveTo: $moveTo), $i];
    }

    /** The anchor text of an `@@` line, '' for none. */
    private static function anchor(string $line): string
    {
        // A unified-diff header: the line numbers are ignored, the trailing
        // function context (if any) is the anchor.
        if (preg_match('/^@@ -\d+(?:,\d+)? \+\d+(?:,\d+)? @@(.*)$/', $line, $m) === 1) {
            return trim($m[1]);
        }

        $text = trim(substr($line, 2));
        if (str_ends_with($text, '@@')) {
            $text = trim(substr($text, 0, -2));
        }

        return $text;
    }

    private function path(string $line, string $prefix, int $i): string
    {
        $path = trim(substr($line, \strlen($prefix)));
        if ($path === '') {
            throw $this->error($i, 'the path after "' . trim($prefix) . '" is empty');
        }
        if (str_contains($path, "\0")) {
            throw $this->error($i, 'the path contains a NUL byte');
        }

        return $path;
    }

    private static function isSectionHeader(string $line): bool
    {
        return str_starts_with($line, self::ADD)
            || str_starts_with($line, self::UPDATE)
            || str_starts_with($line, self::DELETE);
    }

    private function onlyBlankUntilSection(int $i): bool
    {
        return $this->nextSection($i) === $this->firstNonBlank($i);
    }

    /** Index of the next section header at or after $i, or the line count. */
    private function nextSection(int $i): int
    {
        $n = \count($this->lines);
        while ($i < $n && !self::isSectionHeader($this->lines[$i])) {
            $i++;
        }

        return $i;
    }

    private function firstNonBlank(int $i): int
    {
        $n = \count($this->lines);
        while ($i < $n && trim($this->lines[$i]) === '') {
            $i++;
        }

        return $i;
    }

    /** One spelling per file, so `./a.php` and `a.php` are one path. */
    private static function pathKey(string $path): string
    {
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return $path;
    }

    private static function quote(string $line): string
    {
        $clipped = \strlen($line) > 80 ? mb_strcut($line, 0, 80, 'UTF-8') . '…' : $line;

        return '"' . $clipped . '"';
    }

    private function error(int $i, string $what): \InvalidArgumentException
    {
        return new \InvalidArgumentException(sprintf('patch line %d: %s', $i + $this->lineOffset, $what));
    }
}
