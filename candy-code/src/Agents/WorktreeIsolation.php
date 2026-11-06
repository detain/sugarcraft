<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

/**
 * The `isolation: worktree` machinery, shared by its two consumers (G2,
 * wf-g2g3): {@see TaskTool} for delegated sub-agent runs and
 * {@see EngineExecutor} for workflow stage runs. One copy on purpose — a
 * stage that declares isolation must land in exactly the same kind of tree,
 * with exactly the same refuse-not-degrade law and the same result note,
 * as a delegation that declares it.
 *
 * The law both consumers obey: a run that asks for a worktree and cannot
 * get one is NOT started in the shared checkout. The refusal is the result;
 * the run's work never silently degrades into the user's tree. At the end
 * of an isolated run, a tree that holds no work is removed with its branch;
 * a tree with changes is kept, and the note says where and on which branch
 * so the driver (a model reading a Task result, or a workflow reading stage
 * output) can find the work.
 */
final class WorktreeIsolation
{
    private function __construct()
    {
    }

    /**
     * The git worktree an `isolation: worktree` run works in (roadmap 4.9):
     * the one its suspension kept when it is still there, else a new one for
     * this run. Answers it, or null and why the run cannot be isolated.
     *
     * @return array{0: array{id: string, path: string}|null, 1: string}
     */
    public static function enter(?WorktreeManager $manager, string $agentName, string $subAgentId, mixed $kept): array
    {
        if ($manager === null) {
            return [null, sprintf(
                'agent "%s" declares `isolation: worktree`, but this session has no worktree manager, so the run'
                . ' cannot be isolated and was not started; remove `isolation:` from the preset, or delegate from'
                . ' a session launched in a git checkout',
                $agentName,
            )];
        }
        if (\is_string($kept) && $kept !== '') {
            try {
                $path = $manager->worktreePath($kept);
                if (is_dir($path)) {
                    return [['id' => $kept, 'path' => $path], ''];
                }
            } catch (\RuntimeException) {
                // Swept or removed since: the resumed run starts a fresh tree.
            }
        }

        try {
            // A cheap, rate-limited sweep of stale trees that hold no work.
            $manager->sweepIfDue();
        } catch (\RuntimeException) {
            // A sweep that failed has already said so; it never blocks a run.
        }
        try {
            return [['id' => $subAgentId, 'path' => $manager->createWorktree($subAgentId)], ''];
        } catch (\RuntimeException|\InvalidArgumentException $failure) {
            return [null, sprintf(
                'agent "%s" declares `isolation: worktree`, but its worktree could not be created (%s), so the'
                . ' run was not started',
                $agentName,
                $failure->getMessage(),
            )];
        }
    }

    /**
     * The end of an isolated run (roadmap 4.9): its worktree is removed with
     * its branch when it holds no work, and kept otherwise — never deleted
     * with changes in it. Answers the note the result carries (empty when
     * nothing remains) and the id of a kept tree, for the suspension.
     *
     * @param array{id: string, path: string}|null $worktree
     * @return array{0: string, 1: ?string}
     */
    public static function leave(?WorktreeManager $manager, ?array $worktree, string $agentName): array
    {
        if ($worktree === null || $manager === null) {
            return ['', null];
        }
        try {
            if ($manager->releaseIfUnused($worktree['id'])) {
                return ['', null];
            }
        } catch (\RuntimeException $failure) {
            return [sprintf(
                "\n\n[sub-agent \"%s\" worked in its own git worktree at %s, which made no changes but could not be"
                . ' removed: %s]',
                $agentName,
                $worktree['path'],
                $failure->getMessage(),
            ), $worktree['id']];
        }
        $branch = $manager->branchOf($worktree['id']);

        return [sprintf(
            "\n\n[sub-agent \"%s\" worked in its own git worktree; its changes were kept there, not in this checkout:"
            . ' %s%s. Review them and merge what you want (a resume continues in the same tree).]',
            $agentName,
            $worktree['path'],
            $branch === null ? '' : ' on branch ' . $branch,
        ), $worktree['id']];
    }
}
