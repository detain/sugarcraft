<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\SshLauncher\Support\Version;

/**
 * The phar packaging surface, pinned by RUNNING it (sshl-38, findings -096).
 *
 * The old lane-PHE net was three text-greps over tools/build-phar.php and the
 * bin — they proved strings existed, not that any of it worked (the -043
 * "proves nothing about VALUES" indictment). This net instead:
 *
 *  1. runs the real build script's refusal doors BEHAVIORALLY — unknown
 *     option and bad --vendor mode exit 2 before any side effect, the
 *     phar.readonly door answers when the flag is forced On, the integrity
 *     preflight fails CLOSED on a tree git cannot answer for, and a single
 *     symlinked entry inside a packaged tree stops a monorepo-mode build by
 *     name;
 *  2. BUILDS A REAL ARCHIVE once per suite (from a scratch copy of the
 *     package — build-phar refuses dirty trees, and this repo is never
 *     pristine mid-lane; the scratch copy also keeps the lib free of dist/
 *     litter) with `--vendor=monorepo --version=9.9.9`, then runs it:
 *     `php archive.phar --version` must answer the STAMPED value (the whole
 *     stamp→metadata→reader→bin chain in one byte-exact assertion), and
 *     `--help` + `--list` must work — proving the stub's phar:// autoload
 *     guard and the `__DIR__/../lang` data path THROUGH the archive, the two
 *     internal shapes the old greps could only name.
 *
 * Minimal lexical residue stays only for genuinely runtime-unreachable
 * internals: the shebang + open-tag stub shape itself (what makes the file a
 * valid PHP entry at all is exercised by the runs above; the shape line is a
 * one-call string check the build also enforces).
 *
 * TWO-DIRECTIONAL DESIGN CHECK (the suite's T1 doctrine), in-lane measured:
 * a harmless quote-style refactor of build-phar.php stays GREEN here — this
 * file no longer greps its source text at all, only its output values; and
 * breaking the chain goes RED — relabeling setMetadata's 'version' key, or
 * dropping the 'phar://' prefix in the bin's autoload guard, reddens the
 * stamp leg / every archive-run leg respectively (both mutations proven in
 * the lane before commit, then restored byte-clean).
 */
final class PharBuildScriptTest extends TestCase
{
    private const APP_ROOT = __DIR__ . '/..';

    /** Scratch root: package copy + built archives, one per suite run. */
    private static ?string $base = null;
    private static ?string $package = null;
    private static ?string $archive = null;
    private static ?string $archiveDev = null;

    public static function tearDownAfterClass(): void
    {
        if (self::$base !== null && is_dir(self::$base)) {
            self::removeTree(self::$base);
        }
        self::$base = null;
        self::$package = null;
        self::$archive = null;
        self::$archiveDev = null;
    }

    private function readAppFile(string $relative): string
    {
        $path = self::APP_ROOT . '/' . $relative;
        $contents = is_file($path) ? file_get_contents($path) : false;
        self::assertIsString($contents, "expected readable file {$relative}");

        return $contents;
    }

    // -----------------------------------------------------------------------
    // Scratch package harness
    // -----------------------------------------------------------------------

    /**
     * Materialize a self-contained copy of the package outside any git tree.
     * The vendor/ walk MATERIALIZES the monorepo's linked sibling installs
     * (vendor/sugarcraft/* are symlinks in linked mode, and build-phar's
     * guard 4 refuses to package links on purpose) by copying each sibling's
     * composer.json + src/ + lang/ — the autoload map's footprint — into a
     * real directory. Every other entry is copied verbatim.
     */
    private static function packageDir(): string
    {
        if (self::$package !== null) {
            return self::$package;
        }
        $base = sys_get_temp_dir() . '/sshlaunch-phar-' . bin2hex(random_bytes(6));
        mkdir($base . '/package', 0o777, true);
        self::$base = $base;
        self::$package = $base . '/package';

        foreach (['bin', 'src', 'lang', 'tools'] as $root) {
            self::copyTree(self::APP_ROOT . '/' . $root, self::$package . '/' . $root);
        }
        if (is_file(self::APP_ROOT . '/LICENSE')) {
            copy(self::APP_ROOT . '/LICENSE', self::$package . '/LICENSE');
        }
        self::copyVendor(self::APP_ROOT . '/vendor', self::$package . '/vendor');

        return self::$package;
    }

