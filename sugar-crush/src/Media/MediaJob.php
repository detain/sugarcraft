<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media;

use DomainException;
use InvalidArgumentException;
use SugarCraft\Core\Concerns\Mutable;

/**
 * One media generation job's bookkeeping record — plan W1.2.
 *
 * The same skeleton serves both worlds the plan targets (W1.2 goal): an A1111
 * sdapi txt2img call is effectively a one-step job (POST, wait, done — the
 * "running" window is the HTTP request), while SGLang-Diffusion video jobs run
 * minutes with a pollable task id. Nothing here assumes either: the state
 * machine, the dual task-id fields and the bounded progress ring are the
 * union of what both dialects need.
 *
 * Correlation law (mystage §1.3 via plan build step 3): the server's task id
 * is stored OPAQUELY (serverTaskId — sdapi returns 'task(image-XXXXXXX)',
 * other backends return whatever they return) alongside OUR OWN job uuid
 * (jobId). All crush-side books — checkpoints, transcripts, artifact
 * annotations — key on jobId; the server id is only ever an outbound dial
 * argument. A server that forgets its tasks never invalidates our history.
 *
 * Immutable + fluent per house law: every with*() returns a new instance via
 * the candy-core Mutable trait; the state machine guard lives in
 * withStatus() and throws rather than silently mis-ordering a transition.
 *
 * Honesty note (plan Q-3): no cost/billing fields ride the job — spend
 * accounting lives in the existing Chat/Usage books, and minting a second,
 * un-sourced cost column here would drift from them.
 */
