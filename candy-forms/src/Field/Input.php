<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Field;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\TimeoutException;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\SuggestionsReadyMsg;
use SugarCraft\Core\WorkerPool;
use SugarCraft\Forms\AsyncValidatable;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Fuzzy\FuzzyMatcher;
use SugarCraft\Forms\CarriesNonCtorState;
use SugarCraft\Forms\HasAsyncValidation;
use SugarCraft\Forms\HasDynamicLabels;
use SugarCraft\Forms\HasErrorHelp;
use SugarCraft\Forms\HasHideFunc;
use SugarCraft\Forms\HasReadonly;
use SugarCraft\Forms\TextInput\TextInput;
use SugarCraft\Forms\TextInput\ValidateOn;
use SugarCraft\Forms\Util\RenderSafe;
use SugarCraft\Forms\Validator\Validator;

/**
 * Single-line text field. Wraps a {@see TextInput} and exposes an
 * optional validator that runs on every keystroke.
 */
final class Input implements \SugarCraft\Forms\Field, \SugarCraft\Forms\AsyncValidatable
{
    use HasAsyncValidation;
    use HasErrorHelp;
    use HasHideFunc;
    use HasDynamicLabels;
    use HasReadonly;
    use CarriesNonCtorState;

    /**
     * @var list<\Closure(string):?string>
     */
    private array $validators = [];

    /**
     * When the Field-level validator chain runs. Defaults to
     * {@see ValidateOn::None} (validate on every keystroke) to preserve the
     * historical validate-on-change behaviour. When set to
     * {@see ValidateOn::Blur} or {@see ValidateOn::Submit} the chain is NOT
     * run per-keystroke — it runs on {@see blur()} / {@see revalidate()}
     * respectively — which also avoids paying a per-keystroke ReDoS cost for
     * expensive validators (e.g. a catastrophic {@see Validator\Pattern}).
     */
    private ValidateOn $validateOn = ValidateOn::None;

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

    /**
     * @var WorkerPool|null RESERVED (E736 1.5): stored by
     * {@see withAsyncSuggestions()} and carried by every mutation path, but
     * {@see scheduleAsyncSuggestions()} never dispatches through it — the
     * debounced fetch always runs on the main loop. Kept as the landing
     * point for the Phase-6 offload wiring; see the method's
     * WORKER POOL STATUS note.
     */
    private $workerPool = null;

