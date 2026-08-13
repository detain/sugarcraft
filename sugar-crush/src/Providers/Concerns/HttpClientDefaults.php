<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers\Concerns;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * The single place a provider's HTTP client is configured, so the connect
 * bounds below cannot be set on one provider and forgotten on the next one
 * somebody adds.
 *
 * WHY A CONNECT BOUND AND EMPHATICALLY NOT A TOTAL REQUEST TIMEOUT
 * ----------------------------------------------------------------
 * Before this, nothing under `src/Providers/` set any timeout at all, so a
 * provider host that is simply unreachable (black-holed route, dead port,
 * DNS that never answers) sat there for as long as the transport's own
 * default allowed: 300s on the curl path (`CURLOPT_CONNECTTIMEOUT`'s libcurl
 * default) and `default_socket_timeout` on the PHP stream path (60s out of
 * the box, and genuinely unbounded where an operator has set it to -1). On
 * the interactive path that is capped earlier by
 * {@see \SugarCraft\Crush\Backend\EngineBackend}'s 120s idle timer, which
 * SIGKILLs the forked child; on `completeAsyncBlocking()` and on the one-shot
 * `-p`/`run` path ({@see \SugarCraft\Crush\Cli\NonInteractive}) there is no
 * fork and no timer, so the user waits out the full transport default with
 * nothing on screen.
 *
 * A total `timeout` must NOT be added here, however symmetrical it looks next
 * to `src/MCP/McpClient.php`'s `timeout => 30`. That value is correct there
 * because an MCP tool call is a short RPC; an LLM completion on a loaded or
 * remote server routinely runs far past any such ceiling, so a flat total
 * timeout would abort real, in-flight work rather than only genuinely hung
 * connections - the precise bug this seam exists to avoid. Anything that ever
 * does need an overall deadline must set it well above
 * `EngineBackend::COMPLETE_TIMEOUT_SECONDS` (120s), which is itself an *idle*
 * ceiling re-armed on every streamed frame.
 *
 * TWO TRANSPORTS, TWO DIFFERENT KNOBS
 * -----------------------------------
 * Guzzle does not use one transport. `Utils::chooseHandler()` builds a curl
 * handler and then, whenever `allow_url_fopen` is on, wraps it in
 * `Proxy::wrapStreaming()`, which routes **every request carrying
 * `stream => true` to `StreamHandler`** instead. That is not a corner case
 * here: `stream => true` is the live path - {@see
 * \SugarCraft\Crush\Providers\SglangProvider::completeStream()}, {@see
 * \SugarCraft\Crush\Providers\CustomProvider::completeStream()} and
 * openai-php's own `makeStreamHandler()` all set it, and `completeStream()`
 * is what `Runtime` and `AgentManager` actually call.
 *
 * `StreamHandler` has no `add_connect_timeout()` method, so a bare
 * `connect_timeout` is silently discarded there. Measured against a
 * SYN-dropping host with `SUGARCRUSH_CONNECT_TIMEOUT=2`: the non-streaming
 * request failed in 2.01s while the streaming one took 60.07s - i.e. the
 * configured value did nothing and `default_socket_timeout` was the only
 * thing left. With the middleware below, that same streaming request fails
 * in 2.01s, and still does with `default_socket_timeout=-1`, where there was
 * previously no ceiling at all. It translates the same policy into the two
 * options `StreamHandler` does understand:
 *
 *   - `timeout` becomes the http stream-context `timeout`, which PHP applies
 *     to the connect *and* to reads until `fopen()` returns - i.e. it bounds
 *     DNS + TCP + TLS + the response-header read, and nothing after that.
 *   - `read_timeout` is applied by `StreamHandler` with `stream_set_timeout()`
 *     the instant `fopen()` returns, which re-arms the socket for the body
 *     with {@see STREAM_READ_IDLE_TIMEOUT_SECONDS} before a single token has
 *     been read.
 *
 * Setting `timeout` WITHOUT `read_timeout` is the trap, and it is a bad one:
 * that same measurement rig, given a server that sent headers immediately and
 * then thought for 6s before its first SSE token, died with "Unable to read
 * from stream" at exactly the 2s mark. The pair is what makes this safe - with
 * both set, the identical request streamed its tokens at t=6.01s, 9.01s and
 * 12.01s, each delivered as it arrived.
 *
 * `read_timeout` is per-read, not cumulative, so it is an *idle* bound and
 * never a deadline on the completion. It is also strictly more permissive
 * than what shipped before it: with nothing set, PHP was already applying
 * `default_socket_timeout` to those body reads, so any gap over 60s between
 * tokens killed the stream. Measured with `default_socket_timeout=3` and a 6s
 * first-token gap: "Unable to read from stream" at 3.01s without
 * `read_timeout`, all three tokens streamed with it.
 *
 * WHAT IS STILL NOT BOUNDED, PRECISELY
 * ------------------------------------
 * The streaming bound ends when the response headers land, so a server that
 * accepts the connection and then withholds its response *head* past the
 * configured value is treated as dead. Every OpenAI-compatible server in play
 * (SGLang, vLLM, anything on Starlette) emits `http.response.start` before it
 * begins generating, so the head is not gated on model work - but a
 * deployment that queues at the HTTP layer for longer than this wants
 * `SUGARCRUSH_CONNECT_TIMEOUT` raised. Nothing here bounds token gaps, total
 * completion length, or a curl-path request's body.
 *
 * Bedrock is not routed through this middleware: the AWS SDK never passes
 * `stream => true` (`ConverseStream` reassembles its event stream from a
 * buffered body, as {@see \SugarCraft\Crush\Providers\BedrockProvider}'s own
 * note says), so its requests stay on curl where the plain `connect_timeout`
 * it is handed already works.
 */
