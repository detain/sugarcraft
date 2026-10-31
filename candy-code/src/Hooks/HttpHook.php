<?php

declare(strict_types=1);

namespace SugarCraft\Code\Hooks;

use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\HookContextFiles;
use SugarCraft\Code\Support\PublicUrlGuard;
use SugarCraft\Core\Util\Sanitize;

/**
 * A hook consulted over HTTP instead of by spawning a script.
 *
 * C-EXT-6.c (LATE, GAP-HK-03) closes the gap where a `type: http` entry — the
 * shape Claude Code documents for a guard that lives in another process, or on
 * another machine — was skipped and recorded, so it silently never ran. The
 * question it is asked is the SAME object a script hook receives on stdin
 * ({@see HookPayload}), and its answer is read with the SAME reader
 * ({@see HookEnvelope}), so one guard behaves the same whichever transport
 * carries it. That identity is the point of the extraction: two readers of a
 * verdict is a hook that blocks over a pipe and approves over a socket.
 *
 * THE URL IS UNTRUSTED INPUT. It arrives from a settings or hook file, which
 * can be a file somebody else committed to the repository being worked on, and
 * this is the one class in the hook system that makes the host dial out. So the
 * egress DECISION is not made here: it belongs to
 * {@see PublicUrlGuard}, the repo's single SSRF rule, which is the same rule
 * {@see \SugarCraft\Code\Tools\WebFetch} answers to. Minting a second copy of
 * that policy would give the private-address refusal two answers, and the
 * trust boundary would then depend on which caller you reached.
 * {@see PublicUrlGuard::fetch()} is not usable on this path — it is HTTPS-only,
 * and the design for this class speaks plain http as well — so the dial goes
 * through the guard's public decision seam
 * ({@see PublicUrlGuard::guardedDialAddress()}) and its blocked-address and
 * resolver helpers, and only the WIRE is re-implemented here: the same
 * validated-literal authority, the same `Host:` pin, the same no-redirect
 * context, the same bounded read.
 *
 * WHAT IS REFUSED OUTRIGHT, on both dialects, and why:
 *
 *   - a URL that is not http(s), one that cannot be parsed, or one carrying
 *     `user:pass@` credentials. Credentials in a URL end up in somebody's access
 *     log, and "the file asked for `ftp://`" is not a policy this class can
 *     honour.
 *   - a target the guard refuses: loopback, RFC1918, link-local, the
 *     metadata-service ranges, a name that does not resolve, a literal address
 *     in those ranges. This is deliberately NOT routed through the
 *     "the hook did not answer" door the claude dialect forgives: it is not a
 *     failure of the endpoint, it is a configuration asking the host to poke
 *     itself, and letting it fall through to allow makes the probe a feature.
 *   - a header value that still holds a control character after interpolation.
 *     That is request smuggling, and it is refused here rather than trusted to
 *     whatever the wrapper does with the header block it was handed.
 *   - a redirect. NOT FOLLOWED. The guard adjudicates every hop it allows a
 *     caller to make; an answer that arrives past a hop this class never
 *     decided is not an answer it read.
 *
 * EVERYTHING ELSE THAT GOES WRONG ON THE WIRES — transport failure, the
 * timeout, a non-2xx status, a body over {@see MAX_RESPONSE_BYTES} — is the
 * same error door {@see ScriptHook} uses for a script that misbehaves: under
 * the claude dialect the call is allowed WITH A NOTICE naming what happened,
 * under the native dialect it is denied. A hook that was never consulted has
 * not approved anything, and the native file says so in its own terms.
 *
 * A HOOK IS NOT A MODEL CALL, so the standing ban on a blanket total-request
 * timeout (E646) does not bind this path: that rule protects a generation that
 * legitimately runs for minutes. A hook is one request inside a chain that
 * already has a budget, and its `timeout:` is a key the user wrote themselves;
 * honouring it with a per-request transport timeout is exactly what that key
 * means. The timeout is the stream wrapper's own — no wall-clock kill of a
 * request path, no timer on a shared loop — and the read stays a bounded chunk
 * loop, so a host that keeps talking cannot spend anything beyond the cap.
 *
 * NOTHING HERE ECHOES A SECRET. Header values are sent and never printed; the
 * target appears in a message only as {@see targetSummary()}, which drops the
 * path and the query where a webhook token usually sits; the refusal an
 * interceptor hands back is the guard's own sentence, which names a class of
 * address rather than a URL.
 *
 * @see HookEnvelope
 * @see HookPayload
 */
