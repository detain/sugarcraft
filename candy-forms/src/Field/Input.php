<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Field;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\SuggestionsReadyMsg;
use SugarCraft\Core\WorkerPool;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Fuzzy\FuzzyMatcher;
use SugarCraft\Forms\HasDynamicLabels;
use SugarCraft\Forms\HasHideFunc;
use SugarCraft\Forms\TextInput\TextInput;
use SugarCraft\Forms\Validator\Validator;

/**
 * Single-line text field. Wraps a {@see TextInput} and exposes an
 * optional validator that runs on every keystroke.
 */
final class Input implements \SugarCraft\Forms\Field
{
    use HasHideFunc;
    use HasDynamicLabels;

    /**
     * @var list<\Closure(string):?string>
     */
    private array $validators = [];

    /** @var (\Closure(string):list<string>)|null */
    private $suggestionsFunc;

    /** @var list<string> */
    private array $fuzzyCandidates = [];

    /** @var callable|null Async suggestions fetcher: receives input value, returns PromiseInterface<list<string>> */
    private $asyncSuggestionsFetcher = null;

    /** @var int Debounce delay in ms for async suggestions */
    private int $asyncSuggestionsDebounceMs = 150;

    /** @var int Sequence counter for pending async operations (for cancellation) */
    private int $pendingAsyncSeq = 0;

    /** @var CancellationSource|null Cancellation source for the pending async operation */
    private ?CancellationSource $pendingAsyncCancellation = null;

    /** @var WorkerPool|null */
    private $workerPool = null;

    private function __construct(
        public readonly string $key,
        public readonly TextInput $input,
        public readonly string $title,
        public readonly string $description,
        public readonly ?string $error,
        array $validators = [],
        ?\Closure $suggestionsFunc = null,
        array $fuzzyCandidates = [],
        callable $asyncSuggestionsFetcher = null,
        int $asyncSuggestionsDebounceMs = 150,
        int $pendingAsyncSeq = 0,
        ?CancellationSource $pendingAsyncCancellation = null,
        WorkerPool $workerPool = null,
    ) {
        $this->validators = $validators;
        $this->suggestionsFunc = $suggestionsFunc;
        $this->fuzzyCandidates = $fuzzyCandidates;
        $this->asyncSuggestionsFetcher = $asyncSuggestionsFetcher;
        $this->asyncSuggestionsDebounceMs = $asyncSuggestionsDebounceMs;
        $this->pendingAsyncSeq = $pendingAsyncSeq;
        $this->pendingAsyncCancellation = $pendingAsyncCancellation;
        $this->workerPool = $workerPool;
    }

    public static function new(string $key): self
    {
        return new self(
            key: $key,
            input: TextInput::new(),
            title: '',
            description: '',
            error: null,
            validators: [],
            asyncSuggestionsFetcher: null,
            asyncSuggestionsDebounceMs: 150,
            pendingAsyncSeq: 0,
            pendingAsyncCancellation: null,
            workerPool: null,
        );
    }

    public function withTitle(string $t): self       { return $this->mutate(title: $t); }
    public function withDescription(string $d): self { return $this->mutate(description: $d); }
    public function withPlaceholder(string $p): self { return $this->mutate(input: $this->input->withPlaceholder($p)); }
    public function withPrompt(string $p): self      { return $this->mutate(input: $this->input->withPrompt($p)); }
    public function withCharLimit(int $n): self      { return $this->mutate(input: $this->input->withCharLimit($n)); }
    public function withWidth(int $w): self          { return $this->mutate(input: $this->input->withWidth($w)); }

