<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\MdView\HttpFetch;
use SugarCraft\MdView\RenderCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The URL input door (finding 008). Everything talks to a loopback php -S
 * router (tests/fixtures/http-fetch-router.php) — the suite opens zero
 * outbound connections, and the only non-server URL probed is a closed
 * loopback port.
 */
final class HttpFetchTest extends TestCase
{
    /** @var resource|\stdClass|null */
    private static $serverProcess = null;

    private static ?int $serverPort = null;

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            // Reap-bounded: TERM, then close the handle — the dev server
            // exits on signal; proc_close must run to release it.
            proc_close(self::$serverProcess);
        }
        self::$serverProcess = null;
        self::$serverPort    = null;
    }

    /** Boots the loopback server once for the class; returns its port. */
    private static function port(): int
    {
        if (self::$serverPort !== null) {
            return self::$serverPort;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            if ($probe === false) {
                continue;
            }
            $name = (string) stream_socket_get_name($probe, false);
            $port = (int) substr($name, (int) strrpos($name, ':') + 1);
            fclose($probe);

            $proc = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/http-fetch-router.php'],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', '/dev/null', 'w'],
                    2 => ['file', '/dev/null', 'w'],
                ],
                $pipes,
            );
            if (!is_resource($proc)) {
                continue;
            }

            // Readiness: poll /ping for pong (the boot takes milliseconds;
            // the deadline keeps a broken spawn a failure, never a hang).
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                $answer = @file_get_contents('http://127.0.0.1:' . $port . '/ping');
                if ($answer === 'pong') {
                    self::$serverProcess = $proc;
                    self::$serverPort    = $port;

                    return $port;
                }
                if (proc_get_status($proc)['running'] === false) {
                    break;
                }
                usleep(50_000);
            }

            proc_terminate($proc);
            proc_close($proc);
        }

        self::markTestSkipped('could not boot the loopback fetch fixture');
    }

    private static function url(string $path): string
    {
        return 'http://127.0.0.1:' . self::port() . $path;
    }

    public function testIsRemoteNamesTheTwoDoorsOnly(): void
    {
        $this->assertTrue(HttpFetch::isRemote('http://example.invalid/doc.md'));
        $this->assertTrue(HttpFetch::isRemote('HTTPS://example.invalid/doc.md'));
        $this->assertFalse(HttpFetch::isRemote('README.md'));
        $this->assertFalse(HttpFetch::isRemote('./docs/x.md'));
        $this->assertFalse(HttpFetch::isRemote('file:///etc/hostname'));
        $this->assertFalse(HttpFetch::isRemote('ftp://example.invalid/x'));
        $this->assertFalse(HttpFetch::isRemote('https:example.invalid'));
    }

    public function testFetchRefusesForeignSchemesWithoutReadingAnything(): void
    {
        // The scheme gate runs BEFORE any transport call: file:/// must not
        // touch the disk even for a world-readable path.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only http and https URLs are fetched');
        HttpFetch::fetch('file:///etc/hostname');
    }

    public function testFetchServesTheRemoteDocument(): void
    {
        $body = HttpFetch::fetch(self::url('/x.md'));
        $this->assertStringContainsString('# Fetched Doc', $body);
    }

    public function testFetchRefusesEmptyBody(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('the body is empty');
        HttpFetch::fetch(self::url('/empty'));
    }

    public function testFetchRefusesBinaryBodyThroughTheSameGate(): void
    {
        // Hostile remote bytes face the document gate, not a bypass.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a text file');
        HttpFetch::fetch(self::url('/bin.md'));
    }

    public function testFetchRefusesNon2xxAnswers(): void
    {
        try {
            HttpFetch::fetch(self::url('/gone'));
            $this->fail('expected a named fetch refusal');
        } catch (\InvalidArgumentException $refusal) {
            $this->assertStringContainsString('cannot fetch', $refusal->getMessage());
            $this->assertStringContainsString('404', $refusal->getMessage());
        }
    }

    public function testFetchRefusesAnExhaustedRedirectChain(): void
    {
        // /loop 302s to itself forever; max_redirects bounds the chase and
        // the un-followed 3xx answer lands on the status gate (a hang here
        // would be the bug — completing AT ALL is part of the proof).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot fetch');
        HttpFetch::fetch(self::url('/loop'));
    }

    public function testFetchRefusesOverCapBodyWithoutSlurpingIt(): void
    {
        // The read is capped at MAX+1 bytes, so a 64 MiB + 1 answer is
        // refused by name, not materialised in full (the document-lane law
        // the stdin leg already practices).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds 67108864 bytes');
        HttpFetch::fetch(self::url('/huge.md'));
    }

    public function testFetchRefusesTlsHandshakeAgainstPlainServer(): void
    {
        // TLS verification is ON: an https:// attempt at the plain loopback
        // server must die at the handshake, never render the wreck.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot fetch');
        HttpFetch::fetch('https://127.0.0.1:' . self::port() . '/x.md');
    }

    public function testServerSurvivesTheTlsGarbageAndStillServes(): void
    {
        // Order-truth probe: the TLS leg above must not have killed the
        // fixture (proc_open children leave a corpse behind when they die —
        // proc_get_status reads ours before any new request).
        $port = self::port();
        $proc = self::$serverProcess;
        $this->assertIsResource($proc, 'fixture handle recorded by port()');
        $status = proc_get_status($proc);
        $this->assertTrue($status['running'], 'loopback fixture still alive');
        $this->assertStringContainsString('# Fetched Doc', HttpFetch::fetch('http://127.0.0.1:' . $port . '/x.md'));
    }

    public function testFetchRefusesUnreachablePort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot fetch');
        HttpFetch::fetch('http://127.0.0.1:1/x.md');
    }

    public function testRefusalRedactsUrlCredentials(): void
    {
        // A pasted https://user:pass@host URL must never come back wearing
        // its secrets on the terminal.
        try {
            HttpFetch::fetch('http://mduser:sup3rs3cret@127.0.0.1:' . self::port() . '/gone');
            $this->fail('expected a named fetch refusal');
        } catch (\InvalidArgumentException $refusal) {
            $this->assertStringNotContainsString('sup3rs3cret', $refusal->getMessage());
            $this->assertStringNotContainsString('mduser', $refusal->getMessage());
            $this->assertStringContainsString('***@127.0.0.1', $refusal->getMessage());
        }
    }

    public function testExecuteRendersAFetchedUrlArgument(): void
    {
        [$rc, $out, $errors] = $this->executeWithFileArg(self::url('/x.md'));
        $this->assertSame(Command::SUCCESS, $rc);
        // Body text survives the plain renderer verbatim; the heading may
        // restyle, so the sentence proves the fetched bytes are the document.
        $this->assertStringContainsString('remote body', $out);
        $this->assertSame('', $errors);
    }

    public function testExecuteRefusesFailedFetchOnTheUsageChannel(): void
    {
        [$rc, $out, $errors] = $this->executeWithFileArg(self::url('/gone'));
        $this->assertSame(Command::INVALID, $rc);
        $this->assertSame('', $out, 'the error never lands in the document stream');
        $this->assertStringContainsString('cannot fetch', $errors);
    }

    /**
     * Full execute() leg with both output channels captured (the shared
     * RenderCommandTest helpers deliberately ignore the raw payload).
     *
     * @return array{0:int,1:string,2:string} rc, written document, error lines
     */
    private function executeWithFileArg(string $fileArg): array
    {
        $input = $this->createMock(InputInterface::class);
        $input->method('getArgument')->willReturnCallback(
            static fn (string $name): mixed => $name === 'file' ? $fileArg : null
        );
        $input->method('getOption')->willReturnMap([
            ['theme-config', ''],
            ['style', null],
            ['theme', 'plain'],
            ['width', 0],
            ['pager', false],
            ['no-hyperlinks', false],
        ]);

        $payload = '';
        $errors  = '';
        $output  = $this->createMock(OutputInterface::class);
        $output->method('write')->willReturnCallback(
            static function ($messages) use (&$payload): void {
                $payload .= (string) $messages;
            }
        );
        $output->method('writeln')->willReturnCallback(
            static function ($messages) use (&$errors): void {
                $errors .= (string) $messages;
            }
        );

        $method = new ReflectionMethod(RenderCommand::class, 'execute');
        $method->setAccessible(true);

        return [$method->invoke(new RenderCommand(), $input, $output), $payload, $errors];
    }
}
