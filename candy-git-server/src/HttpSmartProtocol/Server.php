<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\HttpSmartProtocol;

use SugarCraft\GitServer\{AccessControl, Config, Repo, Stats, User};
use SugarCraft\GitServer\Git\{PktLine, ReceivePack, StatelessRpc, UploadPack};
use SugarCraft\GitServer\LFS\LFSHandler;

/**
 * HTTP server that speaks the Git smart protocol.
 *
 * Handles Git clone/fetch over HTTP/1.1 using the smart protocol (not
 * the dumb HTTP transport). git-upload-pack is served end to end;
 * git-receive-pack POSTs answer a 501 phase-2 refusal (owner ruling 1b)
 * while its advertisement stays gated behind canWrite for the push phase.
 *
 * Smart HTTP protocol flow:
 * 1. Client GETs /info/refs?service=git-upload-pack  → advertises refs
 * 2. Client POSTs /git-upload-pack with wants           → exchanges pack data
 * 3. For push: Client POSTs /git-receive-pack          → sends pack, receives status
 *
 * CHILD-PROCESS LIFETIME (E721): the live smart-HTTP children are spawned
 * inside Git\StatelessRpc (advertise + negotiate), whose bounded read +
 * TERM/KILL escalation ladder releases pipes and handle in a `finally` per
 * spawn site. This class keeps exactly one further `proc_open` — the DORMANT
 * generatePackData() shortcut, no callers, carrying the same reap shape — so
 * nothing outlives the synchronous handleRequest() call that reached it. All
 * `exec()` helpers (Repo metadata reads) wait for their child by definition.
 * There is no daemon object here to stop: the host application owns the
 * socket, and a request never returns while it has a live child.
 *
 * Port of charmbracelet/soft-serve HttpSmartProtocol Server.
 *
 * @see https://github.com/charmbracelet/soft-serve
 * @see https://git-scm.com/book/en/v2/Git-Internals-Git-Protocols
 */
final class Server
{
    private Config $config;

    /** @var array<string, Repo> repo name => Repo */
    private array $repos = [];

    /** @var array<string, User> username => User */
    private array $users = [];

    /** Current request path. */
    private string $path = '';

    /** Current request method. */
    private string $method = 'GET';

    /** Current request query string. */
    private string $query = '';

    /**
     * Peer (immediate client) IP for the current request, or null when the
     * host application does not supply it. Drives 'proxy' trust-mode checks
     * for the {@see resolveTrustedUser()} header decision.
     */
    private ?string $peerIp = null;

    /** HTTP headers to send. */
    private array $responseHeaders = [];

    /** HTTP status code. */
    private int $statusCode = 200;

    /** Response body. */
    private string $body = '';

    /** Stats collector override (null = shared instance). */
    private ?Stats $stats = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /** Inject a stats collector (default: the shared {@see Stats} instance). */
    public function setStats(Stats $stats): void
    {
        $this->stats = $stats;
    }

    private function stats(): Stats
    {
        return $this->stats ?? Stats::getInstance();
    }

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function registerRepo(Repo $repo): void
    {
        $this->repos[$repo->name] = $repo;
    }

    public function registerUser(User $user): void
    {
        $this->users[$user->username] = $user;
    }

    /** @param iterable<Repo> $repos */
    public function setRepos(iterable $repos): void
    {
        $this->repos = [];
        foreach ($repos as $repo) {
            $this->repos[$repo->name] = $repo;
        }
    }

    // -------------------------------------------------------------------------
    // Request handling
    // -------------------------------------------------------------------------

