<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands\Specs;

use SugarCraft\Crush\Commands\CommandSpec;

/**
 * One built-in command: its registry row plus how `Chat::dispatchCommand()`
 * reaches it.
 *
 * WHY A WRAPPER AND NOT MORE FIELDS ON {@see CommandSpec}: a `CommandSpec` is
 * also what a file-based `*.md` command parses into, and a dispatch target or an
 * alias list means nothing there — a custom command is expanded into a prompt,
 * never routed to a handler. The two things a built-in has and a custom command
 * does not live here instead.
 *
 * - {@see $handler} names the private `Chat` method the draft is routed to, or
 *   null for a palette-only row (no slash spelling dispatches it). A string
 *   rather than a closure because the handlers are private to `Chat` and a spec
 *   file is not; `BuiltInCommandsTest` reds when a name stops resolving.
 * - {@see $aliases} dispatch to the same handler but own no row, so no surface
 *   advertises them — README's slash roster prints them in parentheses.
 */
final class BuiltInCommand
{
    /** @param list<string> $aliases */
    private function __construct(
        public readonly CommandSpec $spec,
        public readonly ?string $handler,
        public readonly CommandArguments $arguments,
        public readonly array $aliases,
    ) {
    }

    /** A row with no dispatch: palette-only until {@see withHandler()} gives it one. */
    public static function new(CommandSpec $spec): self
    {
        return new self($spec, null, CommandArguments::Text, []);
    }

    /** Route `/name` to the private `Chat` method `$method`, handing it `$arguments`. */
    public function withHandler(string $method, CommandArguments $arguments = CommandArguments::Text): self
    {
        return $this->mutate(['handler' => $method, 'arguments' => $arguments]);
    }

    /** Extra spellings that reach the same handler without a row of their own. */
    public function withAliases(string ...$aliases): self
    {
        return $this->mutate(['aliases' => array_values($aliases)]);
    }

    public function name(): string
    {
        return $this->spec->name;
    }

    /**
     * Every spelling that dispatches here: the row's name first, then its
     * aliases. Empty for a palette-only row.
     *
     * @return list<string>
     */
    public function spellings(): array
    {
        return $this->handler === null ? [] : [$this->spec->name, ...$this->aliases];
    }

    /**
     * Whether the trimmed draft `$text`, already parsed to `$name`, reaches the
     * handler: an argument-less command only when the name is the whole draft.
     */
    public function accepts(string $text, string $name): bool
    {
        return $this->handler !== null
            && ($this->arguments !== CommandArguments::None || $text === '/' . $name);
    }

    /** @param array<string, mixed> $changes */
    private function mutate(array $changes): self
    {
        $state = array_merge(get_object_vars($this), $changes);

        return new self($state['spec'], $state['handler'], $state['arguments'], $state['aliases']);
    }
}
