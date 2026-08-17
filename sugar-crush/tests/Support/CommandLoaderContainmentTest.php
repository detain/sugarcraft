<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Commands\CommandLoader;

/**
 * The two containment boundaries {@see CommandLoader} takes from
 * {@see \SugarCraft\Crush\Support\ContainedPath}.
 *
 * Filed beside the predicate rather than with the loader's behavioural suite
 * (`tests/Commands/CommandLoaderTest.php`) because the subject is the boundary,
 * not the loading: the same pair of questions the workflow, skills and
 * agent-preset tiers each answer, asked here of the fourth tier.
 *
 * WHY IT MATTERS FOR A CLASS NOTHING CONSTRUCTS YET. `CommandLoader` is a
 * deliberate dormant seam — no production caller builds one until the step that
 * wires the "/" popup to file-based commands — and it had the per-ENTRY check
 * only. That is the exact shape the agent-preset tier was in when a committed
 * `.sugar-crush/agents -> <outside>` was measured relocating the boundary
 * instead of tripping it: `realpath()` on both sides means a boundary directory
 * that is itself a link travels with the link. Anchoring now rather than at
 * wiring time is the difference between a rule the first consumer inherits and a
 * rule somebody has to remember to add.
 *
 * A command file's body is a PROMPT, so the payload of the escape would be the
 * same as the skills tier's: outside-the-checkout file content in the model's
 * context, reachable by `git clone`.
 */
