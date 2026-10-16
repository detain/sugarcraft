<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Progress;

use SugarCraft\Core\Msg;

/**
 * Tick sentinel dispatched by {@see AnimatedProgress::setPercent()}.
 * Each tick advances the spring integrator; the model re-issues the
 * Cmd until the bar settles.
 */
final class SpringTickMsg implements Msg
{
}
