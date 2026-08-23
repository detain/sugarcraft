<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\InstructionFileLoader;

/**
 * The checkout boundary on {@see InstructionFileLoader}'s reads.
 *
 * Two escapes were REPRODUCED on this host before the gates that close them
 * existed, and the bytes below are the bytes that came back — this file is that
 * reproduction, kept executable:
 *
 *   repoRoot = <sb>/repo, `<sb>/repo/CLAUDE.md -> <sb>/outside/secret.txt`
 *     loadRoot()                              => ["TOP-SECRET-AAA\n"]
 *   repoRoot = <sb>/repo, `<sb>/CLAUDE.md` (an ANCESTOR of the checkout)
 *     loadForPath("<sb>/outside/anything.php") => "ANCESTOR-BBB\n"
 *
 * The first needs one committed symlink; the second needs NOTHING committed —
 * the walk terminated on `$dir !== $repoRoot`, a string equality a directory
 * outside the checkout never reaches, so it climbed to `/`. Both bodies went
 * into the system prompt as instruction documents: `Runtime::buildSystemPrompt()`
 * drains `loadRoot()`, and `Read`/`Edit`/`Write`/`Glob` call `loadForPath()` on
 * every path they touch.
 *
 * DOMAIN of that claim: measured against this class's four public read entry
 * points (`loadRoot()`, `loadForced()`, `loadForPath()`, and the `@import`
 * expansion the first and third run). `loadForced()` and the import gate were
 * already bounded before this file existed; the two controls at the bottom pin
 * that consolidating their compares onto {@see \SugarCraft\Crush\Support\ContainedPath}
 * did not change their verdicts.
 *
 * FIXTURES LIVE OUTSIDE ANY CHECKOUT, in this process's temp directory, because
 * the escapes are only expressible as symlinks and a symlink pointing out of a
 * repository must never be committed into one.
 *
 * THE PREDICATE-SWAP NOTE, recorded here because the source used to argue a
 * distinction it does not make. Swapping `within()` to `below()` at each of
 * `InstructionFileLoader`'s five EXECUTABLE call sites (the sixth match is a
 * `{@see}` cross-reference), one at a time, in a sandbox copy of `src/` +
 * `tests/`, leaves THIS FILE at **OK (27 tests, 37 assertions)** every time —
 * re-measured against this revision; the figure was 18/22 before this file grew
 * its finding-9/10/14 cases. Four of the five judge FILE entries, where equality
 * with a directory boundary is unreachable. The fifth — `loadForPath()`'s START
 * gate — was argued as a real decision ("a file sitting in the checkout ROOT is
 * inside the checkout") and is not one either: `$dir === $repoRoot` returns null
 * under BOTH predicates, because `while ($dir !== $repoRoot)` never runs. The
 * comments in `InstructionFileLoader` now say that rather than the reverse, and
 * they name the four DIRECTORY anchors elsewhere in the package where the two
 * predicates genuinely diverge and are each pinned by a test.
 */
final class InstructionFileLoaderContainmentTest extends TestCase
{
    /** The stand-in for "everything", holding both the fake checkout and the fake secrets. */
    private string $sandbox;

    /** The fake checkout — the boundary every assertion in this file is about. */
    private string $repoRoot;

    /** Deliberately a SIBLING of the checkout, not a child of it. */
    private string $outside;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/instruction_loader_containment_' . uniqid((string) getmypid(), true);
        $this->repoRoot = $this->sandbox . '/repo';
        $this->outside = $this->sandbox . '/outside';

        mkdir($this->repoRoot . '/src', 0o777, true);
        mkdir($this->outside, 0o777, true);

        file_put_contents($this->outside . '/secret.txt', "TOP-SECRET-AAA\n");

        // The `@import` regex only matches a `.md` reference, so the import
        // control below needs the same secret spelled with that extension.
        file_put_contents($this->outside . '/secret.md', "TOP-SECRET-AAA\n");

