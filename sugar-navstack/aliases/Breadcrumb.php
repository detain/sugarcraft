<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs;

/**
 * Back-compat alias of the renamed package root.
 *
 * sugar-navstack was published as sugar-crumbs; the Breadcrumb class kept its
 * role, only the namespace changed. Prefer
 * \SugarCraft\NavStack\Breadcrumb directly; this shim exists so
 * pre-rename consumers keep resolving until their next major.
 *
 * @deprecated Use \SugarCraft\NavStack\Breadcrumb instead.
 */
class_alias(\SugarCraft\NavStack\Breadcrumb::class, Breadcrumb::class);
