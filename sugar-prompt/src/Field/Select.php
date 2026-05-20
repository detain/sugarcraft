<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Field;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Bits\ItemList\ItemList;
use SugarCraft\Bits\ItemList\StringItem;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\SuggestionsReadyMsg;
use SugarCraft\Core\WorkerPool;
use SugarCraft\Prompt\Field;
use SugarCraft\Prompt\Fuzzy\FuzzyMatcher;
use SugarCraft\Prompt\HasDynamicLabels;
use SugarCraft\Prompt\HasHideFunc;

/**
 * Single-choice picker. Wraps {@see ItemList}; the field's value is the
 * highlighted item's title (or null when empty).
 */
final class Select implements Field
{
    use HasHideFunc;
    use HasDynamicLabels;

    /** @var list<string> */
    private array $fuzzyCandidates = [];

    /** @var string */
    private string $fuzzyFilterText = '';

    /** @var callable|null Async suggestions fetcher: receives filter text, returns PromiseInterface<list<string>> */
    private $asyncSuggestionsFetcher = null;

    /** @var int Debounce delay in ms for async suggestions */
    private int $asyncSuggestionsDebounceMs = 150;

    /** @var int Sequence counter for pending async operations */
    private int $pendingAsyncSeq = 0;

    /** @var string Current filter text at the time async was scheduled */
    private string $pendingAsyncFilterText = '';

    private function __construct(
        public readonly string $key,
        public readonly ItemList $list,
        public readonly string $title,
        public readonly string $description,
        array $fuzzyCandidates = [],
        callable $asyncSuggestionsFetcher = null,
        int $asyncSuggestionsDebounceMs = 150,
        int $pendingAsyncSeq = 0,
        string $pendingAsyncFilterText = '',
    ) {
        $this->fuzzyCandidates = $fuzzyCandidates;
        $this->asyncSuggestionsFetcher = $asyncSuggestionsFetcher;
        $this->asyncSuggestionsDebounceMs = $asyncSuggestionsDebounceMs;
        $this->pendingAsyncSeq = $pendingAsyncSeq;
        $this->pendingAsyncFilterText = $pendingAsyncFilterText;
    }

    public static function new(string $key): self
    {
        return new self(
            $key,
            ItemList::new([], 60, 5)->withShowDescription(false),
            '',
            '',
            [],
            null,
            150,
            0,
            '',
        );
    }

    public function withOptions(string ...$options): self
    {
        $items = array_map(static fn(string $o) => new StringItem($o), $options);
        return $this->mutate(list: $this->list->setItems($items));
    }

    /**
     * Provide a candidate pool that is filtered and ranked by fuzzy
     * substring match (Smith-Waterman scoring) against the user's
     * filter keystrokes. The suggestion list ranks by score; user picks
     * via arrow keys. Mirrors huh's `WithFuzzySuggestions([]string)`.
     *
     * @param list<string> $candidates
     */
    public function withFuzzySuggestions(array $candidates): self
    {
        $items = array_map(static fn(string $o) => new StringItem($o), $candidates);
        return $this->mutate(
            list: $this->list->setItems($items),
            fuzzyCandidates: $candidates,
        );
    }

    /**
     * Async suggestions via a callable that returns a Promise.
     * Debounces for $debounceMs after each filter keystroke, then calls the fetcher.
     * The fetcher receives the current filter text and returns a promise of suggestions.
     * Uses WorkerPool to run the fetch off the main event loop.
     * Mirrors huh's async suggestion pattern.
     *
     * @param callable(string):PromiseInterface<list<string>> $fetcher Receives filter text, returns promise of suggestions
     * @param int $debounceMs Milliseconds to wait after last keystroke before fetching (default 150)
     * @param WorkerPool|null $workerPool Optional worker pool for offloading; uses Loop::get() if not provided
     */
    public function withAsyncSuggestions(callable $fetcher, int $debounceMs = 150, WorkerPool $workerPool = null): self
    {
        return new self(
            $this->key,
            $this->list,
            $this->title,
            $this->description,
            $this->fuzzyCandidates,
            $fetcher,
            $debounceMs,
            $this->pendingAsyncSeq,
            $this->pendingAsyncFilterText,
        );
    }

    /**
     * Short-form alias for withAsyncSuggestions.
     */
    public function async(callable $fetcher, int $debounceMs = 150, WorkerPool $workerPool = null): self
    {
        return $this->withAsyncSuggestions($fetcher, $debounceMs, $workerPool);
    }

    public function withTitle(string $t): self        { return $this->mutate(title: $t); }
    public function withDescription(string $d): self  { return $this->mutate(description: $d); }
    public function withHeight(int $h): self          { return $this->mutate(list: $this->list->setSize($this->list->width, max(1, $h))); }

    // Short-form aliases.
    public function title(string $t): self                { return $this->withTitle($t); }
    public function desc(string $d): self                 { return $this->withDescription($d); }
    public function height(int $h): self                  { return $this->withHeight($h); }
    public function options(string ...$options): self    { return $this->withOptions(...$options); }
    public function fuzzy(array $candidates): self         { return $this->withFuzzySuggestions($candidates); }

    public function key(): string  { return $this->key; }
    public function value(): mixed
    {
        $sel = $this->list->selectedItem();
        return $sel?->title();
    }

    public function focus(): array
    {
        [$l, $cmd] = $this->list->focus();
        return [$this->mutate(list: $l), $cmd];
    }

    public function blur(): Field
    {
        return $this->mutate(list: $this->list->blur());
    }

