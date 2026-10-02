<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Context\RulePathNudge;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Support\AtomicFileWriter;
use SugarCraft\Crush\Tools\Concerns\BuildsUnifiedDiff;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;
use SugarCraft\Crush\Tools\AcceptsWorktreeJail;
use SugarCraft\Crush\Tools\Concerns\RebindsWorktreeJail;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\PathJail;

final readonly class Edit implements Tool, AcceptsWorktreeJail
{
    // The unified-diff builder below used to live here; it moved out verbatim
    // so {@see Write} could produce the SAME diff for a new file (a write whose
    // "before" side is empty) instead of growing a second implementation.
    use BuildsUnifiedDiff;
    use RebindsWorktreeJail;
    use TruncatesOutput;

    private const DEFAULT_MAX_BYTES = 1024 * 1024;

    /**
     * $skillNudge turns a skill's `paths:` frontmatter into a live signal
     * (crush_feat.md section 7 E4): editing a file a skill scopes itself to
     * announces that skill once. Null keeps the tool standalone.
     *
     * $writeSeam is a TEST SEAM only (audit F-T7): the payload writer handed
     * to {@see AtomicFileWriter::replace()}, so a test can fail a write
     * part-way and prove the original bytes survive. Production passes null.
     */
    public function __construct(
        private ?string $root = null,
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
        private ?AgentPathJail $worktreeJail = null,
        private ?InstructionFileLoader $instructionLoader = null,
        private array $sessionCache = [],
        private ?SkillPathNudge $skillNudge = null,
        private ?RulePathNudge $ruleNudge = null,
        private ?\Closure $writeSeam = null,
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

        // file_exists() is true for a FIFO, and file_get_contents() on one
        // blocks in open(2) until a writer appears -- in a turn, until the
        // SIGKILL (audit F-T4). is_file() is a stat() and follows symlinks, so
        // only things that are not a regular file land here: FIFOs, sockets,
        // devices, and directories, none of which Edit can meaningfully patch.
        if (!is_file($path)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error: not a regular file: $path",
                isError: true,
            );
        }

        // $maxBytes is enforced BEFORE the read (audit F-T2). It used to be
        // declared and never consulted, and the edit path holds the file
        // several times over (the bytes, the replaced copy, the diff), so a
        // multi-GB log or dump reached the OOM killer -- the CLI runs with
        // memory_limit=-1 -- rather than an error. filesize() names the real
        // size in the refusal; the bounded read below catches a file that grew
        // between the stat and the open.
        $size = filesize($path);
        if ($size !== false && $size > $this->maxBytes) {
            return $this->tooLarge($args, $path, $size);
        }

        // One byte past the cap is enough to tell "over" from "at" it; null
        // (no bound) only for a cap so large that +1 would overflow.
        $limit = $this->maxBytes < PHP_INT_MAX ? $this->maxBytes + 1 : null;
        $content = file_get_contents($path, false, null, 0, $limit);
        if ($content !== false && strlen($content) > $this->maxBytes) {
            return $this->tooLarge($args, $path, strlen($content));
        }
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

        // Temp-then-rename, not file_put_contents() (audit F-T7): that
        // truncated the file first, so a SIGKILL of the turn fork (Esc-Esc,
        // the completion deadline) or an ENOSPC part-way through left a 0-byte
        // or torn source file, with no undo to recover it. replace() keeps the
        // file's mode, owner where it can, symlink and hard links; see it for
        // the cases that still write in place.
        try {
            AtomicFileWriter::replace($path, $newContent, $this->writeSeam);
        } catch (\RuntimeException) {
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
        $preview = $newContent !== $originalContent
            ? self::diffPreview($path, $originalContent, $newContent)
            : ['diff' => '', 'added' => 0, 'removed' => 0, 'omitted' => false];
        $diff = $preview['diff'];

        $message = "File updated: $path"
            . ($preview['omitted'] ? self::omittedDiffNote($preview) : self::changeSummary($diff));
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
        //
        // No budget passed, so {@see \SugarCraft\Crush\Skills\SkillPathNudge::maxBytes()}
        // is the whole bound -- E66. This result has no cap of its own for the
        // nudge to be spent inside: it is a one-line success message plus a
        // flat {@see DEFAULT_MAX_INSTRUCTION_BYTES} of rules, so the nudge's
        // own constant ceiling is the analogous flat term, not a share of
        // something. Before E66 there was no term at all: one line per matching
        // auto-invocable skill, each carrying that skill's whole frontmatter
        // `description`.
        $nudge = $this->skillNudge?->forPath($path);
        if ($nudge !== null) {
            $message .= "\n\n" . $nudge;
        }

        // P6.S5b: the rule channel, appended on the same terms as the skill nudge
        // directly above and for the same reason -- a write that was rejected or
        // failed never touched the path, so it must not burn a rule's one-shot
        // mark. No budget is passed, exactly as Edit/Write pass none to the skills
        // tracker: this result has no output cap to spend a share OF, so the
        // tracker's own ceiling is the flat bound, and the shipped-budget guard in
        // RulePathScopingWiringTest is what keeps that ceiling inside what a capped
        // caller can also hold.
        $ruleNudge = $this->ruleNudge?->forPath($path);
        if ($ruleNudge !== null) {
            $message .= "\n\n" . $ruleNudge;
        }

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: $message,
            isError: false,
            diff: $diff === '' ? null : $diff,
        );
    }

    /**
     * The refusal for a file over $maxBytes. It names the cap and the file's
     * size so the model can tell "too big for Edit" from a transient failure,
     * and says the file was not touched, matching the other refusals.
     *
     * @param array<string, mixed> $args
     */
    private function tooLarge(array $args, string $path, int $size): ToolResult
    {
        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: sprintf(
                'Error: file too large to edit: %s is %s bytes, over the %s-byte Edit limit (maxBytes); file left unchanged',
                $path,
                number_format($size),
                number_format($this->maxBytes),
            ),
            isError: true,
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