final class HttpHook implements BoundedHookInterface
{
    /**
     * Ceiling on the whole response body of one hook call, in bytes.
     *
     * A hook answers about ONE tool call with a verdict, so a legitimate answer
     * is a few hundred bytes, and the largest plausible one is a rewrite of the
     * tool input — which {@see HookEnvelope::rewrite()} refuses over its own
     * derived ceiling regardless. Reading past this would be reading a body
     * that is not an answer, from a host that has already been paid a request.
     */
    public const MAX_RESPONSE_BYTES = 262144;

    /**
     * The shortest bound this class can tell apart from no bound, and so the
     * floor under {@see withTimeoutSeconds()} — the same reasoning
     * {@see ScriptHook} applies to its own clamp, stated here because this
     * class has no exit-poll interval to borrow a figure from.
     */
    public const MIN_TIMEOUT_SECONDS = 0.01;

    /**
     * Fields a hook may not set, because the transport owns them.
     *
     * `Host` and `Content-Length` are what make the address pin and the bounded
     * read trustworthy, so a configured value for either would be a hook
     * rewriting the guard's own framing. `Authorization` is NOT in this list:
     * authenticating to your own guard is the entire reason
     * {@see $allowedEnvVars} exists — what keeps it from leaking is that its
     * value is interpolated, never printed.
     */
    private const TRANSPORT_OWNED_HEADERS = [
        'host',
        'content-length',
        'content-type',
        'transfer-encoding',
        'connection',
        'keep-alive',
        'upgrade',
        'te',
        'trailer',
        'proxy-authorization',
        'proxy-connection',
    ];

    private \Closure $resolveAddresses;
    private \Closure $isBlockedAddress;
    private \Closure $transfer;

    /**
     * @param array<string, string> $headers configured fields; a value may name `$VAR`
     * @param list<string> $allowedEnvVars the only names such a `$VAR` may read
     * @param ?callable(string): list<string> $resolveAddresses DNS seam, defaults to the guard's
     * @param ?callable(string): bool $isBlockedAddress address-policy seam, defaults to the guard's
     * @param ?callable(array<string, mixed>): ?array{0: string, 1: list<string>, 2: bool} $transfer wire seam
     */
    public function __construct(
        private string $name,
        private HookEvent $event,
        private string $matcher,
        private string $url,
        private string $description = '',
        private array $headers = [],
        private array $allowedEnvVars = [],
        private float $timeoutSeconds = ScriptHook::DEFAULT_TIMEOUT_SECONDS,
        private string $dialect = ScriptHook::DIALECT_NATIVE,
        private bool $advisory = false,
        ?callable $resolveAddresses = null,
        ?callable $isBlockedAddress = null,
        ?callable $transfer = null,
    ) {
        $this->resolveAddresses = $resolveAddresses !== null
            ? \Closure::fromCallable($resolveAddresses)
            : static fn (string $host): array => PublicUrlGuard::resolveViaSystemDns($host);
        $this->isBlockedAddress = $isBlockedAddress !== null
            ? \Closure::fromCallable($isBlockedAddress)
            : static fn (string $address): bool => PublicUrlGuard::addressIsBlocked($address);
        $this->transfer = $transfer !== null
            ? \Closure::fromCallable($transfer)
            : static fn (array $request): ?array => self::pinnedPost($request);
    }

    /**
     * A configured `type: http` row, read the way
     * {@see ScriptHook::fromConfig()} reads a `command` row: lenient about the
     * bound, strict about the dialect.
     *
     * The three HTTP keys are read defensively because this door also serves
     * callers that never passed through {@see HookConfig::parse()} — the parser
     * is where a user's file is judged, and a programmatic row that spelled
     * `headers` as a string gets an empty header set and a loud refusal at dial
     * time rather than a TypeError in the middle of a tool call.
     */
    public static function fromConfig(array $config): self
    {
        $event = HookEvent::tryFrom($config['event'] ?? 'PreToolUse') ?? HookEvent::PreToolUse;
        $timeout = $config['timeout'] ?? null;

        $headers = [];
        foreach ((array) ($config['headers'] ?? []) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $headers[$key] = $value;
            }
        }

