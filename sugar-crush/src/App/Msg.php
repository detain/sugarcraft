<?php

declare(strict_types=1);

namespace SugarCraft\Crush\App;

use SugarCraft\Core\Model;
use SugarCraft\Core\Msg as CoreMsg;

/**
 * Marker for this namespace's shell/engine messages.
 *
 * Extends candy-core's marker so {@see App::update()} can satisfy the
 * {@see Model} contract (which accepts any core Msg) while every existing
 * `App\*Msg` still reaches its own arm.
 */
interface Msg extends CoreMsg {}
