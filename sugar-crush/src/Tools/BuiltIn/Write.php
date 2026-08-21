<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Tools\Concerns\BuildsUnifiedDiff;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;
use SugarCraft\Crush\Tools\PathJail;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Create a file (crush_code.md Phase 8 item 12).
 *
 * {@see Edit} cannot do this: it requires `file_exists($path)` AND a non-empty
 * `old_string`, so the model's only route to a new file was a `Bash` heredoc —
 * which bypasses the diff preview entirely and lands in the permission gate as
 * an opaque shell command rather than a reviewable write. This tool is
 * deliberately Edit-shaped (same jail check, same error vocabulary, same
 * {@see ToolResult} fields, same {@see BuildsUnifiedDiff} machinery) so a new
 * file renders in the transcript exactly like an edit whose "before" side
 * happens to be empty.
 *
 * NOT {@see \SugarCraft\Crush\Tools\ParallelSafe}, and it must never become so.
 * That interface's first rule is that a tool mutating the workspace cannot join
 * a concurrent group, for a reason specific to how the group is dispatched:
 * {@see \SugarCraft\Crush\Runtime::executeToolCalls()} forks one child per call,
 * and an orphaned child (Escape-Escape cancel, or the parent hitting
 * {@see \SugarCraft\Crush\Backend\EngineBackend::COMPLETE_TIMEOUT_SECONDS})
 * has NO deadline left to stop it — so a cancelled turn could still leave a
 * file on disk the user never approved. Two writes to one path could also race.
 * Omitting the interface makes this tool a barrier, executed alone in provider
 * order, which is the correct and safe default.
 */
