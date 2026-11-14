<?php

declare(strict_types=1);

namespace SugarCraft\CandyImage\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A light net for the phar packaging surface (lane PHE). It deliberately does
 * NOT build an archive inside the suite — a real build needs a clean published
 * vendor/ and writes to disk, neither of which belongs in a unit run. What it
 * pins is the laws that, if broken, would make a published .phar silently
 * misbehave:
 *
 *  1. the bin is a valid archive stub (shebang + open tag) and carries an
 *     archive-aware autoload guard (both 'phar://' and 'Phar::running' — the
 *     measured PHP 8.3 rule that the stub and the required-from-inside
 *     contexts name the archive through different identities);
 *  2. tools/build-phar.php exists and refuses an unknown option with exit 2
 *     BEFORE touching the archive (so a typo cannot start a half-build);
 *  3. the version SOURCE agrees: the build stamps metadata['version'] and the
 *     runtime Support\Version reader consumes exactly that field, wired through
 *     Cli::runningVersion() so --version tells the truth inside an archive
 *     (the DST-1 lesson — InstalledVersions lies under a frozen vendor/);
 *  4. the argv surface keeps its fail-fast ordering (candy-image-013/021):
 *     a suffixless --out is refused before any guard or write, and the
 *     --vendor grammar is byte-pinned;
 *  5. every git question the build asks captures stderr (candy-image-029/053):
 *     the integrity porcelain read is scoped to this package, and the stamping
 *     describe/rev-parse calls run with 2>&1 so an unanswerable git reads as
 *     a loud failure, never a silent dev-build bake.
 */
final class PharBuildScriptTest extends TestCase
{
    private const APP_ROOT = __DIR__ . '/..';

    private function readAppFile(string $relative): string
    {
        $path = self::APP_ROOT . '/' . $relative;
        $contents = is_file($path) ? file_get_contents($path) : false;
        self::assertIsString($contents, "expected readable file {$relative}");

        return $contents;
    }

    public function testTheBinStubIsAValidArchiveWithAPharAwareGuard(): void
    {
        $bin = $this->readAppFile('bin/candy-image');

        // The build refuses unless the stub begins with the shebang+<?php
        // shape; pin the same precondition the build script checks for.
        self::assertTrue(
            str_starts_with($bin, "#!/usr/bin/env php\n<?php"),
            'bin/candy-image no longer starts with the shebang+<?php stub shape',
        );
        // The autoload guard must resolve BOTH phar identities, not just one.
        self::assertStringContainsString('phar://', $bin);
        self::assertStringContainsString('Phar::running', $bin);
        // A bare '/x.phar/entry' path fails is_file in PHP 8.3 — the guard must
        // never probe one, it must prefix with the phar:// stream instead.
        self::assertStringNotContainsString('.phar/vendor', $bin);
    }

    public function testTheBuildScriptRefusesUnknownOptionsBeforeBuilding(): void
    {
        $build = $this->readAppFile('tools/build-phar.php');

        self::assertStringContainsString('unrecognized option', $build);
        // The unknown-option arm and the phar.readonly guard both bail with 2.
        self::assertStringContainsString('exit(2)', $build);
        // The stub is bin/candy-image verbatim with __HALT_COMPILER();.
        self::assertStringContainsString('__HALT_COMPILER();', $build);
        // The symlink guard is the load-bearing linked-vs-published law.
        self::assertStringContainsString('is_link', $build);
    }

    public function testTheVersionStampIsStampedByTheBuildAndReadByTheRuntime(): void
    {
        $build = $this->readAppFile('tools/build-phar.php');
        $reader = $this->readAppFile('src/Support/Version.php');
        $cli = $this->readAppFile('src/Cli.php');

        // Build side: metadata carries a 'version' field.
        self::assertStringContainsString("'version'", $build);
        self::assertStringContainsString('setMetadata', $build);

        // Runtime side: the reader consumes exactly that field...
        self::assertStringContainsString("metadata['version']", $reader);
        self::assertStringContainsString('fromPharMetadata', $reader);
        // ...and the front end routes BOTH version prints through the resolver,
        // so a stamp wins inside an archive and the constant answers elsewhere.
        self::assertStringContainsString('Support\\Version::resolve', $cli);
        self::assertStringContainsString('self::runningVersion()', $cli);
        self::assertStringContainsString("'candy-image ' . self::runningVersion()", $cli);
    }

    public function testSuffixlessOutIsRefusedBeforeAnyGuardOrWrite(): void
    {
        [$rc, $err] = $this->runBuildScript(['--out=/tmp/candy-image-suffix-probe']);

        self::assertSame(2, $rc, 'a suffixless --out must exit 2');
        self::assertStringContainsString('must name a .phar file', $err);
        // Ordering pin: the refusal must come from the argv surface, above
        // Guard 1 — if the readonly message showed instead, a typo could
        // still be steered past the fail-fast chain.
        self::assertStringNotContainsString('phar.readonly', $err);
        clearstatcache();
        self::assertFalse(is_file('/tmp/candy-image-suffix-probe'), 'refused --out must never touch the disk');
    }

    public function testVendorModeRefusalGrammarIsBytePinned(): void
    {
        $build = $this->readAppFile('tools/build-phar.php');
        self::assertStringContainsString('--vendor={$candidate} is not a valid mode (expected published or monorepo).', $build);

        [$rc, $err] = $this->runBuildScript(['--vendor=bogus']);
        self::assertSame(2, $rc);
        self::assertStringContainsString('--vendor=bogus is not a valid mode (expected published or monorepo).', $err);
    }