trait HttpClientDefaults
{
    /**
     * Seconds allowed for DNS + TCP + TLS before a host is called dead.
     *
     * 15s sits mid-range of the audited 10-30s window. A working handshake
     * to a distant, loaded host lands comfortably inside 1-3s even with slow
     * DNS, so 15s leaves roughly an order of magnitude of headroom while
     * still cutting an unreachable host down from libcurl's 300s default
     * (and the stream path's `default_socket_timeout`) to something a user
     * will sit through.
     */
    private const CONNECT_TIMEOUT_SECONDS = 15.0;

    /**
     * The smallest override that survives the trip down to the transport.
     *
     * Guzzle hands curl `CURLOPT_CONNECTTIMEOUT_MS = connect_timeout * 1000`
     * and curl casts that to an int, so anything under a millisecond arrives
     * as 0 - which curl reads as "use my default" and turns straight back
     * into the 300s wait this whole seam exists to remove. PHP's stream
     * context does the same thing one microsecond further down. A sub-ms
     * connect budget cannot express anything useful anyway, so treat it as
     * operator error rather than quietly reinstating the hang.
     */
    private const MIN_CONNECT_TIMEOUT_SECONDS = 0.001;

    /**
     * Idle ceiling for a single read off an in-flight SSE body.
     *
     * NOT a deadline: PHP re-arms this on every read, so it only fires when
     * the socket has been completely silent for an hour - a connection that
     * died without a FIN, never a model that is merely thinking. An hour is
     * far past any plausible inter-token gap and, on the interactive path,
     * far past `EngineBackend::COMPLETE_TIMEOUT_SECONDS` (120s), which
     * settles the turn long before this could. Its real job is to *replace*
     * the `default_socket_timeout` PHP would otherwise apply to those same
     * reads, which at the usual 60s is short enough to guillotine a genuine
     * thinking pause.
     */
    private const STREAM_READ_IDLE_TIMEOUT_SECONDS = 3600.0;

    /**
     * Escape hatch for deployments this default is wrong for - a satellite
     * link, or a lab box that needs it far shorter. Follows the existing
     * `SUGARCRUSH_*` `getenv()` convention (`SUGARCRUSH_SEARCH_ENDPOINT`,
     * `SUGARCRUSH_MODEL`, ...) rather than introducing a new config
     * mechanism for one scalar.
     */
    private const CONNECT_TIMEOUT_ENV = 'SUGARCRUSH_CONNECT_TIMEOUT';

