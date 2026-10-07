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
use SugarCraft\Crush\Tools\PathJail;
use SugarCraft\Crush\Tools\PromptGuidance;
use SugarCraft\Crush\Tools\ReadLedger;
use SugarCraft\Crush\Tools\AcceptsWorktreeJail;
use SugarCraft\Crush\Tools\Concerns\RebindsWorktreeJail;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;

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
#[BuiltInTool(name: 'Write', permission: ToolPermissionClass::Write, position: 6)]
final readonly class Write implements Tool, AcceptsWorktreeJail, PromptGuidance, BuildsFromCatalog
{
    use BuildsUnifiedDiff;
    use RebindsWorktreeJail;
    use TruncatesOutput;

    /**
     * Largest previous file an overwrite reads back to diff against (audit
     * F-T2). Overwriting does not need the old bytes -- they are read only to
     * build the preview -- so past this the write still happens and only the
     * diff is skipped. Same figure as Edit's and Read's default read cap.
     */
    private const DEFAULT_MAX_DIFF_BYTES = 1024 * 1024;

    /**
     * $skillNudge/$instructionLoader mirror {@see Edit}'s wiring: writing into a
     * directory a skill scopes itself to, or one carrying a nested
     * CLAUDE.md/AGENTS.md, surfaces that context once, exactly as touching the
     * same path through Read/Edit/Glob would.
     *
     * $writeSeam is a TEST SEAM only (audit F-T7): the payload writer handed
     * to {@see AtomicFileWriter::replace()}, so a test can fail a write
     * part-way and prove the original bytes survive. Production passes null.
     *
     * $readLedger is the session's record of what the model has seen (roadmap
     * 3.I-2): with one, overwriting a file that changed on disk since the
     * model last read it is refused, and every write that lands is recorded
     * as the model's picture of the file. Null skips both.
     */
    public function __construct(
        private ?string $root = null,
        private ?AgentPathJail $worktreeJail = null,
        private ?InstructionFileLoader $instructionLoader = null,
        private ?SkillPathNudge $skillNudge = null,
        private ?RulePathNudge $ruleNudge = null,
        private int $maxDiffBytes = self::DEFAULT_MAX_DIFF_BYTES,
        private ?\Closure $writeSeam = null,
        private ?ReadLedger $readLedger = null,
    ) {}

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return new self($context->root, instructionLoader: $context->loader, skillNudge: $context->skillNudge, ruleNudge: $context->ruleNudge, readLedger: $context->readLedger);
    }

    /** The read ledger this instance checks and records into, or null. */
    public function readLedger(): ?ReadLedger
    {
        return $this->readLedger;
    }

    public function name(): string
    {
        return 'Write';
    }

    public function description(): string
    {
        return 'Create a brand-new file containing exactly the given content, creating any '
            . 'missing parent directories along the way. It refuses an existing path unless '
            . 'the overwrite flag is true, because that flag discards the previous contents '
            . 'with no undo. The result is a one-line confirmation naming the path, not the '
            . 'file contents echoed back. For changing only part of a file that exists, '
            . '`Edit` is the right operation.'
            // Claimed only by an instance that enforces it (roadmap 3.I-2).
            . ($this->readLedger === null ? '' : ' Overwriting a file that changed on disk after you last '
                . 'read it is refused the same way: Read it again first.');
    }

    /**
     * The session-scale statement of what the overwrite flag actually costs,
     * phrased without the sibling's name so the fragment holds when this tool
     * is wired alone ({@see PromptGuidance}) — "a different operation" is the
     * most this prose may say about partial changes, because naming it would
     * assert the existence of a tool the session may not have.
     */
    public function promptGuidance(): string
    {
        return 'The Write tool creates a file with the exact content it is given and refuses to '
            . 'touch an existing file unless the overwrite flag is set, because that flag '
            . 'discards the previous contents with no undo. Missing parent directories are '
            . 'created as part of the write. Reach for it with whole new files: changing part of '
            . 'a file that already exists is a different operation, and overwriting the file to '
            . 'do it destroys everything the partial change did not need to touch.';
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

        // Something that exists but is not a regular file -- a FIFO, socket or
        // device -- is refused outright, overwrite or not. `$exists` below is
        // is_file(), so a FIFO slipped past the "already exists" refusal and
        // file_put_contents() then blocked in open(2) until a reader appeared
        // (audit F-T4). file_exists() follows symlinks, so a dangling link is
        // still "missing" here and keeps its create-through behaviour (the
        // jailed paths refuse a dangling link earlier, in resolveForCreate()).
        if (file_exists($path) && !is_file($path)) {
            return $this->error($args, "Error: not a regular file: $path");
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

        // The previous contents are read only to diff against, so a file over
        // $maxDiffBytes is not read at all: the overwrite goes ahead and the
        // result says why it has no diff. Reading it in full used to cost the
        // file's size again on top of $content, and the line-array diff then
        // multiplied both (audit F-T2). The bounded read catches a file that
        // grew past the cap between the stat and the open.
        $previous = '';
        $previousTooLarge = null;
        if ($exists) {
            $size = filesize($path);
            if ($size !== false && $size > $this->maxDiffBytes) {
                $previousTooLarge = $size;
            } else {
                // One byte past the cap is enough to tell "over" from "at" it; null
                // (no bound) only for a cap so large that +1 would overflow.
                $limit = $this->maxDiffBytes < PHP_INT_MAX ? $this->maxDiffBytes + 1 : null;
                $read = file_get_contents($path, false, null, 0, $limit);
                if ($read === false) {
                    return $this->error($args, "Error reading file: $path");
                }
                if (strlen($read) > $this->maxDiffBytes) {
                    $previousTooLarge = strlen($read);
                } else {
                    $previous = $read;
                }
            }
        }

        // Roadmap 3.I-2: an overwrite discards what is there now, so a file
        // that changed since the model read it is refused — the model would be
        // deleting content it has never seen. By content when the previous
        // bytes were read above, by stat signature when they were too large.
        if ($exists && $this->readLedger?->staleness($path, $previousTooLarge === null ? $previous : null) !== null) {
            return $this->error(
                $args,
                "Error: {$path} changed on disk since you last read it (an edit by the user, a formatter or a command); "
                . 'Read it again before overwriting it; file left unchanged',
            );
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

        // Temp-then-rename for an overwrite AND a create (audit F-T7): the
        // old file_put_contents() truncated first, so a killed turn or an
        // ENOSPC mid-write destroyed the file being overwritten, and a create
        // could leave a half-written new file looking complete. replace()
        // keeps an overwritten file's mode, owner where it can, symlink and
        // hard links, and gives a new file the umask default as before.
        try {
            AtomicFileWriter::replace($path, $content, $this->writeSeam);
        } catch (\RuntimeException) {
            return $this->error($args, "Error writing file: $path");
        }

        // What the model just wrote is its picture of the file from here on.
        $this->readLedger?->record($path, $content);

        // Same contract as Edit: the diff rides its own ToolResult field so a
        // renderer hands it straight to DiffViewer. For a new file the old side
        // is empty, which `diff -u` renders as an `@@ -0,0 +1,N @@` hunk for
        // an N-line file — with the GNU elision, N == 1 headers as the short
        // `@@ -0,0 +1 @@` (the `,1` count is dropped when it equals 1).
        $preview = $previousTooLarge === null && $content !== $previous
            ? self::diffPreview($path, $previous, $content)
            : ['diff' => '', 'added' => 0, 'removed' => 0, 'omitted' => false];
        $diff = $preview['diff'];

        $message = ($exists ? 'File overwritten: ' : 'File created: ') . $path;
        if ($previousTooLarge !== null) {
            $message .= sprintf(
                ' (previous %s bytes not diffed: over the %s-byte diff bound)',
                number_format($previousTooLarge),
                number_format($this->maxDiffBytes),
            );
        } elseif ($preview['omitted']) {
            $message .= self::omittedDiffNote($preview);
        }
        // Bounded by the standalone default rather than by a fraction of a
        // cap, because this tool has no output cap to take a fraction OF: its
        // result is one line ("File updated: <path>"), so nothing here was
        // ever going to need one. The instruction body was the whole of the
        // unbounded term — a `CLAUDE.md` of any size, prepended verbatim into
        // every write result for a governed path, replayed into every
        // following request of the turn.
        if ($nestedContent !== null) {
            $message = $this->clipInstructions($nestedContent, $this->instructionCapBytes())
                . "\n\n" . $message;
        }

        // Fired only after the write landed -- a rejected or failed write did
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