    /**
     * Handle an incoming HTTP request.
     *
     * @param string $method     HTTP method (GET, POST, etc.)
     * @param string $path        Request path (e.g. "/myrepo.git/info/refs")
     * @param string $query      Query string (e.g. "service=git-upload-pack")
     * @param array<string, string> $headers  Request headers
     * @param string $body       Request body (for POST)
     * @param string|null $peerIp Immediate client IP (from the accepting
     *                            socket/transport). Required for 'proxy'
     *                            user-trust mode; ignored otherwise.
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handleRequest(
        string $method,
        string $path,
        string $query,
        array $headers,
        string $body,
        ?string $peerIp = null,
    ): array {
        $this->path = $path;
        $this->method = $method;
        $this->query = $query;
        $this->peerIp = $peerIp;
        $this->responseHeaders = [];
        $this->statusCode = 200;
        $this->body = '';

        // Reset for each request
        $this->responseHeaders['Date'] = \gmdate('D, d M Y H:i:s') . ' GMT';
        $this->responseHeaders['Connection'] = 'close';
        $this->responseHeaders['Server'] = 'CandyGitServer/1.0';

        $this->stats()->recordConnection();

        try {
            return $this->route($headers, $body);
        } catch (\Throwable $e) {
            \fwrite(\STDERR, (string) $e);
            return $this->errorResponse(500, 'Internal server error');
        }
    }

    /**
     * Route the request to the appropriate handler.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function route(array $headers, string $body): array
    {
        // Strip leading slash and split path
        $cleanPath = \ltrim($this->path, '/');
        $parts = $cleanPath === '' ? [] : \explode('/', $cleanPath);

        // Expected path formats:
        // - /{repo}.git/info/refs?service=git-upload-pack
        // - /{repo}.git/git-upload-pack  (POST)
        // - /{repo}.git/git-receive-pack (POST)
        // - /{repo}.git/info/refs?service=git-receive-pack
        // - /{repo}.git/info/lfs/objects/batch          (POST, LFS batch API)
        // - /{repo}.git/info/lfs/objects/{oid}          (GET download, PUT upload)
        // - /{repo}.git/info/lfs/objects/{oid}/verify   (POST, post-upload check)

        if (\count($parts) < 2) {
            return $this->errorResponse(404, 'Not found');
        }

        $actionPart = $parts[1] ?? '';

        // Handle info/refs request (advertisement)
        if ($actionPart === 'info' && isset($parts[2]) && $parts[2] === 'refs') {
            return $this->handleInfoRefs($headers);
        }

        // Handle Git LFS routes (batch API + object transfer)
        if ($actionPart === 'info' && isset($parts[2]) && $parts[2] === 'lfs') {
            return $this->handleLfs($parts, $headers, $body);
        }

        // Handle upload-pack request
        if ($actionPart === 'git-upload-pack') {
            return $this->handleUploadPack($headers, $body);
        }

        // Handle receive-pack request
        if ($actionPart === 'git-receive-pack') {
            return $this->handleReceivePack();
        }

        return $this->errorResponse(404, 'Not found');
    }

    /**
     * Handle GET /{repo}/info/refs?service=git-upload-pack
     *
     * This advertises the available refs to the client.
     */
    private function handleInfoRefs(array $headers): array
    {
        $queryParams = $this->parseQuery($this->query);
        $service = $queryParams['service'] ?? '';

        if ($service !== 'git-upload-pack' && $service !== 'git-receive-pack') {
            return $this->errorResponse(400, 'Missing or invalid service parameter');
        }

        // Extract repo name from path
        $repoName = $this->extractRepoName();
        if ($repoName === null) {
            return $this->errorResponse(404, 'Repository not found');
        }

        $repo = $this->findRepo($repoName);
        if ($repo === null) {
            return $this->errorResponse(404, 'Repository not found');
        }

        // Gate BEFORE any advertisement byte leaves the process (M3 cure,
        // r1024/githttp owner ruling 1b): the old code refused upload-pack
        // only and openly admitted the receive-pack bypass, leaking private
        // repo ref names. Read advertisement rides canRead, push advertisement
        // rides canWrite — an anonymous client learns nothing it may not use.
        $ac = AccessControl::getInstance();
        $user = $this->getUserFromHeaders($headers);
        if ($service === 'git-upload-pack') {
            if (!$ac->canRead($user, $repo)) {
                return $this->errorResponse(403, 'Access denied');
            }
        } elseif (!$ac->canWrite($user, $repo)) {
            return $this->errorResponse(403, 'Access denied');
        }

        // Build the advertisement packet
        $advertisement = $this->buildRefAdvertisement($repo, $service);

        $this->responseHeaders['Content-Type'] = 'application/x-' . $service . '-advertisement';
        $this->responseHeaders['Cache-Control'] = 'no-cache';
        $this->body = $advertisement;

        return $this->finalizeResponse();
    }

