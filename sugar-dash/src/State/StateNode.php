<?php

declare(strict_types=1);

namespace SugarCraft\Dash\State;

use SugarCraft\Dash\Components\Tree\StateNode as NewNode;

/**
 * @internal Re-export from Components\Tree for backward compatibility.
 */
class_alias(NewNode::class, StateNode::class);