    // Short-form aliases: same behavior, less typing.
    public function title(string $t): self        { return $this->withTitle($t); }
    public function desc(string $d): self         { return $this->withDescription($d); }
    public function placeholder(string $p): self  { return $this->withPlaceholder($p); }
    public function prompt(string $p): self       { return $this->withPrompt($p); }
    public function charLimit(int $n): self       { return $this->withCharLimit($n); }
    public function width(int $w): self           { return $this->withWidth($w); }
    public function password(bool $on = true, string $echoChar = '*'): self { return $this->withPassword($on, $echoChar); }
    public function suggest(array $candidates): self { return $this->withSuggestions($candidates); }
    public function fuzzy(array $candidates): self { return $this->withFuzzySuggestions($candidates); }
    public function validator(\Closure $fn): self { return $this->withValidator($fn); }
    public function validation(callable $predicate, string $errorMessage): self { return $this->withValidation($predicate, $errorMessage); }
    public function required(): self { return $this->withValidator(new \SugarCraft\Forms\Validator\Required()); }
    public function email(): self { return $this->withValidator(new \SugarCraft\Forms\Validator\Email()); }
    public function minlength(int $n): self { return $this->withValidator(new \SugarCraft\Forms\Validator\MinLength($n)); }
    public function maxlength(int $n): self { return $this->withValidator(new \SugarCraft\Forms\Validator\MaxLength($n)); }

    /**
     * Attach a pending async cancellation source.
     *
     * @internal
     */
    public function withPendingAsyncCancellation(CancellationSource $cancellationSource): self
    {
        return new self(
            key:                       $this->key,
            input:                     $this->input,
            title:                     $this->title,
            description:               $this->description,
            error:                     $this->error,
            validators:                $this->validators,
            suggestionsFunc:           $this->suggestionsFunc,
            fuzzyCandidates:           $this->fuzzyCandidates,
            asyncSuggestionsFetcher:  $this->asyncSuggestionsFetcher,
            asyncSuggestionsDebounceMs: $this->asyncSuggestionsDebounceMs,
            pendingAsyncSeq:           $this->pendingAsyncSeq,
            pendingAsyncCancellation:  $cancellationSource,
            workerPool:                $this->workerPool,
        );
    }

    /**
     * Mask the rendered value with a fixed echo character. Mirrors huh's
     * `Password()` modifier — handy for token / passphrase prompts.
     * Pass empty string to clear masking.
     */
    public function withPassword(bool $on = true, string $echoChar = '*'): self
    {
        if ($on) {
            $next = $this->input
                ->withEchoMode(\SugarCraft\Forms\TextInput\EchoMode::Password)
                ->withEchoChar($echoChar === '' ? '*' : $echoChar);
        } else {
            $next = $this->input->withEchoMode(\SugarCraft\Forms\TextInput\EchoMode::Normal);
        }
        return $this->mutate(input: $next);
    }

    /**
     * Provide an autocomplete pool that the user can cycle with the
     * default TextInput suggestion bindings. Pass an empty list (or omit)
     * to disable. Mirrors huh's `Suggestions([]string)`.
     *
     * @param list<string> $candidates
     */
    public function withSuggestions(array $candidates): self
    {
        $next = $this->input
            ->withSuggestions($candidates)
            ->showSuggestions($candidates !== []);
        return $this->mutate(input: $next);
    }

    /**
     * Dynamic suggestions: the closure receives the current input value
     * (post-keystroke) and returns the candidate list to display. Mirrors
     * huh's `SuggestionsFunc`. The closure is re-evaluated on every
     * keystroke so callers can hit a remote completion endpoint, etc.
     *
     * @param \Closure(string):list<string> $fn
     */
    public function withSuggestionsFunc(\Closure $fn): self
    {
        return new self(
            key:                       $this->key,
            input:                     $this->input,
            title:                     $this->title,
            description:               $this->description,
            error:                     $this->error,
            validators:                $this->validators,
            suggestionsFunc:           $fn,
            fuzzyCandidates:           $this->fuzzyCandidates,
            asyncSuggestionsFetcher:  $this->asyncSuggestionsFetcher,
            asyncSuggestionsDebounceMs: $this->asyncSuggestionsDebounceMs,
            pendingAsyncSeq:           $this->pendingAsyncSeq,
            pendingAsyncCancellation:  $this->pendingAsyncCancellation,
            workerPool:                $this->workerPool,
        );
    }

