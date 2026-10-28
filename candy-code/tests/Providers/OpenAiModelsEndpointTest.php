<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenAI\Contracts\ClientContract;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Providers\CustomProvider;
use SugarCraft\Code\Providers\HasModelCatalog;
use SugarCraft\Code\Providers\ModelCatalog;
use SugarCraft\Code\Providers\ModelMetadata;
use SugarCraft\Code\Providers\OpenAIProvider;
use SugarCraft\Code\Providers\OpenAiModelsEndpoint;
use SugarCraft\Code\Providers\SglangProvider;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * PRV-1 (§6.5): the shared `GET {base}/models` metadata read behind every
 * OpenAI-compatible catalog, and the provider plumbing that reaches it.
 * Nothing here touches a real server — the transport is mocked exactly like
 * {@see SglangServerInfoTest}.
 */
final class OpenAiModelsEndpointTest extends TestCase
{
    public function testAFullBodyAnswersTheIdsInServerOrder(): void
    {
        $ids = OpenAiModelsEndpoint::decode('{"object":"list","data":[{"id":"zeta-2","object":"model"},{"id":"alpha-1","object":"model"}]}');

        self::assertSame(['zeta-2', 'alpha-1'], $ids, 'the server order is the freshest truth; the palette sorts only its own merge');
    }

    public function testDecodeRefusesEveryUnusableShape(): void
    {
        self::assertNull(OpenAiModelsEndpoint::decode('not json at all'));
        self::assertNull(OpenAiModelsEndpoint::decode('[]'), 'a list body is not the documented object');
        self::assertNull(OpenAiModelsEndpoint::decode('{"object":"list"}'), 'no data key is unknown, not empty');
        self::assertNull(OpenAiModelsEndpoint::decode('{"data":[]}'), 'an empty catalog is indistinguishable from a refused route');
        self::assertNull(OpenAiModelsEndpoint::decode('{"data":[{"name":"no-id-field"}]}'), 'rows without ids answer nothing usable');
        self::assertSame(['ok'], OpenAiModelsEndpoint::decode('{"data":[{"id":"ok"},{"id":""},{"id":null},{"id":7}]}'), 'junk rows fall, real ids ride');
    }

    public function testFetchAsksModelsBesideTheChatRouteWithTheBearerKeyAndShortBound(): void
    {
        /** @var list<array{request: RequestInterface, options: array<string, mixed>}> $seen */
        $seen = [];
        $mock = new MockHandler([
            function (RequestInterface $request, array $options) use (&$seen): Response {
                $seen[] = ['request' => $request, 'options' => $options];

                return new Response(200, [], '{"data":[{"id":"gpt-5"}]}');
            },
        ]);

        $ids = OpenAiModelsEndpoint::fetch('https://api.openai.com/v1', 'sk-test', $mock);

        self::assertSame(['gpt-5'], $ids);
        self::assertCount(1, $seen);
        self::assertSame('https://api.openai.com/v1/models', (string) $seen[0]['request']->getUri(), 'the version root the base already carries is kept');
        self::assertSame('GET', $seen[0]['request']->getMethod());
        self::assertSame('Bearer sk-test', $seen[0]['request']->getHeaderLine('Authorization'));
        // A metadata read, so a TOTAL bound is right here (never on a
        // completion) — the ProviderConnectTimeoutTest exemption this file
        // is listed under.
        self::assertSame(OpenAiModelsEndpoint::DISCOVERY_TIMEOUT_SECONDS, $seen[0]['options']['timeout']);
        self::assertSame(OpenAiModelsEndpoint::DISCOVERY_TIMEOUT_SECONDS, $seen[0]['options']['connect_timeout']);
    }

    public function testFetchResolvesAgainstADeeperMountAndAKeylessBase(): void
    {
        $seen = [];
        $respond = function (RequestInterface $request) use (&$seen): Response {
            $seen[] = $request;

            return new Response(200, [], '{"data":[{"id":"m"}]}');
        };

        self::assertSame(['m'], OpenAiModelsEndpoint::fetch('https://proxy/sglang/v1/', null, new MockHandler([$respond])));
        self::assertSame('https://proxy/sglang/v1/models', (string) $seen[0]->getUri());
        self::assertFalse($seen[0]->hasHeader('Authorization'), 'no key, no header — not an empty Bearer');
    }

