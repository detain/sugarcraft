<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\Support\Version;

/**
 * The phar packaging contract, pinned WITHOUT building an archive inside the
 * suite (a real build seals the whole vendor tree — the smoke bar runs in
 * tools/build-phar.php by hand). Three laws:
 *
 *  1. bin/candy-git is a VALID STUB: the build script refuses any other shape,
 *     and the archive guard candidate is what makes `php candy-git.phar` find
 *     its own vendor (the DST-1 law measured on PHP 8.3.6: a bare
 *     '/x.phar/entry' path is refused by is_file()).
 *  2. The build script fails fast (exit 2, usage on stderr, stdout clean) on
 *     options it does not know — the door its guard wording relies on.
 *  3. Support\Version answers CURRENT outside an archive, refuses every
 *     un-stampable path with null, and judges the stamp itself as a pure
 *     function: CURRENT is overridden ONLY under a stamped phar.
 */
final class PharBuildScriptTest extends TestCase
{
    public function testTheBinaryCarriesTheStubShapeAndThePharGuard(): void
    {
        $raw = file_get_contents(__DIR__ . '/../bin/candy-git');
        self::assertIsString($raw);
        // The pinned law is the stub SHAPE - shebang line, then <?php - not
        // the per-host newline spelling: a Windows runner with the global
        // core.autocrlf=true checks this file out with CRLF bytes (ci-wave-3
        // L4). Normalize before the shape pins.
        $bin = str_replace("\r\n", "\n", $raw);
        self::assertTrue(
            str_starts_with($bin, "#!/usr/bin/env php\n<?php"),
            'bin must keep the shebang+<?php stub shape the build script pins',
        );
        // ci-wave-4: the old synthetic-CRLF leg round-tripped from $raw, so on
        // a host whose checkout ALREADY speaks CRLF it doubled the carriage
        // returns and went red for the wrong reason. Synthesize the other
        // spelling from the normalized bytes instead - that tests the fold on
        // every host - and prove the fold is load-bearing: the literal pin
        // must reject the CRLF bytes it is fed before normalizing.
        $stub  = "#!/usr/bin/env php\n<?php";
        $crlf  = str_replace("\n", "\r\n", $bin);
        self::assertTrue(
            str_starts_with(str_replace("\r\n", "\n", $crlf), $stub),
            'the CRLF checkout spelling must satisfy the same stub pin the LF spelling does',
        );
        self::assertFalse(
            str_starts_with($crlf, $stub),
            'the unnormalized literal must reject CRLF bytes - the fold carries the weight',
        );
        // The build script must apply the same fold before its own raw-bytes
        // pin, or a Windows-host build would refuse a valid CRLF checkout.
        $script    = (string) file_get_contents(realpath(__DIR__ . '/../tools/build-phar.php'));
        $foldPos   = strpos($script, 'str_replace("\r\n", "\n", $binSource)');
        $pinPos    = strpos($script, 'str_starts_with($binSource');
        self::assertIsInt($foldPos, 'the build script must fold the stub pin input like this test does');
        self::assertIsInt($pinPos);
        self::assertLessThan($pinPos, $foldPos, 'the fold must happen before the build script pins the shape');
        self::assertStringContainsString(
            "'phar://' . __FILE__",
            $bin,
            'the stub-context identity (DST-1 guard shape) must be addressed as a phar:// URI',
        );
        self::assertStringContainsString(
            '\Phar::running(false)',
            $bin,
            'the require-from-inside identity must be addressed too',
        );
    }

    public function testTheBuildScriptRefusesUnknownOptionsWithExitTwo(): void
    {
        [$exit, $out, $err] = self::runBuild(['--nope']);

        self::assertSame(2, $exit, 'an unknown option must refuse with exit 2, got: ' . $err);
        self::assertStringContainsString('unrecognized option --nope', $err);
        self::assertSame('', $out, 'refusals are stderr-only; stdout stays clean');
    }

