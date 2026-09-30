<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\WebFetch;

/**
 * Security regression tests for tools.
 */
final class ToolSecurityTest extends TestCase
{
    private string $tmpDir;
    private string $markerFile;

    /** @var list<int> forked loopback-fixture children still unreaped */
    private array $fixturePids = [];

    private string $previousSslCertFile = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sugarcrush_security_' . uniqid((string) getmypid(), true);
        mkdir($this->tmpDir, 0777, true);
        $this->markerFile = $this->tmpDir . '/injection_marker_' . uniqid((string) getmypid(), true);
        $this->previousSslCertFile = (string) getenv('SSL_CERT_FILE');
    }

    protected function tearDown(): void
    {
        // A fixture child that outlived its served responses is killed, never
        // waited on forever: each holds a bound loopback socket.
        foreach ($this->fixturePids as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }
        $this->fixturePids = [];

        // T7 points OpenSSL at a throwaway self-signed anchor; the next test
        // in the process must not inherit it.
        putenv($this->previousSslCertFile === ''
            ? 'SSL_CERT_FILE='
            : 'SSL_CERT_FILE=' . $this->previousSslCertFile);

        if (file_exists($this->markerFile)) {
            unlink($this->markerFile);
        }
        if (is_dir($this->tmpDir)) {
            array_map('unlink', glob($this->tmpDir . '/*'));
            rmdir($this->tmpDir);
        }
    }

    public function testGrepIncludeInjectionBlocked(): void
    {
        $grep = new Grep($this->tmpDir);
        $injectionPayload = 'a; touch ' . escapeshellarg($this->markerFile) . ' #';

        $result = $grep->execute([
            'pattern' => 'test_pattern',
            'path' => $this->tmpDir,
            'include' => $injectionPayload,
        ]);

        $this->assertTrue($result->isError() || $result->content() === '');
        $this->assertFalse(file_exists($this->markerFile), 'Injection payload was executed - marker file exists');
    }

    public function testBashPathJailRunsInJailDirectory(): void
    {
        $bash = new Bash($this->tmpDir);

        $result = $bash->execute([
            'command' => 'pwd',
        ]);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString($this->tmpDir, $result->content());
    }

    public function testReadPathJailPreventsEtcPasswd(): void
    {
        $read = new Read($this->tmpDir);

        $result = $read->execute([
            'file_path' => '/etc/passwd',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('workspace root', $result->content());
    }

    public function testEditPathJailPreventsEtcPasswd(): void
    {
        $edit = new Edit($this->tmpDir);

        $result = $edit->execute([
            'file_path' => '/etc/passwd',
            'old_string' => 'test',
            'new_string' => 'replaced',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('workspace root', $result->content());
    }

    public function testGlobPathJailPreventsOutsideAccess(): void
    {
        $glob = new Glob($this->tmpDir);

        $result = $glob->execute([
            'pattern' => '**/*',
            'path' => '/etc',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('workspace root', $result->content());
    }

    public function testGrepPathJailPreventsOutsideAccess(): void
    {
        $grep = new Grep($this->tmpDir);

        $result = $grep->execute([
            'pattern' => 'test',
            'path' => '/etc',
            'include' => '*.conf',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('workspace root', $result->content());
    }

    public function testWebFetchBlocksLocalhost(): void
    {
        $webFetch = new WebFetch();

        $result = $webFetch->execute([
            'url' => 'http://127.0.0.1/test',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('localhost', $result->content());
    }

    public function testWebFetchBlocks169254Metadata(): void
    {
        $webFetch = new WebFetch();

        $result = $webFetch->execute([
            'url' => 'http://169.254.169.254/latest/meta-data/',
        ]);

        $this->assertTrue($result->isError());
    }

    public function testWebFetchBlocksPrivateIpRanges(): void
    {
        $webFetch = new WebFetch();

        $privateIps = [
            'http://10.0.0.1/test',
            'http://172.16.0.1/test',
            'http://192.168.1.1/test',
        ];

        foreach ($privateIps as $url) {
            $result = $webFetch->execute(['url' => $url]);
            $this->assertTrue($result->isError(), "Expected $url to be blocked");
        }
    }

    // ------------------------------------------------------------------
    // Resolve-once-then-dial wave (audit M6 + M7 + L1, 2026-09-30).
    //
    // The fixtures dial 127.0.0.2 — a second loopback address the production
    // blocklist refuses — so every test that reaches the socket injects the
    // narrow blocklist seam that admits ONLY that one literal. The seam is
    // the same closure production composes, just re-pointed; the guard chain
    // around it runs untouched.
    // ------------------------------------------------------------------

    public function testWebFetchBlocksUnspecifiedAddress(): void
    {
        // 0.0.0.0:80 is loopback-equivalent on Linux and was absent from the
        // pre-wave v4 ranges (audit M7, second half).
        $result = (new WebFetch())->execute(['url' => 'http://0.0.0.0/']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('private/link-local', $result->content());
    }

    public function testWebFetchBlocksIpv4MappedIpv6Loopback(): void
    {
        // Linux dials [::ffff:127.0.0.1] as loopback, but a family-length
        // compare wave-passes it every v4 range.
        $result = (new WebFetch())->execute(['url' => 'http://[::ffff:127.0.0.1]/']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('private/link-local', $result->content());
    }

    public function testWebFetchBlocksAddressNameResolvingOnlyToIpv6Loopback(): void
    {
        // gethostbyname() answers the A record only; an AAAA-only name at ::1
        // read as "unresolvable", which the pre-wave guard mapped to "not
        // blocked". The v6 answer must now reach the same refusal.
        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['v6only.test' => ['::1']]),
        );

        $result = $webFetch->execute(['url' => 'http://v6only.test/']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('private/link-local', $result->content());
    }

    public function testWebFetchRefusesNameWhoseAnswersMixPublicAndPrivate(): void
    {
        // The rebinding shape: whatever a second resolver pass would answer,
        // a poisoned answer set forfeits the whole name rather than letting
        // the caller pick which literal to dial.
        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['mixed.test' => ['93.184.216.34', '127.0.0.1']]),
        );

        $result = $webFetch->execute(['url' => 'http://mixed.test/']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('private/link-local', $result->content());
    }

    public function testWebFetchDialsTheValidatedLiteralAndKeepsTheName(): void
    {
        // The TOCTOU fix pinned end-to-end: one resolution feeds both the
        // guard and the socket, the URL authority is the IP, and the peer
        // still sees the name — once, in the Host header, in origin form.
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse('ok-body'),
        ]);

        $resolverCalls = 0;
        $dns = $this->fakeDns(['pin.test' => ['127.0.0.2']]);
        $webFetch = new WebFetch(
            resolveAddresses: function (string $host) use ($dns, &$resolverCalls): array {
                $resolverCalls++;

                return $dns($host);
            },
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $result = $webFetch->execute(['url' => "http://pin.test:$port/probe?x=1"]);

        $this->assertFalse($result->isError());
        $this->assertSame('ok-body', $result->content());
        $this->assertSame(1, $resolverCalls, 'one resolution per hop: the guard and the dial share it');

        $request = (string) file_get_contents($this->tmpDir . '/fixture-request-0.log');
        $this->assertSame(
            1,
            preg_match_all('/^Host: pin\.test(?::\d+)?\r?$/mi', $request),
            'exactly one Host header, carrying the checked name (with its port): ' . $request,
        );
        $this->assertSame(
            0,
            preg_match_all('/^Host: 127\.0\.0\.2\r?$/mi', $request),
            'the pinned URL must not leak an IP-valued Host header: ' . $request,
        );
        $this->assertMatchesRegularExpression('/^GET \/probe\?x=1 HTTP\/1\.1\r?$/m', $request);
    }

    public function testWebFetchSendsPeerNameAsSniNotThePinnedLiteral(): void
    {
        // The plain-TCP fixture never completes a TLS handshake; it exists to
        // capture the ClientHello bytes the https attempt puts on the wire.
        // SNI in cleartext proves ssl.peer_name drove the extension — a
        // literal-IP URL would otherwise send no SNI at all.
        [$port, $capturePath] = $this->startCaptureFixture();

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['sni.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $result = $webFetch->execute(['url' => "https://sni.test:$port/"]);

        $this->assertTrue($result->isError(), 'the cleartext fixture cannot satisfy a TLS client');

        $hello = (string) file_get_contents($capturePath);
        $this->assertNotSame('', $hello, 'the client never reached the socket');
        $this->assertStringContainsString('sni.test', $hello, 'SNI must carry the checked name');
        $this->assertStringNotContainsString('127.0.0.2', $hello, 'the pinned literal must not become the identity');
    }

    public function testWebFetchTlsVerificationFollowsTheNameAcrossPinning(): void
    {
        // Positive/negative pair over one TLS fixture: pinning to the IP must
        // neither skip certificate verification nor verify it against the IP.
        // SSL_CERT_FILE is the only in-process trust lever — openssl.cafile
        // is not PHP_INI_ALL — and WebSearch's own env seams precedent shows
        // getenv-time reads are the house shape.
        [$pinCombined, $pinAnchor] = $this->selfSignedCert('pin.test');
        $port = $this->startTlsFixture($pinCombined);
        putenv('SSL_CERT_FILE=' . $pinAnchor);

        $fetch = fn (string $name): \SugarCraft\Crush\Tools\ToolResult => (new WebFetch(
            resolveAddresses: $this->fakeDns([$name => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        ))->execute(['url' => "https://$name:$port/"]);

        $matched = $fetch('pin.test');
        $this->assertFalse($matched->isError());
        $this->assertSame('ok', $matched->content());

        $mismatched = $fetch('wrong.test');
        $this->assertTrue($mismatched->isError(), 'peer_name must reject a cert issued to another host');
    }

    public function testWebFetchRedirectChainKeepsOnlyTheFinalBody(): void
    {
        // Audit L1: `$content .= $chunk` ran before the 3xx branch, so every
        // intermediate page body prepended itself to the answer. The hop is
        // origin-relative — the client re-attaches the port, and the second
        // request log proves it actually re-dialed.
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse('REDIRBODY', 302, ['Location: /final']),
            $this->httpResponse('FINAL-BODY'),
        ]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['pin.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $result = $webFetch->execute(['url' => "http://pin.test:$port/start"]);

        $this->assertFalse($result->isError());
        $this->assertSame('FINAL-BODY', $result->content());
        $this->assertFileExists($this->tmpDir . '/fixture-request-1.log', 'the second hop must actually be dialed');
    }

    public function testWebFetchRefusesRedirectIntoPrivateRange(): void
    {
        // The per-hop address guard already existed; it stays green through
        // the wave so the pinning rewrite cannot have quietly dropped it.
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse('REDIR', 302, ['Location: http://169.254.169.254/latest/meta-data/']),
        ]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['pin.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $result = $webFetch->execute(['url' => "http://pin.test:$port/start"]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('redirect to private/link-local', $result->content());
    }

    public function testWebFetchNeverDialsANonHttpRedirectTarget(): void
    {
        // `Location: file://anything/etc/passwd` parses WITH a host, so the
        // old address guards waved it through and the stream layer read the
        // local path. The chain must stop at the 3xx body instead: no local
        // file bytes, and no second connection to the origin.
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse('REDIR', 302, ['Location: file://x/etc/passwd']),
        ]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['pin.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $result = $webFetch->execute(['url' => "http://pin.test:$port/start"]);

        $this->assertStringNotContainsString('root:', $result->content(), 'the local passwd file must never arrive');
        $this->assertSame('REDIR', $result->content());
        $this->assertCount(
            1,
            glob($this->tmpDir . '/fixture-request-*.log'),
            'a refused scheme is the end of the chain, not a dial target',
        );
    }

    public function testWebFetchCapsBytesReadFromASingleHop(): void
    {
        // The cap used to apply AFTER buffering: a Content-Length of 2 MiB +
        // 512 proves the bounded read loop stops at one chunk past 2 MiB on
        // the wire, not in the string layer.
        $bulk = str_repeat('x', 2 * 1024 * 1024 + 512);
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse($bulk),
        ]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['pin.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $result = $webFetch->execute(['url' => "http://pin.test:$port/big"]);

        $this->assertFalse($result->isError());
        $this->assertSame(2 * 1024 * 1024 + strlen("\n... [truncated]"), strlen($result->content()));
        $this->assertStringEndsWith('[truncated]', $result->content());
    }

    // ------------------------------------------------------------------
    // Loopback fixture helpers (WebFetch wave)
    // ------------------------------------------------------------------

    /**
     * @param array<string, list<string>> $map name => IP literals
     */
    private function fakeDns(array $map): callable
    {
        return static function (string $host) use ($map): array {
            if (isset($map[$host])) {
                return $map[$host];
            }

            return filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : [];
        };
    }

    /**
     * Admits only the fixture address; every other literal, private or not,
     * stays blocked so a test can never dial out for real by accident.
     */
    private function fixtureOnlyBlocklist(): callable
    {
        return static fn (string $address): bool => $address !== '127.0.0.2';
    }

    /** @param list<string> $extraHeaders */
    private function httpResponse(string $body, int $code = 200, array $extraHeaders = []): string
    {
        $status = $code === 200 ? '200 OK' : $code . ' Redirect';

        return "HTTP/1.1 $status\r\n"
            . implode('', array_map(static fn (string $h): string => "$h\r\n", $extraHeaders))
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $body;
    }

    private function requireFork(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix are required for the loopback fixtures');
        }
    }

    /**
     * @param non-empty-list<string> $responses served in order, one per connection
     *
     * @return int the ephemeral port the fixture is listening on
     */
    private function startLoopbackHttpFixture(array $responses): int
    {
        $this->requireFork();
        $logDir = $this->tmpDir;

        // Port 0 + get_name is MANDATORY on this shared box: hardcoded test
        // ports collide with other users' listeners. 127.0.0.2 so the real
        // blocklist would still refuse it if a seam were ever widened.
        $server = @stream_socket_server('tcp://127.0.0.2:0', $errno, $errstr);
        $this->assertIsResource($server, "fixture bind failed: $errstr");
        $name = (string) stream_socket_get_name($server, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('pcntl_fork() failed for the loopback fixture');
        }
        if ($pid === 0) {
            foreach (array_values($responses) as $index => $response) {
                $connection = @stream_socket_accept($server, 15.0);
                if ($connection === false) {
                    continue;
                }
                stream_set_timeout($connection, 5);
                $request = '';
                while (!feof($connection)) {
                    $chunk = fread($connection, 4096);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $request .= $chunk;
                    if (str_contains($request, "\r\n\r\n")) {
                        break;
                    }
                }
                file_put_contents("$logDir/fixture-request-$index.log", $request);
                $left = $response;
                while ($left !== '') {
                    $written = @fwrite($connection, $left);
                    if ($written === false || $written === 0) {
                        break;
                    }
                    $left = substr($left, $written);
                }
                fclose($connection);
            }
            fclose($server);
            exit(0);
        }

        $this->fixturePids[] = $pid;
        fclose($server);

        return $port;
    }

    /**
     * Plain TCP, serves nothing: captures the first flight (a TLS ClientHello
     * is cleartext) into $logDir/snapshot.bin.
     *
     * @return array{0: int, 1: string} port, capture file path
     */
    private function startCaptureFixture(): array
    {
        $this->requireFork();
        $capturePath = $this->tmpDir . '/snapshot.bin';

        $server = @stream_socket_server('tcp://127.0.0.2:0', $errno, $errstr);
        $this->assertIsResource($server, "capture bind failed: $errstr");
        $name = (string) stream_socket_get_name($server, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('pcntl_fork() failed for the capture fixture');
        }
        if ($pid === 0) {
            $connection = @stream_socket_accept($server, 15.0);
            if ($connection !== false) {
                stream_set_timeout($connection, 3);
                $captured = '';
                $emptyReads = 0;
                while (strlen($captured) < 300 && $emptyReads < 2) {
                    $chunk = fread($connection, 8192);
                    if ($chunk === false || $chunk === '') {
                        $emptyReads++;
                        continue;
                    }
                    $captured .= $chunk;
                }
                file_put_contents($capturePath, $captured);
                fclose($connection);
            }
            fclose($server);
            exit(0);
        }

        $this->fixturePids[] = $pid;
        fclose($server);

        return [$port, $capturePath];
    }

    /**
     * TLS on 127.0.0.2 serving "ok" twice — the second accept meets the
     * name-mismatch client and dies with it; either way the child exits when
     * the response list is exhausted.
     */
    private function startTlsFixture(string $combinedCertPath): int
    {
        $this->requireFork();
        $logDir = $this->tmpDir;
        $response = $this->httpResponse('ok');

        $context = stream_context_create(['ssl' => ['local_cert' => $combinedCertPath]]);
        $server = @stream_socket_server('ssl://127.0.0.2:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        $this->assertIsResource($server, "TLS fixture bind failed: $errstr");
        $name = (string) stream_socket_get_name($server, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('pcntl_fork() failed for the TLS fixture');
        }
        if ($pid === 0) {
            for ($served = 0; $served < 2; $served++) {
                $connection = @stream_socket_accept($server, 15.0);
                if ($connection === false) {
                    continue;
                }
                stream_set_timeout($connection, 5);
                $request = '';
                while (!feof($connection)) {
                    $chunk = fread($connection, 4096);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $request .= $chunk;
                    if (str_contains($request, "\r\n\r\n")) {
                        break;
                    }
                }
                file_put_contents("$logDir/fixture-request-$served.log", $request);
                @fwrite($connection, $response);
                fclose($connection);
            }
            fclose($server);
            exit(0);
        }

        $this->fixturePids[] = $pid;
        fclose($server);

        return $port;
    }

    /**
     * A 1-day self-signed cert. Export goes through STRINGS: writing the cert
     * and then the key to one path with *_export_to_file overwrites the cert
     * with a key-only file (measured, cost one debug round).
     *
     * @return array{0: string, 1: string} combined cert+key path, cert-only anchor path
     */
    private function selfSignedCert(string $commonName): array
    {
        if (!function_exists('openssl_csr_sign')) {
            $this->markTestSkipped('ext-openssl is required for the TLS fixture');
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key, 'openssl_pkey_new failed: ' . openssl_error_string());
        $csr = openssl_csr_new(['CN' => $commonName], $key);
        $this->assertNotFalse($csr, 'openssl_csr_new failed: ' . openssl_error_string());
        $cert = openssl_csr_sign($csr, null, $key, 1);
        $this->assertNotFalse($cert, 'openssl_csr_sign failed: ' . openssl_error_string());

        $this->assertTrue(openssl_x509_export($cert, $certPem));
        $this->assertTrue(openssl_pkey_export($key, $keyPem));

        $combined = $this->tmpDir . "/$commonName-combined.pem";
        $anchor = $this->tmpDir . "/$commonName-anchor.pem";
        file_put_contents($combined, $certPem . $keyPem);
        file_put_contents($anchor, $certPem);

        return [$combined, $anchor];
    }

    public function testReadSizeCapTruncatesLargeFile(): void
    {
        $read = new Read(null, 1024);

        $largeFile = $this->tmpDir . '/large_file.txt';
        file_put_contents($largeFile, str_repeat('x', 2048));

        $result = $read->execute([
            'file_path' => $largeFile,
        ]);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString('[truncated]', $result->content());
        $this->assertLessThanOrEqual(1024 + 50, strlen($result->content()));
    }

    public function testEditNonUniqueOldStringErrors(): void
    {
        $testFile = $this->tmpDir . '/test_edit.txt';
        file_put_contents($testFile, "line1\nfoo\nline2\nfoo\nline3\n");

        $edit = new Edit($this->tmpDir);

        $result = $edit->execute([
            'file_path' => $testFile,
            'old_string' => 'foo',
            'new_string' => 'bar',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('not unique', $result->content());
    }

    public function testEditWithReplaceAllAllowsMultiMatch(): void
    {
        $testFile = $this->tmpDir . '/test_edit2.txt';
        file_put_contents($testFile, "line1\nfoo\nline2\nfoo\nline3\n");

        $edit = new Edit($this->tmpDir);

        $result = $edit->execute([
            'file_path' => $testFile,
            'old_string' => 'foo',
            'new_string' => 'bar',
            'replace_all' => true,
        ]);

        $this->assertFalse($result->isError());
        $content = file_get_contents($testFile);
        $this->assertEquals("line1\nbar\nline2\nbar\nline3\n", $content);
    }
}
