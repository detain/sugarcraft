<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

/**
 * The `$width` argument value that means "size this to the terminal".
 *
 * Most of this library's presenters take an `int|AutoWidth|null` `$width`
 * defaulting to `AutoWidth::Auto`:
 * the render resolves to the terminal width where it is resolvable and to 80
 * cells where it is not (see {@see Internal\WidthProbe} for the precedence
 * chain). Passing an `int` pins that width explicitly; passing `null` keeps
 * each presenter's own unbounded meaning. `AutoWidth::Auto` may also be
 * passed explicitly — it behaves exactly like omitting the argument, which
 * is useful when a caller wants to stay auto while naming later parameters.
 * {@see HelpText::renderPage()} is the one surface outside that shape: it
 * defaults to null — never re-wrap an already-authored page — for the
 * reasons documented there.
 *
 * It is a dedicated type rather than a magic integer because a sentinel
 * number would collide with the argument's own contract: 0 is an authoring
 * error that must throw, and `null` already means "unbounded" — neither can
 * double as "ask the terminal". {@see Banner::title()} accepts the same
 * argument, but `null` — not `Auto` — is its default there: a banner sizes
 * itself to its content unless it asks the terminal.
 */
enum AutoWidth
{
    case Auto;
}
