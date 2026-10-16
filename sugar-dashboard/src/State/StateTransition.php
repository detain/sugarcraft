<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\State;

use SugarCraft\Dashboard\Components\Tree\StateTransition as NewTransition;

/**
 * @internal Re-export from Components\Tree for backward compatibility.
 */
class_alias(NewTransition::class, StateTransition::class);
