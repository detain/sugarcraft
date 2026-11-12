<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context;

use SugarCraft\Code\Lang;

/**
 * Who condensed the history in a compaction: the summarisation model, or the
 * local heuristic and, for the heuristic, why the model did not.
 *
 * The heuristic truncates user rows and writes `[exchanged information]` for
 * assistant replies, so a user who sees a boundary notice must be able to tell
 * which of the two ran and, when it was the fallback, what forced it.
 */
final class CompactionSource
{
    public const REASON_MODEL_FAILED = 'model_failed';
    public const REASON_MODEL_EMPTY = 'model_empty';
    public const REASON_SPEND_CAP = 'spend_cap';
    public const REASON_SAFEGUARD = 'safeguard';
    public const REASON_NO_MODEL = 'no_model';

    private const REASONS = [
        self::REASON_MODEL_FAILED,
        self::REASON_MODEL_EMPTY,
        self::REASON_SPEND_CAP,
        self::REASON_SAFEGUARD,
        self::REASON_NO_MODEL,
    ];

    private function __construct(
        public readonly bool $model,
        public readonly ?string $reason,
    ) {
    }

    public static function model(): self
    {
        return new self(true, null);
    }

    /** @param self::REASON_* $reason */
    public static function heuristic(string $reason): self
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException("Unknown heuristic compaction reason: {$reason}");
        }

        return new self(false, $reason);
    }

    /** "model summary", or "local heuristic (the summary call failed)". */
    public function label(): string
    {
        if ($this->model) {
            return Lang::t('host.compact.source.model');
        }

        // Literal keys, not a concatenated prefix: the lang parity scan only sees literals.
        $reasonKey = match ($this->reason) {
            self::REASON_MODEL_FAILED => 'host.compact.source.reason.model_failed',
            self::REASON_MODEL_EMPTY => 'host.compact.source.reason.model_empty',
            self::REASON_SPEND_CAP => 'host.compact.source.reason.spend_cap',
            self::REASON_SAFEGUARD => 'host.compact.source.reason.safeguard',
            default => 'host.compact.source.reason.no_model',
        };

        return Lang::t('host.compact.source.heuristic', [
            'reason' => Lang::t($reasonKey),
        ]);
    }
}
