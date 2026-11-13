<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents\Board;

use SugarCraft\Core\Concerns\Mutable;

/**
 * One post on a {@see Board}: who wrote it, to whom, what kind, the body,
 * and — for CLAIM and RELEASE only — the paths the post stands on.
 *
 * `id` is the post's place on its board, 1 for the first, assigned under the
 * board's exclusive lock so two members posting at once never share one. It
 * is also the cursor a `BoardRead` call passes as `since`.
 */
final readonly class BoardEntry
{
    use Mutable;

    /**
     * @param list<string> $paths the files a CLAIM stands on, or the files a
     *        RELEASE clears; empty for every other kind
     */
    public function __construct(
        public int $id,
        public string $from,
        public string $to,
        public BoardKind $kind,
        public string $body,
        public ?int $replyTo = null,
        public float $at = 0.0,
        public array $paths = [],
    ) {}

    /**
     * @return array{id: int, from: string, to: string, kind: string, body: string, reply_to: int|null, at: float, paths: list<string>}
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
            'paths' => array_values($this->paths),
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
        // Coerced, never accused: a line written before paths existed carries
        // none, and a line whose paths field is no list degrades to none
        // rather than losing the post the body still describes.
        $paths = \is_array($row['paths'] ?? null) ? $row['paths'] : [];

        if (!\is_int($id) || $id < 1 || !\is_string($from) || !\is_string($to) || $kind === null || !\is_string($body)) {
            return null;
        }

        $names = [];
        foreach ($paths as $path) {
            if (!\is_string($path)) {
                continue;
            }
            $path = trim($path);
            if ($path !== '' && !\in_array($path, $names, true)) {
                $names[] = $path;
            }
        }

        return new self(
            $id,
            $from,
            $to,
            $kind,
            $body,
            \is_int($replyTo) ? $replyTo : null,
            \is_int($at) || \is_float($at) ? (float) $at : 0.0,
            $names,
        );
    }

    /**
     * The same post naming only $paths — how {@see Board::outstandingClaims()}
     * reports a CLAIM after a RELEASE, or a later CLAIM, narrowed it.
     *
     * @param list<string> $paths
     */
    public function withPaths(array $paths): self
    {
        return $this->mutate(['paths' => array_values($paths)]);
    }

    /** Whether $member is who this entry is for: addressed to it, or to everyone. */
    public function isFor(string $member): bool
    {
        return $this->to === $member || $this->to === Board::ALL;
    }

    /**
     * One line of a `BoardRead` result: `#3 coder-1 → ALL [INFO] (re #1) body`;
     * a CLAIM or RELEASE names its files before the body,
     * `#4 coder-1 → ALL [CLAIM] (src/Auth.php) touching auth now`.
     */
    public function render(): string
    {
        return sprintf(
            '#%d %s → %s [%s]%s%s %s',
            $this->id,
            $this->from,
            $this->to,
            $this->kind->value,
            $this->replyTo === null ? '' : ' (re #' . $this->replyTo . ')',
            $this->paths === [] ? '' : ' (' . implode(', ', $this->paths) . ')',
            $this->body,
        );
    }
}
