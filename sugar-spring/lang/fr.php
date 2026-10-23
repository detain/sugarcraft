<?php

/**
 * French translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps doit être > 0 ; reçu : {fps}',
    'spring.inputs_finite' => 'Les entrées de Spring doivent être finies ; reçu : deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.negative_dt' => 'deltaTime doit être >= 0 ; reçu : {deltaTime}',
    'projectile.dt_finite' => 'deltaTime doit être finie ; reçu : {deltaTime}',
    'projectile.negative_dt' => 'deltaTime doit être >= 0 ; reçu : {deltaTime}',
    'projectile.components_finite' => 'Les composants {name} du projectile doivent être finis',
];
