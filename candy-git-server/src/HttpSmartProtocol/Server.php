<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\HttpSmartProtocol;

use SugarCraft\GitServer\{AccessControl, Config, Repo, Stats, User, UserStore};
use SugarCraft\GitServer\Git\{PktLine, ReceivePack, StatelessRpc, UploadPack};
use SugarCraft\GitServer\LFS\LFSHandler;

/**
 * HTTP server that speaks the Git smart protocol.
 *
 * Handles Git clone/fetch AND push over HTTP/1.1 using the smart protocol
 * (not the dumb HTTP transport). git-upload-pack is served end to end;
 * git-receive-pack POSTs run the full stateless negotiation behind the
 * canWrite authorization gate — an unauthenticated client meets the 401
 * challenge and an ungranted one the 403 refusal before any pack byte is
 * read (HttpPushTest pins a real push end to end).
 *
 * Smart HTTP protocol flow:
 * 1. Client GETs /info/refs?service=git-upload-pack  → advertises refs
 * 2. Client POSTs /git-upload-pack with wants           → exchanges pack data
 * 3. For push: Client POSTs /git-receive-pack          → sends pack, receives status
 *
 * CHILD-PROCESS LIFETIME: the live smart-HTTP children are spawned
 * inside Git\StatelessRpc (advertise + negotiate), whose bounded read +
 * TERM/KILL escalation ladder releases pipes and handle in a `finally` per
 * spawn site. This class itself spawns NOTHING: the DORMANT
 * generatePackData() shortcut — a no-caller pack-objects replica of the
 * route StatelessRpc::negotiate() already owns, along with its two private
 * helpers (the finding-071 "honest contract" cure, S14: remove, do not
 * re-wire dead code) — was deleted rather than maintained against a
 * docblock no request ever exercises. The short metadata commands (Repo
 * reads, ssh-keygen, mirror fetch) all run through Support\BoundedCommand,
 * which enforces the same deadline-and-reap contract synchronously.
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
    /**
     * A bcrypt digest whose preimage is random per process and never
     * stored: password_verify() against it always returns false, but it
     * costs the same work as a real verify. The Basic-auth refusal paths
     * for unknown users, wrong passwords and key-only accounts all run
     * through it so their timing cannot be compared to enumerate accounts.
     */
    private static ?string $basicAuthDecoyHash = null;

    /**
     * Process-scoped bcrypt memo for REAL digest checks (finding 048):
     * a connection's repeated Basic header stops paying 100-300 ms per
     * request. Static because a worker may serve several requests and
     * the serial fallback spans a whole daemon life; TTL bounds how long
     * a changed-out password can linger. The decoy burns deliberately
     * stay OUTSIDE it (uniform refusal timing is the enumeration fix).
     */
    private static ?PasswordVerifyCache $verifyCache = null;

    /**
     * Minted at the runtime PASSWORD_DEFAULT so the decoy burns exactly the
     * work the bin mints real credentials with, on any PHP. Dated 2026-11-11:
     * the previous hardcoded cost-10 digest fell out of alignment when PHP
     * 8.4 raised the bcrypt default cost to 12 — burning a quarter of the
     * work reopened the very enumeration oracle the decoy exists to close.
     * The preimage is fresh random per process and discarded, so no
     * password can ever verify; the digest is memoized so only the first
     * refusal pays the mint.
     */
    private static function basicAuthDecoyHash(): string
    {
        return self::$basicAuthDecoyHash
            ??= \password_hash(\bin2hex(\random_bytes(32)), \PASSWORD_DEFAULT);
    }

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

    /**
     * Failed-Basic-auth attempt ledger (finding 008), built on first use
     * from the parsed config. Lazy because construction must stay IO-free
     * (register-only Server instances never authenticate).
     */
    private ?AuthAttemptBudget $authBudget = null;

    /**
     * Live-store watch (finding 011, G-2 default): the users.json path set
     * by {@see watchUserStore()}, its last-successful-read composite stamp
     * (mtime + size), and the snapshot re-read from disk. The old shape
     * snapshotted accounts at boot only, so `user add` / `user deactivate`
     * / a password rotation stayed invisible until a full restart — while
     * a restart is exactly what a wedged-fork pool host avoids.
     *
     * Fail-closed on read error: a corrupt store holds the last-good
     * snapshot (and the stamp is NOT taken, so the next request retries);
     * the process never serves an auth-less server, but neither does a
     * mid-publish corrupt read revoke a known-good account. The composite
     * stamp (not mtime alone) is the atomic-rename tell: a same-second
     * republish still moves the size, and both transports' writers are
     * AtomicWriter renames, never in-place truncates.
     */
    private ?string $userStoreFile = null;
    private ?string $usersStamp = null;

    /** @var array<string, User> disk-snapshot accounts (instance registry wins) */
    private array $fileUsers = [];

    /** @var array<string, string> gitDir => last marker composite stamp */
    private array $markerStamps = [];

    /**
     * The access matrix THIS server consults (finding 032, G-6 minimal
     * instance-scope ruling): a per-instance AccessControl by default, so
     * a permissive GitDaemon or SSH co-tenant in the same process can no
     * longer pin a strict HTTP server open through the shared process-
     * global lever — and this class never WRITES the global posture
     * either. Embedders that want the old shared-posture world (one
     * policy per process, by choice and stated in the README) pass
     * {@see AccessControl::instance()} as the second constructor
     * argument. The anonymous-read arm is raise-only at construction:
     * this server's own config can open its posture, nothing the config
     * parses can close a posture its injected matrix already honoured.
     */
    private AccessControl $accessControl;

    public function __construct(Config $config, ?AccessControl $accessControl = null)
    {
        $this->config = $config;
        $this->accessControl = $accessControl ?? new AccessControl();
        if ($config->allowAnonymousRead) {
            $this->accessControl->setAllowAnonymousRead(true);
        }
    }

    /**
     * Runtime arm surface for THIS server's own matrix (fixtures and
     * embedders): raises or lowers only the instance posture, never the
     * process-global lever the daemon and SSH transports own.
     */
    public function setAllowAnonymousRead(bool $allowed): void
    {
        $this->accessControl->setAllowAnonymousRead($allowed);
    }

    /**
     * The matrix this server consults: hosts that want to share one
     * posture across transports pass the same object in at construction,
     * fixtures can flip the lever without reaching through the server.
     */
    public function accessControl(): AccessControl
    {
        return $this->accessControl;
    }

    /**
     * Arm the live account store watch: users are then resolved from this
     * file (re-checked cheaply per authenticated request) underneath the
     * instance registry. Pass the data directory holding users.json.
     */
    public function watchUserStore(string $dataPath): void
    {
        $this->userStoreFile = \rtrim($dataPath, '/') . '/' . UserStore::FILE_NAME;
        $this->usersStamp = null; // force the first read to prime the snapshot
    }

    /**
     * Cheap per-request diff of the watched store: one stat; only a stamp
     * change pays for a full parse. See the property docblock for the
     * fail-closed contract.
     */
    private function refreshUserStore(): void
    {
        $file = $this->userStoreFile;
        if ($file === null) {
            return;
        }
        @\clearstatcache(true, $file);
        $mtime = @\filemtime($file);
        $stamp = $mtime === false
            ? 'absent'
            : $mtime . ':' . (string) (@\filesize($file));
        if ($stamp === $this->usersStamp) {
            return;
        }

        try {
            $store = UserStore::forDataPath(\dirname($file));
        } catch (\RuntimeException $e) {
            // Corrupt/unsupported on disk: hold the last-good snapshot and
            // leave the stamp unclaimed so a repaired file is picked up by
            // the next request's diff.
            return;
        }

        $users = [];
        foreach ($store->all() as $user) {
            $users[$user->username] = $user;
        }
        $this->fileUsers = $users;
        $this->usersStamp = $stamp;
    }

    /**
     * Re-check one registered repo's visibility marker (finding 011's
     * marker half): a running server must stop serving a repo the moment
     * an operator un-publishes it, and open readability the moment one
     * publishes it — without a restart. One stat per request on the
     * resolved repo; the snapshot VO is replaced only on a real change.
     * A PRESENT marker is authoritative and reads fail-closed (an
     * unreadable or unrecognizable marker means PRIVATE, the same rule
     * the boot scan uses). A VANISHED or never-written marker defers to
     * the registered VO: programmatic registrations (embedders, tests)
     * carry in-memory truth the boot scan never minted from a marker,
     * and the CLI un-publish path rewrites the marker, never deletes it.
     */
    private function liveMarker(Repo $repo): Repo
    {
        $gitDir = $repo->gitDir();
        $marker = $gitDir . '/' . Repo::VISIBILITY_MARKER;
        @\clearstatcache(true, $marker);
        $mtime = @\filemtime($marker);
        $stamp = $mtime === false ? 'absent' : (string) $mtime;
        if (($this->markerStamps[$gitDir] ?? null) === $stamp) {
            return $repo;
        }
        $this->markerStamps[$gitDir] = $stamp;
        if ($stamp === 'absent') {
            return $repo; // marker is not the authority for this repo
        }

        $live = Repo::markerVisibility($gitDir);

        return $live === $repo->visibility
            ? $repo
            : $repo->withVisibility($live);
    }

    /** Inject a stats collector (default: the shared {@see Stats} instance). */
    public function setStats(Stats $stats): void
    {
        $this->stats = $stats;
    }

    private function stats(): Stats
    {
        return $this->stats ?? Stats::instance();
    }

    private function authBudget(): AuthAttemptBudget
    {
        return $this->authBudget ??= new AuthAttemptBudget(
            $this->config->dataPath . '/auth-attempts.json',
            $this->config->httpAuthMaxAttempts,
            $this->config->httpAuthRefillSeconds,
        );
    }

    private static function verifyCache(): PasswordVerifyCache
    {
        return self::$verifyCache ??= new PasswordVerifyCache();
    }

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Body/pack transfer default when `http.max_pack_bytes` is unset:
     * 256 MiB. ONE knob governs every layer that buffers a request body —
     * the socket listener (HttpListener reads it back through
     * maxRequestBodyBytes()) and each router cap below — so the two can
     * never disagree about what size is acceptable.
     */
    public const DEFAULT_MAX_BODY_BYTES = 268435456;

    /** Effective single-source request-body cap (config or the default). */
    public function maxRequestBodyBytes(): int
    {
        return $this->config->maxPackBytes ?? self::DEFAULT_MAX_BODY_BYTES;
    }

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
        } catch (AuthBudgetExhausted $e) {
            // The budget refused to pay for another bcrypt before any
            // handler ran; this is policy, not a fault — the 500 path
            // would log it to STDERR and lie to the client.
            $this->responseHeaders['Retry-After'] = (string) $e->retryAfterSeconds;

            return $this->errorResponse(429, 'Too many authentication attempts');
        } catch (\Throwable $e) {
            // Operator log line, not a frame dump (finding 095): the full
            // (string) $e trace carries every frame's arguments — query
            // strings, request bodies, absolute paths of the hosting
            // box — into whatever collects STDERR. Class, message and
            // the throw site locate the fault; the arguments of deeper
            // frames are evidence nobody asked to log.
            \fwrite(\STDERR, 'candy-git-server: request fault ' . \get_class($e)
                . ': ' . $e->getMessage()
                . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n");

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
        //
        // Everything BEFORE the service segment is the repository path:
        // hosting layouts namespace repos under an owner segment
        // (`/alice/widget.git/info/refs`), which the old flat
        // "second segment is the action" rule answered 404 for. The
        // dispatch below scans for the first service-shaped segment and
        // treats the prefix as the repo name; findRepo resolves the
        // deepest segment (owner prefixes are display, not identity —
        // matching how the scan registers repos by their directory name).

        if (\count($parts) < 2) {
            return $this->errorResponse(404, 'Not found');
        }

        $serviceIndex = null;
        foreach ($parts as $index => $segment) {
            if ($segment === 'info' || $segment === 'git-upload-pack' || $segment === 'git-receive-pack') {
                $serviceIndex = $index;
                break;
            }
        }

        if ($serviceIndex === null || $serviceIndex === 0) {
            return $this->errorResponse(404, 'Not found');
        }

        $repoName = \implode('/', \array_slice($parts, 0, $serviceIndex));
        $rest = \array_slice($parts, $serviceIndex);

        if ($rest[0] === 'info' && ($rest[1] ?? '') === 'refs' && \count($rest) === 2) {
            return $this->handleInfoRefs($repoName, $headers);
        }

        if ($rest[0] === 'info' && ($rest[1] ?? '') === 'lfs' && ($rest[2] ?? '') === 'objects' && \count($rest) >= 4) {
            return $this->handleLfs($rest, $repoName, $headers, $body);
        }

        if ($rest[0] === 'git-upload-pack' && \count($rest) === 1) {
            return $this->handleUploadPack($repoName, $headers, $body);
        }

        if ($rest[0] === 'git-receive-pack' && \count($rest) === 1) {
            return $this->handleReceivePack($repoName, $headers, $body);
        }

        return $this->errorResponse(404, 'Not found');
    }

    /**
     * Handle GET /{repo}/info/refs?service=git-upload-pack
     *
     * This advertises the available refs to the client.
     */
    private function handleInfoRefs(string $repoName, array $headers): array
    {
        // info/refs is a fetch-style GET; a POST here is a client bug or a
        // probe, not a negotiation.
        if ($this->method !== 'GET') {
            return $this->errorResponse(405, 'Use GET for info/refs');
        }

        $queryParams = $this->parseQuery($this->query);
        $service = $queryParams['service'] ?? '';

        if ($service !== 'git-upload-pack' && $service !== 'git-receive-pack') {
            return $this->errorResponse(400, 'Missing or invalid service parameter');
        }

        $repo = $this->findRepo($repoName);
        if ($repo === null) {
            return $this->errorResponse(404, 'Repository not found');
        }

        // Gate BEFORE any advertisement byte leaves the process: a client
        // with no valid identity is challenged (401 + WWW-Authenticate)
        // rather than told yes/no about the repo's policy — one uniform
        // answer for "who are you", so an anonymous probe cannot use the
        // 403-versus-advertisement difference to map push policy across
        // repos. Repo EXISTENCE is not hidden here: the router answers 404
        // for an unregistered repo earlier in this method — a disclosed
        // existence oracle for repo NAMES. USER existence is not a timing
        // channel: every Basic-auth refusal (unknown name, wrong password,
        // key-only account) pays the same bcrypt decoy inside
        // getUserFromHeaders() before answering. A known
        // identity that lacks the
        // right gets 403. Read advertisement rides canRead, push
        // advertisement rides canWrite and always requires an identity.
        // The access matrix consulted here is THIS server's own instance
        // (finding 032): its posture is armed from the parsed config at
        // construction and via setAllowAnonymousRead() — the process-
        // global lever the daemon and SSH transports share is never read
        // nor written on the HTTP path, so a permissive co-tenant can no
        // longer pin a strict listener open.
        $ac = $this->accessControl;
        $user = $this->getUserFromHeaders($headers);
        if ($service === 'git-upload-pack') {
            if (!$ac->canRead($user, $repo)) {
                return $this->authDenied($user);
            }
        } elseif (!$ac->canWrite($user, $repo)) {
            return $this->authDenied($user);
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
    private function handleUploadPack(string $repoName, array $headers, string $body): array
    {
        // The negotiation POST is the transfer; a GET here would spawn a
        // child against an empty body.
        if ($this->method !== 'POST') {
            return $this->errorResponse(405, 'Use POST for git-upload-pack');
        }

        $repo = $this->findRepo($repoName);
        if ($repo === null) {
            return $this->errorResponse(404, 'Repository not found');
        }

        $ac = $this->accessControl;
        $user = $this->getUserFromHeaders($headers);
        if (!$ac->canRead($user, $repo)) {
            return $this->authDenied($user);
        }

        // Delegate the whole negotiation to the real binary through the
        // argv-array stateless-rpc pump (StatelessRpc owns the E721
        // request-scoped reap: bounded write + bounded drain on one
        // deadline, bounded stderr tail logged on failure, TERM->KILL on
        // every path including success). git.max_timeout, when positive,
        // overrides the built-in negotiation ceiling (the constant
        // was not configurable).
        $maxBytes = $this->maxRequestBodyBytes();
        $deadline = $this->config->gitMaxTimeout > 0 ? (float) $this->config->gitMaxTimeout : null;
        $result = StatelessRpc::negotiate('git-upload-pack', $repo->path(), $body, $maxBytes, $deadline);

        // Typed outcome: overflow is 413, a wedged child is 504 —
        // answering 413 for a timeout told clients to send SMALLER
        // requests when nothing was too large.
        if ($result['reason'] === StatelessRpc::REASON_OVERFLOW) {
            return $this->errorResponse(413, 'Packfile too large');
        }
        if ($result['reason'] === StatelessRpc::REASON_TIMEOUT) {
            return $this->errorResponse(504, 'Negotiation timed out');
        }

        $this->stats()->recordPackDownload();
        $this->responseHeaders['Content-Type'] = 'application/x-git-upload-pack-result';
        $this->body = $result['bytes'];
        return $this->finalizeResponse();
    }

    /**
     * Handle POST /{repo}/git-receive-pack — the push transfer.
     *
     * Relays the request body to a real `git receive-pack --stateless-rpc`
     * child through the same bounded pump the read path uses
     * (StatelessRpc::negotiate): object-then-ref ordering, refname
     * validation and packfile indexing are git's own, so a push either
     * lands with its objects or does not land. Hook declines ride
     * IN-BAND (report-status ng packets inside a 200 body, exactly what
     * git clients parse); only a child that dies without producing any
     * answer is reported as 500. The receive-pack ADVERTISEMENT
     * (info/refs?service=git-receive-pack) stays gated above.
     */
    private function handleReceivePack(string $repoName, array $headers, string $body): array
    {
        if ($this->method !== 'POST') {
            return $this->errorResponse(405, 'Use POST for git-receive-pack');
        }

        $repo = $this->findRepo($repoName);
        if ($repo === null) {
            return $this->errorResponse(404, 'Repository not found');
        }

        $ac = $this->accessControl;
        $user = $this->getUserFromHeaders($headers);
        if (!$ac->canWrite($user, $repo)) {
            return $this->authDenied($user);
        }

        $maxBytes = $this->maxRequestBodyBytes();
        $deadline = $this->config->gitMaxTimeout > 0 ? (float) $this->config->gitMaxTimeout : null;
        $result = StatelessRpc::negotiate('git-receive-pack', $repo->path(), $body, $maxBytes, $deadline);

        if ($result['reason'] === StatelessRpc::REASON_OVERFLOW) {
            return $this->errorResponse(413, 'Packfile too large');
        }
        if ($result['reason'] === StatelessRpc::REASON_TIMEOUT) {
            return $this->errorResponse(504, 'Negotiation timed out');
        }
        if ($result['bytes'] === '') {
            // The pump already wrote the child's bounded stderr tail to
            // the operator log; the client gets a bare failure status.
            return $this->errorResponse(500, 'Receive-pack failed');
        }

        $this->stats()->recordPackUpload();
        $this->responseHeaders['Content-Type'] = 'application/x-git-receive-pack-result';
        $this->body = $result['bytes'];

        return $this->finalizeResponse();
    }

    // -------------------------------------------------------------------------
    // Git LFS routes
    // -------------------------------------------------------------------------

    /**
     * Dispatch /{repo}/info/lfs/objects/... requests.
     *
     * @param list<string> $parts Service-relative segments (starts at 'info')
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function handleLfs(array $parts, string $repoName, array $headers, string $body): array
    {
        if (!$this->config->lfsEnabled) {
            return $this->errorResponse(404, 'Git LFS is disabled');
        }

        $repo = $this->findRepo($repoName);
        if ($repo === null) {
            return $this->errorResponse(404, 'Repository not found');
        }

        if (($parts[2] ?? '') !== 'objects') {
            return $this->errorResponse(404, 'Not found');
        }

        $user = $this->getUserFromHeaders($headers);
        $handler = new LFSHandler(
            $repo,
            $user,
            $this->config->lfsPath(),
            stats: $this->stats,
            maxObjectBytes: (int) ($this->config->maxPackBytes ?? LFSHandler::MAX_OBJECT_BYTES),
            publicBaseUrl: $this->config->httpPublicUrl,
            accessControl: $this->accessControl,
        );

        $target = $parts[3] ?? '';
        if ($target === 'batch') {
            return $this->handleLfsBatch($repo, $handler, $user, $body);
        }

        // Object routes: the OID is always the full lowercase SHA-256 hex.
        if (\preg_match('/^[0-9a-f]{64}$/', $target) !== 1) {
            return $this->errorResponse(400, 'Invalid LFS object ID');
        }

        if (($parts[4] ?? '') === 'verify') {
            return $this->handleLfsVerify($repo, $handler, $user, $target, $body);
        }
        if (isset($parts[4])) {
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
     * writes for `upload` operations. The boundary answers with real HTTP
     * statuses (401 challenges so git-lfs prompts); the handler applies
     * the SAME predicates again for direct callers, so no invocation path
     * skips the gate.
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

        $ac = $this->accessControl;
        if (($request['operation'] ?? 'download') === 'upload') {
            if (!$ac->canWrite($user, $repo)) {
                return $this->authDenied($user);
            }
        } elseif (!$ac->canRead($user, $repo)) {
            // Challenge (401) before the JSON body is examined any further:
            // git-lfs clients only prompt for credentials on 401.
            return $this->authDenied($user);
        }

        // Transfers the client insists on that we do not speak are refused
        // at the boundary too (the handler mirrors this predicate).
        $transfers = $request['transfers'] ?? null;
        if (\is_array($transfers) && !\in_array('basic', $transfers, true)) {
            return $this->errorResponse(400, 'Unsupported transfer type');
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
        if (!$this->accessControl->canRead($user, $repo)) {
            return $this->authDenied($user);
        }

        $backend = $handler->storageBackend();
        if (!$backend->exists($oid)) {
            return $this->errorResponse(404, 'Object not found');
        }

        // The object is content-addressed and lands atomically, so its
        // stat size is stable: refuse to MATERIALIZE anything past the
        // transfer cap — readAll() of a hostile/huge object must not be
        // the thing that discovers the limit. Verified again after the
        // read (trust-but-verify the stat under a benign race).
        $cap = $this->maxRequestBodyBytes();
        if ($backend->size($oid) > $cap) {
            return $this->errorResponse(413, 'Object too large');
        }

        $this->stats()->recordLfsDownload();

        $this->responseHeaders['Content-Type'] = 'application/octet-stream';
        $this->body = $backend->readAll($oid);
        if (\strlen($this->body) > $cap) {
            return $this->errorResponse(413, 'Object too large');
        }
        $this->responseHeaders['Content-Length'] = (string) \strlen($this->body);

        return $this->finalizeResponse();
    }

    /**
     * PUT /{repo}/info/lfs/objects/{oid} — upload an object.
     *
     * Enforces the single-source transfer cap (http.max_pack_bytes, the
     * same knob that bounds the socket buffer and buffered packfiles;
     * {@see self::DEFAULT_MAX_BODY_BYTES} when unset) and verifies the
     * body's SHA-256 matches the OID before anything touches storage.
     */
    private function handleLfsUpload(Repo $repo, LFSHandler $handler, ?User $user, string $oid, string $body): array
    {
        if (!$this->accessControl->canWrite($user, $repo)) {
            return $this->authDenied($user);
        }

        $maxBytes = $this->maxRequestBodyBytes();
        if (\strlen($body) > $maxBytes) {
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

        if (!$this->accessControl->canWrite($user, $repo)) {
            return $this->authDenied($user);
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
     * Find a repo by name.
     *
     * Accepts the spellings real clients produce: the registered name,
     * with or without the `.git` suffix, and — since owner-namespaced
     * URLs — the deepest path segment of a longer name (the owner prefix
     * is display, not identity: hosting registers repos by directory
     * name). First hit wins.
     */
    private function findRepo(string $name): ?Repo
    {
        $candidates = [$name];
        if (\str_contains($name, '/')) {
            $candidates[] = \basename($name);
        }

        foreach ($candidates as $candidate) {
            $repo = $this->repos[$candidate] ?? null;
            if ($repo !== null) {
                return $this->repos[$candidate] = $this->liveMarker($repo);
            }

            if (\str_ends_with($candidate, '.git')) {
                $repo = $this->repos[\substr($candidate, 0, -4)] ?? null;
            } else {
                $repo = $this->repos[$candidate . '.git'] ?? null;
            }

            if ($repo !== null) {
                return $this->liveMarker($repo);
            }
        }

        return null;
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
     * Looks for Basic auth or session headers. Every Basic-auth refusal
     * path performs one bcrypt verify — against the real digest, or against
     * the decoy when no digest can match — so reply latency never reveals
     * whether a username exists. That deliberate uniform cost is exactly
     * what makes unbounded attempts a CPU amplifier (finding 008), so the
     * per-peer + per-username {@see AuthAttemptBudget} is charged BEFORE
     * any hashing: an exhausted bucket throws {@see AuthBudgetExhausted}
     * (answered 429 + Retry-After by handleRequest) at zero bcrypt cost,
     * and a verify that actually succeeded refunds its charge so honest
     * clients never drain themselves.
     */
    private function getUserFromHeaders(array $headers): ?User
    {
        // HTTP header names are case-insensitive on the wire (git sends
        // lowercase); canonicalize once at the trust boundary.
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
                        // The gate runs BEFORE every bcrypt below: the
                        // whole point of the budget is that a refused
                        // attempt must be free.
                        $peer = $this->peerIp === null ? '' : self::normalizeIp($this->peerIp);
                        $retryAfter = $this->authBudget()->charge($peer, $username);
                        if ($retryAfter !== null) {
                            throw new AuthBudgetExhausted($retryAfter);
                        }
                        $this->refreshUserStore();
                        $user = $this->users[$username] ?? $this->fileUsers[$username] ?? null;
                        if ($user === null) {
                            // The old shape returned HERE, before any
                            // password_verify() — and a bcrypt-less reply is
                            // measurably faster, so response latency alone
                            // answered "does this username exist?". Burn the
                            // same default-cost work against a decoy digest
                            // whose preimage nobody knows, then refuse:
                            // unknown names, wrong passwords and key-only
                            // accounts all pay bcrypt and all return null.
                            \password_verify($password, self::basicAuthDecoyHash());
                            return null;
                        }
                        // Passwords authenticate against a stored hash only.
                        // An account with no password hash (key-only account)
                        // cannot Basic-authenticate; there is no cleartext
                        // fallback — the old comparison read the plaintext
                        // field straight off the model. The decoy burn keeps
                        // that refusal timing-indistinguishable too.
                        $hash = $user->passwordHash;
                        if ($hash === null || $hash === '') {
                            \password_verify($password, self::basicAuthDecoyHash());
                            return null;
                        }
                        // The memoed verify (finding 048): first sight of a
                        // credential pays bcrypt, the connection's repeats
                        // do not. On a hit the $hash byte never re-verifies.
                        if (!self::verifyCache()->verify($password, $hash, $username)) {
                            return null;
                        }
                        // The verify succeeded — honest work paid for, so
                        // the token goes back. A long clone session re-sends
                        // Basic auth on every request and must never
                        // rate-limit itself (finding 008).
                        $this->authBudget()->refund($peer, $username);
                        // A deactivated account authenticates to nothing:
                        // credentials out,
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
     * Registry lookup fallback: an optional read-only surface for hosts
     * that graft a user registry onto the shared AccessControl (guarded
     * by method_exists — the shipped class has none, so the fallback
     * reads null). This is a REGISTRY read, deliberately global: an
     * embedding host that registers accounts on the process matrix
     * expects them visible. The POSTURE half of that matrix is never
     * consulted from here; the HTTP path owns its posture per instance.
     */
    private function lookupGlobalUser(string $username): ?User
    {
        $ac = AccessControl::instance();

        return method_exists($ac, 'getUser') ? $ac->getUser($username) : null;
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

        // Instance registry first (the registered accounts of THIS
        // server), then the watched store's live snapshot, with the
        // process-global registry as last fallback — mirrors how repos
        // resolve. The old headless singleton-only read could never see
        // accounts registered on the server instance.
        $this->refreshUserStore();
        $user = $this->users[$username]
            ?? $this->fileUsers[$username]
            ?? $this->lookupGlobalUser($username);

        return $user !== null && $user->isActive ? $user : null;
    }

    /**
     * Mint the wire value for `X-CandyGitServer-User-Sig`: an unix expiry
     * and an HMAC-SHA256 over `username NL expiry`, joined by a dot.
     * Shared by the CLI (`user token`) and the verifier so minted and
     * checked shapes cannot drift apart.
     */
    public static function mintUserToken(string $username, int $expiry, string $secret): string
    {
        return $expiry . '.' . \hash_hmac('sha256', $username . "\n" . $expiry, $secret);
    }

    /**
     * Verify the `X-CandyGitServer-User-Sig` header against the shared
     * secret. The signature must carry an unexpired expiry and a matching
     * HMAC; comparison is constant-time via hash_equals(). Missing secret
     * or signature fails closed.
     *
     * The OLD shape signed the username ALONE: once leaked it was an
     * immortal bearer credential — a deactivated or deleted user stayed
     * readable forever through the trusted-proxy token mode. Expired
     * grants are refused, so leaks have a bounded life, and the old
     * username-only hex cannot validate under the new grammar (the
     * expiry field must be digits, and the HMAC covers a different
     * message), which structurally revokes every pre-hardening token.
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

        $bits = \explode('.', $sig, 2);
        if (\count($bits) !== 2 || !\ctype_digit($bits[0])) {
            return false;
        }

        $expiry = (int) $bits[0];
        if ($expiry < \time()) {
            return false;
        }

        return \hash_equals(self::mintUserToken($username, $expiry, $secret), $sig);
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
     * Deny an access-control failure with the right HTTP semantics: no
     * valid identity → 401 with a Basic challenge (so real git clients
     * prompt and retry); a known identity lacking permission → 403.
     */
    private function authDenied(?User $user): array
    {
        if ($user === null) {
            $this->responseHeaders['WWW-Authenticate'] = 'Basic realm="candy-git-server"';
            return $this->errorResponse(401, 'Authentication required');
        }

        return $this->errorResponse(403, 'Access denied');
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
