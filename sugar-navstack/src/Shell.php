<?php

declare(strict_types=1);

namespace SugarCraft\NavStack;

/**
 * Shell — combines NavStack + Breadcrumb into a single navigation component.
 *
 * The Shell holds the current view (NavStack) and can render breadcrumbs
 * on demand. This mirrors the bubbleo Shell pattern.
 *
 * **Honest immutability.** The `with*()` family returns new Shell objects,
 * but the boundary is thinner than "immutable" suggests, in two ways hosts
 * must know:
 *
 *  - The NEW Shell SHARES THE SAME Breadcrumb instance as the old one.
 *    Breadcrumb is mutable by design, so `$shell->breadcrumb->setSeparator()`
 *    is visible through every Shell derived from it (and through the
 *    original). Give each Shell its own Breadcrumb
 *    (`new Shell($stack, new Breadcrumb())`) if they must not.
 *  - The stack LIST is copied into the new NavStack, but the
 *    {@see NavigationItem}s inside are the same objects (titles+data are
 *    readonly, so item sharing is safe to read — but `identity($a,$b)`
 *    holds across Shells).
 *
 * Additionally the stack/breadcrumb props are `public readonly` — readonly
 * protects the READING of the property, not mutation THROUGH the object:
 * `$shell->stack->push('X')` mutates the shared NavStack in place and every
 * Shell that holds it sees the change. Treat that as the escape hatch it
 * is; prefer the `with*()` methods.
 *
 * `withPop()` on a depth-1 stack DOES empty it (it is not a no-op there);
 * guard with {@see NavStack::canPop()} to keep a root crumb alive.
 *
 * Mirrors bubbleo Shell (Go); implemented as a native PHP component.
 */
final class Shell
{
    public function __construct(
        public readonly NavStack $stack,
        public readonly Breadcrumb $breadcrumb,
    ) {}

    public static function new(?Breadcrumb $breadcrumb = null): self
    {
        return new self(new NavStack(), $breadcrumb ?? new Breadcrumb());
    }

    /**
     * Push a new navigation item and return a new Shell.
     *
     * "New Shell" = new stack object sharing items and the SAME
     * Breadcrumb instance (see class docblock). Titles pass the same
     * guards as {@see NavStack::push()} (non-empty, depth cap).
     *
     * Mirrors bubbleo Shell.withPush.
     */
    public function withPush(string $title, mixed $data = null): self
    {
        $newStack = (new NavStack())->setItems($this->stack->items());
        $newStack->push($title, $data);
        return new self($newStack, $this->breadcrumb);
    }

    /**
     * Pop the top item and return a new Shell.
     *
     * No-op only when the stack is already empty. Popping a depth-1 stack
     * DOES empty it — the single item is removed, matching
     * {@see NavStack::pop()}. Callers that must keep the root crumb alive
     * guard with {@see NavStack::canPop()} first.
     *
     * Mirrors bubbleo Shell.withPop.
     */
    public function withPop(): self
    {
        $newStack = (new NavStack())->setItems($this->stack->items());
        $newStack->pop();
        return new self($newStack, $this->breadcrumb);
    }

    /**
     * Truncate the stack to the item at $index (inclusive), removing newer
     * items, and return a new Shell. A negative $index clamps to EMPTY
     * (see {@see NavStack::popTo()}).
     *
     * Mirrors bubbleo Shell truncation.
     */
    public function withPopTo(int $index): self
    {
        $newStack = (new NavStack())->setItems($this->stack->items());
        $newStack->popTo($index);
        return new self($newStack, $this->breadcrumb);
    }

    public function renderBreadcrumb(): string
    {
        return $this->breadcrumb->render($this->stack);
    }

    /**
     * Parse a directory path and push each segment as a navigation item.
     * e.g. "/home/user/projects" pushes "home", then "user", then "projects"
     * Each segment's data is the full path up to that segment.
     *
     * Normalization applied to the raw split:
     *  - empty segments (doubled/trailing slashes) are dropped, but a
     *    literal `0` directory name is KEPT (`array_filter` without a
     *    callback would drop it — real directories are named `0`);
     *  - `.` is skipped (lexical no-op);
     *  - `..` cancels the previous pending segment before anything is
     *    pushed (climbing past the start of this call is a no-op — the
     *    pre-existing stack is never unwound).
     *
     * Beyond these, segments are untrusted: they pass the same
     * {@see NavigationItem} title guards (names that are blank or purely
     * terminal/sentinel bytes after sanitizing are refused, size-capped)
     * and terminal/sentinel bytes are stripped at render by
     * {@see TitleSanitizer}.
     * A path with more than {@see NavStack::MAX_STACK_DEPTH} components
     * throws part-way (the returned Shell does not exist).
     *
     * Mirrors bubbleo Shell.pushDirectory.
     */
    public function pushDirectory(string $path): self
    {
        $segments = \array_filter(
            \explode('/', \trim($path, '/')),
            static fn(string $s): bool => $s !== ''
        );

        // Lexical normalization first (like path resolution): '.' skips,
        // '..' cancels the previous pending segment (climbing past the
        // start of this call is a no-op), only what survives gets pushed.
        $pending = [];
        foreach ($segments as $segment) {
            if ($segment === '.') {
                continue;
            }
            if ($segment === '..') {
                \array_pop($pending);
                continue;
            }
            $pending[] = $segment;
        }

        $newStack = (new NavStack())->setItems($this->stack->items());
        $acc = '';
        foreach ($pending as $segment) {
            $acc .= '/' . $segment;
            $newStack->push($segment, $acc);
        }
        return new self($newStack, $this->breadcrumb);
    }
}
