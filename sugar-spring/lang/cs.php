<?php

/**
 * Czech translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps musí být > 0; obdrženo: {fps}',
    'spring.inputs_finite' => 'Vstupy Spring musí být konečné; obdrženo: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.negative_dt' => 'deltaTime musí být >= 0; obdrženo: {deltaTime}',
    'projectile.dt_finite' => 'deltaTime musí být konečné; získáno {deltaTime}',
    'projectile.negative_dt' => 'deltaTime musí být >= 0; získáno {deltaTime}',
    'projectile.components_finite' => 'Komponenty {name} projektilu musí být konečné',
];
