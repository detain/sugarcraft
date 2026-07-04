<?php

declare(strict_types=1);

namespace SugarCraft\Serve\HttpSmartProtocol;

use SugarCraft\Serve\{AccessControl, Config, Repo, Stats, User};
use SugarCraft\Serve\Git\{UploadPack, ReceivePack};
use SugarCraft\Serve\LFS\LFSHandler;

/**
 * HTTP server that speaks the Git smart protocol.
 *
 * Handles Git clone/fetch/push over HTTP/1.1 using the smart protocol
 * (not the dumb HTTP transport). Supports both git-upload-pack and
 * git-receive-pack via POST requests.
 *
 * Smart HTTP protocol flow:
 * 1. Client GETs /info/refs?service=git-upload-pack  → advertises refs
 * 2. Client POSTs /git-upload-pack with wants           → exchanges pack data
 * 3. For push: Client POSTs /git-receive-pack          → sends pack, receives status
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
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handleRequest(
        string $method,
        string $path,
        string $query,
        array $headers,
        string $body,
    ): array {
        $this->path = $path;
        $this->method = $method;
        $this->query = $query;
        $this->responseHeaders = [];
        $this->statusCode = 200;
        $this->body = '';

        // Reset for each request
        $this->responseHeaders['Date'] = \gmdate('D, d M Y H:i:s') . ' GMT';
        $this->responseHeaders['Connection'] = 'close';
        $this->responseHeaders['Server'] = 'CandyServe/1.0';

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

        $repoPart = $parts[0];
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
            return $this->handleReceivePack($headers, $body);
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

        // Check read access for upload-pack, write for receive-pack
        // Note: Both services allow anonymous access to info/refs - auth
        // happens during the actual pack exchange, not the ref advertisement.
        $ac = AccessControl::getInstance();
        if ($service === 'git-upload-pack') {
            if (!$ac->canRead($this->getUserFromHeaders($headers), $repo)) {
                return $this->errorResponse(403, 'Access denied');
            }
        } else {
            // receive-pack info/refs is publicly accessible for ref discovery.
            // Actual push operation (POST) requires canWrite() auth.
        }

        // Build the advertisement packet
        $advertisement = $this->buildRefAdvertisement($repo, $service);

        $this->responseHeaders['Content-Type'] = 'application/x-' . $service . '-advisory';
        $this->responseHeaders['Cache-Control'] = 'no-cache';
        $this->responseHeaders['Transfer-Encoding'] = 'chunked';
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

        // Set up for streaming response
        $this->responseHeaders['Content-Type'] = 'application/x-git-upload-pack-result';
        $this->responseHeaders['Transfer-Encoding'] = 'chunked';

        // Delegate to git upload-pack --stateless-rpc for correct protocol handling
        $repoPath = \escapeshellarg($repo->path());
        $cmd = "git -C {$repoPath} upload-pack --stateless-rpc .";

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        /** @var resource|false $proc */
        $proc = \proc_open($cmd, $desc, $pipes);
        if ($proc === false) {
            return $this->errorResponse(500, 'Failed to start upload-pack');
        }

        // Write request body to stdin
        \fwrite($pipes[0], $body);
        \fclose($pipes[0]);

        // Read response into buffer (with size cap)
        $maxBytes = $this->config->maxPackBytes ?? 268435456; // 256 MiB default
        $packData = $this->readCapped($pipes[1], $maxBytes);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($proc);
        if ($packData === null) {
            return $this->errorResponse(413, 'Packfile too large');
        }

        $this->stats()->recordPackDownload();
        $this->body = $packData;
        return $this->finalizeResponse();
    }

    /**
     * Handle POST /{repo}/git-receive-pack
     *
     * The client sends a packfile containing the objects to push.
     */
    private function handleReceivePack(array $headers, string $body): array
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
        if (!$ac->canWrite($this->getUserFromHeaders($headers), $repo)) {
            return $this->errorResponse(403, 'Access denied');
        }

        // Set up for streaming response
        $this->responseHeaders['Content-Type'] = 'application/x-git-receive-pack-result';
        $this->responseHeaders['Transfer-Encoding'] = 'chunked';

        // Delegate to git receive-pack --stateless-rpc for correct protocol handling
        $repoPath = \escapeshellarg($repo->path());
        $cmd = "git -C {$repoPath} receive-pack --stateless-rpc .";

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        /** @var resource|false $proc */
        $proc = \proc_open($cmd, $desc, $pipes);
        if ($proc === false) {
            return $this->errorResponse(500, 'Failed to start receive-pack');
        }

        // Write request body (commands + packfile) to stdin
        \fwrite($pipes[0], $body);
        \fclose($pipes[0]);

        // Read response (with memory cap to prevent OOM on large packfiles)
        $maxBytes = $this->config->maxPackBytes ?? 268435456; // 256 MiB default
        $packData = $this->readCapped($pipes[1], $maxBytes);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($proc);
        if ($packData === null) {
            return $this->errorResponse(413, 'Packfile too large');
        }

        $this->stats()->recordPackUpload();
        $this->body = $packData;
        return $this->finalizeResponse();
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
        return $this->repos[$name] ?? null;
    }

    /**
     * Build ref advertisement packet.
     */
    private function buildRefAdvertisement(Repo $repo, string $service): string
    {
        $lines = [];
        $lines[] = '# service=' . $service;
        $lines[] = '';  // flush packet

        // Get refs
        $refs = $repo->refs();
        foreach ($refs as $ref => $hash) {
            $lines[] = "{$hash} {$ref}";
        }

        if ($refs === []) {
            // Empty repo - still need a flush
            $lines[] = '';
        }

        return $this->encodePktLines($lines);
    }

    /**
     * Generate pack data for upload-pack.
     */
    private function generatePackData(Repo $repo, string $requestBody): string
    {
        // Parse the request to get wanted refs
        $wants = $this->parseUploadPackRequest($requestBody);

        if ($wants === []) {
            return '';
        }

        $repoPath = \escapeshellarg($repo->path());
        $cmd = "git -C {$repoPath} pack-objects --stdout --revs 2>/dev/null";

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

        // Write want revs to stdin (one per line, no ^ prefix)
        foreach ($wants as $hash) {
            \fwrite($pipes[0], $hash . "\n");
        }
        \fclose($pipes[0]);

        // TODO: stream via chunked callback for true streaming; cap buffered size for now
        $maxBytes = $this->config->maxPackBytes ?? 268435456; // 256 MiB default
        $packData = $this->readCapped($pipes[1], $maxBytes);

        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($proc);
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
     * Process receive-pack push.
     */
    private function processReceivePack(Repo $repo, string $body): string
    {
        // Send refs advertisement
        $refs = $repo->refs();
        $lines = [];
        $caps = 'report-status delete-refs side-band-64k';
        $firstRef = \key($refs) ?: 'refs/heads/main';
        $firstHash = \current($refs) ?: \str_repeat('0', 40);
        $lines[] = "{$firstHash} {$firstRef}\x00 {$caps}";
        foreach ($refs as $ref => $hash) {
            if ($ref === $firstRef) continue;
            $lines[] = "{$hash} {$ref}";
        }
        $lines[] = '';  // flush

        // In a full implementation, we would receive the pack data and apply it
        // For now, just send the advertisement

        return $this->encodePktLines($lines);
    }

    /**
     * Encode array of lines as Git pkt-line format.
     *
     * @param list<string> $lines
     */
    private function encodePktLines(array $lines): string
    {
        $result = '';
        foreach ($lines as $line) {
            if ($line === '') {
                // Flush packet
                $result .= "0000";
            } else {
                $len = \strlen($line) + 4;
                $hex = \str_pad(\dechex($len), 4, '0', \STR_PAD_LEFT);
                $pktLen = \hex2bin($hex);
                if ($pktLen === false) {
                    throw new \RuntimeException('Invalid pkt-line length: ' . $hex);
                }
                $result .= $pktLen;
                $result .= $line . "\n";
            }
        }
        return $result;
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
        // Check Basic auth
        if (isset($headers['Authorization'])) {
            $auth = $headers['Authorization'];
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
                        return $user;
                    }
                }
            }
        }

        // Check for session/user headers (custom CandyServe headers)
        if (isset($headers['X-CandyServe-User'])) {
            return $this->users[$headers['X-CandyServe-User']] ?? null;
        }

        return null;
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
        return [
            'status' => $this->statusCode,
            'headers' => $this->responseHeaders,
            'body' => $this->body,
        ];
    }
}
