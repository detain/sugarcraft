<?php

declare(strict_types=1);

namespace SugarCraft\Sql\App;

use SugarCraft\Async\CancellationSource;
use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Sql\Pane;

/**
 * Chrome slice of the {@see \SugarCraft\Sql\App} model: which pane has
 * focus and the status-line error/info text. Grouped out of App's
 * constructor per plan 3.3.
 */
final class UiState
{
    use Mutable;

    public function __construct(
        public readonly Pane $pane = Pane::Tables,
        public readonly ?string $error = null,
        public readonly ?string $status = null,
        /**
         * D-M27: exact SQL of a destructive statement awaiting a second
         * confirming run-key press; null when nothing is armed.
         */
        public readonly ?string $armedDestructive = null,
        /**
         * SQL-O08: the cancel handle of the currently in-flight user leg
         * (async query run or browse load); null while nothing is running.
         * Escape/Ctrl+C answer to it before the pane chords do. Like the
         * AdminState recorder this is a live session mechanism parked on an
         * otherwise-value object — only the reference is the value.
         */
        public readonly ?CancellationSource $inFlight = null,
    ) {}

    public static function new(): self
    {
        return new self();
    }

    public function withPane(Pane $pane): self
    {
        return $this->mutate(['pane' => $pane]);
    }

    public function withError(?string $error): self
    {
        return $this->mutate(['error' => $error]);
    }

    public function withStatus(?string $status): self
    {
        return $this->mutate(['status' => $status]);
    }

    public function withArmedDestructive(?string $sql): self
    {
        return $this->mutate(['armedDestructive' => $sql]);
    }

    public function withInFlight(?CancellationSource $source): self
    {
        return $this->mutate(['inFlight' => $source]);
    }
}
