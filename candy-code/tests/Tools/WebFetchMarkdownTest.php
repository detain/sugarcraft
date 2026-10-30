<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\ForkedChild;
use SugarCraft\Code\Support\ToolOutputSpill;
use SugarCraft\Code\Tests\Support\ReapsForkedChildrenTrait;
use SugarCraft\Code\Tools\BuiltIn\WebFetch;

/**
 * C-TOOL-2.a: the WebFetch body path converts an HTML 2xx response to
 * Markdown, keeps every other content type verbatim, refuses a malformed
 * `format` argument before dialing, and spends the 64 KiB result cap on the
 * converted text rather than the raw bytes. Loopback fixtures only — the
 * fake resolver plus the fixture-only blocklist mean nothing can dial out.
 */
final class WebFetchMarkdownTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    private string $tmpDir;

    /** Where an over-cap WebFetch saves its page: never the user's real store. */
    private string $spillDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/candy_code_markdown_' . uniqid((string) getmypid(), true);
        mkdir($this->tmpDir, 0777, true);
        $this->spillDir = $this->tmpDir . '-spill';
        ToolOutputSpill::useDirectoryForTesting($this->spillDir);
    }

    protected function tearDown(): void
    {
        // Reap FIRST: every fixture child holds (or held) a bound loopback
        // socket and may still be writing request logs into $tmpDir — the
        // trait's ledger SIGKILLs+reaps survivors before any cleanup below.
        $this->reapTrackedForkedChildren();

        ToolOutputSpill::useDirectoryForTesting(null);
        // One level of `s-<session>/` directories is all the store ever makes.
        foreach (glob($this->spillDir . '/*') ?: [] as $saved) {
            if (is_dir($saved) && !is_link($saved)) {
                array_map('unlink', glob($saved . '/*') ?: []);
                @rmdir($saved);
            } else {
                @unlink($saved);
            }
        }
        @rmdir($this->spillDir);

        if (is_dir($this->tmpDir)) {
            array_map('unlink', glob($this->tmpDir . '/*'));
            rmdir($this->tmpDir);
        }
    }

    public function testAnHtmlTwoHundredBodyComesBackAsMarkdown(): void
    {
        $body = '<h1>Hi</h1><p>See <a href="/next">next</a>.</p>';
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse($body, 200, ['Content-Type: text/html; charset=utf-8']),
        ]);

        $content = $this->fixtureFetch($port)->execute(['url' => "http://md.test:$port/page"])->content();

        $this->assertSame("# Hi\n\nSee [next](http://md.test:$port/next).\n", $content);
    }

    public function testXhtmlIsConvertedAndRawFormatKeepsTheBytesVerbatim(): void
    {
        $body = '<h1>Hi</h1><p>See <a href="/next">next</a>.</p>';
        $response = $this->httpResponse($body, 200, ['Content-Type: application/xhtml+xml']);
        // One fixture connection per execute(): the raw call needs its own.
        $port = $this->startLoopbackHttpFixture([$response, $response]);

        $converted = $this->fixtureFetch($port)->execute(['url' => "http://md.test:$port/page"])->content();
        $this->assertSame("# Hi\n\nSee [next](http://md.test:$port/next).\n", $converted);

        $raw = $this->fixtureFetch($port)->execute(['url' => "http://md.test:$port/page", 'format' => 'raw'])->content();
        $this->assertSame($body, $raw);
    }

    public function testNonHtmlContentTypeStaysVerbatimEvenWhenTheBodyLooksLikeHtml(): void
    {
        // JSON, tarballs and every other payload the caller may parse by hand
        // must not be touched: the conversion keys on the claimed type only.
        $body = '<h1>Hi</h1><p>See <a href="/next">next</a>.</p>';
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse($body, 200, ['Content-Type: application/json']),
        ]);

        $content = $this->fixtureFetch($port)->execute(['url' => "http://md.test:$port/feed"])->content();

        $this->assertSame($body, $content);
    }

    public function testAnHtmlBodyWithoutAnyContentTypeClaimStaysVerbatim(): void
    {
        $body = '<h1>Hi</h1><p>See <a href="/next">next</a>.</p>';
        $port = $this->startLoopbackHttpFixture([$this->httpResponse($body)]);

        $content = $this->fixtureFetch($port)->execute(['url' => "http://md.test:$port/mystery"])->content();

        $this->assertSame($body, $content);
    }

    public function testLinksResolveAgainstTheFinalUrlAfterARedirect(): void
    {
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse('', 302, ['Location: /deep/target']),
            $this->httpResponse('<p>Text <a href="/redirected/../next">n</a></p>', 200, ['Content-Type: text/html']),
        ]);

        $content = $this->fixtureFetch($port)->execute(['url' => "http://md.test:$port/start"])->content();

        $this->assertSame("Text [n](http://md.test:$port/next)\n", $content);
    }

    public function testUnconvertibleHtmlFallsBackToRawBytesWithOneLeadingNote(): void
    {
        // Whitespace renders nothing, so there is nothing to convert; the raw
        // bytes are still the page that was asked for. The note rides the
        // out-of-budget prefix shape a status line uses, so the cap can never
        // eat the announcement.
        $body = "   \n  ";
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse($body, 200, ['Content-Type: text/html']),
        ]);

        $content = $this->fixtureFetch($port)->execute(['url' => "http://md.test:$port/blank"])->content();

        $this->assertSame("(HTML could not be converted to Markdown; the raw bytes follow.)\n" . $body, $content);
    }

    public function testAnUnknownFormatIsRefusedBeforeAnythingIsDialed(): void
    {
        // No fake resolver, no fixture: the default wiring would fail with a
        // different error if it reached the network, so the exact message
        // proves the refusal precedes the dial.
        $result = (new WebFetch())->execute(['url' => 'http://format.invalid.test/', 'format' => 'html']);

        $this->assertTrue($result->isError());
        $this->assertSame('Error: format must be "markdown" or "raw"', $result->content());
    }

    public function testAnErrorStatusBodyIsNotConverted(): void
    {
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse('<h1>Gone</h1>', 404, ['Content-Type: text/html']),
        ]);

        $result = $this->fixtureFetch($port)->execute(['url' => "http://md.test:$port/missing"]);

        $this->assertTrue($result->isError());
        $this->assertSame("HTTP 404\n<h1>Gone</h1>", $result->content());
    }

    /**
     * MINOR-2 cure end to end: a UTF-8 body that carries no in-document
     * charset declaration converts intact when the header declares one (the
     * hint reaches the converter), while rival charsets across two headers
     * guess nothing — the same refuse-to-decide posture as rival
     * Content-Length values — and the body falls to the parser's default.
     */
    public function testHeaderDeclaredCharsetReachesTheConverter(): void
    {
        $body = '<p>café</p>';

        $declared = $this->startLoopbackHttpFixture([
            $this->httpResponse($body, 200, ['Content-Type: text/html; charset=utf-8']),
        ]);
        $content = $this->fixtureFetch($declared)->execute(["url" => "http://md.test:$declared/utf8"])->content();
        $this->assertSame("café\n", $content, 'the header charset never reached the converter');

        $rival = $this->startLoopbackHttpFixture([
            $this->httpResponse($body, 200, [
                'Content-Type: text/html; charset=utf-8',
                'Content-Type: text/html; charset=iso-8859-1',
            ]),
        ]);
        $guessed = $this->fixtureFetch($rival)->execute(["url" => "http://md.test:$rival/rival"])->content();
        $this->assertStringNotContainsString('café', $guessed, 'a guessed charset contradicts the rival-claims law');
    }

    /**
     * A quoted charset parameter (`charset="utf-8"`) is legal HTTP and the
     * quotes must not poison the hint.
     */
    public function testQuotedCharsetParameterIsUnwrapped(): void
    {
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse('<p>naïve</p>', 200, ['Content-Type: text/html; charset="UTF-8"']),
        ]);

        $content = $this->fixtureFetch($port)->execute(["url" => "http://md.test:$port/quoted"])->content();

        $this->assertSame("naïve\n", $content);
    }

    public function testTheResultCapCountsTheConvertedTextNotTheRawBytes(): void
    {
        // 144,000 raw bytes of paragraph markup convert to far fewer bytes of
        // prose; the cap (and the marker's figures) must describe the text the
        // model actually sees, which is the conversion output.
        $body = str_repeat('<p>word count filler</p>', 6000);
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse($body, 200, ['Content-Type: text/html']),
        ]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['md.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
            saveOverflow: false,
        );

        $content = $webFetch->execute(['url' => "http://md.test:$port/big"])->content();

        $this->assertLessThanOrEqual(65536, strlen($content));
        $this->assertStringStartsWith("word count filler\n\nword count filler", $content);
        [$kept, , $total] = $this->truncationFigures($content);
        $this->assertSame(113999, $total, 'the marker names the converted length, not the raw one');
        $this->assertLessThan(strlen($body), $total);
        $this->assertGreaterThan(60000, $kept);
    }

    /**
     * Round-2 review MINOR cure: with both user byte settings below the
     * 512 KiB parse cap, a wire-truncated body still converts — and the
     * result must say the PAGE was truncated. The forced "complete" was
     * true only of the conversion, never of the fetch. saveOverflow:false
     * with a 64 KiB maxWireBytes is the same shape the two settings take
     * together; the 270,000-byte body leaves the read stopped at ~131 KiB,
     * inside the parse cap, with the stream still open.
     */
    public function testAWireTruncatedConvertedBodyDisclosesTheTruncation(): void
    {
        $body = str_repeat('<p>filler sentence here</p>', 10000);
        $port = $this->startLoopbackHttpFixture([
            $this->httpResponse($body, 200, ['Content-Type: text/html']),
        ]);

        $webFetch = new WebFetch(
            resolveAddresses: $this->fakeDns(['md.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
            saveOverflow: false,
            maxWireBytes: 65536,
        );

        $content = $webFetch->execute(['url' => "http://md.test:$port/truncated"])->content();

        $this->assertStringStartsWith(
            "(The page exceeded the fetch byte limit; the Markdown covers only the bytes fetched.)\n"
                . 'filler sentence here',
            $content,
            'a wire-truncated body was converted and shipped as complete with no disclosure',
        );
    }

    /**
     * A WebFetch wired to the loopback fixture: md.test resolves only to the
     * fixture address and nothing else can be dialed at all.
     */
    private function fixtureFetch(int $port): WebFetch
    {
        return new WebFetch(
            resolveAddresses: $this->fakeDns(['md.test' => ['127.0.0.2']]),
            isBlockedAddress: $this->fixtureOnlyBlocklist(),
        );
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
}
