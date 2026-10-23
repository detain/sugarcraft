<?php

/**
 * Simplified Chinese translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps 必须 > 0；实际：{fps}',
    'spring.inputs_finite' => 'Spring 输入必须为有限值；实际：deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.negative_dt' => 'deltaTime 必须 >= 0；实际：{deltaTime}',
    'projectile.dt_finite' => 'deltaTime 必须为有限值；得到 {deltaTime}',
    'projectile.negative_dt' => 'deltaTime 必须 >= 0；得到 {deltaTime}',
    'projectile.components_finite' => '抛射体 {name} 分量必须为有限值',
];
