<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

use SugarCraft\Code\Workspace\GitRunner;

/**
 * The hand-off surface for a finished agent's worktree (RS-AGENT-A5).
 *
 * Ten-lane programs end with a human reading `git status` in each tree and
 * hand-merging the keepers; the fleet-driver research flagged that as the
 * missing decision-support step. This class answers, for one worktree id,
 * the two questions that decision needs — WHAT the branch holds (ahead/
 * behind against the current main tree, its commit list, its diffstat,
 * whether the working directory still carries uncommitted changes) and
 * WHETHER MERGING IT NOW WORKS (a read-only `git merge-tree` prediction,
 * never a real merge) — and, on explicit `--apply`, performs the merge as
 * pure plumbing: a new merge commit object, published under a fresh
 * `handoff/<id>-<stamp>` ref. `apply()` never checks out, never writes a
 * work or master ref, and refuses outright when there is uncommitted work
 * the move would silently strand.
 *
 * Registry and branches are UNTRUSTED-ON-READ inputs here (the registry is
 * a JSON file on disk, branch names ride it); nothing is echoed by this
 * class at all — every field crosses
 * {@see \SugarCraft\Code\Host\Commands\PermissionsCommand::reportField()}
 * at the formatter — and each name used as a `git` argument is shape-gated
 * first, so a forged registry entry cannot smuggle an option into a
 * command line. Git legs run through {@see GitRunner}, which scrubs the
 * `GIT_DIR`/`GIT_WORK_TREE` family from the child env, bounds every spawn
 * with its own timeout, and forces `--no-optional-locks` plus inert hooks
 * — the prediction legs therefore take no index lock, write no ref, and
 * hold no lock the human's own git could race.
 *
 * Construction of {@see WorktreeManager} below is the sixth census site
 * (StderrEmitterCensusTest names it); it exists per call because the
 * registry must be read fresh from disk — a hand-off report is a question
 * about NOW, not a cached view.
 */
final class WorktreeHandoff
{
    /** Branch-commit rows listed before a count-only tail; more than this and the report stops being scannable. */
    public const COMMIT_ROWS = 12;

    /** Conflicted paths listed before a "more" note. */
    public const CONFLICT_ROWS = 10;

    /**
     * The shape a worktree id may take before it may be embedded in a ref
     * name. `..` is rejected beyond the character class because git itself
     * refuses such components; ids minted by the task runtime always pass,
     * so this gates only forged-registry input.
     */
    private const REF_SAFE = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /** Shape a registry-held branch name must satisfy to be used as a git argument. */
    private const BRANCH_SAFE = '/^[A-Za-z0-9._\/-]+$/';

    /** Every static; the hand-off is a question, not a thing to hold. */
    private function __construct()
    {
    }

    /**
     * The read-only half: everything the merge-or-not decision needs.
     *
     * `known` answers whether THIS repository's registry names the id at
     * all. Once known, each git leg stands or falls independently and a
     * failed leg lands in `problems` by name — a vanished branch, a
     * repository with no HEAD yet, a merge-tree old enough to lack
     * `--write-tree` all produce a still-useful partial report rather than
     * a fatal, because the human reading it is deciding what to salvage.
     *
     * @return array{
     *     agentId: string, known: bool, branch: string|null,
     *     directoryPresent: bool, uncommitted: bool|null,
     *     headSha: string|null, ahead: int|null, behind: int|null,
     *     commits: list<string>, diffStat: string|null,
     *     mergeVerdict: 'clean'|'conflicts'|'unavailable',
     *     conflicts: list<string>, problems: list<string>
     * }
     */
    public static function inspect(string $repoRoot, string $agentId): array
    {
        $report = [
            'agentId' => $agentId,
            'known' => false,
            'branch' => null,
            'directoryPresent' => false,
            'uncommitted' => null,
            'headSha' => null,
            'ahead' => null,
            'behind' => null,
            'commits' => [],
            'diffStat' => null,
            'mergeVerdict' => 'unavailable',
            'conflicts' => [],
            'problems' => [],
        ];

        $manager = WorktreeManager::new($repoRoot);
        $branch = $manager->branchOf($agentId);
        if ($branch === null) {
            return $report;
        }

        $report['known'] = true;
        $report['branch'] = $branch;

        try {
            $path = $manager->worktreePath($agentId);
            $report['directoryPresent'] = true;
            $report['uncommitted'] = $manager->worktreeHasUncommittedDiff($path);
        } catch (\RuntimeException) {
            // Directory released out from under the registry: the branch
            // facts below still answer the decision, and `uncommitted`
            // stays null because anything that was uncommitted is gone.
        }

        if (preg_match(self::BRANCH_SAFE, $branch) !== 1) {
            $report['problems'][] = 'the registry names a branch this command will not run as a git argument';

            return $report;
        }

        $git = GitRunner::new($repoRoot);
        $self = $report;

        $head = $git->run('rev-parse', 'HEAD');
        if ($head['ok']) {
            $self['headSha'] = trim($head['stdout']);
        } else {
            $self['problems'][] = 'the main tree has no HEAD to measure against';
        }

        $ref = $git->run('rev-parse', '--verify', 'refs/heads/' . $branch);
        if (!$ref['ok']) {
            $self['problems'][] = 'the branch is gone from the repository';
            $report = self::absorb($report, $self);

            return $report;
        }

        $counts = $git->run('rev-list', '--left-right', '--count', 'HEAD...' . $branch);
        if ($counts['ok'] && preg_match('/^(\d+)[ \t]+(\d+)/', trim($counts['stdout']), $m) === 1) {
            // Left side is HEAD-only (the main tree moved), right side is
            // branch-only (work to hand over).
            $self['behind'] = (int) $m[1];
            $self['ahead'] = (int) $m[2];
        } else {
            $self['problems'][] = 'ahead/behind could not be counted';
        }

        $log = $git->run('rev-list', '--right-only', '--oneline', '--max-count=' . self::COMMIT_ROWS, 'HEAD...' . $branch);
        if ($log['ok']) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", trim($log['stdout']))), static fn (string $l): bool => $l !== ''));
            $self['commits'] = $lines;
        } else {
            $self['problems'][] = 'the branch commit list could not be read';
        }

