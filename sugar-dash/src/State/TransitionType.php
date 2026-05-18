<?php

declare(strict_types=1);

namespace SugarCraft\Dash\State;

use SugarCraft\Dash\Components\Tree\TransitionType as NewType;

/**
 * @internal Re-export from Components\Tree for backward compatibility.
 */
class_alias(NewType::class, TransitionType::class);