    public function testFetchDegradesToNullOnTransportAndServerFailures(): void
    {
        self::assertNull(OpenAiModelsEndpoint::fetch('http://unreachable.invalid', null, new MockHandler([
            new ConnectException('refused', new Request('GET', 'models')),
        ])));
        self::assertNull(OpenAiModelsEndpoint::fetch('https://api.openai.com/v1', 'sk-nope', new MockHandler([
            new Response(401, [], '{"error":{"message":"Incorrect API key"}}'),
        ])), 'a 401 is "no catalog", never a crash');
        self::assertNull(OpenAiModelsEndpoint::fetch('https://api.openai.com/v1', null, new MockHandler([
            new Response(200, [], '<html>a login portal, not JSON</html>'),
        ])));
    }

    public function testTheThreeOpenAiCompatibleTransportsSpeakForTheirCatalogs(): void
    {
        $client = new \GuzzleHttp\Client();

        $custom = new CustomProvider('hosted', 'https://gw.example/v1', 'm', 'k', $client, true, true);
        self::assertInstanceOf(HasModelCatalog::class, $custom);
        self::assertSame('https://gw.example/v1', $custom->catalogKey());

        $sglang = new SglangProvider('http://127.0.0.1:30000/v1', 'Qwen/Qwen3.8-Flash-Next-FP8', null, $client);
        self::assertInstanceOf(HasModelCatalog::class, $sglang);
        self::assertSame('http://127.0.0.1:30000/v1', $sglang->catalogKey());

        // The SDK seam carries no URL; the factory threads one. A bare
        // construction — embedders and tests — is "no catalog", never a
        // guess at api.openai.com.
        $bare = new OpenAIProvider($this->createMock(ClientContract::class), 'gpt-4o');
        self::assertNull($bare->catalogKey());
        self::assertNull($bare->listModels());
    }

    public function testTheOpenAiCatalogRidesTheThreadedBaseAndSurvivesRebinding(): void
    {
        $provider = new OpenAIProvider(
            $this->createMock(ClientContract::class),
            'gpt-4o',
            modelsBaseUrl: 'https://relay.example/v1/',
            modelsApiKey: 'sk-relay',
        );

        self::assertSame('https://relay.example/v1', $provider->catalogKey());

        // Rebinding the model must not drop the catalog threading —
        // withModel re-news through the whole property set.
        $rebound = $provider->withModel('gpt-5');
        self::assertSame('https://relay.example/v1', $rebound->catalogKey());
    }

    /**
     * PRV-1 (MINOR-1): the factory always threads the SDK-default root so
     * the catalog asks where the wire talks, but an unauthenticated GET of
     * api.openai.com/models is a guaranteed 401 — on palette open it must
     * spend no bytes, exactly like the `models all` door (whose comment
     * names the same rule: "an anonymous api.openai.com call … no bytes
     * spent"). The other polarities are the door's edges: a key in hand
     * makes the same root askable, and an EXPLICIT base without a key is a
     * self-hosted endpoint the operator named, so it still rides keyless.
     */
    public function testTheOpenAiCatalogDoorRefusesAKeylessAskAgainstThePublicApi(): void
    {
        $keyless = new OpenAIProvider(
            $this->createMock(ClientContract::class),
            'gpt-4o',
            modelsBaseUrl: OpenAIProvider::SDK_DEFAULT_BASE,
            modelsApiKey: null,
        );
        self::assertNull($keyless->catalogKey(), 'no credential means the public root is unaskable — no memo key, no fetch, no bytes');
        self::assertNull($keyless->listModels());

        $emptyKey = new OpenAIProvider(
            $this->createMock(ClientContract::class),
            'gpt-4o',
            modelsBaseUrl: OpenAIProvider::SDK_DEFAULT_BASE,
            modelsApiKey: '',
        );
        self::assertNull($emptyKey->catalogKey(), 'the empty string is as uncredentialed as null');

        $sloppy = new OpenAIProvider(
            $this->createMock(ClientContract::class),
            'gpt-4o',
            modelsBaseUrl: 'HTTPS://API.OPENAI.COM/v1/',
            modelsApiKey: null,
        );
        self::assertNull($sloppy->catalogKey(), 'case and trailing-slash noise on the public root still trips the door');

        $armed = new OpenAIProvider(
            $this->createMock(ClientContract::class),
            'gpt-4o',
            modelsBaseUrl: OpenAIProvider::SDK_DEFAULT_BASE,
            modelsApiKey: 'sk-real',
        );
        self::assertSame(OpenAIProvider::SDK_DEFAULT_BASE, $armed->catalogKey(), 'with a key the default root is exactly what is worth asking');
        self::assertSame(OpenAIProvider::SDK_DEFAULT_BASE, $armed->withModel('gpt-5')->catalogKey(), 'rebinding keeps the credential threading — and with it the door polarity');

        $relay = new OpenAIProvider(
            $this->createMock(ClientContract::class),
            'gpt-4o',
            modelsBaseUrl: 'https://relay.example/v1',
            modelsApiKey: null,
        );
        self::assertSame('https://relay.example/v1', $relay->catalogKey(), 'a configured non-default base rides keyless, as `models all` already asks it');
    }

