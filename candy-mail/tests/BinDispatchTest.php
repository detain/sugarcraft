<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke tests for bin/candy-mail: each case runs the real CLI as a
 * child process (argv array spawn, no shell) so the dispatch guard, exit
 * codes, and stderr wording are exercised exactly as a user's are.
 */
final class BinDispatchTest extends TestCase
{
    /** Env vars neutralised before every child unless the test sets them. */
    private const NEUTRAL_ENV = [
        'RESEND_API_KEY',
        'CANDY_MAIL_FROM',
        'CANDY_MAIL_SMTP_HOST',
        'CANDY_MAIL_SMTP_PORT',
        'CANDY_MAIL_SMTP_USERNAME',
        'CANDY_MAIL_SMTP_PASSWORD',
        'CANDY_MAIL_SMTP_REQUIRE_TLS',
        'CANDY_MAIL_SMTP_IMPLICIT_TLS',
        'CANDY_MAIL_SMTP_HELO',
        'CANDY_MAIL_SIGNATURE',
    ];

    protected function setUp(): void
    {
        // Load the bin's helper functions (the dispatch guard stays asleep
        // because $argv[0] is phpunit, not the bin) so cliVersion() parity can
        // be asserted in-process against the child's answer.
        require_once __DIR__ . '/../bin/candy-mail';
    }

