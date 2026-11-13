<?php

declare(strict_types=1);

namespace SugarCraft\Sql\App;

use SugarCraft\Core\Concerns\Mutable;

/**
 * The file-line slice of the {@see \SugarCraft\Sql\App} model (SQL-O07):
 * the Ctrl+S / `x` CSV export prompt and the `i` import prompt the exporters
 * finally got in front of. Exporters complete since SQL-C04 were a library
 * with zero TUI callers; this state is the missing user half.
 *
 * Stages: `closed` → `export-path` (typing a destination) → optional
 * `export-overwrite` (the refuse-overwrite re-press arm) and `import-path`
 * (typing a source) → `import-table` (typing the target table). The buffer
 * always holds the line currently being typed; {@see self::$path} only
 * carries the confirmed import source between its two stages.
 */
final class ExportState
{
    use Mutable;

    public const STAGE_CLOSED = 'closed';
    public const STAGE_EXPORT_PATH = 'export-path';
    public const STAGE_EXPORT_OVERWRITE = 'export-overwrite';
    public const STAGE_IMPORT_PATH = 'import-path';
    public const STAGE_IMPORT_TABLE = 'import-table';

    public function __construct(
        public readonly string $stage = self::STAGE_CLOSED,
        public readonly string $buffer = '',
        public readonly string $path = '',
    ) {}

    public static function new(): self
    {
        return new self();
    }

    /** Whether a file line is open and should own the keyboard. */
    public function isPromptOpen(): bool
    {
        return $this->stage !== self::STAGE_CLOSED;
    }

    public function closed(): self
    {
        return new self();
    }

    public function withStage(string $stage): self
    {
        return $this->mutate(['stage' => $stage]);
    }

    public function withBuffer(string $buffer): self
    {
        return $this->mutate(['buffer' => $buffer]);
    }

    public function withPath(string $path): self
    {
        return $this->mutate(['path' => $path]);
    }
}
