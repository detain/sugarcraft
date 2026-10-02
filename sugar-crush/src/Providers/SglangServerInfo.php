<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;
use SugarCraft\Crush\Providers\Concerns\HttpClientDefaults;

/**
 * What a live SGLang server says about itself: the limits and parsers it was
 * launched with, read from its own `GET /model_info` and `GET /server_info`
 * (audit 15a A18, revised 2026-10-02).
 *
 * WHY A LIVE READ AND NOT ONE MORE TRANSCRIBED CONSTANT. Every per-model figure
 * in {@see SglangProvider} is a transcription of one deployment on one day, and
 * each has decayed: the DeepSeek window held 393,216 and was wrong by the end of
 * that day; the Qwen3.8 one was written as `max_req_input_len` 748,602 on
 * 2026-09-01 and the same server reported **999,994** on 2026-10-02. The
 * transcriptions stay, but only as the fallback for a server that cannot be
 * asked; the server's own answer comes first.
 *
 * THE FIELDS, measured against skynet2 on 2026-10-02
 * (`tests/fixtures/sglang-server-info-qwen3.8.json`,
 * `tests/fixtures/sglang-model-info-qwen3.8.json`):
 *
 * - `context_length` (`/server_info`, 1,000,000): the model's TOTAL window,
 *   prompt plus generated output. It can be `null` on a deployment launched
 *   without `--context-length` (the DeepSeek-V4 one was), so
 *   `internal_states[*].context_length` - the scheduler's resolved figure - is
 *   read when the top-level one is missing.
 * - `max_req_input_len` (`/server_info`, 999,994): the ceiling the scheduler
 *   enforces on ONE request's input. With `allow_auto_truncate=false` an input
 *   over it is a hard error, not a trim.
 * - `served_model_name`, `tool_call_parser` (`qwen3_coder`),
 *   `reasoning_parser` (`qwen3`): read from `/model_info` first, because that
 *   is the small endpoint built to answer exactly this, then from
 *   `/server_info`, which carries the same three keys among ~400 others.
 *
 * Both endpoints live at the server ROOT, not under the OpenAI `/v1` prefix
 * (`/v1/model_info` answers 404, measured), so {@see rootUrl()} strips a
 * trailing `/v1` from the configured `baseUrl`.
 */
