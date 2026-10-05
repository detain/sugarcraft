<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents\Board;

/**
 * One post on a {@see Board}: who wrote it, to whom, what kind, and the body.
 *
 * `id` is the post's place on its board, 1 for the first, assigned under the
 * board's exclusive lock so two members posting at once never share one. It
 * is also the cursor a `BoardRead` call passes as `since`.
 */
final readonly class BoardEntry
{
    public function __construct(
        public int $id,
        public string $from,
        public string $to,
        public BoardKind $kind,
        public string $body,
        public ?int $replyTo = null,
        public float $at = 0.0,
    ) {}

    /**
     * @return array{id: int, from: string, to: string, kind: string, body: string, reply_to: int|null, at: float}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'from' => $this->from,
            'to' => $this->to,
            'kind' => $this->kind->value,
            'body' => $this->body,
            'reply_to' => $this->replyTo,
            'at' => $this->at,
        ];
    }

    /**
     * The entry a stored line describes, or null for a line that is not one —
     * a write a SIGKILL cut short, or bytes nobody on this board wrote.
     */
    public static function fromArray(mixed $row): ?self
    {
        if (!\is_array($row)) {
            return null;
        }

        $id = $row['id'] ?? null;
        $from = $row['from'] ?? null;
        $to = $row['to'] ?? null;
        $kind = \is_string($row['kind'] ?? null) ? BoardKind::tryFrom($row['kind']) : null;
        $body = $row['body'] ?? null;
        $replyTo = $row['reply_to'] ?? null;
        $at = $row['at'] ?? 0.0;

        if (!\is_int($id) || $id < 1 || !\is_string($from) || !\is_string($to) || $kind === null || !\is_string($body)) {
            return null;
        }

        return new self(
            $id,
            $from,
            $to,
            $kind,
            $body,
            \is_int($replyTo) ? $replyTo : null,
            \is_int($at) || \is_float($at) ? (float) $at : 0.0,
        );
    }

    /** Whether $member is who this entry is for: addressed to it, or to everyone. */
    public function isFor(string $member): bool
    {
        return $this->to === $member || $this->to === Board::ALL;
    }

    /** One line of a `BoardRead` result: `#3 coder-1 → ALL [INFO] (re #1) body`. */
    public function render(): string
    {
        return sprintf(
            '#%d %s → %s [%s]%s %s',
            $this->id,
            $this->from,
            $this->to,
            $this->kind->value,
            $this->replyTo === null ? '' : ' (re #' . $this->replyTo . ')',
            $this->body,
        );
    }
}
