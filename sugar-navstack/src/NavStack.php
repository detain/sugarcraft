<?php

declare(strict_types=1);

namespace SugarCraft\NavStack;

// @rename-keep
/**
 * Navigation stack — last-in, first-out navigation state.
 *
 * Push new items to go deeper; pop to go back up.
 * The top of the stack is the "current" screen/section.
 *
 * **Mutable by design** — `push`, `popTo`, `updateTop`, `clear`, and
 * `setItems` mutate `$this` and return `$this` for fluent chaining; `pop`
 * mutates in place too but returns the removed `?NavigationItem`, never
 * `$this`.
 * This is a deliberate exception to the repo-wide immutable `with*()`
 * convention: `filter()` correctly returns a new instance, but the
 * push/pop/set family mutate in place. Callers must not assume
 * copy-on-write for the push/pop/set family (see {@see Shell} for the
 * immutable wrapper — and note it SHARES the stack list, not deep copies).
 *
 * ## Click dispatch
 *
 * When a mouse click resolves to a crumb zone (e.g. `crumb-0`, `crumb-1`)
 * via the {@see Breadcrumb} scanner, the parent component should dispatch
 * to this stack's navigation methods accordingly. Zone ids carry the
 * crumb's position in the FULL stack — even after {@see Breadcrumb::setMaxWidth()}
 * elides older crumbs, `crumb-N` always means "stack item N".
 *
 * Design antecedent: KevM/bubbleo NavStack (Go); implemented as a
 * native PHP component.
 *
 * @see https://github.com/KevM/bubbleo
 */
final class NavStack
{
    /** Default separator used in view() and viewHtml() output. */
    private const SEPARATOR = ' › ';

    /**
     * Hard ceiling on stack depth — navigation stacks grow from user
     * input ({@see Shell::pushDirectory}, {@see Url::parse}), so an
     * unbounded push chain is an unbounded-memory door. Pushing past it
     * throws loudly rather than silently truncating.
     */
    public const MAX_STACK_DEPTH = 10000;

    /**
     * Mirrors bubbleo NavStack constructor.
     */
    public static function new(): self
    {
        return new self();
    }

    /** @var list<NavigationItem> */
    private array $items = [];

    /**
     * Push a new navigation item onto the stack.
     *
     * The title passes through {@see NavigationItem}'s constructor guards:
     * empty/whitespace-only titles are refused (they render as dead
     * unclickable crumbs and derive `//` URL segments).
     *
     * @throws \InvalidArgumentException On an empty title or when pushing
     *                                   would exceed {@see MAX_STACK_DEPTH}
     *
     * Mirrors bubbleo NavStack.push.
     */
    public function push(string $title, mixed $data = null): self
    {
        if (\count($this->items) >= self::MAX_STACK_DEPTH) {
            throw new \InvalidArgumentException(
                'Navigation stack cannot exceed ' . self::MAX_STACK_DEPTH . ' levels'
            );
        }
        $this->items[] = new NavigationItem($title, $data);
        return $this;
    }

    /**
     * Pop the topmost item off the stack and return it.
     * Returns null if the stack is empty.
     *
     * Mirrors bubbleo NavStack.pop.
     */
    public function pop(): ?NavigationItem
    {
        if ($this->items === []) {
            return null;
        }
        return \array_pop($this->items);
    }

    /**
     * Can the stack pop without going empty?
     *
     * Returns false when depth is 0 or 1 (popping would empty the stack).
     */
    public function canPop(): bool
    {
        return \count($this->items) > 1;
    }

    /**
     * Truncate the stack to the item at $index (inclusive), removing everything newer.
     * The item at position $index becomes the top.
     *
     * Out-of-range indices are clamped silently: a negative index clamps to
     * EMPTY (not "keep the root") — callers wanting root-or-deeper semantics
     * guard with `max(0, $index)`; oversized clamps to current depth.
     *
     * Mirrors bubbleo NavStack truncation.
     */
    public function popTo(int $index): self
    {
        $this->items = \array_slice($this->items, 0, \max(0, $index + 1));
        return $this;
    }

