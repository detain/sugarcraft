<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Wishlist\Lang;

/**
 * End-to-end pins for the bin/wishlist CLI surface added with the audit
 * fix wave: --help on stdout (exit 0), unknown flags refused by name
 * (exit 2). PHP's getopt() silently stops parsing at the first unknown
 * option, so without the raw-argv pre-scan a typo like `--confg` would
 * launch the picker with defaults instead of erroring.
 */
final class BinCliTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/wishlist';

    /**
     * Run bin with the given argv tail, closed stdin, and return
     * [exitCode, stdout, stderr]. Reap-first: drain pipes to EOF then
     * proc_close, so the child is never left running when we assert.
     *
     * @param list<string> $args
     * @return array{int,string,string}
     */
    private function runBin(array $args): array
    {
        $cmd = array_merge([PHP_BINARY, self::BIN], $args);
        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
            ['PATH' => '/usr/bin:/bin', 'HOME' => \sys_get_temp_dir()],
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [$code, (string) $stdout, (string) $stderr];
    }

    public function testHelpPrintsUsageOnStdoutAndExitsZero(): void
    {
        [$code, $out, $err] = $this->runBin(['--help']);

        $this->assertSame(0, $code, 'stderr: ' . $err);
        $this->assertStringContainsString('wishlist', $out);
        $this->assertStringContainsString('--config', $out);
        $this->assertStringContainsString('--ssh', $out);
        $this->assertSame(Lang::t('cli.usage') . "\n", $out, 'usage line comes from the cli.usage key verbatim');
        $this->assertSame('', $err);
    }

    public function testGlangedHelpFormAlsoExitsZero(): void
    {
        [$code, $out] = $this->runBin(['--help=1']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('--config', $out);
    }

    public function testUnknownFlagIsRefusedByNameWithNonZeroExit(): void
    {
        [$code, $out, $err] = $this->runBin(['--confg', 'typo.yml']);

        $this->assertSame(2, $code, 'stdout: ' . $out . ' stderr: ' . $err);
        $this->assertStringContainsString('--confg', $err, 'the offending arg is named');
        $this->assertSame('', $out);
    }

    public function testUnknownFlagWithGluedValueIsRefusedByName(): void
    {
        [$code, , $err] = $this->runBin(['--nope=42']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('--nope=42', $err);
    }

    public function testSingleDashFlagIsRefused(): void
    {
        [$code, , $err] = $this->runBin(['-x']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('-x', $err);
    }

    public function testMissingConfigStillFailsLoudly(): void
    {
        // --ssh to a real binary so the ssh guard passes and we reach the
        // config probe. HOME points at a temp dir with no wishlist config
        // (and the lib root carries none), so the run must fail loudly at
        // load time rather than launch an empty picker.
        $probe = \dirname(__DIR__) . '/wishlist.yml';
        if (is_file($probe)) {
            $this->markTestSkipped('lib root unexpectedly carries a wishlist.yml fixture');
        }
        [$code, , $err] = $this->runBin(['--ssh', '/bin/true']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('not found', $err);
    }
}
