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
 *     function: CURRENT is overridden ONLY under a stamped phar.
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
