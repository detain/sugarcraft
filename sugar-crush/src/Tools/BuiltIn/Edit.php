<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Tools\Concerns\BuildsUnifiedDiff;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\PathJail;

final readonly class Edit implements Tool
{
    // The unified-diff builder below used to live here; it moved out verbatim
    // so {@see Write} could produce the SAME diff for a new file (a write whose
    // "before" side is empty) instead of growing a second implementation.
    use BuildsUnifiedDiff;
    use TruncatesOutput;

    private const DEFAULT_MAX_BYTES = 1024 * 1024;

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
    /**
     * States the match contract up front, because every clause of it is
     * otherwise learned from an error string after a wasted turn: the
     * uniqueness rejection, the zero-match rejection and the
     * must-already-exist requirement all live in {@see execute()} and each one
     * leaves the file untouched.
     *
     * The result clause describes what the MODEL receives, which is
     * {@see ToolResult::content()} and nothing else. An earlier draft promised
     * "a unified diff of what changed"; the diff is real but rides
     * {@see ToolResult::$diff}, a field only the renderer and the event stream
     * read -- `Runtime::settle()` builds the model's message from `content()`
     * alone. So the honest clause is the line tally {@see changeSummary()}
     * puts in `content()`, plus the fact that the new text is not echoed back.
     */
    public function description(): string
    {
        return 'Edit a file by replacing text: one exact, unique occurrence of old_string '
            . 'becomes new_string. Read the file first — old_string must match the bytes on '
            . 'disk exactly, indentation and line endings included. Matching more than once '
            . 'is rejected unless replace_all is set, and matching zero times is rejected '
            . 'too; either way the file is left untouched, never partially edited. The file '
            . 'must already exist — use Write to create one. On success the result names the '
            . 'path and counts the lines added and removed, as "(+2 -1 lines)"; it does not '
            . 'echo the new file contents back, so Read the file again if you need to see '
            . 'the edit in context.';
    }
    public function inputSchema(): array
    {
        return [
        'type' => 'object',
        'properties' => [
            'file_path' => ['type' => 'string', 'description' => 'Path to the file to edit. It must already exist; use Write to create a new file.'],
            'old_string' => ['type' => 'string', 'description' => 'The text to replace, matched byte-for-byte against the file on disk. Must occur exactly once unless replace_all is set.'],
            'new_string' => ['type' => 'string', 'description' => 'The replacement text. May be empty to delete old_string.'],
            // `boolean`, not `bool`: JSON Schema has no `bool` type, and a
            // guided-decoding backend (SGLang outlines/xgrammar) can reject
            // or mis-constrain a field whose declared type it cannot resolve.
            'replace_all' => ['type' => 'boolean', 'description' => 'Replace every occurrence instead of requiring old_string to be unique.'],
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

        if (str_contains($path, "\0")) {
            // realpath() throws a ValueError on a NUL byte instead of failing,
            // so without this the crash escaped execute() rather than coming
            // back as a tool error. Same guard as Read/Write.
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: file_path contains a NUL byte',
                isError: true,
            );
        }

        if ($this->worktreeJail !== null) {
            // resolve() once, and read/write the CANONICAL path it returns.
            // jailPath()+isAllowed() proved containment on the realpath() of
            // $path and then re-opened the unresolved $path below, so a
            // symlink component swapped between the check and the open landed
            // outside the jail (crush_code.md P8.14/15). The file_exists()
            // clause is isAllowed()'s existence-strictness kept verbatim, so
            // a missing file still reports 'path outside worktree' here
            // exactly as it did before, not the 'file not found' below.
            $resolved = $this->worktreeJail->resolve($path);
            if ($resolved === null || !file_exists($resolved)) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: 'Error: path outside worktree',
                    isError: true,
                );
            }
            $path = $resolved;
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

        $message = "File updated: $path" . self::changeSummary($diff);
        // Bounded by the standalone default rather than by a fraction of a
        // cap, because this tool has no output cap to take a fraction OF: its
        // result is one line ("File updated: <path>"), so nothing here was
        // ever going to need one. The instruction body was the whole of the
        // unbounded term — a `CLAUDE.md` of any size, prepended verbatim into
        // every edit result for a governed path, replayed into every
        // following request of the turn.
        if ($nestedContent !== null) {
            $message = $this->clipInstructions($nestedContent, self::DEFAULT_MAX_INSTRUCTION_BYTES)
                . "\n\n" . $message;
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
     * ` (+2 -1 lines)` for a real change, `''` for a write that changed nothing.
     *
     * The model is handed `content()` and nothing else, so without this the
     * only report of an edit's effect is the word "updated" -- which is the
     * same string a 1-character typo fix and a 400-line replacement produce.
     * A tally is what lets the model notice its `old_string` matched somewhere
     * far bigger than it meant, without spending a turn re-Reading the file.
     *
     * Counted off the diff rather than off the two file contents so it is the
     * SAME change the renderer shows from {@see ToolResult::$diff}; deriving it
     * independently is how the summary and the diff come to disagree. The
     * `+++`/`---` guards are needed because the header lines are themselves
     * `+`- and `-`-prefixed.
     */
    private static function changeSummary(string $diff): string
    {
        if ($diff === '') {
            return '';
        }

        $added = 0;
        $removed = 0;
        foreach (explode("\n", $diff) as $line) {
            if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
                continue;
            }
            if (str_starts_with($line, '+')) {
                $added++;
            } elseif (str_starts_with($line, '-')) {
                $removed++;
            }
        }

        return sprintf(' (+%d -%d lines)', $added, $removed);
    }
}
