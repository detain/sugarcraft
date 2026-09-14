<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

use SugarCraft\Crush\Commands\McpAuthCommand;

/**
 * E701: the interactive OAuth authorization-code login with PKCE (RFC 8252
 * loopback + RFC 7636).
 *
 * The whole flow lives in `sugarcrush mcp auth login` — a plain CLI command
 * run from a shell — and NEVER in the TUI: the wait is owned by a human
 * opening a browser, and the terminal that must survive it belongs to the
 * renderer. In-chat `/mcp auth login` therefore only prints this command
 * (see {@see McpAuthCommand}).
 *
 * The steps, in causal order:
 *
 * 1. Discover the three endpoints from the server's
 *    `/.well-known/oauth-authorization-server` document through the same
 *    public helper `mcp auth add` uses — registration, token, and
 *    (new here) authorize.
 * 2. Bind an ephemeral loopback listener FIRST, because dynamic client
 *    registration must carry the exact redirect URI the browser will be
 *    sent back to — `http://127.0.0.1:<bound-port>/callback`, never a
 *    wildcard interface.
 * 3. Register the client (existing {@see OAuthClientRegistration::registerClient()},
 *    now able to advertise those loopback redirect URIs).
 * 4. Mint a PKCE verifier + state, print the authorization URL for the
 *    human, and wait on the socket.
 * 5. Verify the callback (method, path, `state` with `hash_equals`, code
 *    present), answer the browser with a STATIC page, exchange the code
 *    with the verifier, and persist the entry in exactly the shape
 *    `mcp auth add` writes — same nine keys, same endpoint carries.
 *
 * Every failure arm closes the listener in `finally`, stores nothing, says
 * at most two lines, and is re-runnable: a half-done login must not poison
 * the next one.
 */
final class OAuthLoopbackFlow
{
    /**
     * Default wait budget in seconds. RATIONALE (backlog E646): this bounds a
     * human, cancellable prompt, NOT a provider request, so it is a plain
     * stream_select deadline and deliberately not a React timer.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 300.0;

    /** The one path the callback reader accepts; anything else is refused. */
    public const CALLBACK_PATH = '/callback';

    /** stream_select slice length — short enough that Ctrl+C stays snappy. */
    private const WAIT_SLICE_SECONDS = 0.2;

    /** Hard cap on the bytes read from one callback connection. */
    private const MAX_REQUEST_BYTES = 8192;

    private readonly \Closure $metadataFetcher;

    private readonly \Closure $clock;

    /**
     * @param callable(string): array<string, mixed>|null $metadataFetcher
     *        Discovery seam. Production leaves it null and gets
     *        {@see McpAuthCommand::fetchOAuthMetadata()} — the one curl path,
     *        options byte-unchanged since `add` shipped; tests inject a table
     *        so the suite stays hermetic (zero network egress is the law).
     * @param callable(): float|null $clock
     *        Monotone wall clock in Unix seconds, overridable so the deadline
     *        arithmetic is testable at sub-second budgets.
     */
    public function __construct(
        private readonly OAuthClientRegistration $oauth,
        ?callable $metadataFetcher = null,
        ?callable $clock = null,
    ) {
        $this->metadataFetcher = $metadataFetcher !== null
            ? \Closure::fromCallable($metadataFetcher)
            : static fn (string $url): array => McpAuthCommand::fetchOAuthMetadata($url);
        $this->clock = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn (): float => microtime(true);
    }

    /**
     * Run the whole login and return the process exit code.
     *
     * Prints only URLs, server names, and the client id — never the verifier,
     * the code, or any token. The `$browser` parameter exists for the test
     * suite (and nothing in production passes it): it is invoked with the
     * authorization URL immediately before the socket wait, so a test can
     * fire the loopback request itself and ride the TCP backlog instead of
     * spawning a thread.
     *
     * @param callable(string): void|null $browser
     */
    public function login(
        string $serverUrl,
        ?string $tokenUrl = null,
        ?string $authorizeUrl = null,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?callable $browser = null,
    ): int {
        echo "\n";

        $wellKnown = rtrim($serverUrl, '/') . '/.well-known/oauth-authorization-server';
        try {
            $metadata = ($this->metadataFetcher)($wellKnown);
        } catch (\Throwable) {
            $metadata = [];
        }

        $registrationUrl = isset($metadata['registration_endpoint']) && is_string($metadata['registration_endpoint'])
            ? $metadata['registration_endpoint']
            : null;
        $tokenUrl = $tokenUrl ?? (isset($metadata['token_endpoint']) && is_string($metadata['token_endpoint']) ? $metadata['token_endpoint'] : null);
        $authorizeUrl = $authorizeUrl ?? (isset($metadata['authorization_endpoint']) && is_string($metadata['authorization_endpoint']) ? $metadata['authorization_endpoint'] : null);

        if ($registrationUrl === null || $tokenUrl === null || $authorizeUrl === null) {
            echo "  ✗ OAuth endpoints could not be discovered for `{$serverUrl}`.\n";
            echo "  login needs registration, token and authorize endpoints; pass the two URLs positionally if the server omits one.\n";

            return 1;
        }

        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            echo "  ✗ Could not bind a loopback callback socket: {$errstr}\n";

            return 1;
        }

