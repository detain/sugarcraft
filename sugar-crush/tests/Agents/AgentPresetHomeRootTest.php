<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Tests\Skills\TemporaryDirectoryTrait;

/**
 * WHERE THE USER TIER MAY POINT — the boundary that replaced a discriminator
 * whose stated premise was false.
 *
 * {@see Bootstrap::agentPresets()} builds the project tier as
 * `rtrim($root,'/') . '/.sugar-crush/agents'` and the user tier as
 * `trustedConfigDirPath() . '/agents'` = `$HOME . '/.sugar-crush/agents'`. The
 * user tier used to be UNANCHORED, justified as "the user's own directory —
 * nobody but the user chose the location", with the one exception that a `$HOME`
 * containing a `.git` kept the project anchor, "because the escape needs a
 * COMMITTED symlink and nothing can be committed without a repository".
 *
 * BOTH HALVES OF THAT WERE WRONG, and the measurement is what this file now
 * pins. A symlink does not need to be committed to arrive — `tar`, `zip`,
 * `rsync -a`, `degit` and a release tarball all carry one and carry no `.git` —
 * and a DANGLING `.git` symlink answers `file_exists()` false. Measured on this
 * host, `$HOME` mode 0700 and owned by the running user, its only content
 * `.sugar-crush/agents -> <outside>` delivered by `tar xzf`:
 *
 *     no .git,  agentPresets($HOME)     presets=["pwned"] mode=bypass-permissions refusals=[]
 *     no .git,  agentPresets(<project>) presets=["pwned"] mode=bypass-permissions refusals=[]
 *     .git dir, agentPresets($HOME)     presets=[]        refusals=[…]
 *     .git dir, agentPresets(<project>) presets=["pwned"] mode=bypass-permissions refusals=[]
 *
 * Row four is the one that settles it: with the discriminator firing exactly as
 * designed, the escape was fully live from any launch that was not made from the
 * home directory — i.e. every ordinary launch. It defended one shape out of four.
 *
 * SO THE QUESTION CHANGED. "Did a repository choose this content" has no
 * filesystem answer; "is this directory inside the home this process established
 * as the user's" does, and it is what the old justification assumed. The user
 * tier is anchored to `$HOME`.
 *
 * ITS COST, stated rather than implied away, and pinned by
 * {@see testARosterLinkedOutOfHomeIsRefusedEvenFromAProjectLaunch()}: a roster
 * symlinked to a path OUTSIDE `$HOME` — a network share, `/opt/team-agents` —
 * stops working, in every launch shape. The layout the old sentence named as its
 * own justification, a link to `~/.claude/agents`, is inside `$HOME` and is
 * unaffected ({@see testARosterLinkedInsideHomeStillWorksFromBothLaunchShapes()}).
 *
 * Ownership is NOT this boundary and cannot stand in for it:
 * {@see \SugarCraft\Crush\Support\HomeDirectory::owned()} passed on every row
 * above, because the user extracted the tarball into their own home. Ownership
 * answers whose directory it is; only containment answers who chose where a link
 * inside it points.
 */
