<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Media\Voice;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SugarCraft\Code\Media\Voice\AudioRecorder;
use SugarCraft\Code\Media\Voice\AudioRecording;
use SugarCraft\Code\Media\Voice\Transcriber;
use SugarCraft\Code\Media\Voice\TranscriptionException;
use SugarCraft\Code\Media\Voice\VoiceConfig;

/**
 * C-TUI-6.l wire laws for the transcription egress: what the multipart
 * carries, that the endpoint join keeps a versioned base, that the key
 * reaches only the Authorization header (never a printable string — not
 * even when the server echoes it back inside its own error body), that
 * every refusal deletes the take, and that the request is bounded by
 * connect + stall ceilings and NEVER by a total wall clock (E646).
 *
 * The dial never happens: a bare callable handler is wrapped by
 * HttpClientDefaults without the default middleware stack, so the raw
 * `multipart` option — exactly what Transcriber spelled — is what each
 * pin inspects.
 */
final class TranscriberTest extends TestCase
{
    /** @var list<string> */
    private array $trnScratch = [];

    protected function tearDown(): void
    {
        \putenv('CT6L_VOICETEST_KEY');

        foreach ($this->trnScratch as $dir) {
            $this->trnPrune($dir);
        }

        $this->trnScratch = [];
    }

    public function testOffConfigRefusesBeforeAnyDialAndStillDeletesTheTake(): void
    {
        $dials = 0;
        $transcriber = new Transcriber(['handler' => static function () use (&$dials) {
            ++$dials;

            return new FulfilledPromise(new Response(200, [], '{"text":"x"}'));
        }]);

        [$path, $recording] = $this->trnTake(120);

        try {
            $transcriber->transcribe($recording, VoiceConfig::off());
            self::fail('an endpoint-less configuration must refuse the send');
        } catch (TranscriptionException $refusal) {
            self::assertStringContainsString('voice input is off', $refusal->getMessage());
        }

        self::assertSame(0, $dials, 'the off refusal never reaches the network');
        self::assertFileDoesNotExist($path, 'even the pre-flight refusal costs the user no leftover take');
    }

    public function testSilentTakeRefusesWithoutDialingAndDeletesTheFile(): void
    {
        $dials = 0;
        $transcriber = new Transcriber(['handler' => static function () use (&$dials) {
            ++$dials;

            return new FulfilledPromise(new Response(200, [], '{"text":"x"}'));
        }]);

        [$path, $recording] = $this->trnTake(AudioRecorder::WAV_HEADER_BYTES);

        try {
            $transcriber->transcribe($recording, $this->trnConfig([]));
            self::fail('a header-only take must be refused, not uploaded');
        } catch (TranscriptionException $refusal) {
            self::assertStringContainsString('captured no audio', $refusal->getMessage());
        }

        self::assertSame(0, $dials);
        self::assertFileDoesNotExist($path);
    }

    public function testMultipartCarriesFileAndModelAndNoLanguageWhenUnset(): void
    {
        $capture = null;
        $transcriber = $this->trnDialBack($capture, '{"text":"hello"}');
        [, $recording] = $this->trnTake(120);

        self::assertSame('hello', $transcriber->transcribe($recording, $this->trnConfig([])));

        // Guzzle's own Client::applyOptions() turns the multipart option into
        // a MultipartStream body BEFORE any handler runs (Client.php's
        // "multipart => body, unset multipart" fold), so the honest seam for
        // the wire shape is the rendered body itself, not the option dict.
        self::assertIsArray($capture);
        $wire = (string) $capture['request']->getBody();

        self::assertStringContainsString('name="file"', $wire);
        self::assertStringContainsString('filename="' . \basename($recording->path) . '"', $wire);
        self::assertStringContainsString(\str_repeat('a', 120), $wire, 'the bytes ride once, as given');
        self::assertStringContainsString('name="model"', $wire);
        self::assertStringContainsString(VoiceConfig::DEFAULT_MODEL, $wire);
        self::assertStringNotContainsString('name="language"', $wire, 'an unset language is absent, not empty');
    }