final readonly class MediaJob
{
    use Mutable;

    /**
     * Progress ring capacity. Mirrors the sdapi pending_tasks window crush_media
     * §4.6 observed (the progress endpoint keeps ~20 recent task entries); a
     * longer history belongs to checkpoints (W4.5), not to a live job record.
     */
    public const PROGRESS_RING = 20;

    /**
     * Legal transitions (plan W1.2 state machine). Terminal states hold an
     * empty list — once a job is done/failed/cancelled it never moves, and
     * even a same-status "re-entry" is refused so a buggy poller cannot mask
     * a double-settle by "refreshing" the terminal stamp.
     *
     * Keyed by status VALUE strings — PHP arrays cannot take enum cases as
     * offset keys in constant expressions (TypeError "Cannot access offset of
     * type ... on array"), so the wire string is the map's natural key.
     *
     * @var array<string, list<MediaJobStatus>>
     */
    public const TRANSITIONS = [
        'queued' => [MediaJobStatus::Running, MediaJobStatus::Failed, MediaJobStatus::Cancelled],
        'running' => [MediaJobStatus::Done, MediaJobStatus::Failed, MediaJobStatus::Cancelled],
        'done' => [],
        'failed' => [],
        'cancelled' => [],
    ];

    /**
     * @param list<string>            $artifactPaths committed outputs, in arrival order
     * @param list<array<string,mixed>> $progressFrames  ring: newest last, capped at self::PROGRESS_RING
     */
    private function __construct(
        private readonly string $jobId,
        private readonly MediaKind $kind,
        private readonly MediaJobStatus $status = MediaJobStatus::Queued,
        private readonly ?string $taskId = null,
        private readonly bool $taskIdSet = false,
        private readonly ?string $serverTaskId = null,
        private readonly bool $serverTaskIdSet = false,
        private readonly array $artifactPaths = [],
        private readonly array $progressFrames = [],
        private readonly ?string $error = null,
        private readonly bool $errorSet = false,
    ) {
    }

    /**
     * @param string|null $jobId our-own correlation uuid; omit to mint one.
     *                         An explicit id is preserved verbatim (resume
     *                         from a checkpoint re-supplies the stored one).
     */
    public static function new(MediaKind $kind, ?string $jobId = null): self
    {
        return new self(jobId: $jobId ?? bin2hex(random_bytes(8)), kind: $kind);
    }

    public function jobId(): string
    {
        return $this->jobId;
    }

    public function kind(): MediaKind
    {
        return $this->kind;
    }

    public function status(): MediaJobStatus
    {
        return $this->status;
    }

    public function taskId(): ?string
    {
        return $this->taskId;
    }

    public function taskIdIsSet(): bool
    {
        return $this->taskIdSet;
    }

    public function serverTaskId(): ?string
    {
        return $this->serverTaskId;
    }

    public function serverTaskIdIsSet(): bool
    {
        return $this->serverTaskIdSet;
    }

    /**
     * @return list<string>
     */
    public function artifactPaths(): array
    {
        return $this->artifactPaths;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function progressFrames(): array
    {
        return $this->progressFrames;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function errorIsSet(): bool
    {
        return $this->errorSet;
    }

    /**
     * The guarded transition. Fail-fast: an illegal edge (skipping running,
     * reviving a terminal job, even re-stamping the current status) throws a
     * DomainException naming both ends instead of quietly mis-accounting a
     * completion the UI already rendered.
     */
    public function withStatus(MediaJobStatus $to): self
    {
        if (!in_array($to, self::TRANSITIONS[$this->status->value], true)) {
            throw new DomainException(
                "MediaJob::withStatus(): cannot move job {$this->jobId} from '{$this->status->value}' to '{$to->value}'"
            );
        }

        return $this->mutate(['status' => $to]);
    }

    /**
     * Client-chosen task pin (crush_media §4.2 force_task_id): when set, the
     * transport stamps the sdapi request with it so a crash-restart can point
     * at the same server-side task. Sentinel law: set-to-null is meaningful
     * ("explicitly no pin") and distinct from never-set.
     */
    public function withTaskId(?string $taskId): self
    {
        return $this->mutate(['taskId' => $taskId, 'taskIdSet' => true]);
    }

    /** The opaque server-side id, stored verbatim (correlation law in class docblock). */
    public function withServerTaskId(?string $serverTaskId): self
    {
        return $this->mutate(['serverTaskId' => $serverTaskId, 'serverTaskIdSet' => true]);
    }

    public function withError(?string $error): self
    {
        return $this->mutate(['error' => $error, 'errorSet' => true]);
    }

    /** Outputs arrive as they are committed; order is the arrival order. */
    public function withArtifactPath(string $path): self
    {
        return $this->mutate(['artifactPaths' => [...$this->artifactPaths, $path]]);
    }

    /**
     * Append one observed progress frame, dropping the oldest past the ring
     * cap. Frames are opaque small arrays ({'ratio'=>0.4,...}) shaped by
     * W1.5's ProgressSource — the job only carries them.
     *
     * @param array<string,mixed> $frame
     */
    public function withProgressFrame(array $frame): self
    {
        $frames = [...$this->progressFrames, $frame];

        return $this->mutate(['progressFrames' => array_slice($frames, -self::PROGRESS_RING)]);
    }

    /**
     * Persistence shape for checkpoint state (W4.5). Bytes never cross this
     * boundary — only paths and scalars, per the artifact-bytes-never-cross-
     * wire law (crush_media §10.1-4) this DTO mirrors.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = [
            'job_id' => $this->jobId,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'artifact_paths' => $this->artifactPaths,
            'progress_frames' => $this->progressFrames,
        ];
        if ($this->taskIdSet) {
            $out['task_id'] = $this->taskId;
        }
        if ($this->serverTaskIdSet) {
            $out['server_task_id'] = $this->serverTaskId;
        }
        if ($this->errorSet) {
            $out['error'] = $this->error;
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $jobId = $data['job_id'] ?? null;
        if (!is_string($jobId) || $jobId === '') {
            throw new InvalidArgumentException('MediaJob::fromArray(): job_id must be a non-empty string');
        }
        $kindRaw = $data['kind'] ?? null;
        $kind = is_string($kindRaw) ? MediaKind::tryFrom($kindRaw) : null;
        if ($kind === null) {
            throw new InvalidArgumentException('MediaJob::fromArray(): kind must be a known MediaKind value');
        }
        $statusRaw = $data['status'] ?? null;
        $status = is_string($statusRaw) ? MediaJobStatus::tryFrom($statusRaw) : null;
        if ($status === null) {
            throw new InvalidArgumentException('MediaJob::fromArray(): status must be a known MediaJobStatus value');
        }
        /** @var array<string,mixed> $rest */
        $rest = $data;

        $job = new self(jobId: $jobId, kind: $kind, status: $status);

        $paths = $rest['artifact_paths'] ?? [];
        if (!is_array($paths)) {
            throw new InvalidArgumentException('MediaJob::fromArray(): artifact_paths must be a list');
        }
        foreach ($paths as $path) {
            if (!is_string($path)) {
                throw new InvalidArgumentException('MediaJob::fromArray(): artifact path entries must be strings');
            }
            $job = $job->withArtifactPath($path);
        }

        $frames = $rest['progress_frames'] ?? [];
        if (!is_array($frames)) {
            throw new InvalidArgumentException('MediaJob::fromArray(): progress_frames must be a list');
        }
        foreach ($frames as $frame) {
            if (!is_array($frame)) {
                throw new InvalidArgumentException('MediaJob::fromArray(): progress frame entries must be arrays');
            }
            /** @var array<string,mixed> $frame */
            $job = $job->withProgressFrame($frame);
        }

        // Sentinel-preserving reads: key presence == "was set".
        if (array_key_exists('task_id', $rest)) {
            $taskRaw = $rest['task_id'];
            if ($taskRaw !== null && !is_string($taskRaw)) {
                throw new InvalidArgumentException('MediaJob::fromArray(): task_id must be a string or null');
            }
            $job = $job->withTaskId($taskRaw);
        }
        if (array_key_exists('server_task_id', $rest)) {
            $serverRaw = $rest['server_task_id'];
            if ($serverRaw !== null && !is_string($serverRaw)) {
                throw new InvalidArgumentException('MediaJob::fromArray(): server_task_id must be a string or null');
            }
            $job = $job->withServerTaskId($serverRaw);
        }
        if (array_key_exists('error', $rest)) {
            $errorRaw = $rest['error'];
            if ($errorRaw !== null && !is_string($errorRaw)) {
                throw new InvalidArgumentException('MediaJob::fromArray(): error must be a string or null');
            }
            $job = $job->withError($errorRaw);
        }

        return $job;
    }
}
