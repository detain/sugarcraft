<?php

/**
 * Russian translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps должен быть > 0; получено: {fps}',
    'spring.inputs_finite' => 'Входные данные Spring должны быть конечными; получено: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.negative_dt' => 'deltaTime должен быть >= 0; получено: {deltaTime}',
    'projectile.dt_finite' => 'deltaTime должно быть конечным; получено {deltaTime}',
    'projectile.negative_dt' => 'deltaTime должно быть >= 0; получено {deltaTime}',
    'projectile.components_finite' => 'Компоненты {name} снаряда должны быть конечными',
];
