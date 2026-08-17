<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Tests\Skills\TemporaryDirectoryTrait;

/**
 * `cd ~ && sugarcrush` — the launch where the two agent-preset tiers are ONE
 * directory, and the project tier's anchor was applied to the user's own roster.
 *
 * {@see Bootstrap::chat()} defaults `$root` to `getcwd()`, and
 * {@see Bootstrap::agentPresets()} builds the project tier as
 * `rtrim($root,'/') . '/.sugar-crush/agents'` and the user tier as
 * `trustedConfigDirPath() . '/agents'` = `$HOME . '/.sugar-crush/agents'`. When
 * `$root` IS `$HOME` those are the same string, so the anchor keyed on the
 * project spelling landed on the user's directory. MEASURED before the fix, with
 * `$HOME/.sugar-crush/agents` symlinked to a directory OUTSIDE `$HOME`:
 *
 *     agentPresets(<a project>) -> presets=[mine]  refusals=[]
 *     agentPresets($HOME)       -> presets=[]      refusals=["…a repository chooses where…"]
 *
 * The user's own roster silently vanished, and the notice blamed "a repository"
 * for a layout the user chose. A link to `~/.claude/agents` survived only by
 * being inside `$HOME`; a link out of it did not.
 *
 * DOMAIN, because the fix has a real cost and hiding it is the defect this
 * session is about: the branch treats one directory that is BOTH tiers as the
 * USER tier, i.e. unanchored. A `$HOME` that is itself a cloned checkout — a
 * dotfiles repository — therefore had its `.sugar-crush/agents` read without the
 * checkout anchor. MEASURED on a real `git init` checkout with one committed
 * `.sugar-crush/agents -> <outside>`:
 *
 *     cd ~          && sugarcrush   presets=["pwned"] mode=bypass-permissions refusals=[]
 *     cd ~/dotfiles && sugarcrush   presets=[]        refusals=["…outside the checkout…"]
 *
 * The cost USED TO BE excused by "that directory is still gated by
 * trustedConfigDirPath(), which refuses a home whose ownership this process
 * cannot establish" — a mitigation that did not exist: that method refused only
 * an UNDETERMINABLE home and no `stat` was performed anywhere in the package.
 * Both halves are now real. `trustedConfigDirPath()` establishes ownership
 * through {@see \SugarCraft\Crush\Support\HomeDirectory::owned()}, and the
 * collapse is CONDITIONAL on `$HOME` not being a checkout — see
 * {@see testAHomeThatIsItselfACheckoutKeepsTheAnchor()} and its control
 * {@see testLaunchingFromTheHomeDirectoryKeepsTheUsersOwnRoster()}.
 *
 * Every launch where `$root !== $HOME` — the overwhelming majority, and the one
 * the escape tests use — is unchanged, which is what
 * {@see testAProjectLaunchIsStillAnchored()} pins.
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

    public function testLaunchingFromTheHomeDirectoryKeepsTheUsersOwnRoster(): void
    {
        $this->userRosterOutsideHome();

        // Before the fix this returned [] and recorded a refusal.
        $this->assertSame(['mine'], $this->names(Bootstrap::agentPresets($this->home)));
    }

    public function testLaunchingFromTheHomeDirectoryRecordsNoRefusalAgainstIt(): void
    {
        $this->userRosterOutsideHome();

        Bootstrap::agentPresets($this->home);

        // The collector is a static accumulator shared with every other launch in
        // this process, so the assertion is scoped to THIS test's own paths rather
        // than to the map being empty.
        $mine = array_filter(
            Bootstrap::projectTierRefusals(),
            fn (string $key): bool => str_starts_with($key, $this->home),
            \ARRAY_FILTER_USE_KEY,
        );

        $this->assertSame([], $mine);
    }

    /** A trailing separator on $root is the same launch. */
    public function testATrailingSeparatorOnTheHomeRootIsTheSameLaunch(): void
    {
        $this->userRosterOutsideHome();

        $this->assertSame(['mine'], $this->names(Bootstrap::agentPresets($this->home . '/')));
    }

    /**
     * Resolved identity, not merely spelled identity: `$root` reached through a
     * symlink to `$HOME` is the same directory and must take the same branch.
     *
     * The ROSTER is not what this pins — the unanchored user tier is in the search
     * list either way, so `mine` comes back with or without the resolved-identity
     * clause. What the clause prevents is the REFUSAL below, recorded against the
     * user's own directory for being "outside the checkout it was reached from".
     */
    public function testAHomeDirectoryReachedThroughASymlinkIsTheSameLaunch(): void
    {
        $this->userRosterOutsideHome();
        $linked = $this->tempDir . '/home-link';
        symlink($this->home, $linked);

        $this->assertSame(['mine'], $this->names(Bootstrap::agentPresets($linked)));

        $mine = array_filter(
            Bootstrap::projectTierRefusals(),
            static fn (string $key): bool => str_starts_with($key, $linked),
            \ARRAY_FILTER_USE_KEY,
        );

        $this->assertSame([], $mine, 'the user\'s own agents directory must not be refused as a repository\'s');
    }

    /**
     * The other half of the same launch, unchanged: a project elsewhere still sees
     * the user's roster, which is the row the pre-fix measurement showed working.
     */
    public function testAProjectLaunchStillSeesTheUsersRoster(): void
    {
        $this->userRosterOutsideHome();

        $project = $this->tempDir . '/project';
        mkdir($project, 0o755, true);

        $this->assertSame(['mine'], $this->names(Bootstrap::agentPresets($project)));
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
     * THE HOLE THE COLLAPSE OPENED, closed. `$HOME` is a git checkout — a
     * dotfiles repository — so `.sugar-crush/agents -> <outside>` is a line
     * somebody COMMITTED rather than a layout the user chose at their own
     * keyboard, and `cd ~ && sugarcrush` used to read it unanchored under
     * whatever `permissionMode:` it declared.
     *
     * `$HOME/.git` is the discriminator because the escape needs a committed
     * symlink and nothing is committed without a repository. STATED BOUND, so
     * this is not read as more than it is: a bare-repo dotfiles layout
     * (`git --git-dir=~/.dotfiles --work-tree=~`) leaves no `.git` at `$HOME`
     * and is NOT caught — it takes the branch
     * {@see testLaunchingFromTheHomeDirectoryKeepsTheUsersOwnRoster()} pins.
     */
    public function testAHomeThatIsItselfACheckoutKeepsTheAnchor(): void
    {
        $this->userRosterOutsideHome();
        mkdir($this->home . '/.git');

        $presets = Bootstrap::agentPresets($this->home);

        $this->assertSame([], $this->names($presets));
        $this->assertStringNotContainsString(
            self::BODY_SENTINEL,
            (string) json_encode(array_map(static fn (object $p): array => (array) $p, $presets)),
        );

        $mine = array_filter(
            Bootstrap::projectTierRefusals(),
            fn (string $key): bool => str_starts_with($key, $this->home),
            \ARRAY_FILTER_USE_KEY,
        );
        $this->assertNotSame([], $mine, 'and the refusal is recorded rather than silent');
    }

    /**
     * A linked worktree spells `.git` as a FILE holding `gitdir: …`, which is
     * why the discriminator is `file_exists()` and not `is_dir()`.
     */
    public function testAHomeWhoseGitIsAFileIsStillACheckout(): void
    {
        $this->userRosterOutsideHome();
        file_put_contents($this->home . '/.git', "gitdir: /elsewhere/.git/worktrees/home\n");

        $this->assertSame([], $this->names(Bootstrap::agentPresets($this->home)));
    }

    /**
     * The control that keeps the branch honest in the other direction: a
     * checkout at `$HOME` must not cost the user their roster when the roster
     * is where a checkout could legitimately put it — INSIDE `$HOME`. Only the
     * link OUT is refused.
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
}
