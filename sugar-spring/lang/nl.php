<?php

/**
 * Dutch translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps moet > 0 zijn; gekregen: {fps}',
    'spring.inputs_finite' => 'Spring-invoer moet eindig zijn; gekregen: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.negative_dt' => 'deltaTime moet >= 0 zijn; gekregen: {deltaTime}',
    'projectile.dt_finite' => 'deltaTime moet eindig zijn; gekregen {deltaTime}',
    'projectile.negative_dt' => 'deltaTime moet >= 0 zijn; gekregen {deltaTime}',
    'projectile.components_finite' => 'De {name}-componenten van het projectiel moeten eindig zijn',
];
