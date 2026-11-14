<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\Support\Version;

/**
 * The archive's runtime shape, EXECUTED (candy-git-057): the build script
 * asserts the in-archive contract, but until this file nothing ever RAN a
 * minted phar — not in the suite, not in CI smoke legs, which stop at
 * --version/--help short-circuits before any catalogue load. A phar://
 * require failure or a locale regression used to surface first in a user's
 * hands. This is the env arm: it builds the real archive from the working
 * tree (--vendor=monorepo — dev-only by design; the published artifact is
 * the release workflow's job) and then drives it like a user would:
 *
 *  1. entry existence — the archive must LAUNCH (the DST-1 stub guard,
 *     grepped by PharBuildScriptTest, is here proven by execution);
 *  2. version stamp — --version answers the build's --version, not CURRENT,
 *     which only the stamped-metadata path can do;
 *  3. German --bogus refusal — the stderr envelope comes from the phar-
 *     relative Lang::DIR catalogue: proof that locales load inside the
 *     archive, the exact claim build-phar.php makes but never executed.
 *
 * Heavy (seals the vendor tree); CI runs it under the release workflow's
 * phar arm, and it self-skips where the build's preconditions are absent.
 */
final class PharCompatTest extends TestCase
{
    private const STAMP = 'v9.9.9-test';

    private static ?string $archive = null;
    private static ?string $skipReason = null;

    public static function setUpBeforeClass(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::$skipReason = 'phar minting leg runs on POSIX CI arms';

            return;
        }
        if (!exec('git --version 2>/dev/null')) {
            self::$skipReason = 'git unavailable';

            return;
        }
        // The -d override can only win if phar.readonly is not locked by
        // php.ini (admin restricts it): ask a child, never the runner.
        exec(escapeshellarg(PHP_BINARY) . ' -d phar.readonly=0 -r ' .
            escapeshellarg('exit(ini_get("phar.readonly") ? 1 : 0);') . ' 2>/dev/null', $out, $rc);
        if ($rc !== 0) {
            self::$skipReason = 'phar.readonly cannot be lifted for a child build';

            return;
        }

        $dir = sys_get_temp_dir() . '/cg-pharcompat-' . getmypid();
        @mkdir($dir, 0o755, true);
        self::$archive = $dir . '/candy-git.phar';

        $script = realpath(__DIR__ . '/../tools/build-phar.php');
        self::assertIsString($script, 'sanity: the build script exists');

        $proc = proc_open(
            [PHP_BINARY, '-d', 'phar.readonly=0', $script, '--vendor=monorepo', '--version=' . self::STAMP, '--out=' . self::$archive],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);

        if ($rc !== 0 || !is_file(self::$archive)) {
            self::$skipReason = "the working tree cannot mint an archive (rc {$rc}): " . trim($err . $out);
            self::$archive = null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$archive !== null) {
            @unlink(self::$archive);
            @unlink(self::$archive . '.gz');
            @rmdir(\dirname(self::$archive));
        }
    }

    public function testTheMintedArchiveAnswersItsVersionStamp(): void
    {
        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        [$exit, $out] = self::runArchive([], []);

        self::assertSame(0, $exit, 'the archive must launch and answer --version: ' . $out);
        self::assertSame("candy-git " . self::STAMP . "\n", $out, 'a stamped archive reports its stamp, never CURRENT');
    }

    public function testTheMintedArchiveCarriesTheGermanCatalogueIntoRefusals(): void
    {
        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        // LC_ALL, not just LANG: the detect chain consults LC_ALL ->
        // LC_MESSAGES -> LANG first, and dev hosts routinely carry an
        // LC_MESSAGES that would shadow a lone LANG.
        [$exit, $out, $err] = self::runArchive(['--bogus'], ['LC_ALL' => 'de_DE.UTF-8', 'LANG' => 'de_DE.UTF-8']);

        self::assertSame(2, $exit, 'a stray argument is refused with exit 2: ' . $err);
        self::assertSame('', $out);
        self::assertStringContainsString('unbekanntes Argument: --bogus', $err, 'the refusal envelope must come from the phar-relative locale catalogue');
        self::assertStringContainsString('versuchen Sie --help', $err, 'the whole refusal pair must localize, not just the first line');
    }

    public function testTheStampedMetadataIsReadableWithoutExecutingTheArchive(): void
    {
        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        self::assertSame(
            self::STAMP,
            Version::fromPharMetadata((string) self::$archive),
            'the stamp lives where Version looks - getMetadata() of the sealed archive',
        );
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $extraEnv
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function runArchive(array $args, array $extraEnv): array
    {
        $env = ['PATH' => (string) getenv('PATH')] + $extraEnv;
        $proc = proc_open(
            [PHP_BINARY, (string) self::$archive, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        // Drain to EOF before judging (pipe-EOF law): a refusal that writes
        // two lines must not block on a half-read pipe.
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }
}
