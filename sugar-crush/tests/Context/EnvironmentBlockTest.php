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

    // ─── P8.10: the size-capped git diff ────────────────────────────

    /**
     * Initialise a real git repo in the temp dir, or skip.
     *
     * A real repo, not `mkdir .git`: every assertion below is about what `git
     * diff` actually emits, and the fake-`.git` shape is covered separately by
     * {@see testAFakeGitDirectoryIsReportedAsGitFailingNotAsACleanTree()}.
     */
    private function initGitRepo(): void
    {
        $q = escapeshellarg($this->tempDir);
        shell_exec('git -C ' . $q . ' init -q 2>/dev/null');
        shell_exec('git -C ' . $q . ' config user.email crush@example.test 2>/dev/null');
        shell_exec('git -C ' . $q . ' config user.name crush 2>/dev/null');

        if (!is_dir($this->tempDir . '/.git')) {
            $this->markTestSkipped('git is unavailable in this environment');
        }
    }

    private function gitCommitAll(string $message): void
    {
        $q = escapeshellarg($this->tempDir);
        shell_exec('git -C ' . $q . ' add -A 2>/dev/null');
        shell_exec('git -C ' . $q . ' commit -q -m ' . escapeshellarg($message) . ' 2>/dev/null');
    }

    /** The text of one labelled diff section, without the rest of the block. */
    private function diffSection(string $output, string $label): string
    {
        $start = strpos($output, $label);
        $this->assertNotFalse($start, "expected a '{$label}' section in the block");

        $rest = substr($output, $start);
        // A section runs to the next section label or to the closing fence.
        foreach (['Unstaged changes (git diff,', "\n</env>"] as $terminator) {
            $end = strpos($rest, $terminator, 1);
            if ($end !== false) {
                $rest = substr($rest, 0, $end);
            }
        }

        return $rest;
    }

    /**
     * The unfixed build emitted no diff at all, so this fails against it.
     */
    public function testAnUnstagedEditAppearsAsAnActualDiffBodyNotJustALabel(): void
    {
        $this->initGitRepo();
        file_put_contents($this->tempDir . '/tracked.txt', "original\n");
        $this->gitCommitAll('seed');
        file_put_contents($this->tempDir . '/tracked.txt', "rewritten-by-the-agent\n");

        $section = $this->diffSection(
            EnvironmentBlock::capture($this->tempDir, 'model')->render(),
            'Unstaged changes (git diff,',
        );

        // The BODY, not the presence of a heading: a `+`-prefixed line carrying
        // the new text is what proves a patch was emitted.
        $this->assertStringContainsString('+rewritten-by-the-agent', $section);
        $this->assertStringContainsString('-original', $section);
        $this->assertStringContainsString('1 file changed', $section);
    }

    /**
     * THE DOMAIN TEST. `git diff HEAD` would satisfy every "is there a diff"
     * assertion above while merging the two views into one, and a model told
     * only "the diff" then reports staged work as its own. This fails for that
     * implementation as well as for the unfixed one.
     */
    public function testStagedAndUnstagedChangesAreReportedSeparatelyAndNotConflated(): void
    {
        $this->initGitRepo();
        file_put_contents($this->tempDir . '/staged.txt', "before\n");
        file_put_contents($this->tempDir . '/unstaged.txt', "before\n");
        $this->gitCommitAll('seed');

        file_put_contents($this->tempDir . '/staged.txt', "ALPHA-ONLY-PAYLOAD\n");
        shell_exec('git -C ' . escapeshellarg($this->tempDir) . ' add staged.txt 2>/dev/null');
        file_put_contents($this->tempDir . '/unstaged.txt', "BRAVO-ONLY-PAYLOAD\n");

        // The two tokens must not be substrings of one another, or the
        // assertNotContains pair below can never pass — the first draft used
        // STAGED/UNSTAGED and failed on exactly that.
        $this->assertStringNotContainsString('ALPHA-ONLY-PAYLOAD', 'BRAVO-ONLY-PAYLOAD');

        $output = EnvironmentBlock::capture($this->tempDir, 'model')->render();
        $staged = $this->diffSection($output, 'Staged changes (git diff --cached,');
        $unstaged = $this->diffSection($output, 'Unstaged changes (git diff,');

        $this->assertStringContainsString('+ALPHA-ONLY-PAYLOAD', $staged);
        $this->assertStringNotContainsString('BRAVO-ONLY-PAYLOAD', $staged);

        $this->assertStringContainsString('+BRAVO-ONLY-PAYLOAD', $unstaged);
        $this->assertStringNotContainsString('ALPHA-ONLY-PAYLOAD', $unstaged);
    }

    /**
     * A clean tree must be distinguishable from a clipped one — the whole
     * reason the cap is announced.
     */
    public function testACleanTreeSaysNoneRatherThanEmittingAnEmptyDiff(): void
    {
        $this->initGitRepo();
        file_put_contents($this->tempDir . '/tracked.txt', "settled\n");
        $this->gitCommitAll('seed');

        $output = EnvironmentBlock::capture($this->tempDir, 'model')->render();

        $this->assertStringContainsString('Staged changes (git diff --cached, index vs HEAD): (none)', $output);
        $this->assertStringContainsString('Unstaged changes (git diff, working tree vs index): (none)', $output);
        $this->assertStringNotContainsString('truncated:', $output);
    }

    /**
     * Writes a working-tree diff comfortably larger than the cap and returns
     * git's own raw `--shortstat --patch` stdout for it, bytes included.
     */
    private function seedOversizedDiff(): string
    {
        $filler = str_repeat("seed line that will be rewritten wholesale\n", 400);
        file_put_contents($this->tempDir . '/big.txt', $filler);
        $this->gitCommitAll('seed');
        file_put_contents($this->tempDir . '/big.txt', str_repeat("REPLACED payload line\n", 400));

        $raw = (string) shell_exec(
            'git -C ' . escapeshellarg($this->tempDir) . ' diff --shortstat --patch 2>/dev/null',
        );
        $this->assertGreaterThan(
            EnvironmentBlock::DIFF_MAX_BYTES,
            strlen($raw),
            'fixture must exceed the cap or it proves nothing about truncation',
        );

        return $raw;
    }

    /**
     * The marker's TOTAL must be the real diff size, not the size of the part
     * that happened to fit. A capped read that reported only what it retained
     * would put a true number next to the wrong domain — and the model would
     * conclude it had seen most of the change.
     */
    public function testTruncationIsAnnouncedAndTheByteTotalIsTheRealDiffSize(): void
    {
        $this->initGitRepo();
        $rawDiff = $this->seedOversizedDiff();
        $realSize = strlen($rawDiff);

        $section = $this->diffSection(
            EnvironmentBlock::capture($this->tempDir, 'model')->render(),
            'Unstaged changes (git diff,',
        );

        $this->assertSame(
            1,
            preg_match('/\[truncated: (\d+) of (\d+) bytes omitted\./', $section, $m),
            'an over-cap diff must announce its clip',
        );

        // THE POINT: the total describes the WHOLE diff, not the sample that
        // fit. A capped read reporting only what it retained would print a true
        // number against the wrong domain, and the model would conclude it had
        // seen nearly everything.
        $this->assertGreaterThan(
            2 * EnvironmentBlock::DIFF_MAX_BYTES,
            (int) $m[2],
            'the announced total must be the whole diff, not the retained sample',
        );

        // Exact, by replicating the mechanism rather than by tolerance.
        // CapturesProcessOutput retains the first DIFF_MAX_BYTES bytes of stdout
        // and then rtrim()s newlines off that WINDOW, so the announced total
        // undercounts git's real stdout by however many trailing newlines the
        // window happened to end with. That is a pre-existing property shared
        // with Bash/Grep/Glob, not something this block introduces; it is
        // replicated here so the figure is pinned rather than approximated.
        $retained = substr($rawDiff, 0, EnvironmentBlock::DIFF_MAX_BYTES);
        $expectedTotal = strlen(rtrim($retained, "\n"))
            + (strlen($rawDiff) - EnvironmentBlock::DIFF_MAX_BYTES);
        $this->assertSame($expectedTotal, (int) $m[2]);
        $this->assertSame($expectedTotal, (int) $m[1] + strlen($this->keptBody($section)));
        $this->assertGreaterThanOrEqual($expectedTotal, $realSize);
    }

    /** The retained patch text of a section: after the label, before the marker. */
    private function keptBody(string $section): string
    {
        $body = substr($section, strpos($section, ":\n") + 2);
        $marker = strpos($body, '... [truncated:');

        return $marker === false ? rtrim($body, "\n") : substr($body, 0, $marker - 1);
    }

    /**
     * The `--shortstat` summary leads the section precisely so the cap (which
     * clips from the END) can never remove it. Without it a truncated section
     * would give the model no complete figure for how much it is missing, and
     * the file count is the figure it would misreport.
     */
    public function testTheShortstatScaleSurvivesTruncationAndIsComplete(): void
    {
        $this->initGitRepo();
        file_put_contents($this->tempDir . '/other.txt', "a\n");
        $this->seedOversizedDiff();
        file_put_contents($this->tempDir . '/other.txt', "b\n");

        $section = $this->diffSection(
            EnvironmentBlock::capture($this->tempDir, 'model')->render(),
            'Unstaged changes (git diff,',
        );

        $this->assertStringContainsString('truncated:', $section, 'fixture must be over the cap');
        $this->assertSame(
            1,
            preg_match('/^ (\d+) files changed/m', $section, $m),
            'the shortstat line must survive the clip',
        );
        // TWO files were changed; the clipped patch body only ever reaches the
        // first. The scale line is the only place that number is complete.
        $this->assertSame(2, (int) $m[1]);
    }

    /**
     * A clip landing mid-line would hand the model a plausible-looking patch
     * line that was never in the diff.
     */
    public function testATruncatedDiffEndsOnACompleteLine(): void
    {
        $this->initGitRepo();
        $this->seedOversizedDiff();

        $section = $this->diffSection(
            EnvironmentBlock::capture($this->tempDir, 'model')->render(),
            'Unstaged changes (git diff,',
        );

        $body = $this->keptBody($section);
        $this->assertNotSame('', $body);
        $lines = explode("\n", $body);
        $last = end($lines);
        // Every line git emits inside a patch body starts with one of these.
        $this->assertContains(
            $last === '' ? ' ' : $last[0],
            [' ', '+', '-', '@', 'd', 'i', '\\'],
            "a clipped body must end on a whole patch line, got: {$last}",
        );
    }

    /**
     * The bound is DERIVED from the constants rather than written as a literal,
     * so a future change to either cap cannot leave this test asserting a
     * number that stopped describing the code.
     */
    public function testTheWholeGitSectionStaysBoundedHoweverDirtyTheTreeIs(): void
    {
        $this->initGitRepo();
        for ($i = 0; $i < 60; $i++) {
            file_put_contents($this->tempDir . "/f{$i}.txt", str_repeat("line\n", 200));
        }
        $this->gitCommitAll('seed');
        for ($i = 0; $i < 60; $i++) {
            file_put_contents($this->tempDir . "/f{$i}.txt", str_repeat("changed\n", 200));
            file_put_contents($this->tempDir . "/untracked{$i}.txt", 'x');
        }

        $output = EnvironmentBlock::capture($this->tempDir, 'model')->render();

        // Two capped diff sections, two capped summary fields = 24,576 B of
        // FIELD text (each field's truncation marker is reserved inside its own
        // cap by TruncatesOutput, not added on top), plus the fixed part: seven
        // labelled lines, the branch line, four field labels and the <env>
        // fence. 1 KiB covers that fixed part, which makes the derived ceiling
        // 25,600 B — and that number is not chosen here, it is the SAME
        // ARITHMETIC SUMMARY_MAX_BYTES's docblock states as "under 25 KiB".
        // The earlier 4 KiB slack made the ceiling 28,672 and left the prose
        // claim unpinned: the block could have grown 4 KiB past what the comment
        // promised with this test still green.
        $ceiling = 2 * EnvironmentBlock::DIFF_MAX_BYTES + 2 * EnvironmentBlock::SUMMARY_MAX_BYTES + 1024;
        $this->assertSame(25600, $ceiling, 'the derived ceiling must still be the 25 KiB the docblock claims');
        $this->assertLessThan($ceiling, strlen($output), 'the <env> block must not scale with the diff');
        // ...and it must still be a real answer, not an empty one.
        $this->assertStringContainsString('truncated:', $output);
    }

    /**
     * `mkdir .git` with no `git init` is the shape three tests in this class
     * already build. Before this change every git field rendered EMPTY for it,
     * which is byte-identical to a spotless checkout — so the block told the
     * model "nothing has changed" on evidence that said "nothing was read".
     */
    public function testAFakeGitDirectoryIsReportedAsGitFailingNotAsACleanTree(): void
    {
        mkdir($this->tempDir . '/.git', 0777, true);

        $output = EnvironmentBlock::capture($this->tempDir, 'model')->render();

        $this->assertStringContainsString('Is directory a git repo: Yes', $output);
        $this->assertStringContainsString('Status:' . "\n" . 'unavailable (git exited', $output);
        $this->assertStringContainsString('Recent commits:' . "\n" . 'unavailable (git exited', $output);
        $this->assertStringContainsString('index vs HEAD): unavailable (git exited', $output);
        $this->assertStringNotContainsString('(none)', $output);
    }

    /**
     * `--porcelain` lists untracked paths too, so the field it feeds was the
     * unbounded one — measured at 9,791 B over 291 paths while sizing the diff
     * cap, which defeated the diff cap sitting right next to it.
     */
    public function testTheStatusFieldIsCappedAndAnnouncesItsOwnClip(): void
    {
        $this->initGitRepo();
        $perPath = 40; // "?? untracked_<n>.txt\n" plus slack
        $paths = intdiv(EnvironmentBlock::SUMMARY_MAX_BYTES, $perPath) * 3;
        for ($i = 0; $i < $paths; $i++) {
            file_put_contents($this->tempDir . "/untracked_{$i}.txt", 'x');
        }

        $output = EnvironmentBlock::capture($this->tempDir, 'model')->render();
        $status = substr($output, strpos($output, "Status:\n") + 8);
        $status = substr($status, 0, strpos($status, "\n\nRecent commits:"));

        $this->assertSame(
            1,
            preg_match('/\[truncated: \d+ of (\d+) bytes omitted\./', $status, $m),
            'an over-cap status must announce its clip',
        );
        $this->assertGreaterThan(EnvironmentBlock::SUMMARY_MAX_BYTES, (int) $m[1]);
        $this->assertLessThan(EnvironmentBlock::SUMMARY_MAX_BYTES, strlen($status));
    }

    // ─── the block's own encoding invariant ─────────────────────────

    /**
     * ONE latin-1 text file in the working tree used to break EVERY step of the
     * session, and the failure was nowhere near the git section: `--porcelain`
     * quotes non-ASCII PATHS, but a diff BODY is raw bytes, so adding the diff
     * put arbitrary working-tree bytes into the system prompt for the first
     * time. `json_encode()` returns false on those, and
     * `GuzzleHttp\Utils::jsonEncode()` — the `'json' => $params` path in the
     * SGLang and Custom providers — throws.
     *
     * The pre-fix figures, on this exact fixture: 648 B block,
     * `mb_check_encoding()` false, Guzzle throws. The three-command version of
     * the class on the same fixture: 331 B, valid UTF-8. So this pins a
     * regression, not a property the prompt path always had.
     */
    public function testNonUtf8WorkingTreeBytesLeaveTheBlockEncodable(): void
    {
        $this->initGitRepo();
        // Deliberately NOT a UTF-8 string: latin-1 "café naïve", the shape any
        // pre-UTF-8 source file or fixture in a real repository has.
        file_put_contents($this->tempDir . '/notes.txt', "caf\xe9 na\xefve\n");
        $this->gitCommitAll('seed');
        file_put_contents($this->tempDir . '/notes.txt', "caf\xe9 na\xefve rewritten\n");

        $output = EnvironmentBlock::capture($this->tempDir, 'model')->render();

        // NOT VACUOUS: the diff body has to have reached the block at all, or
        // "valid UTF-8" would be proved by an empty section.
        $this->assertStringContainsString('+caf? na?ve rewritten', $output);

        $this->assertTrue(mb_check_encoding($output, 'UTF-8'), 'the block must be encodable');
        $this->assertNotFalse(json_encode(['system' => $output]), 'the request body must encode');

        // ANNOUNCED, for the same reason truncation is: a silent repair leaves
        // the model reading `caf?` as a filename that is really spelled that
        // way. Four sequences here — two invalid bytes on the `-` line and two
        // on the `+` line — and the count's domain is the RENDERED, ALREADY
        // CAPPED block, not the underlying diff.
        $this->assertStringContainsString(
            '[encoding: 4 byte sequence(s) of this block were not valid UTF-8',
            $output,
        );
    }

    /** A clean UTF-8 tree must not get the note, or the note means nothing. */
    public function testAValidUtf8TreeIsLeftAloneAndCarriesNoEncodingNote(): void
    {
        $this->initGitRepo();
        file_put_contents($this->tempDir . '/notes.txt', "café naïve\n");
        $this->gitCommitAll('seed');
        file_put_contents($this->tempDir . '/notes.txt', "café naïve rewritten\n");

        $output = EnvironmentBlock::capture($this->tempDir, 'model')->render();

        $this->assertStringContainsString('+café naïve rewritten', $output);
        $this->assertStringNotContainsString('[encoding:', $output);
    }

    // ─── the log field's own cap ────────────────────────────────────

    /**
     * `log --oneline -5` is bounded to five LINES by its flag, which is a bound
     * in the wrong dimension — a commit subject has no length limit. The cap was
     * argued for in SUMMARY_MAX_BYTES's docblock and then not tested, so
     * replacing `self::SUMMARY_MAX_BYTES` with `0` on the log call (which
     * disables the bound entirely, since both appendBounded() and
     * truncateMerged() treat a non-positive max as "no cap") left every
     * assertion in this class green.
     */
    public function testTheRecentLogFieldIsCappedAndAnnouncesItsOwnClip(): void
    {
        $this->initGitRepo();
        $subject = str_repeat('x', 1500);
        for ($i = 0; $i < 5; $i++) {
            file_put_contents($this->tempDir . "/c{$i}.txt", 'x');
            $this->gitCommitAll("{$i}-{$subject}");
        }

        $output = EnvironmentBlock::capture($this->tempDir, 'model')->render();
        $log = $this->logField($output);

        // The fixture must exceed the cap or it proves nothing.
        $raw = (string) shell_exec(
            'git -C ' . escapeshellarg($this->tempDir) . ' log --oneline -5 2>/dev/null',
        );
        $this->assertGreaterThan(EnvironmentBlock::SUMMARY_MAX_BYTES, strlen($raw));

        $this->assertStringContainsString('... [truncated:', $log);
        $this->assertLessThan(EnvironmentBlock::SUMMARY_MAX_BYTES, strlen($log));
    }

    /**
     * The other half of the cap: a log that FITS must arrive whole. Without this
     * the test above is satisfied by a cap of zero, which drops everything and
     * announces it — a marker present is not a cap working.
     */
    public function testALogThatFitsUnderTheCapIsNotClipped(): void
    {
        $this->initGitRepo();
        file_put_contents($this->tempDir . '/a.txt', 'x');
        $this->gitCommitAll('a-short-and-complete-subject');

        $log = $this->logField(EnvironmentBlock::capture($this->tempDir, 'model')->render());

        $this->assertStringContainsString('a-short-and-complete-subject', $log);
        $this->assertStringNotContainsString('... [truncated:', $log);
    }

    /** The `Recent commits:` field text, without the sections around it. */
    private function logField(string $output): string
    {
        $log = substr($output, strpos($output, "Recent commits:\n") + 16);

        return substr($log, 0, strpos($log, "\n\nStaged changes"));
    }

    // ─── worktrees and submodules ───────────────────────────────────

    /**
     * A `git worktree` and a submodule both spell `.git` as a FILE holding a
     * `gitdir:` pointer, so the `is_dir()` this method used made P8.10's whole
     * deliverable silently dead there. MEASURED on a real `git worktree add`
     * before the fix: `Is directory a git repo: No` and NO git section at all —
     * 256 B against 593 B after. The same change-set argued for `file_exists`
     * 200 lines away in InstructionFileLoader::ancestorRoot() and left this one
     * disagreeing with it.
     */
    public function testAWorktreeIsRecognisedAsARepoAndStillGetsItsDiff(): void
    {
        $this->initGitRepo();
        file_put_contents($this->tempDir . '/f.txt', "one\n");
        $this->gitCommitAll('seed');

        $linked = $this->tempDir . '/linked';
        shell_exec(
            'git -C ' . escapeshellarg($this->tempDir) . ' worktree add -q '
            . escapeshellarg($linked) . ' -b side 2>/dev/null',
        );
        if (!is_file($linked . '/.git')) {
            $this->markTestSkipped('git worktree is unavailable in this environment');
        }

        file_put_contents($linked . '/f.txt', "edited-in-the-worktree\n");
        $output = EnvironmentBlock::capture($linked, 'model')->render();

        $this->assertStringContainsString('Is directory a git repo: Yes', $output);
        $this->assertStringContainsString('+edited-in-the-worktree', $output);
    }

    // ─── the process helpers this class now depends on ──────────────

    /**
     * The capped fields go through `proc_open`, and a function listed in
     * `disable_functions` is UNDEFINED — so calling it raises an `Error` that
     * `@` does not suppress. Before the guards, `render()` THREW on such a build
     * and took the whole system-prompt build with it, where the three-command
     * version of this class returned a 327 B block on the same host. A per-field
     * unavailability line is the same trade the git exit codes make.
     *
     * Driven in a subprocess because `disable_functions` is PHP_INI_SYSTEM and
     * cannot be set from inside a running test.
     */
    public function testADisabledProcOpenReportsTheMissingHelperInsteadOfKillingTheRender(): void
    {
        if (PHP_BINARY === '' || !is_file(PHP_BINARY)) {
            $this->markTestSkipped('no PHP binary to re-enter');
        }

        // A REAL repo as the probe's root: the point is what the GIT SECTION
        // says when the helper is gone, and a non-repo root emits no git section
        // at all — which is how the first draft of this test passed its own name
        // while asserting nothing about proc_open.
        $this->initGitRepo();
        file_put_contents($this->tempDir . '/f.txt', "one\n");
        $this->gitCommitAll('seed');

        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = $this->tempDir . '/probe.php';
        file_put_contents($script, sprintf(
            '<?php require %s; echo (new \SugarCraft\Crush\Context\EnvironmentBlock(%s, "m"))->render();',
            var_export($autoload, true),
            var_export($this->tempDir, true),
        ));

        $out = (string) shell_exec(
            escapeshellarg(PHP_BINARY) . ' -d disable_functions=proc_open '
            . escapeshellarg($script) . ' 2>&1',
        );

        if (str_contains($out, 'Call to undefined function') === false && $out === '') {
            $this->markTestSkipped('disable_functions could not be applied on this build');
        }

        $this->assertStringNotContainsString('Fatal error', $out);
        $this->assertStringNotContainsString('Call to undefined function', $out);
        $this->assertStringContainsString('unavailable (proc_open is disabled on this build)', $out);
        // The uncapped branch line still goes through shell_exec, which this
        // probe leaves enabled — so the block is degraded, not blank.
        $this->assertStringContainsString('Current branch:', $out);
    }

    private function buildSystemPrompt(Runtime $runtime, App $app): string
    {
        $method = new \ReflectionMethod($runtime, 'buildSystemPrompt');
        $method->setAccessible(true);

        return (string) $method->invoke($runtime, $app);
    }
}
