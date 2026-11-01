<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SugarCraft\Code\Media\Sd\Client as SdClient;
use SugarCraft\Code\Media\Voice\AudioRecorder;
use SugarCraft\Code\Media\Voice\Transcriber;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\Methods\MediaMethods;
use SugarCraft\Code\Protocol\Schema\MethodSchemas;
use SugarCraft\Code\Protocol\Schema\ProtocolSchema;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Support\MediaStore;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-TUI-6.n: the serve-mode audio pair. `audio.transcribe` is Write scope
 * with side effects (the dispatcher's idempotency cache answers a replay
 * with the same job id), files the take at 0600 beside the render ledgers,
 * and hands it to the C-TUI-6.l transport through the fork channel;
 * `audio.status` projects `{state, text?, error?}` from its ledger.
 *
 * The security centres this file exists to keep honest:
 *  - the endpoint comes from server settings ONLY — the wire carries audio,
 *    a mime and an optional language, and nothing else can name a URL;
 *  - size bounds die at param-reading (the base64 ceiling) and at the
 *    decoded-bytes check BEFORE the take is written anywhere;
 *  - the mime is checked against the declared allowlist first;
 *  - with `voice.baseUrl` unset BOTH doors answer `voice_unconfigured` and
 *    no dial, no file, no ledger happens;
 *  - the API key never rides an error sentence (the transport's own
 *    scrubbing, pinned end-to-end through the ledger here).
 */
final class AudioTranscribeMethodTest extends TestCase
{
    use HomeSandboxTrait;

    private ProtocolFixture $fixture;

    private WireClient $client;

    private string $sessionId = '';

    private string $homeRoot = '';

    /** A random per-process key literal — the scrubbing pin needs a real one. */
    private string $voiceKey = '';

    private const ATM_VOICE_ENDPOINT = 'https://voice-endpoint.example.invalid/v1';

    protected function setUp(): void
    {
        $this->homeRoot = \sys_get_temp_dir() . '/ctui6n-' . \uniqid('', true);
        $this->useHomeSandbox($this->homeRoot);
        \mkdir($this->homeRoot . '/.candy-code', 0o700, true);
        // No SD server, no real voice dial: every transcriber here is a
        // canned handler, and the env base stays unset so a stray live
        // path cannot answer this suite (MediaMethodsTest idiom).
        \putenv(SdClient::BASE_URL_ENV);
        $this->voiceKey = 'sk-atm-' . \bin2hex(\random_bytes(8));

        $this->fixture = ProtocolFixture::new();
        $this->client = $this->fixture->client();
        $this->sessionId = $this->fixture->session($this->client);

        MediaMethods::setGateForTesting(new PermissionGate(PermissionMode::BypassPermissions));
        MediaMethods::setDirectRunForTesting(true);
    }

    protected function tearDown(): void
    {
        MediaMethods::resetForTesting();
        \putenv(SdClient::BASE_URL_ENV);
        $this->fixture->tearDown();
        $this->restoreHomeSandbox();
        $this->atmPurgeTree($this->homeRoot);
    }

