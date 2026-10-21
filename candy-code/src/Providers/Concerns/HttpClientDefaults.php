<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers\Concerns;

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
 * {@see \SugarCraft\Code\Backend\EngineBackend}'s 120s idle timer, which
 * SIGKILLs the forked child; on `completeAsyncBlocking()` and on the one-shot
 * `-p`/`run` path ({@see \SugarCraft\Code\Cli\NonInteractive}) there is no
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
 * \SugarCraft\Code\Providers\SglangProvider::completeStream()}, {@see
 * \SugarCraft\Code\Providers\CustomProvider::completeStream()} and
 * openai-php's own `makeStreamHandler()` all set it, and `completeStream()`
 * is what `Runtime` and `AgentManager` actually call.
 *
 * `StreamHandler` has no `add_connect_timeout()` method, so a bare
 * `connect_timeout` is silently discarded there. Measured against a
 * SYN-dropping host with `CANDY_CODE_CONNECT_TIMEOUT=2`: the non-streaming
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
 * ISS-024: `timeout` on the stream path bounds DNS + TCP + TLS + the
 * response-HEADER read, so reusing the connect budget for it made a 15s
 * "connect" setting silently cap how long a server may think before it sends
 * its head. The two bounds are now decoupled: the connect budget is enforced
 * by an explicit pre-flight TCP probe (which `timeout` alone cannot isolate —
 * PHP applies it to the whole head-wait), and the head-wait gets its own
 * generous {@see STREAM_HEADER_TIMEOUT_SECONDS} bound.
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
 * The header bound ({@see STREAM_HEADER_TIMEOUT_SECONDS}) lands where the old
 * connect-bound value used to: DNS + TCP + TLS + the response-head read. A
 * server that accepts the connection and then withholds its head past THAT
 * value is treated as dead, and every OpenAI-compatible server in play
 * (SGLang, vLLM, anything on Starlette) emits `http.response.start` before it
 * begins generating, so 120s is an order of magnitude past real head waits -
 * a deployment that queues at the HTTP layer longer still wants
 * `streamHeaderTimeoutSeconds` raised. The connect phase itself is bounded by
 * the pre-flight probe below, which is also what makes the connect-timeout
 * refusal message honest ("timed out establishing" instead of StreamHandler's
 * catch-all "Connection refused"). Nothing here bounds token gaps, total
 * completion length, or a curl-path request's body.
 *
 * Bedrock does not ride this middleware: the AWS SDK builds its own handler
 * stack, so nothing pushed by {@see guzzleClient()} sees its requests. Its
 * ConverseStream calls nevertheless DO carry `@http.stream` (measured,
 * ISS-075), so the SDK hands them to StreamHandler, where a bare
 * `http.connect_timeout` is discarded exactly as above - which is why
 * {@see \SugarCraft\Code\Providers\BedrockProvider} mirrors this trait's
 * bounds into its own `http` array instead of routing through here.
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
     * `CANDY_CODE_*` `getenv()` convention (`CANDY_CODE_SEARCH_ENDPOINT`,
     * `CANDY_CODE_MODEL`, ...) rather than introducing a new config
     * mechanism for one scalar.
     */
    private const CONNECT_TIMEOUT_ENV = 'CANDY_CODE_CONNECT_TIMEOUT';

    /**
     * The settings key under that env var (roadmap N-P4a): the persisted form
     * of the same bound, outranked by the env var when the env var actually
     * says something. Read when a client is BUILT — at launch and on a
     * provider switch — because both transports take it from the client.
     */
    private const CONNECT_TIMEOUT_CONFIG_KEY = 'connectTimeoutSeconds';

    /**
     * The settings key that replaces {@see STREAM_READ_IDLE_TIMEOUT_SECONDS}.
     * Read per streaming REQUEST by {@see streamTransportBounds()}, so a save
     * applies from the next turn. Values under
     * {@see MIN_STREAM_READ_IDLE_TIMEOUT_SECONDS} fall back to the default: a
     * per-read bound that short would abort a model that is merely thinking,
     * which is the failure this whole seam exists to prevent.
     */
    private const STREAM_READ_IDLE_CONFIG_KEY = 'streamIdleTimeoutSeconds';

    private const MIN_STREAM_READ_IDLE_TIMEOUT_SECONDS = 30.0;

    /**
     * How long a streaming request may wait for the response HEAD (status +
     * headers) once the connection itself is alive — ISS-024.
     *
     * This is the value the stream transport's `timeout` option carries. It
     * used to be the connect bound (15s), which conflated two failures: a
     * black-holed route and a healthy server that queues HTTP-layer work
     * behind a model. 120s matches the engine's own idle ceiling's order of
     * magnitude and is far past any real head-wait, while still cutting the
     * `default_socket_timeout`-at-(-1) unbounded hang the pair with
     * `read_timeout` exists to prevent. Deliberately NOT re-derived from
     * `CANDY_CODE_CONNECT_TIMEOUT`: raising the connect bound for a satellite
     * link must not silently raise the head budget too, and vice versa.
     */
    private const STREAM_HEADER_TIMEOUT_SECONDS = 120.0;

    /**
     * Floor for {@see STREAM_HEADER_TIMEOUT_SECONDS}: under ~10s the value
     * stops being a head budget and starts being the 15s bug again wearing a
     * different config key; nonsense falls back to the default (honourable
     * law, same as every other bound here).
     */
    private const MIN_STREAM_HEADER_TIMEOUT_SECONDS = 10.0;

    /**
     * The settings key for the header bound. Read per streaming REQUEST by
     * {@see streamTransportBounds()}, mirroring
     * {@see STREAM_READ_IDLE_CONFIG_KEY}: a save applies from the next turn,
     * no client rebuild, no restart.
     */
    private const STREAM_HEADER_TIMEOUT_CONFIG_KEY = 'streamHeaderTimeoutSeconds';

    /**
     * Request option that skips the ISS-024 connect pre-flight for one
     * request. Production never sets it; capture-style tests that swap the
     * terminal handler (so no real dial ever happens) use it to keep the
     * pre-flight from rejecting on the deliberately-unresolvable `.invalid`
     * host before the capture is reached.
     */
    private const CONNECT_PREFLIGHT_OPT_OUT = 'candy_connect_preflight';

    /**
     * Named so a second pass through {@see guzzleClient()} on a shared stack
     * replaces the middleware instead of stacking a duplicate.
     */
    private const STREAM_BOUNDS_MIDDLEWARE = 'candy-code.stream_connect_bounds';

    /**
     * Minimum seconds between two deliveries of a {@see
     * \SugarCraft\Code\Providers\CompleteRequest::$onHeartbeat} beat.
     *
     * libcurl's progress callback fires many times per second during an
     * active transfer (measured 18x in 3s); the consumer's job is "something
     * is alive", which one beat per second states plainly. Throttling here
     * rather than in every caller keeps a confused consumer from being
     * hammered, and keeps the E524 guarantee (a beat lands while the socket
     * is silent) intact - the first tick of every request fires immediately,
     * because the throttle clock starts one full interval in the past.
     */
    private const HEARTBEAT_MIN_INTERVAL_SECONDS = 1.0;

    /**
     * Request options carrying a batch completion's progress heartbeat.
     *
     * Returns `[]` for no callback - so `+ self::heartbeatOptions(...)` on a
     * request-options array is byte-neutral for every request that does not
     * ask to be watched (the E493 consumer half landed at round 71: the
     * forked batch turn is the first caller that asks; everything else -
     * sync paths and un-forked callers - still answers `[]` here).
     * Otherwise one `progress` entry: Guzzle hands it to
     * `CURLOPT_PROGRESSFUNCTION` on the curl path and drives it from body
     * reads on the stream path, which is the ONLY mechanism E524 measured to
     * fire inside a blocking transfer (pcntl signals cannot).
     *
     * The wrapper is fail-soft twice over: every throw from the consumer's
     * closure is swallowed (a broken heartbeat must never fail a paid
     * completion), and the wrapper always returns `false` - the libcurl
     * "keep transferring" value - so even a consumer that accidentally
     * returns truthy cannot abort its own request. Guzzle's CurlFactory
     * discards the return on one transport; this covers both anyway.
     */
    private static function heartbeatOptions(?\Closure $onHeartbeat): array
    {
        if ($onHeartbeat === null) {
            return [];
        }

        // Seeded one interval in the past so the first callback tick delivers.
        $last = -self::HEARTBEAT_MIN_INTERVAL_SECONDS;

        return [
            'progress' => static function (int $downloadTotal, int $downloadedBytes, int $uploadTotal, int $uploadedBytes) use ($onHeartbeat, &$last): bool {
                $now = \microtime(true);

                if ($now - $last < self::HEARTBEAT_MIN_INTERVAL_SECONDS) {
                    return false;
                }

                $last = $now;

                try {
                    $onHeartbeat();
                } catch (\Throwable) {
                    // Fail-soft by contract - see CompleteRequest::$onHeartbeat.
                }

                return false;
            },
        ];
    }

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
     *
     * ISS-024 decoupling: the connect budget is enforced here by an explicit
     * TCP pre-flight (the transport's `timeout` cannot isolate the connect
     * phase from the head-wait), and `timeout` itself carries the dedicated,
     * generous header bound.
     *
     * ISS-024 review fix: that whole translation was assuming Guzzle's
     * `Proxy::wrapStreaming()` actually fronts this request. It does not when
     * `allow_url_fopen` is Off and cURL is loaded - `Utils::chooseHandler()`
     * then builds the PLAIN curl handler, the `stream` option is ignored, and
     * a `timeout` written here would land as `CURLOPT_TIMEOUT_MS`, a TOTAL
     * deadline - the exact shape this codebase's total-timeout census exists
     * to forbid, wearing the stream arm's clothes. Those requests now take
     * the curl arm instead: no total deadline, no pre-flight (cURL honours
     * the client-level connect budget natively), and the head/idle bounds
     * emulated per-operation by the progress watchdog in
     * {@see curlStreamArm()}.
     */
    private static function streamTransportBounds(): callable
    {
        return static function (callable $handler): callable {
            return static function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
                if (self::curlServesStreamInstead($options)) {
                    return self::curlStreamArm($handler, $request, $options);
                }

                if (!self::routedToStreamHandler($options)) {
                    return $handler($request, $options);
                }

                $preflighted = self::connectPreflight($request, $options);
                if ($preflighted instanceof PromiseInterface) {
                    // The probe already answered "dead host" (or "name does
                    // not resolve") with honest prose - the request never
                    // reaches the transport, which could only garble it.
                    return $preflighted;
                }

                $options['timeout'] ??= self::streamHeaderTimeoutSeconds();
                $options['read_timeout'] ??= self::streamReadIdleTimeoutSeconds();

                if ($preflighted !== true) {
                    return $handler($request, $options);
                }

                // The probe connected, so StreamHandler's catch-all
                // `Connection refused for URI` is now a KNOWN lie: TCP was
                // fine moments ago, and the only failure it can still be
                // describing at this point is the head arriving later than
                // the bound above (or a server that died between probe and
                // dial). Replace the message; keep the class and the chain
                // so `TransientFailure::isTransient()`, which walks
                // `getPrevious()`, classifies identically.
                return $handler($request, $options)->then(null, static function (\Throwable $failure) use ($request): \Throwable {
                    if ($failure instanceof \GuzzleHttp\Exception\ConnectException
                        && str_starts_with($failure->getMessage(), 'Connection refused for URI')
                        && $failure->getPrevious() === null
                    ) {
                        throw new \GuzzleHttp\Exception\ConnectException(
                            'Response headers did not arrive for ' . (string) $request->getUri()
                                . ' within ' . self::streamHeaderTimeoutSeconds() . 's of the connection being established; '
                                . 'the TCP connect itself succeeded. If this server queues work behind the HTTP layer, raise '
                                . 'the `streamHeaderTimeoutSeconds` setting - `connectTimeoutSeconds` is no longer what caps this wait.',
                            $request,
                            previous: $failure,
                        );
                    }

                    throw $failure;
                });
            };
        };
    }

    /**
     * ISS-024: dial the target's TCP socket under the CONNECT budget alone,
     * before handing a stream-routed request to a transport that must fold
     * connect and head-wait into one number.
     *
     * Returns `true` when the probe connected (caller attaches the honest
     * reject-rewriter), `null` when the probe was skipped or the failure is
     * one StreamHandler will itself report accurately (real ECONNREFUSED),
     * and a `RejectedPromise` carrying the final failure when the probe
     * settled the question the transport could not answer honestly.
     */
    private static function connectPreflight(RequestInterface $request, array $options): bool|null|PromiseInterface
    {
        if (($options[self::CONNECT_PREFLIGHT_OPT_OUT] ?? null) === false) {
            return null;
        }

        $proxy = $options['proxy'] ?? null;
        if (\is_array($proxy) && $proxy !== []) {
            // With a proxy the socket that matters opens to the PROXY, whose
            // endpoint Guzzle rewrites into the request - probing the origin
            // here would measure the wrong host entirely. Skip.
            return null;
        }

        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = $uri->getHost();
        if ($host === '') {
            return null;
        }
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
        $target = 'tcp://' . (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $host . ']' : $host) . ':' . $port;

        $budget = self::honourableSeconds($options['connect_timeout'] ?? null, self::MIN_CONNECT_TIMEOUT_SECONDS)
            ?? self::connectTimeoutSeconds();

        $errno = 0;
        $errstr = '';
        $started = \microtime(true);
        $socket = @\stream_socket_client($target, $errno, $errstr, $budget, STREAM_CLIENT_CONNECT);

        if (\is_resource($socket)) {
            \fclose($socket);

            return true;
        }

        $elapsed = \microtime(true) - $started;

        if (str_contains($errstr, 'php_network_getaddresses') || str_contains($errstr, 'Name or service not known') || str_contains($errstr, 'nodename nor servname')) {
            // StreamHandler blames this on "Connection refused" too - the one
            // failure class whose honest prose costs nothing extra to emit.
            return new \GuzzleHttp\Promise\RejectedPromise(new \GuzzleHttp\Exception\ConnectException(
                'Could not resolve host `' . $host . '` for ' . (string) $uri . ': ' . $errstr,
                $request,
            ));
        }

        if (str_contains($errstr, 'Connection refused')) {
            // A real refusal: the transport's own message is accurate here,
            // and re-dialing costs microseconds. Pass through untouched.
            return null;
        }

        if ($elapsed >= $budget * 0.9) {
            return new \GuzzleHttp\Promise\RejectedPromise(new \GuzzleHttp\Exception\ConnectException(
                'Timed out after ' . round($elapsed, 1) . 's establishing the connection to ' . $target
                    . '; raise `connectTimeoutSeconds` (or ' . self::CONNECT_TIMEOUT_ENV . ') if this route needs longer - this bound covers the TCP connect only, not the response.',
                $request,
            ));
        }

        // Any other probe failure (unreachable-network, permission, transient
        // kernel answer): let the transport try, it will produce its own
        // classification and the header bound still stands behind it.
        return null;
    }

    /**
     * The head/idle bounds for a stream request the PLAIN curl handler will
     * serve (ISS-024 review fix). cURL has no per-phase read deadline: its
     * only timeout is total, so the bound rides the progress callback
     * instead, which libcurl rings periodically THROUGHOUT a transfer - idle
     * included. Before the first received byte the elapsed silence answers
     * to the header bound; once bytes move the same clock re-arms against
     * the read-idle bound, mirroring the two StreamHandler options the
     * stream arm sets natively. The approximation is stated rather than
     * hidden: progress counts bytes received, response headers included, so
     * a server that flushes headers and then thinks hands the watchdog to
     * the idle bound at the moment the headers landed - which is where
     * StreamHandler's `read_timeout` would take over too, headers being
     * parsed by then.
     */
    private static function curlStreamArm(callable $handler, RequestInterface $request, array $options): PromiseInterface
    {
        $stall = new \ArrayObject(['aborted' => false, 'bound' => 0.0, 'phase' => 'response headers']);
        $options = self::curlStreamBounds($options, $stall);

        return $handler($request, $options)->then(null, static function (\Throwable $failure) use ($request, $stall): \Throwable {
            throw self::curlStallFailure($failure, $request, $stall);
        });
    }

    /**
     * libcurl reports a watchdog abort as errno 42 (CURLE_ABORTED_BY_CALLBACK);
     * the stall state says which bound fired. Guzzle wraps that as
     * `cURL error 42: <text>` and the text itself is libcurl-version
     * dependent ("Operation was aborted by an application callback" on
     * modern builds, "Abort was called on an request" on old ones), so the
     * errno is the gate and the substring a fallback for hand-built
     * failures. A transfer aborted at the callback may still carry its
     * partial 2xx response (headers landed, body died mid-frame) - that
     * response is deliberately DROPPED: a truncated stream is not a
     * response to route on, and dropping it keeps the failure in the
     * no-response transport family TransientFailure already retries. Same
     * class and chain as every other transport cut. Split from the arm so
     * the rewrite is pinnable without living through a real stall.
     */
    private static function curlStallFailure(\Throwable $failure, RequestInterface $request, \ArrayObject $stall): \Throwable
    {
        if ($stall['aborted'] && $failure instanceof \GuzzleHttp\Exception\RequestException
            && (42 === ($failure->getHandlerContext()['errno'] ?? null) || \stripos($failure->getMessage(), 'abort') !== false)
        ) {
            return new \GuzzleHttp\Exception\RequestException(
                \sprintf(self::CURL_STALL_MESSAGE_FORMAT, (int) \round((float) $stall['bound']), (string) $stall['phase']),
                $request,
                null,
                $failure,
            );
        }

        return $failure;
    }

    /**
     * Sentence naming the bound a curl-served stream stalled against. The
     * `%d` is the bound that fired, the `%s` the phase (response headers
     * before the first byte, a further body frame after).
     */
    private const CURL_STALL_MESSAGE_FORMAT = 'Stream transfer stalled: no bytes for %ds while waiting for %s (cURL transport, per-operation bound - NOT a total deadline; see the streamHeaderTimeoutSeconds and streamReadIdleTimeoutSeconds settings).';

    /**
     * The watchdog itself, separated from {@see curlStreamArm()} so the
     * arithmetic is pinnable with a synthetic clock - `allow_url_fopen` is
     * an INI_SYSTEM flag no test may flip in-process, and this box (like
     * CI) runs with it On, which keeps the arm itself out of the suite's
     * reach. Chains, never clobbers, a caller-supplied progress callback
     * (the heartbeat wrapper rides the same option), and swallows the
     * chain's throw: fail-soft is the heartbeat contract, and a watchdog
     * that dies taking its clock with it would stop bounding the transfer.
     *
     * @param array<string, mixed>    $options
     * @param \ArrayObject<string,mixed> $stall  out-parameter the reject
     *        rewriter reads when the tick returns the abort answer
     * @param (callable():float)|null $clock
     *
     * @return array<string, mixed>
     */
    private static function curlStreamBounds(array $options, \ArrayObject $stall, ?callable $clock = null): array
    {
        $headerBound = self::streamHeaderTimeoutSeconds();
        $idleBound = self::streamReadIdleTimeoutSeconds();
        $clock ??= static fn (): float => \microtime(true);
        $existing = isset($options['progress']) && \is_callable($options['progress'])
            ? $options['progress']
            : null;

        /** @var array{mark:float,bytes:int} $state */
        $state = ['mark' => $clock(), 'bytes' => 0];

        $options['progress'] = static function (int $downloadTotal, int $downloadedBytes, int $uploadTotal, int $uploadedBytes) use (&$state, $existing, $clock, $headerBound, $idleBound, $stall): int {
            if ($downloadedBytes > $state['bytes']) {
                $state['bytes'] = $downloadedBytes;
                $state['mark'] = $clock();
            }

            $bound = $state['bytes'] > 0 ? $idleBound : $headerBound;

            if ($clock() - $state['mark'] > $bound) {
                $stall['aborted'] = true;
                $stall['bound'] = $bound;
                $stall['phase'] = $state['bytes'] > 0 ? 'a further body frame' : 'response headers';

                // Non-zero is libcurl's abort answer; it surfaces as the
                // canned Abort RequestException the arm rewrites honestly.
                return 1;
            }

            if ($existing !== null) {
                try {
                    $existing($downloadTotal, $downloadedBytes, $uploadTotal, $uploadedBytes);
                } catch (\Throwable) {
                    // Fail-soft by contract - see CompleteRequest::$onHeartbeat.
                }
            }

            return 0;
        };

        return $options;
    }

    /**
     * Mirrors `Proxy::wrapStreaming()`'s own routing test - `empty($options
     * ['stream'])` - AND `Utils::chooseHandler()`'s fopen gate: the wrapper
     * (and with it the stream semantics of `timeout`/`read_timeout`) exists
     * only when `allow_url_fopen` lets Guzzle build a stream handler at all.
     * ISS-024 review fix: before the gate, `allow_url_fopen=Off` + cURL was
     * mislabelled as stream-served and the header bound became a total
     * deadline; that shape is now {@see curlServesStreamInstead()}'s.
     *
     * The `!extension_loaded('curl')` clause covers the other way
     * `StreamHandler` ends up serving everything: `Utils::chooseHandler()`
     * falls back to it wholesale when it cannot build a curl handler at all.
     * `extension_loaded()` is the coarse form of that test - Guzzle
     * additionally rejects a curl too old for its handler, which this will
     * miss, leaving such a build with the plain `connect_timeout` and no
     * worse off than before this seam existed.
     *
     * @param array<string, mixed> $options
     */
    private static function routedToStreamHandler(array $options): bool
    {
        return self::streamTransportFor($options, self::fopenWrappersAvailable(), \extension_loaded('curl')) === 'stream';
    }

    /**
     * The third shape the routing table answers with (ISS-024 review fix):
     * a stream-flagged request that the PLAIN curl handler will actually
     * serve, because `allow_url_fopen` denied Guzzle its stream wrapper.
     *
     * @param array<string, mixed> $options
     */
    private static function curlServesStreamInstead(array $options): bool
    {
        return self::streamTransportFor($options, self::fopenWrappersAvailable(), \extension_loaded('curl')) === 'curl';
    }

    /**
     * Whether `stream_context` fopen wrappers can carry a request at all -
     * `Utils::chooseHandler()`'s first branch reads this same INI System
     * value, which no running script may change. The indirection exists for
     * that reason: the routing table below is pure, and the pin feeds it
     * both polarities of this flag and of `curl` in-process while the
     * production call sites read what the box actually has.
     */
    private static function fopenWrappersAvailable(): bool
    {
        return (bool) \ini_get('allow_url_fopen');
    }

    /**
     * The routing table both predicates read (mirrors `chooseHandler` +
     * `Proxy::wrapStreaming` in one place so the arms cannot drift apart):
     * `stream` when StreamHandler serves the request (everything without
     * curl; flagged requests with curl AND fopen wrappers), `curl` when the
     * plain curl handler serves a flagged request without wrappers, `none`
     * when curl takes an unflagged request and no emulation belongs anywhere
     * (batch completions keep their native connect budget and no bounds).
     *
     * @param array<string, mixed> $options
     */
    private static function streamTransportFor(array $options, bool $fopen, bool $curl): string
    {
        if (!$curl) {
            return 'stream';
        }

        if (empty($options['stream'])) {
            return 'none';
        }

        return $fopen ? 'stream' : 'curl';
    }

    /**
     * The connect bound as a bare scalar, for SDK clients that take their own
     * transport config instead of a Guzzle instance (the AWS SDK's
     * `http.connect_timeout`, for example).
     */
    private static function connectTimeoutSeconds(): float
    {
        // A non-numeric, non-positive or sub-millisecond override is operator
        // error; falling back beats disabling the bound, since every
        // transport in play reads 0 as "use my own default" - which is the
        // exact hang being fixed. See MIN_CONNECT_TIMEOUT_SECONDS. A rejected
        // env value falls through to the `connectTimeoutSeconds` setting,
        // exactly as an absent one does: the env var outranks the persisted
        // value only when it actually says something.
        $env = getenv(self::CONNECT_TIMEOUT_ENV);

        return self::honourableSeconds($env === false ? null : $env, self::MIN_CONNECT_TIMEOUT_SECONDS)
            ?? self::honourableSeconds(self::settingsConfig()[self::CONNECT_TIMEOUT_CONFIG_KEY] ?? null, self::MIN_CONNECT_TIMEOUT_SECONDS)
            ?? self::CONNECT_TIMEOUT_SECONDS;
    }

    /**
     * The per-read idle bound for a streaming body: the
     * `streamIdleTimeoutSeconds` setting, else
     * {@see STREAM_READ_IDLE_TIMEOUT_SECONDS}. Per READ, never a total - PHP
     * re-arms it on every read - so no value of it can bound a completion's
     * length.
     */
    private static function streamReadIdleTimeoutSeconds(): float
    {
        return self::honourableSeconds(
            self::settingsConfig()[self::STREAM_READ_IDLE_CONFIG_KEY] ?? null,
            self::MIN_STREAM_READ_IDLE_TIMEOUT_SECONDS,
        ) ?? self::STREAM_READ_IDLE_TIMEOUT_SECONDS;
    }

    /**
     * The response-head budget for a stream-routed request (ISS-024): the
     * `streamHeaderTimeoutSeconds` setting, else
     * {@see STREAM_HEADER_TIMEOUT_SECONDS}. It replaces the connect bound in
     * the transport's `timeout` slot, so it is the answer to "how long may a
     * live server take to start answering" — never a total request deadline
     * (reads re-arm under `read_timeout`, see the trait docblock).
     */
    private static function streamHeaderTimeoutSeconds(): float
    {
        return self::honourableSeconds(
            self::settingsConfig()[self::STREAM_HEADER_TIMEOUT_CONFIG_KEY] ?? null,
            self::MIN_STREAM_HEADER_TIMEOUT_SECONDS,
        ) ?? self::STREAM_HEADER_TIMEOUT_SECONDS;
    }

    /**
     * A finite number of seconds at or above `$min` - a JSON number or a
     * numeric string (an env var has no other type) - or null for anything
     * that has not usably asked for one.
     */
    private static function honourableSeconds(mixed $raw, float $min): ?float
    {
        if (\is_string($raw)) {
            $raw = is_numeric($raw) ? (float) $raw : null;
        }

        if (!\is_int($raw) && !\is_float($raw)) {
            return null;
        }

        $seconds = (float) $raw;

        return is_finite($seconds) && $seconds >= $min ? $seconds : null;
    }

    /**
     * The merged settings, or nothing: guarded like
     * `EngineBackend::userConfig()`, because a missing or malformed settings
     * file must cost the DEFAULT bounds, never a provider client.
     *
     * @return array<string, mixed>
     */
    private static function settingsConfig(): array
    {
        try {
            return \SugarCraft\Code\Cli\Bootstrap::readUserConfig();
        } catch (\Throwable) {
            return [];
        }
    }
}
