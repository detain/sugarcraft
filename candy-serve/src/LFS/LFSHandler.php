<?php

declare(strict_types=1);

namespace CandyCore\Serve\LFS;

use CandyCore\Serve\{AccessControl, Repo, User};

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
    private string $lfsPath;

    public const MEDIA_TYPE = 'application/vnd.git-lfs+json';

    public function __construct(Repo $repo, ?User $user, string $lfsPath)
    {
        $this->repo    = $repo;
        $this->user    = $user;
        $this->lfsPath = $lfsPath;
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

        $results = [];
        foreach ($objects as $obj) {
            $oid  = $obj['oid']  ?? '';
            $size = $obj['size'] ?? 0;

            $result = $this->handleObject($operation, $oid, (int) $size);
            $results[] = $result;
        }

        return [
            'transfer' => 'basic',
            'objects'  => $results,
        ];
    }

    /**
     * Handle a single LFS object in a batch.
     */
    private function handleObject(string $operation, string $oid, int $size): array
    {
        $lfsFile = $this->lfsPath . '/' . \substr($oid, 0, 2) . '/' . \substr($oid, 2, 2) . '/' . $oid;

        if ($operation === 'download') {
            if (!\file_exists($lfsFile)) {
                return [
                    'oid'   => $oid,
                    'size'  => $size,
                    'error' => ['code' => 404, 'message' => 'Object not found'],
                ];
            }

            return [
                'oid'   => $oid,
                'size'  => $size,
                'actions' => [
                    'download' => [
                        'href'   => $this->objectUrl($oid),
                        'header' => ['Authorization' => 'Bearer lfs-token'],
                    ],
                ],
            ];
        }

        // Upload operation
        if (!\is_dir(\dirname($lfsFile))) {
            \mkdir(\dirname($lfsFile), 0755, true);
        }

        return [
            'oid'   => $oid,
            'size'  => $size,
            'actions' => [
                'upload' => [
                    'href'   => $this->objectUrl($oid),
                    'header' => ['Authorization' => 'Bearer lfs-token'],
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
}