    /**
     * Provide an autocomplete pool that is filtered and ranked by fuzzy
     * substring match (Smith-Waterman scoring) against the current input
     * value. The user picks from the ranked suggestion list via arrow keys.
     * Mirrors huh's `WithFuzzySuggestions([]string)`.
     *
     * @param list<string> $candidates
     */
    public function withFuzzySuggestions(array $candidates): self
    {
        $matcher = new FuzzyMatcher();
        $pool = $candidates;

        $fn = static function (string $input) use ($matcher, $pool): array {
            if ($input === '') {
                return [];
            }
            $scored = $matcher->match($input, $pool);
            return array_column($scored, 0);
        };

        return new self(
            key:                       $this->key,
            input:                     $this->input,
            title:                     $this->title,
            description:               $this->description,
            error:                     $this->error,
            validators:                $this->validators,
            suggestionsFunc:           $fn,
            fuzzyCandidates:           $candidates,
            asyncSuggestionsFetcher:  $this->asyncSuggestionsFetcher,
            asyncSuggestionsDebounceMs: $this->asyncSuggestionsDebounceMs,
            pendingAsyncSeq:           $this->pendingAsyncSeq,
            pendingAsyncCancellation:  $this->pendingAsyncCancellation,
            workerPool:                $this->workerPool,
        );
    }

    /**
     * Async suggestions via a callable that returns a Promise.
     * Debounces for $debounceMs after each keystroke, then calls the fetcher.
     * Uses WorkerPool to run the fetch off the main event loop.
     * Mirrors huh's async suggestion pattern.
     *
     * @param callable(string):PromiseInterface<list<string>> $fetcher Receives current input value, returns promise of suggestions
     * @param int $debounceMs Milliseconds to wait after last keystroke before fetching (default 150)
     * @param WorkerPool|null $workerPool Optional worker pool for offloading; uses Loop::get() if not provided
     */
    public function withAsyncSuggestions(callable $fetcher, int $debounceMs = 150, WorkerPool $workerPool = null): self
    {
        return new self(
            key:                       $this->key,
            input:                     $this->input,
            title:                     $this->title,
            description:               $this->description,
            error:                     $this->error,
            validators:                $this->validators,
            suggestionsFunc:           $this->suggestionsFunc,
            fuzzyCandidates:           $this->fuzzyCandidates,
            asyncSuggestionsFetcher:  $fetcher,
            asyncSuggestionsDebounceMs: $debounceMs,
            pendingAsyncSeq:           $this->pendingAsyncSeq,
            pendingAsyncCancellation:  $this->pendingAsyncCancellation,
            workerPool:                $workerPool,
        );
    }

    /**
     * Short-form alias for withAsyncSuggestions.
     */
    public function async(callable $fetcher, int $debounceMs = 150, WorkerPool $workerPool = null): self
    {
        return $this->withAsyncSuggestions($fetcher, $debounceMs, $workerPool);
    }

    /**
     * Attach a validator. Accepts a Validator instance or a closure.
     * Multiple calls chain validators together — each runs in sequence
     * and the first error message is returned.
     *
     * @param Validator|\Closure(string):?string $validator
     */
    public function withValidator(Validator|\Closure $validator): self
    {
        if ($validator instanceof Validator) {
            $fn = static fn (string $v): ?string => match (true) {
                $validator->validate($v) === true => null,
                default => (string) $validator->validate($v),
            };
        } else {
            $fn = $validator;
        }

        $chained = $this->buildChainedValidator($fn);
        return new self(
            key:                       $this->key,
            input:                     $this->input,
            title:                     $this->title,
            description:               $this->description,
            error:                     $this->error,
            validators:                $chained,
            suggestionsFunc:           $this->suggestionsFunc,
            fuzzyCandidates:           $this->fuzzyCandidates,
            asyncSuggestionsFetcher:  $this->asyncSuggestionsFetcher,
            asyncSuggestionsDebounceMs: $this->asyncSuggestionsDebounceMs,
            pendingAsyncSeq:           $this->pendingAsyncSeq,
            pendingAsyncCancellation:  $this->pendingAsyncCancellation,
            workerPool:                $this->workerPool,
        );
    }

