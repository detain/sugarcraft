<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Modules\System;

use SugarCraft\Dashboard\Msg\TickMsg as SharedTickMsg;

/**
 * BC alias for the shared tick marker (sugar-dashboard-096).
 *
 * The system module's refresh tick was the fifth byte-identical marker
 * under a fourth name; the vocabulary collapsed onto Msg\TickMsg.
 * Constructing `new RefreshMsg()` through this name yields a
 * source-less broadcast TickMsg, exactly as before.
 *
 * @deprecated Kept only as a class_alias target for two majors.
 *     Use SugarCraft\Dashboard\Msg\TickMsg. REMOVED-IN: 2.0.0.
 */
class_alias(SharedTickMsg::class, __NAMESPACE__ . '\RefreshMsg');
