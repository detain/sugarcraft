<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs;

/**
 * Back-compat alias of the renamed package root.
 *
 * sugar-navstack was published as sugar-crumbs; the Escape class kept its
 * role, only the namespace changed. Prefer
 * \SugarCraft\NavStack\Escape directly; this shim exists so
 * pre-rename consumers keep resolving until their next major.
 *
 * @deprecated Use \SugarCraft\NavStack\Escape instead.
 */
class_alias(\SugarCraft\NavStack\Escape::class, Escape::class);
