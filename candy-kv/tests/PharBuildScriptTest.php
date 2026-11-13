<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Kv\Support\Version;
use SugarCraft\Kv\Tests\Support\SpawnBounded;

/**
 * The phar packaging contract, pinned WITHOUT sealing the real vendor tree
 * inside the suite (a full build freezes the whole vendor/ — the smoke bar
 * runs in tools/build-phar.php by hand). The renamed-download law is the one
 * exception: it is proved with a MINIMAL fixture archive (real stub bytes +
 * a one-line vendor/autoload.php) sealed into a temp dir, which needs no
 * Guard-2-clean tree. Three laws plus that door:
 *
 *  1. bin/candy-kv is a VALID STUB: the build script refuses any other shape,
 *     and the archive guard candidate is what makes `php candy-kv.phar` find
 *     its own vendor (measured on PHP 8.3.6: a bare
 *     '/x.phar/entry' path is refused by is_file()).
 *  2. The build script fails fast (exit 2, usage on stderr, stdout clean) on
 *     options it does not know — the door its guard wording relies on.
 *  3. Support\Version answers CURRENT outside an archive, refuses every
 *     un-stampable path with null, and judges the stamp itself as a pure
 *     function: CURRENT is overridden ONLY under a stamped phar.
 */
final class PharBuildScriptTest extends TestCase
{
    use SpawnBounded;

    public function testTheBinaryCarriesTheStubShapeAndThePharGuard(): void
    {
        $bin = file_get_contents(__DIR__ . '/../bin/candy-kv');
        self::assertIsString($bin);
        self::assertTrue(
            str_starts_with($bin, "#!/usr/bin/env php\n<?php"),
            'bin must keep the shebang+<?php stub shape the build script pins',
        );
        self::assertStringContainsString(
            "'phar://' . __FILE__",
            $bin,
            'the stub-context identity must be addressed as a phar:// URI',
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

    public function testTheRenamedPharDoorIsPinnedOnBothSides(): void
    {
        // The renamed-download identity (README quick start `wget … -O candy-kv`)
        // boots through Phar::mapPhar(); the guard literal and the build's
        // archive alias are a PAIR — candy-code C-SES-6.a measures that one
        // without the other is dead code, so both sides pin here.
        $bin = file_get_contents(__DIR__ . '/../bin/candy-kv');
        self::assertIsString($bin);
        self::assertStringContainsString("\\Phar::mapPhar('candy-kv.phar')", $bin);

        $build = file_get_contents(__DIR__ . '/../tools/build-phar.php');
        self::assertIsString($build);
        self::assertStringContainsString("new Phar(\$outArg, 0, 'candy-kv.phar')", $build);
    }

    public function testARenamedArchiveBootsThroughTheMapPharDoor(): void
    {
        // Full-vendor builds are skipped inside the suite by design (see the
        // class docblock), and a repo-wide dirty tree makes the real build's
        // Guard 2 refuse even a fixture run. The load-bearing byte of a build
        // for THIS law is the stub — bin/candy-kv verbatim + __HALT_COMPILER
        // — plus the archive's own vendor/autoload.php, so the fixture seals
        // exactly those against the real alias and loses the `.phar` suffix,
        // reproducing the README recipe (measured pre-fix: both invocations
        // answer "cannot find composer autoload.php" with rc 1).
        if (!extension_loaded('phar')) {
            self::markTestSkipped('ext-phar unavailable');
        }

        $dir = sys_get_temp_dir() . '/kv-renamed-phar-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0o700, true), 'fixture dir must be creatable');
        $repoAutoload = realpath(__DIR__ . '/../vendor/autoload.php');
        self::assertIsString($repoAutoload, 'sanity: the checkout vendor exists');
        $archive = $dir . '/candy-kv.phar';
        try {
            $gen = $dir . '/gen.php';
            file_put_contents($gen, implode("\n", [
                '<' . '?php',
                '$phar = new Phar($argv[3], 0, "candy-kv.phar");',
                '$phar->startBuffering();',
                '$phar->addFromString("vendor/autoload.php", "<" . "?php require " . var_export($argv[2], true) . ";");',
                '$phar->setStub(file_get_contents($argv[1]) . ' . "'__HALT_COMPILER();'" . ');',
                '$phar->stopBuffering();',
                'echo "built\\n";',
            ]) . "\n");

            [$exit, $out, $err] = self::runPhp(['-d', 'phar.readonly=0', $gen, realpath(__DIR__ . '/../bin/candy-kv'), $repoAutoload, $archive]);
            self::assertSame(0, $exit, "fixture build must succeed, got: $err$out");

            $bare = $dir . '/candy-kv';
            self::assertTrue(rename($archive, $bare), 'renaming the archive away from .phar is the whole scenario');

            [$exit, $out] = self::runPhp([$bare, '--version']);
            self::assertSame(0, $exit, 'php <bare-name> must boot via mapPhar');
            self::assertStringContainsString('candy-kv', $out);

            if (PHP_OS_FAMILY !== 'Windows') {
                self::assertTrue(chmod($bare, 0o755), 'fixture must be executable');
                [$exit, $out, $err] = self::runPhp([$bare, '--version']);
                self::assertSame(0, $exit, "the shebang exec of a renamed archive must boot, got: $err");
                self::assertStringContainsString('candy-kv', $out);
            }
        } finally {
            foreach (glob($dir . '/*') ?: [] as $leftover) {
                @unlink($leftover);
            }
            @rmdir($dir);
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function runPhp(array $args): array
    {
        $run = self::spawnBounded([PHP_BINARY, ...$args]);

        return [$run['exit'], $run['stdout'], $run['stderr']];
    }

    public function testVersionAnswersCurrentOutsideAnArchiveAndNullForUnopenablePaths(): void
    {
        self::assertSame('', Version::hostArchive(), 'a phpunit process never lives inside an archive');
        self::assertSame(Version::CURRENT, Version::current(), 'outside an archive CURRENT is the whole answer');
        self::assertSame("candy-kv " . Version::CURRENT . "\n", Version::line());

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
        self::assertSame('v1.2.3', Version::versionFromMetadata(['version' => 'candy-kv/v1.2.3']), 'the release namespace is decoration, stripped for the report');
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

        // The child must not wait on us: spawnBounded closes stdin immediately,
        // and both pipes drain concurrently (pipe-EOF law) under one deadline.
        $run = self::spawnBounded([PHP_BINARY, '-d', 'phar.readonly=0', $script, ...$args]);

        return [$run['exit'], $run['stdout'], $run['stderr']];
    }
}
