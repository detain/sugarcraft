<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workspace;

/**
 * What changed in the project's files since a workspace checkpoint (item
 * 3.A-2, `/diff [n]`): the changed paths and a unified patch, checkpoint on
 * the left, the files as they are now on the right.
 *
 * "Now" is measured the way a snapshot would record it ({@see
 * WorkspaceCheckpointer::trees()}): tracked files as they are in the work
 * tree, plus the untracked files a snapshot keeps. What a snapshot leaves out
 * — ignored and excluded files, untracked files over the size limit — is left
 * out of the diff on both sides, so the diff is exactly what a restore of that
 * checkpoint would undo, never more.
 *
 * THE PATCH IS BOUNDED AND MADE SAFE FOR THE SCREEN: at most {@see MAX_LINES}
 * lines and {@see MAX_BYTES} bytes (opencode caps its snapshot diff at
 * 256 KiB), invalid UTF-8 replaced, and every control character but tab
 * replaced, so a binary or an escape sequence inside a changed file can
 * neither corrupt the frame nor run a terminal command. Binary files are
 * reported by git as "Binary files … differ", not dumped.
 */
final class CheckpointDiff
{
    /** Patch lines kept; the rest are counted in {@see $omittedLines}. */
    public const MAX_LINES = 2000;

    /** Patch bytes kept, whatever the line count. */
    public const MAX_BYTES = 256 * 1024;

    /**
     * @param list<array{0: string, 1: string}> $changes `[status, path]`:
     *        A created since the checkpoint, D deleted since, M/T changed
     */
    private function __construct(
        public readonly array $changes,
        public readonly string $patch,
        public readonly int $omittedLines,
    ) {}

    /**
     * The diff of the files now against the snapshot $workspace recorded, or
     * the reason there is none to give (no snapshot was taken, the repository
     * is gone, the directory is refused, git failed).
     *
     * @param array<string, mixed> $workspace a captured shape from {@see WorkspaceCheckpointer::capture()}
     */
    public static function of(WorkspaceCheckpointer $checkpointer, array $workspace): self|string
    {
        $trees = $checkpointer->trees($workspace);
        if (\is_string($trees)) {
            return $trees;
        }
        [$git, $target, $current] = $trees;
        if ($target === $current) {
            return new self([], '', 0);
        }

        $changes = WorkspaceCheckpointer::diffTrees($git, $target, $current);
        if ($changes === null) {
            return 'git diff-tree failed';
        }
        $patch = $git->run('diff', '--no-color', '--no-ext-diff', '--no-textconv', '--no-renames', $target, $current);
        if (!$patch['ok']) {
            return $patch['timedOut'] ? 'git diff timed out' : 'git diff failed';
        }

        [$text, $omitted] = self::bounded(self::screenSafe($patch['stdout']));

        return new self($changes, $text, $omitted);
    }

    /** Whether the files match the checkpoint. */
    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /**
     * One line per changed path, `M path` style, in git's order — the same
     * screen-safe treatment as the patch, since a path is file-system text.
     *
     * @return list<string>
     */
    public function summaryLines(): array
    {
        return array_map(
            static fn (array $change): string => $change[0] . ' ' . self::screenSafe($change[1]),
            $this->changes,
        );
    }

    /**
     * $text with invalid UTF-8 replaced and every C0/C1 control character
     * but tab and newline replaced by U+FFFD.
     */
    private static function screenSafe(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = str_replace("\r\n", "\n", $text);

        return (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', "\u{FFFD}", $text);
    }

    /**
     * The first {@see MAX_LINES} lines of $text within {@see MAX_BYTES}, and
     * how many lines were cut.
     *
     * @return array{0: string, 1: int}
     */
    private static function bounded(string $text): array
    {
        $text = rtrim($text, "\n");
        if ($text === '') {
            return ['', 0];
        }
        $lines = explode("\n", $text);
        $kept = [];
        $bytes = 0;
        foreach ($lines as $line) {
            if (\count($kept) >= self::MAX_LINES || $bytes + \strlen($line) + 1 > self::MAX_BYTES) {
                break;
            }
            $kept[] = $line;
            $bytes += \strlen($line) + 1;
        }

        return [implode("\n", $kept), \count($lines) - \count($kept)];
    }
}
