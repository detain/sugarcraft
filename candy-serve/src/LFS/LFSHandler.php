<?php

declare(strict_types=1);

namespace SugarCraft\Serve\LFS;

use SugarCraft\Serve\{AccessControl, Repo, User};

/**
 * Git LFS batch API handler.
 *
 * Handles LFS batch requests: upload and download of large files.
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

    public const MEDIA_TYPE = 'application/vnd.git-lfs+json';

    public function __construct(
        Repo $repo,
        ?User $user,
        string $lfsPath,
        ?LFSStorageBackendInterface $storageBackend = null,
        int $concurrentTransfers = 4,
    ) {
        $this->repo = $repo;
        $this->user = $user;
        $this->lfsPath = $lfsPath;
        $this->storageBackend = $storageBackend ?? new LocalStorageBackend($lfsPath);
        $this->concurrentTransfers = $concurrentTransfers;
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
        );
    }

    // -------------------------------------------------------------------------
    // Batch API
    // -------------------------------------------------------------------------

    /**
     * Handle an LFS batch request.
     *
     * POST https://server/repos/{name}.git/info/lfs/objects/batch
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
     */
    public function handleBatch(array $request): array
    {
        $ac = AccessControl::getInstance();

        if (!$ac->canRead($this->user, $this->repo)) {
            return ['error' => ['code' => 403, 'message' => 'Access denied']];
        }

        $operation = $request['operation'] ?? 'download';
        $objects   = $request['objects']   ?? [];

        // Process objects concurrently if multiple
        $results = $this->processObjectsConcurrently($operation, $objects);

        return [
            'transfer' => 'basic',
            'objects'  => $results,
        ];
    }

    /**
     * Process multiple LFS objects with concurrency control.
     *
     * @param list<array{oid:string,size:int}> $objects
     * @return list<array>
     */
    private function processObjectsConcurrently(string $operation, array $objects): array
    {
        if (\count($objects) <= 1 || $this->concurrentTransfers <= 1) {
            // Sequential processing for single objects or when concurrency is disabled
            return $this->processObjectsSequentially($operation, $objects);
        }

        // Process in batches with concurrency control
        $results = [];
        $batches = \array_chunk($objects, $this->concurrentTransfers);

        foreach ($batches as $batch) {
            $batchResults = $this->processBatch($operation, $batch);
            $results = \array_merge($results, $batchResults);
        }

        return $results;
    }

    /**
     * Process a batch of objects in parallel using pthreads or sequential fallback.
     *
     * @param list<array{oid:string,size:int}> $batch
     * @return list<array>
     */
    private function processBatch(string $operation, array $batch): array
    {
        // For now, use sequential processing since pthreads requires extension
        // In production, this could use ReactPHP promises or Swoole for true concurrency
        return $this->processObjectsSequentially($operation, $batch);
    }

    /**
     * Process objects sequentially.
     *
     * @param list<array{oid:string,size:int}> $objects
     * @return list<array>
     */
    private function processObjectsSequentially(string $operation, array $objects): array
    {
        $results = [];
        foreach ($objects as $obj) {
            $oid  = $obj['oid']  ?? '';
            $size = $obj['size'] ?? 0;
            $result = $this->handleObject($operation, $oid, (int) $size);
            $results[] = $result;
        }
        return $results;
    }

    /**
     * Handle a single LFS object in a batch.
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

        // Upload operation - prepare upload endpoint
        return [
            'oid'   => $oid,
            'size'  => $size,
            'actions' => [
                'upload' => [
                    'href'   => $this->objectUrl($oid),
                ],
            ],
        ];
    }

    /**
     * Get the URL for an LFS object.
     */
    private function objectUrl(string $oid): string
    {
        return '/repos/' . $this->repo->name . '/info/lfs/objects/' . $oid;
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
}