        $stat = $git->run('diff', '--stat', 'HEAD...' . $branch);
        if ($stat['ok']) {
            $trimmed = rtrim($stat['stdout'], "\n");
            $self['diffStat'] = $trimmed === '' ? null : $trimmed;
        } else {
            $self['problems'][] = 'the diffstat could not be read';
        }

        $merge = $git->run('merge-tree', '--write-tree', '--name-only', 'HEAD', $branch);
        if (($merge['exitCode'] ?? 1) === 0 && $merge['ok']) {
            $self['mergeVerdict'] = 'clean';
        } elseif (($merge['exitCode'] ?? 0) === 1) {
            // First stdout line is the resulting (unwritten) tree oid; the
            // `--name-only` section after it lists conflicted paths.
            $lines = array_map('trim', explode("\n", trim($merge['stdout'])));
            array_shift($lines);
            $self['mergeVerdict'] = 'conflicts';
            $self['conflicts'] = array_values(array_filter($lines, static fn (string $l): bool => $l !== ''));
        } else {
            $self['mergeVerdict'] = 'unavailable';
            $self['problems'][] = 'the merge could not be predicted (git merge-tree failed)';
        }

        return self::absorb($report, $self);
    }

    /**
     * The explicit-yes half: merge the branch into a NEW ref, nothing else.
     *
     * Three refusals are load-bearing. Uncommitted changes refuse: the
     * plumbing moves committed history only, and applying through them
     * would strand exactly the work the human is judging. Conflicted or
     * unpredictable merges refuse: `--apply` is the "the report said it
     * would go" verb, not a conflict-resolution verb. An existing stamped
     * ref refuses: re-applying must not silently drop the merge it made a
     * second ago. On success the only repository change is one new ref
     * under `refs/heads/handoff/`; `master`, `HEAD`, the checkout and the
     * agent's own worktree are untouched bytes, and the merge commit
     * object carries GitRunner's own checkpoint identity, never the human's.
     *
     * @return array{ok: bool, reason: string, ref: string|null}
     */
    public static function apply(string $repoRoot, string $agentId, ?int $now = null): array
    {
        $report = self::inspect($repoRoot, $agentId);

        if (!$report['known'] || $report['branch'] === null) {
            return ['ok' => false, 'reason' => 'no worktree is on record for that id in this repository', 'ref' => null];
        }

        if (preg_match(self::REF_SAFE, $agentId) !== 1 || str_contains($agentId, '..')) {
            return ['ok' => false, 'reason' => 'the id cannot be embedded in a branch name', 'ref' => null];
        }

        if ($report['uncommitted'] === true) {
            return ['ok' => false, 'reason' => 'the working directory has uncommitted changes; commit them on the branch and hand off again', 'ref' => null];
        }

        if ($report['mergeVerdict'] !== 'clean') {
            $why = $report['mergeVerdict'] === 'conflicts'
                ? 'the merge conflicts (' . count($report['conflicts']) . ' file(s))'
                : 'the merge cannot be predicted';

            return ['ok' => false, 'reason' => 'refusing to apply: ' . $why, 'ref' => null];
        }

        $branch = (string) $report['branch'];
        $ref = 'refs/heads/handoff/' . $agentId . '-' . date('Ymd-His', $now ?? time());
        $git = GitRunner::new($repoRoot);

        if ($git->run('rev-parse', '--verify', $ref)['ok']) {
            return ['ok' => false, 'reason' => 'a hand-off ref already exists at that second; wait a moment and apply again', 'ref' => null];
        }

        // The prediction is re-run here, at apply's own instant: the main
        // tree may have moved between reading the report and saying --apply,
        // and a merge built on a stale tree oid would be a different merge
        // than the one the human approved.
        $merge = $git->run('merge-tree', '--write-tree', 'HEAD', $branch);
        if (!$merge['ok']) {
            return ['ok' => false, 'reason' => 'refusing to apply: the merge is no longer clean at apply time', 'ref' => null];
        }

        $treeOid = trim(explode("\n", trim($merge['stdout']))[0]);
        $commit = $git->run(
            'commit-tree',
            $treeOid,
            '-p',
            'HEAD',
            '-p',
            $branch,
            '-m',
            "Merge branch '" . $branch . "' (agent hand-off of '" . $agentId . "')",
        );
        if (!$commit['ok']) {
            return ['ok' => false, 'reason' => 'the merge commit object could not be written', 'ref' => null];
        }

        $publish = $git->run('update-ref', $ref, trim($commit['stdout']));
        if (!$publish['ok']) {
            return ['ok' => false, 'reason' => 'the hand-off ref could not be created', 'ref' => null];
        }

        return ['ok' => true, 'reason' => '', 'ref' => substr($ref, strlen('refs/heads/'))];
    }

    /**
     * Fold the mid-flight legs back into the skeleton. Kept as a two-line
     * array merge rather than mutating in place so the skeleton above stays
     * the single readable definition of the report shape.
     *
     * @param array<string,mixed> $report
     * @param array<string,mixed> $legs
     *
     * @return array<string,mixed>
     */
    private static function absorb(array $report, array $legs): array
    {
        foreach ($legs as $key => $value) {
            $report[$key] = $value;
        }

        return $report;
    }
}
