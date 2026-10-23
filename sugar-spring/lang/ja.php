<?php

/**
 * Japanese translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps は > 0 である必要があります；取得：{fps}',
    'spring.inputs_finite' => 'Spring の入力は有限である必要があります；取得：deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.negative_dt' => 'deltaTime は >= 0 である必要があります；取得：{deltaTime}',
    'projectile.dt_finite' => 'deltaTime は有限である必要があります。取得値: {deltaTime}',
    'projectile.negative_dt' => 'deltaTime は 0 以上である必要があります。取得値: {deltaTime}',
    'projectile.components_finite' => 'Projectile の {name} コンポーネントは有限である必要があります',
];
