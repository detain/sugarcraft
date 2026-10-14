<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Exception\ConnectException;
use OpenAI\Exceptions\TransporterException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\OpenAIProvider;
use SugarCraft\Crush\Providers\ProviderFactory;

/**
 * rv-enduser-3 (fix 6): provider errors were written for the log, not the
 * human. "Provider type 'openai' requires 'apiKey' to be set" named neither
 * the environment variable that actually feeds that key nor the config path
 * that now overrides it; "Unknown provider type: opnai" listed nothing and
 * suggested nothing; and an unreachable endpoint surfaced as the Guzzle/SDK
 * exception with the request URL — and sometimes the echoed request — in its
 * message.
 *
 * These pins hold the copy contract: the ORIGINAL sentence stays a
 * byte-identical prefix everywhere (Integration dispatch and slash pins grep
 * for it), the hint rides behind it, the unknown-type refusal lists the
 * valid types plus a `similar_text` nearest match (only when one is
 * genuinely near), and the transport conversion never carries the SDK's
 * "(see ...)" doc clause — because a failure string that quotes a vendor
 * debug page next to a real endpoint is how headers get echoed downstream.
 */
final class ProviderErrorCopyTest extends TestCase
{
    private string|false $originalApiKey;
    private string|false $originalBaseUrl;

    protected function setUp(): void
    {
        $this->originalApiKey = getenv('OPENAI_API_KEY');
        $this->originalBaseUrl = getenv('OPENAI_BASE_URL');
        putenv('OPENAI_API_KEY');
        putenv('OPENAI_BASE_URL');
    }

