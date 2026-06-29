<?php

declare(strict_types=1);

namespace SugarCraft\Files\Msg;

/**
 * Message broadcast when an async copy operation completes.
 *
 * Mirrors charmbracelet/superfile.asyncOps.CopyCompleted.
 */
final readonly class CopyCompletedMsg
{
    /**
     * @param array<string, string> $copiedItems Map of source → destination
     * @param int $errors Number of items that failed to copy
     * @param list<string|null> $names Names of items that were copied
     * @param string|null $dst Destination directory
     */
    public function __construct(
        public array $copiedItems,
        public int $errors,
        public array $names,
        public ?string $dst,
    ) {}
}
