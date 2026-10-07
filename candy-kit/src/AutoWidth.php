<?php

declare(strict_types=1);

namespace SugarCraft\Kit;

/**
 * The `$width` argument value that means "size this to the terminal".
 *
 * Every kit presenter's `$width` parameter defaults to `AutoWidth::Auto`:
 * the render resolves to the terminal width where it is resolvable and to 80
 * cells where it is not (see {@see HelpText::render()} for the precedence
 * chain). Passing an `int` pins that width explicitly; passing `null` keeps
 * each presenter's own unbounded meaning. `AutoWidth::Auto` may also be
 * passed explicitly — it behaves exactly like omitting the argument, which
 * is useful when a caller wants to stay auto while naming later parameters.
 *
 * It is a dedicated type rather than a magic integer because a sentinel
 * number would collide with the argument's own contract: 0 is an authoring
 * error that must throw, and `null` already means "unbounded" — neither can
 * double as "ask the terminal".
 */
enum AutoWidth
{
    case Auto;
}
