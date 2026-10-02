<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;
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
    use ReapsForkedChildrenTrait;

    private string $tmpDir;
    private string $markerFile;

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
        // Reap FIRST: every fixture child holds (or held) a bound loopback
        // socket and may still be writing request logs into $tmpDir — the
        // trait's ledger SIGKILLs+reaps survivors before any cleanup below.
        $this->reapTrackedForkedChildren();

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
        // local path. Now that every Location is resolved (audit F-W2), the
        // target reaches the per-hop scheme check and is REFUSED as an error
        // — no local file bytes, and no second connection to anything.
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse('REDIR', 302, ['Location: file://x/etc/passwd']),
        ]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['pin.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $result = $webFetch->execute(['url' => "http://pin.test:$port/start"]);

        $this->assertStringNotContainsString('root:', $result->content(), 'the local passwd file must never arrive');
        $this->assertTrue($result->isError());
        $this->assertSame('Error: redirect to a non-http(s) scheme is not allowed', $result->content());
        $this->assertCount(
            1,
            // chr(42): the star built, not spelled — a glued '*-bearing'
            // literal here would be harvested as a glob pattern by
            // GlobDialectDifferentialTest's corpus census and drift its
            // pinned figure for being a log-matching detail, not a glob.
            glob($this->tmpDir . '/fixture-request-' . chr(42) . '.log'),
            'a refused scheme is the end of the chain, not a dial target',
        );
    }

    public function testWebFetchCapsBytesReadFromASingleHop(): void
    {
        // Two bounds, two jobs (audit F-T3). The wire read stops one chunk
        // past 2 MiB — the MEMORY bound — and the result is then cut to the
        // shared 64 KiB output cap. Content-Length is what lets the marker
        // name the real total even though the read stopped well short of it.
        $size = 2 * 1024 * 1024 + 200 * 1024;
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse(str_repeat('x', $size)),
        ]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['pin.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $result = $webFetch->execute(['url' => "http://pin.test:$port/big"]);

        $this->assertFalse($result->isError());
        $this->assertLessThanOrEqual(65536, strlen($result->content()));
        [$kept, $dropped, $total] = $this->truncationFigures($result->content());
        $this->assertSame($size, $total, 'Content-Length is a true count, so the marker names it');
        $this->assertSame($size - $kept, $dropped);
        $this->assertStringNotContainsString('lower bound', $result->content());
    }

    public function testWebFetchSaysTheTotalIsALowerBoundWhenTheWireBoundCutsAnUnsizedBody(): void
    {
        // No Content-Length (a close-delimited body, like most chunked
        // dynamic pages): once the wire bound stops the read, nobody knows
        // the real size, so the marker's figures must be announced as lower
        // bounds rather than passed off as the total.
        $size = 2 * 1024 * 1024 + 200 * 1024;
        $port = $this->startLoopbackHttpFixture([
            "HTTP/1.1 200 OK\r\nConnection: close\r\n\r\n" . str_repeat('y', $size),
        ]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['pin.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $result = $webFetch->execute(['url' => "http://pin.test:$port/unsized"]);

        $this->assertFalse($result->isError());
        $this->assertLessThanOrEqual(65536, strlen($result->content()));
        [, , $total] = $this->truncationFigures($result->content());
        $this->assertGreaterThan(2 * 1024 * 1024, $total);
        $this->assertLessThan($size, $total, 'the read must stop at the wire bound, not buffer the whole body');
        $this->assertStringContainsString('lower bound', $result->content());
    }

    public function testWebFetchBoundsAOneMebibyteBodyToTheSharedOutputCap(): void
    {
        // Audit F-T3: a 1 MiB page used to arrive whole — ~0.3M tokens in one
        // tool result, replayed on every later step of the turn. It must now
        // fit the 64 KiB cap Bash/Grep/Glob share, marker included, and the
        // marker must name what was dropped out of what.
        $size = 1024 * 1024;
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse(str_repeat('<p>a</p>', intdiv($size, 8))),
        ]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['pin.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $result = $webFetch->execute(['url' => "http://pin.test:$port/page"]);

        $this->assertFalse($result->isError());
        $content = $result->content();
        $this->assertLessThanOrEqual(65536, strlen($content));
        $this->assertStringStartsWith('<p>a</p><p>a</p>', $content);
        $this->assertStringContainsString('[truncated: ', $content);
        [$kept, $dropped, $total] = $this->truncationFigures($content);
        $this->assertSame($size, $total);
        $this->assertSame($size - $kept, $dropped);
        // A single-line body has no line to clip back to; the kept window
        // must still be nearly the whole budget, not a sliver.
        $this->assertGreaterThan(60000, $kept);
    }

    public function testWebFetchKeepsTheBudgetWhenAShortFirstLinePrecedesOneLongLine(): void
    {
        // Minified HTML is typically `<!doctype html>\n` and then ONE line.
        // The shared trait clips back to the last complete line, which here
        // is the doctype — 15 bytes out of a 64 KiB budget. A fetched page is
        // raw bytes, not a list of paths, so a cut mid-line (announced by the
        // marker) is the right answer and the budget must be spent.
        $body = "<!doctype html>\n" . str_repeat('z', 200 * 1024);
        $port = $this->startLoopbackHttpFixture([$this->httpResponse($body)]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['pin.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $content = $webFetch->execute(['url' => "http://pin.test:$port/min"])->content();

        $this->assertLessThanOrEqual(65536, strlen($content));
        $this->assertStringStartsWith("<!doctype html>\nzzzz", $content);
        [$kept, , $total] = $this->truncationFigures($content);
        $this->assertSame(strlen($body), $total);
        $this->assertGreaterThan(60000, $kept);
    }

    public function testWebFetchOutputCapIsAConstructorParameter(): void
    {
        // The third seam: a caller can pick its own cap. Under it the body is
        // returned verbatim (no marker); over it the cap holds, marker
        // included, and the description quotes the cap actually in force.
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse(str_repeat('s', 500)),
            $this->httpResponse(str_repeat('b', 4096)),
        ]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['pin.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
            maxOutputBytes: 1024,
        );

        $small = $webFetch->execute(['url' => "http://pin.test:$port/small"]);
        $this->assertSame(str_repeat('s', 500), $small->content());

        $big = $webFetch->execute(['url' => "http://pin.test:$port/big"]);
        $this->assertFalse($big->isError());
        $this->assertLessThanOrEqual(1024, strlen($big->content()));
        [$kept, $dropped, $total] = $this->truncationFigures($big->content());
        $this->assertSame(4096, $total);
        $this->assertSame(4096 - $kept, $dropped);
        $this->assertGreaterThan(0, $kept);

        $this->assertStringContainsString('at most 1,024 bytes', $webFetch->description());
    }

    public function testWebFetchDefaultCapIsTheSharedToolOutputDefault(): void
    {
        // WebFetch spells its cap as its own constant (see MAX_OUTPUT_BYTES's
        // doc-block for why); this pins it to the trait's shared default so
        // lowering one without the other is a red test, not silent drift.
        $this->assertSame(
            (new \ReflectionClassConstant(WebFetch::class, 'DEFAULT_MAX_OUTPUT_BYTES'))->getValue(),
            (new \ReflectionClassConstant(WebFetch::class, 'MAX_OUTPUT_BYTES'))->getValue(),
        );
        $this->assertStringContainsString('at most 65,536 bytes', (new WebFetch())->description());
    }

    // ------------------------------------------------------------------
    // Status and redirect semantics (audit F-W2, 2026-10-02).
    // ------------------------------------------------------------------

    public function testWebFetchFollowsARelativeLocationAgainstTheCurrentUrl(): void
    {
        // The repro's `/rel` route: `Location: next` is a relative-path
        // reference (RFC 7231 §7.1.2 allows it). It used to END the chain,
        // handing the model the 3xx stub as if it were the document.
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse('3xx body for /dir/rel', 302, ['Location: next']),
            $this->httpResponse('REAL CONTENT'),
        ]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['pin.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $result = $webFetch->execute(['url' => "http://pin.test:$port/dir/rel"]);

        $this->assertFalse($result->isError());
        $this->assertSame('REAL CONTENT', $result->content());
        $this->assertStringStartsWith('GET /dir/next ', $this->fixtureRequest(1));
    }

    public function testWebFetchResolvesQueryDotSegmentAndNetworkPathLocations(): void
    {
        // Two more relative forms, chained so each one is actually dialed:
        // query-only (keeps the path, replaces the query) and dot-segment
        // (merged against the base directory, then `.`/`..` removed).
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse('r1', 302, ['Location: ?page=2']),
            $this->httpResponse('r2', 301, ['Location: ../x/./y']),
            $this->httpResponse('FINAL'),
        ]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['pin.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $result = $webFetch->execute(['url' => "http://pin.test:$port/a/b/c"]);

        $this->assertFalse($result->isError());
        $this->assertSame('FINAL', $result->content());

        $this->assertStringStartsWith('GET /a/b/c?page=2 ', $this->fixtureRequest(1));
        $this->assertStringStartsWith('GET /a/x/y ', $this->fixtureRequest(2));
    }

    public function testWebFetchFollowsANetworkPathLocationToTheNamedAuthority(): void
    {
        // `//host/p` keeps the scheme and replaces the whole authority, port
        // included — so the next hop is a different NAME, dialed under its
        // own Host header. The port is only known once the fixture listens,
        // hence the `{PORT}` placeholder the fixture fills in.
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse('r1', 302, ['Location: //other.test:{PORT}/net?q=1#frag']),
            $this->httpResponse('NET-BODY'),
        ], substitutePort: true);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['pin.test' => ['127.0.0.2'], 'other.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $result = $webFetch->execute(['url' => "http://pin.test:$port/start"]);

        $this->assertFalse($result->isError());
        $this->assertSame('NET-BODY', $result->content());
        $second = $this->fixtureRequest(1);
        $this->assertStringStartsWith('GET /net?q=1 ', $second, 'the fragment is never sent');
        $this->assertMatchesRegularExpression("/\r\nHost: other\\.test:$port\r\n/i", $second);
    }

    public function testWebFetchReGuardsANetworkPathRedirectIntoAPrivateRange(): void
    {
        // A relative Location is a NEW target, so it must face the whole
        // per-hop guard chain — resolving `//169.254.169.254/` must not
        // become a way around the address check that an absolute URL meets.
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse('REDIR', 302, ['Location: //169.254.169.254/latest/meta-data/']),
        ]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['pin.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $result = $webFetch->execute(['url' => "http://pin.test:$port/start"]);

        $this->assertTrue($result->isError());
        $this->assertSame('Error: redirect to private/link-local address is not allowed', $result->content());
    }

    public function testWebFetchReportsAnExhaustedRedirectChainAsAnError(): void
    {
        // The repro's `/loop` route: after MAX_REDIRECTS (3) follows the
        // fourth response is still a 302. Its stub body is not the document,
        // so it comes back as an error that says why.
        $loop = $this->httpResponse('3xx body for /loop', 302, ['Location: /loop']);
        $port = $this->startLoopbackHttpFixture([$loop, $loop, $loop, $loop]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['pin.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $result = $webFetch->execute(['url' => "http://pin.test:$port/loop"]);

        $this->assertTrue($result->isError());
        $this->assertStringStartsWith('HTTP 302', $result->content());
        $this->assertStringContainsString('redirect limit', $result->content());
        $this->assertStringEndsWith("\n3xx body for /loop", $result->content());
        $this->assertFileExists($this->tmpDir . '/fixture-request-3.log', 'all three follows must be dialed');
    }

    public function testWebFetchReportsAClientErrorWithItsStatus(): void
    {
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse('NOT FOUND PAGE', 404),
        ]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['pin.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $result = $webFetch->execute(['url' => "http://pin.test:$port/missing"]);

        $this->assertTrue($result->isError());
        $this->assertSame("HTTP 404\nNOT FOUND PAGE", $result->content());
    }

    public function testWebFetchReportsAServerErrorWithItsStatus(): void
    {
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse('OOPS', 503),
        ]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['pin.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $result = $webFetch->execute(['url' => "http://pin.test:$port/down"]);

        $this->assertTrue($result->isError());
        $this->assertSame("HTTP 503\nOOPS", $result->content());
    }

    public function testWebFetchReportsARedirectWithNoLocationAsAnError(): void
    {
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse('MOVED SOMEWHERE', 302),
        ]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['pin.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $result = $webFetch->execute(['url' => "http://pin.test:$port/moved"]);

        $this->assertTrue($result->isError());
        $this->assertStringStartsWith('HTTP 302', $result->content());
        $this->assertStringContainsString('no usable Location', $result->content());
        $this->assertStringEndsWith("\nMOVED SOMEWHERE", $result->content());
    }

    public function testWebFetchReportsAResponseWithNoParseableStatusAsAnError(): void
    {
        // The stream wrapper accepts `HTTP/1.1 abc` and hands back its body.
        // Nothing vouches for that body being the document, so it is not
        // passed off as one.
        $port = $this->startLoopbackHttpFixture([
            "HTTP/1.1 abc\r\nContent-Length: 4\r\nConnection: close\r\n\r\nBODY",
        ]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['pin.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );

        $result = $webFetch->execute(['url' => "http://pin.test:$port/odd"]);

        $this->assertTrue($result->isError());
        $this->assertSame("HTTP (no parseable status line)\nBODY", $result->content());
    }

    /**
     * RFC 3986 §5.4 — every normal and abnormal example, against the RFC's own
     * base. Fragments are dropped (they are never sent), which is the only
     * deviation from the RFC's printed results.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rfc3986ReferenceExamples(): array
    {
        $examples = [
            'g:h' => 'g:h', 'g' => 'http://a/b/c/g', './g' => 'http://a/b/c/g', 'g/' => 'http://a/b/c/g/',
            '/g' => 'http://a/g', '//g' => 'http://g', '?y' => 'http://a/b/c/d;p?y', 'g?y' => 'http://a/b/c/g?y',
            '#s' => 'http://a/b/c/d;p?q', 'g#s' => 'http://a/b/c/g', 'g?y#s' => 'http://a/b/c/g?y',
            ';x' => 'http://a/b/c/;x', 'g;x' => 'http://a/b/c/g;x', 'g;x?y#s' => 'http://a/b/c/g;x?y',
            '' => 'http://a/b/c/d;p?q', '.' => 'http://a/b/c/', './' => 'http://a/b/c/', '..' => 'http://a/b/',
            '../' => 'http://a/b/', '../g' => 'http://a/b/g', '../..' => 'http://a/', '../../' => 'http://a/',
            '../../g' => 'http://a/g',
            // §5.4.2 abnormal examples.
            '../../../g' => 'http://a/g', '../../../../g' => 'http://a/g', '/./g' => 'http://a/g',
            '/../g' => 'http://a/g', 'g.' => 'http://a/b/c/g.', '.g' => 'http://a/b/c/.g', 'g..' => 'http://a/b/c/g..',
            '..g' => 'http://a/b/c/..g', './../g' => 'http://a/b/g', './g/.' => 'http://a/b/c/g/',
            'g/./h' => 'http://a/b/c/g/h', 'g/../h' => 'http://a/b/c/h', 'g;x=1/./y' => 'http://a/b/c/g;x=1/y',
            'g;x=1/../y' => 'http://a/b/c/y', 'g?y/./x' => 'http://a/b/c/g?y/./x', 'g?y/../x' => 'http://a/b/c/g?y/../x',
            'g#s/./x' => 'http://a/b/c/g', 'g#s/../x' => 'http://a/b/c/g', 'http:g' => 'http:g',
        ];

        $cases = [];
        foreach ($examples as $reference => $expected) {
            $cases["ref '$reference'"] = [(string) $reference, $expected];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rfc3986ReferenceExamples')]
    public function testWebFetchResolvesLocationReferencesPerRfc3986(string $reference, string $expected): void
    {
        $resolve = new \ReflectionMethod(WebFetch::class, 'resolveReference');

        $this->assertSame($expected, $resolve->invoke(null, 'http://a/b/c/d;p?q', $reference));
    }

    // ------------------------------------------------------------------
    // SSRF range gaps (audit F-W1, 2026-10-01).
    //
    // These tests run the PRODUCTION blocklist decision, but through a seam
    // that turns its "allowed" verdict into a recorded leak plus a refusal —
    // so a regression in the range list fails the leak assertion instead of
    // dialing 100.100.100.200 or a tailnet peer for real.
    // ------------------------------------------------------------------

    public function testWebFetchRefusesEveryNonPublicRangeAsAResolverAnswer(): void
    {
        $nonPublic = [
            '100.64.0.1',              // CGNAT / Tailscale, low edge
            '100.100.100.200',         // Alibaba Cloud ECS metadata
            '100.127.255.254',         // CGNAT, high edge
            '192.0.0.170',             // IETF protocol assignments (NAT64 discovery)
            '198.18.0.1',              // benchmarking
            '198.19.255.254',          // benchmarking, high edge
            '224.0.0.1',               // multicast
            '239.255.255.250',         // multicast (SSDP)
            '240.0.0.1',               // reserved
            '255.255.255.255',         // limited broadcast
            '::',                      // unspecified v6
            '::ffff:100.100.100.200',  // v4-mapped spelling of the new v4 range
            '64:ff9b::7f00:1',         // NAT64 → 127.0.0.1
            '64:ff9b::a9fe:a9fe',      // NAT64 → 169.254.169.254
            '64:ff9b::a00:1',          // NAT64 → 10.0.0.1
            '64:ff9b::5db8:d822',      // NAT64 → public: fail-closed by prefix
            '64:ff9b:1::1',            // local-use NAT64
            '2002:7f00:1::',           // 6to4 → 127.0.0.1
            '2002:a9fe:a9fe::1',       // 6to4 → 169.254.169.254
            '2001:0:4136:e378::1',     // Teredo
            'fec0::1',                 // deprecated site-local
            'ff02::1',                 // multicast, link scope
            'ff05::1:3',               // multicast, site scope
        ];

        $leaks = [];
        foreach ($nonPublic as $address) {
            $result = $this->webFetchWithLeakRecorder(['target.test' => [$address]], $leaks)
                ->execute(['url' => 'http://target.test/']);

            $this->assertTrue($result->isError(), "$address must be refused");
            $this->assertStringContainsString('private/link-local', $result->content(), $address);
        }

        $this->assertSame([], $leaks, 'the production blocklist admitted: ' . implode(', ', $leaks));
    }

    public function testWebFetchStillAdmitsPublicUnicastBesideTheNewRanges(): void
    {
        // Edges of every added v4 range, plus ordinary v6 unicast — including
        // a 2001: address outside the Teredo /32 — must keep their "allowed"
        // verdict, or the widened list has eaten real sites.
        $public = [
            '93.184.216.34',
            '8.8.8.8',
            '100.63.255.255',
            '100.128.0.0',
            '192.0.1.1',
            '198.17.255.255',
            '198.20.0.0',
            '223.255.255.255',
            '2606:2800:220:1:248:1893:25c8:1946',
            '2001:4860:4860::8888',
            '2003::1',
        ];

        foreach ($public as $address) {
            $this->assertFalse($this->productionBlocklistVerdict($address), "$address is public unicast");
        }
    }

    public function testWebFetchRefusesLiteralUrlAtAlibabaMetadataWithTheDefaultGuard(): void
    {
        // The unseamed constructor end-to-end: the literal URL resolves to
        // itself and the default blocklist refuses it before any dial.
        $result = (new WebFetch())->execute(['url' => 'http://100.100.100.200/latest/meta-data/']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('private/link-local', $result->content());
    }

    public function testWebFetchRefusesRedirectIntoCgnatMetadata(): void
    {
        // A public page 302-ing to the Alibaba metadata literal must die on
        // the hop's re-check, by the production verdict — not merely by the
        // fixture's admit-one-literal seam, which refuses everything else.
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse('REDIR', 302, ['Location: http://100.100.100.200/latest/meta-data/']),
        ]);

        $leaks = [];
        $webFetch = $this->webFetchWithLeakRecorder(['pin.test' => ['127.0.0.2']], $leaks, admit: '127.0.0.2');

        $result = $webFetch->execute(['url' => "http://pin.test:$port/start"]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('redirect to private/link-local', $result->content());
        $this->assertSame([], $leaks, 'the production blocklist admitted: ' . implode(', ', $leaks));
    }

    /**
     * A WebFetch whose blocklist is the production decision, except that an
     * "allowed" verdict is recorded in $leaks and refused anyway — the test
     * can assert the verdict without ever opening a socket to it. $admit is
     * the one fixture literal that may actually be dialed.
     *
     * @param array<string, list<string>> $dns
     * @param list<string>                $leaks
     */
    private function webFetchWithLeakRecorder(array $dns, array &$leaks, ?string $admit = null): WebFetch
    {
        return new WebFetch(
            resolveAddresses: $this->fakeDns($dns),
            isBlockedAddress: function (string $address) use (&$leaks, $admit): bool {
                if ($admit !== null && $address === $admit) {
                    return false;
                }
                if (!$this->productionBlocklistVerdict($address)) {
                    $leaks[] = $address;
                }

                return true;
            },
        );
    }

    private function productionBlocklistVerdict(string $address): bool
    {
        return (bool) (new \ReflectionMethod(WebFetch::class, 'addressIsBlocked'))->invoke(null, $address);
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
        $status = match (true) {
            $code === 200 => '200 OK',
            $code >= 300 && $code < 400 => $code . ' Redirect',
            default => $code . ' Error',
        };

        return "HTTP/1.1 $status\r\n"
            . implode('', array_map(static fn (string $h): string => "$h\r\n", $extraHeaders))
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $body;
    }

    /**
     * Kept-byte count plus the marker's two figures, read back from a capped
     * result. The kept count is everything before the marker's own line.
     *
     * @return array{0: int, 1: int, 2: int} kept, dropped, total
     */
    private function truncationFigures(string $content): array
    {
        $this->assertSame(
            1,
            preg_match('/\n\.\.\. \[truncated: (\d+) of (\d+) bytes omitted\./', $content, $m, PREG_OFFSET_CAPTURE),
            'no shared truncation marker in the result',
        );

        return [$m[0][1], (int) $m[1][0], (int) $m[2][0]];
    }

    private function fixtureRequest(int $index): string
    {
        $log = $this->tmpDir . "/fixture-request-$index.log";
        $this->assertFileExists($log, "connection $index was never made");

        return (string) file_get_contents($log);
    }

    private function requireFork(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix are required for the loopback fixtures');
        }
    }

    /**
     * @param non-empty-list<string> $responses served in order, one per connection
     * @param bool $substitutePort replace `{PORT}` in each response with the bound port
     *
     * @return int the ephemeral port the fixture is listening on
     */
    private function startLoopbackHttpFixture(array $responses, bool $substitutePort = false): int
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
        if ($substitutePort) {
            $responses = array_map(static fn (string $r): string => str_replace('{PORT}', (string) $port, $r), $responses);
        }

        $pid = $this->forkTracked();
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
            ForkedChild::exitNow(0);
        }

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

        $pid = $this->forkTracked();
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
            ForkedChild::exitNow(0);
        }

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

        $pid = $this->forkTracked();
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
            ForkedChild::exitNow(0);
        }

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