final readonly class Write implements Tool
{
    use BuildsUnifiedDiff;
    use TruncatesOutput;

    /**
     * $skillNudge/$instructionLoader mirror {@see Edit}'s wiring: writing into a
     * directory a skill scopes itself to, or one carrying a nested
     * CLAUDE.md/AGENTS.md, surfaces that context once, exactly as touching the
     * same path through Read/Edit/Glob would.
     */
    public function __construct(
        private ?string $root = null,
        private ?AgentPathJail $worktreeJail = null,
        private ?InstructionFileLoader $instructionLoader = null,
        private ?SkillPathNudge $skillNudge = null,
    ) {}

    public function name(): string
    {
        return 'Write';
    }

    public function description(): string
    {
        return 'Create a new file with the given content. Refuses to clobber an existing file unless overwrite is true; use Edit to change part of a file that already exists.';
    }

    public function inputSchema(): array
    {
        return [
        'type' => 'object',
        'properties' => [
            'file_path' => ['type' => 'string', 'description' => 'Path to the file to create. Missing parent directories are created.'],
            'content' => ['type' => 'string', 'description' => 'Full contents of the new file'],
            // `boolean`, not `bool`: JSON Schema has no `bool` type, and a
            // guided-decoding backend (SGLang outlines/xgrammar) can reject
            // or mis-constrain a field whose declared type it cannot resolve.
            'overwrite' => ['type' => 'boolean', 'description' => 'Replace the file if it already exists (destroys its current contents)'],
            'description' => [
                'type' => 'string',
                'description' => 'Clear, concise 5-10 word description in active voice of what this file is for (e.g. "Add the retry-policy config loader", not "writes a file").',
            ],
        ],
        'required' => ['file_path', 'content', 'description'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $path = $args['file_path'] ?? '';
        $content = $args['content'] ?? '';
        $overwrite = (bool) ($args['overwrite'] ?? false);

        if (!is_string($path) || $path === '') {
            return $this->error($args, 'Error: file_path cannot be empty');
        }
        if (!is_string($content)) {
            return $this->error($args, 'Error: content must be a string');
        }
        // realpath() throws a ValueError on a NUL byte rather than failing, so
        // without this the crash escaped execute() instead of coming back as a
        // tool error the model can read. Same guard as Read/Edit.
        if (str_contains($path, "\0")) {
            return $this->error($args, 'Error: file_path contains a NUL byte');
        }

        if ($this->worktreeJail !== null) {
            // isAllowed() realpath()s the target and so answers false for ANY
            // file that does not exist yet - i.e. for every legitimate Write.
            // resolveForCreate() is the shared containment algorithm's answer
            // to exactly that case, so the hand-rolled nearest-existing-ancestor
            // probe that used to live here is gone: one algorithm, two jails
            // (see Tools\PathJailInterface for why both jails still exist).
            //
            // That probe was not merely redundant, it was wrong, and the
            // replacement is strictly stronger rather than equivalent.
            // Replaying the old gate path-for-path against resolveForCreate()
            // on a canonical root, SEVEN paths it admitted are now refused:
            // '..', '../', 'out_dir' (symlink -> outside dir), 'out_file'
            // (symlink -> outside file), 'dangling', 'dangling/x.txt' and
            // 'nope/../../escaped.txt'. Each one anchored on an ancestor that
            // really was in the worktree while the TARGET was not. Five of the
            // seven were then stopped downstream by checks that exist for
            // unrelated reasons (the is_dir() refusal below, a failing
            // mkdir()), so the jail was contributing nothing to their safety;
            // two landed outside for real -- 'out_file' overwrote a file
            // outside the worktree, and 'dangling' created one there. Nothing
            // the old gate refused is newly allowed.
            $resolved = $this->worktreeJail->resolveForCreate($path);
            if ($resolved === null) {
                return $this->error($args, 'Error: path outside worktree');
            }
            $path = $resolved;
        } elseif ($this->root !== null) {
            $resolved = PathJail::resolveForCreate($this->root, $path);
            if ($resolved === null) {
                return $this->error($args, 'Error: path outside workspace root');
            }
            $path = $resolved;
        }

        if (is_dir($path)) {
            return $this->error($args, "Error: path is a directory: $path");
        }

        // Overwrite semantics: refuse by default, require an explicit opt-in.
        //
        // Write carries no evidence that the model ever saw what is already
        // there - unlike Edit, whose old_string IS that evidence - so a silent
        // clobber is an unbounded, unreviewable delete of content the user may
        // never have shown the model. Erroring costs one wasted tool call and
        // names the two correct routes (Edit, or overwrite: true); clobbering
        // costs the file. The recoverable failure is the safer default.
        $exists = is_file($path);
        if ($exists && !$overwrite) {
            return $this->error(
                $args,
                "Error: file already exists: $path; use Edit to modify it, or pass overwrite=true to replace its entire contents",
            );
        }

        $previous = '';
        if ($exists) {
            $read = file_get_contents($path);
            if ($read === false) {
                return $this->error($args, "Error reading file: $path");
            }
            $previous = $read;
        }

        // Creating the parent directories here rather than making the model
        // shell out to `mkdir -p` is the whole point of the tool: a heredoc
        // round-trip is what P8.12 exists to remove, and half a round-trip is
        // still a round-trip.
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            return $this->error($args, "Error creating directory: $dir");
        }

        $nestedContent = $this->instructionLoader?->loadForPath($path);

        if (@file_put_contents($path, $content) === false) {
            return $this->error($args, "Error writing file: $path");
        }

        // Same contract as Edit: the diff rides its own ToolResult field so a
        // renderer hands it straight to DiffViewer. For a new file the old side
        // is empty, which `diff -u` renders as an `@@ -0,0 +1,N @@` hunk.
        $diff = $content !== $previous
            ? self::unifiedDiff($path, $previous, $content)
            : '';

        $message = ($exists ? 'File overwritten: ' : 'File created: ') . $path;
        // Bounded by the standalone default rather than by a fraction of a
        // cap, because this tool has no output cap to take a fraction OF: its
        // result is one line ("File updated: <path>"), so nothing here was
        // ever going to need one. The instruction body was the whole of the
        // unbounded term — a `CLAUDE.md` of any size, prepended verbatim into
        // every write result for a governed path, replayed into every
        // following request of the turn.
        if ($nestedContent !== null) {
            $message = $this->clipInstructions($nestedContent, self::DEFAULT_MAX_INSTRUCTION_BYTES)
                . "\n\n" . $message;
        }

        // Fired only after the write landed -- a rejected or failed write did
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

    /** @param array<string, mixed> $args */
    private function error(array $args, string $message): ToolResult
    {
        return new ToolResult(
            toolCallId: is_string($args['id'] ?? null) ? $args['id'] : '',
            content: $message,
            isError: true,
        );
    }
}