    /**
     * Peek at the top item without removing it.
     *
     * Mirrors bubbleo NavStack.current.
     */
    public function current(): ?NavigationItem
    {
        return $this->items[\count($this->items) - 1] ?? null;
    }

    /**
     * Peek at the item below the top.
     *
     * Mirrors bubbleo NavStack.parent.
     */
    public function parent(): ?NavigationItem
    {
        $n = \count($this->items);
        return $n >= 2 ? $this->items[$n - 2] : null;
    }

    /**
     * Current stack depth (number of items).
     */
    public function depth(): int
    {
        return \count($this->items);
    }

    /**
     * Is the stack empty?
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Get all items as a list.
     *
     * UNTRUSTED OUTLET: the returned items carry their RAW stored titles —
     * like current()/parent()/pop(), this accessor bypasses the render-path
     * sanitization. Treat `$item->title` from here as untrusted display data.
     *
     * @return list<NavigationItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Replace the TOP ITEM: a fresh NavigationItem carrying the same title
     * with new data (titles are readonly, so the item object itself is
     * swapped — identity checks on the previous top stop matching).
     * Does nothing if stack is empty.
     */
    public function updateTop(mixed $data): self
    {
        if ($this->items === []) {
            return $this;
        }
        $top = $this->items[\count($this->items) - 1];
        $this->items[\count($this->items) - 1] = new NavigationItem($top->title, $data);
        return $this;
    }

    /**
     * Clear the stack completely.
     */
    public function clear(): self
    {
        $this->items = [];
        return $this;
    }

    /**
     * Replace all items at once (used by Shell for immutable operations).
     *
     * The list is re-keyed (array_values), so non-list arrays — maps,
     * arrays with gaps — cannot smuggle string keys into the item list;
     * every element MUST be a NavigationItem (whose constructor already
     * rejected empty/oversized titles).
     *
     * @param array<array-key, NavigationItem> $items
     *
     * @throws \InvalidArgumentException On a non-NavigationItem element
     */
    public function setItems(array $items): self
    {
        foreach ($items as $k => $v) {
            if (!$v instanceof NavigationItem) {
                throw new \InvalidArgumentException(
                    'Navigation stack items must all be NavigationItem; got '
                    . (\is_scalar($v) ? \get_debug_type($v) : \get_class($v)) . ' at key ' . $k
                );
            }
        }
        $this->items = \array_values($items);
        return $this;
    }

    /**
     * Render the navigation stack as a breadcrumb string.
     * e.g. "Home › Settings › Display"
     *
     * Titles are untrusted and run through {@see TitleSanitizer} (terminal
     * controls, zone sentinels, invisible formatting, CR/LF/tab), so the
     * rendered TITLES are display-safe. The `$separator` itself is NOT
     * sanitized — it is interpolated raw (control bytes survive), so pass a
     * host-chosen constant, never user data.
     */
    public function view(string $separator = self::SEPARATOR): string
    {
        if ($this->items === []) {
            return '';
        }
        return \implode($separator, \array_map(
            static fn(NavigationItem $item): string => TitleSanitizer::sanitize($item->title),
            $this->items
        ));
    }

