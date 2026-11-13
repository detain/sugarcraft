<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\LFS;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use SugarCraft\GitServer\Support\PromisePool;
use SugarCraft\GitServer\{AccessControl, Repo, Stats, User};

use function React\Promise\resolve;

/**
 * Git LFS batch API handler.
 *
 * Handles LFS batch requests: upload and download of large files.
 *
 * Both transport surfaces that reach this class (the smart-HTTP router and
 * direct embedding) share ONE set of predicates: the operation must be
 * 'download' or 'upload', upload additionally requires write access, only
 * the 'basic' transfer scheme is advertised, and every object entry is
 * validated (64-hex oid, non-negative bounded size) BEFORE storage is
 * touched — malformed entries answer with a per-object error and never
 * probe, read, or write the backend.
 *
 * Port of charmbracelet/soft-serve LFSHandler.
 *
 * @see https://github.com/charmbracelet/soft-serve
 */
final class LFSHandler
{
    private Repo $repo;
    private ?User $user;
    private ?LFSStorageBackendInterface $storageBackend;
    private string $lfsPath;
    private int $concurrentTransfers;
    private ?Stats $stats;
    private int $maxObjectBytes;
    private string $publicBaseUrl;
    private AccessControl $accessControl;

    public const MEDIA_TYPE = 'application/vnd.git-lfs+json';

    /** Operations this server implements; anything else is a client error. */
    public const SUPPORTED_OPERATIONS = ['download', 'upload'];

    /** Object size ceiling when the deployment does not configure one. */
    public const MAX_OBJECT_BYTES = 268435456;

    public function __construct(
        Repo $repo,
        ?User $user,
        string $lfsPath,
        ?LFSStorageBackendInterface $storageBackend = null,
        int $concurrentTransfers = 4,
        ?Stats $stats = null,
        ?int $maxObjectBytes = null,
        string $publicBaseUrl = 'http://localhost:23232',
        ?AccessControl $accessControl = null,
    ) {
        $this->repo = $repo;
        $this->user = $user;
        $this->lfsPath = $lfsPath;
        $this->storageBackend = $storageBackend ?? new LocalStorageBackend($lfsPath);
        $this->concurrentTransfers = $concurrentTransfers;
        $this->stats = $stats;
        $this->maxObjectBytes = \max(1, $maxObjectBytes ?? self::MAX_OBJECT_BYTES);
        $this->publicBaseUrl = $publicBaseUrl;
        // Finding 032: the batch gate is the SECOND enforcement site for a
        // request the HTTP server already checked — it must consult the
        // same matrix that admitted the connection, not assume the
        // process-global one. Standalone construction (embedders, fixtures
        // that opt into one-posture-per-process) keeps the global default.
        $this->accessControl = $accessControl ?? AccessControl::instance();
    }

    /**
     * Create with a custom storage backend.
     */
    public function withStorageBackend(LFSStorageBackendInterface $backend): self
    {
        return new self(
            repo: $this->repo,
            user: $this->user,
            lfsPath: $this->lfsPath,
            storageBackend: $backend,
            concurrentTransfers: $this->concurrentTransfers,
            stats: $this->stats,
            maxObjectBytes: $this->maxObjectBytes,
            publicBaseUrl: $this->publicBaseUrl,
            accessControl: $this->accessControl,
        );
    }

    /**
     * Create with concurrent transfer limit.
     */
    public function withConcurrentTransfers(int $count): self
    {
        return new self(
            repo: $this->repo,
            user: $this->user,
            lfsPath: $this->lfsPath,
            storageBackend: $this->storageBackend,
            concurrentTransfers: $count,
            stats: $this->stats,
            maxObjectBytes: $this->maxObjectBytes,
            publicBaseUrl: $this->publicBaseUrl,
            accessControl: $this->accessControl,
        );
    }

    // -------------------------------------------------------------------------
    // Batch API
    // -------------------------------------------------------------------------

    /**
     * An LFS object id is a sha256 hex digest — 64 lowercase hex characters.
     * Anything else (path-shaped, oversized, non-hex) must never reach the
     * storage layer, whose object paths are derived from the id.
     */
    public static function isValidOid(string $oid): bool
    {
        return \preg_match('/^[0-9a-f]{64}$/', $oid) === 1;
    }

