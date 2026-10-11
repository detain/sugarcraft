<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\MediaForm;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Tui\MediaForm\ChoiceFeed;
use SugarCraft\Crush\Tui\MediaForm\FieldSpec;

/**
 * W7 seam cure: the choice-feed hops are media egress like generation, so
 * they must carry the configured credential into Client::authHeaders() —
 * the single header-minting choke point. Before the cure the feed dialled
 * keyless and an auth-guarded server answered 401 into the fail-open door,
 * silently keeping the enum rosters. These pins drive the four listing hops
 * through a Guzzle history double (same shape as GuzzleTransportTest) and
 * read the Authorization header off the wire, hop by hop.
 */
final class ChoiceFeedTest extends TestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $egressHistory = [];

    protected function setUp(): void
    {
        \putenv(Client::BASE_URL_ENV . '=http://sd.test:7860');
        ChoiceFeed::resetForTesting();
    }

    protected function tearDown(): void
    {
        \putenv(Client::BASE_URL_ENV);
        ChoiceFeed::resetForTesting();
        $this->egressHistory = [];
    }

    public function testEveryListingHopCarriesTheConfiguredBearerKey(): void
    {
        $choices = ChoiceFeed::forForm(
            ['sd.apiKey' => 'image-era-key'],
            $this->fakeListingServer(),
        );

        self::assertNotNull($choices, 'the four queued listings must produce the feed');
        self::assertSame(['Euler a', 'DPM++ 2M'], $choices[FieldSpec::CHOICES_SAMPLERS]);
        self::assertSame(['Karras'], $choices[FieldSpec::CHOICES_SCHEDULERS]);
        self::assertContains('ServerUpscaler', $choices[FieldSpec::CHOICES_UPSCALERS], 'server names merge on the latent floor');
        self::assertSame(['checkpoint-a.safetensors'], $choices[FieldSpec::CHOICES_MODELS]);

        self::assertCount(4, $this->egressHistory, 'samplers, schedulers, upscalers, sd-models');
        foreach ($this->egressHistory as $hop) {
            self::assertSame(
                'Bearer image-era-key',
                $hop['request']->getHeaderLine('Authorization'),
                'every hop on ' . $hop['request']->getUri()->getPath(),
            );
        }
    }

    public function testTheMediaKeyBeatsTheImageEraSiblingOnTheLadder(): void
    {
        // GenerateVideo's documented ladder: mediaApiKey first, sd.apiKey as
        // the image-era fallback — one credential serves both surfaces.
        $choices = ChoiceFeed::forForm(
            ['mediaApiKey' => 'media-first', 'sd.apiKey' => 'image-era'],
            $this->fakeListingServer(),
        );

        self::assertNotNull($choices);
        foreach ($this->egressHistory as $hop) {
            self::assertSame('Bearer media-first', $hop['request']->getHeaderLine('Authorization'));
        }
    }

    public function testAKeylessHostEgressesNoAuthorizationHeaderAtAll(): void
    {
        // The fail-open contract stays: no key is a legitimate answer, and
        // authHeaders(null) must mint NOTHING rather than an empty header.
        $choices = ChoiceFeed::forForm([], $this->fakeListingServer());

        self::assertNotNull($choices);
        self::assertCount(4, $this->egressHistory);
        foreach ($this->egressHistory as $hop) {
            self::assertFalse(
                $hop['request']->hasHeader('Authorization'),
                'keyless means keyless on ' . $hop['request']->getUri()->getPath(),
            );
            self::assertSame('application/json', $hop['request']->getHeaderLine('Accept'));
        }
    }

    /**
     * The four hops in forForm() order, each answered with one JSON list,
     * every egress recorded for header inspection.
     *
     * @return array<string, mixed>  clientOptions for forForm()
     */
    private function fakeListingServer(): array
    {
        $json = static fn (string $body): Response => new Response(200, ['Content-Type' => 'application/json'], $body);
        $stack = HandlerStack::create(new MockHandler([
            $json('[{"name":"Euler a"},{"name":"DPM++ 2M"}]'),
            $json('[{"name":"Karras"}]'),
            $json('[{"name":"ServerUpscaler"}]'),
            $json('[{"title":"checkpoint-a.safetensors"}]'),
        ]));
        $stack->push(Middleware::history($this->egressHistory));

        return ['handler' => $stack];
    }
}
