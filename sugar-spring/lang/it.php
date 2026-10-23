<?php

/**
 * Italian translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps deve essere > 0; ottenuto: {fps}',
    'spring.inputs_finite' => 'Gli input di Spring devono essere finiti; ottenuto: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.negative_dt' => 'deltaTime deve essere >= 0; ottenuto: {deltaTime}',
    'projectile.dt_finite' => 'deltaTime deve essere finita; ottenuto {deltaTime}',
    'projectile.negative_dt' => 'deltaTime deve essere >= 0; ottenuto {deltaTime}',
    'projectile.components_finite' => 'I componenti di {name} del proiettile devono essere finiti',
];
