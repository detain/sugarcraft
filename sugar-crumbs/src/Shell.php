<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs;

/**
 * Shell — combines NavStack + Breadcrumb into a single navigation component.
 *
 * The Shell holds the current view (NavStack) and can render breadcrumbs
 * on demand. This mirrors the bubbleo Shell pattern.
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
     * Push a new navigation item and return a new Shell (immutable).
     */
    public function withPush(string $title, mixed $data = null): self
    {
        $newStack = (new NavStack())->setItems($this->stack->items());
        $newStack->push($title, $data);
        return new self($newStack, $this->breadcrumb);
    }

    /**
     * Pop the top item and return a new Shell (immutable).
     */
    public function withPop(): self
    {
        $newStack = (new NavStack())->setItems($this->stack->items());
        $newStack->pop();
        return new self($newStack, $this->breadcrumb);
    }

    public function renderBreadcrumb(): string
    {
        return $this->breadcrumb->render($this->stack);
    }

    /**
     * Parse a directory path and push each segment as a navigation item.
     * e.g. "/home/user/projects" pushes "home", then "user", then "projects"
     * Each segment's data is set to the full path up to that segment.
     */
    public function pushDirectory(string $path): self
    {
        $segments = \array_filter(\explode('/', \trim($path, '/')));
        $newStack = (new NavStack())->setItems($this->stack->items());
        $acc = '';
        foreach ($segments as $segment) {
            $acc .= '/' . $segment;
            $newStack->push($segment, $acc);
        }
        return new self($newStack, $this->breadcrumb);
    }
}
