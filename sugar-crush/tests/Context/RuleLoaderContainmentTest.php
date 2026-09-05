<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\RuleLoader;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * The containment boundaries {@see RuleLoader} takes from
 * {@see \SugarCraft\Crush\Support\ContainedPath}, and - the half the plan names
 * explicitly - that a refusal is RECORDED rather than silently skipped.
 *
 * Filed beside the boundary rather than with the behavioural suite because the
 * subject here is what the loader REFUSES. The plan's done-when is two clauses:
 * symlink a rules directory at `$HOME/.ssh` and assert refusal, AND a separate
 * test that the refusal is recorded. Both directory tiers reach `$HOME/.ssh` by
 * a different route (project via the standard checkout anchor, user via the
 * tighter `$HOME/.sugar-crush` anchor this step chose) and both are pinned, plus
 * the per-file entry boundary that stops a single rules file from smuggling
 * `~/.ssh/config` in as a prompt - the threat {@see \SugarCraft\Crush\Commands\CommandLoader}
 * states in its own comment.
 *
 * Every refusal test is paired with a positive control in this file: a test that
 * a legitimate, contained rules directory still loads. Without the control, a
 * refusal assertion passes against a loader that was simply changed to load
 * nothing at all.
 */
final class RuleLoaderContainmentTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir() . '/sugarcrush_rule_contain_' . uniqid('', true);
        mkdir($this->sandbox, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->scrubSandbox($this->sandbox);

        parent::tearDown();
    }

    /**
     * A helper writing a benign rule document: a mapping frontmatter plus a
     * sentinel body, so a test can tell "loaded" from "refused" by whether the
     * sentinel name appears in the result.
     */
    private function stageDoc(string $path, string $name): void
    {
        file_put_contents($path, "---\nname: {$name}\n---\nSENTINEL-{$name}-BODY\n");
    }

    /**
     * @param list<\SugarCraft\Crush\Context\Rule> $rules
     *
     * @return list<string>
     */
    private function titlesIn(array $rules): array
    {
        return array_map(static fn ($r): string => $r->name, $rules);
    }

    // -- Project tier: standard checkout anchor ------------------------------

    public function testAProjectRulesDirectoryLinkedOutOfTheCheckoutIsRefused(): void
    {
        $root = $this->sandbox . '/repo';
        mkdir($root . '/.sugar-crush', 0o755, true);
        mkdir($this->sandbox . '/private', 0o755, true);
        $this->stageDoc($this->sandbox . '/private/leak.md', 'leak');
        self::assertTrue(symlink($this->sandbox . '/private', $root . '/.sugar-crush/rules'));

        $loaded = (new RuleLoader($root))->loadProjectRules();

        self::assertSame([], $loaded, 'a rules directory resolving outside the checkout contributes nothing');
    }

    /**
     * THE SECOND HALF OF THE DONE-WHEN, asserted as its own test: the refusal is
     * RECORDED in the ledger, because a silently skipped read is indistinguishable
     * from an empty directory. Reads the SAME layout as the test above and checks
     * the ledger rather than only the (also-empty) return value.
     */
    public function testAProjectRulesRefusalIsRecordedWithItsBoundary(): void
    {
        $root = $this->sandbox . '/repo';
        $spelled = $root . '/.sugar-crush/rules';
        mkdir($root . '/.sugar-crush', 0o755, true);
        mkdir($this->sandbox . '/private', 0o755, true);
        $this->stageDoc($this->sandbox . '/private/leak.md', 'leak');
        self::assertTrue(symlink($this->sandbox . '/private', $spelled));

        $loader = new RuleLoader($root);
        $loader->loadProjectRules();
        $refused = $loader->refusedPaths();

        self::assertArrayHasKey($spelled, $refused, 'the refused directory is keyed by the path as spelled');
        self::assertStringContainsString($root, $refused[$spelled], 'the reason names the boundary it escaped');
    }

    // -- User tier: the plan's named $HOME/.ssh threat, via the tighter anchor

    public function testAUserRulesDirectoryLinkedAtHomeSshIsRefused(): void
    {
        // This is the plan's literal done-when. `$HOME/.ssh` is INSIDE `$HOME`, so
        // the $HOME anchor CommandLoader uses would ALLOW it (see the control test
        // further down); the rules user tier is anchored one level deeper at
        // `$HOME/.sugar-crush` precisely so this link is refused.
        $home = $this->sandbox . '/home';
        mkdir($home . '/.sugar-crush', 0o700, true);
        mkdir($home . '/.ssh', 0o700, true);
        $this->stageDoc($home . '/.ssh/id_ed25519.md', 'stolen-key');
        self::assertTrue(symlink($home . '/.ssh', $home . '/.sugar-crush/rules'));

        $this->useHomeSandbox($home, create: false);

        $loaded = (new RuleLoader($home))->loadUserRules();

        self::assertSame([], $loaded, 'a rules link at ~/.ssh is refused even though ~/.ssh is inside $HOME');
    }

    public function testAUserRulesRefusalIsRecorded(): void
    {
        $home = $this->sandbox . '/home';
        $spelled = $home . '/.sugar-crush/rules';
        mkdir($home . '/.sugar-crush', 0o700, true);
        mkdir($home . '/.ssh', 0o700, true);
        $this->stageDoc($home . '/.ssh/id_ed25519.md', 'stolen-key');
        self::assertTrue(symlink($home . '/.ssh', $spelled));

        $this->useHomeSandbox($home, create: false);

        $loader = new RuleLoader($home);
        $loader->loadUserRules();

        self::assertArrayHasKey($spelled, $loader->refusedPaths(), 'the user-tier refusal is recorded, not silent');
    }

    /**
     * THE CONTROL, and the recorded divergence: `~/.sugar-crush/rules` linked to
     * another directory INSIDE the home is REFUSED here, whereas CommandLoader's
     * `$HOME` anchor would (and, per its own test, intends to) still read it. This
     * pins the deliberate tightening: the boundary is the application's own
     * `.sugar-crush` namespace, not the whole home.
     */
    public function testAUserRulesDirectoryLinkedElsewhereInHomeIsRefusedByTheTighterAnchor(): void
    {
        $home = $this->sandbox . '/home';
        mkdir($home . '/.sugar-crush', 0o700, true);
        mkdir($home . '/dotfiles/rules', 0o700, true);
        $this->stageDoc($home . '/dotfiles/rules/mine.md', 'dotfile');
        self::assertTrue(symlink($home . '/dotfiles/rules', $home . '/.sugar-crush/rules'));

        $this->useHomeSandbox($home, create: false);

        $loader = new RuleLoader($home);
        $loaded = $loader->loadUserRules();

        self::assertSame([], $loaded, 'a link out of .sugar-crush - even to elsewhere in $HOME - is refused');
        self::assertArrayHasKey($home . '/.sugar-crush/rules', $loader->refusedPaths());
    }

    public function testARealUserRulesDirectoryInsideSugarCrushIsStillRead(): void
    {
        // Positive control for the whole user tier: without it every refusal test
        // above would pass against a build that never reads user rules at all.
        $home = $this->sandbox . '/home';
        mkdir($home . '/.sugar-crush/rules', 0o700, true);
        $this->stageDoc($home . '/.sugar-crush/rules/mine.md', 'mine');

        $this->useHomeSandbox($home, create: false);

        $loaded = (new RuleLoader($home))->loadUserRules();

        self::assertSame(['mine'], $this->titlesIn($loaded));
    }

    // -- Per-file entry boundary inside an honoured directory ----------------

    public function testARulesFileSymlinkedOutOfAnHonouredDirectoryIsRefusedAndRecorded(): void
    {
        $home = $this->sandbox . '/home';
        $rules = $home . '/.sugar-crush/rules';
        mkdir($rules, 0o700, true);
        mkdir($home . '/.ssh', 0o700, true);
        $this->stageDoc($home . '/.ssh/config', 'real-config');
        $this->stageDoc($rules . '/kept.md', 'kept');
        // CommandLoader's own comment: "cannot smuggle in ~/.ssh/config as a
        // prompt." Same payload, rules tier: a single file, directory honoured.
        self::assertTrue(symlink($home . '/.ssh/config', $rules . '/evil.md'));

        $this->useHomeSandbox($home, create: false);

        $loader = new RuleLoader($home);
        $loaded = $loader->loadUserRules();

        self::assertSame(['kept'], $this->titlesIn($loaded), 'the honoured directory is still read');
        self::assertArrayHasKey($rules . '/evil.md', $loader->refusedPaths(), 'the escaping file is a recorded refusal');
    }

    // -- P6.S3: the rulebooks directory reaches the same boundaries ----------

    /**
     * The plan's done-when for the user tier, re-run against the SECOND user
     * directory, and it passes without a single new containment call:
     * `loadUserRulebooks()` hands `~/.sugar-crush/rulebooks` to the same
     * {@see RuleLoader::loadFromDirectory()} with the same `$HOME/.sugar-crush`
     * anchor, so a rulebook directory symlinked at `~/.ssh` is refused by the gate
     * that already existed.
     *
     * This is the test that would fail if someone "helpfully" gave rulebooks its
     * own boundary check: the ledger would look the same while the call-site ledger
     * in `ContainedPathInventoryTest` gained a row it was never given room for.
     */
    public function testARulebooksDirectoryLinkedAtHomeSshIsRefusedAndRecorded(): void
    {
        $home = $this->sandbox . '/home';
        $spelled = $home . '/.sugar-crush/rulebooks';
        mkdir($home . '/.sugar-crush', 0o700, true);
        mkdir($home . '/.ssh', 0o700, true);
        $this->stageDoc($home . '/.ssh/id_ed25519.md', 'stolen-key');
        self::assertTrue(symlink($home . '/.ssh', $spelled));

        $this->useHomeSandbox($home, create: false);

        $loader = new RuleLoader($home);

        self::assertSame([], $loader->loadUserRulebooks(), 'a rulebooks link at ~/.ssh is refused like the rules one');
        self::assertArrayHasKey($spelled, $loader->refusedPaths(), 'and the refusal is recorded under the spelling used');
    }

    // -- NIT-5: a name that resolves to nothing is a refusal, not a vanishing --

    /**
     * NIT-5, carried to this step from the P6.S2 review.
     *
     * A `*.md` SYMLINK whose target no longer exists used to satisfy
     * `!$file->isFile()` and be dropped with ZERO ledger entries - indistinguishable
     * from a directory that holds nothing. That is the one input shape where the
     * loader cannot prove the path stays inside its anchor, because `realpath()`
     * fails, and the plan's standing rule is that the scanner fails CLOSED: an
     * unknown spelling costs a false-positive refusal, never a silent miss.
     *
     * It goes to {@see RuleLoader::refusedPaths()} and NOT to
     * {@see RuleLoader::skippedFiles()}. The skip ledger is reserved for cap trips
     * and parse errors - truncation of content that DID resolve - and filing a
     * non-resolving path there would let a containment question hide among the
     * ordinary ones. Both halves are asserted: present here, absent there.
     */
    public function testADanglingMarkdownSymlinkIsARecordedRefusalNotASilentAbsence(): void
    {
        $home = $this->sandbox . '/home';
        $rules = $home . '/.sugar-crush/rules';
        mkdir($rules, 0o700, true);
        $this->stageDoc($rules . '/kept.md', 'kept');
        $dangling = $rules . '/gone.md';
        self::assertTrue(symlink($rules . '/never-existed.md', $dangling));
        self::assertFileDoesNotExist($dangling, 'the fixture is a real dangling link, not a live one');

        $this->useHomeSandbox($home, create: false);

        $loader = new RuleLoader($home);
        $loaded = $loader->loadUserRules();

        self::assertSame(['kept'], $this->titlesIn($loaded), 'the good sibling is still read');
        self::assertArrayHasKey($dangling, $loader->refusedPaths(), 'the unresolvable name is a recorded refusal');
        self::assertStringContainsString(
            'does not resolve to a regular file',
            $loader->refusedPaths()[$dangling],
            'and the reason says the path resolved to nothing, rather than alleging it escaped',
        );
        self::assertSame([], $loader->skippedFiles(), 'an unresolvable path is not a truncation of resolved content');
    }

    /**
     * The same refusal on the rulebook side, so neither user directory can hold a
     * silently vanishing entry.
     */
    public function testADanglingMarkdownSymlinkInARulebooksDirectoryIsRefusedToo(): void
    {
        $home = $this->sandbox . '/home';
        $packs = $home . '/.sugar-crush/rulebooks';
        mkdir($packs, 0o700, true);
        $dangling = $packs . '/gone.md';
        self::assertTrue(symlink('/tmp/definitely-not-here-' . bin2hex(random_bytes(6)) . '.md', $dangling));

        $this->useHomeSandbox($home, create: false);

        $loader = new RuleLoader($home);

        self::assertSame([], $loader->loadUserRulebooks());
        self::assertArrayHasKey($dangling, $loader->refusedPaths());
        self::assertSame([], $loader->skippedFiles());
    }

    /**
     * THE CONTROL, and the scope limit. The refusal is for files the loader would
     * otherwise READ: a dangling link called `notes.txt` is not a rule candidate,
     * and recording it would put arbitrary home-directory litter in a security
     * ledger. If the unresolvable-name check ever moves above the extension check,
     * this reddens - which is the correct outcome to have to argue about.
     */
    public function testADanglingLinkThatIsNotMarkdownStaysOutOfBothLedgers(): void
    {
        $home = $this->sandbox . '/home';
        $rules = $home . '/.sugar-crush/rules';
        mkdir($rules, 0o700, true);
        self::assertTrue(symlink($rules . '/never-existed.txt', $rules . '/notes.txt'));

        $this->useHomeSandbox($home, create: false);

        $loader = new RuleLoader($home);

        self::assertSame([], $loader->loadUserRules());
        self::assertSame([], $loader->refusedPaths(), 'a non-markdown dangling name is not a rule candidate at all');
        self::assertSame([], $loader->skippedFiles());
    }

    // -- Root tier single file ------------------------------------------------

    public function testARootRulesFileSymlinkedOutOfTheCheckoutIsRefusedAndRecorded(): void
    {
        $root = $this->sandbox . '/repo';
        mkdir($root, 0o755, true);
        mkdir($this->sandbox . '/elsewhere', 0o755, true);
        $this->stageDoc($this->sandbox . '/elsewhere/real.md', 'outside');
        self::assertTrue(symlink($this->sandbox . '/elsewhere/real.md', $root . '/RULES.md'));

        $loader = new RuleLoader($root);
        $loaded = $loader->loadRootRules();

        self::assertSame([], $loaded);
        self::assertArrayHasKey($root . '/RULES.md', $loader->refusedPaths());
    }

    // -- No established home -> recorded refusal, not a fallback -------------

    public function testAWorldWritableHomeYieldsNoUserRulesAndRecordsARefusal(): void
    {
        // HomeDirectory::owned() rejects a world-writable home; there is no anchor,
        // so the tier is refused under the literal ~/ spelling and RECORDED. This
        // is the guarantee that the loader never falls back to a stand-in path.
        $home = $this->sandbox . '/loose-home';
        mkdir($home . '/.sugar-crush/rules', 0o700, true);
        $this->stageDoc($home . '/.sugar-crush/rules/mine.md', 'mine');
        chmod($home, 0o777);

        $this->useHomeSandbox($home, create: false);

        $loader = new RuleLoader($home);
        $loaded = $loader->loadUserRules();

        self::assertSame([], $loaded);
        self::assertArrayHasKey('~/.sugar-crush/rules', $loader->refusedPaths(), 'refusal recorded under the ~/ spelling when no path resolves');
    }

    private function scrubSandbox(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        // is_link() BEFORE is_dir(): some fixtures here link to their own
        // ancestors, so recursing through a link would neither finish nor free
        // what the test was asked to clean up.
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || !is_dir($path)) {
                @unlink($path);

                continue;
            }
            $this->scrubSandbox($path);
        }
        @rmdir($dir);
    }
}
