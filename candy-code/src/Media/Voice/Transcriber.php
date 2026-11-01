<?php

declare(strict_types=1);

namespace SugarCraft\Code\Media\Voice;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use SugarCraft\Code\Providers\Concerns\HttpClientDefaults;
use SugarCraft\Core\Util\Sanitize;
use Throwable;

/**
 * Uploads one take to an OpenAI-compatible `/audio/transcriptions` endpoint
 * and returns the trimmed text (C-TUI-6.l, GAP-MOD-23).
 *
 * THE URL IS COMPOSED, NOT RESOLVED. `rtrim($base,'/') . '/audio/transcriptions'`
 * — deliberately NOT Guzzle's `base_uri` plus a leading-slash path, which
 * drops the base's own path segment and would silently eat the `/v1` suffix
 * every self-hosted endpoint needs. `voice.baseUrl` ending in `/v1`
 * transcribes at `/v1/audio/transcriptions`; that sentence is the whole
 * reason this constructor takes no base_uri at all.
 *
 * CONNECT-BOUND, STALL-BOUND, NEVER TOTAL-BOUND (E646). The client rides the
 * {@see HttpClientDefaults} policy: a bounded connect, and on the curl
 * transport a low-speed abort (`CURLOPT_LOW_SPEED_*`) that fires only when
 * the socket carries almost no bytes for a full stall window — a dead pipe,
 * not a slow server doing its job. A whisper request on a loaded box may
 * legitimately sit in the endpoint for minutes before answering; a flat
 * total `timeout` would abort real transcription, which is the exact bug
 * the house doctrine exists to refuse. There is no `timeout` option
 * anywhere in this file, and {@see TranscriberTest} pins that absence.
 *
 * THE KEY NEVER LEAVES THE HEADER. `${VAR}` expansion mirrors the Sd
 * client's deliberate twin of `ProviderFactory::resolveEnv`; the
 * Authorization value is built once into the header array. No log line,
 * exception message, or excerpt is ever assembled from the request — every
 * failure sentence is built from a status code, a reason phrase, or the
 * endpoint's own (sanitized, clipped) body text, so a TranscriptionException
 * is printable wherever its message is shown.
 *
 * THE TAKE DIES WITH THE CALL. The WAV is read once, uploaded, and unlinked
 * in `finally` — success, 4xx, transport death, all of them. Audio bytes
 * never enter a log, never round-trip through stdout, and outlive neither
 * the request nor the process that made them.
 */
final class Transcriber
{
    use HttpClientDefaults;

    /** Curl liveness abort: below this byte rate for the full window, the pipe is dead. */
    public const STALL_BYTES_PER_SECOND = 1024;

    /** How long that rate must persist before the stall counts. A window on silence, not on work. */
    public const STALL_SECONDS = 60;

    /** Cap on how much endpoint body text is quoted back into a refusal sentence. */
    public const EXCERPT_LIMIT = 240;

    private ClientInterface $http;

    /**
     * @param array<string, mixed> $clientOptions verbatim Guzzle client options; a 'handler' key is the test seam
     */
    public function __construct(array $clientOptions = [])
    {
        $this->http = self::guzzleClient($clientOptions + [
            // Same shape as GuzzleTransport/SelfUpdate: a 4xx is a VALUE to
            // read and sanitize, not an exception to translate blindly.
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }

    /**
     * @throws TranscriptionException on every failure path; the message never carries the key
     */
    public function transcribe(AudioRecording $recording, VoiceConfig $config): string
    {
        if (!$config->enabled()) {
            throw TranscriptionException::off();
        }

        if (!AudioRecorder::hasAudio($recording->path)) {
            throw TranscriptionException::noAudio();
        }

        try {
            $bytes = @\file_get_contents($recording->path);

            if ($bytes === false) {
                throw TranscriptionException::unreadable($recording->path);
            }

            $fields = ['model' => $config->model];

            if ($config->language !== null && $config->language !== '') {
                $fields['language'] = $config->language;
            }

            $options = [
                'multipart' => [
                    ['name' => 'file', 'contents' => $bytes, 'filename' => \basename($recording->path)],
                    ...self::fieldParts($fields),
                ],
                'curl' => [
                    CURLOPT_LOW_SPEED_LIMIT => self::STALL_BYTES_PER_SECOND,
                    CURLOPT_LOW_SPEED_TIME => self::STALL_SECONDS,
                ],
            ];
            $expanded = self::expandEnvPlaceholders($config->apiKey);

            if ($expanded !== '') {
                $options['headers'] = ['Authorization' => 'Bearer ' . $expanded];
            }

            try {
                $response = $this->http->request('POST', $config->transcriptionsUrl(), $options);
            } catch (GuzzleException $failure) {
                throw TranscriptionException::transport($failure::class);
            }

            if (!$response instanceof ResponseInterface) {
                throw TranscriptionException::transport('expected a PSR response, got ' . \get_debug_type($response));
            }

            $status = $response->getStatusCode();

            if ($status < 200 || $status >= 300) {
                throw TranscriptionException::status(
                    $status,
                    trim($response->getReasonPhrase()),
                    self::excerpt(self::body($response)),
                );
            }

            $decoded = json_decode(self::body($response), true);
            $text = \is_array($decoded) && isset($decoded['text']) && \is_string($decoded['text'])
                ? trim($decoded['text'])
                : null;

            if ($text === null) {
                throw TranscriptionException::unparseable(self::excerpt(self::body($response)));
            }

            return $text;
        } finally {
            // Deleted after use on every exit — including the early throws.
            @\unlink($recording->path);
        }
    }

    /**
     * @param array<string, string> $fields
     * @return list<array{name: string, contents: string}>
     */
    private static function fieldParts(array $fields): array
    {
        $parts = [];

        foreach ($fields as $name => $value) {
            $parts[] = ['name' => $name, 'contents' => $value];
        }

        return $parts;
    }

    /**
     * Resolve `${VAR}` (and `${VAR:-default}`) key spellings against the
     * environment before they hit the wire. A documented, deliberate twin of
     * {@see \SugarCraft\Code\Media\Sd\Client::expandEnvPlaceholders()} — the
     * Sd copy lives behind its transport seam and this request does not ride
     * that transport; if the law changes it changes in both sentences. An
     * unset variable expands to its default, or to empty (which sends no
     * header at all), never to the literal `${VAR}`.
     */
    private static function expandEnvPlaceholders(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $expanded = preg_replace_callback(
            '/\$\{([A-Z_][A-Z0-9_]*)(?::-([^}]*))?\}/',
            static fn(array $m): string => (string) (\getenv($m[1]) ?: ($m[2] ?? '')),
            $value,
        );

        return $expanded ?? '';
    }

    /** Body text made safe to quote: escape/control-free, then clipped. */
    private static function excerpt(string $body): string
    {
        $clean = Sanitize::untrusted($body);

        return \mb_substr(trim($clean), 0, self::EXCERPT_LIMIT);
    }

    private static function body(ResponseInterface $response): string
    {
        try {
            return (string) $response->getBody();
        } catch (Throwable) {
            return '';
        }
    }
}
