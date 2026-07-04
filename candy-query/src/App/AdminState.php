<?php

declare(strict_types=1);

namespace SugarCraft\Query\App;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Query\Admin\AdminPane;
use SugarCraft\Query\Admin\History\HistoryRecorder;
use SugarCraft\Query\Admin\PageBase;

/**
 * Admin/monitoring slice of the {@see \SugarCraft\Query\App} model: the
 * selected admin pane + sidebar cursor, the lazily-built page instance, the
 * async-fetched status/server variable caches, and the polling/loading
 * bookkeeping. Grouped out of App's constructor per plan 3.3.
 */
final class AdminState
{
    use Mutable;

    /**
     * @param array<string,string>|null $cachedStatusVars Async-fetched SHOW GLOBAL STATUS (null before first fetch)
     * @param array<string,string>|null $cachedServerVars Async-fetched SHOW GLOBAL VARIABLES (null before first fetch)
     * @param float $cacheTs Epoch timestamp of the cached vars
     * @param float $lastFetchAt Throttle timestamp of the last admin fetch dispatch
     */
    public function __construct(
        public readonly AdminPane $pane = AdminPane::ProcessList,
        public readonly int $cursor = 0,
        public readonly bool $paused = false,
        public readonly ?PageBase $page = null,
        public readonly ?array $cachedStatusVars = null,
        public readonly ?array $cachedServerVars = null,
        public readonly float $cacheTs = 0.0,
        public readonly bool $loading = false,
        public readonly float $lastFetchAt = 0.0,
        public readonly ?HistoryRecorder $historyRecorder = null,
    ) {}

    public static function new(): self
    {
        return new self();
    }

    /**
     * Selecting a pane resets the lazily-built page so it is recreated
     * against the new pane; this is the ONLY transition that drops page
     * state (loading ticks must preserve it — see App::withAdminLoading()).
     */
    public function withPane(AdminPane $pane): self
    {
        return $this->mutate(['pane' => $pane, 'page' => null]);
    }

    public function withCursor(int $cursor): self
    {
        return $this->mutate(['cursor' => $cursor]);
    }

    public function withPaused(bool $paused): self
    {
        return $this->mutate(['paused' => $paused]);
    }

    public function withPage(?PageBase $page): self
    {
        return $this->mutate(['page' => $page]);
    }

    /**
     * @param array<string,string> $statusVars
     * @param array<string,string> $serverVars
     */
    public function withCachedData(array $statusVars, array $serverVars, float $ts): self
    {
        return $this->mutate([
            'cachedStatusVars' => $statusVars,
            'cachedServerVars' => $serverVars,
            'cacheTs' => $ts,
            'loading' => false,
        ]);
    }

    public function withLoading(bool $loading): self
    {
        return $this->mutate(['loading' => $loading]);
    }

    public function withLastFetchAt(float $lastFetchAt): self
    {
        return $this->mutate(['lastFetchAt' => $lastFetchAt]);
    }

    public function withHistoryRecorder(?HistoryRecorder $historyRecorder): self
    {
        return $this->mutate(['historyRecorder' => $historyRecorder]);
    }
}
