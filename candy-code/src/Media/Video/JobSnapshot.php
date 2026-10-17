<?php

declare(strict_types=1);

namespace SugarCraft\Code\Media\Video;

use SugarCraft\Code\Media\MediaJobStatus;
use SugarCraft\Code\Media\Sd\SdException;

/**
 * One decoded read of a video job's status endpoint.
 *
 * Mirrors plan_crush_media.md W5.2: `status(taskId)` answers with the job's
 * state, progress, ETA and — once done — the result reference the fetch step
 * consumes. Decoding is tolerant of omitted keys exactly like
 * {@see \SugarCraft\Code\Media\Sd\ProgressState} (servers legitimately
 * withhold progress while a job is still queued), but the STATUS VOCABULARY
 * is closed: an unrecognized state word is a protocol error, never a silent
 * "keep polling" — a done job wearing a new label would otherwise poll
 * forever against an E646-unbounded loop.
 *
 * `resultRef` is INGRESS material (raw url / file_path / b64 shapes per
 * plan §5.4 rows 1-4): it exists only to feed {@see JobClient::fetch()} and
 * must never be echoed to a transcript — nothing downstream sees a b64
 * string (fact (f)).
 */
final readonly class JobSnapshot
{
    /**
     * Closed server-vocabulary → our-state map. Every value is a
     * {@see MediaJobStatus} case value; words absent here refuse loudly at
     * {@see fromArray()}.
     */
    private const STATE_WORDS = [
        'queued' => MediaJobStatus::Queued,
        'pending' => MediaJobStatus::Queued,
        'waiting' => MediaJobStatus::Queued,
        'scheduled' => MediaJobStatus::Queued,
        'processing' => MediaJobStatus::Running,
        'running' => MediaJobStatus::Running,
        'generating' => MediaJobStatus::Running,
        'started' => MediaJobStatus::Running,
        'in_progress' => MediaJobStatus::Running,
        'completed' => MediaJobStatus::Done,
        'complete' => MediaJobStatus::Done,
        'done' => MediaJobStatus::Done,
        'succeeded' => MediaJobStatus::Done,
        'success' => MediaJobStatus::Done,
        'finished' => MediaJobStatus::Done,
        'failed' => MediaJobStatus::Failed,
        'failure' => MediaJobStatus::Failed,
        'error' => MediaJobStatus::Failed,
        'cancelled' => MediaJobStatus::Cancelled,
        'canceled' => MediaJobStatus::Cancelled,
        'interrupted' => MediaJobStatus::Cancelled,
    ];

    /** Result-bearing keys a done job may carry (§5.4 ingestion shapes). */
    private const RESULT_KEYS = ['url', 'file_path', 'videoPath', 'b64_json', 'b64'];

    /**
     * @param  array<string, mixed>  $resultRef  raw result-reference keys, bytes never included
     * @param  array<string, mixed>  $raw  the untouched server payload
     */
    public function __construct(
        public MediaJobStatus $state,
        public ?float $progress,
        public ?float $etaSeconds,
        public array $resultRef,
        public ?string $serverMessage,
        public array $raw,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @throws SdException when the state word is missing or outside the closed vocabulary
     */
    public static function fromArray(array $payload): self
    {
        $word = self::stateWord($payload);
        $state = self::STATE_WORDS[$word] ?? null;

        if ($state === null) {
            throw SdException::protocol(
                'video job',
                "unrecognized job status '{$word}' — the state vocabulary is closed; refusing to guess a transition",
            );
        }

        return new self(
            state: $state,
            progress: self::numeric($payload['progress'] ?? null),
            etaSeconds: self::numeric($payload['eta_seconds'] ?? $payload['etaSeconds'] ?? $payload['eta'] ?? null),
            resultRef: self::resultRef($payload),
            serverMessage: self::message($payload),
            raw: $payload,
        );
    }

    public function terminal(): bool
    {
        return $this->state->terminal();
    }

    /**
     * The status key is `status` on every recorded contract (sglang
     * VideoResponse, W0.1); `state` is the A1111-internal spelling we accept
     * as a documented courtesy. Anything else fails at fromArray().
     *
     * @param  array<string, mixed>  $payload
     */
    private static function stateWord(array $payload): string
    {
        $word = $payload['status'] ?? $payload['state'] ?? null;

        if (!\is_string($word) || trim($word) === '') {
            throw SdException::protocol('video job', 'status payload carries no job status string');
        }

        return strtolower(trim($word));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function resultRef(array $payload): array
    {
        $ref = [];

        foreach (self::RESULT_KEYS as $key) {
            $value = $payload[$key] ?? null;

            if (\is_string($value) && $value !== '') {
                $ref[$key] = $value;
            }
        }

        // OpenAI-isms: some servers wrap results in a videos[]/data[] list.
        foreach (['videos', 'data'] as $listKey) {
            $list = $payload[$listKey] ?? null;

            if (!\is_array($list)) {
                continue;
            }

            foreach ($list as $entry) {
                if (!\is_array($entry)) {
                    continue;
                }

                foreach (self::RESULT_KEYS as $key) {
                    $value = $entry[$key] ?? null;

                    if (\is_string($value) && $value !== '' && !isset($ref[$key])) {
                        $ref[$key] = $value;
                    }
                }
            }
        }

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function message(array $payload): ?string
    {
        foreach (['error', 'detail'] as $key) {
            $value = $payload[$key] ?? null;

            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function numeric(mixed $value): ?float
    {
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        if (\is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