    /** @var float|null E736-F2/6.5 wall-clock ceiling in seconds per debounced async fetch; null = no added timeout */
    private ?float $asyncSuggestionsFetchTimeoutSeconds = null;

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
        ValidateOn $validateOn = ValidateOn::None,
        ?float $asyncSuggestionsFetchTimeoutSeconds = null,
    ) {
        $this->validators = $validators;
        $this->suggestionsFunc = $suggestionsFunc;
        $this->fuzzyCandidates = $fuzzyCandidates;
        $this->asyncSuggestionsFetcher = $asyncSuggestionsFetcher;
        $this->asyncSuggestionsDebounceMs = $asyncSuggestionsDebounceMs;
        $this->pendingAsyncSeq = $pendingAsyncSeq;
        $this->pendingAsyncCancellation = $pendingAsyncCancellation;
        $this->workerPool = $workerPool;
        $this->validateOn = $validateOn;
        $this->asyncSuggestionsFetchTimeoutSeconds = $asyncSuggestionsFetchTimeoutSeconds;
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
    /** Pre-fill the editable text with an initial value. Unlike a placeholder this is the actual submitted value until the user edits it. Pass an empty string to clear. */
    public function withValue(string $value): self   { return $this->mutate(input: $this->input->setValue($value)); }
    public function withPrompt(string $p): self      { return $this->mutate(input: $this->input->withPrompt($p)); }
    public function withCharLimit(int $n): self      { return $this->mutate(input: $this->input->withCharLimit($n)); }
    public function withWidth(int $w): self          { return $this->mutate(input: $this->input->withWidth($w)); }
    /**
     * E736 5.4 — rebind keys inside this field's input domain. The map lives on
     * the inner TextInput (single owner of the edit primitives); spellings +
     * actions are validated at set time — see {@see \SugarCraft\Forms\HasKeyOverrides}.
     *
     * @param array<string,string> $map e.g. ['ctrl+u' => 'move_end']
     */
    public function withKeyOverrides(array $map): self { return $this->mutate(input: $this->input->withKeyOverrides($map)); }
    /** The parsed override map carried by the inner widget. @return array<string,string> */
    public function keyOverrides(): array             { return $this->input->keyOverrides(); }

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
        return $this->carryNonCtorState(new self(
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
            validateOn:                $this->validateOn,
            asyncSuggestionsFetchTimeoutSeconds: $this->asyncSuggestionsFetchTimeoutSeconds,
        ));
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
     * E736 5.5 — the pattern-based upgrade of {@see withPassword()}: matched
     * characters echo as `$echoChar`, the rest render verbatim (partial masks
     * like `4111 **** **** 1111`). Mirrors huh's Password() in spirit; the
     * pattern granularity is the SugarCraft extension. Null clears back to
     * plain rendering. The pattern is compile-checked at this door (with the
     * renderer's own `/u` flags) and throws loudly — it can never silently
     * disable masking.
     */
    public function withPasswordMask(?string $pattern, string $echoChar = '*'): self
    {
        $next = $pattern === null
            ? $this->input->clearMask()
            : $this->input->withMask($pattern, $echoChar);
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
        return $this->carryNonCtorState(new self(
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
            validateOn:                $this->validateOn,
            asyncSuggestionsFetchTimeoutSeconds: $this->asyncSuggestionsFetchTimeoutSeconds,
        ));
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

        return $this->carryNonCtorState(new self(
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
            validateOn:                $this->validateOn,
            asyncSuggestionsFetchTimeoutSeconds: $this->asyncSuggestionsFetchTimeoutSeconds,
        ));
    }

    /**
     * Async suggestions via a callable that returns a Promise.
     * Debounces for $debounceMs after each keystroke, then calls the fetcher.
     * Mirrors huh's async suggestion pattern.
     *
     * WORKER POOL STATUS (E736 1.5): the fetch runs on the MAIN event loop —
     * {@see scheduleAsyncSuggestions()} debounces via {@see Loop::addTimer()}
     * and invokes the fetcher in-loop; the stored {@see WorkerPool} is
     * captured into the scheduling closure but never consulted (see the
     * property docblock). The parameter is accepted for signature parity
     * with the planned offload path — wiring real offloading is deferred to
     * the follow-up async lane (findings/plan_candy-forms.md Phase 6.3).
     * Until then passing a pool has NO effect, and fetchers that block will
     * block the loop.
     *
     * ASYNC DESIGN (E736-F2/6.1-6.2-6.4, round 85): the fetcher promise is
     * single-resolve by contract — suggestions arrive whole via
     * SuggestionsReadyMsg and only the newest keystroke's settlement is
     * honoured (superseded fetches settle quietly with null, 6.6). Streaming
     * partial suggestion lists (6.1) would need a public AsyncCmd extension
     * in candy-core and stay out of scope for this library. A unified
     * cross-field AsyncSuggestionRequest object (6.2) was judged
     * unnecessary: Input and Select each own their debounce/cancel pair and
     * share no state to reconcile today. Many short-lived debounce timers
     * (6.4) are acceptable by design — each armed timer fires at most once
     * into a cancellation check and neither timer nor fetch outlives its
     * keystroke's replacement. Cold-start timing (6.9): the AsyncCmd
     * promise settles only once the host Program is running the event
     * loop, and its resolution is dispatched as an ordinary message, so a
     * fetch scheduled from the very first update() cannot land before
     * init() — pre-seed suggestions via the focus()/init Cmd chain when a
     * cold list is wanted.
     *
     * @param callable(string):PromiseInterface<list<string>> $fetcher Receives current input value, returns promise of suggestions
     * @param int $debounceMs Milliseconds to wait after last keystroke before fetching (default 150)
     * @param WorkerPool|null $workerPool RESERVED — stored, never consulted; see WORKER POOL STATUS above
     * @param float|null $fetchTimeoutSeconds E736-F2/6.5: when set, each in-flight
     *   fetch is raced against this wall-clock ceiling (seconds) via
     *   {@see AsyncOps::withTimeout()}; a breach rejects with
     *   {@see TimeoutException} (surfaced as the fetcher failing). Default null
     *   awaits the fetcher promise with NO added timeout — byte-identical to
     *   pre-E736 behaviour.
     */
    public function withAsyncSuggestions(callable $fetcher, int $debounceMs = 150, WorkerPool $workerPool = null, ?float $fetchTimeoutSeconds = null): self
    {
        return $this->carryNonCtorState(new self(
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
            validateOn:                $this->validateOn,
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

    /**
     * Attach a validator. Accepts a Validator instance or a closure.
     * Multiple calls chain validators together — each runs in sequence
     * and the first error message is returned.
     *
     * E736-F2/2.3 (round 85): the chain is an append to the indexed list
     * that {@see self::validate()} already walks. It used to RE-WRAP the
     * whole accumulated chain into one fresh closure per attach, so the
     * Nth `withValidator()` built an N-deep nested wrapper (O(N²) closure
     * graph) while `validate()` meanwhile expected a flat list and only
     * ever saw one element. Appending keeps attach O(1) and first-error-
     * wins ordering byte-identical.
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

        return $this->carryNonCtorState(new self(
            key:                       $this->key,
            input:                     $this->input,
            title:                     $this->title,
            description:               $this->description,
            error:                     $this->error,
            validators:                [...$this->validators, $fn],
            suggestionsFunc:           $this->suggestionsFunc,
            fuzzyCandidates:           $this->fuzzyCandidates,
            asyncSuggestionsFetcher:  $this->asyncSuggestionsFetcher,
            asyncSuggestionsDebounceMs: $this->asyncSuggestionsDebounceMs,
            pendingAsyncSeq:           $this->pendingAsyncSeq,
            pendingAsyncCancellation:  $this->pendingAsyncCancellation,
            workerPool:                $this->workerPool,
            validateOn:                $this->validateOn,
            asyncSuggestionsFetchTimeoutSeconds: $this->asyncSuggestionsFetchTimeoutSeconds,
        ));
    }

    /**
     * Control WHEN the Field-level validator chain runs. Mirrors the timing
     * modes of the inner {@see TextInput} but applies to the Field's own
     * validators (which run independently of the TextInput's).
     *
     * - {@see ValidateOn::None} / {@see ValidateOn::Change} — validate on
     *   every keystroke (the default, backward-compatible behaviour).
     * - {@see ValidateOn::Blur} — validate only when the field loses focus
     *   (see {@see blur()}).
     * - {@see ValidateOn::Submit} — validate only when the form re-validates
     *   the field (see {@see revalidate()}), i.e. on submit.
     *
     * Deferring to Blur/Submit avoids running an expensive validator on every
     * keystroke — notably a catastrophic {@see Validator\Pattern} whose PCRE
     * would otherwise pay its (potentially ReDoS-scale) cost per keypress.
     */
    public function withValidateOn(ValidateOn $timing): self
    {
        return $this->carryNonCtorState(new self(
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
            pendingAsyncCancellation:  $this->pendingAsyncCancellation,
            workerPool:                $this->workerPool,
            validateOn:                $timing,
            asyncSuggestionsFetchTimeoutSeconds: $this->asyncSuggestionsFetchTimeoutSeconds,
        ));
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
        $next = $this->mutate(input: $this->input->blur());
        // When timing is Blur, run the deferred validator chain now.
        if ($this->validateOn === ValidateOn::Blur) {
            $next = $next->validate();
        }
        return $next;
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

        if ($msg instanceof \SugarCraft\Core\Msg\KeyMsg && $this->isReadonly()) {
            return [$this, null];
        }

        [$ti, $cmd] = $this->input->update($msg);
        if ($this->suggestionsFunc !== null) {
            $candidates = ($this->suggestionsFunc)($ti->value);
            $ti = $ti->withSuggestions($candidates)->showSuggestions($candidates !== []);
        }
        $next = $this->mutate(input: $ti);
        // Only validate per-keystroke when timing is None/Change (the default).
        // Blur/Submit defer the chain to blur()/revalidate() so an expensive
        // validator (e.g. a catastrophic Pattern) isn't paid on every keypress.
        if ($this->validateOn === ValidateOn::None || $this->validateOn === ValidateOn::Change) {
            $next = $next->validate();
        }

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
                // Both the inner blink/tick Cmd and the debounced fetch Cmd must run.
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
     * the user types again before the debounce window elapses. Cancellation
     * resolves the previous AsyncCmd with null — Program discards null
     * resolutions — so replaced keystrokes no longer surface as rejected
     * promises / ExceptionMsg storms (E736-F2/6.6).
     *
     * @param self $field  The field instance to use for getting current input value
     * @return \Closure|null Returns a Cmd closure, or null if no async suggestions
     */
    private function scheduleAsyncSuggestions(self $field): ?\Closure
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
        $workerPool = $this->workerPool;

        return function () use ($fetcher, $debounceMs, $timeoutSeconds, $currentSeq, $fieldKey, $field, $workerPool, $cancellationSource): \SugarCraft\Core\AsyncCmd {
            $deferred = new Deferred();
            $token = $cancellationSource->token();

            // E736-F2/6.6 (round 85): cancellation resolves the deferred with
            // NULL instead of rejecting. The consumer program discards a null
            // resolution (candy-core Program only dispatches non-null msgs),
            // while a rejection rides AsyncCmd's otherwise-arm as an
            // ExceptionMsg + error log PER REPLACED KEYSTROKE — the old shape
            // turned ordinary rapid typing into a spurious error storm. A
            // genuine fetcher failure still rejects (kept below); only the
            // superseded-by-typing path goes quiet.
            $token->onCancel(static function () use ($deferred): void {
                $deferred->resolve(null);
            });

            // Schedule the debounce timer
            Loop::addTimer($debounceMs / 1000.0, function () use ($fetcher, $fieldKey, $currentSeq, $field, $deferred, $token, $cancellationSource, $timeoutSeconds): void {
                // Check if cancelled before proceeding
                if ($token->isCancelled()) {
                    return;
                }

                // Get current input value
                $inputValue = $field->input->value;

                // Call the fetcher to get a promise
                $promise = $fetcher($inputValue);

                // E736-F2/6.5: opt-in wall-clock ceiling per fetch. Null
                // (default) skips the wrapper entirely — byte-identical await
                // of the raw fetcher promise.
                if ($timeoutSeconds !== null) {
                    $promise = AsyncOps::withTimeout(Loop::get(), $promise, $timeoutSeconds);
                }

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
            $lines[] = $title . $this->readonlyTitleSuffix();
        }
        if ($desc !== '') {
            $lines[] = $desc;
        }
        $lines[] = $this->input->view();
        if ($this->error !== null) {
            // Validator messages can echo user input — clean at the display
            // site (the stored $this->error / getError() stay raw).
            $lines[] = '! ' . RenderSafe::clean($this->error);
        }
        foreach ($this->errorHelpLines() as $helpLine) {
            $lines[] = $helpLine;
        }
        return implode("\n", $lines);
    }

    public function isFocused(): bool        { return $this->input->focused; }
    public function getTitle(): string       { return $this->resolveTitle($this->title); }
    public function getDescription(): string { return $this->resolveDescription($this->description); }
    public function getError(): ?string      { return $this->error; }
    public function revalidate(): Field      { return $this->validate(); }
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
                return $this->carryNonCtorState(new self(
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
                    validateOn:                $this->validateOn,
                    asyncSuggestionsFetchTimeoutSeconds: $this->asyncSuggestionsFetchTimeoutSeconds,
                ));
            }
        }
        if ($this->error !== null) {
            return $this->carryNonCtorState(new self(
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
                validateOn:                $this->validateOn,
                asyncSuggestionsFetchTimeoutSeconds: $this->asyncSuggestionsFetchTimeoutSeconds,
            ));
        }
        return $this;
    }

    private function mutate(?TextInput $input = null, ?string $title = null, ?string $description = null, ?string $error = null, bool $errorSet = false): self
    {
        return $this->carryNonCtorState(new self(
            key:                       $this->key,
            input:                     $input       ?? $this->input,
            title:                     $title       ?? $this->title,
            description:               $description ?? $this->description,
            error:                     $errorSet ? $error : $this->error,
            validators:                $this->validators,
            suggestionsFunc:           $this->suggestionsFunc,
            fuzzyCandidates:           $this->fuzzyCandidates,
            asyncSuggestionsFetcher:  $this->asyncSuggestionsFetcher,
            asyncSuggestionsDebounceMs: $this->asyncSuggestionsDebounceMs,
            pendingAsyncSeq:           $this->pendingAsyncSeq,
            pendingAsyncCancellation:  $this->pendingAsyncCancellation,
            workerPool:                $this->workerPool,
            validateOn:                $this->validateOn,
            asyncSuggestionsFetchTimeoutSeconds: $this->asyncSuggestionsFetchTimeoutSeconds,
        ));
    }
}