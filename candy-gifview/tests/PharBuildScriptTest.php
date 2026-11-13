<?php

declare(strict_types=1);

namespace SugarCraft\GifView\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A light net for the phar packaging surface (lane PHE). It deliberately does
 * NOT build an archive inside the suite — a real build needs a clean published
 * vendor/ and writes to disk, neither of which belongs in a unit run. What it
 * pins is the three laws that, if broken, would make a published .phar silently
 * misbehave:
 *
 *  1. the bin shim is a valid archive stub (shebang + open tag) and carries an
 *     archive-aware autoload guard (both 'phar://' and 'Phar::running' — the
 *     measured PHP 8.3 rule that the stub and the required-from-inside
 *     contexts name the archive through different identities);
 *  2. tools/build-phar.php exists and refuses an unknown option with exit 2
 *     BEFORE touching the archive (so a typo cannot start a half-build);
 *  3. the version SOURCE agrees: the build stamps metadata['version'] and the
 *     runtime Support\Version reader consumes exactly that field (DST-1),
 *     wired through Cli::runningVersion() into a --version/-v arm that stands
 *     in front of every decode and Program boot — the app is image-heavy, so
 *     the archive answers these two flags even where playback needs a tty.
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
        $bin = $this->readAppFile('bin/candy-gifview');

        // The build refuses unless the stub begins with the shebang+<?php
        // shape; pin the same precondition the build script checks for.
        self::assertTrue(
            str_starts_with($bin, "#!/usr/bin/env php\n<?php"),
            'bin/candy-gifview no longer starts with the shebang+<?php stub shape',
        );
        // The autoload guard must resolve BOTH phar identities, not just one.
        self::assertStringContainsString('phar://', $bin);
        self::assertStringContainsString('Phar::running', $bin);
        // A bare (un-prefixed) '/x.phar/entry' path fails is_file in PHP 8.3 —
        // every archive-entry probe must ride the phar:// stream wrapper (the
        // renamed-boot arm adds legitimate PREFIXED '.phar/vendor' reads, so
        // the law is per-line, not substring-ambient).
        foreach (explode("\n", $bin) as $line) {
            if (str_contains($line, '.phar/vendor')) {
                self::assertStringContainsString(
                    'phar://',
                    $line,
                    'an archive-entry probe without the phar:// prefix: ' . trim($line),
                );
            }
        }
        // Renamed suffix-less boot law: the alias the guard maps must equal
        // the alias the builder stamps, or the suffix-less identity misses.
        self::assertSame(
            1,
            preg_match("/mapPhar\('([^']+)'\)/", $bin, $binAlias),
            'the guard lost its Phar::mapPhar() renamed-boot candidate',
        );
        $build = $this->readAppFile('tools/build-phar.php');
        self::assertSame(
            1,
            preg_match('/new Phar\(\$outArg, 0, \'([^\']+)\'\)/', $build, $buildAlias),
            'the builder no longer stamps a fixed archive alias',
        );
        self::assertSame(
            $buildAlias[1],
            $binAlias[1],
            'phar alias drift between guard and builder kills the renamed boot',
        );
        // The shim still delegates every branch to the testable Cli::main().
        self::assertStringContainsString('GifView\\Cli::main(', $bin);
        // Locale lands before the first message is formatted (DM3 wiring).
        self::assertStringContainsString('T::setLocale(\SugarCraft\Core\I18n\T::detect());', $bin);
    }

    public function testTheBuildScriptRefusesUnknownOptionsBeforeBuilding(): void
    {
        $build = $this->readAppFile('tools/build-phar.php');

        self::assertStringContainsString('unrecognized option', $build);
        // The unknown-option arm and the phar.readonly guard both bail with 2.
        self::assertStringContainsString('exit(2)', $build);
        // The stub is bin/candy-gifview verbatim with __HALT_COMPILER();.
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
        self::assertStringContainsString("CURRENT = '0.1.0'", $reader);
        // ...and knows its two vocabularies: a comparable release, or an
        // honest dev stamp ('dev-' + short sha from the builder, 'dev-build'
        // when git cannot answer). Without the DEV leg an untagged build
        // would silently impersonate CURRENT — a release number it is not.
        self::assertStringContainsString("'dev-' .", $build);
        self::assertStringContainsString('self::DEV', $reader);
        self::assertStringContainsString('dev-(?:[0-9a-f]{7,40}|build)', $reader);
        // The artifact lands in dist/, which the lib .gitignore keeps out of
        // git — phar builds are disposable, never committed bytes.
        self::assertStringContainsString('/dist/candy-gifview.phar', $build);
        self::assertStringContainsString('/dist/', $this->readAppFile('.gitignore'));
        // ...and the front end routes --version through the resolver.
        self::assertStringContainsString('Support\\Version::resolve', $cli);
        self::assertStringContainsString("token === '--version'", $cli);
        self::assertStringContainsString("'candy-gifview ' . self::runningVersion()", $cli);
        // Producer gate (gifview-05): every stamp — explicit or derived —
        // is validated through the reader's own isStampable().
        self::assertStringContainsString('Version::isStampable', $build);
        self::assertStringContainsString("require_once \$package . '/src/Support/Version.php'", $build);
        // ...and the archive seat of runningVersion() must stay spelled IN
        // THE BODY (the class docblock legitimately quotes the call too).
        $rvStart = strpos($cli, 'function runningVersion');
        self::assertNotFalse($rvStart, 'Cli must keep a runningVersion() seat');
        $rvBody = substr($cli, $rvStart, strpos($cli, "\n    }", $rvStart) ?: $rvStart);
        self::assertStringContainsString('Phar::running(false)', $rvBody);
        self::assertStringContainsString('public static function normalizeStamp', $reader);
        self::assertStringContainsString('public static function isStampable', $reader);
    }

    public function testTheBuilderRefusesAnUnstampableVersionBeforeAnyBuildWork(): void
    {
        $cmd = [
            'timeout', '15', \PHP_BINARY,
            realpath(__DIR__ . '/../tools/build-phar.php') ?: __DIR__ . '/../tools/build-phar.php',
            '--version=release-0.2',
        ];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'could not start the builder child');

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        // The grammar gate sits BEFORE the phar.readonly guard: the refusal
        // must name the version, proving no runtime flag was ever consulted.
        $this->assertSame(2, $code, "builder said: {$err}");
        $this->assertStringContainsString('not consumable', $err);
        $this->assertStringNotContainsString('phar.readonly', $err);
        $this->assertSame('', $out);
    }
}
