<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Memory;

/**
 * What {@see MemoryWriter::save()} did: the new note's id, the scope it was
 * saved under, and whether a project note fell back to the home store because
 * the repository could not host `.sugar-crush/memory/`.
 */
final readonly class MemorySaveResult
{
    public function __construct(
        public string $id,
        public string $scope,
        public bool $inRepository,
        public bool $fellBackToHome,
    ) {
    }
}