    /**
     * Validate one batch entry, then run it through $handle.
     *
     * Shared by every batch walk so the predicates cannot drift: malformed
     * entries answer with the spec's per-object error shape (code 400,
     * empty oid — attacker bytes are never echoed back into the response)
     * and the storage backend is never touched.
     *
     * @param callable(string,int):array $handle receives a validated oid and size
     */
    private function objectOutcome(mixed $entry, callable $handle): array
    {
        if (!\is_array($entry)) {
            return self::invalidObject();
        }

        $oid = $entry['oid'] ?? null;
        if (!\is_string($oid) || !self::isValidOid($oid)) {
            return self::invalidObject();
        }

        $size = $entry['size'] ?? null;
        $sizeOk = \is_int($size)
            ? $size >= 0 && $size <= $this->maxObjectBytes
            : (\is_string($size) && \ctype_digit($size) && (int) $size <= $this->maxObjectBytes);
        if (!$sizeOk) {
            return self::invalidObject();
        }

        return $handle($oid, (int) $size);
    }

    /**
     * The per-object error entry for a malformed specification.
     */
    private static function invalidObject(): array
    {
        return [
            'oid'  => '',
            'size' => 0,
            'error' => ['code' => 400, 'message' => 'Invalid object specification'],
        ];
    }

    /**
     * Gate a batch request: operation allowlist, operation-appropriate
     * access, and transfer-scheme support.
     *
     * @return array{0:string,1:?array} [operation, error] — error is null
     *                                  when the request may proceed
     */
    private function gateBatch(array $request): array
    {
        $operation = $request['operation'] ?? 'download';
        if (!\is_string($operation) || !\in_array($operation, self::SUPPORTED_OPERATIONS, true)) {
            return ['download', ['error' => ['code' => 400, 'message' => 'Unsupported operation']]];
        }

        $ac = $this->accessControl;
        $allowed = $operation === 'upload'
            ? $ac->canWrite($this->user, $this->repo)
            : $ac->canRead($this->user, $this->repo);
        if (!$allowed) {
            return [$operation, ['error' => ['code' => 403, 'message' => 'Access denied']]];
        }

        $transfers = $request['transfers'] ?? null;
        if (\is_array($transfers) && !\in_array('basic', $transfers, true)) {
            return [$operation, ['error' => ['code' => 400, 'message' => 'Unsupported transfer type']]];
        }

        return [$operation, null];
    }

    /**
     * Handle an LFS batch request.
     *
     * POST https://server/{repo}.git/info/lfs/objects/batch
     *
     * Request body:
     * {
     *   "operation": "download" | "upload",
     *   "transfers": ["basic"],
     *   "objects": [{ "oid": "<sha256>", "size": <bytes> }, ...]
     * }
     *
     * Response body:
     * {
     *   "transfer": "basic",
     *   "objects": [
     *     {
     *       "oid": "...",
     *       "size": ...,
     *       "actions": {
     *         "download": { "href": "https://..." },
     *         "upload":  { "href": "https://...", "header": { "Authorization": "..." } }
     *       },
     *       "error": { "code": 404, "message": "..." }
     *     }
     *   ]
     * }
     *
     * Href schemes follow the configured http.public_url (https appears only
     * when the deployment sits behind an external TLS terminator — this
     * server refuses to terminate TLS itself).
     */
    public function handleBatch(array $request): array
    {
        ($this->stats ?? Stats::instance())->recordLfsBatch();

        [$operation, $error] = $this->gateBatch($request);
        if ($error !== null) {
            return $error;
        }

        $results = [];
        foreach (($request['objects'] ?? []) as $entry) {
            $results[] = $this->objectOutcome(
                $entry,
                fn (string $oid, int $size): array => $this->safeObject($operation, $oid, $size),
            );
        }

        return [
            'transfer' => 'basic',
            'objects'  => $results,
        ];
    }

