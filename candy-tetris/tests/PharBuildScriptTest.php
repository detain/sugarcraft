<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Tetris\Support\Version;

/**
 * The phar packaging contract, pinned without sealing the REAL vendor tree
 * inside the suite — the only archive a test builds is over a bounded
 * synthetic package (five real files, section 4). Five laws plus one:
 *
 *  1. bin/candy-tetris is a VALID STUB: the build script refuses any other shape,
 *     and the archive guard candidate is what makes `php candy-tetris.phar` find
 *     its own vendor (the DST-1 law measured on PHP 8.3.6: a bare
 *     '/x.phar/entry' path is refused by is_file()). The same guard is what
 *     makes a RENAMED phar boot — the identity is Phar::running(), never the
 *     file suffix (candy-tetris-056).
 *  2. The build script fails fast (exit 2, usage on stderr, stdout clean) on
 *     options it does not know — the door its guard wording relies on.
 *  3. Support\Version answers CURRENT outside an archive, refuses every
 *     un-stampable path with null, and judges the stamp itself as a pure
 *     function: CURRENT is overridden ONLY under a stamped phar.
 *  4. The locales SHIP INSIDE the archive: `Lang::DIR = __DIR__ . '/../lang'`
 *     is a `__DIR__`-relative data site, so 'lang' must be in INCLUDE_ROOTS
 *     (a source pin) and the synthetic-package build must actually seal every
 *     catalogue (an archive-membership pin). Before TET-LOCALES-era catalogues
 *     landed, the packaged root list omitted lang/ and the phar channel
 *     rendered raw 'tetris.*' keys — this section is the C1 regression wall.
 *  5. The extension floor is enforced twice — the launcher preflight refuses a
 *     starved host by name before anything loads, and the manifest self-
 *     declares ext-mbstring/ext-intl; /dist/ is ignored so local builds stay
 *     out of porcelain (candy-tetris-057, -114).
 */
final class PharBuildScriptTest extends TestCase
{
    public function testTheBinaryCarriesTheStubShapeAndThePharGuard(): void
    {
        $bin = file_get_contents(__DIR__ . '/../bin/candy-tetris');
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
        self::assertStringContainsString(
            '\Phar::loadPhar(__FILE__',
            $bin,
            'the renamed-phar identity leg must read the magic bytes, not the name (candy-tetris-056)',
        );
    }

    public function testTheBuildScriptRefusesUnknownOptionsWithExitTwo(): void
    {
        [$exit, $out, $err] = self::runBuild(['--nope']);

        self::assertSame(2, $exit, 'an unknown option must refuse with exit 2, got: ' . $err);
        self::assertStringContainsString('unrecognized option --nope', $err);
        self::assertSame('', $out, 'refusals are stderr-only; stdout stays clean');
    }

    public function testThePackagedRootsListTheShippedLangCatalogues(): void
    {
        $script = file_get_contents(__DIR__ . '/../tools/build-phar.php');
        self::assertIsString($script);
        self::assertStringContainsString(
            "const INCLUDE_ROOTS = ['bin', 'src', 'lang', 'vendor'];",
            $script,
            'the packaged root list must carry lang — Lang::DIR is a __DIR__-relative data site, and an archive without it renders raw tetris.* keys',
        );
        self::assertStringNotContainsString(
            'no `lang/` catalogue',
            $script,
            'the pre-TET-LOCALES docblock claim that no lang catalogue exists is false and must not return',
        );

        $files = array_values(array_filter(
            scandir(__DIR__ . '/../lang') ?: [],
            static fn (string $name): bool => str_ends_with($name, '.php'),
        ));
        self::assertCount(16, $files, 'every shipped locale must exist on disk for the root to seal them');
    }

