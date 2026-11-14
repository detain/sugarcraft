<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Modules\Uptime;

use SugarCraft\Dashboard\Msg\TickMsg as SharedTickMsg;

/**
 * BC alias for the shared tick marker (sugar-dashboard-096).
 *
 * The per-module marker classes collapsed onto one vocabulary type;
 * constructing a broadcast tick through this name behaves as before.
 *
 * @deprecated Kept only as a class_alias target for two majors.
 *     Use SugarCraft\Dashboard\Msg\TickMsg. REMOVED-IN: 2.0.0.
 */
class_alias(SharedTickMsg::class, __NAMESPACE__ . '\TickMsg');
