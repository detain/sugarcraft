<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs;

/**
 * Back-compat alias of the renamed package root.
 *
 * sugar-navstack was published as sugar-crumbs; the Url class kept its
 * role, only the namespace changed. Prefer
 * \SugarCraft\NavStack\Url directly; this shim exists so
 * pre-rename consumers keep resolving until their next major.
 *
 * @deprecated Use \SugarCraft\NavStack\Url instead.
 */
class_alias(\SugarCraft\NavStack\Url::class, Url::class);
