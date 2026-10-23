<?php

/**
 * Polish translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps musi być > 0; otrzymano: {fps}',
    'spring.inputs_finite' => 'Dane wejściowe Spring muszą być skończone; otrzymano: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.negative_dt' => 'deltaTime musi być >= 0; otrzymano: {deltaTime}',
    'projectile.dt_finite' => 'deltaTime musi być skończone; otrzymano {deltaTime}',
    'projectile.negative_dt' => 'deltaTime musi być >= 0; otrzymano {deltaTime}',
    'projectile.components_finite' => 'Składowe {name} pocisku muszą być skończone',
];
