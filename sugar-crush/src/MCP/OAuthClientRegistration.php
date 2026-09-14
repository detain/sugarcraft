<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Handles OAuth 2.0 Dynamic Client Registration (RFC 7591) for MCP servers.
 *
 * Registers sugar-crush with a remote MCP server on first connection,
 * receives credentials automatically, stores tokens at
 * ~/.local/share/sugar-crush/mcp-auth.json, and refreshes them ahead of expiry.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7591
 */
final class OAuthClientRegistration
{
    private const TOKEN_EXPIRY_BUFFER_SECONDS = 300;

    private Client $httpClient;

    /** @var array<string, AuthEntry>|null */
    private ?array $authCache = null;

    private string $authFilePath;

    public function __construct(
        ?Client $httpClient = null,
        ?string $authFilePath = null,
    ) {
        $this->httpClient = $httpClient ?? new Client(['timeout' => 30]);
        $this->authFilePath = $authFilePath ?? $this->defaultAuthFilePath();
    }

    /**
     * Register a new OAuth client with the given server and receive credentials.
     *
     * @param string $registrationUrl The server's dynamic client registration endpoint
     * @param string $clientName Human-readable name for this client
     * @param array<string, string> $scopes Requested OAuth scopes
     * @param list<string>|null $redirectUris Loopback callback URIs for the E701
     *                                        authorization-code login, known only AFTER
     *                                        the ephemeral socket is bound. NULL keeps
     *                                        today's out-of-band list byte-stable — the
     *                                        `add` path never had a redirect to declare
     *                                        and must not start advertising one.
     * @return array{clientId: string, clientSecret: string, registrationAccessToken: string}
     */
    public function registerClient(
        string $registrationUrl,
        string $clientName,
        array $scopes = [],
        ?array $redirectUris = null,
    ): array {
        $metadata = [
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris ?? ['urn:ietf:wg:oauth:2.0:oob'],
            'grant_types' => ['authorization_code', 'client_credentials'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'client_secret_basic',
        ];

        if ($scopes !== []) {
            $metadata['scope'] = implode(' ', $scopes);
        }

        $data = $this->requestJson($registrationUrl, [
            'method' => 'POST',
            'json' => ['client_metadata' => $metadata],
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);

        $clientId = $data['client_id'] ?? null;
        $clientSecret = $data['client_secret'] ?? null;
        $accessToken = $data['registration_access_token'] ?? null;

        if ($clientId === null || $accessToken === null) {
            throw new \RuntimeException('Dynamic client registration response missing required fields');
        }

        return [
            'clientId' => $clientId,
            'clientSecret' => $clientSecret ?? '',
            'registrationAccessToken' => $accessToken,
        ];
    }

    /**
     * Fetch an access token using the registered client credentials.
     *
     * @param string $tokenUrl The server's token endpoint
     * @param string $clientId The client ID from registration
     * @param string $clientSecret The client secret from registration (may be empty)
     * @param array<string, string> $scopes Requested scopes
     * @return array{accessToken: string, refreshToken: string, expiresIn: int}
     */
    public function fetchToken(
        string $tokenUrl,
        string $clientId,
        string $clientSecret,
        array $scopes = [],
    ): array {
        $body = [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
        ];

        if ($clientSecret !== '') {
            $body['client_secret'] = $clientSecret;
        }

        if ($scopes !== []) {
            $body['scope'] = implode(' ', $scopes);
        }

        $data = $this->requestJson($tokenUrl, [
            'method' => 'POST',
            'form_params' => $body,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $accessToken = $data['access_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;

        if ($accessToken === null || $expiresIn === null) {
            throw new \RuntimeException('Token response missing access_token or expires_in');
        }

        return [
            'accessToken' => $accessToken,
            'refreshToken' => $data['refresh_token'] ?? '',
            'expiresIn' => (int) $expiresIn,
        ];
    }

    /**
     * Refresh an access token using a refresh token.
     *
     * @param string $tokenUrl The server's token endpoint
     * @param string $clientId The client ID
     * @param string $clientSecret The client secret
     * @param string $refreshToken The refresh token
     * @return array{accessToken: string, refreshToken: string, expiresIn: int}
     */
    public function refreshToken(
        string $tokenUrl,
        string $clientId,
        string $clientSecret,
        string $refreshToken,
    ): array {
        $body = [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
        ];

        if ($clientSecret !== '') {
            $body['client_secret'] = $clientSecret;
        }

        $data = $this->requestJson($tokenUrl, [
            'method' => 'POST',
            'form_params' => $body,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $accessToken = $data['access_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;

        if ($accessToken === null || $expiresIn === null) {
            throw new \RuntimeException('Refresh response missing access_token or expires_in');
        }

        return [
            'accessToken' => $accessToken,
            'refreshToken' => $data['refresh_token'] ?? $refreshToken,
            'expiresIn' => (int) $expiresIn,
        ];
    }

    /**
     * E701: exchange an authorization code (plus the PKCE verifier that
     * produced the challenge the authorize URL carried) for tokens.
     *
     * The response validation mirrors {@see fetchToken()} exactly — an entry
     * that cannot say WHEN it dies is not an entry — and the returned
     * `refreshToken` is `''` when the server omitted one, which is legal for
     * authorization-code grants. The caller of a refresh-less entry serves
     * the access token until it expires and then re-runs the browser login;
     * {@see getValidAuth()}'s buffer-window arm honours that, rather than
     * calling refresh with an empty token and failing deep in the wire.
     *
     * The secret rides the form body (client_secret_post). Registration
     * advertises client_secret_basic (:56's `token_endpoint_auth_method`),
     * and that mismatch is deliberately NOT "fixed" here: every working
     * request this class makes today — fetchToken, refreshToken — posts the
     * credentials in the body against the same endpoint, so the exchange
     * keeps parity with the POSTURE THAT WORKS rather than the capability
     * string the registration happens to advertise.
     *
     * @param string $tokenUrl The server's token endpoint
     * @param string $clientId The client ID from registration
     * @param string $clientSecret The client secret from registration (may be empty)
     * @param string $code The authorization code from the callback
     * @param string $redirectUri The exact redirect_uri the authorize URL carried
     * @param string $codeVerifier The PKCE verifier whose S256 challenge was sent
     * @return array{accessToken: string, refreshToken: string, expiresIn: int, scopes: list<string>}
     */
    public function exchangeAuthorizationCode(
        string $tokenUrl,
        string $clientId,
        string $clientSecret,
        string $code,
        string $redirectUri,
        string $codeVerifier,
    ): array {
        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'code_verifier' => $codeVerifier,
        ];

        if ($clientSecret !== '') {
            $body['client_secret'] = $clientSecret;
        }

        $data = $this->requestJson($tokenUrl, [
            'method' => 'POST',
            'form_params' => $body,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $accessToken = $data['access_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;

        if ($accessToken === null || $expiresIn === null) {
            throw new \RuntimeException('Authorization-code response missing access_token or expires_in');
        }

        $scope = $data['scope'] ?? '';

        return [
            'accessToken' => $accessToken,
            // An omitted refresh_token is stored as '' — the honest shape of
            // "this entry cannot refresh", which getValidAuth() reads as a
            // guard rather than as a token to send.
            'refreshToken' => $data['refresh_token'] ?? '',
            'expiresIn' => (int) $expiresIn,
            'scopes' => $scope === '' ? [] : explode(' ', (string) $scope),
        ];
    }

    /**
     * Persist auth data for a server to the auth file.
     *
     * @param string $serverUrl The server's URL (used as key)
     * @param AuthEntry $entry The auth entry to persist
     */
    public function saveAuth(string $serverUrl, AuthEntry $entry): void
    {
        $authData = $this->loadAuth();
        $authData[$serverUrl] = $entry;
        $this->writeAuthFile($authData);
    }

    /**
     * Load all auth entries from the auth file.
     *
     * @return array<string, AuthEntry>
     */
    public function loadAuth(): array
    {
        if ($this->authCache !== null) {
            return $this->authCache;
        }

        if (!file_exists($this->authFilePath)) {
            return [];
        }

        $content = file_get_contents($this->authFilePath);
        if ($content === false || $content === '') {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        $authData = [];
        foreach ($data as $url => $entry) {
            if (is_array($entry)) {
                $authData[$url] = AuthEntry::fromArray($entry);
            }
        }

        $this->authCache = $authData;
        return $authData;
    }

    /**
     * Get an auth entry for a server, auto-refreshing if within the buffer window.
     *
     * @param string $serverUrl The server URL
     * @param string $tokenUrl The token endpoint for refresh
     * @param string|null $registrationUrl The registration endpoint for re-registration (required when refresh fails with empty refreshToken)
     * @return AuthEntry|null The valid auth entry, or null if none exists
     */
    public function getValidAuth(string $serverUrl, string $tokenUrl, ?string $registrationUrl = null): ?AuthEntry
    {
        $authData = $this->loadAuth();
        $entry = $authData[$serverUrl] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry->isExpired()) {
            if ($entry->refreshToken === '') {
                // Deadlock: expired entry with no refresh token — attempt re-registration
                // per RFC 7591 §3.4 (update existing registration using registrationAccessToken).
                if ($registrationUrl === null) {
                    throw new \RuntimeException(
                        "Auth entry for {$serverUrl} is expired with no refresh token, and no registrationUrl was provided. "
                        . 'Delete the auth file to re-register from scratch.'
                    );
                }

                $registered = $this->updateRegistration($registrationUrl, $entry->registrationAccessToken);
                $now = time();
                $newEntry = new AuthEntry(
                    clientId: $registered['clientId'],
                    clientSecret: $registered['clientSecret'],
                    registrationAccessToken: $registered['registrationAccessToken'],
                    accessToken: $registered['clientId'],
                    refreshToken: '',
                    expiresAt: null,
                    scopes: $entry->scopes,
                    tokenUrl: $entry->tokenUrl,
                    registrationUrl: $entry->registrationUrl,
                );
                $this->saveAuth($serverUrl, $newEntry);

                $token = $this->fetchToken(
                    $tokenUrl,
                    $newEntry->clientId,
                    $newEntry->clientSecret,
                    $entry->scopes,
                );
                return $this->refreshAndSave($serverUrl, $newEntry, $token);
            }

            $refreshed = $this->refreshToken(
                $tokenUrl,
                $entry->clientId,
                $entry->clientSecret,
                $entry->refreshToken,
            );

            return $this->refreshAndSave($serverUrl, $entry, $refreshed);
        }

        if ($entry->expiresAt !== null && $entry->expiresAt - time() < self::TOKEN_EXPIRY_BUFFER_SECONDS) {
            // E701 DEFECT FIX: an authorization-code entry with no
            // refresh_token (legal — RFC 6749 makes the field optional)
            // used to reach refreshToken() here with '' as the token,
            // turning every request inside the buffer window into a doomed
            // wire call whose server error the user saw. An empty refresh
            // token means "cannot refresh", not "refresh with nothing":
            // serve the unexpired entry as-is and let the existing
            // isExpired() arm — earlier in this method — own the eventual
            // expiry: an auth-code entry that expires without a refresh
            // token lands on its re-registration path, whose honest throw
            // when the server will not re-issue credentials is what tells
            // the user to re-run login.
            // That path is deliberately NOT widened to re-run the browser
            // flow: launching a human-in-the-loop prompt from a request
            // path is not something this class should ever do.
            if ($entry->refreshToken === '') {
                return $entry;
            }

            $refreshed = $this->refreshToken(
                $tokenUrl,
                $entry->clientId,
                $entry->clientSecret,
                $entry->refreshToken,
            );

            return $this->refreshAndSave($serverUrl, $entry, $refreshed);
        }

        return $entry;
    }

    /**
     * E695: the server-keyed read path for request-time attachment.
     *
     * Loads the stored entry for a server URL and hands it to
     * {@see getValidAuth()} using the token/registration endpoints that entry
     * PERSISTS — so a caller holding only the server URL (an
     * {@see HttpMcpServer} composing its request headers) gets the refresh
     * behavior for free, and a rotated token is written back through
     * {@see refreshAndSave()}'s existing save path.
     *
     * A legacy row saved before endpoints were persisted carries an empty
     * tokenUrl: there is nothing to refresh against, so the stored entry is
     * served exactly as-is — if its token has since expired, the server's own
     * 401 is the honest outcome, and re-running `mcp auth add` records the
     * endpoints. This asymmetry is deliberate: inventing an endpoint is worse
     * than a visible rejection.
     */
    public function validAuthFor(string $serverUrl): ?AuthEntry
    {
        $entry = $this->loadAuth()[$serverUrl] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry->tokenUrl === '') {
            return $entry;
        }

        return $this->getValidAuth(
            $serverUrl,
            $entry->tokenUrl,
            $entry->registrationUrl !== '' ? $entry->registrationUrl : null,
        );
    }

    /**
     * Update an existing client registration per RFC 7591 §3.4.
     *
     * @param string $registrationUrl The server's registration endpoint
     * @param string $registrationAccessToken The token received during initial registration
     * @return array{clientId: string, clientSecret: string, registrationAccessToken: string}
     */
    private function updateRegistration(string $registrationUrl, string $registrationAccessToken): array
    {
        $data = $this->requestJson($registrationUrl, [
            'method' => 'PUT',
            'headers' => [
                'Authorization' => "Bearer {$registrationAccessToken}",
                'Accept' => 'application/json',
            ],
        ]);

        $clientId = $data['client_id'] ?? null;
        $clientSecret = $data['client_secret'] ?? null;
        $accessToken = $data['registration_access_token'] ?? $registrationAccessToken;

        if ($clientId === null) {
            throw new \RuntimeException('Client update response missing client_id');
        }

        return [
            'clientId' => $clientId,
            'clientSecret' => $clientSecret ?? '',
            'registrationAccessToken' => $accessToken,
        ];
    }

    /**
     * Reconstruct an AuthEntry from a refreshed token response and persist it.
     */
    private function refreshAndSave(string $serverUrl, AuthEntry $entry, array $refreshed): AuthEntry
    {
        $now = time();
        $newEntry = new AuthEntry(
            clientId: $entry->clientId,
            clientSecret: $entry->clientSecret,
            registrationAccessToken: $entry->registrationAccessToken,
            accessToken: $refreshed['accessToken'],
            refreshToken: $refreshed['refreshToken'],
            expiresAt: $now + $refreshed['expiresIn'],
            scopes: $entry->scopes,
            tokenUrl: $entry->tokenUrl,
            registrationUrl: $entry->registrationUrl,
        );

        $this->saveAuth($serverUrl, $newEntry);
        return $newEntry;
    }

    /**
     * Execute an HTTP request and parse the JSON response, validating it is an array.
     *
     * @param string $url
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function requestJson(string $url, array $options): array
    {
        try {
            $response = $this->httpClient->request(
                $options['method'] ?? 'GET',
                $url,
                array_diff_key($options, ['method' => true]),
            );

            $body = $response->getBody()->getContents();
            if ($body === '') {
                throw new \RuntimeException("Empty response body from {$url}");
            }

            $data = json_decode($body, true);
            if (!is_array($data)) {
                throw new \RuntimeException("Non-array JSON response from {$url}");
            }

            return $data;
        } catch (GuzzleException $e) {
            throw new \RuntimeException("HTTP request to {$url} failed: {$e->getMessage()}");
        }
    }

    /**
     * Clear the cached auth data (forces re-read from disk on next load).
     */
    public function clearCache(): void
    {
        $this->authCache = null;
    }

    /**
     * Delete auth data for a specific server.
     *
     * @param string $serverUrl The server URL to remove
     */
    public function deleteAuth(string $serverUrl): void
    {
        $authData = $this->loadAuth();
        unset($authData[$serverUrl]);
        $this->writeAuthFile($authData);
    }

    private function defaultAuthFilePath(): string
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '/tmp');
        return $home . '/.local/share/sugar-crush/mcp-auth.json';
    }

    /**
     * @param array<string, AuthEntry> $authData
     */
    private function writeAuthFile(array $authData): void
    {
        $dir = dirname($this->authFilePath);
        if (!is_dir($dir) && mkdir($dir, 0700, true) === false) {
            throw new \RuntimeException("Failed to create auth directory: {$dir}");
        }

        $serializable = [];
        foreach ($authData as $url => $entry) {
            $serializable[$url] = $entry->toArray();
        }

        $json = json_encode($serializable, JSON_PRETTY_PRINT);
        if (file_put_contents($this->authFilePath, $json, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write auth file: {$this->authFilePath}");
        }
        chmod($this->authFilePath, 0600);

        $this->authCache = $authData;
    }
}

/**
 * Represents stored OAuth credentials for a single MCP server.
 */
final readonly class AuthEntry
{
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public string $registrationAccessToken,
        public string $accessToken,
        public string $refreshToken,
        public ?int $expiresAt,
        public array $scopes = [],
        public string $tokenUrl = '',
        public string $registrationUrl = '',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            clientId: $data['clientId'] ?? '',
            clientSecret: $data['clientSecret'] ?? '',
            registrationAccessToken: $data['registrationAccessToken'] ?? '',
            accessToken: $data['accessToken'] ?? '',
            refreshToken: $data['refreshToken'] ?? '',
            expiresAt: isset($data['expiresAt']) ? (int) $data['expiresAt'] : null,
            scopes: $data['scopes'] ?? [],
            tokenUrl: $data['tokenUrl'] ?? '',
            registrationUrl: $data['registrationUrl'] ?? '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
            'registrationAccessToken' => $this->registrationAccessToken,
            'accessToken' => $this->accessToken,
            'refreshToken' => $this->refreshToken,
            'expiresAt' => $this->expiresAt,
            'scopes' => $this->scopes,
            'tokenUrl' => $this->tokenUrl,
            'registrationUrl' => $this->registrationUrl,
        ];
    }

    /**
     * Check if the token is already expired.
     */
    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= time();
    }

    /**
     * Check if the token expires within the given number of seconds.
     */
    public function expiresWithin(int $seconds): bool
    {
        return $this->expiresAt !== null && $this->expiresAt - time() < $seconds;
    }
}
