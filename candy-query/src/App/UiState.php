<?php

declare(strict_types=1);

namespace SugarCraft\Query\App;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Query\Pane;

/**
 * Chrome slice of the {@see \SugarCraft\Query\App} model: which pane has
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
}
