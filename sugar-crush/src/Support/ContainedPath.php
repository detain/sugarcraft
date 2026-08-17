<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * "Does this path really live under that one?" — the one resolution every read
 * whose LOCATION a cloned repository chooses goes through, for the reason
 * {@see HomeDirectory} is the one home-directory resolution: two
 * implementations of a security predicate is how the second one stays wrong.
 *
 * SEVEN TIERS, and this paragraph said FIVE for a round — which is the same
 * failure as the counts below, in the sentence that frames them. The three it
 * omitted were not oversights of wording: they were three read paths with NO
 * containment at all, in two DORMANT classes whose doc-blocks honestly said
 * they were unwired, and "unwired" was doing the work "gated" should have been.
 * Native workflows, native skills, native agent presets, native custom commands
 * and instruction files were the five; {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry}'s
 * `{projectRoot}/.claude/agents` and `{projectRoot}/.opencode/agents`, and
 * {@see \SugarCraft\Crush\Memory\ForeignMemoryImporter}'s
 * `{projectRoot}/.opencode/memory`, are the three that were missing. MEASURED
 * on this host before they were gated, with `.claude/agents` symlinked out of a
 * fixture checkout:
 *
 *     FOREIGN discoverClaude:  presets=["leak"] permissionMode=bypass-permissions
 *                              initialPrompt='SIXTH-ESCAPE-BODY sk-live-CAFEBABE'
 *     NATIVE  agentPresets():  presets=[]       refusals={…"outside the checkout"…}
 *
 * — the native tier refusing the byte-identical shape, with a message
 * describing exactly the harm the foreign tier was performing.
 *
 * THE INVENTORY BELOW IS NOT MAINTAINED BY HAND. Every figure in it is derived
 * from `src/` and asserted by
 * {@see \SugarCraft\Crush\Tests\Support\ContainedPathInventoryTest}, because
 * three successive revisions of this paragraph carried a count that had stopped
 * matching the tree — and one of them named a file's two innocuous compares in a
 * way that made the file read as audited while its two PRIMARY read paths had no
 * compare at all (see below). Per-file, executable lines only:
 *
 *   - NINETEEN call sites in SEVEN files ask this class:
 *     {@see \SugarCraft\Crush\Workflows\WorkflowRegistry} (2 — entry, directory
 *     anchor), {@see \SugarCraft\Crush\Skills\SkillLoader} (3 — entry, directory
 *     anchor, skill asset), {@see \SugarCraft\Crush\Agents\AgentPresetRegistry}
 *     (3 — the same pair, plus `load()`'s single-file arm),
 *     {@see \SugarCraft\Crush\Commands\CommandLoader} (2),
 *     {@see \SugarCraft\Crush\Context\InstructionFileLoader} (5 — one per read
 *     decision it makes),
 *     {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry} (2 — entry,
 *     directory anchor) and {@see \SugarCraft\Crush\Memory\ForeignMemoryImporter}
 *     (2 — the same pair).
 *   - EIGHT spellings remain by hand, in FOUR files, and they are a DIFFERENT
 *     CONTRACT rather than copies waiting to be swept up:
 *
 *     {@see \SugarCraft\Crush\Tools\PathJail} (5). TWO of them —
 *     `resolve()`'s and `resolveForCreate()`'s `realpath() === false` arms —
 *     answer for a path that does not exist YET, anchoring on the nearest
 *     existing ancestor so a file can be CREATED, and a predicate whose `false`
 *     covers "unresolvable" cannot express that. The other THREE (`resolve()`,
 *     `resolveForCreate()`, `resolveDir()`, one each) are exactly `within()`,
 *     spelled out against a `$resolved` the method already holds; an earlier
 *     revision here said they "want the canonical path back, not a verdict",
 *     which describes the METHOD's return type and not the compare's — each is
 *     `if (!contained) { return null; } return $resolved;`. The honest barrier
 *     for those three is one extra `realpath()` per call, not a contract
 *     mismatch.
 *
 *     {@see \SugarCraft\Crush\Tools\BuiltIn\Glob} (1) compares two paths this
 *     process resolved in the same statement, so routing it here would add a
 *     syscall to re-derive what is in the local variable.
 *
 *     {@see \SugarCraft\Crush\Tools\IgnoreRules}'s `relative()` (1) needs the
 *     REMAINDER, not a verdict.
 *
 *     {@see \SugarCraft\Crush\Hooks\BuiltIn\BashEscapeDenyHook}'s `within()` (1)
 *     judges a LEXICALLY collapsed path that deliberately need not exist. THE
 *     REASON GIVEN HERE USED TO BE BACKWARDS: it said `realpath() === false`
 *     would "invert a DENY into an allow". It does the opposite — the call site
 *     is `if (!within(...)) { deny; }`, so `false` denies, and `rm -rf
 *     /nonexistent`, the case that revision cited, is DENIED either way.
 *     Measured on this host, hand vs. consolidated:
 *
 *         rm -rf /nonexistent          hand=deny   consolidated=deny
 *         rm -rf ../outside            hand=deny   consolidated=deny
 *         touch sub/../newfile.txt     hand=allow  consolidated=DENY  <- diverges
 *         mkdir -p sub/deep/../made    hand=allow  consolidated=DENY  <- diverges
 *
 *     So the real reason is OVER-DENIAL of legitimate in-root work: this class
 *     refuses a path it cannot resolve, and a file about to be created does not
 *     resolve. Conclusion unchanged, mechanism corrected — and the mechanism is
 *     what made the entry read as a security argument.
 *
 *     {@see \SugarCraft\Crush\Agents\WorktreeManager}'s two prefix compares are
 *     NOT in this count and not omitted by oversight: they match relative paths
 *     against a glob directory, not a path against a boundary. The inventory
 *     test names them explicitly so the exclusion is a decision rather than a gap.
 *
 * WHAT THIS INVENTORY STILL CANNOT SEE, stated because assuming otherwise is how
 * finding #89 survived six review rounds: it counts the compares that are
 * WRITTEN AND ENFORCING (see
 * {@see \SugarCraft\Crush\Tests\Support\ContainedPathInventoryTest} for what
 * "enforcing" is measured to mean, and for the residue it still misses). A read
 * path with NO compare at all is invisible to it. That is
 * exactly what {@see \SugarCraft\Crush\Context\InstructionFileLoader} was — it
 * appeared in an earlier revision of this list on its two already-correct
 * compares, which is how a sweep instrumented on `grep -rn str_starts_with src/`
 * reported it as audited while `loadRoot()` returned the body of a file symlinked
 * out of the checkout and `loadForPath()` walked to `/`. The derived per-file
 * counts DO catch a check being deleted; only a reviewer reading the read paths
 * catches one never written.
 *
 * It was two implementations here, before any of the above.
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
 *
 * NEITHER ANSWER SEES A HARD LINK, and this is the THIRD limit rather than a
 * newly-discovered one — it was simply the one not written beside the two above.
 * MEASURED on this host: a hard link inside the boundary to a file outside it
 * answers `within = true, below = true`, and `file_get_contents()` on it returns
 * the outside file's bytes. That is `realpath()` behaving correctly — a hard
 * link is not a reference to another path, it is a second name for the same
 * inode, and there is no "original" for a resolver to find. It is out of the
 * threat model every caller here is written against, which is a CLONED
 * REPOSITORY: git cannot represent or commit a hard link, so no `git clone`
 * produces one. It is in scope for nothing this package currently does, and it
 * is written down so the next reviewer measures it once rather than
 * rediscovering it.
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