    public function testConfiguredLanguageRidesAsItsOwnField(): void
    {
        $capture = null;
        $transcriber = $this->trnDialBack($capture, '{"text":"hi"}');
        [, $recording] = $this->trnTake(120);

        $transcriber->transcribe($recording, $this->trnConfig(['voice.language' => 'de']));

        $wire = (string) $capture['request']->getBody();
        self::assertStringContainsString('name="language"', $wire);
        // The part payload sits alone between its blank header line and the
        // next boundary; MultipartStream renders CRLF line endings.
        self::assertMatchesRegularExpression('/name="language".*\r\n\r\nde\r\n--/s', $wire);
    }

    public function testEndpointJoinKeepsAVersionedBaseUrl(): void
    {
        // The law the design names: rtrim + literal path, NOT base_uri with
        // a leading-slash path, which would silently drop the /v1 segment.
        $capture = null;
        $transcriber = $this->trnDialBack($capture, '{"text":"hi"}');
        [, $recording] = $this->trnTake(120);

        $transcriber->transcribe($recording, $this->trnConfig(['voice.baseUrl' => 'https://api.example.invalid/v1/']));

        self::assertSame(
            'https://api.example.invalid/v1/audio/transcriptions',
            (string) $capture['request']->getUri(),
        );
    }

    public function testKeyPlaceholderExpandsFromTheEnvironmentOntoTheHeader(): void
    {
        $secret = 'sk-trntest-' . \uniqid('', true);
        \putenv('CT6L_VOICETEST_KEY=' . $secret);

        $capture = null;
        $transcriber = $this->trnDialBack($capture, '{"text":"hi"}');
        [, $recording] = $this->trnTake(120);

        $transcriber->transcribe($recording, $this->trnConfig(['voice.apiKey' => '${CT6L_VOICETEST_KEY}']));

        self::assertSame('Bearer ' . $secret, $capture['request']->getHeaderLine('Authorization'));
    }

    public function testMissingEnvKeySendsNoAuthorizationHeaderAtAll(): void
    {
        \putenv('CT6L_VOICETEST_KEY');

        $capture = null;
        $transcriber = $this->trnDialBack($capture, '{"text":"hi"}');
        [, $recording] = $this->trnTake(120);

        $transcriber->transcribe($recording, $this->trnConfig(['voice.apiKey' => '${CT6L_VOICETEST_KEY}']));

        self::assertSame('', $capture['request']->getHeaderLine('Authorization'),
            'an unset ${VAR} resolves to empty, which sends no header — never the literal');
    }

    public function testErrorAnswerCarriesTheStatusButNeverTheKey(): void
    {
        $secret = 'sk-echo-' . \uniqid('', true);
        $capture = null;
        // A hostile-or-clumsy endpoint echoes the credential back in its
        // error body; the excerpt must not launder it into the message.
        $transcriber = $this->trnDialBack($capture, '{"error":"invalid key ' . $secret . '"}', 401);
        [$path, $recording] = $this->trnTake(120);

        try {
            $transcriber->transcribe($recording, $this->trnConfig(['voice.apiKey' => $secret]));
            self::fail('a 401 must raise');
        } catch (TranscriptionException $refusal) {
            self::assertStringContainsString('401', $refusal->getMessage());
            self::assertStringNotContainsString($secret, $refusal->getMessage(),
                'the configured key is scrubbed from any excerpt, echoed or not');
        }

        self::assertFileDoesNotExist($path, 'the refused take is deleted too');
    }

    public function testSuccessTextIsTrimmedAndTheTakeIsDeletedAfterwards(): void
    {
        $capture = null;
        $transcriber = $this->trnDialBack($capture, '{"text":"  spaced speech  "}');
        [$path, $recording] = $this->trnTake(120);

        self::assertSame('spaced speech', $transcriber->transcribe($recording, $this->trnConfig([])));
        self::assertFileDoesNotExist($path);
    }