    /**
     * Handle an LFS batch request on a ReactPHP event loop with bounded
     * concurrency — the opt-in async counterpart to {@see handleBatch()}.
     * Gates and per-entry validation run synchronously through the SAME
     * predicates first, so the two paths can never disagree; at most
     * `$concurrentTransfers` validated objects are in flight at once (via
     * {@see PromisePool}), and the resolved payload is byte-identical to
     * what the sequential path returns, including per-object error
     * entries (400 malformed, 404 missing). A storage-backend exception
     * on one object never sinks the batch: it is captured as a GENERIC
     * code-500 error entry for that object (the exception detail goes to
     * the server error log, never onto the wire) and the remaining
     * objects still resolve.
     *
     * What is and is not concurrent: per-object storage work
     * (`exists()`/`size()`) is synchronous file I/O that executes inside
     * its scheduled loop tick — the file I/O itself is NOT async. What
     * the loop buys is scheduling: a large batch is spread across ticks
     * in bounded waves instead of monopolising the caller until every
     * object is inspected, and timers/sockets on the same loop keep
     * firing between objects. A promise-returning storage backend could
     * plug into the same pool for true I/O concurrency later.
     *
     * @param array $request Same shape as handleBatch()
     * @param LoopInterface|null $loop Event loop (defaults to the global loop)
     * @return PromiseInterface<array> resolves with the handleBatch() response shape
     */
    public function handleBatchAsync(array $request, ?LoopInterface $loop = null): PromiseInterface
    {
        ($this->stats ?? Stats::instance())->recordLfsBatch();

        [$operation, $error] = $this->gateBatch($request);
        if ($error !== null) {
            return resolve($error);
        }

        // Validate every entry first (sync, identical predicates), then
        // spread only the VALIDATED objects across bounded async waves and
        // stitch results back to their original positions.
        $entries = \array_values($request['objects'] ?? []);
        $results = \array_fill(0, \count($entries), null);
        $pending = [];
        foreach ($entries as $index => $entry) {
            $outcome = $this->objectOutcome($entry, static fn (): array => []);
            if ($outcome !== []) {
                $results[$index] = $outcome; // malformed: error entry, no work
                continue;
            }
            $oid = (string) $entry['oid'];
            $size = (int) ($entry['size'] ?? 0);
            $pending[$index] = [$oid, $size];
        }

        if ($pending === []) {
            return resolve(['transfer' => 'basic', 'objects' => $results]);
        }

        $loop ??= Loop::get();
        $indexes = \array_keys($pending);

        return PromisePool::map(
            $loop,
            \array_values($pending),
            fn (array $pair): array => $this->safeObject($operation, $pair[0], $pair[1]),
            \max(1, $this->concurrentTransfers),
        )->then(static function (array $handled) use ($indexes, $results): array {
            foreach ($indexes as $slot => $index) {
                $results[$index] = $handled[$slot];
            }

            return ['transfer' => 'basic', 'objects' => $results];
        });
    }

    /**
     * handleObject with per-object failure isolation: a storage-backend
     * exception answers with a GENERIC code-500 error entry for that
     * object, never a thrown walk (the async batch is promise-resolved,
     * the sync one must not throw either) and never the raw exception
     * text — the detail goes to the server error log only, since a
     * backend message can carry an absolute filesystem path.
     */
    private function safeObject(string $operation, string $oid, int $size): array
    {
        try {
            return $this->handleObject($operation, $oid, $size);
        } catch (\Throwable $e) {
            // The exception detail (which may embed an absolute object
            // directory) is a server-side diagnostic; it never crosses
            // the wire, where clients get only the generic 500.
            \error_log(\sprintf(
                'candy-git-server: LFS %s object %s failed: %s: %s',
                $operation,
                $oid,
                \get_class($e),
                $e->getMessage(),
            ));

            return [
                'oid'   => $oid,
                'size'  => $size,
                'error' => ['code' => 500, 'message' => 'Internal storage error'],
            ];
        }
    }

    /**
     * Handle a single validated LFS object in a batch.
     */
    private function handleObject(string $operation, string $oid, int $size): array
    {
        $backend = $this->storageBackend;

        if ($operation === 'download') {
            if (!$backend->exists($oid)) {
                return [
                    'oid'   => $oid,
                    'size'  => $size,
                    'error' => ['code' => 404, 'message' => 'Object not found'],
                ];
            }

            return [
                'oid'   => $oid,
                'size'  => $backend->size($oid),
                'actions' => [
                    'download' => [
                        'href'   => $this->objectUrl($oid),
                    ],
                ],
            ];
        }

        // Upload operation - prepare upload + post-upload verify endpoints
        return [
            'oid'   => $oid,
            'size'  => $size,
            'actions' => [
                'upload' => [
                    'href'   => $this->objectUrl($oid),
                ],
                'verify' => [
                    'href'   => $this->objectUrl($oid) . '/verify',
                ],
            ],
        ];
    }

    /**
     * The absolute URL for an LFS object route.
     *
     * Built from the configured http.public_url base (the deployment's
     * canonical address — clients may resolve it through proxies where a
     * relative link would be rewritten or refused) and URL-encodes the
     * repository name. Matches the route shapes the smart-HTTP server
     * actually dispatches: `/{repo}/info/lfs/objects/{oid}`.
     */
    private function objectUrl(string $oid): string
    {
        $base = $this->publicBaseUrl !== '' ? \rtrim($this->publicBaseUrl, '/') : '';

        return $base . '/' . \rawurlencode($this->repo->name) . '/info/lfs/objects/' . $oid;
    }

    /**
     * Get the storage backend being used.
     */
    public function storageBackend(): LFSStorageBackendInterface
    {
        return $this->storageBackend;
    }

    /**
     * Get the concurrent transfer limit.
     */
    public function concurrentTransfers(): int
    {
        return $this->concurrentTransfers;
    }

    /**
     * The configured per-object size ceiling.
     */
    public function maxObjectBytes(): int
    {
        return $this->maxObjectBytes;
    }
}