    /**
     * Build a new validator closure that runs $fn in sequence with
     * the existing chained validator (first error wins).
     *
     * @param \Closure(string):?string $fn
     * @return list<\Closure(string):?string>
     */
    private function buildChainedValidator(\Closure $fn): array
    {
        $existing = $this->validators;

        // If no existing validators, just return the new one wrapped in array.
        if ($existing === []) {
            return [$fn];
        }

        // Chain: run existing first, then $fn.
        $chained = static function (string $v) use ($existing, $fn): ?string {
            foreach ($existing as $vfn) {
                $err = $vfn($v);
                if ($err !== null) {
                    return $err;
                }
            }
            return $fn($v);
        };

        return [$chained];
    }

    /**
     * Attach a validation rule using a predicate + error message.
     * The predicate receives the current value and returns true if valid,
     * false if invalid. When invalid the $errorMessage is displayed.
     */
    public function withValidation(callable $predicate, string $errorMessage): self
    {
        return $this->withValidator(
            static fn (string $value): ?string => $predicate($value) ? null : $errorMessage,
        );
    }

    public function key(): string  { return $this->key; }
    public function value(): mixed { return $this->input->value; }

    public function focus(): array
    {
        [$ti, $cmd] = $this->input->focus();
        return [$this->mutate(input: $ti), $cmd];
    }

    public function blur(): Field
    {
        return $this->mutate(input: $this->input->blur());
    }

