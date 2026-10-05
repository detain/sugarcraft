<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Edit;

/**
 * One file section of an `ApplyPatch` patch, as {@see PatchParser} read it.
 *
 * - {@see PatchAction::Add}: `$path` is created with `$content` (the `+`
 *   lines, newline-terminated) and must not exist yet.
 * - {@see PatchAction::Update}: `$hunks` apply to `$path` in order; with
 *   `$moveTo` set the result is written there and `$path` removed.
 * - {@see PatchAction::Delete}: `$path` is removed.
 *
 * Paths are exactly as the patch spells them; resolving them against the
 * project root (and refusing one outside it) is the tool's job, not the
 * parser's.
 */
final readonly class PatchOperation
{
    /**
     * @param list<PatchHunk> $hunks
     */
    public function __construct(
        public PatchAction $action,
        public string $path,
        public string $content = '',
        public array $hunks = [],
        public ?string $moveTo = null,
    ) {
    }

    /**
     * Every path this operation writes or removes: the source, and the move
     * destination when there is one.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->moveTo === null ? [$this->path] : [$this->path, $this->moveTo];
    }
}
