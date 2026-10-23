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
        $bin = file_get_contents(__DIR__ . '/../bin/candy-git');
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
