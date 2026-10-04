<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Context\RulePathNudge;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Support\AtomicFileWriter;
use SugarCraft\Crush\Support\ToolOutputSpill;
use SugarCraft\Crush\Tools\Concerns\BuildsUnifiedDiff;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;
use SugarCraft\Crush\Tools\Edit\EditFailureHints;
use SugarCraft\Crush\Tools\Edit\EditMatcher;
use SugarCraft\Crush\Tools\AcceptsWorktreeJail;
use SugarCraft\Crush\Tools\Concerns\RebindsWorktreeJail;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\PathJail;
use SugarCraft\Crush\Tools\ReadLedger;
use SugarCraft\Crush\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;

#[BuiltInTool(name: 'Edit', permission: ToolPermissionClass::Write, position: 3)]
final readonly class Edit implements Tool, AcceptsWorktreeJail, BuildsFromCatalog
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
     *
     * $readLedger is the session's record of what the model has seen (roadmap
     * 3.I-2): with one, an edit of a file that changed on disk since the
     * model last read it is refused, and every edit that lands is recorded as
     * the model's new picture of the file. Null skips both.
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
        private ?ReadLedger $readLedger = null,
    ) {}

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return new self($context->root, instructionLoader: $context->loader, skillNudge: $context->skillNudge, ruleNudge: $context->ruleNudge, readLedger: ReadLedger::forContext($context));
    }

    /** The read ledger this instance checks and records into, or null. */
    public function readLedger(): ?ReadLedger
    {
        return $this->readLedger;
    }

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
        return 'Edit a file by replacing text: old_string, which must match exactly one place, becomes '
            . 'new_string. Read the file first and copy old_string from it. When the exact bytes are not '
            . 'there, a chain of stricter-to-looser matches is tried, each of which must find exactly one '
            . 'place: the same lines under one indentation shift, then with each line\'s outer whitespace '
            . 'ignored, then with runs of spaces and tabs inside lines treated as one, then a block of 3+ '
            . 'lines found by its exact first and last lines with the lines between mostly alike, then with '
            . 'curly quotes, dashes and Unicode look-alikes folded. new_string is re-indented to the file '
            . 'where the match was shifted, and the result names the stage that matched. A match in several '
            . 'places is rejected (exact matches can set replace_all to change every one; looser matches '
            . 'never apply to more than one), as is zero matches or a loose match far larger or smaller '
            . 'than old_string; the file is then left untouched, never partially edited, and the error '
            . 'names the line of every match or the closest lines of the file. Leave out the "N: " line '
            . 'numbers Read prefixes. To make several changes to one file at once, put the first in '
            . 'old_string/new_string and the rest in edits, in order: each applies to the text the previous '
            . 'ones produced, and if any fails none is written. The file must already exist — use Write to '
            . 'create one. On success the result names the path and counts the lines added and removed, as '
            . '"(+2 -1 lines)"; it does not echo the new file contents back, so Read the file again if you '
            . 'need to see the edit in context.'
            // Claimed only by an instance that enforces it (roadmap 3.I-2).
            . ($this->readLedger === null ? '' : ' If the file changed on disk after you last read it — '
                . 'an edit by the user, a formatter or a command — the edit is refused and the file left '
                . 'untouched: Read it again and retry against what is there now.');
    }

    public function inputSchema(): array
    {
        return [
        'type' => 'object',
        'properties' => [
            'file_path' => ['type' => 'string', 'description' => 'Path to the file to edit. It must already exist; use Write to create a new file.'],
            'old_string' => ['type' => 'string', 'description' => 'The text to replace, copied from the file. Matched exactly first, then by the looser stages the tool description lists; must match exactly one place unless replace_all is set (exact matches only).'],
            'new_string' => ['type' => 'string', 'description' => 'The replacement text. May be empty to delete old_string.'],
            // `boolean`, not `bool`: JSON Schema has no `bool` type, and a
            // guided-decoding backend (SGLang outlines/xgrammar) can reject
            // or mis-constrain a field whose declared type it cannot resolve.
            'replace_all' => ['type' => 'boolean', 'description' => 'Replace every exact occurrence instead of requiring old_string to be unique.'],
            'edits' => [
                'type' => 'array',
                'description' => 'Further replacements in the same file, applied in order after old_string/new_string, each to the text the previous ones produced. All or nothing: if any fails to match, none is written.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'old_string' => ['type' => 'string', 'description' => 'The text to replace, as for the top-level old_string.'],
                        'new_string' => ['type' => 'string', 'description' => 'The replacement text.'],
                        'replace_all' => ['type' => 'boolean', 'description' => 'Replace every exact occurrence.'],
                    ],
                    'required' => ['old_string', 'new_string'],
                ],
            ],
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

        // Roadmap 3.I-1: the top-level pair is edit 1, `edits` the rest. Parsed
        // before the file is touched, so a malformed list costs nothing.
        $edits = self::editList($oldString, $newString, $replaceAll, $args['edits'] ?? null);
        if (is_string($edits)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: $edits,
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

        // The jail lets a SAVED TOOL OUTPUT file through for reading (roadmap
        // 2.8); it is the record of what a call printed, not a source file, so
        // the one writer that resolves through the same method turns it away.
        if (ToolOutputSpill::readablePath($path) !== null) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: "Error: $path is saved tool output and is read-only; Read or Grep it instead",
                isError: true,
            );
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

        // Roadmap 3.I-2: an old_string that still matches proves the model
        // saw SOME version of this text, not the current one — everything
        // around it may have moved since the read. Compared by content, so a
        // touch or a rewrite of identical bytes is not a change.
        $stale = $this->readLedger?->staleness($path, $originalContent);
        if ($stale !== null) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: self::staleRefusal($path),
                isError: true,
            );
        }

        $nestedContent = $this->instructionLoader?->loadForPath($path);

        // Which text to replace, found by the staged matcher chain (audit
        // 0.11, roadmap 3.I-1): exact bytes first, then ever looser stages,
        // each of which must find exactly one place. On a miss, an ambiguity
        // or a refusal the error says WHERE — every match's line, or the
        // closest windows of the file — and nothing is written: with several
        // edits, every one is matched against the text the ones before it
        // produced, IN MEMORY, and the file is written once, after the last,
        // so a failure at edit 3 leaves edits 1 and 2 unwritten too.
        $content = $originalContent;
        $notes = [];
        $total = \count($edits);
        $matcher = EditMatcher::new();
        foreach ($edits as $k => $edit) {
            $match = $matcher->match($content, $edit['old'], $edit['new']);

            $failure = match (true) {
                $match === null => EditFailureHints::notFound($content, $edit['old'], $edit['new'], $path),
                $match->isRefused() => "Error: old_string not found as written in {$path}; {$match->refusal}; file left unchanged",
                // A zero-match edit is a FAILED edit, not a silent no-op (the
                // arm above): str_replace() would rewrite the file byte for
                // byte and report "File updated". Several exact matches need
                // replace_all; a looser stage never reaches here with more than
                // one, because the matcher refuses that itself.
                $match->count > 1 && !$edit['replaceAll'] => EditFailureHints::ambiguous($content, $edit['old'], $match->count),
                default => null,
            };

            if ($failure !== null) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: $total === 1 ? $failure : self::inEdit($failure, $k + 1, $total),
                    isError: true,
                );
            }

            /** @var \SugarCraft\Crush\Tools\Edit\EditMatch $match */
            $content = $match->applyTo($content);
            if ($match->note !== null) {
                $notes[] = ($total === 1 ? '' : 'edit ' . ($k + 1) . ': ') . $match->note;
            }
        }

        $newContent = $content;

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

        // The model's own change is its new picture of the file, so its next
        // edit here is not refused as stale.
        $this->readLedger?->record($path, $newContent);

        // Mirrors opencode's and Claude Code's Edit tool: the transcript always
        // shows a real before/after diff, never just a bare confirmation string.
        // It rides its own ToolResult field rather than being concatenated into
        // the summary, so a renderer can hand it straight to DiffViewer.
        $preview = $newContent !== $originalContent
            ? self::diffPreview($path, $originalContent, $newContent)
            : ['diff' => '', 'added' => 0, 'removed' => 0, 'omitted' => false];
        $diff = $preview['diff'];

        $message = "File updated: $path"
            . ($preview['omitted'] ? self::omittedDiffNote($preview) : self::changeSummary($diff))
            . ($total === 1 ? '' : " ({$total} edits applied)")
            . implode('', array_map(static fn (string $note): string => " ({$note})", $notes));
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
     * The edits one call asks for: the top-level pair first, then `edits` in
     * order, or the error string for a malformed list.
     *
     * @return list<array{old: string, new: string, replaceAll: bool}>|string
     */
    private static function editList(mixed $old, mixed $new, mixed $replaceAll, mixed $extra): array|string
    {
        if (!is_string($old) || !is_string($new)) {
            return 'Error: old_string and new_string must be strings';
        }

        // Truthiness, as the single-edit path always read replace_all.
        $edits = [['old' => $old, 'new' => $new, 'replaceAll' => (bool) $replaceAll]];
        if ($extra === null || $extra === []) {
            return $edits;
        }
        if (!is_array($extra) || !array_is_list($extra)) {
            return 'Error: edits must be a list of {old_string, new_string} objects; file left unchanged';
        }

        foreach ($extra as $i => $edit) {
            $n = $i + 2;
            if (!is_array($edit) || !is_string($edit['old_string'] ?? null) || !is_string($edit['new_string'] ?? null)) {
                return "Error: edit {$n} needs string old_string and new_string; file left unchanged";
            }
            if ($edit['old_string'] === '') {
                return "Error: edit {$n}'s old_string cannot be empty; file left unchanged";
            }
            $edits[] = [
                'old' => $edit['old_string'],
                'new' => $edit['new_string'],
                'replaceAll' => (bool) ($edit['replace_all'] ?? false),
            ];
        }

        return $edits;
    }

    /**
     * A single-edit error re-labelled for edit $n of $total: the matcher and
     * hint text is unchanged, and the head says which edit failed and that the
     * earlier ones were not written either. Line numbers in it refer to the
     * text as the earlier edits left it.
     */
    private static function inEdit(string $failure, int $n, int $total): string
    {
        $head = sprintf(
            'Error in edit %d of %d (none of the %d edits was written%s): ',
            $n,
            $total,
            $total,
            $n === 1 ? '' : sprintf('; line numbers below are in the text as edit%s left it', $n === 2 ? ' 1' : 's 1-' . ($n - 1)),
        );

        return $head . (str_starts_with($failure, 'Error: ') ? substr($failure, 7) : $failure);
    }

    /**
     * The refusal for an edit of a file that changed since the model last
     * read it (roadmap 3.I-2). Names the cure, since the model cannot tell a
     * stale picture from a bad old_string on its own.
     */
    private static function staleRefusal(string $path): string
    {
        return "Error: {$path} changed on disk since you last read it (an edit by the user, a formatter or a command); "
            . 'Read it again before editing it; file left unchanged';
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
