<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards the inline `Banner` middleware shipped in
 * `examples/hello-server.php` against interface drift.
 *
 * The example is a runnable smoke test wired up via sshd's
 * `ForceCommand`; if `Banner::handle()` stops matching the
 * {@see \SugarCraft\Wish\Middleware} contract, the example fatals the
 * moment the transport invokes it. That exact regression once shipped
 * because `Banner::handle()` was missing the `Context $ctx` parameter,
 * and nothing in the suite exercised the example.
 *
 * `php -l` cannot catch this: the mismatch is a class-declaration
 * incompatibility (checked when the class is linked against the
 * interface), not a parse error, and the single-file linter never
 * loads the interface. So the real test loads the example's `Banner`
 * in a child process and actually invokes `handle()` against the real
 * interface — see `_fixtures/load-banner.php` for why a subprocess is
 * required (the incompatibility is an uncatchable compile-time fatal).
 */
final class ExampleHelloServerBannerTest extends TestCase
{
    private const EXAMPLE = __DIR__ . '/../examples/hello-server.php';
    private const FIXTURE = __DIR__ . '/_fixtures/load-banner.php';

    public function testHelloServerExampleLintsClean(): void
    {
        [$code, $out] = $this->runPhp(['-l', self::EXAMPLE]);
        $this->assertSame(0, $code, "php -l on hello-server.php failed:\n{$out}");
    }

    public function testBannerMiddlewareIsInterfaceCompatibleAndInvocable(): void
    {
        $this->assertFileExists(self::FIXTURE);

        [$code, $out, $err] = $this->runPhp([self::FIXTURE, self::EXAMPLE]);

        // Exit 255 == class-declaration fatal (handle() signature no
        // longer compatible with Middleware). Any non-zero code means
        // the example's Banner can't be defined or invoked cleanly.
        $this->assertSame(
            0,
            $code,
            "Banner harness exited {$code}; the example's middleware is not "
            . "interface-compatible or fatals on invocation.\nstdout:\n{$out}\nstderr:\n{$err}",
        );
        // Proves handle() ran to completion, echoed the banner, and
        // called the $next continuation with (Context, Session).
        $this->assertStringContainsString('Hello, tester', $out, 'Banner did not render its greeting.');
        $this->assertStringContainsString('BANNER-OK', $out, 'Banner harness did not report success.');
    }

    /**
     * Run PHP with the given argv and return [exitCode, stdout, stderr].
     *
     * @param list<string> $args
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
