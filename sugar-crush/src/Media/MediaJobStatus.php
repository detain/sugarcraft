<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media;

/**
 * Lifecycle positions of a MediaJob — plan W1.2 roster, wire vocabulary.
 *
 * Backed values are persistence-facing: MediaJob::toArray() writes these
 * strings into checkpoint state (W4.5) and any future server-side job row,
 * so the set is closed and renames are breaking changes. The five positions
 * mirror the plan's state machine exactly: a job is queued, the worker is
 * running it, and it ends in exactly one of done / failed / cancelled.
 *
 * There is deliberately no "generating vs decoding" split: A1111's sync
 * sdapi gives no intermediate signal finer than /sdapi/v1/progress fractions
 * (crush_media §4.6), and the video backends that DO split phases are
 * unimplemented v1 (Appendix C-1) — modelling phases we cannot observe would
 * be a lying enum. Progress granularity rides the job's frame ring instead.
 */
enum MediaJobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** Terminal jobs never transition again — MediaJob::withStatus() law. */
    public function terminal(): bool
    {
        return match ($this) {
            self::Done, self::Failed, self::Cancelled => true,
            self::Queued, self::Running => false,
        };
    }
}
