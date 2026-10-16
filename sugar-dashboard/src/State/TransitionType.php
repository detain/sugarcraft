<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\State;

use SugarCraft\Dashboard\Components\Tree\TransitionType as NewType;

/**
 * @internal Re-export from Components\Tree for backward compatibility.
 */
class_alias(NewType::class, TransitionType::class);