    protected function tearDown(): void
    {
        $this->originalApiKey === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $this->originalApiKey);
        $this->originalBaseUrl === false ? putenv('OPENAI_BASE_URL') : putenv('OPENAI_BASE_URL=' . $this->originalBaseUrl);
    }

    // =========================================================================
    // Missing required key → name the variable
    // =========================================================================

    public function testTheMissingKeySentenceGrowsItsEnvironmentHintBehindAnUntouchedPrefix(): void
    {
        $caught = null;
        try {
            (new ProviderFactory())->create(['type' => 'openai', 'model' => 'gpt-5-mini']);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'a keyless openai config must refuse');
        $message = $caught->getMessage();

        $this->assertStringStartsWith(
            "Provider type 'openai' requires 'apiKey' to be set",
            $message,
            'the pre-fix sentence must survive byte-identical as the prefix — other suites grep for it'
        );
        $this->assertStringContainsString('OPENAI_API_KEY', $message);
        $this->assertStringContainsString('providers.<name>', $message, 'the config door this same review opened must be named too');
    }

    public function testTheAnthropicBedrockAndVertexHintsMatchWhatTheArmsActuallyRead(): void
    {
        $expectations = [
            'anthropic' => ['apiKey', 'ANTHROPIC_API_KEY'],
            'bedrock' => ['region', 'AWS_REGION'],
            'vertex' => ['projectId', 'GCP_PROJECT_ID'],
            'sglang' => ['baseUrl', 'OPENAI_BASE_URL'],
        ];

        foreach ($expectations as $type => [$key, $env]) {
            $caught = null;
            try {
                (new ProviderFactory())->create(['type' => $type]);
            } catch (\RuntimeException $e) {
                $caught = $e;
            }
            $this->assertNotNull($caught, "{$type} with nothing set must refuse");
            $this->assertStringContainsString("requires '{$key}' to be set", $caught->getMessage());
            $this->assertStringContainsString($env, $caught->getMessage(), "{$type}: the hint must name a variable the config arm really reads");
        }
    }

    public function testAnUnmappedRequiredKeyKeepsTheBareSentence(): void
    {
        // `custom` requires 'name'; no environment variable feeds it, and an
        // invented hint would be worse than none.
        $caught = null;
        try {
            (new ProviderFactory())->create(['type' => 'custom', 'baseUrl' => 'http://x', 'model' => 'm']);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'custom without a name must refuse');
        $this->assertSame(
            "Provider type 'custom' requires 'name' to be set",
            $caught->getMessage(),
            'no hint in the map means the message stays exactly as it was'
        );
    }

    // =========================================================================
    // Unknown type → list, nearest, and where to look
    // =========================================================================

    public function testTheUnknownTypeRefusalListsEveryValidTypeAndNearestMatch(): void
    {
        try {
            (new ProviderFactory())->create(['type' => 'opnai']);
            $this->fail('a typo must refuse');
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringStartsWith('Unknown provider type: opnai', $message);
        $this->assertStringContainsString('(valid types: ', $message);
        foreach ((new ProviderFactory())->availableTypes() as $type) {
            $this->assertStringContainsString($type, $message);
        }
        $this->assertStringContainsString('closest match: openai', $message);
        $this->assertStringContainsString('sugarcrush models', $message);
    }

    public function testFarAwayGarbageGetsTheListButNoSuggestion(): void
    {
        try {
            (new ProviderFactory())->defaultConfig('zzzzqqqqqq');
            $this->fail('garbage must refuse');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('(valid types: ', $e->getMessage());
            $this->assertStringNotContainsString('closest match', $e->getMessage(), 'a "nearest match" that is not near trains the reader to ignore it');
        }
    }

    public function testAllThreeUnknownNameSeamsRaiseTheSameRefusal(): void
    {
        // create(), defaultConfig() and the instantiation match each used to
        // carry their own string; they now share unknownTypeError().
        $messages = [];

        try {
            (new ProviderFactory())->create(['type' => 'nope-nope-nope']);
        } catch (\InvalidArgumentException $e) {
            $messages['create'] = $e->getMessage();
        }
        try {
            (new ProviderFactory())->defaultConfig('nope-nope-nope');
        } catch (\InvalidArgumentException $e) {
            $messages['defaultConfig'] = $e->getMessage();
        }

        $this->assertCount(2, $messages);
        $this->assertStringContainsString('valid types', $messages['create']);
        $this->assertStringContainsString('valid types', $messages['defaultConfig']);
    }

    // =========================================================================
    // Transport failure → human copy, endpoint named, nothing else echoed
    // =========================================================================

    public function testTheTransportConversionNamesTheEndpointAndDropsTheDocClause(): void
    {
        $inner = new ConnectException(
            'cURL error 7: Failed to connect to https://gw.internal.example/v1 after 1 ms (see https://github.com/openai-php/client/blob/main/TROUBLESHOOTING.md)',
            new \GuzzleHttp\Psr7\Request('GET', 'https://gw.internal.example/v1')
        );

        $method = new \ReflectionMethod(OpenAIProvider::class, 'transportFailure');
        $method->setAccessible(true);
        /** @var \RuntimeException $converted */
        $converted = $method->invoke(null, new TransporterException($inner));

        $this->assertStringContainsString(
            'OpenAI-compatible provider could not reach its endpoint:',
            $converted->getMessage()
        );
        $this->assertStringContainsString('(endpoint https://gw.internal.example/v1', $converted->getMessage());
        $this->assertStringNotContainsString('(see https://github.com', $converted->getMessage());
        $this->assertStringNotContainsString('Authorization', $converted->getMessage());
        $this->assertInstanceOf(
            TransporterException::class,
            $converted->getPrevious(),
            'the chain must stay intact for TransientFailure classification — the wrapper substitutes copy, not diagnosis'
        );
    }

    public function testTheConversionSurvivesAMessageWithNoUrlAtAll(): void
    {
        $method = new \ReflectionMethod(OpenAIProvider::class, 'transportFailure');
        $method->setAccessible(true);
        /** @var \RuntimeException $converted */
        $converted = $method->invoke(null, new TransporterException(new ConnectException('dns is broken', new \GuzzleHttp\Psr7\Request('GET', 'https://gw.internal.example/v1'))));

        $this->assertStringContainsString('dns is broken', $converted->getMessage());
        $this->assertStringNotContainsString('(endpoint ', $converted->getMessage(), 'no URL in, no empty endpoint paren out');
    }
}
