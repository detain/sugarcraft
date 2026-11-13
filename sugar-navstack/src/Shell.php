<?php

declare(strict_types=1);

namespace SugarCraft\NavStack;

/**
 * Shell — combines NavStack + Breadcrumb into a single navigation component.
 *
 * The Shell holds the current view (NavStack) and can render breadcrumbs
 * on demand. This mirrors the KevM/bubbleo Shell pattern in spirit; upstream
 * New() builds a window model (research §2.4) while this port is purely
 * logical.
 *
 * **Honest immutability.** The `with*()` family returns new Shell objects,
 * but the boundary is thinner than "immutable" suggests in one way hosts
 * must know:
 *
 *  - The stack LIST is copied into the new NavStack, but the
 *    {@see NavigationItem}s inside are the same objects (titles+data are
 *    readonly, so item sharing is safe to read — but `identity($a,$b)`
 *    holds across Shells).
 *
 * Each derived Shell gets its OWN copy of the Breadcrumb (Breadcrumb is
 * mutable by design — sharing one instance between Shells would leak
 * `setSeparator()`-style configuration sideways; proven hazard). A host
 * that deliberately wants one bar shared across Shells says so through
 * {@see withBreadcrumb()}.
 *
 * Additionally the stack/breadcrumb props are `public readonly` — readonly
 * protects the READING of the property, not mutation THROUGH the object:
 * `$shell->stack->push('X')` mutates that NavStack INSTANCE in place, and
 * every Shell holding THE SAME INSTANCE (the original, or one built as
 * `new Shell($shell->stack, ...)`) sees the change. The `with*()` family
 * copies the item list into a fresh NavStack, so derived Shells do NOT
 * see later direct pushes on an ancestor's stack object. Treat in-place
 * mutation as the escape hatch it is; prefer the `with*()` methods.
 *
 * `withPop()` on a depth-1 stack DOES empty it (it is not a no-op there);
 * guard with {@see NavStack::canPop()} to keep a root crumb alive.
 *
 * Adapted from KevM/bubbleo Shell (Go); implemented as a native PHP component.
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
     * "New Shell" = new stack object sharing items, with its OWN copy of
     * the Breadcrumb (see class docblock). Titles pass the same
     * guards as {@see NavStack::push()} (non-empty, depth cap).
     *
     * Project addition (research §10) — upstream KevM/bubbleo Shell has no
     * withPush; it mutates through the Bubble Tea message loop.
     */
    public function withPush(string $title, mixed $data = null): self
    {
        $newStack = (new NavStack())->setItems($this->stack->items());
        $newStack->push($title, $data);
        return $this->copy($newStack);
    }

    /**
     * Pop the top item and return a new Shell.
     *
     * No-op only when the stack is already empty. Popping a depth-1 stack
     * DOES empty it — the single item is removed, matching
     * {@see NavStack::pop()}. Callers that must keep the root crumb alive
     * guard with the predicate before dispatching:
     * `if ($shell->stack->canPop()) { $shell = $shell->withPop(); }` —
     * discarding the return without that guard is the classic silent
     * stack wipe.
     *
     * Project addition — upstream KevM/bubbleo Shell has no withPop; the
     * Pop semantics it wraps live on NavStack.
     */
    public function withPop(): self
    {
        $newStack = (new NavStack())->setItems($this->stack->items());
        $newStack->pop();
        return $this->copy($newStack);
    }

    /**
     * Truncate the stack to the item at $index (inclusive), removing newer
     * items, and return a new Shell. A negative $index THROWS (see
     * {@see NavStack::popTo()}); {@see NavStack::popToRoot()} names the
     * keep-only-the-root intent the old silent clamp used to swallow.
     *
     * Project addition — upstream KevM/bubbleo Shell has no truncation/
     * withPopTo; see {@see NavStack::popTo()}.
     */
    public function withPopTo(int $index): self
    {
        $newStack = (new NavStack())->setItems($this->stack->items());
        $newStack->popTo($index);
        return $this->copy($newStack);
    }

    /**
     * Return a new Shell carrying a DELIBERATELY chosen Breadcrumb — the
     * opt-in door for hosts that want one mutable bar shared across
     * Shells (the `with*()` family otherwise copies the breadcrumb so
     * configuration cannot leak sideways).
     */
    /**
     * The single copy mechanism for the whole `with*()` family (NS-25).
     *
     * Four call sites used to inline `new self($newStack, clone
     * $this->breadcrumb)`; one named helper keeps the copy story in one
     * grep-able place: a fresh Shell object, the CALLER-BUILT new stack,
     * and a private clone of the shared-by-reference breadcrumb so derived
     * Shells cannot leak bar configuration sideways (NS-02).
     *
     * Why raw clone instead of sugar-core's Mutable trait: Mutable/mutate()
     * serves immutable value objects with a fixed field set; the Shell
     * hands back a DIFFERENT stack (built by the caller) and the
     * Breadcrumb is mutable-by-design — a trait copy would obscure the
     * one deliberate shallow-clone sharing (item identity survives, pinned
     * by ShellTest). This deviation is documented, not accidental.
     */
    private function copy(NavStack $newStack): self
    {
        return new self($newStack, clone $this->breadcrumb);
    }

    public function withBreadcrumb(Breadcrumb $breadcrumb): self
    {
        return new self($this->stack, $breadcrumb);
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
     * Split is POSIX `/` ONLY (NS-26): a Windows-style path arrives as one
     * `C:`-bearing crumb (backslash never splits, `..` never engages) —
     * hosts fed drive paths should explode them first; auto-recognizing
     * `\`/`:` on POSIX hosts misroutes more than it helps, so this port
     * documents the law instead of guessing the OS.
     *
     * Returns a NEW Shell despite the verb name. The name is historical;
     * the honest immutable verb is {@see withDirectory()}, to which this
     * is now a deprecated forwarder (NS-26).
     *
     * Project addition (research §10) — upstream KevM/bubbleo Shell has no
     * pushDirectory.
     */
    public function withDirectory(string $path): self
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
        return $this->copy($newStack);
    }

    /**
     * @deprecated NS-26 naming outlier — verb name, immutable return.
     *             {@see withDirectory()} is the canonical spelling; this
     *             forwarder delegates byte-for-byte and keeps the shipped
     *             call surface working unchanged.
     */
    public function pushDirectory(string $path): self
    {
        return $this->withDirectory($path);
    }
}
