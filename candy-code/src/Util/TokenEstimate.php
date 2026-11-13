<?php

declare(strict_types=1);

namespace SugarCraft\Code\Util;

use SugarCraft\Core\Util\TokenEstimate as CoreTokenEstimate;

/*
 * S-X09 (R16): the script-weighted token estimate moved to sugar-core
 * (`SugarCraft\Core\Util\TokenEstimate`, origin credit in its docblock). This
 * file is the app-side alias so every `SugarCraft\Code\Util\TokenEstimate`
 * call site (and its pinned figures) keeps resolving to the ONE implementation.
 * The calibration factor and the window-resolution law stay app-side
 * ({@see \SugarCraft\Code\Host\ContextMeter}).
 */
class_alias(CoreTokenEstimate::class, TokenEstimate::class);
