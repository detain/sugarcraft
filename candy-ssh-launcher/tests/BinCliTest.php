<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\SshLauncher\Lang;

/**
 * End-to-end pins for the bin/candy-ssh-launcher CLI surface: --help on
 * stdout (exit 0), unknown flags refused by name (exit 2), valueless
 * options refused (exit 2), the `--` terminator honored, `--list`
 * non-interactive output, the interactive-path TTY gate (exit 2, pipe
 * runs refuse before dispatching; pty legs drive a real terminal via
 * script(1)), the no-endpoints/cancelled split (exit 1/130),
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
     * @param string $stdin bytes written to the child's stdin before close
     *                     ('' keeps the historical immediately-closed shape)
     * @return array{int,string,string}
     */
    private function runBin(array $args, array $extraEnv = [], string $stdin = ''): array
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
        if ($stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
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
        $this->assertStringContainsString(
            '~/.config/wishlist.yml',
            $err,
            'the refusal itself teaches where to create the file (-009)'
        );
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

    public function testListForgedNewlineStaysASinglePhysicalRow(): void
    {
        // -003: a config name carrying a newline used to print a phantom
        // second TSV row (and a bare \r let an awk-style consumer repaint);
        // the line bytes now fold to spaces before the row is written.
        $path = $this->writeFixture(json_encode([
            ['name' => "prod\nforged", 'host' => 'p.test', 'user' => 'root'],
            ['name' => 'box', 'host' => 'b.test'],
        ]) . "\n", ".json");

        [$code, $out, $err] = $this->runBin(['--config', $path, '--list']);

        $this->assertSame(0, $code, 'stderr: ' . $err);
        $rows = explode("\n", rtrim($out, "\n"));
        $this->assertCount(2, $rows, 'one physical row per endpoint, never a forged third: ' . $out);
        foreach ($rows as $row) {
            $this->assertCount(3, explode("\t", $row), 'exactly two field tabs per row: ' . $row);
        }
        $this->assertSame("prod forged\tp.test:22\troot\nbox\tb.test:22\t-\n", $out);
    }

    public function testListForgedTabAndCarriageReturnCannotShiftColumns(): void
    {
        // -003 companion: a TAB inside a name used to push every later
        // column one field right; a CR used to let the row overwrite itself.
        $path = $this->writeFixture(json_encode([
            ['name' => "a\tb\rc", 'host' => "h\tx.test", 'user' => "u\rv"],
        ]) . "\n", ".json");

        [$code, $out, $err] = $this->runBin(['--config', $path, '--list']);

        $this->assertSame(0, $code, 'stderr: ' . $err);
        $rows = explode("\n", rtrim($out, "\n"));
        $this->assertCount(1, $rows);
        $this->assertSame('a b c', explode("\t", $rows[0])[0]);
        $this->assertSame("a b c\th x.test:22\tu v\n", $out);
    }

    public function testListPrintsEmptyUserLikeAnUnsetOne(): void
    {
        // -078: user "" and user unset are the same statement — one dash.
        $path = $this->writeFixture(json_encode([
            ['name' => 'a', 'host' => 'h.test', 'user' => ''],
        ]) . "\n", ".json");

        [$code, $out, $err] = $this->runBin(['--config', $path, '--list']);

        $this->assertSame(0, $code, 'stderr: ' . $err);
        $this->assertSame("a\th.test:22\t-\n", $out);
    }

    public function testListSuffixesDuplicateNamesWithTheConfigIndex(): void
    {
        // -078 pairing config.duplicate_name (sshl-12): both rows stay in the
        // listing, each addressable by its suffixed name.
        $path = $this->writeFixture(implode("\n", [
            '- name: dup',
            '  host: one.test',
            '- name: uniq',
            '  host: two.test',
            '- name: dup',
            '  host: three.test',
        ]) . "\n");

        [$code, $out, $err] = $this->runBin(['--config', $path, '--list']);

        $this->assertSame(0, $code, 'stderr: ' . $err);
        $this->assertSame(
            "dup#1\tone.test:22\t-\nuniq\ttwo.test:22\t-\ndup#3\tthree.test:22\t-\n",
            $out
        );
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

    public function testVersionPrintsNameAndExitsZero(): void
    {
        // Behavioral pin (F-2): --version answers without reading config or
        // touching a terminal, and glued =value portions are ignored exactly
        // as README documents (F-49's documented side).
        [$code, $out, $err] = $this->runBin(['--version']);
        $this->assertSame(0, $code, 'stderr: ' . $err);
        $this->assertMatchesRegularExpression('/^candy-ssh-launcher \S+\n$/', $out);
        $this->assertStringStartsWith('candy-ssh-launcher ', $out);

        [$gluedCode, $gluedOut] = $this->runBin(['--version=ignored']);
        $this->assertSame(0, $gluedCode);
        $this->assertSame($out, $gluedOut);
    }

    public function testUnknownArgSanitizesHostileToken(): void
    {
        // An argv-interpolating refusal message must not smuggle raw escape
        // bytes onto stderr (F-21).
        [$code, $out, $err] = $this->runBin(["--\x1b[2Jevil"]);
        $this->assertSame(2, $code, 'stderr: ' . $err);
        $this->assertStringContainsString('evil', $err);
        $this->assertStringNotContainsString("\x1b", $err);
        $this->assertSame('', $out);
    }

    public function testSshNotExecutableSanitizesHostilePath(): void
    {
        $cfg = $this->writeFixture("- name: prod\n  host: p.test\n");
        [$code, , $err] = $this->runBin(
            ['--config', $cfg, '--ssh', "/tmp/no\x1b[2Jpe"],
        );
        $this->assertSame(1, $code, 'stderr: ' . $err);
        $this->assertStringContainsString('pe', $err);
        $this->assertStringNotContainsString("\x1b", $err);
    }

    public function testDispatchRefusalFailsLoudWithBoundedExit(): void
    {
        // F-6: a field the Endpoint layer refuses at dispatch time used to
        // escape as a raw stack trace; it now exits 3 with a named,
        // sanitized stderr line. The name field carries hostile bytes to
        // prove the same wrap covers the message. Since the TTY gate the
        // picker path cannot be driven from a pipe at all, so this leg
        // runs the bin under its own pty (same harness sshl-40 extends).
        $cfg = $this->writeFixture(
            "- name: \"p\x1b[2Jtest\"\n  host: p.test\n  proxyJump: \"-o=evil\"\n"
        );
        [$code, $output] = $this->runUnderPty(
            ['--config', $cfg, '--ssh', '/bin/true'],
            selectKey: "\r",
        );
        $this->assertSame(3, $code, 'pty output: ' . $this->printable($output));
        $this->assertStringContainsString('proxyJump', $output);
        $this->assertStringContainsString('unsafe leading-dash', $output);
    }

    // ------------------------------------------------------------------
    // TTY gate (sshl-10 / finding -005): the interactive path refuses
    // pipes; --list/--help/--version stay pipe-safe by contract.
    // ------------------------------------------------------------------

    public function testPipedInputIsRefusedWithExitTwoAndNeverDispatches(): void
    {
        // The finding's live probe: `printf 'prod\r' | bin --ssh /bin/echo`
        // used to exit 0 AFTER EXECUTING echo — a piped line completing a
        // selection unattended. The gate refuses before the picker exists:
        // exit 2, the localized refusal on stderr, and zero bytes of echo
        // output (stdout empty is the proof nothing launched).
        $cfg = $this->writeFixture("- name: prod\n  host: p.test\n");
        /** @var array<string,string> $en */
        $en = require \dirname(__DIR__) . '/lang/en.php';

        [$code, $out, $err] = $this->runBin(
            ['--config', $cfg, '--ssh', '/bin/echo'],
            [],
            "prod\r",
        );

        $this->assertSame(2, $code, 'stdout: ' . $out . ' stderr: ' . $err);
        $this->assertSame('', $out, 'a piped run must launch nothing and paint nothing');
        $this->assertSame($en['cli.not_a_terminal'] . "\n", $err, 'refusal names the key verbatim and points at --list');
        $this->assertStringContainsString('--list', $err);
    }

    public function testClosedStdinOnTheInteractivePathIsRefused(): void
    {
        $cfg = $this->writeFixture("- name: prod\n  host: p.test\n");
        [$code, $out, $err] = $this->runBin(['--config', $cfg, '--ssh', '/bin/true']);

        $this->assertSame(2, $code, 'stdout: ' . $out . ' stderr: ' . $err);
        $this->assertStringContainsString('interactive terminal', $err);
    }

    public function testRedirectedStdoutUnderARealTerminalIsRefused(): void
    {
        // Keyboard on a terminal, screen into a file: the frames must not
        // be painted into the redirect — the gate checks BOTH streams.
        $cfg = $this->writeFixture("- name: prod\n  host: p.test\n");
        $capture = tempnam(sys_get_temp_dir(), 'sshlaunch-stdout-') . '.capture';
        $this->tempFiles[] = $capture;

        [$code, $output] = $this->runUnderPty(
            ['--config', $cfg, '--ssh', '/bin/true'],
            redirectStdoutTo: $capture,
        );

        $this->assertSame(2, $code, 'pty output: ' . $this->printable($output));
        $this->assertSame('', (string) file_get_contents($capture), 'nothing may be painted into the redirect');
        $this->assertStringContainsString('interactive terminal', $output, 'the refusal goes to the terminal on stderr');
    }

    public function testListRemainsPipeSafeUnderTheGate(): void
    {
        // Explicit double-hand for the acceptance: the refusal gate sits
        // AFTER the --list leg on purpose, scripted output keeps working
        // (exit 0, endpoints printed, no refusal text).
        $cfg = $this->writeFixture("- name: prod\n  host: p.test\n  user: root\n");
        [$code, $out, $err] = $this->runBin(['--config', $cfg, '--list']);

        $this->assertSame(0, $code, 'stderr: ' . $err);
        $this->assertSame("prod\tp.test:22\troot\n", $out);
        $this->assertStringNotContainsString('interactive terminal', $err);
    }

    public function testPickerRendersAndSelectsUnderAPty(): void
    {
        // End-to-end: under a real pty the picker draws its frame, Enter
        // completes the selection, and the launch replaces the process
        // (/bin/echo prints the ssh argv and exits 0) — the gate must not
        // break the very path it protects.
        $cfg = $this->writeFixture(implode("\n", [
            '- name: prod',
            '  host: p.test',
            '- name: box',
            '  host: b.test',
        ]) . "\n");

        [$code, $output] = $this->runUnderPty(
            ['--config', $cfg, '--ssh', '/bin/echo'],
            selectKey: "\r",
        );

        $this->assertSame(0, $code, 'pty output: ' . $this->printable($output));
        $this->assertStringContainsString('prod', $output, 'the first frame showed the endpoint list');
        $this->assertStringContainsString('-- p.test', $output, 'the selection launched ssh at the chosen host (echo printed argv)');
        $this->assertStringNotContainsString('-- b.test', $output, 'the untouched second endpoint never launched');
    }

    public function testRemoteCommandRidesThePositionalEndToEnd(): void
    {
        // -007 acceptance: a kubectl-attach wishlist row must survive the
        // whole path — ingest, argv build, dispatch — with the command in
        // ssh's positional slot AFTER the destination (fake ssh /bin/echo
        // prints the argv it received).
        $cfg = $this->writeFixture(implode("\n", [
            '- name: kprod',
            '  host: k.test',
            '  remote_command: kubectl exec -it bash',
        ]) . "\n");

        [$code, $output] = $this->runUnderPty(
            ['--config', $cfg, '--ssh', '/bin/echo'],
            selectKey: "\r",
        );

        $this->assertSame(0, $code, 'pty output: ' . $this->printable($output));
        $this->assertStringContainsString('-- k.test kubectl exec -it bash', $this->printable($output));
    }

    /**
     * Run the bin on a pty of its own via util-linux `script -qec` (the
     * candy-tetris BinaryLaunchTest shape): -e propagates the child's exit
     * code and the typescript is discarded. $selectKey is typed once the
     * first picker frame is on screen — earlier keystrokes can be flushed
     * by the raw-mode switch — and re-sent until the child exits.
     *
     * @param list<string> $args
     * @return array{0:int,1:string} exit code and everything the pty showed
     */
    private function runUnderPty(
        array $args,
        ?string $selectKey = null,
        ?string $redirectStdoutTo = null,
    ): array {
        $binary = realpath(self::BIN);
        $this->assertNotFalse($binary);
        $inner = 'stty rows 24 cols 80 2>/dev/null; exec '
            . implode(' ', array_map('escapeshellarg', [PHP_BINARY, $binary, ...$args]))
            . ($redirectStdoutTo !== null ? ' > ' . escapeshellarg($redirectStdoutTo) : '');
        $process = proc_open(
            'exec script -qec ' . escapeshellarg($inner) . ' /dev/null',
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
            ['PATH' => '/usr/bin:/bin', 'HOME' => \sys_get_temp_dir(), 'TERM' => 'xterm-256color', 'LC_ALL' => 'C', 'LANG' => 'C'],
        );
        $this->assertIsResource($process, 'failed to spawn script(1) — is util-linux installed?');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + 20.0;
        $nextKeyAt = null;
        $output = '';
        while (true) {
            $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if ($selectKey !== null && str_contains($output, "\x1b[1;1H")) {
                $nextKeyAt ??= microtime(true) + 0.3;
                if (microtime(true) >= $nextKeyAt) {
                    fwrite($pipes[0], $selectKey);
                    $nextKeyAt = microtime(true) + 1.0;
                }
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                $this->fail('bin under a pty did not exit within the deadline: ' . $this->printable($output));
            }
            usleep(20_000);
        }
        $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);

        return [$exitCode, $output];
    }

    private function printable(string $bytes): string
    {
        return addcslashes(substr($bytes, -600), "\0..\37\177");
    }
}
