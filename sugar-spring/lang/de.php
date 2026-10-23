<?php

/**
 * German translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps muss > 0 sein; erhalten: {fps}',
    'spring.inputs_finite' => 'Spring-Eingaben müssen endlich sein; erhalten: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.negative_dt' => 'deltaTime muss >= 0 sein; erhalten: {deltaTime}',
    'projectile.dt_finite' => 'deltaTime muss endlich sein; erhalten {deltaTime}',
    'projectile.negative_dt' => 'deltaTime muss >= 0 sein; erhalten {deltaTime}',
    'projectile.components_finite' => 'Komponenten des Projektil-{name} müssen endlich sein',
];