    /** Straight recursive copy; a symlink here means the source drifted. */
    private static function copyTree(string $from, string $to): void
    {
        mkdir($to, 0o777, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $src = $from . '/' . $entry;
            if (is_link($src)) {
                throw new \RuntimeException("unexpected symlink in packaged source {$src}");
            }
            if (is_dir($src)) {
                self::copyTree($src, $to . '/' . $entry);
                continue;
            }
            copy($src, $to . '/' . $entry);
        }
    }

    /** Vendor walk with the linked-sibling materialization (see above). */
    /**
     * Dev-only vendor trees (phpunit's closure) plus the composer bin dir.
     * Dropping them mirrors `composer install --no-dev` — the state build-phar
     * documents as the honest release input — and keeps the scratch package at
     * the archive's true runtime closure (measured: the full 12M linked-mode
     * vendor seals so slowly the suite stalls; the trimmed one builds in ~5s).
     */
    private const VENDOR_DEV_SKIP = ['bin', 'phpunit', 'myclabs', 'nikic', 'phar-io', 'sebastian', 'theseer'];

    private static function copyVendor(string $from, string $to, bool $top = true): void
    {
        mkdir($to, 0o777, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if ($top && in_array($entry, self::VENDOR_DEV_SKIP, true)) {
                continue;
            }
            $src = $from . '/' . $entry;
            $dst = $to . '/' . $entry;
            if (is_link($src)) {
                $real = realpath($src);
                if ($real === false || !is_dir($real)) {
                    throw new \RuntimeException("unresolvable symlink {$src}");
                }
                mkdir($dst, 0o777, true);
                if (is_file($real . '/composer.json')) {
                    copy($real . '/composer.json', $dst . '/composer.json');
                }
                foreach (['src', 'lang'] as $needed) {
                    if (is_dir($real . '/' . $needed)) {
                        self::copyTree($real . '/' . $needed, $dst . '/' . $needed);
                    }
                }
                continue;
            }
            if (is_dir($src)) {
                self::copyVendor($src, $dst, false);
                continue;
            }
            copy($src, $dst);
        }
        if (!$top) {
            return;
        }
        // Composer's file loader requires each listed file verbatim, so the
        // skipped dev trees must also leave the autoload maps that name them
        // — again exactly what --no-dev does. This loader is generated with
        // $files inlined in autoload_static.php (the optimized path), but
        // autoload_files.php (the plain path) is filtered too so the harness
        // survives a composer regeneration.
        foreach (['autoload_static.php', 'autoload_files.php'] as $mapFile) {
            $files = $to . '/composer/' . $mapFile;
            if (!is_file($files)) {
                continue;
            }
            $source = (string) file_get_contents($files);
            $filtered = preg_grep(
                '#=> [^,]*/(bin|phpunit|myclabs|nikic|phar-io|sebastian|theseer)/#',
                explode("\n", $source),
                PREG_GREP_INVERT,
            );
            file_put_contents($files, implode("\n", $filtered));
        }
    }

    /**
     * Run a child to completion with a bounded drain: whitelist env only,
     * closed stdin, pipes drained concurrently, overrun terminates the child
     * and fails. Returns [exitCode, stdout, stderr].
     *
     * @param list<string> $cmd
     * @param array<string,string> $env
     * @return array{int,string,string}
     */
    private static function runChild(array $cmd, array $env, string $cwd): array
    {
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('proc_open failed for ' . $cmd[0]);
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + 180.0;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            if (stream_select($read, $write, $except, 1) === false) {
                break;
            }
            foreach ($read as $stream) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($stream === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
            $status = proc_get_status($proc);
            $open = !feof($pipes[1]) || !feof($pipes[2]);
            if (!$open || (!$status['running'] && self::drained($pipes[1]) && self::drained($pipes[2]))) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, SIGKILL);
                throw new \RuntimeException('child overrun: ' . implode(' ', $cmd));
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [$code, $stdout, $stderr];
    }