    /**
     * Render the stack as semantic HTML breadcrumb.
     *
     * Structure is the accessibility-recommended one — `nav[aria-label]`
     * around an ordered list, last item `aria-current="page"` — so no
     * literal separator text is emitted (style the gutter in CSS; the old
     * ` &gt; ` text made screen readers announce noise between crumbs).
     *
     * Titles run through {@see TitleSanitizer} FIRST (terminal controls,
     * zone sentinels, invisible formatting) and then htmlspecialchars, so
     * the TITLE text is HTML-safe and free of escape bytes a copy-paste
     * into a terminal would otherwise reanimate. The $href callback's URL
     * is quote-escaped but NOT scheme-filtered: javascript:/data: URLs you
     * return pass through.
     *
     * Pass `$href` to wrap every non-final crumb in an anchor:
     * `fn(NavigationItem $item): ?string` returning the URL, or null to
     * leave that crumb as plain text (unlinked ancestor). The callback MUST
     * return a validated absolute path or allow-listed-scheme URL.
     *
     * Example output (with `$href = fn($i) => Url::derive(...)` style links):
     * <nav class="breadcrumb" aria-label="Breadcrumb">
     *   <ol><li><a href="/home">Home</a></li><li><a href="/home/settings">Settings</a></li><li aria-current="page">Display</li></ol>
     * </nav>
     *
     * @param (callable(NavigationItem): ?string)|null $href
     */
    public function viewHtml(?callable $href = null, string $label = 'Breadcrumb'): string
    {
        $items = $this->items;
        if ($items === []) {
            return '';
        }

        $parts = [];
        $last  = \count($items) - 1;
        for ($i = 0; $i <= $last; $i++) {
            $item    = $items[$i];
            $escaped = \htmlspecialchars(TitleSanitizer::sanitize($item->title), \ENT_QUOTES, 'UTF-8');
            if ($i === $last) {
                $parts[] = '<li aria-current="page">' . $escaped . '</li>';
                continue;
            }
            $url  = $href !== null ? $href($item) : null;
            $body = \is_string($url)
                ? '<a href="' . \htmlspecialchars($url, \ENT_QUOTES, 'UTF-8') . '">' . $escaped . '</a>'
                : $escaped;
            $parts[] = '<li>' . $body . '</li>';
        }

        return '<nav class="breadcrumb" aria-label="'
            . \htmlspecialchars($label, \ENT_QUOTES, 'UTF-8') . '">'
            . '<ol>' . \implode('', $parts) . '</ol>'
            . '</nav>';
    }

    /**
     * Filter items by title or data matching $term (case-insensitive
     * substring). Data is matched when it is scalar or Stringable, or —
     * for array data such as `['route' => '/dash']` — when ANY
     * scalar/Stringable value inside the array matches, at any depth
     * (bounded: see {@see MAX_PAYLOAD_DEPTH} below).
     * Returns a new NavStack with only matching items.
     *
     * Notes: the result is a FRESH stack — item order is kept but indices
     * renumber, so zone ids (`crumb-N`) of a breadcrumb rendered from the
     * filtered stack refer to FILTERED positions, not the original ones.
     * The root is not exempt: if level 0 does not match, it is dropped.
     * An empty `$term` matches everything (substring semantics).
     *
     * @throws \InvalidArgumentException When an array payload nests deeper
     *                                   than the 64-level search limit
     */
    public function filter(string $term): self
    {
        $filtered = \array_filter(
            $this->items,
            static function(NavigationItem $item) use ($term): bool {
                if (\stripos($item->title, $term) !== false) {
                    return true;
                }
                return self::dataMatches($item->data, $term);
            }
        );
        $new = new self();
        $new->items = \array_values($filtered);
        return $new;
    }

    /**
     * Payload nesting the search walk descends into at most. Recursive data
     * is user-supplied ({@see Shell::pushDirectory}-built stacks are plain,
     * but anything the host carries is fair game), and a self-referencing
     * array would otherwise recurse until the stack dies (measured:
     * SIGSEGV). 64 levels is far beyond any sane payload shape; past it the
     * walk throws loudly instead of silently under-matching.
     */
    private const MAX_PAYLOAD_DEPTH = 64;

    /**
     * Does $data match $term? Scalars and Stringables are cast and scanned;
     * arrays are walked recursively so the example pattern of carrying a
     * payload array per level ('route' => '/dash') is searchable. Anything
     * else (null, non-Stringable objects) is skipped — never cast, which
     * would warn or fatal under strict types.
     *
     * @throws \InvalidArgumentException When nesting exceeds {@see MAX_PAYLOAD_DEPTH}
     */
    private static function dataMatches(mixed $data, string $term, int $depth = 0): bool
    {
        if (\is_array($data)) {
            if ($depth >= self::MAX_PAYLOAD_DEPTH) {
                throw new \InvalidArgumentException(
                    'Navigation payload nests deeper than the ' . self::MAX_PAYLOAD_DEPTH . '-level search limit'
                );
            }
            foreach ($data as $value) {
                if (self::dataMatches($value, $term, $depth + 1)) {
                    return true;
                }
            }
            return false;
        }
        if ($data === null || !(\is_scalar($data) || $data instanceof \Stringable)) {
            return false;
        }
        return \stripos((string) $data, $term) !== false;
    }
}