    // ------------------------------------------------------------------
    // fx/palette-sec (plan-reverify-1026): the endpoint is untrusted input
    // ------------------------------------------------------------------

    /**
     * A malicious configured provider answers /v1/models with escape-carrying
     * ids. Every one is swept at {@see OpenAiModelsEndpoint::decode()} — the
     * single point the wire's bytes enter the process — because BOTH
     * consumers (the Ctrl+P model palette, whose row bodies renderPalette()
     * paints raw, and the `models all` table) read this list, and the palette
     * cannot sweep at paint time without desynchronising the
     * {@see \SugarCraft\Fuzzy\Highlighter} indices computed over the same
     * label. OSC-8 in both terminators, SGR, DCS, BEL, CR/LF, NUL and DEL
     * all fall; the visible text survives.
     */
    public function testHostileIdsAreSweptToControlFreeLabelsAtIngest(): void
    {
        $rows = [
            "\x1b]8;;https://evil.example\x07pwned"            => 'pwned',
            "\x1b]8;;https://evil.example\x1b\\linked"          => 'linked',
            "\x1b[31mred\x1b[0m-model"                          => 'red-model',
            "\x1bP1;2qdevice-control\x1b\\tail"                 => 'tail',
            "a\x07b"                                            => 'ab',
            "two\r\nlines"                                      => 'two lines',
            "gpt-4o\r\nnewline"                                 => 'gpt-4o newline',
            "nul\x00del\x7fb"                                   => 'nuldelb',
            "plain-model-v2"                                    => 'plain-model-v2',
            "Qwen/Qwen3.8-Flash-Next"                           => 'Qwen/Qwen3.8-Flash-Next',
        ];
        $body = json_encode(['data' => array_map(
            static fn(string $id): array => ['id' => $id],
            array_keys($rows),
        )], JSON_UNESCAPED_SLASHES);

        $ids = OpenAiModelsEndpoint::decode($body);

        self::assertSame(array_values($rows), $ids, 'every id lands as its control-free label, in server order');
        foreach ($ids as $id) {
            self::assertStringNotContainsString("\x1b", $id, 'no raw ESC may survive the ingest sweep');
            self::assertSame([], preg_grep('/[\x00-\x08\x0b-\x1f\x7f]/', [$id]), 'no C0/DEL byte may survive the ingest sweep');
        }
    }

    /**
     * An id that is NOTHING but an escape sequence sweeps to the empty
     * string and falls like the other unusable rows — a wire answer made
     * only of control bytes is "no catalog", never a blank clickable row.
     * The body spells the escapes the JSON way (`\u001b`), because a raw
     * control byte inside a JSON string is a malformed document that
     * json_decode refuses long before the catalog sees it.
     */
    public function testAnIdOfPureEscapesFallsRatherThanEnteringTheCatalog(): void
    {
        $ids = OpenAiModelsEndpoint::decode('{"data":[{"id":"\\u001b[31m"},{"id":"\\u001b]8;;x\\u0007"}]}');

        self::assertNull($ids, 'rows whose whole content is escape scaffolding leave nothing paintable');
    }

