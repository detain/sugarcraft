<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Field;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\SuggestionsReadyMsg;
use SugarCraft\Core\WorkerPool;
use SugarCraft\Forms\Field;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Forms\HasDynamicLabels;
use SugarCraft\Forms\HasHideFunc;
use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\ItemList\StringItem;

/**
 * Single-choice picker. Wraps {@see ItemList}; the field's value is the
 * highlighted item's title (or null when empty).
 */
final class Select implements \SugarCraft\Forms\Field
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

    /** @var CancellationSource|null Cancellation source for the pending async operation */
    private ?CancellationSource $pendingAsyncCancellation = null;

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
        ?CancellationSource $pendingAsyncCancellation = null,
        string $pendingAsyncFilterText = '',
        public readonly ?string $enumClass = null,
    ) {
        $this->fuzzyCandidates = $fuzzyCandidates;
        $this->asyncSuggestionsFetcher = $asyncSuggestionsFetcher;
        $this->asyncSuggestionsDebounceMs = $asyncSuggestionsDebounceMs;
        $this->pendingAsyncSeq = $pendingAsyncSeq;
        $this->pendingAsyncCancellation = $pendingAsyncCancellation;
        $this->pendingAsyncFilterText = $pendingAsyncFilterText;
    }

    public static function new(string $key): self
    {
        return new self(
            key:                       $key,
            list:                      ItemList::new([], 60, 5)->withShowDescription(false),
            title:                     '',
            description:               '',
            fuzzyCandidates:           [],
            asyncSuggestionsFetcher:  null,
            asyncSuggestionsDebounceMs: 150,
            pendingAsyncSeq:           0,
            pendingAsyncCancellation:  null,
            pendingAsyncFilterText:   '',
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
            key:                       $this->key,
            list:                      $this->list,
            title:                     $this->title,
            description:               $this->description,
            fuzzyCandidates:           $this->fuzzyCandidates,
            asyncSuggestionsFetcher:   $fetcher,
            asyncSuggestionsDebounceMs: $debounceMs,
            pendingAsyncSeq:           $this->pendingAsyncSeq,
            pendingAsyncCancellation:  $this->pendingAsyncCancellation,
            pendingAsyncFilterText:    $this->pendingAsyncFilterText,
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

    /**
     * When a backed enum class is provided, the selected value is coerced
     * to the enum via `EnumClass::from($stringValue)`. The option titles
     * must match the enum case values exactly. Mirrors huh's enum mode.
     *
     * @param class-string<\BackedEnum> $enumClass
     */
    public function withEnum(string $enumClass): self
    {
        return $this->mutate(enumClass: $enumClass);
    }

    // Short-form aliases.
    public function title(string $t): self                { return $this->withTitle($t); }
    public function desc(string $d): self                 { return $this->withDescription($d); }
    public function height(int $h): self                  { return $this->withHeight($h); }
    public function options(string ...$options): self    { return $this->withOptions(...$options); }
    public function fuzzy(array $candidates): self         { return $this->withFuzzySuggestions($candidates); }
    public function enum(string $enumClass): self         { return $this->withEnum($enumClass); }

    /**
     * Attach a pending async cancellation source.
     *
     * @internal
     */
    public function withPendingAsyncCancellation(CancellationSource $cancellationSource): self
    {
        return new self(
            key:                       $this->key,
            list:                      $this->list,
            title:                     $this->title,
            description:               $this->description,
            fuzzyCandidates:           $this->fuzzyCandidates,
            asyncSuggestionsFetcher:  $this->asyncSuggestionsFetcher,
            asyncSuggestionsDebounceMs: $this->asyncSuggestionsDebounceMs,
            pendingAsyncSeq:           $this->pendingAsyncSeq,
            pendingAsyncCancellation:  $cancellationSource,
            pendingAsyncFilterText:    $this->pendingAsyncFilterText,
        );
    }

    public function key(): string  { return $this->key; }
    public function value(): mixed
    {
        $sel = $this->list->selectedItem();
        $value = $sel?->title();
        if ($value === null || $this->enumClass === null) {
            return $value;
        }
        return $this->enumClass::from($value);
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
                // Apply fuzzy ranking via candy-fuzzy's SmithWatermanMatcher.
                $matcher = new SmithWatermanMatcher();
                $results = $matcher->matchAll($filterText, $this->fuzzyCandidates);
                if ($results !== []) {
                    $ranked = array_map(static fn($r) => $r->haystack, $results);
                    $items = array_map(static fn(string $o) => new StringItem($o), $ranked);
                    $l = $l->setItems($items);
                }
            }
        }

        $next = $this->mutate(list: $l);

        // Schedule async suggestions with debounce when filter text changes and is not empty
        // Cancel any previously pending operation so only the latest keystroke fires
        if ($this->asyncSuggestionsFetcher !== null
            && $l->isFiltering()
            && $l->filterText !== ''
            && $l->filterText !== $this->list->filterText
        ) {
            // Cancel previous pending async
            $this->pendingAsyncCancellation?->cancel();
            // Create a new cancellation source and store it on $next
            $next = $next->withPendingAsyncCancellation(CancellationSource::new());
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
     * Uses CancellationSource to cancel the previous pending operation when
     * the user types again before the debounce window elapses.
     *
     * @param self $field  The field instance to use for getting current input value
     * @param string $filterText  The filter text at scheduling time
     * @return \Closure|null Returns a Cmd closure, or null if no async suggestions
     */
    private function scheduleAsyncSuggestions(self $field, string $filterText): ?\Closure
    {
        // Create a new cancellation source for this operation
        $cancellationSource = CancellationSource::new();
        $fetcher = $this->asyncSuggestionsFetcher;
        $debounceMs = $this->asyncSuggestionsDebounceMs;
        $currentSeq = ++$this->pendingAsyncSeq;
        $fieldKey = $this->key;

        // Store the filter text at time of scheduling for sequence tracking
        $scheduledFilterText = $filterText;

        return function () use ($fetcher, $debounceMs, $currentSeq, $fieldKey, $field, $scheduledFilterText, $cancellationSource): \SugarCraft\Core\AsyncCmd {
            $deferred = new Deferred();
            $token = $cancellationSource->token();

            // Register cancellation: if the user types again, this will fire
            // and reject the deferred before the timer fires.
            $token->onCancel(static function () use ($deferred): void {
                $deferred->reject(new \RuntimeException('Async suggestions cancelled'));
            });

            // Schedule the debounce timer
            Loop::addTimer($debounceMs / 1000.0, function () use ($fetcher, $fieldKey, $currentSeq, $field, $deferred, $token, $scheduledFilterText): void {
                // Check if cancelled before proceeding
                if ($token->isCancelled()) {
                    return;
                }

                // Call the fetcher to get a promise
                $promise = $fetcher($scheduledFilterText);

                // Chain to resolve the deferred when the fetcher promise resolves
                $promise->then(
                    function (array $suggestions) use ($deferred, $fieldKey, $field): void {
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

    private function mutate(?ItemList $list = null, ?string $title = null, ?string $description = null, ?string $enumClass = null, ?array $fuzzyCandidates = null, bool $fuzzyCandidatesSet = false): self
    {
        return new self(
            key:                       $this->key,
            list:                      $list        ?? $this->list,
            title:                     $title       ?? $this->title,
            description:               $description ?? $this->description,
            fuzzyCandidates:           $fuzzyCandidatesSet ? $fuzzyCandidates : $this->fuzzyCandidates,
            asyncSuggestionsFetcher:  $this->asyncSuggestionsFetcher,
            asyncSuggestionsDebounceMs: $this->asyncSuggestionsDebounceMs,
            pendingAsyncSeq:           $this->pendingAsyncSeq,
            pendingAsyncCancellation:  $this->pendingAsyncCancellation,
            pendingAsyncFilterText:    $this->pendingAsyncFilterText,
            enumClass:                 $enumClass  ?? $this->enumClass,
        );
    }
}