    public function update(Msg $msg): array
    {
        // Handle async suggestions result
        if ($msg instanceof SuggestionsReadyMsg && $msg->fieldKey === $this->key) {
            $next = $this->mutate(
                input: $this->input
                    ->withSuggestions($msg->suggestions)
                    ->showSuggestions($msg->suggestions !== []),
            );
            return [$next, null];
        }

        [$ti, $cmd] = $this->input->update($msg);
        if ($this->suggestionsFunc !== null) {
            $candidates = ($this->suggestionsFunc)($ti->value);
            $ti = $ti->withSuggestions($candidates)->showSuggestions($candidates !== []);
        }
        $next = $this->mutate(input: $ti);
        $next = $next->validate();

        // Schedule async suggestions with debounce on Char keystroke
        // Cancel any previously pending operation so only the latest keystroke fires
        if ($this->asyncSuggestionsFetcher !== null
            && $msg instanceof \SugarCraft\Core\Msg\KeyMsg
            && $msg->type === \SugarCraft\Core\KeyType::Char
        ) {
            // Cancel previous pending async
            $this->pendingAsyncCancellation?->cancel();
            // Create a new cancellation source and store it on $next
            $next = $next->withPendingAsyncCancellation(CancellationSource::new());
            $asyncCmd = $this->scheduleAsyncSuggestions($next);
            // Combine with any existing cmd from the synchronous update
            if ($cmd !== null) {
                // Return combined cmd (both must run)
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
     * @return \Closure|null Returns a Cmd closure, or null if no async suggestions
     */
    private function scheduleAsyncSuggestions(self $field): ?\Closure
    {
        // Create a new cancellation source for this operation
        $cancellationSource = CancellationSource::new();
        $fetcher = $this->asyncSuggestionsFetcher;
        $debounceMs = $this->asyncSuggestionsDebounceMs;
        $currentSeq = ++$this->pendingAsyncSeq;
        $fieldKey = $this->key;
        $workerPool = $this->workerPool;

        return function () use ($fetcher, $debounceMs, $currentSeq, $fieldKey, $field, $workerPool, $cancellationSource): \SugarCraft\Core\AsyncCmd {
            $deferred = new Deferred();
            $token = $cancellationSource->token();

            // Register cancellation: if the user types again, this will fire
            // and reject the deferred before the timer fires.
            $token->onCancel(static function () use ($deferred): void {
                $deferred->reject(new \RuntimeException('Async suggestions cancelled'));
            });

            // Schedule the debounce timer
            Loop::addTimer($debounceMs / 1000.0, function () use ($fetcher, $fieldKey, $currentSeq, $field, $deferred, $token, $cancellationSource): void {
                // Check if cancelled before proceeding
                if ($token->isCancelled()) {
                    return;
                }

                // Get current input value
                $inputValue = $field->input->value;

                // Call the fetcher to get a promise
                $promise = $fetcher($inputValue);

                // Chain to resolve the deferred when the fetcher promise resolves
                $promise->then(
                    function (array $suggestions) use ($deferred, $fieldKey, $field): void {
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
        if ($title !== '') {
            $lines[] = $title;
        }
        if ($desc !== '') {
            $lines[] = $desc;
        }
        $lines[] = $this->input->view();
        if ($this->error !== null) {
            $lines[] = '! ' . $this->error;
        }
        return implode("\n", $lines);
    }

    public function isFocused(): bool        { return $this->input->focused; }
    public function getTitle(): string       { return $this->resolveTitle($this->title); }
    public function getDescription(): string { return $this->resolveDescription($this->description); }
    public function getError(): ?string      { return $this->error; }
    public function skippable(): bool        { return false; }
    public function consumes(Msg $msg): bool { return false; }

    private function validate(): self
    {
        if ($this->validators === []) {
            return $this;
        }
        foreach ($this->validators as $vfn) {
            $err = $vfn($this->input->value);
            if ($err !== null) {
                if ($err === $this->error) {
                    return $this;
                }
                return new self(
                    key:                       $this->key,
                    input:                     $this->input,
                    title:                     $this->title,
                    description:               $this->description,
                    error:                     $err,
                    validators:                $this->validators,
                    suggestionsFunc:           $this->suggestionsFunc,
                    fuzzyCandidates:           $this->fuzzyCandidates,
                    asyncSuggestionsFetcher:  $this->asyncSuggestionsFetcher,
                    asyncSuggestionsDebounceMs: $this->asyncSuggestionsDebounceMs,
                    pendingAsyncSeq:           $this->pendingAsyncSeq,
                    pendingAsyncCancellation:  $this->pendingAsyncCancellation,
                    workerPool:                $this->workerPool,
                );
            }
        }
        if ($this->error !== null) {
            return new self(
                key:                       $this->key,
                input:                     $this->input,
                title:                     $this->title,
                description:               $this->description,
                error:                     null,
                validators:                $this->validators,
                suggestionsFunc:           $this->suggestionsFunc,
                fuzzyCandidates:           $this->fuzzyCandidates,
                asyncSuggestionsFetcher:  $this->asyncSuggestionsFetcher,
                asyncSuggestionsDebounceMs: $this->asyncSuggestionsDebounceMs,
                pendingAsyncSeq:           $this->pendingAsyncSeq,
                pendingAsyncCancellation:  $this->pendingAsyncCancellation,
                workerPool:                $this->workerPool,
            );
        }
        return $this;
    }

    private function mutate(?TextInput $input = null, ?string $title = null, ?string $description = null, ?string $error = null): self
    {
        return new self(
            key:                       $this->key,
            input:                     $input       ?? $this->input,
            title:                     $title       ?? $this->title,
            description:               $description ?? $this->description,
            error:                     $error       ?? $this->error,
            validators:                $this->validators,
            suggestionsFunc:           $this->suggestionsFunc,
            fuzzyCandidates:           $this->fuzzyCandidates,
            asyncSuggestionsFetcher:  $this->asyncSuggestionsFetcher,
            asyncSuggestionsDebounceMs: $this->asyncSuggestionsDebounceMs,
            pendingAsyncSeq:           $this->pendingAsyncSeq,
            pendingAsyncCancellation:  $this->pendingAsyncCancellation,
            workerPool:                $this->workerPool,
        );
    }
}