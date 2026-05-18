<?php

declare(strict_types=1);

namespace SugarCraft\Dash\State;

use SugarCraft\Dash\Components\Tree\StateTransition as NewTransition;

/**
 * @internal Re-export from Components\Tree for backward compatibility.
 */
class_alias(NewTransition::class, StateTransition::class);
