<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

/**
 * Keyboard shortcut handler.
 *
 * Features:
 * - Register key combinations to actions
 * - Handle key events and dispatch to actions
 * - Support for single keys and modifier combinations
 * - Priority-based action resolution
 *
 * Mirrors keymap handling from bubble-keymap but adapted
 * to PHP with wither-style immutable setters.
 */
final class KeyMap implements Sizer
{
    /** @var array<string, list<KeyAction>> */
    private array $actions = [];

    /** @var list<KeyAction> */
    private array $globalActions = [];

    /** @var array{width: int|null, height: int|null} */
    private array $size = ['width' => null, 'height' => null];

    public function __construct(
        private readonly Item $content,
    ) {}

    /**
     * Create a new keymap with default styling.
     */
    public static function new(Item $content): self
    {
        return new self($content);
    }

    /**
     * Render the content managed by this keymap.
     */
    public function render(): string
    {
        return $this->content->render();
    }

    /**
     * Set the allocated dimensions for this keymap.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $newContent = $this->content;
        if ($newContent instanceof Sizer) {
            $newContent = $newContent->setSize($width, $height);
        }
        $clone = new self($newContent);
        $clone->actions = $this->actions;
        $clone->globalActions = $this->globalActions;
        $clone->size = ['width' => $width, 'height' => $height];
        return $clone;
    }

    /**
     * Get the dimensions of the managed content.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if ($this->size['width'] !== null && $this->size['height'] !== null) {
            return [$this->size['width'], $this->size['height']];
        }
        return $this->content->getInnerSize();
    }

    // ─── Action Registration ───────────────────────────────────────

    /**
     * Register an action for a key combination.
     *
     * @param callable(Key): Item $action
     */
    public function on(string $key, callable $action, bool $ctrl = false, bool $alt = false, bool $shift = false): self
    {
        $clone = clone $this;
        $keyObj = new Key($key, $ctrl, $alt, $shift);
        $keyString = $keyObj->toString();

        if (!isset($clone->actions[$keyString])) {
            $clone->actions[$keyString] = [];
        }
        $clone->actions[$keyString][] = new KeyAction($keyString, $action);

        return $clone;
    }

    /**
     * Register a global action that fires on any key.
     *
     * Global actions are checked after specific actions.
     *
     * @param callable(Key): Item $action
     */
    public function onAny(callable $action): self
    {
        $clone = clone $this;
        $clone->globalActions[] = new KeyAction('any', $action);
        return $clone;
    }

    /**
     * Remove an action by key combination.
     */
    public function off(string $key, bool $ctrl = false, bool $alt = false, bool $shift = false): self
    {
        $clone = clone $this;
        $keyObj = new Key($key, $ctrl, $alt, $shift);
        $keyString = $keyObj->toString();
        unset($clone->actions[$keyString]);
        return $clone;
    }

    // ─── Key Handling ──────────────────────────────────────────────

    /**
     * Handle a key event and return the new state.
     *
     * @return array{0:Item, 1:bool} [new content, whether a key was handled]
     */
    public function handle(Key $key): array
    {
        $keyString = $key->toString();

        // Check for specific action
        if (isset($this->actions[$keyString])) {
            foreach ($this->actions[$keyString] as $action) {
                $newContent = $action->execute($key);
                return [$newContent, true];
            }
        }

        // Check global actions
        foreach ($this->globalActions as $action) {
            $newContent = $action->execute($key);
            return [$newContent, true];
        }

        // No action handled the key - return this keymap unchanged
        return [$this, false];
    }

    /**
     * Check if a key combination is registered.
     */
    public function has(string $key, bool $ctrl = false, bool $alt = false, bool $shift = false): bool
    {
        $keyObj = new Key($key, $ctrl, $alt, $shift);
        $keyString = $keyObj->toString();
        return isset($this->actions[$keyString]) && $this->actions[$keyString] !== [];
    }

    /**
     * Get all registered key combinations.
     *
     * @return list<string>
     */
    public function getRegisteredKeys(): array
    {
        return array_keys(array_filter($this->actions));
    }

    /**
     * Check if any global actions are registered.
     */
    public function hasGlobalActions(): bool
    {
        return $this->globalActions !== [];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set new content.
     */
    public function withContent(Item $content): self
    {
        $clone = new self($content);
        $clone->actions = $this->actions;
        $clone->globalActions = $this->globalActions;
        $clone->size = $this->size;
        return $clone;
    }

    /**
     * Create a new KeyMap with an action registered for a simple key.
     *
     * @param callable(Key): Item $action
     */
    public function withAction(string $key, callable $action): self
    {
        return $this->on($key, $action);
    }
}