        // An instruction file ABOVE the checkout root. Nothing inside the
        // checkout points at it; the old walk simply arrived here.
        file_put_contents($this->sandbox . '/CLAUDE.md', "ANCESTOR-BBB\n");
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->sandbox);
    }

    /**
     * `is_link()` FIRST, and this is not a style preference: `is_dir()` answers
     * true for a symlink to a directory, so the obvious recursive remover would
     * follow this test's own escape fixtures out of the sandbox and delete the
     * directory on the far side of them.
     */
    private function removeTree(string $dir): void
    {
        if (is_link($dir) || !is_dir($dir)) {
            if (is_link($dir) || is_file($dir)) {
                unlink($dir);
            }

            return;
        }

        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $this->removeTree($dir . '/' . $entry);
        }

        rmdir($dir);
    }

    // ─── loadRoot() — escape (a), the committed symlink ─────────────

    public function testLoadRootRefusesARootFileSymlinkedOutsideTheCheckout(): void
    {
        symlink($this->outside . '/secret.txt', $this->repoRoot . '/CLAUDE.md');

        $documents = (new InstructionFileLoader($this->repoRoot))->loadRoot();

        // The reproduced value was ["TOP-SECRET-AAA\n"]; the boundary makes the
        // whole document list empty, because the ONLY root file resolves out.
        $this->assertSame([], $documents);
        $this->assertStringNotContainsString('TOP-SECRET-AAA', implode("\n", $documents));
    }

    public function testLoadRootRefusesAgentsMdOutsideWhileKeepingTheRealClaudeMd(): void
    {
        file_put_contents($this->repoRoot . '/CLAUDE.md', '# real conventions');
        symlink($this->outside . '/secret.txt', $this->repoRoot . '/AGENTS.md');

        $documents = (new InstructionFileLoader($this->repoRoot))->loadRoot();

        // Per-ENTRY, not all-or-nothing: one refusal must not cost the other file.
        $this->assertSame(['# real conventions'], $documents);
    }

    public function testLoadRootStillReadsBothRealRootFiles(): void
    {
        file_put_contents($this->repoRoot . '/CLAUDE.md', '# C');
        file_put_contents($this->repoRoot . '/AGENTS.md', '# A');

        $this->assertSame(['# C', '# A'], (new InstructionFileLoader($this->repoRoot))->loadRoot());
    }

    /**
     * A symlink is not the defect — leaving the checkout is. Vendoring a shared
     * doc INSIDE the repository through a link keeps working.
     */
    public function testLoadRootFollowsASymlinkThatStaysInsideTheCheckout(): void
    {
        mkdir($this->repoRoot . '/docs');
        file_put_contents($this->repoRoot . '/docs/conventions.md', '# vendored in-repo');
        symlink($this->repoRoot . '/docs/conventions.md', $this->repoRoot . '/CLAUDE.md');

        $this->assertSame(['# vendored in-repo'], (new InstructionFileLoader($this->repoRoot))->loadRoot());
    }

    // ─── loadForPath() — escape (b), no symlink at all ──────────────

    public function testLoadForPathRefusesToWalkFromOutsideTheCheckout(): void
    {
        $result = (new InstructionFileLoader($this->repoRoot))
            ->loadForPath($this->outside . '/anything.php');

        // The reproduced value was "ANCESTOR-BBB\n" — <sb>/CLAUDE.md, one level
        // ABOVE the checkout the loader was constructed for.
        $this->assertNull($result);
    }

    /**
     * What the START gate does that the per-candidate gate CANNOT, measured
     * rather than asserted: with the start gate removed and the candidate gate
     * left in place, every escape in this file above is still refused — the
     * candidate gate alone is sufficient for containment. This is the one shape
     * it is not sufficient for.
     *
     * The candidate gate judges where a file RESOLVES. The start gate judges
     * where the walk was STANDING. An instruction file sitting ABOVE the
     * checkout whose target happens to be inside it satisfies the first and not
     * the second — so without the start gate a directory outside the repository
     * still gets to decide WHICH of the repository's own instruction files
     * governs a touched path, and the walk still stats its way to `/` for every
     * touched path that was never in the checkout to begin with.
     *
     * NOT a body-disclosure escape — the bytes delivered here come from inside
     * the checkout. It is a control escape, and it is why both gates are here.
     */
    public function testLoadForPathIgnoresAnAncestorInstructionFileThatLinksBackIntoTheCheckout(): void
    {
        mkdir($this->repoRoot . '/docs');
        file_put_contents($this->repoRoot . '/docs/inside.md', '# chosen from outside');
        unlink($this->sandbox . '/CLAUDE.md');
        symlink($this->repoRoot . '/docs/inside.md', $this->sandbox . '/CLAUDE.md');

        $this->assertNull(
            (new InstructionFileLoader($this->repoRoot))->loadForPath($this->outside . '/anything.php'),
        );
    }

    public function testLoadForPathRefusesToWalkFromAnAbsolutePathWithNoRelationToTheCheckout(): void
    {
        // The starting directory is not merely a sibling: it shares no ancestor
        // with the checkout below `/`, which is the shape that climbed furthest.
        $this->assertNull(
            (new InstructionFileLoader($this->repoRoot))->loadForPath('/etc/anything.php'),
        );
    }

    /**
     * The second half of the same walk: the DIRECTORIES are contained but an
     * ENTRY inside one need not be.
     */
    public function testLoadForPathRefusesANestedFileSymlinkedOutsideTheCheckout(): void
    {
        symlink($this->outside . '/secret.txt', $this->repoRoot . '/src/CLAUDE.md');

        $result = (new InstructionFileLoader($this->repoRoot))
            ->loadForPath($this->repoRoot . '/src/Component.php');

        $this->assertNull($result);
    }

    public function testLoadForPathSkipsARefusedCandidateAndKeepsWalkingUp(): void
    {
        mkdir($this->repoRoot . '/src/deep');
        symlink($this->outside . '/secret.txt', $this->repoRoot . '/src/deep/CLAUDE.md');
        file_put_contents($this->repoRoot . '/src/CLAUDE.md', '# src conventions');

        $result = (new InstructionFileLoader($this->repoRoot))
            ->loadForPath($this->repoRoot . '/src/deep/Component.php');

        // A refusal skips the ENTRY, not the walk — the legitimate ancestor
        // instruction file one level up is still delivered.
        $this->assertSame('# src conventions', $result);
    }

    public function testLoadForPathStillFindsALegitimateNestedFile(): void
    {
        file_put_contents($this->repoRoot . '/src/CLAUDE.md', '# src conventions');

        $this->assertSame(
            '# src conventions',
            (new InstructionFileLoader($this->repoRoot))->loadForPath($this->repoRoot . '/src/Component.php'),
        );
    }

    /**
     * The third shape of escape (b), and the one needing nothing committed AND
     * no path outside the checkout: the walk compared an UNRESOLVED `$dir`
     * against a `realpath()`-resolved `$repoRoot`, so a checkout merely REACHED
     * through a symlink — a worktree under `/var/…` reached via `~/work/repo`,
     * or macOS's `/tmp` -> `/private/tmp` — never matched the loop's terminator
     * and climbed past its own root.
     */
    public function testLoadForPathDoesNotClimbAboveACheckoutSpelledThroughASymlink(): void
    {
        symlink($this->repoRoot, $this->sandbox . '/link-repo');

        $result = (new InstructionFileLoader($this->sandbox . '/link-repo'))
            ->loadForPath($this->sandbox . '/link-repo/src/Component.php');

        // <sb>/CLAUDE.md exists and is one dirname() step above the checkout.
        $this->assertNull($result);
    }

    /**
     * Why the walk starts from a `realpath()` and not from the spelling it was
     * handed, pinned rather than argued. Measured: reverting only that one line
     * to `dirname($touchedPath)` leaves every OTHER assertion in this file green,
     * because the per-candidate gate catches the bodies. What it does not catch
     * is the walk's EXTENT — an unresolved `$dir` never equals the resolved
     * `$repoRoot`, so the loop's terminator never fires and it climbs to `/`.
     *
     * This is the two halves meeting: a checkout REACHED through a symlink, plus
     * an instruction file above it whose target is inside it. The candidate gate
     * says yes (it resolves inside), the loop should never have been standing
     * there, and only the resolved walk stops it.
     */
    public function testAWalkStartedFromAResolvedDirectoryStopsAtTheCheckoutRoot(): void
    {
        mkdir($this->repoRoot . '/docs');
        file_put_contents($this->repoRoot . '/docs/inside.md', '# chosen from above the checkout');
        unlink($this->sandbox . '/CLAUDE.md');
        symlink($this->repoRoot . '/docs/inside.md', $this->sandbox . '/CLAUDE.md');
        symlink($this->repoRoot, $this->sandbox . '/link-repo');

        $this->assertNull(
            (new InstructionFileLoader($this->sandbox . '/link-repo'))
                ->loadForPath($this->sandbox . '/link-repo/src/Component.php'),
        );
    }

    /**
     * A DELIBERATE narrowing recorded as a test rather than left implicit: the
     * walk now starts from a `realpath()`, so a touched path in a directory that
     * does not exist yet stops here instead of climbing from a lexical string.
     * No live caller reaches it — `Read`/`Edit`/`Glob` have the file open, and
     * `Write` `mkdir -p`s the parent BEFORE calling — and the pre-existing
     * `/nonexistent/path/file.php` case in
     * {@see InstructionFileLoaderTest::testLoadForPathHandlesFilesystemErrorsGracefully}
     * already expected null.
     */
    public function testLoadForPathReturnsNullForADirectoryThatDoesNotExistYet(): void
    {
        file_put_contents($this->repoRoot . '/src/CLAUDE.md', '# src conventions');

        $this->assertNull(
            (new InstructionFileLoader($this->repoRoot))
                ->loadForPath($this->repoRoot . '/src/not/created/yet/New.php'),
        );
    }

    public function testLoadForPathReturnsNullWhenTheCheckoutItselfDoesNotResolve(): void
    {
        $this->assertNull(
            (new InstructionFileLoader($this->sandbox . '/no-such-checkout'))
                ->loadForPath($this->repoRoot . '/src/Component.php'),
        );
    }

    // ─── controls for the two compares that were already bounded ────

    public function testLoadForcedStillRefusesAMatchResolvingOutsideTheCheckout(): void
    {
        symlink($this->outside . '/secret.txt', $this->repoRoot . '/vendored.md');

        $loader = new InstructionFileLoader($this->repoRoot, ['*.md']);

        $this->assertSame([], $loader->loadForced());
    }

    public function testLoadForcedStillReadsAnInRepoMatch(): void
    {
        file_put_contents($this->repoRoot . '/forced.md', '# forced');

        $this->assertSame(
            ['# forced'],
            (new InstructionFileLoader($this->repoRoot, ['forced.md']))->loadForced(),
        );
    }

    public function testAnImportResolvingOutsideTheCheckoutIsStillBlockedWithANote(): void
    {
        file_put_contents(
            $this->repoRoot . '/CLAUDE.md',
            "# root\n@../outside/secret.md\n",
        );

        $documents = (new InstructionFileLoader($this->repoRoot))->loadRoot();

        $this->assertCount(1, $documents);
        $this->assertStringContainsString('import-blocked', $documents[0]);
        $this->assertStringNotContainsString('TOP-SECRET-AAA', $documents[0]);
    }

    public function testAnInRepoImportIsStillExpanded(): void
    {
        file_put_contents($this->repoRoot . '/src/detail.md', 'DETAIL-CCC');
        file_put_contents($this->repoRoot . '/CLAUDE.md', "# root\n@./src/detail.md\n");

        $documents = (new InstructionFileLoader($this->repoRoot))->loadRoot();

        $this->assertCount(1, $documents);
        $this->assertStringContainsString('DETAIL-CCC', $documents[0]);
    }

    // ─── the walk climbs the RESOLVED tree, not the spelled one ─────

    /**
     * WHICH FILE GOVERNS, when the touched path is inside a symlinked in-repo
     * subtree. Documented when the `realpath()` on the start directory landed,
     * pinned by nothing until now.
     *
     * `<root>/spelled -> <root>/a/b/nested`. Climbing the SPELLED tree goes
     * `<root>/spelled` -> `<root>` and stops at the root with nothing found.
     * Climbing the RESOLVED tree goes `<root>/a/b/nested` -> `<root>/a/b` ->
     * `<root>/a`, where the instruction file is. Both are defensible readings;
     * only one is what the code does, and this is it.
     */
    public function testTheWalkClimbsTheResolvedTreeNotTheSpelledOne(): void
    {
        mkdir($this->repoRoot . '/a/b/nested', 0o777, true);
        file_put_contents($this->repoRoot . '/a/CLAUDE.md', '# A-GOVERNS');
        symlink($this->repoRoot . '/a/b/nested', $this->repoRoot . '/spelled');

        $this->assertSame(
            '# A-GOVERNS',
            (new InstructionFileLoader($this->repoRoot))
                ->loadForPath($this->repoRoot . '/spelled/Component.php'),
        );
    }

    /**
     * The DORMANT half of the same narrowing, pinned so it stops being an
     * argument. {@see \SugarCraft\Crush\Tools\BuiltIn\Write} takes a
     * `$worktreeJail` constructor argument; a write routed through a jail
     * OUTSIDE `repoRoot` gets no nested instruction file, where the pre-gate
     * lexical walk would have delivered the worktree's own.
     *
     * Latent, not live: `Bootstrap::tools()` constructs `Write` with
     * `$root` and no jail, so nothing reaches this today. It is a test rather
     * than a sentence because the next person to pass a jail needs the
     * consequence to be visible, and because "a worktree's own CLAUDE.md is
     * silently not delivered" is not something a shorter prompt can say.
     */
    public function testAWorktreeOutsideTheCheckoutGetsNoNestedInstructionFile(): void
    {
        $worktree = $this->sandbox . '/worktree';
        mkdir($worktree . '/src', 0o777, true);
        file_put_contents($worktree . '/src/CLAUDE.md', '# WORKTREE-CONVENTIONS');

        $loader = new InstructionFileLoader($this->repoRoot);

        $this->assertNull($loader->loadForPath($worktree . '/src/Component.php'));
        $this->assertArrayHasKey($worktree . '/src/Component.php', $loader->refusedPaths());
    }

    // ─── the refusal seam ───────────────────────────────────────────

    /**
     * THE MONOREPO LAYOUT THE SILENT SKIP COSTS. A per-library `CLAUDE.md`
     * symlinked to the monorepo root's shared one is the natural shape for a
     * `--root <lib>` run in a repository whose root `CLAUDE.md` IS the shared
     * file — and it is refused, correctly, with nothing said anywhere.
     *
     * Adding the SEAM needs neither `Runtime` nor `Bootstrap`; only a DISPLAY
     * would. This pins the seam. Whether anything shows it is a separate call.
     */
    public function testARefusedRootFileIsRecordedRatherThanOnlySkipped(): void
    {
        symlink($this->outside . '/secret.txt', $this->repoRoot . '/CLAUDE.md');

        $loader = new InstructionFileLoader($this->repoRoot);
        $this->assertSame([], $loader->loadRoot());

        $refusals = $loader->refusedPaths();
        $this->assertArrayHasKey($this->repoRoot . '/CLAUDE.md', $refusals);
        $this->assertStringContainsString($this->repoRoot, $refusals[$this->repoRoot . '/CLAUDE.md']);
        $this->assertStringNotContainsString('TOP-SECRET-AAA', implode("\n", $refusals));
    }

    public function testARefusedNestedCandidateIsRecorded(): void
    {
        symlink($this->outside . '/secret.txt', $this->repoRoot . '/src/CLAUDE.md');

        $loader = new InstructionFileLoader($this->repoRoot);
        $loader->loadForPath($this->repoRoot . '/src/Component.php');

        $this->assertArrayHasKey($this->repoRoot . '/src/CLAUDE.md', $loader->refusedPaths());
    }

    public function testARefusedForcedMatchIsRecorded(): void
    {
        symlink($this->outside . '/secret.txt', $this->repoRoot . '/vendored.md');

        $loader = new InstructionFileLoader($this->repoRoot, ['*.md']);
        $loader->loadForced();

        $this->assertArrayHasKey($this->repoRoot . '/vendored.md', $loader->refusedPaths());
    }

    public function testABlockedImportIsRecordedAsWellAsNoted(): void
    {
        file_put_contents($this->repoRoot . '/CLAUDE.md', "# root\n@../outside/secret.md\n");

        $loader = new InstructionFileLoader($this->repoRoot);
        $loader->loadRoot();

        $this->assertArrayHasKey((string) realpath($this->outside . '/secret.md'), $loader->refusedPaths());
    }

    /**
     * ACCUMULATED, not per-call, and the asymmetry with the sibling seams is
     * deliberate: `loadRoot()`/`loadForced()` memoize and `loadForPath()` is
     * called once per touched path, so a map rebuilt per call would report the
     * last touched path's refusals and forget the root file's.
     */
    public function testRefusalsFromDifferentReadPathsAccumulate(): void
    {
        symlink($this->outside . '/secret.txt', $this->repoRoot . '/CLAUDE.md');
        symlink($this->outside . '/secret.txt', $this->repoRoot . '/src/AGENTS.md');

        $loader = new InstructionFileLoader($this->repoRoot);
        $loader->loadRoot();
        $loader->loadForPath($this->repoRoot . '/src/Component.php');

        $this->assertSame(
            [$this->repoRoot . '/CLAUDE.md', $this->repoRoot . '/src/AGENTS.md'],
            array_keys($loader->refusedPaths()),
        );
    }

    /** A clean checkout refuses nothing, so the map is not a permanent noise source. */
    public function testACleanCheckoutRecordsNoRefusals(): void
    {
        file_put_contents($this->repoRoot . '/CLAUDE.md', '# C');
        file_put_contents($this->repoRoot . '/src/CLAUDE.md', '# src');

        $loader = new InstructionFileLoader($this->repoRoot);
        $loader->loadRoot();
        $loader->loadForPath($this->repoRoot . '/src/Component.php');

        $this->assertSame([], $loader->refusedPaths());
    }

    // ─── the read is on the RESOLVED path ───────────────────────────

    /**
     * The narrowing behind finding #14: `loadForced()` read the `realpath()` it
     * had already resolved while `loadRoot()`/`loadForPath()` re-resolved the
     * SPELLED path a second time at read, which is a wider TOCTOU window in the
     * two paths the containment work had just fixed than in the one it left
     * alone. All three now read resolved.
     *
     * WHAT THIS ASSERTS AND WHAT IT CANNOT: the window is narrowed, not closed
     * — the read still follows whatever the resolved path names at read time —
     * so this pins the OBSERVABLE consequence instead, which is that the import
     * base directory did NOT move with it. An `@./detail.md` inside a CLAUDE.md
     * a repository vendored through a link still means "next to where the
     * repository put the link", and reading the resolved file while resolving
     * imports from the resolved directory would silently rename that import.
     */
    public function testASymlinkedRootFileResolvesItsImportsFromWhereTheRepositoryPutIt(): void
    {
        mkdir($this->repoRoot . '/docs');
        file_put_contents($this->repoRoot . '/docs/conventions.md', "# vendored\n@./detail.md\n");
        file_put_contents($this->repoRoot . '/docs/detail.md', 'DOCS-SIDE-DETAIL');
        file_put_contents($this->repoRoot . '/detail.md', 'ROOT-SIDE-DETAIL');
        symlink($this->repoRoot . '/docs/conventions.md', $this->repoRoot . '/CLAUDE.md');

        $documents = (new InstructionFileLoader($this->repoRoot))->loadRoot();

        $this->assertCount(1, $documents);
        $this->assertStringContainsString('ROOT-SIDE-DETAIL', $documents[0]);
        $this->assertStringNotContainsString('DOCS-SIDE-DETAIL', $documents[0]);
    }

    /**
     * ACCUMULATED REFUSALS CAN OUTLIVE THEIR CONDITION, and the one that is
     * re-decided must not.
     *
     * {@see InstructionFileLoader::refusedPaths()} accumulates rather than
     * recomputes — deliberately, because `loadForPath()` is called once per
     * touched path over a whole session and a per-call map would report the last
     * one's refusals and forget the root file's. What its doc-block never said,
     * and what a consumer will get wrong, is that an entry is a statement about
     * the moment it was made. MEASURED before this fix:
     *
     *     loadForPath('<repo>/notyet/x.php')  -> null, refused
     *     mkdir notyet; write notyet/CLAUDE.md
     *     loadForPath('<repo>/notyet/x.php')  -> "LEGIT\n"
     *     refusedPaths()                      -> STILL names it refused
     */
    public function testARefusalIsDroppedWhenTheSamePathIsReDecidedAndSucceeds(): void
    {
        $loader = new InstructionFileLoader($this->repoRoot);
        $touched = $this->repoRoot . '/notyet/x.php';

        $this->assertNull($loader->loadForPath($touched));
        $this->assertArrayHasKey($touched, $loader->refusedPaths());

        mkdir($this->repoRoot . '/notyet');
        file_put_contents($this->repoRoot . '/notyet/CLAUDE.md', "LEGIT\n");

        $this->assertStringContainsString('LEGIT', (string) $loader->loadForPath($touched));
        $this->assertArrayNotHasKey(
            $touched,
            $loader->refusedPaths(),
            'a refusal that has become untrue must not survive the call that disproved it',
        );
    }

    /**
     * The other direction, so the `unset()` above is a re-decision and not an
     * erasure: a path that is STILL refused keeps its entry across calls, and a
     * DIFFERENT path's refusal is untouched by re-deciding this one.
     */
    public function testReDecidingOnePathLeavesEveryOtherRefusalStanding(): void
    {
        $loader = new InstructionFileLoader($this->repoRoot);
        $first = $this->repoRoot . '/absent-a/x.php';
        $second = $this->repoRoot . '/absent-b/y.php';

        $loader->loadForPath($first);
        $loader->loadForPath($second);
        $loader->loadForPath($first);

        $this->assertArrayHasKey($first, $loader->refusedPaths(), 'still refused, so still recorded');
        $this->assertArrayHasKey($second, $loader->refusedPaths(), 'and a sibling refusal is untouched');
    }
}
