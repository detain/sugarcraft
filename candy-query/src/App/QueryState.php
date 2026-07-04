<?php

declare(strict_types=1);

namespace SugarCraft\Query\App;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Forms\TextArea\TextArea;

/**
 * SQL-editor slice of the {@see \SugarCraft\Query\App} model: the candy-forms
 * TextArea plus executed-query history and favorites. Grouped out of App's
 * constructor per plan 3.3.
 */
final class QueryState
{
    use Mutable;

    /**
     * @param TextArea|null $editor Multi-line SQL editor widget (candy-forms);
     *        null until the Query pane is first focused (see App::editor()).
     * @param list<string> $history Recently executed queries (newest first)
     * @param list<string> $favorites Saved/favorited queries
     */
    public function __construct(
        public readonly ?TextArea $editor = null,
        public readonly array $history = [],
        public readonly array $favorites = [],
    ) {}

    public static function new(): self
    {
        return new self();
    }

    public function withEditor(?TextArea $editor): self
    {
        return $this->mutate(['editor' => $editor]);
    }

    /** @param list<string> $history */
    public function withHistory(array $history): self
    {
        return $this->mutate(['history' => $history]);
    }

    /** @param list<string> $favorites */
    public function withFavorites(array $favorites): self
    {
        return $this->mutate(['favorites' => $favorites]);
    }
}
