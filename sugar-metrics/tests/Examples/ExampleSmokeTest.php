<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests\Examples;

use PHPUnit\Framework\TestCase;

/**
 * Guards the runnable examples shipped with sugar-metrics.
 *
 * `examples/ssh-server-with-metrics.php` once shipped an inline
 * middleware whose `handle()` was missing the `Context $ctx` parameter
 * required by {@see \SugarCraft\SshServer\Middleware} — a copy-paste
 * fatal for anyone following the README integration story. `php -l`
 * cannot catch that class of breakage (the incompatibility is a
 * class-declaration-time error, not a parse error), so the real test
 * loads the example's middleware in a child process and invokes it
 * against the real interface — see `_fixtures/load-middleware-example.php`
 * for why the subprocess is required.
 */
final class ExampleSmokeTest extends TestCase
{
    private const SSH_EXAMPLE = __DIR__ . '/../../examples/ssh-server-with-metrics.php';
    private const SHOWCASE_EXAMPLE = __DIR__ . '/../../examples/showcase.php';
    private const FIXTURE = __DIR__ . '/_fixtures/load-middleware-example.php';
    private const SCRATCH_TEXTFILE = '/tmp/sugar-ssh-server-metrics.prom';

    public function testSshServerExampleLintsClean(): void
    {
        [$code, $out] = $this->runPhp(['-l', self::SSH_EXAMPLE]);
        $this->assertSame(0, $code, "php -l on ssh-server-with-metrics.php failed:\n{$out}");
    }

    public function testShowcaseExampleLintsClean(): void
    {
        [$code, $out] = $this->runPhp(['-l', self::SHOWCASE_EXAMPLE]);
        $this->assertSame(0, $code, "php -l on showcase.php failed:\n{$out}");
    }

    public function testShowcaseExampleRunsToCompletion(): void
    {
        [$code, $out, $err] = $this->runPhp([self::SHOWCASE_EXAMPLE]);
        $this->assertSame(0, $code, "showcase.php exited {$code}.\nstdout:\n{$out}\nstderr:\n{$err}");
        $this->assertStringContainsString('=== Counters ===', $out, 'showcase.php did not print its totals.');
    }

    public function testSshServerExampleMiddlewareIsInterfaceCompatible(): void
    {
        $this->assertFileExists(self::FIXTURE);

        [$code, $out, $err] = $this->runPhp([self::FIXTURE, self::SSH_EXAMPLE]);

        // Exit 255 == class-declaration fatal (handle() signature no
        // longer compatible with Middleware). Any non-zero code means the
        // example's middleware can't be defined or invoked cleanly.
        $this->assertSame(
            0,
            $code,
            "middleware harness exited {$code}; the example's HelloMetrics is not "
            . "interface-compatible or fatals on invocation.\nstdout:\n{$out}\nstderr:\n{$err}",
        );
        $this->assertStringContainsString('Hello, tester', $out, 'HelloMetrics did not render its greeting.');
        $this->assertStringContainsString('HELLO-METRICS-OK', $out, 'HelloMetrics harness did not report success.');
    }

    protected function tearDown(): void
    {
        // The fixture's eval'd registry construction hands the child a
        // PrometheusFileBackend whose destructor writes this scratch path.
        if (\is_file(self::SCRATCH_TEXTFILE)) {
            \unlink(self::SCRATCH_TEXTFILE);
        }
    }

    /**
     * Run PHP with the given argv and return [exitCode, stdout, stderr].
     *
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runPhp(array $args): array
    {
        $argv = [PHP_BINARY, ...$args];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = \proc_open($argv, $descriptors, $pipes);
        $this->assertIsResource($proc, 'proc_open failed for: ' . \implode(' ', $argv));

        \fclose($pipes[0]);
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $code = \proc_close($proc);

        return [$code, $out, $err];
    }
}