    /**
     * Handle POST /{repo}/git-upload-pack
     *
     * The client sends wants and we respond with a packfile.
     */
    private function handleUploadPack(array $headers, string $body): array
    {
        $repoName = $this->extractRepoName();
        if ($repoName === null) {
            return $this->errorResponse(404, 'Repository not found');
        }

        $repo = $this->findRepo($repoName);
        if ($repo === null) {
            return $this->errorResponse(404, 'Repository not found');
        }

        $ac = AccessControl::getInstance();
        if (!$ac->canRead($this->getUserFromHeaders($headers), $repo)) {
            return $this->errorResponse(403, 'Access denied');
        }

        // Delegate the whole negotiation to the real binary through the
        // argv-array stateless-rpc pump (StatelessRpc owns the E721
        // request-scoped reap: bounded write + bounded drain on one
        // deadline, stderr to null, TERM->KILL).
        $maxBytes = $this->config->maxPackBytes ?? 268435456; // 256 MiB default
        $packData = StatelessRpc::negotiate('git-upload-pack', $repo->path(), $body, $maxBytes);
        if ($packData === null) {
            return $this->errorResponse(413, 'Packfile too large');
        }

        $this->stats()->recordPackDownload();
        $this->responseHeaders['Content-Type'] = 'application/x-git-upload-pack-result';
        $this->body = $packData;
        return $this->finalizeResponse();
    }

    /**
     * Handle POST /{repo}/git-receive-pack — phase-2 refusal (owner ruling
     * 1b, r1024/githttp): phase 1 ships the smart-HTTP READ path only.
     * Push needs receive-pack negotiation plus ref-update hardening that
     * belong to their own lane; until then this answers 501 loudly instead
     * of pretending to work. The receive-pack ADVERTISEMENT (info/refs
     * ?service=git-receive-pack) is gated and served, so a future phase-2
     * flips this one handler back on without touching the wire plumbing.
     */
    private function handleReceivePack(): array
    {
        return $this->errorResponse(501, 'receive-pack (push) is phase 2; this server speaks the read path only');
    }

    // -------------------------------------------------------------------------
    // Git LFS routes (plan item 7.9)
    // -------------------------------------------------------------------------

    /**
     * Dispatch /{repo}/info/lfs/objects/... requests.
     *
     * @param list<string> $parts Path segments
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function handleLfs(array $parts, array $headers, string $body): array
    {
        if (!$this->config->lfsEnabled) {
            return $this->errorResponse(404, 'Git LFS is disabled');
        }

        $repoName = $this->extractRepoName();
        $repo = $repoName !== null ? $this->findRepo($repoName) : null;
        if ($repo === null) {
            return $this->errorResponse(404, 'Repository not found');
        }

        if (($parts[3] ?? '') !== 'objects') {
            return $this->errorResponse(404, 'Not found');
        }

        $user = $this->getUserFromHeaders($headers);
        $handler = new LFSHandler($repo, $user, $this->config->lfsPath(), stats: $this->stats);

        $target = $parts[4] ?? '';
        if ($target === 'batch') {
            return $this->handleLfsBatch($repo, $handler, $user, $body);
        }

        // Object routes: the OID is always the full lowercase SHA-256 hex.
        if (\preg_match('/^[0-9a-f]{64}$/', $target) !== 1) {
            return $this->errorResponse(400, 'Invalid LFS object ID');
        }

        if (($parts[5] ?? '') === 'verify') {
            return $this->handleLfsVerify($repo, $handler, $user, $target, $body);
        }
        if (isset($parts[5])) {
            return $this->errorResponse(404, 'Not found');
        }

        return match ($this->method) {
            'GET' => $this->handleLfsDownload($repo, $handler, $user, $target),
            'PUT' => $this->handleLfsUpload($repo, $handler, $user, $target, $body),
            default => $this->errorResponse(405, 'Method not allowed'),
        };
    }

    /**
     * POST /{repo}/info/lfs/objects/batch — the LFS batch API.
     *
     * Auth mirrors the object routes: reads for `download` operations,
     * writes for `upload` operations (the handler itself only checks
     * read access; the write gate lives here at the HTTP boundary).
     */
    private function handleLfsBatch(Repo $repo, LFSHandler $handler, ?User $user, string $body): array
    {
        if ($this->method !== 'POST') {
            return $this->errorResponse(405, 'Method not allowed');
        }

        $request = \json_decode($body, true);
        if (!\is_array($request)) {
            return $this->errorResponse(400, 'Invalid JSON body');
        }

        if (($request['operation'] ?? 'download') === 'upload'
            && !AccessControl::getInstance()->canWrite($user, $repo)) {
            return $this->errorResponse(403, 'Access denied');
        }

        $result = $handler->handleBatch($request);

        if (isset($result['error']['code'])) {
            return $this->errorResponse((int) $result['error']['code'], (string) $result['error']['message']);
        }

        $this->responseHeaders['Content-Type'] = LFSHandler::MEDIA_TYPE;
        $this->responseHeaders['Cache-Control'] = 'no-cache';
        $this->body = \json_encode($result, \JSON_UNESCAPED_SLASHES) ?: '{}';

        return $this->finalizeResponse();
    }

