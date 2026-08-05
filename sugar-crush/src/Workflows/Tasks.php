<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

/**
 * Static factory for TaskBuilder instances — the entry point for the DSL.
 *
 * Mirrors the DSL usage seen in workflow definitions:
 *   Tasks::agent('architect')->prompt('...')->tools([...])
 *
 * @example Tasks::agent('architect')->prompt('Design the system')
 * @example Tasks::agent('coder', 'implement-api')->prompt('Implement the API')
 */
final class Tasks
{
    /**
     * Create a TaskBuilder pre-configured with an agent type and optional name.
     *
     * @param string      $type The agent type (e.g. 'architect', 'coder', 'reviewer')
     * @param string|null $name Optional human-readable name for this task
     */
    public static function agent(string $type, ?string $name = null): TaskBuilder
    {
        $builder = new TaskBuilder();
        $builder->agent($type);

        if ($name !== null) {
            $builder->name($name);
        }

        return $builder;
    }

    /**
     * Convenience factory — creates a TaskBuilder and immediately sets the prompt.
     * Useful for shorter fluent chains when the agent type is set elsewhere.
     */
    public static function prompt(string $prompt): TaskBuilder
    {
        return (new TaskBuilder())->prompt($prompt);
    }
}
