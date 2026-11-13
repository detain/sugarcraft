<?php

declare(strict_types=1);

namespace SugarCraft\Flappy\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flappy\Support\Version;

/**
 * The phar packaging contract, pinned WITHOUT building an archive inside the
 * suite (a real build seals the whole vendor tree — the smoke bar runs in
 * tools/build-phar.php by hand). Three laws:
 *
 *  1. bin/candy-flappy is a VALID STUB: the build script refuses any other shape,
 *     and the archive guard candidate is what makes `php candy-flappy.phar` find
 *     its own vendor (the DST-1 law measured on PHP 8.3.6: a bare
 *     '/x.phar/entry' path is refused by is_file()).
 *  2. The build script fails fast (exit 2, usage on stderr, stdout clean) on
 *     options it does not know — the door its guard wording relies on.
 *  3. Support\Version answers CURRENT outside an archive, refuses every
 *     un-stampable path with null, and judges the stamp itself as a pure
 *     function: CURRENT is overridden ONLY under a stamped phar — and the
 *     stamped-positive arm of current() runs from INSIDE a synthetic
 *     archive (flappy-30);
 *  4. the RENAMED, suffix-less archive boots through the Phar::mapPhar()
 *     door, whose alias the builder stamps — both sides pinned, and a
 *     faithful stub subprocess actually executes it (flappy-34).
 */