    /**
     * GET /{repo}/info/lfs/objects/{oid} — download an object.
     *
     * The handleRequest() contract returns the body as one string, so
     * the object is buffered here; readAll() drains the backend stream
     * in a single C-level copy rather than PHP-level chunk loops.
     */
    private function handleLfsDownload(Repo $repo, LFSHandler $handler, ?User $user, string $oid): array
    {
        if (!AccessControl::getInstance()->canRead($user, $repo)) {
            return $this->errorResponse(403, 'Access denied');
        }

        $backend = $handler->storageBackend();
        if (!$backend->exists($oid)) {
            return $this->errorResponse(404, 'Object not found');
        }

        $this->stats()->recordLfsDownload();

        $this->responseHeaders['Content-Type'] = 'application/octet-stream';
        $this->body = $backend->readAll($oid);
        $this->responseHeaders['Content-Length'] = (string) \strlen($this->body);

        return $this->finalizeResponse();
    }

    /**
     * PUT /{repo}/info/lfs/objects/{oid} — upload an object.
     *
     * Enforces the configured transfer cap (`http.max_pack_bytes`, the
     * same knob that caps buffered packfiles) and verifies the body's
     * SHA-256 matches the OID before anything touches storage.
     */
    private function handleLfsUpload(Repo $repo, LFSHandler $handler, ?User $user, string $oid, string $body): array
    {
        if (!AccessControl::getInstance()->canWrite($user, $repo)) {
            return $this->errorResponse(403, 'Access denied');
        }

        $maxBytes = $this->config->maxPackBytes;
        if ($maxBytes !== null && \strlen($body) > $maxBytes) {
            return $this->errorResponse(413, 'Object too large');
        }

        if (\hash('sha256', $body) !== $oid) {
            return $this->errorResponse(422, 'Object hash does not match OID');
        }

        $stream = \fopen('php://temp', 'r+');
        if ($stream === false) {
            return $this->errorResponse(500, 'Internal server error');
        }
        try {
            \fwrite($stream, $body);
            \rewind($stream);
            $handler->storageBackend()->write($oid, $stream);
        } finally {
            \fclose($stream);
        }

        $this->stats()->recordLfsUpload();
        $this->statusCode = 200;
        $this->body = '';

        return $this->finalizeResponse();
    }

