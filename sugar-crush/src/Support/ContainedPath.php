<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * "Does this path really live under that one?" — the one resolution every read
 * whose LOCATION a cloned repository chooses goes through, for the reason
 * {@see HomeDirectory} is the one home-directory resolution: two
 * implementations of a security predicate is how the second one stays wrong.
 *
 * THERE IS NO TIER COUNT AT THE HEAD OF THIS PARAGRAPH ANY MORE, and its removal
 * is the finding rather than a tidy-up. It said FIVE, then SEVEN, then EIGHT, and
 * each revision was wrong within a round or two — because "tier" was never
 * defined in a way that survived the next subsystem. The five it started with
 * were `native workflows, native skills, native agent presets, native custom
 * commands, instruction files`, which counts SUBSYSTEMS; the three that were
 * missing ({@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry}'s
 * `{projectRoot}/.claude/agents` AND `{projectRoot}/.opencode/agents`, and
 * {@see \SugarCraft\Crush\Memory\ForeignMemoryImporter}'s
 * `{projectRoot}/.opencode/memory`) count DIRECTORIES in one place and subsystems
 * in another; and the eighth ({@see \SugarCraft\Crush\Agents\WorktreeConfig}
 * with {@see \SugarCraft\Crush\Agents\WorktreeManager}) counted two files as one.
 * The TENTH and ELEVENTH escapes, below, were then USER tiers of subsystems
 * already counted, so the number could not move at all while two live paths were
 * open. A figure whose domain shifts to keep it true is worse than no figure. The
 * counts that remain here are per-file and derived — asserted by
 * {@see \SugarCraft\Crush\Tests\Support\ContainedPathInventoryTest} — and the
 * read/execute paths themselves are enumerated and each required to name its gate
 * by {@see \SugarCraft\Crush\Tests\Support\ReadPathCensusTest}, which is the
 * instrument that can see an absence.
 *
 * The three the FIVE omitted were not oversights of wording: they were three read
 * paths with NO containment at all, in two DORMANT classes whose doc-blocks
 * honestly said they were unwired, and "unwired" was doing the work "gated"
 * should have been.
 *
 * THE TENTH WAS ARBITRARY CODE EXECUTION AND ITS INVENTORY ROW WAS GREEN.
 * {@see \SugarCraft\Crush\Workflows\WorkflowRegistry}'s USER tier — the directory
 * whose `.php` files it `require`s — had no call to this class at all, while the
 * file's derived row read `2` for its project tier's two. The premise written in
 * its constructor was that the user's own directory needs no confinement because
 * "a link inside it is the user pointing at their own file"; MEASURED on this
 * host with `$HOME` mode 0700, owned, and no `.git` anywhere, two spellings each
 * executed arbitrary PHP as the launching uid through `/workflow run`:
 *
 *     ~/.sugar-crush/workflows -> <outside>     load('pwned') EXECUTED uid=1000
 *     workflows/entry.php -> <outside>/x.php    load('entry') EXECUTED uid=1000
 *
 * The ELEVENTH is the same premise in
 * {@see \SugarCraft\Crush\Commands\CommandLoader::loadUserCommands()}, which
 * called `loadFromDirectory()` with the anchor argument OMITTED while its project
 * twin passed one: an outside file's body reached `CommandSpec::$template`, the
 * prompt, with `refusals=[]`. Its per-entry compare — counted in the row below —
 * could not help, because it resolves the boundary directory too and travels with
 * a link on it. Both are closed by giving the user tier the anchor its project
 * twin always had.
 *
 * THE EIGHTH IS {@see \SugarCraft\Crush\Agents\WorktreeConfig}'s, and it is the
 * one worth reading twice, because it was invisible to BOTH of the instruments
 * written to stop exactly this. `WorktreeConfig::new()` read
 * `__DIR__ . '/../../../.sugar-crush/config.json'` with no containment of any
 * kind and set `worktreeIncludeFile` from it;
 * {@see \SugarCraft\Crush\Agents\WorktreeManager} then read THAT file and turned
 * every line into a copy pattern. MEASURED on this host against the ungated
 * build, one line, `../secret/id_rsa`: read `<repoRoot>/../secret/id_rsa` and
 * wrote `<worktreePath>/../secret/id_rsa` — outside the checkout in one
 * direction and outside the worktree in the other. The inventory test could not
 * see it because it counts compares that are WRITTEN and this had none, and the
 * project-tier inventory could not see it because it classified
 * `.sugar-crush/config.json` from the STRING as user-tier, which is true of
 * {@see \SugarCraft\Crush\Cli\Bootstrap}'s call site and false of this one.
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
 *   - THIRTY-FOUR call sites in FOURTEEN files ask this class. THIS SENTENCE
 *     WAS FIVE SITES AND THREE FILES STALE when a reviewer measured it — it
 *     read "TWENTY-SEVEN in ELEVEN" while
 *     {@see \SugarCraft\Crush\Tests\Support\ContainedPathInventoryTest}'s
 *     derived map, one file away, summed to thirty-two across fourteen. The
 *     paragraph directly above says "THE INVENTORY BELOW IS NOT MAINTAINED BY
 *     HAND", and this restatement of it was, and drifted for three rounds
 *     exactly as the tier count above it had. It is now read back out of this
 *     file and compared to the derived map by
 *     `ContainedPathInventoryTest::testContainedPathsOwnDocBlockRestatesThisInventory()`,
 *     which is the only thing that has ever stopped a number here from rotting.
 *     The three files it was missing are
 *     {@see \SugarCraft\Crush\Config\LayeredSettings} (2 — the project
 *     `.sugar-crush` directory and the settings file inside it),
 *     {@see \SugarCraft\Crush\Commands\CommandSpec} (1) and
 *     {@see \SugarCraft\Crush\Context\RepoMapBlock} (3 — each candidate
 *     sub-package DIRECTORY, every `composer.json` opened, and each
 *     `autoload.psr-4` source root; the first two arrived a round after the
 *     third, when the walk turned out to be ungated while the values were
 *     gated). The ELEVENTH to arrive was
 *     {@see \SugarCraft\Crush\Cli\Bootstrap} (1 — `$root/.mcp.json`, the MCP
 *     server config, against the root that named it), and it is the first entry
 *     here that arrived WITH its read rather than after it: nothing in `src/`
 *     constructed an {@see \SugarCraft\Crush\MCP\McpClient} at all, so the
 *     config path had no producer to bound. It is ONE compare, not the
 *     anchor-plus-entry pair, because the file sits directly in `$root` and a
 *     tree cannot be confined to itself — `WorktreeManager`'s two entry-level
 *     compares are the same shape. The tenth is
 *     {@see \SugarCraft\Crush\Providers\ProviderFactory} (2 — the config DIRECTORY
 *     against the tree containing the package, and `config.dev.json` against that
 *     directory), which held the SAME `__DIR__`-relative, containment-free
 *     construction as `WorktreeConfig`'s and appeared on neither inventory:
 *     {@see \SugarCraft\Crush\Workflows\WorkflowRegistry} (3 — entry, PROJECT
 *     directory anchor, USER directory anchor; the third is the TENTH read path,
 *     see below), {@see \SugarCraft\Crush\Skills\SkillLoader} (3 — entry, directory
 *     anchor, skill asset), {@see \SugarCraft\Crush\Agents\AgentPresetRegistry}
 *     (3 — the same pair, plus `load()`'s single-file arm),
 *     {@see \SugarCraft\Crush\Commands\CommandLoader} (2),
 *     {@see \SugarCraft\Crush\Context\InstructionFileLoader} (6 — one per read
 *     decision it makes),
 *     {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry} (2 — entry,
 *     directory anchor), {@see \SugarCraft\Crush\Memory\ForeignMemoryImporter}
 *     (2 — the same pair), {@see \SugarCraft\Crush\Agents\WorktreeConfig} (2 —
 *     the config DIRECTORY against the tree containing the package, and
 *     `config.json` against that directory) and
 *     {@see \SugarCraft\Crush\Agents\WorktreeManager} (2 — the include FILE
 *     against the repo root, and each copy pattern's source against it too).
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
 *     Its THIRD guard, `patternStaysInside()`, is not a prefix compare at all —
 *     it walks a pattern's segments — and the reason it does not route here is
 *     the reason `BashEscapeDenyHook` does not: it judges a destination path
 *     that does not exist yet, which this class refuses outright.
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
