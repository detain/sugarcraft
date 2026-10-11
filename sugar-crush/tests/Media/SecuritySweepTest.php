<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Media;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Media\Capability\MediaAffordance;
use SugarCraft\Crush\Media\Infotext;
use SugarCraft\Crush\Media\Png;
use SugarCraft\Crush\Media\Sd\CallableSdTransport;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Media\Sd\GuzzleTransport;
use SugarCraft\Crush\Media\Sd\SdException;
use SugarCraft\Crush\Media\Sd\UrlGuard;
use SugarCraft\Crush\Media\Video\JobClient;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Protocol\Methods\MediaMethods;
use SugarCraft\Crush\Support\ClipboardImage;
use SugarCraft\Crush\Support\MediaStore;
use SugarCraft\Crush\Support\PrivateDir;
use SugarCraft\Crush\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Crush\Tests\Server\Support\WireClient;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tools\BuiltIn\GenerateImage;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tui\MediaForm\MediaPresets;

/**
 * Plan W7.3 (lane SECURITY): executable pins for the media feature's attack
 * surface, audited read-only against src as landed on master. Every test here
 * is a claim that the shipped shape is SAFE; where the audit found a hole the
 * pin is deliberately absent and the finding rides
 * /tmp/opencode/plan-crush-media/w7/security/REPORT.md as FIX-NEEDED with
 * reproduction steps (lane law: no committed reds).
 *
 * Six areas, mirroring the brief: (1) SSRF/egress, (2) path jail,
 * (3) secrets, (4) hostile infotext/PNG, (5) clipboard hygiene,
 * (6) consent × idempotency.
 */
final class SecuritySweepTest extends TestCase
{
    use HomeSandboxTrait;

    /** Canary credential: any appearance in an outbound message fails the suite. */
    private const CANARY = 'CANARY-KEY-7f3a9b-do-not-leak';

    private string $homeRoot = '';

    private ?ProtocolFixture $fixture = null;

    private WireClient $client;

    private string $sessionId = '';

    /** Render tally shared with the stub tool (object handle — by-ref params rebind, they never alias). */
    private \stdClass $counter;

    protected function setUp(): void
    {
        $this->homeRoot = sys_get_temp_dir() . '/w7sec-' . \uniqid('', true);
        $this->useHomeSandbox($this->homeRoot);
        // Same discipline as the W6.2 suite: the env door must be shut or a
        // real launch's SD server would silently receive these pins' dials.
        putenv(Client::BASE_URL_ENV);
    }

    protected function tearDown(): void
    {
        MediaMethods::resetForTesting();
        MediaAffordance::resetForTesting();
        $this->fixture?->tearDown();
        $this->restoreHomeSandbox();
        $this->purge($this->homeRoot);
    }

    // ── area 1: SSRF / egress ─────────────────────────────────────────

    /**
     * The server's own task id travels into the job route ONLY through
     * rawurlencode (JobClient::jobPath): traversal shapes cannot open a new
     * segment, and the guard pins the origin of whatever path results.
     */
    public function testServerTaskIdCannotOpenSegmentsOnTheJobRoute(): void
    {
        $dials = [];
        $transport = new CallableSdTransport(static function (string $method, string $path) use (&$dials): array {
            $dials[] = [$method, $path];

            return ['status' => 200, 'body' => '{}'];
        });
        $client = JobClient::withBase('http://sd.test:7860', $transport);

        $client->cancel('../../etc/passwd');

        self::assertCount(1, $dials, 'one dial, no follow-ups');
        self::assertSame('/v1/videos/..%2F..%2Fetc%2Fpasswd', $dials[0][1], 'slashes are encoded into the segment, never re-opened as one');
    }

