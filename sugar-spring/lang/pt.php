<?php

/**
 * Portuguese translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps tem de ser > 0; obtido: {fps}',
    'spring.inputs_finite' => 'As entradas do Spring têm de ser finitas; obtido: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.negative_dt' => 'deltaTime tem de ser >= 0; obtido: {deltaTime}',
    'projectile.dt_finite' => 'deltaTime deve ser finito; obtido {deltaTime}',
    'projectile.negative_dt' => 'deltaTime deve ser >= 0; obtido {deltaTime}',
    'projectile.components_finite' => 'Os componentes de {name} do projétil devem ser finitos',
];
