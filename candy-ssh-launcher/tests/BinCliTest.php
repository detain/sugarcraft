<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\SshLauncher\Lang;

/**
 * End-to-end pins for the bin/candy-ssh-launcher CLI surface: --help on
 * stdout (exit 0), unknown flags refused by name (exit 2), valueless
 * options refused (exit 2), the `--` terminator honored, `--list`
 * non-interactive output, the no-endpoints/cancelled split (exit 1/130),
 * sanitized error stderr, and LIVE locale resolution (the lang/ files
 * only do something if the bin calls T::setLocale(T::detect())).
 */
final class BinCliTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/candy-ssh-launcher';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
    }

    /**
     * @param string $contents
     * @param string $suffix
     * @return string
     */
    private function writeFixture(string $contents, string $suffix = '.yml'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sshlaunch-bin-') . $suffix;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Run bin with the given argv tail, closed stdin, and return
     * [exitCode, stdout, stderr]. Reap-first: drain pipes to EOF then
     * proc_close, so the child is never left running when we assert.
     *
     * @param list<string> $args
     * @param array<string,string> $extraEnv merged over the minimal env
     * @return array{int,string,string}
     */
    private function runBin(array $args, array $extraEnv = []): array
    {
        $cmd = array_merge([PHP_BINARY, self::BIN], $args);
        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
            ['PATH' => '/usr/bin:/bin', 'HOME' => \sys_get_temp_dir()] + $extraEnv,
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
        $this->assertStringContainsString('candy-ssh-launcher', $out);
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
        // (and the lib root carries none). With BOTH search tiers now
        // existence-gated (2026-10-23), the run stops with the honest
        // "no config found — pass --config" message instead of the old
        // lie about a nonexistent path being "not found".
        $probe = \dirname(__DIR__) . '/wishlist.yml';
        if (is_file($probe)) {
            $this->markTestSkipped('lib root unexpectedly carries a wishlist.yml fixture');
        }
        [$code, , $err] = $this->runBin(['--ssh', '/bin/true']);

        $this->assertSame(1, $code, 'stderr: ' . $err);
        $this->assertStringContainsString('no config', $err);
    }

    public function testExplicitMissingConfigPathSaysNotFound(): void
    {
        [$code, , $err] = $this->runBin([
            '--ssh', '/bin/true',
            '--config', '/nonexistent-dir/wishlist.yml',
        ]);

        $this->assertSame(1, $code, 'stderr: ' . $err);
        $this->assertStringContainsString('not found', $err);
    }

    public function testConfigWithoutValueIsRefused(): void
    {
        [$code, , $err] = $this->runBin(['--config']);

        $this->assertSame(2, $code, 'stderr: ' . $err);
        $this->assertStringContainsString('--config', $err);
        $this->assertStringContainsString('requires a value', $err);
    }

    public function testOptionSwallowingTheNextFlagIsRefused(): void
    {
        // `--config --ssh /bin/true` used to silently bind '--ssh' as the
        // config path via getopt; the next token being a flag is a user
        // mistake, not a path.
        [$code, , $err] = $this->runBin(['--config', '--ssh', '/bin/true']);

        $this->assertSame(2, $code, 'stderr: ' . $err);
        $this->assertStringContainsString('requires a value', $err);
    }

    public function testGluedEmptyValueIsRefused(): void
    {
        [$code, , $err] = $this->runBin(['--ssh=']);

        $this->assertSame(2, $code, 'stderr: ' . $err);
        $this->assertStringContainsString('--ssh=', $err);
    }

    public function testDoubleDashTerminatorIgnoresTheRest(): void
    {
        // Everything after `--` is not read: the bogus flags beneath would
        // each be exit-2-worthy on their own; the run must reach the
        // config stage (exit 1 here, with no config around).
        $probe = \dirname(__DIR__) . '/wishlist.yml';
        if (is_file($probe)) {
            $this->markTestSkipped('lib root unexpectedly carries a wishlist.yml fixture');
        }
        [$code, , $err] = $this->runBin(['--', '--bogus=1', '-x']);

        $this->assertSame(1, $code, 'expected the terminator to silence arg checking, got: ' . $err);
        $this->assertStringContainsString('no config', $err);
    }

    public function testShortHelpFlagExitsZero(): void
    {
        [$code, $out] = $this->runBin(['-h']);

        $this->assertSame(0, $code);
        $this->assertSame(Lang::t('cli.usage') . "\n", $out);
    }

    public function testListPrintsEndpointsWithoutNeedingSshOrATerminal(): void
    {
        $path = $this->writeFixture(implode("\n", [
            '- name: prod',
            '  host: prod.example.com',
            '  user: root',
            '- name: box',
            '  host: box.example.com',
            '  port: 2222',
        ]) . "\n");

        // A deliberately broken --ssh must not stop --list: listing never
        // launches anything.
        [$code, $out, $err] = $this->runBin(['--config', $path, '--list', '--ssh', '/definitely/not/ssh']);

        $this->assertSame(0, $code, 'stderr: ' . $err);
        $this->assertSame(
            "prod\tprod.example.com:22\troot\nbox\tbox.example.com:2222\t-\n",
            $out
        );
    }

    public function testListSanitizesHostileEndpointFields(): void
    {
        $path = $this->writeFixture(
            "- name: \"a\\x1b[2Jb\"\n  host: h.example.com\n"
        );

        [$code, $out, $err] = $this->runBin(['--config', $path, '--list']);

        $this->assertSame(0, $code, 'stderr: ' . $err);
        $this->assertStringNotContainsString("\x1b[2J", $out, 'config bytes must not forge terminal control through --list');
        $this->assertStringContainsString('b', $out);
    }

    public function testEmptyConfigIsNoEndpointsNotCancelled(): void
    {
        // A wholly-commented config previously produced zero endpoints,
        // an empty picker, and — because pick([]) short-circuits to null —
        // the misleading 'cancelled.' with exit 130.
        $path = $this->writeFixture("# nothing to see here\n");

        [$code, , $err] = $this->runBin(['--ssh', '/bin/true', '--config', $path]);

        $this->assertSame(1, $code, 'stderr: ' . $err);
        $this->assertStringContainsString('no endpoints', $err);
        $this->assertStringNotContainsString('cancelled', $err);
    }

    public function testConfigErrorStderrIsSanitized(): void
    {
        // The unparseable-line error echoes raw file bytes; the stderr
        // boundary must not forward their control sequences verbatim.
        $path = $this->writeFixture("\x1b[2Jnot-a-config-line\n");

        [$code, , $err] = $this->runBin(['--ssh', '/bin/true', '--config', $path]);

        $this->assertSame(1, $code, 'stderr: ' . $err);
        $this->assertStringNotContainsString("\x1b[2J", $err);
        $this->assertStringContainsString('not-a-config-line', $err, 'the human part of the message survives sanitizing');
    }

    public function testLocaleIsLiveUnderDe_DE(): void
    {
        // M4 production-reachability proof: with LANG set, the usage line
        // must come back in German — this fails red if the bin drops its
        // T::setLocale(T::detect()) call, because the lang files are
        // otherwise dead weight. The expected string is re-read from the
        // locale file itself so a translation edit cannot desync the pin.
        /** @var array<string,string> $de */
        $de = require \dirname(__DIR__) . '/lang/de.php';

        [$code, $out, $err] = $this->runBin(['--help'], ['LANG' => 'de_DE.UTF-8']);

        $this->assertSame(0, $code, 'stderr: ' . $err);
        $this->assertSame($de['cli.usage'] . "\n", $out);
        $this->assertStringStartsWith('Aufruf', $out);
    }
}