final class CommandLoaderContainmentTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_cmd_containment_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    private function writeCommand(string $path, string $name, string $body): void
    {
        file_put_contents($path, "---\nname: {$name}\ndescription: fixture\n---\n{$body}\n");
    }

    /**
     * The directory-level boundary: a checkout that commits
     * `.sugar-crush/commands -> <outside>` gets no commands from it.
     */
    public function testAProjectCommandsDirectoryPointingOutOfTheCheckoutIsRefused(): void
    {
        $root = $this->tempDir . '/repo';
        $outside = $this->tempDir . '/private';
        mkdir($root . '/.sugar-crush', 0755, true);
        mkdir($outside, 0755, true);
        $this->writeCommand($outside . '/leak.md', 'leak', 'SENTINEL-COMMAND-BODY');

        $this->assertTrue(symlink($outside, $root . '/.sugar-crush/commands'));

        $this->assertSame([], (new CommandLoader())->loadProjectCommands($root));
    }

    /**
     * The arm where the two questions differ: `-> ..` from inside
     * `.sugar-crush` resolves EXACTLY onto the checkout root, which the
     * entry-level `within()` counts as contained and a trust anchor must refuse.
     */
    public function testAProjectCommandsDirectoryResolvingOntoTheCheckoutRootIsRefused(): void
    {
        $root = $this->tempDir . '/onto-root';
        mkdir($root . '/.sugar-crush', 0755, true);
        $this->writeCommand($root . '/local.md', 'local', 'SENTINEL-COMMAND-BODY');

        $this->assertTrue(symlink('..', $root . '/.sugar-crush/commands'));

        $this->assertSame([], (new CommandLoader())->loadProjectCommands($root));
    }

    /**
     * The control every refusal above needs: a link that stays INSIDE the
     * checkout is followed, because `.sugar-crush/commands -> tools/commands` is
     * repository content pointing at repository content. Refusing every symlinked
     * commands directory would satisfy both tests above and break a real layout.
     */
    public function testASymlinkedProjectCommandsDirectoryInsideTheCheckoutIsStillRead(): void
    {
        $root = $this->tempDir . '/in-checkout';
        mkdir($root . '/.sugar-crush', 0755, true);
        mkdir($root . '/tools/commands', 0755, true);
        $this->writeCommand($root . '/tools/commands/deploy.md', 'deploy', 'IN-CHECKOUT-BODY');

        $this->assertTrue(symlink($root . '/tools/commands', $root . '/.sugar-crush/commands'));

        $commands = (new CommandLoader())->loadProjectCommands($root);

        $this->assertArrayHasKey('deploy', $commands);
    }

    /**
     * And a plain in-checkout directory, which is what almost every project has:
     * the anchor must be invisible to it.
     */
    public function testTheOrdinaryProjectCommandsDirectoryIsRead(): void
    {
        $root = $this->tempDir . '/plain';
        mkdir($root . '/.sugar-crush/commands', 0755, true);
        $this->writeCommand($root . '/.sugar-crush/commands/test.md', 'test', 'RUN THE TESTS');

        $commands = (new CommandLoader())->loadProjectCommands($root);

        $this->assertArrayHasKey('test', $commands);
    }

    /**
     * THE ELEVENTH PATH, inverted. This test used to assert the escape, under the
     * name `testAnUnanchoredDirectoryMayBeALinkOutOfWhereverItSits` and the
     * justification "nobody but the user chose where `~/.sugar-crush/commands`
     * points, and linking it at another tool's command directory is a working
     * layout rather than an escape" — the same premise
     * {@see \SugarCraft\Crush\Cli\Bootstrap::agentPresetTiers()} had already
     * measured false, still standing here because `loadUserCommands()` omitted the
     * anchor argument its project twin passed.
     *
     * MEASURED before the fix, `$HOME` mode 0700 and owned, with
     * `~/.sugar-crush/commands -> <outside>`: `names=["leak"]` and
     * `template="ELEVENTH-ESCAPE-BODY sk-live-CAFEBABE"` — an outside file's body
     * as the PROMPT a slash command sends, with no refusal recorded anywhere.
     */
    public function testAUserCommandsDirectoryLinkedOutOfHomeIsRefused(): void
    {
        $home = $this->tempDir . '/home';
        $outside = $this->tempDir . '/outside-commands';
        mkdir($home . '/.sugar-crush', 0o700, true);
        mkdir($outside, 0o700, true);
        $this->writeCommand($outside . '/leak.md', 'leak', 'SENTINEL-PROMPT-BODY');
        $this->assertTrue(symlink($outside, $home . '/.sugar-crush/commands'));

        $this->useHomeSandbox($home, create: false);

        $this->assertSame([], array_keys((new CommandLoader())->loadUserCommands()));
    }

    /**
     * THE CONTROL: a real `~/.sugar-crush/commands` inside a home this process can
     * establish as the user's is still read. Without it the test above passes
     * against a build that simply stopped loading user commands.
     */
    public function testARealUserCommandsDirectoryInsideHomeIsStillRead(): void
    {
        $home = $this->tempDir . '/good-home';
        mkdir($home . '/.sugar-crush/commands', 0o700, true);
        $this->writeCommand($home . '/.sugar-crush/commands/mine.md', 'mine', 'MY BODY');

        $this->useHomeSandbox($home, create: false);

        $this->assertSame(['mine'], array_keys((new CommandLoader())->loadUserCommands()));
    }

    /**
     * AND WHAT THE ANCHOR COSTS: the layout the refuted justification named —
     * `~/.sugar-crush/commands -> ~/.claude/commands`, a link elsewhere INSIDE the
     * home — still works, because the anchor is `$HOME` and not the directory's
     * own parent.
     *
     * So the cost of closing the eleventh path is narrower than the sentence it
     * replaced implied: what stops working is a link OUT of the home (a network
     * share, `/opt/team-commands`), not a link between the user's own directories.
     */
    public function testAUserCommandsLinkElsewhereInsideHomeStillWorks(): void
    {
        $home = $this->tempDir . '/linked-home';
        mkdir($home . '/.sugar-crush', 0o700, true);
        mkdir($home . '/.claude/commands', 0o700, true);
        $this->writeCommand($home . '/.claude/commands/mine.md', 'mine', 'MY BODY');
        $this->assertTrue(symlink($home . '/.claude/commands', $home . '/.sugar-crush/commands'));

        $this->useHomeSandbox($home, create: false);

        $this->assertSame(['mine'], array_keys((new CommandLoader())->loadUserCommands()));
    }

    /**
     * A home {@see \SugarCraft\Crush\Support\HomeDirectory::owned()} refuses gets
     * NO user commands, rather than commands anchored to a stand-in.
     *
     * `path()` — what this loader used to build the directory from — falls back to
     * `sys_get_temp_dir()`, mode 1777, and accepts a world-writable or
     * foreign-owned `$HOME` as it stands. Both halves matter: the directory is
     * built from the OWNED home, and there is no tier at all when there is none.
     */
    public function testAWorldWritableHomeYieldsNoUserCommands(): void
    {
        $home = $this->tempDir . '/loose-home';
        mkdir($home . '/.sugar-crush/commands', 0o777, true);
        $this->writeCommand($home . '/.sugar-crush/commands/mine.md', 'mine', 'NOT MINE');
        chmod($home, 0o777);

        $this->useHomeSandbox($home, create: false);

        $this->assertSame([], array_keys((new CommandLoader())->loadUserCommands()));
    }

    /**
     * The unanchored arm of {@see CommandLoader::loadFromDirectory()} still
     * exists, and this pins WHAT IT IS: a caller's explicit choice to take the
     * per-entry boundary alone, not a policy this class holds about user
     * directories. No caller in `src/` passes it any more.
     *
     * Kept as a test rather than deleted with the escape it used to justify,
     * because the parameter is part of the public contract and a contract with no
     * test is how the next caller learns the wrong lesson from the fix above.
     */
    public function testTheUnanchoredArmIsStillOfferedAndTakesTheEntryBoundaryOnly(): void
    {
        $dir = $this->tempDir . '/wherever';
        $outside = $this->tempDir . '/outside-unanchored';
        mkdir($this->tempDir . '/holder', 0o755, true);
        mkdir($outside, 0o755, true);
        $this->writeCommand($outside . '/mine.md', 'mine', 'MY BODY');
        $this->assertTrue(symlink($outside, $dir));

        $commands = (new CommandLoader())->loadFromDirectory($dir);

        $this->assertArrayHasKey('mine', $commands, 'unanchored means the directory link is honoured');
    }

    /**
     * The per-ENTRY boundary still holds inside an honoured directory — it is the
     * check this class always had, and the directory-level one must not have
     * replaced it.
     */
    public function testACommandFileLinkedOutOfAnHonouredDirectoryIsSkipped(): void
    {
        $root = $this->tempDir . '/entry';
        mkdir($root . '/.sugar-crush/commands', 0755, true);
        mkdir($this->tempDir . '/elsewhere', 0755, true);
        $this->writeCommand($this->tempDir . '/elsewhere/stolen.md', 'stolen', 'SENTINEL-COMMAND-BODY');

        $this->assertTrue(symlink(
            $this->tempDir . '/elsewhere/stolen.md',
            $root . '/.sugar-crush/commands/stolen.md',
        ));
        $this->writeCommand($root . '/.sugar-crush/commands/kept.md', 'kept', 'KEPT BODY');

        $commands = (new CommandLoader())->loadProjectCommands($root);

        $this->assertArrayHasKey('kept', $commands, 'the honoured directory is still read');
        $this->assertArrayNotHasKey('stolen', $commands);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        // is_link() BEFORE is_dir(): these fixtures contain links to their own
        // ancestors, so recursing through one would neither terminate nor delete
        // the thing it was asked to.
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_link($path) || !is_dir($path)) {
                @unlink($path);

                continue;
            }

            $this->removeDirectory($path);
        }

        @rmdir($dir);
    }
}