    public function update(Msg $msg): array
    {
        // Handle async suggestions result
        if ($msg instanceof SuggestionsReadyMsg && $msg->fieldKey === $this->key) {
            $items = array_map(
                static fn(string $o) => new StringItem($o),
                $msg->suggestions,
            );
            $next = $this->mutate(list: $this->list->setItems($items));
            return [$next, null];
        }

        [$l, $cmd] = $this->list->update($msg);

        // Apply fuzzy filtering when we have fuzzy candidates and list is in filtering mode.
        if ($this->fuzzyCandidates !== [] && $l->isFiltering()) {
            $filterText = $l->filterText;
            if ($filterText === '') {
                // No filter text - show all candidates in original order
                $items = array_map(
                    static fn(string $o) => new StringItem($o),
                    $this->fuzzyCandidates,
                );
                $l = $l->setItems($items);
            } else {
                // Apply fuzzy ranking
                $matcher = new FuzzyMatcher();
                $scored = $matcher->match($filterText, $this->fuzzyCandidates);
                if ($scored !== []) {
                    $ranked = array_column($scored, 0);
                    $items = array_map(static fn(string $o) => new StringItem($o), $ranked);
                    $l = $l->setItems($items);
                }
            }
        }

        $next = $this->mutate(list: $l);

        // Schedule async suggestions with debounce when filter text changes and is not empty
        if ($this->asyncSuggestionsFetcher !== null
            && $l->isFiltering()
            && $l->filterText !== ''
            && $l->filterText !== $this->list->filterText
        ) {
            $asyncCmd = $this->scheduleAsyncSuggestions($next, $l->filterText);
            if ($cmd !== null) {
                return [$next, fn() => $cmd()];
            }
            return [$next, $asyncCmd];
        }

        return [$next, $cmd];
    }

    /**
     * Schedule async suggestions fetch with debounce.
     * Returns a Cmd that will perform the debounce and return AsyncCmd.
     *
     * @return \Closure|null Returns a Cmd closure, or null if no async suggestions
     */
    private function scheduleAsyncSuggestions(self $field, string $filterText): ?\Closure
    {
        $fetcher = $this->asyncSuggestionsFetcher;
        $debounceMs = $this->asyncSuggestionsDebounceMs;
        $currentSeq = ++$this->pendingAsyncSeq;
        $fieldKey = $this->key;

        // Store the filter text at time of scheduling for sequence tracking
        $scheduledFilterText = $filterText;

        return function () use ($fetcher, $debounceMs, $currentSeq, $fieldKey, $field, $scheduledFilterText): \SugarCraft\Core\AsyncCmd {
            $deferred = new Deferred();

            // Schedule the debounce timer
            Loop::addTimer($debounceMs / 1000.0, function () use ($fetcher, $fieldKey, $currentSeq, $field, $deferred, $scheduledFilterText): void {
                // Call the fetcher to get a promise
                $promise = $fetcher($scheduledFilterText);

                // Chain to resolve the deferred when the fetcher promise resolves
                $promise->then(
                    function (array $suggestions) use ($deferred, $currentSeq, $field): void {
                        // Resolve with the suggestions message
                        $deferred->resolve(new SuggestionsReadyMsg($fieldKey, $suggestions));
                    },
                    function (\Throwable $e) use ($deferred): void {
                        $deferred->reject($e);
                    }
                );
            });

            return new \SugarCraft\Core\AsyncCmd($deferred->promise());
        };
    }

    public function view(): string
    {
        $lines = [];
        $title = $this->resolveTitle($this->title);
        $desc  = $this->resolveDescription($this->description);
        if ($title !== '') { $lines[] = $title; }
        if ($desc  !== '') { $lines[] = $desc; }
        $lines[] = $this->list->view();
        return implode("\n", $lines);
    }

    public function isFocused(): bool         { return $this->list->focused; }
    public function getTitle(): string        { return $this->resolveTitle($this->title); }
    public function getDescription(): string  { return $this->resolveDescription($this->description); }
    public function getError(): ?string       { return null; }
    public function skippable(): bool         { return false; }

    /**
     * In filter mode the inner ItemList uses Enter to leave the filter
     * and Escape to clear it; both must be consumed locally so the Form
     * doesn't advance/abort while the user is filtering. Up / Down also
     * belong to the list — without consuming them the form would steal
     * arrow keys for between-field navigation.
     */
    public function consumes(Msg $msg): bool
    {
        if (!$this->list->focused || !$msg instanceof KeyMsg) {
            return false;
        }
        if ($msg->type === KeyType::Up || $msg->type === KeyType::Down) {
            return true;
        }
        if ($this->list->isFiltering()) {
            return $msg->type === KeyType::Enter || $msg->type === KeyType::Escape;
        }
        return false;
    }

    private function mutate(?ItemList $list = null, ?string $title = null, ?string $description = null): self
    {
        return new self(
            key:                       $this->key,
            list:                      $list        ?? $this->list,
            title:                     $title       ?? $this->title,
            description:               $description ?? $this->description,
            fuzzyCandidates:           $this->fuzzyCandidates,
            asyncSuggestionsFetcher:  $this->asyncSuggestionsFetcher,
            asyncSuggestionsDebounceMs: $this->asyncSuggestionsDebounceMs,
            pendingAsyncSeq:           $this->pendingAsyncSeq,
            pendingAsyncFilterText:    $this->pendingAsyncFilterText,
        );
    }
}
