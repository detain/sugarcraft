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
    private function runCli(array $args, string $stdin = '', array $env = [], ?string $binOverride = null): array
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
}
