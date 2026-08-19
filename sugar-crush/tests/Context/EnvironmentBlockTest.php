<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Context\EnvironmentBlock;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;

/**
 * Tests for EnvironmentBlock — covers capture(), render(), and gitStatusSnapshot().
 */
final class EnvironmentBlockTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/environment_block_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ─── capture() factory tests ───────────────────────────────────

    public function testCaptureSetsCwdAndModelName(): void
    {
        $block = EnvironmentBlock::capture('/some/path', 'claude-sonnet-4-6');

        $this->assertSame('/some/path', $block->cwd());
        $this->assertSame('claude-sonnet-4-6', $block->modelName());
    }

    public function testCaptureSetsNowTimestamp(): void
    {
        $before = new DateTimeImmutable();
        $block = EnvironmentBlock::capture('/any', 'model');
        $after = new DateTimeImmutable();

        $this->assertNotNull($block->now());
        $this->assertGreaterThanOrEqual($before, $block->now());
        $this->assertLessThanOrEqual($after, $block->now());
    }

    // ─── render() basic structure tests ─────────────────────────────

    public function testRenderContainsEnvTags(): void
    {
        $block = EnvironmentBlock::capture($this->tempDir, 'test-model');
        $output = $block->render();

        $this->assertStringStartsWith("<env>\n", $output);
        $this->assertStringEndsWith("\n</env>", $output);
    }

    public function testRenderContainsWorkingDirectory(): void
    {
        $block = EnvironmentBlock::capture($this->tempDir, 'test-model');
        $output = $block->render();

        $this->assertStringContainsString('Working directory: ' . $this->tempDir, $output);
    }

    public function testRenderContainsPlatform(): void
    {
        $block = EnvironmentBlock::capture($this->tempDir, 'test-model');
        $output = $block->render();

        $this->assertStringContainsString('Platform: ' . strtolower(PHP_OS_FAMILY), $output);
    }

    /**
     * The crush_code.md Phase 5 item 10a OS-version line.
     *
     * Pinned to the expression that produces it rather than to a literal string,
     * because the literal would be a fact about THIS machine's kernel and this
     * assertion has to hold on every runner.
     *
     * DOMAIN: `php_uname('s') . ' ' . php_uname('r')` is the OS/kernel RELEASE,
     * which on Linux is the kernel version ("Linux 6.8.0-137-generic"), on macOS
     * the Darwin version ("Darwin 23.5.0") and NOT the macOS product version, and
     * on Windows "Windows NT 10.0". The `php_uname('s')` prefix is what makes the
     * value name its own domain instead of leaving a bare number under a label
     * that does not own it.
     */
    public function testRenderContainsTheOsVersionAndItIsNotJustThePlatformFamily(): void
    {
        $output = EnvironmentBlock::capture($this->tempDir, 'test-model')->render();

        $this->assertStringContainsString(
            'OS version: ' . php_uname('s') . ' ' . php_uname('r'),
            $output,
        );
        $this->assertNotSame(
            strtolower(PHP_OS_FAMILY),
            strtolower(php_uname('s') . ' ' . php_uname('r')),
            'if this ever became equal to the Platform line, the second line would be redundant',
        );
    }

    /**
     * crush_code.md Phase 5 item 10a also asks for an "additional working
     * directories" line. It is deliberately NOT emitted, and this pins that as a
     * decision rather than an omission.
     *
     * There is no multi-root concept in this application to describe: `App::$root`
     * (`--root`'s value) and the process cwd are the only directories that exist,
     * and a grep for additionalDir/additionalWorking/extraDirs/workingDirs across
     * src/ finds nothing. A permanently-blank line would be a decorative surface,
     * and inventing directories to fill it would be worse. The prerequisite is
     * recorded in docs/plans/crush_code_hardening_backlog.md.
     */
    public function testNoAdditionalWorkingDirectoriesLineIsEmitted(): void
    {
        $output = EnvironmentBlock::capture($this->tempDir, 'test-model')->render();

        $this->assertStringNotContainsString('dditional working director', $output);
        $this->assertStringNotContainsString('dditional director', $output);
    }

    /**
     * The WHOLE line set, in order.
     *
     * Every other assertion in this class is a `assertStringContainsString` on one
     * line, which cannot notice a line being added, removed or reordered — that is
     * how the OS-version line could be added without reddening anything. This one
     * pins the set, so any future change to the block has to come here and say so.
     */
    public function testTheCompleteLineSetAndItsOrder(): void
    {
        $output = EnvironmentBlock::capture($this->tempDir, 'test-model')->render();

        $body = substr($output, strlen("<env>\n"), -strlen("\n</env>"));

        $this->assertSame([
            'Working directory: ' . $this->tempDir,
            'Is directory a git repo: No',
            'Platform: ' . strtolower(PHP_OS_FAMILY),
            'OS version: ' . php_uname('s') . ' ' . php_uname('r'),
            'PHP version: ' . PHP_VERSION,
            'Model: test-model',
            'Current date: ' . (new \DateTimeImmutable())->format('Y-m-d'),
        ], explode("\n", $body));
    }

    public function testRenderContainsPhpVersion(): void
    {
        $block = EnvironmentBlock::capture($this->tempDir, 'test-model');
        $output = $block->render();

        $this->assertStringContainsString('PHP version: ' . PHP_VERSION, $output);
    }

    public function testRenderContainsModelName(): void
    {
        $block = EnvironmentBlock::capture($this->tempDir, 'my-custom-model');
        $output = $block->render();

        $this->assertStringContainsString('Model: my-custom-model', $output);
    }

    public function testRenderContainsCurrentDate(): void
    {
        $now = new DateTimeImmutable();
        $block = EnvironmentBlock::capture($this->tempDir, 'model', $now);
        $output = $block->render();

        $this->assertStringContainsString('Current date: ' . $now->format('Y-m-d'), $output);
    }

    // ─── render() non-git directory tests ───────────────────────────

    public function testRenderShowsNotGitRepoWhenNoGitDirectory(): void
    {
        $block = EnvironmentBlock::capture($this->tempDir, 'model');
        $output = $block->render();

        $this->assertStringContainsString('Is directory a git repo: No', $output);
        // Should not include git status block
        $this->assertStringNotContainsString('Current branch:', $output);
        $this->assertStringNotContainsString('Status:', $output);
        $this->assertStringNotContainsString('Recent commits:', $output);
    }

    // ─── render() git directory tests ───────────────────────────────

    public function testRenderShowsGitRepoWhenGitDirectoryPresent(): void
    {
        // Create a fake .git directory
        mkdir($this->tempDir . '/.git', 0777, true);

        $block = EnvironmentBlock::capture($this->tempDir, 'model');
        $output = $block->render();

        $this->assertStringContainsString('Is directory a git repo: Yes', $output);
    }

    public function testRenderIncludesGitStatusSnapshotInGitRepo(): void
    {
        // Create a fake .git directory
        mkdir($this->tempDir . '/.git', 0777, true);

        $block = EnvironmentBlock::capture($this->tempDir, 'model');
        $output = $block->render();

        $this->assertStringContainsString('Current branch:', $output);
        $this->assertStringContainsString('Status:', $output);
        $this->assertStringContainsString('Recent commits:', $output);
    }

    // ─── constructor tests ─────────────────────────────────────────

    public function testConstructorAcceptsOptionalNow(): void
    {
        $now = new DateTimeImmutable('2025-01-01');
        $block = new EnvironmentBlock('/path', 'model', $now);

        $this->assertSame($now, $block->now());
    }

    public function testConstructorDefaultsNowToNull(): void
    {
        $block = new EnvironmentBlock('/path', 'model');

        $this->assertNull($block->now());
    }

    public function testConstructorDefaultsNowToNullViaCapture(): void
    {
        // When constructing directly with null now, render falls back to new DateTimeImmutable
        $block = new EnvironmentBlock($this->tempDir, 'model', null);
        $output = $block->render();

        // Should contain a date in Y-m-d format
        $this->assertMatchesRegularExpression('/Current date: \d{4}-\d{2}-\d{2}/', $output);
    }

    // ─── immutability tests ─────────────────────────────────────────

    public function testInstancesAreImmutable(): void
    {
        $block = EnvironmentBlock::capture($this->tempDir, 'model');

        $this->assertSame($this->tempDir, $block->cwd());
        $this->assertSame('model', $block->modelName());
    }

    // ─── `--root` propagation (crush_code.md Phase 0 item 6) ────────
    //
    // Every case below deliberately points the configured root at a temp
    // directory that is NOT the process cwd. A test where the two coincide
    // proves nothing here: the whole defect was that the block reported
    // `getcwd()` while the tools were jailed somewhere else, and that is
    // invisible unless they differ.

    public function testCaptureReportsTheGivenRootRatherThanTheProcessDirectory(): void
    {
        $this->assertNotSame(getcwd(), $this->tempDir, 'the fixture must diverge from the process cwd');

        $output = EnvironmentBlock::capture($this->tempDir, 'model')->render();

        $this->assertStringContainsString('Working directory: ' . $this->tempDir, $output);
        $this->assertStringNotContainsString('Working directory: ' . getcwd() . "\n", $output);
    }

    /**
     * The git half of the same divergence: `Is directory a git repo` and the
     * status snapshot are read from the CAPTURED directory. sugar-crush lives
     * inside a git repo, so a block that had silently fallen back to
     * `getcwd()` would answer "Yes" here — which is exactly how the bug
     * presented (`--root <lib>` describing the enclosing monorepo's git
     * state to the model).
     */
    public function testCaptureReadsGitStateFromTheGivenRootNotTheProcessDirectory(): void
    {
        $output = EnvironmentBlock::capture($this->tempDir, 'model')->render();

        $this->assertStringContainsString('Is directory a git repo: No', $output);
    }

    /**
     * The production capture path. {@see \SugarCraft\Crush\Runtime} is what
     * actually builds the block folded into every system prompt, and it used
     * to call `getcwd()` bare — so an App configured with `--root` still told
     * the model it was standing in the process directory.
     */
    public function testRuntimeCapturesTheEnvironmentAtTheAppsConfiguredRoot(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test-provider');

        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $app = App::new($provider, 'gpt-4')->withRoot($this->tempDir);

        $prompt = $this->buildSystemPrompt($runtime, $app);

        $this->assertStringContainsString('Working directory: ' . $this->tempDir, $prompt);
        $this->assertStringNotContainsString('Working directory: ' . getcwd() . "\n", $prompt);
    }

    /**
     * The unrooted App must keep falling back to the process directory —
     * `App::$root` is null for every test and embedder that never names one,
     * and null must not degrade to an empty working directory.
     */
    public function testRuntimeFallsBackToTheProcessDirectoryForAnUnrootedApp(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test-provider');

        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));

        $prompt = $this->buildSystemPrompt($runtime, App::new($provider, 'gpt-4'));

        $this->assertStringContainsString('Working directory: ' . getcwd(), $prompt);
    }

    private function buildSystemPrompt(Runtime $runtime, App $app): string
    {
        $method = new \ReflectionMethod($runtime, 'buildSystemPrompt');
        $method->setAccessible(true);

        return (string) $method->invoke($runtime, $app);
    }
}