    private static function atmPurgeTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach ((array) \scandir($dir) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $dir . '/' . $entry;
                \is_dir($path) ? self::atmPurgeTree($path) : @\unlink($path);
            }
        }
        @\rmdir($dir);
    }

    /** @param array<string, mixed> $overrides */
    private function atmVoiceConfig(array $overrides = []): void
    {
        \file_put_contents(
            $this->homeRoot . '/.candy-code/config.json',
            (string) \json_encode([
                'voice.baseUrl' => self::ATM_VOICE_ENDPOINT,
                'voice.apiKey' => $this->voiceKey,
            ] + $overrides),
        );
    }

    /** @return array<string, mixed> */
    private function atmTake(): array
    {
        // Comfortably above the WAV header floor the transport's no-audio
        // door guards, and well under every declared ceiling.
        return ['audio/webm', base64_encode(\random_bytes(4096))];
    }

    /**
     * A Transcriber whose bare handler records the request pair, counts the
     * dials, and answers with a canned body/status.
     *
     * @param array<int, RequestInterface>|null $dials
     */
    private function atmDialBack(?array &$dials, string $body, int $status = 200): Transcriber
    {
        return new Transcriber(['handler' => static function (RequestInterface $request, array $options) use (&$dials, $body, $status) {
            $dials[] = $request;

            return new FulfilledPromise(new Response($status, [], $body));
        }]);
    }

    // ── roster discipline ──────────────────────────────────────────────

    public function testTheAudioPairCarriesTheDeclaredScopesAndSideEffects(): void
    {
        $submit = Dispatcher::methods()->get('audio.transcribe');
        self::assertNotNull($submit);
        self::assertSame(Scope::Write, $submit->scope, 'audio.transcribe is a Write door exactly like generate.submit');
        self::assertTrue($submit->sideEffects, 'the idempotency cache must engage for the replay door');

        $status = Dispatcher::methods()->get('audio.status');
        self::assertNotNull($status);
        self::assertSame(Scope::Read, $status->scope);
        self::assertFalse($status->sideEffects);
    }

    public function testTheSchemaCeilingsAndEnumsCannotDriftFromTheHandler(): void
    {
        // The 5.d byte-consistency discipline: one source of truth per
        // declared bound, re-derived here rather than re-typed.
        $params = MethodSchemas::params('audio.transcribe')?->toArray();
        self::assertIsArray($params);
        self::assertSame(
            MediaMethods::TRANSCRIBE_MAX_BASE64_BYTES,
            $params['properties']['audioBase64']['maxLength'] ?? null,
            'the schema bound IS the handler param ceiling',
        );
        self::assertSame(
            \array_keys(MediaMethods::TRANSCRIBE_MIME_EXTENSIONS),
            $params['properties']['mime']['enum'] ?? null,
            'the schema mime enum IS the handler allowlist keys',
        );
        $result = MethodSchemas::result('audio.status')?->toArray();
        self::assertIsArray($result);
        self::assertSame(
            MediaMethods::AUDIO_STATES,
            $result['properties']['state']['enum'] ?? null,
            'the wire state enum IS the ledger state set',
        );

        // The declared arithmetic itself: the base64 ceiling is exactly the
        // encoded form of the audio ceiling, and both sit under the frame
        // ceiling the transport verifies first.
        self::assertSame(
            4 * (int) ceil(MediaMethods::TRANSCRIBE_MAX_AUDIO_BYTES / 3),
            MediaMethods::TRANSCRIBE_MAX_BASE64_BYTES,
        );
        self::assertLessThan(ServerConfig::MAX_CLIENT_FRAME_BYTES, MediaMethods::TRANSCRIBE_MAX_BASE64_BYTES);
        self::assertLessThan(MediaMethods::TRANSCRIBE_MAX_BASE64_BYTES, MediaMethods::TRANSCRIBE_MAX_AUDIO_BYTES);
    }

    // ── the voice door ─────────────────────────────────────────────────

    public function testVoiceUnconfiguredRefusesBothDoorsWithoutFileOrDial(): void
    {
        // No config written at all: voice.baseUrl unset is the door shut.
        $submit = $this->client->request('audio.transcribe', [
            'sessionId' => $this->sessionId,
        ] + $this->atmTakePair());
        self::assertSame(ErrorCode::Forbidden->value, $submit['error']['code'] ?? null);
        self::assertSame('voice_unconfigured', $submit['error']['data']['kind'] ?? null);

        $poll = $this->client->request('audio.status', [
            'sessionId' => $this->sessionId,
            'jobId' => \str_repeat('a', 16),
        ]);
        self::assertSame(ErrorCode::Forbidden->value, $poll['error']['code'] ?? null);
        self::assertSame('voice_unconfigured', $poll['error']['data']['kind'] ?? null, 'the poll answers the same silence — a stranded ledger is never half-believed');

        $jobsDir = MediaStore::forSession($this->sessionId)->directoryPath() . '/jobs';
        self::assertDirectoryDoesNotExist($jobsDir, 'a refused door writes no ledger directory, no take, nothing');
    }

    private function atmTakePair(): array
    {
        [$mime, $encoded] = $this->atmTake();

        return ['mime' => $mime, 'audioBase64' => $encoded];
    }

    // ── the size caps ──────────────────────────────────────────────────

    public function testABodyOverTheBase64CeilingDiesAtParamReading(): void
    {
        $this->atmVoiceConfig();
        $oversized = \str_repeat('A', MediaMethods::TRANSCRIBE_MAX_BASE64_BYTES + 4);

        $frame = $this->client->request('audio.transcribe', [
            'sessionId' => $this->sessionId,
            'mime' => 'audio/webm',
            'audioBase64' => $oversized,
        ]);
        self::assertSame(ErrorCode::InvalidParams->value, $frame['error']['code'] ?? null);
        self::assertStringContainsString('audioBase64: longer than', (string) ($frame['error']['message'] ?? ''), 'the Params reader owns this refusal shape');
        $this->atmAssertNothingFiled();
    }

    public function testTheDecodedBytesCeilingIsCheckedBeforeTheTakeLands(): void
    {
        $this->atmVoiceConfig();
        // The 1-byte window the ceilings deliberately leave between an
        // allowed base64 length and an allowed decode: exactly at the param
        // bound, 11_184_812 valid base64 chars decode to 8_388_609 bytes —
        // one over the audio ceiling — so ONLY the decoded check can refuse
        // it, and it must do so before anything is written.
        $atCap = \str_repeat('A', MediaMethods::TRANSCRIBE_MAX_BASE64_BYTES);
        self::assertSame(MediaMethods::TRANSCRIBE_MAX_AUDIO_BYTES + 1, \strlen((string) \base64_decode($atCap, true)), 'arithmetic anchor: the window really exists');

        $frame = $this->client->request('audio.transcribe', [
            'sessionId' => $this->sessionId,
            'mime' => 'audio/webm',
            'audioBase64' => $atCap,
        ]);
        self::assertSame(ErrorCode::InvalidParams->value, $frame['error']['code'] ?? null);
        $message = (string) ($frame['error']['message'] ?? '');
        self::assertStringContainsString('decodes to', $message);
        self::assertStringContainsString((string) MediaMethods::TRANSCRIBE_MAX_AUDIO_BYTES, $message, 'the refusal names the honest limit');
        $this->atmAssertNothingFiled();
    }

    public function testGarbageBase64AndOffListMimesAreRefusedByName(): void
    {
        $this->atmVoiceConfig();

        $bad = $this->client->request('audio.transcribe', [
            'sessionId' => $this->sessionId,
            'mime' => 'audio/webm',
            'audioBase64' => 'not!valid!base64!',
        ]);
        self::assertSame(ErrorCode::InvalidParams->value, $bad['error']['code'] ?? null);
        self::assertStringContainsString('not valid base64', (string) ($bad['error']['message'] ?? ''));

        $mime = $this->client->request('audio.transcribe', [
            'sessionId' => $this->sessionId,
            'mime' => 'audio/flac',
            'audioBase64' => $this->atmTake()[1],
        ]);
        self::assertSame(ErrorCode::InvalidParams->value, $mime['error']['code'] ?? null);
        $message = (string) ($mime['error']['message'] ?? '');
        self::assertStringContainsString('audio/webm', $message, 'the refusal names the declared set');
        self::assertStringNotContainsString('audio/flac', $message);
        $this->atmAssertNothingFiled();
    }

    private function atmAssertNothingFiled(): void
    {
        $jobsDir = MediaStore::forSession($this->sessionId)->directoryPath() . '/jobs';
        if (!\is_dir($jobsDir)) {
            return;
        }
        foreach ((array) \scandir($jobsDir) as $entry) {
            self::assertFalse(
                \str_ends_with($entry, '.audio.webm') || \str_starts_with($entry, 'audio-'),
                'a refused submission must leave no take and no ledger behind',
            );
        }
    }

    // ── the happy path and the egress law ──────────────────────────────

    public function testAJobReachesDoneThroughTheTransportAndPollAnswersTheText(): void
    {
        $this->atmVoiceConfig();
        $dials = [];
        // A bound closure (not an arrow fn): arrow capture is by value and
        // the handler's writes would land in a copy the test never sees.
        MediaMethods::setTranscriberFactoryForTesting(function () use (&$dials): Transcriber {
            return $this->atmDialBack($dials, '{"text":"the drafted words"}');
        });

        [$mime, $encoded] = $this->atmTake();
        $submitted = $this->client->call('audio.transcribe', [
            'sessionId' => $this->sessionId,
            'audioBase64' => $encoded,
            'mime' => $mime,
            'language' => 'fr',
            'idempotencyKey' => 'k-atm-replay',
        ]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $submitted['jobId']);
        self::assertSame([], ProtocolSchema::errors($submitted, MethodSchemas::result('audio.transcribe')));

        // The replay door: same idempotency key, same job, no second dial.
        $again = $this->client->call('audio.transcribe', [
            'sessionId' => $this->sessionId,
            'audioBase64' => $encoded,
            'mime' => $mime,
            'language' => 'fr',
            'idempotencyKey' => 'k-atm-replay',
        ]);
        self::assertSame($submitted['jobId'], $again['jobId'], 'a repeated submission returns the same job, exactly like generate.submit');
        self::assertCount(1, $dials, 'the cache answered the replay; the endpoint saw one upload');

        $status = $this->client->call('audio.status', [
            'sessionId' => $this->sessionId,
            'jobId' => $submitted['jobId'],
        ]);
        self::assertSame(['state' => 'done', 'text' => 'the drafted words'], $status);
        self::assertSame([], ProtocolSchema::errors($status, MethodSchemas::result('audio.status')));

        // Egress law: the only dial went to the CONFIGURED endpoint; the
        // wire carried no URL door-handle, and the language rode the
        // multipart as the transport's own field.
        $request = $dials[0];
        self::assertStringStartsWith(self::ATM_VOICE_ENDPOINT, $request->getUri()->__toString());
        $body = (string) $request->getBody();
        self::assertStringContainsString('name="language"', $body);
        self::assertStringContainsString('fr', $body);
        self::assertStringNotContainsString($this->voiceKey, $body, 'the key rides the Authorization header only, never the body');
        self::assertStringContainsString('Bearer', (string) ($request->getHeaderLine('Authorization') ?? ''), 'the welded header posture rides this path too');

        // The take is consumed and the ledger keeps its audio- name space.
        $jobsDir = MediaStore::forSession($this->sessionId)->directoryPath() . '/jobs';
        $filed = (array) \scandir($jobsDir);
        self::assertContains('audio-' . $submitted['jobId'] . '.json', $filed);
        self::assertSame([], \array_values(\array_filter($filed, static fn (string $e): bool => \str_contains($e, '.audio.'))), 'the Transcriber finally unlinks the take; nothing strands');
    }

    public function testAFailedTransportSettlesTheLedgerWithoutLeakingTheKey(): void
    {
        $this->atmVoiceConfig();
        // A hostile-ish upstream that echoes the submitted key in its 502
        // body: the transport's excerpt scrubbing must neutralise it before
        // the ledger ever sees the sentence.
        $dials = [];
        MediaMethods::setTranscriberFactoryForTesting(function () use (&$dials): Transcriber {
            return $this->atmDialBack($dials, 'upstream saw key ' . $this->voiceKey . ' in your request', 502);
        });

        [$mime, $encoded] = $this->atmTake();
        $submitted = $this->client->call('audio.transcribe', [
            'sessionId' => $this->sessionId,
            'audioBase64' => $encoded,
            'mime' => $mime,
        ]);
        $status = $this->client->call('audio.status', [
            'sessionId' => $this->sessionId,
            'jobId' => $submitted['jobId'],
        ]);

        self::assertSame('failed', $status['state']);
        self::assertStringNotContainsString($this->voiceKey, (string) $status['error'], 'no key in errors — end to end, through the ledger and back onto the wire');
        self::assertStringContainsString('[redacted]', (string) $status['error']);
        self::assertSame([], ProtocolSchema::errors($status, MethodSchemas::result('audio.status')));

        $filed = (array) \scandir(MediaStore::forSession($this->sessionId)->directoryPath() . '/jobs');
        self::assertSame([], \array_values(\array_filter($filed, static fn (string $e): bool => \str_contains($e, '.audio.'))), 'the failure path costs the user no leftover take');
    }

    public function testAnUnknownJobIdIsSilenceAndAMalformedOneIsRefused(): void
    {
        $this->atmVoiceConfig();

        $frame = $this->client->request('audio.status', [
            'sessionId' => $this->sessionId,
            'jobId' => \str_repeat('7', 16),
        ]);
        self::assertSame(ErrorCode::NotFound->value, $frame['error']['code'] ?? null);
        self::assertSame('audio_job_not_found', $frame['error']['data']['kind'] ?? null);
        self::assertStringNotContainsString('generation', (string) ($frame['error']['message'] ?? ''), 'the audio namespace answers with its own words');

        $shape = $this->client->request('audio.status', [
            'sessionId' => $this->sessionId,
            'jobId' => '../../etc/passwd',
        ]);
        self::assertSame(ErrorCode::InvalidParams->value, $shape['error']['code'] ?? null);
    }

    public function testTheTakeFloorIsTheTransportsOwnAndThisLaneFilesWholeBytes(): void
    {
        // Cross-authority pin: the random-bytes take this suite uploads is
        // strictly above the transport's no-audio floor, so the happy legs
        // above cannot be green on an empty-file accident.
        self::assertGreaterThan(AudioRecorder::WAV_HEADER_BYTES, 4096);
        $this->atmVoiceConfig();
        $dials = [];
        MediaMethods::setTranscriberFactoryForTesting(function () use (&$dials): Transcriber {
            return $this->atmDialBack($dials, '{"text":"ok"}');
        });
        $submitted = $this->client->call('audio.transcribe', [
            'sessionId' => $this->sessionId,
        ] + $this->atmTakePair());
        self::assertCount(1, $dials, 'the take reached the dial (no silent no-audio refusal hid in the size)');
        $status = $this->client->call('audio.status', ['sessionId' => $this->sessionId, 'jobId' => $submitted['jobId']]);
        self::assertSame('done', $status['state']);
    }
}
