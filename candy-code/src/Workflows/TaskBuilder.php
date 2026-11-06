<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

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