final readonly class SglangServerInfo
{
    use HttpClientDefaults;

    /**
     * Connect AND total bound on each discovery GET.
     *
     * A TOTAL timeout is correct here and would be wrong anywhere else under
     * `src/Providers/` (`ProviderConnectTimeoutTest` lists this file as its
     * one metadata-read exemption from the no-total-timeout scrape, by name): these are two tiny metadata reads the server answers
     * from memory, not a completion, so the "never bound an LLM call's total
     * time" rule ({@see Concerns\HttpClientDefaults}) does not apply. What does
     * apply is that {@see SglangProvider::contextWindow()} triggers the read on
     * the render path, so a server that accepts the connection and then never
     * answers must not hold the first frame for longer than this. Both GETs run
     * concurrently, so this is also the worst case for the pair.
     */
    public const DISCOVERY_TIMEOUT_SECONDS = 3.0;

    private const MODEL_INFO_PATH = 'model_info';

    private const SERVER_INFO_PATH = 'server_info';

    private function __construct(
        public ?string $servedModelName,
        public ?int $contextLength,
        public ?int $maxReqInputLen,
        public ?string $toolCallParser,
        public ?string $reasoningParser,
        /**
         * Whether the server's answer carried a `tool_call_parser` key at all.
         * Kept apart from the value because the two absences mean different
         * things: a key holding `null` is a server launched WITHOUT
         * `--tool-call-parser` (tool calls will arrive as raw text), while a
         * missing key is only an older server that does not report it.
         */
        public bool $reportsToolCallParser,
    ) {
    }

    /**
     * Default root factory, per repo convention - for callers (tests, a
     * future cache) that already hold the figures.
     */
    public static function new(
        ?string $servedModelName = null,
        ?int $contextLength = null,
        ?int $maxReqInputLen = null,
        ?string $toolCallParser = null,
        ?string $reasoningParser = null,
        bool $reportsToolCallParser = false,
    ): self {
        return new self(
            self::nonEmptyString($servedModelName),
            self::positiveInt($contextLength),
            self::positiveInt($maxReqInputLen),
            self::nonEmptyString($toolCallParser),
            self::nonEmptyString($reasoningParser),
            $reportsToolCallParser || self::nonEmptyString($toolCallParser) !== null,
        );
    }

    /**
     * Builds the snapshot from the two decoded response bodies, either of
     * which may be missing (the endpoint failed or is not served).
     *
     * Returns null when neither body yielded a single usable field, so a
     * proxy answering 200 with an HTML page or `{}` counts as "discovery
     * failed" and the caller's fallback table applies, rather than a snapshot
     * of nothing silently suppressing it.
     *
     * @param array<mixed>|null $modelInfo
     * @param array<mixed>|null $serverInfo
     */
    public static function fromResponses(?array $modelInfo, ?array $serverInfo): ?self
    {
        $modelInfo ??= [];
        $serverInfo ??= [];

        $contextLength = self::positiveInt($serverInfo['context_length'] ?? null);
        if ($contextLength === null && is_array($serverInfo['internal_states'] ?? null)) {
            foreach ($serverInfo['internal_states'] as $state) {
                $contextLength = is_array($state) ? self::positiveInt($state['context_length'] ?? null) : null;
                if ($contextLength !== null) {
                    break;
                }
            }
        }

        $reportsParser = array_key_exists('tool_call_parser', $modelInfo)
            || array_key_exists('tool_call_parser', $serverInfo);

        $info = new self(
            self::firstString($modelInfo, $serverInfo, 'served_model_name')
                ?? self::firstString($modelInfo, $serverInfo, 'model_path'),
            $contextLength,
            self::positiveInt($serverInfo['max_req_input_len'] ?? null),
            self::firstString($modelInfo, $serverInfo, 'tool_call_parser'),
            self::firstString($modelInfo, $serverInfo, 'reasoning_parser'),
            $reportsParser,
        );

        return $info->isEmpty() ? null : $info;
    }

    /**
     * Reads both endpoints concurrently and returns what they said - or null,
     * never an exception, when the server cannot be asked. Discovery is an
     * optimisation over the fallback table, so an unreachable host, a 404 from
     * a proxy that only forwards `/v1`, a 401, a timeout or a non-JSON body
     * must all degrade to "use the table", not fail the session.
     *
     * @param callable|null $handler A Guzzle handler, for tests; the default
     *        transport otherwise.
     */
    public static function discover(string $baseUrl, ?string $apiKey = null, ?callable $handler = null): ?self
    {
        try {
            $headers = ['Accept' => 'application/json'];
            if ($apiKey !== null && $apiKey !== '') {
                $headers['Authorization'] = 'Bearer ' . $apiKey;
            }

            // Through the shared seam like every provider client, with both
            // bounds overridden down to the discovery budget - see
            // DISCOVERY_TIMEOUT_SECONDS for why a total bound is right here.
            $client = self::guzzleClient([
                'base_uri' => self::rootUrl($baseUrl) . '/',
                'handler' => $handler === null ? HandlerStack::create() : HandlerStack::create($handler),
                'headers' => $headers,
                'connect_timeout' => self::DISCOVERY_TIMEOUT_SECONDS,
                'timeout' => self::DISCOVERY_TIMEOUT_SECONDS,
                'http_errors' => true,
            ]);

            $settled = Utils::settle([
                'model' => $client->getAsync(self::MODEL_INFO_PATH),
                'server' => $client->getAsync(self::SERVER_INFO_PATH),
            ])->wait();

            return self::fromResponses(
                self::decodedBody($settled['model'] ?? null),
                self::decodedBody($settled['server'] ?? null),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The server root the info endpoints hang off: the configured `baseUrl`
     * without a trailing slash or a trailing `/v1` (case-insensitive). A
     * deeper prefix (`https://proxy/sglang/v1`) keeps everything before the
     * `/v1`, so a reverse proxy mounted under a path still resolves.
     */
    public static function rootUrl(string $baseUrl): string
    {
        $trimmed = rtrim($baseUrl, '/');

        return (string) preg_replace('~/v1$~i', '', $trimmed);
    }

    /**
     * The model's total token window - prompt plus output - as the server
     * reports it. When only `max_req_input_len` is known that figure stands
     * in: it is a few tokens BELOW the true total, so the stand-in can only
     * make an output budget derived from it smaller, never overrun the server.
     */
    public function totalWindow(): ?int
    {
        return $this->contextLength ?? $this->maxReqInputLen;
    }

    /**
     * The input budget {@see SglangProvider::contextWindow()} reports: the
     * enforced input ceiling less `$outputHeadroom`, never above the total
     * window - the same `min(context_length, max_req_input_len − headroom)`
     * arithmetic the transcribed Qwen3.8 constant was derived with, now over
     * live figures. Null when the server reported neither limit.
     */
    public function inputWindow(int $outputHeadroom): ?int
    {
        if ($this->maxReqInputLen !== null) {
            $window = $this->maxReqInputLen - $outputHeadroom;

            if ($this->contextLength !== null) {
                $window = min($this->contextLength, $window);
            }
        } elseif ($this->contextLength !== null) {
            $window = $this->contextLength - $outputHeadroom;
        } else {
            return null;
        }

        return $window > 0 ? $window : null;
    }

    private function isEmpty(): bool
    {
        return $this->servedModelName === null
            && $this->contextLength === null
            && $this->maxReqInputLen === null
            && $this->toolCallParser === null
            && $this->reasoningParser === null
            && !$this->reportsToolCallParser;
    }

    /**
     * @return array<mixed>|null
     */
    private static function decodedBody(mixed $settled): ?array
    {
        if (!is_array($settled) || ($settled['state'] ?? null) !== 'fulfilled') {
            return null;
        }

        $response = $settled['value'] ?? null;
        if (!$response instanceof ResponseInterface) {
            return null;
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<mixed> $first
     * @param array<mixed> $second
     */
    private static function firstString(array $first, array $second, string $key): ?string
    {
        return self::nonEmptyString($first[$key] ?? null) ?? self::nonEmptyString($second[$key] ?? null);
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        // SGLang stringifies some unset enums as the literal "null"
        // (`disaggregation_mode` in the 2026-10-02 fixture), so that spelling
        // counts as absent too.
        if (!is_string($value) || $value === '' || strtolower($value) === 'null') {
            return null;
        }

        return $value;
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }
}
