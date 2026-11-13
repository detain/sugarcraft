<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Keys;


/**
 * Keyboard shortcut handler.
 *
 * Features:
 * - Register key combinations to actions
 * - Handle key events and dispatch to actions
 * - Support for single keys and modifier combinations
 *
 * Resolution semantics (finding sugar-dashboard-106 — the old docblock
 * advertised "priority-based" resolution that never existed): a key first
 * checks its specific registrations, then the onAny globals; per key the
 * FIRST registration wins and later duplicates are shadowed — on() emits
 * an error_log warning naming the shadow at registration time so the
 * mistake is visible in the developer's log, not silently invisible inside
 * a running PTY.
 *
 * Mirrors keymap handling from bubble-keymap but adapted
 * to PHP with wither-style immutable setters.
 */
final class KeyMap implements \SugarCraft\Dashboard\Foundation\SizedItem
{
    /** @var array<string, list<KeyAction>> */
    private array $actions = [];

    /** @var list<KeyAction> */
    private array $globalActions = [];

    /** @var array{width: int|null, height: int|null} */
    private array $size = ['width' => null, 'height' => null];

    public function __construct(
        private readonly \SugarCraft\Dashboard\Foundation\Item $content,
    ) {}

    /**
     * Create a new keymap with default styling.
     */
    public static function new(\SugarCraft\Dashboard\Foundation\Item $content): self
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
    public function setSize(int $width, int $height): \SugarCraft\Dashboard\Foundation\Sizer
    {
        $newContent = $this->content;
        if ($newContent instanceof \SugarCraft\Dashboard\Foundation\Sizer) {
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
    public function innerSize(): array
    {
        if ($this->size['width'] !== null && $this->size['height'] !== null) {
            return [$this->size['width'], $this->size['height']];
        }
        if (! $this->content instanceof \SugarCraft\Dashboard\Foundation\SizedItem) {
            return [0, 0];
        }
        return $this->content->innerSize();
    }

    // ─── Action Registration ───────────────────────────────────────

    /**
     * Register an action for a key combination.
     *
     * The FIRST registration for a combination is the one handle() runs;
     * registering a second action for the same combination shadows nothing
     * that already fires — the newcomer never runs, and the shadowing is
     * announced via error_log so a typo-collision is diagnosable instead
     * of a silent unreachable entry (documented first-wins policy).
     *
     * @param callable(Key): \SugarCraft\Dashboard\Foundation\Item $action
     */
    public function on(string $key, callable $action, bool $ctrl = false, bool $alt = false, bool $shift = false): self
    {
        $clone = clone $this;
        $keyObj = new Key($key, $ctrl, $alt, $shift);
        $keyString = $keyObj->toString();

        if (($clone->actions[$keyString] ?? []) !== []) {
            // Kept in the roster for introspection (registeredKeys()/off()
            // see the combination), but unreachable through handle().
            error_log("KeyMap: action for '{$keyString}' is already registered; the first registration wins and this one is shadowed (finding sugar-dashboard-106 policy: first-wins).");
        }

        $clone->actions[$keyString][] = new KeyAction($keyString, $action);

        return $clone;
    }

    /**
     * Register a global action that fires on any key.
     *
     * Global actions are checked after specific actions; the FIRST global
     * registration wins (later ones are shadowed, same first-wins policy
     * as on()).
     *
     * @param callable(Key): \SugarCraft\Dashboard\Foundation\Item $action
     */
    public function onAny(callable $action): self
    {
        $clone = clone $this;
        if ($clone->globalActions !== []) {
            error_log('KeyMap: onAny() global already registered; the first one wins and this one is shadowed (first-wins policy).');
        }
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
     * Specific registrations are checked before the onAny globals; per
     * source only the FIRST registered action executes (first-wins policy
     * in the class docblock) — the old foreach-over-roster shape implied
     * iteration that could never reach entry two.
     *
     * @return array{0:Item, 1:bool} [new content, whether a key was handled]
     */
    public function handle(Key $key): array
    {
        $keyString = $key->toString();

        // Check for specific action: first registration wins.
        $specific = $this->actions[$keyString][0] ?? null;
        if ($specific !== null) {
            return [$specific->execute($key), true];
        }

        // Check global actions: first registration wins, same policy.
        $global = $this->globalActions[0] ?? null;
        if ($global !== null) {
            return [$global->execute($key), true];
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
    public function registeredKeys(): array
    {
        return array_keys(array_filter($this->actions));
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::registeredKeys()} instead. REMOVED-IN: 2.0.0.
     */
    public function getRegisteredKeys(): array { return $this->registeredKeys(); }

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
    public function withContent(\SugarCraft\Dashboard\Foundation\Item $content): self
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
     * @param callable(Key): \SugarCraft\Dashboard\Foundation\Item $action
     */
    public function withAction(string $key, callable $action): self
    {
        return $this->on($key, $action);
    }
}
