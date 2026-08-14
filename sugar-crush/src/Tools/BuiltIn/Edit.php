<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Tools\Concerns\BuildsUnifiedDiff;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\PathJail;

final readonly class Edit implements Tool
{
    // The unified-diff builder below used to live here; it moved out verbatim
    // so {@see Write} could produce the SAME diff for a new file (a write whose
    // "before" side is empty) instead of growing a second implementation.
    use BuildsUnifiedDiff;

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

}
