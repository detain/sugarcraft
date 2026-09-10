<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Crush\Registry\Tool;
use SugarCraft\Crush\Registry\ToolSignature;

/**
 * Registry of available slash-commands / built-in tools.
 *
 * Ships with five built-in tools: filter, sort, goto, select, quit.
 *
 * E14 (docs/plans/crush_code_hardening_backlog.md): the side declarations
 * `ToolSignature` and `Tool` that used to share this file now live in
 * `SugarCraft\Crush\Registry` (`src/Registry/`), moved out of the root
 * namespace where the bare `Tool` sat one `use` away from colliding with the
 * `SugarCraft\Crush\Tools\Tool` interface. The root
 * `SugarCraft\Crush\ToolRegistry` FQN is deliberately untouched: crush_code.md
 * flags it superseded with zero production callers, and retiring it is a
 * human consolidation decision, not a rename — everything the move deletes
 * is nothing.
 */
final class ToolRegistry
{
    /** @var array<non-empty-string, Tool> */
    private array $tools = [];

    public function __construct()
    {
        $this->registerBuiltIns();
    }

    /**
     * Register a tool. Overwrites existing tool of the same name.
     */
    public function register(Tool $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    /**
     * Execute a named tool with the given arguments.
     *
     * @param array<string, mixed> $args
     * @throws \BadMethodCallException When the tool is not registered
     */
    public function execute(string $name, array $args = []): ToolResult
    {
        $tool = $this->get($name);
        if ($tool === null) {
            throw new \BadMethodCallException("Unknown tool: {$name}");
        }
        return $tool->execute($args);
    }

    /**
     * Retrieve a tool by name.
     */
    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * List all registered tools.
     *
     * @return list<Tool>
     */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /**
     * Whether a named tool is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    // ─── Built-in tools ────────────────────────────────────────────────────

    private function registerBuiltIns(): void
    {
        $this->register(new Tool(
            name: 'filter',
            signature: new ToolSignature(
                positional: ['expression'],
                description: 'Filter viewport lines matching the given expression',
            ),
            execute: static fn(array $args): ToolResult => ToolResult::ok(
                'filter',
                "Filter applied: {$args['expression']}"
            ),
        ));

        $this->register(new Tool(
            name: 'sort',
            signature: new ToolSignature(
                positional: [],
                named: ['r' => false, 'n' => false],
                description: 'Sort viewport lines (flags: -r reverse, -n numeric)',
            ),
            execute: static function (array $args): ToolResult {
                $flags = [];
                if (($args['r'] ?? false))  { $flags[] = 'reverse'; }
                if (($args['n'] ?? false))   { $flags[] = 'numeric'; }
                $flagStr = $flags !== [] ? ' (' . implode(', ', $flags) . ')' : '';
                return ToolResult::ok('sort', "Sort applied{$flagStr}");
            },
        ));

        $this->register(new Tool(
            name: 'goto',
            signature: new ToolSignature(
                positional: ['line'],
                description: 'Jump to a specific line number or match',
            ),
            execute: static fn(array $args): ToolResult => ToolResult::ok(
                'goto',
                "Gone to line: {$args['line']}"
            ),
        ));

        $this->register(new Tool(
            name: 'select',
            signature: new ToolSignature(
                positional: ['start', 'end'],
                description: 'Select a range of lines (start, end)',
            ),
            execute: static fn(array $args): ToolResult => ToolResult::ok(
                'select',
                "Selected lines {$args['start']}–{$args['end']}"
            ),
        ));

        $this->register(new Tool(
            name: 'quit',
            signature: new ToolSignature(
                description: 'Exit the application',
            ),
            execute: static fn(): ToolResult => ToolResult::ok(
                'quit',
                'Quit'
            ),
        ));
    }
}