    /** @param resource $stream */
    private static function drained($stream): bool
    {
        return feof($stream);
    }

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /** @return array<string,string> */
    private static function childEnv(): array
    {
        return ['PATH' => '/usr/bin:/bin', 'HOME' => (string) self::$base];
    }

    /**
     * Build (once) the two archives the value legs need: one stamped 9.9.9,
     * one without --version (which falls to the git describe answer — and a
     * scratch tree has no tags, so its stamp is the literal 'dev-build').
     */
    private static function ensureArchives(): void
    {
        if (self::$archive !== null && self::$archiveDev !== null) {
            return;
        }
        $package = self::packageDir();
        $build = $package . '/tools/build-phar.php';

        $stamped = self::$base . '/launcher-9.9.9.phar';
        [$code, $out, $err] = self::runChild(
            [PHP_BINARY, '-d', 'phar.readonly=0', $build, '--vendor=monorepo', '--version=9.9.9', '--out=' . $stamped],
            self::childEnv(),
            $package,
        );
        self::assertSame(0, $code, "stamped build failed\nstdout: {$out}\nstderr: {$err}");
        self::assertTrue(is_file($stamped), 'build-phar reported success but wrote no archive at ' . $stamped);
        self::$archive = $stamped;

        $dev = self::$base . '/launcher-dev.phar';
        [$code, $out, $err] = self::runChild(
            [PHP_BINARY, '-d', 'phar.readonly=0', $build, '--vendor=monorepo', '--out=' . $dev],
            self::childEnv(),
            $package,
        );
        self::assertSame(0, $code, "dev build failed\nstdout: {$out}\nstderr: {$err}");
        self::$archiveDev = $dev;
    }

    // -----------------------------------------------------------------------
    // Legs
    // -----------------------------------------------------------------------

    public function testTheBinStubKeepsTheShebangAndArchiveAwareGuardShape(): void
    {
        $bin = $this->readAppFile('bin/candy-ssh-launcher');

        // The stub shape: shebang + open tag. Everything the guard must DO is
        // exercised by running real archives below (autoload, lang data, CLI
        // values); this line check is the one property that only the source
        // bytes can prove before a build ever consumes them.
        self::assertTrue(
            str_starts_with($bin, "#!/usr/bin/env php\n<?php"),
            'bin/candy-ssh-launcher no longer starts with the shebang+<?php stub shape',
        );
        self::assertStringContainsString('phar://', $bin);
        self::assertStringContainsString('Phar::running', $bin);
    }

    public function testTheBuildScriptRefusesBadOptionsBeforeAnySideEffect(): void
    {
        $build = self::APP_ROOT . '/tools/build-phar.php';

        [$code, $out, $err] = self::runChild([PHP_BINARY, $build, '--bogus'], self::childEnv(), self::APP_ROOT);
        self::assertSame(2, $code, "stderr: {$err}");
        self::assertStringContainsString('unrecognized option --bogus', $err);
        self::assertSame('', $out);

        [$code, , $err] = self::runChild([PHP_BINARY, $build, '--vendor=bogus'], self::childEnv(), self::APP_ROOT);
        self::assertSame(2, $code, "stderr: {$err}");
        self::assertStringContainsString('--vendor=bogus is not a mode', $err);
    }

    public function testTheBuildRefusesWhilePharReadonlyIsOn(): void
    {
        [$code, , $err] = self::runChild(
            [PHP_BINARY, '-d', 'phar.readonly=1', self::APP_ROOT . '/tools/build-phar.php'],
            self::childEnv(),
            self::APP_ROOT,
        );

        self::assertSame(2, $code, "stderr: {$err}");
        self::assertStringContainsString('phar.readonly is On', $err);
    }

