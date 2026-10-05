<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Context\RulePathNudge;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Support\AtomicFileWriter;
use SugarCraft\Crush\Support\ToolOutputSpill;
use SugarCraft\Crush\Tools\AcceptsWorktreeJail;
use SugarCraft\Crush\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\Concerns\BuildsUnifiedDiff;
use SugarCraft\Crush\Tools\Concerns\RebindsWorktreeJail;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;
use SugarCraft\Crush\Tools\Edit\EditFailureHints;
use SugarCraft\Crush\Tools\Edit\EditMatcher;
use SugarCraft\Crush\Tools\Edit\PatchAction;
use SugarCraft\Crush\Tools\Edit\PatchHunk;
use SugarCraft\Crush\Tools\Edit\PatchOperation;
use SugarCraft\Crush\Tools\Edit\PatchParser;
use SugarCraft\Crush\Tools\PathJail;
use SugarCraft\Crush\Tools\ReadLedger;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Several files changed in one call, from a `*** Begin Patch` patch
 * (roadmap 3.I-3; opencode's and Codex's `apply_patch`, the edit format
 * GPT-family models are trained on). Grammar: {@see PatchParser}.
 *
 * BUILT ON EDIT'S CONTRACT, NOT BESIDE IT. Every hunk is placed by the same
 * staged {@see EditMatcher} chain `Edit` uses, so a hunk whose context drifted
 * by indentation or whitespace still lands, a forgiving match must be unique,
 * and the result names the stage that matched. Each file goes through the
 * same jail, saved-output refusal, size cap, regular-file check and read
 * ledger staleness refusal as `Edit` (`Add File` and a move destination as
 * `Write` creates). Hunks apply in file order: each is looked for after the
 * one before it, and after its `@@` anchors, which is what tells two
 * identical blocks apart.
 *
 * ALL OR NOTHING ACROSS FILES. Every section is parsed, resolved, read and
 * matched in memory before anything is written; a refusal anywhere leaves
 * every file as it was. The writes then land one file at a time (each atomic,
 * {@see AtomicFileWriter::replace()}); if one fails, the ones already made are
 * put back, so a failed patch is never a half-applied one.
 *
 * PERMISSIONS see every path. The gate classifies the call by the WORST of
 * its paths (`accept-edits` grants it only when all are inside the root, and
 * `auto` classifies each), and `protect-files` checks every path, move
 * destinations included — both through {@see PatchParser::paths()}, which
 * returns null for a patch that does not parse, and both fail closed on that.
 */
