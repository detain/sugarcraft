<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Field;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\TimeoutException;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\SuggestionsReadyMsg;
use SugarCraft\Core\WorkerPool;
use SugarCraft\Forms\Field;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Forms\CarriesNonCtorState;
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
    use CarriesNonCtorState;

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

    /** @var float|null E736-F2/6.5 wall-clock ceiling in seconds per debounced async fetch; null = no added timeout */
    private ?float $asyncSuggestionsFetchTimeoutSeconds = null;

    /**
     * E736-F2/2.2 (round 85): the fuzzy filter path used to scatter
     * `new SmithWatermanMatcher()` across every apply site; the widget now
     * instantiates it at exactly ONE site — this constructor. Select is
     * immutable, so every mutate() rebuilds a sibling and the ctor seeds a
     * fresh matcher; the matcher is stateless apart from a lazily built
     * fallback scorer, so per-instance construction stays cheap and output
     * byte-identical. The census pins the single SITE, not instance reuse
     * across keystrokes (r85-rv-u4: the survives-keystrokes wording was
     * measured false and the matcher was deliberately NOT made static).
     */
    private readonly SmithWatermanMatcher $matcher;

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
        ?float $asyncSuggestionsFetchTimeoutSeconds = null,
    ) {
        $this->matcher = new SmithWatermanMatcher();
        $this->fuzzyCandidates = $fuzzyCandidates;
        $this->asyncSuggestionsFetcher = $asyncSuggestionsFetcher;
        $this->asyncSuggestionsDebounceMs = $asyncSuggestionsDebounceMs;
        $this->pendingAsyncSeq = $pendingAsyncSeq;
        $this->pendingAsyncCancellation = $pendingAsyncCancellation;
        $this->pendingAsyncFilterText = $pendingAsyncFilterText;
        $this->asyncSuggestionsFetchTimeoutSeconds = $asyncSuggestionsFetchTimeoutSeconds;
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
     * Mirrors huh's async suggestion pattern.
     *
     * WORKER POOL STATUS (E736 1.5): the fetch runs on the MAIN event loop —
     * {@see scheduleAsyncSuggestions()} debounces via {@see Loop::addTimer()}
     * and invokes the fetcher in-loop; nothing is offloaded. The
     * {@see WorkerPool} parameter is accepted for signature parity with the
     * planned offload path and is DISCARDED here (Select does not even store
     * it; Input stores the same dead parameter). Wiring real offloading is
     * deferred to the follow-up async lane (findings/plan_candy-forms.md
     * Phase 6.3) — until then passing a pool has NO effect, and fetchers
     * that block will block the loop.
     *
     * @param callable(string):PromiseInterface<list<string>> $fetcher Receives filter text, returns promise of suggestions
     * @param int $debounceMs Milliseconds to wait after last keystroke before fetching (default 150)
     * @param WorkerPool|null $workerPool RESERVED — accepted, never consulted; see WORKER POOL STATUS above
     * @param float|null $fetchTimeoutSeconds E736-F2/6.5: when set, each in-flight
     *   fetch is raced against this wall-clock ceiling (seconds) via
     *   {@see AsyncOps::withTimeout()}; a breach rejects with
     *   {@see TimeoutException}. Default null awaits with NO added timeout —
     *   byte-identical to pre-E736 behaviour.
     * ASYNC DESIGN: see Input::withAsyncSuggestions() for the E736-F2
     * notes (single-resolve fetch, quiet supersession via null-resolution
     * 6.6, opt-in $fetchTimeoutSeconds 6.5, acceptable debounce timers 6.4).
     */
    public function withAsyncSuggestions(callable $fetcher, int $debounceMs = 150, WorkerPool $workerPool = null, ?float $fetchTimeoutSeconds = null): self
    {
        return $this->carryNonCtorState(new self(
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
            // E741-sibling: enumClass is ctor state these two rebuild sites used
            // to drop back to null; thread it (measured: Select::async() on an
            // enum Select silently erased the enum cast before this fix).
            enumClass:                 $this->enumClass,
            asyncSuggestionsFetchTimeoutSeconds: $fetchTimeoutSeconds,
        ));
    }

    /**
     * Short-form alias for withAsyncSuggestions.
     */
    public function async(callable $fetcher, int $debounceMs = 150, WorkerPool $workerPool = null, ?float $fetchTimeoutSeconds = null): self
    {
        return $this->withAsyncSuggestions($fetcher, $debounceMs, $workerPool, $fetchTimeoutSeconds);
    }

    public function withTitle(string $t): self        { return $this->mutate(title: $t); }
    public function withDescription(string $d): self  { return $this->mutate(description: $d); }
    public function withHeight(int $h): self          { return $this->mutate(list: $this->list->setSize($this->list->width, max(1, $h))); }

    /**
     * Pre-select an option by its 0-based index. Negative indices clamp
     * to 0; the inner {@see ItemList} clamps the upper bound. Handy for
     * showing a form's current value before the user touches it.
     */
    public function withSelectedIndex(int $index): self
    {
        return $this->mutate(list: $this->list->select(max(0, $index)));
    }

    /**
     * Pre-select the option whose value matches `$value`. When the value
     * is not among the field's options the selection is left unchanged.
     * Delegates to {@see withSelectedIndex()} once the index is resolved.
     */
    public function withSelected(string $value): self
    {
        foreach ($this->list->items() as $i => $item) {
            if ($item->title() === $value) {
                return $this->withSelectedIndex($i);
            }
        }
        return $this;
    }

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
        return $this->carryNonCtorState(new self(
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
            enumClass:                 $this->enumClass, // E741-sibling carry (see withAsyncSuggestions)
            asyncSuggestionsFetchTimeoutSeconds: $this->asyncSuggestionsFetchTimeoutSeconds,
        ));
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
                $results = $this->matcher->matchAll($filterText, $this->fuzzyCandidates);
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
                // Both the inner Cmd and the debounced fetch Cmd must run.
                return [$next, Cmd::batch($cmd, $asyncCmd)];
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
        // Use the cancellation source already stored on $field (passed from update()).
        // This is the CancellationSource that gets cancelled on subsequent keystrokes,
        // ensuring rapid keystrokes cancel previous pending async operations.
        $cancellationSource = $field->pendingAsyncCancellation;
        $fetcher = $this->asyncSuggestionsFetcher;
        $debounceMs = $this->asyncSuggestionsDebounceMs;
        $timeoutSeconds = $this->asyncSuggestionsFetchTimeoutSeconds;
        $currentSeq = ++$this->pendingAsyncSeq;
        $fieldKey = $this->key;

        // Store the filter text at time of scheduling for sequence tracking
        $scheduledFilterText = $filterText;

        return function () use ($fetcher, $debounceMs, $timeoutSeconds, $currentSeq, $fieldKey, $field, $scheduledFilterText, $cancellationSource): \SugarCraft\Core\AsyncCmd {
            $deferred = new Deferred();
            $token = $cancellationSource->token();

            // E736-F2/6.6 (round 85): cancellation resolves with NULL (the
            // consumer program discards null resolutions) instead of rejecting
            // — see the identical fix in Input::scheduleAsyncSuggestions().
            $token->onCancel(static function () use ($deferred): void {
                $deferred->resolve(null);
            });

            // Schedule the debounce timer
            Loop::addTimer($debounceMs / 1000.0, function () use ($fetcher, $fieldKey, $currentSeq, $field, $deferred, $token, $scheduledFilterText, $timeoutSeconds): void {
                // Check if cancelled before proceeding
                if ($token->isCancelled()) {
                    return;
                }

                // Call the fetcher to get a promise
                $promise = $fetcher($scheduledFilterText);

                // E736-F2/6.5: opt-in wall-clock ceiling per fetch (null = raw await).
                if ($timeoutSeconds !== null) {
                    $promise = AsyncOps::withTimeout(Loop::get(), $promise, $timeoutSeconds);
                }

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

    /**
     * Select carries no validator surface, so it can never hold an error:
     * its value is by construction the highlighted {@see ItemList} entry
     * (or null when empty), which is always a legal choice. These two are
     * the correct no-op of the Field contract (E736 1.3) —
     * {@see \SugarCraft\Forms\Field::revalidate()} degenerates to
     * "re-run validators" over an empty validator set, returning `$this`
     * unchanged, exactly as the contract's idempotence clause demands.
     * Any future change that gives Select a validator/constraint MUST
     * replace both — until then Form::validateAll() passing a Select
     * through untouched is intended, not a silent skip.
     */
    public function getError(): ?string       { return null; }
    public function revalidate(): Field       { return $this; }
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
        return $this->carryNonCtorState(new self(
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
            asyncSuggestionsFetchTimeoutSeconds: $this->asyncSuggestionsFetchTimeoutSeconds,
            enumClass:                 $enumClass  ?? $this->enumClass,
        ));
    }
}