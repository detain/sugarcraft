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
        $this->sandbox = sys_get_temp_dir() . '/instruction_loader_containment_' . uniqid();
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
}