    /**
     * Named so a second pass through {@see guzzleClient()} on a shared stack
     * replaces the middleware instead of stacking a duplicate.
     */
    private const STREAM_BOUNDS_MIDDLEWARE = 'sugarcrush.stream_connect_bounds';

    /**
     * Builds a Guzzle client carrying this library's connect-bound policy on
     * both of Guzzle's transports.
     *
     * `$options` wins on collision so a caller can deliberately override the
     * connect timeout (tests, an injected handler stack); everything else it
     * passes - `base_uri`, `headers` - is untouched. A caller-supplied
     * `handler` is wrapped rather than replaced, so a `MockHandler` keeps
     * behaving exactly as it would without this seam.
     *
     * @param array<string, mixed> $options
     */
    private static function guzzleClient(array $options = []): Client
    {
        $handler = $options['handler'] ?? HandlerStack::create();

        if ($handler instanceof HandlerStack) {
            // remove() is a no-op for an absent name, so this stays idempotent
            // if the same stack is handed to guzzleClient() twice.
            $handler->remove(self::STREAM_BOUNDS_MIDDLEWARE);
            $handler->push(self::streamTransportBounds(), self::STREAM_BOUNDS_MIDDLEWARE);
        } else {
            // A bare callable handler is left bare - wrapping it in a
            // HandlerStack would silently add http_errors/redirects/cookies
            // that the caller did not ask for.
            $handler = (self::streamTransportBounds())($handler);
        }

        $options['handler'] = $handler;

        return new Client($options + ['connect_timeout' => self::connectTimeoutSeconds()]);
    }

    /**
     * Middleware translating the connect policy for requests Guzzle will hand
     * to `StreamHandler`, which understands neither `connect_timeout` nor any
     * other name for it. See this trait's docblock for why it takes two
     * options and what each one does and does not bound.
     *
     * Both are set with `??=` so an explicit per-request value still wins.
     */
    private static function streamTransportBounds(): callable
    {
        return static function (callable $handler): callable {
            return static function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
                if (!self::routedToStreamHandler($options)) {
                    return $handler($request, $options);
                }

                $options['timeout'] ??= self::connectTimeoutSeconds();
                $options['read_timeout'] ??= self::STREAM_READ_IDLE_TIMEOUT_SECONDS;

                return $handler($request, $options);
            };
        };
    }

    /**
     * Mirrors `Proxy::wrapStreaming()`'s own routing test - `empty($options
     * ['stream'])` - so this cannot drift out of step with the decision Guzzle
     * actually makes.
     *
     * The second clause covers the other way `StreamHandler` ends up serving
     * everything: `Utils::chooseHandler()` falls back to it wholesale when it
     * cannot build a curl handler at all. `extension_loaded()` is the coarse
     * form of that test - Guzzle additionally rejects a curl too old for its
     * handler, which this will miss, leaving such a build with the plain
     * `connect_timeout` and no worse off than before this seam existed.
     *
     * @param array<string, mixed> $options
     */
    private static function routedToStreamHandler(array $options): bool
    {
        return !empty($options['stream']) || !\extension_loaded('curl');
    }

    /**
     * The connect bound as a bare scalar, for SDK clients that take their own
     * transport config instead of a Guzzle instance (the AWS SDK's
     * `http.connect_timeout`, for example).
     */
    private static function connectTimeoutSeconds(): float
    {
        $raw = getenv(self::CONNECT_TIMEOUT_ENV);

        // A non-numeric, non-positive or sub-millisecond override is operator
        // error; falling back to the default beats disabling the bound, since
        // every transport in play reads 0 as "use my own default" - which is
        // the exact hang being fixed. See MIN_CONNECT_TIMEOUT_SECONDS.
        if ($raw === false || !is_numeric($raw) || (float) $raw < self::MIN_CONNECT_TIMEOUT_SECONDS) {
            return self::CONNECT_TIMEOUT_SECONDS;
        }

        return (float) $raw;
    }
}
