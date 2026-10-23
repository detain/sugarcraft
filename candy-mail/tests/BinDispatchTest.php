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
     * @return array{status: int, out: string, err: string}
     */
    private function runCli(array $args, string $stdin = '', array $env = []): array
    {
        $bin = \realpath(__DIR__ . '/../bin/candy-mail');
        $this->assertIsString($bin);

        $childEnv = [];
        foreach (self::NEUTRAL_ENV as $name) {
            if (isset($env[$name])) {
                $childEnv[$name] = $env[$name];
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
}