final class PharBuildScriptTest extends TestCase
{
    /** @var list<string> temp dirs holding synthetic stamped archives */
    private static array $stampDirs = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$stampDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        self::$stampDirs = [];
    }

    public function testTheBinaryCarriesTheStubShapeAndThePharGuard(): void
    {
        $bin = file_get_contents(__DIR__ . '/../bin/candy-flappy');
        self::assertIsString($bin);
        self::assertTrue(
            str_starts_with($bin, "#!/usr/bin/env php\n<?php"),
            'bin must keep the shebang+<?php stub shape the build script pins',
        );
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

    public function testTheRenamedPharDoorIsPinnedOnBothSides(): void
    {
        // flappy-34 (finding -083, fleet family): the renamed-download
        // identity (`wget … -O candy-flappy`, the composer bin-install shape)
        // boots through Phar::mapPhar(); the guard literal and the build's
        // archive alias are a PAIR — candy-code's measurement is that one
        // without the other is dead code — so both sides pin here, in the
        // same shape candy-kv and candy-gifview carry.
        $bin = file_get_contents(__DIR__ . '/../bin/candy-flappy');
        self::assertIsString($bin);
        self::assertStringContainsString("\\Phar::mapPhar('candy-flappy.phar')", $bin);

        $build = file_get_contents(__DIR__ . '/../tools/build-phar.php');
        self::assertIsString($build);
        self::assertStringContainsString("new Phar(\$outArg, 0, 'candy-flappy.phar')", $build);
    }

    public function testARenamedArchiveBootsThroughTheMapPharDoor(): void
    {
        // The subprocess law: a full-vendor build stays by hand (class
        // docblock), but the load-bearing bytes for THIS law are the stub —
        // bin/candy-flappy verbatim + __HALT_COMPILER — and the archive's
        // own vendor/autoload.php. The fixture seals exactly those against
        // the real alias and drops the `.phar` suffix, reproducing the
        // renamed-download recipe; pre-port both invocations answered
        // "cannot find composer autoload.php" with rc 1.
        if (!extension_loaded('phar')) {
            self::markTestSkipped('ext-phar unavailable');
        }

        $dir = sys_get_temp_dir() . '/flappy-renamed-phar-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0o700, true), 'fixture dir must be creatable');
        $repoAutoload = realpath(__DIR__ . '/../vendor/autoload.php');
        self::assertIsString($repoAutoload, 'sanity: the checkout vendor exists');
        $archive = $dir . '/candy-flappy.phar';
        try {
            $gen = $dir . '/gen.php';
            file_put_contents($gen, implode("\n", [
                '<' . '?php',
                '$phar = new Phar($argv[3], 0, "candy-flappy.phar");',
                '$phar->startBuffering();',
                '$phar->addFromString("vendor/autoload.php", "<" . "?php require " . var_export($argv[2], true) . ";");',
                '$phar->setStub(file_get_contents($argv[1]) . ' . "'__HALT_COMPILER();'" . ');',
                '$phar->stopBuffering();',
                'echo "built\\n";',
            ]) . "\n");

            [$exit, $out, $err] = self::runPhp(['-d', 'phar.readonly=0', $gen, realpath(__DIR__ . '/../bin/candy-flappy'), $repoAutoload, $archive]);
            self::assertSame(0, $exit, "fixture build must succeed, got: $err$out");

            $bare = $dir . '/candy-flappy';
            self::assertTrue(rename($archive, $bare), 'renaming the archive away from .phar is the whole scenario');

            // The boot must NOT lean on the M6 bare-leg: the phpunit CWD
            // (the lib root) carries a real vendor/autoload.php, so a child
            // run there would boot even with no door at all. Run the fixture
            // from its own vendor-less directory — the mapPhar door is then
            // the only path that can answer, which is what makes the alias
            // mutation redden this arm.
            [$exit, $out] = self::runCwd($dir, [PHP_BINARY, $bare, '--version']);
            self::assertSame(0, $exit, 'php <bare-name> must boot via mapPhar');
            self::assertStringContainsString('candy-flappy', $out);

            if (PHP_OS_FAMILY !== 'Windows') {
                self::assertTrue(chmod($bare, 0o755), 'fixture must be executable');
                // The shebang path: exec the archive itself, no php binary.
                [$exit, $out, $err] = self::runCwd($dir, [$bare, '--version']);
                self::assertSame(0, $exit, "the shebang exec of a renamed archive must boot, got: $err");
                self::assertStringContainsString('candy-flappy', $out);
            }
        } finally {
            foreach (glob($dir . '/*') ?: [] as $leftover) {
                @unlink($leftover);
            }
            @rmdir($dir);
        }
    }

    /**
     * One bounded child php run.
     *
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private static function runPhp(array $args): array
    {
        $proc = proc_open(
            [PHP_BINARY, ...$args],
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

    /**
     * A child run with an explicit working directory (the renamed-archive
     * boot must not inherit the phpunit CWD, whose vendor/ would boot it
     * through the bare-leg no matter what the door does).
     *
     * @param list<string> $argv
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private static function runCwd(string $cwd, array $argv): array
    {
        $proc = proc_open(
            $argv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }

    public function testTheBuildScriptRefusesUnknownOptionsWithExitTwo(): void
    {
        [$exit, $out, $err] = self::runBuild(['--nope']);

        self::assertSame(2, $exit, 'an unknown option must refuse with exit 2, got: ' . $err);
        self::assertStringContainsString('unrecognized option --nope', $err);
        self::assertSame('', $out, 'refusals are stderr-only; stdout stays clean');
    }

    public function testVersionAnswersCurrentOutsideAnArchiveAndNullForUnopenablePaths(): void
    {
        self::assertSame('', Version::hostArchive(), 'a phpunit process never lives inside an archive');
        self::assertSame(Version::CURRENT, Version::current(), 'outside an archive CURRENT is the whole answer');
        self::assertSame("candy-flappy " . Version::CURRENT . "\n", Version::line());

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
        self::assertSame('v1.2.3', Version::versionFromMetadata(['version' => 'candy-flappy/v1.2.3']), 'the release namespace is decoration, stripped for the report');
        self::assertSame('dev-test1', Version::versionFromMetadata(['version' => 'dev-test1']), 'an explicit build stamp is reported verbatim — no self-update means no ordering to fail closed on');
        self::assertNull(Version::versionFromMetadata(['version' => 'dev-build']), 'the working-tree sentinel is not a version');
        self::assertNull(Version::versionFromMetadata([]), 'a stamp-less manifest answers null');
        self::assertNull(Version::versionFromMetadata(['version' => 42]), 'a stamp of the wrong type answers null');
        self::assertNull(Version::versionFromMetadata(null), 'getMetadata() answering null is a refusal, not an error');
    }

    public function testAStampedArchiveDrivesCurrentFromInsideIt(): void
    {
        // flappy-30 (finding -033): the positive arm of current(). Every
        // other leg asserts Version from OUTSIDE an archive — the exact
        // shape where it must answer CURRENT — so the one branch the class
        // exists to serve (a stamped archive overrides CURRENT) was never
        // executed. A synthetic child build (gifview's precedent) stamps
        // candy-flappy's REAL Version.php into a minimal archive; a second
        // child loads the class from the phar:// stream, where
        // Phar::running() answers the archive path, and current() must
        // report the stripped stamp — not CURRENT, not the raw tag.
        $built = self::stampArchive('candy-flappy/v9.9.9');
        if ($built === null) {
            $this->markTestSkipped('phar.readonly=0 unavailable even in a child — archive stamp legs cannot run here');
        }

        // In-process read: opening an archive is allowed under phar.readonly,
        // so the metadata leg round-trips without a second boot. The tag
        // prefix strips; the 'v' is kept (the grammar versionFromMetadata
        // already pins above).
        self::assertSame('v9.9.9', Version::fromPharMetadata($built), 'the release namespace must strip on the round trip');

        // From inside: require the packed class and let current() discover
        // its host archive itself. A current() that ignored the stamp (the
        // DST-1 lie) answers 'dev-master' here and reddens this assert.
        $child = proc_open(
            [PHP_BINARY, '-r', 'require ' . var_export('phar://' . $built . '/version.php', true)
                . '; echo \\SugarCraft\\Flappy\\Support\\Version::current();'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($child);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($child);

        self::assertSame(0, $code, 'the from-inside child must boot cleanly, got: ' . $err);
        self::assertSame('v9.9.9', $out, 'inside a stamped phar, current() must report the stamp');
    }

    /**
     * Build a throwaway archive whose metadata carries $version and whose
     * single entry is the real src/Support/Version.php, via a child
     * (`php -d phar.readonly=0`), or return null when this host refuses.
     * The archive is registered for cleanup by the caller's parent test.
     */
    private static function stampArchive(string $version): ?string
    {
        $dir = tempnam(sys_get_temp_dir(), 'flappy-stamp-');
        if ($dir === false) {
            return null;
        }
        @unlink($dir);
        if (!mkdir($dir, 0o755, true)) {
            return null;
        }
        self::$stampDirs[] = $dir;

        $target = $dir . '/stamped.phar';
        $versionFile = realpath(__DIR__ . '/../src/Support/Version.php');
        if ($versionFile === false) {
            return null;
        }
        $maker = $dir . '/make.php';
        file_put_contents($maker, implode("\n", [
            '<?php',
            '$phar = new Phar(' . var_export($target, true) . ', 0, \'candy-flappy.phar\');',
            '$phar->startBuffering();',
            '$phar->addFromString(\'version.php\', file_get_contents(' . var_export($versionFile, true) . '));',
            '$phar->setMetadata([\'version\' => ' . var_export($version, true) . ']);',
            '$phar->setStub("<?php __HALT_COMPILER();");',
            '$phar->stopBuffering();',
            'echo "BUILT\\n";',
        ]));

        $proc = proc_open(
            ['timeout', '30', PHP_BINARY, '-d', 'phar.readonly=0', $maker],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($proc)) {
            return null;
        }
        // Drain both pipes to EOF before judging (pipe-EOF law).
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0 || !is_file($target)) {
            // Host cannot write archives: the caller's skip is the disclosure.
            return null;
        }

        return $target;
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

        return self::runPhp(['-d', 'phar.readonly=0', $script, ...$args]);
    }
}
