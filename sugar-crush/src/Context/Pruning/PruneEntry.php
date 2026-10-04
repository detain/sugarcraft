<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

/**
 * One pruned tool result in the {@see ContextLedger} (roadmap 2.2-1, DCP §13.2
 * B), keyed by its TOOL-CALL ID.
 *
 * The id is the key because it is the one identity a result has on every path
 * today: unique per session since step 0.2, and already persisted on Chat's
 * rows (`toolResults[].id`), so an entry means the same result whether the turn
 * runs in-process, in a forked child, or replays a stored transcript. Row refs
 * ({@see RefTag}) resolve to ids through {@see ContextLedger::refsFor()}.
 *
 * A {@see PruneKind::Distilled} entry (roadmap 3.B-3, the model's own
 * `Prune` with a distillation) carries the text that stands in for the
 * output; every other kind carries none.
 */
final readonly class PruneEntry
{
    /**
     * @param int         $tokens       estimated tokens the projection saves
     *                                   (the output's estimate less its
     *                                   placeholder's), for the receipts and
     *                                   the strategies' "worth it" gate
     * @param string|null $distillation  what a {@see PruneKind::Distilled}
     *                                   entry shows in the output's place;
     *                                   null for every other kind
     */
    public function __construct(
        public string $toolCallId,
        public PruneKind $kind,
        public PruneReason $reason,
        public PruneAuthor $by,
        public int $tokens,
        public ?string $distillation = null,
    ) {
    }

    /**
     * The `distillation` key is written only when there is one, so an entry
     * of every other kind keeps the shape it was stored in before 3.B-3.
     *
     * @return array{toolCallId:string,kind:string,reason:string,by:string,tokens:int,distillation?:string}
     */
    public function toArray(): array
    {
        return [
            'toolCallId' => $this->toolCallId,
            'kind' => $this->kind->value,
            'reason' => $this->reason->value,
            'by' => $this->by->value,
            'tokens' => $this->tokens,
            ...($this->distillation === null ? [] : ['distillation' => $this->distillation]),
        ];
    }

    /** Rebuild from {@see toArray()}; null for anything it did not write. */
    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw) || !is_string($raw['toolCallId'] ?? null) || $raw['toolCallId'] === '') {
            return null;
        }
        $kind = is_string($raw['kind'] ?? null) ? PruneKind::tryFrom($raw['kind']) : null;
        $reason = is_string($raw['reason'] ?? null) ? PruneReason::tryFrom($raw['reason']) : null;
        $by = is_string($raw['by'] ?? null) ? PruneAuthor::tryFrom($raw['by']) : null;
        if ($kind === null || $reason === null || $by === null) {
            return null;
        }
        // A distilled entry without its text would project an empty
        // stand-in for the output: unreadable, so skipped like any other.
        $distillation = is_string($raw['distillation'] ?? null) ? $raw['distillation'] : null;
        if ($kind === PruneKind::Distilled && ($distillation === null || trim($distillation) === '')) {
            return null;
        }

        return new self(
            $raw['toolCallId'],
            $kind,
            $reason,
            $by,
            is_int($raw['tokens'] ?? null) ? $raw['tokens'] : 0,
            $kind === PruneKind::Distilled ? $distillation : null,
        );
    }
}
