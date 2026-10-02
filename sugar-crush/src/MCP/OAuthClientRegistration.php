<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Crush\Support\TimedFileLock;

/**
 * Handles OAuth 2.0 Dynamic Client Registration (RFC 7591) for MCP servers.
 *
 * Registers sugar-crush with a remote MCP server on first connection,
 * receives credentials automatically, stores tokens at
 * ~/.local/share/sugar-crush/mcp-auth.json, and refreshes them ahead of expiry.
 *
 * THE FILE IS SHARED BETWEEN PROCESSES. A long-lived TUI refreshes tokens
 * while `sugarcrush mcp auth login|remove` runs in another shell, so every
 * write is a read-modify-write of ONE key under an exclusive lock on a
 * sidecar (`<file>.lock`), re-reading the file fresh under that lock and
 * publishing through a 0600 temp file + rename(). Writing back this object's
 * cached map instead (audit MCP-7) erased every login another process had
 * made since the cache was filled, and the old in-place, chmod-afterwards
 * write could be observed torn (read back as "no credentials") and briefly
 * world-readable.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7591
 */
final class OAuthClientRegistration
{
    private const TOKEN_EXPIRY_BUFFER_SECONDS = 300;

    private Client $httpClient;

    /** @var array<string, AuthEntry>|null */
    private ?array $authCache = null;

    /**
     * Stat signature of the file {@see $authCache} was decoded from.
     *
     * @var list<int>|null
     */
    private ?array $authCacheSignature = null;

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
     * Only $serverUrl's row is replaced: the rest of the file is whatever is on
     * disk under the lock, NOT this object's cache — see the class doc-block.
     *
     * @param string $serverUrl The server's URL (used as key)
     * @param AuthEntry $entry The auth entry to persist
     */
    public function saveAuth(string $serverUrl, AuthEntry $entry): void
    {
        $this->mutateAuthFile(static function (array $raw) use ($serverUrl, $entry): array {
            $raw[$serverUrl] = $entry->toArray();
            return $raw;
        });
    }

    /**
     * Load all auth entries from the auth file.
     *
     * Memoized, but only for as long as the file on disk is the one the memo
     * was decoded from: a long-lived TUI must see a login or a token rotation
     * another process published, or it keeps sending (and refreshing with) a
     * token that process already replaced. Every write here publishes a new
     * inode by rename(), so the stat compare is one syscall per call and the
     * file is re-decoded only when it actually changed.
     *
     * A present-but-unparseable file reads as no credentials, as it always
     * has: a broken file must cost a login, not the session. The WRITE path
     * refuses that same file instead ({@see mutateAuthFile()}).
     *
     * @return array<string, AuthEntry>
     */
    public function loadAuth(): array
    {
        $signature = $this->fileSignature();
        if ($signature === null) {
            $this->authCache = null;
            $this->authCacheSignature = null;
            return [];
        }

        if ($this->authCache !== null && $signature === $this->authCacheSignature) {
            return $this->authCache;
        }

        $raw = $this->readRawAuth();
        if ($raw === null) {
            return [];
        }

        $this->authCache = $this->decodeEntries($raw);
        $this->authCacheSignature = $signature;
        return $this->authCache;
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
        $this->authCacheSignature = null;
    }

    /**
     * Delete auth data for a specific server.
     *
     * Same locked read-modify-write as {@see saveAuth()}: removing one server
     * must not resurrect or erase anybody else's row.
     *
     * @param string $serverUrl The server URL to remove
     */
    public function deleteAuth(string $serverUrl): void
    {
        $this->mutateAuthFile(static function (array $raw) use ($serverUrl): ?array {
            if (!\array_key_exists($serverUrl, $raw)) {
                return null;
            }

            unset($raw[$serverUrl]);
            return $raw;
        });
    }