    /**
     * POST /{repo}/info/lfs/objects/{oid}/verify — post-upload check
     * that the object landed intact (exists and has the size the client
     * claims). Uses upload auth, matching the batch `verify` action.
     */
    private function handleLfsVerify(Repo $repo, LFSHandler $handler, ?User $user, string $oid, string $body): array
    {
        if ($this->method !== 'POST') {
            return $this->errorResponse(405, 'Method not allowed');
        }

        if (!AccessControl::getInstance()->canWrite($user, $repo)) {
            return $this->errorResponse(403, 'Access denied');
        }

        $request = \json_decode($body, true);
        if (!\is_array($request)) {
            return $this->errorResponse(400, 'Invalid JSON body');
        }

        $backend = $handler->storageBackend();
        if (!$backend->exists($oid)) {
            return $this->errorResponse(404, 'Object not found');
        }

        $expectedSize = (int) ($request['size'] ?? -1);
        if ($expectedSize >= 0 && $backend->size($oid) !== $expectedSize) {
            return $this->errorResponse(422, 'Object size does not match');
        }

        $this->responseHeaders['Content-Type'] = LFSHandler::MEDIA_TYPE;
        $this->body = \json_encode(['oid' => $oid, 'size' => $backend->size($oid)], \JSON_UNESCAPED_SLASHES) ?: '{}';

        return $this->finalizeResponse();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Extract repo name from the current path.
     */
    private function extractRepoName(): ?string
    {
        $cleanPath = \ltrim($this->path, '/');
        $parts = $cleanPath === '' ? [] : \explode('/', $cleanPath);

        if ($parts === []) {
            return null;
        }

        // Handle /{name}.git/... format
        $firstPart = $parts[0];
        if (\str_ends_with($firstPart, '.git')) {
            return $firstPart;
        }

        return $firstPart;
    }

    /**
     * Find a repo by name.
     */
    private function findRepo(string $name): ?Repo
    {
        $repo = $this->repos[$name] ?? null;
        if ($repo !== null) {
            return $repo;
        }

        // Clients append '.git' to clone URLs (and tolerate it omitted);
        // hosts register under whichever name they scanned. Resolve the
        // other spelling before declaring 404 (r1024/githttp).
        if (\str_ends_with($name, '.git')) {
            return $this->repos[\substr($name, 0, -4)] ?? null;
        }

        return $this->repos[$name . '.git'] ?? null;
    }

    /**
     * Build the smart-HTTP advertisement: the http transport's service
     * header packet and its flush, then git's OWN advertisement bytes
     * verbatim (StatelessRpc::advertise). The old shape self-encoded
     * Repo::refs() without capabilities — a pkt-line stream git clients
     * reject. Delegating means the ref list, the NUL-joined capability
     * line and the symref/agent announcements are exactly what the
     * installed git binary itself would serve.
     */
    private function buildRefAdvertisement(Repo $repo, string $service): string
    {
        return PktLine::encode("# service={$service}\n")
            . PktLine::flush()
            . StatelessRpc::advertise($service, $repo->path());
    }

    /**
     * Generate pack data for upload-pack.
     *
     * DORMANT (E721 census): this private helper has no callers — the live
     * upload-pack route delegates the whole negotiation to
     * StatelessRpc::negotiate() (a real `git upload-pack --stateless-rpc`
     * child), which subsumes this pack-objects shortcut. It is kept, carries
     * the same request-scoped reap as its live siblings, and if it is ever
     * re-wired it inherits that contract; removal was not taken under the
     * dormant-code stop rule. Its spawn moved to an argv array with the
     * others (house law: no shell strings around git).
     */
    private function generatePackData(Repo $repo, string $requestBody): string
    {
        // Parse the request to get wanted refs
        $wants = $this->parseUploadPackRequest($requestBody);

        if ($wants === []) {
            return '';
        }

        $cmd = ['git', '-C', $repo->path(), 'pack-objects', '--stdout', '--revs'];

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        /** @var resource|false $proc */
        $proc = \proc_open($cmd, $desc, $pipes);
        if ($proc === false) {
            return '';
        }

        // E721: request-scoped reap — see handleUploadPack() for the shape.
        $packData = null;
        try {
            // Write want revs to stdin (one per line, no ^ prefix)
            foreach ($wants as $hash) {
                \fwrite($pipes[0], $hash . "\n");
            }
            \fclose($pipes[0]);

            // TODO: stream via chunked callback for true streaming; cap buffered size for now
            $maxBytes = $this->config->maxPackBytes ?? 268435456; // 256 MiB default
            $packData = $this->readCapped($pipes[1], $maxBytes);
        } finally {
            if ($packData === null) {
                @\proc_terminate($proc);
            }
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    \fclose($pipe);
                }
            }
            \proc_close($proc);
        }
        if ($packData === null) {
            throw new \RuntimeException('Packfile exceeds maximum size limit');
        }
        return $packData;
    }

    /**
     * Drain a pipe into memory, enforcing a byte cap.
     *
     * stream_get_contents() copies in C rather than PHP-level 64 KiB
     * concatenations; reading maxlength+1 lets a single call both fetch the
     * data and detect the overflow.
     *
     * @param resource $pipe
     * @return string|null  null when the stream exceeds $maxBytes
     */
    private function readCapped($pipe, int $maxBytes): ?string
    {
        $data = \stream_get_contents($pipe, $maxBytes + 1);
        if ($data === false) {
            return '';
        }
        return \strlen($data) > $maxBytes ? null : $data;
    }

    /**
     * Parse upload-pack request body to extract wanted hashes.
     *
     * @return list<string>
     */
    private function parseUploadPackRequest(string $body): array
    {
        $wants = [];
        $lines = \explode("\n", \rtrim($body, "\r\n"));

        foreach ($lines as $line) {
            $line = \rtrim($line, "\r\n");
            if (\str_starts_with($line, 'want ')) {
                $hash = \substr($line, 5);
                if (\strlen($hash) === 40 && \ctype_xdigit($hash)) {
                    $wants[] = $hash;
                }
            } elseif ($line === '') {
                break;  // End of wants section
            }
        }

        return $wants;
    }

    /**
     * Parse a query string into key-value pairs.
     *
     * @return array<string, string>
     */
    private function parseQuery(string $query): array
    {
        if ($query === '') {
            return [];
        }

        $params = [];
        \parse_str($query, $params);
        return $params;
    }

    /**
     * Extract user from HTTP headers.
     *
     * Looks for Basic auth or session headers.
     */
    private function getUserFromHeaders(array $headers): ?User
    {
        // HTTP header names are case-insensitive on the wire (git sends
        // lowercase); canonicalize once at the trust boundary (r1024/githttp).
        $headers = \array_change_key_case($headers, \CASE_LOWER);

        // Check Basic auth
        if (isset($headers['authorization'])) {
            $auth = $headers['authorization'];
            if (\str_starts_with($auth, 'Basic ')) {
                $credentials = \base64_decode(\substr($auth, 6));
                if ($credentials !== false) {
                    $colon = \strpos($credentials, ':');
                    if ($colon !== false) {
                        $username = \substr($credentials, 0, $colon);
                        $password = \substr($credentials, $colon + 1);
                        $user = $this->users[$username] ?? null;
                        if ($user === null) return null;
                        // Verify password — reject null/empty passwords (deny auth bypass)
                        if ($user->password === null || !\hash_equals($user->password, $password)) {
                            return null;
                        }
                        // A deactivated account authenticates to nothing
                        // (gap #5 cure, r1024/githttp): credentials out,
                        // identity null — the request proceeds as anonymous.
                        if (!$user->isActive) {
                            return null;
                        }
                        return $user;
                    }
                }
            }
        }

        // Custom CandyGitServer user header — trusted only when the configured
        // trust mode's check passes (fail-closed; see resolveTrustedUser).
        return $this->resolveTrustedUser($headers, $this->peerIp);
    }

    /**
     * Resolve the user named by the untrusted `X-CandyGitServer-User` header,
     * honoring it only when the configured trust mode's check passes.
     *
     * Fail-closed by design: 'off' (default) ignores the header entirely;
     * 'proxy' requires the peer IP in the trusted-proxy allowlist; 'token'
     * requires a valid HMAC-SHA256 signature in `X-CandyGitServer-User-Sig`.
     * Absent required config (empty allowlist / empty secret) trusts
     * nothing — a raw client-supplied header can never authenticate a user.
     *
     * Extracted from getUserFromHeaders() so the trust decision is unit
     * testable without a live socket.
     *
     * @param array<string, string> $headers
     */
    private function resolveTrustedUser(array $headers, ?string $peerIp): ?User
    {
        $headers = \array_change_key_case($headers, \CASE_LOWER);
        $username = $headers['x-candygitserver-user'] ?? null;
        if (!\is_string($username) || $username === '') {
            return null;
        }

        $trusted = match ($this->config->userTrustMode) {
            'proxy' => $peerIp !== null
                && self::ipInAllowlist(self::normalizeIp($peerIp), $this->config->trustedProxies),
            'token' => $this->verifyUserToken($username, $headers),
            default => false, // 'off' — the header is never trusted.
        };

        if (!$trusted) {
            return null;
        }

        $user = $this->users[$username] ?? null;

        return $user !== null && $user->isActive ? $user : null;
    }

    /**
     * Verify the `X-CandyGitServer-User-Sig` header is a valid hex HMAC-SHA256
     * of the username keyed by the configured shared secret. Constant-time
     * comparison via hash_equals(); missing secret or signature fails closed.
     *
     * @param array<string, string> $headers
     */
    private function verifyUserToken(string $username, array $headers): bool
    {
        $secret = $this->config->authSecret;
        $headers = \array_change_key_case($headers, \CASE_LOWER);
        $sig = $headers['x-candygitserver-user-sig'] ?? '';
        if ($secret === '' || !\is_string($sig) || $sig === '') {
            return false;
        }

        $expected = \hash_hmac('sha256', $username, $secret);
        return \hash_equals($expected, $sig);
    }

    /**
     * Normalize a peer address to a bare IP: strips a `[v6]:port` bracket
     * form and an `ipv4:port` suffix (as ReactPHP/`stream_socket_get_name`
     * report), leaving a raw IPv4/IPv6 address untouched.
     */
    private static function normalizeIp(string $addr): string
    {
        $addr = \trim($addr);
        if ($addr === '') {
            return '';
        }
        if ($addr[0] === '[') { // [2001:db8::1]:443
            $end = \strpos($addr, ']');
            return $end === false ? $addr : \substr($addr, 1, $end - 1);
        }
        if (\substr_count($addr, ':') === 1) { // 127.0.0.1:8080 (not raw IPv6)
            return \substr($addr, 0, (int) \strpos($addr, ':'));
        }
        return $addr;
    }

    /**
     * Whether $ip falls within any allowlist entry (plain IP or CIDR range,
     * IPv4 and IPv6).
     *
     * @param list<string> $ranges
     */
    private static function ipInAllowlist(string $ip, array $ranges): bool
    {
        if ($ip === '') {
            return false;
        }
        foreach ($ranges as $range) {
            if (self::ipMatchesRange($ip, \trim((string) $range))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Match a bare IP against a single allowlist entry — an exact IP or a
     * `subnet/bits` CIDR range. Works for both IPv4 and IPv6 by comparing
     * the packed inet_pton() forms; a family mismatch never matches.
     */
    private static function ipMatchesRange(string $ip, string $range): bool
    {
        if ($range === '') {
            return false;
        }

        $bits = null;
        $subnet = $range;
        if (\str_contains($range, '/')) {
            [$subnet, $bitStr] = \explode('/', $range, 2);
            if (!\ctype_digit($bitStr)) {
                return false;
            }
            $bits = (int) $bitStr;
        }

        $ipBin = @\inet_pton($ip);
        $subnetBin = @\inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false
            || \strlen($ipBin) !== \strlen($subnetBin)) {
            return false; // malformed, or IPv4-vs-IPv6 family mismatch
        }

        if ($bits === null) {
            return \hash_equals($subnetBin, $ipBin);
        }

        $maxBits = \strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $whole = \intdiv($bits, 8);
        if ($whole > 0 && \substr($ipBin, 0, $whole) !== \substr($subnetBin, 0, $whole)) {
            return false;
        }

        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = 0xff << (8 - $rem) & 0xff;
        return (\ord($ipBin[$whole]) & $mask) === (\ord($subnetBin[$whole]) & $mask);
    }

    /**
     * Build an error response.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function errorResponse(int $status, string $message): array
    {
        $this->statusCode = $status;
        $this->responseHeaders['Content-Type'] = 'text/plain';
        $this->body = $message;

        return $this->finalizeResponse();
    }

    /**
     * Finalize the response array.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function finalizeResponse(): array
    {
        // Buffered responses are honest about their size: no handler sets
        // chunked framing (the old Transfer-Encoding header was a lie — the
        // listener never chunked anything). finalize owns the truth.
        if (!isset($this->responseHeaders['Content-Length'])) {
            $this->responseHeaders['Content-Length'] = (string) \strlen($this->body);
        }

        return [
            'status' => $this->statusCode,
            'headers' => $this->responseHeaders,
            'body' => $this->body,
        ];
    }
}
