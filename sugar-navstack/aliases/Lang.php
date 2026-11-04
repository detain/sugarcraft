<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs;

/**
 * Back-compat alias of the renamed package root.
 *
 * sugar-navstack was published as sugar-crumbs; the Lang class kept its
 * role, only the namespace changed. Prefer
 * \SugarCraft\NavStack\Lang directly; this shim exists so
 * pre-rename consumers keep resolving until their next major.
 *
 * @deprecated Use \SugarCraft\NavStack\Lang instead.
 */
class_alias(\SugarCraft\NavStack\Lang::class, Lang::class);
