<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * "Does this path really live under that one?" — the one resolution the FOUR
 * DISCOVERY TIERS go through (workflows, skills, agent presets, custom commands:
 * the directories whose LOCATION a cloned repository chooses), for the reason
 * {@see HomeDirectory} is the one home-directory resolution: two
 * implementations of a security predicate is how the second one stays wrong.
 *
 * THE DOMAIN OF THAT CLAIM, because a previous revision of this doc-block
 * asserted it over the whole package and the whole package is not what was
 * measured. Sweep run this session over `src/` — `grep -rn str_starts_with src/`
 * filtered to the lines comparing a path against a path — and read line by line:
 *
 *   - TEN executable call sites in FOUR files ask this class, and those four are
 *     exactly the discovery tiers a cloned repository can point somewhere:
 *     {@see \SugarCraft\Crush\Workflows\WorkflowRegistry} (2 — entry, directory
 *     anchor), {@see \SugarCraft\Crush\Skills\SkillLoader} (3 — entry, directory
 *     anchor, skill asset), {@see \SugarCraft\Crush\Agents\AgentPresetRegistry}
 *     (3 — the same pair, plus `load()`'s single-file arm) and
 *     {@see \SugarCraft\Crush\Commands\CommandLoader} (2). The preset,
 *     custom-command and skill-asset ones arrived here in the round that wrote
 *     this paragraph; before it they were hand-spelled, and the two preset ones
 *     had NO directory anchor at all — a third live instance of the identical
 *     relocatable-boundary escape, sitting in a subsystem the round that closed
 *     the other two named as sharing their trust model.
 *   - TEN spellings remain by hand, and they are a DIFFERENT CONTRACT rather
 *     than copies waiting to be swept up. {@see \SugarCraft\Crush\Tools\PathJail}
 *     (5: two arms of `resolve()`, two of `resolveForCreate()`, one in
 *     `resolveDir()`) has to answer for paths that do not exist yet — two of
 *     those five run on the `realpath() === false` branch, anchoring on the
 *     nearest existing ancestor so a file can be CREATED, and a predicate whose
 *     false covers "unresolvable" cannot express that. The other three want the
 *     canonical path back, not a verdict, which is the return type this class
 *     does not have.
 *     {@see \SugarCraft\Crush\Context\InstructionFileLoader} (2) and
 *     {@see \SugarCraft\Crush\Tools\BuiltIn\Glob} (1) compare two paths this
 *     process ALREADY resolved in the same statement, so routing them here would
 *     add a syscall to re-derive what is in the local variable.
 *     {@see \SugarCraft\Crush\Tools\IgnoreRules}'s `relative()` needs the
 *     REMAINDER, not a verdict, and {@see \SugarCraft\Crush\Hooks\BuiltIn\BashEscapeDenyHook}'s
 *     `within()` judges a LEXICALLY collapsed path that deliberately need not
 *     exist — `realpath()` returns false for the `rm -rf /nonexistent` it is
 *     there to deny, and false means "not contained" here, which for a DENY hook
 *     inverts into an allow. Those two would be actively wrong to consolidate.
 *
 * So: one implementation for the tiers whose boundary a repository can move, an
 * inventory rather than a claim for the rest, and a re-measurement is one `grep`
 * away in either direction.
 *
 * It was two implementations here.
 * {@see \SugarCraft\Crush\Workflows\WorkflowRegistry::containedIn()}
 * and {@see \SugarCraft\Crush\Skills\SkillLoader::contained()} each spelled out
 * `realpath` both sides, compare with a trailing separator — and the workflow
 * one grew a directory-level trust anchor that the skills one did not, so a
 * cloned repository could still commit `.claude/skills` ITSELF as a symlink and
 * put `SKILL.md` bodies from outside the checkout into the model's prompt
 * context. Both call sites now ask this class, and each one's doc-block points
 * here rather than restating the idiom.
 *
 * TWO QUESTIONS, not one, and keeping them apart is the whole interface:
 *
 *  - {@see within()} — may this ENTRY be read, given the directory it was
 *    reached from? An entry that resolves onto the boundary itself is fine
 *    (`skills/self -> .` is a cycle the walker's `$seen` set stops, not an
 *    escape), so equality counts as contained.
 *  - {@see below()} — may this DIRECTORY be trusted, given the checkout it was
 *    derived from? Equality is REFUSED here, and that is the whole difference:
 *    a repository committing `.sugar-crush/workflows -> ..` resolves its
 *    workflows directory exactly onto the checkout root, and a checkout root is
 *    not a curated directory of workflow files — it is the developer's working
 *    tree, untracked and gitignored files included. Measured on this host
 *    before the split: a repo shipping that one line had `/workflow list`
 *    enumerate `kubeconfig` and `local-secrets` out of the checkout and
 *    `/workflow run local-secrets` report that file's `description` into the
 *    transcript.
 *
 * BOTH SIDES ARE RESOLVED. A caller holding an already-canonical boundary pays
 * one redundant `realpath()` for it, which is a cached stat and the price of
 * there being one implementation: a predicate that trusted its caller to have
 * canonicalised would be a predicate whose correctness lives at the call sites.
 *
 * NEITHER ANSWER IS A SNAPSHOT. `realpath()` twice is two syscalls, so a
 * "contained" answer describes the filesystem at the instant it was computed
 * and not the instant the caller then reads. Callers that grant a path and read
 * it later must say so where they grant it — see
 * {@see \SugarCraft\Crush\Workflows\WorkflowRegistry::readableProjectDir()},
 * which narrows its own window rather than pretending it has none.
 */
final class ContainedPath
{
    /**
     * Does $path resolve inside $boundary, or ONTO it?
     *
     * The entry-level question. False when either side will not resolve: an
     * unresolvable path — a dangling link, a target this process cannot reach —
     * is not something to read.
     */
    public static function within(string $path, string $boundary): bool
    {
        return self::compare($path, $boundary, exactCounts: true);
    }

    /**
     * Does $path resolve STRICTLY inside $boundary?
     *
     * The trust-anchor question, and the strictness is load-bearing — see the
     * class doc-block for the checkout-root enumeration that `within()` used to
     * accept here.
     */
    public static function below(string $path, string $boundary): bool
    {
        return self::compare($path, $boundary, exactCounts: false);
    }

    private static function compare(string $path, string $boundary, bool $exactCounts): bool
    {
        // The empty string is refused here rather than left to `realpath()`,
        // which answers it with the PROCESS CWD instead of `false` — so an
        // empty boundary would silently anchor containment wherever the process
        // happened to be standing, and an empty path would be judged as the
        // CWD. No caller passes one today ({@see
        // \SugarCraft\Crush\Workflows\WorkflowRegistry}'s expandPath() special-
        // cases a root-only path back to `/` precisely because it used to
        // produce `''` for `--root /`), and that is the point: this class exists
        // so the predicate does not depend on its callers having got that
        // right.
        if ($path === '' || $boundary === '') {
            return false;
        }

        $realBoundary = realpath($boundary);
        $realPath = realpath($path);

        if ($realBoundary === false || $realPath === false) {
            return false;
        }

        if ($realPath === $realBoundary) {
            return $exactCounts;
        }

        // The trailing separator on BOTH sides is what stops `/a/b` being read
        // as containing `/a/bevil` — the prefix match that would have made the
        // boundary decorative. `rtrim()` on a boundary of `/` leaves the empty
        // string, which is why the separator is appended rather than assumed:
        // every absolute path starts with `/`, so a boundary of `/` contains
        // everything, which is the right answer for it.
        return str_starts_with($realPath . '/', rtrim($realBoundary, '/') . '/');
    }
}
