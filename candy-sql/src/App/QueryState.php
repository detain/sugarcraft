<?php

declare(strict_types=1);

namespace SugarCraft\Sql\App;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Forms\TextArea\TextArea;

/**
 * SQL-editor slice of the {@see \SugarCraft\Sql\App} model: the sugar-forms
 * TextArea plus executed-query history and favorites. Grouped out of App's
 * constructor per plan 3.3.
 */
final class QueryState
{
    use Mutable;

    /**
     * client-016: bound of the in-memory query history. An Up-arrow
     * recall surface that shows one entry must not accumulate multi-KB
     * pasted DDL forever. SQL-O01's recall (and its "saved (N)" readout)
     * surfaces this same list and must reuse this constant — never
     * renumber the cap a second time.
     */
    public const HISTORY_MAX = 200;

    /**
     * @param TextArea|null $editor Multi-line SQL editor widget (sugar-forms);
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

    /**
     * Record one executed statement at the front of the history (newest
     * first): consecutive repeats dedupe and the list is capped at
     * {@see self::HISTORY_MAX} (client-016). The single home for the
     * rule keeps the sync run leg and the async pre-dispatch leg honest
     * to the same shape.
     */
    public function withRecordedQuery(string $sql): self
    {
        $history = $this->history;
        if (($history[0] ?? '') !== $sql) {
            array_unshift($history, $sql);
            $history = array_slice($history, 0, self::HISTORY_MAX);
        }
        return $this->withHistory($history);
    }

    /** @param list<string> $favorites */
    public function withFavorites(array $favorites): self
    {
        return $this->mutate(['favorites' => $favorites]);
    }
}
