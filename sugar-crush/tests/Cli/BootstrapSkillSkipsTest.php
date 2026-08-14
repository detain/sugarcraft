<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * The seam that replaced {@see SkillLoader}'s per-skip `error_log()`.
 *
 * Taking those lines off stderr was right — they are OTHER TOOLS' files, one
 * broken third-party skill printed on every launch, and a skill scan also runs
 * mid-session on the Ctrl+P provider switch where a stderr write lands inside
 * a frame the renderer believes it owns. But `skipped()` had no caller in
 * `src/` or `bin/` at all, so the replacement was a diagnostic nothing read:
 * the README claimed skips "are also readable from SkillManager::skipped()",
 * which was true of the API and false of the product.
 */
final class BootstrapSkillSkipsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir;
    private string $home;
    private string $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/bootstrap_skill_skips_' . uniqid('', true);
        $this->home = $this->tempDir . '/home';
        $this->project = $this->tempDir . '/project';
        mkdir($this->project, 0700, true);
        $this->useHomeSandbox($this->home);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    /** The launch's skill scan keeps its diagnostic where a caller can ask for it. */
    public function testTheLaunchsSkillSkipsAreReadableFromBootstrap(): void
    {
        $broken = $this->writeBrokenSkill();

        Bootstrap::backend($this->project);

        $this->assertArrayHasKey($broken, Bootstrap::skillSkips());
    }

    /**
     * ...and ONE bounded line tells the user it is there to ask for. One line
     * regardless of how many skips: the failure this replaced was N lines on
     * every launch.
     */
    public function testTheLaunchReportsSkippedSkillsInOneLine(): void
    {
        $this->writeBrokenSkill('one');
        $this->writeBrokenSkill('two');

        $stderr = $this->stderrOfALaunch();

        $this->assertSame(1, substr_count($stderr, 'could not be read'));
        $this->assertStringContainsString('2 skill files', $stderr);
        $this->assertStringContainsString(SkillLoader::DEBUG_SKIPS_ENV, $stderr);
    }

    /** A clean skill tree says nothing at all. */
    public function testACleanLaunchIsSilent(): void
    {
        $this->assertStringNotContainsString('could not be read', $this->stderrOfALaunch());
    }

    /**
     * A `-p` RUN SCANS THE SAME TREES AND USED TO SWALLOW THE NOTICE ENTIRELY.
     * {@see \SugarCraft\Crush\Cli\NonInteractive::run()} builds its backend
     * directly rather than through {@see Bootstrap::chat()}, which was the only
     * caller that reported skips — so the one path with no alt screen to
     * protect was the one path that said nothing.
     */
    public function testAOneShotRunReportsSkippedSkillsToo(): void
    {
        $this->writeBrokenSkill();

        $stderr = $this->stderrOfAOneShotRun();

        $this->assertStringContainsString('could not be read', $stderr);
        $this->assertStringContainsString(SkillLoader::DEBUG_SKIPS_ENV, $stderr);
    }

    /** ...and a clean tree is silent there too. */
    public function testACleanOneShotRunIsSilent(): void
    {
        $this->assertStringNotContainsString('could not be read', $this->stderrOfAOneShotRun());
    }

    /**
     * The real binary, in one-shot mode. The offline echo provider answers, so
     * this needs no credentials and no network.
     */
    private function stderrOfAOneShotRun(): string
    {
        $errFile = $this->tempDir . '/oneshot-stderr.txt';

        exec(sprintf(
            'cd %s && HOME=%s timeout -s KILL 60 %s %s -p %s >/dev/null 2>%s',
            escapeshellarg($this->project),
            escapeshellarg($this->home),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 2) . '/bin/sugarcrush'),
            escapeshellarg('hello'),
            escapeshellarg($errFile),
        ));

        return is_file($errFile) ? (string) file_get_contents($errFile) : '';
    }

    private function writeBrokenSkill(string $name = 'broken'): string
    {
        $dir = $this->home . '/.claude/skills/' . $name;
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/SKILL.md', "no frontmatter at all\n");

        return $dir . '/SKILL.md';
    }

    /**
     * A CHILD process, because the notice goes to the STDERR constant — which
     * is the point: it must reach a real user, ahead of the alt screen.
     */
    private function stderrOfALaunch(): string
    {
        $script = $this->tempDir . '/launch.php';
        $errFile = $this->tempDir . '/stderr.txt';

        file_put_contents($script, sprintf(
            "<?php\nrequire %s;\n\\SugarCraft\\Crush\\Cli\\Bootstrap::chat(%s);\n",
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->project, true),
        ));

        exec(sprintf(
            'HOME=%s timeout -s KILL 60 %s %s >/dev/null 2>%s',
            escapeshellarg($this->home),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($errFile),
        ));

        return is_file($errFile) ? (string) file_get_contents($errFile) : '';
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isLink() || !$entry->isDir() ? @unlink($entry->getPathname()) : @rmdir($entry->getPathname());
        }

        @rmdir($dir);
    }
}