    public function testBodyWithoutTextFieldIsRefusedAsUnparseable(): void
    {
        $capture = null;
        $transcriber = $this->trnDialBack($capture, '{"result":"nothing useful"}');
        [$path, $recording] = $this->trnTake(120);

        try {
            $transcriber->transcribe($recording, $this->trnConfig([]));
            self::fail('a body with no text field must not read as silence');
        } catch (TranscriptionException $refusal) {
            self::assertStringContainsString('no text field', $refusal->getMessage());
        }

        self::assertFileDoesNotExist($path);
    }

    public function testTransportDeathMapsToAKeyFreeRefusalAndDeletesTheTake(): void
    {
        $transcriber = new Transcriber(['handler' => static function (): never {
            throw new ConnectException('Connection refused', new Request('POST', 'https://api.example.invalid/v1/audio/transcriptions'));
        }]);

        [$path, $recording] = $this->trnTake(120);

        try {
            $transcriber->transcribe($recording, $this->trnConfig([]));
            self::fail('a dead transport must raise');
        } catch (TranscriptionException $refusal) {
            self::assertStringContainsString('did not complete', $refusal->getMessage());
            self::assertStringContainsString(ConnectException::class, $refusal->getMessage());
        }

        self::assertFileDoesNotExist($path);
    }

    public function testTheRequestIsBoundedByStallCeilingsAndNeverByATotalTimeout(): void
    {
        $capture = null;
        $transcriber = $this->trnDialBack($capture, '{"text":"hi"}');
        [, $recording] = $this->trnTake(120);

        $transcriber->transcribe($recording, $this->trnConfig([]));

        self::assertArrayNotHasKey('timeout', $capture, 'E646: no blanket total-request ceiling reaches the wire');
        self::assertArrayHasKey(
            CURLOPT_LOW_SPEED_LIMIT,
            $capture['curl'],
            'the stall abort is a per-operation ceiling, the shape E646 permits',
        );
        self::assertSame(Transcriber::STALL_SECONDS, $capture['curl'][CURLOPT_LOW_SPEED_TIME]);

        // The structural half: the method body itself must never spell the
        // forbidden option key, so a future edit has to pass a test rewrite.
        $src = (string) \file_get_contents(__DIR__ . '/../../../src/Media/Voice/Transcriber.php');
        $body = \substr($src, (int) \strpos($src, 'public function transcribe('));
        $body = \substr($body, 0, (int) \strpos($body, 'private static function fieldParts'));
        self::assertStringNotContainsString("'timeout' =>", $body);
        self::assertStringNotContainsString('"timeout" =>', $body);
    }

    /**
     * Build a Transcriber whose bare handler records the (request, options)
     * pair it receives and answers with a canned body/status.
     *
     * @param array<string,mixed>|null $capture
     */
    private function trnDialBack(?array &$capture, string $body, int $status = 200): Transcriber
    {
        return new Transcriber(['handler' => static function (RequestInterface $request, array $options) use (&$capture, $body, $status) {
            $capture = $options + ['request' => $request];

            return new FulfilledPromise(new Response($status, [], $body));
        }]);
    }

    /** @param array<string,string> $overrides */
    private function trnConfig(array $overrides = []): VoiceConfig
    {
        return VoiceConfig::fromSettings([
            'voice.baseUrl' => 'https://api.example.invalid/v1',
        ] + $overrides);
    }

    /** @return array{0:string,1:AudioRecording} */
    private function trnTake(int $bytes): array
    {
        $dir = \sys_get_temp_dir() . '/ct6l_trn_' . \uniqid((string) \getmypid(), true);
        self::assertTrue(\mkdir($dir, 0o700, true));
        $this->trnScratch[] = $dir;

        $path = $dir . '/take-' . \uniqid('', true) . '.wav';
        self::assertIsInt(\file_put_contents($path, \str_repeat('a', $bytes)));

        return [$path, new AudioRecording($path, AudioRecorder::BACKEND_ALSA, 120, \microtime(true))];
    }

    private function trnPrune(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        foreach ((array) \scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $dir . '/' . $entry;

            if (\is_dir($child)) {
                $this->trnPrune($child);
                @\rmdir($child);
            } else {
                @\unlink($child);
            }
        }

        @\rmdir($dir);
    }
}
