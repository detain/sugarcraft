<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\CommandLoader;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Commands\CommandSpec;

final class CommandLoaderTest extends TestCase
{
    private string $tmp;

    /** @var string|false */
    private $originalHome;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/crush-cmdloader-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o700, true);
        $this->originalHome = $_SERVER['HOME'] ?? false;
    }

    protected function tearDown(): void
    {
        if ($this->originalHome === false) {
            unset($_SERVER['HOME']);
            putenv('HOME');
        } else {
            $_SERVER['HOME'] = $this->originalHome;
            putenv('HOME=' . $_SERVER['HOME']);
        }

        $this->removeTree($this->tmp);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } else {
                $this->removeTree($path);
            }
        }

        rmdir($dir);
    }

    private function writeCommand(string $relativePath, string $content): string
    {
        $path = $this->tmp . '/' . $relativePath;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        file_put_contents($path, $content);

        return $path;
    }

    // -------------------------------------------------------------------
    // loadFromDirectory
    // -------------------------------------------------------------------

    public function testLoadFromDirectoryReturnsEmptyForMissingDirectory(): void
    {
        $this->assertSame([], (new CommandLoader())->loadFromDirectory($this->tmp . '/nope'));
    }

    public function testLoadFromDirectoryParsesFrontmatterAndTemplateBody(): void
    {
        $this->writeCommand('test.md', <<<'MD'
            ---
            description: Run tests with coverage
            argument-hint: <suite>
            model: anthropic/claude-3-5-sonnet-20241022
            subtask: true
            ---
            Review @src/Foo.php then run the tests for $1.
            MD);

        $commands = (new CommandLoader())->loadFromDirectory($this->tmp);

        $this->assertArrayHasKey('test', $commands);
        $spec = $commands['test'];
        $this->assertSame('test', $spec->name);
        $this->assertSame('Run tests with coverage', $spec->description);
        $this->assertSame('<suite>', $spec->argumentHint);
        $this->assertSame('anthropic/claude-3-5-sonnet-20241022', $spec->model);
        $this->assertTrue($spec->subtask);
        $this->assertSame('Review @src/Foo.php then run the tests for $1.', $spec->template);
        $this->assertTrue($spec->isFileBased());
        // File-based commands are typed after "/" and carry no palette action.
        $this->assertTrue($spec->slashVisible);
        $this->assertNull($spec->paletteAction);
        $this->assertSame('Custom', $spec->category);
    }

    public function testBareMarkdownFileWithoutFrontmatterIsAValidCommand(): void
    {
        $this->writeCommand('summarize.md', "Summarize the diff.\n");

        $commands = (new CommandLoader())->loadFromDirectory($this->tmp);

        $this->assertSame('Custom command: summarize', $commands['summarize']->description);
        $this->assertSame('Summarize the diff.', $commands['summarize']->template);
        $this->assertFalse($commands['summarize']->subtask);
        $this->assertNull($commands['summarize']->model);
    }

    public function testSubdirectoriesNamespaceTheCommandName(): void
    {
        $this->writeCommand('deploy/staging.md', 'Deploy to staging.');

        $commands = (new CommandLoader())->loadFromDirectory($this->tmp);

        $this->assertArrayHasKey('deploy/staging', $commands);
        $this->assertSame('deploy/staging', $commands['deploy/staging']->name);
    }

    public function testNonMarkdownFilesAreIgnored(): void
    {
        $this->writeCommand('notes.txt', 'not a command');
        $this->writeCommand('real.md', 'a command');

        $this->assertSame(['real'], array_keys((new CommandLoader())->loadFromDirectory($this->tmp)));
    }

    public function testResultsAreSortedByNameForDeterministicMenuOrder(): void
    {
        $this->writeCommand('zeta.md', 'z');
        $this->writeCommand('alpha.md', 'a');
        $this->writeCommand('mid.md', 'm');

        $this->assertSame(
            ['alpha', 'mid', 'zeta'],
            array_keys((new CommandLoader())->loadFromDirectory($this->tmp)),
        );
    }

    // -------------------------------------------------------------------
    // Fail-closed behaviour on user-controlled input
    // -------------------------------------------------------------------

    public function testMalformedFrontmatterIsSkippedWithoutLosingSiblingCommands(): void
    {
        $this->writeCommand('broken.md', "---\ndescription: [unclosed\n---\nbody\n");
        $this->writeCommand('good.md', "---\ndescription: Fine\n---\nbody\n");

        $commands = (new CommandLoader())->loadFromDirectory($this->tmp);

        $this->assertArrayNotHasKey('broken', $commands);
        $this->assertArrayHasKey('good', $commands);
    }

    public function testWronglyTypedFrontmatterIsRejectedRatherThanCoerced(): void
    {
        $this->writeCommand('listy.md', "---\ndescription:\n  - a\n  - b\n---\nbody\n");
        $this->writeCommand('boolish.md', "---\nsubtask: sure\n---\nbody\n");

        $commands = (new CommandLoader())->loadFromDirectory($this->tmp);

        $this->assertSame([], $commands);
    }

    public function testEmptyTemplateBodyIsRejected(): void
    {
        $this->writeCommand('hollow.md', "---\ndescription: Nothing to say\n---\n\n   \n");

        $this->assertSame([], (new CommandLoader())->loadFromDirectory($this->tmp));
    }

    public function testSymlinkEscapingTheCommandsDirectoryIsSkipped(): void
    {
        $outside = sys_get_temp_dir() . '/crush-cmdloader-outside-' . bin2hex(random_bytes(6)) . '.md';
        file_put_contents($outside, "secret prompt\n");

        try {
            if (!@symlink($outside, $this->tmp . '/sneaky.md')) {
                $this->markTestSkipped('symlink() unavailable on this filesystem');
            }

            $this->assertSame([], (new CommandLoader())->loadFromDirectory($this->tmp));
        } finally {
            @unlink($outside);
        }
    }

    public function testUnsafeCommandNameIsRejectedByFromFile(): void
    {
        $path = $this->writeCommand('ok.md', 'body');

        $this->expectException(\InvalidArgumentException::class);
        CommandSpec::fromFile($path, '../../etc/passwd');
    }

    public function testFromFileThrowsForMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        CommandSpec::fromFile($this->tmp . '/absent.md', 'absent');
    }

    // -------------------------------------------------------------------
    // Tiered discovery
    // -------------------------------------------------------------------

    public function testLoadUserCommandsReadsTheHomeCommandsDirectory(): void
    {
        $_SERVER['HOME'] = $this->tmp;
        putenv('HOME=' . $_SERVER['HOME']);
        $this->writeCommand('.candy-code/commands/mine.md', 'user body');

        $commands = (new CommandLoader())->loadUserCommands();

        $this->assertArrayHasKey('mine', $commands);
        $this->assertSame('user body', $commands['mine']->template);
    }

    public function testLoadProjectCommandsReadsTheProjectCommandsDirectory(): void
    {
        $this->writeCommand('.candy-code/commands/ours.md', 'project body');

        $commands = (new CommandLoader())->loadProjectCommands($this->tmp);

        $this->assertArrayHasKey('ours', $commands);
        $this->assertSame('project body', $commands['ours']->template);
    }

    public function testLoadAllMergesBuiltInsWithUserAndProjectTiers(): void
    {
        $home = $this->tmp . '/home';
        $project = $this->tmp . '/project';
        $_SERVER['HOME'] = $home;
        putenv('HOME=' . $_SERVER['HOME']);
        $this->writeCommand('home/.candy-code/commands/user-only.md', 'user body');
        $this->writeCommand('project/.candy-code/commands/project-only.md', 'project body');

        $commands = (new CommandLoader())->loadAll($project);

        foreach (CommandRegistry::all() as $builtIn) {
            $this->assertArrayHasKey($builtIn->name, $commands);
        }
        $this->assertArrayHasKey('user-only', $commands);
        $this->assertArrayHasKey('project-only', $commands);
        $this->assertFalse($commands['compact']->isFileBased());
        $this->assertTrue($commands['user-only']->isFileBased());
    }

    public function testProjectCommandOverridesUserCommandWhichOverridesBuiltIn(): void
    {
        $home = $this->tmp . '/home';
        $project = $this->tmp . '/project';
        $_SERVER['HOME'] = $home;
        putenv('HOME=' . $_SERVER['HOME']);
        $this->writeCommand('home/.candy-code/commands/compact.md', "---\ndescription: user compact\n---\nuser body");
        $this->writeCommand('home/.candy-code/commands/shared.md', "---\ndescription: user shared\n---\nuser body");
        $this->writeCommand('project/.candy-code/commands/shared.md', "---\ndescription: project shared\n---\nproject body");

        $commands = (new CommandLoader())->loadAll($project);

        $this->assertSame('user compact', $commands['compact']->description);
        $this->assertSame('project shared', $commands['shared']->description);
        $this->assertCount(count(CommandRegistry::all()) + 1, $commands);
    }

    /**
     * ISS-036: a project-tier file that WOULD shadow a built-in is replaced
     * back on an untrusted root, refused on the same launch-report channel the
     * `` !`cmd` `` gate uses, and never announced as an override.
     */
    public function testLoadAllRefusesProjectTierBuiltInShadowsOnAnUntrustedRoot(): void
    {
        $project = $this->tmp . '/project';
        $this->writeCommand('project/.candy-code/commands/compact.md', 'project compact body');

        $loader = new CommandLoader();
        $commands = $loader->loadAll($project);

        $this->assertFalse($commands['compact']->isFileBased(), 'the built-in row survived the merge');
        $this->assertSame([$project . '/.candy-code/commands/compact.md'], array_keys($loader->refusedCommands()));
        $this->assertStringContainsString('trustedProjectCommands', array_values($loader->refusedCommands())[0]);
        $this->assertSame([], $loader->overrides());
    }

    /**
     * The same file on a TRUSTED root overrides — and the override is REPORTED,
     * which is the half ISS-036 adds beyond the gate: an operator who granted
     * the root still learns their `/compact` is no longer the shipped one.
     */
    public function testLoadAllReportsTheOverrideWhenTheRootIsTrusted(): void
    {
        $project = $this->tmp . '/project';
        $this->writeCommand('project/.candy-code/commands/compact.md', 'project compact body');

        $loader = new CommandLoader();
        $commands = $loader->loadAll($project, true);

        $this->assertTrue($commands['compact']->isFileBased());
        $this->assertSame(['compact'], array_keys($loader->overrides()));
        $this->assertStringContainsString('project-tier command file replaced the built-in', array_values($loader->overrides())[0]);
        $this->assertSame([], $loader->refusedCommands());
    }

    /** A USER-tier shadow needs no grant but is still announced. */
    public function testLoadAllReportsAUserTierOverrideWithoutAnyGrant(): void
    {
        $_SERVER['HOME'] = $this->tmp . '/home';
        putenv('HOME=' . $_SERVER['HOME']);
        $this->writeCommand('home/.candy-code/commands/compact.md', 'user compact body');

        $loader = new CommandLoader();
        $commands = $loader->loadAll($this->tmp . '/no-project');

        $this->assertTrue($commands['compact']->isFileBased());
        $this->assertStringContainsString('user-tier command file replaced the built-in', array_values($loader->overrides())[0]);
    }

    public function testLoadAllWithNoCommandDirectoriesIsJustTheBuiltInRegistry(): void
    {        $_SERVER['HOME'] = $this->tmp . '/no-home';
        putenv('HOME=' . $_SERVER['HOME']);

        $commands = (new CommandLoader())->loadAll($this->tmp . '/no-project');

        $this->assertCount(count(CommandRegistry::all()), $commands);
    }
}
