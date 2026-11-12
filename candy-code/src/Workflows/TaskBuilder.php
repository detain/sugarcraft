<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

use SugarCraft\Code\Agents\Effort;
use SugarCraft\Code\Agents\Isolation;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\JsonSchemaArgument;

/**
 * Fluent builder for assembling a WorkflowTask.
 *
 * Mirrors the DSL usage seen in workflow definitions:
 *   Tasks::agent('architect')->prompt('...')->tools([...])
 *
 * Each method returns $this for chaining, and build() produces
 * the immutable WorkflowTask DTO.
 */
final class TaskBuilder
{
    private string $agentType = '';
    private string $prompt = '';
    private array $tools = [];
    private ?int $timeout = null;
    private ?int $retries = null;
    private ?Isolation $isolation = null;
    private ?string $name = null;
    private array|string|null $outputSchema = null;
    private ?string $model = null;
    private ?string $reject = null;
    private int $rejectAtLeast = 1;
    private ?string $resource = null;
    private ?int $lockWaitSeconds = null;
    private ?int $stallTimeout = null;
    private ?int $restarts = null;
    /** @var array{from: list<string>, as: string}|null */
    private ?array $input = null;
    private int $votes = 1;
    /** @var list<string>|null */
    private ?array $lenses = null;
    private ?int $quorum = null;
    private ?string $preset = null;
    private ?Effort $effort = null;

    /**
     * Set the agent type (e.g. 'architect', 'coder', 'reviewer').
     */
    public function agent(string $agentType): self
    {
        $this->agentType = $agentType;
        return $this;
    }

    /**
     * Set the task prompt text.
     */
    public function prompt(string $prompt): self
    {
        $this->prompt = $prompt;
        return $this;
    }

    /**
     * Set the list of available tools for this task.
     *
     * @param array<string> $tools
     */
    public function tools(array $tools): self
    {
        $this->tools = $tools;
        return $this;
    }

    /**
     * Set the timeout in seconds for this task.
     */
    public function timeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * Set the number of retries allowed for this task.
     */
    public function retries(int $retries): self
    {
        $this->retries = $retries;
        return $this;
    }

    /**
     * Set the workspace isolation level for this task.
     */
    public function isolation(Isolation $isolation): self
    {
        $this->isolation = $isolation;
        return $this;
    }

    /**
     * Set an optional human-readable name for this task.
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Constrain this task's terminal turn to a JSON schema.
     *
     * Accepts a decoded schema document (an associative array — the shape the
     * YAML loader hands over), or the raw headless spelling: an inline JSON
     * object or a path to a schema file, decoded at build() time exactly like
     * the `-p --json-schema` flag decodes it. An invalid schema fails closed
     * here, at build time, never at dispatch.
     *
     * @param array<string, mixed>|string|null $schema
     */
    public function outputSchema(array|string|null $schema): self
    {
        $this->outputSchema = $schema;
        return $this;
    }

    /**
     * The model THIS task runs on (S2). The registry hands over the trimmed
     * YAML string; `null` keeps the session's model byte-identical to today.
     * Whether a named id is servable is decided ONCE, at dispatch, by the
     * same ladder a delegation consults ({@see \SugarCraft\Code\Agents\ModelChoice});
     * a stage whose ask cannot be honoured refuses rather than dialing a
     * stand-in. `inherit` is the delegation spelling kept alive here: it
     * defers to the agent's own preset model, exactly as it does for a Task.
     */
    public function model(?string $model): self
    {
        $this->model = $model;
        return $this;
    }

    /**
     * Reject the verifier's verdict when its completed output MATCHES a pattern.
     *
     * POLARITY, LOUD: this is a REJECT pattern, not an expectation — a MATCH
     * means rejection, and the verification stage fails exactly as if the
     * verifier agent itself had failed (retries consumed, /workflow resume
     * re-runs it). Only the verifier slot of a withVerification() stage may
     * carry it; the engine refuses any other placement before anything runs.
     *
     * The pattern is a PCRE as passed to preg_match(), delimiter included
     * (e.g. '/REJECT/', '/\bNEEDS-FIX\b/i'). Validity is checked here, at
     * set time — a pattern that cannot compile throws instead of being born
     * into a workflow where its scan could only ever fail closed.
     *
     * `atLeast` is the occurrence threshold: the verdict is rejected only
     * when the pattern matches AT LEAST that many times (default 1 — the
     * match-once behavior, byte-identical). A threshold below 1 throws here,
     * the same fail-closed rule as an uncompilable pattern.
     */
    public function reject(string $pattern, int $atLeast = 1): self
    {
        if (@preg_match($pattern, '') === false) {
            throw new \InvalidArgumentException(
                'reject() pattern ' . $pattern . ' is not a valid PCRE: ' . self::pcreErrorText(),
            );
        }

        if ($atLeast < 1) {
            throw new \InvalidArgumentException(
                "reject() atLeast must be at least 1 occurrence, got {$atLeast}",
            );
        }

        $this->reject = $pattern;
        $this->rejectAtLeast = $atLeast;
        return $this;
    }