    public function testGitQuestionsCaptureStderrAndScopeThePorcelainRead(): void
    {
        $build = $this->readAppFile('tools/build-phar.php');

        // candy-image-029: the integrity read counts only this package's paths.
        self::assertStringContainsString("' status --porcelain -- . 2>&1'", $build);
        // The answers are SHAPE-checked, not just fatal-checked: shell-level
        // complaints ("sh: 1: git: not found") contain ':' and would otherwise
        // pose as a branch name / dirty path in the refusal.
        self::assertStringContainsString("[ MARCDTUAX?!]{1,2} ", $build);
        // candy-image-053: no git question may swallow its stderr any more —
        // both stamping calls ask with 2>&1, like Guard 2's do.
        self::assertStringContainsString("' rev-parse HEAD 2>&1'", $build);
        self::assertMatchesRegularExpression("/describe --tags[^\n]*2>&1/", $build);
        self::assertStringNotContainsString('2>/dev/null', $build);
        // And an unanswerable git is loud in both modes (refuse / warn).
        self::assertStringContainsString('refusing to stamp the artifact', $build);
        self::assertStringContainsString('WARNING — git', $build);
    }

    public function testUnanswerableGitRefusesAsUnverifiableNotAsDirtiness(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('PATH-shadowing probe is POSIX-shaped; this package does not run on Windows CI.');
        }
        $emptyBin = sys_get_temp_dir() . '/candy-image-empty-bin';
        if (!is_dir($emptyBin) && !@mkdir($emptyBin, 0o700, true) && !is_dir($emptyBin)) {
            self::markTestSkipped("cannot create {$emptyBin}");
        }

        // phar.readonly is opened so the run reaches Guard 2 at all; git is
        // then unreachable by PATH, and Guard 2's promise (unverifiable tree,
        // never a waved-through or misdiagnosed build) is what gets tested.
        [$rc, $err] = $this->runBuildScript(
            ['--out=' . $emptyBin . '/probe.phar'],
            ['PATH=' . $emptyBin],
            ['-d', 'phar.readonly=0'],
        );

        self::assertSame(2, $rc);
        self::assertStringContainsString('integrity cannot be verified', $err);
        self::assertStringNotContainsString('dirty source tree', $err);
    }

    /**
     * Run the build script as a child with the given extra arguments.
     *
     * @param list<string>     $extraArgs
     * @param list<string>     $env      full child environment (name=value), or [] to inherit
     * @param list<string>     $phpArgs  flags placed before the script path (e.g. -d ...)
     *
     * @return array{0:int,1:string} exit code and captured stderr
     */
    private function runBuildScript(array $extraArgs, array $env = [], array $phpArgs = []): array
    {
        $command = array_merge([PHP_BINARY], $phpArgs, [self::APP_ROOT . '/tools/build-phar.php'], $extraArgs);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::APP_ROOT, $env === [] ? null : $env);
        self::assertIsResource($process, 'build script must be launchable');
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($process);
        // Failures below should show why: attach child output to the message.
        self::assertSame('', $stdout, "unexpected stdout from @{$rc}: {$stdout}|{$stderr}");

        return [$rc, $stderr];
    }

    public function testUnrecognizedOptionRefusesWithoutCreatingAnything(): void
    {
        $probe = sys_get_temp_dir() . '/candy-image-test-bogus-' . bin2hex(random_bytes(6)) . '.phar';

        // The argv loop sits above every guard and the first write, so a
        // typo'd flag must die naming itself — never reach the readonly
        // guard, a mkdir, or the artifact path.
        [$rc, $err] = $this->runBuildScript(['--out=' . $probe, '--definitely-bogus']);

        self::assertSame(2, $rc);
        self::assertStringContainsString('unrecognized option --definitely-bogus', $err);
        self::assertStringNotContainsString('phar.readonly is On', $err, 'the refusal is the argv arm, not a later guard (usage text may name the flag)');
        clearstatcache();
        self::assertFalse(is_file($probe), 'a refused build must create no artifact');
    }

    public function testTheBinShimRunsCliMainAndTheVersionLineIsByteExact(): void
    {
        [$rc, $out, $err] = $this->runBin(['--version']);

        self::assertSame(0, $rc, "shim stderr: {$err}");
        self::assertSame('', $err);
        // Byte-exact: the exit(Cli::main(...)) shim, the 'candy-image <v>'
        // grammar, and the compiled VERSION (no archive => no stamp).
        self::assertSame("candy-image " . \SugarCraft\CandyImage\Cli::VERSION . "\n", $out);
    }

    public function testTheBinShimAnswersHelpWithUsageOnStdout(): void
    {
        [$rc, $out, $err] = $this->runBin(['--help']);

        self::assertSame(0, $rc);
        self::assertSame('', $err, 'help is a success print, never an error');
        self::assertStringContainsString('usage: candy-image', $out);
    }

    /**
     * Run bin/candy-image as a real child (autoload resolution + the
     * exit(Cli::main) contract are what this executes).
     *
     * @param list<string>      $args
     * @param list<string>|null $env  full child environment (name=value), or null to inherit
     *
     * @return array{0:int,1:string,2:string} exit code, stdout, stderr
     */
    private function runBin(array $args, ?array $env = null): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, self::APP_ROOT . '/bin/candy-image'], $args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::APP_ROOT,
            $env,
        );
        self::assertIsResource($process, 'the bin must be launchable');
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