    public function testASyntheticPackageBuildSealsEveryLangCatalogueIntoTheArchive(): void
    {
        // A five-file real package under a temp dir: the walk, the guards and
        // the seal run for real without freezing the monorepo's linked vendor.
        $root = sys_get_temp_dir() . '/candy-tetris-phar-smoke-' . getmypid() . '-' . uniqid('u', true);
        $out = $root . '/dist/tetris.phar';
        foreach (['/tools', '/bin', '/src', '/lang', '/vendor', '/tests'] as $dir) {
            self::assertTrue(mkdir($root . $dir, 0o755, true));
        }
        $script = realpath(__DIR__ . '/../tools/build-phar.php');
        self::assertIsString($script);
        self::assertTrue(copy($script, $root . '/tools/build-phar.php'));
        // The stub-shape pin in the script demands this exact opening.
        file_put_contents($root . '/bin/candy-tetris', "#!/usr/bin/env php\n<?php // synthetic stub\n");
        file_put_contents($root . '/src/Fake.php', "<?php\n");
        file_put_contents($root . '/lang/en.php', "<?php\nreturn [];\n");
        file_put_contents($root . '/lang/de.php', "<?php\nreturn [];\n");
        file_put_contents($root . '/vendor/autoload.php', "<?php\n");
        file_put_contents($root . '/LICENSE', "synthetic license\n");
        file_put_contents($root . '/tests/FakeTest.php', "<?php\n");

        try {
            [$exit, $outText, $err] = self::runScript($root . '/tools/build-phar.php', ['--vendor=monorepo', '--out=' . $out]);
            self::assertSame(0, $exit, "synthetic build must succeed, got: $err$outText");

            $phar = new \Phar($out);
            foreach (['bin/candy-tetris', 'src/Fake.php', 'lang/en.php', 'lang/de.php', 'vendor/autoload.php', 'LICENSE'] as $member) {
                self::assertTrue(isset($phar[$member]), "the archive must carry $member");
            }
            self::assertFalse(isset($phar['tests/FakeTest.php']), 'tests/ is not a packaged root');
        } finally {
            @unlink($out);
            @unlink($root . '/dist');
            foreach (['/tools/build-phar.php', '/bin/candy-tetris', '/src/Fake.php', '/lang/en.php', '/lang/de.php', '/vendor/autoload.php', '/LICENSE', '/tests/FakeTest.php'] as $file) {
                @unlink($root . $file);
            }
            foreach (['/tools', '/bin', '/src', '/lang', '/vendor', '/tests', '/dist', ''] as $dir) {
                @rmdir($root . $dir);
            }
        }
    }

    public function testVersionAnswersCurrentOutsideAnArchiveAndNullForUnopenablePaths(): void
    {
        self::assertSame('', Version::hostArchive(), 'a phpunit process never lives inside an archive');
        self::assertSame(Version::CURRENT, Version::current(), 'outside an archive CURRENT is the whole answer');
        self::assertSame("candy-tetris " . Version::CURRENT . "\n", Version::line());

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
        self::assertSame('v1.2.3', Version::versionFromMetadata(['version' => 'candy-tetris/v1.2.3']), 'the release namespace is decoration, stripped for the report');
        self::assertSame('dev-test1', Version::versionFromMetadata(['version' => 'dev-test1']), 'an explicit build stamp is reported verbatim — no self-update means no ordering to fail closed on');
        self::assertNull(Version::versionFromMetadata(['version' => 'dev-build']), 'the working-tree sentinel is not a version');
        self::assertNull(Version::versionFromMetadata([]), 'a stamp-less manifest answers null');
        self::assertNull(Version::versionFromMetadata(['version' => 42]), 'a stamp of the wrong type answers null');
        self::assertNull(Version::versionFromMetadata(null), 'getMetadata() answering null is a refusal, not an error');
    }

    public function testTheRealBootRefusesAHostMissingTheExtensionFloor(): void
    {
        // candy-tetris-057: the phar channel has no composer gate, so bin
        // itself must name a missing mbstring/intl BEFORE loading anything.
        // `php -n` drops the ini-loaded extensions, and the refusal is
        // observable here (requireExtension-style: if a host still loads
        // both statically, there is nothing to refuse and we skip).
        $bare = self::runPhp(['-n', '-r', 'echo (int) extension_loaded("mbstring"), (int) extension_loaded("intl");']);
        self::assertSame(0, $bare[0]);
        if ($bare[1] === '11') {
            self::markTestSkipped('this host compiles mbstring+intl statically; -n cannot starve them');
        }

        $bin = realpath(__DIR__ . '/../bin/candy-tetris');
        self::assertIsString($bin);
        [$exit, $out, $err] = self::runPhp(['-n', $bin, '--version']);

        self::assertSame(1, $exit, 'a starved host must be refused with exit 1, got: ' . $err);
        self::assertSame('', $out, 'the refusal is stderr-only');
        self::assertMatchesRegularExpression(
            '/candy-tetris needs the PHP extension\(s\): (mbstring, intl|intl, mbstring|mbstring|intl)/',
            $err,
            'the refusal must name the missing extension(s) — not a mid-boot ICU fatal'
        );
    }

