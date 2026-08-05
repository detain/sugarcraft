<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks;

final class HookRegistry
{
    /** @var array<string, array<string, HookInterface>> hooks by event type */
    private array $hooks = [
        'PreToolUse' => [],
        'PostToolUse' => [],
        'Stop' => [],
        'SubagentStop' => [],
        'SessionStart' => [],
        'SessionEnd' => [],
        'UserPromptSubmit' => [],
        'PreCompact' => [],
        'Notification' => [],
        'TeammateIdle' => [],
        'TaskCreated' => [],
        'TaskCompleted' => [],
    ];

    /** @var array<string, bool> disabled hooks */
    private array $disabled = [];

    public function register(HookInterface $hook): void
    {
        $event = $hook->event()->value;
        $name = $hook->name();
        $this->hooks[$event][$name] = $hook;
    }

    public function unregister(string $name): void
    {
        foreach ($this->hooks as $event => $hooks) {
            unset($this->hooks[$event][$name]);
        }
    }

    public function get(string $event, string $name): ?HookInterface
    {
        return $this->hooks[$event][$name] ?? null;
    }

    public function getForEvent(string $event): array
    {
        return array_values($this->hooks[$event] ?? []);
    }

    public function disable(string $name): void
    {
        $this->disabled[$name] = true;
    }

    public function enable(string $name): void
    {
        unset($this->disabled[$name]);
    }

    public function isDisabled(string $name): bool
    {
        return $this->disabled[$name] ?? false;
    }

    /**
     * Find matching hooks for a tool call.
     *
     * @return array<HookInterface>
     */
    public function findMatches(string $event, string $toolName): array
    {
        $matches = [];

        foreach ($this->getForEvent($event) as $hook) {
            if ($this->isDisabled($hook->name())) {
                continue;
            }

            if ($this->matcherMatches($hook->matcher(), $toolName)) {
                $matches[] = $hook;
            }
        }

        return $matches;
    }

    /**
     * Returns true if the given pattern matches the tool name.
     *
     * Validates the pattern compiles before use to avoid PREG_* errors
     * from malformed regex patterns in hook matcher() implementations.
     */
    private function matcherMatches(string $pattern, string $toolName): bool
    {
        // Validate the pattern compiles before use
        if (@preg_match('/' . $pattern . '/i', '') === false) {
            return false;
        }

        return preg_match('/' . $pattern . '/i', $toolName) === 1;
    }

    /**
     * Execute all matching hooks for an event.
     */
    public function executeHooks(string $event, HookContext $context): HookResult
    {
        $matches = $this->findMatches($event, $context->toolName);

        foreach ($matches as $hook) {
            $result = $hook->execute($context);

            if (!$result->isAllowed()) {
                return $result;
            }

            if ($result->isModified()) {
                $context = $context->withToolInput($result->modifiedInput);
            }
        }

        return HookResult::allow();
    }
}