    /**
     * End-to-end through the fetch transport and the catalog merge the
     * palette consumes: the hostile row that reaches
     * {@see ModelCatalog::merge()} — and therefore renderPalette()'s row
     * body and the `models all` print loop — is the swept label, byte-clean.
     */
    public function testTheFetchedCatalogThePaletteMergesCarriesNoEscapeBytes(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '{"data":[{"id":"\\u001b]8;;https://evil\\u0007hostile-row"},{"id":"honest-row"}]}'),
        ]);

        $live = OpenAiModelsEndpoint::fetch('https://evil.example/v1', null, $mock);
        self::assertNotNull($live);

        $merged = ModelCatalog::merge(['configured-model'], $live, ['litellm-model']);

        self::assertSame(['configured-model', 'hostile-row', 'honest-row', 'litellm-model'], $merged);
        foreach ($merged as $label) {
            self::assertSame(0, preg_match('/[\x00-\x1b\x7f]/', $label), 'no row the palette or `models all` paints carries a control byte');
        }
    }

    /**
     * C-PRV-5.c: the async sibling. The transport seam receives the
     * composed URL and the header set — the same contract the sync pins
     * through Guzzle options — and the answer rides the shared decode
     * containment. Every sender here settles synchronously: no test in
     * this family ever runs the loop.
     */
    public function testFetchAsyncAsksTheModelsRouteBesideTheChatRouteAndCarriesTheCredential(): void
    {
        $seen = [];
        $sender = static function (string $url, array $headers) use (&$seen): PromiseInterface {
            $seen[] = [$url, $headers];

            return resolve(new Response(200, [], '{"data":[{"id":"relay-a"},{"id":"relay-b"}]}'));
        };

        $captured = 'unsettled';
        OpenAiModelsEndpoint::fetchAsync('https://relay.example/v1/', 'sk-relay', $sender)
            ->then(static function (?array $ids) use (&$captured): void {
                $captured = $ids;
            });

        self::assertCount(1, $seen, 'exactly one GET, answered synchronously through the seam');
        self::assertSame('https://relay.example/v1/models', $seen[0][0], 'a trailing-slash base resolves the route beside its own chat/completions — the sync read\'s rule, byte-for-byte');
        self::assertSame('application/json', $seen[0][1]['Accept']);
        self::assertSame('Bearer sk-relay', $seen[0][1]['Authorization'], 'a key in hand rides the async Bearer contract exactly as the sync one');
        self::assertSame(['relay-a', 'relay-b'], $captured, 'the promise carries the sync answer, in server order');

        OpenAiModelsEndpoint::fetchAsync('https://gw.example/v1', null, $sender)
            ->then(static fn (?array $ids): mixed => $ids);

        self::assertArrayNotHasKey('Authorization', $seen[1][1], 'no key, no header — the self-hosted keyless ride is the same rule as the sync door');
    }

    /**
     * The NEVER-REJECTS trio (plus two): every failure shape the sync read
     * answers null for resolves null here — the seam bypasses Browser's own
     * status refusal, so the callback re-applies it and a 500 body is
     * "unknown" even when it would have decoded. A rejection that escaped
     * would leave its case missing from the roster below, which fails the
     * identity assert, not the suite.
     */
    public function testFetchAsyncNeverRejectsAnyFailureTheSyncReadAlsoRefuses(): void
    {
        $settles = [];
        $record = static function (string $case) use (&$settles): \Closure {
            return static function (mixed $value) use ($case, &$settles): void {
                $settles[$case] = $value;
            };
        };

        $senders = [
            'http-500' => static fn (): PromiseInterface => resolve(new Response(500, [], '{"data":[{"id":"stolen-row"}]}')),
            'transport-error' => static fn (): PromiseInterface => reject(new \RuntimeException('connection refused by the host')),
            'junk-body' => static fn (): PromiseInterface => resolve(new Response(200, [], 'not-json-at-all')),
            'no-usable-rows' => static fn (): PromiseInterface => resolve(new Response(200, [], '{"data":[]}')),
        ];
        foreach ($senders as $case => $sender) {
            OpenAiModelsEndpoint::fetchAsync('https://evil.example/v1', null, $sender)->then($record($case));
        }

        $senders['sender-throws'] = static function (): PromiseInterface {
            throw new \LogicException('the seam itself exploded');
        };
        OpenAiModelsEndpoint::fetchAsync('https://evil.example/v1', null, $senders['sender-throws'])->then($record('sender-throws'));

        self::assertSame(['http-500', 'transport-error', 'junk-body', 'no-usable-rows', 'sender-throws'], array_keys($settles), 'every shape settles, none escapes a rejection');
        self::assertSame(array_fill_keys(array_keys($settles), null), $settles, 'every failure answers the same null the sync fetch returns — never a partial or refused catalog');
    }

    /**
     * THE POINT OF THE WHOLE STEP: the open does not wait. A pending
     * transport leaves the listener untouched until the answer lands, and
     * the answer landing is when the memo (and then the picker) moves —
     * late, never blocking. A late failure is a late null, never a
     * rejection.
     */
    public function testFetchAsyncAnswersOnSettleNotOnOpen(): void
    {
        $deferred = new Deferred();
        $captured = 'unsettled';
        OpenAiModelsEndpoint::fetchAsync('https://relay.example/v1', null, static fn (): PromiseInterface => $deferred->promise())
            ->then(static function (?array $ids) use (&$captured): void {
                $captured = $ids;
            });

        self::assertSame('unsettled', $captured, 'nothing has settled at open — the caller keeps moving');

        $deferred->resolve(new Response(200, [], '{"data":[{"id":"late-model"}]}'));

        self::assertSame(['late-model'], $captured, 'the answer rides the settle the moment it arrives');

        $broken = new Deferred();
        $failed = 'unsettled';
        OpenAiModelsEndpoint::fetchAsync('https://relay.example/v1', null, static fn (): PromiseInterface => $broken->promise())
            ->then(static function (?array $ids) use (&$failed): void {
                $failed = $ids;
            });
        $broken->reject(new \RuntimeException('the bounded budget ran out'));

        self::assertNull($failed, 'a late failure is the same "unknown" as the sync door, never a rejection');
    }

    /**
     * The containment is shared, not duplicated: a hostile escape-swept id
     * answers the async path through the SAME {@see OpenAiModelsEndpoint::decode()}
     * choke the sync fetch pins above.
     */
    public function testFetchAsyncSharesTheDecodeContainmentOfTheSyncRead(): void
    {
        $captured = null;
        OpenAiModelsEndpoint::fetchAsync('https://evil.example/v1', null, static fn (): PromiseInterface => resolve(
            new Response(200, [], '{"data":[{"id":"\\u001b]8;;https://evil\\u0007hostile-row"},{"id":"honest-row"}]}'),
        ))->then(static function (?array $ids) use (&$captured): void {
            $captured = $ids;
        });

        self::assertSame(['hostile-row', 'honest-row'], $captured, 'the async answer lands control-swept exactly like the sync one');
    }

    /**
     * The transports' async siblings keep the sync doors: the keyless
     * public-root refusal answers null WITHOUT constructing a read at all
     * (the seam is never reached), and the sglang ask threads URL and
     * keylessness through unchanged.
     */
    public function testTheAsyncProviderSiblingsThreadTheSeamAndKeepTheDoor(): void
    {
        $doorReached = false;
        $keyless = new OpenAIProvider(
            $this->createMock(ClientContract::class),
            'gpt-4o',
            modelsBaseUrl: OpenAIProvider::SDK_DEFAULT_BASE,
            modelsApiKey: null,
        );
        $answered = 'unsettled';
        $keyless->listModelsAsync(static function () use (&$doorReached): PromiseInterface {
            $doorReached = true;

            return resolve(new Response(200, [], '{"data":[]}'));
        })->then(static function (?array $ids) use (&$answered): void {
            $answered = $ids;
        });

        self::assertFalse($doorReached, 'the guaranteed-401 door spends no bytes on the async path either');
        self::assertNull($answered, 'the door answers null, settled immediately');

        $bare = new OpenAIProvider($this->createMock(ClientContract::class), 'gpt-4o');
        $bareAnswer = 'unsettled';
        $bare->listModelsAsync()->then(static function (?array $ids) use (&$bareAnswer): void {
            $bareAnswer = $ids;
        });
        self::assertNull($bareAnswer, 'no threaded base, no ask — the sync constructor contract holds async too');

        $sglang = new SglangProvider('http://127.0.0.1:30000/v1', 'Qwen/Qwen3.8-Flash-Next-FP8', null, new \GuzzleHttp\Client());
        $seen = [];
        $captured = 'unsettled';
        $sglang->listModelsAsync(static function (string $url, array $headers) use (&$seen): PromiseInterface {
            $seen[] = [$url, $headers];

            return resolve(new Response(200, [], '{"data":[{"id":"sg-a"}]}'));
        })->then(static function (?array $ids) use (&$captured): void {
            $captured = $ids;
        });

        self::assertSame('http://127.0.0.1:30000/v1/models', $seen[0][0]);
        self::assertArrayNotHasKey('Authorization', $seen[0][1], 'a keyless self-host rides the async read credential-free, like the sync one');
        self::assertSame(['sg-a'], $captured);
    }

    /**
     * The catalog's async dispatcher: typed to the two transports that
     * answer async, guarded by the same three refusals as the sync prime,
     * and IN FLIGHT it pins the once-per-process rule across the async
     * window — the placeholder memo stamp means a second open (or the CLI
     * listing) never starts a parallel dial into the same base.
     */
    public function testPrefetchAsyncDispatchesForAsyncTransportsAndPinsTheInFlightMemo(): void
    {
        $original = getenv(ModelMetadata::OPT_OUT_ENV);
        ModelCatalog::resetForTesting();
        putenv(ModelMetadata::OPT_OUT_ENV . '=0');

        try {
            $syncOnly = new class implements HasModelCatalog {
                public function catalogKey(): ?string
                {
                    return 'https://sync-only.example/v1';
                }

                public function listModels(): ?array
                {
                    return ['tier-of-a-sync-transport'];
                }
            };
            self::assertNull(ModelCatalog::prefetchAsync($syncOnly), 'a transport without an async path answers "nothing started" — its caller stays on the sync prime, byte-identical');

            $deferred = new Deferred();
            $sglang = new SglangProvider('http://127.0.0.1:30001/v1', 'm', null, new \GuzzleHttp\Client());
            $inflight = ModelCatalog::prefetchAsync($sglang, static fn (): PromiseInterface => $deferred->promise());
            self::assertNotNull($inflight, 'the OpenAI-compatible transports do dispatch');
            self::assertNull(ModelCatalog::live($sglang), 'while the read is in flight the memo carries the placeholder — the existing "answered nothing" stamp');
            self::assertNull(ModelCatalog::prefetchAsync($sglang), 'in flight, the once-per-process rule holds: the placeholder IS a settled key, and a second open does not dial in parallel');

            $deferred->resolve(new Response(200, [], '{"data":[{"id":"newly-live"}]}'));
            self::assertSame(['newly-live'], ModelCatalog::live($sglang), 'the answer overwrites the placeholder in the memo before anything downstream can see it — the palette derives rows from the memo on the repaint');
            self::assertNull(ModelCatalog::prefetchAsync($sglang), 'a settled answer is the same memo key the sync prime would have written');

            putenv(ModelMetadata::OPT_OUT_ENV . '=1');
            $gated = new SglangProvider('http://127.0.0.1:30002/v1', 'm', null, new \GuzzleHttp\Client());
            self::assertNull(ModelCatalog::prefetchAsync($gated), 'the discovery opt-out stands down the async sibling too — air-gapped hosts stay silent on every path');
        } finally {
            ModelCatalog::resetForTesting();
            if ($original === false) {
                putenv(ModelMetadata::OPT_OUT_ENV);
            } else {
                putenv(ModelMetadata::OPT_OUT_ENV . '=' . $original);
            }
        }
    }
}