        $allowed = [];
        foreach ((array) ($config['allowedEnvVars'] ?? []) as $value) {
            if (is_string($value)) {
                $allowed[] = $value;
            }
        }

        $url = is_string($config['url'] ?? null) ? $config['url'] : '';
        $name = $config['name'] ?? null;

        return new self(
            name: is_string($name) && $name !== ''
                ? $name
                : ($url !== '' ? $url : (string) uniqid('hook_', true)),
            event: $event,
            matcher: is_string($config['matcher'] ?? null) ? $config['matcher'] : '.*',
            url: $url,
            description: is_string($config['description'] ?? null) ? $config['description'] : '',
            headers: $headers,
            allowedEnvVars: $allowed,
            timeoutSeconds: (is_int($timeout) || is_float($timeout))
                && $timeout > 0
                && is_finite((float) $timeout)
                    ? (float) $timeout
                    : ScriptHook::DEFAULT_TIMEOUT_SECONDS,
            dialect: ($config['dialect'] ?? null) === ScriptHook::DIALECT_CLAUDE
                ? ScriptHook::DIALECT_CLAUDE
                : ScriptHook::DIALECT_NATIVE,
            advisory: ($config['advisory'] ?? null) === true,
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function event(): HookEvent
    {
        return $this->event;
    }

    public function matcher(): string
    {
        return $this->matcher;
    }

    /** The URL this hook was configured with, for a caller that reports its chain. */
    public function url(): string
    {
        return $this->url;
    }

    public function timeoutSeconds(): float
    {
        return $this->timeoutSeconds > 0.0 && is_finite($this->timeoutSeconds)
            ? $this->timeoutSeconds
            : ScriptHook::DEFAULT_TIMEOUT_SECONDS;
    }

    /**
     * The same hook with a shorter bound — and every seam carried into the
     * clone.
     *
     * {@see HookRegistry} charges each hook what is left of the chain budget
     * through exactly this door, so a copy that re-built the transport or the
     * resolver would send a clamped hook to the real network where its
     * expectations, and its guard, were injected.
     */
    public function withTimeoutSeconds(float $seconds): self
    {
        $requested = is_finite($seconds)
            ? max(self::MIN_TIMEOUT_SECONDS, $seconds)
            : self::MIN_TIMEOUT_SECONDS;

        $clone = new self(
            $this->name,
            $this->event,
            $this->matcher,
            $this->url,
            $this->description,
            $this->headers,
            $this->allowedEnvVars,
            min($this->timeoutSeconds(), $requested),
            $this->dialect,
            $this->advisory,
            $this->resolveAddresses,
            $this->isBlockedAddress,
            $this->transfer,
        );

        return $clone;
    }

    /**
     * Ask the endpoint, and answer with what it said — or with the refusal its
     * absence earns.
     *
     * DENY-WINS ORDERING, which is the whole safety property of the chain this
     * hook joins: a configuration error refuses the call before a socket is
     * opened, a refusal read out of the envelope is returned untouched, and a
     * transport that could not be consulted never reaches a line that could
     * approve the call. Nothing after the first `deny` in this method can turn
     * a refusal into a permission.
     */
    public function execute(HookContext $context): HookResult
    {
        $target = self::inspect($this->url);
        if (is_string($target)) {
            return HookResult::deny(Lang::t(self::REFUSAL_KEYS[$target], [
                'hook' => $this->name,
                'target' => self::targetSummary($this->url),
                'reason' => self::problemSentence($target),
            ]));
        }

        $headers = $this->requestHeaders();
        if ($headers instanceof HookResult) {
            return $headers;
        }

        // THE GUARD ANSWERS FOR THE ADDRESS, on this path and on no other: the
        // literal it returns is the only thing this class ever dials, so there
        // is no route from a settings file to a socket that skips the policy.
        [$refusal, $dial] = PublicUrlGuard::guardedDialAddress(
            $target['host'],
            false,
            $this->resolveAddresses,
            $this->isBlockedAddress,
        );
        if ($refusal !== null || $dial === null) {
            return HookResult::deny(Lang::t('hooks.http.deny_blocked_target', [
                'hook' => $this->name,
                'target' => self::targetSummary($this->url),
                'reason' => $refusal ?? 'could not resolve host',
            ]));
        }

        $answered = ($this->transfer)([
            'scheme' => $target['scheme'],
            'host' => $target['host'],
            'address' => $dial,
            'port' => $target['port'],
            'origin' => $target['origin'],
            'headers' => $headers,
            'body' => HookPayload::stdin($context, $this->event->value),
            'timeout' => $this->timeoutSeconds(),
        ]);

        if (!is_array($answered)) {
            return $this->couldNotConsult('hooks.http.reason_unreachable');
        }

        $response = (string) ($answered[0] ?? '');
        $lines = array_values((array) ($answered[1] ?? []));
        $complete = (bool) ($answered[2] ?? false);

        if (!$complete || strlen($response) > self::MAX_RESPONSE_BYTES) {
            return $this->couldNotConsult('hooks.http.reason_oversized');
        }

        $status = self::statusCode($lines);
        if ($status === null) {
            return $this->couldNotConsult('hooks.http.reason_no_status');
        }
        if ($status >= 300 && $status < 400) {
            // Named apart from a plain 4xx/5xx because a moved endpoint is the
            // one answer that reads like success from here. Falling through
            // would NOT make a 302 an allow — the <200||>=300 arm below still
            // denies it — so this arm's job is the distinct reason word the
            // message table pins, not a different outcome.
            return $this->couldNotConsult('hooks.http.reason_redirect');
        }
        if ($status < 200 || $status >= 300) {
            return $this->couldNotConsult('hooks.http.reason_status');
        }

        $envelope = HookEnvelope::read($response);
        if ($envelope === null) {
            // A 2xx with a non-JSON body ALLOWS. Its text still reaches the
            // model as additional context, bounded as a script's plain stdout
            // is — and swept, because this body came off a network rather than
            // out of a process this session spawned.
            return HookResult::allow('', HookContextFiles::bound(
                Sanitize::untrusted($response),
                HookResult::MAX_ADDITIONAL_CONTEXT_BYTES,
            ));
        }

        return (new HookEnvelope(
            $this->name,
            noticeRecorder: function (string $message): void {
                ScriptHook::showSystemMessage($this->name, $message);
            },
            questionFallback: fn (): string => $this->description !== ''
                ? $this->description
                : "Hook {$this->name} requires your approval",
        ))->verdict($envelope, strlen($context->toolInput));
    }

    /**
     * The single error door, and the two directions behind it.
     *
     * A claude-dialect row was written by an ecosystem where a hook that cannot
     * be reached does not hold the tool call open, so it ALLOWS — with a notice
     * naming what was lost, because silence is how a disabled guard passes for
     * an active one. A native row denies: candy-code's own contract is that a
     * guard which did not answer has not approved anything.
     *
     * @param string $reasonKey the `hooks.http.reason_*` key describing the failure
     */
    private function couldNotConsult(string $reasonKey): HookResult
    {
        $params = [
            'hook' => $this->name,
            'target' => self::targetSummary($this->url),
            'reason' => Lang::t($reasonKey, ['bytes' => (string) self::MAX_RESPONSE_BYTES]),
        ];

        if ($this->dialect !== ScriptHook::DIALECT_CLAUDE && !$this->advisory) {
            return HookResult::deny(Lang::t('hooks.http.deny_unanswered', $params));
        }

        RuntimeNoticeSink::record(Lang::t('hooks.http.notice_unanswered', $params));

        return HookResult::allow('');
    }

    /**
     * The configured fields, with `$VAR` filled from {@see $allowedEnvVars} and
     * from nothing else.
     *
     * Interpolation is the whole reason a hook can authenticate: a literal
     * `Bearer abc123` in a settings file is a secret committed to a repository.
     * Listing the handful of names a value may read is what stops that same key
     * from becoming a general `getenv` of the process — an environment an
     * operator can point at any endpoint they own.
     *
     * A name outside the list stays LITERAL in the request. It is not a secret
     * the operator chose to hide, it is a typo, and printing the placeholder is
     * how the typo becomes visible in the endpoint's log instead of invisible
     * in this one. An allowed name that is unset sends an empty value.
     *
     * @return array<string, string>|HookResult
     */
    private function requestHeaders(): array|HookResult
    {
        $out = [];
        foreach ($this->headers as $field => $template) {
            $value = $this->interpolate($template);
            if (self::carriesControlBytes($field) || self::carriesControlBytes($value)) {
                // The FIELD is named because a refusal that could not say which
                // header was at fault is a fifteen-minute debugging session;
                // the VALUE is never named, because that is where the secret is.
                return HookResult::deny(Lang::t('hooks.http.deny_header_injection', [
                    'hook' => $this->name,
                    'field' => Sanitize::untrusted($field),
                    'target' => self::targetSummary($this->url),
                ]));
            }
            $out[$field] = $value;
        }

        return $out;
    }

    /** `$NAME` and `${NAME}` replaced, for allowed names only. */
    private function interpolate(string $template): string
    {
        if ($this->allowedEnvVars === [] || !str_contains($template, '$')) {
            return $template;
        }

        $allowed = array_fill_keys($this->allowedEnvVars, true);

        return (string) preg_replace_callback(
            '/\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?/',
            static function (array $match) use ($allowed): string {
                if (!isset($allowed[$match[1]])) {
                    return $match[0];
                }

                $value = \getenv($match[1]);

                return $value === false ? '' : $value;
            },
            $template,
        );
    }

    /**
     * `url` reduced to what may be dialled, or the category of reason it may
     * not be.
     *
     * @return array{scheme: string, host: string, port: int, origin: string}|string
     *         the target, or one of the categories in {@see REFUSAL_KEYS}
     */
    public static function inspect(string $url): array|string
    {
        $parsed = parse_url($url);

        if (!is_array($parsed)) {
            return 'unparsable';
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return 'scheme';
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return 'credentials';
        }

        $host = strtolower((string) ($parsed['host'] ?? ''));
        if ($host === '') {
            return 'no-host';
        }

        // A bracketed IPv6 literal arrives from parse_url() with its brackets
        // still on, and `filter_var` would refuse it before the guard could
        // judge the address inside.
        $literal = str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;

        if (in_array($literal, PublicUrlGuard::BLOCKED_HOSTNAMES, true)) {
            return 'blocked-host';
        }

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false
            && PublicUrlGuard::addressIsBlocked($literal)) {
            return 'blocked-host';
        }

        $port = isset($parsed['port']) ? (int) $parsed['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            return 'bad-port';
        }

        $origin = ($parsed['path'] ?? '') === '' ? '/' : (string) $parsed['path'];
        if (isset($parsed['query'])) {
            $origin .= '?' . $parsed['query'];
        }

        return [
            'scheme' => $scheme,
            'host' => $literal,
            'port' => $port,
            'origin' => $origin,
        ];
    }