    public function testThePublishedBuildRefusesATreeGitCannotAnswerFor(): void
    {
        $package = self::packageDir();

        // Guard 2 fails CLOSED: the scratch copy lives outside every
        // repository, so git cannot vouch for it and a published-mode build
        // must stop. (The same door refuses dirty trees; a scratch tree
        // cannot be made dirty-and-committed without a git identity, which
        // the lane declines to assume.)
        [$code, , $err] = self::runChild(
            [PHP_BINARY, '-d', 'phar.readonly=0', $package . '/tools/build-phar.php', '--out=' . self::$base . '/never.phar'],
            self::childEnv(),
            $package,
        );

        self::assertSame(2, $code, "stderr: {$err}");
        self::assertStringContainsString('integrity cannot be verified', $err);
        self::assertFalse(is_file(self::$base . '/never.phar'), 'a refused build wrote an archive anyway');
    }

    public function testTheBuildRefusesSymlinkedTreesEvenInDevMode(): void
    {
        $package = self::packageDir();
        $link = $package . '/vendor/linkpoison';
        if (!symlink('/etc/hostname', $link)) {
            self::markTestSkipped('this filesystem cannot create symlinks');
        }
        try {
            [$code, , $err] = self::runChild(
                [PHP_BINARY, '-d', 'phar.readonly=0', $package . '/tools/build-phar.php', '--vendor=monorepo', '--out=' . self::$base . '/poison.phar'],
                self::childEnv(),
                $package,
            );
        } finally {
            @unlink($link);
        }

        self::assertSame(2, $code, "stderr: {$err}");
        self::assertStringContainsString('refusing to package symlinked tree', $err);
        self::assertStringContainsString('linkpoison', $err, 'the offending entry is named');
        self::assertFalse(is_file(self::$base . '/poison.phar'));
    }

    public function testTheBuiltArchiveAnswersTheStampedVersionValue(): void
    {
        self::ensureArchives();
        self::assertNotSame('9.9.9', Version::CURRENT, 'the stamp must stay distinguishable from CURRENT for this pin to mean anything');

        [$code, $out, $err] = self::runChild([PHP_BINARY, self::$archive, '--version'], self::childEnv(), (string) self::$base);

        self::assertSame(0, $code, "stderr: {$err}");
        self::assertSame("candy-ssh-launcher 9.9.9\n", $out, 'the archive answers its stamped metadata, not the compiled-in CURRENT');
    }

    public function testAnArchiveStampedWithTheDevPlaceholderFallsBackToCurrent(): void
    {
        self::ensureArchives();

        [$code, $out, $err] = self::runChild([PHP_BINARY, self::$archiveDev, '--version'], self::childEnv(), (string) self::$base);

        self::assertSame(0, $code, "stderr: {$err}");
        self::assertSame('candy-ssh-launcher ' . Version::CURRENT . "\n", $out, "the unstampable 'dev-build' metadata must not leak into --version");
    }

    public function testTheBuiltArchiveServesHelpAndListThroughThePharAutoloadBranch(): void
    {
        self::ensureArchives();

        // --help proves the composer autoload AND the __DIR__/../lang data
        // path work inside the archive (the values come from lang/en.php
        // resolved via phar://, exactly what the archive-root mirror law is
        // for).
        [$code, $out, $err] = self::runChild([PHP_BINARY, self::$archive, '--help'], self::childEnv(), (string) self::$base);
        self::assertSame(0, $code, "stderr: {$err}");
        self::assertStringContainsString('--from-ssh-config', $out, 'cli.usage text must load from lang/ inside the archive');
        self::assertStringContainsString('candy-ssh-launcher', $out);

        // --list proves config parsing and the display line run end-to-end.
        $config = (string) self::$base . '/wishlist.yml';
        file_put_contents($config, "- name: web1\n  host: web.example.com\n  port: 2200\n  user: ops\n");
        chmod($config, 0o644);
        [$code, $out, $err] = self::runChild([PHP_BINARY, self::$archive, '--config', $config, '--list'], self::childEnv(), (string) self::$base);

        self::assertSame(0, $code, "stderr: {$err}");
        self::assertStringContainsString("web1\tweb.example.com:2200\tops\n", $out);
    }
}
