<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

use SugarCraft\Code\Agents\Isolation;

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
    private ?string $reject = null;
    private int $rejectAtLeast = 1;

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
            reject: $this->reject,
            rejectMinOccurrences: $this->rejectAtLeast,
        );
    }
}