    /**
     * The refusal categories and the message each one renders through, in one
     * place so a caller that only validates (the parser) and a caller that only
     * denies (this hook) cannot name different sets of them.
     *
     * @var array<string, string>
     */
    public const REFUSAL_KEYS = [
        'unparsable' => 'hooks.http.deny_bad_url',
        'scheme' => 'hooks.http.deny_scheme',
        'credentials' => 'hooks.http.deny_credentials',
        'no-host' => 'hooks.http.deny_bad_url',
        'blocked-host' => 'hooks.http.deny_blocked_target',
        'bad-port' => 'hooks.http.deny_bad_url',
    ];

    /** The guard's own sentence for a category, for the row that names a reason. */
    public static function problemSentence(string $category): string
    {
        return match ($category) {
            'blocked-host' => 'Error: fetching from private/link-local addresses is not allowed',
            default => 'Error: this hook URL cannot be dialled',
        };
    }

    /** Whether a configured header field is one the transport has to own. */
    public static function transportOwnedHeader(string $field): bool
    {
        return in_array(strtolower(trim($field)), self::TRANSPORT_OWNED_HEADERS, true);
    }

    /** Whether a name is shaped like a field this class will put on a request. */
    public static function validHeaderName(string $field): bool
    {
        return $field !== ''
            && preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $field) === 1
            && !self::transportOwnedHeader($field);
    }

    /** Whether an `allowedEnvVars` entry is a name the environment can hold. */
    public static function validEnvName(string $name): bool
    {
        return $name !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
    }

    private static function carriesControlBytes(string $text): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $text) === 1;
    }

    /**
     * The only form a hook URL ever takes in a message: scheme and host, with
     * the port kept only when it is off-scheme.
     *
     * Path and query are where a token hides — `?key=…` is common enough in
     * webhook URLs that treating a query as printable would put the secret in
     * the transcript that shows the refusal.
     */
    public static function targetSummary(string $url): string
    {
        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            return '(unparsable url)';
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $host = strtolower((string) ($parsed['host'] ?? ''));
        if ($host === '') {
            return $scheme === '' ? '(no host)' : $scheme . '://(no host)';
        }

        $port = isset($parsed['port']) ? (int) $parsed['port'] : null;
        $default = $scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null);
        $suffix = $port !== null && $port !== $default ? ':' . $port : '';

        return ($scheme === '' ? '' : $scheme . '://') . $host . $suffix;
    }

    /** The status of the FINAL response line, or null when no status line came. */
    private static function statusCode(array $headers): ?int
    {
        foreach (array_reverse($headers) as $line) {
            if (!is_string($line) || preg_match('#^HTTP/[^ ]*( (\d{3}))?#', $line, $match) !== 1) {
                continue;
            }

            return isset($match[2]) && $match[2] !== '' ? (int) $match[2] : null;
        }

        return null;
    }

    /**
     * The real wire: POST the body to the VALIDATED LITERAL, with `Host:`
     * carrying the name that was resolved so virtual hosting survives the
     * address pin that makes the SSRF rule un-bypassable.
     *
     * The shape is {@see PublicUrlGuard}'s own transfer, with a request body and
     * the two framing fields a POST requires. `max_redirects: 0` is the same
     * refusal the guard makes, `ignore_errors` keeps a 4xx here instead of
     * turning into a `false` the caller cannot tell from a dead socket, and the
     * read stops past {@see MAX_RESPONSE_BYTES} so an over-capped body is
     * detected rather than accumulated.
     *
     * @param array<string, mixed> $request
     * @return ?array{0: string, 1: list<string>, 2: bool}
     */
    private static function pinnedPost(array $request): ?array
    {
        $address = (string) $request['address'];
        $scheme = (string) $request['scheme'];
        $port = (int) $request['port'];
        $authority = (str_contains($address, ':') ? '[' . $address . ']' : $address) . ':' . $port;

        // The name the guard judged, not the bracketed literal we dial: this
        // is what keeps name-based virtual hosting alive over plain http, and
        // the port rides only when it is not the scheme default — the SAME
        // construction PublicUrlGuard::transferPinned makes of its inputs.
        $name = (string) $request['host'];
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $hostHeader = $port === $defaultPort ? $name : "$name:$port";

        $body = (string) $request['body'];
        $lines = [];
        foreach ((array) $request['headers'] as $field => $value) {
            $lines[] = $field . ': ' . $value;
        }
        $lines[] = 'Content-Type: application/json';
        $lines[] = 'Content-Length: ' . strlen($body);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Host: $hostHeader\r\n" . implode("\r\n", $lines) . "\r\n",
                'content' => $body,
                'timeout' => (float) $request['timeout'],
                'ignore_errors' => true,
                'max_redirects' => 0,
            ],
            'ssl' => [
                'peer_name' => (string) $request['host'],
            ],
        ]);

        $stream = @fopen($scheme . '://' . $authority . (string) $request['origin'], 'rb', false, $context);
        if ($stream === false) {
            return null;
        }

        // fopen() populates the magic response-header variable in this scope.
        $headers = $http_response_header;

        $response = '';
        while (!feof($stream) && strlen($response) <= self::MAX_RESPONSE_BYTES) {
            $chunk = fread($stream, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }
        $complete = strlen($response) <= self::MAX_RESPONSE_BYTES || feof($stream);
        fclose($stream);

        return [$response, array_values((array) $headers), $complete];
    }
}
