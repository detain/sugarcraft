<?php

/**
 * Korean translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps는 > 0이어야 합니다; 받음: {fps}',
    'spring.inputs_finite' => 'Spring 입력은 유한해야 합니다; 받음: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.negative_dt' => 'deltaTime은 >= 0이어야 합니다; 받음: {deltaTime}',
    'projectile.dt_finite' => 'deltaTime은 유한해야 합니다. 입력값: {deltaTime}',
    'projectile.negative_dt' => 'deltaTime은 0 이상이어야 합니다. 입력값: {deltaTime}',
    'projectile.components_finite' => 'Projectile {name} 구성 요소는 유한해야 합니다',
];