    public function testVendorDevWeightIsPrunedButRuntimeCodeAndSrcSurvive(): void
    {
        $script = realpath(__DIR__ . '/../tools/build-phar.php');
        self::assertIsString($script);
        $pkg = sys_get_temp_dir() . '/cg-prune-' . bin2hex(random_bytes(4));
        mkdir($pkg . '/tools', 0o755, true);
        copy($script, $pkg . '/tools/build-phar.php');
        foreach (['bin', 'src', 'lang'] as $root) {
            mkdir($pkg . '/' . $root, 0o755, true);
        }
        $keep = ['vendor/autoload.php', 'vendor/sugarcraft/pkg/src/Keep.php', 'vendor/sugarcraft/pkg/src/Tests/Keep.php', 'vendor/sugarcraft/pkg/composer.json'];
        $drop = ['vendor/sugarcraft/pkg/tests/FooTest.php', 'vendor/sugarcraft/pkg/Tests/B.php', 'vendor/sugarcraft/pkg/examples/d.php', 'vendor/sugarcraft/pkg/.vhs/x.tape', 'vendor/sugarcraft/pkg/docs/a.html', 'vendor/sugarcraft/pkg/phpunit.xml.dist', 'vendor/sugarcraft/pkg/README.md'];
        foreach ([...$keep, ...$drop] as $rel) {
            @mkdir(dirname($pkg . '/' . $rel), 0o755, true);
            file_put_contents($pkg . '/' . $rel, "x\n");
        }
        try {
            [$exit, $out, $err] = self::runBuildAt($pkg . '/tools/build-phar.php', ['--vendor=monorepo', '--list-files']);
        } finally {
            exec('rm -rf ' . escapeshellarg($pkg));
        }

        self::assertSame(0, $exit, $err);
        $listed = array_filter(explode("\n", $out));
        foreach ($keep as $rel) {
            self::assertContains($rel, $listed);
        }
        foreach ($drop as $rel) {
            self::assertNotContains($rel, $listed);
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function runBuildAt(string $script, array $args): array
    {
        $proc = proc_open(
            [PHP_BINARY, '-d', 'phar.readonly=0', $script, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }

    public function testVersionAnswersCurrentOutsideAnArchiveAndNullForUnopenablePaths(): void
    {
        self::assertSame('', Version::hostArchive(), 'a phpunit process never lives inside an archive');
        self::assertSame(Version::CURRENT, Version::current(), 'outside an archive CURRENT is the whole answer');
        self::assertSame("candy-git " . Version::CURRENT . "\n", Version::line());

        self::assertNull(Version::fromPharMetadata(''), 'an empty path is a refusal, not an error');
        $junk = tempnam(sys_get_temp_dir(), 'pharjunk');
        self::assertIsString($junk);
        file_put_contents($junk, 'not a phar');
        try {
            self::assertNull(Version::fromPharMetadata($junk), 'an unreadable archive answers null, never throws');
        } finally {
            @unlink($junk);
        }
    }

    public function testTheStampJudgementIsAPureFunctionOverMetadata(): void
    {
        self::assertSame('v1.2.3', Version::versionFromMetadata(['version' => 'candy-git/v1.2.3']), 'the release namespace is decoration, stripped for the report');
        self::assertSame('dev-test1', Version::versionFromMetadata(['version' => 'dev-test1']), 'an explicit build stamp is reported verbatim — no self-update means no ordering to fail closed on');
        self::assertNull(Version::versionFromMetadata(['version' => 'dev-build']), 'the working-tree sentinel is not a version');
        self::assertNull(Version::versionFromMetadata([]), 'a stamp-less manifest answers null');
        self::assertNull(Version::versionFromMetadata(['version' => 42]), 'a stamp of the wrong type answers null');
        self::assertNull(Version::versionFromMetadata(null), 'getMetadata() answering null is a refusal, not an error');
    }

    /**
     * One bounded child of the real build script. The unknown-option door is
     * the FIRST thing the script answers, before any guard touches git,
     * vendor, or the filesystem, so the run is hermetic in every mode.
     *
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private static function runBuild(array $args): array
    {
        $script = realpath(__DIR__ . '/../tools/build-phar.php');
        self::assertIsString($script, 'sanity: the build script exists');

        $proc = proc_open(
            [PHP_BINARY, '-d', 'phar.readonly=0', $script, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc);
        // The child must not wait on us: close stdin immediately.
        fclose($pipes[0]);
        // Drain both pipes to EOF before judging (pipe-EOF law).
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }
}
