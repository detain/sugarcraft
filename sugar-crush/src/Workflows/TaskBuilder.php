<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

use SugarCraft\Crush\Agents\Isolation;

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
        );
    }
}
