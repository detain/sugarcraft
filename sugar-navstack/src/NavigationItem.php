<?php

declare(strict_types=1);

namespace SugarCraft\NavStack;

/**
 * A single item on the navigation stack.
 *
 * Holds the title (used in breadcrumbs) and optional arbitrary data
 * that each navigation level wants to carry (e.g. filter state, selected ID, etc.)
 *
 * The title is validated at construction: titles that are empty after
 * trimming AND sanitizing are refused (blank, whitespace-only, or built
 * purely of terminal/sentinel/invisible bytes — all of them render as dead
 * crumbs and derive `//` URL segments), and titles above
 * {@see self::MAX_TITLE_BYTES} bytes are refused so a hostile path/URL
 * segment cannot smuggle megabytes into every render of the row.
 *
 * Implements {@see Closable}; see that interface for who dispatches the
 * lifecycle hooks (the CONSUMING component does — this library never calls
 * onEnter/onLeave on items it renders). This class is `final` and
 * {@see NavStack::push()} constructs its items from a title + data — so a
 * Closable implementation of YOUR OWN can never enter the stack through
 * this library's API. Implementing {@see Closable} is the vocabulary your
 * host application uses for its own screens; you dispatch those hooks
 * yourself when a crumb click (or pop) moves between them.
 */
final class NavigationItem implements Closable
{
    /**
     * Hard ceiling on raw title bytes (pre-sanitization). Breadcrumb rows
     * are single-line widgets; titles past this size are input attacks or
     * bugs, not navigation labels.
     */
    public const MAX_TITLE_BYTES = 65536;

    /**
     * @throws \InvalidArgumentException On an empty/whitespace/sanitize-empty
     *                                   title or one exceeding
     *                                   {@see MAX_TITLE_BYTES}
     */
    public function __construct(
        public readonly string $title,
        public readonly mixed $data = null,
    ) {
        if (\strlen($title) > self::MAX_TITLE_BYTES) {
            throw new \InvalidArgumentException(
                'Navigation item title must not exceed ' . self::MAX_TITLE_BYTES . ' bytes'
            );
        }
        // Size is checked on RAW bytes first (sanitize cost must not run on a
        // megabyte payload); then the render sanitizer decides emptiness — a
        // title made purely of ANSI/sentinel/zero-width bytes survives trim()
        // but prints as nothing at all, i.e. the dead crumb the docblocks
        // always promised was refused.
        if (\trim(TitleSanitizer::sanitize($title)) === '') {
            throw new \InvalidArgumentException('Navigation item title must not be empty');
        }
    }

    /**
     * Contract default from {@see Closable}: a no-op. Nothing in this
     * library dispatches it — a consumer that wires enter/leave events
     * calls it, and subclasses are impossible (final); implement
     * {@see Closable} on your own type instead.
     */
    public function onEnter(): void
    {
        // no-op by default
    }

    /**
     * Contract default from {@see Closable}: a no-op. See
     * {@see onEnter()} for who dispatches.
     */
    public function onLeave(): void
    {
        // no-op by default
    }

    /**
     * Returns the item's title.
     */
    public function title(): string
    {
        return $this->title;
    }
}
