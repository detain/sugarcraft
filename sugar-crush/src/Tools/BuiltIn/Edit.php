<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\PathJail;

final readonly class Edit implements Tool
{
    private const DEFAULT_MAX_BYTES = 1024 * 1024;

    /** Lines of unchanged context shown around each hunk, matching `diff -u`'s default. */
    private const CONTEXT_LINES = 3;

    /**
     * Guard against the O(n*m) LCS table on a huge scattered `replace_all`
     * edit (common-prefix/suffix trimming only shrinks the middle region
     * for a *localized* change) - past this many cells we fall back to a
     * non-minimal but still-valid delete-all/insert-all hunk instead of
     * hanging the request.
     */
    private const MAX_LCS_CELLS = 250_000;

    /**
     * $skillNudge turns a skill's `paths:` frontmatter into a live signal
     * (crush_feat.md section 7 E4): editing a file a skill scopes itself to
     * announces that skill once. Null keeps the tool standalone.
     */
    public function __construct(
        private ?string $root = null,
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
        private ?AgentPathJail $worktreeJail = null,
        private ?InstructionFileLoader $instructionLoader = null,
        private array $sessionCache = [],
        private ?SkillPathNudge $skillNudge = null,
    ) {}

    public function name(): string
    {
        return 'Edit';
    }
    public function description(): string
    {
        return 'Edit a file by replacing text';
    }
    public function inputSchema(): array
    {
        return [
        'type' => 'object',
        'properties' => [
            'file_path' => ['type' => 'string', 'description' => 'Path to file to edit'],
            'old_string' => ['type' => 'string', 'description' => 'The text to replace'],
            'new_string' => ['type' => 'string', 'description' => 'The replacement text'],
            // `boolean`, not `bool`: JSON Schema has no `bool` type, and a
            // guided-decoding backend (SGLang outlines/xgrammar) can reject
            // or mis-constrain a field whose declared type it cannot resolve.
            'replace_all' => ['type' => 'boolean', 'description' => 'Replace all occurrences'],
            'description' => [
                'type' => 'string',
                'description' => 'Clear, concise 5-10 word description in active voice of what this edit does (e.g. "Rename the legacy config helper", not "edits a file").',
            ],
        ],
        'required' => ['file_path', 'old_string', 'new_string', 'description'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $path = $args['file_path'] ?? '';
        $oldString = $args['old_string'] ?? '';
        $newString = $args['new_string'] ?? '';
        $replaceAll = $args['replace_all'] ?? false;

        if ($oldString === '') {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: old_string cannot be empty',
                isError: true,
            );
        }

        if ($this->worktreeJail !== null) {
            $path = $this->worktreeJail->jailPath($path);
            if (!$this->worktreeJail->isAllowed($path)) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: 'Error: path outside worktree',
                    isError: true,
                );
            }
        } elseif ($this->root !== null) {
            $resolved = PathJail::resolve($this->root, $path);
            if ($resolved === null) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: 'Error: path outside workspace root',
                    isError: true,
                );
            }
            $path = $resolved;
        }

        if (!file_exists($path)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error: file not found: $path",
                isError: true,
            );
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error reading file: $path",
                isError: true,
            );
        }

        // Snapshot the real on-disk bytes; this is what old_string is matched
        // against, what new_string is applied to, and what gets written back.
        // The nested-instruction content loaded below is informational only
        // (surfaced to the model in the result message) and must never be
        // mixed into the content that is searched, replaced, or persisted --
        // doing so previously corrupted the on-disk file by permanently
        // prepending the instruction file's text into it.
        $originalContent = $content;

        $nestedContent = $this->instructionLoader?->loadForPath($path);

        $count = substr_count($originalContent, $oldString);
        if ($count > 1 && !$replaceAll) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error: old_string is not unique ($count matches); include more context",
                isError: true,
            );
        }

        // A zero-match edit is a FAILED edit, not a silent no-op: str_replace()
        // would happily rewrite the file with byte-identical content and we'd
        // report "File updated", telling the model its edit landed when it
        // did not. Bail out before touching the file so the model sees the
        // real outcome and can retry with a correct old_string.
        if ($count === 0) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error: old_string not found in $path; file left unchanged",
                isError: true,
            );
        }

        $newContent = str_replace($oldString, $newString, $originalContent);
        $result = file_put_contents($path, $newContent);

        if ($result === false) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error writing file: $path",
                isError: true,
            );
        }

        // Mirrors opencode's and Claude Code's Edit tool: the transcript always
        // shows a real before/after diff, never just a bare confirmation string.
        // It rides its own ToolResult field rather than being concatenated into
        // the summary, so a renderer can hand it straight to DiffViewer.
        $diff = $newContent !== $originalContent
            ? self::unifiedDiff($path, $originalContent, $newContent)
            : '';

        $message = "File updated: $path";
        if ($nestedContent !== null) {
            $message = $nestedContent . "\n\n" . $message;
        }

        // Fired only after the write landed -- a rejected or failed edit did
        // not actually touch the path, so it must not burn the one-shot nudge.
        $nudge = $this->skillNudge?->forPath($path);
        if ($nudge !== null) {
            $message .= "\n\n" . $nudge;
        }

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: $message,
            isError: false,
            diff: $diff === '' ? null : $diff,
        );
    }

    /**
     * Build a `diff -u`/`git diff --no-color`-compatible unified diff of an
     * edit. `execute()` puts this on {@see ToolResult::diff()} verbatim -- no
     * prefix, no surrounding prose -- so a renderer can pass it straight to
     * `sugar-stash\DiffViewer::fromRawDiff()` without string-scanning
     * `content()` for a `--- a/` line.
     */
    private static function unifiedDiff(string $path, string $oldContent, string $newContent): string
    {
        $ops = self::diffLines(self::splitLines($oldContent), self::splitLines($newContent));
        $hunks = self::buildHunks($ops);

        if ($hunks === '') {
            return '';
        }

        return "--- a/{$path}\n+++ b/{$path}\n" . $hunks;
    }

    /**
     * Split text into lines the way `git diff` counts them: a trailing
     * newline is the line terminator, not an extra empty final line.
     *
     * @return list<string>
     */
    private static function splitLines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $lines = explode("\n", $text);
        if (end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * Line-level diff via common-prefix/common-suffix trimming (cheap, and
     * exact for the overwhelming majority of Edit calls, which touch one
     * localized region) followed by an LCS-based diff of whatever's left in
     * the middle.
     *
     * @param list<string> $old
     * @param list<string> $new
     * @return list<array{0:'eq'|'del'|'ins',1:string}>
     */
    private static function diffLines(array $old, array $new): array
    {
        $oldCount = count($old);
        $newCount = count($new);

        $prefix = 0;
        while ($prefix < $oldCount && $prefix < $newCount && $old[$prefix] === $new[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        $maxSuffix = min($oldCount - $prefix, $newCount - $prefix);
        while (
            $suffix < $maxSuffix
            && $old[$oldCount - 1 - $suffix] === $new[$newCount - 1 - $suffix]
        ) {
            $suffix++;
        }

        $oldMid = array_slice($old, $prefix, $oldCount - $prefix - $suffix);
        $newMid = array_slice($new, $prefix, $newCount - $prefix - $suffix);

        $ops = [];
        for ($i = 0; $i < $prefix; $i++) {
            $ops[] = ['eq', $old[$i]];
        }

        if (count($oldMid) * count($newMid) > self::MAX_LCS_CELLS) {
            // Pathological case (e.g. replace_all scattering changes across
            // a huge file): skip the O(n*m) table and emit a correct, if
            // non-minimal, delete-all/insert-all block for the middle.
            foreach ($oldMid as $line) {
                $ops[] = ['del', $line];
            }
            foreach ($newMid as $line) {
                $ops[] = ['ins', $line];
            }
        } else {
            array_push($ops, ...self::lcsOps($oldMid, $newMid));
        }

        for ($i = 0; $i < $suffix; $i++) {
            $ops[] = ['eq', $old[$oldCount - $suffix + $i]];
        }

        return $ops;
    }

    /**
     * Classic O(n*m) longest-common-subsequence backtrack, turned into a
     * sequence of equal/delete/insert ops.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{0:'eq'|'del'|'ins',1:string}>
     */
    private static function lcsOps(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 && $m === 0) {
            return [];
        }

        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $a[$i] === $b[$j]
                    ? $dp[$i + 1][$j + 1] + 1
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }

        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = ['eq', $a[$i]];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $ops[] = ['del', $a[$i]];
                $i++;
            } else {
                $ops[] = ['ins', $b[$j]];
                $j++;
            }
        }
        while ($i < $n) {
            $ops[] = ['del', $a[$i]];
            $i++;
        }
        while ($j < $m) {
            $ops[] = ['ins', $b[$j]];
            $j++;
        }

        return $ops;
    }

    /**
     * Group diff ops into `@@ -oldStart,oldLen +newStart,newLen @@` hunks,
     * merging two changed regions into one hunk whenever their context
     * windows would touch or overlap -- i.e. whenever they are separated by
     * no more than 2*CONTEXT_LINES + 1 index positions -- matching `diff
     * -u`'s hunk-joining behaviour.
     *
     * @param list<array{0:'eq'|'del'|'ins',1:string}> $ops
     */
    private static function buildHunks(array $ops): string
    {
        $n = count($ops);
        $oldLine = 1;
        $newLine = 1;
        $annotated = [];
        $changedIdx = [];
        foreach ($ops as $idx => $op) {
            [$type] = $op;
            $annotated[$idx] = [$op[0], $op[1], $oldLine, $newLine];
            if ($type === 'eq') {
                $oldLine++;
                $newLine++;
            } elseif ($type === 'del') {
                $oldLine++;
                $changedIdx[] = $idx;
            } else {
                $newLine++;
                $changedIdx[] = $idx;
            }
        }

        if ($changedIdx === []) {
            return '';
        }

        $groups = [];
        $groupStart = $changedIdx[0];
        $groupEnd = $changedIdx[0];
        for ($k = 1, $count = count($changedIdx); $k < $count; $k++) {
            if ($changedIdx[$k] - $groupEnd <= self::CONTEXT_LINES * 2 + 1) {
                $groupEnd = $changedIdx[$k];
            } else {
                $groups[] = [$groupStart, $groupEnd];
                $groupStart = $changedIdx[$k];
                $groupEnd = $changedIdx[$k];
            }
        }
        $groups[] = [$groupStart, $groupEnd];

        $out = '';
        foreach ($groups as [$groupStart, $groupEnd]) {
            $start = max(0, $groupStart - self::CONTEXT_LINES);
            $end = min($n - 1, $groupEnd + self::CONTEXT_LINES);

            $oldStart = $annotated[$start][2];
            $newStart = $annotated[$start][3];
            $oldLen = 0;
            $newLen = 0;
            $body = '';
            for ($idx = $start; $idx <= $end; $idx++) {
                [$type, $line] = $annotated[$idx];
                if ($type === 'eq') {
                    $body .= " {$line}\n";
                    $oldLen++;
                    $newLen++;
                } elseif ($type === 'del') {
                    $body .= "-{$line}\n";
                    $oldLen++;
                } else {
                    $body .= "+{$line}\n";
                    $newLen++;
                }
            }

            // `diff -u` reports a start line of 0 when a hunk's old- or
            // new-side is empty (pure insertion/deletion at a boundary),
            // since there's no real line number to anchor an empty range to.
            $headerOldStart = $oldLen === 0 ? 0 : $oldStart;
            $headerNewStart = $newLen === 0 ? 0 : $newStart;

            $out .= "@@ -{$headerOldStart},{$oldLen} +{$headerNewStart},{$newLen} @@\n{$body}";
        }

        return $out;
    }
}