final class AgentPresetHomeRootTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private const DESCRIPTION_SENTINEL = 'SENTINEL-HOME-ROOT-DESCRIPTION';
    private const BODY_SENTINEL = 'SENTINEL-HOME-ROOT-BODY';

    private string $tempDir;
    private string $home;
    private string $originalHome;
    private mixed $originalServerHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_home_root_agents_' . uniqid('', true);
        $this->home = $this->tempDir . '/home';
        mkdir($this->home . '/.sugar-crush', 0o700, true);

        // BOTH spellings: HomeDirectory reads $_SERVER['HOME'] and Bootstrap reads
        // getenv(), so redirecting one would leave the other reading the
        // DEVELOPER's own ~/.sugar-crush/agents into these assertions.
        $this->originalHome = getenv('HOME') ?: '';
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . $this->home);
        $_SERVER['HOME'] = $this->home;
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }

        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    /** `$HOME/.sugar-crush/agents -> <a directory outside $HOME>`, one preset in it. */
    private function userRosterOutsideHome(): void
    {
        $outside = $this->tempDir . '/elsewhere';
        mkdir($outside, 0o755, true);
        file_put_contents(
            $outside . '/mine.md',
            "---\nname: mine\ndescription: " . self::DESCRIPTION_SENTINEL . "\n---\n"
            . self::BODY_SENTINEL . "\n",
        );

        symlink($outside, $this->home . '/.sugar-crush/agents');
    }

    /**
     * `agentPresets()` returns a roster KEYED by preset name, so the keys are
     * dropped here — this asserts on which presets came back, not on the map shape.
     *
     * @param array<string, object> $presets
     * @return list<string>
     */
    private function names(array $presets): array
    {
        return array_values(array_map(static fn (object $preset): string => (string) $preset->name, $presets));
    }

    /**
     * @return array<string, string> the refusals THIS test caused, keyed by path
     */
    private function refusalsUnder(string $prefix): array
    {
        // The collector is a static accumulator shared with every other launch in
        // this process, so every assertion on it is scoped to this test's own
        // paths rather than to the map being empty.
        return array_filter(
            Bootstrap::projectTierRefusals(),
            static fn (string $key): bool => str_starts_with($key, $prefix),
            \ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * ROW ONE AND TWO of the measurement, and the reason this file was rewritten:
     * a roster linked out of `$HOME` is refused from the HOME launch and from a
     * project launch alike. The second is the one the `.git` discriminator never
     * touched.
     *
     * @return array<string, array{0: bool}>
     */
    public static function launchShapes(): array
    {
        return ['from the home directory' => [true], 'from a project elsewhere' => [false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('launchShapes')]
    public function testARosterLinkedOutOfHomeIsRefusedEvenFromAProjectLaunch(bool $fromHome): void
    {
        $this->userRosterOutsideHome();

        $root = $this->home;
        if (!$fromHome) {
            $root = $this->tempDir . '/project';
            mkdir($root, 0o755, true);
        }

        $presets = Bootstrap::agentPresets($root);

        $this->assertSame([], $this->names($presets));

        // The BYTES, not merely the roster: an outside file's body is what became
        // a sub-agent's initialPrompt.
        $this->assertStringNotContainsString(
            self::BODY_SENTINEL,
            (string) json_encode(array_map(static fn (object $p): array => (array) $p, $presets)),
        );

        $this->assertNotSame(
            [],
            $this->refusalsUnder($this->home),
            'and the refusal is recorded rather than silent',
        );
    }

    /**
     * THE COST, paid in the other direction and named: the refusal above is
     * recorded against the USER's own directory, so its wording may not blame a
     * repository for it. {@see \SugarCraft\Crush\Agents\AgentPresetRegistry}'s
     * notice said "a repository chooses where this directory is" for every
     * refusal, which sends the reader of this one to the wrong file.
     */
    public function testTheRefusalNamesTheAnchorRatherThanBlamingARepository(): void
    {
        $this->userRosterOutsideHome();
        Bootstrap::agentPresets($this->home);

        $refusal = implode("\n", $this->refusalsUnder($this->home));

        $this->assertStringContainsString('anchored to', $refusal);
        $this->assertStringContainsString($this->home, $refusal);
        $this->assertStringNotContainsString('a repository chooses', $refusal);
    }

    /** A trailing separator on $root is the same launch. */
    public function testATrailingSeparatorOnTheHomeRootIsTheSameLaunch(): void
    {
        $this->userRosterOutsideHome();

        $this->assertSame([], $this->names(Bootstrap::agentPresets($this->home . '/')));
    }

    /**
     * Resolved identity, not merely spelled identity: `$root` reached through a
     * symlink to `$HOME` is the same directory and must take the same branch.
     *
     * What this pins now is the DE-DUPLICATION, which is all `$sameDirectory` is
     * for since both tiers carry an anchor: one directory, one refusal, keyed
     * once — not the same verdict recorded twice under two spellings.
     */
    public function testAHomeDirectoryReachedThroughASymlinkIsOneDirectoryNotTwo(): void
    {
        $this->userRosterOutsideHome();
        $linked = $this->tempDir . '/home-link';
        symlink($this->home, $linked);

        $this->assertSame([], $this->names(Bootstrap::agentPresets($linked)));
        $this->assertSame([], $this->refusalsUnder($linked), 'the link spelling records no second refusal');
        $this->assertCount(1, $this->refusalsUnder($this->home));
    }

    /**
     * THE LAYOUT THE OLD JUSTIFICATION NAMED, and it still works — from both
     * launch shapes. `~/.claude/agents` is inside `$HOME`, so the anchor never
     * touches it. Without this the change above would be indistinguishable from
     * "the user tier was deleted".
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('launchShapes')]
    public function testARosterLinkedInsideHomeStillWorksFromBothLaunchShapes(bool $fromHome): void
    {
        mkdir($this->home . '/.claude/agents', 0o700, true);
        file_put_contents(
            $this->home . '/.claude/agents/mine.md',
            "---\nname: mine\ndescription: " . self::DESCRIPTION_SENTINEL . "\n---\n" . self::BODY_SENTINEL . "\n",
        );
        symlink($this->home . '/.claude/agents', $this->home . '/.sugar-crush/agents');

        $root = $this->home;
        if (!$fromHome) {
            $root = $this->tempDir . '/project';
            mkdir($root, 0o755, true);
        }

        $this->assertSame(['mine'], $this->names(Bootstrap::agentPresets($root)));
        $this->assertSame([], $this->refusalsUnder($this->home));
    }

    /** A roster that is an ordinary directory inside `$HOME` is unaffected too. */
    public function testAnOrdinaryUserRosterIsUnaffected(): void
    {
        mkdir($this->home . '/.sugar-crush/agents', 0o700, true);
        file_put_contents(
            $this->home . '/.sugar-crush/agents/plain.md',
            "---\nname: plain\ndescription: ordinary roster\n---\nPLAIN-BODY\n",
        );

        $project = $this->tempDir . '/project';
        mkdir($project, 0o755, true);

        $this->assertSame(['plain'], $this->names(Bootstrap::agentPresets($project)));
    }

    /**
     * THE CONTROL THAT MATTERS: the project tier is still anchored. A checkout
     * that is not `$HOME` and commits `.sugar-crush/agents -> <outside>` is still
     * refused, so the fix above narrowed one coincidence and not the boundary.
     */
    public function testAProjectLaunchIsStillAnchored(): void
    {
        $project = $this->tempDir . '/repo';
        $outside = $this->tempDir . '/repo-private';
        mkdir($project . '/.sugar-crush', 0o755, true);
        mkdir($outside, 0o755, true);
        file_put_contents(
            $outside . '/leak.md',
            "---\nname: leak\ndescription: LEAKED-DESCRIPTION\n---\nLEAKED-BODY\n",
        );
        symlink($outside, $project . '/.sugar-crush/agents');

        $presets = Bootstrap::agentPresets($project);

        $this->assertNotContains('leak', $this->names($presets));
        $this->assertStringNotContainsString(
            'LEAKED-BODY',
            (string) json_encode(array_map(static fn (object $p): array => (array) $p, $presets)),
        );
    }

    /**
     * THE THREE SHAPES THAT DEFEATED THE OLD DISCRIMINATOR, each driven, so a
     * future revision cannot reintroduce a `.git`-presence check without this
     * file saying which of them it fails on.
     *
     * The verdict is now the SAME for all of them, which is the point — the
     * boundary no longer depends on the state of `$HOME/.git` at all.
     *
     * @return array<string, array{0: string}>
     */
    public static function gitDiscriminatorDefeats(): array
    {
        return [
            // Delivered by tar/zip/rsync/degit: symlink present, no repository.
            'no .git at all' => ['none'],
            // `git --git-dir=~/.dotfiles --work-tree=~` leaves nothing at $HOME.
            'a bare-repo dotfiles layout' => ['none'],
            // file_exists() FOLLOWS the link and answers false.
            'a dangling .git symlink' => ['dangling-link'],
            // The one shape the old check did catch — asserted to behave the
            // same as the three it did not.
            'a real .git directory' => ['dir'],
            // A linked worktree spells .git as a file holding `gitdir: …`.
            'a .git gitfile' => ['file'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('gitDiscriminatorDefeats')]
    public function testTheVerdictNoLongerDependsOnWhetherHomeLooksLikeACheckout(string $git): void
    {
        $this->userRosterOutsideHome();

        match ($git) {
            'dir' => mkdir($this->home . '/.git'),
            'file' => file_put_contents($this->home . '/.git', "gitdir: /elsewhere/.git/worktrees/home\n"),
            'dangling-link' => symlink($this->tempDir . '/no-such-target', $this->home . '/.git'),
            default => null,
        };

        $presets = Bootstrap::agentPresets($this->home);

        $this->assertSame([], $this->names($presets));
        $this->assertStringNotContainsString(
            self::BODY_SENTINEL,
            (string) json_encode(array_map(static fn (object $p): array => (array) $p, $presets)),
        );
        $this->assertNotSame([], $this->refusalsUnder($this->home));
    }

    /**
     * The other direction of the same independence: a `$HOME` that IS a checkout
     * keeps a roster that stays inside it. Only the link OUT is refused, and
     * `.git` has nothing to do with either verdict.
     */
    public function testACheckoutHomeStillReadsARosterThatStaysInsideIt(): void
    {
        mkdir($this->home . '/.git');
        mkdir($this->home . '/.sugar-crush/agents', 0o700, true);
        file_put_contents(
            $this->home . '/.sugar-crush/agents/inside.md',
            "---\nname: inside\ndescription: in-checkout roster\n---\nINSIDE-BODY\n",
        );

        $this->assertSame(['inside'], $this->names(Bootstrap::agentPresets($this->home)));
    }

    /**
     * THE DISCRIMINATOR IS GONE FROM THE SOURCE, not merely bypassed. A
     * behavioural assertion cannot tell "the check was removed" from "the check
     * is still there and something else now fires first", and a dead check with
     * a false rationale beside it is exactly what this round was called to
     * remove.
     */
    public function testNoGitPresenceCheckRemainsInTheTierDerivation(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php');
        $start = strpos($source, 'private static function agentPresetTiers(');
        $this->assertNotFalse($start, 'agentPresetTiers() is where the tiers are derived');

        $body = substr($source, $start, (int) strpos($source, "\n    }", $start) - $start);

        $this->assertStringNotContainsString('.git', $body);
    }
}
