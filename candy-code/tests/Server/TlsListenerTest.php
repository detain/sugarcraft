<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Server;
use SugarCraft\Code\Server\ServerConfig;

/**
 * C-WEB-5.a: `serve --tls-cert/--tls-key` — built-in TLS on the PUBLIC
 * listener. The design wrapped {@see \SugarCraft\Code\Server\Listener} in
 * `React\Socket\SecureServer` inside {@see Server::start()} only, so the
 * control socket and the Workspace gateway stay plaintext by construction;
 * these tests hold that line: the wrapped server speaks only TLS, the
 * unwrapped one beside it still speaks plain HTTP, and the bind URI that
 * reaches the listener itself never changes scheme.
 *
 * The pair is a throwaway self-signed certificate generated per process —
 * verification is deliberately off on the client side here, because what is
 * under test is the LISTENER's TLS, not the trust chain a browser would judge.
 */
final class TlsListenerTest extends TestCase
{
    private Server $tls;

    private Server $plain;

    private ServerConfig $tlsConfig;

    private int $tlsPort = 0;

    private int $plainPort = 0;

    /** @var list<string> */
    private array $tlsLines = [];

    /** @var list<resource> */
    private array $sockets = [];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = (string) (\sys_get_temp_dir() . '/cc-cmp-tls-' . \getmypid() . '-' . \bin2hex(\random_bytes(4)));
        \mkdir($this->dir, 0o700);
        [$cert, $key] = $this->issuePair();

        $this->tlsConfig = ServerConfig::new('/nonexistent')->withPort(0)->withTls($cert, $key);
        $this->tls = Server::new(
            $this->tlsConfig,
            AuthContext::new(TokenStore::new('/nonexistent')->withOverride(\str_repeat('7', 64))),
            null,
            null,
            Loop::get(),
            function (string $line): void {
                $this->tlsLines[] = $line;
            },
        );
        $this->tls->start();
        $this->tlsPort = (int) $this->tls->port();