    /**
     * http(s) init-image URLs pass through the client UNFETCHED: the client
     * dials exactly once, at the configured origin, and forwards the URL as
     * data. (Who eventually fetches that URL is the SD server's egress, not
     * ours — judgment documented in the lane REPORT.)
     */
    public function testInitImageUrlPassesThroughTheClientUnfetched(): void
    {
        $dials = [];
        $transport = new CallableSdTransport(static function (string $method, string $path, array $json) use (&$dials): array {
            $dials[] = [$method, $path, $json];

            return ['status' => 200, 'body' => '{"images":[]}'];
        });
        $client = Client::withBase('http://sd.test:7860', $transport);

        $client->img2img(['prompt' => 'x', 'init_images' => ['http://elsewhere.invalid:9999/x.png']]);

        self::assertCount(1, $dials, 'the client fetched nothing; it forwarded once');
        self::assertSame('http://elsewhere.invalid:9999/x.png', $dials[0][2]['init_images'][0], 'the URL rides as wire data, verbatim');
        self::assertSame('/sdapi/v1/img2img', $dials[0][1]);
    }

    /**
     * A protocol-relative Location ('//host/...') is an authority swap
     * wearing a relative hat; the per-hop guard re-check refuses it and the
     * hostile host is never dialled.
     */
    public function testProtocolRelativeRedirectLocationIsRefusedPerHop(): void
    {
        $mock = new MockHandler([
            new PsrResponse(302, ['Location' => '//evil.test/collect']),
            new PsrResponse(200, [], '{"should":"never-run"}'),
        ]);
        $stack = HandlerStack::create($mock);
        $seen = [];
        $stack->push(Middleware::history($seen));
        $transport = new GuzzleTransport(UrlGuard::forBase('http://sd.test:7860'), [], ['handler' => $stack]);

        try {
            $transport->request('GET', '/sdapi/v1/options');
            self::fail('expected the double-slash Location to be refused');
        } catch (SdException $e) {
            self::assertNotSame('', $e->getMessage(), 'the refusal says why');
        }

        self::assertCount(1, $seen, 'exactly one real dial: the guard refused before the second was issued');
    }

    // ── area 2: path jail ─────────────────────────────────────────────

    /**
     * Percent-encoding is not decoded anywhere on the fetch path, so a
     * '%2e%2e%2f' traversal is just a weird filename: realpath fails, the
     * answer is not_found, and the refusal never echoes what was probed.
     */
    public function testPercentEncodedTraversalAtFetchPathResolvesToNothing(): void
    {
        $this->armRpc();
        $sessionDir = MediaStore::forSession($this->sessionId)->directoryPath();

        $result = $this->client->request('media.fetch', [
            'sessionId' => $this->sessionId,
            'path' => $sessionDir . '/%2e%2e%2f%2e%2e%2fetc%2fpasswd',
        ]);

        self::assertArrayHasKey('error', $result);
        self::assertStringNotContainsString('passwd', (string) json_encode($result), 'the refusal names nothing it probed');
    }