#[BuiltInTool(name: self::NAME, permission: ToolPermissionClass::Write, position: 20, gloss: 'add, change, move and delete several files in one call from a `*** Begin Patch` patch, all or nothing')]
final readonly class ApplyPatch implements Tool, AcceptsWorktreeJail, BuildsFromCatalog
{
    use BuildsUnifiedDiff;
    use RebindsWorktreeJail;
    use TruncatesOutput;

    public const NAME = 'ApplyPatch';

    /** Same per-file cap as {@see Edit}. */
    private const DEFAULT_MAX_BYTES = 1024 * 1024;

    /**
     * $writeSeam is a TEST SEAM only: the payload writer handed to
     * {@see AtomicFileWriter::replace()}, so a test can fail one file's write
     * and prove the earlier files are put back.
     *
     * $readLedger is the session's record of what the model has seen (roadmap
     * 3.I-2), shared with Read/Edit/Write: a section whose file changed on
     * disk since the model last read it refuses the whole patch.
     */
    public function __construct(
        private ?string $root = null,
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
        private ?AgentPathJail $worktreeJail = null,
        private ?InstructionFileLoader $instructionLoader = null,
        private ?SkillPathNudge $skillNudge = null,
        private ?RulePathNudge $ruleNudge = null,
        private ?\Closure $writeSeam = null,
        private ?ReadLedger $readLedger = null,
    ) {
    }

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
        return self::NAME;
    }

    public function description(): string
    {
        return 'Change several files in one call with a patch: add new files, edit existing ones, move and '
            . 'delete them. The patch starts with "*** Begin Patch" and ends with "*** End Patch"; in between, '
            . 'one section per file: "*** Add File: <path>" followed by the new file\'s lines, each starting '
            . 'with "+"; "*** Delete File: <path>"; or "*** Update File: <path>", optionally followed by '
            . '"*** Move to: <new path>", then hunks. A hunk is the changed lines with about 3 unchanged lines '
            . 'around them: unchanged lines start with a space, removed ones with "-", added ones with "+". '
            . 'Start a hunk with "@@", or with "@@ <a line of the file>" (such as a function or class line) to '
            . 'say the hunk comes after that line when the same lines appear more than once; end the last hunk '
            . 'of a file with "*** End of File" when it is at the end. Hunks are found by their text, never by '
            . 'line numbers, in order, each after the one before; if the exact lines are not there, the same '
            . 'looser matches as Edit are tried, and each must find exactly one place. If any section fails -- '
            . 'a hunk not found or found in several places, a file that must exist does not, a new file already '
            . 'exists -- no file is changed and the error says which section and why. On success the result '
            . 'lists each file with its added and removed line counts; it does not echo the files back.'
            // Claimed only by an instance that enforces it (roadmap 3.I-2).
            . ($this->readLedger === null ? '' : ' If a file you change or delete changed on disk after you last '
                . 'read it, the patch is refused: Read it again and retry against what is there now.');
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'patch' => [
                    'type' => 'string',
                    'description' => 'The whole patch, from "*** Begin Patch" to "*** End Patch".',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Clear, concise 5-10 word description in active voice of what this patch does (e.g. "Rename the cart helpers across three files").',
                ],
            ],
            'required' => ['patch', 'description'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $patch = $args['patch'] ?? null;
        if (!\is_string($patch) || trim($patch) === '') {
            return $this->error($args, 'Error: patch must be a non-empty string; no file changed');
        }

        try {
            $operations = PatchParser::new()->parse($patch);
        } catch (\InvalidArgumentException $e) {
            return $this->error($args, 'Error: the patch does not parse: ' . $e->getMessage() . '; no file changed');
        }

        // Plan every section in memory first. A refusal here costs nothing.
        $plans = [];
        $claimed = [];
        foreach ($operations as $k => $operation) {
            $plan = $this->plan($operation, \count($operations) === 1 ? '' : sprintf('section %d (%s): ', $k + 1, $operation->path));
            if (\is_string($plan)) {
                return $this->error($args, $plan);
            }
            foreach ([$plan['path'], $plan['target']] as $canonical) {
                if ($canonical === null) {
                    continue;
                }
                if (isset($claimed[$canonical])) {
                    return $this->error($args, "Error: two sections of the patch resolve to {$canonical}; put every change to one file in one section; no file changed");
                }
                $claimed[$canonical] = true;
            }
            $plans[] = $plan;
        }

        $failure = $this->commit($plans);
        if ($failure !== null) {
            return $this->error($args, $failure);
        }

        return $this->report($args, $plans);
    }

    /**
     * One section resolved, read and applied in memory, or the error that
     * refuses the whole patch.
     *
     * @return array{action: PatchAction, path: string, target: ?string, before: ?string, after: ?string, notes: list<string>}|string
     */
    private function plan(PatchOperation $operation, string $where): array|string
    {
        if ($operation->action === PatchAction::Add) {
            $path = $this->resolveForCreate($operation->path);
            if ($path === null) {
                return "Error: {$where}{$operation->path} is outside the workspace; no file changed";
            }
            if (ToolOutputSpill::readablePath($path) !== null) {
                return "Error: {$where}{$path} is saved tool output and is read-only; no file changed";
            }
            if (file_exists($path) || is_link($path)) {
                return "Error: {$where}{$path} already exists; change it with an \"*** Update File:\" section instead; no file changed";
            }

            return ['action' => PatchAction::Add, 'path' => $path, 'target' => null, 'before' => null, 'after' => $operation->content, 'notes' => []];
        }

        $path = $this->resolveExisting($operation->path);
        if ($path === null) {
            // Inside the workspace but absent, or not inside it at all.
            $wouldBe = $this->resolveForCreate($operation->path);

            return $wouldBe !== null && !file_exists($wouldBe)
                ? "Error: {$where}file not found: {$operation->path}; use an \"*** Add File:\" section to create it; no file changed"
                : "Error: {$where}{$operation->path} is outside the workspace; no file changed";
        }
        if (ToolOutputSpill::readablePath($path) !== null) {
            return "Error: {$where}{$path} is saved tool output and is read-only; no file changed";
        }
        if (!is_file($path)) {
            return "Error: {$where}not a regular file: {$path}; no file changed";
        }

        // The cap is checked before the read and again on the bytes read, as
        // in Edit: a file can grow between the stat and the open.
        $tooLarge = sprintf('Error: %s%s is over the %s-byte limit for a patched file; no file changed', $where, $path, number_format($this->maxBytes));
        $size = filesize($path);
        if ($size !== false && $size > $this->maxBytes) {
            return $tooLarge;
        }
        $content = file_get_contents($path, false, null, 0, $this->maxBytes < PHP_INT_MAX ? $this->maxBytes + 1 : null);
        if ($content === false) {
            return "Error: {$where}cannot read {$path}; no file changed";
        }
        if (\strlen($content) > $this->maxBytes) {
            return $tooLarge;
        }

        // Roadmap 3.I-2: a hunk that still matches proves the model saw SOME
        // version of these lines, not the current file; a delete of a file
        // that changed would discard what the model never saw.
        if ($this->readLedger?->staleness($path, $content) !== null) {
            return "Error: {$where}{$path} changed on disk since you last read it (an edit by the user, a formatter or a command); "
                . 'Read it again before patching it; no file changed';
        }

        if ($operation->action === PatchAction::Delete) {
            return ['action' => PatchAction::Delete, 'path' => $path, 'target' => null, 'before' => $content, 'after' => null, 'notes' => []];
        }

        $target = null;
        if ($operation->moveTo !== null) {
            $target = $this->resolveForCreate($operation->moveTo);
            if ($target === null) {
                return "Error: {$where}the move destination {$operation->moveTo} is outside the workspace; no file changed";
            }
            if (ToolOutputSpill::readablePath($target) !== null) {
                return "Error: {$where}{$target} is saved tool output and is read-only; no file changed";
            }
            if ($target !== $path && (file_exists($target) || is_link($target))) {
                return "Error: {$where}the move destination {$target} already exists; no file changed";
            }
            if ($target === $path) {
                $target = null;
            }
        }

        $applied = self::applyHunks($content, $operation->hunks, $path, $where);
        if (\is_string($applied)) {
            return $applied;
        }

        return ['action' => PatchAction::Update, 'path' => $path, 'target' => $target, 'before' => $content, 'after' => $applied[0], 'notes' => $applied[1]];
    }

    /**
     * $content with every hunk applied in order, plus a note per hunk that
     * matched only loosely, or the error.
     *
     * @param list<PatchHunk> $hunks
     * @return array{0: string, 1: list<string>}|string
     */
    private static function applyHunks(string $content, array $hunks, string $path, string $where): array|string
    {
        // Hunks are whole lines: a file whose last line has no newline is
        // matched as if it had one, and gets it back without.
        $terminated = $content === '' || str_ends_with($content, "\n");
        $work = $terminated ? $content : $content . "\n";

        $notes = [];
        $cursor = 0;
        $matcher = EditMatcher::new();
        $total = \count($hunks);
        foreach ($hunks as $k => $hunk) {
            $label = $total === 1 ? 'the hunk' : 'hunk ' . ($k + 1);

            foreach ($hunk->anchors as $anchor) {
                $after = self::findAnchor($work, $cursor, $anchor);
                if ($after === null) {
                    return sprintf(
                        'Error: %s%s: the "@@ %s" line was not found in %s%s; no file changed',
                        $where,
                        $label,
                        $anchor,
                        $path,
                        $cursor === 0 ? '' : ' after line ' . EditFailureHints::lineAt($work, $cursor - 1),
                    );
                }
                $cursor = $after;
            }

            $old = $hunk->oldText();
            $new = $hunk->newText();

            if ($old === '') {
                // A pure insertion has nothing to find, so it needs a place.
                if ($hunk->endOfFile) {
                    $at = \strlen($work);
                } elseif ($hunk->anchors !== []) {
                    $at = $cursor;
                } else {
                    return "Error: {$where}{$label} only adds lines and has no context, \"@@\" line or \"*** End of File\", "
                        . 'so there is no telling where they go; add a few unchanged lines around them; no file changed';
                }
                $work = substr_replace($work, $new, $at, 0);
                $cursor = $at + \strlen($new);

                continue;
            }

            $region = substr($work, $cursor);
            $found = self::lineAlignedOccurrences($region, $old);
            if ($hunk->endOfFile && $found !== [] && end($found) + \strlen($old) === \strlen($region)) {
                $found = [end($found)];
            }

            if (\count($found) === 1) {
                $at = $cursor + $found[0];
                $work = substr_replace($work, $new, $at, \strlen($old));
                $cursor = $at + \strlen($new);

                continue;
            }

            if (\count($found) > 1) {
                return sprintf(
                    'Error: %s%s matches %d places in %s (starting at lines %s); add an "@@ <line>" anchor naming a line '
                    . 'above the right one, or more unchanged lines, so it matches exactly one; no file changed',
                    $where,
                    $label,
                    \count($found),
                    $path,
                    implode(', ', array_map(static fn (int $o): int => EditFailureHints::lineAt($work, $cursor + $o), \array_slice($found, 0, 8))),
                );
            }

            $match = $matcher->match($region, $old, $new);
            if ($match === null || $match->isExact()) {
                return self::notFound($work, $old, $new, $path, $where, $label);
            }
            if ($match->isRefused()) {
                return "Error: {$where}{$label}'s lines were not found as written in {$path}; {$match->refusal}; no file changed";
            }

            $at = $cursor + (int) $match->offset;
            $work = substr_replace($work, $match->newString, $at, \strlen($match->oldString));
            $cursor = $at + \strlen($match->newString);
            if ($match->note !== null) {
                $notes[] = ($total === 1 ? '' : $label . ': ') . $match->note;
            }
        }

        if (!$terminated && str_ends_with($work, "\n")) {
            $work = substr($work, 0, -1);
        }

        return [$work, $notes];
    }

    /**
     * Offset just past the first line at or after $cursor that is $anchor
     * (outer whitespace ignored, then runs of whitespace treated as one), or
     * null.
     */
    private static function findAnchor(string $work, int $cursor, string $anchor): ?int
    {
        $wanted = trim($anchor);
        $loose = preg_replace('/\s+/', ' ', $wanted) ?? $wanted;
        $fallback = null;
        $offset = $cursor;
        $length = \strlen($work);
        while ($offset < $length) {
            $end = strpos($work, "\n", $offset);
            $next = $end === false ? $length : $end + 1;
            $line = trim(substr($work, $offset, ($end === false ? $length : $end) - $offset));
            if ($line === $wanted) {
                return $next;
            }
            if ($fallback === null && (preg_replace('/\s+/', ' ', $line) ?? $line) === $loose) {
                $fallback = $next;
            }
            $offset = $next;
        }

        return $fallback;
    }

    /**
     * Every offset where $needle starts a line of $haystack.
     *
     * @return list<int>
     */
    private static function lineAlignedOccurrences(string $haystack, string $needle): array
    {
        $found = [];
        $offset = 0;
        while (($at = strpos($haystack, $needle, $offset)) !== false) {
            if ($at === 0 || $haystack[$at - 1] === "\n") {
                $found[] = $at;
            }
            $offset = $at + 1;
        }

        return $found;
    }

    /**
     * Edit's not-found diagnosis — the closest lines of the file, an already
     * applied change — in this tool's words.
     */
    private static function notFound(string $work, string $old, string $new, string $path, string $where, string $label): string
    {
        $hints = EditFailureHints::notFound($work, $old, $new, $path);
        $hints = preg_replace('/^Error: old_string not found in .*?; file left unchanged\.\n?/', '', $hints) ?? $hints;
        $hints = str_replace(['old_string', 'new_string'], ["the hunk's unchanged and \"-\" lines", "the hunk's unchanged and \"+\" lines"], $hints);

        return "Error: {$where}{$label}'s unchanged and \"-\" lines were not found in {$path}; no file changed."
            . (trim($hints) === '' ? '' : "\n" . $hints);
    }

    /**
     * Write every planned section, or put back what was written and say why.
     *
     * @param list<array{action: PatchAction, path: string, target: ?string, before: ?string, after: ?string, notes: list<string>}> $plans
     */
    private function commit(array $plans): ?string
    {
        /** @var list<array{0: string, 1: ?string}> $undo path => bytes to restore, null to remove */
        $undo = [];
        foreach ($plans as $plan) {
            $path = $plan['path'];
            try {
                switch ($plan['action']) {
                    case PatchAction::Add:
                        $this->ensureParent($path, $undo);
                        $undo[] = [$path, null];
                        AtomicFileWriter::replace($path, (string) $plan['after'], $this->writeSeam);
                        break;

                    case PatchAction::Delete:
                        $undo[] = [$path, (string) $plan['before']];
                        if (!@unlink($path)) {
                            throw new \RuntimeException("cannot delete {$path}");
                        }
                        break;

                    case PatchAction::Update:
                        $target = $plan['target'];
                        if ($target === null) {
                            $undo[] = [$path, (string) $plan['before']];
                            AtomicFileWriter::replace($path, (string) $plan['after'], $this->writeSeam);
                            break;
                        }
                        $this->ensureParent($target, $undo);
                        $undo[] = [$target, null];
                        AtomicFileWriter::replace($target, (string) $plan['after'], $this->writeSeam);
                        $undo[] = [$path, (string) $plan['before']];
                        if (!@unlink($path)) {
                            throw new \RuntimeException("cannot remove {$path} after moving it");
                        }
                        break;
                }
            } catch (\RuntimeException $e) {
                $restored = $this->rollBack($undo);

                return 'Error writing the patch: ' . $e->getMessage() . '; '
                    . ($restored ? 'every file already changed was put back, so no file changed' : 'putting back the files already changed failed too; Read them before retrying');
            }
        }

        foreach ($plans as $plan) {
            if ($plan['action'] === PatchAction::Delete) {
                $this->readLedger?->forget($plan['path']);
                continue;
            }
            if ($plan['target'] !== null) {
                $this->readLedger?->forget($plan['path']);
            }
            $this->readLedger?->record($plan['target'] ?? $plan['path'], (string) $plan['after']);
        }

        return null;
    }

    /**
     * Create $path's missing parent directories, recording each one so a
     * roll-back removes them again.
     *
     * @param list<array{0: string, 1: ?string}> $undo
     */
    private function ensureParent(string $path, array &$undo): void
    {
        $dir = \dirname($path);
        $missing = [];
        for ($d = $dir; !is_dir($d) && $d !== \dirname($d); $d = \dirname($d)) {
            $missing[] = $d;
        }
        if ($missing === []) {
            return;
        }
        if (!@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create directory {$dir}");
        }
        foreach (array_reverse($missing) as $created) {
            $undo[] = [$created . '/', null];
        }
    }

    /**
     * Undo $undo newest first. True when every step went back.
     *
     * @param list<array{0: string, 1: ?string}> $undo
     */
    private function rollBack(array $undo): bool
    {
        $ok = true;
        foreach (array_reverse($undo) as [$path, $bytes]) {
            if (str_ends_with($path, '/')) {
                $ok = (@rmdir($path) || !is_dir($path)) && $ok;
                continue;
            }
            if ($bytes === null) {
                $ok = (!file_exists($path) || @unlink($path)) && $ok;
                continue;
            }
            try {
                AtomicFileWriter::replace($path, $bytes);
            } catch (\RuntimeException) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * @param array<string, mixed> $args
     * @param list<array{action: PatchAction, path: string, target: ?string, before: ?string, after: ?string, notes: list<string>}> $plans
     */
    private function report(array $args, array $plans): ToolResult
    {
        $lines = [];
        $diffs = [];
        $touched = [];
        foreach ($plans as $plan) {
            $shown = $plan['target'] ?? $plan['path'];
            $preview = self::diffPreview($shown, (string) $plan['before'], (string) $plan['after']);
            if ($preview['diff'] !== '') {
                $diffs[] = $preview['diff'];
            }
            $tally = $preview['omitted']
                ? self::omittedDiffNote($preview)
                : sprintf(' (+%d -%d lines)', $preview['added'], $preview['removed']);

            $lines[] = match ($plan['action']) {
                PatchAction::Add => "- added {$shown}{$tally}",
                PatchAction::Delete => "- deleted {$plan['path']}{$tally}",
                PatchAction::Update => $plan['target'] === null
                    ? "- updated {$shown}{$tally}"
                    : "- moved {$plan['path']} to {$shown}{$tally}",
            } . implode('', array_map(static fn (string $note): string => " ({$note})", $plan['notes']));

            if ($plan['action'] !== PatchAction::Delete) {
                $touched[] = $shown;
            }
        }

        $message = 'Patch applied:' . "\n" . implode("\n", $lines);

        // The same per-path context Edit and Write surface, once per patch:
        // nested instruction files (bounded together, as one result), then the
        // one-shot skill and rule nudges, which fire only for a write that
        // landed.
        $instructions = [];
        foreach ($touched as $path) {
            $nested = $this->instructionLoader?->loadForPath($path);
            if ($nested !== null && !\in_array($nested, $instructions, true)) {
                $instructions[] = $nested;
            }
        }
        if ($instructions !== []) {
            $message = $this->clipInstructions(implode("\n\n", $instructions), self::DEFAULT_MAX_INSTRUCTION_BYTES)
                . "\n\n" . $message;
        }
        foreach ($touched as $path) {
            foreach ([$this->skillNudge?->forPath($path), $this->ruleNudge?->forPath($path)] as $nudge) {
                if ($nudge !== null) {
                    $message .= "\n\n" . $nudge;
                }
            }
        }

        return new ToolResult(
            toolCallId: \is_string($args['id'] ?? null) ? $args['id'] : '',
            content: $message,
            isError: false,
            diff: $diffs === [] ? null : implode("\n", $diffs),
        );
    }

    private function resolveExisting(string $path): ?string
    {
        if ($this->worktreeJail !== null) {
            $resolved = $this->worktreeJail->resolve($path);

            return $resolved !== null && file_exists($resolved) ? $resolved : null;
        }
        if ($this->root !== null) {
            $resolved = PathJail::resolve($this->root, $path);

            return $resolved !== null && file_exists($resolved) ? $resolved : null;
        }

        return file_exists($path) ? $path : null;
    }

    private function resolveForCreate(string $path): ?string
    {
        if ($this->worktreeJail !== null) {
            return $this->worktreeJail->resolveForCreate($path);
        }
        if ($this->root !== null) {
            return PathJail::resolveForCreate($this->root, $path);
        }

        return $path;
    }

    /** @param array<string, mixed> $args */
    private function error(array $args, string $message): ToolResult
    {
        return new ToolResult(
            toolCallId: \is_string($args['id'] ?? null) ? $args['id'] : '',
            content: $message,
            isError: true,
        );
    }
}