    /**
     * @param list<string>                      $args
     * @param array<string, string>             $env
     * @param string|null                       $binOverride  alternate bin path (layout pins)
     * @return array{status: int, out: string, err: string}
     */
    /**
     * @param array<string,string> $extraEnv verbatim child-env additions for
     *                                       names outside NEUTRAL_ENV (plain
     *                                       ASCII only: control-byte NAME
     *                                       delivery is impossible through
     *                                       PHP's env layer, B3 discovery)
     */
    private function runCli(array $args, string $stdin = '', array $env = [], ?string $binOverride = null, array $extraEnv = []): array
    {
        $bin = $binOverride ?? \realpath(__DIR__ . '/../bin/candy-mail');
        $this->assertIsString($bin);

        $childEnv = [];
        foreach (self::NEUTRAL_ENV as $name) {
            if (isset($env[$name])) {
                $childEnv[$name] = $env[$name];
            }
        }
        // Locale selection is deliberately controllable per test (the bin
        // reads LANG/LC_ALL/LC_MESSAGES via T::detect()); everything else in
        // the parent env stays invisible to the child.
        foreach (['LANG', 'LC_ALL', 'LC_MESSAGES'] as $localeVar) {
            if (isset($env[$localeVar])) {
                $childEnv[$localeVar] = $env[$localeVar];
            }
        }
        $childEnv['PATH'] = (string) (\getenv('PATH') ?: '/usr/bin:/bin');
        foreach ($extraEnv as $extraName => $extraValue) {
            $childEnv[(string) $extraName] = (string) $extraValue;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = \proc_open(
            [(string) PHP_BINARY, '-d', 'display_errors=stderr', $bin, ...$args],
            $descriptors,
            $pipes,
            \dirname($bin),
            $childEnv
        );
        $this->assertIsResource($process);

        \fwrite($pipes[0], $stdin);
        \fclose($pipes[0]);
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        // Reap-first, then judge: proc_close blocks until exit and returns
        // the status (single-shot proc_get_status after pipe EOF is racy).
        $status = \proc_close($process);

        return ['status' => $status, 'out' => $out, 'err' => $err];
    }

    public function testVersionFlagPrintsOneLineAndExitsZero(): void
    {
        $result = $this->runCli(['--version']);

        $this->assertSame(0, $result['status']);
        $this->assertSame('', $result['err']);
        $this->assertMatchesRegularExpression('/^\S+\R$/', $result['out']);
        $this->assertSame(\rtrim($result['out']), \SugarCraft\Mail\cliVersion());
    }

    public function testBareInvocationPrintsHelp(): void
    {
        $result = $this->runCli([]);

        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('Usage:', $result['out']);
    }

    public function testZeroPartMessageIsRefusedBeforeRender(): void
    {
        // `--render </dev/null` (empty closed non-tty stdin, no --body, no
        // --html, no attachment) used to exit 0 with a header-only document
        // no receiver accepts as mail. The composition check refuses it now,
        // before the render branch and before any transport is consulted.
        $result = $this->runCli(
            ['--from', 'a@b.c', '--to', 'you@example.com', '--subject', 'Hollow', '--render'],
            env: ['CANDY_MAIL_SMTP_HOST' => 'smtp.invalid.test']
        );

        $this->assertSame(1, $result['status'], $result['err']);
        $this->assertSame('', $result['out']);
        $this->assertStringContainsString('no body', $result['err']);
    }

    public function testStdinAccumulationIsCappedAndRefusedLoudly(): void
    {
        // The cap guard: a pathological producer (the `yes | candy-mail`
        // shape) is refused past 4 MiB instead of draining sender memory,
        // and the refusal names the limit rather than silently truncating.
        $flood = str_repeat('x', 4 * 1024 * 1024 + 8192);
        $result = $this->runCli(
            ['--from', 'a@b.c', '--to', 'you@example.com', '--render'],
            $flood,
            env: ['CANDY_MAIL_SMTP_HOST' => 'smtp.invalid.test']
        );

        $this->assertSame(1, $result['status'], $result['err']);
        $this->assertStringContainsString('4194304', $result['err']);
        $this->assertStringNotContainsString('MIME-Version', $result['out']);
    }

    public function testEnvironmentSenderPipelineRendersInsteadOfShowingHelp(): void
    {
        // The M-5 reproduction, verbatim: an env-provided sender plus --to
        // must reach the send path. --render keeps it network-free while
        // still proving the gate fired.
        $result = $this->runCli(
            ['--to', 'you@example.com', '--subject', 'Ping', '--render'],
            'body from stdin',
            ['CANDY_MAIL_FROM' => 'env-sender@example.com']
        );

        $this->assertSame(0, $result['status'], $result['err']);
        $this->assertStringNotContainsString('Usage:', $result['out']);
        $this->assertStringContainsString('env-sender@example.com', $result['out']);
        $this->assertStringContainsString('you@example.com', $result['out']);
        $this->assertStringContainsString('Subject: Ping', $result['out']);

        // The rendered MIME must be self-consistent: every part opener uses
        // the declared boundary (the C-1 defect family, now pinned at CLI
        // level too).
        $this->assertSame(1, \preg_match('/boundary="([^"]+)"/', $result['out'], $declared));
        $boundary = $declared[1];
        $this->assertStringContainsString('--' . $boundary, $result['out']);
        $this->assertStringNotContainsString(
            '--' . \bin2hex((string) random_bytes(16)),
            $result['out'],
            'no undeclared boundary tokens may appear'
        );
    }

    public function testUnknownOptionExitsOneAndNamesTheOffender(): void
    {
        $result = $this->runCli(['--from', 'a@b.c', '--to', 'c@d.e', '--frobnicate', '--body', 'x']);

        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('frobnicate', $result['err']);
    }

    public function testFlagValueFormsFlowThroughToTheRenderedMessage(): void
    {
        // M-6 reproduction: `--to=x@y` used to surface a raw TypeError.
        $result = $this->runCli([
            '--from=a@b.c', '--to=x@y.z', '--to=p@q.z', '--subject=S', '--body=B', '--render',
        ]);

        $this->assertSame(0, $result['status'], $result['err']);
        $this->assertStringContainsString('x@y.z', $result['out']);
        $this->assertStringContainsString('p@q.z', $result['out']);
        $this->assertStringNotContainsString('TypeError', $result['err']);
    }

    public function testInvalidPortIsRefusedBeforeAnyConnection(): void
    {
        $result = $this->runCli(
            ['--from', 'a@b.c', '--to', 'c@d.e', '--body', 'x'],
            '',
            ['CANDY_MAIL_SMTP_HOST' => 'smtp.invalid.example', 'CANDY_MAIL_SMTP_PORT' => '99999']
        );

        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('port', $result['err']);
    }

    public function testDispatchArmsTheUserLocaleBeforeAnythingCanFail(): void
    {
        $src = (string) \file_get_contents((string) \realpath(__DIR__ . '/../bin/candy-mail'));

        // Structural order-pin: setLocale(T::detect()) must run before the
        // first parseArgs() throw path, or the first error would be English
        // whatever the user's locale is.
        $armed   = \strpos($src, 'T::setLocale(');
        $parseAt = \strpos($src, 'parseArgs($argv)');
        $this->assertNotFalse($armed);
        $this->assertNotFalse($parseAt);
        $this->assertLessThan($parseAt, $armed);
    }

    public function testUnknownOptionMessageFollowsTheUserLocale(): void
    {
        $de = require __DIR__ . '/../lang/de.php';
        $expected = \str_replace(
            '{message}',
            \str_replace('{option}', '--nope', $de['cli.unknown_option']),
            $de['cli.error'],
        );

        $result = $this->runCli(['--nope'], '', ['LANG' => 'de_DE.UTF-8']);

        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString($expected, $result['err']);
        $this->assertStringNotContainsString('Unknown option', $result['err']);
    }

    public function testMessagesStayEnglishWithoutALocaleEnv(): void
    {
        $result = $this->runCli(['--nope']);

        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('Unknown option: --nope', $result['err']);
    }

    /**
     * Build a throwaway directory tree from a ['relative path' => contents]
     * map and return its root. The caller removes it with removeTree().
     *
     * @param array<string, string> $files
     */
    private function makeTree(array $files): string
    {
        $root = \sys_get_temp_dir() . '/candy-mail-bin-' . \uniqid('', true);
        foreach ($files as $relative => $contents) {
            $full = $root . '/' . $relative;
            if (!\is_dir(\dirname($full))) {
                $this->assertNotFalse(\mkdir(\dirname($full), 0777, true));
            }
            \file_put_contents($full, $contents);
        }

        return $root;
    }

    private function removeTree(string $root): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? \rmdir($item->getPathname()) : \unlink($item->getPathname());
        }
        \rmdir($root);
    }

    public function testBootstrapFindsTheConsumerProjectAutoloaderInAVendorLayout(): void
    {
        // C1 behaviour pin: composer installs bins as symlinks, so at runtime
        // __DIR__ is <project>/vendor/sugarcraft/candy-mail/bin — the package
        // local ../vendor does not exist there and the old bare require
        // fataled for every consumer install. The ladder must land on the
        // project's vendor/autoload.php; the fake autoloader marks the hit
        // and ends the process from inside the require.
        $binSource = (string) \file_get_contents(\dirname(__DIR__) . '/bin/candy-mail');
        $root = $this->makeTree([
            'vendor/sugarcraft/candy-mail/bin/candy-mail' => $binSource,
            'vendor/autoload.php' => "<?php \\fwrite(\\STDERR, 'LADDER-HIT'); exit(0);\n",
        ]);

        try {
            $result = $this->runCli(['--version'], '', [], $root . '/vendor/sugarcraft/candy-mail/bin/candy-mail');

            $this->assertSame(0, $result['status'], $result['err']);
            $this->assertStringContainsString('LADDER-HIT', $result['err']);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testBootstrapPrefersThePackageLocalVendorWhenBothExist(): void
    {
        // Order pin (behavioural): a monorepo checkout carries BOTH a
        // package-local vendor and (via the same ladder arithmetic) an
        // outer autoload; the local one must win so the lib boots against
        // its own linked siblings.
        $binSource = (string) \file_get_contents(\dirname(__DIR__) . '/bin/candy-mail');
        $root = $this->makeTree([
            'vendor/sugarcraft/candy-mail/bin/candy-mail' => $binSource,
            'vendor/sugarcraft/candy-mail/vendor/autoload.php' => "<?php \\fwrite(\\STDERR, 'LOCAL'); exit(0);\n",
            'vendor/autoload.php' => "<?php \\fwrite(\\STDERR, 'OUTER'); exit(0);\n",
        ]);

        try {
            $result = $this->runCli(['--version'], '', [], $root . '/vendor/sugarcraft/candy-mail/bin/candy-mail');

            $this->assertSame(0, $result['status'], $result['err']);
            $this->assertStringContainsString('LOCAL', $result['err']);
            $this->assertStringNotContainsString('OUTER', $result['err']);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testBootstrapFailsLoudWhenNoAutoloadCandidateExists(): void
    {
        // Neither candidate exists: exit 1 with the named failure — never a
        // silent success and never an unlabelled fatal.
        $binSource = (string) \file_get_contents(\dirname(__DIR__) . '/bin/candy-mail');
        $root = $this->makeTree([
            'nowhere/candy-mail/bin/candy-mail' => $binSource,
        ]);

        try {
            $result = $this->runCli(['--version'], '', [], $root . '/nowhere/candy-mail/bin/candy-mail');

            $this->assertSame(1, $result['status']);
            $this->assertStringContainsString('cannot find composer autoload.php', $result['err']);
            $this->assertStringNotContainsString('Usage:', $result['out']);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testBootstrapLadderListsPackageLocalCandidateBeforeProjectCandidate(): void
    {
        $src = (string) \file_get_contents((string) \realpath(__DIR__ . '/../bin/candy-mail'));

        $packageLocal = \strpos($src, "/../vendor/autoload.php");
        $projectRoot = \strpos($src, "/../../../autoload.php");
        $this->assertNotFalse($packageLocal);
        $this->assertNotFalse($projectRoot);
        // Package-local first: a monorepo checkout must boot on its linked
        // siblings, not on whatever project happens to surround it.
        $this->assertLessThan($projectRoot, $packageLocal, 'candidate order drifted');
        $this->assertStringContainsString('cannot find composer autoload.php', $src);
    }

    // ---------------------------------------------------------------------
    // C5 (lib-reviews-1102): untrusted bytes must never reach the terminal
    // raw. Every stderr message choke in bin/candy-mail sweeps the composed
    // text through SugarCraft\Core\Util\Sanitize::untrusted(); these pins
    // run the real CLI as a child with hostile escape bytes on each input
    // surface — argv tokens, env var NAMES, and a pre-STARTTLS server
    // greeting — and assert the sequences are stripped while the human-
    // readable text survives (fail-visible wording, silent escapes).
    // ---------------------------------------------------------------------

    public function testHostileArgvBytesAreSanitizedBeforeHittingStderr(): void
    {
        // parseArgs refuses the unknown option with the raw argv token
        // interpolated; the OSC title-set + BEL inside it must not ride out.
        $result = $this->runCli(['--to', 'b@example.com', "--frobn\x1b]0;pwned\x07"]);

        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('Unknown option: --frobn', $result['err']);
        $this->assertStringNotContainsString("\x1b", $result['err']);
        $this->assertStringNotContainsString("\x07", $result['err']);
        $this->assertStringNotContainsString('pwned', $result['err'], 'OSC payload must not leak to the terminal');
    }

    public function testLegacyEnvVarNameChokeIsSanitizedInBootstrapSource(): void
    {
        // The POP_* deprecation warning echoes env var NAMES, and names are
        // attacker-settable — but PHP's own exec layer filters control bytes
        // out of environment names (measured: SGR/BEL/DEL/TAB-bearing names
        // never reach getenv intact via proc_open), so no child can deliver
        // one. The honest pin is therefore structural: the fwrite at the
        // legacy choke must wrap the composed string in Sanitize::untrusted.
        $src = (string) \file_get_contents((string) \realpath(__DIR__ . '/../bin/candy-mail'));

        // CM-13 routed the sentence through cli.deprecated_env (translated),
        // so the literal English no longer lives in the bin — anchor on the
        // Lang::t call that composes the warning instead.
        $pos = \strpos($src, "Lang::t('cli.deprecated_env'");
        $this->assertNotFalse($pos, 'legacy deprecation warning drifted out of the bin');
        $window = \substr($src, \max(0, $pos - 220), 320);
        $this->assertStringContainsString('Sanitize::untrusted(', $window, 'legacy-name choke lost its untrusted sweep');
        $this->assertStringNotContainsString('fwrite(STDERR, sprintf(', $window, 'raw legacy-name echo reintroduced');
    }

    public function testUsageBannerNameChokeIsSanitizedInBootstrapSource(): void
    {
        // help($bin) echoes argv[0] to STDOUT; execve lets any caller set
        // argv[0], so the banner name must pass through the untrusted sweep.
        $src = (string) \file_get_contents((string) \realpath(__DIR__ . '/../bin/candy-mail'));

        $this->assertStringContainsString('Sanitize::untrusted(basename($argv[0]))', $src, 'usage-banner name choke lost its sweep');
        $this->assertStringNotContainsString('= basename($argv[0]);', $src, 'raw argv[0] banner echo reintroduced');
    }

    public function testHostilePreStartTlsGreetingIsSanitizedOnSendFailure(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl not available');
        }

        $errno  = 0;
        $errstr = '';
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped("cannot bind loopback server: {$errstr}");
        }
        $name = \stream_socket_get_name($server, false);
        $port = (int) \substr((string) $name, \strrpos((string) $name, ':') + 1);

        $pid = \pcntl_fork();
        if ($pid === -1) {
            @\fclose($server);
            $this->markTestSkipped('pcntl_fork failed');
        }
        if ($pid === 0) {
            // Child: a refusal reply whose continuation line carries an OSC
            // title-set + BEL — attacker-controlled bytes BEFORE any TLS,
            // echoed verbatim into smtp.unexpected_response's {response}.
            $conn = @\stream_socket_accept($server, 3);
            if ($conn !== false) {
                @\fwrite($conn, "599-\x1b]0;pwned\x07host\r\n250 fake\r\n");
                @\fclose($conn);
            }
            @\fclose($server);
            // Hard-exit: skip inherited PHPUnit shutdown handlers (SmtpSecurityTest idiom).
            if (\function_exists('posix_kill')) {
                \posix_kill(\getmypid(), \SIGKILL);
            }
            exit(0);
        }
        @\fclose($server); // parent drives the client only; child owns accept()

        try {
            $result = $this->runCli(
                ['--to', 'b@example.com'],
                'hello',
                [
                    'CANDY_MAIL_FROM'            => 'a@example.com',
                    'CANDY_MAIL_SMTP_HOST'       => '127.0.0.1',
                    'CANDY_MAIL_SMTP_PORT'       => (string) $port,
                    'CANDY_MAIL_SMTP_REQUIRE_TLS' => '0',
                ]
            );

            $this->assertSame(1, $result['status']);
            $this->assertStringContainsString('599', $result['err']);
            $this->assertStringContainsString('host', $result['err']);
            $this->assertStringNotContainsString("\x1b", $result['err']);
            $this->assertStringNotContainsString("\x07", $result['err']);
            $this->assertStringNotContainsString('pwned', $result['err'], 'OSC payload must not ride the greeting to the terminal');
        } finally {
            $this->reapHostileServer($pid);
        }
    }

    public function testSendSuccessExitsZeroAfterDeliveredMail(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl not available');
        }

        $errno  = 0;
        $errstr = '';
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped("cannot bind loopback server: {$errstr}");
        }
        $name = \stream_socket_get_name($server, false);
        $port = (int) \substr((string) $name, \strrpos((string) $name, ':') + 1);

        $pid = \pcntl_fork();
        if ($pid === -1) {
            @\fclose($server);
            $this->markTestSkipped('pcntl_fork failed');
        }
        if ($pid === 0) {
            // Child: a plain (no-STARTTLS, no-extensions) fake that queues the
            // one message it is handed. Loopback only; never dials out.
            $conn = @\stream_socket_accept($server, 10);
            if ($conn !== false) {
                @\fwrite($conn, "220 fake ESMTP\r\n");
                $deadline = \microtime(true) + 15.0;
                $inData   = false;
                while (\microtime(true) < $deadline && !\feof($conn)) {
                    $line = \fgets($conn, 4096);
                    if ($line === false) {
                        break;
                    }
                    $trim = \rtrim($line, "\r\n");
                    if ($inData) {
                        if ($trim === '.') {
                            $inData = false;
                            @\fwrite($conn, "250 OK queued\r\n");
                        }
                        continue;
                    }
                    $up = \strtoupper($trim);
                    if (\str_starts_with($up, 'EHLO') || \str_starts_with($up, 'HELO')) {
                        @\fwrite($conn, "250 fake\r\n");
                    } elseif (\str_starts_with($up, 'MAIL') || \str_starts_with($up, 'RCPT')) {
                        @\fwrite($conn, "250 OK\r\n");
                    } elseif (\str_starts_with($up, 'DATA')) {
                        $inData = true;
                        @\fwrite($conn, "354 Send\r\n");
                    } elseif (\str_starts_with($up, 'QUIT')) {
                        @\fwrite($conn, "221 Bye\r\n");
                        break;
                    } else {
                        @\fwrite($conn, "250 OK\r\n");
                    }
                }
                @\fclose($conn);
            }
            @\fclose($server);
            // Hard-exit: skip inherited PHPUnit shutdown handlers (SmtpSecurityTest idiom).
            if (\function_exists('posix_kill')) {
                \posix_kill(\getmypid(), \SIGKILL);
            }
            exit(0);
        }
        @\fclose($server); // parent drives the client only; child owns accept()

        try {
            $result = $this->runCli(
                ['--to', 'b@example.com'],
                'hello body',
                [
                    'CANDY_MAIL_FROM'            => 'a@example.com',
                    'CANDY_MAIL_SMTP_HOST'       => '127.0.0.1',
                    'CANDY_MAIL_SMTP_PORT'       => (string) $port,
                    'CANDY_MAIL_SMTP_REQUIRE_TLS' => '0',
                ]
            );

            // The documented contract is '0 success, 1 any refusal'. Before
            // the fix main() fell off its end after a DELIVERED send: the
            // declared int return type turned that into a TypeError exit 255
            // printed after '✓ Email sent' — delivery succeeded, status lied.
            $this->assertSame(0, $result['status'], 'a delivered send must exit 0, not 255');
            $this->assertStringContainsString('Email sent', $result['out']);
            $this->assertStringNotContainsString('TypeError', $result['err']);
        } finally {
            $this->reapHostileServer($pid);
        }
    }

    public function testParseTimeTimeoutRefusesBeforeTransportSelection(): void
    {
        // CM-15 acceptance: with a Resend key configured, an out-of-range
        // --timeout used to ride silently (the SMTP branch never ran).
        $result = $this->runCli(
            ['--to', 'b@example.com', '--timeout=99999', '--render'],
            'hello',
            env: ['RESEND_API_KEY' => 're_lane_probe', 'CANDY_MAIL_FROM' => 'a@example.com'],
        );
        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('Timeout', $result['err']);
    }

    public function testResendLegNotesEveryIgnoredSmtpInput(): void
    {
        // CM-15 acceptance: choosing Resend with SMTP-scoped flags supplied
        // prints one note naming each ignored input instead of silence.
        $result = $this->runCli(
            ['--to', 'b@example.com', '--timeout=60', '--helo', 'relay.invalid', '--render'],
            'hello',
            env: ['RESEND_API_KEY' => 're_lane_probe', 'CANDY_MAIL_FROM' => 'a@example.com'],
        );
        $this->assertSame(0, $result['status'], 'stderr: ' . $result['err']);
        $this->assertStringContainsString('--timeout', $result['err']);
        $this->assertStringContainsString('--helo', $result['err']);
        $this->assertStringNotContainsString('--ca-file', $result['err']);
    }

    /**
     * CM-16 acceptance: with BOTH transports configured, --transport=smtp
     * must drive a real SMTP conversation — EHLO on the wire, not a curl
     * POST to api.resend.com. The child fake relay logs its RX lines; the
     * pin asserts the greeting path ran and the send completed.
     */
    public function testTransportSmtpOverrideDialsTheRelayNotTheApi(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl is required for the forked relay');
        }

        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, 'could not reserve a loopback port: ' . $errstr);
        $port = (int) \substr(\strrchr(\stream_socket_get_name($server, false), ':'), 1);
        $log  = \tempnam(\sys_get_temp_dir(), 'cm16relay');

        $pid = \pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }
        if ($pid === 0) {
            // Child: one-session fake relay. Answers the full dialogue and
            // logs every client line so the parent can prove the transport.
            $rx = '';
            $conn = \stream_socket_accept($server, 10.0);
            if ($conn !== false) {
                \fwrite($conn, "220 fake.relay\r\n");
                while (!\feof($conn)) {
                    $line = \fgets($conn);
                    if ($line === false) {
                        break;
                    }
                    $rx .= $line;
                    $up = \strtoupper(\trim($line));
                    if (\str_starts_with($up, 'EHLO') || \str_starts_with($up, 'HELO')) {
                        \fwrite($conn, "250-fake.relay greets\r\n250 HELP\r\n");
                    } elseif (\str_starts_with($up, 'MAIL') || \str_starts_with($up, 'RCPT')) {
                        \fwrite($conn, "250 OK\r\n");
                    } elseif (\str_starts_with($up, 'DATA')) {
                        \fwrite($conn, "354 go\r\n");
                        $buf = '';
                        while (!\feof($conn)) {
                            $l = \fgets($conn);
                            if ($l === false) {
                                break;
                            }
                            $buf .= $l;
                            if (\rtrim($l, "\r\n") === '.') {
                                break;
                            }
                        }
                        $rx .= $buf;
                        \fwrite($conn, "250 queued\r\n");
                    } elseif (\str_starts_with($up, 'QUIT')) {
                        \fwrite($conn, "221 bye\r\n");
                        break;
                    }
                }
                \fclose($conn);
            }
            \fclose($server);
            \file_put_contents($log, $rx);
            \posix_kill(\getmypid(), \SIGKILL);
            exit(0);
        }
        \fclose($server);

        try {
            $result = $this->runCli(
                ['--to', 'b@example.com', '--transport', 'smtp'],
                'hello',
                [
                    'CANDY_MAIL_FROM'             => 'a@example.com',
                    'CANDY_MAIL_SMTP_HOST'        => '127.0.0.1',
                    'CANDY_MAIL_SMTP_PORT'        => (string) $port,
                    'CANDY_MAIL_SMTP_REQUIRE_TLS' => '0',
                    'RESEND_API_KEY'              => 're_poison_the_ladder',
                ]
            );
        } finally {
            $deadline = \microtime(true) + 5.0;
            while (\microtime(true) < $deadline) {
                $status = 0;
                if (\pcntl_waitpid($pid, $status, \WNOHANG) === $pid || $status > 0) {
                    break;
                }
                \usleep(20000);
            }
            @\posix_kill($pid, \SIGKILL);
            \pcntl_waitpid($pid, $status);
        }

        $this->assertSame(0, $result['status'], 'the forced SMTP send must complete: ' . $result['err']);
        $this->assertStringNotContainsString('Resend', $result['err'], 'no ignored-inputs note — Resend was not chosen');
        $this->assertStringContainsString('Email sent', $result['out']);

        $deadline = \microtime(true) + 5.0;
        $rx = '';
        while (\microtime(true) < $deadline) {
            $rx = (string) @\file_get_contents($log);
            if (\str_contains($rx, 'QUIT')) {
                break;
            }
            \usleep(20000);
        }
        @\unlink($log);
        $this->assertMatchesRegularExpression('/^EHLO /mi', $rx, 'the wire conversation, not an API POST, carried the send');
        $this->assertStringContainsString('RCPT TO:<b@example.com>', $rx);
    }

    public function testRelayFailureAnnouncesTheWrapExactlyOnce(): void
    {
        // CM-13 era finding 013: a refused dial printed
        // 'Send failed: SMTP send failed: Cannot connect …' — the transport
        // names its own failure and the bin wrapped it a second time. The
        // bin now prints transport RuntimeExceptions bare.
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, 'could not reserve a loopback port: ' . $errstr);
        $port = (int) \substr(\strrchr(\stream_socket_get_name($server, false), ':'), 1);
        @\fclose($server); // closed on purpose: the dial below is refused

        $result = $this->runCli(
            ['--to', 'b@example.com'],
            'hello',
            [
                'CANDY_MAIL_FROM'             => 'a@example.com',
                'CANDY_MAIL_SMTP_HOST'        => '127.0.0.1',
                'CANDY_MAIL_SMTP_PORT'        => (string) $port,
                'CANDY_MAIL_SMTP_REQUIRE_TLS' => '0',
            ]
        );

        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('SMTP send failed:', $result['err']);
        $this->assertStringNotContainsString('Send failed: SMTP send failed:', $result['err'], 'the wrap must ride exactly once');
        $this->assertSame(
            1,
            \substr_count($result['err'], 'ailed:'),
            'one named failure per refused send, not a stacked pair'
        );
    }

    // ------------------------------------------------------------------
    // B4b (lib-reviews-1102 fix wave): M-B / M-C / M-G / M-P end to end.
    // ------------------------------------------------------------------

    public function testSwitchIsNeverStolenAsAFlagValue(): void
    {
        // M-G: `--subject --render` once swallowed the switch, mailed an
        // empty subject and silently skipped the render the user asked for.
        $result = $this->runCli(
            ['--from', 'a@b.c', '--to', 'c@d.e', '--subject', '--render'],
            'hello'
        );

        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('requires a value', $result['err']);
        $this->assertStringNotContainsString('MIME-Version', $result['out'], 'a refused parse must not render anything');
    }

    public function testTypoInASecuritySwitchRefusesTheSend(): void
    {
        // M-B: 'ture' once read as OFF and silently disabled REQUIRE_TLS.
        $result = $this->runCli(
            ['--from', 'a@b.c', '--to', 'c@d.e'],
            'hello',
            ['CANDY_MAIL_SMTP_HOST' => 'smtp.invalid.test', 'CANDY_MAIL_SMTP_REQUIRE_TLS' => 'ture']
        );

        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('invalid flag value', $result['err']);
        $this->assertStringContainsString('CANDY_MAIL_SMTP_REQUIRE_TLS', $result['err']);
    }

    public function testMissingSenderIsRefusedNotFabricated(): void
    {
        // M-C: the child used to mail from a forged 'unknown@localhost'.
        $result = $this->runCli(['--to', 'c@d.e'], 'hello');

        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('No sender address', $result['err']);
        $this->assertStringNotContainsString('unknown@localhost', $result['err'] . $result['out']);
    }

    public function testWhitespaceOnlyResendKeyDoesNotHijackTransportChoice(): void
    {
        // M-P: a RESEND_API_KEY of ' ' resolved truthy and bypassed the
        // whole SMTP configuration; a blank key now reads as unconfigured.
        $result = $this->runCli(
            ['--from', 'a@b.c', '--to', 'c@d.e'],
            'hello',
            ['RESEND_API_KEY' => ' ']
        );

        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('No transport configured', $result['err']);
    }

    public function testRequireProxyExitsWithTheMainStatus(): void
    {
        // M-T: under a require proxy the auto-dispatch used to no-op silently
        // (`php proxy.php --version` printed nothing with exit 0 — false
        // success). The supported embed shape is exit(main($argv)); pinned
        // here in both directions: the proxy must print and must fail loud.
        $binPath = (string) realpath(__DIR__ . '/../bin/candy-mail');
        $root    = $this->makeTree([
            'proxy.php' => '<?php require_once ' . var_export($binPath, true) . "; exit(\\SugarCraft\\Mail\\main(\$argv));\n",
        ]);
        try {
            $version = $this->runCli(['--version'], binOverride: $root . '/proxy.php');
            $this->assertSame(0, $version['status']);
            $this->assertNotSame('', trim($version['out']), 'a proxy must print, never silently no-op');

            $bad = $this->runCli(['--frobnicate'], binOverride: $root . '/proxy.php');
            $this->assertSame(1, $bad['status']);
            $this->assertStringContainsString('Unknown option', $bad['err']);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testRenderRefusesToPreviewOverABrokenTransportConfig(): void
    {
        // M-V: --render swallowed EVERY transport-build error and exited 0.
        // A typo in a security switch must still break the dry run.
        $result = $this->runCli(
            ['--from', 'a@b.c', '--to', 'c@d.e', '--render'],
            stdin: 'hi',
            env: ['CANDY_MAIL_SMTP_HOST' => 'smtp.invalid.test', 'CANDY_MAIL_SMTP_REQUIRE_TLS' => 'ture'],
        );
        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('invalid flag value', $result['err']);
        $this->assertStringNotContainsString('MIME-Version', $result['out']);
    }

    public function testRenderFallbackIsPinnedByTypeNotMessageText(): void
    {
        // PB-MAIL-37: the render fallback used to compare $e->getMessage()
        // against Lang::t('cli.no_transport') — a locale flip or a re-word of
        // that key silently rerouted every broken config into the localhost
        // preview. The absence is now its own type; pin the wiring so it
        // cannot regress back into text matching.
        $bin = (string) file_get_contents(__DIR__ . '/../bin/candy-mail');
        $this->assertStringContainsString("new MissingTransportConfig(Lang::t('cli.no_transport'))", $bin);
        $this->assertStringContainsString('catch (MissingTransportConfig)', $bin);
        $this->assertStringNotContainsString("!== Lang::t('cli.no_transport')", $bin);
    }

    public function testRenderStampsTheResendDivergence(): void
    {
        // M-V: Resend posts a JSON payload; the MIME preview must say so
        // instead of certifying a wire format that never ships.
        $result = $this->runCli(
            ['--from', 'a@b.c', '--to', 'c@d.e', '--render'],
            stdin: 'hi',
            env: ['RESEND_API_KEY' => 're_sealed_off'],
        );
        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('not what ships', $result['err']);
        $this->assertStringContainsString('multipart/mixed', $result['out']);
    }

    public function testRenderStampsThe8BitDivergenceOnlyForRawHighBytes(): void
    {
        // CM-46: the unconnected preview cannot know the relay's 8BITMIME
        // stance, so an 8-bit body that renders as-is here ships
        // quoted-printable to a relay without the extension. The note must
        // fire on raw 8-bit bytes in the document — and NOT on content that
        // the renderer already encoded 7-bit-safe.
        $raw = $this->runCli(
            ['--from', 'a@b.c', '--to', 'c@d.e', '--render'],
            stdin: "Grüße\n",
        );
        $this->assertSame(0, $raw['status']);
        $this->assertStringContainsString('Content-Transfer-Encoding: 8bit', $raw['out']);
        $this->assertStringContainsString('8BITMIME', $raw['err'], 'raw 8-bit preview declares the wire divergence');

        $encoded = $this->runCli(
            ['--from', 'a@b.c', '--to', 'c@d.e', '--render', '--subject', 'Grüße'],
            stdin: "hi\n",
        );
        $this->assertSame(0, $encoded['status']);
        $this->assertStringNotContainsString('8BITMIME', $encoded['err'], 'an RFC 2047-encoded subject is 7-bit-safe on every relay');
    }

    public function testHeloFlagSetsTheMessageIdIdentity(): void
    {
        // M-Q: the Message-ID host leaked the internal machine name with no
        // reachable seam; --helo wires what the old docblock falsely claimed.
        $result = $this->runCli(
            ['--from', 'a@b.c', '--to', 'c@d.e', '--render', '--helo', 'mail.example.org'],
            stdin: 'hi',
            env: ['CANDY_MAIL_SMTP_HOST' => 'smtp.example.test'],
        );
        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('@mail.example.org>', $result['out']);
    }

    public function testRenderFallbackPreviewHonoursHeloWithNoTransportConfigured(): void
    {
        // CM-30: with no transport the preview fell back to a bare
        // SmtpTransport('localhost'), dropping --helo and
        // CANDY_MAIL_SMTP_HELO entirely — the rendered Message-ID carried the
        // machine identity a configured send would never use. Both seams now
        // thread into the fallback renderer.
        $flag = $this->runCli(
            ['--from', 'a@b.c', '--to', 'c@d.e', '--render', '--helo', 'mail.example.com'],
            stdin: 'hi',
        );
        $this->assertSame(0, $flag['status']);
        $this->assertStringContainsString('@mail.example.com>', $flag['out']);

        $env = $this->runCli(
            ['--from', 'a@b.c', '--to', 'c@d.e', '--render'],
            stdin: 'hi',
            env: ['CANDY_MAIL_SMTP_HELO' => 'env.example.org'],
        );
        $this->assertSame(0, $env['status']);
        $this->assertStringContainsString('@env.example.org>', $env['out']);
    }

    public function testTimeoutFlagIsBoundedLoudly(): void
    {
        $result = $this->runCli(
            ['--from', 'a@b.c', '--to', 'c@d.e', '--timeout', '0', '--render'],
            stdin: 'hi',
            env: ['CANDY_MAIL_SMTP_HOST' => 'smtp.example.test'],
        );
        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('Timeout', $result['err']);
    }

    public function testCaFileMustBeAReadableRegularFile(): void
    {
        // M-AA: the README sells withCaFile() as THE private-CA remedy; the
        // CLI must refuse an unusable path at configuration, not mid-handshake.
        $result = $this->runCli(
            ['--from', 'a@b.c', '--to', 'c@d.e', '--ca-file', '/nonexistent-ca-bundle.pem'],
            stdin: 'hi',
            env: ['CANDY_MAIL_SMTP_HOST' => 'smtp.invalid.test'],
        );
        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('not a readable regular file', $result['err']);
    }

    public function testCcOnlyInvocationMeansToSendNotHelp(): void
    {
        // qa m1: the help gate ignored cc/bcc, so a cc-only send printed the
        // banner with exit 0 — silence-as-progress. It now falls through to
        // the loud missing---to refusal.
        $result = $this->runCli(
            ['--cc', 'x@y.z'],
            stdin: 'hi',
            env: ['CANDY_MAIL_FROM' => 'me@example.com'],
        );
        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('No --to recipient', $result['err']);
        $this->assertStringNotContainsString('CandyMail CLI', $result['out']);
    }

    public function testOnRosterLegacyVarStillAnnouncesItself(): void
    {
        // The POP_* -> CANDY_MAIL_* bridge keeps working for one release and
        // says so — but only for vars the program actually consumes (N1).
        $result = $this->runCli(['--version'], extraEnv: ['POP_SMTP_HOST' => 'smtp.example.test']);

        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('POP_SMTP_HOST is deprecated', $result['err']);
    }

    public function testOffRosterLegacyVarStaysSilent(): void
    {
        // POP_KERNEL_PANIC maps to a CANDY_MAIL_KERNEL_PANIC nothing ever
        // reads; the old code printed a warning inventing a migration target.
        // Silence is the honest behavior for an inert import.
        $result = $this->runCli(['--version'], extraEnv: ['POP_KERNEL_PANIC' => '1']);

        $this->assertSame(0, $result['status']);
        $this->assertStringNotContainsString('KERNEL_PANIC', $result['err']);
    }

    public function testLegacyPopSmtpTlsAnnouncesItsRealSuccessor(): void
    {
        // CM-35: POP_SMTP_TLS is off-roster by suffix, but unlike POP_KERNEL_
        // PANIC it has a REAL successor — CANDY_MAIL_SMTP_REQUIRE_TLS. The old
        // whole-prefix import parked its value on a name nothing reads and the
        // roster filter kept the bridge silent: a security knob that stopped
        // working while pretending it never existed.
        $result = $this->runCli(['--version'], extraEnv: ['POP_SMTP_TLS' => '0']);

        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('POP_SMTP_TLS is deprecated', $result['err']);
        $this->assertStringContainsString('CANDY_MAIL_SMTP_REQUIRE_TLS', $result['err']);
    }

    public function testLegacyPopSmtpTlsValueCarriesToRequireTlsAndNewNameWins(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl not available');
        }

        $errno  = 0;
        $errstr = '';
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped("cannot bind loopback server: {$errstr}");
        }
        $name = \stream_socket_get_name($server, false);
        $port = (int) \substr((string) $name, \strrpos((string) $name, ':') + 1);

        $pid = \pcntl_fork();
        if ($pid === -1) {
            @\fclose($server);
            $this->markTestSkipped('pcntl_fork failed');
        }
        if ($pid === 0) {
            // Child: plain relay, advertises no STARTTLS (see the success-
            // banner test for the dialogue shape this mirrors). Serves the
            // carry leg and the new-name-wins leg: two connections.
            for ($round = 0; $round < 2; $round++) {
                $conn = @\stream_socket_accept($server, 10);
                if ($conn === false) {
                    break;
                }
                @\fwrite($conn, "220 fake ESMTP\r\n");
                $deadline = \microtime(true) + 15.0;
                $inData   = false;
                while (\microtime(true) < $deadline && !\feof($conn)) {
                    $line = \fgets($conn, 4096);
                    if ($line === false) {
                        break;
                    }
                    $trim = \rtrim($line, "\r\n");
                    if ($inData) {
                        if ($trim === '.') {
                            $inData = false;
                            @\fwrite($conn, "250 OK queued\r\n");
                        }
                        continue;
                    }
                    $up = \strtoupper($trim);
                    if (\str_starts_with($up, 'EHLO') || \str_starts_with($up, 'HELO')) {
                        @\fwrite($conn, "250 fake\r\n");
                    } elseif (\str_starts_with($up, 'MAIL') || \str_starts_with($up, 'RCPT')) {
                        @\fwrite($conn, "250 OK\r\n");
                    } elseif (\str_starts_with($up, 'DATA')) {
                        $inData = true;
                        @\fwrite($conn, "354 Send\r\n");
                    } elseif (\str_starts_with($up, 'QUIT')) {
                        @\fwrite($conn, "221 Bye\r\n");
                        break;
                    } else {
                        @\fwrite($conn, "250 OK\r\n");
                    }
                }
                @\fclose($conn);
            }
            @\fclose($server);
            if (\function_exists('posix_kill')) {
                \posix_kill(\getmypid(), \SIGKILL);
            }
            exit(0);
        }
        @\fclose($server);

        try {
            // Carry leg: POP_SMTP_TLS=0 alone must reach REQUIRE_TLS and let
            // the plaintext send succeed (old behaviour: silently ignored,
            // requireTls stayed on, send refused).
            $carried = $this->runCli(
                ['--to', 'b@example.com'],
                'hello body',
                [
                    'CANDY_MAIL_FROM'      => 'a@example.com',
                    'CANDY_MAIL_SMTP_HOST' => '127.0.0.1',
                    'CANDY_MAIL_SMTP_PORT' => (string) $port,
                ],
                extraEnv: ['POP_SMTP_TLS' => '0']
            );
            $this->assertSame(0, $carried['status'], 'the carried legacy 0 must switch REQUIRE_TLS off');
            $this->assertStringContainsString('Email sent', $carried['out']);
            $this->assertStringContainsString('POP_SMTP_TLS is deprecated', $carried['err']);

            // New-name-wins leg: CANDY_MAIL_SMTP_REQUIRE_TLS=1 beside the
            // legacy 0 must keep the floor ON — same relay, refusal.
            $fresh = $this->runCli(
                ['--to', 'b@example.com'],
                'hello body',
                [
                    'CANDY_MAIL_FROM'             => 'a@example.com',
                    'CANDY_MAIL_SMTP_HOST'        => '127.0.0.1',
                    'CANDY_MAIL_SMTP_PORT'        => (string) $port,
                    'CANDY_MAIL_SMTP_REQUIRE_TLS' => '1',
                ],
                extraEnv: ['POP_SMTP_TLS' => '0']
            );
            $this->assertSame(1, $fresh['status'], 'the new name must win over the legacy carry');
            $this->assertStringNotContainsString('Email sent', $fresh['out']);
        } finally {
            $this->reapHostileServer($pid);
        }
    }

    private function reapHostileServer(int $pid): void
    {
        $deadline = \microtime(true) + 5.0;
        do {
            $res = \pcntl_waitpid($pid, $status, \WNOHANG);
            if ($res === $pid || $res === -1) {
                return;
            }
            \usleep(20_000);
        } while (\microtime(true) < $deadline);

        if (\function_exists('posix_kill')) {
            @\posix_kill($pid, \SIGKILL);
        }
        \pcntl_waitpid($pid, $status);
    }
}