    /**
     * PrivateDir::ensure refuses a planted symlink and a loose-mode directory
     * for the FINAL component it owns. (The ancestor-chain gap — a symlinked
     * parent planted pre-launch — is FIX-NEEDED in the lane REPORT,
     * single-owner model, low severity.)
     */
    public function testPrivateDoorRefusesPlantedSymlinkAndLooseMode(): void
    {
        $root = $this->homeRoot . '/pd-' . \uniqid('', true);
        mkdir($root, 0o700, true);
        $real = $root . '/real';
        mkdir($real, 0o700);
        $link = $root . '/link';
        symlink($real, $link);

        try {
            PrivateDir::ensure($link, 'media store');
            self::fail('a symlinked store directory must be refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('symbolic link', $e->getMessage());
        }

        $loose = $root . '/loose';
        mkdir($loose, 0o755);
        chmod($loose, 0o755);
        $this->expectException(\RuntimeException::class);
        PrivateDir::ensure($loose, 'media store');
    }

    /**
     * @return array<non-empty-string, non-empty-string>
     */
    public static function hostileArtifactIdProvider(): array
    {
        return [
            'relative traversal' => ['../../etc/passwd'],
            'encoded traversal' => ['..%2f..%2fetc%2fpasswd'],
            'inner slash' => ['jobs/deadbeefdeadbeef'],
            'absolute path' => ['/etc/passwd'],
            'dot-dot alone' => ['..'],
            'deep dot-dot run' => ['....//....//etc/passwd'],
        ];
    }

    /** @dataProvider hostileArtifactIdProvider */
    public function testCraftedArtifactIdNeverReadsOutsideTheStore(string $artifactId): void
    {
        $this->armRpc();

        $result = $this->client->request('media.fetch', [
            'sessionId' => $this->sessionId,
            'artifactId' => $artifactId,
        ]);

        self::assertArrayHasKey('error', $result, 'hostile ids answer errors, never bytes');
        self::assertStringNotContainsString('"bytes"', (string) json_encode($result));
    }

    /**
     * Session isolation at the RPC door: a sibling session's legitimate
     * absolute path is not_found through this session's anchor.
     */
    public function testSiblingSessionPathIsNotFound(): void
    {
        $this->armRpc();
        $mine = MediaStore::forSession($this->sessionId);
        $theirs = MediaStore::forSession('victim-' . \uniqid('', true));
        $artifact = $theirs->put('sibling bytes', 'png');

        $result = $this->client->request('media.fetch', [
            'sessionId' => $this->sessionId,
            'path' => (string) $artifact->path(),
        ]);

        self::assertArrayHasKey('error', $result);
        self::assertStringNotContainsString('sibling bytes', (string) json_encode($result));
        self::assertNotSame(\dirname((string) $artifact->path()), $mine->directoryPath());
    }

    // ── area 3: secrets ───────────────────────────────────────────────

    /**
     * A failed render echoes context, status, and the server's own error and
     * detail — never the request we sent: neither the bearer key nor the
     * prompt text can reach the tool content through the error path.
     */
    public function testFailedRenderLeaksNeitherKeyNorPromptIntoToolContent(): void
    {
        $transport = new CallableSdTransport(static fn (): array => [
            'status' => 400,
            'body' => '{"error":"Validation error","detail":"bad cfg_scale"}',
        ]);
        $tool = GenerateImage::new()->withFakeDial($transport, 'http://sd.test:7860', [
            'sd.apiKey' => self::CANARY,
            'sd.baseUrl' => 'http://sd.test:7860',
        ]);

        $result = $tool->execute(['prompt' => 'a prompt nobody may see ' . self::CANARY]);

        self::assertTrue($result->isError(), $result->content());
        self::assertStringNotContainsString(self::CANARY, $result->content());
        self::assertStringContainsString('HTTP 400', $result->content());
    }

    /**
     * sdapi echoes the WHOLE request body (including override_settings) in
     * 400 envelopes; the message builder keeps the body field OUT of the
     * message on purpose. The raw body survives only behind its accessor.
     */
    public function testSdapiEnvelopeKeepsTheEchoedRequestBodyOutOfTheMessage(): void
    {
        $echo = '{"error":"E","detail":"D","body":{"prompt":"' . self::CANARY . '","override_settings":{"scheduler":"x"}}}';
        $decoded = json_decode($echo, true);
        self::assertIsArray($decoded);
        $e = SdException::fromResponse('sd txt2img', 400, $decoded, $echo);

        self::assertStringNotContainsString(self::CANARY, $e->getMessage());
        self::assertStringNotContainsString('override_settings', $e->getMessage());
        self::assertStringContainsString(self::CANARY, $e->rawBody(), 'the accessor keeps it for diagnosis; the message does not');
    }

    /**
     * Transport failures embed the Guzzle exception's message — a URL and a
     * cURL reason, never the header set: an Authorization value on the failed
     * request cannot ride into our exception text.
     */
    public function testTransportFailureCarriesUrlNeverHeaders(): void
    {
        $request = new PsrRequest('POST', 'http://sd.test:7860/sdapi/v1/txt2img', [
            'Authorization' => 'Bearer ' . self::CANARY,
        ]);
        $e = SdException::transportFailure(
            'sd transport POST /sdapi/v1/txt2img',
            new ConnectException('cURL error 7: Failed to connect', $request),
        );

        self::assertStringNotContainsString(self::CANARY, $e->getMessage());
        self::assertStringContainsString('cURL error 7', $e->getMessage());
    }

    /**
     * A base URL carrying embedded credentials is refused at parse time and
     * the refusal is redacted end-to-end: through the tool layer the operator
     * sees the failure without ever seeing the password back.
     */
    public function testCredentialBearingBaseUrlIsRefusedRedacted(): void
    {
        $transport = new CallableSdTransport(static fn (): array => ['status' => 200, 'body' => '{}']);
        $tool = GenerateImage::new()->withFakeDial($transport, 'http://user:' . self::CANARY . '@sd.test:7860', []);

        $result = $tool->execute(['prompt' => 'anything']);

        self::assertTrue($result->isError(), $result->content());
        self::assertStringNotContainsString(self::CANARY, $result->content());
    }

    /**
     * authHeaders shape (the one place the key is deliberately materialized):
     * bare key → Bearer, colon-bearing key → Basic of the whole pair, and
     * blank/absent → no header at all.
     */
    public function testAuthHeaderShapesAreTheDocumentedLadder(): void
    {
        self::assertSame([], Client::authHeaders(null));
        self::assertSame([], Client::authHeaders('   '));
        self::assertSame(
            ['Authorization' => 'Bearer ' . self::CANARY],
            Client::authHeaders(self::CANARY),
        );
        self::assertSame(
            ['Authorization' => 'Basic ' . base64_encode('user:pw')],
            Client::authHeaders('user:pw'),
        );
    }

    /**
     * W3.9 secret scrub at preset save, W7 security widening: every key
     * matching the deny-shaped pattern is dropped before the bundle lands in
     * settings — the 'auth' stem now covers the 'authorization' and
     * 'x-auth-*' spellings and 'cookie' joins the roster, so a hand-edited
     * preset feed cannot smuggle a session credential into the manifest.
     */
    public function testPresetSaveScrubsWithTheDenyShapePattern(): void
    {
        $store = MediaPresets::empty()->save('demo', [
            'apiKey' => self::CANARY,
            'api_base' => 'http://leak.test',
            'access_token' => 'tok-' . self::CANARY,
            'client_secret' => 'sec-1',
            'PASSWORD' => 'pw-1',
            'authorization' => 'Bearer ' . self::CANARY,
            'Cookie' => 'sid=' . self::CANARY,
            'seed' => 5,
            'cfg_scale' => 7,
        ]);

        $values = $store->apply('demo');
        self::assertSame(['seed' => 5, 'cfg_scale' => 7], $values);
        self::assertStringNotContainsString(self::CANARY, (string) json_encode($store->toStored()));
    }

    /**
     * W6.7 hello flags privacy: the wire carries a sha256 prefix of the
     * lowercase host — never the host itself.
     */
    public function testHelloFlagsPublishHostHashNotTheHost(): void
    {
        $flags = MediaAffordance::flags([], 'http://My-GPU-Box.invalid:7860');
        self::assertSame(
            substr(hash('sha256', 'my-gpu-box.invalid'), 0, MediaAffordance::HOST_HASH_CHARS),
            $flags['baseUrlHostOnly'],
        );
        self::assertStringNotContainsString('gpu-box', $flags['baseUrlHostOnly']);
        self::assertTrue($flags['image']);

        // The door BOOLEANS for sglang deliberately go unasserted here: the
        // W7 sglang adapter is landing in a sibling lane and its implemented
        // flip is affordance wiring, not a security invariant. What the
        // security pin owns is the hash: family-independent, lowercase host,
        // never the host itself — true before and after that flip.
        $sglang = MediaAffordance::flags(['mediaFamily' => 'sglang-diffusion'], 'https://gpu-box.invalid');
        self::assertSame(
            substr(hash('sha256', 'gpu-box.invalid'), 0, MediaAffordance::HOST_HASH_CHARS),
            $sglang['baseUrlHostOnly'],
        );
        self::assertStringNotContainsString('gpu-box', $sglang['baseUrlHostOnly']);

        $dark = MediaAffordance::flags([], null);
        self::assertFalse($dark['image']);
        self::assertFalse($dark['video']);
        self::assertNull($dark['baseUrlHostOnly']);
    }

    // ── area 4: hostile infotext / PNG ────────────────────────────────

    /**
     * The parser is total: pathological payloads — 5k open brackets, a 2 MB
     * single token, an unterminated quote, ESC bytes — answer arrays, never
     * exceptions, never recursion blowups (the bracket walk is iterative).
     */
    public function testHostileInfotextPayloadsParseWithoutThrowing(): void
    {
        self::assertIsArray(Infotext::parse(str_repeat('[', 5000)));
        self::assertIsArray(Infotext::parse('prompt: ' . str_repeat('x', 2_000_000)));
        self::assertIsArray(Infotext::parse("negative: \"unterminated\nsteps: 20"));
        self::assertIsArray(Infotext::parse("model: \x1b[31mred\x1b[0m, seed: 7"));
    }

    /**
     * Hostile token VALUES reaching filename expansion: slashes collapse to
     * dashes (NAME_ALPHABET), escape bytes cannot survive into the name, and
     * the artifact lands inside the session directory whatever the tokens
     * claimed.
     */
    public function testTokenExpandedNamesStripSlashesAndEscapeBytes(): void
    {
        $root = $this->homeRoot . '/store-' . \uniqid('', true);
        mkdir($root, 0o700, true);
        $store = MediaStore::forSession('sweep', $root, 'art-[model_name]-[seed]');

        $artifact = $store->put('PNG bytes', 'png', [
            'model_name' => "\x1b[31m../../etc/passwd\x07",
            'seed' => 7,
        ]);

        $name = basename((string) $artifact->path());
        self::assertStringNotContainsString(chr(27), $name);
        self::assertStringNotContainsString(chr(7), $name);
        self::assertStringNotContainsString('/', substr($name, 0, -4), 'no segment separator survives');
        self::assertStringStartsWith($root . '/sweep/', (string) $artifact->path());
        self::assertSame('PNG bytes', file_get_contents((string) $artifact->path()));
    }

    /**
     * A stream cut mid-chunk is damage, not danger: the reader answers null
     * without throwing and without chasing the claimed length past EOF.
     */
    public function testTruncatedChunkStreamReadsNullNotHang(): void
    {
        $signature = chr(137) . 'PNG' . chr(13) . chr(10) . chr(26) . chr(10);
        $halfChunk = "\x00\x00\x00\x0dIHDR\x00\x00\x02\x80\x00\x00\x02\x80";

        self::assertNull(Png::readText($signature . $halfChunk));
        self::assertNull(Png::readText($signature));
        self::assertNull(Png::readText('not even close to png'));
    }

    // ── area 5: clipboard hygiene ─────────────────────────────────────

    /**
     * Bytes that do not sniff as an image are unlinked at once: a refused
     * paste leaves zero residue in the temp directory (W4.2 law).
     */
    public function testSniffRefusedPasteBytesLeaveNoResidue(): void
    {
        $dir = $this->homeRoot . '/pastes-' . \uniqid('', true);
        ClipboardImage::useDirectoryForTesting($dir);
        ClipboardImage::useCandidatesForTesting([['xclip']]);
        ClipboardImage::useRunnerForTesting(static function (array $argv, string $dest): bool {
            file_put_contents($dest, 'plain text masquerading as an image');

            return true;
        });

        try {
            self::assertNull(ClipboardImage::save());
            $leftovers = is_dir($dir)
                ? array_values(array_filter(scandir($dir), static fn (string $e): bool => $e !== '.' && $e !== '..'))
                : [];
            self::assertSame([], $leftovers, 'a refused paste persists nothing');
        } finally {
            ClipboardImage::useRunnerForTesting(null);
            ClipboardImage::useCandidatesForTesting(null);
            ClipboardImage::useDirectoryForTesting(null);
        }
    }

    // ── area 6: consent × idempotency ─────────────────────────────────

    /**
     * A DENIED submit costs the idempotency cache nothing: the same key after
     * a policy change runs for real (renders == 1, a fresh jobId). Revocation
     * semantics can never be answered by a remembered refusal, because
     * refusals are never remembered.
     */
    public function testDeniedSubmitIsNotRememberedByTheIdempotencyCache(): void
    {
        $this->armRpc();
        MediaMethods::setGateForTesting(new PermissionGate(PermissionMode::DontAsk));
        $key = 'denied-first-' . \uniqid('', true);

        $denied = $this->client->request('generate.submit', [
            'sessionId' => $this->sessionId,
            'prompt' => 'denied first',
            'idempotencyKey' => $key,
        ]);
        self::assertArrayHasKey('error', $denied);
        self::assertStringContainsString('permission_denied', (string) json_encode($denied));
        self::assertSame(0, $this->counter->renders, 'a denied submit renders nothing');

        MediaMethods::setGateForTesting(new PermissionGate(PermissionMode::BypassPermissions));
        $allowed = $this->client->request('generate.submit', [
            'sessionId' => $this->sessionId,
            'prompt' => 'denied first',
            'idempotencyKey' => $key,
        ]);
        self::assertArrayHasKey('result', $allowed, 'the refusal was not cached: the retried key runs once consent changes');
        self::assertStringContainsString('jobId', (string) json_encode($allowed));
        self::assertSame(1, $this->counter->renders);
    }

    /**
     * A replayed accepted submit answers from the cache WITHOUT re-invoking
     * the tool: one render, byte-identical jobId — the double-spend a dropped
     * connection would otherwise cause is structurally gone.
     */
    public function testReplayOfAcceptedSubmitNeverRendersTwice(): void
    {
        $this->armRpc();
        $key = 'replay-' . \uniqid('', true);

        $first = $this->client->call('generate.submit', [
            'sessionId' => $this->sessionId,
            'prompt' => 'render once',
            'idempotencyKey' => $key,
        ]);
        $second = $this->client->call('generate.submit', [
            'sessionId' => $this->sessionId,
            'prompt' => 'render once',
            'idempotencyKey' => $key,
        ]);

        self::assertSame($first['jobId'], $second['jobId']);
        self::assertSame(1, $this->counter->renders, 'the cache answered; the handler never ran twice');
    }

    /**
     * Revoke-after-success: replaying the accepted key while the gate now
     * denies still answers from cache (the job already launched — replay is
     * not a new action). Pinned so the semantics stay honest and a future
     * "purge answers on revoke" change must say so here first.
     */
    public function testRevokeAfterSuccessStillReplaysTheAcceptedAnswer(): void
    {
        $this->armRpc();
        $key = 'revoke-' . \uniqid('', true);

        $first = $this->client->call('generate.submit', [
            'sessionId' => $this->sessionId,
            'prompt' => 'accepted then revoked',
            'idempotencyKey' => $key,
        ]);
        MediaMethods::setGateForTesting(new PermissionGate(PermissionMode::DontAsk));
        $replay = $this->client->call('generate.submit', [
            'sessionId' => $this->sessionId,
            'prompt' => 'accepted then revoked',
            'idempotencyKey' => $key,
        ]);

        self::assertSame($first['jobId'], $replay['jobId']);
        self::assertSame(1, $this->counter->renders);
    }

    // ── harness ───────────────────────────────────────────────────────

    /** Wires the RPC stack with a stubbed render that tallies executions. */
    private function armRpc(): void
    {
        $counter = new \stdClass();
        $counter->renders = 0;
        $this->counter = $counter;

        $this->fixture = ProtocolFixture::new();
        $this->client = $this->fixture->client();
        $this->sessionId = $this->fixture->session($this->client);

        MediaMethods::setGateForTesting(new PermissionGate(PermissionMode::BypassPermissions));
        MediaMethods::setDirectRunForTesting(true);
        MediaMethods::setToolFactoryForTesting(static function (string $sessionId, ?WorkspaceContext $workspace) use ($counter): Tool {
            return new class($sessionId, $counter) implements Tool {
                public function __construct(
                    private readonly string $sessionId,
                    private readonly \stdClass $counter,
                ) {
                }

                public function name(): string
                {
                    return GenerateImage::NAME;
                }

                public function description(): string
                {
                    return 'stub render for W7.3 security pins';
                }

                public function inputSchema(): array
                {
                    return GenerateImage::new()->inputSchema();
                }

                public function execute(array $args): ToolResult
                {
                    $this->counter->renders++;
                    $artifact = MediaStore::forSession($this->sessionId)->put('sweep bytes', 'png');

                    return new ToolResult('w7sec-stub', 'image 1: ' . (string) $artifact->path(), false);
                }
            };
        });
    }

    /** Best-effort recursive purge of this test's temp tree. */
    private function purge(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_link($child) || is_file($child)) {
                @unlink($child);
            } else {
                $this->purge($child);
            }
        }
        @rmdir($path);
    }
}