    public function testTheManifestSelfDescribesTheExtensionFloorAndTheBuildOutputDirIsIgnored(): void
    {
        // The same floor the preflight enforces must be declared, so a
        // composer install refuses at the door too (candy-tetris-057), and
        // local phar builds must not read as porcelain noise (candy-tetris-114).
        $composer = file_get_contents(__DIR__ . '/../composer.json');
        self::assertIsString($composer);
        self::assertStringContainsString('"ext-mbstring": "*"', $composer);
        self::assertStringContainsString('"ext-intl": "*"', $composer);

        $ignore = file_get_contents(__DIR__ . '/../.gitignore');
        self::assertIsString($ignore);
        self::assertStringContainsString('/dist/', $ignore, 'build-phar default output lives inside the package');
    }

    public function testARenamedPharBootsOnItsOwnArchiveIdentityNotItsName(): void
    {
        // candy-tetris-056: `mv candy-tetris.phar tetris` is the shape many
        // users pick. The guard answers via Phar::running(), so the archive
        // finds its bundled autoload under any external name. Bounded
        // synthetic mirror of the real guard (the real bin is name-agnostically
        // pinned in testTheBinaryCarriesTheStubShapeAndThePharGuard).
        $root = sys_get_temp_dir() . '/candy-tetris-renamed-' . getmypid() . '-' . uniqid('r', true);
        self::assertTrue(mkdir($root, 0o755, true));
        $phar = $root . '/tetris.phar';
        $renamed = $root . '/tetris';

        $builder = <<<'PHP'
<?php
$p = new Phar($argv[1]);
$p->setStub(
    "#!/usr/bin/env php\n<?php\n"
    . "\$running = \\Phar::running(false);\n"
    . "\$archive = \$running !== '' ? 'phar://' . \$running"
    . " : (str_ends_with(__FILE__, '.phar') ? 'phar://' . __FILE__ : '');\n"
    . "if (\$archive === '') { try { if (@\\Phar::loadPhar(__FILE__, 'synthetic') === true) { \$archive = 'phar://' . __FILE__; } } catch (\\Throwable) { \$archive = ''; } }\n"
    . "if (\$archive !== '' && is_file(\$archive . '/vendor/autoload.php')) { echo 'BOOTED ' . \$archive . PHP_EOL; exit(0); }\n"
    . "fwrite(STDERR, 'no archive identity' . PHP_EOL); exit(1);\n"
    . "__HALT_COMPILER();"
);
$p->startBuffering();
$p->addFromString('vendor/autoload.php', "<?php\n");
$p->stopBuffering();
PHP;
        $build = $root . '/build.php';
        self::assertGreaterThan(0, file_put_contents($build, $builder));
        @unlink($phar);

        try {
            [$exit, $out, $err] = self::runPhp(['-d', 'phar.readonly=0', $build, $phar]);
            self::assertSame(0, $exit, "synthetic seal must succeed, got: $err");
            self::assertTrue(copy($phar, $renamed));

            [$exit, $out, $err] = self::runPhp([$renamed]);
            self::assertSame(0, $exit, "the renamed phar must boot, got: $err");
            self::assertStringContainsString('BOOTED phar://' . $renamed, $out, 'the archive identity is the invoked path, not a .phar suffix');
        } finally {
            @unlink($renamed);
            @unlink($phar);
            @unlink($build);
            @rmdir($root);
        }
    }

    /**
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

        return self::runScript($script, $args);
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private static function runScript(string $script, array $args): array
    {
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
