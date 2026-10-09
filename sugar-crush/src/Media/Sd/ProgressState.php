<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Sd;

/**
 * One decoded /sdapi/v1/progress frame (crush_media §4.7 + §4.6).
 *
 * The server-side math readers can audit raw replies against:
 *
 *   progress = 0.01 + (job_no / job_count) + (1 / job_count) * (sampling_step / sampling_steps)
 *
 * i.e. the outer two decimals are job-level completion within the batch and
 * the tail rides the current sampler's step fraction. Because /progress is
 * the SHARED Gradio UI-state singleton (per-task lives on /internal/progress,
 * Gradio-side, not in v1 scope), frames from a co-tenant's job can
 * interleave: monotonicity is NOT assumed anywhere in this lane — the loop
 * reports frames raw and callers pair with their own done-sentinel.
 *
 * Every field is nullable-tolerant: servers omit keys across versions, and a
 * decoder that fatals on a missing textinfo would take down a heartbeat loop
 * over cosmetics.
 */
final class ProgressState
{
    private function __construct(
        public readonly ?float $progress,
        public readonly ?float $etaRelative,
        public readonly ?int $jobNo,
        public readonly ?int $jobCount,
        public readonly ?int $samplingStep,
        public readonly ?int $samplingSteps,
        public readonly ?bool $skipped,
        public readonly ?bool $interrupted,
        public readonly ?bool $finished,
        public readonly ?string $textinfo,
        /** @var array<string, mixed>|null */
        public readonly ?array $currentTask,
        /** Live-preview PNG as base64 when the server sent current_image. */
        public readonly ?string $currentImage,
        /** @var array<string, mixed> the untouched decoded payload. */
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $inner = isset($payload['state']) && is_array($payload['state']) ? $payload['state'] : [];

        return new self(
            progress: self::asFloat($payload['progress'] ?? null),
            etaRelative: self::asFloat($payload['eta_relative'] ?? null),
            jobNo: self::asInt($inner['job_no'] ?? null),
            jobCount: self::asInt($inner['job_count'] ?? null),
            samplingStep: self::asInt($inner['sampling_step'] ?? null),
            samplingSteps: self::asInt($inner['sampling_steps'] ?? null),
            skipped: self::asBool($inner['skipped'] ?? null),
            interrupted: self::asBool($inner['interrupted'] ?? null),
            finished: self::asBool($inner['finished'] ?? null),
            textinfo: isset($payload['textinfo']) && is_string($payload['textinfo']) ? $payload['textinfo'] : null,
            currentTask: isset($payload['current_task']) && is_array($payload['current_task']) ? $payload['current_task'] : null,
            currentImage: isset($payload['current_image']) && is_string($payload['current_image']) && $payload['current_image'] !== ''
                ? $payload['current_image']
                : null,
            raw: $payload,
        );
    }

    /** Server-side batch/job completion flag — advisory under co-tenancy. */
    public function finished(): bool
    {
        return $this->finished === true;
    }

    private static function asFloat(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private static function asInt(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }

        return is_numeric($v) && (float) (int) $v === (float) $v ? (int) $v : null;
    }

    private static function asBool(mixed $v): ?bool
    {
        return is_bool($v) ? $v : null;
    }
}
