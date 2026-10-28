<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workspace;

use SugarCraft\Diff\Reader\ScannedLine;
use SugarCraft\Diff\Reader\UnifiedScan;

/**
 * What changed in the project's files since a workspace checkpoint (item
 * 3.A-2, `/diff [n]`): the changed paths and a unified patch, checkpoint on
 * the left, the files as they are now on the right.
 *
 * {@see between()} answers the same shape between TWO snapshots — checkpoint
 * k against checkpoint k−1, the per-turn view the diff review shows
 * (C-TUI-2.d, GAP-GIT-09) — and {@see files()} carves the patch into per-file
 * rows with ±counts, so a viewer can list files and paint one body without
 * re-reading git.
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

        return self::fromTrees(...$trees);
    }

    /**
     * The diff between TWO snapshots — what changed across the turn that ends
     * at $newer, the per-turn view the diff review cycles through
     * (C-TUI-2.d, GAP-GIT-09). Neither side is the work tree, so the answer
     * cannot drift while it is being read.
     *
     * Returns the reason there is none to give: a side whose snapshot was
     * refused or lost, or two snapshots that do not live in the same
     * repository — tree ids only mean something inside the object store that
     * holds them, and one checkpointer happily builds runners from whatever
     * root each workspace names.
     *
     * @param array<string, mixed> $older the checkpoint on the left
     * @param array<string, mixed> $newer the checkpoint on the right
     */
    public static function between(WorkspaceCheckpointer $checkpointer, array $older, array $newer): self|string
    {
        $left = $checkpointer->trees($older);
        if (\is_string($left)) {
            return $left;
        }
        $right = $checkpointer->trees($newer);
        if (\is_string($right)) {
            return $right;
        }
        if ($left[0]->gitDir() !== $right[0]->gitDir()) {
            return 'the two checkpoints do not share a repository';
        }

        return self::fromTrees($left[0], $left[1], $right[1]);
    }

    /**
     * @return self|string the diff, or why git could not answer
     */
    private static function fromTrees(GitRunner $git, string $left, string $right): self|string
    {
        if ($left === $right) {
            return new self([], '', 0);
        }

        $changes = WorkspaceCheckpointer::diffTrees($git, $left, $right);
        if ($changes === null) {
            return 'git diff-tree failed';
        }
        $patch = $git->run('diff', '--no-color', '--no-ext-diff', '--no-textconv', '--no-renames', $left, $right);
        if (!$patch['ok']) {
            return $patch['timedOut'] ? 'git diff timed out' : 'git diff failed';
        }

        [$text, $omitted] = self::bounded(self::screenSafe($patch['stdout']));

        return new self($changes, $text, $omitted);
    }

    /**
     * One row per changed file: the status git's own name-list reported, the
     * raw path, added/removed line counts, and this file's section of the
     * (screen-safe, bounded) patch — so a viewer lists files and paints one
     * body without re-reading git (C-TUI-2.d).
     *
     * The counts ignore `+++`/`---` headers, the "Binary files … differ"
     * notice, and any row {@see UnifiedScan} leaves unnumbered (hunks past its
     * digit bound): a row is counted only when it sits inside a numbered hunk.
     *
     * The patch is bounded BEFORE this is carved, so a file the cap cut off
     * keeps its git-reported row with zero counts and an empty section, while
     * {@see $omittedLines} says the bodies were shortened. Joining by raw path
     * is exact: the runner pins `core.quotepath=false` and `--no-renames`, so
     * diff and diff-tree -z name every file the same byte-for-byte way.
     *
     * @return list<array{status: string, path: string, added: int, removed: int, patch: string}>
     */
    public function files(): array
    {
        $statusByPath = [];
        foreach ($this->changes as [$status, $path]) {
            $statusByPath[$path] = $status;
        }

        $rows = $this->patch === '' ? [] : explode("\n", $this->patch);
        $scanned = UnifiedScan::lines($rows);
        $seen = [];
        $files = [];

        $count = \count($rows);
        $i = 0;
        while ($i < $count) {
            if (!str_starts_with($rows[$i], 'diff --git ')) {
                ++$i;
                continue;
            }
            $start = $i;
            do {
                ++$i;
            } while ($i < $count && !str_starts_with($rows[$i], 'diff --git '));

            $path = self::sectionPath($rows, $scanned, $start, $i);
            if ($path === null || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;

            $added = 0;
            $removed = 0;
            for ($j = $start; $j < $i; ++$j) {
                if (str_starts_with($rows[$j], '+') && $scanned[$j]->new !== null) {
                    ++$added;
                } elseif (str_starts_with($rows[$j], '-') && $scanned[$j]->old !== null) {
                    ++$removed;
                }
            }

            $files[] = [
                'status' => $statusByPath[$path] ?? 'M',
                'path' => $path,
                'added' => $added,
                'removed' => $removed,
                'patch' => implode("\n", \array_slice($rows, $start, $i - $start)),
            ];
        }

        foreach ($this->changes as [$status, $path]) {
            if (isset($seen[$path])) {
                continue;
            }
            $files[] = ['status' => $status, 'path' => $path, 'added' => 0, 'removed' => 0, 'patch' => ''];
        }

        return $files;
    }

    /**
     * Which path a patch section touches. A `--- a/…` header wins over a
     * `+++ b/…` one, because for a deletion the `+++` side is /dev/null and
     * only `---` carries the path; the reverse holds for a creation, where
     * `---` is /dev/null and `+++` names the file. A binary section carries
     * neither header — then, and only then, the `diff --git a/x b/x` row is
     * asked, and only when its two candidate paths are equal, so a spaced or
     * exotic path is never guessed apart.
     *
     * @param list<string> $rows
     * @param list<ScannedLine> $scanned
     */
    private static function sectionPath(array $rows, array $scanned, int $start, int $end): ?string
    {
        $addedSide = null;
        for ($i = $start; $i < $end; ++$i) {
            if (!$scanned[$i]->fileHeader) {
                continue;
            }
            if (str_starts_with($rows[$i], '--- a/')) {
                return substr($rows[$i], 6);
            }
            if ($addedSide === null && str_starts_with($rows[$i], '+++ b/')) {
                $addedSide = substr($rows[$i], 6);
            }
        }
        if ($addedSide !== null) {
            return $addedSide;
        }

        return preg_match('#^diff --git a/(.*?) b/(.*)$#', $rows[$start], $m) === 1 && $m[1] === $m[2]
            ? $m[1]
            : null;
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