        // The contrast server shares the process and loop, bound WITHOUT TLS:
        // if the wrap were global instead of per-config, this plain bind
        // would stop answering plain HTTP and every pin below would go red.
        $this->plain = Server::new(
            ServerConfig::new('/nonexistent')->withPort(0),
            AuthContext::new(TokenStore::new('/nonexistent')->withOverride(\str_repeat('9', 64))),
            null,
            null,
            Loop::get(),
        );
        $this->plain->start();
        $this->plainPort = (int) $this->plain->port();
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            @fclose($socket);
        }
        $this->sockets = [];
        $this->tls->stop();
        $this->plain->stop();
        foreach ((array) \scandir($this->dir) as $entry) {
            if (\is_string($entry) && !\str_starts_with($entry, '.')) {
                @\unlink($this->dir . '/' . $entry);
            }
        }
        @\rmdir($this->dir);
        $timer = Loop::addTimer(0.05, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);
    }

    public function testTheServedUrlsCarryTheTlsSchemeAndTheBindUriDoesNot(): void
    {
        self::assertStringStartsWith('https://127.0.0.1:' . $this->tlsPort . '/', $this->tls->loginUrl());
        self::assertStringStartsWith('https://127.0.0.1:' . $this->tlsPort, $this->tls->url());
        self::assertStringStartsWith('http://127.0.0.1:' . $this->plainPort, $this->plain->url());

        // The listener itself still binds tcp:// — SecureServer is what turns
        // the accepted sockets into tls:// ones. A tls:// bindUri here would
        // hand Listener a scheme it refuses.
        self::assertStringStartsWith('tcp://127.0.0.1:', $this->tlsConfig->bindUri());
    }

    public function testTheTlsListenerShakesHandsAndAnswers(): void
    {
        $socket = $this->secureConnect();
        $this->sendBytes($socket, "GET /api/health HTTP/1.1\r\nHost: 127.0.0.1:{$this->tlsPort}\r\n\r\n");
        $this->pumpLoop(0.35);

        self::assertStringStartsWith('HTTP/1.1 200', $this->drainAll($socket), 'health answers over TLS');

        $this->sendBytes($socket, "GET / HTTP/1.1\r\nHost: 127.0.0.1:{$this->tlsPort}\r\n\r\n");
        $this->pumpLoop(0.35);
        self::assertStringStartsWith('HTTP/1.1', $this->drainAll($socket), 'any path round-trips once the handshake is done');
    }

    public function testAPlaintextRequestToTheTlsEndpointGetsNoAnswerAtAll(): void
    {
        $socket = $this->plainConnect($this->tlsPort);
        $this->sendBytes($socket, "GET /api/health HTTP/1.1\r\nHost: 127.0.0.1:{$this->tlsPort}\r\n\r\n");
        $this->pumpLoop(0.6);

        self::assertSame('', $this->drainAll($socket), 'no HTTP response — the handshake refuses, it does not negotiate down');
        self::assertTrue(feof($socket), 'and the socket is torn down');
        foreach ($this->tlsLines as $line) {
            self::assertStringNotContainsString('HTTP response', $line);
        }
        $this->assertNotEmpty(
            \array_filter($this->tlsLines, static fn (string $l): bool => \str_starts_with($l, 'tls error:')),
            'the refused handshake is logged',
        );

        // The same listener still serves a real TLS client right after — one
        // refused plaintext probe must not poison the endpoint.
        $after = $this->secureConnect();
        $this->sendBytes($after, "GET /api/health HTTP/1.1\r\nHost: 127.0.0.1:{$this->tlsPort}\r\n\r\n");
        $this->pumpLoop(0.35);
        self::assertStringStartsWith('HTTP/1.1 200', $this->drainAll($after));
    }

    public function testTheSignInCookieCarriesSecureOverTlsAndNotOverPlainHttp(): void
    {
        $code = \substr((string) \parse_url($this->tls->loginUrls()[0], \PHP_URL_FRAGMENT), \strlen('code='));
        self::assertNotFalse($code);

        $socket = $this->secureConnect();
        $body = '{"code":"' . $code . '"}';
        $this->sendBytes($socket, "POST /api/login HTTP/1.1\r\nHost: 127.0.0.1:{$this->tlsPort}\r\nContent-Length: " . \strlen($body) . "\r\nOrigin: https://127.0.0.1:{$this->tlsPort}\r\n\r\n" . $body);
        $this->pumpLoop(0.4);
        $answer = $this->drainAll($socket);

        self::assertStringStartsWith('HTTP/1.1 200', $answer, 'a fresh code signs in over TLS');
        self::assertStringContainsString('Set-Cookie: candy_code_session_', $answer);
        self::assertStringContainsString('; Secure', $answer, 'the direct-TLS connection sets the Secure flag (C-WEB-5.a: ClientAddress::isSecure reads the TLS state of the listener itself)');

        $plainCode = \substr((string) \parse_url($this->plain->loginUrls()[0], \PHP_URL_FRAGMENT), \strlen('code='));
        $plainSocket = $this->plainConnect($this->plainPort);
        $plainBody = '{"code":"' . $plainCode . '"}';
        $this->sendBytes($plainSocket, "POST /api/login HTTP/1.1\r\nHost: 127.0.0.1:{$this->plainPort}\r\nContent-Length: " . \strlen($plainBody) . "\r\nOrigin: http://127.0.0.1:{$this->plainPort}\r\n\r\n" . $plainBody);
        $this->pumpLoop(0.4);
        $plainAnswer = $this->drainAll($plainSocket);

        self::assertStringStartsWith('HTTP/1.1 200', $plainAnswer);
        self::assertStringContainsString('Set-Cookie: candy_code_session_', $plainAnswer);
        self::assertStringNotContainsString('; Secure', $plainAnswer, 'the same sign-in over plain HTTP must NOT mark the cookie Secure — the browser would drop it');
    }

    /**
     * A throwaway RSA self-signed pair; the key is written 0600 because
     * {@see ServerConfig::withTls()} refuses anything looser.
     *
     * @return array{0:string,1:string}
     */
    private function issuePair(): array
    {
        $private = \openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($private, 'openssl_pkey_new');
        \openssl_pkey_export($private, $keyPem);
        $csr = \openssl_csr_new(['CN' => '127.0.0.1'], $private);
        self::assertNotFalse($csr, 'openssl_csr_new');
        $cert = \openssl_csr_sign($csr, null, $private, 30);
        self::assertNotFalse($cert, 'openssl_csr_sign');
        \openssl_x509_export($cert, $certPem);

        $certPath = $this->dir . '/cert.pem';
        $keyPath = $this->dir . '/key.pem';
        \file_put_contents($certPath, $certPem);
        \file_put_contents($keyPath, $keyPem);
        \chmod($keyPath, 0o600);

        return [$certPath, $keyPath];
    }

    /** @return resource */
    private function plainConnect(int $port)
    {
        $socket = @\stream_socket_client('127.0.0.1:' . $port, $errno, $errstr, 5.0, \STREAM_CLIENT_ASYNC_CONNECT);
        self::assertNotFalse($socket, 'connect: ' . $errstr);
        \stream_set_blocking($socket, false);
        $this->sockets[] = $socket;

        return $socket;
    }

    /** @return resource a socket whose TLS handshake completed against the TLS server */
    private function secureConnect()
    {
        $socket = $this->plainConnect($this->tlsPort);
        \stream_context_set_option($socket, 'ssl', 'verify_peer', false);
        \stream_context_set_option($socket, 'ssl', 'verify_peer_name', false);
        \stream_context_set_option($socket, 'ssl', 'allow_self_signed', true);

        // The client connected ASYNC: let the loop notice the socket until the
        // transport itself is up, or enable_crypto is asked to speak TLS over
        // a half-open connection and (correctly) refuses with false.
        for ($try = 0; $try < 120 && \stream_socket_get_name($socket, true) === false; ++$try) {
            $this->pumpLoop(0.02);
        }

        // Non-blocking handshake pumped by the same loop the server runs on:
        // enable_crypto answers 0 while it needs more turns of the loop, and a
        // lone false can just be EAGAIN before the server has accepted — both
        // are retried until the bounded attempt budget says the handshake is
        // genuinely refused.
        $result = 0;
        for ($try = 0; $try < 240 && ($result === 0 || $result === false); ++$try) {
            $result = @\stream_socket_enable_crypto($socket, true, \STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($result === 0 || $result === false) {
                $this->pumpLoop(0.02);
            }
        }
        self::assertNotFalse($result, 'the TLS handshake must complete against the wrapped listener');
        self::assertTrue($result === 1 || $result === true, 'the TLS handshake must complete against the wrapped listener');

        return $socket;
    }

    /** @param resource $socket */
    private function sendBytes($socket, string $bytes): void
    {
        // Non-blocking write: header lines are tiny, one fwrite lands them.
        $written = 0;
        for ($try = 0; $try < 120 && $written < \strlen($bytes); ++$try) {
            $chunk = @\fwrite($socket, \substr($bytes, $written));
            if ($chunk === false) {
                $this->pumpLoop(0.02);
                continue;
            }
            $written += $chunk;
        }
        self::assertSame(\strlen($bytes), $written, 'the request was fully sent');
    }

    /** Run the shared loop for $seconds, then hand control back. */
    private function pumpLoop(float $seconds): void
    {
        $timer = Loop::addTimer($seconds, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);
    }

    /** @param resource $socket */
    private function drainAll($socket): string
    {
        $bytes = '';
        while (false !== ($chunk = \fread($socket, 65536))) {
            $bytes .= $chunk;
            if ('' === $chunk) {
                break;
            }
        }

        return $bytes;
    }
}