    /**
     * O3: serialize this task's stage against every other stage naming the
     * same host resource ('gpu', 'display', …). A blank name names nothing
     * and is refused HERE, not silently dropped at the lock.
     */
    public function resource(string $resource): self
    {
        $asked = trim($resource);
        if ($asked === '') {
            throw new \InvalidArgumentException(
                'resource: names no resource — give the host resource this stage serializes on (e.g. "gpu"), or omit it',
            );
        }

        $this->resource = $asked;
        return $this;
    }

    /**
     * How many seconds to wait for {@see resource()} before the stage fails
     * loudly. Negative bounds are refused at build time; 0 is a legitimate
     * ask-once-and-give-up.
     */
    public function lockWaitSeconds(int $seconds): self
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException(
                "lockWaitSeconds cannot be negative, got {$seconds}",
            );
        }

        $this->lockWaitSeconds = $seconds;
        return $this;
    }

    /**
     * Set the idle-stall window in seconds (C7). Values below 1 are refused
     * at set time — an instant-expiry window is never what an author meant;
     * omit the key to stay on wall-clock bounds alone.
     */
    public function stallTimeout(int $seconds): self
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException(
                "stallTimeout must be at least 1 second, got {$seconds}",
            );
        }

        $this->stallTimeout = $seconds;
        return $this;
    }

    /**
     * Set the stall-restart budget (C7): re-dispatches allowed when a worker
     * EXPIRES SILENT, counted apart from retries(). Negative caps are
     * refused at set time; 0 is the legitimate "never restart a stall".
     */
    public function restarts(int $restarts): self
    {
        if ($restarts < 0) {
            throw new \InvalidArgumentException(
                "restarts cannot be negative, got {$restarts}",
            );
        }

        $this->restarts = $restarts;
        return $this;
    }

    /**
     * C9 fan-in join: attach an `input:` spec — a join over PRIOR stages'
     * recorded rows, injected under the fixed `input` handle into this
     * task's interpolation context immediately before its prompt is
     * assembled. The spec is validated and normalized at set time by
     * {@see trustedJoin()}; the engine never re-checks the shape.
     *
     * @param array<array-key, mixed> $spec
     */
    public function input(array $spec): self
    {
        $this->input = self::trustedJoin($spec);
        return $this;
    }

    /**
     * P1-4: convene a verifier PANEL of $votes independent dispatches of
     * this verifier, run concurrently on the stage's budget. The verdict
     * passes when at least {@see quorum()} votes do not reject. Verifier
     * slot only (the engine refuses any other placement before dispatch).
     * 1, the default, is the single-verifier path, byte-identical.
     */
    public function votes(int $votes): self
    {
        if ($votes < 1) {
            throw new \InvalidArgumentException("votes must be a whole number of at least 1, got {$votes}");
        }

        $this->votes = $votes;
        return $this;
    }

    /**
     * P1-4: one lens per vote — `{{lens}}` in the verifier prompt becomes
     * that vote's lens, so the panel judges from diverse perspectives
     * (correctness, security, does-it-reproduce) instead of N identical
     * ones. The list's length must equal {@see votes()} (checked at build).
     *
     * @param list<string> $lenses
     */
    public function lenses(array $lenses): self
    {
        $this->lenses = self::trustedLenses($lenses);
        return $this;
    }

    /**
     * P1-4: how many votes must NOT reject for the verdict to pass,
     * 1..votes (checked at build). Unset = the majority, floor(votes/2)+1.
     */
    public function quorum(int $quorum): self
    {
        if ($quorum < 1) {
            throw new \InvalidArgumentException("quorum must be at least 1, got {$quorum}");
        }

        $this->quorum = $quorum;
        return $this;
    }

    /**
     * P1-6: run this task as the named agent preset (`.candy-code/agents/<name>.md`,
     * resolved by the engine against the same roster `Task` uses). The preset
     * supplies its system prompt, tools, disallowed tools, model, effort,
     * isolation, step cap and permission mode; any key this task sets itself
     * wins. An unknown name refuses the run before anything is dispatched.
     * `agent`/`Tasks::agent()` stays a display label.
     */
    public function preset(string $name): self
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('preset() needs a preset name, got an empty string');
        }

        $this->preset = $name;
        return $this;
    }

    /**
     * P1-6: the reasoning effort this task's agent asks for — `low` for
     * mechanical stages, the high tiers only for the hardest judge/verify
     * stages. A closed set: anything outside it is refused.
     */
    public function effort(Effort|string $effort): self
    {
        $this->effort = self::trustedEffort($effort);
        return $this;
    }

    /**
     * P1-6: the one parser for an `effort` spelling — an {@see Effort} case or
     * its exact lowercase value.
     *
     * @throws \InvalidArgumentException naming the closed set.
     */
    public static function trustedEffort(Effort|string $effort): Effort
    {
        if ($effort instanceof Effort) {
            return $effort;
        }

        return Effort::tryFrom($effort) ?? throw new \InvalidArgumentException(sprintf(
            'effort must be one of %s, got "%s"',
            implode(', ', array_map(static fn (Effort $e): string => $e->value, Effort::cases())),
            $effort,
        ));
    }

    /** P1-4: the default quorum of a $votes panel — a strict majority, floor(votes/2)+1. */
    public static function defaultQuorum(int $votes): int
    {
        return intdiv(max(1, $votes), 2) + 1;
    }

    /**
     * P1-4: the lens-list shape law, shared by {@see lenses()} and the YAML
     * loader: a non-empty list of non-empty strings (trimmed).
     *
     * @param array<array-key, mixed> $lenses
     * @return list<string>
     */
    public static function trustedLenses(array $lenses): array
    {
        if ($lenses === [] || !array_is_list($lenses)) {
            throw new \InvalidArgumentException(
                'lenses must be a non-empty list of lens names, one per vote, got ' . ($lenses === [] ? 'an empty list' : 'a map')
            );
        }

        $trusted = [];
        foreach ($lenses as $i => $lens) {
            if (!is_string($lens) || trim($lens) === '') {
                throw new \InvalidArgumentException(
                    'lenses entry #' . ($i + 1) . ' must be a non-empty string, got '
                    . (is_string($lens) ? 'an empty string' : get_debug_type($lens))
                );
            }
            $trusted[] = trim($lens);
        }

        return $trusted;
    }

    /**
     * P1-4: the cross-key law of a verifier panel, judged once all three
     * keys are known (builder build() and the YAML loader): lenses, when
     * present, name exactly one lens per vote, and a quorum can be met by
     * the votes there are.
     *
     * @param list<string>|null $lenses
     *
     * @throws \InvalidArgumentException naming the mismatch.
     */
    public static function verifierPanel(int $votes, ?array $lenses, ?int $quorum): void
    {
        if ($lenses !== null && count($lenses) !== $votes) {
            throw new \InvalidArgumentException(sprintf(
                'lenses lists %d lens%s but votes is %d; give exactly one lens per vote',
                count($lenses),
                count($lenses) === 1 ? '' : 'es',
                $votes,
            ));
        }
        if ($quorum !== null && $quorum > $votes) {
            throw new \InvalidArgumentException("quorum {$quorum} exceeds votes {$votes}; at most {$votes} vote" . ($votes === 1 ? '' : 's') . ' can pass');
        }
    }

    /**
     * C9: the builder's parse-don't-validate gate for `input:` join specs.
     *
     * A join is a map carrying exactly two keys:
     *  - `from`: a non-empty LIST of prior stage names (non-empty strings,
     *    each named at most once);
     *  - `as`: the join shape, from the closed vocabulary
     *    `file-list` (rows as newline-separated capture paths), `first`
     *    (the first present row verbatim), `all` (every present row,
     *    labelled and separated), `results` (a JSON document over the
     *    typed payload of every present row).
     *
     * Everything malformed is REFUSED here, at the declaration site, with
     * raw English — no lang keys, no coercion. Name ORDER (which declared
     * stages are "prior") is the registry's job at end-of-file, where every
     * stage name is visible; this gate judges SHAPE only.
     *
     * @param array<array-key, mixed> $spec
     *
     * @return array{from: list<string>, as: string}
     */
    public static function trustedJoin(array $spec): array
    {
        foreach (array_keys($spec) as $key) {
            if ($key !== 'from' && $key !== 'as') {
                $named = is_string($key) ? $key : (string) $key;
                throw new \InvalidArgumentException(
                    "has unknown key \"{$named}\"; supported keys: from, as",
                );
            }
        }

        if (!array_key_exists('from', $spec)) {
            throw new \InvalidArgumentException('needs a "from" list of stage names to join');
        }
        if (!array_key_exists('as', $spec)) {
            throw new \InvalidArgumentException('needs an "as" shape: file-list, first, all or results');
        }

        $from = $spec['from'];
        if (!is_array($from) || !array_is_list($from)) {
            throw new \InvalidArgumentException(
                '"from" must be a list of stage names, got ' . get_debug_type($from),
            );
        }
        if ($from === []) {
            throw new \InvalidArgumentException('"from" must name at least one stage');
        }

        /** @var list<string> $names */
        $names = [];
        foreach ($from as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                throw new \InvalidArgumentException(
                    '"from" must hold only non-empty stage-name strings, got ' . get_debug_type($entry),
                );
            }
            $entry = trim($entry);
            if (in_array($entry, $names, true)) {
                throw new \InvalidArgumentException("\"from\" names stage \"{$entry}\" twice");
            }
            $names[] = $entry;
        }

        $as = $spec['as'];
        if (!is_string($as)) {
            throw new \InvalidArgumentException(
                '"as" must be one of file-list, first, all, results, got ' . get_debug_type($as),
            );
        }
        if (!in_array($as, ['file-list', 'first', 'all', 'results'], true)) {
            throw new \InvalidArgumentException(
                "\"as\" must be one of file-list, first, all, results, got \"{$as}\"",
            );
        }

        return ['from' => $names, 'as' => $as];
    }

    /** Last PCRE compile failure as readable text (shared with the engine's fail-closed scan). */
    public static function pcreErrorText(): string
    {
        return match (preg_last_error()) {
            PREG_BACKTRACK_LIMIT_ERROR => 'backtrack limit exceeded',
            PREG_RECURSION_LIMIT_ERROR => 'recursion limit exceeded',
            PREG_BAD_UTF8_ERROR => 'malformed UTF-8 (from the pattern)',
            PREG_BAD_UTF8_OFFSET_ERROR => 'bad UTF-8 offset',
            PREG_JIT_STACKLIMIT_ERROR => 'JIT stack limit exceeded',
            PREG_NO_ERROR => 'unknown compile error',
            default => 'unknown compile error',
        };
    }

    /**
     * Assemble and return the immutable WorkflowTask DTO.
     */
    public function build(): WorkflowTask
    {
        self::verifierPanel($this->votes, $this->lenses, $this->quorum);

        return new WorkflowTask(
            agentType: $this->agentType,
            prompt: $this->prompt,
            tools: $this->tools,
            timeout: $this->timeout,
            retries: $this->retries,
            isolation: $this->isolation,
            name: $this->name,
            outputSchema: self::trustedSchema($this->outputSchema),
            model: self::trustedModel($this->model),
            reject: $this->reject,
            rejectMinOccurrences: $this->rejectAtLeast,
            resource: $this->resource,
            lockWaitSeconds: $this->lockWaitSeconds,
            stallTimeout: $this->stallTimeout,
            restarts: $this->restarts ?? 0,
            input: $this->input,
            votes: $this->votes,
            lenses: $this->lenses,
            quorum: $this->quorum,
            preset: $this->preset,
            effort: $this->effort,
        );
    }

    /**
     * S2 parse-don't-validate: `null` is the session default, any other value
     * must actually NAME a model — a blank string names nothing, and it fails
     * CLOSED here, where it was declared, rather than reaching the ladder and
     * quietly reading as `inherit`.
     */
    private static function trustedModel(?string $model): ?string
    {
        if ($model === null) {
            return null;
        }
        $asked = trim($model);
        if ($asked === '') {
            throw new \InvalidArgumentException(
                'model: names no model — give a model id (or "inherit" for the agent preset), or omit it to run on the session\'s',
            );
        }

        return $asked;
    }

    /**
     * G3: the builder's parse-don't-validate gate. A raw string (inline JSON
     * or a file path) decodes exactly like the `-p --json-schema` flag; an
     * array arrives as a decoded map and must actually be one — a list is
     * not a schema and fails CLOSED here, where the caller declared it, so
     * nothing downstream has to re-check.
     *
     * @param array<array-key, mixed>|string|null $schema
     *
     * @return array<array-key, mixed>|null
     */
    private static function trustedSchema(array|string|null $schema): ?array
    {
        if (is_string($schema)) {
            return JsonSchemaArgument::decode($schema);
        }

        if ($schema !== null && array_is_list($schema) && $schema !== []) {
            throw new \InvalidArgumentException(Lang::t('cli.noninteractive.json_schema_not_object'));
        }

        return $schema;
    }
}