    private function defaultAuthFilePath(): string
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '/tmp');
        return $home . '/.local/share/sugar-crush/mcp-auth.json';
    }

    /**
     * Apply $mutate to the auth file's CURRENT contents and publish the result.
     *
     * $mutate receives the decoded file read fresh under the exclusive sidecar
     * lock and returns the full map to write, or null for "nothing changed".
     * Rows it does not touch are carried through as raw arrays, never
     * round-tripped through {@see AuthEntry}, so a field a newer build added
     * survives an older build's write.
     *
     * A file that exists but does not decode as a JSON object is REFUSED here,
     * where it used to be overwritten. The old answer was the read path's `[]`
     * used as the merge base, which is not forgiveness but deletion: every
     * credential the file held was gone the moment the write landed, and the
     * user was never told. Refusing names the file, keeps its bytes for the
     * user to repair or remove, and matches the config writer's doctrine
     * (`Bootstrap::userConfigForMerge()`). Our own writes can no longer produce
     * such a file — they are atomic — so it means a hand edit or another tool.
     *
     * @param callable(array<array-key, mixed>): (array<array-key, mixed>|null) $mutate
     */
    private function mutateAuthFile(callable $mutate): void
    {
        $dir = \dirname($this->authFilePath);
        // Race-safe: a concurrent writer may create it between the two checks.
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create auth directory: {$dir}");
        }

        $lock = $this->acquireAuthLock();

        try {
            $raw = $this->readRawAuth();
            if ($raw === null) {
                throw new \RuntimeException(
                    "Refusing to rewrite {$this->authFilePath}: it exists but is not a readable JSON object "
                    . 'of MCP credentials, and overwriting it would drop every entry it holds. '
                    . 'Repair or remove the file, then retry.'
                );
            }

            $next = $mutate($raw);
            if ($next !== null) {
                // withPermissions() chmods the temp inode BEFORE a payload byte
                // is written, and rename() carries that mode onto the target, so
                // the tokens are never on disk with looser bits than 0600 —
                // the old write chmod'ed only after the bytes had landed.
                AtomicJsonFile::new($this->authFilePath)->withPermissions(0600)->write($next);
                $raw = $next;
            }

            // The memo becomes the merged on-disk state, never "our map plus
            // our one change".
            $this->authCache = $this->decodeEntries($raw);
            $this->authCacheSignature = $this->fileSignature();
        } finally {
            TimedFileLock::release($lock);
            fclose($lock);
        }
    }

    /**
     * Take the exclusive lock that serialises {@see mutateAuthFile()}.
     *
     * A SIDECAR, never the auth file itself: every write replaces the file's
     * inode by rename(), so a lock held on it would guard a file no longer on
     * disk while the next writer locked the new inode unopposed. The sidecar is
     * never unlinked for the same reason. Created under umask 077 — it holds no
     * bytes, but whoever can open it can hold it and stall this user's logins.
     * Fails CLOSED: a write that went ahead without the lock is exactly the
     * lost update the lock exists to prevent.
     *
     * @return resource
     */
    private function acquireAuthLock()
    {
        $lockPath = $this->authFilePath . '.lock';

        $umask = umask(0077);
        try {
            $fp = @fopen($lockPath, 'c');
        } finally {
            umask($umask);
        }

        if ($fp === false) {
            throw new \RuntimeException("Failed to open auth lock file: {$lockPath}");
        }

        try {
            TimedFileLock::acquire($fp, \LOCK_EX, $lockPath);
        } catch (\RuntimeException $e) {
            fclose($fp);
            throw $e;
        }

        return $fp;
    }

    /**
     * The auth file decoded, `[]` when it is missing or empty (nothing to
     * lose), or null when it exists but is not a JSON object.
     *
     * A non-empty top-level LIST is refused like any other non-object: this
     * file is always keyed by server URL. `[]` itself is accepted because the
     * old writer encoded an empty map that way.
     *
     * @return array<array-key, mixed>|null
     */
    private function readRawAuth(): ?array
    {
        clearstatcache(true, $this->authFilePath);
        if (!file_exists($this->authFilePath)) {
            return [];
        }

        $content = @file_get_contents($this->authFilePath);
        if ($content === false) {
            return null;
        }

        if (trim($content) === '') {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            return null;
        }

        return $data;
    }

    /**
     * @param array<array-key, mixed> $raw
     * @return array<string, AuthEntry>
     */
    private function decodeEntries(array $raw): array
    {
        $authData = [];
        foreach ($raw as $url => $entry) {
            if (is_array($entry)) {
                $authData[(string) $url] = AuthEntry::fromArray($entry);
            }
        }

        return $authData;
    }

    /**
     * Identity of the file currently at the auth path, or null when none is.
     *
     * Device + inode change on every rename() publish (ours and AtomicJsonFile
     * users'); size, mtime and ctime catch an in-place edit by another tool.
     *
     * @return list<int>|null
     */
    private function fileSignature(): ?array
    {
        clearstatcache(true, $this->authFilePath);
        $stat = @stat($this->authFilePath);
        if ($stat === false) {
            return null;
        }

        return [$stat['dev'], $stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime']];
    }
}