        try {
            $redirectUri = self::redirectUriFor($server);

            $clientName = 'sugar-crush/' . parse_url($serverUrl, PHP_URL_HOST);
            $registered = $this->oauth->registerClient($registrationUrl, $clientName, [], [$redirectUri]);

            $verifier = OAuthPkce::newVerifier();
            $state = bin2hex(random_bytes(16));
            $url = self::authorizeUrlFor(
                $authorizeUrl,
                $registered['clientId'],
                $redirectUri,
                $state,
                OAuthPkce::challengeFor($verifier),
            );

            echo "  Open this URL in a browser to authorize `{$serverUrl}`:\n";
            echo "\n";
            echo "    {$url}\n";
            echo "\n";
            echo '  Waiting up to ' . self::secondsWord($timeoutSeconds) . " s for the callback (Ctrl+C cancels)...\n";

            if ($browser !== null) {
                $browser($url);
            }

            $code = $this->awaitCallback($server, $state, $timeoutSeconds);

            $token = $this->oauth->exchangeAuthorizationCode(
                $tokenUrl,
                $registered['clientId'],
                $registered['clientSecret'],
                $code,
                $redirectUri,
                $verifier,
            );

            $entry = new AuthEntry(
                clientId: $registered['clientId'],
                clientSecret: $registered['clientSecret'],
                registrationAccessToken: $registered['registrationAccessToken'],
                accessToken: $token['accessToken'],
                refreshToken: $token['refreshToken'],
                expiresAt: time() + $token['expiresIn'],
                scopes: $token['scopes'],
                tokenUrl: $tokenUrl,
                registrationUrl: $registrationUrl,
            );
            $this->oauth->saveAuth($serverUrl, $entry);

            echo "  ✓ Signed in `{$serverUrl}`\n";
            echo "  Client ID: `{$registered['clientId']}`\n";

            return 0;
        } catch (\Throwable $e) {
            echo "  ✗ Login failed: {$e->getMessage()}\n";
            echo "  Nothing was stored — fix the cause and re-run.\n";

            return 1;
        } finally {
            fclose($server);
        }
    }

    /**
     * The authorization URL as a PURE function — every value it carries is
     * public by construction (challenge, not verifier; state; client id),
     * which is what makes it safe to print into a terminal that a screen
     * recorder owns.
     *
     * @param list<string> $scopes
     */
    public static function authorizeUrlFor(
        string $authorizeEndpoint,
        string $clientId,
        string $redirectUri,
        string $state,
        string $codeChallenge,
        array $scopes = [],
    ): string {
        $query = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => OAuthPkce::METHOD,
        ];

        if ($scopes !== []) {
            $query['scope'] = implode(' ', $scopes);
        }

        $separator = str_contains($authorizeEndpoint, '?') ? '&' : '?';

        return $authorizeEndpoint . $separator . http_build_query($query, '', '&');
    }

    /**
     * The redirect URI a bound listener will receive callbacks on. The
     * 127.0.0.1 check is load-bearing, not cosmetic: RFC 8252 §7.3 permits
     * loopback literals only, and a listener ever bound to a wildcard
     * interface would deliver authorization codes across the network.
     *
     * @param resource $server
     */
    public static function redirectUriFor($server): string
    {
        $name = stream_socket_get_name($server, false);
        if ($name === false || !str_starts_with($name, '127.0.0.1:')) {
            throw new \RuntimeException('loopback listener is not bound to 127.0.0.1');
        }

        return 'http://' . $name . self::CALLBACK_PATH;
    }

    /**
     * Wait for — and validate — exactly one callback.
     *
     * The deadline is checked in {@see self::WAIT_SLICE_SECONDS} slices of
     * `stream_select`, against the injected clock. The read is bounded at 8
     * KiB and stops at the request line (the first CRLF), because that line
     * alone carries method, path and query — everything this reader judges.
     *
     * @param resource $server
     * @return string the authorization code
     * @throws \RuntimeException with a message that never quotes the request
     */
    public function awaitCallback($server, string $expectedState, float $timeoutSeconds): string
    {
        $deadline = ($this->clock)() + $timeoutSeconds;

        while (true) {
            $remaining = $deadline - ($this->clock)();
            if ($remaining <= 0.0) {
                throw new \RuntimeException('no callback arrived before the wait expired');
            }

            $slice = min(self::WAIT_SLICE_SECONDS, $remaining);
            $read = [$server];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, (int) $slice, (int) (($slice - (int) $slice) * 1_000_000));
            if ($ready === false) {
                throw new \RuntimeException('the loopback wait was interrupted');
            }

            if ($ready === 0) {
                continue;
            }

            $connection = @stream_socket_accept($server, 5);
            if ($connection === false) {
                throw new \RuntimeException('the callback connection could not be accepted');
            }

            try {
                return $this->handleCallback($connection, $expectedState);
            } finally {
                fclose($connection);
            }
        }
    }

    /**
     * Read one request, judge it, answer it. The answer page is STATIC on
     * every outcome — success or rejection — because echoing any part of the
     * query back into a browser page is how an attacker learns what the
     * listener accepted.
     *
     * @param resource $connection
     */
    private function handleCallback($connection, string $expectedState): string
    {
        $raw = '';
        while (!feof($connection) && strlen($raw) <= self::MAX_REQUEST_BYTES) {
            $chunk = fread($connection, 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
            if (str_contains($raw, "\r\n")) {
                break;
            }
        }

        $requestLine = strtok($raw, "\r\n");
        if (!is_string($requestLine) || strlen($raw) > self::MAX_REQUEST_BYTES) {
            self::respond($connection, 400, 'Bad request.');

            throw new \RuntimeException('the callback request was not a readable GET');
        }

        if (preg_match('#^GET (\S+) HTTP/1\.[01]$#', $requestLine, $m) !== 1) {
            self::respond($connection, 405, 'Method not allowed.');

            throw new \RuntimeException('the callback must arrive as a GET');
        }

        $path = parse_url($m[1], PHP_URL_PATH);
        $query = parse_url($m[1], PHP_URL_QUERY);
        if ($path !== self::CALLBACK_PATH) {
            self::respond($connection, 404, 'Not found.');

            throw new \RuntimeException('the callback path was not ' . self::CALLBACK_PATH);
        }

        parse_str(is_string($query) ? $query : '', $params);
        $state = isset($params['state']) && is_string($params['state']) ? $params['state'] : '';
        $code = isset($params['code']) && is_string($params['code']) ? $params['code'] : '';

        if (!hash_equals($expectedState, $state)) {
            self::respond($connection, 400, 'State mismatch.');

            throw new \RuntimeException('the callback state did not match — possible CSRF, the attempt was discarded');
        }

        if ($code === '') {
            self::respond($connection, 400, 'No authorization code.');

            throw new \RuntimeException('the callback carried no authorization code');
        }

        self::respond($connection, 200, 'Authorization complete — return to your terminal.');

        return $code;
    }

    /**
     * One fixed HTTP response, body from this method's caller only — never a
     * byte of the request.
     *
     * @param resource $connection
     */
    private static function respond($connection, int $status, string $message): void
    {
        $reason = match ($status) {
            200 => 'OK',
            400 => 'Bad Request',
            404 => 'Not Found',
            default => 'Method Not Allowed',
        };
        $body = $message . "\n";

        fwrite(
            $connection,
            "HTTP/1.1 {$status} {$reason}\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $body,
        );
    }

    /**
     * Render a wait budget without a float artefact ("300", not "300.0") —
     * the figure printed here is the same one `--timeout` accepted.
     */
    private static function secondsWord(float $seconds): string
    {
        return $seconds === floor($seconds) ? (string) (int) $seconds : (string) $seconds;
    }
}
