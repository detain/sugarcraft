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
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_cmd_containment_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
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
     * The USER tier is anchored to nothing, for the reason the agent-preset and
     * skills tiers do not anchor theirs: nobody but the user chose where
     * `~/.sugar-crush/commands` points, and linking it at another tool's command
     * directory is a working layout rather than an escape.
     *
     * Driven through {@see CommandLoader::loadFromDirectory()} with no anchor,
     * which is exactly what `loadUserCommands()` does.
     */
    public function testAnUnanchoredDirectoryMayBeALinkOutOfWhereverItSits(): void
    {
        $home = $this->tempDir . '/home';
        mkdir($home . '/.sugar-crush', 0755, true);
        mkdir($home . '/.claude/commands', 0755, true);
        $this->writeCommand($home . '/.claude/commands/mine.md', 'mine', 'MY BODY');
        $this->assertTrue(symlink($home . '/.claude/commands', $home . '/.sugar-crush/commands'));

        $commands = (new CommandLoader())->loadFromDirectory($home . '/.sugar-crush/commands');

        $this->assertArrayHasKey('mine', $commands);
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
