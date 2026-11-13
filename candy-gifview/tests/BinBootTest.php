<?php

declare(strict_types=1);

namespace SugarCraft\GifView\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Boot matrix for the bin's archive-aware autoload guard (finding 011): the
 * three trusted identities a phar can present, and the renamed suffix-less
 * one that used to be fatal.
 *
 * A published .phar downloaded and renamed to a bare command (the composer
 * install idiom) neither auto-mounts (the CLI SAPI runs it as a plain script
 * and Phar::running() answers '') nor resolves `phar://__FILE__` — measured
 * PHP 8.3.6, the wrapper cannot locate an archive without a `.phar` segment
 * in the URL. Phar::mapPhar() is the escape composer uses for the same case:
 * it finds the signature inside the running file and mounts it under its
 * build-time alias. This suite boots real (minimal) archives in temp dirs to
 * pin every leg end-to-end; the archive carries only a vendor/autoload.php
 * shim requiring the working tree's published vendor, so the guard logic,
 * not the app, is what runs.
 */
final class BinBootTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/candy-gifview';

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
        $this->paths = [];
    }

    /**
     * Build a minimal archive whose stub is the real bin verbatim (what
     * tools/build-phar.php ships) around an autoload shim. Returns the dir
     * holding `candy-gifview.phar`, or null when this host cannot write
     * archives (phar.readonly unbeatable even in a child) — the skip is the
     * disclosure, never a fake green.
     */
    private function buildMiniPhar(): ?string
    {
        $dir = sys_get_temp_dir() . '/gifview-binboot-' . uniqid('', true);
        if (!mkdir($dir, 0o755, true)) {
            self::fail('cannot create the temp boot dir');
        }
        $this->paths[] = $dir;

        $realAutoload = realpath(__DIR__ . '/../vendor/autoload.php');
        self::assertIsString($realAutoload, 'the published vendor must exist for the shim to point at');

        $maker = $dir . '/make.php';
        file_put_contents($maker, implode("\n", [
            '<?php',
            '$bin = file_get_contents(' . var_export(realpath(self::BIN) ?: self::BIN, true) . ');',
            '$shim = "<?php require_once " . var_export(' . var_export($realAutoload, true) . ', true) . ";";',
            '$phar = new Phar(' . var_export($dir . '/candy-gifview.phar', true) . ', 0, \'candy-gifview.phar\');',
            '$phar->startBuffering();',
            '$phar->addFromString(\'vendor/autoload.php\', $shim);',
            '$phar->setStub($bin . "\\n__HALT_COMPILER();");',
            '$phar->stopBuffering();',
            'echo "BUILT\\n";',
        ]));

        [$code, , $out] = $this->runChild(['timeout', '30', \PHP_BINARY, '-d', 'phar.readonly=0', $maker]);
        if ($code !== 0 || !is_file($dir . '/candy-gifview.phar')) {
            return null;
        }

        return $dir;
    }

    public function testRenamedSuffixlessPharBootsAndAnswersHelp(): void
    {
        $dir = $this->buildMiniPhar();
        if ($dir === null) {
            $this->markTestSkipped('phar.readonly=0 unavailable even in a child — archive boot legs cannot run here');
        }

        $moved = $dir . '/moved-no-suffix';
        $this->paths[] = $moved;
        self::assertTrue(copy($dir . '/candy-gifview.phar', $moved));

        [$code, $err, $out] = $this->runChild(['timeout', '15', \PHP_BINARY, $moved, '--help']);

        $this->assertSame(0, $code, 'renamed suffix-less boot died: ' . $err);
        $this->assertStringContainsString('usage:', $out);
        $this->assertStringContainsString('candy-gifview', $out);
    }

    public function testSuffixFullPharStillBoots(): void
    {
        $dir = $this->buildMiniPhar();
        if ($dir === null) {
            $this->markTestSkipped('phar.readonly=0 unavailable even in a child — archive boot legs cannot run here');
        }

        [$code, $err, $out] = $this->runChild(['timeout', '15', \PHP_BINARY, $dir . '/candy-gifview.phar', '--help']);

        $this->assertSame(0, $code, 'the .phar-suffixed boot regressed: ' . $err);
        $this->assertStringContainsString('usage:', $out);
    }

    public function testTheSourceTreeBinStillBoots(): void
    {
        [$code, $err, $out] = $this->runChild(['timeout', '15', \PHP_BINARY, realpath(self::BIN), '--help']);

        $this->assertSame(0, $code, 'the ordinary source-tree boot regressed: ' . $err);
        $this->assertStringContainsString('usage:', $out);
    }

    /**
     * Finding 033: on a phar-less host (`php -n`) the bare \Phar::running
     * calls used to fatal rc 255 in BOTH seats (bin guard and
     * Cli::runningVersion) before anything was parsed. The class_exists
     * gates turn absence into the '' non-archive answer, so --version —
     * which stands in front of the gd preflight — must answer honestly.
     * Gated on the -n lever actually dropping Phar, per the GdGuard
     * disclosure law (hosts with phar compiled in skip with a named reason).
     */
    public function testTheBinAnswersVersionWithoutThePharClass(): void
    {
        $probe = $this->runChild(['timeout', '10', \PHP_BINARY, '-n', '-r',
            'echo class_exists("Phar") ? "present" : "absent";']);
        if (trim($probe[2]) !== 'absent') {
            $this->markTestSkipped('phar survives `php -n` on this host (compiled in); the gate cannot be exercised behaviourally');
        }

        [$code, $err, $out] = $this->runChild(
            ['timeout', '15', \PHP_BINARY, '-n', realpath(self::BIN), '--version'],
        );

        $this->assertStringNotContainsString('Class "Phar" not found', $err, 'the class_exists gate came off: ' . $err);
        $this->assertNotSame(255, $code, 'a phar-less host fatal-ed the bin: ' . $err);
        $this->assertSame(0, $code, 'the -n --version arm must still answer from source: ' . $err);
        $this->assertSame("candy-gifview 0.1.0\n", $out);
    }

    public function testAPlainScriptWithoutAutoloadStillRefusesHonestly(): void    {
        // The mapPhar probe must not turn the no-autoload refusal into a
        // PharException fatal: copy the bin where no vendor/ resolves and
        // assert the old contract (exit 1 + the cannot-find-autoload line).
        $dir = sys_get_temp_dir() . '/gifview-binboot-novendor-' . uniqid('', true);
        self::assertTrue(mkdir($dir, 0o755, true));
        $this->paths[] = $dir;
        $orphan = $dir . '/bin';
        $this->paths[] = $orphan;
        self::assertTrue(copy(self::BIN, $orphan));

        [$code, $err] = $this->runChild(['timeout', '15', \PHP_BINARY, $orphan, '--help']);

        $this->assertSame(1, $code, 'a vendor-less plain script must exit 1, never fatal: ' . $err);
        $this->assertStringContainsString('cannot find composer autoload.php', $err);
        $this->assertStringNotContainsString('PharException', $err);
        $this->assertStringNotContainsString('__HALT_COMPILER', $err);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{int, string, string} exit code, stderr, stdout
     */
    private function runChild(array $argv): array
    {
        $outLog = tempnam(sys_get_temp_dir(), 'gv_boot_out_');
        $errLog = tempnam(sys_get_temp_dir(), 'gv_boot_err_');
        $this->paths[] = $outLog;
        $this->paths[] = $errLog;

        $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['file', $outLog, 'w'], 2 => ['file', $errLog, 'w']], $pipes);
        $this->assertIsResource($proc, 'could not start child: ' . implode(' ', $argv));
        if (is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $code = proc_close($proc);

        return [$code, (string) file_get_contents($errLog), (string) file_get_contents($outLog)];
    }
}
